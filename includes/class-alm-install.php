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
	const DB_VERSION        = '1.4.0';

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

		$links_table          = self::table_name();
		$show_columns_sql     = "SHOW COLUMNS FROM {$links_table} LIKE %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; the real value (a fixed column name) is bound via prepare() below.
		$resolved_url_exists  = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'resolved_url' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		$thumbnail_url_exists = (bool) $wpdb->get_var( $wpdb->prepare( $show_columns_sql, 'thumbnail_url' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION || ! $domains_exists || ! $resolved_url_exists || ! $thumbnail_url_exists ) {
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
	}

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'alm_links';
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
		// resolved_url/resolved_at (see ALM_Shortener_Resolver/_Scanner)
		// and thumbnail_url/thumbnail_fetched_at (see
		// ALM_Thumbnail_Fetcher) both live on the link row itself, not a
		// shared cache table like wp_alm_domains below -- unlike the
		// domain-content-check cache (one row per *domain*, shared by
		// every link on it), a shortener's real destination and a
		// product's photo are both unique per link, not per domain.
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
		delete_option( 'alm_auto_convert_unclassified' );
		delete_option( 'alm_candidate_excluded_domains' );
		delete_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID );
		delete_option( ALM_Provider_ShopMy::OPTION_COLLECTION_ID );

		wp_clear_scheduled_hook( 'alm_domain_recheck_cron' );
	}
}
