<?php
/**
 * Integration tests for ALM_Link_Health_Scanner against a real
 * WordPress install and real $wpdb -- the status-transition logic in
 * particular needs a real DB to prove it actually updates the right
 * rows. Real network calls are intercepted via WP core's own
 * `pre_http_request` filter, same as DomainScannerIntegrationTest/
 * ShortenerScannerIntegrationTest.
 *
 * @package ALM
 */

/**
 * The real checker sleeps for real (see ALM_Link_Health_Checker's
 * class docblock) before trusting a dead signal -- fine for a real
 * batch, unnecessary real delay for this test suite, where the fake
 * pre_http_request response is identical on both attempts anyway.
 */
class NoSleepLinkHealthChecker extends ALM_Link_Health_Checker {
	protected function wait_before_dead_retry() {
		// No-op.
	}
}

/**
 * @covers ALM_Link_Health_Scanner
 * @covers ALM_Link_Health_Checker
 */
class LinkHealthScannerIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Link_Health_Scanner
	 */
	private $scanner;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		$this->scanner = new ALM_Link_Health_Scanner( new NoSleepLinkHealthChecker() );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * @param array<string,int> $status_by_url
	 * @return void
	 */
	private function fake_http_responses( array $status_by_url ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $status_by_url ) {
				if ( ! isset( $status_by_url[ $url ] ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => $status_by_url[ $url ] ),
					'headers'  => array(),
					'body'     => '',
				);
			},
			10,
			3
		);
	}

	/**
	 * @param string      $url
	 * @param string      $category
	 * @param string|null $modifier
	 * @return int Inserted row id.
	 */
	private function insert_link( $url, $category = ALM_Install::CATEGORY_CANDIDATE, $modifier = null ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'       => 1,
				'provider'      => 'unclassified',
				'adapter'       => 'post_content',
				'location'      => (string) wp_rand(),
				'url'           => $url,
				'anchor_text'   => 'link',
				'category'      => $category,
				'modifier'      => $modifier,
				'classified_at' => $now,
				'first_seen'    => $now,
				'last_seen'     => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $id
	 * @return array
	 */
	private function get_row( $id ) {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	public function test_a_confirmed_dead_candidate_is_moved_to_nonaffiliate_dead() {
		$id = $this->insert_link( 'https://gone.example.com/product' );
		$this->fake_http_responses( array( 'https://gone.example.com/product' => 404 ) );

		$result = $this->scanner->check_batch( 10 );
		$this->assertSame( 1, $result['checked'] );
		$this->assertTrue( $result['done'] );

		$row = $this->get_row( $id );
		$this->assertSame( ALM_Install::CATEGORY_NONAFFILIATE, $row['category'] );
		$this->assertNotNull( $row['health_checked_at'] );
		$this->assertSame( ALM_Install::MODIFIER_DEAD, $row['modifier'], 'A confirmed-dead result must set modifier=dead so ALM_Links_List_Table\'s "Dead" tab can find it -- see ALM_Install::count_confirmed_dead().' );
	}

	public function test_a_bot_blocked_candidate_stays_a_candidate() {
		$id = $this->insert_link( 'https://real-retailer.example.com/product' );
		$this->fake_http_responses( array( 'https://real-retailer.example.com/product' => 403 ) );

		$this->scanner->check_batch( 10 );

		$row = $this->get_row( $id );
		$this->assertSame( ALM_Install::CATEGORY_CANDIDATE, $row['category'], 'A 403 is ambiguous (real, live retailers block automated requests) -- must not be demoted.' );
		$this->assertNotNull( $row['health_checked_at'], 'Still marked checked, so it is not retried every single batch.' );
		$this->assertNull( $row['modifier'], 'Ambiguous, not confirmed dead -- must not appear on the "Dead" tab.' );
	}

	public function test_a_live_candidate_stays_a_candidate() {
		$id = $this->insert_link( 'https://live-shop.example.com/product' );
		$this->fake_http_responses( array( 'https://live-shop.example.com/product' => 200 ) );

		$this->scanner->check_batch( 10 );

		$row = $this->get_row( $id );
		$this->assertSame( ALM_Install::CATEGORY_CANDIDATE, $row['category'] );
		$this->assertNotNull( $row['health_checked_at'] );
		$this->assertNull( $row['modifier'] );
	}

	public function test_active_and_already_stale_links_are_never_checked() {
		$active_id = $this->insert_link( 'https://active-link.example.com/product', ALM_Install::CATEGORY_AFFILIATE );
		$stale_id  = $this->insert_link( 'https://stale-link.example.com/product', ALM_Install::CATEGORY_NONAFFILIATE, ALM_Install::MODIFIER_STALE );
		$this->fake_http_responses(
			array(
				'https://active-link.example.com/product' => 404,
				'https://stale-link.example.com/product'  => 404,
			)
		);

		$result = $this->scanner->check_batch( 10 );
		$this->assertSame( 0, $result['checked'], 'Only category=candidate links are ever selected for a health check.' );

		$this->assertNull( $this->get_row( $active_id )['health_checked_at'] );
		$this->assertNull( $this->get_row( $stale_id )['health_checked_at'] );
	}

	public function test_count_pending_reflects_only_unchecked_candidates() {
		$this->insert_link( 'https://one.example.com/product' );
		$this->insert_link( 'https://two.example.com/product', ALM_Install::CATEGORY_AFFILIATE );

		$this->assertSame( 1, $this->scanner->count_pending() );
	}

	/**
	 * Feeds the Dashboard Tasks table's "Last run" line -- same role
	 * ALM_Domain_Scanner/ALM_Shortener_Scanner's own delta recording
	 * plays for their tasks.
	 */
	public function test_first_of_run_records_a_delta_of_what_this_run_actually_found() {
		$this->insert_link( 'https://gone.example.com/product' );
		$this->insert_link( 'https://live-shop.example.com/product' );
		$this->fake_http_responses(
			array(
				'https://gone.example.com/product'      => 404,
				'https://live-shop.example.com/product' => 200,
			)
		);

		$result = $this->scanner->check_batch( 10, true );
		$this->assertTrue( $result['done'] );

		$this->assertNotEmpty( get_option( 'alm_link_health_started_at' ) );
		$this->assertNotEmpty( get_option( 'alm_last_link_health_time' ) );

		$delta = get_option( 'alm_last_link_health_delta' );
		$this->assertSame( 1, $delta['confirmed_dead'] );
		$this->assertSame( 1, $delta['still_fine'] );
	}

	/**
	 * A run spanning more than one batch must report on the whole run
	 * once finished, and a resumed (non-first) batch must not reset
	 * when the run itself began -- same guarantee already locked in for
	 * Check Domains/Expand Shortened Links.
	 */
	public function test_a_resumed_batch_does_not_restamp_the_run_start_or_lose_the_first_batchs_delta() {
		$this->insert_link( 'https://gone-one.example.com/product' );
		$this->insert_link( 'https://gone-two.example.com/product' );
		$this->fake_http_responses(
			array(
				'https://gone-one.example.com/product' => 404,
				'https://gone-two.example.com/product' => 404,
			)
		);

		$first = $this->scanner->check_batch( 1, true );
		$this->assertFalse( $first['done'], 'One link checked out of two -- this run is not finished yet.' );
		$started_after_first = get_option( 'alm_link_health_started_at' );
		$this->assertNotEmpty( $started_after_first );

		$second = $this->scanner->check_batch( 1, false );
		$this->assertTrue( $second['done'] );

		$this->assertSame( $started_after_first, get_option( 'alm_link_health_started_at' ), 'A resumed batch must not restamp when this run began.' );

		$delta = get_option( 'alm_last_link_health_delta' );
		$this->assertSame( 2, $delta['confirmed_dead'], 'The delta must cover both batches of this run, not just the last one.' );
	}

	/**
	 * Every existing call site calls check_batch() with just one
	 * argument -- omitting the new $is_first_of_run param must keep
	 * working exactly as before, not fabricate a run boundary out of
	 * nothing.
	 */
	public function test_omitting_is_first_of_run_does_not_fabricate_a_run_start() {
		$this->insert_link( 'https://live-shop.example.com/product' );
		$this->fake_http_responses( array( 'https://live-shop.example.com/product' => 200 ) );

		$this->scanner->check_batch( 10 );

		$delta = get_option( 'alm_last_link_health_delta' );
		$this->assertSame( 0, $delta['confirmed_dead'], 'With no known run start, the delta has nothing real to attribute to this call.' );
	}
}
