<?php
/**
 * ShareASale affiliate network provider (classify-only).
 *
 * Not yet confirmed present in honestlywtf's real data -- built
 * proactively from ShareASale's own documented link format, per
 * explicit user request to cover major real-world networks even before
 * they show up in a scan. Real, documented redirect formats:
 * `https://www.shareasale.com/r.cfm?b=<id>&u=<id>&m=<id>` (banner/text
 * links) and `https://shareasale.com/m-pr.cfm?merchantID=...` (deep
 * product links). See
 * https://www.amnavigator.com/blog/2012/09/03/anatomy-of-shareasale-affiliate-links-and-how-to-set-one-up/.
 *
 * Classify-only: no public API this plugin can call to generate a new
 * ShareASale deep link (requires a publisher account and an approved
 * merchant relationship), same reasoning as ALM_Provider_CJ/_Rakuten.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_ShareASale extends ALM_Provider {

	public function get_id() {
		return 'shareasale';
	}

	public function get_label() {
		return __( 'ShareASale', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );

		if ( 'shareasale.com' === $host ) {
			return true;
		}

		// A subdomain of shareasale.com (e.g. www.shareasale.com) counts
		// too -- checked as "ends with .shareasale.com", not a bare
		// substring match, so a lookalike domain can never match.
		$suffix = '.shareasale.com';
		return strlen( $host ) > strlen( $suffix ) && 0 === substr_compare( $host, $suffix, -strlen( $suffix ) );
	}
}
