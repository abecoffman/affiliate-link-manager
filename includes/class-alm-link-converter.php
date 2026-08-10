<?php
/**
 * Converts a single wp_alm_links row to a different provider -- the
 * shared logic behind both the Links screen's single-row "Edit" modal
 * and its "Convert to [Provider]" bulk action, so the two never drift
 * out of sync on what "convert" actually does.
 *
 * Deliberately reuses ALM_Provider::wrap_url()/can_wrap() and
 * ALM_Content_Adapter::replace_link() exactly as already implemented
 * and tested -- no changes to either contract. See those classes'
 * docblocks for the guarantees this relies on (replace_link() verifies
 * the old URL still matches before writing and refuses via WP_Error if
 * the content changed underneath since the last scan; wrap_url() is a
 * pure string transform for ShopMy, no outbound request).
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
	 * @param array  $item        A full wp_alm_links row (ARRAY_A).
	 * @param string $provider_id Target provider id.
	 * @return true|WP_Error
	 */
	public function convert( array $item, $provider_id ) {
		$provider = $this->providers->get_provider( $provider_id );
		if ( ! $provider ) {
			return new WP_Error( 'alm_unknown_provider', __( 'Unknown provider.', 'affiliate-link-manager' ) );
		}

		global $wpdb;
		$table = ALM_Install::table_name();

		// Reclassify-only path: the target provider can't build a new
		// URL (RewardStyle, Generic), so this is a DB-only status/provider
		// change -- exactly the existing classify-only behavior, just
		// triggered by an admin instead of the scanner.
		if ( ! $provider->can_wrap() ) {
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

		$new_url = $provider->wrap_url( $item['url'] );
		if ( is_wp_error( $new_url ) ) {
			return $new_url;
		}

		$adapter = $this->adapters->get_adapter( $item['adapter'] );
		if ( ! $adapter ) {
			return new WP_Error( 'alm_unknown_adapter', __( 'Unknown content adapter for this link.', 'affiliate-link-manager' ) );
		}

		$result = $adapter->replace_link( (int) $item['post_id'], $item['location'], $item['url'], $new_url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

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
