<?php
/**
 * Dashboard data aggregation, extracted out of ALM_Admin: the
 * provider/status summary counts and the Tasks table's per-row
 * formatting are pure data-in/data-out (a registry, a few scanners for
 * pending counts, options, $wpdb reads), with no hooks or AJAX wiring
 * of their own -- a natural, low-risk seam to split off a class that
 * had otherwise grown to own screen registration, nine AJAX handlers,
 * settings persistence, and this all at once.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Dashboard_Data {

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * @var ALM_Domain_Scanner
	 */
	private $domain_scanner;

	/**
	 * @var ALM_Shortener_Scanner
	 */
	private $shortener_scanner;

	/**
	 * @var ALM_Link_Health_Scanner
	 */
	private $link_health_scanner;

	public function __construct( ALM_Provider_Registry $providers, ALM_Domain_Scanner $domain_scanner, ALM_Shortener_Scanner $shortener_scanner, ALM_Link_Health_Scanner $link_health_scanner ) {
		$this->providers           = $providers;
		$this->domain_scanner      = $domain_scanner;
		$this->shortener_scanner   = $shortener_scanner;
		$this->link_health_scanner = $link_health_scanner;
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
	public function get_provider_stats() {
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
	 * around: Affiliate Links (category=affiliate), Candidate Affiliate
	 * Links (category=candidate), and Other Outbound Links
	 * (category=nonaffiliate, modifier IS NULL specifically -- not
	 * every nonaffiliate row) -- the last of which is deliberately
	 * never shown anywhere else as more than this one summary number.
	 * A nonaffiliate row that's ignored, dead, or stale already has its
	 * own explicit surface elsewhere (the Ignored tab, the Dead Links
	 * tile, quiet cron cleanup respectively) and must not also be
	 * double-counted into this plain "noise" bucket.
	 *
	 * @return array<string,int>
	 */
	public function get_status_summary() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only aggregate query.
		$rows = $wpdb->get_results( "SELECT category, modifier, COUNT(*) as total FROM {$table} GROUP BY category, modifier", ARRAY_A );

		$summary = array(
			ALM_Install::CATEGORY_AFFILIATE    => 0,
			ALM_Install::CATEGORY_CANDIDATE    => 0,
			ALM_Install::CATEGORY_NONAFFILIATE => 0,
		);

		foreach ( (array) $rows as $row ) {
			if ( ALM_Install::CATEGORY_NONAFFILIATE === $row['category'] && null !== $row['modifier'] ) {
				continue; // Ignored/dead/stale -- not part of this plain noise count, see docblock above.
			}
			$summary[ $row['category'] ] = (int) $row['total'];
		}

		return $summary;
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
	public function get_dashboard_tasks() {
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
	 * @param array{reclassified?:int,confirmed_dead?:int} $delta
	 * @return string
	 */
	private function format_shortener_delta( array $delta ) {
		if ( empty( $delta ) ) {
			return '';
		}

		$reclassified   = isset( $delta['reclassified'] ) ? (int) $delta['reclassified'] : 0;
		$confirmed_dead = isset( $delta['confirmed_dead'] ) ? (int) $delta['confirmed_dead'] : 0;

		if ( 0 === $reclassified && 0 === $confirmed_dead ) {
			return __( 'nothing new to resolve', 'affiliate-link-manager' );
		}

		return sprintf(
			/* translators: 1: number of shortened links tracked to a known network, 2: number confirmed dead */
			__( '%1$d tracked, %2$d confirmed dead', 'affiliate-link-manager' ),
			$reclassified,
			$confirmed_dead
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
