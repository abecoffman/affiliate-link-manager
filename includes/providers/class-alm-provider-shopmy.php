<?php
/**
 * ShopMy affiliate network provider.
 *
 * Recognizes go.shopmy.us links (both the manually-created short-link
 * form, https://go.shopmy.us/p-<id>, and the redirect-wrapper form,
 * https://go.shopmy.us/apx/<affiliateId>?url=<url>&c=<collectionId>) and
 * can build new redirect-wrapper links once an affiliate ID is
 * configured.
 *
 * ShopMy's real creator API -- which would let this resolve an arbitrary
 * product URL against their catalog and confirm the destination
 * retailer is actually monetizable through their network -- is not yet
 * publicly available ("no API Key for Creators" as of this plugin's
 * initial build). wrap_url() only builds the documented redirect
 * format; it can't verify eligibility server-side. See
 * https://guide.shopmy.us/creating-and-sharing-links for the documented
 * link formats this is based on.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_ShopMy extends ALM_Provider {

	const OPTION_AFFILIATE_ID  = 'alm_shopmy_affiliate_id';
	const OPTION_COLLECTION_ID = 'alm_shopmy_collection_id';

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

	public function can_wrap() {
		return $this->is_configured();
	}

	public function is_configured() {
		return '' !== (string) get_option( self::OPTION_AFFILIATE_ID, '' );
	}

	public function wrap_url( $url, array $args = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'alm_shopmy_not_configured',
				__( 'ShopMy affiliate ID is not set. Add it under Affiliate Links → Providers first.', 'affiliate-link-manager' )
			);
		}

		$affiliate_id  = get_option( self::OPTION_AFFILIATE_ID, '' );
		$collection_id = isset( $args['collection_id'] ) ? $args['collection_id'] : get_option( self::OPTION_COLLECTION_ID, '' );

		$query = array( 'url' => $url );
		if ( '' !== (string) $collection_id ) {
			$query['c'] = $collection_id;
		}

		return add_query_arg( $query, 'https://go.shopmy.us/apx/' . rawurlencode( $affiliate_id ) );
	}
}
