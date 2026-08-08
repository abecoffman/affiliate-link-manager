<?php
/**
 * Integration tests for the scanner + both content adapters against a
 * real WordPress install, real $wpdb, and (for the Beaver Builder
 * adapter) the bb-plugin-stub fixture -- none of which the fast Brain
 * Monkey unit tier (tests/unit/) can exercise. Run via `composer
 * test:integration` against the plugin-sandbox test DB.
 *
 * @package ALM
 */

/**
 * @covers ALM_Scanner
 * @covers ALM_Adapter_Post_Content
 * @covers ALM_Adapter_Beaver_Builder
 */
class ScannerIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * @var ALM_Adapter_Registry
	 */
	private $adapters;

	/**
	 * @var ALM_Scanner
	 */
	private $scanner;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		$this->providers = new ALM_Provider_Registry();
		$this->adapters  = new ALM_Adapter_Registry();
		$this->scanner   = new ALM_Scanner( $this->adapters, $this->providers );
	}

	public function test_scan_finds_and_classifies_links_in_plain_post_content() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Wearing this <a href="https://go.shopmy.us/p-123">cardigan</a> and these '
					. '<a href="https://rstyle.me/+abc123">boots</a> today.</p>',
			)
		);

		$result = $this->scanner->scan_batch( 0, 10 );
		$this->assertTrue( $result['done'] );

		$rows = $this->get_links_for_post( $post_id );
		$this->assertCount( 2, $rows );

		$by_url = wp_list_pluck( $rows, 'provider', 'url' );
		$this->assertSame( 'shopmy', $by_url['https://go.shopmy.us/p-123'] );
		$this->assertSame( 'rewardstyle', $by_url['https://rstyle.me/+abc123'] );

		foreach ( $rows as $row ) {
			$this->assertSame( 'post_content', $row['adapter'] );
		}
	}

	/**
	 * The real point of this whole plugin's adapter architecture: a
	 * Beaver-Builder-backed post's link must be found via the BB
	 * adapter, not silently missed or double-counted via the
	 * post_content fallback (post_content for a BB post is just a
	 * cached render, but it may still contain the same href -- the
	 * registry must pick exactly one adapter per post).
	 */
	public function test_scan_finds_links_in_a_beaver_builder_post_via_the_bb_adapter() {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			$this->markTestSkipped( 'bb-plugin-stub not loaded -- set BB_PLUGIN_STUB_PATH before running.' );
		}

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_fl_builder_enabled', 1 );
		update_post_meta(
			$post_id,
			'_fl_builder_data',
			array(
				'node-1' => (object) array(
					'type'     => 'module',
					'settings' => (object) array(
						'type' => 'rich-text',
						'text' => '<p>Get the <a href="https://go.shopmy.us/p-456">lamp</a>.</p>',
					),
				),
			)
		);

		$result = $this->scanner->scan_batch( 0, 10 );
		$this->assertTrue( $result['done'] );

		$rows = $this->get_links_for_post( $post_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'beaver_builder', $rows[0]['adapter'] );
		$this->assertSame( 'shopmy', $rows[0]['provider'] );
		$this->assertSame( 'https://go.shopmy.us/p-456', $rows[0]['url'] );
	}

	public function test_post_content_adapter_replace_link_persists_to_the_real_database() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$adapter = new ALM_Adapter_Post_Content();
		$result  = $adapter->replace_link( $post_id, '0', 'https://www.zara.com/product', 'https://go.shopmy.us/apx/abc?url=https://www.zara.com/product' );

		$this->assertTrue( $result );

		// Re-fetch from the real database, not the in-memory object, to
		// prove the write actually persisted.
		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'go.shopmy.us/apx/abc', $fresh->post_content );
		// The wrapped URL legitimately embeds the old URL as a query
		// param -- what must be gone is the *raw, unwrapped* href.
		$this->assertStringNotContainsString( 'href="https://www.zara.com/product"', $fresh->post_content );
	}

	public function test_beaver_builder_adapter_replace_link_persists_layout_data_and_resyncs_post_content() {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			$this->markTestSkipped( 'bb-plugin-stub not loaded -- set BB_PLUGIN_STUB_PATH before running.' );
		}

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_fl_builder_enabled', 1 );
		update_post_meta(
			$post_id,
			'_fl_builder_data',
			array(
				'node-1' => (object) array(
					'type'     => 'module',
					'settings' => (object) array(
						'type' => 'rich-text',
						'text' => '<p><a href="https://rstyle.me/+old">boots</a></p>',
					),
				),
			)
		);

		$adapter = new ALM_Adapter_Beaver_Builder();
		$result  = $adapter->replace_link( $post_id, 'node-1:0', 'https://rstyle.me/+old', 'https://go.shopmy.us/apx/xyz?url=https://boots.example.com' );

		$this->assertTrue( $result );

		// Real layout data, re-read from postmeta.
		$data = get_post_meta( $post_id, '_fl_builder_data', true );
		$this->assertStringContainsString( 'go.shopmy.us/apx/xyz', $data['node-1']->settings->text );
		$this->assertStringNotContainsString( 'rstyle.me', $data['node-1']->settings->text );

		// post_content must have been resynced via FLBuilder::render_editor_content()
		// -- confirmed via the stub's fixed marker, proving the adapter
		// actually called it rather than leaving post_content stale.
		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertSame( '<!-- bb-plugin-stub rendered content -->', $fresh->post_content );
	}

	/**
	 * @param int $post_id
	 * @return array[]
	 */
	private function get_links_for_post( $post_id ) {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
	}
}
