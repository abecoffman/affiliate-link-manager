<?php
/**
 * Settings screen: network configuration and scan/classification
 * behavior, in one screen. Merged from what used to be two separate
 * top-level screens (Providers + Settings) -- with every registered
 * network being classify-only (recognized on sight, nothing to
 * configure; see each provider's own class docblock for why none of
 * them build a new tracked link), a whole separate menu item for that
 * didn't earn its keep. The Networks section below is read-only
 * display; only the Scan behavior section actually submits anything.
 *
 * ALM_Provider_Generic is deliberately excluded from $providers before
 * this ever runs (see ALM_Admin::render_settings()) -- it's a fallback
 * classification, not a real network with anything to show.
 *
 * @package ALM
 *
 * @var ALM_Provider[] $providers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$provider_labels = array();
foreach ( $providers as $provider ) {
	$provider_labels[] = $provider->get_label();
}
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Affiliate Links', 'affiliate-link-manager' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only decides whether to show a notice; the real nonce check already happened in the POST handler that redirected here. ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'affiliate-link-manager' ); ?></p></div>
	<?php endif; ?>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Networks', 'affiliate-link-manager' ); ?></h2>
			<p class="alm-card-lede"><?php esc_html_e( 'Affiliate networks this plugin recognizes.', 'affiliate-link-manager' ); ?></p>
		</div>

		<form method="post">
			<?php wp_nonce_field( ALM_Admin::SETTINGS_NONCE, 'alm_settings_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Recognized', 'affiliate-link-manager' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: %s: comma-separated list of network names */
								esc_html__( '%s -- classified automatically when found. This plugin does not build or edit tracked links for any network; generate the link on the network\'s own site and paste it in via a link\'s Edit modal to track it here.', 'affiliate-link-manager' ),
								esc_html( implode( ', ', $provider_labels ) )
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<div class="alm-card-header alm-card-header-secondary">
				<h2><?php esc_html_e( 'Scan behavior', 'affiliate-link-manager' ); ?></h2>
			</div>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="alm-excluded-domains"><?php esc_html_e( 'Additional excluded domains', 'affiliate-link-manager' ); ?></label></th>
					<td>
						<textarea id="alm-excluded-domains" name="alm_candidate_excluded_domains" rows="4" class="large-text code" placeholder="honestlyyum.com&#10;vogue.com&#10;architecturaldigest.com"><?php echo esc_textarea( get_option( 'alm_candidate_excluded_domains', '' ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One domain per line (or comma-separated). Links to these are never counted as affiliate-link candidates -- use this for domains only your site would know are noise: a sister site, or a magazine/reference site your posts frequently credit as an image source. Built-in defaults already exclude social platforms, WordPress/Google infrastructure, and this site\'s own links. Takes effect on the next scan.', 'affiliate-link-manager' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'affiliate-link-manager' ) ); ?>
		</form>
	</div>
</div>
