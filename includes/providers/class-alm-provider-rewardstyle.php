<?php
/**
 * RewardStyle / LTK affiliate network provider (classify-only).
 *
 * honestlywtf's dominant existing affiliate network by far -- this
 * project's initial content audit found 559 rstyle.me links, versus 9
 * go.shopmy.us links. Recognized so the admin dashboard can label these
 * links correctly, but this provider deliberately does not implement
 * wrap_url(): converting a link that's already earning under a
 * different network is a real monetization decision, not something
 * this plugin should ever do automatically or even offer as a casual
 * one-click action.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_RewardStyle extends ALM_Provider {

	public function get_id() {
		return 'rewardstyle';
	}

	public function get_label() {
		return __( 'RewardStyle / LTK', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && 'rstyle.me' === strtolower( $host );
	}
}
