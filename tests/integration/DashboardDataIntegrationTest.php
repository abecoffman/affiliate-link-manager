<?php
/**
 * Integration tests for ALM_Dashboard_Data -- extracted out of
 * ALM_Admin (see that class's own docblock), previously untested at
 * the PHP level despite backing every number shown on the Dashboard.
 * Real $wpdb needed for the provider/status aggregate queries; real
 * options for the Tasks table's "last run" formatting.
 *
 * @package ALM
 */

/**
 * @covers ALM_Dashboard_Data
 */
class DashboardDataIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Dashboard_Data
	 */
	private $dashboard_data;

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		$this->providers = new ALM_Provider_Registry();

		$domain_scanner      = new ALM_Domain_Scanner( new ALM_Domain_Checker(), new ALM_Candidate_Classifier() );
		$shortener_scanner   = new ALM_Shortener_Scanner( new ALM_Shortener_Resolver(), $this->providers );
		$link_health_scanner = new ALM_Link_Health_Scanner( new ALM_Link_Health_Checker() );

		$this->dashboard_data = new ALM_Dashboard_Data( $this->providers, $domain_scanner, $shortener_scanner, $link_health_scanner );
	}

	public function tear_down() {
		foreach ( ALM_Background_Runner::TASK_BATCH_SIZES as $task_id => $size ) {
			ALM_Background_Runner::clear_state( $task_id );
		}
		delete_option( 'alm_last_scan_time' );
		delete_option( 'alm_last_scan_delta' );
		delete_option( 'alm_last_domain_check_time' );
		delete_option( 'alm_last_domain_check_delta' );
		delete_option( 'alm_last_shortener_expand_time' );
		delete_option( 'alm_last_shortener_expand_delta' );
		delete_option( 'alm_last_link_health_time' );
		delete_option( 'alm_last_link_health_delta' );
		parent::tear_down();
	}

	/**
	 * @param string $url
	 * @param string $status
	 * @param string $provider
	 * @return int Inserted row id.
	 */
	private function insert_link( $url, $status, $provider = 'unclassified' ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'     => 1,
				'provider'    => $provider,
				'adapter'     => 'post_content',
				'location'    => (string) wp_rand(),
				'url'         => $url,
				'anchor_text' => 'link',
				'status'      => $status,
				'first_seen'  => $now,
				'last_seen'   => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_get_provider_stats_counts_only_real_providers_grouped_by_id() {
		$this->insert_link( 'https://go.shopmy.us/apx/1', ALM_Install::STATUS_ACTIVE, 'shopmy' );
		$this->insert_link( 'https://go.shopmy.us/apx/2', ALM_Install::STATUS_ACTIVE, 'shopmy' );
		$this->insert_link( 'https://unclassified.example/x', ALM_Install::STATUS_CONVERTIBLE, 'unclassified' );

		$stats = $this->dashboard_data->get_provider_stats();

		$this->assertArrayHasKey( 'shopmy', $stats );
		$this->assertSame( 2, $stats['shopmy']['count'] );
		$this->assertArrayNotHasKey( 'unclassified', $stats, 'The always-matches fallback provider is never a real per-network row -- it belongs to get_status_summary() instead.' );
	}

	public function test_get_provider_stats_uses_the_providers_own_label() {
		$this->insert_link( 'https://go.shopmy.us/apx/1', ALM_Install::STATUS_ACTIVE, 'shopmy' );

		$stats    = $this->dashboard_data->get_provider_stats();
		$provider = $this->providers->get_provider( 'shopmy' );

		$this->assertSame( $provider->get_label(), $stats['shopmy']['label'] );
	}

	public function test_get_status_summary_counts_the_three_headline_tiers() {
		$this->insert_link( 'https://go.shopmy.us/apx/1', ALM_Install::STATUS_ACTIVE, 'shopmy' );
		$this->insert_link( 'https://candidate.example/a', ALM_Install::STATUS_CONVERTIBLE );
		$this->insert_link( 'https://candidate.example/b', ALM_Install::STATUS_CONVERTIBLE );
		$this->insert_link( 'https://noise.example/a', ALM_Install::STATUS_UNCLASSIFIED );
		// A stale row belongs to neither tab nor tile the summary feeds --
		// must never inflate any of the three counts.
		$this->insert_link( 'https://stale.example/a', ALM_Install::STATUS_STALE );

		$summary = $this->dashboard_data->get_status_summary();

		$this->assertSame( 1, $summary[ ALM_Install::STATUS_ACTIVE ] );
		$this->assertSame( 2, $summary[ ALM_Install::STATUS_CONVERTIBLE ] );
		$this->assertSame( 1, $summary[ ALM_Install::STATUS_UNCLASSIFIED ] );
	}

	public function test_get_status_summary_defaults_every_tier_to_zero_on_an_empty_table() {
		$summary = $this->dashboard_data->get_status_summary();

		$this->assertSame( 0, $summary[ ALM_Install::STATUS_ACTIVE ] );
		$this->assertSame( 0, $summary[ ALM_Install::STATUS_CONVERTIBLE ] );
		$this->assertSame( 0, $summary[ ALM_Install::STATUS_UNCLASSIFIED ] );
	}

	public function test_get_dashboard_tasks_returns_all_four_tasks_in_a_stable_order() {
		$tasks = $this->dashboard_data->get_dashboard_tasks();

		$this->assertSame( array( 'scan', 'domains', 'shorteners', 'link_health' ), wp_list_pluck( $tasks, 'id' ) );
	}

	public function test_get_dashboard_tasks_reports_never_run_yet_with_no_option_set() {
		$tasks = $this->dashboard_data->get_dashboard_tasks();
		$scan  = $tasks[0];

		$this->assertSame( 'Never run yet.', $scan['last_run'] );
	}

	public function test_get_dashboard_tasks_formats_a_scan_delta_with_relative_time() {
		update_option( 'alm_last_scan_time', current_time( 'mysql' ) );
		update_option(
			'alm_last_scan_delta',
			array(
				'new_links' => 3,
				'now_stale' => 1,
			)
		);

		$tasks = $this->dashboard_data->get_dashboard_tasks();
		$scan  = $tasks[0];

		$this->assertStringContainsString( 'ago', $scan['last_run'] );
		$this->assertStringContainsString( '3 new, 1 now stale', $scan['last_run'] );
	}

	public function test_get_dashboard_tasks_reflects_a_currently_active_background_run() {
		ALM_Background_Runner::save_state(
			'link_health',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 7,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 2,
				'stalled'          => false,
			)
		);

		$tasks       = $this->dashboard_data->get_dashboard_tasks();
		$link_health = $tasks[3];

		$this->assertSame( 'link_health', $link_health['id'] );
		$this->assertTrue( $link_health['running'] );
		$this->assertSame( 7, $link_health['processed_so_far'] );
		$this->assertFalse( $link_health['stalled'] );
	}

	public function test_get_dashboard_tasks_reflects_a_stalled_run_as_not_running() {
		ALM_Background_Runner::save_state(
			'domains',
			array(
				'active'           => true,
				'cursor'           => 0,
				'processed'        => 2,
				'started_at'       => current_time( 'mysql' ),
				'reschedule_count' => 5000,
				'stalled'          => true,
			)
		);

		$tasks   = $this->dashboard_data->get_dashboard_tasks();
		$domains = $tasks[1];

		$this->assertSame( 'domains', $domains['id'] );
		$this->assertFalse( $domains['running'], 'A stalled run must not read as actively running, even though active=true.' );
		$this->assertTrue( $domains['stalled'] );
	}
}
