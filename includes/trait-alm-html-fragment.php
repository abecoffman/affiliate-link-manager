<?php
/**
 * Shared HTML-fragment anchor parsing/rewriting, used by any content
 * adapter that stores a chunk of raw HTML (post_content directly, or a
 * page builder module's rich-text field) rather than fully structured
 * per-link data.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait ALM_Html_Fragment_Trait {

	/**
	 * @param string $html
	 * @return array[] 0-indexed list of ['href' => ..., 'text' => ...], in document order.
	 */
	private function parse_anchors( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return array();
		}

		$doc     = $this->load_html_fragment( $html );
		$anchors = $this->get_fragment_anchors( $doc );

		$results = array();
		foreach ( $anchors as $anchor ) {
			$results[] = array(
				'href' => $anchor->getAttribute( 'href' ),
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own built-in PHP property, not a WordPress API.
				'text' => trim( $anchor->textContent ),
			);
		}

		return $results;
	}

	/**
	 * @param string $html
	 * @param int    $index
	 * @param string $old_url
	 * @param string $new_url
	 * @return string|WP_Error Updated HTML fragment, or WP_Error if the
	 *                         anchor at $index no longer has $old_url.
	 */
	private function replace_anchor_href( $html, $index, $old_url, $new_url ) {
		$doc     = $this->load_html_fragment( $html );
		$anchors = $this->get_fragment_anchors( $doc );

		if ( $index < 0 || $index >= $anchors->length ) {
			return new WP_Error(
				'alm_link_not_found',
				__( 'That link no longer exists at the expected location -- the post may have been edited since the last scan. Re-scan and try again.', 'affiliate-link-manager' )
			);
		}

		$anchor = $anchors->item( $index );
		if ( $anchor->getAttribute( 'href' ) !== $old_url ) {
			return new WP_Error(
				'alm_link_changed',
				__( 'That link has changed since the last scan. Re-scan and try again.', 'affiliate-link-manager' )
			);
		}

		$anchor->setAttribute( 'href', $new_url );

		return $this->save_html_fragment( $doc );
	}

	/**
	 * A short "as it reads in the post" snippet around one anchor --
	 * the anchor's own text plus a little plain-text context on each
	 * side, for a link editor UI to show what's actually being changed
	 * rather than just an isolated URL. Best-effort: walks up to the
	 * nearest block-level ancestor (a raw <a> with no meaningful
	 * surrounding text, e.g. one that *is* the entire content of its
	 * <p>, still returns empty before/after rather than failing) and
	 * locates the anchor's own text within that block's plain text --
	 * good enough for a UI hint, not meant to be exact for content
	 * where the same text legitimately appears more than once nearby.
	 *
	 * @param string $html
	 * @param int    $index
	 * @return array{before:string,text:string,after:string}|null
	 */
	private function get_anchor_context( $html, $index ) {
		if ( '' === trim( (string) $html ) ) {
			return null;
		}

		$doc     = $this->load_html_fragment( $html );
		$anchors = $this->get_fragment_anchors( $doc );

		if ( $index < 0 || $index >= $anchors->length ) {
			return null;
		}

		$anchor = $anchors->item( $index );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own built-in PHP property.
		$anchor_text = trim( $anchor->textContent );

		$block_tags = array( 'p', 'li', 'td', 'th', 'div', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figcaption' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own built-in PHP property, not a WordPress API.
		$container = $anchor->parentNode;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement's own built-in PHP property.
		while ( $container instanceof DOMElement && ! in_array( strtolower( $container->tagName ), $block_tags, true ) && $container->parentNode ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$container = $container->parentNode;
		}
		if ( ! ( $container instanceof DOMElement ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$container = $anchor->parentNode;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$full = trim( preg_replace( '/\s+/', ' ', $container->textContent ) );
		$pos  = '' !== $anchor_text ? strpos( $full, $anchor_text ) : false;

		if ( false === $pos ) {
			return array(
				'before' => '',
				'text'   => $anchor_text,
				'after'  => '',
			);
		}

		return array(
			'before' => $this->truncate_context_edge( trim( substr( $full, 0, $pos ) ), 80, true ),
			'text'   => $anchor_text,
			'after'  => $this->truncate_context_edge( trim( substr( $full, $pos + strlen( $anchor_text ) ) ), 80, false ),
		);
	}

	/**
	 * @param string $text
	 * @param int    $max
	 * @param bool   $from_end Truncate from the front (keep the tail,
	 *                         for "before" text) or from the back
	 *                         (keep the head, for "after" text).
	 * @return string
	 */
	private function truncate_context_edge( $text, $max, $from_end ) {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}

		return $from_end ? '…' . substr( $text, -$max ) : substr( $text, 0, $max ) . '…';
	}

	/**
	 * Parse an HTML fragment safely (preserving UTF-8, no full
	 * <html><body> wrapper needed) into a DOMDocument.
	 *
	 * @param string $html
	 * @return DOMDocument
	 */
	private function load_html_fragment( $html ) {
		$doc             = new DOMDocument( '1.0', 'UTF-8' );
		$internal_errors = libxml_use_internal_errors( true );

		// The 'utf-8' meta charset trick avoids DOMDocument mangling
		// multi-byte characters -- without it, loadHTML() assumes
		// ISO-8859-1 regardless of the string's real encoding. The
		// wrapper div gives loadHTML() a single root even though this is
		// a fragment, not a full document.
		$doc->loadHTML(
			'<?xml encoding="UTF-8"?><div id="alm-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );

		return $doc;
	}

	/**
	 * @param DOMDocument $doc
	 * @return DOMNodeList
	 */
	private function get_fragment_anchors( DOMDocument $doc ) {
		$xpath = new DOMXPath( $doc );
		return $xpath->query( '//div[@id="alm-root"]//a' );
	}

	/**
	 * Reverse of load_html_fragment()'s wrapping -- serialize just the
	 * inner HTML of our synthetic wrapper div, not the div itself.
	 *
	 * @param DOMDocument $doc
	 * @return string
	 */
	private function save_html_fragment( DOMDocument $doc ) {
		$xpath = new DOMXPath( $doc );
		$root  = $xpath->query( '//div[@id="alm-root"]' )->item( 0 );
		if ( ! $root ) {
			return '';
		}

		$html = '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own built-in PHP property, not a WordPress API.
		foreach ( $root->childNodes as $child ) {
			$html .= $doc->saveHTML( $child );
		}

		return $html;
	}
}
