<?php
/**
 * Creates/upgrades/drops the plugin's custom database table.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Install {

	const DB_VERSION_OPTION = 'alm_db_version';
	const DB_VERSION        = '1.7.0';

	/**
	 * Real, documented values -- both columns are plain VARCHARs (dbDelta
	 * can't express a real ENUM diff-safely), these constants are the
	 * single source of truth for what's valid.
	 *
	 * Two dimensions, not one flat enum: `category` is what a link
	 * fundamentally is; `modifier` is only ever set when
	 * `category = CATEGORY_NONAFFILIATE`, exactly one at a time --
	 * `ignored` (an admin explicitly dismissed it -- sticky, a later
	 * scan rediscovering the same link must never silently un-ignore
	 * it), `dead` (the health checker or shortener resolver confirmed
	 * the destination is actually broken), or `stale` (a full scan
	 * simply didn't find it in post content anymore -- including a
	 * link that used to be `active`/`candidate`, which demotes all the
	 * way to nonaffiliate+stale rather than keeping its old category
	 * while secretly not being found; see sweep_stale_links()). `NULL`
	 * modifier + `nonaffiliate` category is a plain, ordinary non-
	 * affiliate link -- nav, social, an editorial credit -- currently
	 * present, nothing to review.
	 */
	const CATEGORY_AFFILIATE    = 'affiliate';
	const CATEGORY_CANDIDATE    = 'candidate';
	const CATEGORY_NONAFFILIATE = 'nonaffiliate';

	const MODIFIER_IGNORED = 'ignored';
	const MODIFIER_DEAD    = 'dead';
	const MODIFIER_STALE   = 'stale';

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		self::create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Runs on every boot; only actually does anything the first time the
	 * table needs creating, or after a future schema-version bump.
	 */
	public static function maybe_upgrade() {
		// The version-option check alone isn't quite enough to trust: a
		// dbDelta() call that silently no-ops on part of its work
		// (dbDelta() failures aren't fatal errors) would still let
		// update_option() below mark the site as "upgraded" regardless.
		// Seen twice in practice now, two different shapes -- the
		// domains table not getting created at all, and (found live on
		// honestlywtf while adding resolved_url/resolved_at) an ALTER
		// TABLE silently not adding new columns to the links table even
		// though a manual re-run of the exact same dbDelta() call
		// immediately after succeeded. Checking both real schema facts
		// directly, not just the version option, makes this self-healing
		// on the next boot instead of silently stuck either way.
		global $wpdb;
		$domains_table  = self::domains_table_name();
		$domains_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $domains_table ) ) === $domains_table;

		$links_table           = self::table_name();
		$show_columns_sql      = "SHOW COLUMNS FROM {$links_table} LIKE %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; the real value (a fixed column name) is bound via prepare() below.
		$resolved_url_exists   = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'resolved_url' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.
		$thumbnail_url_exists  = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'thumbnail_url' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.
		$health_checked_exists = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'health_checked_at' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.
		$category_exists       = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'category' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.
		$status_exists         = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'status' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.

		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION || ! $domains_exists || ! $resolved_url_exists || ! $thumbnail_url_exists || ! $health_checked_exists || ! $category_exists ) {
			self::create_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		// The old `status`/`dismissed_at`/`dead_confirmed_at` columns
		// only still exist on a site that hasn't migrated yet -- a fresh
		// install's create_table() above never creates them at all, so
		// this is naturally a one-time, self-healing no-op everywhere
		// else, same reasoning as the rest of this method.
		if ( $status_exists ) {
			self::migrate_status_to_category();
		}

		// register_activation_hook() only ever fires on an actual
		// activate-toggle -- an already-active install picking up this
		// capability for the first time via a plain file update (no
		// deactivate/reactivate) would otherwise never get the cron
		// event scheduled at all. Checked on every boot instead, same
		// as the schema check above; wp_next_scheduled() makes this a
		// cheap no-op once it's actually set.
		if ( ! wp_next_scheduled( 'alm_domain_recheck_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'alm_domain_recheck_cron' );
		}

		// Hourly, not daily like the housekeeping cron above -- this one
		// does no real work of its own (see
		// alm_watchdog_reprime_stuck_tasks()'s own docblock), just checks
		// whether any of the four background tasks got stranded (found
		// live: a real, uncatchable PHP execution-time fatal mid-batch
		// can silently break alm_continue_batch_run()'s own self-
		// rescheduling chain -- see its docblock for the full story), so
		// running it often costs nothing and bounds the worst-case stuck
		// window to about an hour instead of up to a day.
		if ( ! wp_next_scheduled( 'alm_watchdog_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'alm_watchdog_cron' );
		}
	}

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'alm_links';
	}

	/**
	 * Single source of truth for "how many links are confirmed dead" --
	 * used by both ALM_Links_List_Table's Dead Links tab count and the
	 * Dashboard's own stat tile, so the two can never quietly drift
	 * apart the way two separate near-identical queries risked.
	 *
	 * @return int
	 */
	public static function count_confirmed_dead() {
		global $wpdb;
		$table = self::table_name();

		$sql = "SELECT COUNT(*) FROM {$table} WHERE category = %s AND modifier = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built entirely from prepare() above; small admin-only aggregate query.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, self::CATEGORY_NONAFFILIATE, self::MODIFIER_DEAD ) );
	}

	/**
	 * Per-domain cache of ALM_Domain_Checker's real-content verdict --
	 * one row per unique domain, not per link, so a domain with 200
	 * links only ever costs one outbound HTTP request. See
	 * ALM_Domain_Checker/ALM_Domain_Scanner.
	 *
	 * @return string
	 */
	public static function domains_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'alm_domains';
	}

	/**
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$domains_table   = self::domains_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() is picky about exact formatting (two spaces before
		// "KEY", each column on its own line) -- see
		// https://developer.wordpress.org/reference/functions/dbdelta/.
		//
		// resolved_url/resolved_at (see ALM_Shortener_Resolver/_Scanner),
		// thumbnail_url/thumbnail_fetched_at (see ALM_Thumbnail_Fetcher),
		// and health_checked_at (see ALM_Link_Health_Checker/_Scanner)
		// all live on the link row itself, not a shared cache table like
		// wp_alm_domains below -- unlike the domain-content-check cache
		// (one row per *domain*, shared by every link on it), a
		// shortener's real destination, a product's photo, and a
		// specific link's own reachability are all unique per link, not
		// per domain (the same domain can have one dead product page
		// and ten live ones).
		//
		// category/modifier/classified_at replace the old status column
		// (plus the dismissed_at/dead_confirmed_at side-columns that
		// used to disambiguate it) -- see ALM_Install's own class
		// docblock-level constants for the full model. classified_at is
		// nullable, not NOT NULL, for the same reason every other
		// app-populated timestamp column here is (resolved_at,
		// dismissed_at used to be, etc.): a column added via ALTER TABLE
		// to a table that may already have rows needs to be safe to add
		// without a backfill happening in the same statement. Every
		// write path sets a real value going forward; migrate_status_to_
		// category() backfills it once for pre-existing rows.
		//
		// last_verified was vestigial (predated health_checked_at/the old
		// dead_confirmed_at, never read or written anywhere in this
		// codebase) and is dropped in this same migration rather than
		// carried through yet another round. (Deliberately not a SQL
		// comment inline below -- dbDelta() parses this string with its
		// own regex and isn't guaranteed to tolerate one.)
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(32) NOT NULL,
			adapter VARCHAR(32) NOT NULL,
			location VARCHAR(191) NOT NULL DEFAULT '',
			url TEXT NOT NULL,
			anchor_text TEXT NOT NULL,
			category VARCHAR(20) NOT NULL DEFAULT 'nonaffiliate',
			modifier VARCHAR(20) NULL DEFAULT NULL,
			classified_at DATETIME NULL DEFAULT NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			resolved_url TEXT NULL,
			resolved_at DATETIME NULL DEFAULT NULL,
			thumbnail_url TEXT NULL,
			thumbnail_fetched_at DATETIME NULL DEFAULT NULL,
			health_checked_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY provider (provider),
			KEY category_modifier (category, modifier),
			UNIQUE KEY natural_key (post_id, adapter, location(100))
		) {$charset_collate};";

		dbDelta( $sql );

		// is_shop: NULL = not yet checked, or checked but the fetch
		// failed/was inconclusive (never reclassifies links either way);
		// 1/0 = a real, checked verdict. checked_at NULL means "known
		// about (a link on this domain was seen) but never actually
		// fetched yet" -- the domain-scanner's "needs check" query reads
		// straight off of that.
		$domains_sql = "CREATE TABLE {$domains_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			domain VARCHAR(191) NOT NULL,
			is_shop TINYINT(1) NULL DEFAULT NULL,
			signals TEXT NULL,
			http_status SMALLINT UNSIGNED NULL DEFAULT NULL,
			sample_url TEXT NULL,
			checked_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY domain (domain),
			KEY checked_at (checked_at)
		) {$charset_collate};";

		dbDelta( $domains_sql );
	}

	/**
	 * One-time backfill from the old status/dismissed_at/dead_confirmed_at
	 * shape to category/modifier/classified_at, then drops the old
	 * columns. Only ever called once per site -- maybe_upgrade() only
	 * calls this when the `status` column is still actually present,
	 * which create_table() above never (re)creates, so this is a
	 * natural no-op everywhere except a site upgrading from before this
	 * version.
	 *
	 * Six cases, matching the old status/dead_confirmed_at combinations
	 * exactly:
	 * - active                                  -> affiliate    / NULL   / first_seen (no better signal exists)
	 * - convertible                              -> candidate    / NULL   / first_seen
	 * - unclassified                             -> nonaffiliate / NULL   / first_seen
	 * - ignored                                  -> nonaffiliate / ignored/ dismissed_at
	 * - stale, dead_confirmed_at set              -> nonaffiliate / dead   / dead_confirmed_at
	 * - stale, dead_confirmed_at NULL             -> nonaffiliate / stale  / last_seen (closest real proxy for "when it went stale")
	 *
	 * @return void
	 */
	private static function migrate_status_to_category() {
		global $wpdb;
		$table = self::table_name();

		// The old status values ('active'/'convertible'/'unclassified'/
		// 'ignored'/'stale') are hardcoded literals below, not constant
		// references -- the STATUS_* constants they used to name no
		// longer exist on this class at all, only CATEGORY_*/MODIFIER_*
		// do. Safe to hardcode: these are fixed, one-time migration
		// literals describing a schema that will never change again,
		// not user input.

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET category = %s, modifier = NULL, classified_at = first_seen WHERE status = 'active'", self::CATEGORY_AFFILIATE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET category = %s, modifier = NULL, classified_at = first_seen WHERE status = 'convertible'", self::CATEGORY_CANDIDATE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET category = %s, modifier = NULL, classified_at = first_seen WHERE status = 'unclassified'", self::CATEGORY_NONAFFILIATE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET category = %s, modifier = %s, classified_at = dismissed_at WHERE status = 'ignored'", self::CATEGORY_NONAFFILIATE, self::MODIFIER_IGNORED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET category = %s, modifier = %s, classified_at = dead_confirmed_at WHERE status = 'stale' AND dead_confirmed_at IS NOT NULL", self::CATEGORY_NONAFFILIATE, self::MODIFIER_DEAD ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() below.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET category = %s, modifier = %s, classified_at = last_seen WHERE status = 'stale' AND dead_confirmed_at IS NULL", self::CATEGORY_NONAFFILIATE, self::MODIFIER_STALE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real values bound via prepare() above.

		// dbDelta() never drops columns -- these need a real ALTER. Built
		// from only the columns that actually still exist, not a fixed
		// list -- a single ALTER TABLE's multiple DROP COLUMN clauses
		// are one atomic operation in MySQL, so trying to drop a column
		// that's already gone (confirmed live: last_verified had
		// already been removed by an earlier partial migration attempt)
		// fails the *entire* statement, leaving every other column that
		// really was ready to drop stuck in place too -- and, since
		// $status_exists would then stay true forever, this whole
		// function would keep re-running (harmlessly, but pointlessly)
		// on every future boot instead of ever actually finishing.
		$show_columns_sql   = "SHOW COLUMNS FROM {$table} LIKE %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; the real value (a fixed column name) is bound via prepare() below.
		$columns_to_drop    = array();
		$retired_column_set = array( 'status', 'dismissed_at', 'dead_confirmed_at', 'last_verified' );
		foreach ( $retired_column_set as $column ) {
			if ( $wpdb->get_var( $wpdb->prepare( $show_columns_sql, $column ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
				$columns_to_drop[] = 'DROP COLUMN ' . $column;
			}
		}

		if ( $columns_to_drop ) {
			$alter_sql = 'ALTER TABLE ' . $table . ' ' . implode( ', ', $columns_to_drop ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed, hardcoded column list, not user input.
			$wpdb->query( $alter_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user-supplied values in this statement at all.
		}
	}

	/**
	 * Runs from uninstall.php on plugin deletion. Removes only this
	 * plugin's own data -- never touches anything in actual post
	 * content (any links a later version of this plugin writes there
	 * are the site's own data, not plugin state).
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$table_name = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; can't be a placeholder.
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		$domains_table = self::domains_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; can't be a placeholder.
		$wpdb->query( "DROP TABLE IF EXISTS {$domains_table}" );

		delete_option( self::DB_VERSION_OPTION );
		delete_option( 'alm_last_scan_time' );
		delete_option( 'alm_scan_started_at' );
		delete_option( 'alm_last_scan_delta' );
		delete_option( 'alm_candidate_excluded_domains' );
		delete_option( 'alm_domain_check_started_at' );
		delete_option( 'alm_last_domain_check_time' );
		delete_option( 'alm_last_domain_check_delta' );
		delete_option( 'alm_shortener_expand_started_at' );
		delete_option( 'alm_last_shortener_expand_time' );
		delete_option( 'alm_last_shortener_expand_delta' );
		delete_option( 'alm_link_health_started_at' );
		delete_option( 'alm_last_link_health_time' );
		delete_option( 'alm_last_link_health_delta' );
		delete_option( 'alm_incremental_scan_started_at' );
		delete_option( 'alm_last_incremental_scan_time' );
		delete_option( 'alm_last_incremental_scan_delta' );
		delete_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID );
		delete_option( ALM_Provider_ShopMy::OPTION_COLLECTION_ID );

		// ALM_Background_Runner's per-task run state -- see its own
		// docblock. Deliberately named/deleted individually rather than
		// looping TASK_BATCH_SIZES's keys here: uninstall.php runs in a
		// context where that class may not be loaded/available, and this
		// list is small and stable enough not to need it.
		delete_option( 'alm_scan_run_state' );
		delete_option( 'alm_domains_run_state' );
		delete_option( 'alm_shorteners_run_state' );
		delete_option( 'alm_link_health_run_state' );
		delete_option( 'alm_incremental_scan_run_state' );

		wp_clear_scheduled_hook( 'alm_domain_recheck_cron' );
		wp_clear_scheduled_hook( 'alm_watchdog_cron' );
		wp_clear_scheduled_hook( 'alm_continue_batch_run', array( 'scan' ) );
		wp_clear_scheduled_hook( 'alm_continue_batch_run', array( 'domains' ) );
		wp_clear_scheduled_hook( 'alm_continue_batch_run', array( 'shorteners' ) );
		wp_clear_scheduled_hook( 'alm_continue_batch_run', array( 'link_health' ) );
		wp_clear_scheduled_hook( 'alm_continue_batch_run', array( 'incremental_scan' ) );
	}
}
