<?php
/*
 * Visit Seattle Events Importer bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the admin area.
 * This file also includes all of the dependencies used by the plugin, registers the
 * activation, deactivation, and uninstallation functions, and defines a function that
 * starts the plugin.
 *
 * Boilerplate code comes from [Wordpress-Plugin-Boilerplate](https://github.com/DevinVinson/WordPress-Plugin-Boilerplate)
 */

/*
Plugin Name: Visit Seattle Events Importer
Plugin URI: https://github.com/VisitSeattle/visitseattle-events-api
Description: Fetches event data from the Visit Seattle Events API, sourced from BeDynamic.
Version: 1.1.1
Author: Visit Seattle
*/

// If this file is called directly, abort
if ( !function_exists( 'add_filter' ) ) {
    header('Status: 403 Forbidden' );
    header( 'HTTP/1.1 403 Forbidden' );
    exit();
}

/* ==== Define Globals ==== */

// Intentionally not checking for a previous definition
// since this should not be overridden
define('VSEI_VERSION', '1.1.1');

if (!defined('VSEI_PATH')) {
    define('VSEI_PATH', plugin_dir_path(__FILE__));
}

if (!defined('VSEI_BASENAME')) {
    define('VSEI_BASENAME', 'Visit Seattle Events Importer');
}

if (!defined('VSEI_CACHE_TABLE')) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'vsei_data_cache';
    define('VSEI_CACHE_TABLE', $table_name);
}

/*==== Activation, Deactivation, and Uninstallation === */

require_once(VSEI_PATH . 'classes/class-vsei.php');

/**
 * Handler for plugin activation.
 */
function vsei_activate_plugin() {
    VSEI::activate();
}

/**
 * Handler for plugin deactivation.
 */
function vsei_deactivate_plugin() {
    VSEI::deactivate();
}

/**
 * Handler for plugin uninstallation.
 */
function vsei_uninstall_plugin() {
    VSEI::uninstall();
}

register_activation_hook(__FILE__, 'vsei_activate_plugin');
register_deactivation_hook(__FILE__, 'vsei_deactivate_plugin');
register_uninstall_hook(__FILE__, 'vsei_uninstall_plugin');

/**
 * Begins plugin execution.
 */
function vsei_run_plugin() {
    $plugin = new VSEI();
    $plugin->run();
}
vsei_run_plugin();
