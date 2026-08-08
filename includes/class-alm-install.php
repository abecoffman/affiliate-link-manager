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
	const DB_VERSION        = '1.0.0';

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
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() is picky about exact formatting (two spaces before
		// "KEY", each column on its own line) -- see
		// https://developer.wordpress.org/reference/functions/dbdelta/.
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
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY provider (provider),
			UNIQUE KEY natural_key (post_id, adapter, location(100))
		) {$charset_collate};";

		dbDelta( $sql );
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

		delete_option( self::DB_VERSION_OPTION );
		delete_option( 'alm_last_scan_time' );
		delete_option( 'alm_auto_convert_unclassified' );
		delete_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID );
		delete_option( ALM_Provider_ShopMy::OPTION_COLLECTION_ID );
	}
}
