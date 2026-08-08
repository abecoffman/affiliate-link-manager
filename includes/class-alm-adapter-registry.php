<?php
/**
 * Holds registered content adapters and picks the right one per post.
 *
 * The scanner (and everything else in this plugin) goes through this
 * registry rather than ever naming a specific page builder -- adding
 * support for another one later (Elementor, Divi, WPBakery, SiteOrigin,
 * ...) is a matter of writing one new ALM_Content_Adapter subclass and
 * registering it here or via the 'alm_register_content_adapters'
 * filter, with zero changes to the scanner or anything downstream of it.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Adapter_Registry {

	/**
	 * @var ALM_Content_Adapter[]
	 */
	private $adapters = array();

	/**
	 * @var ALM_Content_Adapter
	 */
	private $fallback;

	public function __construct() {
		$this->fallback = new ALM_Adapter_Post_Content();

		$adapters = array(
			new ALM_Adapter_Beaver_Builder(),
		);

		/**
		 * Filters the list of registered page-builder-specific content
		 * adapters. The generic post_content adapter is always appended
		 * last automatically and should not be included here -- it's
		 * the universal fallback every post supports.
		 *
		 * @since 1.0.0
		 *
		 * @param ALM_Content_Adapter[] $adapters
		 */
		$adapters = apply_filters( 'alm_register_content_adapters', $adapters );

		$this->adapters = $adapters;
	}

	/**
	 * @return ALM_Content_Adapter[] All registered adapters, fallback last.
	 */
	public function get_adapters() {
		return array_merge( $this->adapters, array( $this->fallback ) );
	}

	/**
	 * Ask each registered adapter, in order, whether it owns this post.
	 * Always returns something -- the generic post_content adapter
	 * supports every post, so it's the guaranteed last resort.
	 *
	 * @param int $post_id
	 * @return ALM_Content_Adapter
	 */
	public function get_adapter_for_post( $post_id ) {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->supports_post( $post_id ) ) {
				return $adapter;
			}
		}

		return $this->fallback;
	}
}
