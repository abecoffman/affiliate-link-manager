<?php
/**
 * Uninstall handler -- removes the plugin's own data (the alm_links
 * table and its options) when deleted via the Plugins screen. Does not
 * touch anything in actual post content: any links this plugin writes
 * there (once the convert/insert features exist in a later phase) are
 * the site's own data, not plugin state.
 *
 * @package ALM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-alm-install.php';

ALM_Install::uninstall();
