<?php
/**
 * Integration tests for alm_continue_batch_run() (the WP-Cron
 * continuation callback in the main plugin file) and
 * ALM_Background_Runner against a real WordPress install -- real
 * options, real transients, and real wp_next_scheduled()/
 * wp_schedule_single_event() state, none of which the fast Brain
 * Monkey unit tier (tests/unit/BackgroundRunnerTest.php) can exercise.
 * Together with AjaxBatchRunIntegrationTest.php (the AJAX-handler side
 * of the same state), this proves a batch task's run-state survives
 * exactly the way the "background tasks survive navigating away" plan
 * intends: real forward progress via alm_continue_batch_run() alone,
 * with no AJAX call involved at all.
 *
 * @package ALM
 */

/**
 * @covers ALM_Background_Runner
 * @covers ::alm_continue_batch_run
 */
class BackgroundRunnerIntegrationTest extends WP_UnitTestCase {

	private const TASK_IDS = array( 'scan', 'domains', 'shorteners', 'link_health' );

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		$this->reset_task_state();
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
	 * @param string $status
	 * @return int Inserted row id.
	 */
	private function insert_link( $url, $status = ALM_Install::STATUS_CONVERTIBLE ) {
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

	public function test_a_task_with_no_active_run_is_left_completely_untouched() {
		// Never started -- must not fabricate a run, schedule anything,
		// or fatal on a task it's never heard of being active.
		alm_continue_batch_run( 'link_health' );

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertFalse( $state['active'] );
		$this->assertFalse( (bool) wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ) );
	}

	public function test_a_tick_advances_progress_and_reschedules_itself_when_not_yet_done() {
		// Batch size for link_health is 5 -- 7 real, live candidates
		// means the first tick can't finish in one call.
		$urls = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$urls[] = "https://alive-{$i}.example.com/product";
		}
		foreach ( $urls as $url ) {
			$this->insert_link( $url );
		}
		$this->fake_http_responses( array_fill_keys( $urls, 200 ) );

		// Simulate what the first AJAX call would have already done --
		// this test is specifically about the cron continuation side,
		// see AjaxBatchRunIntegrationTest for the handler side of the
		// same bookkeeping.
		ALM_Background_Runner::save_state(
			'link_health',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 0,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 0,
				'stalled'          => false,
			)
		);

		alm_continue_batch_run( 'link_health' );

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertTrue( $state['active'], 'Still going -- 2 of 7 remain unchecked.' );
		$this->assertSame( 5, $state['processed'] );
		$this->assertSame( 1, $state['reschedule_count'] );
		$this->assertNotFalse( wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ), 'Must reschedule itself -- this is the whole point.' );

		// A second tick finishes the remaining 2 and must clear
		// everything back out.
		alm_continue_batch_run( 'link_health' );

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertFalse( $state['active'] );
		$this->assertFalse( (bool) wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ), 'Done -- nothing left to continue.' );
		$this->assertNotEmpty( get_option( 'alm_last_link_health_time', '' ), 'Completion options still get set exactly as the AJAX path already does.' );
	}

	public function test_a_locked_out_tick_reschedules_without_processing_anything() {
		$this->insert_link( 'https://alive.example.com/product' );
		$this->fake_http_responses( array( 'https://alive.example.com/product' => 200 ) );

		ALM_Background_Runner::save_state(
			'link_health',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 3,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 1,
				'stalled'          => false,
			)
		);
		// Simulate a still-open browser tab's own AJAX call already
		// holding the lock for this exact batch.
		ALM_Background_Runner::acquire_lock( 'link_health' );

		alm_continue_batch_run( 'link_health' );

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertSame( 3, $state['processed'], 'Locked out -- must not have touched anything.' );
		$this->assertSame( 1, $state['reschedule_count'], 'Not incremented -- this tick never actually ran a batch.' );
		$this->assertNotFalse( wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ), 'Backs off and tries again shortly rather than giving up.' );
	}

	public function test_reschedule_count_past_the_safety_cap_stops_rescheduling_and_marks_stalled() {
		// 7, not 1 -- a single candidate would finish (done=true) in
		// this one tick and return early via the done branch before
		// the reschedule-count check is ever reached; this needs the
		// tick's own batch to stay not-done so that check actually runs.
		$urls = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$urls[] = "https://stalled-cap-{$i}.example.com/product";
		}
		foreach ( $urls as $url ) {
			$this->insert_link( $url );
		}
		$this->fake_http_responses( array_fill_keys( $urls, 200 ) );

		ALM_Background_Runner::save_state(
			'link_health',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 999,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => ALM_Background_Runner::MAX_RESCHEDULES,
				'stalled'          => false,
			)
		);

		alm_continue_batch_run( 'link_health' );

		$state = ALM_Background_Runner::get_state( 'link_health' );
		$this->assertTrue( $state['stalled'], 'A real defensive cap, not expected to bind in normal use -- but must actually bind when it is reached.' );
		$this->assertFalse( (bool) wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ), 'Must stop rescheduling once stalled -- that is the entire point of the cap.' );
	}

	public function test_scan_task_persists_and_advances_its_offset_cursor() {
		// Batch size for scan is 20 -- 22 posts means the first tick
		// can't finish in one call, unlike the DB-state-driven tasks
		// above; scan is the one task with a real cursor to thread
		// through, not just a "still unchecked" DB query.
		for ( $i = 0; $i < 22; $i++ ) {
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => '<p>plain post, nothing to find</p>',
				)
			);
		}

		ALM_Background_Runner::save_state(
			'scan',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 0,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 0,
				'stalled'          => false,
			)
		);

		alm_continue_batch_run( 'scan' );

		$state = ALM_Background_Runner::get_state( 'scan' );
		$this->assertTrue( $state['active'] );
		$this->assertSame( 20, $state['cursor'] );
		$this->assertSame( 20, $state['processed'] );

		alm_continue_batch_run( 'scan' );

		$state = ALM_Background_Runner::get_state( 'scan' );
		$this->assertFalse( $state['active'] );
		$this->assertNotEmpty( get_option( 'alm_last_scan_time', '' ), 'ALM_Scanner::scan_batch() itself never sets this -- alm_continue_batch_run() must, exactly mirroring ALM_Admin::handle_scan_batch()\'s own line.' );
	}
}
