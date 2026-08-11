<?php
/**
 * Dashboard screen: the three-tier headline (Affiliate Links / Candidate
 * Affiliate Links / Other Outbound Links -- the last one deliberately
 * just a summary number, never a browsable list), a per-network
 * sub-breakdown for real Affiliate Links, a "Needs attention" panel,
 * the last-scan delta, and the Run Scan / Check Domains controls.
 *
 * @package ALM
 *
 * @var array<string,array{label:string,count:int}> $stats
 * @var array<string,int>                            $status_summary
 * @var array<string,int>                            $needs_attention
 * @var array{new_links?:int,now_stale?:int}          $scan_delta
 * @var array{checked:int,pending:int,confirmed_shops:int,confirmed_noise:int} $domain_check
 * @var array<string,array{label:string,count:int,sample_url:string}> $network_signals
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

		<table class="alm-provider-breakdown alm-status-headline">
			<tbody>
				<tr>
					<td>
						<a href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::STATUS_ACTIVE ) ) ); ?>">
							<span class="alm-badge alm-badge-status-active"><?php echo esc_html( ALM_Links_List_Table::status_label( ALM_Install::STATUS_ACTIVE, true ) ); ?></span>
						</a>
					</td>
					<td><?php echo esc_html( $status_summary[ ALM_Install::STATUS_ACTIVE ] ); ?></td>
				</tr>
				<tr>
					<td>
						<a href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::STATUS_CONVERTIBLE ) ) ); ?>">
							<span class="alm-badge alm-badge-status-convertible"><?php echo esc_html( ALM_Links_List_Table::status_label( ALM_Install::STATUS_CONVERTIBLE, true ) ); ?></span>
						</a>
					</td>
					<td><?php echo esc_html( $status_summary[ ALM_Install::STATUS_CONVERTIBLE ] ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $stats ) ) : ?>
			<table class="alm-provider-breakdown alm-provider-sub-breakdown">
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

		<p class="alm-other-outbound-count">
			<?php
			printf(
				/* translators: 1: label "Other Outbound Links", 2: count */
				esc_html__( '%1$s: %2$d -- not shown individually; these are internal navigation, social/embed links, and other content that will never be an affiliate opportunity.', 'affiliate-link-manager' ),
				esc_html( ALM_Links_List_Table::status_label( ALM_Install::STATUS_UNCLASSIFIED, true ) ),
				(int) $status_summary[ ALM_Install::STATUS_UNCLASSIFIED ]
			);
			?>
		</p>

		<p>
			<button type="button" class="button button-primary" id="alm-run-scan"><?php esc_html_e( 'Run Scan', 'affiliate-link-manager' ); ?></button>
			<span id="alm-scan-progress" class="alm-scan-progress" hidden></span>
		</p>
	</div>

	<?php if ( $needs_attention[ ALM_Install::STATUS_CONVERTIBLE ] > 0 || $needs_attention[ ALM_Install::STATUS_STALE ] > 0 ) : ?>
		<div class="alm-card">
			<div class="alm-card-header">
				<h2><?php esc_html_e( 'Needs attention', 'affiliate-link-manager' ); ?></h2>
				<p class="alm-card-lede"><?php esc_html_e( 'Likely affiliate-link opportunities, and links no longer found on the last scan -- not the full Other Outbound Links pile, which is mostly navigation and social noise.', 'affiliate-link-manager' ); ?></p>
			</div>

			<table class="alm-provider-breakdown">
				<tbody>
					<?php if ( $needs_attention[ ALM_Install::STATUS_CONVERTIBLE ] > 0 ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::STATUS_CONVERTIBLE ) ) ); ?>">
									<span class="alm-badge alm-badge-status-convertible"><?php echo esc_html( ALM_Links_List_Table::status_label( ALM_Install::STATUS_CONVERTIBLE, true ) ); ?></span>
								</a>
							</td>
							<td><?php echo esc_html( $needs_attention[ ALM_Install::STATUS_CONVERTIBLE ] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $needs_attention[ ALM_Install::STATUS_STALE ] > 0 ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $alm_links_url( array( 'status' => ALM_Install::STATUS_STALE ) ) ); ?>">
									<span class="alm-badge alm-badge-status-stale"><?php echo esc_html( ALM_Links_List_Table::status_label( ALM_Install::STATUS_STALE ) ); ?></span>
								</a>
							</td>
							<td><?php echo esc_html( $needs_attention[ ALM_Install::STATUS_STALE ] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Domain content check', 'affiliate-link-manager' ); ?></h2>
			<p class="alm-card-lede"><?php esc_html_e( 'Fetches one real page per candidate domain and looks for actual e-commerce signals (product schema, shop-platform fingerprints) instead of guessing from the domain name. Confirmed non-shops move back to Other Outbound Links automatically -- this is what keeps the Candidate Affiliate Links list accurate without anyone maintaining a list of known sites by hand.', 'affiliate-link-manager' ); ?></p>
		</div>

		<p class="alm-total-count">
			<?php
			/* translators: 1: number of domains checked so far, 2: confirmed real shops, 3: confirmed not shops */
			$domain_count_format = _n(
				'%1$d domain checked so far (%2$d confirmed shops, %3$d confirmed not).',
				'%1$d domains checked so far (%2$d confirmed shops, %3$d confirmed not).',
				$domain_check['checked'],
				'affiliate-link-manager'
			);
			printf(
				esc_html( $domain_count_format ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() applied above; the sniff can't see through the intermediate variable.
				(int) $domain_check['checked'],
				(int) $domain_check['confirmed_shops'],
				(int) $domain_check['confirmed_noise']
			);
			?>
		</p>

		<?php if ( $domain_check['pending'] > 0 ) : ?>
			<p>
				<button type="button" class="button button-primary" id="alm-check-domains">
					<?php
					printf(
						/* translators: %d: number of domains waiting to be checked */
						esc_html__( 'Check Domains (%d pending)', 'affiliate-link-manager' ),
						(int) $domain_check['pending']
					);
					?>
				</button>
				<span id="alm-domain-check-progress" class="alm-scan-progress" hidden></span>
			</p>
		<?php else : ?>
			<p class="alm-card-lede"><?php esc_html_e( 'All candidate domains are checked and up to date.', 'affiliate-link-manager' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $network_signals ) ) : ?>
		<div class="alm-card">
			<div class="alm-card-header">
				<h2><?php esc_html_e( 'Possible unrecognized networks', 'affiliate-link-manager' ); ?></h2>
				<p class="alm-card-lede"><?php esc_html_e( 'Links through a redirect domain known to belong to a real affiliate network this plugin does not have a provider for yet (see ALM_Network_Signal_Scanner) -- worth building support for, the same way ShopMy, RewardStyle, Amazon, CJ, Rakuten, and ShopStyle already are.', 'affiliate-link-manager' ); ?></p>
			</div>

			<table class="alm-provider-breakdown">
				<tbody>
					<?php foreach ( $network_signals as $signal_domain => $signal ) : ?>
						<tr>
							<td><?php echo esc_html( $signal['label'] ); ?> (<?php echo esc_html( $signal_domain ); ?>)</td>
							<td><?php echo esc_html( $signal['count'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
