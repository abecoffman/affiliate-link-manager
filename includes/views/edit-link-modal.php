<?php
/**
 * Hidden-by-default markup for the Links screen's Edit modal --
 * populated and shown by assets/admin.js on an .alm-edit-link row
 * action click, never server-rendered per-row (would mean one full
 * modal's worth of markup duplicated for every row in the table).
 *
 * Deliberately has no provider picker -- the affiliate network is
 * always inferred from the URL (ALM_Provider_Registry::match_url(),
 * the same matching the scanner itself uses), live as the admin edits
 * it, never a manual choice. See ALM_Link_Converter::save_url() and
 * ALM_Admin::handle_match_provider().
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
			<dd id="alm-edit-link-context" class="alm-modal-snippet"></dd>
			<dt><?php esc_html_e( 'Affiliate partner', 'affiliate-link-manager' ); ?></dt>
			<dd id="alm-edit-link-provider-display"></dd>
		</dl>

		<p>
			<label for="alm-edit-link-url-input"><?php esc_html_e( 'URL', 'affiliate-link-manager' ); ?></label>
			<input type="url" id="alm-edit-link-url-input" class="widefat" />
			<span class="description"><?php esc_html_e( "Already have a link generated on the network's own site (e.g. RewardStyle)? Paste it here -- the affiliate partner above updates automatically.", 'affiliate-link-manager' ); ?></span>
		</p>

		<p id="alm-edit-link-error" class="alm-modal-error" hidden></p>

		<div class="alm-modal-actions">
			<button type="button" id="alm-edit-link-cancel" class="button"><?php esc_html_e( 'Cancel', 'affiliate-link-manager' ); ?></button>
			<button type="button" id="alm-edit-link-save" class="button button-primary"></button>
		</div>
	</div>
</div>
