<?php
/**
 * Dashboard screen: total links found, breakdown by provider (each
 * linking into Links filtered to it), a "Needs attention" panel for
 * unclassified/stale counts, the last-scan delta, and the Run Scan
 * control.
 *
 * @package ALM
 *
 * @var array<string,array{label:string,count:int}> $stats
 * @var array<string,int>                            $needs_attention
 * @var array{new_links?:int,now_stale?:int}          $scan_delta
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

$last_scan = get_option( 'alm_last_scan_time', '' );
$total     = array_sum( wp_list_pluck( $stats, 'count' ) );
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Affiliate Links', 'affiliate-link-manager' ); ?></h1>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Overview', 'affiliate-link-manager' ); ?></h2>
			<p class="alm-card-lede">
				<?php
				if ( $last_scan ) {
					printf(
						/* translators: %s: date/time of the last scan */
						esc_html__( 'Last scanned %s.', 'affiliate-link-manager' ),
						esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) )
					);

					if ( isset( $scan_delta['new_links'] ) ) {
						echo ' ';
						printf(
							/* translators: 1: number of new links found, 2: number of links that went stale */
							esc_html__( '%1$d new, %2$d now stale.', 'affiliate-link-manager' ),
							(int) $scan_delta['new_links'],
							(int) $scan_delta['now_stale']
						);
					}
				} else {
					esc_html_e( 'No scan has run yet.', 'affiliate-link-manager' );
				}
				?>
			</p>
		</div>

		<p class="alm-total-count">
			<?php
			printf(
				/* translators: %d: total number of links found */
				esc_html(
					/* translators: %d: total number of links found */
					_n( '%d link found', '%d links found', $total, 'affiliate-link-manager' )
				),
				(int) $total
			);
			?>
		</p>

		<?php if ( ! empty( $stats ) ) : ?>
			<table class="alm-provider-breakdown">
				<tbody>
				<?php foreach ( $stats as $provider_id => $row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $alm_links_url( array( 'provider' => $provider_id ) ) ); ?>">
								<span class="alm-badge alm-badge-<?php echo esc_attr( $provider_id ); ?>"><?php echo esc_html( $row['label'] ); ?></span>
							</a>
						</td>
						<td><?php echo esc_html( $row['count'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p>
			<button type="button" class="button button-primary" id="alm-run-scan"><?php esc_html_e( 'Run Scan', 'affiliate-link-manager' ); ?></button>
			<span id="alm-scan-progress" class="alm-scan-progress" hidden></span>
		</p>
	</div>

	<?php if ( $needs_attention[ ALM_Install::STATUS_UNCLASSIFIED ] > 0 || $needs_attention[ ALM_Install::STATUS_STALE ] > 0 ) : ?>
		<div class="alm-card">
			<div class="alm-card-header">
				<h2><?php esc_html_e( 'Needs attention', 'affiliate-link-manager' ); ?></h2>
				<p class="alm-card-lede"><?php esc_html_e( 'Links worth a look -- not classified by a known provider, or no longer found on the last scan.', 'affiliate-link-manager' ); ?></p>
			</div>

			<table class="alm-provider-breakdown">
				<tbody>
					<?php if ( $needs_attention[ ALM_Install::STATUS_UNCLASSIFIED ] > 0 ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::STATUS_UNCLASSIFIED ) ) ); ?>">
									<span class="alm-badge alm-badge-status-unclassified"><?php esc_html_e( 'Unclassified', 'affiliate-link-manager' ); ?></span>
								</a>
							</td>
							<td><?php echo esc_html( $needs_attention[ ALM_Install::STATUS_UNCLASSIFIED ] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $needs_attention[ ALM_Install::STATUS_STALE ] > 0 ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::STATUS_STALE ) ) ); ?>">
									<span class="alm-badge alm-badge-status-stale"><?php esc_html_e( 'Stale', 'affiliate-link-manager' ); ?></span>
								</a>
							</td>
							<td><?php echo esc_html( $needs_attention[ ALM_Install::STATUS_STALE ] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
