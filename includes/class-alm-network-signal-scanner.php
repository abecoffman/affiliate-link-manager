<?php
/**
 * Surfaces "this domain looks like it belongs to a known affiliate
 * network we haven't built an ALM_Provider for yet" -- an ongoing,
 * automatic version of the one-time manual cross-reference that found
 * ALM_Provider_CJ/Rakuten/ShopStyle in honestlywtf's real data. The
 * point: the next unrecognized network should surface here on its
 * own, not need another screenshot to notice.
 *
 * Deliberately conservative, same standard the real providers built
 * from this list were held to: a small, curated set of well-
 * documented redirect-domain families for major networks, not a
 * generic "any URL with a tracking-looking query param" heuristic
 * (which would false-positive on ordinary site analytics params).
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Network_Signal_Scanner {

	const TRANSIENT_KEY = 'alm_network_signals';
	const CACHE_TTL     = HOUR_IN_SECONDS;

	/**
	 * Known affiliate-network redirect-domain families with no real
	 * ALM_Provider built yet. Extendable via the
	 * alm_known_unrecognized_network_domains filter.
	 *
	 * Once a real provider is built for one of these (see
	 * includes/providers/ -- ALM_Provider_CJ, _Rakuten, and _ShopStyle
	 * all started life as an entry here), remove it from this list:
	 * ALM_Provider_Registry::match_url() will claim those links
	 * directly on the next scan, and they'll naturally stop appearing
	 * here (having stopped being "unclassified" at all).
	 *
	 * @return array<string,string> domain => network label.
	 */
	public static function known_unrecognized_domains() {
		$domains = array(
			'go.skimresources.com' => 'Skimlinks',
			'redirect.viglink.com' => 'Skimlinks / VigLink (Sovrn)',
			'awin1.com'            => 'Awin',
			'prf.hn'               => 'Partnerize',
			'sjv.io'               => 'Impact.com',
			'pntrs.com'            => 'Impact.com',
			'pntrac.com'           => 'Impact.com',
			'narrativ.com'         => 'Narrativ',
		);

		/**
		 * Filters the list of known-but-not-yet-built affiliate network
		 * redirect domains this scanner watches for.
		 *
		 * @since 1.10.0
		 *
		 * @param array<string,string> $domains domain => network label.
		 */
		return apply_filters( 'alm_known_unrecognized_network_domains', $domains );
	}

	/**
	 * Cached (1 hour) -- this walks every currently-unclassified row's
	 * URL, which is real work at honestlywtf's scale (35,000+ rows);
	 * no reason to redo it on every single Dashboard page load when
	 * the underlying data only changes on a scan.
	 *
	 * @return array<string,array{label:string,count:int,sample_url:string}> Keyed by domain.
	 */
	public function scan() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$results = $this->scan_uncached();
		set_transient( self::TRANSIENT_KEY, $results, self::CACHE_TTL );

		return $results;
	}

	/**
	 * @return array<string,array{label:string,count:int,sample_url:string}>
	 */
	private function scan_uncached() {
		$known = self::known_unrecognized_domains();
		if ( ! $known ) {
			return array();
		}

		global $wpdb;
		$table = ALM_Install::table_name();

		// provider = 'unclassified', not category -- this is about "no
		// real network recognized this URL yet" (what a new
		// ALM_Provider would fix), independent of whether
		// ALM_Candidate_Classifier separately decided the link looks
		// like noise (category=nonaffiliate) or a real opportunity
		// (category=candidate). Confirmed against real data: the CJ/
		// Rakuten/ShopStyle links this scanner is modeled on were
		// almost all category=candidate already, not noise -- filtering
		// on category here would have missed the exact case this exists
		// to catch.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; cached for an hour, not run per-request.
		$urls = $wpdb->get_col( "SELECT url FROM {$table} WHERE provider = 'unclassified'" );

		$results = array();
		foreach ( $urls as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! is_string( $host ) ) {
				continue;
			}
			$host = strtolower( $host );

			foreach ( $known as $domain => $label ) {
				if ( $host !== $domain && ! $this->is_subdomain_of( $host, $domain ) ) {
					continue;
				}

				if ( ! isset( $results[ $domain ] ) ) {
					$results[ $domain ] = array(
						'label'      => $label,
						'count'      => 0,
						'sample_url' => $url,
					);
				}
				++$results[ $domain ]['count'];
				break;
			}
		}

		return $results;
	}

	/**
	 * @param string $host
	 * @param string $domain
	 * @return bool
	 */
	private function is_subdomain_of( $host, $domain ) {
		$suffix = '.' . $domain;
		return strlen( $host ) > strlen( $suffix ) && 0 === substr_compare( $host, $suffix, -strlen( $suffix ) );
	}
}
