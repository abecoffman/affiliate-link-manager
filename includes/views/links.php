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

	<?php $list_table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( ALM_Admin::MENU_SLUG . '-links' ); ?>" />
		<?php
		$list_table->search_box( __( 'Search links', 'affiliate-link-manager' ), 'alm-link-search' );
		wp_nonce_field( ALM_Links_List_Table::BULK_NONCE_ACTION );
		$list_table->display();
		?>
	</form>
</div>
