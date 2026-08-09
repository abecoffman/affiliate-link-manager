<?php
/**
 * Decides which links the "unclassified" fallback provider matched are
 * actually worth a human's attention as affiliate-link candidates, vs.
 * pure noise -- internal navigation, social/embed platforms, and
 * reference sites that will never be a real affiliate opportunity.
 *
 * Deliberately conservative in one direction only: everything not
 * positively identified as noise defaults to "candidate". A missed
 * noise domain is a minor annoyance (one extra row to skip past); a
 * real retailer link wrongly buried back in the noise bucket defeats
 * the entire point of this class. The default noise list only
 * includes domains that are *never* a shoppable product page on any
 * site -- anything site-specific (a sister blog, a magazine this site
 * frequently credits as an image source, etc.) is left to the
 * `alm_candidate_noise_domains` filter and the Settings screen's own
 * "Additional excluded domains" field, not guessed at here.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Candidate_Classifier {

	/**
	 * Universal non-commerce domains: social/community platforms,
	 * photo/portfolio sharing (credit/inspiration links, not product
	 * pages), encyclopedic references, and WordPress/Google
	 * infrastructure. Matched by suffix, so subdomains (www.,
	 * m., maps.) are covered without listing each one.
	 *
	 * @var string[]
	 */
	const DEFAULT_NOISE_DOMAINS = array(
		// Social / community, including short-link domain variants
		// (instagr.am, t.co, pin.it, fb.me are the *same* platforms as
		// instagram.com/twitter.com/pinterest.com/facebook.com under a
		// different host, not separate services).
		'instagram.com',
		'instagr.am',
		'facebook.com',
		'fb.me',
		'twitter.com',
		'x.com',
		't.co',
		'pinterest.com',
		'pin.it',
		'youtube.com',
		'youtu.be',
		'tiktok.com',
		'linkedin.com',
		'snapchat.com',
		'threads.net',
		'vimeo.com',
		// Photo / portfolio sharing -- credit links, not product pages.
		'flickr.com',
		'behance.net',
		'deviantart.com',
		'500px.com',
		// Reference.
		'wikipedia.org',
		'wiktionary.org',
		'imdb.com',
		// Blog-hosting platforms -- a personal blogspot/tumblr is
		// content, not a storefront, regardless of which specific blog
		// it is. Matched by suffix like everything else here, so this
		// covers every subdomain (anyname.blogspot.com, etc.) at once.
		'blogspot.com',
		'tumblr.com',
		// Major editorial / magazine / media publishers -- these come
		// up constantly as image-source or "as seen in" credit links on
		// a lifestyle/DIY/fashion blog, never as a product page. Found
		// by looking at this site's own actual candidate list (not
		// guessed): honestlywtf alone had 600+ links to sites in this
		// category showing up as false-positive candidates before this
		// list existed. Still domain identity, not content -- a
		// publisher's own online shop (e.g. a magazine-branded product
		// line) would incorrectly get excluded too; none of these are
		// known to run one.
		'vogue.com',
		'style.com',
		'elle.com',
		'refinery29.com',
		'architecturaldigest.com',
		'elledecor.com',
		'apartmenttherapy.com',
		'domino.com',
		'countryliving.com',
		'housebeautiful.com',
		'nytimes.com',
		'latimesmagazine.com',
		'mydomaine.com',
		'nymag.com',
		'designboom.com',
		'mymodernmet.com',
		'thisiscolossal.com',
		'boredpanda.com',
		'desiretoinspire.net',
		'thejealouscurator.com',
		'sfgirlbybay.com',
		'thedesignfiles.net',
		'stylebistro.com',
		'fashionising.com',
		'domainehome.com',
		'missmoss.co.za',
		'fashiongonerogue.com',
		'lonny.com',
		'designsponge.com',
		// Never a product page regardless of niche.
		'uber.com',
		// WordPress / Google infrastructure, and FeedBlitz -- a common
		// 2010s-era blog subscription/RSS widget, never a shop.
		'wordpress.org',
		'wordpress.com',
		'gravatar.com',
		'feedburner.com',
		'fmpub.net',
		'google.com',
	);

	/**
	 * @param string    $url
	 * @param bool|null $domain_verdict A real, content-checked verdict
	 *                  for this URL's domain from ALM_Domain_Checker
	 *                  (via ALM_Domain_Scanner's cache), if one exists.
	 *                  Takes precedence over the static domain-name
	 *                  denylist below when given -- actual page content
	 *                  is strictly more reliable than a guess from the
	 *                  domain name. Structural checks (scheme, internal
	 *                  link, direct file link) still apply first
	 *                  regardless: a real shop's own image asset is
	 *                  never a product page just because the domain is
	 *                  confirmed to be a shop.
	 * @return bool
	 */
	public function is_candidate( $url, $domain_verdict = null ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			// Relative paths, #anchors, mailto:, tel:, javascript: --
			// none of these are external product links.
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$host = strtolower( $host );

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $home_host && $this->host_matches( $host, strtolower( $home_host ) ) ) {
			// A link back to this site itself (or a subdomain of it)
			// is navigation, never a product page.
			return false;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path && preg_match( '/\.(jpe?g|png|gif|webp|svg|pdf|mp4|mov|avi)$/i', $path ) ) {
			// A direct file link (an image wrapped in an anchor is a
			// common pattern in post content) is never itself a
			// product page.
			return false;
		}

		if ( null !== $domain_verdict ) {
			return $domain_verdict;
		}

		foreach ( $this->get_noise_domains() as $noise_domain ) {
			if ( $this->host_matches( $host, strtolower( $noise_domain ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * True if $host is $domain itself or a subdomain of it.
	 *
	 * @param string $host
	 * @param string $domain
	 * @return bool
	 */
	private function host_matches( $host, $domain ) {
		if ( '' === $domain ) {
			return false;
		}

		return $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain;
	}

	/**
	 * The default list, merged with the site owner's own additions from
	 * the Settings screen, then filterable for anything a site's own
	 * code wants to add (e.g. a network of sibling sites).
	 *
	 * @return string[]
	 */
	private function get_noise_domains() {
		$domains = self::DEFAULT_NOISE_DOMAINS;

		$custom = (string) get_option( 'alm_candidate_excluded_domains', '' );
		if ( '' !== trim( $custom ) ) {
			$custom_domains = preg_split( '/[\s,]+/', $custom, -1, PREG_SPLIT_NO_EMPTY );
			$domains        = array_merge( $domains, $custom_domains );
		}

		/**
		 * Filters the domain list ALM_Candidate_Classifier treats as
		 * never-a-product-page, in addition to the built-in defaults
		 * and the Settings screen's "Additional excluded domains" field.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $domains
		 */
		return apply_filters( 'alm_candidate_noise_domains', $domains );
	}
}
