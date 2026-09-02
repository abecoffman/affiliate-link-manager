<?php
/**
 * Sovrn Commerce (formerly VigLink) affiliate network provider
 * (classify-only).
 *
 * Not yet confirmed present in honestlywtf's real data -- built
 * proactively from Sovrn's own documented link formats, per explicit
 * user request to cover major real-world networks even before they
 * show up in a scan. Two real, documented domains, not variants of the
 * same one: `redirect.viglink.com/?key=<key>&u=<destination>&type=ap`
 * (the long, auto-generated wrapper links this plugin will actually
 * find in post content) and `sovrn.co` (Sovrn's own vanity short-link
 * domain for links posted where length/appearance matters, e.g. social
 * media). See
 * https://knowledge.sovrn.com/kb/link-shortening-in-commerce and
 * https://support.refersion.com/en/articles/1538624-faq-about-sovrn-commerce-formerly-viglink.
 *
 * Classify-only: Sovrn Commerce is a publisher-side wrapping tool, not
 * something with a per-retailer deep-link API this plugin could call
 * directly, same reasoning as ALM_Provider_CJ/_Rakuten.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Sovrn extends ALM_Provider {

	const REDIRECT_DOMAINS = array(
		'redirect.viglink.com',
		'sovrn.co',
	);

	public function get_id() {
		return 'sovrn';
	}

	public function get_label() {
		return __( 'Sovrn Commerce (VigLink)', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );

		foreach ( self::REDIRECT_DOMAINS as $domain ) {
			if ( $host === $domain ) {
				return true;
			}
			$suffix = '.' . $domain;
			if ( strlen( $host ) > strlen( $suffix ) && 0 === substr_compare( $host, $suffix, -strlen( $suffix ) ) ) {
				return true;
			}
		}

		return false;
	}
}
