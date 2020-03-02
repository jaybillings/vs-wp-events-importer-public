<?php
/**
 * Class VSEI - The core plugin class.
 *
 * This is used to define plugin activation and dependency wrangling. Also maintains the
 * unique plugin identifier and current version.
 *
 * @package VSEI/classes
 * @version 1.1.0
 * @author Visit Seattle <webmaster@visitseattle.org>
 */
class VSEI
{
    /** @var object $loader - The Importer's loader object. */
    protected $loader;

    /**
     * VSEI constructor.
     * @constructor
     */
    public function __construct() {
        $this->load_dependencies();
        $this->loader = new VSEI_Loader();
        $this->define_filters();
        $this->define_admin_hooks();
        $this->define_importer_hooks();
    }

    /* ==== Activation / Deactivation / Uninstallation ==== */

    /**
     * Handles plugin activation, namely creating database fields.
     *
     * `activate` initializes the Importer plugin by creating fields in the Options table and schedules cron events.
     *
     * (@internal `add_option` is used in cases where pre-existing values should pre preserved. `update_option` is used
     *  in the case of backwards-incompatible changes.)
     */
    public static function activate() {
        // Add database fields
        update_option('vsei_import_status', 'free', false);
        add_option('vsei_import_method', '', '', 'no');
        add_option('vsei_last_updated', '2001-01-01', '', 'no');
        add_option('vsei_processed_count', 0, '', 'no');
        add_option('vsei_add_count', 0, '', 'no');
        add_option('vsei_delete_count', 0, '', 'no');
        add_option('vsei_total_count', 0, '', 'no');
        add_option('vsei_last_run_data', '{}', '', 'no');

        // Create cache table
        self::create_custom_table();

        // Add cron job
        wp_schedule_event(time(), 'daily', 'vsei_run_cron_import');
        wp_schedule_event(time(), 'weekly', 'vsei_run_cron_invalidate_cache');
    }

    /**
     * Handles plugin deactivation.
     */
    public static function deactivate() {
        // Remove import cron
        $next_import = wp_next_scheduled('vsei_run_cron_import');
        if ($next_import) {
            wp_unschedule_event($next_import, 'vsei_run_cron_import');
        }

        // Remove invalidate cron
        $next_invalidate = wp_next_scheduled('vsei_run_cron_invalidate_cache');
        if ($next_invalidate) {
            wp_unschedule_event($next_invalidate, 'vsei_run_cron_invalidate_cache');
        }
    }

    /**
     * Handles plugin uninstallation.
     *
     * `uninstall` removes the plugin and its data, including rows in the Options table and cron processes.
     */
    public static function uninstall() {
        // If uninstall is not called from WordPress, exit
        if (!defined( 'WP_UNINSTALL_PLUGIN' )) {
            exit;
        }

        // Remove options
        delete_option('vsei_import_status');
        delete_option('vsei_import_method');
        delete_option('vsei_last_updated');
        delete_option('vsei_processed_count');
        delete_option('vsei_add_count');
        delete_option('vsei_delete_count');
        delete_option('vsei_total_count');
        delete_option('vsei_last_run_data');

        // Remove cache table
        self::drop_custom_table();

        // Remove import cron
        $next_import = wp_next_scheduled('vsei_run_cron_import');
        if ($next_import) {
            wp_unschedule_event($next_import, 'vsei_run_cron_import');
        }

        // Remove invalidate cron
        $next_invalidate = wp_next_scheduled('vsei_run_cron_invalidate_cache');
        if ($next_invalidate) {
            wp_unschedule_event($next_invalidate, 'vsei_run_cron_invalidate_cache');
        }
    }

    /* ==== Loader Handling ==== */

    /**
     * Loads PHP files the plugin is dependent upon.
     */
    private function load_dependencies() {
        require_once VSEI_PATH . 'classes/class-vsei-loader.php';
        require_once VSEI_PATH . 'classes/class-vsei-importer.php';
        require_once VSEI_PATH . 'admin/class-vsei-admin.php';
    }

