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
	 * Unwraps one anchor -- removes the `<a>` tag itself but keeps
	 * whatever's inside it exactly as it was (plain text, or nested
	 * inline markup like `<strong>`), so the sentence it lived in still
	 * reads naturally with just the link gone. Direct sibling of
	 * replace_anchor_href(): identical index-bounds/old-URL-match
	 * verification, only the mutation itself differs (unwrap instead of
	 * swapping the href).
	 *
	 * @param string $html
	 * @param int    $index
	 * @param string $old_url
	 * @return string|WP_Error Updated HTML fragment, or WP_Error if the
	 *                         anchor at $index no longer has $old_url.
	 */
	private function unwrap_anchor( $html, $index, $old_url ) {
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

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own built-in PHP property, not a WordPress API.
		$parent = $anchor->parentNode;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		while ( $anchor->firstChild ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$parent->insertBefore( $anchor->firstChild, $anchor );
		}
		$parent->removeChild( $anchor );

		return $this->save_html_fragment( $doc );
	}

	/**
	 * A short "as it reads in the post" snippet around one anchor -- the
	 * one sentence it lives in, real markup and all (other links, bold,
	 * italic), for a link editor UI to show what's actually being
	 * changed rather than just an isolated URL. Two things this is
	 * deliberately built to do, both from direct user feedback on an
	 * earlier plain-text-only version:
	 * - Preserve HTML, not flatten it to text: any other inline
	 *   markup (another <a>, <strong>, <em>, ...) in the same
	 *   paragraph is kept as real markup, sanitized through a small
	 *   allowed-tag whitelist (wp_kses()) as a safety net regardless of
	 *   where it came from, with any surviving <a> forced to
	 *   target="_blank" -- a link inside a *preview* must never
	 *   navigate the admin away from this modal.
	 * - Scope to one sentence, not the whole paragraph: walks outward
	 *   from the anchor, node by node, stopping the first time it finds
	 *   a `.`/`!`/`?` sentence delimiter (real prose only -- doesn't try
	 *   to special-case abbreviations like "e.g."). An inline element's
	 *   own text is never split mid-tag; if a boundary is found inside
	 *   one, that whole element is still included and the walk stops
	 *   there.
	 *
	 * Best-effort throughout: a raw <a> with no meaningful surrounding
	 * text (e.g. one that *is* the entire content of its <p>) still
	 * returns empty before/after rather than failing.
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

		// The direct child of $container that either *is* the anchor, or
		// contains it -- before/after are built from this child's
		// siblings, never from inside it (the anchor's own markup is
		// handled separately, via $anchor_text/the modal's own <mark>).
		$target_child = $anchor;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		while ( $target_child->parentNode && $target_child->parentNode !== $container ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$target_child = $target_child->parentNode;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$children     = iterator_to_array( $container->childNodes );
		$target_index = array_search( $target_child, $children, true );
		if ( false === $target_index ) {
			return array(
				'before' => '',
				'text'   => $anchor_text,
				'after'  => '',
			);
		}

		$before_parts = array();
		for ( $i = $target_index - 1; $i >= 0; $i-- ) {
			$stop = $this->collect_sentence_scoped_node( $doc, $children[ $i ], true, $before_parts );
			if ( $stop ) {
				break;
			}
		}

		$after_parts = array();
		for ( $i = $target_index + 1, $count = count( $children ); $i < $count; $i++ ) {
			$stop = $this->collect_sentence_scoped_node( $doc, $children[ $i ], false, $after_parts );
			if ( $stop ) {
				break;
			}
		}

		$allowed_tags = array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'span'   => array(),
			'br'     => array(),
		);

		return array(
			'before' => trim( wp_kses( implode( '', array_reverse( $before_parts ) ), $allowed_tags ) ),
			'text'   => $anchor_text,
			'after'  => trim( wp_kses( implode( '', $after_parts ), $allowed_tags ) ),
		);
	}

	/**
	 * Appends one sibling node's contribution to a before/after part
	 * list, stopping at the first sentence delimiter found (a plain
	 * text node can be cut mid-string; an inline element is always
	 * included whole, since splitting mid-tag isn't safe to attempt).
	 *
	 * @param DOMDocument $doc
	 * @param DOMNode     $node
	 * @param bool        $walking_backward true for the "before" walk
	 *                                      (looking for the *last*
	 *                                      delimiter, keeping the tail),
	 *                                      false for "after" (looking
	 *                                      for the *first*, keeping the
	 *                                      head through it).
	 * @param string[]    $parts            Appended to by reference.
	 * @return bool True once a sentence boundary was found (the caller
	 *              should stop walking further siblings).
	 */
	private function collect_sentence_scoped_node( DOMDocument $doc, DOMNode $node, $walking_backward, array &$parts ) {
		if ( $node instanceof DOMText ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own built-in PHP property.
			$text = $node->textContent;
			$cut  = $walking_backward ? $this->find_last_sentence_boundary( $text ) : $this->find_first_sentence_boundary( $text );

			if ( false === $cut ) {
				$parts[] = esc_html( $text );
				return false;
			}

			$parts[] = $walking_backward ? esc_html( substr( $text, $cut ) ) : esc_html( substr( $text, 0, $cut ) );
			return true;
		}

		if ( $node instanceof DOMElement ) {
			$this->force_links_to_open_in_a_new_tab( $doc, $node );
			$parts[] = (string) $doc->saveHTML( $node );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$node_text = $node->textContent;
			$boundary  = $walking_backward ? $this->find_last_sentence_boundary( $node_text ) : $this->find_first_sentence_boundary( $node_text );
			return false !== $boundary;
		}

		// Comments, etc. -- contribute nothing, don't end the sentence.
		return false;
	}

	/**
	 * @param string $text
	 * @return int|false Offset just past the delimiter and any
	 *                    following whitespace, or false if none found.
	 */
	private function find_last_sentence_boundary( $text ) {
		if ( ! preg_match_all( '/[.!?](?=\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE ) || empty( $matches[0] ) ) {
			return false;
		}

		$last   = end( $matches[0] );
		$pos    = $last[1] + strlen( $last[0] );
		$length = strlen( $text );
		while ( $pos < $length && ctype_space( $text[ $pos ] ) ) {
			++$pos;
		}

		return $pos;
	}

	/**
	 * @param string $text
	 * @return int|false Offset just past the delimiter itself (the
	 *                    delimiter is kept, the sentence should still
	 *                    end with its own punctuation), or false if
	 *                    none found.
	 */
	private function find_first_sentence_boundary( $text ) {
		if ( ! preg_match( '/[.!?](?=\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		return $matches[0][1] + 1;
	}

	/**
	 * A link surviving into a preview snippet must never navigate the
	 * admin away from the modal it's shown in -- mutates the (already
	 * throwaway, never saved back) $doc in place, not a copy.
	 *
	 * @param DOMDocument $doc
	 * @param DOMElement  $scope
	 * @return void
	 */
	private function force_links_to_open_in_a_new_tab( DOMDocument $doc, DOMElement $scope ) {
		$xpath = new DOMXPath( $doc );
		foreach ( $xpath->query( 'descendant-or-self::a', $scope ) as $link ) {
			$link->setAttribute( 'target', '_blank' );
			$link->setAttribute( 'rel', 'noopener noreferrer' );
		}
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
