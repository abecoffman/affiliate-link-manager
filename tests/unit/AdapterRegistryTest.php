<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \ALM_Adapter_Registry
 * @covers \ALM_Adapter_Beaver_Builder
 * @covers \ALM_Adapter_Post_Content
 */
class AdapterRegistryTest extends TestCase {

	public function test_beaver_builder_adapter_never_claims_a_post_when_the_builder_is_absent() {
		// The core case this plugin's adapter architecture exists to
		// handle: on a site without Beaver Builder active (or any page
		// builder at all), FLBuilderModel simply doesn't exist --
		// confirmed here rather than assumed, since this test suite
		// never defines that class.
		$this->assertFalse( class_exists( 'FLBuilderModel' ) );

		$adapter = new \ALM_Adapter_Beaver_Builder();
		$this->assertFalse( $adapter->supports_post( 123 ) );
	}

	public function test_registry_falls_back_to_post_content_adapter_when_nothing_else_matches() {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = new \ALM_Adapter_Registry();
		$adapter  = $registry->get_adapter_for_post( 123 );

		$this->assertSame( 'post_content', $adapter->get_id() );
	}

	public function test_registry_lets_third_party_adapters_register_via_filter() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $adapters ) {
				if ( 'alm_register_content_adapters' === $tag ) {
					$adapters[] = new FakePageBuilderAdapter();
				}
				return $adapters;
			}
		);

		$registry = new \ALM_Adapter_Registry();
		$adapter  = $registry->get_adapter_for_post( 456 );

		$this->assertSame( 'fake_builder', $adapter->get_id() );
	}

	public function test_post_content_adapter_supports_every_post() {
		$adapter = new \ALM_Adapter_Post_Content();
		$this->assertTrue( $adapter->supports_post( 1 ) );
		$this->assertTrue( $adapter->supports_post( 999999 ) );
	}

	public function test_post_content_adapter_extracts_links_from_a_real_post() {
		$post = (object) array(
			'post_content' => '<p>Wearing this <a href="https://go.shopmy.us/p-1">cardigan</a> today.</p>',
		);
		Functions\when( 'get_post' )->justReturn( $post );

		$adapter = new \ALM_Adapter_Post_Content();
		$links   = $adapter->get_links( 42 );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://go.shopmy.us/p-1', $links[0]['url'] );
		$this->assertSame( 'cardigan', $links[0]['anchor_text'] );
		$this->assertSame( '0', $links[0]['location'] );
	}

	public function test_post_content_adapter_returns_empty_when_post_not_found() {
		Functions\when( 'get_post' )->justReturn( null );

		$adapter = new \ALM_Adapter_Post_Content();
		$this->assertSame( array(), $adapter->get_links( 999 ) );
	}
}

/**
 * Minimal third-party adapter stand-in used only by
 * test_registry_lets_third_party_adapters_register_via_filter() above,
 * to prove the 'alm_register_content_adapters' filter is a real,
 * working extension point -- the whole reason this plugin's content
 * layer is adapter-based rather than hardcoding one page builder.
 */
class FakePageBuilderAdapter extends \ALM_Content_Adapter {
	public function get_id() {
		return 'fake_builder';
	}

	public function get_label() {
		return 'Fake Builder';
	}

	public function supports_post( $post_id ) {
		return 456 === $post_id;
	}

	public function get_links( $post_id ) {
		return array();
	}

	public function replace_link( $post_id, $location, $old_url, $new_url ) {
		return true;
	}
}
