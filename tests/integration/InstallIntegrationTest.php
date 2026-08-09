<?php
/**
 * @package ALM
 */

/**
 * @covers ALM_Install
 */
class InstallIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		ALM_Install::activate();
	}

	/**
	 * maybe_upgrade()'s self-healing check (does the domains table
	 * actually exist, not just "does the version option say it should")
	 * is real and was added after observing the exact broken state in
	 * practice on honestlywtf: the version option had already been
	 * bumped, but the table itself was never created, so every later
	 * boot's version check saw "already up to date" and never retried.
	 *
	 * Deliberately NOT tested here via DROP TABLE + re-run: DDL
	 * statements don't interact cleanly with WP_UnitTestCase's
	 * per-test transaction wrapping in this environment -- confirmed
	 * directly (a DROP TABLE reports success with no error, but a
	 * same-test SHOW TABLES immediately afterwards still finds the
	 * table). The fix itself was verified for real: reproduced the
	 * broken state directly against honestlywtf's actual database via
	 * wp-cli, confirmed maybe_upgrade() alone didn't recover it before
	 * this fix, and confirmed it does after.
	 */
	public function test_maybe_upgrade_is_a_cheap_no_op_when_everything_is_already_correct() {
		// Just confirms this doesn't throw/fatal on a second call in a
		// row against an already-fully-correct install -- the common
		// case, run on every single admin page load.
		ALM_Install::maybe_upgrade();
		ALM_Install::maybe_upgrade();

		global $wpdb;
		$domains_table = ALM_Install::domains_table_name();
		$exists        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $domains_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare().
		$this->assertSame( $domains_table, $exists );
	}
}
