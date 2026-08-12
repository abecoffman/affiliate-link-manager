<?php
/**
 * Fetches a link's destination page and pulls out a representative
 * product photo -- the Edit modal's thumbnail slot. Mirrors
 * ALM_Domain_Checker's fetch-and-parse shape (real HTTP GET, a real
 * user-agent, the same conservative "no answer beats a wrong one"
 * restraint) rather than inventing a new pattern for this one feature.
 *
 * Deliberately narrow: only `<meta property="og:image">`, falling back
 * to `<meta name="twitter:image">` -- both are standard, deliberate
 * publisher-supplied signals of "this is the representative image for
 * this page," unlike guessing from the first large `<img>` on the
 * page (a logo, a hero banner, an unrelated recommended-product widget
 * image are all more likely than the actual product photo on a real
 * e-commerce page). A wrong thumbnail is worse than none.
 *
 * Fetched on demand and cached on the link row itself
 * (wp_alm_links.thumbnail_url/thumbnail_fetched_at) -- see
 * ALM_Admin::handle_fetch_thumbnail() for the caching rules.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Thumbnail_Fetcher {

	const TIMEOUT = 8;

	/**
	 * Matches ALM_Shortener_Resolver::MAX_HOPS -- found live, sampling
	 * 40 real honestlywtf links: RewardStyle's own rstyle.me redirects
	 * through more than 3 hops before reaching a real page (tracking/
	 * attribution layers, then the retailer's own affiliate redirect,
	 * then the product page itself), so the previous limit of 3 was
	 * failing "too many redirects" on this plugin's single dominant
	 * network before ever reaching a page worth checking for an image.
	 */
	const MAX_REDIRECTS = 5;

	/**
	 * @param string $url
	 * @return array{thumbnail_url:string|null}
	 */
	public function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => self::MAX_REDIRECTS,
				'user-agent'  => $this->user_agent(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'thumbnail_url' => null );
		}

		// Same reasoning as ALM_Domain_Checker::check() -- only a
		// genuine 2xx counts as "reached real content"; anything else
		// (a redirect the API's own limit couldn't resolve, a 4xx/5xx)
		// is inconclusive, not "checked and found no image".
		$http_status = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_status < 200 || $http_status >= 300 ) {
			return array( 'thumbnail_url' => null );
		}

		$image = $this->extract_image( (string) wp_remote_retrieve_body( $response ) );

		// A relative or protocol-relative og:image path is skipped
		// rather than hand-rolling URL resolution against the fetched
		// page's own address -- a deliberate v1 simplification; most
		// real sites already publish an absolute URL here.
		if ( ! $image || ! wp_http_validate_url( $image ) ) {
			return array( 'thumbnail_url' => null );
		}

		return array( 'thumbnail_url' => $image );
	}

	/**
	 * @param string $html
	 * @return string|null
	 */
	private function extract_image( $html ) {
		$og_image = $this->extract_meta_content( $html, 'property', 'og:image' );
		if ( $og_image ) {
			return $og_image;
		}

		return $this->extract_meta_content( $html, 'name', 'twitter:image' );
	}

	/**
	 * Finds a `<meta $attr="$key" content="...">` tag's content value --
	 * checked both attribute orderings, same as
	 * ALM_Domain_Checker::has_meta_tag_pair(), since HTML doesn't
	 * guarantee either comes first.
	 *
	 * @param string $html
	 * @param string $attr
	 * @param string $key
	 * @return string|null
	 */
	private function extract_meta_content( $html, $attr, $key ) {
		$attr = preg_quote( $attr, '#' );
		$key  = preg_quote( $key, '#' );

		if ( preg_match( "#<meta[^>]*{$attr}=[\"']{$key}[\"'][^>]*content=[\"']([^\"']+)[\"']#i", $html, $matches )
			|| preg_match( "#<meta[^>]*content=[\"']([^\"']+)[\"'][^>]*{$attr}=[\"']{$key}[\"']#i", $html, $matches )
		) {
			return html_entity_decode( trim( $matches[1] ), ENT_QUOTES );
		}

		return null;
	}

	/**
	 * Identifies the plugin and links back to the operating site --
	 * same good-citizenship reasoning as the other fetchers in this
	 * plugin.
	 *
	 * @return string
	 */
	private function user_agent() {
		return sprintf(
			'AffiliateLinkManager/%s (+%s; product thumbnail fetch)',
			ALM_VERSION,
			home_url()
		);
	}
}
