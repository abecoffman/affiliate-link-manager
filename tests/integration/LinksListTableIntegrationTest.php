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

		$this->assertCount( 2, $list_table->items, 'A direct ?status=stale URL (no longer linked from anywhere) still matches the raw status literally -- low priority, not part of any user-facing flow anymore.' );
	}

	/**
	 * "Stale" is no longer a leaked implementation detail anywhere a
	 * user actually browses -- see get_views()'s own docblock. A
	 * merely-not-rediscovered, never-confirmed-dead row is pure
	 * background housekeeping (cleaned up quietly by cron), never
	 * something to review.
	 */
	public function test_all_tab_excludes_merely_stale_non_dead_rows_but_keeps_confirmed_dead_ones() {
		$this->insert_link( 'https://confirmed-dead.example.com/a', ALM_Install::STATUS_STALE, current_time( 'mysql' ) );
		$this->insert_link( 'https://merely-swept-stale.example.com/b', ALM_Install::STATUS_STALE, null );
		$this->insert_link( 'https://still-a-candidate.example.com/c', ALM_Install::STATUS_CONVERTIBLE, null );

		$list_table = $this->make_list_table();
		$list_table->prepare_items();

		$urls = wp_list_pluck( $list_table->items, 'url' );
		$this->assertContains( 'https://confirmed-dead.example.com/a', $urls );
		$this->assertContains( 'https://still-a-candidate.example.com/c', $urls );
		$this->assertNotContains( 'https://merely-swept-stale.example.com/b', $urls, 'A merely not-rediscovered, never-confirmed-dead row must never show up in "All" either.' );
	}

	public function test_nav_tabs_no_longer_include_a_bare_stale_entry() {
		$this->insert_link( 'https://confirmed-dead.example.com/a', ALM_Install::STATUS_STALE, current_time( 'mysql' ) );
		$this->insert_link( 'https://merely-swept-stale.example.com/b', ALM_Install::STATUS_STALE, null );

		$views = $this->make_list_table()->get_views();

		$this->assertArrayNotHasKey( ALM_Install::STATUS_STALE, $views, '"Stale" is not a browsable nav tab anymore -- only "Dead Links" (its confirmed-dead slice) is.' );
		$this->assertArrayHasKey( 'dead', $views );
	}

	/**
	 * Real correctness bug found live: bulk_remove() (dispatched by
	 * process_bulk_action() for the remove_dead_links action) used to
	 * gate only on status=stale, even though "Only a confirmed-dead
	 * link can be removed this way" was already the claimed behavior.
	 * A mixed selection must only ever actually remove the genuinely
	 * confirmed-dead row.
	 *
	 * Calls the private bulk_remove() directly via reflection rather
	 * than the full process_bulk_action() -- that method always ends
	 * in wp_safe_redirect()+exit on success (a real request's normal
	 * post-action flow), which has nothing to do with the gating logic
	 * itself and can't run headers-already-sent inside a CLI test
	 * process. See AjaxBatchRunIntegrationTest/RemoveLinkIntegrationTest
	 * for the equivalent coverage through a real request for the
	 * *other* entry point (the single-row AJAX handler).
	 */
	public function test_bulk_remove_dead_links_only_processes_confirmed_dead_rows() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Get the <a href="https://confirmed-dead.example.com/a">boots</a> now.</p>',
			)
		);

		$dead_id = $this->insert_link( 'https://confirmed-dead.example.com/a', ALM_Install::STATUS_STALE, current_time( 'mysql' ) );
		global $wpdb;
		$wpdb->update( ALM_Install::table_name(), array( 'post_id' => $post_id, 'location' => '0' ), array( 'id' => $dead_id ) );

		$merely_stale_id = $this->insert_link( 'https://merely-swept-stale.example.com/b', ALM_Install::STATUS_STALE, null );

		$list_table = $this->make_list_table();
		// No setAccessible() call -- deprecated since PHP 8.1, reflection
		// methods are accessible by default from that version on.
		$reflection = new ReflectionMethod( $list_table, 'bulk_remove' );
		$reflection->invoke( $list_table, array( $dead_id, $merely_stale_id ) );

		$table = ALM_Install::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$remaining_ids = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$table}" ) );

		$this->assertNotContains( $dead_id, $remaining_ids, 'The confirmed-dead row was actually removed.' );
		$this->assertContains( $merely_stale_id, $remaining_ids, 'The merely-stale, never-confirmed-dead row must be skipped, not removed -- it has nothing to unwrap and was never confirmed dead.' );
	}
}
