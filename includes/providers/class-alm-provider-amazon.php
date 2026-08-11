<?php
/**
 * Amazon Associates affiliate network provider (classify-only).
 *
 * Two real, distinct shapes on honestlywtf, found via direct DB
 * inspection before building this: `amzn.to` short links (always a
 * real Associates link -- that's the only thing Amazon's own official
 * shortener is for) and direct `amazon.<tld>` product links carrying a
 * `tag` query parameter (Amazon's own affiliate tracking ID). The
 * second case is deliberately gated on `tag` actually being present --
 * honestlywtf has 185 amazon.com links total, but only 108 of them
 * carry a real tag; the other 77 are old, unmonetized product links
 * from years before this site had an Associates account. Matching
 * amazon.* unconditionally would misclassify all 77 of those as
 * already-tracked, when they're genuinely still Candidates.
 *
 * Classify-only, same reasoning as ALM_Provider_RewardStyle: there's
 * no API this plugin can call to generate a real Associates link (that
 * requires the site's own Associates account, via Amazon's SiteStripe
 * tool or manually appending a tag), so wrap_url() is deliberately not
 * implemented here.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Amazon extends ALM_Provider {

	public function get_id() {
		return 'amazon';
	}

	public function get_label() {
		return __( 'Amazon Associates', 'affiliate-link-manager' );
	}

	public function matches_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );

		if ( 'amzn.to' === $host ) {
			return true;
		}

		// Matches amazon.com, amazon.co.uk, amazon.de, smile.amazon.com,
		// etc. -- but not a lookalike domain like notamazon.com, since
		// "amazon." has to be preceded by the start of the host or a
		// literal dot, never a bare substring match.
		if ( ! preg_match( '/(^|\.)amazon\.[a-z.]+$/', $host ) ) {
			return false;
		}

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}

		parse_str( $query, $params );
		return ! empty( $params['tag'] );
	}
}
