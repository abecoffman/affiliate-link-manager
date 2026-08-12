<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \ALM_Background_Runner
 */
class BackgroundRunnerTest extends TestCase {

	public function test_get_state_returns_defaults_when_nothing_saved_yet() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'alm_link_health_run_state', array() )
			->andReturn( array() );

		$state = \ALM_Background_Runner::get_state( 'link_health' );

		$this->assertFalse( $state['active'] );
		$this->assertSame( 0, $state['cursor'] );
		$this->assertSame( 0, $state['processed'] );
		$this->assertSame( '', $state['started_at'] );
		$this->assertSame( 0, $state['reschedule_count'] );
		$this->assertFalse( $state['stalled'] );
	}

	public function test_get_state_merges_a_saved_partial_state_over_the_defaults() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'alm_domains_run_state', array() )
			->andReturn(
				array(
					'active'    => true,
					'processed' => 42,
				)
			);

		$state = \ALM_Background_Runner::get_state( 'domains' );

		$this->assertTrue( $state['active'] );
		$this->assertSame( 42, $state['processed'] );
		// Untouched keys still fall back to the defaults, not left unset.
		$this->assertSame( 0, $state['cursor'] );
		$this->assertFalse( $state['stalled'] );
	}

	public function test_get_state_falls_back_to_defaults_if_the_option_is_somehow_not_an_array() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'alm_scan_run_state', array() )
			->andReturn( 'not-an-array' );

		$state = \ALM_Background_Runner::get_state( 'scan' );

		$this->assertFalse( $state['active'] );
	}

	public function test_save_state_persists_without_autoloading() {
		$state = array(
			'active'           => true,
			'cursor'           => 100,
			'processed'        => 5,
			'started_at'       => '2026-08-12 10:00:00',
			'reschedule_count' => 1,
			'stalled'          => false,
		);

		Functions\expect( 'update_option' )
			->once()
			->with( 'alm_shorteners_run_state', $state, false );

		\ALM_Background_Runner::save_state( 'shorteners', $state );

		// The Functions\expect()->once() call above is the real
		// assertion (verified via Mockery::close() in tear_down(), a
		// mismatch would fail this test) -- this just satisfies
		// PHPUnit's own "no assertions performed" risky-test detector,
		// same reason on every void-return test below.
		$this->addToAssertionCount( 1 );
	}

	public function test_clear_state_deletes_the_option() {
		Functions\expect( 'delete_option' )
			->once()
			->with( 'alm_scan_run_state' );

		\ALM_Background_Runner::clear_state( 'scan' );

		$this->addToAssertionCount( 1 );
	}

	public function test_acquire_lock_succeeds_and_sets_a_transient_when_nothing_is_locked() {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'alm_link_health_batch_lock' )
			->andReturn( false );

		Functions\expect( 'set_transient' )
			->once()
			->with( 'alm_link_health_batch_lock', 1, \ALM_Background_Runner::LOCK_TTL );

		$this->assertTrue( \ALM_Background_Runner::acquire_lock( 'link_health' ) );
	}

	public function test_acquire_lock_fails_when_already_locked() {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'alm_domains_batch_lock' )
			->andReturn( 1 );

		Functions\expect( 'set_transient' )->never();

		$this->assertFalse( \ALM_Background_Runner::acquire_lock( 'domains' ) );
	}

	public function test_release_lock_deletes_the_transient() {
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'alm_scan_batch_lock' );

		\ALM_Background_Runner::release_lock( 'scan' );

		$this->addToAssertionCount( 1 );
	}

	public function test_schedule_next_tick_schedules_the_shared_hook_with_the_task_id_as_its_arg() {
		$before = time();

		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with(
				\Mockery::on(
					function ( $timestamp ) use ( $before ) {
						// time() itself isn't mockable here (same PHP-built-in
						// limitation documented for sleep() elsewhere in this
						// suite) -- asserting a tight real-time range instead
						// of an exact value is the reliable way to test this.
						return $timestamp >= $before + 8 && $timestamp <= time() + 8;
					}
				),
				\ALM_Background_Runner::CONTINUE_HOOK,
				array( 'domains' )
			);

		\ALM_Background_Runner::schedule_next_tick( 'domains', 8 );

		$this->addToAssertionCount( 1 );
	}

	public function test_unschedule_clears_the_shared_hook_scoped_to_this_task_id() {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( \ALM_Background_Runner::CONTINUE_HOOK, array( 'link_health' ) );

		\ALM_Background_Runner::unschedule( 'link_health' );

		$this->addToAssertionCount( 1 );
	}

	public function test_every_real_task_has_a_batch_size_and_reschedule_delay_configured() {
		$task_ids = array( 'scan', 'domains', 'shorteners', 'link_health' );

		foreach ( $task_ids as $task_id ) {
			$this->assertArrayHasKey( $task_id, \ALM_Background_Runner::TASK_BATCH_SIZES );
			$this->assertGreaterThan( 0, \ALM_Background_Runner::TASK_BATCH_SIZES[ $task_id ] );
			$this->assertArrayHasKey( $task_id, \ALM_Background_Runner::TASK_RESCHEDULE_DELAYS );
			$this->assertGreaterThan( 0, \ALM_Background_Runner::TASK_RESCHEDULE_DELAYS[ $task_id ] );
		}
	}
}
