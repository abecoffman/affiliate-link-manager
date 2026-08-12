<?php
/**
 * Beaver Builder content adapter.
 *
 * The one page-builder-specific adapter shipped in this initial build,
 * covering a real, verified case: on this project's first target site,
 * Beaver Builder stores each post's actual content as a flat array of
 * node objects in postmeta (_fl_builder_data for the published layout,
 * _fl_builder_draft for an unpublished draft) and re-renders the front
 * end from that data on every page load (FLBuilder::render_content())
 * whenever _fl_builder_enabled is set. wp_posts.post_content is only a
 * cached copy Beaver Builder itself regenerates at save time
 * (FLBuilder::render_editor_content()), used for search/RSS/REST -- not
 * the live page. Writing to post_content alone would silently not
 * appear on the site. Confirmed via direct inspection that of Beaver
 * Builder's ~40 module types, only 'rich-text' modules carry real links
 * on that site (photo/heading/video modules don't) -- this adapter only
 * looks inside those.
 *
 * supports_post() -- not the scanner -- owns all of this detection, per
 * this plugin's adapter-registry design (see ALM_Adapter_Registry): on
 * a site without Beaver Builder active, supports_post() always returns
 * false and every post falls through to the generic post_content
 * adapter instead, with no special-casing anywhere else in the plugin.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Adapter_Beaver_Builder extends ALM_Content_Adapter {

	use ALM_Html_Fragment_Trait;

	public function get_id() {
		return 'beaver_builder';
	}

	public function get_label() {
		return __( 'Beaver Builder', 'affiliate-link-manager' );
	}

	public function supports_post( $post_id ) {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return false;
		}

		return (bool) get_post_meta( $post_id, '_fl_builder_enabled', true );
	}

	public function get_links( $post_id ) {
		$links = array();

		foreach ( $this->get_rich_text_nodes( $post_id ) as $node_id => $text ) {
			foreach ( $this->parse_anchors( $text ) as $index => $anchor ) {
				$links[] = array(
					'location'    => $node_id . ':' . $index,
					'url'         => $anchor['href'],
					'anchor_text' => $anchor['text'],
				);
			}
		}

		return $links;
	}

	public function replace_link( $post_id, $location, $old_url, $new_url ) {
		$parts = explode( ':', $location, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'alm_bad_location', __( 'Invalid link location.', 'affiliate-link-manager' ) );
		}
		list( $node_id, $anchor_index ) = $parts;

		$data = FLBuilderModel::get_layout_data( 'published', $post_id );

		if ( ! $this->is_rich_text_node( $data, $node_id ) ) {
			return new WP_Error(
				'alm_node_not_found',
				__( 'That content block no longer exists -- the post may have been edited since the last scan. Re-scan and try again.', 'affiliate-link-manager' )
			);
		}

		$current_text = (string) $data[ $node_id ]->settings->text;
		$new_text     = $this->replace_anchor_href( $current_text, (int) $anchor_index, $old_url, $new_url );

		if ( is_wp_error( $new_text ) ) {
			return $new_text;
		}

		$data[ $node_id ]->settings->text = $new_text;

		FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		// Keep post_content (Beaver Builder's cached HTML, used for
		// search/RSS/REST) in sync with the real layout data we just
		// changed -- this is exactly what Beaver Builder itself does on
		// every save made through its own editor.
		if ( class_exists( 'FLBuilder' ) && method_exists( 'FLBuilder', 'render_editor_content' ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => FLBuilder::render_editor_content(),
				)
			);
		}

		if ( method_exists( 'FLBuilderModel', 'delete_all_asset_cache' ) ) {
			FLBuilderModel::delete_all_asset_cache( $post_id );
		}

		return true;
	}

	public function remove_link( $post_id, $location, $old_url ) {
		$parts = explode( ':', $location, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'alm_bad_location', __( 'Invalid link location.', 'affiliate-link-manager' ) );
		}
		list( $node_id, $anchor_index ) = $parts;

		$data = FLBuilderModel::get_layout_data( 'published', $post_id );

		if ( ! $this->is_rich_text_node( $data, $node_id ) ) {
			return new WP_Error(
				'alm_node_not_found',
				__( 'That content block no longer exists -- the post may have been edited since the last scan. Re-scan and try again.', 'affiliate-link-manager' )
			);
		}

		$current_text = (string) $data[ $node_id ]->settings->text;
		$new_text     = $this->unwrap_anchor( $current_text, (int) $anchor_index, $old_url );

		if ( is_wp_error( $new_text ) ) {
			return $new_text;
		}

		$data[ $node_id ]->settings->text = $new_text;

		FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		// Same post_content resync as replace_link() -- see its own
		// comment for why this has to happen here too, not just on the
		// real layout data.
		if ( class_exists( 'FLBuilder' ) && method_exists( 'FLBuilder', 'render_editor_content' ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => FLBuilder::render_editor_content(),
				)
			);
		}

		if ( method_exists( 'FLBuilderModel', 'delete_all_asset_cache' ) ) {
			FLBuilderModel::delete_all_asset_cache( $post_id );
		}

		return true;
	}

	public function get_context( $post_id, $location ) {
		$parts = explode( ':', $location, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		list( $node_id, $anchor_index ) = $parts;

		$data = FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( ! $this->is_rich_text_node( $data, $node_id ) ) {
			return null;
		}

		return $this->get_anchor_context( (string) $data[ $node_id ]->settings->text, (int) $anchor_index );
	}

	/**
	 * @param int $post_id
	 * @return array<string,string> node_id => raw HTML text, for every
	 *                              rich-text module in this post.
	 */
	private function get_rich_text_nodes( $post_id ) {
		$data = FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$nodes = array();
		foreach ( array_keys( $data ) as $node_id ) {
			if ( $this->is_rich_text_node( $data, $node_id ) ) {
				$nodes[ $node_id ] = (string) $data[ $node_id ]->settings->text;
			}
		}

		return $nodes;
	}

	/**
	 * @param array  $data    Layout data as returned by get_layout_data().
	 * @param string $node_id
	 * @return bool
	 */
	private function is_rich_text_node( $data, $node_id ) {
		if ( ! isset( $data[ $node_id ] ) ) {
			return false;
		}

		$node = $data[ $node_id ];

		return isset( $node->type ) && 'module' === $node->type
			&& isset( $node->settings->type ) && 'rich-text' === $node->settings->type
			&& isset( $node->settings->text );
	}
}
