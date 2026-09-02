<?php
/**
 * Partnerize affiliate network provider (classify-only).
 *
 * Not yet confirmed present in honestlywtf's real data -- built
 * proactively from Partnerize's own documented link format, per
 * explicit user request to cover major real-world networks even
 * before they show up in a scan. Real, documented redirect format:
 * `https://prf.hn/click/camref:<id>` (optionally with `/pubref:...`,
 * `/ar:...`, `/destination:...` segments appended). See
 * https://docs.fmtc.co/kb/partnerize.
 *
 * Also matches gopjn.com -- Pepperjam's own domain before it was
 * acquired by Partnerize in 2020 and rebranded "Ascend by Partnerize";
 * the same tracking infrastructure, not a separate network.
 *
 * Classify-only: no public API this plugin can call to generate a new
 * Partnerize deep link (requires a publisher account and an approved
 * advertiser relationship per-retailer), same reasoning as
 * ALM_Provider_CJ/_Rakuten.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Partnerize extends ALM_Provider {

	const REDIRECT_DOMAINS = array(
		'prf.hn',
		'gopjn.com',
	);

	public function get_id() {
		return 'partnerize';
	}

	public function get_label() {
		return __( 'Partnerize', 'affiliate-link-manager' );
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
