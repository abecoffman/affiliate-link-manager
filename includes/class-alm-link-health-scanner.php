<?php
/**
 * Resumable batch runner checking real reachability for candidate
 * links -- mirrors ALM_Domain_Scanner's own batching/delta-recording
 * shape (see its docblock for the small-batch-size reasoning, doubly
 * true here since several candidates often share one retailer domain,
 * a real rate-limiting risk already found live once this session).
 *
 * Deliberately scoped to category=candidate only -- directly the
 * "is this actually a good candidate" question this class exists to
 * answer. Not category=affiliate: an already-converted, already-earning
 * link is a higher-stakes thing to auto-touch, out of scope here. Not
 * an already-nonaffiliate row (stale or dead): no auto-recovery
 * attempt -- if a later full scan rediscovers the same dead link in
 * fresh post content it naturally comes back as a Candidate, and the
 * next health-check batch simply marks it dead again, a stable
 * self-correcting cycle that needs no special-casing.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Link_Health_Scanner {

	/**
	 * A checked candidate is trusted for this long before it's eligible
	 * for a re-check -- shorter than ALM_Domain_Scanner::RECHECK_AFTER_DAYS
	 * (90) since a Candidate is actively being worked and worth
	 * re-verifying sooner than a settled shop/not-shop domain verdict.
	 */
	const RECHECK_AFTER_DAYS = 30;

	/**
	 * @var ALM_Link_Health_Checker
	 */
	private $checker;

	public function __construct( ALM_Link_Health_Checker $checker ) {
		$this->checker = $checker;
	}

	/**
	 * @return int
	 */
	public function count_pending() {
		global $wpdb;
		$table = ALM_Install::table_name();

		$sql = "SELECT COUNT(*) FROM {$table} WHERE category = %s AND (health_checked_at IS NULL OR health_checked_at < %s)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ALM_Install::CATEGORY_CANDIDATE, $this->recheck_cutoff() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built entirely from prepare() above; small admin-only aggregate query.
	}

	/**
	 * @param int  $batch_size
	 * @param bool $is_first_of_run True only for the click-triggered
	 *                               first batch of a run -- same role
	 *                               ALM_Domain_Scanner::check_batch()'s
	 *                               own param plays.
	 * @return array{done:bool,checked:int,remaining:int}
	 */
	public function check_batch( $batch_size, $is_first_of_run = false ) {
		if ( $is_first_of_run ) {
			update_option( 'alm_link_health_started_at', current_time( 'mysql' ) );
		}

		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; real values bound via prepare() below.
		$sql  = "SELECT id, url FROM {$table} WHERE category = %s AND (health_checked_at IS NULL OR health_checked_at < %s) ORDER BY health_checked_at IS NULL DESC, health_checked_at ASC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ALM_Install::CATEGORY_CANDIDATE, $this->recheck_cutoff(), $batch_size ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		foreach ( (array) $rows as $row ) {
			$this->check_one_link( (int) $row['id'], $row['url'] );
		}

		$remaining = $this->count_pending();
		$done      = 0 === $remaining;

		if ( $done ) {
			$this->record_health_delta( get_option( 'alm_link_health_started_at', '' ) );
			update_option( 'alm_last_link_health_time', current_time( 'mysql' ) );
		}

		return array(
			'done'      => $done,
			'checked'   => count( $rows ),
			'remaining' => $remaining,
		);
	}

	/**
	 * @param int    $id
	 * @param string $url
	 * @return void
	 */
	private function check_one_link( $id, $url ) {
		$result = $this->checker->check( $url );

		global $wpdb;
		$table = ALM_Install::table_name();

		$data = array( 'health_checked_at' => current_time( 'mysql' ) );

		// Confirmed dead -- moved out of the Candidate list the same way
		// ALM_Shortener_Scanner already moves a confirmed-dead shortlink
		// to nonaffiliate+dead, so it stops reading as a live
		// opportunity. Alive or merely inconclusive (a 403, a timeout,
		// ...) both leave category/modifier untouched -- "still don't
		// know" beats a wrong guess either way.
		if ( $result['dead'] ) {
			$data['category']      = ALM_Install::CATEGORY_NONAFFILIATE;
			$data['modifier']      = ALM_Install::MODIFIER_DEAD;
			$data['classified_at'] = current_time( 'mysql' );
		}

		$wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	/**
	 * Records what this run actually found -- read by the Dashboard's
	 * Tasks table, same role ALM_Domain_Scanner::record_check_delta()
	 * plays for Check Domains. Scoped to rows health_checked_at >=
	 * $started_at (this run only) -- safe to group blindly by current
	 * status since nothing else in this plugin ever writes
	 * health_checked_at, so every matching row was necessarily
	 * processed by this run.
	 *
	 * @param string $started_at MySQL datetime this run began, or '' if unknown.
	 * @return void
	 */
	private function record_health_delta( $started_at ) {
		$confirmed_dead = 0;
		$still_fine     = 0;

		if ( $started_at ) {
			global $wpdb;
			$table = ALM_Install::table_name();

			// Grouped by modifier alone (not category too) -- every row
			// this exact call touches was written by check_one_link()
			// moments ago, which only ever leaves modifier NULL (still
			// fine/inconclusive) or sets it to 'dead', nothing else.
			$sql  = "SELECT modifier, COUNT(*) as total FROM {$table} WHERE health_checked_at >= %s GROUP BY modifier"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $started_at ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built entirely from prepare() above; small admin-only aggregate query.

			foreach ( (array) $rows as $row ) {
				if ( ALM_Install::MODIFIER_DEAD === $row['modifier'] ) {
					$confirmed_dead = (int) $row['total'];
				} else {
					$still_fine += (int) $row['total'];
				}
			}
		}

		update_option(
			'alm_last_link_health_delta',
			array(
				'confirmed_dead' => $confirmed_dead,
				'still_fine'     => $still_fine,
			)
		);
	}

	/**
	 * gmdate() here mirrors ALM_Domain_Scanner::recheck_cutoff()
	 * exactly -- see its docblock for why this isn't a UTC/local mismatch.
	 *
	 * @return string MySQL datetime.
	 */
	private function recheck_cutoff() {
		return gmdate( 'Y-m-d H:i:s', current_time( 'U' ) - ( self::RECHECK_AFTER_DAYS * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- intentional, matches ALM_Domain_Scanner's own identical pattern.
	}
}
