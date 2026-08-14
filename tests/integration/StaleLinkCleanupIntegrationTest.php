<?php
/**
 * Integration tests for the quiet stale-link cleanup piggybacked onto
 * the existing daily housekeeping cron (alm_run_domain_recheck_cron()
 * in the main plugin file) -- deletes tracking rows for links that are
 * status=stale AND were never confirmed dead, once they've sat
 * unresolved past a retention window. Real $wpdb needed to prove the
 * DELETE's WHERE scoping is actually correct -- never touches a
 * confirmed-dead row (that's the real, visible Dead Links flow), a
 * recently-stale row still within its grace period, or anything that
 * isn't status=stale at all.
 *
 * No domain-check/link-health candidates are ever inserted in these
 * tests, so alm_run_domain_recheck_cron()'s other two piggybacked jobs
 * have nothing pending and make no real outbound HTTP calls -- this
 * exercises the new cleanup step in isolation without needing to fake
 * responses for the other two.
 *
 * @package ALM
 */

/**
 * @covers ::alm_run_domain_recheck_cron
 */
class StaleLinkCleanupIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.
	}

	public function tear_down() {
		remove_all_filters( 'alm_stale_link_retention_days' );
		parent::tear_down();
	}

	/**
	 * @param array $overrides
	 * @return int Inserted row id.
	 */
	private function insert_link( array $overrides ) {
		global $wpdb;

		$defaults = array(
			'post_id'           => 1,
			'provider'          => 'unclassified',
			'adapter'           => 'post_content',
			'location'          => (string) wp_rand(),
			'url'               => 'https://example.com/product',
			'anchor_text'       => 'link',
			'status'            => ALM_Install::STATUS_STALE,
			'first_seen'        => current_time( 'mysql' ),
			'last_seen'         => current_time( 'mysql' ),
			'dead_confirmed_at' => null,
		);

		$wpdb->insert( ALM_Install::table_name(), array_merge( $defaults, $overrides ) );

		return (int) $wpdb->insert_id;
	}

	public function test_deletes_a_long_stale_never_confirmed_dead_row_past_the_retention_window() {
		$id = $this->insert_link(
			array(
				'status'    => ALM_Install::STATUS_STALE,
				'last_seen' => gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
			)
		);

		alm_run_domain_recheck_cron();

		$this->assertNull( $this->get_row( $id ), 'Past the 60-day default retention window -- must be deleted.' );
	}

	public function test_leaves_a_recently_stale_row_alone() {
		$id = $this->insert_link(
			array(
				'status'    => ALM_Install::STATUS_STALE,
				'last_seen' => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) ),
			)
		);

		alm_run_domain_recheck_cron();

		$this->assertNotNull( $this->get_row( $id ), 'Still well within the retention window -- must not be touched yet.' );
	}

	public function test_never_deletes_a_confirmed_dead_row_regardless_of_age() {
		$id = $this->insert_link(
			array(
				'status'            => ALM_Install::STATUS_STALE,
				'last_seen'         => gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) ),
				'dead_confirmed_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) ),
			)
		);

		alm_run_domain_recheck_cron();

		$this->assertNotNull( $this->get_row( $id ), 'A confirmed-dead row goes through the real, visible Dead Links flow -- this quiet cleanup must never touch it, no matter how old.' );
	}

	public function test_never_deletes_a_non_stale_row() {
		$id = $this->insert_link(
			array(
				'status'    => ALM_Install::STATUS_CONVERTIBLE,
				'last_seen' => gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) ),
			)
		);

		alm_run_domain_recheck_cron();

		$this->assertNotNull( $this->get_row( $id ) );
	}

	public function test_retention_window_is_filterable() {
		add_filter(
			'alm_stale_link_retention_days',
			function () {
				return 10;
			}
		);

		$id = $this->insert_link(
			array(
				'status'    => ALM_Install::STATUS_STALE,
				'last_seen' => gmdate( 'Y-m-d H:i:s', strtotime( '-20 days' ) ),
			)
		);

		alm_run_domain_recheck_cron();

		$this->assertNull( $this->get_row( $id ), 'Past the filtered 10-day window -- must be deleted even though it would have survived the 60-day default.' );
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	private function get_row( $id ) {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}
}
