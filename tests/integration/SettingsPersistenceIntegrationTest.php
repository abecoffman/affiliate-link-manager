<?php
/**
 * Integration tests for the Settings screen's persistence
 * (ALM_Admin::persist_settings_from_request(), dispatched by
 * save_settings()/handle_settings_forms()) -- previously had zero
 * PHP-level coverage despite this being the only functional part of
 * the Settings screen. Confirms the ShopMy affiliate/collection ID
 * fields and the excluded-domains textarea actually persist to
 * options, closing the gap a stale project note once described as a
 * "dead settings toggle" (removed in an earlier round; the rest of
 * this screen has been fully wired ever since).
 *
 * persist_settings_from_request() is called directly via reflection
 * rather than through save_settings() itself, which always ends in
 * wp_safe_redirect()+exit on success -- same reasoning as
 * ALM_Links_List_Table::bulk_remove()'s own test, see that method's
 * docblock. handle_settings_forms() (the real entry point, hooked to
 * admin_init) is exercised directly for the one path that never
 * reaches that exit: no nonce posted at all.
 *
 * @package ALM
 */

/**
 * @covers ALM_Admin
 */
class SettingsPersistenceIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Admin
	 */
	private $admin;

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

		$this->admin = new ALM_Admin( $scanner, $providers, $adapters, $domain_scanner, $converter, $network_signal_scanner, $shortener_scanner, $thumbnail_fetcher, $link_health_scanner, $dashboard_data );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function tear_down() {
		unset( $_POST['alm_shopmy_affiliate_id'], $_POST['alm_shopmy_collection_id'], $_POST['alm_candidate_excluded_domains'], $_POST['alm_settings_nonce'] );
		delete_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID );
		delete_option( ALM_Provider_ShopMy::OPTION_COLLECTION_ID );
		delete_option( 'alm_candidate_excluded_domains' );
		parent::tear_down();
	}

	/**
	 * @return void
	 */
	private function invoke_persist() {
		$reflection = new ReflectionMethod( $this->admin, 'persist_settings_from_request' );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$reflection->invoke( $this->admin );
	}

	public function test_shopmy_affiliate_id_persists() {
		$_POST['alm_shopmy_affiliate_id'] = 'sDXyBS';

		$this->invoke_persist();

		$this->assertSame( 'sDXyBS', get_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID ) );
	}

	public function test_shopmy_collection_id_persists() {
		$_POST['alm_shopmy_collection_id'] = '123';

		$this->invoke_persist();

		$this->assertSame( '123', get_option( ALM_Provider_ShopMy::OPTION_COLLECTION_ID ) );
	}

	public function test_excluded_domains_textarea_persists() {
		$_POST['alm_candidate_excluded_domains'] = "sisterblog.example\nmagazine.example";

		$this->invoke_persist();

		$this->assertSame( "sisterblog.example\nmagazine.example", get_option( 'alm_candidate_excluded_domains' ) );
	}

	public function test_fields_are_sanitized_on_the_way_in() {
		$_POST['alm_shopmy_affiliate_id'] = '<script>alert(1)</script>sDXyBS';

		$this->invoke_persist();

		$this->assertStringNotContainsString( '<script>', get_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID ) );
	}

	public function test_a_field_absent_from_the_request_is_left_untouched() {
		update_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID, 'already-set' );

		// Only posting the excluded-domains field this time.
		$_POST['alm_candidate_excluded_domains'] = 'magazine.example';

		$this->invoke_persist();

		$this->assertSame( 'already-set', get_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID ), 'A field the form did not submit must not be reset.' );
	}

	public function test_handle_settings_forms_does_nothing_without_a_posted_nonce() {
		update_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID, 'unchanged' );
		$_POST['alm_shopmy_affiliate_id'] = 'should-not-be-saved';
		// Deliberately no alm_settings_nonce posted -- handle_settings_forms()
		// must never even attempt a save, and therefore never reach
		// save_settings()'s wp_safe_redirect()+exit tail, so this is safe
		// to call directly through the real, unmocked entry point.
		$this->admin->handle_settings_forms();

		$this->assertSame( 'unchanged', get_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID ) );
	}
}
