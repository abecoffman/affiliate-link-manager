<?php
/**
 * Links screen: full audit table of every link the last scan found.
 *
 * @package ALM
 *
 * @var array[]        $links
 * @var ALM_Provider[] $providers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Affiliate Links', 'affiliate-link-manager' ); ?></h1>

	<div class="alm-card">
		<div class="alm-card-header">
			<h2><?php esc_html_e( 'Links', 'affiliate-link-manager' ); ?></h2>
			<p class="alm-card-lede"><?php esc_html_e( 'Every link the last scan found, most recently seen first (showing up to 500).', 'affiliate-link-manager' ); ?></p>
		</div>

		<?php if ( empty( $links ) ) : ?>
			<p><?php esc_html_e( 'No links found yet — run a scan from the Dashboard.', 'affiliate-link-manager' ); ?></p>
		<?php else : ?>
			<table class="widefat striped alm-links-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Provider', 'affiliate-link-manager' ); ?></th>
						<th><?php esc_html_e( 'Link text', 'affiliate-link-manager' ); ?></th>
						<th><?php esc_html_e( 'URL', 'affiliate-link-manager' ); ?></th>
						<th><?php esc_html_e( 'Post', 'affiliate-link-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $links as $found_link ) : ?>
					<?php $post_title = get_the_title( $found_link['post_id'] ); ?>
					<tr>
						<td><span class="alm-badge alm-badge-<?php echo esc_attr( $found_link['provider'] ); ?>"><?php echo esc_html( $found_link['provider'] ); ?></span></td>
						<td><?php echo esc_html( $found_link['anchor_text'] ); ?></td>
						<td><a href="<?php echo esc_url( $found_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $found_link['url'] ); ?></a></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $found_link['post_id'] ) ); ?>"><?php echo esc_html( $post_title ? $post_title : '#' . $found_link['post_id'] ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
