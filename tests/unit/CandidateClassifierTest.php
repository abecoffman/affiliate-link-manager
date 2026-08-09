<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \ALM_Candidate_Classifier
 */
class CandidateClassifierTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'home_url' )->justReturn( 'https://honestlywtf.com' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * @dataProvider provide_candidate_urls
	 */
	public function test_is_candidate( $url, $expected, $message = '' ) {
		$classifier = new \ALM_Candidate_Classifier();
		$this->assertSame( $expected, $classifier->is_candidate( $url ), $message );
	}

	public static function provide_candidate_urls() {
		return array(
			'a real, unrecognized retailer'    => array( 'https://www.zara.com/us/en/product.html', true ),
			'etsy'                              => array( 'https://www.etsy.com/listing/12345', true ),
			'internal link, same host'          => array( 'https://honestlywtf.com/category/diy', false ),
			'internal link, www subdomain'      => array( 'https://www.honestlywtf.com/category/diy', false ),
			'instagram, bare domain'            => array( 'https://instagram.com/p/abc123', false ),
			'instagram, www subdomain'          => array( 'https://www.instagram.com/p/abc123', false ),
			'pinterest'                         => array( 'https://www.pinterest.com/pin/123', false ),
			'wikipedia'                         => array( 'https://en.wikipedia.org/wiki/Macrame', false ),
			'a major magazine/media publisher'  => array( 'https://www.vogue.com/article/some-feature', false ),
			'another major publisher'           => array( 'https://www.elle.com/fashion/story', false ),
			'a personal blogspot blog'          => array( 'https://somecreativeblog.blogspot.com/2019/some-post.html', false ),
			'a tumblr blog'                     => array( 'https://someblog.tumblr.com/post/123', false ),
			'a video platform'                  => array( 'https://vimeo.com/12345678', false ),
			'imdb'                              => array( 'https://www.imdb.com/title/tt0111161/', false ),
			'ride-sharing, never a shop'         => array( 'https://www.uber.com/global/en/price-estimate/', false ),
			'instagram short domain'            => array( 'https://instagr.am/p/abc123', false ),
			'twitter short domain'              => array( 'https://t.co/abc123', false ),
			// A real affiliate-network redirect domain (Commission
			// Junction) must NOT be excluded -- it's likely already a
			// functioning affiliate link this plugin just doesn't have a
			// dedicated provider for yet, the opposite of noise.
			'a real affiliate-network redirect' => array( 'https://www.anrdoezrs.net/click-1234-5678', true ),
			'a direct jpg link'                 => array( 'https://cdn.example.com/photos/look.jpg', false ),
			'a direct png link, query string'   => array( 'https://cdn.example.com/photos/look.png?w=800', false ),
			'a relative path'                   => array( '/2024/01/some-post/', false ),
			'an anchor-only link'               => array( '#content', false ),
			'a mailto link'                     => array( 'mailto:hello@honestlywtf.com', false ),
			'a tel link'                        => array( 'tel:+15555550100', false ),
			// A domain that merely *contains* a noise domain as a
			// substring must not false-positive -- only real subdomains
			// of the noise domain should match.
			'lookalike domain, not a subdomain' => array( 'https://eviltwitter.com/product', true, 'eviltwitter.com is not a subdomain of twitter.com' ),
		);
	}

	public function test_a_domain_added_via_settings_option_is_excluded() {
		// honestlyyum.com (a real sister site) is exactly the kind of
		// thing that can never be a universal default -- only the site
		// owner would know it's noise for *their* site specifically.
		Functions\when( 'get_option' )->justReturn( "honestlyyum.com\nsome-niche-blog.example" );

		$classifier = new \ALM_Candidate_Classifier();
		$this->assertFalse( $classifier->is_candidate( 'https://www.honestlyyum.com/recipe' ) );
		$this->assertFalse( $classifier->is_candidate( 'https://www.some-niche-blog.example/article' ) );
		// A real retailer is unaffected by the custom list.
		$this->assertTrue( $classifier->is_candidate( 'https://www.zara.com/product' ) );
	}

	public function test_comma_separated_custom_domains_are_also_supported() {
		Functions\when( 'get_option' )->justReturn( 'honestlyyum.com, some-niche-blog.example' );

		$classifier = new \ALM_Candidate_Classifier();
		$this->assertFalse( $classifier->is_candidate( 'https://honestlyyum.com/recipe' ) );
		$this->assertFalse( $classifier->is_candidate( 'https://some-niche-blog.example/article' ) );
	}

	public function test_a_domain_added_via_the_filter_is_excluded() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $domains ) {
				if ( 'alm_candidate_noise_domains' === $tag ) {
					$domains[] = 'example-magazine.com';
				}
				return $domains;
			}
		);

		$classifier = new \ALM_Candidate_Classifier();
		$this->assertFalse( $classifier->is_candidate( 'https://www.example-magazine.com/feature' ) );
	}
}
