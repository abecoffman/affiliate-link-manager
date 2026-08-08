<?php
/**
 * Generic content adapter: reads/writes plain wp_posts.post_content.
 *
 * The universal fallback -- every post has post_content, even ones a
 * page builder also stores elsewhere -- so this adapter always
 * registers last/lowest-priority in ALM_Adapter_Registry and supports
 * every post unconditionally.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Adapter_Post_Content extends ALM_Content_Adapter {

	use ALM_Html_Fragment_Trait;

	public function get_id() {
		return 'post_content';
	}

	public function get_label() {
		return __( 'Post content', 'affiliate-link-manager' );
	}

	public function supports_post( $post_id ) {
		return true;
	}

	public function get_links( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$links = array();
		foreach ( $this->parse_anchors( $post->post_content ) as $index => $anchor ) {
			$links[] = array(
				'location'    => (string) $index,
				'url'         => $anchor['href'],
				'anchor_text' => $anchor['text'],
			);
		}

		return $links;
	}

	public function replace_link( $post_id, $location, $old_url, $new_url ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'alm_post_not_found', __( 'Post not found.', 'affiliate-link-manager' ) );
		}

		$updated = $this->replace_anchor_href( $post->post_content, (int) $location, $old_url, $new_url );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
