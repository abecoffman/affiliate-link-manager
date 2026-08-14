<?php
/**
 * Light smoke coverage for ALM_Admin::render_dashboard()/render_links()/
 * render_settings() -- previously had zero PHP-level coverage. Not
 * trying to characterize the full markup (that's what tests/e2e/ is
 * for against a real browser); just confirming each screen renders
 * without a fatal and contains a couple of markers that would only be
 * there if the right data actually reached the view.
 *
 * @package ALM
 */

/**
 * @covers ALM_Admin
 */
class RenderScreensIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Admin
	 */
	private $admin;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		if ( ! class_exists( 'ALM_Links_List_Table' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/class-alm-links-list-table.php';
		}

		$providers              = new ALM_Provider_Registry();
		$adapters               = new ALM_Adapter_Registry();
		$scanner                = new ALM_Scanner( $adapters, $providers, new ALM_Candidate_Classifier() );
		$domain_scanner         = new ALM_Domain_Scanner( new ALM_Domain_Checker(), new ALM_Candidate_Classifier() );
		$converter              = new ALM_Link_Converter( $providers, $adapters );
		$network_signal_scanner = new ALM_Network_Signal_Scanner();
		$shortener_scanner      = new ALM_Shortener_Scanner( new ALM_Shortener_Resolver(), $providers );
		$thumbnail_fetcher      = new ALM_Thumbnail_Fetcher();
		$link_health_scanner    = new ALM_Link_Health_Scanner( new ALM_Link_Health_Checker() );
		$dashboard_data         = new ALM_Dashboard_Data( $providers, $domain_scanner, $shortener_scanner, $link_health_scanner );

		$this->admin = new ALM_Admin( $scanner, $providers, $adapters, $domain_scanner, $converter, $network_signal_scanner, $shortener_scanner, $thumbnail_fetcher, $link_health_scanner, $dashboard_data );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * @param callable $render
	 * @return string
	 */
	private function capture( callable $render ) {
		ob_start();
		$render();
		return ob_get_clean();
	}

	public function test_render_dashboard_shows_the_dead_links_tile_once_a_link_is_confirmed_dead() {
		global $wpdb;
		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'           => 1,
				'provider'          => 'unclassified',
				'adapter'           => 'post_content',
				'location'          => '0',
				'url'               => 'https://confirmed-dead.example.com/product',
				'anchor_text'       => 'link',
				'status'            => ALM_Install::STATUS_STALE,
				'first_seen'        => current_time( 'mysql' ),
				'last_seen'         => current_time( 'mysql' ),
				'dead_confirmed_at' => current_time( 'mysql' ),
			)
		);

		$html = $this->capture( array( $this->admin, 'render_dashboard' ) );

		$this->assertStringContainsString( 'Affiliate Links', $html );
		$this->assertStringContainsString( 'alm-stat-tile-dead', $html );
	}

	public function test_render_dashboard_omits_the_dead_tile_when_nothing_is_confirmed_dead() {
		$html = $this->capture( array( $this->admin, 'render_dashboard' ) );

		$this->assertStringContainsString( 'Affiliate Links', $html );
		$this->assertStringNotContainsString( 'alm-stat-tile-dead', $html );
	}

	public function test_render_links_renders_the_list_table() {
		$html = $this->capture( array( $this->admin, 'render_links' ) );

		$this->assertStringContainsString( 'Affiliate Links', $html );
		$this->assertStringContainsString( '<table', $html, 'The WP_List_Table markup must actually be present.' );
	}

	public function test_render_settings_shows_the_shopmy_fields() {
		$html = $this->capture( array( $this->admin, 'render_settings' ) );

		$this->assertStringContainsString( 'alm_shopmy_affiliate_id', $html );
		$this->assertStringContainsString( 'Networks', $html );
		$this->assertStringContainsString( 'Scan behavior', $html );
	}
}
