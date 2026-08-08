<?php
/**
 * Fallback "unclassified" provider.
 *
 * Matches any URL no other registered provider claimed -- raw retailer
 * links, old shorteners (bit.ly), legacy tracking domains, etc. Always
 * registered last/lowest-priority by ALM_Provider_Registry so it never
 * shadows a real provider.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Generic extends ALM_Provider {

	public function get_id() {
		return 'unclassified';
	}

	public function get_label() {
		return __( 'Unclassified', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		return true;
	}
}
