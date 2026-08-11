<?php
/**
 * Resumable batch runner resolving shortened links to their real
 * destination and reclassifying based on it -- mirrors
 * ALM_Domain_Scanner's batching pattern (small batches, since real
 * outbound fetches are much slower than this plugin's own DB-only
 * work), but per-link rather than per-domain: unlike a domain's
 * shared "is this a shop" verdict, a shortener's destination is
 * unique to each individual short URL, so there's no shared cache to
 * check first, just the row itself (see ALM_Install::create_table()'s
 * resolved_url/resolved_at columns).
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Shortener_Scanner {

	/**
	 * @var ALM_Shortener_Resolver
	 */
	private $resolver;

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	public function __construct( ALM_Shortener_Resolver $resolver, ALM_Provider_Registry $providers ) {
		$this->resolver  = $resolver;
		$this->providers = $providers;
	}

	/**
	 * @return int
	 */
	public function count_pending() {
		global $wpdb;
		$table = ALM_Install::table_name();

		list( $placeholders, $domains ) = $this->domains_in_clause();
		if ( ! $domains ) {
			return 0;
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE resolved_at IS NULL AND provider = 'unclassified' AND status != %s"
			. " AND SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/', 3), '://', -1) IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %s tokens, not user input; real values bound via prepare() below.

		$params = array_merge( array( ALM_Install::STATUS_IGNORED ), $domains );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
	}

	/**
	 * @param int  $batch_size
	 * @param bool $is_first_of_run True only for the click-triggered
	 *                               first batch of a run -- same
	 *                               "mark when this run started" role
	 *                               ALM_Domain_Scanner::check_batch()'s
	 *                               own param plays.
	 * @return array{done:bool,checked:int,remaining:int}
	 */
	public function check_batch( $batch_size, $is_first_of_run = false ) {
		if ( $is_first_of_run ) {
			update_option( 'alm_shortener_expand_started_at', current_time( 'mysql' ) );
		}

		global $wpdb;
		$table = ALM_Install::table_name();

		list( $placeholders, $domains ) = $this->domains_in_clause();
		if ( ! $domains ) {
			$this->finish_run_if_done( true );
			return array(
				'done'      => true,
				'checked'   => 0,
				'remaining' => 0,
			);
		}

		$sql = "SELECT * FROM {$table} WHERE resolved_at IS NULL AND provider = 'unclassified' AND status != %s"
			. " AND SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/', 3), '://', -1) IN ({$placeholders}) LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as count_pending() above.

		$params = array_merge( array( ALM_Install::STATUS_IGNORED ), $domains, array( $batch_size ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		foreach ( (array) $rows as $row ) {
			$this->resolve_one( $row );
		}

		$remaining = $this->count_pending();
		$done      = 0 === $remaining;
		$this->finish_run_if_done( $done );

		return array(
			'done'      => $done,
			'checked'   => count( $rows ),
			'remaining' => $remaining,
		);
	}

	/**
	 * @param bool $done
	 * @return void
	 */
	private function finish_run_if_done( $done ) {
		if ( ! $done ) {
			return;
		}

		$this->record_expand_delta( get_option( 'alm_shortener_expand_started_at', '' ) );
		update_option( 'alm_last_shortener_expand_time', current_time( 'mysql' ) );
	}

	/**
	 * Records what this run actually found -- read by the Dashboard's
	 * Tasks table, same role ALM_Domain_Scanner::record_check_delta()
	 * plays for Check Domains. Scoped to links resolved_at >= $started_at
	 * (this run only).
	 *
	 * @param string $started_at MySQL datetime this run began, or '' if unknown.
	 * @return void
	 */
	private function record_expand_delta( $started_at ) {
		$reclassified = 0;
		$stale        = 0;

		if ( $started_at ) {
			global $wpdb;
			$table = ALM_Install::table_name();

			$sql  = "SELECT status, COUNT(*) as total FROM {$table} WHERE resolved_at >= %s GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $started_at ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built entirely from prepare() above; small admin-only aggregate query.

			foreach ( (array) $rows as $row ) {
				if ( ALM_Install::STATUS_ACTIVE === $row['status'] ) {
					$reclassified = (int) $row['total'];
				} elseif ( ALM_Install::STATUS_STALE === $row['status'] ) {
					$stale = (int) $row['total'];
				}
			}
		}

		update_option(
			'alm_last_shortener_expand_delta',
			array(
				'reclassified' => $reclassified,
				'stale'        => $stale,
			)
		);
	}

	/**
	 * @param array $row A full wp_alm_links row.
	 * @return void
	 */
	private function resolve_one( array $row ) {
		$result = $this->resolver->resolve( $row['url'] );

		global $wpdb;
		$table = ALM_Install::table_name();
		$now   = current_time( 'mysql' );

		if ( $result['dead'] ) {
			// Confirmed dead (404/410 from the shortener itself) -- not
			// a real opportunity anymore, marked stale rather than left
			// looking like an untouched Candidate forever.
			$wpdb->update(
				$table,
				array(
					'resolved_at' => $now,
					'status'      => ALM_Install::STATUS_STALE,
				),
				array( 'id' => $row['id'] )
			);
			return;
		}

		if ( null === $result['destination'] ) {
			// Inconclusive (timeout, 5xx, too many hops) -- leave status
			// exactly as-is, the same "still don't know" restraint
			// ALM_Domain_Checker uses. Still mark resolved_at so a
			// slow/broken fetch isn't retried every single batch
			// forever -- a future full rescan will pick it up again.
			$wpdb->update( $table, array( 'resolved_at' => $now ), array( 'id' => $row['id'] ) );
			return;
		}

		$matched_provider = $this->providers->match_url( $result['destination'] );

		$data = array(
			'resolved_url' => $result['destination'],
			'resolved_at'  => $now,
		);

		// A confident match against an already-registered real network
		// -- reclassify exactly the way a directly-matched URL would
		// be, same trust level. An unmatched destination (a plain,
		// unwrapped retailer URL, or something only
		// ALM_Candidate_Classifier would have an opinion on) is
		// deliberately left as whatever status it already had -- still
		// a real Candidate, just a richer one with its actual
		// destination now visible, not silently promoted to "active"
		// on a guess.
		if ( ! ( $matched_provider instanceof ALM_Provider_Generic ) ) {
			$data['provider'] = $matched_provider->get_id();
			$data['status']   = ALM_Install::STATUS_ACTIVE;
		}

		$wpdb->update( $table, $data, array( 'id' => $row['id'] ) );
	}

	/**
	 * @return array{0:string,1:string[]} SQL IN() placeholder string, and the domains themselves.
	 */
	private function domains_in_clause() {
		$domains = ALM_Shortener_Resolver::known_shortener_domains();

		return array( implode( ',', array_fill( 0, count( $domains ), '%s' ) ), $domains );
	}
}
