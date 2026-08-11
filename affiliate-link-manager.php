<?php
/**
 * Plugin Name: Affiliate Link Manager
 * Description: Finds, classifies, and manages affiliate links across post content. Built on a pluggable network-provider architecture (ShopMy to start, more networks can register via the alm_register_providers filter) and a content-storage adapter architecture (plain post content by default, Beaver Builder when active, more via alm_register_content_adapters) so it works regardless of which affiliate networks or page builder a site uses.
 * Version:     1.13.0
 * Author:      Abe Coffman
 * License:     GPL-2.0-or-later
 * Text Domain: affiliate-link-manager
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALM_VERSION', '1.13.0' );
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
}
add_action( 'alm_domain_recheck_cron', 'alm_run_domain_recheck_cron' );

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

	if ( is_admin() ) {
		require_once ALM_PATH . 'includes/class-alm-links-list-table.php';

		$admin = new ALM_Admin( $scanner, $providers, $adapters, $domain_scanner, $converter, $network_signal_scanner, $shortener_scanner );
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'alm_init' );
