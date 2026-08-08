<?php
/**
 * Settings screen: site-wide automation-policy toggles. Present in the
 * UI now but not yet acted on anywhere -- the automatic-linking engine
 * is a later phase of this plugin.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Affiliate Links', 'affiliate-link-manager' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only decides whether to show a notice; the real nonce check already happened in the POST handler that redirected here. ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'affiliate-link-manager' ); ?></p></div>
	<?php endif; ?>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Settings', 'affiliate-link-manager' ); ?></h2>
			<p class="alm-card-lede"><?php esc_html_e( 'Automation policy. Nothing here takes effect yet — these are saved for the upcoming convert/insert features.', 'affiliate-link-manager' ); ?></p>
		</div>

		<form method="post">
			<?php wp_nonce_field( ALM_Admin::SETTINGS_NONCE, 'alm_settings_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-convert', 'affiliate-link-manager' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="alm_auto_convert_unclassified" value="1" <?php checked( get_option( 'alm_auto_convert_unclassified' ), '1' ); ?>>
							<?php esc_html_e( 'Automatically convert eligible unclassified links when scanning (not active yet).', 'affiliate-link-manager' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'affiliate-link-manager' ) ); ?>
		</form>
	</div>
</div>
