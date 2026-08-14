<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * A real sleep() in the retry path has no place slowing down the fast
 * unit tier -- same reasoning, same pattern as
 * ShortenerResolverTest's own NoSleepShortenerResolver.
 */
class NoSleepLinkHealthChecker extends \ALM_Link_Health_Checker {
	protected function wait_before_dead_retry() {
		// No-op.
	}
}

/**
 * @covers \ALM_Link_Health_Checker
 */
class LinkHealthCheckerTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.com' );
	}

	/**
	 * @param int $status
	 * @return void
	 */
	private function mock_response( $status ) {
		Functions\when( 'wp_safe_remote_get' )->justReturn( array( 'response' => array( 'code' => $status ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
	}

	public function test_a_live_200_page_is_alive_and_not_dead() {
		$this->mock_response( 200 );

		$result = ( new NoSleepLinkHealthChecker() )->check( 'https://shop.example.com/product' );

		$this->assertTrue( $result['alive'] );
		$this->assertFalse( $result['dead'] );
	}

	/**
	 * @dataProvider provide_inconclusive_statuses
	 */
	public function test_ambiguous_statuses_are_never_treated_as_dead( $status ) {
		$this->mock_response( $status );

		$result = ( new NoSleepLinkHealthChecker() )->check( 'https://shop.example.com/product' );

		$this->assertNull( $result['alive'] );
		$this->assertFalse( $result['dead'], "Status {$status} must be inconclusive, not dead -- see class docblock (403 in particular: real, live retailers block automated requests without being gone)." );
	}

	public static function provide_inconclusive_statuses() {
		return array(
			'403 forbidden (bot-blocked, not gone)' => array( 403 ),
			'500 server error'                      => array( 500 ),
			'503 service unavailable'               => array( 503 ),
		);
	}

	/**
	 * @dataProvider provide_dead_statuses
	 */
	public function test_404_and_410_are_confirmed_dead_only_after_two_attempts( $status ) {
		$this->mock_response( $status );

		$result = ( new NoSleepLinkHealthChecker() )->check( 'https://gone.example.com/product' );

		$this->assertTrue( $result['dead'] );
		$this->assertNull( $result['alive'] );
	}

	public static function provide_dead_statuses() {
		return array(
			'404 not found' => array( 404 ),
			'410 gone'      => array( 410 ),
		);
	}

	/**
	 * Same live-found scenario as ALM_Shortener_Resolver's own test of
	 * this exact rate-limiting risk: a first attempt looking dead must
	 * not be trusted if the retry comes back fine.
	 */
	public function test_a_recovering_retry_overrides_the_first_attempts_dead_reading() {
		$call_count = 0;
		Functions\when( 'wp_safe_remote_get' )->alias(
			function () use ( &$call_count ) {
				++$call_count;
				return array( 'response' => array( 'code' => 1 === $call_count ? 404 : 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);

		$result = ( new NoSleepLinkHealthChecker() )->check( 'https://maybe-rate-limited.example.com/product' );

		$this->assertFalse( $result['dead'], 'The retry succeeding must override the first attempt\'s 404.' );
		$this->assertTrue( $result['alive'] );
		$this->assertSame( 2, $call_count );
	}

	public function test_a_confirmed_dead_result_requires_two_dead_attempts() {
		$this->mock_response( 404 );

		$call_count = 0;
		Functions\when( 'wp_safe_remote_get' )->alias(
			function () use ( &$call_count ) {
				++$call_count;
				return array( 'response' => array( 'code' => 404 ) );
			}
		);

		( new NoSleepLinkHealthChecker() )->check( 'https://gone.example.com/product' );

		$this->assertSame( 2, $call_count, 'A single 404 must trigger exactly one retry before being trusted.' );
	}

	/**
	 * The one deliberate departure from "every WP_Error is inconclusive"
	 * -- a confirmed DNS failure is dead, mirroring the same two-strike
	 * confirmation as a 404/410.
	 */
	public function test_a_confirmed_dns_failure_is_dead() {
		Functions\when( 'wp_safe_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: this-domain-does-not-exist.example' ) );

		$result = ( new NoSleepLinkHealthChecker() )->check( 'https://this-domain-does-not-exist.example/product' );

		$this->assertTrue( $result['dead'] );
		$this->assertNull( $result['alive'] );
	}

	/**
	 * Every other transport failure (timeout, connection refused, TLS
	 * errors, ...) stays inconclusive -- only a DNS failure specifically
	 * is trusted as dead. See class docblock.
	 */
	public function test_a_non_dns_transport_error_is_never_treated_as_dead() {
		Functions\when( 'wp_safe_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'Connection timed out after 8001 milliseconds' ) );

		$result = ( new NoSleepLinkHealthChecker() )->check( 'https://slow-or-down.example.com/product' );

		$this->assertNull( $result['alive'] );
		$this->assertFalse( $result['dead'], 'A generic timeout is not the same confidence level as a confirmed DNS failure.' );
	}
}
