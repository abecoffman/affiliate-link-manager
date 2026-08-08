<?php
/**
 * Dashboard screen: total links found, breakdown by provider, last scan
 * time, and the Run Scan control.
 *
 * @package ALM
 *
 * @var array<string,array{label:string,count:int}> $stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
						<td><span class="alm-badge alm-badge-<?php echo esc_attr( $provider_id ); ?>"><?php echo esc_html( $row['label'] ); ?></span></td>
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
</div>
