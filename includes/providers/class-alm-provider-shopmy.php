<?php
/**
 * ShopMy affiliate network provider (classify-only).
 *
 * Recognizes go.shopmy.us links (both the manually-created short-link
 * form, https://go.shopmy.us/p-<id>, and the redirect-wrapper form,
 * https://go.shopmy.us/apx/<affiliateId>?url=<url>&c=<collectionId>) so
 * the admin dashboard can label these links correctly.
 *
 * Deliberately does not implement wrap_url(): ShopMy's real creator
 * API -- which would let this resolve an arbitrary product URL against
 * their catalog and confirm the destination retailer is actually
 * monetizable through their network -- is not publicly available ("no
 * API Key for Creators" as of this plugin's initial build). Building
 * the documented redirect-wrapper URL by hand without that
 * verification would let this plugin hand out links it can't actually
 * confirm will earn anything, which is worse than just not offering
 * to build one at all -- same classify-only reasoning as every other
 * provider here (RewardStyle, Amazon, CJ, Rakuten, ShopStyle). See
 * https://guide.shopmy.us/creating-and-sharing-links for the
 * documented link formats this recognizes.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_ShopMy extends ALM_Provider {

	public function get_id() {
		return 'shopmy';
	}

	public function get_label() {
		return __( 'ShopMy', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && 'go.shopmy.us' === strtolower( $host );
	}
}
