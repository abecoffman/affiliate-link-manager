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

	private const TASK_IDS = array( 'scan', 'domains', 'shorteners', 'link_health', 'incremental_scan' );

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

	/**
	 * The real, live-diagnosed bug this round fixes: a batch call that
	 * blows up partway through must not strand the run with nothing
	 * left to continue it. A genuine uncatchable PHP fatal (the real
	 * failure found live -- max_execution_time exceeded) can't be
	 * reproduced inside a PHPUnit process without killing the test
	 * runner itself; throwing a real exception from inside the fake
	 * pre_http_request response is the closest faithful proxy available
	 * -- it authentically propagates up through wp_remote_get() and the
	 * scanner's own check_batch(), same call path a real fatal would
	 * blow through, and directly proves the ordering fix (schedule
	 * *before* the risky call, not after) actually took effect rather
	 * than just asserting it by reading the source.
	 */
	public function test_a_tick_that_blows_up_mid_batch_still_leaves_a_continuation_scheduled() {
		$this->insert_link( 'https://blows-up.example.com/product' );

		add_filter(
			'pre_http_request',
			function () {
				throw new \Exception( 'simulated mid-batch failure' );
			}
		);

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

		try {
			alm_continue_batch_run( 'link_health' );
			$this->fail( 'Expected the simulated failure to propagate, same as a real fatal would abort this function.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'simulated mid-batch failure', $e->getMessage() );
		}

		$this->assertNotFalse(
			wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ),
			'The next tick must already have been scheduled before the risky call ran -- otherwise this exact failure (found live on honestlywtf) strands the run with nothing left to continue it.'
		);
	}

	public function test_watchdog_reprimes_an_active_task_with_nothing_scheduled() {
		ALM_Background_Runner::save_state(
			'domains',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 42,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 3,
				'stalled'          => false,
			)
		);
		$this->assertFalse( (bool) wp_next_scheduled( 'alm_continue_batch_run', array( 'domains' ) ), 'Sanity check: genuinely nothing scheduled yet, same as the real stuck state found live.' );

		alm_watchdog_reprime_stuck_tasks();

		$this->assertNotFalse( wp_next_scheduled( 'alm_continue_batch_run', array( 'domains' ) ), 'Must re-prime a stranded run.' );
	}

	public function test_watchdog_does_not_duplicate_an_already_scheduled_tick() {
		ALM_Background_Runner::save_state(
			'shorteners',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 1,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 1,
				'stalled'          => false,
			)
		);
		ALM_Background_Runner::schedule_next_tick( 'shorteners', 30 );
		$already_scheduled_for = wp_next_scheduled( 'alm_continue_batch_run', array( 'shorteners' ) );

		alm_watchdog_reprime_stuck_tasks();

		$this->assertSame( $already_scheduled_for, wp_next_scheduled( 'alm_continue_batch_run', array( 'shorteners' ) ), 'A real, already-pending tick must be left exactly as it is -- no duplicate/earlier one alongside it.' );
	}

	public function test_watchdog_leaves_inactive_and_stalled_tasks_alone() {
		// Never started -- the watchdog only ever continues a run that
		// was already explicitly started, same rule alm_continue_batch_run()
		// itself follows.
		alm_watchdog_reprime_stuck_tasks();
		$this->assertFalse( (bool) wp_next_scheduled( 'alm_continue_batch_run', array( 'scan' ) ) );

		// Stalled -- a real defensive cap that already fired means this
		// run needs a human, not another silent auto-retry.
		ALM_Background_Runner::save_state(
			'link_health',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 999,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => ALM_Background_Runner::MAX_RESCHEDULES,
				'stalled'          => true,
			)
		);

		alm_watchdog_reprime_stuck_tasks();

		$this->assertFalse( (bool) wp_next_scheduled( 'alm_continue_batch_run', array( 'link_health' ) ), 'A stalled run must not be silently re-primed.' );
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

	/**
	 * @param int    $post_id
	 * @param string $mysql_datetime
	 * @return void
	 */
	private function backdate_post( $post_id, $mysql_datetime ) {
		global $wpdb;
		// A direct DB write, not wp_update_post() -- that would just
		// reset post_modified(_gmt) back to "now" on save, defeating
		// the whole point of backdating it.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $mysql_datetime,
				'post_modified_gmt' => $mysql_datetime,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
	}

	public function test_scan_incremental_batch_only_covers_posts_modified_since_the_checkpoint() {
		$old_post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://old-post-retailer.example.com/product">boots</a>.</p>',
			)
		);
		$this->backdate_post( $old_post, '2020-01-01 00:00:00' );

		$recent_post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://recent-post-retailer.example.com/product">shoes</a>.</p>',
			)
		);
		// A fresh post's post_modified is already "now" -- no backdating
		// needed for this one.

		$scanner = new ALM_Scanner( new ALM_Adapter_Registry(), new ALM_Provider_Registry(), new ALM_Candidate_Classifier() );
		$scanner->scan_incremental_batch( 0, 20, '2024-01-01 00:00:00' );

		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$scanned_post_ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$table}" );

		$this->assertContains( $recent_post, array_map( 'intval', $scanned_post_ids ), 'The recently-modified post must be covered.' );
		$this->assertNotContains( $old_post, array_map( 'intval', $scanned_post_ids ), 'A post modified before the checkpoint must not be touched by an incremental run.' );
	}

	public function test_scan_incremental_batch_never_sweeps_stale_links() {
		// A real candidate tied to a post an incremental run never
		// covers (old, unmodified) -- if the sweep ran here, this
		// would incorrectly flip to stale despite nothing about it
		// actually having changed.
		$untouched_post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://untouched-retailer.example.com/product">bag</a>.</p>',
			)
		);
		$this->backdate_post( $untouched_post, '2020-01-01 00:00:00' );

		global $wpdb;
		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'     => $untouched_post,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://untouched-retailer.example.com/product',
				'anchor_text' => 'bag',
				'status'      => ALM_Install::STATUS_CONVERTIBLE,
				'first_seen'  => '2020-01-01 00:00:00',
				'last_seen'   => '2020-01-01 00:00:00',
			)
		);

		$recent_post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://recent-retailer.example.com/product">shoes</a>.</p>',
			)
		);

		$scanner = new ALM_Scanner( new ALM_Adapter_Registry(), new ALM_Provider_Registry(), new ALM_Candidate_Classifier() );
		$scanner->scan_incremental_batch( 0, 20, '2024-01-01 00:00:00' );

		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE post_id = %d", $untouched_post ) );
		$this->assertSame( ALM_Install::STATUS_CONVERTIBLE, $status, 'An incremental run must never sweep links in posts it did not cover.' );
	}

	public function test_scan_incremental_batch_checkpoints_to_when_the_run_started_not_when_it_finished() {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://checkpoint-retailer.example.com/product">hat</a>.</p>',
			)
		);

		// Simulates a continuation tick (offset > 0) of a run that
		// began earlier -- update_option('alm_incremental_scan_started_at')
		// only happens on offset===0 (see the top of scan_incremental_batch()
		// itself), so calling with offset=0 here would just overwrite this
		// pre-seeded value with "now" before ever reaching the $done
		// branch this test is actually about.
		update_option( 'alm_incremental_scan_started_at', '2024-06-01 12:00:00' );

		$scanner = new ALM_Scanner( new ALM_Adapter_Registry(), new ALM_Provider_Registry(), new ALM_Candidate_Classifier() );
		// offset=1 with a batch_size larger than any possible remaining
		// result set -- done=true on this single call, without ever
		// touching alm_incremental_scan_started_at.
		$scanner->scan_incremental_batch( 1, 500, '2024-01-01 00:00:00' );

		$this->assertSame(
			'2024-06-01 12:00:00',
			get_option( 'alm_last_incremental_scan_time' ),
			'Must checkpoint to when the run started, not "now" -- a post modified while the run was still in progress must still be caught by the next run.'
		);
	}

	public function test_watchdog_starts_a_new_incremental_scan_when_a_post_has_been_modified() {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://watchdog-start-retailer.example.com/product">hat</a>.</p>',
			)
		);
		// Fresh post's post_modified is "now" -- well after the default
		// epoch checkpoint used when alm_last_incremental_scan_time has
		// never been set.

		$this->assertFalse( $this->get_incremental_state()['active'] );

		alm_watchdog_maybe_start_incremental_scan();

		$state = $this->get_incremental_state();
		$this->assertTrue( $state['active'] );
		$this->assertNotFalse( wp_next_scheduled( 'alm_continue_batch_run', array( 'incremental_scan' ) ), 'The first tick must be primed immediately, same as every other task\'s first click.' );
	}

	public function test_watchdog_does_not_start_a_run_when_nothing_has_changed() {
		// Checkpoint set to right now -- nothing in this fresh test DB
		// can have a post_modified_gmt after this instant.
		update_option( 'alm_last_incremental_scan_time', current_time( 'mysql', true ) );

		alm_watchdog_maybe_start_incremental_scan();

		$this->assertFalse( $this->get_incremental_state()['active'], 'Nothing changed -- must not spin up a background run for zero work.' );
	}

	public function test_watchdog_does_not_start_a_second_run_when_one_is_already_active() {
		ALM_Background_Runner::save_state(
			'incremental_scan',
			array(
				'active'           => true,
				'cursor'           => 40,
				'processed'        => 40,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 2,
				'stalled'          => false,
			)
		);

		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://watchdog-already-active.example.com/product">hat</a>.</p>',
			)
		);

		alm_watchdog_maybe_start_incremental_scan();

		$state = $this->get_incremental_state();
		$this->assertSame( 40, $state['cursor'], 'An already-active run must not be reset back to a fresh start.' );
	}

	public function test_watchdog_respects_the_incremental_scan_enabled_filter() {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://watchdog-disabled-retailer.example.com/product">hat</a>.</p>',
			)
		);

		add_filter( 'alm_incremental_scan_enabled', '__return_false' );
		alm_watchdog_maybe_start_incremental_scan();
		remove_filter( 'alm_incremental_scan_enabled', '__return_false' );

		$this->assertFalse( $this->get_incremental_state()['active'], 'The escape hatch must actually prevent a new run from starting.' );
	}

	/**
	 * @return array{active:bool,cursor:int,processed:int,started_at:string,reschedule_count:int,stalled:bool}
	 */
	private function get_incremental_state() {
		return ALM_Background_Runner::get_state( 'incremental_scan' );
	}
}
