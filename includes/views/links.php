<?php
/**
 * Links screen: full, filterable, sortable, paginated audit table of
 * every link the scanner has found, built on WP_List_Table
 * (ALM_Links_List_Table) -- the same base class WordPress core's own
 * Posts/Users/Plugins screens use, for consistent bulk actions,
 * pagination, sorting, and status view-tabs.
 *
 * @package ALM
 *
 * @var ALM_Links_List_Table $list_table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Affiliate Links', 'affiliate-link-manager' ); ?></h1>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state-changing action.
	$filtered_post_id = ! empty( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $filtered_post_id ) :
		$post_title = get_the_title( $filtered_post_id );
		?>
		<p class="alm-filtered-notice">
			<?php
			printf(
				/* translators: %s: post title */
				esc_html__( 'Showing links found in: %s', 'affiliate-link-manager' ),
				'<strong>' . esc_html( $post_title ? $post_title : '#' . $filtered_post_id ) . '</strong>'
			);
			?>
			&nbsp;&mdash;&nbsp;
			<a href="<?php echo esc_url( remove_query_arg( 'post_id' ) ); ?>"><?php esc_html_e( 'Clear', 'affiliate-link-manager' ); ?></a>
		</p>
	<?php endif; ?>

	<?php
	// Result notice from the "Convert to [Provider]" bulk action --
	// ALM_Links_List_Table::bulk_convert() redirects here with these two
	// query args set, same pattern as WP core's own bulk-action notices
	// (Posts' "N posts updated"). A skip isn't a failure: it means the
	// row's content changed since the last scan and replace_link()
	// correctly refused to write over it -- said plainly rather than
	// silently dropped, per this plugin's existing "leave it alone and
	// say why" convention (see ALM_Content_Adapter::replace_link()).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display, the state change already happened and was itself nonce-verified in process_bulk_action().
	if ( isset( $_GET['alm_converted'] ) ) :
		$converted = absint( wp_unslash( $_GET['alm_converted'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped   = isset( $_GET['alm_skipped'] ) ? absint( wp_unslash( $_GET['alm_skipped'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="notice notice-<?php echo esc_attr( $skipped ? 'warning' : 'success' ); ?> is-dismissible">
			<p>
				<?php if ( $skipped ) : ?>
					<?php
					printf(
						/* translators: 1: number converted, 2: number of selected links, 3: number skipped */
						esc_html__( 'Converted %1$d of %2$d; %3$d skipped because the post content changed since the last scan — rescan and try again.', 'affiliate-link-manager' ),
						(int) $converted,
						(int) ( $converted + $skipped ),
						(int) $skipped
					);
					?>
				<?php else : ?>
					<?php
					printf(
						/* translators: %d: number of links converted */
						esc_html( _n( 'Converted %d link.', 'Converted %d links.', $converted, 'affiliate-link-manager' ) ),
						(int) $converted
					);
					?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php $list_table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( ALM_Admin::MENU_SLUG . '-links' ); ?>" />
		<?php if ( $filtered_post_id ) : ?>
			<input type="hidden" name="post_id" value="<?php echo esc_attr( $filtered_post_id ); ?>" />
		<?php endif; ?>
		<?php
		$list_table->search_box( __( 'Search links', 'affiliate-link-manager' ), 'alm-link-search' );
		wp_nonce_field( ALM_Links_List_Table::BULK_NONCE_ACTION );
		?>
		<div class="alm-table-scroll">
			<?php $list_table->display(); ?>
		</div>
	</form>

	<?php require ALM_PATH . 'includes/views/edit-link-modal.php'; ?>
</div>
