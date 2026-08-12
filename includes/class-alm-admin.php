<?php
/**
 * Admin UI controller: registers the top-level "Affiliate Links" menu
 * and its screens (Dashboard, Links, Settings -- no separate Posts or
 * Providers screen, see register_menu()), and handles the AJAX
 * scan-batch endpoint plus the plain POST-and-redirect settings form.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Admin {

	const AJAX_SCAN_ACTION             = 'alm_scan_batch';
	const AJAX_DOMAIN_CHECK_ACTION     = 'alm_check_domains_batch';
	const AJAX_EDIT_LINK_ACTION        = 'alm_edit_link';
	const AJAX_MATCH_PROVIDER_ACTION   = 'alm_match_provider';
	const AJAX_EXPAND_SHORTENER_ACTION = 'alm_expand_shorteners_batch';
	const AJAX_FETCH_THUMBNAIL_ACTION  = 'alm_fetch_thumbnail';
	const AJAX_LINK_HEALTH_ACTION      = 'alm_check_link_health_batch';
	const AJAX_REMOVE_LINK_ACTION      = 'alm_remove_link';
	const NONCE_ACTION                 = 'alm_admin';
	const SETTINGS_NONCE               = 'alm_settings';
	const CAPABILITY                   = 'manage_options';

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

	/**
	 * @var ALM_Domain_Scanner
	 */
	private $domain_scanner;

	/**
	 * @var ALM_Link_Converter
	 */
	private $converter;

	/**
	 * @var ALM_Network_Signal_Scanner
	 */
	private $network_signal_scanner;

	/**
	 * @var ALM_Shortener_Scanner
	 */
	private $shortener_scanner;

	/**
	 * @var ALM_Thumbnail_Fetcher
	 */
	private $thumbnail_fetcher;

	/**
	 * @var ALM_Link_Health_Scanner
	 */
	private $link_health_scanner;

	public function __construct( ALM_Scanner $scanner, ALM_Provider_Registry $providers, ALM_Adapter_Registry $adapters, ALM_Domain_Scanner $domain_scanner, ALM_Link_Converter $converter, ALM_Network_Signal_Scanner $network_signal_scanner, ALM_Shortener_Scanner $shortener_scanner, ALM_Thumbnail_Fetcher $thumbnail_fetcher, ALM_Link_Health_Scanner $link_health_scanner ) {
		$this->scanner                = $scanner;
		$this->providers              = $providers;
		$this->adapters               = $adapters;
		$this->domain_scanner         = $domain_scanner;
		$this->converter              = $converter;
		$this->network_signal_scanner = $network_signal_scanner;
		$this->shortener_scanner      = $shortener_scanner;
		$this->thumbnail_fetcher      = $thumbnail_fetcher;
		$this->link_health_scanner    = $link_health_scanner;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_SCAN_ACTION, array( $this, 'handle_scan_batch' ) );
		add_action( 'wp_ajax_' . self::AJAX_DOMAIN_CHECK_ACTION, array( $this, 'handle_domain_check_batch' ) );
		add_action( 'wp_ajax_' . self::AJAX_EDIT_LINK_ACTION, array( $this, 'handle_edit_link' ) );
		add_action( 'wp_ajax_' . self::AJAX_MATCH_PROVIDER_ACTION, array( $this, 'handle_match_provider' ) );
		add_action( 'wp_ajax_' . self::AJAX_EXPAND_SHORTENER_ACTION, array( $this, 'handle_expand_shorteners_batch' ) );
		add_action( 'wp_ajax_' . self::AJAX_FETCH_THUMBNAIL_ACTION, array( $this, 'handle_fetch_thumbnail' ) );
		add_action( 'wp_ajax_' . self::AJAX_LINK_HEALTH_ACTION, array( $this, 'handle_check_link_health_batch' ) );
		add_action( 'wp_ajax_' . self::AJAX_REMOVE_LINK_ACTION, array( $this, 'handle_remove_link' ) );
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

		$links_hook = add_submenu_page(
			self::MENU_SLUG,
			__( 'Links', 'affiliate-link-manager' ),
			__( 'Links', 'affiliate-link-manager' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-links',
			array( $this, 'render_links' )
		);

		// Bulk/row actions (Ignore, Delete) redirect on success -- that
		// has to happen before wp-admin's own header HTML starts
		// outputting, which has already begun by the time render_links()
		// itself runs. load-{hook} is the standard WP pattern for this
		// (the same reason Posts/Plugins process their own bulk actions
		// this early, not inside their list table's display path).
		add_action( "load-{$links_hook}", array( $this, 'handle_links_bulk_action' ) );

		// No separate Posts screen -- the Links table's own Post/Link/
		// Affiliate columns now cover the same job (which posts have
		// which links) that screen existed for. Removed rather than kept
		// as a second, mostly-overlapping way to see the same thing.

		// No separate Providers screen either -- 5 of 6 registered
		// networks have nothing to configure beyond "recognized," so a
		// whole top-level menu item for that was mostly empty space.
		// Folded into Settings instead (see render_settings()).

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
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'action'                 => self::AJAX_SCAN_ACTION,
				'domainCheckAction'      => self::AJAX_DOMAIN_CHECK_ACTION,
				'editLinkAction'         => self::AJAX_EDIT_LINK_ACTION,
				'matchProviderAction'    => self::AJAX_MATCH_PROVIDER_ACTION,
				'expandShortenersAction' => self::AJAX_EXPAND_SHORTENER_ACTION,
				'fetchThumbnailAction'   => self::AJAX_FETCH_THUMBNAIL_ACTION,
				'linkHealthAction'       => self::AJAX_LINK_HEALTH_ACTION,
				'removeLinkAction'       => self::AJAX_REMOVE_LINK_ACTION,
				'nonce'                  => wp_create_nonce( self::NONCE_ACTION ),
				'total'                  => $this->scanner->count_scannable_posts(),
				'domainsTotal'           => $this->domain_scanner->count_domains_needing_check(),
				'shortenersTotal'        => $this->shortener_scanner->count_pending(),
				'linkHealthTotal'        => $this->link_health_scanner->count_pending(),
				// The bulk "Convert to [Provider]" action's own confirm()
				// text needs a provider label by id -- the Edit modal no
				// longer does (it infers the provider from the URL server-
				// side, see ALM_Admin::handle_match_provider()), so this is
				// bulk-only now.
				'providers'              => $this->get_provider_capabilities(),
				// Lets admin.js auto-resume a task's own polling loop on
				// a fresh page load if a run is already active (started
				// from an earlier click, possibly still being carried
				// forward by alm_continue_batch_run() right now) instead
				// of only ever starting on an explicit click -- see
				// ALM_Background_Runner.
				'runState'               => array(
					'scan'        => ALM_Background_Runner::get_state( 'scan' ),
					'domains'     => ALM_Background_Runner::get_state( 'domains' ),
					'shorteners'  => ALM_Background_Runner::get_state( 'shorteners' ),
					'link_health' => ALM_Background_Runner::get_state( 'link_health' ),
				),
				'strings'                => array(
					'scanning'             => __( 'Scanning…', 'affiliate-link-manager' ),
					'scanDone'             => __( 'Scan complete — reloading…', 'affiliate-link-manager' ),
					'scanStart'            => __( 'Run Scan', 'affiliate-link-manager' ),
					'error'                => __( 'Something went wrong. Please try again.', 'affiliate-link-manager' ),
					'checkingDomains'      => __( 'Checking domains…', 'affiliate-link-manager' ),
					'domainCheckDone'      => __( 'Domain check complete — reloading…', 'affiliate-link-manager' ),
					'checkDomains'         => __( 'Check Domains', 'affiliate-link-manager' ),
					'expandingShorteners'  => __( 'Expanding shortened links…', 'affiliate-link-manager' ),
					'expandShortenersDone' => __( 'Done — reloading…', 'affiliate-link-manager' ),
					'checkingLinkHealth'   => __( 'Checking link health…', 'affiliate-link-manager' ),
					'linkHealthDone'       => __( 'Done — reloading…', 'affiliate-link-manager' ),
					'editModalTitle'       => __( 'Edit link', 'affiliate-link-manager' ),
					'editPost'             => __( 'Edit Post', 'affiliate-link-manager' ),
					'view'                 => __( 'View', 'affiliate-link-manager' ),
					'save'                 => __( 'Save', 'affiliate-link-manager' ),
					'saving'               => __( 'Saving…', 'affiliate-link-manager' ),
					'cancel'               => __( 'Cancel', 'affiliate-link-manager' ),
					'matching'             => __( 'Checking…', 'affiliate-link-manager' ),
					'deleteConfirm'        => __( "Delete this link? This only removes it from Affiliate Link Manager's records -- it does not change the post.", 'affiliate-link-manager' ),
					'removeConfirm'        => __( 'Remove this link from the post? This edits the post content directly (the link text stays, just unlinked) and deletes it from Affiliate Link Manager\'s records -- it can\'t be undone from here.', 'affiliate-link-manager' ),
					'bulkRemoveWarn'       => __( 'Remove all selected dead links from their posts? This edits post content directly and can\'t be undone from here. Anything not confirmed dead is skipped.', 'affiliate-link-manager' ),
					/* translators: %s: provider label, e.g. "RewardStyle / LTK" */
					'forceConvertWarn'     => __( 'This link is currently tracked under %s. Saving will replace it -- are you sure?', 'affiliate-link-manager' ),
					/* translators: %s: provider label, e.g. "ShopMy" */
					'bulkConvertWarn'      => __( 'Convert all selected links to %s? This replaces the tracked link for any that already have one under a different network.', 'affiliate-link-manager' ),
				),
			)
		);
	}

	/**
	 * Feeds the bulk "Convert to [Provider]" action's confirm() text
	 * (see assets/admin.js) with a provider label by id -- deliberately
	 * excludes ALM_Provider_Generic ("Unclassified"/"Unaffiliated").
	 * It's the always-matches fallback the scanner uses when nothing
	 * else claimed a URL, not a real network anything would ever be
	 * bulk-"converted to" (same reason it's excluded from every other
	 * part of the UI, see the three-tier restructure elsewhere in this
	 * class).
	 *
	 * @return array<string,array{label:string,canWrap:bool}>
	 */
	private function get_provider_capabilities() {
		$capabilities = array();
		foreach ( $this->providers->get_providers() as $provider ) {
			if ( $provider instanceof ALM_Provider_Generic ) {
				continue;
			}
			$capabilities[ $provider->get_id() ] = array(
				'label'   => $provider->get_label(),
				'canWrap' => $provider->can_wrap(),
			);
		}
		return $capabilities;
	}

	/**
	 * Runs on the Links screen's load-{hook}, before any HTML output --
	 * see register_menu() for why this can't happen inside render_links().
	 *
	 * @return void
	 */
	public function handle_links_bulk_action() {
		$list_table = new ALM_Links_List_Table( $this->providers, $this->adapters, $this->converter );
		$list_table->process_bulk_action();
	}

	public function handle_scan_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		$offset     = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size = ALM_Background_Runner::TASK_BATCH_SIZES['scan'];

		$result = $this->scanner->scan_batch( $offset, $batch_size );

		if ( $result['done'] ) {
			update_option( 'alm_last_scan_time', current_time( 'mysql' ) );
		}

		// Scan has no explicit "first" flag -- offset===0 already means
		// the same thing, same as ALM_Scanner::scan_batch() itself uses
		// internally to decide whether to stamp alm_scan_started_at.
		$this->track_batch_run( 'scan', 0 === $offset, $result['done'], $result['next_offset'] - $offset, $result['next_offset'] );

		wp_send_json_success( $result );
	}

	public function handle_domain_check_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		// Real HTTP requests to third-party sites, much slower per-item
		// than the link scanner's own DB-only batches -- a small batch
		// size keeps this comfortably clear of max_execution_time.
		// $is_first_of_run marks the click-triggered first call of a run
		// (not any batch resumed after it) so ALM_Domain_Scanner can
		// scope its "what did this run find" delta correctly -- see
		// its check_batch() docblock.
		$is_first_of_run = ! empty( $_POST['first'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().
		$result          = $this->domain_scanner->check_batch( ALM_Background_Runner::TASK_BATCH_SIZES['domains'], $is_first_of_run );

		$this->track_batch_run( 'domains', $is_first_of_run, $result['done'], $result['checked'] );

		wp_send_json_success( $result );
	}

	public function handle_expand_shorteners_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		// Real HTTP requests, up to ALM_Shortener_Resolver::MAX_HOPS deep
		// per link -- same small-batch reasoning as Check Domains above.
		$is_first_of_run = ! empty( $_POST['first'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().
		$result          = $this->shortener_scanner->check_batch( ALM_Background_Runner::TASK_BATCH_SIZES['shorteners'], $is_first_of_run );

		$this->track_batch_run( 'shorteners', $is_first_of_run, $result['done'], $result['checked'] );

		wp_send_json_success( $result );
	}

	public function handle_check_link_health_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		// Real HTTP requests, with a retry on a suspected-dead result --
		// same small-batch reasoning as Check Domains above, doubly so
		// here since several candidates often share one retailer domain.
		$is_first_of_run = ! empty( $_POST['first'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().
		$result          = $this->link_health_scanner->check_batch( ALM_Background_Runner::TASK_BATCH_SIZES['link_health'], $is_first_of_run );

		$this->track_batch_run( 'link_health', $is_first_of_run, $result['done'], $result['checked'] );

		wp_send_json_success( $result );
	}

	/**
	 * Shared ALM_Background_Runner bookkeeping for all four batch
	 * handlers above -- every one of them needs the identical "did
	 * this call start a new run, persist how far it's gotten, prime or
	 * cancel the WP-Cron continuation tick" logic around an otherwise
	 * task-specific batch call. See ALM_Background_Runner's own
	 * docblock for why this state exists (surviving the browser tab
	 * that started a run closing) and alm_continue_batch_run() in the
	 * main plugin file for the other side of it.
	 *
	 * @param string $task_id
	 * @param bool   $is_first_of_run
	 * @param bool   $done
	 * @param int    $processed_this_batch
	 * @param int    $cursor Only meaningful for 'scan' (its next_offset)
	 *                        -- the other three tasks are DB-state-driven
	 *                        (their own check_batch() re-queries "what's
	 *                        still unchecked" every time) and pass 0.
	 * @return void
	 */
	private function track_batch_run( $task_id, $is_first_of_run, $done, $processed_this_batch, $cursor = 0 ) {
		if ( $done ) {
			ALM_Background_Runner::clear_state( $task_id );
			ALM_Background_Runner::unschedule( $task_id );
			return;
		}

		if ( $is_first_of_run ) {
			ALM_Background_Runner::save_state(
				$task_id,
				array(
					'active'           => true,
					'cursor'           => $cursor,
					'processed'        => $processed_this_batch,
					'started_at'       => current_time( 'mysql' ),
					'reschedule_count' => 0,
					'stalled'          => false,
				)
			);
			// Primed immediately, not after some later batch -- even a
			// tab closed right after this very first click must not
			// leave the run stranded with nothing to continue it.
			ALM_Background_Runner::schedule_next_tick( $task_id, ALM_Background_Runner::TASK_RESCHEDULE_DELAYS[ $task_id ] );
			return;
		}

		$state               = ALM_Background_Runner::get_state( $task_id );
		$state['cursor']     = $cursor;
		$state['processed'] += $processed_this_batch;
		ALM_Background_Runner::save_state( $task_id, $state );
	}

	/**
	 * On-demand product-thumbnail fetch for the Edit modal's thumbnail
	 * slot -- fired only the first time a given link's modal is opened
	 * (ALM_Links_List_Table::column_link()'s data-thumbnail-fetched
	 * attribute tells the JS whether this has already been attempted;
	 * every later open renders straight from the cached
	 * data-thumbnail-url with no request at all). Once attempted here,
	 * never retried automatically even on a null result -- same
	 * restraint ALM_Shortener_Scanner already applies to resolved_at.
	 *
	 * @return void
	 */
	public function handle_fetch_thumbnail() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'affiliate-link-manager' ) ), 400 );
		}

		global $wpdb;
		$table = ALM_Install::table_name();
		$sql   = "SELECT * FROM {$table} WHERE id = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
		$item  = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'affiliate-link-manager' ) ), 404 );
		}

		// Already attempted (even if it found nothing) -- the row's own
		// cached answer is the source of truth, not a fresh fetch.
		if ( ! empty( $item['thumbnail_fetched_at'] ) ) {
			wp_send_json_success( array( 'thumbnailUrl' => ! empty( $item['thumbnail_url'] ) ? $item['thumbnail_url'] : null ) );
		}

		$result = $this->thumbnail_fetcher->fetch( $item['url'] );

		$wpdb->update(
			$table,
			array(
				'thumbnail_url'        => $result['thumbnail_url'],
				'thumbnail_fetched_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array( 'thumbnailUrl' => $result['thumbnail_url'] ) );
	}

	/**
	 * Single-row save for the Links screen's Edit modal. Bulk conversion
	 * is a separate, non-AJAX path (ALM_Links_List_Table::process_bulk_action(),
	 * dispatched from handle_links_bulk_action() above) since it's just
	 * WP_List_Table's normal bulk-action form submit, not a JS-driven
	 * flow -- both funnel through the same ALM_Link_Converter either way.
	 * The modal only ever edits a URL -- the provider is never a manual
	 * choice here, ALM_Link_Converter::save_url() infers it via
	 * ALM_Provider_Registry::match_url(), same as the scanner.
	 *
	 * @return void
	 */
	public function handle_edit_link() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		// esc_url_raw() is itself the sanitization here -- the raw
		// wp_unslash() below is not output or stored on its own.
		$url = ! empty( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( ! $id || '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'That URL does not look valid.', 'affiliate-link-manager' ) ), 400 );
		}

		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() below.
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.

		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'affiliate-link-manager' ) ), 404 );
		}

		$result = $this->converter->save_url( $item, $url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Single-row "Remove from Post" for the Edit modal -- unwraps the
	 * link out of the post entirely and deletes its tracking row, via
	 * ALM_Link_Converter::remove(). Only ever actually acts on a
	 * status=stale row; the modal itself already only shows this action
	 * for a stale link (see assets/admin.js), but this is re-checked
	 * server-side too rather than trusting the client alone.
	 *
	 * @return void
	 */
	public function handle_remove_link() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'affiliate-link-manager' ) ), 400 );
		}

		global $wpdb;
		$table = ALM_Install::table_name();
		$sql   = "SELECT * FROM {$table} WHERE id = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
		$item  = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'affiliate-link-manager' ) ), 404 );
		}

		if ( ALM_Install::STATUS_STALE !== $item['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Only a confirmed-dead link can be removed this way.', 'affiliate-link-manager' ) ), 400 );
		}

		$result = $this->converter->remove( $item );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Live provider inference for the Edit modal, as the admin edits
	 * the URL field -- same ALM_Provider_Registry::match_url() the
	 * scanner itself uses, never a manual choice. A URL matching
	 * nothing registered is a normal, valid answer ("Unaffiliated"),
	 * not an error.
	 *
	 * @return void
	 */
	public function handle_match_provider() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-link-manager' ) ), 403 );
		}

		$url = ! empty( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- esc_url_raw() is itself the sanitization here.

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'That URL does not look valid.', 'affiliate-link-manager' ) ), 400 );
		}

		$provider = $this->providers->match_url( $url );

		wp_send_json_success(
			array(
				'id'    => $provider->get_id(),
				'label' => ALM_Links_List_Table::provider_display_label( $provider ),
			)
		);
	}

	public function handle_settings_forms() {
		if ( isset( $_POST['alm_settings_nonce'] ) && check_admin_referer( self::SETTINGS_NONCE, 'alm_settings_nonce' ) ) {
			$this->save_settings();
		}
	}

	/**
	 * Saves the whole (merged) Settings screen -- both the Networks
	 * section (ShopMy's own fields; every other registered network is
	 * classify-only with nothing to save) and the Scan behavior section
	 * (excluded domains) -- in one submit, one nonce. Used to be two
	 * separate forms/nonces/screens; see class docblock and
	 * render_settings().
	 *
	 * @return void
	 */
	private function save_settings() {
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

		// Read by ALM_Candidate_Classifier on every scan -- this is the
		// site owner's own no-code way to teach it about domains only
		// this site would know are noise (a sister blog, a magazine this
		// site frequently credits as an image source, etc.), on top of
		// the built-in universal defaults.
		if ( isset( $_POST['alm_candidate_excluded_domains'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( 'alm_candidate_excluded_domains', sanitize_textarea_field( wp_unslash( $_POST['alm_candidate_excluded_domains'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
		exit;
	}

	public function render_dashboard() {
		$stats           = $this->get_provider_stats();
		$status_summary  = $this->get_status_summary();
		$stale_count     = $this->get_stale_count();
		$network_signals = $this->network_signal_scanner->scan();
		$tasks           = $this->get_dashboard_tasks();
		require ALM_PATH . 'includes/views/dashboard.php';
	}

	public function render_links() {
		$list_table = new ALM_Links_List_Table( $this->providers, $this->adapters, $this->converter );
		$list_table->prepare_items();
		require ALM_PATH . 'includes/views/links.php';
	}

	public function render_settings() {
		// ALM_Provider_Generic excluded -- it's the scanner's own
		// always-matches fallback for "no real network recognized this
		// URL," not a real network with settings to manage. Same gate
		// get_provider_capabilities() already uses for the Edit modal.
		$providers = array_filter(
			$this->providers->get_providers(),
			static function ( $provider ) {
				return ! ( $provider instanceof ALM_Provider_Generic );
			}
		);
		require ALM_PATH . 'includes/views/settings.php';
	}

	/**
	 * The real-network sub-breakdown shown under the "Affiliate Links"
	 * headline on the Dashboard (ShopMy X, RewardStyle Y) -- excludes
	 * the 'unclassified' provider bucket on purpose. That bucket mixes
	 * Candidate Affiliate Links and Other Outbound Links together and
	 * belongs to the three-tier status summary (get_status_summary()),
	 * not a per-network breakdown; a real provider only ever produces
	 * status=active links (see ALM_Scanner::upsert_link()), so this
	 * list is naturally just ShopMy/RewardStyle/etc., never noise.
	 *
	 * @return array<string,array{label:string,count:int}>
	 */
	private function get_provider_stats() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only aggregate query.
		$rows = $wpdb->get_results( "SELECT provider, COUNT(*) as total FROM {$table} WHERE provider != 'unclassified' GROUP BY provider", ARRAY_A );

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
	 * The three-tier headline counts the Dashboard Overview is built
	 * around: Affiliate Links (status=active), Candidate Affiliate
	 * Links (status=convertible), and Other Outbound Links
	 * (status=unclassified) -- the last of which is deliberately never
	 * shown anywhere else as more than this one summary number.
	 *
	 * @return array<string,int>
	 */
	private function get_status_summary() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only aggregate query.
		$rows      = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM {$table} GROUP BY status", ARRAY_A );
		$by_status = wp_list_pluck( $rows, 'total', 'status' );

		return array(
			ALM_Install::STATUS_ACTIVE       => isset( $by_status[ ALM_Install::STATUS_ACTIVE ] ) ? (int) $by_status[ ALM_Install::STATUS_ACTIVE ] : 0,
			ALM_Install::STATUS_CONVERTIBLE  => isset( $by_status[ ALM_Install::STATUS_CONVERTIBLE ] ) ? (int) $by_status[ ALM_Install::STATUS_CONVERTIBLE ] : 0,
			ALM_Install::STATUS_UNCLASSIFIED => isset( $by_status[ ALM_Install::STATUS_UNCLASSIFIED ] ) ? (int) $by_status[ ALM_Install::STATUS_UNCLASSIFIED ] : 0,
		);
	}

	/**
	 * The Stale count shown as a third row on the Dashboard's headline
	 * table, only when non-zero -- a link no longer found on the last
	 * scan. Used to live in its own "Needs attention" card alongside a
	 * repeat of the Candidate count already shown in the headline table;
	 * folded into a single row here instead of two places saying the
	 * same thing about Candidates.
	 *
	 * @return int
	 */
	private function get_stale_count() {
		global $wpdb;
		$table = ALM_Install::table_name();

		$sql = "SELECT COUNT(*) FROM {$table} WHERE status = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; real value bound via prepare() below.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built entirely from prepare() above; small admin-only aggregate query.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ALM_Install::STATUS_STALE ) );
	}

	/**
	 * The Dashboard's Tasks table: one row per background operation, all
	 * shaped identically (label, why it matters, what happened last
	 * time, how much is left, one button) instead of three differently-
	 * shaped cards -- see includes/views/dashboard.php. Formatting lives
	 * here, once, rather than duplicated per task in the template.
	 *
	 * @return array<int,array{id:string,label:string,description:string,last_run:string,pending:int|null,button_id:string,progress_id:string,button_label:string,primary:bool,running:bool,processed_so_far:int,stalled:bool}>
	 */
	private function get_dashboard_tasks() {
		$tasks = array(
			array(
				'id'           => 'scan',
				'label'        => __( 'Scan', 'affiliate-link-manager' ),
				'description'  => __( 'Finds links in your published posts and classifies each one as an affiliate link, a candidate, or noise.', 'affiliate-link-manager' ),
				'last_run'     => $this->format_last_run(
					get_option( 'alm_last_scan_time', '' ),
					$this->format_scan_delta( get_option( 'alm_last_scan_delta', array() ) )
				),
				// No queue concept -- a scan always re-covers every
				// scannable post, unlike the two resumable queues below.
				'pending'      => null,
				'button_id'    => 'alm-run-scan',
				'progress_id'  => 'alm-scan-progress',
				'button_label' => __( 'Run Scan', 'affiliate-link-manager' ),
				'primary'      => true,
			),
			array(
				'id'           => 'domains',
				'label'        => __( 'Check Domains', 'affiliate-link-manager' ),
				'description'  => __( 'Confirms candidate domains are real shops, not reference or magazine sites, so those don\'t get counted as opportunities.', 'affiliate-link-manager' ),
				'last_run'     => $this->format_last_run(
					get_option( 'alm_last_domain_check_time', '' ),
					$this->format_domain_check_delta( get_option( 'alm_last_domain_check_delta', array() ) )
				),
				'pending'      => $this->domain_scanner->count_domains_needing_check(),
				'button_id'    => 'alm-check-domains',
				'progress_id'  => 'alm-domain-check-progress',
				'button_label' => __( 'Check Domains', 'affiliate-link-manager' ),
				'primary'      => false,
			),
			array(
				'id'           => 'shorteners',
				'label'        => __( 'Expand Shortened Links', 'affiliate-link-manager' ),
				'description'  => __( 'Follows bit.ly, etsy.me, and other shortened links to their real destination, so those get tracked (or flagged dead) instead of sitting unclassified.', 'affiliate-link-manager' ),
				'last_run'     => $this->format_last_run(
					get_option( 'alm_last_shortener_expand_time', '' ),
					$this->format_shortener_delta( get_option( 'alm_last_shortener_expand_delta', array() ) )
				),
				'pending'      => $this->shortener_scanner->count_pending(),
				'button_id'    => 'alm-expand-shorteners',
				'progress_id'  => 'alm-expand-shorteners-progress',
				'button_label' => __( 'Expand Shortened Links', 'affiliate-link-manager' ),
				'primary'      => false,
			),
			array(
				'id'           => 'link_health',
				'label'        => __( 'Check Link Health', 'affiliate-link-manager' ),
				'description'  => __( 'Confirms each candidate link\'s destination still actually works -- dead domains and missing pages move out of your opportunities list instead of sitting there unusable.', 'affiliate-link-manager' ),
				'last_run'     => $this->format_last_run(
					get_option( 'alm_last_link_health_time', '' ),
					$this->format_link_health_delta( get_option( 'alm_last_link_health_delta', array() ) )
				),
				'pending'      => $this->link_health_scanner->count_pending(),
				'button_id'    => 'alm-check-link-health',
				'progress_id'  => 'alm-link-health-progress',
				'button_label' => __( 'Check Link Health', 'affiliate-link-manager' ),
				'primary'      => false,
			),
		);

		// Real in-progress state, not just the always-idle "click to
		// start" shape above -- read once per task here (not inline
		// per-array-literal above) so a run started from an earlier
		// click, and possibly still being carried forward right now by
		// alm_continue_batch_run() with the tab long closed, renders
		// accurately on a fresh page load instead of looking abandoned.
		// See ALM_Background_Runner.
		foreach ( $tasks as &$task ) {
			$state                    = ALM_Background_Runner::get_state( $task['id'] );
			$task['running']          = $state['active'] && ! $state['stalled'];
			$task['processed_so_far'] = $state['processed'];
			$task['stalled']          = $state['stalled'];
		}
		unset( $task );

		return $tasks;
	}

	/**
	 * Single source of truth for "Last run" phrasing across every Tasks
	 * row -- relative time (human_time_diff(), the same idiom wp-admin
	 * itself uses for "X ago") plus whatever that task's own delta
	 * formatter has to say, so all three rows read in the same voice
	 * instead of Run Scan being the only one with a real answer.
	 *
	 * @param string $time       MySQL datetime this task last completed, or '' if never.
	 * @param string $delta_text Pre-formatted delta phrase, or '' if none.
	 * @return string
	 */
	private function format_last_run( $time, $delta_text ) {
		if ( ! $time ) {
			return __( 'Never run yet.', 'affiliate-link-manager' );
		}

		$relative = sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 hours" */
			__( '%s ago', 'affiliate-link-manager' ),
			human_time_diff( strtotime( $time ), current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- human_time_diff() needs a plain Unix timestamp to diff against; current_time('timestamp') is the documented way to get one on the same WP-local basis $time itself was written with.
		);

		return $delta_text ? $relative . ' — ' . $delta_text : $relative;
	}

	/**
	 * @param array{new_links?:int,now_stale?:int} $delta
	 * @return string
	 */
	private function format_scan_delta( array $delta ) {
		if ( empty( $delta ) ) {
			return '';
		}

		$new_links = isset( $delta['new_links'] ) ? (int) $delta['new_links'] : 0;
		$now_stale = isset( $delta['now_stale'] ) ? (int) $delta['now_stale'] : 0;

		if ( 0 === $new_links && 0 === $now_stale ) {
			return __( 'no changes', 'affiliate-link-manager' );
		}

		return sprintf(
			/* translators: 1: number of new links found, 2: number of links that went stale */
			__( '%1$d new, %2$d now stale', 'affiliate-link-manager' ),
			$new_links,
			$now_stale
		);
	}

	/**
	 * @param array{confirmed_shops?:int,confirmed_not?:int} $delta
	 * @return string
	 */
	private function format_domain_check_delta( array $delta ) {
		if ( empty( $delta ) ) {
			return '';
		}

		$shops = isset( $delta['confirmed_shops'] ) ? (int) $delta['confirmed_shops'] : 0;
		$not   = isset( $delta['confirmed_not'] ) ? (int) $delta['confirmed_not'] : 0;

		if ( 0 === $shops && 0 === $not ) {
			return __( 'nothing new to confirm', 'affiliate-link-manager' );
		}

		return sprintf(
			/* translators: 1: number of domains confirmed to be real shops, 2: number confirmed not to be */
			__( '%1$d confirmed shops, %2$d confirmed not', 'affiliate-link-manager' ),
			$shops,
			$not
		);
	}

	/**
	 * @param array{reclassified?:int,stale?:int} $delta
	 * @return string
	 */
	private function format_shortener_delta( array $delta ) {
		if ( empty( $delta ) ) {
			return '';
		}

		$reclassified = isset( $delta['reclassified'] ) ? (int) $delta['reclassified'] : 0;
		$stale        = isset( $delta['stale'] ) ? (int) $delta['stale'] : 0;

		if ( 0 === $reclassified && 0 === $stale ) {
			return __( 'nothing new to resolve', 'affiliate-link-manager' );
		}

		return sprintf(
			/* translators: 1: number of shortened links tracked to a known network, 2: number confirmed dead */
			__( '%1$d tracked, %2$d marked stale', 'affiliate-link-manager' ),
			$reclassified,
			$stale
		);
	}

	/**
	 * @param array{confirmed_dead?:int,still_fine?:int} $delta
	 * @return string
	 */
	private function format_link_health_delta( array $delta ) {
		if ( empty( $delta ) ) {
			return '';
		}

		$confirmed_dead = isset( $delta['confirmed_dead'] ) ? (int) $delta['confirmed_dead'] : 0;

		if ( 0 === $confirmed_dead ) {
			return __( 'all still good', 'affiliate-link-manager' );
		}

		return sprintf(
			/* translators: %d: number of candidate links confirmed dead and marked stale */
			__( '%d confirmed dead', 'affiliate-link-manager' ),
			$confirmed_dead
		);
	}
}
