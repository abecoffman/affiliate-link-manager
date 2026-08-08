<?php
/**
 * PHPUnit bootstrap for the lightweight (no WP install/DB) unit suite.
 * Uses Brain Monkey to stub WordPress functions rather than booting real
 * WordPress -- fast, but only exercises pure logic (provider matching,
 * HTML-fragment link parsing/rewriting), not real hook wiring or a real
 * database. Manual/live testing against a real WordPress install is
 * what exercises the real integration (scanning real posts, the real
 * Beaver Builder adapter against real _fl_builder_data).
 *
 * @package ALM
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The plugin's own files guard on ABSPATH being defined, same as any
// WordPress plugin file loaded outside of WordPress itself.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! defined( 'ALM_PATH' ) ) {
	define( 'ALM_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ALM_URL' ) ) {
	define( 'ALM_URL', 'http://example.test/wp-content/plugins/affiliate-link-manager/' );
}
if ( ! defined( 'ALM_FILE' ) ) {
	define( 'ALM_FILE', ALM_PATH . 'affiliate-link-manager.php' );
}
if ( ! defined( 'ALM_VERSION' ) ) {
	define( 'ALM_VERSION', 'test' );
}

/**
 * Brain Monkey stubs WordPress functions, but WP_Error is a real class,
 * not a function -- it needs a minimal stand-in so provider/adapter
 * code that returns `new WP_Error(...)` on failure paths is testable.
 * Kept deliberately minimal (just enough surface for is_wp_error() and
 * get_error_message() to work), not a full reimplementation.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

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

require_once ALM_PATH . 'includes/class-alm-candidate-classifier.php';
