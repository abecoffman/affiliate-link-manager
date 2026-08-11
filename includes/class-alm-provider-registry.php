<?php
/**
 * Holds registered affiliate network providers and matches URLs against
 * them in priority order.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Provider_Registry {

	/**
	 * @var ALM_Provider[]
	 */
	private $providers = array();

	public function __construct() {
		$providers = array(
			new ALM_Provider_ShopMy(),
			new ALM_Provider_RewardStyle(),
			new ALM_Provider_Amazon(),
			new ALM_Provider_CJ(),
			new ALM_Provider_Rakuten(),
			new ALM_Provider_ShopStyle(),
		);

		/**
		 * Filters the list of registered affiliate network providers.
		 * Do not include the generic fallback here -- it's always
		 * appended last automatically, after this filter runs, so it
		 * only ever wins when nothing else (including a third-party
		 * provider) matched.
		 *
		 * @since 1.0.0
		 *
		 * @param ALM_Provider[] $providers Providers in match-priority order.
		 */
		$providers = apply_filters( 'alm_register_providers', $providers );

		$providers[] = new ALM_Provider_Generic();

		$this->providers = $providers;
	}

	/**
	 * @return ALM_Provider[]
	 */
	public function get_providers() {
		return $this->providers;
	}

	/**
	 * Find the first registered provider that claims this URL. Always
	 * returns something, since the generic fallback matches everything.
	 *
	 * @param string $url
	 * @return ALM_Provider
	 */
	public function match_url( $url ) {
		foreach ( $this->providers as $provider ) {
			if ( $provider->matches_url( $url ) ) {
				return $provider;
			}
		}

		// Unreachable in practice -- the generic fallback always matches
		// -- but keep a safe default in case a filter removed it.
		return new ALM_Provider_Generic();
	}

	/**
	 * @param string $id
	 * @return ALM_Provider|null
	 */
	public function get_provider( $id ) {
		foreach ( $this->providers as $provider ) {
			if ( $provider->get_id() === $id ) {
				return $provider;
			}
		}

		return null;
	}
}
