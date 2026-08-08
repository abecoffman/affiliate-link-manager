<?php
/**
 * Providers screen: enable/configure each registered affiliate network
 * provider.
 *
 * @package ALM
 *
 * @var ALM_Provider[] $providers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Affiliate Links', 'affiliate-link-manager' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only decides whether to show a notice; the real nonce check already happened in the POST handler that redirected here. ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Providers saved.', 'affiliate-link-manager' ); ?></p></div>
	<?php endif; ?>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Providers', 'affiliate-link-manager' ); ?></h2>
			<p class="alm-card-lede"><?php esc_html_e( 'Affiliate networks this plugin recognizes.', 'affiliate-link-manager' ); ?></p>
		</div>

		<form method="post">
			<?php wp_nonce_field( ALM_Admin::PROVIDERS_NONCE, 'alm_providers_nonce' ); ?>

			<table class="form-table">
				<?php foreach ( $providers as $provider ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $provider->get_label() ); ?></th>
						<td>
							<?php if ( $provider instanceof ALM_Provider_ShopMy ) : ?>
								<p>
									<label for="alm_shopmy_affiliate_id"><?php esc_html_e( 'Affiliate ID', 'affiliate-link-manager' ); ?></label><br>
									<input type="text" id="alm_shopmy_affiliate_id" name="alm_shopmy_affiliate_id" class="regular-text" value="<?php echo esc_attr( get_option( ALM_Provider_ShopMy::OPTION_AFFILIATE_ID, '' ) ); ?>">
								</p>
								<p>
									<label for="alm_shopmy_collection_id"><?php esc_html_e( 'Default collection ID (optional)', 'affiliate-link-manager' ); ?></label><br>
									<input type="text" id="alm_shopmy_collection_id" name="alm_shopmy_collection_id" class="regular-text" value="<?php echo esc_attr( get_option( ALM_Provider_ShopMy::OPTION_COLLECTION_ID, '' ) ); ?>">
								</p>
							<?php elseif ( $provider->can_wrap() ) : ?>
								<p class="description"><?php esc_html_e( 'Configured.', 'affiliate-link-manager' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Classify-only — this plugin recognizes these links but does not offer to convert them.', 'affiliate-link-manager' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button( __( 'Save Providers', 'affiliate-link-manager' ) ); ?>
		</form>
	</div>
</div>
