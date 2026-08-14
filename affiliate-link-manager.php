<?php
/**
 * Plugin Name: Affiliate Link Manager
 * Description: Finds, classifies, and manages affiliate links across post content. Built on a pluggable network-provider architecture (ShopMy to start, more networks can register via the alm_register_providers filter) and a content-storage adapter architecture (plain post content by default, Beaver Builder when active, more via alm_register_content_adapters) so it works regardless of which affiliate networks or page builder a site uses.
 * Version:     1.19.1
 * Author:      Abe Coffman
 * License:     GPL-2.0-or-later
 * Text Domain: affiliate-link-manager
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALM_VERSION', '1.19.1' );
define( 'ALM_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALM_URL', plugin_dir_url( __FILE__ ) );
define( 'ALM_FILE', __FILE__ );

require_once ALM_PATH . 'includes/trait-alm-html-fragment.php';

require_once ALM_PATH . 'includes/class-alm-provider.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-shopmy.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-rewardstyle.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-amazon.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-cj.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-rakuten.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-shopstyle.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-generic.php';
require_once ALM_PATH . 'includes/class-alm-provider-registry.php';

require_once ALM_PATH . 'includes/class-alm-content-adapter.php';
require_once ALM_PATH . 'includes/class-alm-adapter-post-content.php';
require_once ALM_PATH . 'includes/class-alm-adapter-beaver-builder.php';
require_once ALM_PATH . 'includes/class-alm-adapter-registry.php';

require_once ALM_PATH . 'includes/class-alm-install.php';
require_once ALM_PATH . 'includes/class-alm-candidate-classifier.php';
require_once ALM_PATH . 'includes/class-alm-domain-checker.php';
require_once ALM_PATH . 'includes/class-alm-domain-scanner.php';
require_once ALM_PATH . 'includes/class-alm-scanner.php';
require_once ALM_PATH . 'includes/class-alm-link-converter.php';
require_once ALM_PATH . 'includes/class-alm-network-signal-scanner.php';
require_once ALM_PATH . 'includes/class-alm-shortener-resolver.php';
require_once ALM_PATH . 'includes/class-alm-shortener-scanner.php';
require_once ALM_PATH . 'includes/class-alm-thumbnail-fetcher.php';
require_once ALM_PATH . 'includes/class-alm-link-health-checker.php';
require_once ALM_PATH . 'includes/class-alm-link-health-scanner.php';
require_once ALM_PATH . 'includes/class-alm-background-runner.php';
require_once ALM_PATH . 'includes/class-alm-admin.php';

register_activation_hook( __FILE__, array( 'ALM_Install', 'activate' ) );
// The daily cron event itself is scheduled from ALM_Install::maybe_upgrade()
// (runs on every boot, not just an actual activate-toggle) -- see its
// docblock for why activation alone isn't enough here.
register_deactivation_hook( __FILE__, 'alm_unschedule_domain_recheck_cron' );

/**
 * @return void
 */
function alm_unschedule_domain_recheck_cron() {
	wp_clear_scheduled_hook( 'alm_domain_recheck_cron' );
	wp_clear_scheduled_hook( 'alm_watchdog_cron' );

	// Also clear any pending self-rescheduled continuation tick left
	// over from an in-flight background run for any of the four tasks
	// -- see ALM_Background_Runner/alm_continue_batch_run() below.
	foreach ( array_keys( ALM_Background_Runner::TASK_BATCH_SIZES ) as $task_id ) {
		ALM_Background_Runner::unschedule( $task_id );
	}
}

/**
 * @return void
 */
function alm_run_domain_recheck_cron() {
	$scanner = new ALM_Domain_Scanner( new ALM_Domain_Checker(), new ALM_Candidate_Classifier() );
	// Small and slow on purpose: a background job with nobody watching
	// a progress bar has no reason to rush, and a low batch size keeps
	// this well clear of a shared host's max_execution_time even on a
	// slow day for the domains being checked.
	$scanner->check_batch( 20 );

	// Piggybacks the same daily cron event rather than adding a second
	// one for a very similar "housekeeping nobody's watching" job --
	// see ALM_Link_Health_Scanner's own docblock for why this needs to
	// keep re-running rather than being a one-off: a link marked stale
	// here stays that way only until the next full Scan naturally
	// rediscovers it in post content, at which point it needs this same
	// cron to catch it again.
	$health_scanner = new ALM_Link_Health_Scanner( new ALM_Link_Health_Checker() );
	$health_scanner->check_batch( 10, true );
}
add_action( 'alm_domain_recheck_cron', 'alm_run_domain_recheck_cron' );

