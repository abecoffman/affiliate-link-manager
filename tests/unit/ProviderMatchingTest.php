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
 * @covers \ALM_Provider_Awin
 * @covers \ALM_Provider_ShareASale
 * @covers \ALM_Provider_Sovrn
 * @covers \ALM_Provider_Skimlinks
 * @covers \ALM_Provider_Impact
 * @covers \ALM_Provider_Partnerize
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

	/**
	 * None of the six providers below are confirmed present in
	 * honestlywtf's real data (unlike every provider above, each built
	 * from an observed link) -- built proactively from each network's
	 * own current publisher documentation instead, per explicit user
	 * request to survey and cover major real-world affiliate networks
	 * even before one happens to show up in a scan. Each dataProvider's
	 * sample URLs use the network's own documented link shape; see each
	 * provider class's own docblock for the source.
	 *
	 * @dataProvider provide_awin_urls
	 */
	public function test_awin_matches_its_redirect_domain( $url, $expected ) {
		$provider = new \ALM_Provider_Awin();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_awin_urls() {
		return array(
			'documented cread.php format' => array( 'https://www.awin1.com/cread.php?awinmid=111&awinaffid=222&clickref=myheader&ued=https%3A%2F%2Fwww.zara.com%2Fproduct', true ),
			'bare domain, no www'         => array( 'https://awin1.com/cread.php?awinmid=111&awinaffid=222', true ),
			'lookalike domain'            => array( 'https://notawin1.com/cread.php?awinmid=111', false ),
			'unrelated retailer'          => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_awin_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_Awin();
		$this->assertFalse( $provider->can_wrap() );
	}

	/**
	 * @dataProvider provide_shareasale_urls
	 */
	public function test_shareasale_matches_its_redirect_domain( $url, $expected ) {
		$provider = new \ALM_Provider_ShareASale();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_shareasale_urls() {
		return array(
			'documented r.cfm format'   => array( 'https://www.shareasale.com/r.cfm?b=111111&u=987654&m=12345', true ),
			'deep-link m-pr.cfm format' => array( 'https://shareasale.com/m-pr.cfm?merchantID=12345&userID=987654&productID=555', true ),
			'lookalike domain'          => array( 'https://notshareasale.com/r.cfm?b=111111', false ),
			'unrelated retailer'        => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_shareasale_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_ShareASale();
		$this->assertFalse( $provider->can_wrap() );
	}

	/**
	 * @dataProvider provide_sovrn_urls
	 */
	public function test_sovrn_matches_its_redirect_domains( $url, $expected ) {
		$provider = new \ALM_Provider_Sovrn();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_sovrn_urls() {
		return array(
			'documented redirect.viglink.com format' => array( 'https://redirect.viglink.com/?key=abc123&u=https%3A%2F%2Fwww.zara.com%2Fproduct&type=ap', true ),
			'sovrn.co vanity short link'             => array( 'https://sovrn.co/abc123', true ),
			'lookalike domain'                       => array( 'https://notsovrn.co/abc123', false ),
			'unrelated retailer'                     => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_sovrn_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_Sovrn();
		$this->assertFalse( $provider->can_wrap() );
	}

	/**
	 * @dataProvider provide_skimlinks_urls
	 */
	public function test_skimlinks_matches_its_redirect_domains( $url, $expected ) {
		$provider = new \ALM_Provider_Skimlinks();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_skimlinks_urls() {
		return array(
			'documented go.skimresources.com format' => array( 'https://go.skimresources.com/?id=1X2&url=https%3A%2F%2Fwww.zara.com%2Fproduct&sref=https%3A%2F%2Fhonestlywtf.com%2Fsome-post', true ),
			'fave.co vanity short link'              => array( 'https://fave.co/abc123', true ),
			'lookalike domain'                       => array( 'https://go.notskimresources.com/?id=1X2', false ),
			'unrelated retailer'                     => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_skimlinks_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_Skimlinks();
		$this->assertFalse( $provider->can_wrap() );
	}

	/**
	 * @dataProvider provide_impact_urls
	 */
	public function test_impact_matches_its_redirect_domain_family( $url, $expected ) {
		$provider = new \ALM_Provider_Impact();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_impact_urls() {
		return array(
			'sjv.io, brand subdomain' => array( 'https://some-brand.sjv.io/c/123456/456789/7890?u=https%3A%2F%2Fwww.zara.com%2Fproduct', true ),
			'pxf.io'                  => array( 'https://some-brand.pxf.io/c/123456/456789/7890', true ),
			'7eer.net'                => array( 'https://some-brand.7eer.net/c/123456/456789/7890', true ),
			'evyy.net'                => array( 'https://some-brand.evyy.net/c/123456/456789/7890', true ),
			'pntrs.com'               => array( 'https://some-brand.pntrs.com/t/8-12345-67890-123', true ),
			'pntrac.com'              => array( 'https://some-brand.pntrac.com/t/8-12345-67890-123', true ),
			'lookalike domain'        => array( 'https://notsjv.io/c/123456', false ),
			'unrelated retailer'      => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_impact_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_Impact();
		$this->assertFalse( $provider->can_wrap() );
	}

	/**
	 * @dataProvider provide_partnerize_urls
	 */
	public function test_partnerize_matches_its_redirect_domains( $url, $expected ) {
		$provider = new \ALM_Provider_Partnerize();
		$this->assertSame( $expected, $provider->matches_url( $url ) );
	}

	public static function provide_partnerize_urls() {
		return array(
			'documented prf.hn format'                   => array( 'https://prf.hn/click/camref:1101l79Q/destination:https://www.zara.com/product', true ),
			'gopjn.com (Ascend by Partnerize/Pepperjam)' => array( 'https://some-brand.gopjn.com/t/2-123456-789012-34567', true ),
			'lookalike domain'                           => array( 'https://notprf.hn/click/camref:1101l79Q', false ),
			'unrelated retailer'                         => array( 'https://www.zara.com/us/en/product.html', false ),
		);
	}

	public function test_partnerize_cannot_wrap_new_links() {
		$provider = new \ALM_Provider_Partnerize();
		$this->assertFalse( $provider->can_wrap() );
	}

	public function test_generic_provider_matches_everything() {
		$provider = new \ALM_Provider_Generic();
		$this->assertTrue( $provider->matches_url( 'https://anything.example.com/whatever' ) );
	}

	public function test_shopmy_cannot_wrap_new_links() {
		// No public creator API to verify a destination is actually
		// monetizable through ShopMy -- see ALM_Provider_ShopMy's class
		// docblock. Same classify-only reasoning as every other provider
		// here.
		$provider = new \ALM_Provider_ShopMy();
		$this->assertFalse( $provider->can_wrap() );
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

	public function test_registry_matches_awin_before_falling_back() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = new \ALM_Provider_Registry();
		$provider = $registry->match_url( 'https://www.awin1.com/cread.php?awinmid=111&awinaffid=222' );

		$this->assertSame( 'awin', $provider->get_id() );
	}

	public function test_registry_matches_impact_before_falling_back() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = new \ALM_Provider_Registry();
		$provider = $registry->match_url( 'https://some-brand.sjv.io/c/123456/456789/7890' );

		$this->assertSame( 'impact', $provider->get_id() );
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
