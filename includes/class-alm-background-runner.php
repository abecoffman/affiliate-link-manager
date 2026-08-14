<?php
/**
 * Shared state/lock/schedule primitives for the four Dashboard batch
 * tasks (scan/domains/shorteners/link_health) -- lets a run started
 * from the AJAX-driven Dashboard button keep making progress via
 * WP-Cron even after the browser tab that started it closes, instead
 * of the run living only in that page's JS closure. Deliberately just
 * the generic bookkeeping (is a run active, where did it get to, is a
 * batch already in flight, when should the next one fire) -- the
 * actual "how do I run one batch of task X" logic stays where it
 * already lived (ALM_Admin's AJAX handlers, alm_continue_batch_run()
 * in the main plugin file), matching this codebase's existing
 * preference for explicit per-scanner call sites over a generic
 * do-everything framework.
 *
 * Does NOT make any of these tasks run with zero user action -- a
 * task only has state here once a run has actually been started
 * (an AJAX click, or the existing daily alm_domain_recheck_cron).
 * See the "background tasks survive navigating away" plan for the
 * full reasoning.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Background_Runner {

	const CONTINUE_HOOK = 'alm_continue_batch_run';

	// Single source of truth for batch size/reschedule delay per task,
	// shared by both entry points that need them (ALM_Admin's AJAX
	// handlers and alm_continue_batch_run() in the main plugin file)
	// so the two can never quietly drift apart. Delays are gentle on
	// purpose -- three of these (domains/shorteners/link_health) make
	// real outbound HTTP requests to third-party sites, same "no reason
	// to rush" reasoning as the existing daily alm_domain_recheck_cron's
	// own comments; link_health is slowest per item given its
	// two-attempt dead-retry. scan/incremental_scan are both pure DB/
	// HTML-parsing work, no outbound HTTP at all, hence the much
	// shorter delay -- incremental_scan uses scan's own values exactly,
	// same underlying cost shape, just a narrower post set.
	const TASK_BATCH_SIZES = array(
		'scan'             => 20,
		'domains'          => 5,
		'shorteners'       => 5,
		'link_health'      => 5,
		'incremental_scan' => 20,
	);

	const TASK_RESCHEDULE_DELAYS = array(
		'scan'             => 2,
		'domains'          => 8,
		'shorteners'       => 8,
		'link_health'      => 10,
		'incremental_scan' => 2,
	);

	// Comfortably above the largest realistic backlog at today's batch
	// sizes (e.g. ~8,800 pending Candidates / 5 per batch is well
	// under 2,000 ticks) -- a real safety valve against a genuine
	// runaway/stuck-batch bug silently rescheduling forever, not a
	// limit expected to ever actually bind in normal use.
	const MAX_RESCHEDULES = 5000;

	const LOCK_TTL = 60;

	/**
	 * @param string $task_id
	 * @return array{active:bool,cursor:int,processed:int,started_at:string,reschedule_count:int,stalled:bool}
	 */
	public static function get_state( $task_id ) {
		$defaults = array(
			'active'           => false,
			'cursor'           => 0,
			'processed'        => 0,
			'started_at'       => '',
			'reschedule_count' => 0,
			'stalled'          => false,
		);

		$state = get_option( self::option_name( $task_id ), array() );

		if ( ! is_array( $state ) ) {
			return $defaults;
		}

		return array_merge( $defaults, $state );
	}

	/**
	 * @param string $task_id
	 * @param array  $state
	 * @return void
	 */
	public static function save_state( $task_id, array $state ) {
		update_option( self::option_name( $task_id ), $state, false );
	}

	/**
	 * @param string $task_id
	 * @return void
	 */
	public static function clear_state( $task_id ) {
		delete_option( self::option_name( $task_id ) );
	}

	/**
	 * A short-lived lock so a WP-Cron tick and a still-open browser
	 * tab's own AJAX call can never process the same batch twice --
	 * whichever gets here first proceeds, the other backs off (see
	 * callers: the AJAX handlers just skip re-saving state on a miss,
	 * alm_continue_batch_run() reschedules a few seconds out instead).
	 *
	 * @param string $task_id
	 * @return bool True if the lock was acquired.
	 */
	public static function acquire_lock( $task_id ) {
		if ( false !== get_transient( self::lock_name( $task_id ) ) ) {
			return false;
		}

		set_transient( self::lock_name( $task_id ), 1, self::LOCK_TTL );
		return true;
	}

	/**
	 * @param string $task_id
	 * @return void
	 */
	public static function release_lock( $task_id ) {
		delete_transient( self::lock_name( $task_id ) );
	}

	/**
	 * @param string $task_id
	 * @param int    $delay_seconds
	 * @return void
	 */
	public static function schedule_next_tick( $task_id, $delay_seconds ) {
		wp_schedule_single_event( time() + $delay_seconds, self::CONTINUE_HOOK, array( $task_id ) );
	}

	/**
	 * @param string $task_id
	 * @return void
	 */
	public static function unschedule( $task_id ) {
		wp_clear_scheduled_hook( self::CONTINUE_HOOK, array( $task_id ) );
	}

	/**
	 * @param string $task_id
	 * @return string
	 */
	private static function option_name( $task_id ) {
		return 'alm_' . $task_id . '_run_state';
	}

	/**
	 * @param string $task_id
	 * @return string
	 */
	private static function lock_name( $task_id ) {
		return 'alm_' . $task_id . '_batch_lock';
	}
}
