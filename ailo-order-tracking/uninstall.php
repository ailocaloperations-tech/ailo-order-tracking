<?php
/**
 * Uninstall.
 *
 * Removes the plugin's own option. Order meta is deliberately LEFT ALONE:
 * a tracking number is the shop's business record, and a merchant who removes
 * a plugin to try an alternative must not lose the numbers they already
 * entered. Deleting them would also mean an unbounded write across every order
 * on the site, which is exactly the kind of uninstall that times out on a
 * large store and leaves the data half-deleted.
 *
 * @package AiloOrderTracking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ailo_track_carriers' );
