<?php
/**
 * ShopStyle Collective affiliate network provider (classify-only).
 *
 * Confirmed present in honestlywtf's real data: 5 links through
 * shopstyle.it short links. shop-links.co is ShopStyle Collective's
 * other documented short-link domain -- not yet observed here, but
 * matched anyway for the same reason ALM_Provider_Amazon matches every
 * amazon.<tld>: it's the same network's own infrastructure, not a
 * separate thing to wait and discover link-by-link.
 *
 * Classify-only: no public API this plugin can call to generate a new
 * ShopStyle link (requires a Collective account), so wrap_url() is
 * deliberately not implemented, same reasoning as
 * ALM_Provider_RewardStyle.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_ShopStyle extends ALM_Provider {

	const DOMAINS = array(
		'shopstyle.it',
		'shop-links.co',
	);

	public function get_id() {
		return 'shopstyle';
	}

	public function get_label() {
		return __( 'ShopStyle Collective', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && in_array( strtolower( $host ), self::DOMAINS, true );
	}
}
