<?php
/**
 * Resumable batch runner over wp_alm_domains: syncs newly-discovered
 * candidate domains into the cache, content-checks a batch of domains
 * that need it, and reclassifies every alm_links row on that domain
 * based on the real verdict. Mirrors ALM_Scanner's own offset/AJAX
 * batching pattern (see its docblock) for the same reason: real sites
 * have too many domains to check in one request without risking
 * max_execution_time, and checking real pages is much slower per-item
 * than the link scanner's own DB-only work, so batches here are
 * deliberately small.
 *
 * Only ever checks domains currently backing at least one `convertible`
 * (candidate) link -- the `unclassified` bucket is already trusted (it
 * got there via the static denylist or a structural rule, not a guess
 * this needs to double-check), and re-verifying it would burn outbound
 * requests for no benefit. The entire point of this class is adding
 * precision to the candidate bucket specifically: catching the
 * editorial/reference sites the static denylist hasn't been taught
 * about yet, without anyone having to maintain that list by hand.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Domain_Scanner {

	/**
	 * A checked domain is trusted for this long before it's eligible
	 * for a re-check -- a site's fundamental nature (magazine vs. shop)
	 * essentially never changes, so this just guards against a one-off
	 * bad fetch (a transient 500, a temporary redirect) staying wrong
	 * forever, not against real drift.
	 */
	const RECHECK_AFTER_DAYS = 90;

	/**
	 * @var ALM_Domain_Checker
	 */
	private $checker;

	/**
	 * @var ALM_Candidate_Classifier
	 */
	private $classifier;

	public function __construct( ALM_Domain_Checker $checker, ALM_Candidate_Classifier $classifier ) {
		$this->checker    = $checker;
		$this->classifier = $classifier;
	}

	/**
	 * Registers every domain currently backing a `convertible` link
	 * that isn't already known to wp_alm_domains. Cheap (one grouped
	 * query + INSERT IGNORE), safe to call at the start of every batch.
	 *
	 * @return void
	 */
	public function sync_known_domains() {
		global $wpdb;
		$links_table   = ALM_Install::table_name();
		$domains_table = ALM_Install::domains_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names, not user input; real value bound via prepare() below.
		$sql  = "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/', 3), '://', -1) as host, MIN(url) as sample_url FROM {$links_table} WHERE category = %s GROUP BY host";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ALM_Install::CATEGORY_CANDIDATE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		$now = current_time( 'mysql' );

		foreach ( (array) $rows as $row ) {
			$host = strtolower( trim( (string) $row['host'] ) );
			if ( '' === $host ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; an idempotent upsert-if-missing, not a per-row query needing caching.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$domains_table} (domain, sample_url, created_at) VALUES (%s, %s, %s)", $host, $row['sample_url'], $now ) );
		}
	}

	/**
	 * Total domains currently eligible for a check -- used by the admin
	 * UI to size its progress display, same role
	 * ALM_Scanner::count_scannable_posts() plays for the link scan.
	 *
	 * Syncs first: called from the Dashboard on every render, not just
	 * from inside check_batch() -- without this, a brand-new candidate
	 * domain nobody has clicked "Check Domains" for yet would never
	 * appear in wp_alm_domains at all, and this would wrongly report 0
	 * pending (a real bug, caught by actually looking at the rendered
	 * Dashboard against real data, not by reading the code).
	 *
	 * @return int
	 */
	public function count_domains_needing_check() {
		$this->sync_known_domains();

		global $wpdb;
		$domains_table = ALM_Install::domains_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; real value bound via prepare() below.
		$sql = "SELECT COUNT(*) FROM {$domains_table} WHERE checked_at IS NULL OR checked_at < %s";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $this->recheck_cutoff() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
	}

	/**
	 * Checks up to $batch_size domains that need it (never-checked
	 * first, then longest-stale) and reclassifies every affected link.
	 *
	 * @param int  $batch_size
	 * @param bool $is_first_of_run True only for the click-triggered
	 *                               first batch of a run, not any batch
	 *                               resumed after it -- marks when this
	 *                               run started, mirroring
	 *                               ALM_Scanner::scan_batch()'s own
	 *                               offset===0 convention, so the
	 *                               eventual delta (once $done) only
	 *                               counts what *this* run actually did.
	 * @return array{done:bool,checked:int,remaining:int}
	 */
	public function check_batch( $batch_size, $is_first_of_run = false ) {
		$this->sync_known_domains();

		if ( $is_first_of_run ) {
			update_option( 'alm_domain_check_started_at', current_time( 'mysql' ) );
		}

		global $wpdb;
		$domains_table = ALM_Install::domains_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; real values bound via prepare() below.
		$sql  = "SELECT id, domain, sample_url FROM {$domains_table} WHERE checked_at IS NULL OR checked_at < %s ORDER BY checked_at IS NULL DESC, checked_at ASC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $this->recheck_cutoff(), $batch_size ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		foreach ( (array) $rows as $row ) {
			$this->check_one_domain( (int) $row['id'], $row['domain'], $row['sample_url'] );
		}

		$remaining = $this->count_domains_needing_check();
		$done      = 0 === $remaining;

		if ( $done ) {
			$this->record_check_delta( get_option( 'alm_domain_check_started_at', '' ) );
			update_option( 'alm_last_domain_check_time', current_time( 'mysql' ) );
		}

		return array(
			'done'      => $done,
			'checked'   => count( $rows ),
			'remaining' => $remaining,
		);
	}

	/**
	 * Records what this run actually found -- read by the Dashboard's
	 * Tasks table so "Check Domains" can say what happened last time,
	 * the same way ALM_Scanner::record_scan_delta() already does for
	 * Run Scan. Deliberately scoped to domains checked_at >= $started_at
	 * (this run only), not an all-time cumulative total.
	 *
	 * @param string $started_at MySQL datetime this run began, or '' if unknown.
	 * @return void
	 */
	private function record_check_delta( $started_at ) {
		$shops = 0;
		$not   = 0;

		if ( $started_at ) {
			global $wpdb;
			$table = ALM_Install::domains_table_name();

			$sql  = "SELECT is_shop, COUNT(*) as total FROM {$table} WHERE checked_at >= %s GROUP BY is_shop"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $started_at ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built entirely from prepare() above; small admin-only aggregate query.

			foreach ( (array) $rows as $row ) {
				if ( null === $row['is_shop'] ) {
					continue;
				}
				if ( (int) $row['is_shop'] ) {
					$shops = (int) $row['total'];
				} else {
					$not = (int) $row['total'];
				}
			}
		}

		update_option(
			'alm_last_domain_check_delta',
			array(
				'confirmed_shops' => $shops,
				'confirmed_not'   => $not,
			)
		);
	}

	/**
	 * @param int    $domain_row_id
	 * @param string $domain
	 * @param string $sample_url
	 * @return void
	 */
	private function check_one_domain( $domain_row_id, $domain, $sample_url ) {
		$result = $this->checker->check( $sample_url );

		global $wpdb;
		$domains_table = ALM_Install::domains_table_name();

		$wpdb->update(
			$domains_table,
			array(
				'is_shop'     => null === $result['is_shop'] ? null : (int) $result['is_shop'],
				'signals'     => implode( ',', $result['signals'] ),
				'http_status' => $result['http_status'],
				'checked_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $domain_row_id )
		);

		// A null verdict (fetch failed, timed out, non-2xx) means
		// "still don't know" -- leave every link on this domain exactly
		// as the static-denylist heuristic already had it. Only a real,
		// successful verdict is worth reclassifying anything over.
		if ( null === $result['is_shop'] ) {
			return;
		}

		$this->reclassify_links_for_domain( $domain, $result['is_shop'] );
	}

	/**
	 * @param string $domain
	 * @param bool   $is_shop
	 * @return void
	 */
	private function reclassify_links_for_domain( $domain, $is_shop ) {
		global $wpdb;
		$links_table = ALM_Install::table_name();

		// Ignored is a deliberate, sticky admin decision (see
		// ALM_Scanner::upsert_link()) -- a real content verdict doesn't
		// get to override that any more than a later scan does. Only
		// candidate or plain (modifier IS NULL) nonaffiliate rows are
		// eligible -- a dead/stale nonaffiliate row has already moved
		// on to a different question than "is this domain a shop".
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; real values bound via prepare() below.
		$sql  = "SELECT id, url, category FROM {$links_table} WHERE ( category = %s OR ( category = %s AND modifier IS NULL ) ) AND SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/', 3), '://', -1) = %s";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ALM_Install::CATEGORY_CANDIDATE, ALM_Install::CATEGORY_NONAFFILIATE, $domain ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		foreach ( (array) $rows as $row ) {
			// is_candidate() still applies the structural checks (a
			// direct image-file link on a confirmed shop domain stays
			// correctly non-candidate) -- only the domain-identity
			// question itself is answered by the real verdict here
			// instead of the static denylist guess.
			$new_category = $this->classifier->is_candidate( $row['url'], $is_shop )
				? ALM_Install::CATEGORY_CANDIDATE
				: ALM_Install::CATEGORY_NONAFFILIATE;

			$data = array( 'category' => $new_category );
			if ( $new_category !== $row['category'] ) {
				$data['classified_at'] = current_time( 'mysql' );
			}

			$wpdb->update( $links_table, $data, array( 'id' => $row['id'] ) );
		}
	}

	/**
	 * gmdate() here is intentional, not a mismatch with current_time():
	 * current_time('U') already returns a WP-local-adjusted Unix
	 * timestamp, so formatting it with gmdate() (no further timezone
	 * shift) yields the same wall-clock string current_time('mysql')
	 * itself would -- matching the basis every other timestamp column
	 * in this table (checked_at, created_at) is written with.
	 *
	 * @return string MySQL datetime.
	 */
	private function recheck_cutoff() {
		return gmdate( 'Y-m-d H:i:s', current_time( 'U' ) - ( self::RECHECK_AFTER_DAYS * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- intentional, see docblock above: this is the WP-local basis, deliberately matched to how checked_at/created_at are written elsewhere in this table, not a raw UTC/local mismatch.
	}
}
