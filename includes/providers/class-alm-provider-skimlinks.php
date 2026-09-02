<?php
/**
 * Skimlinks affiliate network provider (classify-only).
 *
 * Not yet confirmed present in honestlywtf's real data -- built
 * proactively from Skimlinks' own documented link formats, per
 * explicit user request to cover major real-world networks even
 * before they show up in a scan. Two real, documented domains:
 * `go.skimresources.com/?id=<id>&url=<destination>&sref=<page>` (the
 * long, auto-generated wrapper link) and `fave.co` (Skimlinks' own
 * branded short-link domain, documented as an alternate form of the
 * same long link). See
 * https://developers.skimlinks.com/link.html.
 *
 * Classify-only: Skimlinks is a publisher-side wrapping tool, not
 * something with a per-retailer deep-link API this plugin could call
 * directly, same reasoning as ALM_Provider_CJ/_Rakuten.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Skimlinks extends ALM_Provider {

	const REDIRECT_DOMAINS = array(
		'go.skimresources.com',
		'fave.co',
	);

	public function get_id() {
		return 'skimlinks';
	}

	public function get_label() {
		return __( 'Skimlinks', 'affiliate-link-manager' );
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
