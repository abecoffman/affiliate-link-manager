<?php
/**
 * Integration tests for the AJAX-handler side of ALM_Background_Runner
 * bookkeeping (ALM_Admin::track_batch_run(), called from each of the
 * four handle_*_batch() methods) -- real WP_Ajax_UnitTestCase-driven
 * requests through the real wp_ajax_* hooks, real $wpdb, real options/
 * cron state. Together with BackgroundRunnerIntegrationTest.php (the
 * cron-continuation side of the same state), this proves a run started
 * from a real click leaves the state alm_continue_batch_run() needs to
 * carry it forward, exactly as the "background tasks survive
 * navigating away" plan intends.
 *
 * ALM_Admin is never constructed by the plugin's own bootstrap in this
 * test environment (it only happens `if ( is_admin() )` in alm_init(),
 * and a WP_UnitTestCase request isn't one) -- constructed directly
 * here instead, same reason LinksListTableIntegrationTest.php has its
 * own require_once guard for ALM_Links_List_Table.
 *
 * @package ALM
 */

/**
 * @covers ALM_Admin
 */
class AjaxBatchRunIntegrationTest extends WP_Ajax_UnitTestCase {

	private const TASK_IDS = array( 'scan', 'domains', 'shorteners', 'link_health' );

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		$this->reset_task_state();

		$providers              = new ALM_Provider_Registry();
		$adapters               = new ALM_Adapter_Registry();
		$scanner                = new ALM_Scanner( $adapters, $providers, new ALM_Candidate_Classifier() );
		$domain_scanner         = new ALM_Domain_Scanner( new ALM_Domain_Checker(), new ALM_Candidate_Classifier() );
		$converter              = new ALM_Link_Converter( $providers, $adapters );
		$network_signal_scanner = new ALM_Network_Signal_Scanner();
		$shortener_scanner      = new ALM_Shortener_Scanner( new ALM_Shortener_Resolver(), $providers );
		$thumbnail_fetcher      = new ALM_Thumbnail_Fetcher();
		$link_health_scanner    = new ALM_Link_Health_Scanner( new NoSleepLinkHealthChecker() );
		$dashboard_data         = new ALM_Dashboard_Data( $providers, $domain_scanner, $shortener_scanner, $link_health_scanner );

		$admin = new ALM_Admin( $scanner, $providers, $adapters, $domain_scanner, $converter, $network_signal_scanner, $shortener_scanner, $thumbnail_fetcher, $link_health_scanner, $dashboard_data );
		$admin->init();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_POST['nonce'] = wp_create_nonce( ALM_Admin::NONCE_ACTION );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		$this->reset_task_state();
		parent::tear_down();
	}

	/**
	 * @return void
	 */
	private function reset_task_state() {
		foreach ( self::TASK_IDS as $task_id ) {
			ALM_Background_Runner::clear_state( $task_id );
			ALM_Background_Runner::unschedule( $task_id );
			delete_transient( 'alm_' . $task_id . '_batch_lock' );
		}
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
				'post_id'       => 1,
				'provider'      => 'unclassified',
				'adapter'       => 'post_content',
				'location'      => (string) wp_rand(),
				'url'           => $url,
				'anchor_text'   => 'link',
				'category'      => ALM_Install::CATEGORY_CANDIDATE,
				'classified_at' => $now,
				'first_seen'    => $now,
				'last_seen'     => $now,
			)
		);

		return (int) $wpdb->insert_id;
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

	public function test_the_first_click_marks_a_run_active_and_primes_the_continuation_tick() {
		$urls = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$urls[] = "https://ajax-alive-{$i}.example.com/product";
		}
		foreach ( $urls as $url ) {
			$this->insert_link( $url );
		}
		$this->fake_http_responses( array_fill_keys( $urls, 200 ) );

		$_POST['first'] = '1';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_LINK_HEALTH_ACTION );
			$this->fail( 'Expected wp_die() to interrupt execution, same as a real AJAX response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected -- wp_send_json_success() always ends this way.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 5, $response['data']['checked'], 'Batch size for link_health is 5.' );

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertTrue( $state['active'] );
		$this->assertSame( 5, $state['processed'] );
		$this->assertNotFalse( wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ), 'Primed immediately on the very first click, not only after a second round-trip.' );
	}

	public function test_a_resumed_click_accumulates_progress_without_restarting_the_count() {
		// 13, not 8 -- two batches of 5 must still leave 3 remaining
		// (not done) so the run is still readable via get_state()
		// afterward; reaching done here would clear_state() back to
		// defaults, which is exactly what test_reaching_done_clears_state
		// below is for.
		$urls = array();
		for ( $i = 0; $i < 13; $i++ ) {
			$urls[] = "https://ajax-resume-{$i}.example.com/product";
		}
		foreach ( $urls as $url ) {
			$this->insert_link( $url );
		}
		$this->fake_http_responses( array_fill_keys( $urls, 200 ) );

		$_POST['first'] = '1';
		try {
			$this->_handleAjax( ALM_Admin::AJAX_LINK_HEALTH_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$_POST['first'] = '0';
		try {
			$this->_handleAjax( ALM_Admin::AJAX_LINK_HEALTH_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertTrue( $state['active'], '3 of 13 still remain -- must not be done yet.' );
		$this->assertSame( 10, $state['processed'], 'Two batches of 5 accumulated, not overwritten by the second call.' );
	}

	public function test_reaching_done_clears_state_and_cancels_the_continuation_tick() {
		$this->insert_link( 'https://ajax-single.example.com/product' );
		$this->fake_http_responses( array( 'https://ajax-single.example.com/product' => 200 ) );

		$_POST['first'] = '1';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_LINK_HEALTH_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['data']['done'], 'Only 1 candidate existed -- must finish in a single batch.' );

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertFalse( $state['active'] );
		$this->assertFalse( (bool) wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ), 'Nothing left to continue -- a stray scheduled tick would be a real leak.' );
	}

	public function test_scan_uses_offset_zero_as_its_own_first_of_run_signal_not_a_first_param() {
		// More than one batch's worth (20) of posts -- a single post
		// would finish in one call, which (correctly) clears state
		// straight back to inactive and never actually exercises the
		// "was this the first call" branch this test is about.
		for ( $i = 0; $i < 22; $i++ ) {
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => '<p>nothing to find here</p>',
				)
			);
		}

		// Deliberately no 'first' POST field at all -- scan has never
		// used one (see ALM_Admin::handle_scan_batch()), offset===0
		// already means the same thing.
		unset( $_POST['first'] );
		$_POST['offset'] = '0';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_SCAN_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$state = ALM_Background_Runner::get_state( 'scan' );
		$this->assertTrue( $state['active'], 'offset=0 must be treated as a first-of-run call for scan, exactly like the other three tasks\' explicit first=1.' );
	}
}
