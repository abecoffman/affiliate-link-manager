<?php
/**
 * Integration tests for ALM_Admin::handle_match_provider() -- the Edit
 * modal's live provider inference as the admin edits the URL field.
 * Previously had zero PHP-level coverage. No DB or network dependency
 * -- pure classification via ALM_Provider_Registry::match_url(), same
 * as the scanner itself uses.
 *
 * @package ALM
 */

/**
 * @covers ALM_Admin
 */
class MatchProviderIntegrationTest extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();

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

	public function test_a_recognized_network_url_matches_its_provider() {
		$_POST['url'] = 'https://go.shopmy.us/apx/sDXyBS?url=https://example.com/product';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_MATCH_PROVIDER_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'shopmy', $response['data']['id'] );
		$this->assertNotEmpty( $response['data']['label'] );
	}

	public function test_an_unrecognized_url_matches_the_generic_fallback_not_an_error() {
		$_POST['url'] = 'https://unrecognized-retailer.example.com/product';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_MATCH_PROVIDER_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'A URL matching nothing registered is a legitimate outcome ("Unaffiliated"), not an error.' );
		$this->assertSame( 'unclassified', $response['data']['id'] );
	}

	public function test_an_empty_url_is_rejected() {
		$_POST['url'] = '';

		try {
			$this->_handleAjax( ALM_Admin::AJAX_MATCH_PROVIDER_ACTION );
			$this->fail( 'Expected wp_die() to interrupt execution, same as a real AJAX error response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
	}
}
