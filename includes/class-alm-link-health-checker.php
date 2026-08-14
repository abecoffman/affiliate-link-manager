<?php
/**
 * Checks whether a single candidate link's destination is actually
 * reachable -- a dead destination can never earn a commission,
 * regardless of how good a "candidate" it otherwise looks like. Found
 * live, sampling real honestlywtf links while diagnosing a separate
 * thumbnail-fetch issue: a meaningful share of Candidate Affiliate
 * Links point at domains that no longer resolve, or specific product
 * pages that 404 on an otherwise-live domain -- neither of which
 * ALM_Domain_Checker catches, since it only ever samples one URL per
 * *domain* and can't see a single dead product page on a domain
 * that's otherwise fine.
 *
 * "Dead" is deliberately narrow, mirroring ALM_Shortener_Resolver's
 * own precedent for the same underlying question (confirmed only
 * after two independent checks, a couple of seconds apart -- a single
 * failed request from an automated batch isn't trustworthy enough on
 * its own, and this batch is if anything *more* exposed to that risk
 * than the shortener case, since several candidates often share the
 * same retailer domain):
 *
 * - A 404/410 response -- an explicit "not found"/"gone".
 * - A confirmed DNS resolution failure ("could not resolve host") --
 *   the one deliberate departure from ALM_Shortener_Resolver's own
 *   rule of treating every WP_Error as merely inconclusive. Real dead
 *   domains showed up this way in the same diagnostic that motivated
 *   this class, and an NXDOMAIN is a far more stable signal than a
 *   generic timeout: DNS records don't flap the way a slow or
 *   temporarily overloaded server does. Every *other* transport
 *   failure (timeout, connection refused, TLS/certificate errors)
 *   stays inconclusive, same as elsewhere in this plugin.
 *
 * Everything else -- a 403, any 5xx, a timeout, an SSL error -- is
 * explicitly *not* treated as dead. The diagnostic that motivated this
 * class found several 403s from clearly live, legitimate major
 * retailers (blocking automated requests, not actually gone);
 * mistaking those for dead links would hide real opportunities, the
 * opposite of the goal.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Link_Health_Checker {

	const TIMEOUT          = 8;
	const MAX_REDIRECTS    = 5; // Matches ALM_Thumbnail_Fetcher::MAX_REDIRECTS -- same wrapped-affiliate-link-needs-several-hops reasoning.
	const DEAD_RETRY_DELAY = 2;

	/**
	 * @param string $url
	 * @return array{alive:bool|null,dead:bool,http_status:int|null}
	 */
	public function check( $url ) {
		$result = $this->check_once( $url );

		if ( ! $result['dead'] ) {
			return $result;
		}

		// A single 404/410 or DNS failure isn't trusted on its own --
		// see the class docblock for why. A short, deliberate pause and
		// one full independent re-attempt before treating "dead" as real.
		$this->wait_before_dead_retry();

		return $this->check_once( $url );
	}

	/**
	 * Broken out into its own method (rather than a bare sleep() inline
	 * above) purely so tests can override it to a no-op -- PHP's
	 * built-in sleep() isn't something Brain Monkey can stub the way it
	 * stubs WP's own functions. Same seam ALM_Shortener_Resolver already
	 * uses for the identical reason.
	 *
	 * @return void
	 */
	protected function wait_before_dead_retry() {
		sleep( self::DEAD_RETRY_DELAY ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_sleep -- deliberate: a follow-up retry after a suspicious dead signal is inherently going to take real time, same reasoning already applied elsewhere in this plugin (ALM_Shortener_Resolver).
	}

	/**
	 * @param string $url
	 * @return array{alive:bool|null,dead:bool,http_status:int|null}
	 */
	private function check_once( $url ) {
		// wp_safe_remote_get(), not wp_remote_get() -- see
		// ALM_Domain_Checker::check()'s identical comment; this follows
		// up to MAX_REDIRECTS hops auto-followed by WP core, and each
		// hop's destination needs the same SSRF guard.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => self::MAX_REDIRECTS,
				'user-agent'  => $this->user_agent(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'alive'       => null,
				'dead'        => $this->is_dns_failure( $response ),
				'http_status' => null,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $status, array( 404, 410 ), true ) ) {
			return array(
				'alive'       => null,
				'dead'        => true,
				'http_status' => $status,
			);
		}

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'alive'       => true,
				'dead'        => false,
				'http_status' => $status,
			);
		}

		// 403, 5xx, or anything else non-2xx/404/410 -- inconclusive,
		// not dead. See the class docblock for why this matters.
		return array(
			'alive'       => null,
			'dead'        => false,
			'http_status' => $status,
		);
	}

	/**
	 * curl error 6 ("Could not resolve host") is the standard, stable
	 * message text for a real DNS/NXDOMAIN failure regardless of curl
	 * version -- WP core's HTTP API doesn't expose a structured error
	 * code that distinguishes this from any other transport failure, so
	 * this is the only reliable way to isolate it from a generic
	 * timeout or connection-refused error, both of which stay
	 * inconclusive (see class docblock).
	 *
	 * @param \WP_Error $error
	 * @return bool
	 */
	private function is_dns_failure( $error ) {
		return false !== stripos( $error->get_error_message(), 'could not resolve host' );
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
			'AffiliateLinkManager/%s (+%s; candidate link health check)',
			ALM_VERSION,
			home_url()
		);
	}
}
