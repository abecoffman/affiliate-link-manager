<?php
/**
 * Decides which links the "unclassified" fallback provider matched are
 * actually worth a human's attention as affiliate-link candidates, vs.
 * pure noise -- internal navigation, social/embed platforms, and
 * reference sites that will never be a real affiliate opportunity.
 *
 * Deliberately conservative in one direction only: everything not
 * positively identified as noise defaults to "candidate". A missed
 * noise domain is a minor annoyance (one extra row to skip past); a
 * real retailer link wrongly buried back in the noise bucket defeats
 * the entire point of this class. The default noise list only
 * includes domains that are *never* a shoppable product page on any
 * site -- anything site-specific (a sister blog, a magazine this site
 * frequently credits as an image source, etc.) is left to the
 * `alm_candidate_noise_domains` filter and the Settings screen's own
 * "Additional excluded domains" field, not guessed at here.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Candidate_Classifier {

	/**
	 * Universal non-commerce domains: social/community platforms,
	 * photo/portfolio sharing (credit/inspiration links, not product
	 * pages), encyclopedic references, and WordPress/Google
	 * infrastructure. Matched by suffix, so subdomains (www.,
	 * m., maps.) are covered without listing each one.
	 *
	 * @var string[]
	 */
	const DEFAULT_NOISE_DOMAINS = array(
		// Social / community.
		'instagram.com',
		'facebook.com',
		'twitter.com',
		'x.com',
		'pinterest.com',
		'youtube.com',
		'youtu.be',
		'tiktok.com',
		'linkedin.com',
		'snapchat.com',
		'threads.net',
		// Photo / portfolio sharing -- credit links, not product pages.
		'flickr.com',
		'behance.net',
		'deviantart.com',
		'500px.com',
		// Reference.
		'wikipedia.org',
		'wiktionary.org',
		// WordPress / Google infrastructure.
		'wordpress.org',
		'wordpress.com',
		'gravatar.com',
		'feedburner.com',
		'google.com',
	);

	/**
	 * @param string $url
	 * @return bool
	 */
	public function is_candidate( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			// Relative paths, #anchors, mailto:, tel:, javascript: --
			// none of these are external product links.
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$host = strtolower( $host );

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $home_host && $this->host_matches( $host, strtolower( $home_host ) ) ) {
			// A link back to this site itself (or a subdomain of it)
			// is navigation, never a product page.
			return false;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path && preg_match( '/\.(jpe?g|png|gif|webp|svg|pdf|mp4|mov|avi)$/i', $path ) ) {
			// A direct file link (an image wrapped in an anchor is a
			// common pattern in post content) is never itself a
			// product page.
			return false;
		}

		foreach ( $this->get_noise_domains() as $noise_domain ) {
			if ( $this->host_matches( $host, strtolower( $noise_domain ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * True if $host is $domain itself or a subdomain of it.
	 *
	 * @param string $host
	 * @param string $domain
	 * @return bool
	 */
	private function host_matches( $host, $domain ) {
		if ( '' === $domain ) {
			return false;
		}

		return $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain;
	}

	/**
	 * The default list, merged with the site owner's own additions from
	 * the Settings screen, then filterable for anything a site's own
	 * code wants to add (e.g. a network of sibling sites).
	 *
	 * @return string[]
	 */
	private function get_noise_domains() {
		$domains = self::DEFAULT_NOISE_DOMAINS;

		$custom = (string) get_option( 'alm_candidate_excluded_domains', '' );
		if ( '' !== trim( $custom ) ) {
			$custom_domains = preg_split( '/[\s,]+/', $custom, -1, PREG_SPLIT_NO_EMPTY );
			$domains        = array_merge( $domains, $custom_domains );
		}

		/**
		 * Filters the domain list ALM_Candidate_Classifier treats as
		 * never-a-product-page, in addition to the built-in defaults
		 * and the Settings screen's "Additional excluded domains" field.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $domains
		 */
		return apply_filters( 'alm_candidate_noise_domains', $domains );
	}
}