/**
 * WP-Cron continuation for a batch task already started via the
 * Dashboard's own AJAX-driven button (or, in principle, any other
 * future entry point) -- runs exactly one more batch of $task_id and,
 * if there's still more to do, reschedules itself a short while out,
 * so the run keeps moving via ALM_Background_Runner's persisted state
 * even after the tab that started it closes. Registered unconditionally
 * below, not from inside alm_init()'s `is_admin()` branch, since a
 * real WP-Cron request is never an admin context -- ALM_Admin is never
 * constructed for it, so this can't be a method on that class; it
 * mirrors alm_run_domain_recheck_cron() above instead, constructing
 * whichever one scanner it needs fresh, same as that function already
 * does.
 *
 * Deliberately does nothing if no run is active for this task (e.g. a
 * stale scheduled event left over after a run already finished by
 * some other path) -- this function only ever continues a run that
 * was already explicitly started, never starts one on its own.
 *
 * Real, live-diagnosed failure mode this guards against: a single
 * health/domain check can legitimately take up to ~40s (5 redirect
 * hops x an 8s timeout each -- WP core's own Requests library gives
 * each hop a fresh timeout budget, not one shared ceiling for the
 * whole chain, confirmed by reading wp-includes/Requests/src/Requests.php
 * directly), and ALM_Link_Health_Checker/ALM_Shortener_Resolver each
 * do a real second attempt before trusting a "looks dead" result --
 * worst case ~82s for one single item. A shared-hosting php.ini's
 * generic max_execution_time (30s is common, confirmed on this exact
 * host) turns that into an uncatchable PHP fatal mid-batch -- not a
 * WP_Error, not a Throwable, nothing try/catch can intercept. Found
 * live: both this plugin's real Check Domains and Check Link Health
 * runs on honestlywtf were stuck for a full day this way, with
 * wp_next_scheduled() showing nothing scheduled despite still being
 * marked active, because the fatal happened after acquire_lock() but
 * before the old code ever reached its own schedule_next_tick() call.
 *
 * Two changes directly answer that: set_time_limit() below removes
 * the actual trigger (this cron-driven path has no live user waiting
 * on it, so a generous budget costs nothing); scheduling the next
 * tick *before* running the risky batch (not after) means even some
 * future, different failure mode can't strand the chain the same way
 * -- the next tick already exists in WP-Cron's own table by the time
 * anything risky runs. See also alm_watchdog_reprime_stuck_tasks()
 * below, the third layer for whatever these two still miss.
 *
 * @param string $task_id One of ALM_Background_Runner::TASK_BATCH_SIZES's keys.
 * @return void
 */
function alm_continue_batch_run( $task_id ) {
	// Nobody is watching a WP-Cron request in real time -- generous on
	// purpose, comfortably above the ~82s real worst case above, well
	// clear of a shared host's default (often 30s, the actual trigger
	// found live). Not literally unbounded: still a real, finite guard
	// against a truly runaway/unexpected hang.
	set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- deliberate, see the docblock above for the real failure this fixes.

	$state = ALM_Background_Runner::get_state( $task_id );

	if ( ! $state['active'] || $state['stalled'] ) {
		return;
	}

	$delay = isset( ALM_Background_Runner::TASK_RESCHEDULE_DELAYS[ $task_id ] )
		? ALM_Background_Runner::TASK_RESCHEDULE_DELAYS[ $task_id ]
		: 10;

	if ( ! ALM_Background_Runner::acquire_lock( $task_id ) ) {
		// A still-open browser tab's own AJAX call already has this
		// batch in flight -- back off and try again shortly rather
		// than race it for the same rows.
		ALM_Background_Runner::schedule_next_tick( $task_id, $delay );
		return;
	}

	// Scheduled *before* the risky batch dispatch below, not after --
	// see the docblock above. The $done/stalled branches below cancel
	// this again via unschedule() once there's nothing left to
	// continue; the normal "still going" path at the very end
	// deliberately does NOT schedule again itself, since this call
	// already covers it.
	ALM_Background_Runner::schedule_next_tick( $task_id, $delay );

	$batch_size = isset( ALM_Background_Runner::TASK_BATCH_SIZES[ $task_id ] )
		? ALM_Background_Runner::TASK_BATCH_SIZES[ $task_id ]
		: 5;

	switch ( $task_id ) {
		case 'scan':
			$scanner              = new ALM_Scanner( new ALM_Adapter_Registry(), new ALM_Provider_Registry(), new ALM_Candidate_Classifier() );
			$result               = $scanner->scan_batch( $state['cursor'], $batch_size );
			$done                 = $result['done'];
			$processed_this_batch = $result['next_offset'] - $state['cursor'];
			$state['cursor']      = $result['next_offset'];
			if ( $done ) {
				// ALM_Scanner::scan_batch() itself only records
				// alm_last_scan_delta -- alm_last_scan_time is set by
				// whichever caller actually finished the run, exactly
				// mirroring ALM_Admin::handle_scan_batch()'s own line.
				update_option( 'alm_last_scan_time', current_time( 'mysql' ) );
			}
			break;

		case 'domains':
			$scanner              = new ALM_Domain_Scanner( new ALM_Domain_Checker(), new ALM_Candidate_Classifier() );
			$result               = $scanner->check_batch( $batch_size, false );
			$done                 = $result['done'];
			$processed_this_batch = $result['checked'];
			break;

		case 'shorteners':
			$scanner              = new ALM_Shortener_Scanner( new ALM_Shortener_Resolver(), new ALM_Provider_Registry() );
			$result               = $scanner->check_batch( $batch_size, false );
			$done                 = $result['done'];
			$processed_this_batch = $result['checked'];
			break;

		case 'link_health':
			$scanner              = new ALM_Link_Health_Scanner( new ALM_Link_Health_Checker() );
			$result               = $scanner->check_batch( $batch_size, false );
			$done                 = $result['done'];
			$processed_this_batch = $result['checked'];
			break;

		default:
			// Not one of the four real tasks -- nothing to run. Cancels
			// the tick just pre-scheduled above too, since this task_id
			// will never legitimately do anything with it.
			ALM_Background_Runner::release_lock( $task_id );
			ALM_Background_Runner::unschedule( $task_id );
			return;
	}

	// domains/shorteners/link_health are all DB-state-driven (their
	// own check_batch() re-queries "what's still unchecked" every
	// time), so there's no cursor to persist for them -- only scan
	// needed $state['cursor'] updated above, in its own branch.
	$state['processed']        += $processed_this_batch;
	$state['reschedule_count'] += 1;

	if ( $done ) {
		ALM_Background_Runner::release_lock( $task_id );
		ALM_Background_Runner::clear_state( $task_id );
		ALM_Background_Runner::unschedule( $task_id );
		return;
	}

	if ( $state['reschedule_count'] >= ALM_Background_Runner::MAX_RESCHEDULES ) {
		// A real defensive cap, not expected to ever bind in normal
		// use -- surfaces as a stalled run on the Dashboard rather
		// than silently rescheduling itself forever on some future
		// stuck-batch bug. Cancels the tick pre-scheduled above too --
		// unlike before this round, something really is scheduled at
		// this point, and a stalled run has nothing left to continue.
		$state['stalled'] = true;
		ALM_Background_Runner::save_state( $task_id, $state );
		ALM_Background_Runner::release_lock( $task_id );
		ALM_Background_Runner::unschedule( $task_id );
		return;
	}

	// Still going, and already has its next tick scheduled (see above,
	// before the batch dispatch) -- nothing left to do here but save.
	ALM_Background_Runner::save_state( $task_id, $state );
	ALM_Background_Runner::release_lock( $task_id );
}
add_action( ALM_Background_Runner::CONTINUE_HOOK, 'alm_continue_batch_run' );

