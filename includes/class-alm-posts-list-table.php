<?php
/**
 * WP_List_Table subclass for the Posts screen -- the same underlying
 * alm_links rows as the Links screen, rolled up one row per post
 * instead of one row per link. Answers "which content needs editorial
 * attention" as a distinct, complementary cut through the data, not a
 * duplicate of the Links screen (see the Admin IA plan).
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ALM_Posts_List_Table extends WP_List_Table {

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * Per-post provider counts, keyed by post_id then provider id.
	 * Populated in prepare_items(), read by column_breakdown().
	 *
	 * @var array<int,array<string,int>>
	 */
	private $provider_counts = array();

	/**
	 * Per-post stale-link counts, keyed by post_id.
	 *
	 * @var array<int,int>
	 */
	private $stale_counts = array();

	public function __construct( ALM_Provider_Registry $providers ) {
		parent::__construct(
			array(
				'singular' => 'alm_post',
				'plural'   => 'alm_posts',
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
			'post'      => __( 'Post', 'affiliate-link-manager' ),
			'total'     => __( 'Links', 'affiliate-link-manager' ),
			'breakdown' => __( 'Breakdown', 'affiliate-link-manager' ),
			'last_seen' => __( 'Last scanned', 'affiliate-link-manager' ),
		);
	}

	/**
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'post';
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'total'     => array( 'total', true ),
			'last_seen' => array( 'last_seen', false ),
		);
	}

	/**
	 * A provider filter dropdown, same pattern as the Links screen --
	 * limits the rollup to posts containing at least one link from the
	 * selected provider.
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
			<label for="alm-filter-posts-provider" class="screen-reader-text"><?php esc_html_e( 'Filter by provider', 'affiliate-link-manager' ); ?></label>
			<select name="provider" id="alm-filter-posts-provider">
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state-changing action.
		if ( ! empty( $_GET['provider'] ) ) {
			$where[]  = 'post_id IN ( SELECT post_id FROM ' . $table . ' WHERE provider = %s )'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
			$params[] = sanitize_key( wp_unslash( $_GET['provider'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) ) {
			$search   = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = "post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts, not user input.
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'total', 'last_seen' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort selection.
		$orderby = ( isset( $_GET['orderby'] ) && in_array( wp_unslash( $_GET['orderby'] ), $allowed_orderby, true ) ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'total'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name fixed, not user input; real values bound via prepare() below.
		$count_sql   = "SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE {$where_sql}";
		$total_items = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql has no raw user input when $params is empty.

		$offset = ( $current_page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above; $orderby/$order are allow-listed, not raw user input.
		$data_sql    = "SELECT post_id, COUNT(*) as total, MAX(last_seen) as last_seen FROM {$table} WHERE {$where_sql} GROUP BY post_id ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$data_params = array_merge( $params, array( $per_page, $offset ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		$this->load_breakdowns( wp_list_pluck( $this->items, 'post_id' ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * One extra query for the current page of posts only -- fetches
	 * per-post/provider/status counts so column_breakdown() can render
	 * "3 ShopMy · 1 stale" without a query per row.
	 *
	 * @param array<int> $post_ids
	 * @return void
	 */
	private function load_breakdowns( $post_ids ) {
		$post_ids = array_map( 'absint', array_filter( $post_ids ) );
		if ( empty( $post_ids ) ) {
			return;
		}

		global $wpdb;
		$table        = ALM_Install::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
		$sql  = "SELECT post_id, provider, status, COUNT(*) as total FROM {$table} WHERE post_id IN ({$placeholders}) GROUP BY post_id, provider, status";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $post_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$total   = (int) $row['total'];

			if ( ! isset( $this->provider_counts[ $post_id ] ) ) {
				$this->provider_counts[ $post_id ] = array();
			}
			if ( ! isset( $this->provider_counts[ $post_id ][ $row['provider'] ] ) ) {
				$this->provider_counts[ $post_id ][ $row['provider'] ] = 0;
			}
			$this->provider_counts[ $post_id ][ $row['provider'] ] += $total;

			if ( ALM_Install::STATUS_STALE === $row['status'] ) {
				$this->stale_counts[ $post_id ] = ( isset( $this->stale_counts[ $post_id ] ) ? $this->stale_counts[ $post_id ] : 0 ) + $total;
			}
		}
	}

	/**
	 * Primary column -- post title, linked to the edit screen, carrying
	 * the "View Links" row action that drills into the Links screen
	 * pre-filtered to this post.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_post( $item ) {
		$post_id = (int) $item['post_id'];
		$title   = get_the_title( $post_id );
		$title   = $title ? $title : '#' . $post_id;

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		$label     = $edit_link
			? sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( $edit_link ), esc_html( $title ) )
			: '<strong>' . esc_html( $title ) . '</strong>';

		$actions = array();

		$links_url               = add_query_arg(
			array(
				'page'    => ALM_Admin::MENU_SLUG . '-links',
				'post_id' => $post_id,
			),
			admin_url( 'admin.php' )
		);
		$actions['view_links']   = sprintf( '<a href="%s">%s</a>', esc_url( $links_url ), esc_html__( 'View Links', 'affiliate-link-manager' ) );

		if ( $edit_link ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit Post', 'affiliate-link-manager' ) );
		}

		$view_link = get_permalink( $post_id );
		if ( $view_link ) {
			$actions['view'] = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $view_link ), esc_html__( 'View', 'affiliate-link-manager' ) );
		}

		return $label . $this->row_actions( $actions );
	}

	/**
	 * @param array $item
	 * @return string
	 */
	public function column_total( $item ) {
		return esc_html( number_format_i18n( (int) $item['total'] ) );
	}

	/**
	 * Mini breakdown: one badge per provider present on this post, plus
	 * a stale-count badge when this post has any -- the thing that
	 * actually needs a human's attention, surfaced right in the rollup
	 * instead of requiring a click into Links to discover it.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_breakdown( $item ) {
		$post_id = (int) $item['post_id'];
		$badges  = array();

		$counts = isset( $this->provider_counts[ $post_id ] ) ? $this->provider_counts[ $post_id ] : array();
		foreach ( $counts as $provider_id => $count ) {
			$provider = $this->providers->get_provider( $provider_id );
			$label    = $provider ? $provider->get_label() : $provider_id;

			$badges[] = sprintf(
				'<span class="alm-badge alm-badge-%s">%d %s</span>',
				esc_attr( $provider_id ),
				$count,
				esc_html( $label )
			);
		}

		$stale = isset( $this->stale_counts[ $post_id ] ) ? $this->stale_counts[ $post_id ] : 0;
		if ( $stale > 0 ) {
			$badges[] = sprintf(
				'<span class="alm-badge alm-badge-status-stale">%d %s</span>',
				$stale,
				esc_html__( 'stale', 'affiliate-link-manager' )
			);
		}

		return $badges ? implode( ' ', $badges ) : '&#8212;';
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
