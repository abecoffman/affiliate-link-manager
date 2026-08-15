<?php
/**
 * Dashboard screen: a one-sentence framing of what this plugin does,
 * an Overview stat grid showing the current state (Affiliate Links /
 * Candidate Affiliate Links / Dead Links when present, plus a
 * per-network breakdown), and a Tasks table -- one row per background operation
 * (Scan, Check Domains, Expand Shortened Links), each shaped
 * identically: what it does, what happened last time it ran, how much
 * is left, one button. Replaces an earlier version where those three
 * actions lived in separately-shaped cards with asymmetric feedback
 * (only Scan said what it had last accomplished) -- see
 * ALM_Dashboard_Data::get_dashboard_tasks() for where "last run"
 * phrasing comes from, now computed the same way for all three.
 *
 * @package ALM
 *
 * @var array<string,array{label:string,count:int}> $stats
 * @var array<string,int>                            $category_summary
 * @var int                                           $dead_count
 * @var array<string,array{label:string,count:int,sample_url:string}> $network_signals
 * @var array<int,array{id:string,label:string,description:string,last_run:string,pending:int|null,button_id:string,progress_id:string,button_label:string,primary:bool,running:bool,processed_so_far:int,stalled:bool}> $tasks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A local closure, not a global function -- this file is a plain
// require()'d template, and a global function here would fatal with
// "cannot redeclare" if the Dashboard were ever rendered twice in one
// request.
$alm_links_url = static function ( $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => ALM_Admin::MENU_SLUG . '-links' ), $args ),
		admin_url( 'admin.php' )
	);
};
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Affiliate Links', 'affiliate-link-manager' ); ?></h1>
	<p class="alm-page-intro"><?php esc_html_e( 'Finds links in your posts, flags the ones that could be affiliate links, and keeps that classification accurate as your content changes.', 'affiliate-link-manager' ); ?></p>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Overview', 'affiliate-link-manager' ); ?></h2>
		</div>

		<div class="alm-stat-grid">
			<a class="alm-stat-tile alm-stat-tile-active" href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::CATEGORY_AFFILIATE ) ) ); ?>">
				<span class="alm-stat-tile-number"><?php echo esc_html( $category_summary[ ALM_Install::CATEGORY_AFFILIATE ] ); ?></span>
				<span class="alm-stat-tile-label"><?php echo esc_html( ALM_Links_List_Table::tab_label( ALM_Install::CATEGORY_AFFILIATE ) ); ?></span>
			</a>
			<a class="alm-stat-tile alm-stat-tile-convertible" href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::CATEGORY_CANDIDATE ) ) ); ?>">
				<span class="alm-stat-tile-number"><?php echo esc_html( $category_summary[ ALM_Install::CATEGORY_CANDIDATE ] ); ?></span>
				<span class="alm-stat-tile-label"><?php echo esc_html( ALM_Links_List_Table::tab_label( ALM_Install::CATEGORY_CANDIDATE ) ); ?></span>
			</a>
			<?php if ( $dead_count > 0 ) : ?>
				<a class="alm-stat-tile alm-stat-tile-dead" href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::MODIFIER_DEAD ) ) ); ?>">
					<span class="alm-stat-tile-number"><?php echo esc_html( $dead_count ); ?></span>
					<span class="alm-stat-tile-label"><?php echo esc_html( ALM_Links_List_Table::tab_label( ALM_Install::MODIFIER_DEAD ) ); ?></span>
				</a>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $stats ) ) : ?>
			<div class="alm-chip-section">
				<p class="alm-chip-section-label"><?php esc_html_e( 'By network', 'affiliate-link-manager' ); ?></p>
				<div class="alm-chip-row">
					<?php foreach ( $stats as $provider_id => $row ) : ?>
						<a href="<?php echo esc_url( $alm_links_url( array( 'provider' => $provider_id ) ) ); ?>">
							<span class="alm-badge alm-badge-<?php echo esc_attr( $provider_id ); ?>"><?php echo esc_html( $row['label'] ); ?> <?php echo esc_html( $row['count'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<p class="alm-other-outbound-count">
			<?php
			printf(
				/* translators: 1: label "Other Outbound Links", 2: count */
				esc_html__( '%1$s: %2$d -- not shown individually; these are internal navigation, social/embed links, and other content that will never be an affiliate opportunity.', 'affiliate-link-manager' ),
				esc_html( ALM_Links_List_Table::tab_label( ALM_Install::CATEGORY_NONAFFILIATE ) ),
				(int) $category_summary[ ALM_Install::CATEGORY_NONAFFILIATE ]
			);
			?>
		</p>
	</div>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Tasks', 'affiliate-link-manager' ); ?></h2>
			<p class="alm-card-lede"><?php esc_html_e( 'These keep the numbers above accurate as your site changes. Every task shows what it found the last time it ran.', 'affiliate-link-manager' ); ?></p>
		</div>

		<div class="alm-table-scroll">
			<table class="widefat striped alm-tasks-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Task', 'affiliate-link-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last run', 'affiliate-link-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pending', 'affiliate-link-manager' ); ?></th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tasks as $task ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $task['label'] ); ?></strong>
								<p class="description"><?php echo esc_html( $task['description'] ); ?></p>
							</td>
							<td><?php echo esc_html( $task['last_run'] ); ?></td>
							<td><?php echo null === $task['pending'] ? '—' : esc_html( $task['pending'] ); ?></td>
							<td>
								<?php
								// running deliberately overrides the "0 pending" done-state
								// here -- count_pending() is live and can transiently read 0
								// right as a still-active run's last batch lands, before its
								// own run-state has been cleared; the button/progress must
								// keep showing in that split second, not flash "All caught up"
								// and then immediately flash again to idle on the next load.
								?>
								<?php if ( null !== $task['pending'] && 0 === $task['pending'] && ! $task['running'] ) : ?>
									<span class="alm-task-done"><?php esc_html_e( 'All caught up', 'affiliate-link-manager' ); ?></span>
								<?php else : ?>
									<button type="button" class="button<?php echo $task['primary'] ? ' button-primary' : ''; ?>" id="<?php echo esc_attr( $task['button_id'] ); ?>"><?php echo esc_html( $task['button_label'] ); ?></button>
								<?php endif; ?>
								<?php if ( $task['stalled'] ) : ?>
									<span id="<?php echo esc_attr( $task['progress_id'] ); ?>" class="alm-scan-progress alm-scan-progress-wrap alm-scan-progress-stalled">
										<?php esc_html_e( 'Paused after an unusually long run -- click to pick back up.', 'affiliate-link-manager' ); ?>
									</span>
								<?php elseif ( $task['running'] ) : ?>
									<?php
									// Server-rendered on a fresh page load, not left waiting on
									// JS -- this is the direct fix for a run that was actually
									// still making real progress via alm_continue_batch_run()
									// looking abandoned just because the tab that started it
									// was closed. See ALM_Background_Runner.
									//
									// alm-scan-progress-wrap, not the base class's nowrap: this
									// is a full sentence, not a short "(N / M)" counter -- nowrap
									// inside this column's fixed 30% width would overflow/clip
									// instead of wrapping, the same class of bug already found
									// and fixed once this session for the JS-driven text.
									?>
									<span id="<?php echo esc_attr( $task['progress_id'] ); ?>" class="alm-scan-progress alm-scan-progress-wrap">
										<?php
										printf(
											/* translators: %d: number of items this still-running background task has processed so far */
											esc_html__( '%d processed so far — still running in the background.', 'affiliate-link-manager' ),
											(int) $task['processed_so_far']
										);
										?>
									</span>
								<?php else : ?>
									<span id="<?php echo esc_attr( $task['progress_id'] ); ?>" class="alm-scan-progress" hidden></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( ! empty( $network_signals ) ) : ?>
		<p class="alm-network-signals-note">
			<?php
			$signal_summaries = array();
			foreach ( $network_signals as $signal_domain => $signal ) {
				$signal_summaries[] = sprintf( '%s (%s: %d)', $signal['label'], $signal_domain, $signal['count'] );
			}
			printf(
				/* translators: %s: comma-separated list of "Network label (domain: count)" */
				esc_html__( 'Some links go through a redirect domain that belongs to a real affiliate network this plugin doesn\'t recognize yet: %s. Nothing to do here directly -- worth passing along if you\'d like one added.', 'affiliate-link-manager' ),
				esc_html( implode( ', ', $signal_summaries ) )
			);
			?>
		</p>
	<?php endif; ?>
</div>
