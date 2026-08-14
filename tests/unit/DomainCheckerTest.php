<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \ALM_Domain_Checker
 */
class DomainCheckerTest extends TestCase {

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
	 * @param string $body
	 * @param int    $status
	 * @return void
	 */
	private function mock_response( $body, $status = 200 ) {
		Functions\when( 'wp_safe_remote_get' )->justReturn( array( 'response' => array( 'code' => $status ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	public function test_a_page_with_json_ld_product_schema_is_a_shop() {
		$this->mock_response(
			'<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Wool Blazer","offers":{"@type":"Offer","price":"89.00"}}</script></head></html>'
		);

		$result = ( new \ALM_Domain_Checker() )->check( 'https://www.zara.com/product' );

		$this->assertTrue( $result['is_shop'] );
		$this->assertContains( 'json_ld_product', $result['signals'] );
	}

	public function test_a_graph_wrapped_json_ld_product_is_detected() {
		$this->mock_response(
			'<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"BreadcrumbList"},{"@type":"Product","name":"Necklace"}]}</script>'
		);

		$result = ( new \ALM_Domain_Checker() )->check( 'https://shop.example.com/necklace' );

		$this->assertTrue( $result['is_shop'] );
	}

	public function test_a_non_product_json_ld_type_is_not_a_false_positive() {
		$this->mock_response(
			'<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"10 Fall Trends"}</script>'
		);

		$result = ( new \ALM_Domain_Checker() )->check( 'https://www.vogue.com/article/fall-trends' );

		$this->assertFalse( $result['is_shop'] );
		$this->assertSame( array(), $result['signals'] );
	}

	public function test_microdata_product_itemtype_is_detected() {
		$this->mock_response( '<div itemscope itemtype="https://schema.org/Product"><span itemprop="name">Necklace</span></div>' );

		$result = ( new \ALM_Domain_Checker() )->check( 'https://shop.example.com/necklace' );

		$this->assertTrue( $result['is_shop'] );
		$this->assertContains( 'microdata_product', $result['signals'] );
	}

	public function test_og_type_product_meta_is_detected_regardless_of_attribute_order() {
		$this->mock_response( '<meta property="og:type" content="product" />' );
		$result = ( new \ALM_Domain_Checker() )->check( 'https://shop.example.com/item' );
		$this->assertTrue( $result['is_shop'] );

		$this->mock_response( '<meta content="product" property="og:type" />' );
		$result = ( new \ALM_Domain_Checker() )->check( 'https://shop.example.com/item' );
		$this->assertTrue( $result['is_shop'] );
	}

	public function test_price_meta_tag_is_detected() {
		$this->mock_response( '<meta property="product:price:amount" content="42.00" />' );

		$result = ( new \ALM_Domain_Checker() )->check( 'https://shop.example.com/item' );

		$this->assertTrue( $result['is_shop'] );
		$this->assertContains( 'price_meta', $result['signals'] );
	}

	/**
	 * @dataProvider provide_shop_platform_html
	 */
	public function test_known_shop_platform_fingerprints_are_detected( $html ) {
		$this->mock_response( $html );

		$result = ( new \ALM_Domain_Checker() )->check( 'https://smallboutique.example.com/product' );

		$this->assertTrue( $result['is_shop'] );
		$this->assertContains( 'shop_platform_fingerprint', $result['signals'] );
	}

	public static function provide_shop_platform_html() {
		return array(
			'shopify cdn'    => array( '<script src="https://cdn.shopify.com/s/files/1/theme.js"></script>' ), // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- fixture HTML string for the checker to parse, not a real enqueued script.
			'myshopify host' => array( '<link rel="canonical" href="https://smallboutique.myshopify.com/products/necklace">' ),
			'woocommerce'    => array( '<body class="woocommerce single-product"><div class="woocommerce-product-gallery"></div></body>' ),
		);
	}

	public function test_a_plain_editorial_page_with_no_signals_is_not_a_shop() {
		$this->mock_response(
			'<html><body><article><h1>10 Fall Trends We Love</h1><p>This season is all about texture...</p></article></body></html>'
		);

		$result = ( new \ALM_Domain_Checker() )->check( 'https://www.vogue.com/article/fall-trends' );

		$this->assertFalse( $result['is_shop'] );
		$this->assertSame( array(), $result['signals'] );
		$this->assertSame( 200, $result['http_status'] );
	}

	public function test_a_failed_request_returns_an_unknown_verdict_not_false() {
		Functions\when( 'wp_safe_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$result = ( new \ALM_Domain_Checker() )->check( 'https://unreachable.example.com/product' );

		$this->assertNull( $result['is_shop'], 'A fetch failure must never be treated as "confirmed not a shop".' );
		$this->assertNull( $result['http_status'] );
	}

	/**
	 * @dataProvider provide_non_success_statuses
	 */
	public function test_a_non_2xx_response_returns_an_unknown_verdict_not_false( $status ) {
		$this->mock_response( '<html>irrelevant</html>', $status );

		$result = ( new \ALM_Domain_Checker() )->check( 'https://example.com/gone' );

		$this->assertNull( $result['is_shop'] );
		$this->assertSame( $status, $result['http_status'] );
	}

	public static function provide_non_success_statuses() {
		return array(
			'404 not found'    => array( 404 ),
			'500 server error' => array( 500 ),
			'301 (redirect loop exhausted, still a redirect at the end)' => array( 301 ),
		);
	}
}