/**
 * Third layer of defense for a run getting stranded (see
 * alm_continue_batch_run()'s own docblock for the first two: a
 * generous set_time_limit(), and scheduling the next tick before the
 * risky part runs). Cheap on purpose -- no real work, no outbound
 * HTTP, just option/wp_next_scheduled() reads -- so it can safely run
 * far more often than the once-daily housekeeping cron: any task left
 * `active` with nothing actually scheduled to continue it (found live
 * on honestlywtf, see alm_continue_batch_run()'s docblock) gets a
 * fresh tick re-primed, bounding the worst-case stuck window to about
 * an hour instead of indefinitely. Deliberately does not touch a
 * `stalled` run (see ALM_Background_Runner::MAX_RESCHEDULES) -- that
 * state means the run itself needs a human's attention, not another
 * silent auto-retry.
 *
 * @return void
 */
function alm_watchdog_reprime_stuck_tasks() {
	foreach ( array_keys( ALM_Background_Runner::TASK_BATCH_SIZES ) as $task_id ) {
		$state = ALM_Background_Runner::get_state( $task_id );

		if ( ! $state['active'] || $state['stalled'] ) {
			continue;
		}

		if ( wp_next_scheduled( ALM_Background_Runner::CONTINUE_HOOK, array( $task_id ) ) ) {
			continue;
		}

		ALM_Background_Runner::schedule_next_tick( $task_id, 5 );
	}
}
add_action( 'alm_watchdog_cron', 'alm_watchdog_reprime_stuck_tasks' );

/**
 * Boot the plugin.
 */
function alm_init() {
	ALM_Install::maybe_upgrade();

	$providers              = new ALM_Provider_Registry();
	$adapters               = new ALM_Adapter_Registry();
	$classifier             = new ALM_Candidate_Classifier();
	$scanner                = new ALM_Scanner( $adapters, $providers, $classifier );
	$domain_checker         = new ALM_Domain_Checker();
	$domain_scanner         = new ALM_Domain_Scanner( $domain_checker, $classifier );
	$converter              = new ALM_Link_Converter( $providers, $adapters );
	$network_signal_scanner = new ALM_Network_Signal_Scanner();
	$shortener_resolver     = new ALM_Shortener_Resolver();
	$shortener_scanner      = new ALM_Shortener_Scanner( $shortener_resolver, $providers );
	$thumbnail_fetcher      = new ALM_Thumbnail_Fetcher();
	$link_health_scanner    = new ALM_Link_Health_Scanner( new ALM_Link_Health_Checker() );

	if ( is_admin() ) {
		require_once ALM_PATH . 'includes/class-alm-links-list-table.php';

		$admin = new ALM_Admin( $scanner, $providers, $adapters, $domain_scanner, $converter, $network_signal_scanner, $shortener_scanner, $thumbnail_fetcher, $link_health_scanner );
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'alm_init' );
