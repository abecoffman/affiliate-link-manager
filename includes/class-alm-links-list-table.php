<?php
/**
 * WP_List_Table subclass for the Links screen -- gives us real
 * pagination, column sorting, status view-tabs, and bulk actions for
 * free, the same way WordPress core's own Posts/Users/Plugins screens
 * work, instead of a hand-rolled <table>.
 *
 * Deliberately only ships Ignore/Delete actions this round -- both are
 * pure metadata operations on this plugin's own table. "Convert to
 * [provider]" is a real content-rewrite (ALM_Provider::wrap_url() +
 * ALM_Content_Adapter::replace_link(), both already implemented and
 * unit-tested) and belongs to the next phase alongside the rest of the
 * write-back UI, not bundled in here.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ALM_Links_List_Table extends WP_List_Table {

	const BULK_NONCE_ACTION = 'alm-bulk-links';

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	public function __construct( ALM_Provider_Registry $providers ) {
		parent::__construct(
			array(
				'singular' => 'alm_link',
				'plural'   => 'alm_links',
				'ajax'     => false,
			)
		);
		$this->providers = $providers;
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'provider'    => __( 'Provider', 'affiliate-link-manager' ),
			'anchor_text' => __( 'Link text', 'affiliate-link-manager' ),
			'url'         => __( 'URL', 'affiliate-link-manager' ),
			'post'        => __( 'Post', 'affiliate-link-manager' ),
			'status'      => __( 'Status', 'affiliate-link-manager' ),
			'last_seen'   => __( 'Last seen', 'affiliate-link-manager' ),
		);
	}

	/**
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'anchor_text';
	}

	/**
	 * Adds an alm-links-table hook class alongside WP_List_Table's own
	 * defaults (widefat/striped/the 'plural' constructor arg) -- lets
	 * admin.css and the E2E suite target this specific table without
	 * depending on WP core's own generated class names.
	 *
	 * @return array<string>
	 */
	protected function get_table_classes() {
		return array_merge( parent::get_table_classes(), array( 'alm-links-table' ) );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'provider'  => array( 'provider', false ),
			'status'    => array( 'status', false ),
			'last_seen' => array( 'last_seen', true ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'ignore' => __( 'Ignore', 'affiliate-link-manager' ),
			'delete' => __( 'Delete', 'affiliate-link-manager' ),
		);
	}

	/**
	 * Status view-tabs above the table ("All | Active | Stale | ...",
	 * same pattern as Posts' "All | Published | Drafts"). Empty
	 * statuses are hidden rather than shown as a permanent "(0)" --
	 * keeps the tab bar from accumulating networks/statuses nobody's
	 * ever actually seen.
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only aggregate query.
		$counts    = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM {$table} GROUP BY status", ARRAY_A );
		$by_status = wp_list_pluck( $counts, 'total', 'status' );
		$total     = array_sum( $by_status );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection, not a state-changing action.
		$current  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$base_url = remove_query_arg( array( 'status', 'paged' ) );

		$labels = array(
			''                               => __( 'All', 'affiliate-link-manager' ),
			ALM_Install::STATUS_ACTIVE       => __( 'Active', 'affiliate-link-manager' ),
			ALM_Install::STATUS_CONVERTIBLE  => __( 'Convertible', 'affiliate-link-manager' ),
			ALM_Install::STATUS_UNCLASSIFIED => __( 'Unclassified', 'affiliate-link-manager' ),
			ALM_Install::STATUS_STALE        => __( 'Stale', 'affiliate-link-manager' ),
			ALM_Install::STATUS_IGNORED      => __( 'Ignored', 'affiliate-link-manager' ),
		);

		$views = array();
		foreach ( $labels as $status => $label ) {
			$count = '' === $status ? $total : ( isset( $by_status[ $status ] ) ? (int) $by_status[ $status ] : 0 );
			if ( '' !== $status && 0 === $count ) {
				continue;
			}

			$url   = '' === $status ? $base_url : add_query_arg( 'status', $status, $base_url );
			$class = ( $current === $status ) ? ' class="current"' : '';

			$views[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	/**
	 * A provider filter dropdown alongside the status tabs -- WP_List_Table
	 * doesn't generate arbitrary filters itself, this is the standard
	 * extension point core screens (e.g. Posts' category dropdown) use.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection.
		$current = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		?>
		<div class="alignleft actions">
			<label for="alm-filter-provider" class="screen-reader-text"><?php esc_html_e( 'Filter by provider', 'affiliate-link-manager' ); ?></label>
			<select name="provider" id="alm-filter-provider">
				<option value=""><?php esc_html_e( 'All providers', 'affiliate-link-manager' ); ?></option>
				<?php foreach ( $this->providers->get_providers() as $provider ) : ?>
					<option value="<?php echo esc_attr( $provider->get_id() ); ?>" <?php selected( $current, $provider->get_id() ); ?>>
						<?php echo esc_html( $provider->get_label() ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'affiliate-link-manager' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public function prepare_items() {
		// Bulk/row actions are processed separately and earlier, by
		// ALM_Admin::handle_links_bulk_action() on the load-{hook} action
		// -- redirecting after a successful action has to happen before
		// wp-admin's own header HTML starts outputting, which has
		// already begun by the time prepare_items() (called from the
		// page's render callback) runs.
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$per_page     = 25;
		$current_page = $this->get_pagenum();

		global $wpdb;
		$table = ALM_Install::table_name();

		$where  = array( '1=1' );
		$params = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters, not a state-changing action.
		if ( ! empty( $_GET['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['provider'] ) ) {
			$where[]  = 'provider = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['provider'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter; how the Posts screen's "View Links" row action drills in.
		if ( ! empty( $_GET['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = absint( wp_unslash( $_GET['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) ) {
			$search   = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(url LIKE %s OR anchor_text LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'provider', 'status', 'last_seen' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort selection.
		$orderby = ( isset( $_GET['orderby'] ) && in_array( wp_unslash( $_GET['orderby'] ), $allowed_orderby, true ) ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'last_seen'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + column names are fixed/allow-listed above, never raw user input; real values go through prepare() below.
		$count_sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total_items = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql has no raw user input when $params is empty.

		$offset = ( $current_page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above; $orderby/$order are allow-listed, not raw user input.
		$data_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$data_params = array_merge( $params, array( $per_page, $offset ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Handles both bulk actions (a checkbox-selected form POST) and
	 * single-row actions (a GET link with one id) through the same
	 * path -- a row action is just a bulk action with one item, the
	 * same pattern WP core's own Plugins/Posts screens use.
	 *
	 * Public: called explicitly and early by
	 * ALM_Admin::handle_links_bulk_action() (see that method for why),
	 * not internally from prepare_items().
	 *
	 * @return void
	 */
	public function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::BULK_NONCE_ACTION );

		$ids = isset( $_REQUEST['alm_link'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['alm_link'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines above via check_admin_referer().
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$table        = ALM_Install::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( 'ignore' === $action ) {
			$sql    = "UPDATE {$table} SET status = %s, dismissed_at = %s WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
			$params = array_merge( array( ALM_Install::STATUS_IGNORED, current_time( 'mysql' ) ), $ids );
			$wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		} elseif ( 'delete' === $action ) {
			$sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above.
			$wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		} else {
			return;
		}

		// Redirect to a clean URL after processing, same reasoning as
		// the plugin's other form handlers (see ALM_Admin::save_settings())
		// -- without this, refreshing the page would resubmit the same
		// action=/alm_link[]= query args. Harmless here since both
		// actions are idempotent, but not good practice to leave in.
		$clean_url = remove_query_arg( array( 'action', 'action2', 'alm_link', '_wpnonce' ) );
		wp_safe_redirect( $clean_url );
		exit;
	}

	/**
	 * @param array $item
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="alm_link[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * @param array $item
	 * @return string
	 */
	public function column_provider( $item ) {
		$provider = $this->providers->get_provider( $item['provider'] );
		$label    = $provider ? $provider->get_label() : $item['provider'];

		return sprintf( '<span class="alm-badge alm-badge-%s">%s</span>', esc_attr( $item['provider'] ), esc_html( $label ) );
	}

	/**
	 * Primary column -- carries this row's row_actions() (Edit Post,
	 * View Post, Ignore, Delete).
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_anchor_text( $item ) {
		$actions = array();

		$edit_link = get_edit_post_link( $item['post_id'], 'raw' );
		if ( $edit_link ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit Post', 'affiliate-link-manager' ) );
		}

		$view_link = get_permalink( $item['post_id'] );
		if ( $view_link ) {
			$actions['view'] = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $view_link ), esc_html__( 'View', 'affiliate-link-manager' ) );
		}

		if ( ALM_Install::STATUS_IGNORED !== $item['status'] ) {
			$actions['ignore'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->row_action_url( 'ignore', $item['id'] ) ), esc_html__( 'Ignore', 'affiliate-link-manager' ) );
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
			esc_url( $this->row_action_url( 'delete', $item['id'] ) ),
			wp_json_encode( __( 'Delete this link? This only removes it from Affiliate Link Manager\'s records -- it does not change the post.', 'affiliate-link-manager' ) ),
			esc_html__( 'Delete', 'affiliate-link-manager' )
		);

		return esc_html( $item['anchor_text'] ) . $this->row_actions( $actions );
	}

	/**
	 * @param string $action
	 * @param int    $id
	 * @return string
	 */
	private function row_action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => $action,
					'alm_link' => $id,
				)
			),
			self::BULK_NONCE_ACTION
		);
	}

	/**
	 * @param array $item
	 * @return string
	 */
	public function column_url( $item ) {
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" class="alm-url-cell-link" title="%1$s">%2$s</a>',
			esc_url( $item['url'] ),
			esc_html( $item['url'] )
		);
	}

	/**
	 * @param array $item
	 * @return string
	 */
	public function column_post( $item ) {
		$title = get_the_title( $item['post_id'] );
		return esc_html( $title ? $title : '#' . $item['post_id'] );
	}

	/**
	 * @param array $item
	 * @return string
	 */
	public function column_status( $item ) {
		return sprintf( '<span class="alm-badge alm-badge-status-%s">%s</span>', esc_attr( $item['status'] ), esc_html( ucfirst( $item['status'] ) ) );
	}

	/**
	 * @param array $item
	 * @return string
	 */
	public function column_last_seen( $item ) {
		return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['last_seen'] ) );
	}

	/**
	 * @param array  $item
	 * @param string $column_name
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
}
