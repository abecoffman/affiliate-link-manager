<?php
/**
 * Impact.com affiliate network provider (classify-only).
 *
 * Not yet confirmed present in honestlywtf's real data -- built
 * proactively from Impact's own documented redirect-domain family, per
 * explicit user request to cover major real-world networks even
 * before they show up in a scan. Impact's tracking infrastructure
 * spans a documented family of short-link domains, not one single
 * domain -- matching the whole family here, same reasoning as
 * ALM_Provider_CJ matching anrdoezrs.net/jdoqocy.com/etc. as one
 * network rather than only whichever single domain happens to be
 * observed. See
 * https://help.impact.com/other/readme/google-ads-and-impactcom-tracking
 * (sjv.io/pxf.io confirmed Google-certified Impact domains) and
 * Ghostery's WhoTracks.Me tracker database (7eer.net/evyy.net).
 *
 * Classify-only: no public API this plugin can call to generate a new
 * Impact deep link (requires a publisher account and an approved
 * advertiser relationship per-retailer), same reasoning as
 * ALM_Provider_CJ/_Rakuten.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Impact extends ALM_Provider {

	const REDIRECT_DOMAINS = array(
		'sjv.io',
		'pxf.io',
		'7eer.net',
		'evyy.net',
		'pntrs.com',
		'pntrac.com',
	);

	public function get_id() {
		return 'impact';
	}

	public function get_label() {
		return __( 'Impact.com', 'affiliate-link-manager' );
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
