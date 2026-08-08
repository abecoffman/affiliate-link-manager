<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \ALM_Provider_ShopMy
 * @covers \ALM_Provider_RewardStyle
 * @covers \ALM_Provider_Generic
 * @covers \ALM_Provider_Registry
 */
class ProviderMatchingTest extends TestCase {

	/**
	 * @dataProvider provide_urls
	 */
	public function test_shopmy_matches_only_its_own_domain( $url, $expected ) {
		$provider = new \ALM_Provider_ShopMy();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_urls() {
		return array(
			'shopmy apx link'   => array( 'https://go.shopmy.us/apx/abc123?url=https://example.com', true ),
			'shopmy short link' => array( 'https://go.shopmy.us/p-18314974', true ),
			'rstyle link'       => array( 'https://rstyle.me/+abc123', false ),
			'raw retailer link' => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_rewardstyle_matches_only_rstyle_me() {
		$provider = new \ALM_Provider_RewardStyle();
		$this->assertTrue( $provider->matches_url( 'https://rstyle.me/+abc123' ) );
		$this->assertFalse( $provider->matches_url( 'https://go.shopmy.us/p-1' ) );
	}

	public function test_rewardstyle_cannot_wrap_new_links() {
		// Deliberate: converting an already-earning legacy network's
		// links is a monetization decision, never automatic. See
		// ALM_Provider_RewardStyle's class docblock.
		$provider = new \ALM_Provider_RewardStyle();
		$this->assertFalse( $provider->can_wrap() );
	}

	public function test_generic_provider_matches_everything() {
		$provider = new \ALM_Provider_Generic();
		$this->assertTrue( $provider->matches_url( 'https://anything.example.com/whatever' ) );
	}

	public function test_shopmy_is_not_configured_without_an_affiliate_id() {
		Functions\when( 'get_option' )->justReturn( '' );

		$provider = new \ALM_Provider_ShopMy();
		$this->assertFalse( $provider->is_configured() );
		$this->assertFalse( $provider->can_wrap() );
	}

	public function test_shopmy_wrap_url_fails_when_not_configured() {
		Functions\when( 'get_option' )->justReturn( '' );

		$provider = new \ALM_Provider_ShopMy();
		$result   = $provider->wrap_url( 'https://www.zara.com/product' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_shopmy_wrap_url_builds_the_documented_redirect_format() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return \ALM_Provider_ShopMy::OPTION_AFFILIATE_ID === $name ? 'sDXyBS' : $default;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$provider = new \ALM_Provider_ShopMy();
		$result   = $provider->wrap_url( 'https://www.zara.com/product' );

		$this->assertStringStartsWith( 'https://go.shopmy.us/apx/sDXyBS?', $result );
		$this->assertStringContainsString( 'url=', $result );
	}

	public function test_shopmy_wrap_url_includes_collection_id_when_set() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				if ( \ALM_Provider_ShopMy::OPTION_AFFILIATE_ID === $name ) {
					return 'sDXyBS';
				}
				if ( \ALM_Provider_ShopMy::OPTION_COLLECTION_ID === $name ) {
					return '123';
				}
				return $default;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$provider = new \ALM_Provider_ShopMy();
		$result   = $provider->wrap_url( 'https://www.zara.com/product' );

		$this->assertStringContainsString( 'c=123', $result );
	}

	public function test_registry_falls_back_to_generic_provider() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = new \ALM_Provider_Registry();
		$provider = $registry->match_url( 'https://www.some-random-retailer.example.com/product' );

		$this->assertSame( 'unclassified', $provider->get_id() );
	}

	public function test_registry_matches_shopmy_before_falling_back() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = new \ALM_Provider_Registry();
		$provider = $registry->match_url( 'https://go.shopmy.us/p-123' );

		$this->assertSame( 'shopmy', $provider->get_id() );
	}

	public function test_registry_lets_third_party_providers_register_via_filter() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $providers ) {
				if ( 'alm_register_providers' === $tag ) {
					$providers[] = new FakeThirdPartyProvider();
				}
				return $providers;
			}
		);

		$registry = new \ALM_Provider_Registry();
		$provider = $registry->match_url( 'https://example.fake-network.test/link' );

		$this->assertSame( 'fake_network', $provider->get_id() );
	}
}

/**
 * Minimal third-party provider stand-in used only by
 * test_registry_lets_third_party_providers_register_via_filter() above,
 * to prove the 'alm_register_providers' filter is a real, working
 * extension point.
 */
class FakeThirdPartyProvider extends \ALM_Provider {
	public function get_id() {
		return 'fake_network';
	}

	public function get_label() {
		return 'Fake Network';
	}

	public function matches_url( $url ) {
		return false !== strpos( $url, 'fake-network.test' );
	}
}
