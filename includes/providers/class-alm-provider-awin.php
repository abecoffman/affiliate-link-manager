<?php
/**
 * Awin affiliate network provider (classify-only).
 *
 * Not yet confirmed present in honestlywtf's real data -- unlike this
 * plugin's earlier providers, built proactively from Awin's own current
 * publisher documentation rather than an observed link, per explicit
 * user request to cover major real-world networks even before they
 * show up in a scan. Real, documented redirect format:
 * `https://www.awin1.com/cread.php?awinmid=<id>&awinaffid=<id>&clickref=<subid>&ued=<destination>`.
 * See https://help.awin.com/developers/docs/click-appends-dyn-params.
 *
 * Classify-only: no public API this plugin can call to generate a new
 * Awin deep link (requires a publisher account and an approved
 * advertiser relationship per-retailer), same reasoning as
 * ALM_Provider_CJ/_Rakuten.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Awin extends ALM_Provider {

	public function get_id() {
		return 'awin';
	}

	public function get_label() {
		return __( 'Awin', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );

		if ( 'awin1.com' === $host ) {
			return true;
		}

		// A subdomain of awin1.com (e.g. www.awin1.com) counts too --
		// checked as "ends with .awin1.com", not a bare substring match,
		// so a lookalike domain can never match.
		$suffix = '.awin1.com';
		return strlen( $host ) > strlen( $suffix ) && 0 === substr_compare( $host, $suffix, -strlen( $suffix ) );
	}
}
