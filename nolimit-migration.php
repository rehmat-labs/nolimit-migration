<?php
/**
 * Plugin Name:       NoLimit Migration
 * Plugin URI:        https://nolimitmigration.com/features
 * Description:       Move, back up and restore a WordPress site in one click. No upload size limit, import from URL, site-to-site transfer links, scheduled backups, safe database swap.
 * Version:           1.0.1
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            NoLimit Migration
 * Author URI:        https://nolimitmigration.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       nolimit-migration
 *
 * Copyright (C) NoLimit Migration, https://nolimitmigration.com
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NLWM_VERSION', '1.0.1' );
define( 'NLWM_FILE', __FILE__ );
define( 'NLWM_DIR', __DIR__ );
define( 'NLWM_URL', plugin_dir_url( __FILE__ ) );
define( 'NLWM_BASENAME', plugin_basename( __FILE__ ) );
define( 'NLWM_SLUG', 'nolimit-migration' );

/** Archive extension for our own backups. `.wpress` files are accepted for import too. */
define( 'NLWM_EXT', 'nolimit' );

/** Placeholder written in place of the table prefix in our SQL dumps. */
define( 'NLWM_PREFIX_PLACEHOLDER', 'NLWMPFX_' );

/** Placeholder used by All-in-One WP Migration (.wpress) dumps. */
define( 'NLWM_WPRESS_PLACEHOLDER', 'SERVMASK_PREFIX_' );

/*
 * Backups live in wp-content/nlwm-backups-<random>. The random part makes the folder unguessable on
 * servers that ignore .htaccess (nginx). Define NLWM_BACKUPS_DIR in wp-config.php to use another folder.
 */

/** Seconds one step may run inside a single HTTP request. */
if ( ! defined( 'NLWM_STEP_TIME' ) ) {
	define( 'NLWM_STEP_TIME', 15 );
}

foreach ( array(
	'util',
	'archive',
	'replacer',
	'database',
	'files',
	'job',
	'backups',
	'export',
	'import',
	'remote',
	'storage',
	'schedule',
	'admin',
) as $nlwm_part ) {
	require_once NLWM_DIR . '/includes/class-nlwm-' . $nlwm_part . '.php';
}
unset( $nlwm_part );


if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NLWM_DIR . '/includes/class-nlwm-cli.php';
	WP_CLI::add_command( 'nolimit', 'NLWM_CLI' );
}

register_activation_hook( __FILE__, array( 'NLWM_Util', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NLWM_Schedule', 'unschedule' ) );

NLWM_Admin::init();
NLWM_Remote::init();
NLWM_Schedule::init();
