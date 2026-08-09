<?php
/**
 * Plugin Name: Affiliate Link Manager
 * Description: Finds, classifies, and manages affiliate links across post content. Built on a pluggable network-provider architecture (ShopMy to start, more networks can register via the alm_register_providers filter) and a content-storage adapter architecture (plain post content by default, Beaver Builder when active, more via alm_register_content_adapters) so it works regardless of which affiliate networks or page builder a site uses.
 * Version:     1.4.0
 * Author:      Abe Coffman
 * License:     GPL-2.0-or-later
 * Text Domain: affiliate-link-manager
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALM_VERSION', '1.4.0' );
define( 'ALM_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALM_URL', plugin_dir_url( __FILE__ ) );
define( 'ALM_FILE', __FILE__ );

require_once ALM_PATH . 'includes/trait-alm-html-fragment.php';

require_once ALM_PATH . 'includes/class-alm-provider.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-shopmy.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-rewardstyle.php';
require_once ALM_PATH . 'includes/providers/class-alm-provider-generic.php';
require_once ALM_PATH . 'includes/class-alm-provider-registry.php';

require_once ALM_PATH . 'includes/class-alm-content-adapter.php';
require_once ALM_PATH . 'includes/class-alm-adapter-post-content.php';
require_once ALM_PATH . 'includes/class-alm-adapter-beaver-builder.php';
require_once ALM_PATH . 'includes/class-alm-adapter-registry.php';

require_once ALM_PATH . 'includes/class-alm-install.php';
require_once ALM_PATH . 'includes/class-alm-candidate-classifier.php';
require_once ALM_PATH . 'includes/class-alm-scanner.php';
require_once ALM_PATH . 'includes/class-alm-admin.php';

register_activation_hook( __FILE__, array( 'ALM_Install', 'activate' ) );

/**
 * Boot the plugin.
 */
function alm_init() {
	ALM_Install::maybe_upgrade();

	$providers  = new ALM_Provider_Registry();
	$adapters   = new ALM_Adapter_Registry();
	$classifier = new ALM_Candidate_Classifier();
	$scanner    = new ALM_Scanner( $adapters, $providers, $classifier );

	if ( is_admin() ) {
		require_once ALM_PATH . 'includes/class-alm-links-list-table.php';
		require_once ALM_PATH . 'includes/class-alm-posts-list-table.php';
		require_once ALM_PATH . 'includes/class-alm-posts-column.php';

		$admin = new ALM_Admin( $scanner, $providers, $adapters );
		$admin->init();

		$posts_column = new ALM_Posts_Column( $scanner );
		$posts_column->init();
	}
}
add_action( 'plugins_loaded', 'alm_init' );
