<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \ALM_Thumbnail_Fetcher
 */
class ThumbnailFetcherTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.com' );
		// A real, permissive validator -- close enough to WP core's own
		// wp_http_validate_url() for these tests' purposes (absolute
		// http/https only), not stubbed to always-true, so the
		// relative/invalid-URL test actually exercises real rejection.
		Functions\when( 'wp_http_validate_url' )->alias(
			function ( $url ) {
				$scheme = wp_parse_url( $url, PHP_URL_HOST );
				return is_string( $scheme ) && 0 === strpos( $url, 'http' );
			}
		);
	}

	/**
	 * @param string $body
	 * @param int    $status
	 * @return void
	 */
	private function mock_response( $body, $status = 200 ) {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'response' => array( 'code' => $status ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	public function test_og_image_is_found_regardless_of_attribute_order() {
		$this->mock_response( '<meta property="og:image" content="https://cdn.example.com/product.jpg" />' );
		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://shop.example.com/item' );
		$this->assertSame( 'https://cdn.example.com/product.jpg', $result['thumbnail_url'] );

		$this->mock_response( '<meta content="https://cdn.example.com/product.jpg" property="og:image" />' );
		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://shop.example.com/item' );
		$this->assertSame( 'https://cdn.example.com/product.jpg', $result['thumbnail_url'] );
	}

	public function test_falls_back_to_twitter_image_when_no_og_image_present() {
		$this->mock_response( '<meta name="twitter:image" content="https://cdn.example.com/twitter-card.jpg" />' );

		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://shop.example.com/item' );

		$this->assertSame( 'https://cdn.example.com/twitter-card.jpg', $result['thumbnail_url'] );
	}

	public function test_og_image_takes_priority_over_twitter_image_when_both_present() {
		$this->mock_response(
			'<meta property="og:image" content="https://cdn.example.com/og.jpg" />' .
			'<meta name="twitter:image" content="https://cdn.example.com/twitter.jpg" />'
		);

		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://shop.example.com/item' );

		$this->assertSame( 'https://cdn.example.com/og.jpg', $result['thumbnail_url'] );
	}

	public function test_no_answer_beats_a_guess_when_no_image_meta_tag_exists() {
		$this->mock_response( '<html><body><article><h1>An article, not a product</h1></article></body></html>' );

		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://www.vogue.com/article' );

		$this->assertNull( $result['thumbnail_url'] );
	}

	/**
	 * A relative or protocol-relative og:image path is skipped rather
	 * than hand-rolling URL resolution -- a deliberate v1
	 * simplification, see the class docblock.
	 */
	public function test_a_relative_image_url_is_rejected_not_resolved() {
		$this->mock_response( '<meta property="og:image" content="/images/product.jpg" />' );

		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://shop.example.com/item' );

		$this->assertNull( $result['thumbnail_url'] );
	}

	public function test_a_failed_request_returns_null_not_an_error() {
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'Timed out' ) );

		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://unreachable.example.com/product' );

		$this->assertNull( $result['thumbnail_url'] );
	}

	/**
	 * @dataProvider provide_non_success_statuses
	 */
	public function test_a_non_2xx_response_returns_null( $status ) {
		$this->mock_response( '<meta property="og:image" content="https://cdn.example.com/product.jpg" />', $status );

		$result = ( new \ALM_Thumbnail_Fetcher() )->fetch( 'https://example.com/gone' );

		$this->assertNull( $result['thumbnail_url'], 'A non-2xx response is inconclusive, even if the (irrelevant) body happens to contain a valid-looking tag.' );
	}

	public static function provide_non_success_statuses() {
		return array(
			'404 not found'    => array( 404 ),
			'500 server error' => array( 500 ),
		);
	}
}
