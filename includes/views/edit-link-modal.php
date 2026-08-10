<?php
/**
 * Hidden-by-default markup for the Links screen's Edit modal --
 * populated and shown by assets/admin.js on an .alm-edit-link row
 * action click, never server-rendered per-row (would mean one full
 * modal's worth of markup duplicated for every row in the table).
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="alm-edit-link-modal" class="alm-modal-overlay" hidden>
	<div class="alm-modal" role="dialog" aria-modal="true" aria-labelledby="alm-edit-link-title">
		<h2 id="alm-edit-link-title"><?php esc_html_e( 'Edit link', 'affiliate-link-manager' ); ?></h2>

		<dl class="alm-modal-context">
			<dt><?php esc_html_e( 'Post', 'affiliate-link-manager' ); ?></dt>
			<dd id="alm-edit-link-post"></dd>
			<dt><?php esc_html_e( 'Link text', 'affiliate-link-manager' ); ?></dt>
			<dd id="alm-edit-link-anchor"></dd>
			<dt><?php esc_html_e( 'Current URL', 'affiliate-link-manager' ); ?></dt>
			<dd id="alm-edit-link-url" class="alm-modal-url"></dd>
		</dl>

		<p>
			<label for="alm-edit-link-provider"><?php esc_html_e( 'Provider', 'affiliate-link-manager' ); ?></label>
			<select id="alm-edit-link-provider"></select>
		</p>

		<p id="alm-edit-link-help" class="alm-modal-help"></p>
		<p id="alm-edit-link-error" class="alm-modal-error" hidden></p>

		<div class="alm-modal-actions">
			<button type="button" id="alm-edit-link-cancel" class="button"><?php esc_html_e( 'Cancel', 'affiliate-link-manager' ); ?></button>
			<button type="button" id="alm-edit-link-save" class="button button-primary"></button>
		</div>
	</div>
</div>
