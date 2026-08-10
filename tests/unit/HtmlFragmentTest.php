<?php
/**
 * @package ALM
 */

namespace ALM\Tests\Unit;

use ALM\Tests\TestCase;

/**
 * Minimal concrete class exposing the shared HTML-fragment trait's
 * private methods publicly, purely so this test suite can exercise
 * them directly without going through a full content adapter (which
 * would require mocking get_post()/wp_update_post() for no added value
 * here -- the parsing/rewriting logic itself is what's under test).
 */
class HtmlFragmentTestSubject {
	use \ALM_Html_Fragment_Trait;

	public function anchors( $html ) {
		return $this->parse_anchors( $html );
	}

	public function replace( $html, $index, $old_url, $new_url ) {
		return $this->replace_anchor_href( $html, $index, $old_url, $new_url );
	}

	public function context( $html, $index ) {
		return $this->get_anchor_context( $html, $index );
	}
}

/**
 * @covers \ALM_Html_Fragment_Trait
 */
class HtmlFragmentTest extends TestCase {

	public function test_parses_multiple_anchors_in_document_order() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<ul><li><a href="https://a.example.com/">first</a></li>'
			. '<li><a href="https://b.example.com/">second</a></li></ul>';

		$anchors = $subject->anchors( $html );

		$this->assertCount( 2, $anchors );
		$this->assertSame( 'https://a.example.com/', $anchors[0]['href'] );
		$this->assertSame( 'first', $anchors[0]['text'] );
		$this->assertSame( 'https://b.example.com/', $anchors[1]['href'] );
		$this->assertSame( 'second', $anchors[1]['text'] );
	}

	public function test_parses_no_anchors_from_plain_text() {
		$subject = new HtmlFragmentTestSubject();
		$this->assertSame( array(), $subject->anchors( '<p>No links here.</p>' ) );
	}

	public function test_parses_empty_string_safely() {
		$subject = new HtmlFragmentTestSubject();
		$this->assertSame( array(), $subject->anchors( '' ) );
	}

	public function test_preserves_utf8_characters() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p>Café <a href="https://example.com/">bouclés</a></p>';

		$anchors = $subject->anchors( $html );

		$this->assertSame( 'bouclés', $anchors[0]['text'] );
	}

	public function test_replace_anchor_href_updates_only_the_matching_index() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a><a href="https://b.example.com/">second</a>';

		$updated = $subject->replace(
			$html,
			1,
			'https://b.example.com/',
			'https://go.shopmy.us/apx/xyz?url=https://b.example.com/'
		);

		$this->assertStringContainsString( 'https://a.example.com/', $updated );
		$this->assertStringContainsString( 'go.shopmy.us', $updated );
	}

	public function test_replace_anchor_href_refuses_when_url_has_changed() {
		// Safety check: the content may have been edited since the last
		// scan found this link at this location.
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a>';

		$result = $subject->replace( $html, 0, 'https://not-the-current-href.example.com/', 'https://new.example.com/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_replace_anchor_href_refuses_out_of_range_index() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a>';

		$result = $subject->replace( $html, 5, 'https://a.example.com/', 'https://new.example.com/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_get_anchor_context_splits_surrounding_text_around_the_anchor() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p>Wearing my favorite <a href="https://a.example.com/">Birkenstocks</a> today with jeans.</p>';

		$context = $subject->context( $html, 0 );

		$this->assertSame( 'Wearing my favorite', $context['before'] );
		$this->assertSame( 'Birkenstocks', $context['text'] );
		$this->assertSame( 'today with jeans.', $context['after'] );
	}

	public function test_get_anchor_context_handles_a_link_with_no_surrounding_text() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p><a href="https://a.example.com/">Just this link</a></p>';

		$context = $subject->context( $html, 0 );

		$this->assertSame( '', $context['before'] );
		$this->assertSame( 'Just this link', $context['text'] );
		$this->assertSame( '', $context['after'] );
	}

	public function test_get_anchor_context_returns_null_for_an_out_of_range_index() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p><a href="https://a.example.com/">first</a></p>';

		$this->assertNull( $subject->context( $html, 5 ) );
	}

	public function test_get_anchor_context_returns_null_for_empty_html() {
		$subject = new HtmlFragmentTestSubject();
		$this->assertNull( $subject->context( '', 0 ) );
	}

	public function test_get_anchor_context_truncates_long_surrounding_text() {
		$subject = new HtmlFragmentTestSubject();
		$long    = str_repeat( 'a very long sentence of prose ', 10 );
		$html    = '<p>' . $long . '<a href="https://a.example.com/">the link</a>' . $long . '</p>';

		$context = $subject->context( $html, 0 );

		// 80 chars plus the multi-byte "…" marker (3 bytes in UTF-8) --
		// strlen() is byte length, not character count.
		$max_bytes = 80 + strlen( '…' );
		$this->assertLessThanOrEqual( $max_bytes, strlen( $context['before'] ), 'before text should be truncated to a short snippet.' );
		$this->assertStringStartsWith( '…', $context['before'] );
		$this->assertLessThanOrEqual( $max_bytes, strlen( $context['after'] ) );
		$this->assertStringEndsWith( '…', $context['after'] );
	}

	public function test_never_leaks_the_synthetic_wrapper_div() {
		// Regression-style guard: load_html_fragment() wraps content in
		// a throwaway <div id="alm-root">; save_html_fragment() must
		// strip that back out, not leave it in the persisted content.
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a>';

		$updated = $subject->replace( $html, 0, 'https://a.example.com/', 'https://b.example.com/' );

		$this->assertStringNotContainsString( 'alm-root', $updated );
	}
}
