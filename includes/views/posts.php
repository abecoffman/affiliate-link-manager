<?php
/**
 * Posts screen: one row per post with tracked links, rolling up the
 * same alm_links data as the Links screen from the opposite direction
 * -- "which content needs attention" rather than "which links need
 * attention". Built on ALM_Posts_List_Table (WP_List_Table), same
 * pattern as the Links screen.
 *
 * @package ALM
 *
 * @var ALM_Posts_List_Table $list_table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap alm-wrap">
	<h1><?php esc_html_e( 'Posts', 'affiliate-link-manager' ); ?></h1>
	<p class="alm-card-lede"><?php esc_html_e( 'Every post with at least one tracked affiliate link, grouped for editorial triage.', 'affiliate-link-manager' ); ?></p>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( ALM_Admin::MENU_SLUG . '-posts' ); ?>" />
		<?php
		$list_table->search_box( __( 'Search posts', 'affiliate-link-manager' ), 'alm-post-search' );
		?>
		<div class="alm-table-scroll">
			<?php $list_table->display(); ?>
		</div>
	</form>
</div>
