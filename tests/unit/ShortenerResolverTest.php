<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * A real sleep() in the retry path (see ALM_Shortener_Resolver's class
 * docblock for why it exists) has no place slowing down the fast unit
 * tier -- PHP's built-in sleep() isn't something Brain Monkey can stub
 * the way it stubs WP's own functions, so the resolver deliberately
 * routes it through this one overridable method instead.
 */
class NoSleepShortenerResolver extends \ALM_Shortener_Resolver {
	protected function wait_before_dead_retry() {
		// No-op.
	}
}

/**
 * @covers \ALM_Shortener_Resolver
 */
class ShortenerResolverTest extends TestCase {

	public function test_is_shortener_matches_known_domains() {
		$resolver = new NoSleepShortenerResolver();

		$this->assertTrue( $resolver->is_shortener( 'http://bit.ly/abc123' ) );
		$this->assertTrue( $resolver->is_shortener( 'https://etsy.me/1R6cI8v' ) );
		$this->assertFalse( $resolver->is_shortener( 'https://www.zara.com/us/en/product.html' ) );
	}

	public function test_known_shortener_domains_is_filterable() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $domains ) {
				if ( 'alm_known_shortener_domains' === $tag ) {
					$domains[] = 'a-brand-new-shortener.example';
				}
				return $domains;
			}
		);

		$resolver = new NoSleepShortenerResolver();
		$this->assertTrue( $resolver->is_shortener( 'https://a-brand-new-shortener.example/abc' ) );
	}

	public function test_resolve_follows_a_single_redirect_to_a_final_page() {
		Functions\when( 'wp_safe_remote_head' )->alias(
			function ( $url ) {
				if ( 'http://bit.ly/abc123' === $url ) {
					return array(
						'headers'  => array( 'location' => 'https://www.zara.com/product' ),
						'response' => array( 'code' => 301 ),
					);
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $response, $header ) {
				return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.test' );

		$resolver = new NoSleepShortenerResolver();
		$result   = $resolver->resolve( 'http://bit.ly/abc123' );

		$this->assertSame( 'https://www.zara.com/product', $result['destination'] );
		$this->assertFalse( $result['dead'] );
	}

	/**
	 * Found live, against honestlywtf's real etsy.me links: a real
	 * multi-hop chain (a couple of scheme-upgrade redirects) lands on a
	 * genuine etsy.com URL, but Etsy's own bot protection returns 403
	 * to this resolver's HEAD request on that final page. The
	 * destination is still real and known (captured from the
	 * second-to-last hop's own Location header) -- confirming the page
	 * itself loads for this resolver was never the point.
	 */
	public function test_resolve_reports_the_destination_even_if_its_own_check_is_bot_blocked() {
		Functions\when( 'wp_safe_remote_head' )->alias(
			function ( $url ) {
				$chain = array(
					'http://etsy.me/1R6cI8v'  => array( 301, 'https://etsy.me/1R6cI8v' ),
					'https://etsy.me/1R6cI8v' => array( 301, 'http://www.etsy.com/search?q=vintage' ),
					'http://www.etsy.com/search?q=vintage' => array( 301, 'https://www.etsy.com/search?q=vintage' ),
				);
				if ( isset( $chain[ $url ] ) ) {
					return array(
						'headers'  => array( 'location' => $chain[ $url ][1] ),
						'response' => array( 'code' => $chain[ $url ][0] ),
					);
				}
				// The final, real destination -- blocked by Etsy's own
				// bot protection, not a redirect and not a 404.
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 403 ),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $response, $header ) {
				return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.test' );

		$resolver = new NoSleepShortenerResolver();
		$result   = $resolver->resolve( 'http://etsy.me/1R6cI8v' );

		$this->assertSame( 'https://www.etsy.com/search?q=vintage', $result['destination'] );
		$this->assertFalse( $result['dead'] );
		$this->assertSame( 403, $result['http_status'] );
	}

	/**
	 * A 404/410 partway down the chain (the shortener redirected fine,
	 * but the page it pointed to is itself now gone) is a different
	 * thing from the shortener's own first response being 404/410 --
	 * only the latter means the shortlink itself is dead.
	 */
	public function test_resolve_does_not_report_dead_for_a_404_past_the_first_hop() {
		Functions\when( 'wp_safe_remote_head' )->alias(
			function ( $url ) {
				if ( 'http://bit.ly/redirects-to-gone-page' === $url ) {
					return array(
						'headers'  => array( 'location' => 'https://www.zara.com/discontinued-product' ),
						'response' => array( 'code' => 301 ),
					);
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 404 ),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $response, $header ) {
				return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.test' );

		$resolver = new NoSleepShortenerResolver();
		$result   = $resolver->resolve( 'http://bit.ly/redirects-to-gone-page' );

		$this->assertFalse( $result['dead'], 'The shortlink itself redirected fine -- not dead.' );
		$this->assertSame( 'https://www.zara.com/discontinued-product', $result['destination'] );
	}

	/**
	 * "Dead" requires two independent 404s, not one -- found live,
	 * against real bit.ly links, getting rate-limited by repeated
	 * testing into a false 404 for a link that resolved completely
	 * normally moments later on its own. Both attempts confirming dead
	 * is what this test locks in.
	 */
	public function test_resolve_reports_a_confirmed_dead_shortlink_only_after_two_dead_attempts() {
		Functions\when( 'wp_safe_remote_head' )->justReturn(
			array(
				'headers'  => array(),
				'response' => array( 'code' => 404 ),
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.test' );

		$resolver = new NoSleepShortenerResolver();
		$result   = $resolver->resolve( 'http://bit.ly/dead-link' );

		$this->assertNull( $result['destination'] );
		$this->assertTrue( $result['dead'] );
	}

	/**
	 * The exact scenario found live: a first attempt returns 404 (a
	 * rate-limited or otherwise transient false negative), but the
	 * retry succeeds -- the real, non-dead result must win, not the
	 * first attempt's 404.
	 */
	public function test_resolve_does_not_report_dead_if_the_retry_succeeds() {
		$call_count = 0;
		Functions\when( 'wp_safe_remote_head' )->alias(
			function ( $url ) use ( &$call_count ) {
				++$call_count;

				if ( 'http://bit.ly/Xn3SWX' === $url && 1 === $call_count ) {
					// First attempt: a suspicious 404 (the real,
					// rate-limited false negative this test reproduces).
					return array(
						'headers'  => array(),
						'response' => array( 'code' => 404 ),
					);
				}

				if ( 'http://bit.ly/Xn3SWX' === $url ) {
					// The retry: a real redirect this time.
					return array(
						'headers'  => array( 'location' => 'https://www.lorealparisusa.com/product' ),
						'response' => array( 'code' => 301 ),
					);
				}

				// The redirect's own destination -- a real final page.
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $response, $header ) {
				return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.test' );

		$resolver = new NoSleepShortenerResolver();
		$result   = $resolver->resolve( 'http://bit.ly/Xn3SWX' );

		$this->assertFalse( $result['dead'], 'The retry succeeding must override the first attempt\'s 404.' );
		$this->assertSame( 'https://www.lorealparisusa.com/product', $result['destination'] );
		$this->assertSame( 3, $call_count, 'First attempt (dead) + retry\'s own redirect hop + the final destination fetch.' );
	}

	public function test_resolve_returns_null_destination_on_a_fetch_error_not_a_confirmed_death() {
		Functions\when( 'wp_safe_remote_head' )->justReturn( new \WP_Error( 'http_request_failed', 'Timed out' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.test' );

		$resolver = new NoSleepShortenerResolver();
		$result   = $resolver->resolve( 'http://bit.ly/timeout' );

		$this->assertNull( $result['destination'] );
		$this->assertFalse( $result['dead'], 'A fetch error is inconclusive, not a confirmed death.' );
	}
}
