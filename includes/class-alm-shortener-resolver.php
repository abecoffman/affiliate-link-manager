<?php
/**
 * Follows a shortened URL's redirect chain to find its real
 * destination -- bit.ly, etsy.me, and the like reveal nothing about
 * what they actually point to from the host alone, unlike every other
 * registered ALM_Provider's host-based matching.
 *
 * Deliberately conservative about *not* asserting a destination: a
 * failed fetch, a timeout, or a chain that never resolves to a final
 * page returns a null destination ("still don't know"), the same
 * restraint ALM_Domain_Checker already uses for "is this a shop."
 * A confirmed-dead shortlink (a 404/410 from the shortener itself) is
 * reported distinctly, not conflated with "still don't know" -- real,
 * common case: on direct inspection, roughly 70% of honestlywtf's own
 * bit.ly links are expired/deleted custom slugs from ~2011-2012, not
 * live links anyone can act on.
 *
 * "Dead" specifically requires two independent 404/410s a couple of
 * seconds apart, not one -- found live, the hard way, testing this
 * class against honestlywtf's real bit.ly links: repeated rapid
 * requests from this same testing session got rate-limited by bit.ly
 * into returning 404 for a link that, checked again moments later on
 * its own, redirected completely normally. A single 404 alone isn't
 * trustworthy enough to move a real link to "stale" over.
 *
 * "Dead" also only ever means the *shortener's own* first response was
 * a 404/410 -- once at least one redirect has been followed, the
 * current URL is treated as the real destination the instant a
 * non-redirect response comes back, whatever that response actually
 * is. Also found live: honestlywtf's real etsy.me links redirect
 * through a couple of scheme-upgrade hops to a real etsy.com URL, but
 * Etsy's own bot protection returns 403 to this resolver's HEAD
 * request on that final page. The chain still correctly ended at a
 * real, meaningful destination URL -- confirming the *page* is
 * reachable was never the point, only finding out *where the
 * shortener points*, which a 403 (or a 500, or anything else) on the
 * destination itself doesn't change.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Shortener_Resolver {

	const TIMEOUT          = 8;
	const MAX_HOPS         = 5;
	const DEAD_RETRY_DELAY = 2;

	/**
	 * Known generic URL-shortener domains -- these reveal nothing about
	 * their destination from the host alone, unlike every registered
	 * ALM_Provider. Extendable via the alm_known_shortener_domains
	 * filter.
	 *
	 * @return string[]
	 */
	public static function known_shortener_domains() {
		$domains = array(
			'bit.ly',
			'tinyurl.com',
			'ow.ly',
			'buff.ly',
			't.co',
			'is.gd',
			'tiny.cc',
			'rebrand.ly',
			'cutt.ly',
			'rb.gy',
			'shorturl.at',
			'goo.gl',
			// Etsy's own shortener -- real inspection of honestlywtf's
			// etsy.me links shows they consistently expand to etsy.com
			// URLs carrying affiliate/partnership tracking params
			// (utm_medium=affiliate, awc=... -- Awin's own click-id
			// param), but not consistently enough to build a confident
			// ALM_Provider on yet. Treated as "expand and let the
			// resolved URL speak for itself" for now, same as any other
			// shortener.
			'etsy.me',
		);

		/**
		 * Filters the list of known generic URL-shortener domains this
		 * resolver expands.
		 *
		 * @since 1.11.0
		 *
		 * @param string[] $domains
		 */
		return apply_filters( 'alm_known_shortener_domains', $domains );
	}

	/**
	 * @param string $url
	 * @return bool
	 */
	public function is_shortener( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && in_array( strtolower( $host ), self::known_shortener_domains(), true );
	}

	/**
	 * @param string $url
	 * @return array{destination:string|null,dead:bool,http_status:int|null}
	 */
	public function resolve( $url ) {
		$result = $this->resolve_once( $url );

		if ( ! $result['dead'] ) {
			return $result;
		}

		// A single 404/410 isn't trusted on its own -- see the class
		// docblock for why. A short, deliberate pause and one full
		// independent re-attempt from scratch (not resuming wherever the
		// first attempt stopped) before treating "dead" as real.
		$this->wait_before_dead_retry();

		return $this->resolve_once( $url );
	}

	/**
	 * A real, deliberate pause -- broken out into its own method (rather
	 * than a bare sleep() inline above) purely so tests can override it
	 * to a no-op; PHP's built-in sleep() isn't something Brain Monkey
	 * can stub the way it stubs WP's own functions.
	 *
	 * @return void
	 */
	protected function wait_before_dead_retry() {
		sleep( self::DEAD_RETRY_DELAY ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_sleep -- deliberate: a follow-up retry after a suspicious 404/410 is inherently going to take real time, same reasoning already applied to this class's own outbound timeouts.
	}

	/**
	 * @param string $url
	 * @return array{destination:string|null,dead:bool,http_status:int|null}
	 */
	private function resolve_once( $url ) {
		$current      = $url;
		$is_first_hop = true;

		for ( $hop = 0; $hop < self::MAX_HOPS; $hop++ ) {
			// wp_safe_remote_head(), not wp_remote_head() -- this
			// follows a chain of URLs supplied by post content, up to
			// MAX_HOPS deep; the "safe" variant blocks requests to
			// private/reserved IP ranges (WP core's own SSRF guard),
			// load-bearing here in a way it isn't for
			// ALM_Domain_Checker (which only ever fetches an already-
			// scanned link's own already-public URL directly, not
			// something that redirects it somewhere new each hop).
			$response = wp_safe_remote_head(
				$current,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'user-agent'  => $this->user_agent(),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'destination' => null,
					'dead'        => false,
					'http_status' => null,
				);
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( $status >= 300 && $status < 400 && $location ) {
				$current      = $location;
				$is_first_hop = false;
				continue;
			}

			if ( $is_first_hop ) {
				// Still checking the shortener's *own* URL -- a 404/410
				// here means the shortlink itself is dead. Anything else
				// non-redirect (5xx, an unrelated 403, ...) is
				// inconclusive: no Location header was ever handed to
				// us, so there's genuinely no destination to report yet.
				return array(
					'destination' => null,
					'dead'        => in_array( $status, array( 404, 410 ), true ),
					'http_status' => $status,
				);
			}

			// At least one real redirect was already followed -- $current
			// is a URL the shortener (or an intermediate hop) actually
			// handed us via its own Location header, so it's the real
			// destination regardless of what this specific status code
			// is (see the class docblock's Etsy/403 example: a blocked
			// HEAD request on the destination doesn't change what URL it
			// actually is).
			return array(
				'destination' => $current,
				'dead'        => false,
				'http_status' => $status,
			);
		}

		// Too many hops -- same "still don't know" treatment as any
		// other inconclusive fetch, not a confirmed destination or a
		// confirmed death.
		return array(
			'destination' => null,
			'dead'        => false,
			'http_status' => null,
		);
	}

	/**
	 * Identifies the plugin and links back to the operating site --
	 * same good-citizenship reasoning as ALM_Domain_Checker's own
	 * user agent.
	 *
	 * @return string
	 */
	private function user_agent() {
		return sprintf(
			'AffiliateLinkManager/%s (+%s; shortened-link resolution)',
			ALM_VERSION,
			home_url()
		);
	}
}
