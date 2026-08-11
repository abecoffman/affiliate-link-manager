<?php
/**
 * CJ (Commission Junction) affiliate network provider (classify-only).
 *
 * Confirmed present in honestlywtf's real data: 17 links through
 * anrdoezrs.net (`www.anrdoezrs.net/links/<id>/type/dlg/<destination>`).
 * CJ's own redirect infrastructure spans a documented family of
 * lookalike domains that all serve the identical purpose (which one a
 * given deep link uses depends on which edge cluster generated it, not
 * a different network) -- matching the whole family here rather than
 * only the one domain actually observed, the same way
 * ALM_Provider_Amazon matches every amazon.<tld>, not just amazon.com.
 *
 * Classify-only: no public API this plugin can call to generate a new
 * CJ deep link (that requires a CJ publisher account and an approved
 * advertiser relationship per-retailer), so wrap_url() is deliberately
 * not implemented, same reasoning as ALM_Provider_RewardStyle.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_CJ extends ALM_Provider {

	const REDIRECT_DOMAINS = array(
		'anrdoezrs.net',
		'jdoqocy.com',
		'kqzyfj.com',
		'tkqlhce.com',
		'dpbolvw.net',
	);

	public function get_id() {
		return 'cj';
	}

	public function get_label() {
		return __( 'CJ (Commission Junction)', 'affiliate-link-manager' );
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
			// A subdomain of a known redirect domain (e.g. a CJ-managed
			// custom link domain) counts too -- checked via
			// substr_compare() as "ends with .domain", not a bare
			// substring match, so a lookalike like "notanrdoezrs.net"
			// can never match.
			$suffix = '.' . $domain;
			if ( strlen( $host ) > strlen( $suffix ) && 0 === substr_compare( $host, $suffix, -strlen( $suffix ) ) ) {
				return true;
			}
		}

		return false;
	}
}