    /**
     * Defines filters for the admin page.
     * @since 1.1.0
     */
    private function define_filters() {
        $this->loader->add_filter('cron_schedules', $this, 'vsei_cron_weekly_recurrence');
    }

    /**
     * Defines hooks for the admin page.
     */
    private function define_admin_hooks() {
        $plugin_admin = new VSEI_Admin();

        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
        $this->loader->add_action( 'admin_menu', $plugin_admin, 'display_admin_menu_item');
    }

    /**
     * Defines hooks for the Importer.
     */
    private function define_importer_hooks() {
        $plugin_importer = new VSEI_Importer();

        // Cron
        $this->loader->add_action('vsei_run_cron_import', $plugin_importer, 'vsei_run_cron_import');
        $this->loader->add_action('vsei_run_cron_invalidate_cache', $plugin_importer, 'vsei_run_cron_invalidate_cache');
        // Import
        $this->loader->add_action('wp_ajax_vsei_run_import_new', $plugin_importer, 'vsei_run_import_new');
        $this->loader->add_action('wp_ajax_vsei_run_import_single', $plugin_importer, 'vsei_run_import_single');
        $this->loader->add_action('wp_ajax_vsei_run_import_all', $plugin_importer, 'vsei_run_import_all');
        // Prune/purge
        $this->loader->add_action('wp_ajax_vsei_run_delete_all', $plugin_importer, 'vsei_run_delete_all');
        $this->loader->add_action('wp_ajax_vsei_run_delete_stale', $plugin_importer, 'vsei_run_delete_stale');
        // Cancel/resume
        $this->loader->add_action('wp_ajax_vsei_run_cancel', $plugin_importer, 'vsei_run_cancel');
        $this->loader->add_action('wp_ajax_vsei_run_resume', $plugin_importer, 'vsei_run_resume');
        // Cache
        $this->loader->add_action('wp_ajax_vsei_run_clear_cache', $plugin_importer, 'vsei_run_clear_cache');
        // Data fetch
        $this->loader->add_action('wp_ajax_vsei_fetch_import_status', $plugin_importer, 'vsei_fetch_importer_status');
        $this->loader->add_action('wp_ajax_vsei_fetch_total_count', $plugin_importer, 'vsei_fetch_total_count');
        $this->loader->add_action('wp_ajax_vsei_fetch_running_action', $plugin_importer, 'vsei_fetch_running_action');
    }

    /* ==== Data Handling ==== */

    /**
     * Creates a table in the WordPress database to hold cached data.
     * @since 1.1.0
     */
    private static function create_custom_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $query = "CREATE TABLE " . VSEI_CACHE_TABLE . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name tinytext NOT NULL,
            xmldata longblob,
            lastupdated tinytext,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        /** @internal Include upgrade.php to make `dbDelta` available */
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($query);
    }

    /**
     * Removes the plugin's custom table from the database.
     * @since 1.1.0
     */
    private static function drop_custom_table() {
        global $wpdb;
        $table_name = VSEI_CACHE_TABLE;
        $query = "DROP TABLE IF EXISTS ($table_name);";
        $wpdb->query($query);
    }

    /* ==== Custom Cron Interval ==== */

    /**
     * Adds a 'weekly' cron interval, if one doesn't already exist.
     * @since 1.1.0
     *
     * @param array $schedules - The registered cron intervals.
     *
     * @return array
     */
    public static function vsei_cron_weekly_recurrence($schedules) {
        if (!array_key_exists('weekly', $schedules)) {
            $schedules['weekly'] = array(
                'display'   => __('once weekly', 'textdomain'),
                'interval'  => 604800
            );
        }
        return $schedules;
    }

    /* ==== Run the Plugin ====*/

    /**
     * Runs the plugin initialization process.
     */
    public function run() {
        $this->loader->run();
    }
}
