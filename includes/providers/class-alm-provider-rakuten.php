<?php
/**
 * Rakuten Advertising (formerly LinkShare) affiliate network provider
 * (classify-only).
 *
 * Confirmed present in honestlywtf's real data: 25 links through
 * click.linksynergy.com (`click.linksynergy.com/fs-bin/click?id=...`).
 *
 * Classify-only: no public API this plugin can call to generate a new
 * Rakuten deep link (requires a publisher account and an approved
 * advertiser relationship per-retailer), so wrap_url() is deliberately
 * not implemented, same reasoning as ALM_Provider_RewardStyle.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Rakuten extends ALM_Provider {

	public function get_id() {
		return 'rakuten';
	}

	public function get_label() {
		return __( 'Rakuten Advertising', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && 'click.linksynergy.com' === strtolower( $host );
	}
}
