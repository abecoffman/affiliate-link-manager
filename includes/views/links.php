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

	<?php
	// Result notice from the "Remove from Post" bulk action -- same
	// pattern as the "Convert to [Provider]" notice above.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display, the state change already happened and was itself nonce-verified in process_bulk_action().
	if ( isset( $_GET['alm_removed'] ) ) :
		$removed        = absint( wp_unslash( $_GET['alm_removed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$remove_skipped = isset( $_GET['alm_remove_skipped'] ) ? absint( wp_unslash( $_GET['alm_remove_skipped'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="notice notice-<?php echo esc_attr( $remove_skipped ? 'warning' : 'success' ); ?> is-dismissible">
			<p>
				<?php if ( $remove_skipped ) : ?>
					<?php
					printf(
						/* translators: 1: number removed, 2: number of selected links, 3: number skipped */
						esc_html__( 'Removed %1$d of %2$d; %3$d skipped because they weren\'t confirmed dead, or the post content changed since the last scan — rescan and try again.', 'affiliate-link-manager' ),
						(int) $removed,
						(int) ( $removed + $remove_skipped ),
						(int) $remove_skipped
					);
					?>
				<?php else : ?>
					<?php
					printf(
						/* translators: %d: number of links removed */
						esc_html( _n( 'Removed %d link from its post.', 'Removed %d links from their posts.', $removed, 'affiliate-link-manager' ) ),
						(int) $removed
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
		// No explicit wp_nonce_field() here -- $list_table->display()
		// already renders one on its own (WP_List_Table::display_tablenav()),
		// using exactly ALM_Links_List_Table::BULK_NONCE_ACTION's value
		// by design. See that constant's own docblock for why a second,
		// explicit field here was a real bug, not redundant safety.
		?>

		<?php
		// The actual point of this round: the "Remove from Post" bulk
		// action already existed, but buried in a generic dropdown +
		// the Edit modal's footer -- effectively invisible ("I don't
		// actually see that utility itself"). This puts it directly in
		// front of an admin already looking at exactly the links it
		// applies to. Every row on this tab is already confirmed dead
		// (see ALM_Links_List_Table::prepare_items()'s ?status=dead
		// handling), so this button reuses the exact same, already-
		// tested remove_dead_links bulk action -- just pre-selecting
		// every row on the current page rather than requiring the admin
		// to already know the dropdown entry exists. Scoped to "this
		// page" deliberately, not a claimed site-wide total: only rows
		// with a checkbox actually on screen can be selected this way.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, not a state-changing action.
		if ( isset( $_GET['status'] ) && 'dead' === $_GET['status'] && ! empty( $list_table->items ) ) :
			?>
			<p class="alm-dead-links-banner">
				<?php
				printf(
					/* translators: %s: link text describing how many links are shown */
					esc_html__( 'These %s are confirmed dead -- their destination no longer works.', 'affiliate-link-manager' ),
					esc_html(
						sprintf(
							/* translators: %d: number of dead links shown on this page */
							_n( '%d link', '%d links', count( $list_table->items ), 'affiliate-link-manager' ),
							count( $list_table->items )
						)
					)
				);
				?>
				<button type="submit" id="alm-remove-all-dead" class="button button-primary">
					<?php
					printf(
						/* translators: %d: number of dead links on this page */
						esc_html__( 'Remove These %d From Their Posts', 'affiliate-link-manager' ),
						count( $list_table->items )
					);
					?>
				</button>
			</p>
		<?php endif; ?>

		<div class="alm-table-scroll">
			<?php $list_table->display(); ?>
		</div>
	</form>

	<?php require ALM_PATH . 'includes/views/edit-link-modal.php'; ?>
</div>
