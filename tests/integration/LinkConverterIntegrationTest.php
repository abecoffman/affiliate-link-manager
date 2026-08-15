<?php
/**
 * Integration tests for ALM_Link_Converter -- the shared logic behind
 * both the Links screen's row-level Edit modal (ALM_Admin::handle_edit_link())
 * and its "Convert to [Provider]" bulk action
 * (ALM_Links_List_Table::process_bulk_action()). Needs a real $wpdb and
 * real post content, same as ScannerIntegrationTest's replace_link()
 * coverage -- Brain Monkey's unit tier can't exercise a real
 * wp_update_post()/wpdb->update() round trip.
 *
 * @package ALM
 */

/**
 * @covers ALM_Link_Converter
 */
class LinkConverterIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Link_Converter
	 */
	private $converter;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

		update_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID, 'sDXyBS' );

		$providers       = new ALM_Provider_Registry();
		$adapters        = new ALM_Adapter_Registry();
		$this->converter = new ALM_Link_Converter( $providers, $adapters );
	}

	public function test_convert_wraps_and_persists_for_a_wrap_capable_provider() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		$result = $this->converter->convert( $item, 'shopmy' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'shopmy', $row['provider'] );
		$this->assertSame( ALM_Install::CATEGORY_AFFILIATE, $row['category'] );
		$this->assertStringContainsString( 'go.shopmy.us/apx/sDXyBS', $row['url'] );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'go.shopmy.us/apx/sDXyBS', $fresh->post_content );
		$this->assertStringNotContainsString( 'href="https://www.zara.com/product"', $fresh->post_content );
	}

	public function test_convert_reclassifies_only_for_a_non_wrapping_provider() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		$result = $this->converter->convert( $item, 'rewardstyle' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'rewardstyle', $row['provider'] );
		$this->assertSame( ALM_Install::CATEGORY_AFFILIATE, $row['category'] );
		// A classify-only conversion never touches the URL or the post --
		// RewardStyle can't build a new tracked link, this just relabels
		// the record.
		$this->assertSame( 'https://www.zara.com/product', $row['url'] );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'href="https://www.zara.com/product"', $fresh->post_content );
	}

	public function test_convert_refuses_and_leaves_the_row_untouched_when_content_changed_since_the_last_scan() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		// The editor changed the link in the post since the last scan --
		// the tracked row's own 'url' (above) is now stale, and
		// replace_link() must catch this itself rather than clobbering
		// whatever is actually there now.
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p><a href="https://www.zara.com/different-product">tank top</a></p>',
			)
		);

		$result = $this->converter->convert( $item, 'shopmy' );
		$this->assertInstanceOf( WP_Error::class, $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'unclassified', $row['provider'], 'The record must be left exactly as it was.' );
		$this->assertSame( ALM_Install::CATEGORY_CANDIDATE, $row['category'] );
		$this->assertSame( 'https://www.zara.com/product', $row['url'] );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'href="https://www.zara.com/different-product"', $fresh->post_content, 'The real, current post content must be untouched.' );
	}

	/**
	 * The Edit modal's whole reason for existing: an explicit URL --
	 * e.g. a real link the admin already generated on RewardStyle's own
	 * site and is pasting in -- is written verbatim, no wrap_url()
	 * involved, with the provider auto-inferred via match_url() rather
	 * than manually chosen. This is the *only* way to attach a tracked
	 * link for a provider that can't build one itself.
	 */
	public function test_save_url_writes_the_url_verbatim_and_infers_the_provider() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		$pasted_url = 'https://rstyle.me/+manually-generated-abc123';
		$result     = $this->converter->save_url( $item, $pasted_url );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'rewardstyle', $row['provider'], 'match_url() must infer RewardStyle from the rstyle.me host, never a manual choice.' );
		$this->assertSame( ALM_Install::CATEGORY_AFFILIATE, $row['category'] );
		$this->assertSame( $pasted_url, $row['url'] );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'href="' . $pasted_url . '"', $fresh->post_content );
	}

	/**
	 * A pasted-in ShopMy URL never goes through wrap_url() -- the admin
	 * is providing the exact destination themselves, not asking this
	 * plugin to generate one, even though ShopMy happens to be
	 * can_wrap()-capable.
	 */
	public function test_save_url_never_wraps_a_url_the_admin_provided_directly() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		$pasted_url = 'https://go.shopmy.us/p-already-generated-manually';
		$result     = $this->converter->save_url( $item, $pasted_url );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'shopmy', $row['provider'] );
		$this->assertSame( $pasted_url, $row['url'], 'wrap_url() must not have run -- the pasted URL is used exactly as given.' );
	}

	/**
	 * A URL matching no registered provider is a legitimate outcome,
	 * not an error -- saved as given, classified "unaffiliated" (the
	 * scanner's own fallback), never silently promoted to a real
	 * Affiliate Link it isn't just because an admin clicked Save.
	 */
	public function test_save_url_for_an_unrecognized_url_stays_unclassified() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		$new_url = 'https://www.a-different-unrecognized-retailer.example/product';
		$result  = $this->converter->save_url( $item, $new_url );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'unclassified', $row['provider'] );
		$this->assertSame( ALM_Install::CATEGORY_NONAFFILIATE, $row['category'], 'No real network recognized this URL -- must not be marked affiliate.' );
		$this->assertSame( $new_url, $row['url'] );
	}

	public function test_save_url_refuses_when_content_changed_since_the_last_scan() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p><a href="https://www.zara.com/different-product">tank top</a></p>',
			)
		);

		$result = $this->converter->save_url( $item, 'https://rstyle.me/+manually-generated-abc123' );
		$this->assertInstanceOf( WP_Error::class, $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'unclassified', $row['provider'], 'The record must be left exactly as it was.' );
		$this->assertSame( ALM_Install::CATEGORY_CANDIDATE, $row['category'] );
		$this->assertSame( 'https://www.zara.com/product', $row['url'] );
	}

	/**
	 * A product thumbnail cached against this link's *old* destination
	 * must never linger once the URL itself actually changes -- see
	 * ALM_Thumbnail_Fetcher and ALM_Link_Converter::write_and_persist().
	 * The next Edit modal open re-fetches fresh for the new URL.
	 */
	public function test_save_url_clears_a_cached_thumbnail_when_the_url_actually_changes() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'              => $post_id,
				'provider'             => 'unclassified',
				'adapter'              => 'post_content',
				'location'             => '0',
				'url'                  => 'https://www.zara.com/product',
				'anchor_text'          => 'tank top',
				'category'             => ALM_Install::CATEGORY_CANDIDATE,
				'thumbnail_url'        => 'https://cdn.zara.com/old-product-photo.jpg',
				'thumbnail_fetched_at' => current_time( 'mysql' ),
			)
		);

		$result = $this->converter->save_url( $item, 'https://www.zara.com/a-different-product' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertNull( $row['thumbnail_url'] );
		$this->assertNull( $row['thumbnail_fetched_at'] );
	}

	/**
	 * The inverse of the above: saving the *same* URL (a no-op edit, or
	 * an admin who just clicked Save without changing anything) must
	 * leave an already-cached thumbnail alone -- it's still correct for
	 * a destination that hasn't changed.
	 */
	public function test_save_url_leaves_a_cached_thumbnail_alone_when_the_url_is_unchanged() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'              => $post_id,
				'provider'             => 'unclassified',
				'adapter'              => 'post_content',
				'location'             => '0',
				'url'                  => 'https://www.zara.com/product',
				'anchor_text'          => 'tank top',
				'category'             => ALM_Install::CATEGORY_CANDIDATE,
				'thumbnail_url'        => 'https://cdn.zara.com/still-the-right-photo.jpg',
				'thumbnail_fetched_at' => current_time( 'mysql' ),
			)
		);

		$result = $this->converter->save_url( $item, 'https://www.zara.com/product' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'https://cdn.zara.com/still-the-right-photo.jpg', $row['thumbnail_url'] );
		$this->assertNotNull( $row['thumbnail_fetched_at'] );
	}

	/**
	 * The bulk "Convert to [Provider]" path funnels through the same
	 * write_and_persist() -- wrap_url() building a new tracked URL is
	 * just as much a real URL change as a manually pasted one, so the
	 * same reset must apply here too.
	 */
	public function test_convert_clears_a_cached_thumbnail_when_wrap_url_changes_the_url() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'              => $post_id,
				'provider'             => 'unclassified',
				'adapter'              => 'post_content',
				'location'             => '0',
				'url'                  => 'https://www.zara.com/product',
				'anchor_text'          => 'tank top',
				'category'             => ALM_Install::CATEGORY_CANDIDATE,
				'thumbnail_url'        => 'https://cdn.zara.com/old-product-photo.jpg',
				'thumbnail_fetched_at' => current_time( 'mysql' ),
			)
		);

		$result = $this->converter->convert( $item, 'shopmy' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertNull( $row['thumbnail_url'] );
		$this->assertNull( $row['thumbnail_fetched_at'] );
	}

	/**
	 * A reclassify-only conversion (a non-wrapping provider like
	 * RewardStyle) never touches the URL at all -- it must not touch
	 * the cached thumbnail either, since reclassify() doesn't go
	 * through write_and_persist() in the first place.
	 */
	public function test_convert_to_a_non_wrapping_provider_leaves_a_cached_thumbnail_alone() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'              => $post_id,
				'provider'             => 'unclassified',
				'adapter'              => 'post_content',
				'location'             => '0',
				'url'                  => 'https://www.zara.com/product',
				'anchor_text'          => 'tank top',
				'category'             => ALM_Install::CATEGORY_CANDIDATE,
				'thumbnail_url'        => 'https://cdn.zara.com/still-the-right-photo.jpg',
				'thumbnail_fetched_at' => current_time( 'mysql' ),
			)
		);

		$result = $this->converter->convert( $item, 'rewardstyle' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'https://cdn.zara.com/still-the-right-photo.jpg', $row['thumbnail_url'] );
	}

	/**
	 * "Remove from Post" -- unwraps a confirmed-dead link out of the
	 * post entirely and, since there's nothing left to track, deletes
	 * the row rather than updating it (the one way this differs from
	 * save_url()/convert()).
	 */
	public function test_remove_unwraps_the_link_and_deletes_the_row() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://www.a-dead-retailer.example/product">boots</a> now.</p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.a-dead-retailer.example/product',
				'anchor_text' => 'boots',
				'category'    => ALM_Install::CATEGORY_NONAFFILIATE,
				'modifier'    => ALM_Install::MODIFIER_DEAD,
			)
		);

		$result = $this->converter->remove( $item );
		$this->assertTrue( $result );

		$this->assertNull( $this->get_link_row( $item['id'] ), 'The tracking row must be gone -- nothing left to track once the link is out of the post.' );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringNotContainsString( '<a ', $fresh->post_content );
		$this->assertStringContainsString( 'Get the boots now.', $fresh->post_content );
	}

	/**
	 * A content-changed-since-scan refusal must leave both the post and
	 * the tracking row exactly as they were -- same "leave it alone and
	 * say why" convention as save_url()/convert().
	 */
	public function test_remove_leaves_everything_untouched_when_content_changed_since_the_last_scan() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.a-dead-retailer.example/product">boots</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.a-dead-retailer.example/different-product',
				'anchor_text' => 'boots',
				'category'    => ALM_Install::CATEGORY_NONAFFILIATE,
				'modifier'    => ALM_Install::MODIFIER_DEAD,
			)
		);

		$result = $this->converter->remove( $item );
		$this->assertInstanceOf( WP_Error::class, $result );

		$this->assertNotNull( $this->get_link_row( $item['id'] ), 'The tracking row must still exist.' );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'href="https://www.a-dead-retailer.example/product"', $fresh->post_content );
	}

	public function test_convert_to_an_unknown_provider_returns_an_error() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><a href="https://www.zara.com/product">tank top</a></p>',
			)
		);

		$item = $this->insert_link_row(
			array(
				'post_id'     => $post_id,
				'provider'    => 'unclassified',
				'adapter'     => 'post_content',
				'location'    => '0',
				'url'         => 'https://www.zara.com/product',
				'anchor_text' => 'tank top',
				'category'    => ALM_Install::CATEGORY_CANDIDATE,
			)
		);

		$result = $this->converter->convert( $item, 'not_a_real_provider' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * @param array $overrides
	 * @return array The freshly-inserted row, re-read from the database.
	 */
	private function insert_link_row( array $overrides ) {
		global $wpdb;

		$defaults = array(
			'classified_at' => current_time( 'mysql' ),
			'first_seen'    => current_time( 'mysql' ),
			'last_seen'     => current_time( 'mysql' ),
		);

		$wpdb->insert( ALM_Install::table_name(), array_merge( $defaults, $overrides ) );

		return $this->get_link_row( $wpdb->insert_id );
	}

	/**
	 * @param int $id
	 * @return array
	 */
	private function get_link_row( $id ) {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}
}
