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
		Functions\when( 'get_option' )->justReturn( "honestlyyum.com\nvogue.com" );

		$classifier = new \ALM_Candidate_Classifier();
		$this->assertFalse( $classifier->is_candidate( 'https://www.honestlyyum.com/recipe' ) );
		$this->assertFalse( $classifier->is_candidate( 'https://www.vogue.com/article' ) );
		// A real retailer is unaffected by the custom list.
		$this->assertTrue( $classifier->is_candidate( 'https://www.zara.com/product' ) );
	}

	public function test_comma_separated_custom_domains_are_also_supported() {
		Functions\when( 'get_option' )->justReturn( 'honestlyyum.com, vogue.com' );

		$classifier = new \ALM_Candidate_Classifier();
		$this->assertFalse( $classifier->is_candidate( 'https://honestlyyum.com/recipe' ) );
		$this->assertFalse( $classifier->is_candidate( 'https://vogue.com/article' ) );
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
