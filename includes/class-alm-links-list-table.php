<?php
/**
 * WP_List_Table subclass for the Links screen -- gives us real
 * pagination, column sorting, status view-tabs, and bulk actions for
 * free, the same way WordPress core's own Posts/Users/Plugins screens
 * work, instead of a hand-rolled <table>.
 *
 * Ignore/Delete are pure metadata operations on this plugin's own
 * table. Convert (both the row-level "Edit" action, via
 * ALM_Admin::handle_edit_link()'s AJAX endpoint, and the "Convert to
 * [provider]" bulk action below) is a real content-rewrite --
 * ALM_Provider::wrap_url() + ALM_Content_Adapter::replace_link() --
 * funneled through the shared ALM_Link_Converter so both entry points
 * behave identically.
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

	const BULK_NONCE_ACTION     = 'alm-bulk-links';
	const CONVERT_ACTION_PREFIX = 'convert_';

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * @var ALM_Link_Converter
	 */
	private $converter;

	public function __construct( ALM_Provider_Registry $providers, ALM_Link_Converter $converter ) {
		parent::__construct(
			array(
				'singular' => 'alm_link',
				'plural'   => 'alm_links',
				'ajax'     => false,
			)
		);
		$this->providers = $providers;
		$this->converter = $converter;
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			// anchor_text (the primary column, see get_primary_column_name())
			// has to be the first column after the checkbox, not just
			// visually -- WP core's own responsive CSS hides every column
			// at <=782px via a `th.column-primary ~ th` *following*-sibling
			// selector. A primary column that isn't first can never match
			// that selector for the columns before it, so they're stuck
			// half-rendered on narrow screens instead of collapsing into
			// the row card like every other WP core list table. Confirmed
			// live: Provider was ahead of Link text here originally, and
			// broke exactly this way at mobile widths.
			'anchor_text' => __( 'Link text', 'affiliate-link-manager' ),
			'provider'    => __( 'Provider', 'affiliate-link-manager' ),
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
	 * One "Convert to [Provider]" entry per registered, configured,
	 * can_wrap()-capable provider (today: just ShopMy, once an affiliate
	 * ID is set) -- same gate the row-level Edit modal uses, so the two
	 * entry points never offer a provider one can do that the other
	 * can't. Providers that can only reclassify (RewardStyle, Generic)
	 * don't get a bulk entry -- that's a per-row decision made in the
	 * Edit modal, not something to fire at scale from a checkbox list.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		$actions = array();

		foreach ( $this->providers->get_providers() as $provider ) {
			if ( $provider->can_wrap() ) {
				$actions[ self::CONVERT_ACTION_PREFIX . $provider->get_id() ] = sprintf(
					/* translators: %s: provider label, e.g. "ShopMy" */
					__( 'Convert to %s', 'affiliate-link-manager' ),
					$provider->get_label()
				);
			}
		}

		$actions['ignore'] = __( 'Ignore', 'affiliate-link-manager' );
		$actions['delete'] = __( 'Delete', 'affiliate-link-manager' );

		return $actions;
	}

	/**
	 * Single source of truth for status display text -- used by
	 * get_views() (the tab bar), column_status() (the per-row badge),
	 * and the Dashboard, so none of them can drift out of sync on
	 * wording. Two forms of the same mapping, not two separate ones:
	 * $long is for section/tab-level text ("Affiliate Links",
	 * "Candidate Affiliate Links" -- the three-tier framing the user
	 * asked for), $short is for the per-row status badge, where the
	 * long form would wrap awkwardly next to the other (short) badges
	 * in that same row.
	 *
	 * STATUS_CONVERTIBLE reads as "Candidate(s)" here, not literally
	 * "convertible" in the UI sense: it's a link ALM_Candidate_Classifier
	 * decided looks like a real affiliate-link opportunity, nothing
	 * converts it automatically.
	 *
	 * @param string $status
	 * @param bool   $long
	 * @return string
	 */
	public static function status_label( $status, $long = false ) {
		$labels = array(
			ALM_Install::STATUS_ACTIVE       => array(
				'short' => __( 'Affiliate Link', 'affiliate-link-manager' ),
				'long'  => __( 'Affiliate Links', 'affiliate-link-manager' ),
			),
			ALM_Install::STATUS_CONVERTIBLE  => array(
				'short' => __( 'Candidate', 'affiliate-link-manager' ),
				'long'  => __( 'Candidate Affiliate Links', 'affiliate-link-manager' ),
			),
			ALM_Install::STATUS_UNCLASSIFIED => array(
				'short' => __( 'Other Outbound Link', 'affiliate-link-manager' ),
				'long'  => __( 'Other Outbound Links', 'affiliate-link-manager' ),
			),
			ALM_Install::STATUS_STALE        => array(
				'short' => __( 'Stale', 'affiliate-link-manager' ),
				'long'  => __( 'Stale', 'affiliate-link-manager' ),
			),
			ALM_Install::STATUS_IGNORED      => array(
				'short' => __( 'Ignored', 'affiliate-link-manager' ),
				'long'  => __( 'Ignored', 'affiliate-link-manager' ),
			),
		);

		if ( ! isset( $labels[ $status ] ) ) {
			return ucfirst( $status );
		}

		return $labels[ $status ][ $long ? 'long' : 'short' ];
	}

	/**
	 * Status view-tabs above the table ("All | Candidate Affiliate
	 * Links | Affiliate Links | ...", same pattern as Posts' "All |
	 * Published | Drafts"). Other Outbound Links (STATUS_UNCLASSIFIED)
	 * deliberately never gets a tab here at all, regardless of count --
	 * it's noise (internal nav, social icons, reference sites), not
	 * something to browse; the Dashboard Overview is the one place its
	 * count is shown. Every other empty status is hidden rather than
	 * shown as a permanent "(0)", keeping the tab bar from accumulating
	 * statuses nobody's ever actually seen.
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only aggregate query.
		$counts    = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM {$table} GROUP BY status", ARRAY_A );
		$by_status = wp_list_pluck( $counts, 'total', 'status' );
		// "All" here means all three visible tiers, not literally every
		// row -- Other Outbound Links is excluded from this sum the same
		// way it's excluded from the "All" tab's own query in
		// prepare_items(), so the count next to "All" always matches
		// what that tab actually shows.
		$visible_statuses = array( ALM_Install::STATUS_CONVERTIBLE, ALM_Install::STATUS_ACTIVE, ALM_Install::STATUS_STALE, ALM_Install::STATUS_IGNORED );
		$total            = array_sum( array_intersect_key( $by_status, array_flip( $visible_statuses ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection, not a state-changing action.
		$current  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$base_url = remove_query_arg( array( 'status', 'paged' ) );

		$labels = array( '' => __( 'All', 'affiliate-link-manager' ) );
		foreach ( $visible_statuses as $status ) {
			// Candidates listed first -- it's the tab most worth an
			// editor's attention, not an alphabetical/schema accident.
			$labels[ $status ] = self::status_label( $status, true );
		}

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
		} else {
			// "All" (no explicit status param) means all three visible
			// tiers -- Other Outbound Links is deliberately never part of
			// the default/browsable view, only a summary count on the
			// Dashboard. A direct ?status=unclassified link (e.g. an old
			// bookmark) still works via the branch above; this exclusion
			// only applies to the unfiltered default.
			$where[]  = 'status != %s';
			$params[] = ALM_Install::STATUS_UNCLASSIFIED;
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

		// column_anchor_text()/column_post() call get_the_title()/
		// get_edit_post_link()/get_permalink() per row, each an uncached
		// get_post() query without this -- a real N+1 confirmed at
		// honestlywtf's scale (37,000+ rows), not just a theoretical one.
		_prime_post_caches( wp_list_pluck( $this->items, 'post_id' ), false, false );

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

		$notice_args = array();

		if ( 'ignore' === $action ) {
			$sql    = "UPDATE {$table} SET status = %s, dismissed_at = %s WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
			$params = array_merge( array( ALM_Install::STATUS_IGNORED, current_time( 'mysql' ) ), $ids );
			$wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		} elseif ( 'delete' === $action ) {
			$sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above.
			$wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		} elseif ( 0 === strpos( $action, self::CONVERT_ACTION_PREFIX ) ) {
			$provider_id = substr( $action, strlen( self::CONVERT_ACTION_PREFIX ) );
			$notice_args = $this->bulk_convert( $ids, $provider_id );
		} else {
			return;
		}

		// Redirect to a clean URL after processing, same reasoning as
		// the plugin's other form handlers (see ALM_Admin::save_settings())
		// -- without this, refreshing the page would resubmit the same
		// action=/alm_link[]= query args. Harmless here since both
		// actions are idempotent, but not good practice to leave in.
		$clean_url = remove_query_arg( array( 'action', 'action2', 'alm_link', '_wpnonce' ) );
		if ( $notice_args ) {
			$clean_url = add_query_arg( $notice_args, $clean_url );
		}
		wp_safe_redirect( $clean_url );
		exit;
	}

	/**
	 * Converts each selected row to $provider_id via the shared
	 * ALM_Link_Converter, one row at a time -- not a single batched
	 * query, since each conversion is a real write to the post's own
	 * content (ALM_Content_Adapter::replace_link()), not just a status
	 * flip. A row whose content changed underneath since the last scan
	 * comes back as a WP_Error and is left untouched rather than
	 * failing the whole batch -- counted as skipped, surfaced in the
	 * redirect notice rather than silently dropped.
	 *
	 * @param int[]  $ids
	 * @param string $provider_id
	 * @return array<string,int> Query args for the post-redirect notice.
	 */
	private function bulk_convert( array $ids, $provider_id ) {
		global $wpdb;
		$table        = ALM_Install::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql   = "SELECT * FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		$converted = 0;
		$skipped   = 0;
		foreach ( $items as $item ) {
			$result = $this->converter->convert( $item, $provider_id );
			if ( is_wp_error( $result ) ) {
				++$skipped;
			} else {
				++$converted;
			}
		}

		return array(
			'alm_converted' => $converted,
			'alm_skipped'   => $skipped,
		);
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
	/**
	 * On this WP core version, row_actions() (called below, from
	 * column_anchor_text()) already appends its own "Show more details"
	 * toggle-row button as part of its return value -- but
	 * single_row_columns() *also* unconditionally appends one of its own
	 * for the primary column via this method, producing two identical
	 * buttons in the same cell. Confirmed by diffing raw server-rendered
	 * HTML (not just the live DOM) against WP core's own
	 * class-wp-list-table.php source -- both row_actions() and
	 * handle_row_actions() independently emit the button. Deferring
	 * entirely to row_actions()'s own copy here, rather than trying to
	 * suppress it there (that method belongs to WP core, not this
	 * plugin).
	 *
	 * @param object|array $item
	 * @param string       $column_name
	 * @param string       $primary
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		return '';
	}

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

		// Opens the JS-driven Edit modal (assets/admin.js) -- data
		// attributes carry everything the modal needs to render without
		// a round-trip, since this row already has it all server-side.
		// href="#" with an explicit click handler, not a real link: this
		// never navigates, same as WP core's own inline-edit row actions.
		$actions['edit_link'] = sprintf(
			'<a href="#" class="alm-edit-link" data-id="%1$d" data-post-title="%2$s" data-url="%3$s" data-anchor="%4$s" data-provider="%5$s">%6$s</a>',
			(int) $item['id'],
			esc_attr( get_the_title( $item['post_id'] ) ),
			esc_attr( $item['url'] ),
			esc_attr( $item['anchor_text'] ),
			esc_attr( $item['provider'] ),
			esc_html__( 'Edit', 'affiliate-link-manager' )
		);

		if ( ALM_Install::STATUS_IGNORED !== $item['status'] ) {
			$actions['ignore'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->row_action_url( 'ignore', $item['id'] ) ), esc_html__( 'Ignore', 'affiliate-link-manager' ) );
		}

		// esc_attr() on the JSON string is load-bearing, not decorative:
		// wp_json_encode() delimits with double quotes, and this whole
		// thing sits inside an onclick="..." attribute that also uses
		// double quotes. Without escaping, the attribute value ends at
		// the JSON string's own opening quote, and everything after
		// that -- including "Manager's" own apostrophe once the parser
		// is already out of sync -- gets parsed as garbage bare
		// attributes, corrupting this element and, in the browser's
		// error-recovery parsing, cascading into duplicated markup
		// later in the row (confirmed live: two toggle-row buttons
		// instead of one, downstream of this exact corruption).
		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
			esc_url( $this->row_action_url( 'delete', $item['id'] ) ),
			esc_attr( wp_json_encode( __( 'Delete this link? This only removes it from Affiliate Link Manager\'s records -- it does not change the post.', 'affiliate-link-manager' ) ) ),
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
		return sprintf( '<span class="alm-badge alm-badge-status-%s">%s</span>', esc_attr( $item['status'] ), esc_html( self::status_label( $item['status'] ) ) );
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
