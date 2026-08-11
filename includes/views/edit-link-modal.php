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
 * Also carries every other per-link action (View, Ignore, Delete) --
 * moved here from the Links table's row actions per explicit feedback
 * that they didn't make sense split across two different columns
 * anymore. Edit Post/View are secondary links next to the post title;
 * Ignore/Delete are secondary actions in the footer, deliberately kept
 * apart from Cancel/Save (a different kind of action, not part of the
 * same URL-editing flow).
 *
 * The thumbnail slot (#alm-edit-link-thumb) is populated the same way
 * -- JS renders straight from the row's own data-thumbnail-url/
 * data-thumbnail-fetched attributes when already known, or shows a
 * loading state and fetches on demand via ALM_Admin::handle_fetch_thumbnail()
 * the first time this particular link's modal is opened. See
 * ALM_Thumbnail_Fetcher.
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

		<div class="alm-modal-body">
			<div id="alm-edit-link-thumb" class="alm-modal-thumb" aria-hidden="true"></div>

			<dl class="alm-modal-context">
				<dt><?php esc_html_e( 'Post', 'affiliate-link-manager' ); ?></dt>
				<dd id="alm-edit-link-post"></dd>
				<dt><?php esc_html_e( 'Link text', 'affiliate-link-manager' ); ?></dt>
				<dd id="alm-edit-link-context" class="alm-modal-snippet"></dd>
				<dt><?php esc_html_e( 'Affiliate partner', 'affiliate-link-manager' ); ?></dt>
				<dd id="alm-edit-link-provider-display"></dd>
			</dl>
		</div>

		<div class="alm-modal-divider"></div>

		<p>
			<label for="alm-edit-link-url-input"><?php esc_html_e( 'URL', 'affiliate-link-manager' ); ?></label>
			<input type="url" id="alm-edit-link-url-input" class="widefat" />
			<span class="description"><?php esc_html_e( "Already have a link generated on the network's own site (e.g. RewardStyle)? Paste it here -- the affiliate partner above updates automatically.", 'affiliate-link-manager' ); ?></span>
		</p>

		<p id="alm-edit-link-resolved" class="description" hidden>
			<?php esc_html_e( 'This shortened link actually points to:', 'affiliate-link-manager' ); ?>
			<span id="alm-edit-link-resolved-url" class="alm-modal-url"></span>
			<a href="#" id="alm-edit-link-use-resolved" class="alm-modal-text-action"><?php esc_html_e( 'Use this URL', 'affiliate-link-manager' ); ?></a>
		</p>

		<p id="alm-edit-link-error" class="alm-modal-error" hidden></p>

		<div class="alm-modal-actions">
			<div class="alm-modal-actions-secondary">
				<a href="#" id="alm-edit-link-ignore" class="alm-modal-text-action"><?php esc_html_e( 'Ignore', 'affiliate-link-manager' ); ?></a>
				<a href="#" id="alm-edit-link-delete" class="alm-modal-text-action alm-modal-text-action-danger"><?php esc_html_e( 'Delete', 'affiliate-link-manager' ); ?></a>
			</div>
			<div class="alm-modal-actions-primary">
				<button type="button" id="alm-edit-link-cancel" class="button"><?php esc_html_e( 'Cancel', 'affiliate-link-manager' ); ?></button>
				<button type="button" id="alm-edit-link-save" class="button button-primary"></button>
			</div>
		</div>
	</div>
</div>
