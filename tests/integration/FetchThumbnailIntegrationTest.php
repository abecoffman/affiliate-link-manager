<?php
/**
 * Integration tests for ALM_Admin::handle_fetch_thumbnail() -- the Edit
 * modal's on-demand product-thumbnail fetch. Previously had zero
 * PHP-level coverage. Real network calls intercepted via WP core's own
 * `pre_http_request` filter, same pattern as
 * DomainScannerIntegrationTest.php.
 *
 * @package ALM
 */

/**
 * @covers ALM_Admin
 */
class FetchThumbnailIntegrationTest extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.

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

		$admin = new ALM_Admin( $scanner, $providers, $adapters, $domain_scanner, $converter, $network_signal_scanner, $shortener_scanner, $thumbnail_fetcher, $link_health_scanner, $dashboard_data );
		$admin->init();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_POST['nonce'] = wp_create_nonce( ALM_Admin::NONCE_ACTION );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * @param array $overrides
	 * @return int Inserted row id.
	 */
	private function insert_link( array $overrides = array() ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$defaults = array(
			'post_id'              => 1,
			'provider'             => 'unclassified',
			'adapter'              => 'post_content',
			'location'             => (string) wp_rand(),
			'url'                  => 'https://example.com/product',
			'anchor_text'          => 'link',
			'category'             => ALM_Install::CATEGORY_CANDIDATE,
			'classified_at'        => $now,
			'first_seen'           => $now,
			'last_seen'            => $now,
			'thumbnail_url'        => null,
			'thumbnail_fetched_at' => null,
		);

		$wpdb->insert( ALM_Install::table_name(), array_merge( $defaults, $overrides ) );

		return (int) $wpdb->insert_id;
	}

	public function test_a_page_with_an_og_image_returns_and_caches_the_thumbnail() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '<meta property="og:image" content="https://example.com/product.jpg">',
					'headers'  => array(),
				);
			}
		);

		$id = $this->insert_link();

		$_POST['id'] = $id;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_FETCH_THUMBNAIL_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'https://example.com/product.jpg', $response['data']['thumbnailUrl'] );

		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		$this->assertSame( 'https://example.com/product.jpg', $row['thumbnail_url'] );
		$this->assertNotNull( $row['thumbnail_fetched_at'], 'Must be marked as attempted so a later open never re-fetches.' );
	}

	public function test_a_page_with_no_og_image_caches_a_null_result() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '<html><body>no image here</body></html>',
					'headers'  => array(),
				);
			}
		);

		$id = $this->insert_link();

		$_POST['id'] = $id;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_FETCH_THUMBNAIL_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'No image found is a valid outcome, not an error.' );
		$this->assertNull( $response['data']['thumbnailUrl'] );

		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$fetched_at = $wpdb->get_var( $wpdb->prepare( "SELECT thumbnail_fetched_at FROM {$table} WHERE id = %d", $id ) );
		$this->assertNotNull( $fetched_at, 'Marked as attempted even on a null result, so it is never retried automatically.' );
	}

	public function test_an_already_fetched_link_returns_the_cached_value_without_a_new_request() {
		$request_count = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$request_count ) {
				++$request_count;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '<meta property="og:image" content="https://example.com/should-not-be-fetched.jpg">',
					'headers'  => array(),
				);
			}
		);

		$id = $this->insert_link(
			array(
				'thumbnail_url'        => 'https://example.com/already-cached.jpg',
				'thumbnail_fetched_at' => current_time( 'mysql' ),
			)
		);

		$_POST['id'] = $id;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_FETCH_THUMBNAIL_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertSame( 'https://example.com/already-cached.jpg', $response['data']['thumbnailUrl'] );
		$this->assertSame( 0, $request_count, 'The cached row is the source of truth -- no fresh fetch should have happened.' );
	}

	public function test_a_missing_link_id_is_rejected() {
		$_POST['id'] = 999999;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_FETCH_THUMBNAIL_ACTION );
			$this->fail( 'Expected wp_die() to interrupt execution, same as a real AJAX error response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
	}
}
