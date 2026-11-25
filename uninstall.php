<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Settings and cron events are removed. Backup files are KEPT on purpose (they may be the only copy);
 * delete the wp-content/nlwm-backups-* folder by hand if you no longer need them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nlwm_settings' );
delete_option( 'nlwm_secret' );
delete_option( 'nlwm_backups_index' );
foreach ( array( 'nlwm_scheduled_backup', 'nlwm_continue_job', 'nlwm_daily_gc' ) as $nlwm_hook ) {
	wp_clear_scheduled_hook( $nlwm_hook );
}

