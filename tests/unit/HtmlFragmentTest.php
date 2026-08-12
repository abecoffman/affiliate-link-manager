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

	public function unwrap( $html, $index, $old_url ) {
		return $this->unwrap_anchor( $html, $index, $old_url );
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

	/**
	 * No character-count cap: a long run of text with no sentence
	 * delimiter in it at all (an earlier version truncated to ~80
	 * chars regardless -- a real usability complaint) is returned in
	 * full, since there's no sentence boundary to stop at.
	 */
	public function test_get_anchor_context_does_not_impose_a_length_cap_absent_a_sentence_boundary() {
		$subject = new HtmlFragmentTestSubject();
		$long    = str_repeat( 'a very long run of prose with no punctuation ', 10 );
		$html    = '<p>' . $long . '<a href="https://a.example.com/">the link</a>' . $long . '</p>';

		$context = $subject->context( $html, 0 );

		$this->assertSame( trim( $long ), $context['before'] );
		$this->assertSame( trim( $long ), $context['after'] );
	}

	/**
	 * The real fix this exists for: context scoped to *one sentence*,
	 * not the whole paragraph -- reproduces the exact honestlywtf
	 * example that prompted it (a multi-sentence Beaver Builder rich-
	 * text block, where the first version returned all three
	 * sentences instead of just the one the link is actually in).
	 */
	public function test_get_anchor_context_scopes_to_a_single_sentence_not_the_whole_paragraph() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p>We could not be more excited to bring this back! Ariel and I reimagined the palette with fresh colors. '
			. 'If you are in town between April 18-20, come hang with us all weekend at Birkenstock&#8217;s '
			. '<a href="https://www.birkenstock.com/us/magazine/boston-store/">Newbury</a> and Chestnut Hill locations and get hands-on. '
			. 'It is equal parts craft and community. We can not wait to see what you make!</p>';

		$context = $subject->context( $html, 0 );

		$this->assertSame( "If you are in town between April 18-20, come hang with us all weekend at Birkenstock’s", $context['before'] );
		$this->assertSame( 'Newbury', $context['text'] );
		$this->assertSame( 'and Chestnut Hill locations and get hands-on.', $context['after'] );
	}

	/**
	 * The other real fix: other inline markup in the same sentence --
	 * another link, bold text -- must survive as real markup, not get
	 * flattened to plain text. Any surviving link is forced to open in
	 * a new tab: a link inside a *preview* must never navigate the
	 * admin away from the modal it's shown in.
	 */
	public function test_get_anchor_context_preserves_other_inline_markup_and_forces_links_to_a_new_tab() {
		$subject = new HtmlFragmentTestSubject();
		$html = '<p>As seen in <a href="https://vogue.example.com/">Vogue</a>, our <strong>new collection</strong> features the '
			. '<a href="https://a.example.com/">Birkenstocks</a> everyone is talking about.</p>';

		$context = $subject->context( $html, 1 );

		$this->assertStringContainsString( '<a', $context['before'], 'The Vogue link must survive as real markup.' );
		$this->assertStringContainsString( 'href="https://vogue.example.com/"', $context['before'] );
		$this->assertStringContainsString( 'target="_blank"', $context['before'], 'A link inside a preview must never navigate away from the modal.' );
		$this->assertStringContainsString( '<strong>new collection</strong>', $context['before'] );
	}

	/**
	 * Confirms the bound really is "the nearest block ancestor," not
	 * the whole post -- text in a sibling paragraph must never bleed
	 * into this link's own context.
	 */
	public function test_get_anchor_context_stays_within_the_anchors_own_paragraph() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p>Earlier, unrelated paragraph.</p>'
			. '<p>Wearing my favorite <a href="https://a.example.com/">Birkenstocks</a> today.</p>'
			. '<p>A later, also unrelated paragraph.</p>';

		$context = $subject->context( $html, 0 );

		$this->assertSame( 'Wearing my favorite', $context['before'] );
		$this->assertSame( 'today.', $context['after'] );
	}

	public function test_unwrap_anchor_removes_the_link_but_keeps_its_text() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p>Wearing my favorite <a href="https://a.example.com/">Birkenstocks</a> today.</p>';

		$updated = $subject->unwrap( $html, 0, 'https://a.example.com/' );

		$this->assertStringNotContainsString( '<a ', $updated );
		$this->assertStringNotContainsString( 'href', $updated );
		$this->assertStringContainsString( 'Wearing my favorite Birkenstocks today.', $updated );
	}

	/**
	 * Nested markup inside the anchor (bold, etc.) must survive the
	 * unwrap intact, not get flattened to plain text -- same "preserve
	 * real markup" principle already established for get_anchor_context().
	 */
	public function test_unwrap_anchor_preserves_nested_inline_markup() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<p>Shop the <a href="https://a.example.com/"><strong>sale</strong></a> now.</p>';

		$updated = $subject->unwrap( $html, 0, 'https://a.example.com/' );

		$this->assertStringNotContainsString( '<a ', $updated );
		$this->assertStringContainsString( '<strong>sale</strong>', $updated );
	}

	public function test_unwrap_anchor_only_removes_the_matching_index() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a><a href="https://b.example.com/">second</a>';

		$updated = $subject->unwrap( $html, 1, 'https://b.example.com/' );

		$this->assertStringContainsString( 'href="https://a.example.com/"', $updated );
		$this->assertStringContainsString( 'second', $updated );
		$this->assertStringNotContainsString( 'https://b.example.com/', $updated );
	}

	public function test_unwrap_anchor_refuses_when_url_has_changed() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a>';

		$result = $subject->unwrap( $html, 0, 'https://not-the-current-href.example.com/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'href="https://a.example.com/"', $html, 'The original (never-passed-back) $html itself must obviously be untouched -- sanity check on the test\'s own assumption.' );
	}

	public function test_unwrap_anchor_refuses_out_of_range_index() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a>';

		$result = $subject->unwrap( $html, 5, 'https://a.example.com/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_unwrap_anchor_never_leaks_the_synthetic_wrapper_div() {
		$subject = new HtmlFragmentTestSubject();
		$html    = '<a href="https://a.example.com/">first</a>';

		$updated = $subject->unwrap( $html, 0, 'https://a.example.com/' );

		$this->assertStringNotContainsString( 'alm-root', $updated );
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
