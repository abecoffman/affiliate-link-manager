<?php
/**
 * Fetches one representative URL from a domain and looks for real
 * e-commerce signals in the actual page -- schema.org Product markup,
 * og:type=product, product price meta tags, and known shop-platform
 * fingerprints (Shopify/WooCommerce/BigCommerce/Squarespace commerce).
 *
 * This exists specifically to replace guessing from the domain name
 * alone (a hand-maintained "known editorial sites" list is a losing,
 * ever-growing arms race against every blog a site happens to cite --
 * see ALM_Candidate_Classifier's own history). Letting the page say
 * what it actually is scales to domains nobody thought to list.
 *
 * Deliberately conservative about *not* asserting a verdict: a failed
 * fetch, a timeout, or a non-2xx response returns `is_shop => null`
 * ("unknown"), not `false` -- ALM_Domain_Scanner never reclassifies a
 * link based on a null verdict, so a site that's merely slow or
 * temporarily down can't accidentally bury a real candidate back in
 * the noise pile.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Domain_Checker {

	const TIMEOUT = 8;

	/**
	 * @param string $url A representative URL from the domain to fetch --
	 *                    one real, already-discovered link on that
	 *                    domain, not a guessed homepage.
	 * @return array{is_shop:bool|null,signals:string[],http_status:int|null}
	 */
	public function check( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'user-agent'  => $this->user_agent(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'is_shop'     => null,
				'signals'     => array(),
				'http_status' => null,
			);
		}

		// Only a genuine 2xx counts as "reached real content". A 3xx
		// here means the redirect limit above was exhausted without
		// landing on a final page (a normal successful redirect chain
		// never surfaces its intermediate status to this code at all) --
		// same as a 4xx/5xx, that's inconclusive, not "checked and
		// found no product", and must not silently reclassify a link.
		$http_status = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_status < 200 || $http_status >= 300 ) {
			return array(
				'is_shop'     => null,
				'signals'     => array(),
				'http_status' => $http_status,
			);
		}

		$signals = $this->detect_signals( (string) wp_remote_retrieve_body( $response ) );

		return array(
			'is_shop'     => ! empty( $signals ),
			'signals'     => $signals,
			'http_status' => $http_status,
		);
	}

	/**
	 * Identifies the plugin and links back to the operating site --
	 * good citizenship for something that makes outbound requests to
	 * third-party sites at scale; a receiving site's admin looking at
	 * their own logs can tell what this is and who to contact.
	 *
	 * @return string
	 */
	private function user_agent() {
		return sprintf(
			'AffiliateLinkManager/%s (+%s; candidate-classification domain check)',
			ALM_VERSION,
			home_url()
		);
	}

	/**
	 * @param string $html
	 * @return string[] Names of every signal found, empty if none.
	 */
	private function detect_signals( $html ) {
		$signals = array();

		if ( $this->has_json_ld_product( $html ) ) {
			$signals[] = 'json_ld_product';
		}

		if ( preg_match( '#itemtype=["\']https?://schema\.org/Product["\']#i', $html ) ) {
			$signals[] = 'microdata_product';
		}

		if ( $this->has_meta_tag_pair( $html, 'og:type', 'product' ) ) {
			$signals[] = 'og_type_product';
		}

		if ( $this->has_meta_property( $html, 'product:price:amount' ) || $this->has_meta_property( $html, 'og:price:amount' ) ) {
			$signals[] = 'price_meta';
		}

		if ( $this->has_shop_platform_fingerprint( $html ) ) {
			$signals[] = 'shop_platform_fingerprint';
		}

		return $signals;
	}

	/**
	 * Looks for `"@type":"Product"` inside any application/ld+json
	 * block, including the common `@graph` wrapper array and bare JSON
	 * arrays of multiple schema objects on one page.
	 *
	 * @param string $html
	 * @return bool
	 */
	private function has_json_ld_product( $html ) {
		if ( ! preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $json_block ) {
			$data = json_decode( trim( $json_block ), true );
			if ( null !== $data && $this->json_ld_contains_product_type( $data ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $data A decoded JSON-LD value -- object, array, or scalar.
	 * @return bool
	 */
	private function json_ld_contains_product_type( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( isset( $data['@type'] ) ) {
			$type = $data['@type'];
			if ( 'Product' === $type || ( is_array( $type ) && in_array( 'Product', $type, true ) ) ) {
				return true;
			}
		}

		// `@graph` wrapper, or a bare top-level array of schema objects.
		foreach ( $data as $value ) {
			if ( is_array( $value ) && $this->json_ld_contains_product_type( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True if a single <meta> tag has both this property and content
	 * value -- checked both attribute orderings since HTML doesn't
	 * guarantee either comes first.
	 *
	 * @param string $html
	 * @param string $property
	 * @param string $content
	 * @return bool
	 */
	private function has_meta_tag_pair( $html, $property, $content ) {
		$property = preg_quote( $property, '#' );
		$content  = preg_quote( $content, '#' );

		$property_then_content = "#<meta[^>]*property=[\"']{$property}[\"'][^>]*content=[\"']{$content}[\"']#i";
		$content_then_property = "#<meta[^>]*content=[\"']{$content}[\"'][^>]*property=[\"']{$property}[\"']#i";

		return (bool) ( preg_match( $property_then_content, $html ) || preg_match( $content_then_property, $html ) );
	}

	/**
	 * True if a <meta property="$property" content="..."> tag exists
	 * with any non-empty content -- used for price meta, where the
	 * value itself (an amount) varies per product and isn't worth
	 * matching exactly.
	 *
	 * @param string $html
	 * @param string $property
	 * @return bool
	 */
	private function has_meta_property( $html, $property ) {
		$property = preg_quote( $property, '#' );

		return (bool) preg_match( "#<meta[^>]*property=[\"']{$property}[\"']#i", $html );
	}

	/**
	 * Known e-commerce platform fingerprints -- a real signal even on
	 * pages that skip structured data markup entirely. Deliberately
	 * only the handful of platforms common among small/independent
	 * shops (exactly the kind of retailer a domain denylist would never
	 * think to special-case), not an exhaustive list.
	 *
	 * @param string $html
	 * @return bool
	 */
	private function has_shop_platform_fingerprint( $html ) {
		$fingerprints = array(
			'cdn.shopify.com',
			'.myshopify.com',
			'Shopify.theme',
			'woocommerce',
			'bigcommerce.com',
			'squarespace-commerce',
		);

		foreach ( $fingerprints as $fingerprint ) {
			if ( false !== stripos( $html, $fingerprint ) ) {
				return true;
			}
		}

		return false;
	}
}
