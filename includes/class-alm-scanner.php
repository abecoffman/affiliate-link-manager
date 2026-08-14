<?php
/**
 * Scans posts for links, classifies them via the provider registry, and
 * upserts results into the alm_links table. Read-only with respect to
 * post content -- this initial build never rewrites anything, only
 * records what it finds.
 *
 * Runs as a resumable batch (mirrors the same cursor/AJAX pattern the
 * sibling webp-generator plugin uses for its own bulk operation) so a
 * site with thousands of posts never risks hitting PHP's
 * max_execution_time in a single request.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Scanner {

	/**
	 * @var ALM_Adapter_Registry
	 */
	private $adapters;

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * @var ALM_Candidate_Classifier
	 */
	private $classifier;

	/**
	 * $classifier defaults to a plain instance rather than being
	 * required -- keeps existing call sites (and this class's own
	 * pre-existing integration tests) working unchanged. `new` isn't
	 * usable directly as a parameter default pre-PHP 8.1, hence the
	 * null-coalesce in the body instead of in the signature.
	 */
	public function __construct( ALM_Adapter_Registry $adapters, ALM_Provider_Registry $providers, ?ALM_Candidate_Classifier $classifier = null ) {
		$this->adapters   = $adapters;
		$this->providers  = $providers;
		$this->classifier = $classifier ? $classifier : new ALM_Candidate_Classifier();
	}

	/**
	 * Post types this plugin scans. Filterable so a site can add pages,
	 * a custom post type, etc.
	 *
	 * @return string[]
	 */
	public function get_scannable_post_types() {
		/**
		 * Filters which post types ALM_Scanner scans for links.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $post_types
		 */
		return apply_filters( 'alm_scannable_post_types', array( 'post' ) );
	}

	/**
	 * Total number of posts a full scan would cover -- used by the admin
	 * UI to size its progress display before starting.
	 *
	 * @return int
	 */
	public function count_scannable_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_scannable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Scan one batch of posts starting at $offset, upsert findings into
	 * the alm_links table, and return progress info for the client to
	 * resume from.
	 *
	 * @param int $offset
	 * @param int $batch_size
	 * @return array {
	 *     @type bool $done        True once every post has been scanned.
	 *     @type int  $next_offset Offset to resume from on the next call.
	 *     @type int  $links_found Links found in this batch.
	 * }
	 */
	public function scan_batch( $offset, $batch_size ) {
		// offset 0 is, by definition, the start of a fresh full scan --
		// record when it began so the sweep step (once this run finishes)
		// knows which rows weren't touched this time around. Stored as an
		// option rather than threaded through the AJAX request payload so
		// the client doesn't need to know or care about this bookkeeping.
		if ( 0 === $offset ) {
			update_option( 'alm_scan_started_at', current_time( 'mysql' ) );
		}

		$query = new WP_Query(
			array(
				'post_type'      => $this->get_scannable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$post_ids    = $query->posts;
		$links_found = 0;

		foreach ( $post_ids as $post_id ) {
			$links_found += $this->scan_post( (int) $post_id );
		}

		$done = count( $post_ids ) < $batch_size;

		if ( $done ) {
			$scan_started_at = get_option( 'alm_scan_started_at', '' );
			$now_stale       = $this->sweep_stale_links( $scan_started_at );
			$this->record_scan_delta( $scan_started_at, $now_stale );
		}

		return array(
			'done'        => $done,
			'next_offset' => $offset + count( $post_ids ),
			'links_found' => $links_found,
		);
	}

	/**
	 * Scan one batch of *only the posts modified since $modified_since*
	 * -- the automatic, unattended counterpart to scan_batch() (which
	 * always covers every scannable post). Lets new/edited content get
	 * classified without anyone remembering to click "Run Scan" -- see
	 * alm_watchdog_reprime_stuck_tasks() in the main plugin file for
	 * what actually triggers a run.
	 *
	 * Deliberately never calls sweep_stale_links(): that step trusts
	 * the run just covered *every* scannable post, so anything not
	 * re-seen must be genuinely gone. An incremental run only ever
	 * covers a subset -- running the same sweep after it would
	 * incorrectly mark links in completely untouched, unrelated posts
	 * as stale.
	 *
	 * Same ID-ordered pagination as scan_batch(), for the same reason:
	 * a stable sort key means offset-based pagination stays correct
	 * even if more posts get modified while a multi-batch run is still
	 * in progress. The one thing that *does* need to account for that
	 * possibility is the completion checkpoint -- see the $done branch
	 * below.
	 *
	 * @param int    $offset
	 * @param int    $batch_size
	 * @param string $modified_since MySQL datetime (GMT) -- only posts
	 *                                modified after this are considered.
	 * @return array {
	 *     @type bool $done        True once every currently-matching post has been scanned.
	 *     @type int  $next_offset Offset to resume from on the next tick.
	 *     @type int  $links_found Links found in this batch.
	 * }
	 */
	public function scan_incremental_batch( $offset, $batch_size, $modified_since ) {
		// Checkpointed to when *this run* started, not "now" once it
		// finishes -- a post modified while the run was still in
		// progress must still be caught by the *next* run, the same
		// "trust the start time, not the finish time" reasoning
		// scan_batch()'s own alm_scan_started_at already relies on.
		if ( 0 === $offset ) {
			update_option( 'alm_incremental_scan_started_at', current_time( 'mysql' ) );
		}

		$query = new WP_Query(
			array(
				'post_type'      => $this->get_scannable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => $modified_since,
					),
				),
			)
		);

		$post_ids    = $query->posts;
		$links_found = 0;

		foreach ( $post_ids as $post_id ) {
			$links_found += $this->scan_post( (int) $post_id );
		}

		$done = count( $post_ids ) < $batch_size;

		if ( $done ) {
			$started_at = get_option( 'alm_incremental_scan_started_at', '' );
			if ( $started_at ) {
				update_option( 'alm_last_incremental_scan_time', $started_at );
				$this->record_incremental_scan_delta( $started_at );
			}
		}

		return array(
			'done'        => $done,
			'next_offset' => $offset + count( $post_ids ),
			'links_found' => $links_found,
		);
	}

	/**
	 * How many posts currently qualify for the *next* incremental run
	 * -- used by alm_watchdog_reprime_stuck_tasks() to decide whether
	 * there's actually anything to do before spinning up a background
	 * run for zero work.
	 *
	 * @param string $modified_since MySQL datetime (GMT).
	 * @return int
	 */
	public function count_posts_modified_since( $modified_since ) {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_scannable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => $modified_since,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Records what an incremental run found -- kept for future use/
	 * wp-cli inspection, same lightweight shape as record_scan_delta(),
	 * but (deliberately, unlike that one) never rendered anywhere in
	 * the admin UI -- this whole mechanism is meant to be fully
	 * invisible background housekeeping, not one more thing to look at.
	 *
	 * @param string $started_at MySQL datetime this run began.
	 * @return void
	 */
	private function record_incremental_scan_delta( $started_at ) {
		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
		$new_links = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE first_seen >= %s", $started_at ) );

		update_option(
			'alm_last_incremental_scan_delta',
			array( 'new_links' => $new_links )
		);
	}

	/**
	 * A link not re-discovered during the scan that just finished
	 * (its last_seen still predates when this run started) either had
	 * its link removed from the post, or the post itself is gone
	 * (WP_Query with post_status=publish naturally excludes trashed/
	 * deleted posts, so those links go stale here too, for free) --
	 * either way, the content changed since the last time this row was
	 * confirmed. Ignored links are deliberately left alone: an admin
	 * already decided that one was fine, so it shouldn't be able to
	 * silently override that decision.
	 *
	 * @param string $scan_started_at MySQL datetime this scan run began.
	 * @return int Number of rows that just transitioned to stale.
	 */
	private function sweep_stale_links( $scan_started_at ) {
		if ( ! $scan_started_at ) {
			return 0;
		}

		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; a bulk status-transition update, not a per-row query needing caching.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE last_seen < %s AND status NOT IN (%s, %s)", ALM_Install::STATUS_STALE, $scan_started_at, ALM_Install::STATUS_STALE, ALM_Install::STATUS_IGNORED ) );

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Records what changed in the scan that just finished -- read by the
	 * Dashboard to show "X new links, Y now stale" instead of only a
	 * bare timestamp.
	 *
	 * @param string $scan_started_at MySQL datetime this scan run began.
	 * @param int    $now_stale       Rows sweep_stale_links() just flipped to stale.
	 * @return void
	 */
	private function record_scan_delta( $scan_started_at, $now_stale ) {
		$new_links = 0;

		if ( $scan_started_at ) {
			global $wpdb;
			$table = ALM_Install::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
			$new_links = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE first_seen >= %s", $scan_started_at ) );
		}

		update_option(
			'alm_last_scan_delta',
			array(
				'new_links' => $new_links,
				'now_stale' => $now_stale,
			)
		);
	}

	/**
	 * Scan a single post and upsert its links.
	 *
	 * @param int $post_id
	 * @return int Number of links found (not necessarily new -- includes
	 *             ones already known from a previous scan).
	 */
	private function scan_post( $post_id ) {
		$adapter = $this->adapters->get_adapter_for_post( $post_id );
		$links   = $adapter->get_links( $post_id );

		foreach ( $links as $link ) {
			$provider = $this->providers->match_url( $link['url'] );
			$this->upsert_link( $post_id, $adapter->get_id(), $provider->get_id(), $link );
		}

		return count( $links );
	}

	/**
	 * @param int    $post_id
	 * @param string $adapter_id
	 * @param string $provider_id
	 * @param array  $link {location, url, anchor_text}
	 * @return void
	 */
	private function upsert_link( $post_id, $adapter_id, $provider_id, array $link ) {
		global $wpdb;

		$table = ALM_Install::table_name();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a table name (not user input, can't be a placeholder); the real user-supplied values are all passed through prepare() below.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, dead_confirmed_at FROM {$table} WHERE post_id = %d AND adapter = %s AND location = %s", $post_id, $adapter_id, $link['location'] ), ARRAY_A );

		$data = array(
			'post_id'     => $post_id,
			'provider'    => $provider_id,
			'adapter'     => $adapter_id,
			'location'    => $link['location'],
			'url'         => $link['url'],
			'anchor_text' => $link['anchor_text'],
			'last_seen'   => $now,
		);

		$classified_status = ALM_Install::STATUS_ACTIVE;
		if ( 'unclassified' === $provider_id ) {
			// Not matched by any real provider -- but that's not the same
			// as "noise". A raw retailer link nobody has wrapped yet is
			// exactly what this plugin exists to surface; a link back to
			// this site's own nav or an Instagram icon is not. See
			// ALM_Candidate_Classifier's own docblock for the reasoning.
			$classified_status = $this->classifier->is_candidate( $link['url'] )
				? ALM_Install::STATUS_CONVERTIBLE
				: ALM_Install::STATUS_UNCLASSIFIED;
		}

		// Ignored is a deliberate, sticky admin decision -- re-discovering
		// the same link on a later scan (including one that had gone
		// stale and come back) shouldn't silently un-ignore it. Every
		// other status is safe to recompute fresh on every sighting.
		if ( ! $existing || ALM_Install::STATUS_IGNORED !== $existing['status'] ) {
			$data['status'] = $classified_status;
		}

		// A previously-stale row being rediscovered here is, by
		// definition, moving to a non-stale status (this method never
		// assigns STATUS_STALE itself -- only sweep_stale_links() does)
		// -- any dead_confirmed_at verdict from before no longer applies
		// and must not silently carry forward onto whatever this link's
		// status becomes now. See ALM_Install::create_table()'s docblock.
		if ( $existing && ALM_Install::STATUS_STALE === $existing['status'] && ! empty( $existing['dead_confirmed_at'] ) ) {
			$data['dead_confirmed_at'] = null;
		}

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing['id'] ) );
		} else {
			$data['status']     = $classified_status;
			$data['first_seen'] = $now;
			$wpdb->insert( $table, $data );
		}
	}
}
