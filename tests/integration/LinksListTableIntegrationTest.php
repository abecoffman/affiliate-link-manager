<?php
/**
 * Integration tests for ALM_Links_List_Table's "Dead" tab -- the one
 * piece of this round's work that isn't covered by exercising a
 * Scanner/Checker class directly: the tab is a query-scope decision
 * (status = stale AND dead_confirmed_at IS NOT NULL) living in
 * get_views()/prepare_items(), and needs a real DB + real $_GET to
 * prove it actually draws that line correctly -- in particular that a
 * plain scan-swept stale link (dead_confirmed_at still NULL) never
 * shows up there. See ALM_Install::create_table()'s docblock for why
 * status=stale alone can't carry this distinction.
 *
 * @package ALM
 */

// The plugin only requires this file when is_admin() is true (see
// alm_init() in the main plugin file) -- not the case for a CLI
// PHPUnit run, same reason WP_List_Table itself needs its own
// class_exists() guard inside class-alm-links-list-table.php.
if ( ! class_exists( 'ALM_Links_List_Table' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/class-alm-links-list-table.php';
}

/**
 * @covers ALM_Links_List_Table
 */
class LinksListTableIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.
	}

	public function tear_down() {
		unset( $_GET['status'] );
		parent::tear_down();
	}

	/**
	 * @param string      $url
	 * @param string      $status
	 * @param string|null $dead_confirmed_at
	 * @return int Inserted row id.
	 */
	private function insert_link( $url, $status, $dead_confirmed_at = null ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			ALM_Install::table_name(),
			array(
				'post_id'           => 1,
				'provider'          => 'unclassified',
				'adapter'           => 'post_content',
				'location'          => (string) wp_rand(),
				'url'               => $url,
				'anchor_text'       => 'link',
				'status'            => $status,
				'first_seen'        => $now,
				'last_seen'         => $now,
				'dead_confirmed_at' => $dead_confirmed_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return ALM_Links_List_Table
	 */
	private function make_list_table() {
		$providers = new ALM_Provider_Registry();
		$adapters  = new ALM_Adapter_Registry();
		$converter = new ALM_Link_Converter( $providers, $adapters );

		return new ALM_Links_List_Table( $providers, $adapters, $converter );
	}

	public function test_dead_tab_only_shows_confirmed_dead_links() {
		$this->insert_link( 'https://confirmed-dead.example.com/a', ALM_Install::STATUS_STALE, current_time( 'mysql' ) );
		$this->insert_link( 'https://merely-swept-stale.example.com/b', ALM_Install::STATUS_STALE, null );
		$this->insert_link( 'https://still-a-candidate.example.com/c', ALM_Install::STATUS_CONVERTIBLE, null );

		$_GET['status'] = 'dead';

		$list_table = $this->make_list_table();
		$list_table->prepare_items();

		$this->assertCount( 1, $list_table->items, 'Only the confirmed-dead row belongs on the "Dead" tab.' );
		$this->assertSame( 'https://confirmed-dead.example.com/a', $list_table->items[0]['url'] );
	}

	public function test_dead_tab_is_empty_when_no_link_has_been_confirmed_dead() {
		$this->insert_link( 'https://merely-swept-stale.example.com/b', ALM_Install::STATUS_STALE, null );

		$_GET['status'] = 'dead';

		$list_table = $this->make_list_table();
		$list_table->prepare_items();

		$this->assertCount( 0, $list_table->items, 'A plain "not rediscovered" stale link is not the same as a confirmed-dead one.' );
	}

	public function test_get_views_dead_count_matches_confirmed_dead_rows_only() {
		$this->insert_link( 'https://confirmed-dead-1.example.com/a', ALM_Install::STATUS_STALE, current_time( 'mysql' ) );
		$this->insert_link( 'https://confirmed-dead-2.example.com/b', ALM_Install::STATUS_STALE, current_time( 'mysql' ) );
		$this->insert_link( 'https://merely-swept-stale.example.com/c', ALM_Install::STATUS_STALE, null );

		$views = $this->make_list_table()->get_views();

		$this->assertArrayHasKey( 'dead', $views, 'The "Dead Links" tab must appear once at least one link is confirmed dead.' );
		$this->assertStringContainsString( '(2)', $views['dead'], 'Count must reflect only the confirmed-dead rows, not every stale row.' );
	}

	public function test_get_views_omits_the_dead_tab_when_nothing_is_confirmed_dead() {
		$this->insert_link( 'https://merely-swept-stale.example.com/c', ALM_Install::STATUS_STALE, null );

		$views = $this->make_list_table()->get_views();

		$this->assertArrayNotHasKey( 'dead', $views, 'Same convention every other empty status tab already follows -- see get_views() docblock.' );
	}

	public function test_the_plain_stale_tab_still_includes_both_kinds_of_stale_link() {
		$this->insert_link( 'https://confirmed-dead.example.com/a', ALM_Install::STATUS_STALE, current_time( 'mysql' ) );
		$this->insert_link( 'https://merely-swept-stale.example.com/b', ALM_Install::STATUS_STALE, null );

		$_GET['status'] = ALM_Install::STATUS_STALE;

		$list_table = $this->make_list_table();
		$list_table->prepare_items();

		$this->assertCount( 2, $list_table->items, 'The "Stale" tab itself is untouched by this round -- still the broader, unfiltered bucket.' );
	}
}
