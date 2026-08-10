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

	/**
	 * Per-post candidate-link counts, keyed by post_id -- mirrors
	 * $stale_counts, populated by the same load_breakdowns() query.
	 *
	 * @var array<int,int>
	 */
	private $candidate_counts = array();

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

		// Other Outbound Links (status=unclassified) never counts toward
		// a post's presence or total here, same as it's excluded from
		// the Links screen's default "All" view -- a post whose only
		// tracked links are internal nav/social noise has nothing an
		// editor needs to see in this rollup at all.
		$where  = array( 'status != %s' );
		$params = array( ALM_Install::STATUS_UNCLASSIFIED );

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

		$page_post_ids = wp_list_pluck( $this->items, 'post_id' );

		// column_post() calls get_the_title()/get_edit_post_link()/
		// get_permalink() per row, each an uncached get_post() query
		// without this -- confirmed as a real N+1 at honestlywtf's scale
		// (3,000+ posts), not just a theoretical one. Neither term nor
		// meta caches are needed for any of those three calls.
		_prime_post_caches( $page_post_ids, false, false );

		$this->load_breakdowns( $page_post_ids );

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

		// Other Outbound Links excluded here too, same reasoning as
		// prepare_items() -- without this, a post with e.g. 50
		// other-outbound + 3 candidate links would still show a "50
		// Unclassified" badge in the breakdown, exactly the clutter this
		// screen exists to avoid.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
		$sql    = "SELECT post_id, provider, status, COUNT(*) as total FROM {$table} WHERE post_id IN ({$placeholders}) AND status != %s GROUP BY post_id, provider, status";
		$params = array_merge( $post_ids, array( ALM_Install::STATUS_UNCLASSIFIED ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$total   = (int) $row['total'];

			// Stale and candidate links get their own badges below, shown
			// as flat totals rather than folded into the provider badge --
			// so provider_counts only accumulates the "steady state"
			// statuses. Otherwise a post showing "70 Unclassified" and
			// "14 candidates" would visually imply 84 links when the real
			// total (the Links column right next to it) is 70; this way
			// the badges shown always literally sum to the row's total.
			if ( ALM_Install::STATUS_STALE === $row['status'] ) {
				$this->stale_counts[ $post_id ] = ( isset( $this->stale_counts[ $post_id ] ) ? $this->stale_counts[ $post_id ] : 0 ) + $total;
				continue;
			}

			if ( ALM_Install::STATUS_CONVERTIBLE === $row['status'] ) {
				$this->candidate_counts[ $post_id ] = ( isset( $this->candidate_counts[ $post_id ] ) ? $this->candidate_counts[ $post_id ] : 0 ) + $total;
				continue;
			}

			if ( ! isset( $this->provider_counts[ $post_id ] ) ) {
				$this->provider_counts[ $post_id ] = array();
			}
			if ( ! isset( $this->provider_counts[ $post_id ][ $row['provider'] ] ) ) {
				$this->provider_counts[ $post_id ][ $row['provider'] ] = 0;
			}
			$this->provider_counts[ $post_id ][ $row['provider'] ] += $total;
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
	/**
	 * Same fix as ALM_Links_List_Table::handle_row_actions() -- see its
	 * docblock. row_actions(), called below from column_post(), already
	 * appends its own toggle-row button on this WP core version;
	 * without this override, single_row_columns() adds a second,
	 * identical one.
	 *
	 * @param object|array $item
	 * @param string       $column_name
	 * @param string       $primary
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		return '';
	}

	public function column_post( $item ) {
		$post_id = (int) $item['post_id'];
		$title   = get_the_title( $post_id );
		$title   = $title ? $title : '#' . $post_id;

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		$label     = $edit_link
			? sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( $edit_link ), esc_html( $title ) )
			: '<strong>' . esc_html( $title ) . '</strong>';

		$actions = array();

		$links_url             = add_query_arg(
			array(
				'page'    => ALM_Admin::MENU_SLUG . '-links',
				'post_id' => $post_id,
			),
			admin_url( 'admin.php' )
		);
		$actions['view_links'] = sprintf( '<a href="%s">%s</a>', esc_url( $links_url ), esc_html__( 'View Links', 'affiliate-link-manager' ) );

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

		// Candidates before stale -- an opportunity worth chasing reads
		// as more actionable than cleanup, and it's the badge this
		// screen exists to make visible in the first place.
		$candidates = isset( $this->candidate_counts[ $post_id ] ) ? $this->candidate_counts[ $post_id ] : 0;
		if ( $candidates > 0 ) {
			$badges[] = sprintf(
				'<span class="alm-badge alm-badge-status-convertible">%d %s</span>',
				$candidates,
				esc_html( _n( 'candidate', 'candidates', $candidates, 'affiliate-link-manager' ) )
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
