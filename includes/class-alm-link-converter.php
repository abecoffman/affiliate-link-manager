<?php
/**
 * Rewrites a single wp_alm_links row's URL and/or provider -- the
 * shared logic behind both the Links screen's single-row "Edit" modal
 * and its "Convert to [Provider]" bulk action, so the two never drift
 * out of sync on what "convert" actually does.
 *
 * Three distinct things can happen, chosen automatically by what was
 * actually submitted rather than three separate methods to keep
 * straight:
 * - An explicit replacement URL was submitted (differs from the row's
 *   own stored URL): write it verbatim, no wrap_url() involved. This
 *   is the *only* way to attach a link for a provider that can't build
 *   one itself (RewardStyle/LTK today) -- the admin generates the real
 *   tracked link on the network's own site and pastes the result in
 *   here.
 * - No explicit URL (or it matches what's already stored) and the
 *   target provider can_wrap(): call wrap_url() to build a new tracked
 *   URL from the current one.
 * - No explicit URL and the target provider can't wrap: a DB-only
 *   reclassify, content untouched.
 *
 * Deliberately reuses ALM_Provider::wrap_url()/can_wrap() and
 * ALM_Content_Adapter::replace_link() exactly as already implemented
 * and tested -- no changes to either contract. See those classes'
 * docblocks for the guarantees this relies on (replace_link() verifies
 * the old URL still matches before writing and refuses via WP_Error if
 * the content changed underneath since the last scan).
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
	 * @param array       $item        A full wp_alm_links row (ARRAY_A).
	 * @param string      $provider_id Provider id to label the link with.
	 * @param string|null $url         An explicit replacement URL (e.g.
	 *                                 pasted in from the network's own
	 *                                 dashboard). Null/unchanged means
	 *                                 "use whatever's already there."
	 * @return true|WP_Error
	 */
	public function convert( array $item, $provider_id, $url = null ) {
		$provider = $this->providers->get_provider( $provider_id );
		if ( ! $provider ) {
			return new WP_Error( 'alm_unknown_provider', __( 'Unknown provider.', 'affiliate-link-manager' ) );
		}

		if ( null !== $url && $url !== $item['url'] ) {
			return $this->write_and_persist( $item, $url, $provider_id );
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
	 * (RewardStyle) or wasn't given (Generic) a URL, so there's nothing
	 * to write into the post. Exactly the existing classify-only
	 * behavior, just triggered by an admin instead of the scanner.
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
	 * content adapter, then updates the tracked record to match.
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

		global $wpdb;
		$table   = ALM_Install::table_name();
		$updated = $wpdb->update(
			$table,
			array(
				'url'      => $new_url,
				'provider' => $provider_id,
				'status'   => ALM_Install::STATUS_ACTIVE,
			),
			array( 'id' => (int) $item['id'] ),
			array( '%s', '%s', '%s' ),
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
