<?php
/**
 * PHPUnit bootstrap for the integration tier: real WordPress, real DB
 * (wp_plugin_sandbox_test), real dbDelta()-created table. Distinct from
 * tests/bootstrap.php (the fast Brain Monkey unit tier, untouched by
 * this) -- this tier exists for things the unit tier structurally
 * can't cover: real scanning of real posts, the real Beaver Builder
 * adapter (against the bb-plugin-stub fixture -- see
 * wp-plugins/bb-plugin-stub), real $wpdb writes.
 *
 * @package ALM
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain diagnostic text to a CLI terminal, before WordPress itself has even loaded; there's no HTML output context here to escape for.
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL;
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested, plus the Beaver Builder stub
 * fixture so ALM_Adapter_Beaver_Builder::supports_post() has a real
 * FLBuilderModel class to detect.
 */
function _manually_load_alm_plugin() {
	$bb_stub = getenv( 'BB_PLUGIN_STUB_PATH' );
	if ( $bb_stub && file_exists( $bb_stub . '/bb-plugin-stub.php' ) ) {
		require $bb_stub . '/bb-plugin-stub.php';
	}

	require dirname( dirname( __DIR__ ) ) . '/affiliate-link-manager.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_alm_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
