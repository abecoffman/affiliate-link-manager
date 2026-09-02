<?php
/**
 * WP_List_Table subclass for the Links screen -- gives us real
 * pagination, column sorting, status view-tabs, and bulk actions for
 * free, the same way WordPress core's own Posts/Users/Plugins screens
 * work, instead of a hand-rolled <table>.
 *
 * Ignore/Delete are pure metadata operations on this plugin's own
 * table. Convert (both clicking a row's own Link cell, which opens
 * ALM_Admin::handle_edit_link()'s AJAX-backed modal, and the "Convert
 * to [provider]" bulk action below) is a real content-rewrite --
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

	// Deliberately the exact string WP_List_Table::display_tablenav()
	// already renders on its own ('bulk-' . $this->_args['plural'],
	// see the 'plural' => 'alm_links' constructor arg below) -- not an
	// arbitrary choice. A second, differently-named nonce field used to
	// be rendered explicitly in includes/views/links.php on top of that
	// one; both share the HTML name="_wpnonce", so a real form
	// submission sent both values and whichever landed last in the
	// query string silently won, failing check_admin_referer() here
	// against the *other* one -- a real 403 on every bulk action, found
	// live via an actual browser submission (Playwright), not caught by
	// any integration test (those call process_bulk_action() directly,
	// never rendering + submitting the real form). Matching this
	// constant to WP core's own auto-rendered nonce and deleting the
	// redundant explicit field is the fix -- exactly one nonce field,
	// exactly one action string, everywhere.
	const BULK_NONCE_ACTION     = 'bulk-alm_links';
	const CONVERT_ACTION_PREFIX = 'convert_';

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * @var ALM_Adapter_Registry
	 */
	private $adapters;

	/**
	 * @var ALM_Link_Converter
	 */
	private $converter;

	public function __construct( ALM_Provider_Registry $providers, ALM_Adapter_Registry $adapters, ALM_Link_Converter $converter ) {
		parent::__construct(
			array(
				'singular' => 'alm_link',
				'plural'   => 'alm_links',
				'ajax'     => false,
			)
		);
		$this->providers = $providers;
		$this->adapters  = $adapters;
		$this->converter = $converter;
	}

	/**
	 * Redesigned per explicit user feedback: Status dropped entirely
	 * (redundant with Affiliate -- a real network name already implies
	 * "this is a real Affiliate Link," "Unaffiliated" already implies
	 * it isn't, and the view-tabs above the table already communicate
	 * status by which tab you're on). "Provider" relabeled "Affiliate"
	 * to say plainly what it is. Last seen dropped -- ranking by
	 * "when the scanner last saw this," not anything about the post
	 * itself, wasn't a signal worth a column; see prepare_items() for
	 * what sorts the table by default instead.
	 *
	 * This one column set now covers what the separate Posts screen
	 * used to (which posts have which links) -- that screen was
	 * removed rather than kept as a second, mostly-overlapping way to
	 * see the same thing.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			// post (the primary column, see get_primary_column_name())
			// has to be the first column after the checkbox, not just
			// visually -- WP core's own responsive CSS hides every column
			// at <=782px via a `th.column-primary ~ th` *following*-sibling
			// selector. A primary column that isn't first can never match
			// that selector for the columns before it, so they're stuck
			// half-rendered on narrow screens instead of collapsing into
			// the row card like every other WP core list table. Confirmed
			// live, more than once this session, with a different column
			// out of order each time.
			'post'     => __( 'Post', 'affiliate-link-manager' ),
			'link'     => __( 'Link', 'affiliate-link-manager' ),
			'provider' => __( 'Affiliate', 'affiliate-link-manager' ),
			'url'      => __( 'URL', 'affiliate-link-manager' ),
		);
	}

	/**
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'post';
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
			'provider' => array( 'provider', false ),
		);
	}

	/**
	 * One "Convert to [Provider]" entry per registered, configured,
	 * can_wrap()-capable provider -- none of the currently-registered
	 * networks qualify (every one, ShopMy included, is classify-only;
	 * see each provider's own class docblock for why), so this list is
	 * empty in practice today. The mechanism stays in place for a
	 * future provider that does support it, gated the same way the
	 * row-level Edit modal is, so the two entry points could never
	 * offer a provider one can do that the other can't. Providers that
	 * can only reclassify don't get a bulk entry regardless -- that's a
	 * per-row decision made in the Edit modal, not something to fire at
	 * scale from a checkbox list.
	 *
	 * Grouped into two <optgroup>s (native WP_List_Table support since
	 * 5.6 -- a nested array value becomes an optgroup keyed by its own
	 * label) rather than one flat list -- reported live as genuinely
	 * confusing which of these four actions edits the live post and
	 * which only touches this plugin's own tracking data. "Delete" is
	 * relabeled "Delete Tracking Record" for the same reason, matching
	 * its own confirm text (see almAdmin.strings.deleteConfirm) that
	 * already explains this but wasn't reflected in the button itself.
	 *
	 * @return array<string,array<string,string>>
	 */
	protected function get_bulk_actions() {
		$edits_post = array();

		foreach ( $this->providers->get_providers() as $provider ) {
			if ( $provider->can_wrap() ) {
				$edits_post[ self::CONVERT_ACTION_PREFIX . $provider->get_id() ] = sprintf(
					/* translators: %s: provider label, e.g. "ShopMy" */
					__( 'Convert to %s', 'affiliate-link-manager' ),
					$provider->get_label()
				);
			}
		}

		// Only ever actually removes rows that are both
		// category=nonaffiliate AND modifier=dead -- see bulk_remove();
		// listed unconditionally here the same way Ignore/Delete are,
		// not gated per-tab.
		$edits_post['remove_dead_links'] = __( 'Remove from Post', 'affiliate-link-manager' );

		$tracking_only = array(
			'ignore' => __( 'Ignore', 'affiliate-link-manager' ),
			'delete' => __( 'Delete Tracking Record', 'affiliate-link-manager' ),
		);

		return array(
			__( 'Edits your post content', 'affiliate-link-manager' )          => $edits_post,
			__( 'Tracking only — your post is not changed', 'affiliate-link-manager' ) => $tracking_only,
		);
	}

	/**
	 * Single source of truth for section/tab-level display text -- used
	 * by get_views() (the tab bar) and the Dashboard, so the two can
	 * never drift out of sync on wording. Keyed by a UI-level tab
	 * identifier, not a raw stored category/modifier value directly --
	 * 'dead' and 'ignored' both mean category=nonaffiliate plus a
	 * specific modifier, not a value that exists on its own, the same
	 * way 'dead' already wasn't a real stored status value even before
	 * this table gained a modifier column at all. No per-row badge
	 * anymore (the Links table's Status column was removed -- redundant
	 * with Affiliate, and which tab you're on already says the status),
	 * so this only ever needs the one long form now, not a short/long
	 * pair.
	 *
	 * 'candidate' reads as "Candidate(s)" here, not literally the
	 * schema's own naming: it's a link ALM_Candidate_Classifier decided
	 * looks like a real affiliate-link opportunity, nothing converts it
	 * automatically.
	 *
	 * @param string $tab_key One of 'affiliate'|'candidate'|'nonaffiliate'|'dead'|'ignored'.
	 * @return string
	 */
	public static function tab_label( $tab_key ) {
		$labels = array(
			'affiliate'    => __( 'Affiliate Links', 'affiliate-link-manager' ),
			'candidate'    => __( 'Candidate Affiliate Links', 'affiliate-link-manager' ),
			'nonaffiliate' => __( 'Other Outbound Links', 'affiliate-link-manager' ),
			'dead'         => __( 'Dead Links', 'affiliate-link-manager' ),
			'ignored'      => __( 'Ignored', 'affiliate-link-manager' ),
		);

		return isset( $labels[ $tab_key ] ) ? $labels[ $tab_key ] : ucfirst( $tab_key );
	}

	/**
	 * Single source of truth for how a provider reads in admin UI --
	 * used by column_provider() (below), the Edit modal's live
	 * provider-match display, and its AJAX match endpoint
	 * (ALM_Admin::handle_match_provider()), so the three can never say
	 * different things about the same provider. ALM_Provider_Generic
	 * ("Unclassified") is the scanner's own always-matches fallback,
	 * not a real network -- reads as "Unaffiliated" everywhere an admin
	 * sees it as a *result*, distinct from how the three-tier status
	 * restructure already keeps it out of anywhere it'd read as a
	 * pickable destination.
	 *
	 * @param ALM_Provider $provider
	 * @return string
	 */
	public static function provider_display_label( ALM_Provider $provider ) {
		return $provider instanceof ALM_Provider_Generic
			? __( 'Unaffiliated', 'affiliate-link-manager' )
			: $provider->get_label();
	}

	/**
	 * Status view-tabs above the table ("All | Candidate Affiliate
	 * Links | Affiliate Links | ...", same pattern as Posts' "All |
	 * Published | Drafts"). Other Outbound Links (a plain, unmodified
	 * nonaffiliate link) deliberately never gets a tab here at all,
	 * regardless of count -- it's noise (internal nav, social icons,
	 * reference sites), not something to browse; the Dashboard
	 * Overview is the one place its count is shown. Every other empty
	 * tab is hidden rather than shown as a permanent "(0)", keeping the
	 * tab bar from accumulating tabs nobody's ever actually seen.
	 *
	 * "Dead" and "Ignored" both mean category=nonaffiliate plus a
	 * specific modifier -- see ALM_Install::count_confirmed_dead() and
	 * this table's own tab_label() docblock.
	 *
	 * The *other* nonaffiliate modifier -- stale, a link the last scan
	 * simply didn't rediscover -- deliberately has no tab of its own
	 * anywhere, at any count. It can never resolve into anything else
	 * on its own (only Candidates ever get health-checked, and a stale
	 * row is never a Candidate), so showing it as something to review
	 * would be asking an admin to interpret internal scan bookkeeping
	 * with nothing real to decide. It's cleaned up quietly by cron
	 * instead (see alm_run_domain_recheck_cron()'s docblock) --
	 * reported live as "the maintenance of the index really needs to
	 * be abstracted from the user."
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		global $wpdb;
		$table = ALM_Install::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name, not user input; small admin-only aggregate query.
		$rows = $wpdb->get_results( "SELECT category, modifier, COUNT(*) as total FROM {$table} GROUP BY category, modifier", ARRAY_A );

		$counts = array(
			ALM_Install::CATEGORY_CANDIDATE => 0,
			ALM_Install::CATEGORY_AFFILIATE => 0,
			ALM_Install::MODIFIER_IGNORED   => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( ALM_Install::CATEGORY_CANDIDATE === $row['category'] ) {
				$counts[ ALM_Install::CATEGORY_CANDIDATE ] = (int) $row['total'];
			} elseif ( ALM_Install::CATEGORY_AFFILIATE === $row['category'] ) {
				$counts[ ALM_Install::CATEGORY_AFFILIATE ] = (int) $row['total'];
			} elseif ( ALM_Install::MODIFIER_IGNORED === $row['modifier'] ) {
				$counts[ ALM_Install::MODIFIER_IGNORED ] = (int) $row['total'];
			}
		}
		// Not folded into the loop above -- see its own docblock for why
		// this needs the compound category+modifier query it already is,
		// not just another bucket of the same GROUP BY.
		$counts[ ALM_Install::MODIFIER_DEAD ] = ALM_Install::count_confirmed_dead();

		// "All" here means every tab a user can actually browse, not
		// literally every row.
		$total = array_sum( $counts );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection, not a state-changing action.
		$current  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$base_url = remove_query_arg( array( 'status', 'paged' ) );

		// Candidates listed first -- it's the tab most worth an editor's
		// attention, not an alphabetical/schema accident. "Dead" sits
		// right before Ignored -- the last real, actionable tab.
		$tab_order = array(
			ALM_Install::CATEGORY_CANDIDATE,
			ALM_Install::CATEGORY_AFFILIATE,
			ALM_Install::MODIFIER_DEAD,
			ALM_Install::MODIFIER_IGNORED,
		);

		$views = array(
			'all' => $this->render_view_link( '', __( 'All', 'affiliate-link-manager' ), $total, $current, $base_url ),
		);
		foreach ( $tab_order as $tab_key ) {
			if ( 0 === $counts[ $tab_key ] ) {
				continue;
			}
			$views[ $tab_key ] = $this->render_view_link( $tab_key, self::tab_label( $tab_key ), $counts[ $tab_key ], $current, $base_url );
		}

		return $views;
	}

	/**
	 * @param string $tab_key  '' for "All".
	 * @param string $label
	 * @param int    $count
	 * @param string $current  The currently-active ?status= value, '' for "All".
	 * @param string $base_url
	 * @return string
	 */
	private function render_view_link( $tab_key, $label, $count, $current, $base_url ) {
		$url   = '' === $tab_key ? $base_url : add_query_arg( 'status', $tab_key, $base_url );
		$class = ( $current === $tab_key ) ? ' class="current"' : '';

		return sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $url ),
			$class,
			esc_html( $label ),
			$count
		);
	}

	/**
	 * A provider filter dropdown alongside the status tabs -- WP_List_Table
	 * doesn't generate arbitrary filters itself, this is the standard
	 * extension point core screens (e.g. Posts' category dropdown) use.
	 * Unlike the Edit modal's provider display (which never shows
	 * ALM_Provider_Generic at all -- it's never a real pick target),
	 * this dropdown keeps it, labeled "Unaffiliated" via the same
	 * provider_display_label() every other provider label in this
	 * table goes through: filtering *by* "not yet attached to a real
	 * network" is a genuinely useful view (most Candidates are exactly
	 * this), unlike converting a link *to* it.
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
						<?php echo esc_html( self::provider_display_label( $provider ) ); ?>
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
		$table       = ALM_Install::table_name();
		$posts_table = $wpdb->posts;

		// "alm." throughout below isn't defensive over-qualification --
		// it's load-bearing now that this query joins wp_posts, so a
		// bare column name can never silently resolve to the wrong
		// table's column if a future WP core version adds one with the
		// same name.
		$where  = array( '1=1' );
		$params = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters, not a state-changing action.
		$status_param = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ALM_Install::CATEGORY_AFFILIATE === $status_param || ALM_Install::CATEGORY_CANDIDATE === $status_param ) {
			$where[]  = 'alm.category = %s';
			$params[] = $status_param;
		} elseif ( ALM_Install::MODIFIER_DEAD === $status_param || ALM_Install::MODIFIER_IGNORED === $status_param ) {
			$where[]  = 'alm.category = %s AND alm.modifier = %s';
			$params[] = ALM_Install::CATEGORY_NONAFFILIATE;
			$params[] = $status_param;
		} else {
			// "All" -- the unfiltered default, and the fallback for any
			// unrecognized ?status= value (including a stale bookmark
			// using one of the old raw status strings this table used
			// before -- low priority, not part of any real flow anymore,
			// safer to fall through to "All" than to try to keep
			// interpreting retired values). Means every browsable tab,
			// not literally every row: a plain (modifier IS NULL)
			// nonaffiliate link is noise, never part of the default/
			// browsable view (only a summary count on the Dashboard),
			// and a nonaffiliate+stale row is pure housekeeping (see
			// get_views()'s own docblock) -- neither shown here either.
			$where[]  = 'NOT ( alm.category = %s AND ( alm.modifier IS NULL OR alm.modifier = %s ) )';
			$params[] = ALM_Install::CATEGORY_NONAFFILIATE;
			$params[] = ALM_Install::MODIFIER_STALE;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['provider'] ) ) {
			$where[]  = 'alm.provider = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['provider'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, e.g. a manually-constructed link filtered to one post.
		if ( ! empty( $_GET['post_id'] ) ) {
			$where[]  = 'alm.post_id = %d';
			$params[] = absint( wp_unslash( $_GET['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) ) {
			$search   = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(alm.url LIKE %s OR alm.anchor_text LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Only "Affiliate" is a clickable sortable column now (Status and
		// Last seen are both gone -- see get_columns()). Default order is
		// by the *post's* publish date, not anything on this plugin's own
		// table -- explicit product decision: rank by the posts, not by
		// when the scanner happened to last see a link. A real engagement
		// metric (pageviews, etc.) is a planned future replacement for
		// this default; post date is what's available with zero new
		// infrastructure today.
		$allowed_orderby = array(
			'provider'  => 'alm.provider',
			'post_date' => 'p.post_date',
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort selection.
		$orderby_key = ( isset( $_GET['orderby'] ) && isset( $allowed_orderby[ sanitize_key( wp_unslash( $_GET['orderby'] ) ) ] ) ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'post_date'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby_sql = $allowed_orderby[ $orderby_key ];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Count doesn't need the join -- every filter above is on alm's
		// own columns.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + column names are fixed/allow-listed above, never raw user input; real values go through prepare() below.
		$count_sql   = "SELECT COUNT(*) FROM {$table} alm WHERE {$where_sql}";
		$total_items = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql has no raw user input when $params is empty.

		$offset = ( $current_page - 1 ) * $per_page;
		// LEFT JOIN, not INNER -- a link whose post was deleted after
		// being scanned must still show up (with $orderby_sql='p.post_date'
		// naturally sorting it as if p.post_date is NULL, i.e. last),
		// rather than silently vanishing from the table entirely.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above; $orderby_sql/$order are allow-listed, not raw user input.
		$data_sql    = "SELECT alm.* FROM {$table} alm LEFT JOIN {$posts_table} p ON p.ID = alm.post_id WHERE {$where_sql} ORDER BY {$orderby_sql} {$order} LIMIT %d OFFSET %d";
		$data_params = array_merge( $params, array( $per_page, $offset ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		// column_post()/column_link() call get_the_title()/
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
			$sql    = "UPDATE {$table} SET category = %s, modifier = %s, classified_at = %s WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
			$params = array_merge( array( ALM_Install::CATEGORY_NONAFFILIATE, ALM_Install::MODIFIER_IGNORED, current_time( 'mysql' ) ), $ids );
			$wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		} elseif ( 'delete' === $action ) {
			$sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above.
			$wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.
		} elseif ( 0 === strpos( $action, self::CONVERT_ACTION_PREFIX ) ) {
			$provider_id = substr( $action, strlen( self::CONVERT_ACTION_PREFIX ) );
			$notice_args = $this->bulk_convert( $ids, $provider_id );
		} elseif ( 'remove_dead_links' === $action ) {
			$notice_args = $this->bulk_remove( $ids );
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
	 * Unwraps each selected row's link out of its post entirely, via the
	 * shared ALM_Link_Converter -- one row at a time, same reasoning as
	 * bulk_convert() (a real content write, not a batched query). Only
	 * ever actually processes rows that are both category=nonaffiliate
	 * AND modifier=dead -- a link merely not-rediscovered (stale, no
	 * modifier=dead) has nothing to unwrap from a scan's perspective,
	 * and isn't something "Remove from Post" should ever touch -- see
	 * get_views()'s own docblock. Selecting a mix and running this
	 * doesn't silently strip a link that isn't confirmed dead. Anything
	 * skipped (wrong category/modifier, or a content-changed-since-scan
	 * WP_Error) is counted and surfaced in the redirect notice, same
	 * "leave it alone and say why" convention as bulk_convert().
	 *
	 * @param int[] $ids
	 * @return array<string,int> Query args for the post-redirect notice.
	 */
	private function bulk_remove( array $ids ) {
		global $wpdb;
		$table        = ALM_Install::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql   = "SELECT * FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a fixed run of %d tokens, not user input; real values bound via prepare() below.
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built entirely from prepare() above.

		$removed = 0;
		$skipped = 0;
		foreach ( $items as $item ) {
			if ( ALM_Install::CATEGORY_NONAFFILIATE !== $item['category'] || ALM_Install::MODIFIER_DEAD !== $item['modifier'] ) {
				++$skipped;
				continue;
			}

			$result = $this->converter->remove( $item );
			if ( is_wp_error( $result ) ) {
				++$skipped;
			} else {
				++$removed;
			}
		}

		return array(
			'alm_removed'        => $removed,
			'alm_remove_skipped' => $skipped,
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
		$label    = $provider ? self::provider_display_label( $provider ) : $item['provider'];

		return sprintf( '<span class="alm-badge alm-badge-%s">%s</span>', esc_attr( $item['provider'] ), esc_html( $label ) );
	}

	/**
	 * Primary column: the post title, linking straight to its editor.
	 * No row_actions() here on purpose -- View/Ignore/Delete used to
	 * live here as hover-revealed row actions, but per explicit
	 * feedback ("these commands don't really make sense here anymore")
	 * they've all moved into the Edit modal itself (see column_link()'s
	 * data-view-url/data-ignore-url/data-delete-url and
	 * assets/admin.js). No handle_row_actions() override either, for
	 * the same reason it existed before is now moot: that override
	 * used to suppress WP core's own default toggle-row-button
	 * behavior for the primary column, needed only because this method
	 * *also* called row_actions() (which appends its own copy of that
	 * button unconditionally) -- two buttons, one row. With no manual
	 * row_actions() call left in this column at all, WP core's own
	 * default handle_row_actions() (unoverridden) adds exactly one
	 * toggle-row button for the primary column, correctly, on its own.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_post( $item ) {
		$title     = get_the_title( $item['post_id'] );
		$title     = $title ? $title : '#' . $item['post_id'];
		$edit_link = get_edit_post_link( $item['post_id'], 'raw' );

		return $edit_link
			? sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $title ) )
			: esc_html( $title );
	}

	/**
	 * The link's own text -- clicking it opens the JS-driven Edit modal
	 * (assets/admin.js) directly, no separate "Edit" action needed. Data
	 * attributes carry everything the modal needs to render without a
	 * round-trip, since this row already has it all server-side (the
	 * one exception is re-matching the provider live as the admin edits
	 * the URL, which does need a round-trip -- see
	 * ALM_Admin::handle_match_provider()). href="#" with an explicit
	 * click handler, not a real link: this never navigates, same as WP
	 * core's own inline-edit actions.
	 *
	 * View/Ignore/Delete are also carried here as data-*-url attributes
	 * now, not rendered as row actions anywhere -- they live inside the
	 * modal itself (per explicit feedback that they didn't make sense
	 * as row actions once Post/Link split the way they did). Reuses the
	 * exact same nonce'd row_action_url() links the old row actions
	 * used; only where they're rendered changed, not how they work.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_link( $item ) {
		$edit_link    = get_edit_post_link( $item['post_id'], 'raw' );
		$view_link    = get_permalink( $item['post_id'] );
		$provider_obj = $this->providers->get_provider( $item['provider'] );
		$context      = $this->get_link_context( $item );

		return sprintf(
			'<a href="#" class="alm-edit-link" data-id="%1$d" data-post-title="%2$s" data-post-edit-url="%3$s" data-view-url="%4$s" data-ignore-url="%5$s" data-delete-url="%6$s" data-category="%7$s" data-modifier="%8$s" data-url="%9$s" data-resolved-url="%10$s" data-anchor="%11$s" data-provider="%12$s" data-provider-label="%13$s" data-context-before="%14$s" data-context-after="%15$s" data-thumbnail-url="%16$s" data-thumbnail-fetched="%17$s">%18$s</a>',
			(int) $item['id'],
			esc_attr( get_the_title( $item['post_id'] ) ),
			esc_attr( $edit_link ? $edit_link : '' ),
			esc_attr( $view_link ? $view_link : '' ),
			esc_attr( $this->row_action_url( 'ignore', $item['id'] ) ),
			esc_attr( $this->row_action_url( 'delete', $item['id'] ) ),
			esc_attr( $item['category'] ),
			// data-modifier drives the Edit modal's Ignore/Remove-from-Post
			// visibility toggles directly ('ignored'/'dead') -- no separate
			// boolean attribute needed for either anymore.
			esc_attr( $item['modifier'] ? $item['modifier'] : '' ),
			esc_attr( $item['url'] ),
			// Only meaningful for a shortened link that's been through
			// ALM_Shortener_Scanner -- see ALM_Install::create_table()'s
			// docblock for why this lives directly on the link row, not
			// a shared cache table.
			esc_attr( ! empty( $item['resolved_url'] ) ? $item['resolved_url'] : '' ),
			esc_attr( $item['anchor_text'] ),
			esc_attr( $item['provider'] ),
			esc_attr( $provider_obj ? self::provider_display_label( $provider_obj ) : $item['provider'] ),
			esc_attr( $context ? $context['before'] : '' ),
			esc_attr( $context ? $context['after'] : '' ),
			// Same per-link caching shape as resolved_url above, via
			// ALM_Thumbnail_Fetcher -- data-thumbnail-fetched lets the JS
			// tell "never attempted" (fetch on open) apart from "attempted,
			// found nothing" (show the empty state, never re-fetch).
			esc_attr( ! empty( $item['thumbnail_url'] ) ? $item['thumbnail_url'] : '' ),
			esc_attr( ! empty( $item['thumbnail_fetched_at'] ) ? '1' : '' ),
			esc_html( $item['anchor_text'] )
		);
	}

	/**
	 * Best-effort "as it reads in the post" context for the Edit
	 * modal's Link text display -- see
	 * ALM_Content_Adapter::get_context()'s docblock for why this can
	 * legitimately come back null (a third-party adapter that doesn't
	 * implement it, or a link the adapter can no longer locate).
	 *
	 * @param array $item
	 * @return array{before:string,text:string,after:string}|null
	 */
	private function get_link_context( array $item ) {
		$adapter = $this->adapters->get_adapter( $item['adapter'] );
		if ( ! $adapter ) {
			return null;
		}

		return $adapter->get_context( (int) $item['post_id'], $item['location'] );
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
	 * @param array  $item
	 * @param string $column_name
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * WP_List_Table's own default "No items found." is a dead end for a
	 * brand-new install that's never run a scan -- points back at the
	 * Dashboard's Run Scan action instead of just saying nothing was
	 * found. A filtered/searched empty result also lands here (WP core
	 * gives no_items() no way to distinguish the two cases either), but
	 * a pointer back to the Dashboard is harmless noise in that case,
	 * not actively misleading.
	 *
	 * @return void
	 */
	public function no_items() {
		printf(
			/* translators: 1: opening <a> tag to the Dashboard screen, 2: closing </a> tag */
			esc_html__( 'No links found yet. Head to the %1$sDashboard%2$s to run your first scan.', 'affiliate-link-manager' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . ALM_Admin::MENU_SLUG ) ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() applied above; this is a fixed, self-authored anchor tag, not user input.
			'</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed closing tag, not user input.
		);
	}
}
