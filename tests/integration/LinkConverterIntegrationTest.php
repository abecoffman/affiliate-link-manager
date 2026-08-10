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
				'status'      => 'convertible',
			)
		);

		$result = $this->converter->convert( $item, 'shopmy' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'shopmy', $row['provider'] );
		$this->assertSame( 'active', $row['status'] );
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
				'status'      => 'convertible',
			)
		);

		$result = $this->converter->convert( $item, 'rewardstyle' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'rewardstyle', $row['provider'] );
		$this->assertSame( 'active', $row['status'] );
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
				'status'      => 'convertible',
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
		$this->assertSame( 'convertible', $row['status'] );
		$this->assertSame( 'https://www.zara.com/product', $row['url'] );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'href="https://www.zara.com/different-product"', $fresh->post_content, 'The real, current post content must be untouched.' );
	}

	/**
	 * The other half of the Edit modal: an explicit replacement URL --
	 * e.g. a real link the admin already generated on RewardStyle's own
	 * site and is pasting in -- is written verbatim, no wrap_url()
	 * involved. This is the *only* way to attach a tracked link for a
	 * provider that can't build one itself.
	 */
	public function test_convert_writes_an_explicit_url_verbatim_for_a_non_wrapping_provider() {
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
				'status'      => 'convertible',
			)
		);

		$pasted_url = 'https://rstyle.me/+manually-generated-abc123';
		$result     = $this->converter->convert( $item, 'rewardstyle', $pasted_url );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'rewardstyle', $row['provider'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( $pasted_url, $row['url'] );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertStringContainsString( 'href="' . $pasted_url . '"', $fresh->post_content );
	}

	/**
	 * An explicit URL always wins over auto-generation, even when the
	 * selected provider *can* wrap -- editing the URL field is a
	 * deliberate override, not a suggestion wrap_url() second-guesses.
	 */
	public function test_convert_prefers_an_explicit_url_over_wrapping_even_for_a_wrap_capable_provider() {
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
				'status'      => 'convertible',
			)
		);

		$pasted_url = 'https://go.shopmy.us/p-already-generated-manually';
		$result     = $this->converter->convert( $item, 'shopmy', $pasted_url );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertSame( 'shopmy', $row['provider'] );
		$this->assertSame( $pasted_url, $row['url'], 'wrap_url() must not have run -- the pasted URL is used exactly as given.' );
	}

	/**
	 * Submitting a URL that happens to equal what's already stored must
	 * behave exactly like not submitting one at all (auto-generate /
	 * reclassify, per the target provider) -- the "was this actually
	 * edited?" check is a real equality test, not "was the field present."
	 */
	public function test_convert_treats_an_unchanged_submitted_url_the_same_as_no_url() {
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
				'status'      => 'convertible',
			)
		);

		$result = $this->converter->convert( $item, 'shopmy', 'https://www.zara.com/product' );
		$this->assertTrue( $result );

		$row = $this->get_link_row( $item['id'] );
		$this->assertStringContainsString( 'go.shopmy.us/apx/sDXyBS', $row['url'], 'An unchanged submitted URL should still trigger wrap_url(), same as submitting none.' );
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
				'status'      => 'convertible',
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
			'first_seen' => current_time( 'mysql' ),
			'last_seen'  => current_time( 'mysql' ),
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
