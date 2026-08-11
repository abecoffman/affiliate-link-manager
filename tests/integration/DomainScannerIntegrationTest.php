<?php
/**
 * Integration tests for ALM_Domain_Scanner against a real WordPress
 * install and real $wpdb -- the reclassification logic in particular
 * needs a real DB to prove it actually updates the right rows. Real
 * network calls are intercepted via WP core's own `pre_http_request`
 * filter (the standard way to fake wp_remote_get() in WP tests without
 * touching the network), not Brain Monkey -- this tier runs against a
 * real WP_UnitTestCase, no mocking framework involved.
 *
 * @package ALM
 */

/**
 * @covers ALM_Domain_Scanner
 * @covers ALM_Domain_Checker
 */
class DomainScannerIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Domain_Scanner
	 */
	private $scanner;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::domains_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		$this->scanner = new ALM_Domain_Scanner( new ALM_Domain_Checker(), new ALM_Candidate_Classifier() );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * @param string $body
	 * @param int    $status
	 * @return void
	 */
	private function fake_http_response( $body, $status = 200 ) {
		add_filter(
			'pre_http_request',
			function () use ( $body, $status ) {
				return array(
					'response' => array( 'code' => $status ),
					'body'     => $body,
					'headers'  => array(),
				);
			}
		);
	}

	/**
	 * @param string $url
	 * @param string $status
	 * @return int Inserted row id.
	 */
	private function insert_link( $url, $status ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'     => 1,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => (string) wp_rand(),
				'url'         => $url,
				'anchor_text' => 'link',
				'status'      => $status,
				'first_seen'  => $now,
				'last_seen'   => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_a_confirmed_shop_domain_stays_convertible_and_is_cached() {
		$this->insert_link( 'https://smallboutique.example/product/necklace', ALM_Install::STATUS_CONVERTIBLE );
		$this->fake_http_response( '<meta property="og:type" content="product" />' );

		$result = $this->scanner->check_batch( 10 );

		$this->assertSame( 1, $result['checked'] );
		$this->assertTrue( $result['done'] );

		global $wpdb;
		$domain_row = $wpdb->get_row( "SELECT * FROM " . ALM_Install::domains_table_name() . " WHERE domain = 'smallboutique.example'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test-only.
		$this->assertSame( 1, (int) $domain_row['is_shop'] );
		$this->assertNotNull( $domain_row['checked_at'] );

		$link = $wpdb->get_row( "SELECT status FROM " . ALM_Install::table_name() . " WHERE url = 'https://smallboutique.example/product/necklace'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test-only.
		$this->assertSame( ALM_Install::STATUS_CONVERTIBLE, $link['status'] );
	}

	public function test_a_confirmed_non_shop_domain_gets_reclassified_to_unclassified() {
		// This link was a heuristic-default candidate (nothing said it
		// was noise yet) -- the real content check is what should catch
		// it, the exact scenario the whole feature exists for.
		$id = $this->insert_link( 'https://some-magazine-nobody-listed.example/article', ALM_Install::STATUS_CONVERTIBLE );
		$this->fake_http_response( '<html><body><article><h1>An article</h1></article></body></html>' );

		$this->scanner->check_batch( 10 );

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . ALM_Install::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test-only.
		$this->assertSame( ALM_Install::STATUS_UNCLASSIFIED, $status );
	}

	public function test_ignored_links_are_never_touched_even_if_their_domain_gets_checked() {
		$id = $this->insert_link( 'https://some-magazine-nobody-listed.example/article', ALM_Install::STATUS_IGNORED );
		$this->fake_http_response( '<meta property="og:type" content="product" />' );

		$this->scanner->check_batch( 10 );

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . ALM_Install::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test-only.
		$this->assertSame( ALM_Install::STATUS_IGNORED, $status, 'An explicitly ignored link must stay ignored regardless of what the domain check finds.' );
	}

	public function test_a_failed_fetch_leaves_the_links_status_untouched() {
		$id = $this->insert_link( 'https://unreachable.example/product', ALM_Install::STATUS_CONVERTIBLE );
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$this->scanner->check_batch( 10 );

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . ALM_Install::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test-only.
		$this->assertSame( ALM_Install::STATUS_CONVERTIBLE, $status, 'A failed check must never bury a link back in the noise bucket.' );

		$domain_row = $wpdb->get_row( "SELECT * FROM " . ALM_Install::domains_table_name() . " WHERE domain = 'unreachable.example'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test-only.
		$this->assertNull( $domain_row['is_shop'] );
		$this->assertNotNull( $domain_row['checked_at'], 'Still marked as checked, so it does not get retried every single batch.' );
	}

	public function test_one_domain_with_many_links_only_costs_one_http_request() {
		$this->insert_link( 'https://busyshop.example/product/1', ALM_Install::STATUS_CONVERTIBLE );
		$this->insert_link( 'https://busyshop.example/product/2', ALM_Install::STATUS_CONVERTIBLE );
		$this->insert_link( 'https://busyshop.example/product/3', ALM_Install::STATUS_CONVERTIBLE );

		$request_count = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$request_count ) {
				++$request_count;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '<meta property="og:type" content="product" />',
					'headers'  => array(),
				);
			}
		);

		$result = $this->scanner->check_batch( 10 );

		$this->assertSame( 1, $result['checked'], 'Three links on the same domain are one domain, one check.' );
		$this->assertSame( 1, $request_count );

		global $wpdb;
		$statuses = $wpdb->get_col( "SELECT status FROM " . ALM_Install::table_name() . " WHERE url LIKE 'https://busyshop.example/%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test-only.
		$this->assertSame( array( ALM_Install::STATUS_CONVERTIBLE, ALM_Install::STATUS_CONVERTIBLE, ALM_Install::STATUS_CONVERTIBLE ), $statuses );
	}

	public function test_a_recently_checked_domain_is_not_checked_again() {
		$this->insert_link( 'https://already-checked.example/product', ALM_Install::STATUS_CONVERTIBLE );
		$this->fake_http_response( '<meta property="og:type" content="product" />' );

		$first  = $this->scanner->check_batch( 10 );
		$second = $this->scanner->check_batch( 10 );

		$this->assertSame( 1, $first['checked'] );
		$this->assertSame( 0, $second['checked'], 'Already checked within the recheck window -- must not be re-fetched.' );
	}

	/**
	 * Real bug this guards: count_domains_needing_check() originally
	 * read wp_alm_domains directly without syncing first, so a
	 * brand-new candidate link nobody had run "Check Domains" for yet
	 * simply wasn't in that table at all -- the Dashboard reported "0
	 * pending" while a real, unchecked candidate sat right above it.
	 * Found by actually looking at the rendered Dashboard against real
	 * data, not by reading the code.
	 */
	public function test_pending_count_reflects_a_brand_new_candidate_before_any_check_has_ever_run() {
		$this->insert_link( 'https://never-checked-yet.example/product', ALM_Install::STATUS_CONVERTIBLE );

		$this->assertSame( 1, $this->scanner->count_domains_needing_check(), 'A newly-discovered candidate domain must count as pending even before the first sync/check batch ever runs.' );
	}

	public function test_a_stale_checked_domain_becomes_eligible_again() {
		$this->insert_link( 'https://stale-domain.example/product', ALM_Install::STATUS_CONVERTIBLE );
		$this->fake_http_response( '<meta property="og:type" content="product" />' );
		$this->scanner->check_batch( 10 );

		global $wpdb;
		$wpdb->update(
			ALM_Install::domains_table_name(),
			array( 'checked_at' => gmdate( 'Y-m-d H:i:s', time() - ( 91 * DAY_IN_SECONDS ) ) ),
			array( 'domain' => 'stale-domain.example' )
		);

		$this->assertSame( 1, $this->scanner->count_domains_needing_check() );
	}

	/**
	 * Feeds the Dashboard Tasks table's "Last run" line for Check
	 * Domains -- same role ALM_Scanner::record_scan_delta() plays for
	 * Run Scan, added so that row could finally say what it found last
	 * time instead of nothing (see ALM_Admin::get_dashboard_tasks()).
	 */
	public function test_first_of_run_records_a_delta_of_what_this_run_actually_found() {
		$this->insert_link( 'https://realshop.example/product', ALM_Install::STATUS_CONVERTIBLE );
		$this->insert_link( 'https://magazine.example/article', ALM_Install::STATUS_CONVERTIBLE );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$body = ( false !== strpos( $url, 'realshop.example' ) )
					? '<meta property="og:type" content="product" />'
					: '<html><body><article><h1>An article</h1></article></body></html>';
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $body,
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->scanner->check_batch( 10, true );
		$this->assertTrue( $result['done'] );

		$this->assertNotEmpty( get_option( 'alm_domain_check_started_at' ) );
		$this->assertNotEmpty( get_option( 'alm_last_domain_check_time' ) );

		$delta = get_option( 'alm_last_domain_check_delta' );
		$this->assertSame( 1, $delta['confirmed_shops'] );
		$this->assertSame( 1, $delta['confirmed_not'] );
	}

	/**
	 * A run that spans more than one batch (small batch size forces
	 * this) must still report on the whole run once it finishes, not
	 * just whatever the final batch touched -- and a resumed (non-first)
	 * batch must not reset when the run itself began.
	 */
	public function test_a_resumed_batch_does_not_restamp_the_run_start_or_lose_the_first_batchs_delta() {
		$this->insert_link( 'https://shop-a.example/product', ALM_Install::STATUS_CONVERTIBLE );
		$this->insert_link( 'https://shop-b.example/product', ALM_Install::STATUS_CONVERTIBLE );
		$this->fake_http_response( '<meta property="og:type" content="product" />' );

		$first = $this->scanner->check_batch( 1, true );
		$this->assertFalse( $first['done'], 'One domain checked out of two -- this run is not finished yet.' );
		$started_after_first = get_option( 'alm_domain_check_started_at' );
		$this->assertNotEmpty( $started_after_first );

		$second = $this->scanner->check_batch( 1, false );
		$this->assertTrue( $second['done'] );

		$this->assertSame( $started_after_first, get_option( 'alm_domain_check_started_at' ), 'A resumed batch must not restamp when this run began.' );

		$delta = get_option( 'alm_last_domain_check_delta' );
		$this->assertSame( 2, $delta['confirmed_shops'], 'The delta must cover both batches of this run, not just the last one.' );
	}

	/**
	 * Every existing call site (and every other test in this file)
	 * calls check_batch() with just one argument -- omitting the new
	 * $is_first_of_run param must keep working exactly as before, not
	 * fabricate a run boundary out of nothing.
	 */
	public function test_omitting_is_first_of_run_does_not_fabricate_a_run_start() {
		$this->insert_link( 'https://shop-c.example/product', ALM_Install::STATUS_CONVERTIBLE );
		$this->fake_http_response( '<meta property="og:type" content="product" />' );

		$this->scanner->check_batch( 10 );

		$delta = get_option( 'alm_last_domain_check_delta' );
		$this->assertSame( 0, $delta['confirmed_shops'], 'With no known run start, the delta has nothing real to attribute to this call.' );
	}
}
