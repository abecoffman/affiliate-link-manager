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
 * @covers \ALM_Provider_Amazon
 * @covers \ALM_Provider_CJ
 * @covers \ALM_Provider_Rakuten
 * @covers \ALM_Provider_ShopStyle
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

	public function test_amazon_matches_short_links() {
		$provider = new \ALM_Provider_Amazon();
		$this->assertTrue( $provider->matches_url( 'https://amzn.to/3XUY1At' ) );
	}

	/**
	 * @dataProvider provide_amazon_tagged_urls
	 */
	public function test_amazon_matches_direct_links_only_when_tagged( $url, $expected ) {
		$provider = new \ALM_Provider_Amazon();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_amazon_tagged_urls() {
		return array(
			'tagged product link'        => array( 'http://www.amazon.com/gp/product/2759403963?tag=lifecont-20', true ),
			'tagged, other params first' => array( 'https://www.amazon.co.uk/dp/B00073I09S/ref=sr_1_3?ie=UTF8&tag=mytag-21', true ),
			'tagged smile subdomain'     => array( 'https://smile.amazon.com/dp/B00073I09S?tag=mytag-20', true ),
			// The real, common case on honestlywtf: an old product link
			// from before this site had an Associates account. Matching
			// this would misclassify a genuine Candidate as already
			// tracked -- confirmed against real data (77 of honestlywtf's
			// 185 amazon.com links have no tag at all).
			'untagged product link'      => array( 'http://www.amazon.com/dp/B00073I09S', false ),
			'lookalike domain'           => array( 'https://notamazon.com/dp/B00073I09S?tag=fake-20', false ),
			'unrelated retailer'         => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_amazon_cannot_wrap_new_links() {
		// No public API to generate a real Associates tag -- that
		// requires the site's own Associates account (SiteStripe or a
		// manual tag), not something this plugin can fabricate. Same
		// classify-only reasoning as ALM_Provider_RewardStyle.
		$provider = new \ALM_Provider_Amazon();
		$this->assertFalse( $provider->can_wrap() );
	}

	public function test_registry_matches_amazon_before_falling_back() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = new \ALM_Provider_Registry();
		$provider = $registry->match_url( 'https://amzn.to/3XUY1At' );

		$this->assertSame( 'amazon', $provider->get_id() );
	}

	/**
	 * @dataProvider provide_cj_urls
	 */
	public function test_cj_matches_its_redirect_domain_family( $url, $expected ) {
		$provider = new \ALM_Provider_CJ();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_cj_urls() {
		return array(
			'anrdoezrs.net (confirmed on honestlywtf)' => array( 'http://www.anrdoezrs.net/links/7581063/type/dlg/https://society6.com/prints', true ),
			'jdoqocy.com'                              => array( 'https://jdoqocy.com/click-123', true ),
			'kqzyfj.com'                               => array( 'https://www.kqzyfj.com/click-123', true ),
			'tkqlhce.com'                              => array( 'https://tkqlhce.com/click-123', true ),
			'dpbolvw.net'                              => array( 'https://dpbolvw.net/click-123', true ),
			'lookalike domain'                         => array( 'https://notanrdoezrs.net/click-123', false ),
			'unrelated retailer'                       => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_cj_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_CJ();
		$this->assertFalse( $provider->can_wrap() );
	}

	public function test_rakuten_matches_only_linksynergy() {
		$provider = new \ALM_Provider_Rakuten();
		$this->assertTrue( $provider->matches_url( 'http://click.linksynergy.com/fs-bin/click?id=QcQPJCw8spc&offerid=238161.10001574&type=3' ) );
		$this->assertFalse( $provider->matches_url( 'https://www.zara.com/us/en/product.html' ) );
	}

	public function test_rakuten_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_Rakuten();
		$this->assertFalse( $provider->can_wrap() );
	}

	public function test_shopstyle_matches_its_known_domains() {
		$provider = new \ALM_Provider_ShopStyle();
		$this->assertTrue( $provider->matches_url( 'http://shopstyle.it/l/IaAT' ) );
		$this->assertTrue( $provider->matches_url( 'https://shop-links.co/abc123' ) );
		$this->assertFalse( $provider->matches_url( 'https://www.zara.com/us/en/product.html' ) );
	}

	public function test_shopstyle_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_ShopStyle();
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
			function ( $name, $default_value = '' ) {
				return \ALM_Provider_ShopMy::OPTION_AFFILIATE_ID === $name ? 'sDXyBS' : $default_value;
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
			function ( $name, $default_value = '' ) {
				if ( \ALM_Provider_ShopMy::OPTION_AFFILIATE_ID === $name ) {
					return 'sDXyBS';
				}
				if ( \ALM_Provider_ShopMy::OPTION_COLLECTION_ID === $name ) {
					return '123';
				}
				return $default_value;
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
