<?php
/**
 * Integration tests for ALM_Admin::handle_remove_link() -- the Edit
 * modal's single-row "Remove from Post" AJAX handler. Real correctness
 * bug found live: this only ever checked status===stale, even though
 * its own error message already claimed dead_confirmed_at was
 * required ("Only a confirmed-dead link can be removed this way").
 * Real WP_Ajax_UnitTestCase-driven requests through the real wp_ajax_*
 * hook, same pattern as AjaxBatchRunIntegrationTest.php.
 *
 * @package ALM
 */

/**
 * @covers ALM_Admin
 */
class RemoveLinkIntegrationTest extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		$providers              = new ALM_Provider_Registry();
		$adapters               = new ALM_Adapter_Registry();
		$scanner                = new ALM_Scanner( $adapters, $providers, new ALM_Candidate_Classifier() );
		$domain_scanner         = new ALM_Domain_Scanner( new ALM_Domain_Checker(), new ALM_Candidate_Classifier() );
		$converter              = new ALM_Link_Converter( $providers, $adapters );
		$network_signal_scanner = new ALM_Network_Signal_Scanner();
		$shortener_scanner      = new ALM_Shortener_Scanner( new ALM_Shortener_Resolver(), $providers );
		$thumbnail_fetcher      = new ALM_Thumbnail_Fetcher();
		$link_health_scanner    = new ALM_Link_Health_Scanner( new ALM_Link_Health_Checker() );
		$dashboard_data         = new ALM_Dashboard_Data( $providers, $domain_scanner, $shortener_scanner, $link_health_scanner );

		$admin = new ALM_Admin( $scanner, $providers, $adapters, $domain_scanner, $converter, $network_signal_scanner, $shortener_scanner, $thumbnail_fetcher, $link_health_scanner, $dashboard_data );
		$admin->init();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_POST['nonce'] = wp_create_nonce( ALM_Admin::NONCE_ACTION );
	}

	/**
	 * @param string $url
	 * @param string $status
	 * @param string|null $dead_confirmed_at
	 * @param int    $post_id
	 * @param string $location
	 * @return int
	 */
	private function insert_link( $url, $status, $dead_confirmed_at, $post_id, $location ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'           => $post_id,
				'provider'          => 'unclassified',
				'adapter'           => 'post_content',
				'location'          => $location,
				'url'               => $url,
				'anchor_text'       => 'link',
				'status'            => $status,
				'first_seen'        => $now,
				'last_seen'         => $now,
				'dead_confirmed_at' => $dead_confirmed_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_a_confirmed_dead_link_can_be_removed() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://confirmed-dead.example.com/product">boots</a> now.</p>',
			)
		);

		$id = $this->insert_link( 'https://confirmed-dead.example.com/product', ALM_Install::STATUS_STALE, current_time( 'mysql' ), $post_id, '0' );

		$_POST['id'] = $id;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_REMOVE_LINK_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'A genuinely confirmed-dead link must be removable.' );
	}

	/**
	 * The real bug: a merely not-rediscovered, never-confirmed-dead
	 * link (status=stale, dead_confirmed_at NULL) must be rejected,
	 * even though the old code's check (status===stale alone) would
	 * have let it through.
	 */
	public function test_a_merely_stale_never_confirmed_dead_link_is_rejected() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://merely-stale.example.com/product">boots</a> now.</p>',
			)
		);

		$id = $this->insert_link( 'https://merely-stale.example.com/product', ALM_Install::STATUS_STALE, null, $post_id, '0' );

		$_POST['id'] = $id;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_REMOVE_LINK_ACTION );
			$this->fail( 'Expected wp_die() to interrupt execution, same as a real AJAX error response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected -- wp_send_json_error() echoes real JSON before
			// dying, same as wp_send_json_success() does.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Only a confirmed-dead link can be removed this way.', $response['data']['message'] );

		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$still_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) );
		$this->assertSame( '1', $still_exists, 'Rejected -- the tracking row must be untouched.' );
	}

	public function test_a_real_candidate_link_is_rejected() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://still-a-candidate.example.com/product">boots</a> now.</p>',
			)
		);

		$id = $this->insert_link( 'https://still-a-candidate.example.com/product', ALM_Install::STATUS_CONVERTIBLE, null, $post_id, '0' );

		$_POST['id'] = $id;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_REMOVE_LINK_ACTION );
			$this->fail( 'Expected wp_die() to interrupt execution, same as a real AJAX error response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected -- wp_send_json_error() echoes real JSON before
			// dying, same as wp_send_json_success() does.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
	}
}
