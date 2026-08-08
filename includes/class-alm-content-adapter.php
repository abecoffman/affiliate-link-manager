<?php
/**
 * Base class for a content adapter.
 *
 * An adapter knows how to find and rewrite links within one particular
 * way of storing post content (plain post_content HTML, a page
 * builder's own structured data, etc). Adapters self-declare which
 * posts they handle via supports_post() and register into
 * ALM_Adapter_Registry, which decides -- per post -- which single
 * adapter owns it. Nothing outside an adapter's own file should ever
 * need to know which specific content-storage system it wraps; the
 * scanner and everything else only deal with this generic interface.
 * This is deliberate: a site could use any page builder (or none), and
 * the plugin's core logic must never hardcode which one.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ALM_Content_Adapter {

	/**
	 * Short, stable machine-readable identifier (e.g. 'beaver_builder').
	 * Stored as the `adapter` column value in the alm_links table.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Human-readable label for admin screens.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Does this adapter know how to read/write this post's content?
	 * Each adapter owns its own detection logic entirely -- a check
	 * against a specific postmeta key, a specific plugin's classes
	 * being loaded, a shortcode signature inside post_content, whatever
	 * is appropriate for the content-storage system this adapter wraps.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	abstract public function supports_post( $post_id );

	/**
	 * Find every link in this post's content.
	 *
	 * @param int $post_id
	 * @return array[] List of associative arrays, each:
	 *                 @type string $location    Opaque handle identifying
	 *                                            where this link lives.
	 *                                            Meaningless outside this
	 *                                            adapter; pass it back
	 *                                            verbatim to replace_link().
	 *                 @type string $url          The href value found.
	 *                 @type string $anchor_text  The link's visible text.
	 */
	abstract public function get_links( $post_id );

	/**
	 * Replace one link's href in place, persisting the change through
	 * whatever mechanism this content-storage system actually needs --
	 * not necessarily a simple string replace in post_content. See the
	 * Beaver Builder adapter for a concrete example of why.
	 *
	 * @param int    $post_id
	 * @param string $location The opaque handle from get_links().
	 * @param string $old_url  The href expected to currently be there --
	 *                         implementations must verify this and
	 *                         refuse (WP_Error) if the content has
	 *                         changed underneath since the last scan.
	 * @param string $new_url  The href to replace it with.
	 * @return true|WP_Error
	 */
	abstract public function replace_link( $post_id, $location, $old_url, $new_url );
}
