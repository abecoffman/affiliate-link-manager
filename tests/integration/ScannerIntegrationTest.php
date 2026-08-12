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

	public function test_stale_link_sweep_marks_links_no_longer_found_after_a_full_scan() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://go.shopmy.us/p-999">old item</a></p>',
			)
		);

		$this->assertTrue( $this->scanner->scan_batch( 0, 10 )['done'] );

		$rows = $this->get_links_for_post( $post_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'active', $rows[0]['status'] );

		// Simulate "this link was found a while ago" by back-dating
		// last_seen directly, rather than a real sleep() between two
		// scans -- current_time('mysql') is only second-granular, so
		// two scans run back-to-back in a fast test run could otherwise
		// land on the exact same timestamp and never trigger the
		// sweep's `last_seen < scan_started_at` comparison.
		global $wpdb;
		$wpdb->update( ALM_Install::table_name(), array( 'last_seen' => '2020-01-01 00:00:00' ), array( 'id' => $rows[0]['id'] ) );

		// The editor removed the link from the post.
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>No more links here.</p>',
			)
		);

		$this->assertTrue( $this->scanner->scan_batch( 0, 10 )['done'] );

		$rows = $this->get_links_for_post( $post_id );
		$this->assertCount( 1, $rows, 'The row should persist (not be deleted) -- just marked stale.' );
		$this->assertSame( 'stale', $rows[0]['status'] );
	}

	public function test_stale_sweep_never_touches_ignored_links() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://go.shopmy.us/p-888">old item</a></p>',
			)
		);

		$this->scanner->scan_batch( 0, 10 );
		$rows = $this->get_links_for_post( $post_id );

		global $wpdb;
		$wpdb->update(
			ALM_Install::table_name(),
			array(
				'status'    => 'ignored',
				'last_seen' => '2020-01-01 00:00:00',
			),
			array( 'id' => $rows[0]['id'] )
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>No more links here.</p>',
			)
		);
		$this->scanner->scan_batch( 0, 10 );

		$rows = $this->get_links_for_post( $post_id );
		$this->assertSame( 'ignored', $rows[0]['status'], 'An ignored link must not be swept to stale even once no longer found.' );
	}

	public function test_ignored_status_is_not_reverted_when_the_link_is_rediscovered() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://go.shopmy.us/p-777">item</a></p>',
			)
		);

		$this->scanner->scan_batch( 0, 10 );
		$rows = $this->get_links_for_post( $post_id );

		global $wpdb;
		$wpdb->update( ALM_Install::table_name(), array( 'status' => 'ignored' ), array( 'id' => $rows[0]['id'] ) );

		// Re-scan with the link still present in the post -- ignored must stick.
		$this->scanner->scan_batch( 0, 10 );

		$rows = $this->get_links_for_post( $post_id );
		$this->assertSame( 'ignored', $rows[0]['status'] );
	}

	/**
	 * @covers ALM_Install
	 */
	public function test_dead_confirmed_at_is_cleared_when_a_previously_stale_link_is_rediscovered() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://go.shopmy.us/p-777">item</a></p>',
			)
		);

		$this->scanner->scan_batch( 0, 10 );
		$rows = $this->get_links_for_post( $post_id );

		// Simulate a prior ALM_Link_Health_Scanner verdict: the link
		// briefly went stale-and-confirmed-dead (e.g. it was removed
		// from the post, swept stale, then health-checked) before
		// being restored to the post and rediscovered here.
		global $wpdb;
		$wpdb->update(
			ALM_Install::table_name(),
			array(
				'status'            => ALM_Install::STATUS_STALE,
				'dead_confirmed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $rows[0]['id'] )
		);

		// Re-scan with the link still present -- it is a real
		// rediscovery, so upsert_link() must not leave the stale
		// dead_confirmed_at verdict behind now that the row is being
		// reclassified to something else. See ALM_Install::create_table()'s
		// docblock and ALM_Scanner::upsert_link().
		$this->scanner->scan_batch( 0, 10 );

		$rows = $this->get_links_for_post( $post_id );
		$this->assertNotSame( ALM_Install::STATUS_STALE, $rows[0]['status'] );
		$this->assertNull( $rows[0]['dead_confirmed_at'] );
	}

	/**
	 * @covers ALM_Candidate_Classifier
	 */
	public function test_unclassified_links_are_split_into_candidates_and_noise() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://www.a-real-retailer.example/product">necklace</a> and '
					. 'follow us on <a href="https://www.instagram.com/honestlywtf">Instagram</a>.</p>',
			)
		);

		$this->assertTrue( $this->scanner->scan_batch( 0, 10 )['done'] );

		$by_url = wp_list_pluck( $this->get_links_for_post( $post_id ), 'status', 'url' );
		$this->assertSame( 'convertible', $by_url['https://www.a-real-retailer.example/product'], 'An unrecognized retailer link is a real candidate.' );
		$this->assertSame( 'unclassified', $by_url['https://www.instagram.com/honestlywtf'], 'A social-platform link is noise, not a candidate.' );
	}

	public function test_scan_records_a_delta_of_new_and_now_stale_links_for_the_dashboard() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://go.shopmy.us/p-321">new item</a></p>',
			)
		);

		$this->assertTrue( $this->scanner->scan_batch( 0, 10 )['done'] );

		$delta = get_option( 'alm_last_scan_delta' );
		$this->assertSame( 1, $delta['new_links'], 'The link discovered on this first scan is new.' );
		$this->assertSame( 0, $delta['now_stale'], 'Nothing has gone stale yet.' );

		// Back-date and remove the link, same as the stale-sweep test --
		// the next scan should report it as newly stale, and report zero
		// new links since nothing new was actually found this time.
		// Both first_seen and last_seen need back-dating: current_time('mysql')
		// is only second-granular, so a first_seen left at "now" could
		// still land in the same second as this second scan's own
		// scan_started_at and get miscounted as new by the >= comparison.
		$rows = $this->get_links_for_post( $post_id );
		global $wpdb;
		$wpdb->update(
			ALM_Install::table_name(),
			array(
				'first_seen' => '2020-01-01 00:00:00',
				'last_seen'  => '2020-01-01 00:00:00',
			),
			array( 'id' => $rows[0]['id'] )
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>No more links here.</p>',
			)
		);

		$this->assertTrue( $this->scanner->scan_batch( 0, 10 )['done'] );

		$delta = get_option( 'alm_last_scan_delta' );
		$this->assertSame( 0, $delta['new_links'], 'No new links were found on this second scan.' );
		$this->assertSame( 1, $delta['now_stale'], 'The removed link should be counted as newly stale.' );
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

	public function test_post_content_adapter_remove_link_unwraps_the_anchor_and_persists() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Wearing this <a href="https://www.a-dead-retailer.example/product">tank top</a> today.</p>',
			)
		);

		$adapter = new ALM_Adapter_Post_Content();
		$result  = $adapter->remove_link( $post_id, '0', 'https://www.a-dead-retailer.example/product' );

		$this->assertTrue( $result );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringNotContainsString( '<a ', $fresh->post_content );
		$this->assertStringContainsString( 'Wearing this tank top today.', $fresh->post_content );
	}

	public function test_post_content_adapter_remove_link_refuses_when_content_changed_since_the_last_scan() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.a-dead-retailer.example/product">tank top</a></p>',
			)
		);

		$adapter = new ALM_Adapter_Post_Content();
		$result  = $adapter->remove_link( $post_id, '0', 'https://not-the-real-current-href.example/product' );

		$this->assertInstanceOf( WP_Error::class, $result );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'href="https://www.a-dead-retailer.example/product"', $fresh->post_content, 'The real, current post content must be untouched.' );
	}

	public function test_post_content_adapter_get_context_reads_the_real_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Wearing my favorite <a href="https://www.zara.com/product">tank top</a> today.</p>',
			)
		);

		$adapter = new ALM_Adapter_Post_Content();
		$context = $adapter->get_context( $post_id, '0' );

		$this->assertSame( 'Wearing my favorite', $context['before'] );
		$this->assertSame( 'tank top', $context['text'] );
		$this->assertSame( 'today.', $context['after'] );
	}

	/**
	 * Real gap this closes: the Edit modal's Link text context was
	 * silently empty for every Beaver-Builder-backed link on honestlywtf
	 * (get_context() only implemented on ALM_Adapter_Post_Content at
	 * first) -- found live, on a real BB post, not in a test.
	 */
	public function test_beaver_builder_adapter_get_context_reads_the_real_layout_data() {
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
						'text' => '<p>Come hang with us at the <a href="https://www.birkenstock.com/us/magazine/boston-store/">Newbury</a> location.</p>',
					),
				),
			)
		);

		$adapter = new ALM_Adapter_Beaver_Builder();
		$context = $adapter->get_context( $post_id, 'node-1:0' );

		$this->assertSame( 'Come hang with us at the', $context['before'] );
		$this->assertSame( 'Newbury', $context['text'] );
		$this->assertSame( 'location.', $context['after'] );
	}

	public function test_beaver_builder_adapter_get_context_returns_null_for_a_malformed_location() {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			$this->markTestSkipped( 'bb-plugin-stub not loaded -- set BB_PLUGIN_STUB_PATH before running.' );
		}

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$adapter = new ALM_Adapter_Beaver_Builder();

		$this->assertNull( $adapter->get_context( $post_id, 'not-a-valid-location' ) );
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

	public function test_beaver_builder_adapter_remove_link_unwraps_and_resyncs_post_content() {
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
						'text' => '<p>Get the <a href="https://www.a-dead-retailer.example/product">boots</a> now.</p>',
					),
				),
			)
		);

		$adapter = new ALM_Adapter_Beaver_Builder();
		$result  = $adapter->remove_link( $post_id, 'node-1:0', 'https://www.a-dead-retailer.example/product' );

		$this->assertTrue( $result );

		$data = get_post_meta( $post_id, '_fl_builder_data', true );
		$this->assertStringNotContainsString( '<a ', $data['node-1']->settings->text );
		$this->assertStringContainsString( 'Get the boots now.', $data['node-1']->settings->text );

		// Same post_content resync as replace_link() -- confirmed via
		// the stub's fixed marker.
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
