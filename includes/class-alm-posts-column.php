<?php
/**
 * A sortable "Affiliate Links" column on the native Posts (and any
 * other scannable post type's) list screen -- surfaces the count where
 * editors already work, rather than only inside the plugin's own
 * top-level menu. High value, low effort (see the Admin IA plan).
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Posts_Column {

	const COLUMN_KEY = 'alm_links';

	/**
	 * @var ALM_Scanner
	 */
	private $scanner;

	/**
	 * Per-request cache of post_id => array{total:int,stale:int,candidates:int},
	 * populated once from the current list table's own result set the
	 * first time a row is rendered -- avoids a query per row.
	 *
	 * @var array<int,array{total:int,stale:int,candidates:int}>|null
	 */
	private $link_counts = null;

	public function __construct( ALM_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	public function init() {
		foreach ( $this->scanner->get_scannable_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'add_sortable_column' ) );
		}

		add_action( 'pre_get_posts', array( $this, 'maybe_sort_by_link_count' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_badge_styles' ) );
	}

	/**
	 * ALM_Admin::enqueue_assets() only loads admin.css on this plugin's
	 * own screens -- the stale-count badge this column can print needs
	 * it on edit.php too, for every scannable post type. Style only, no
	 * JS: there's no scan control on this screen.
	 *
	 * @param string $hook
	 * @return void
	 */
	public function enqueue_badge_styles( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->scanner->get_scannable_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'alm-admin', ALM_URL . 'assets/admin.css', array(), ALM_VERSION );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN_KEY ] = __( 'Affiliate Links', 'affiliate-link-manager' );
		return $columns;
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_sortable_column( $columns ) {
		$columns[ self::COLUMN_KEY ] = self::COLUMN_KEY;
		return $columns;
	}

	/**
	 * @param string $column
	 * @param int    $post_id
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$counts = $this->get_counts_for_current_screen();
		$count  = isset( $counts[ $post_id ] ) ? $counts[ $post_id ] : array(
			'total'      => 0,
			'stale'      => 0,
			'candidates' => 0,
		);

		if ( 0 === $count['total'] ) {
			echo '&#8212;';
			return;
		}

		$url = add_query_arg(
			array(
				'page'    => ALM_Admin::MENU_SLUG . '-links',
				'post_id' => $post_id,
			),
			admin_url( 'admin.php' )
		);

		printf( '<a href="%s">%d</a>', esc_url( $url ), (int) $count['total'] );

		if ( $count['candidates'] > 0 ) {
			printf( ' <span class="alm-badge alm-badge-status-convertible">%d candidates</span>', (int) $count['candidates'] );
		}

		if ( $count['stale'] > 0 ) {
			printf( ' <span class="alm-badge alm-badge-status-stale">%d stale</span>', (int) $count['stale'] );
		}
	}

	/**
	 * One batched query for every post_id currently on the list screen's
	 * page (the global $wp_query has already run by the time any column
	 * is rendered), grouped by status so the stale/candidate sub-counts
	 * come free.
	 *
	 * @return array<int,array{total:int,stale:int,candidates:int}>
	 */
	private function get_counts_for_current_screen() {
		if ( null !== $this->link_counts ) {
			return $this->link_counts;
		}

		$this->link_counts = array();

		global $wp_query, $wpdb;
		$post_ids = wp_list_pluck( (array) $wp_query->posts, 'ID' );
		$post_ids = array_map( 'absint', array_filter( $post_ids ) );
		if ( empty( $post_ids ) ) {
			return $this->link_counts;
		}

		$table        = ALM_Install::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
		$sql  = "SELECT post_id, status, COUNT(*) as total FROM {$table} WHERE post_id IN ({$placeholders}) GROUP BY post_id, status";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $post_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$total   = (int) $row['total'];

			if ( ! isset( $this->link_counts[ $post_id ] ) ) {
				$this->link_counts[ $post_id ] = array(
					'total'      => 0,
					'stale'      => 0,
					'candidates' => 0,
				);
			}

			$this->link_counts[ $post_id ]['total'] += $total;
			if ( ALM_Install::STATUS_STALE === $row['status'] ) {
				$this->link_counts[ $post_id ]['stale'] += $total;
			}
			if ( ALM_Install::STATUS_CONVERTIBLE === $row['status'] ) {
				$this->link_counts[ $post_id ]['candidates'] += $total;
			}
		}

		return $this->link_counts;
	}

	/**
	 * Sorting by an aggregate from this plugin's own table isn't
	 * expressible through WP_Query's normal orderby/meta machinery --
	 * join a per-post count in via posts_clauses instead, the standard
	 * extension point for exactly this (same technique WooCommerce and
	 * others use for custom sortable columns backed by a separate table).
	 *
	 * @param WP_Query $query
	 * @return void
	 */
	public function maybe_sort_by_link_count( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::COLUMN_KEY !== $query->get( 'orderby' ) ) {
			return;
		}

		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses_for_sort' ) );
	}

	/**
	 * @param array<string,string> $clauses
	 * @return array<string,string>
	 */
	public function filter_posts_clauses_for_sort( $clauses ) {
		// One-shot: only ever meant to apply to the single query that
		// triggered it in maybe_sort_by_link_count().
		remove_filter( 'posts_clauses', array( $this, 'filter_posts_clauses_for_sort' ) );

		global $wpdb;
		$table = ALM_Install::table_name();
		$order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort direction, not a state-changing action.

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		$clauses['fields'] .= ", ( SELECT COUNT(*) FROM {$table} WHERE {$table}.post_id = {$wpdb->posts}.ID ) as alm_link_count";
		$clauses['orderby'] = "alm_link_count {$order}, " . $clauses['orderby'];

		return $clauses;
	}
}
