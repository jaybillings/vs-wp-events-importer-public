<?php
/**
 * Class VSEI_Admin - Admin specific functionality for the plugin.
 *
 * Defines the plugin name, version, and hooks to enqueue the admin-specific stylesheet
 * and scripts.
 *
 * @package VSEI/admin
 * @version 1.0.0
 * @author Visit Seattle <webmaster@visitseattle.org>
 */
class VSEI_Admin
{
    /**
     * VSEI_Admin constructor.
     */
    public function __construct() {
        // Silence is golden
    }

    /**
     * Register the stylesheets for the admin area
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            VSEI_BASENAME,
            plugins_url('includes/vsei-admin-styles.css', __FILE__),
            array(),
            VSEI_VERSION,
            'all'
        );
    }

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'moment',
            plugins_url('includes/moment.min.js', __FILE__),
            array(),
            VSEI_VERSION,
            false
        );
        wp_enqueue_script(
            'minidaemon',
            plugins_url('includes/mdn-minidaemon.js', __FILE__),
            array(),
            VSEI_VERSION,
            false
        );
        wp_enqueue_script(
            'vsei-admin',
            plugins_url('includes/vsei-admin.js', __FILE__),
            array('jquery'),
            VSEI_VERSION,
            false
        );
    }

    /**
     * Displays page for admin area.
     */
    public static function display_page() {
        require_once VSEI_PATH . 'admin/includes/vsei-admin-display.php';
    }

    /**
     * Adds menu item linking to plugin page.
     */
    public function display_admin_menu_item() {
        add_menu_page(
            'Visit Seattle Events Importer',
            'Events Importer',
            'manage_options',
            'visit-seattle-events-importer',
            array( 'VSEI_Admin', 'display_page' ),
            'dashicons-update'
        );
    }
}
