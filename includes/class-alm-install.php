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
	const DB_VERSION        = '1.6.0';

	/**
	 * Real, documented status values -- the column itself is a plain
	 * VARCHAR (dbDelta can't express a real ENUM diff-safely), these
	 * constants are the single source of truth for what's valid.
	 *
	 * `stale` and `ignored` are deliberately distinct: stale means the
	 * scanner didn't see this link the last time it ran (the content
	 * changed); ignored means an admin explicitly dismissed it. Without
	 * that distinction, a link an admin already reviewed and accepted
	 * would keep re-flagging on every scan forever.
	 */
	const STATUS_ACTIVE       = 'active';
	const STATUS_CONVERTIBLE  = 'convertible';
	const STATUS_UNCLASSIFIED = 'unclassified';
	const STATUS_STALE        = 'stale';
	const STATUS_IGNORED      = 'ignored';

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
		$resolved_url_exists   = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'resolved_url' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		$thumbnail_url_exists  = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'thumbnail_url' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		$health_checked_exists = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'health_checked_at' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		$dead_confirmed_exists = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'dead_confirmed_at' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION || ! $domains_exists || ! $resolved_url_exists || ! $thumbnail_url_exists || ! $health_checked_exists || ! $dead_confirmed_exists ) {
			self::create_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
	 * apart the way two separate near-identical queries risked. Not a
	 * real ALM_Install::STATUS_* value on its own -- "confirmed dead"
	 * is the status=stale AND dead_confirmed_at IS NOT NULL slice; see
	 * create_table()'s own docblock for why status=stale alone can't
	 * carry this distinction.
	 *
	 * @return int
	 */
	public static function count_confirmed_dead() {
		global $wpdb;
		$table = self::table_name();

		$sql = "SELECT COUNT(*) FROM {$table} WHERE status = %s AND dead_confirmed_at IS NOT NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built entirely from prepare() above; small admin-only aggregate query.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, self::STATUS_STALE ) );
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
		// health_checked_at (see ALM_Link_Health_Checker/_Scanner), and
		// dead_confirmed_at all live on the link row itself, not a
		// shared cache table like wp_alm_domains below -- unlike the
		// domain-content-check cache (one row per *domain*, shared by
		// every link on it), a shortener's real destination, a product's
		// photo, and a specific link's own reachability are all unique
		// per link, not per domain (the same domain can have one dead
		// product page and ten live ones).
		//
		// dead_confirmed_at specifically: distinct from status=stale
		// itself, which is overloaded to mean two different things --
		// "the scanner didn't rediscover this in post content" (the
		// original meaning) and "the health checker confirmed the
		// destination is actually dead" (this column). Set only by
		// ALM_Link_Health_Scanner on a confirmed-dead result, cleared by
		// ALM_Scanner/ALM_Shortener_Scanner the moment a previously-stale
		// row is reclassified to anything else -- see their own
		// docblocks. Lets ALM_Links_List_Table's "Dead" tab show only
		// links actually worth removing, not every stale row.
		//
		// last_verified below is vestigial: predates health_checked_at/
		// dead_confirmed_at, which are what the health-check flow
		// actually reads/writes today (see ALM_Link_Health_Scanner).
		// Never read or written anywhere in this codebase. Left in
		// place rather than dropped via a migration for a column
		// nobody's using -- candidate for removal in a future schema-
		// touching round. (Deliberately not a SQL comment inline below
		// -- dbDelta() parses this string with its own regex and isn't
		// guaranteed to tolerate one.)
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(32) NOT NULL,
			adapter VARCHAR(32) NOT NULL,
			location VARCHAR(191) NOT NULL DEFAULT '',
			url TEXT NOT NULL,
			anchor_text TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			last_verified DATETIME NULL DEFAULT NULL,
			dismissed_at DATETIME NULL DEFAULT NULL,
			resolved_url TEXT NULL,
			resolved_at DATETIME NULL DEFAULT NULL,
			thumbnail_url TEXT NULL,
			thumbnail_fetched_at DATETIME NULL DEFAULT NULL,
			health_checked_at DATETIME NULL DEFAULT NULL,
			dead_confirmed_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY provider (provider),
			KEY status (status),
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
