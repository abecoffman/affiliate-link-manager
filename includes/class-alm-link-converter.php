<?php
/**
 * Rewrites (or removes) a single wp_alm_links row's URL/provider.
 *
 * Three distinct entry points, for three distinct UIs:
 * - save_url() -- the Links screen's row-level Edit modal. The admin
 *   only ever edits a destination URL; the provider is always inferred
 *   from it via ALM_Provider_Registry::match_url(), the same matching
 *   logic the scanner itself uses -- never manually picked. This is
 *   also the only way to attach a link for a provider that can't build
 *   one itself (RewardStyle/LTK today): the admin generates the real
 *   tracked link on the network's own site and pastes the result in.
 * - convert() -- the "Convert to [Provider]" bulk action. A provider is
 *   chosen explicitly (only ever a can_wrap()-capable, configured one,
 *   see ALM_Links_List_Table::get_bulk_actions()) and wrap_url() builds
 *   a new tracked URL from whatever's already there.
 * - remove() -- "Remove from Post", for a confirmed-dead (status=stale)
 *   link. Unlike the two above, this doesn't rewrite the row -- it
 *   deletes it, since once the link is unwrapped out of the post
 *   there's nothing left to track.
 *
 * save_url()/convert() funnel through the same private
 * write_and_persist() so they can never drift on what "save" actually
 * does. Deliberately reuses ALM_Provider::wrap_url()/can_wrap()/match_url()
 * and ALM_Content_Adapter::replace_link()/remove_link() exactly as
 * already implemented and tested -- no changes to either contract. See
 * those classes' docblocks for the guarantees this relies on
 * (replace_link()/remove_link() both verify the old URL still matches
 * before writing and refuse via WP_Error if the content changed
 * underneath since the last scan).
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Link_Converter {

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	/**
	 * @var ALM_Adapter_Registry
	 */
	private $adapters;

	public function __construct( ALM_Provider_Registry $providers, ALM_Adapter_Registry $adapters ) {
		$this->providers = $providers;
		$this->adapters  = $adapters;
	}

	/**
	 * Saves an admin-provided URL verbatim and relabels the link with
	 * whatever provider it actually matches -- never a manual choice.
	 * A URL that doesn't match any registered network is a legitimate
	 * outcome, not an error: the link is saved as given, classified
	 * "unaffiliated" (the same fallback the scanner itself uses), not
	 * silently promoted to a real Affiliate Link it isn't.
	 *
	 * @param array  $item A full wp_alm_links row (ARRAY_A).
	 * @param string $url
	 * @return true|WP_Error
	 */
	public function save_url( array $item, $url ) {
		$provider = $this->providers->match_url( $url );

		return $this->write_and_persist( $item, $url, $provider->get_id() );
	}

	/**
	 * Removes a confirmed-dead link from the post entirely (unwraps the
	 * `<a>` tag, keeps its text) and, on success, deletes the tracking
	 * row -- once the link is out of the post there's nothing left to
	 * track, same reasoning the bulk Delete action already uses for a
	 * link an admin dismisses outright. Callers are expected to have
	 * already confirmed $item['status'] is stale before calling this
	 * (see ALM_Links_List_Table::bulk_remove()/ALM_Admin::handle_remove_link());
	 * this method itself doesn't re-check status, only that the content
	 * adapter can still find the link where the last scan left it.
	 *
	 * @param array $item A full wp_alm_links row (ARRAY_A).
	 * @return true|WP_Error
	 */
	public function remove( array $item ) {
		$adapter = $this->adapters->get_adapter( $item['adapter'] );
		if ( ! $adapter ) {
			return new WP_Error( 'alm_unknown_adapter', __( 'Unknown content adapter for this link.', 'affiliate-link-manager' ) );
		}

		$result = $adapter->remove_link( (int) $item['post_id'], $item['location'], $item['url'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		global $wpdb;
		$table = ALM_Install::table_name();
		$wpdb->delete( $table, array( 'id' => (int) $item['id'] ) );

		return true;
	}

	/**
	 * Wraps this link's existing URL for $provider_id (or, for a
	 * provider that can't wrap, just relabels the record) -- the bulk
	 * "Convert to [Provider]" action.
	 *
	 * @param array  $item        A full wp_alm_links row (ARRAY_A).
	 * @param string $provider_id Target provider id.
	 * @return true|WP_Error
	 */
	public function convert( array $item, $provider_id ) {
		$provider = $this->providers->get_provider( $provider_id );
		if ( ! $provider ) {
			return new WP_Error( 'alm_unknown_provider', __( 'Unknown provider.', 'affiliate-link-manager' ) );
		}

		if ( ! $provider->can_wrap() ) {
			return $this->reclassify( $item, $provider_id );
		}

		$new_url = $provider->wrap_url( $item['url'] );
		if ( is_wp_error( $new_url ) ) {
			return $new_url;
		}

		return $this->write_and_persist( $item, $new_url, $provider_id );
	}

	/**
	 * DB-only status/provider change -- the target provider can't build
	 * a URL (RewardStyle), so there's nothing to write into the post.
	 *
	 * @param array  $item
	 * @param string $provider_id
	 * @return true|WP_Error
	 */
	private function reclassify( array $item, $provider_id ) {
		global $wpdb;
		$table = ALM_Install::table_name();

		$updated = $wpdb->update(
			$table,
			array(
				'provider' => $provider_id,
				'status'   => ALM_Install::STATUS_ACTIVE,
			),
			array( 'id' => (int) $item['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated ? true : new WP_Error( 'alm_db_error', __( 'Could not update this link.', 'affiliate-link-manager' ) );
	}

	/**
	 * Writes $new_url into the real post content via this link's own
	 * content adapter, then updates the tracked record to match. Status
	 * follows the resolved provider: a real, recognized network means a
	 * real Affiliate Link; the "unclassified" fallback (no network
	 * recognized this URL) keeps the link in Other Outbound, never
	 * mislabeled as active just because an admin saved it.
	 *
	 * @param array  $item
	 * @param string $new_url
	 * @param string $provider_id
	 * @return true|WP_Error
	 */
	private function write_and_persist( array $item, $new_url, $provider_id ) {
		$adapter = $this->adapters->get_adapter( $item['adapter'] );
		if ( ! $adapter ) {
			return new WP_Error( 'alm_unknown_adapter', __( 'Unknown content adapter for this link.', 'affiliate-link-manager' ) );
		}

		$result = $adapter->replace_link( (int) $item['post_id'], $item['location'], $item['url'], $new_url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = ( ALM_Install::STATUS_UNCLASSIFIED === $provider_id ) ? ALM_Install::STATUS_UNCLASSIFIED : ALM_Install::STATUS_ACTIVE;

		$data   = array(
			'url'      => $new_url,
			'provider' => $provider_id,
			'status'   => $status,
		);
		$format = array( '%s', '%s', '%s' );

		// A product thumbnail cached against this link's *old*
		// destination must never linger once the URL itself has
		// actually changed (a manual URL edit, or wrap_url() building a
		// new tracked link) -- the next Edit modal open re-fetches fresh
		// for wherever this link points now. See ALM_Thumbnail_Fetcher.
		if ( $new_url !== $item['url'] ) {
			$data['thumbnail_url']        = null;
			$data['thumbnail_fetched_at'] = null;
			$format[]                     = '%s';
			$format[]                     = '%s';
		}

		global $wpdb;
		$table   = ALM_Install::table_name();
		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => (int) $item['id'] ),
			$format,
			array( '%d' )
		);

		// The post content was already updated successfully above -- a
		// failure here would leave the tracked record out of sync with
		// what's actually in the post, which the next full scan
		// self-heals (it re-reads content and upserts), but is still
		// worth surfacing rather than silently reporting success.
		return false !== $updated ? true : new WP_Error( 'alm_db_error', __( "Content was updated, but this link's record could not be. Rescan to resync.", 'affiliate-link-manager' ) );
	}
}
