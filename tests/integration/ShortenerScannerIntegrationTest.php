<?php
/**
 * Integration tests for ALM_Shortener_Scanner against a real WordPress
 * install and real $wpdb -- the reclassification logic in particular
 * needs a real DB to prove it actually updates the right rows. Real
 * network calls are intercepted via WP core's own `pre_http_request`
 * filter (the standard way to fake wp_safe_remote_head() in WP tests
 * without touching the network), same as DomainScannerIntegrationTest.
 *
 * @package ALM
 */

/**
 * The real resolver sleeps for real (see ALM_Shortener_Resolver's
 * class docblock) before trusting a 404/410 -- fine for a real batch,
 * unnecessary real delay for this test suite, where the fake
 * pre_http_request response is identical on both attempts anyway.
 */
class NoSleepShortenerResolver extends ALM_Shortener_Resolver {
	protected function wait_before_dead_retry() {
		// No-op.
	}
}

/**
 * @covers ALM_Shortener_Scanner
 * @covers ALM_Shortener_Resolver
 */
class ShortenerScannerIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Shortener_Scanner
	 */
	private $scanner;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		update_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID, 'sDXyBS' );

		$providers     = new ALM_Provider_Registry();
		$this->scanner = new ALM_Shortener_Scanner( new NoSleepShortenerResolver(), $providers );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Maps specific URLs to canned redirect/response behavior -- lets a
	 * single test simulate a real multi-hop redirect chain, not just
	 * one fetch.
	 *
	 * @param array<string,array{status:int,location?:string}> $map
	 * @return void
	 */
	private function fake_http_chain( array $map ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $map ) {
				if ( ! isset( $map[ $url ] ) ) {
					return $preempt;
				}

				$entry   = $map[ $url ];
				$headers = isset( $entry['location'] ) ? array( 'location' => $entry['location'] ) : array();

				return array(
					'response' => array( 'code' => $entry['status'] ),
					'headers'  => $headers,
					'body'     => '',
				);
			},
			10,
			3
		);
	}

	/**
	 * @param string $url
	 * @return int Inserted row id.
	 */
	private function insert_link( $url ) {
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
				'status'      => ALM_Install::STATUS_CONVERTIBLE,
				'first_seen'  => $now,
				'last_seen'   => $now,
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

	public function test_a_shortlink_resolving_to_a_known_provider_is_auto_reclassified() {
		$id = $this->insert_link( 'http://bit.ly/abc123' );
		$this->fake_http_chain(
			array(
				'http://bit.ly/abc123' => array(
					'status'   => 301,
					'location' => 'https://amzn.to/manually-generated',
				),
				'https://amzn.to/manually-generated' => array( 'status' => 200 ),
			)
		);

		$result = $this->scanner->check_batch( 10 );
		$this->assertSame( 1, $result['checked'] );
		$this->assertTrue( $result['done'] );

		$row = $this->get_row( $id );
		$this->assertSame( 'amazon', $row['provider'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( 'https://amzn.to/manually-generated', $row['resolved_url'] );
	}

	public function test_a_shortlink_resolving_to_an_unrecognized_url_stays_a_candidate_but_gets_a_resolved_url() {
		$id = $this->insert_link( 'http://bit.ly/plain-product' );
		$this->fake_http_chain(
			array(
				'http://bit.ly/plain-product' => array(
					'status'   => 301,
					'location' => 'https://www.zara.com/us/en/product.html',
				),
				'https://www.zara.com/us/en/product.html' => array( 'status' => 200 ),
			)
		);

		$this->scanner->check_batch( 10 );

		$row = $this->get_row( $id );
		$this->assertSame( 'unclassified', $row['provider'] );
		$this->assertSame( 'convertible', $row['status'], 'Still a real Candidate -- not auto-promoted on an unmatched destination.' );
		$this->assertSame( 'https://www.zara.com/us/en/product.html', $row['resolved_url'] );
	}

	public function test_a_confirmed_dead_shortlink_is_marked_stale() {
		$id = $this->insert_link( 'http://bit.ly/dead-link' );
		$this->fake_http_chain(
			array(
				'http://bit.ly/dead-link' => array( 'status' => 404 ),
			)
		);

		$this->scanner->check_batch( 10 );

		$row = $this->get_row( $id );
		$this->assertSame( 'stale', $row['status'] );
		$this->assertNull( $row['resolved_url'] );
		$this->assertNotNull( $row['resolved_at'], 'Marked resolved even though dead -- must not be retried every batch.' );
	}

	public function test_an_inconclusive_fetch_leaves_status_untouched_but_marks_resolved_at() {
		$id = $this->insert_link( 'http://bit.ly/server-error' );
		$this->fake_http_chain(
			array(
				'http://bit.ly/server-error' => array( 'status' => 500 ),
			)
		);

		$this->scanner->check_batch( 10 );

		$row = $this->get_row( $id );
		$this->assertSame( 'convertible', $row['status'], 'Inconclusive -- must not change status on a guess.' );
		$this->assertNull( $row['resolved_url'] );
		$this->assertNotNull( $row['resolved_at'] );
	}

	public function test_a_non_shortener_link_is_never_touched() {
		$id = $this->insert_link( 'https://www.zara.com/us/en/product.html' );

		$result = $this->scanner->check_batch( 10 );
		$this->assertSame( 0, $result['checked'] );

		$row = $this->get_row( $id );
		$this->assertNull( $row['resolved_at'] );
	}

	public function test_count_pending_reflects_only_unresolved_shortener_links() {
		$this->insert_link( 'http://bit.ly/one' );
		$this->insert_link( 'https://www.zara.com/us/en/product.html' );

		$this->assertSame( 1, $this->scanner->count_pending() );
	}
}
