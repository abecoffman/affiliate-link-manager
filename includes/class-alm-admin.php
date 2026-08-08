<?php
/**
 * Admin UI controller: registers the top-level "Affiliate Links" menu
 * and its four screens, and handles the AJAX scan-batch endpoint plus
 * the plain POST-and-redirect settings/provider forms.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Admin {

	const AJAX_SCAN_ACTION = 'alm_scan_batch';
	const NONCE_ACTION     = 'alm_admin';
	const SETTINGS_NONCE   = 'alm_settings';
	const PROVIDERS_NONCE  = 'alm_providers';
	const CAPABILITY       = 'manage_options';

	const MENU_SLUG = 'affiliate-links';

	/**
	 * @var ALM_Scanner
	 */
	private $scanner;

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * @var ALM_Adapter_Registry
	 */
	private $adapters;

	public function __construct( ALM_Scanner $scanner, ALM_Provider_Registry $providers, ALM_Adapter_Registry $adapters ) {
		$this->scanner   = $scanner;
		$this->providers = $providers;
		$this->adapters  = $adapters;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_SCAN_ACTION, array( $this, 'handle_scan_batch' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_forms' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Affiliate Links', 'affiliate-link-manager' ),
			__( 'Affiliate Links', 'affiliate-link-manager' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-tag',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'affiliate-link-manager' ),
			__( 'Dashboard', 'affiliate-link-manager' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Links', 'affiliate-link-manager' ),
			__( 'Links', 'affiliate-link-manager' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-links',
			array( $this, 'render_links' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Providers', 'affiliate-link-manager' ),
			__( 'Providers', 'affiliate-link-manager' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-providers',
			array( $this, 'render_providers' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'affiliate-link-manager' ),
			__( 'Settings', 'affiliate-link-manager' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * @param string $hook
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'alm-admin', ALM_URL . 'assets/admin.css', array(), ALM_VERSION );
		wp_enqueue_script( 'alm-admin', ALM_URL . 'assets/admin.js', array(), ALM_VERSION, true );

		wp_localize_script(
			'alm-admin',
			'almAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_SCAN_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'total'   => $this->scanner->count_scannable_posts(),
				'strings' => array(
					'scanning'  => __( 'Scanning…', 'affiliate-link-manager' ),
					'scanDone'  => __( 'Scan complete — reloading…', 'affiliate-link-manager' ),
					'scanStart' => __( 'Run Scan', 'affiliate-link-manager' ),
					'error'     => __( 'Something went wrong. Please try again.', 'affiliate-link-manager' ),
				),
			)
		);
	}

	public function handle_scan_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		$offset     = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size = 20;

		$result = $this->scanner->scan_batch( $offset, $batch_size );

		if ( $result['done'] ) {
			update_option( 'alm_last_scan_time', current_time( 'mysql' ) );
		}

		wp_send_json_success( $result );
	}

	public function handle_settings_forms() {
		if ( isset( $_POST['alm_settings_nonce'] ) && check_admin_referer( self::SETTINGS_NONCE, 'alm_settings_nonce' ) ) {
			$this->save_settings();
		}

		if ( isset( $_POST['alm_providers_nonce'] ) && check_admin_referer( self::PROVIDERS_NONCE, 'alm_providers_nonce' ) ) {
			$this->save_providers();
		}
	}

	/**
	 * @return void
	 */
	private function save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Automation-policy toggles are stored now so the UI persists,
		// but nothing reads them yet -- the actual automatic-linking
		// engine is a later phase of this plugin, not this round.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was already verified in handle_settings_forms() before this method is called.
		update_option( 'alm_auto_convert_unclassified', isset( $_POST['alm_auto_convert_unclassified'] ) ? '1' : '' );

		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * @return void
	 */
	private function save_providers() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// The nonce was already verified in handle_settings_forms() before this method is called.
		if ( isset( $_POST['alm_shopmy_affiliate_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID, sanitize_text_field( wp_unslash( $_POST['alm_shopmy_affiliate_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['alm_shopmy_collection_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( ALM_Provider_ShopMy::OPTION_COLLECTION_ID, sanitize_text_field( wp_unslash( $_POST['alm_shopmy_collection_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
		exit;
	}

	public function render_dashboard() {
		$stats = $this->get_provider_stats();
		require ALM_PATH . 'includes/views/dashboard.php';
	}

	public function render_links() {
		$links     = $this->get_links_for_display();
		$providers = $this->providers->get_providers();
		require ALM_PATH . 'includes/views/links.php';
	}

	public function render_providers() {
		$providers = $this->providers->get_providers();
		require ALM_PATH . 'includes/views/providers.php';
	}

	public function render_settings() {
		require ALM_PATH . 'includes/views/settings.php';
	}

	/**
	 * @return array<string,array{label:string,count:int}>
	 */
	private function get_provider_stats() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only aggregate query.
		$rows = $wpdb->get_results( "SELECT provider, COUNT(*) as total FROM {$table} GROUP BY provider", ARRAY_A );

		$stats = array();
		foreach ( (array) $rows as $row ) {
			$provider = $this->providers->get_provider( $row['provider'] );
			$label    = $provider ? $provider->get_label() : $row['provider'];

			$stats[ $row['provider'] ] = array(
				'label' => $label,
				'count' => (int) $row['total'],
			);
		}

		return $stats;
	}

	/**
	 * @return array[]
	 */
	private function get_links_for_display() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only listing query.
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT 500", ARRAY_A );
	}
}
