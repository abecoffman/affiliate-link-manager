<?php
/**
 * Integration tests for ALM_Admin::handle_edit_link() -- the Edit
 * modal's single-row save. Previously had zero PHP-level coverage
 * despite being the only way to manually correct a link's URL. Real
 * WP_Ajax_UnitTestCase-driven requests, same pattern as
 * RemoveLinkIntegrationTest.php.
 *
 * @package ALM
 */

/**
 * @covers ALM_Admin
 */
class EditLinkIntegrationTest extends WP_Ajax_UnitTestCase {

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

	/**
	 * @param int    $post_id
	 * @param string $url
	 * @return int Inserted row id.
	 */
	private function insert_link( $post_id, $url ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'       => $post_id,
				'provider'      => 'unclassified',
				'adapter'       => 'post_content',
				'location'      => '0',
				'url'           => $url,
				'anchor_text'   => 'link',
				'category'      => ALM_Install::CATEGORY_CANDIDATE,
				'classified_at' => $now,
				'first_seen'    => $now,
				'last_seen'     => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_a_valid_url_edit_persists_and_reclassifies() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://old-destination.example.com/product">boots</a> now.</p>',
			)
		);
		$id      = $this->insert_link( $post_id, 'https://old-destination.example.com/product' );

		$new_url      = 'https://go.shopmy.us/apx/sDXyBS?url=https://new-destination.example.com/product';
		$_POST['id']  = $id;
		$_POST['url'] = $new_url;

		try {
			$this->_handleAjax( ALM_Admin::AJAX_EDIT_LINK_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );

		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		$this->assertSame( $new_url, $row['url'] );
		$this->assertSame( 'shopmy', $row['provider'], 'A URL matching a real network gets relabeled from its own domain, not left unclassified.' );
		$this->assertSame( ALM_Install::CATEGORY_AFFILIATE, $row['category'] );

		$fresh = get_post( $post_id );
		$this->assertStringContainsString( $new_url, $fresh->post_content, 'The post content itself must reflect the new URL.' );
	}

	public function test_a_url_matching_no_network_is_saved_unaffiliated_not_rejected() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://old-destination.example.com/product">boots</a> now.</p>',
			)
		);
		$id      = $this->insert_link( $post_id, 'https://old-destination.example.com/product' );

		$_POST['id']  = $id;
		$_POST['url'] = 'https://unrecognized-retailer.example.com/product';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_EDIT_LINK_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'A URL matching no registered network is a legitimate outcome, not an error.' );

		global $wpdb;
		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$provider = $wpdb->get_var( $wpdb->prepare( "SELECT provider FROM {$table} WHERE id = %d", $id ) );
		$this->assertSame( 'unclassified', $provider );
	}

	public function test_an_empty_url_is_rejected() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://old-destination.example.com/product">boots</a> now.</p>',
			)
		);
		$id      = $this->insert_link( $post_id, 'https://old-destination.example.com/product' );

		$_POST['id']  = $id;
		$_POST['url'] = '';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_EDIT_LINK_ACTION );
			$this->fail( 'Expected wp_die() to interrupt execution, same as a real AJAX error response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
	}

	public function test_a_missing_link_id_is_rejected() {
		$_POST['id']  = 999999;
		$_POST['url'] = 'https://new-destination.example.com/product';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_EDIT_LINK_ACTION );
			$this->fail( 'Expected wp_die() to interrupt execution, same as a real AJAX error response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Link not found.', $response['data']['message'] );
	}
}
