<?php
/**
 * Base class for an affiliate network provider.
 *
 * A provider knows how to recognize its own links (matches_url()) and,
 * where the network supports it, how to build a new tracked link for a
 * given product URL (wrap_url()). Registered into ALM_Provider_Registry;
 * see that class for how third-party providers plug in via the
 * 'alm_register_providers' filter.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ALM_Provider {

	/**
	 * Short, stable machine-readable identifier (e.g. 'shopmy'). Stored
	 * as the `provider` column value in the alm_links table -- must
	 * never change once links have been scanned under it.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Human-readable label for admin screens (e.g. "ShopMy").
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Does this URL belong to this provider's network?
	 *
	 * @param string $url Absolute URL found in post content.
	 * @return bool
	 */
	abstract public function matches_url( $url );

	/**
	 * Can this provider actually generate a new tracked link right now?
	 * False for classify-only providers (e.g. a legacy network this
	 * plugin recognizes but deliberately never offers to convert links
	 * into automatically), or when a real provider isn't configured yet.
	 *
	 * @return bool
	 */
	public function can_wrap() {
		return false;
	}

	/**
	 * Build a new tracked link for a product URL. Only meaningful when
	 * can_wrap() is true; the base implementation always refuses.
	 *
	 * @param string $url  The destination product URL to wrap.
	 * @param array  $args Optional provider-specific arguments (e.g. a
	 *                     collection ID). See the concrete provider for
	 *                     what it accepts.
	 * @return string|WP_Error The wrapped tracked URL, or a WP_Error
	 *                         explaining why it couldn't be built.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept named in the base signature so every subclass override documents the same contract; the base implementation itself always refuses regardless of input.
	public function wrap_url( $url, array $args = array() ) {
		return new WP_Error(
			'alm_provider_cannot_wrap',
			sprintf(
				/* translators: %s: provider label, e.g. "ShopMy" */
				__( '%s does not support creating new links.', 'affiliate-link-manager' ),
				$this->get_label()
			)
		);
	}

	/**
	 * Is this provider configured enough to be used (e.g. does it have
	 * an affiliate ID saved)? Providers with no required configuration
	 * (classify-only ones) should just return true.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}
}
