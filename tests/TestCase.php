<?php
/**
 * Base test case: wires up Brain Monkey's WordPress function stubs
 * around every test, plus i18n/escaping passthrough stubs common to
 * virtually every test in this suite (real behavior for these doesn't
 * vary by test, unlike get_option()/apply_filters()/etc., which each
 * test file stubs for itself as needed).
 *
 * @package ALM
 */

namespace ALM\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Extends the Yoast polyfill base (not PHPUnit's directly) so this
 * suite runs the same across the PHPUnit 9/10 range the CI matrix
 * covers.
 */
abstract class TestCase extends PolyfillTestCase {

	protected function set_up() {
		parent::set_up();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tear_down() {
		Monkey\tearDown();
		parent::tear_down();
	}
}
