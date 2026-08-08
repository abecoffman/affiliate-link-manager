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
