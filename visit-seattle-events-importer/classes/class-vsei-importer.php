<?php
/**
 * Class VSEI_Importer - Imports event data into WordPress.
 *
 * Imports event data from Visit Seattle's internal API, sourced from BeDynamic. Creates WordPress posts and taxonomy
 * based on imported data. Also allows for deletion, resetting, and pruning of events.
 *
 * @package VSEI/classes
 * @version 1.1.1
 * @author Visit Seattle <webmaster@visitseattle.org>
 */
class VSEI_Importer
{
    /* ==== Constants ==== */

    /** @var string - URL for Event API endpoint. @readonly */
    const API_URL = 'http://eventapi.visitseattle.org/ClientService.asmx?wsdl';

    /** @var string - The security token for the API endpoint. @readonly */
    const API_TOKEN = 'notarealtoken';

    /** @var string - The WP custom taxonomies associated with event type posts. @readonly */
    const CUSTOM_TAXONOMIES = array('events_categories', 'events_venues', 'events_regions',);

    /** @var number - The # of events to request per chunk. @readonly */
    const CHUNK_SIZE = 200;

    /** @var string - The default data for import_all pulls. @readonly @since 1.1.0 */
    const IMPORT_ALL_DATE = '2011-01-01';

    /** @var string - The prefix for cache row names. @readonly @since 1.1.0 */
    const CACHE_NAME_PREFIX = 'vsei_events-xml_';

    /* ==== Variables ==== */

    /** @var array - A list of already imported events. */
    private static $existing_events = array();

    /* ================================
     * ========= Constructor  =========
     * ================================
     */

    /**
     * VSEI_Importer constructor.
     * @constructor
     */
    public function __construct() {
        // Nothing here yet
    }

    /* ==================================
	 * ========= AJAX Endpoints =========
	 * ==================================
	 */

    /* ==== Importer Actions ==== */

    /**
     * AJAX endpoint to pull new/updated data.
     * @public
     */
    public static function vsei_run_import_new() {
        $action = $_POST['import_action'] ?? 'import_new';
        $start_date = self::calculate_start_date();
        $init =  $_POST['init'] ?? 'resume';
        $use_cache = $init === 'hard';
        $args = array('action' => $action, 'date' => $start_date);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        }

        self::import_events_by_chunk($start_date, $use_cache, $args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to pull all data.
     * @public
     */
    public static function vsei_run_import_all() {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'import_all';
        $init = $_POST['init'] ?? 'hard';
        $use_cache = $init === 'hard';
        $args = array('action' => $action, 'date' => self::IMPORT_ALL_DATE);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        }

        self::import_events_by_chunk(self::IMPORT_ALL_DATE, $use_cache, $args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to pull a single event.
     * @public
     */
    public static function vsei_run_import_single() {
        $action = $_POST['import_action'] ?? 'import_single';
        $event_id = $_POST['event_id'] ?: null;
        $args = array('action' => $action, 'event_id' => $event_id);

        if (empty($event_id) || !intval($event_id)) {
            exit(json_encode(array('status' => 'error', 'message' => 'No or invalid listing ID given')));
        }
        if (!self::preflight($args, 'hard')) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        }

        self::import_single_event($event_id);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to delete all data.
     * @public
     */
    public static function vsei_run_delete_all() {
        $action = $_POST['import_action'] ?? 'delete_all';
        $init = $_POST['init'] ?? 'hard';
        $args = array('action' => $action);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        }

        self::delete_all_by_chunk($args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to delete dropped events.
     * @public
     */
    public static function vsei_run_delete_stale() {
        $action = $_POST['import_action'] ?? 'delete_stale';
        $interval = ($action === 'import_all') ? '-1 month' : '-1 week'; // Interval differs depending on action
        $start_date = self::calculate_start_date($interval);
        $init = $_POST['init'] ?? 'hard';
        $args = array('action' => $action, 'date' => $start_date);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        }

        self::delete_stale_events($start_date, $args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }


    /**
     * AJAX endpoint to manually remove all cached data.
     * @public @since 1.1.1
     */
    public static function vsei_run_clear_cache() {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'clear_cache';
        $args = array('action' => $action);

        if (!self::preflight($args, 'hard')) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        }

        self::clear_event_cache();
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AAJX endpoint to cancel the current operation.
     * @public
     */
    public static function vsei_run_cancel() {
        $run_data = self::get_last_run_data();
        $args = array(
            'action' => $run_data->action,
            'date' => $run_data->fetch_date,
            'event_id' => $run_data->event_id
        );
        self::set_last_run_data($args);
        self::set_import_status('free:canceled');
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to resume a canceled operation.
     * @public @since 1.1.0
     */
    public static function vsei_run_resume() {
        $prev_run_data = self::get_last_run_data();
        /** @noinspection PhpUndefinedFieldInspection */
        self::resume_process(
            $prev_run_data->action,
            intval($prev_run_data->page),
            $prev_run_data->fetch_date,
            $prev_run_data->event_id
        );
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * Handles the running of resumed processes.
     * @since 1.1.0
     *
     * @param string      $action_name - The name of the action/process being resumed.
     * @param number|null $start_page  - The page/chunk to fetch.
     * @param string|null $start_date  - The start date for fetching events from the API.
     * @param number|null $event_id    - The ID of the event to import.
     */
    protected static function resume_process($action_name, $start_page=null, $start_date=null, $event_id=null) {
        switch ($action_name) {
            case 'import_new':
                // Start date & start page required
                if (!$start_date || is_null($start_page)) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Missing required data for import_new')));
                }
                $args = array('action' => $action_name, 'date' => $start_date);
                if (!self::preflight($args, 'resume')) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
                }
                self::import_events_by_chunk($start_date, true, $args);
                self::postflight($args);
                break;
            case 'import_all':
                // Start page required
                $args = array('action' => $action_name);
                if (!self::preflight($args, 'resume')) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
                }
                self::import_events_by_chunk(self::IMPORT_ALL_DATE, true, $args);
                self::postflight($args);
                break;
            case 'import_single':
                // Event ID required
                if (!$event_id || !intval($event_id)) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Valid event ID required to resume import_single')));
                }
                $args = array('action' => $action_name, 'event_id' => $event_id);
                if (!self::preflight($args, 'hard')) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
                }
                self::import_single_event($event_id);
                self::postflight($args);
                break;
            case 'delete_all':
                $args = array('action' => $action_name);
                if (!self::preflight($args, 'resume')) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
                }
                self::delete_all_by_chunk($args);
                self::postflight($args);
                break;
            case 'delete_stale':
                // Start date is required
                if (!$start_date) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Date required to resume import_new')));
                }
                $args = array('action' => $action_name, 'date' => $start_date);
                if (!self::preflight($args, 'resume')) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
                }
                self::delete_stale_events($start_date, $args);
                self::postflight($args);
                break;
            case 'reset_all':
                $args = array('action' => $action_name);
                if (!self::preflight($args, 'resume')) {
                    exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
                }
                $method = explode('/', self::get_import_method());
                if ($method === 'delete') {
                    self::delete_all_by_chunk($args);
                } else if ($method[0] === 'import') {
                    self::import_events_by_chunk(self::IMPORT_ALL_DATE, true, $args);
                } else {
                    exit(json_encode(array('status' => 'error', 'message' => "Unhandled method '$method' when resuming 'reset_all'")));
                }
                self::postflight($args);
                break;
            default:
                exit(json_encode(array(
                    'status' => 'error',
                    'message' => "Unknown action '$action_name' set to resume."
                )));
        }
    }

    /* ==== Cron actions ==== */

    /**
     * Endpoint to clear/invalidate cache data via server cron.
     * @public @since 1.1.0
     *
     * @internal This is not currently called by the user, so does not need pre/postflight checks.
     */
    public static function vsei_run_cron_invalidate_cache() {
        self::clear_event_cache();
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * Endpoint to fetch new or changed partners via server cron.
     * @public
     */
    public static function vsei_run_cron_import() {
        $start_date = date('Y-m-d', strtotime('-2 days'));
        $args = array('action' => 'cron_import');

        /* == Delete stale == */

        if (!self::preflight($args, 'hard')) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        }

        self::delete_stale_events($start_date, $args);

        self::postflight($args);

        /* == Reset counters == */

        self::set_processed_count(0);
        self::set_total_count(0);

        /* == Import new/changed == */

        if (self::preflight($args, 'soft')) {
            exit(json_encode(array('status' => 'error', 'message' => 'Process failed preflight checks.')));
        };

        $page_num = 0;
        do {
            // First page ignores cache
            self::import_events_by_chunk($start_date, $page_num > 0, $args);
            self::set_current_page(++$page_num);
        } while (self::get_processed_count() < self::get_total_count());

        /* == Postflight == */

        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /* ==== Data Fetch ==== */

    /**
     * AJAX endpoint to fetch import status.
     * @public @since 1.1.0
     */
    public static function vsei_fetch_importer_status() {
        $import_data = array(
            'status'    => self::get_import_status(),
            'method'    => self::get_import_method(),
            'timestamp' => self::get_import_time(),
            'processed' => self::get_processed_count(),
            'added'     => self::get_add_count(),
            'deleted'   => self::get_delete_count(),
            'page'      => self::get_current_page(),
            'total'     => self::get_total_count()
        );
        $import_json = json_encode($import_data) ?: "{}";
        echo $import_json;
        exit;
    }

    /**
     * AJAX endpoint to fetch total event count.
     * @public
     */
    public static function vsei_fetch_total_count() {
        echo wp_count_posts('events')->publish;
        exit;
    }

    /**
     * AJAX endpoint to fetch the runing action.
     * @public @since 1.1.0
     */
    public static function vsei_fetch_running_action() {
        $prev_run_data = self::get_last_run_data();
        if ($prev_run_data) {
            echo $prev_run_data->action;
        } else {
            echo 'NONE';
        }
        exit;
    }

    /* ===============================
     * ==== BeDynamic API Methods ====
     * ===============================
     */

    /**
     * Fetches a list of live events from Visit Seattle's COE.
     * @since 1.1.0
     *
     * @param string $start_date - Start date for data pull
     * @param bool   $use_cache  - Should the importer use cached data? @since 1.1.1
     *
     * @return object|false - A collection of live events, or false on error.
     */
    private static function fetch_and_cache_live($start_date, $use_cache) {
        $cache_title = self::CACHE_NAME_PREFIX . date('Y-m-d', strtotime($start_date));

        // Return cached data, if it exists and isn't stale
        $cached_data = self::retrieve_cached_data($cache_title);

        if ($use_cache && !empty($cached_data)) {
            $events = json_decode(gzuncompress($cached_data->xmldata), true);
            $last_updated = strtotime($cached_data->lastupdated);
            if (!empty($events) && (strtotime("+1 day", $last_updated) >= time())) {
                return $events;
            }
        }

        // If not, grab fresh data
        $api_params = array(
            'securityToken'   => self::API_TOKEN,
            'lastUpdated'     => (new DateTime($start_date))->format('Y-m-d'),
            'numberOfReturns' => null,
            'numberToStartAt' => null
        );
        try {
            $api_client = new SoapClient(self::API_URL);
            /** @noinspection PhpUndefinedMethodInspection */
            $results = $api_client->SelectEventsLive($api_params)->SelectEventsLiveResult;
        } catch (SoapFault $fault) {
            return false;
        }

        if (empty($results) || is_wp_error($results)) return false;

        $prev_id = $cached_data->id ?? null;
        self::save_cached_data($cache_title, $results, $prev_id);
        return json_decode($results, true);
    }

    /**
     * Fetches details for a single event from Visit Seattle's COE.
     *
     * @param string $id - The ID of the COE record
     *
     * @return object - JSON-formatted record with single event details
     */
    private static function fetch_single_event_live($id) {
        $api_client = new SoapClient(self::API_URL);
        $api_params = array(
            'securityToken' => self::API_TOKEN,
            'eventIDs' => array($id),
        );
        /** @noinspection PhpUndefinedMethodInspection */
        $results = $api_client->SelectEventsByID($api_params)->SelectEventsByIDResult;
        return json_decode($results, true);
    }

    /**
     * Fetches a list of dropped events from Visit Seattle's COE.
     *
     * @param string $start_date - Start date for data pull
     *
     * @return object - JSON-formatted record of events
     */
    private static function fetch_events_dropped($start_date='2016-01-01') {
        $api_client = new SoapClient(self::API_URL);
        $api_params = array(
            'securityToken' => self::API_TOKEN,
            'lastUpdated' => $start_date,
        );
        /** @noinspection PhpUndefinedMethodInspection */
        $results = $api_client->SelectEventsDropped($api_params)->SelectEventsDroppedResult;
        return json_decode($results, true);
    }

    /* =============================
     * ==== Importer Operations ====
     * =============================
     */

    /* ==== Pre & Post Flight ==== */

    /**
     * Checks to see if the process can proceed and does initial setup.
     *
     * @internal `init` values are as follows:
     *           * 'hard'   - Default. Starting a new run. Hard init.
     *           * 'soft'   - Doing next step in run. Partial init.
     *           * 'resume' - Resuming/continuing. Use previous data.
     *
     * @param array $args - Data associate with the action.
     * @param bool  $init - How should the parameters be initialized?
     *
     * @return bool - Can the run proceed?
     */
    private static function preflight($args, $init) {
        // Check for event type & import state
        if (!self::events_exist() || !self::check_import_free()) return false;

        // Set import time
        date_default_timezone_set('America/Los_Angeles');
        self::set_import_time(date('Y-m-d H:i:s'));

         if ($init === 'soft') {
            // Next step start -- retain add/del count
            $previous_data = self::get_last_run_data();
            self::set_processed_count(0);
            self::set_current_page(0);
            self::set_delete_count($previous_data->deleted);
            self::set_add_count($previous_data->added);
            self::set_total_count(0);
        } else if ($init === 'resume') {
            // Continue/resume -- retain all values
            $previous_data = self::get_last_run_data();
            self::set_processed_count($previous_data->added); // Set to added to avoid skipping an event
            $current_page = self::calculate_current_page($previous_data);
            self::set_current_page($current_page);
            self::set_delete_count($previous_data->deleted);
            self::set_add_count($previous_data->added);
        } else {
            // Action start -- re-initialize
            self::set_processed_count(0);
            self::set_current_page(0);
            self::set_add_count(0);
            self::set_delete_count(0);
            self::set_total_count(0);
        }

        // Save process data
        self::set_last_run_data($args);

        return true;
    }

    /**
     * Implements teardown for the process.
     *
     * @param $args - Data associated with the completed process.
     */
    private static function postflight($args) {
        // Save process data
        self::set_last_run_data($args);

        // Allow time for db writes
        sleep(2);

        self::set_import_time(date('Y-m-d H:i:s'));
        self::set_import_status('free');
        return;
    }

    /* ==== Importing ==== */

    /**
     * Imports all events fetched from the COE.
     * @since 1.1.0
     *
     * @param string $start_date - The start date for the data pull.
     * @param bool   $use_cache  - Should the importer grab cached data? @since 1.1.1
     * @param array  $run_args   - Variables associated with the running action.
     */
    private static function import_events_by_chunk($start_date, $use_cache, $run_args) {
        self::set_import_status('running');
        self::set_import_method('import/fetch');

        $processed_count = self::get_processed_count();
        $added_count = self::get_add_count();
        $page_num = self::get_current_page();

        // Get starting index
        $chunk_start = $page_num === 0 ? $processed_count : $processed_count - 1;

        // * * Fetch events * *

        $results = self::fetch_and_cache_live($start_date, $use_cache);
        if (!$results || empty($results['ResultDetails'])) {
            exit(json_encode(array(
                'status' => 'error',
                'message' => "In import_events_by_chunk, empty results returned."
            )));
        }

        self::set_total_count($results['ResultDetails']['EventCount']);
        self::check_and_handle_cancel($run_args);

        // * *  Save events * *

        self::set_import_method('import/update');

        $live_events = array_slice($results['ResultDetails']['BeDynamicExport']['Events'], $chunk_start, self::CHUNK_SIZE);
        foreach ($live_events as $event) {
            self::check_and_handle_cancel($run_args);
            self::set_processed_count(++$processed_count);
            $success = self::fetch_and_save_event($event['ID']);
            if ($success) {
                self::set_add_count(++$added_count);
            }
            unset($event);
        }
    }

    /**
     * Imports a single event into WordPress as an event type post.
     * @since 1.1.0
     *
     * @param string $event_id - The ID of the event being imported.
     */
    private static function import_single_event($event_id) {
        self::set_import_status('running');
        self::set_import_method('import/update');

        self::set_total_count(1);
        self::set_processed_count(1);

        $success = self::fetch_and_save_event($event_id);
        if ($success) {
            self::set_add_count(1);
        }
    }

    /* ==== Deletion ==== */

    /**
     * Removes WordPress posts for events that have been dropped on the COE.
     *
     * @param string $start_date - The start date for the fetch.
     * @param array  $run_args   - Variables associated with the running action.
     */
    private static function delete_stale_events($start_date, $run_args) {
        self::set_import_status('running');
        self::set_import_method('delete/fetch');

        $processed_count = self::get_processed_count();
        $deleted_count = self::get_delete_count();

        // * * Fetch dropped events * *

        $results = self::fetch_events_dropped($start_date);
        if (!$results || !isset($results['ResultDetails'])) {
            exit(json_encode(array(
                'status' => 'error',
                'message' => 'Empty results returned while deleting stale events.'
            )));
        }
        self::set_total_count($results['ResultDetails']['EventCount']);

        self::check_and_handle_cancel($run_args);

        // * * Remove events * *

        self::set_import_method('delete/prune');

        $query_args = array(
            'post_type' => 'events',
            'posts_per_page' => 1,
            'meta_key' => 'event_id'
        );

        $invalid_events = $results['ResultDetails']['BeDynamicExport']['Events'];
        foreach ($invalid_events as $event) {
            self::check_and_handle_cancel($run_args);
            self::set_processed_count(++$processed_count);
            if (empty($event['ID'])) {
                error_log("[VSEI Plugin] Event with name " . $event['Name'] . " has no ID and cannot be pruned.");
                continue;
            }
            $fetch_args = array_merge($query_args, array('meta_value' => $event['ID']));
            $posts_to_delete = get_posts($fetch_args);
            // Delete the WP posts -- might be multiple b/c of unintentional duplicates
            foreach ($posts_to_delete as $post) {
                if (!empty($post->ID)) {
                    $success = wp_delete_post($post->ID, true);
                    if ($success) self::set_delete_count(++$deleted_count);
                }
            }
            unset($event);
        }

        self::remove_event_metadata();
        self::remove_orphaned_data();
    }

    /**
     * Removes all WP event posts and associated metadata.
     * @since 1.1.0
     *
     * @param array $run_args - Variables associated with the running action.
     */
    private static function delete_all_by_chunk($run_args) {
        self::set_import_status('running');
        self::set_import_method('delete/fetch');

        $processed_count = self::get_processed_count();
        $delete_count = self::get_delete_count();

        // * * Purge event posts * *

        $events = new WP_Query(array(
            'post_type' => 'events',
            'posts_per_page' => self::CHUNK_SIZE,
            'orderby' => 'name'
        ));

        if ($events->have_posts()) {
            self::set_import_method('delete/prune');
            self::set_total_count($events->found_posts + $processed_count);
            self::check_and_handle_cancel($run_args);

            while ($events->have_posts()) {
                self::check_and_handle_cancel($run_args);
                self::set_processed_count(++$processed_count);
                $events->the_post();
                $success = wp_delete_post(get_the_ID(), true);
                if ($success) {
                    self::set_delete_count(++$delete_count);
                }
            }
        }
        wp_reset_query();

        self::remove_event_metadata();
        self::remove_orphaned_data();
    }

    /* ==== Cache Management ==== */

    /**
     * Grabs cached data for the current action.
     * @since 1.1.0
     *
     * @param string $cache_title - The name of the row being retrieved.
     *
     * @return object|false - The results of the query. False if no results.
     */
    private static function retrieve_cached_data($cache_title) {
        global $wpdb;
        $table_name = VSEI_CACHE_TABLE;
        $results = $wpdb->get_results("SELECT * FROM $table_name WHERE `name` = '$cache_title'");

        if (!$results || !$wpdb->num_rows) return false;

        return $results[0];
    }

    /**
     * Saves retrieved data to the database. Updates pre-existing rows.
     * @since 1.1.0
     *
     * @param string $row_name - The name of the db row containing the data.
     * @param object $data - The data to be saved.
     * @param number|null $row_id - The ID of the row, if being updated.
     *
     * @return int|false - Whether the save succeeded. 1/true or 0/false
     */
    private static function save_cached_data($row_name, $data, $row_id=null) {
        global $wpdb;
        $data_to_insert = array(
            'name' => $row_name,
            'xmldata' => gzcompress($data),
            'lastupdated' => date('Y-m-d H:i:s')
        );
        $db_param_arr = array('%s', '%s', '%s');

        if (!is_null($row_id)) {
            $data_to_insert = array_merge($data_to_insert, array('id' => $row_id));
            $db_param_arr = array_merge($db_param_arr, array('%s'));
        }

        $success = $wpdb->replace(VSEI_CACHE_TABLE, $data_to_insert, $db_param_arr);
        return $success;
    }

    /**
     * Removes all rows from the cache table.
     * @since 1.1.0
     */
    private static function clear_event_cache() {
        global $wpdb;

        self::set_import_status('running');
        self::set_import_method('cache/delete');

        $cache_count = $wpdb->get_var("SELECT COUNT(*) FROM " . VSEI_CACHE_TABLE);
        self::set_processed_count($cache_count);
        self::set_total_count($cache_count);

        $table_name = VSEI_CACHE_TABLE;
        $wpdb->query("TRUNCATE $table_name");

        self::set_delete_count($cache_count);
    }

    /* ==============================
     * ==== WordPress Operations ====
     * ==============================
     */

    /* ==== Additions ==== */

    /**
     * Imports detailed data for a single event.
     * @since 1.1.0
     *
     * @param string $event_id - The COE ID of the event
     *
     * @return bool - Was the import successful?
     */
    private static function fetch_and_save_event($event_id) {
        $event_data = self::fetch_single_event_live($event_id);
        if (!isset($event_data['ResultDetails'])) {
            error_log("[VSEI Plugin] Invalid data returned by SelectEventsByID for event with ID #$event_id.");
            return false;
        }

        // Should only return one of each - ignore any other listings
        $event = $event_data['ResultDetails']['BeDynamicExport']['Events'][0];
        $venue = $event_data['ResultDetails']['BeDynamicExport']['Venues'][0];

        // Check for venue
        if (empty($venue)) {
            error_log("[VSEI Plugin] No venue returned for event with ID #$event_id.");
            return false;
        }

        // Check for event & required data
        if (empty($event) || empty($event['ID']) || empty($event['Name'])) {
            error_log("[VSEI Plugin] Empty or incomplete data returned for event with ID #$event_id.");
            return false;
        }

        // Insert or update post
        $post_id = self::insert_or_update_post($event);
        if (!$post_id) {
            error_log("[VSEI Plugin] Failed to insert or update event with ID #$event_id.");
            return false;
        }

        // Update metadata
        self::set_post_metadata($post_id, $event);
        self::set_category_taxonomy($post_id, $event);
        self::set_custom_taxonomies($post_id, $venue);

        return true;
    }

    /**
     * Creates or updates a WP post for a given COE event.
     *
     * @param $event_data - The data being inserted
     *
     * @return int|bool - The post's ID. False on error.
     */
    private static function insert_or_update_post($event_data) {
        // Look for preexisting post
        if (empty(self::$existing_events)) {
            self::create_event_id_mapping();
        }
        $post_id = null;
        if (array_key_exists(intval($event_data['ID']), self::$existing_events)) {
            $post_id = self::$existing_events[$event_data['ID']];
        }

        // Set the post data
        $post_params = array(
            'post_title' => $event_data['Name'],
            'post_name' => sanitize_title($event_data['Name']),
            'post_content' => $event_data['Description'],
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'events'
        );

        if (!$post_id) {
            $post_id = wp_insert_post($post_params);
        } else {
            $post_params['ID'] = $post_id;
            wp_update_post($post_params);
        }

        // Report errors
        if (is_wp_error($post_id)) {
            error_log("[VSEI Plugin] Failed to insert/update " . $event_data['NAME'] . ".");
            $messages = $post_id->get_error_messages();
            foreach ($messages as $message) {
                error_log("[VSEI Plugin] $message");
            }
        }

        return !empty($post_id) && !(is_wp_error($post_id)) ? $post_id : false;
    }

    /**
     * Sets metadata for a given WP post.
     *
     * @param string $post_id    - The ID of the associated post.
     * @param object $event_data - The event's data.
     */
    private static function set_post_metadata($post_id, $event_data) {
        // * * Start date * *

        $start_date = explode('T', $event_data['StartDate']);
        $formatted_start_date = date('Y-m-d', strtotime($start_date[0]));
        update_post_meta($post_id, 'start_date', $formatted_start_date);

        // * * End date * *

        $end_date = explode('T', $event_data['EndDate']);
        $formatted_end_date = date('Y-m-d', strtotime($end_date[0]));
        update_post_meta($post_id, 'end_date', $formatted_end_date);

        // * * Other Post Meta * *

        $event_meta_mapping = array(
            'event_id' => 'ID',
            'venue' => 'VenueID',
            'phone' => 'Phone',
            'website' => 'EventURL',
            'static' => 'Static',
            'featured' => 'Featured'
        );

        foreach($event_meta_mapping as $key => $value) {
            if (isset($event_data[$value])) {
                update_post_meta($post_id, $key, $event_data[$value]);
            }
        }
    }

    /**
     * Sets categories for a given WP post.
     *
     * @param string $post_id    - The ID of the associated post.
     * @param object $event_data - The event's data.
     */
    private static function set_category_taxonomy($post_id, $event_data) {
        if (!empty($event_data['WebCodes'])) {
            $categories = array();
            foreach ($event_data['WebCodes'] as $code) {
                $categories[] = $code['Name'];
            }
            wp_set_object_terms($post_id, $categories, self::CUSTOM_TAXONOMIES[0]);
        }
    }

    /**
     * Creates new Venues & associates Region and Venue information with a given post.
     *
     * @param string $post_id    - The ID of the associated post.
     * @param object $venue_data - The venue's data.
     */
    private static function set_custom_taxonomies($post_id, $venue_data) {
        // Region/neighborhood
        wp_set_object_terms($post_id, $venue_data['Neighborhood'], self::CUSTOM_TAXONOMIES[2]);

        // Venue
        $venue_id = wp_set_object_terms($post_id, $venue_data['Name'], self::CUSTOM_TAXONOMIES[1]);
        if (empty($venue_id) || is_wp_error($venue_id)) {
            error_log("[VSEI Plugin] Could not set venue with ID #$venue_id for event post with ID #$post_id.");
            return;
        }

        // Venue description
        wp_update_term($venue_id[0], self::CUSTOM_TAXONOMIES[1], array('description' => $venue_data['Description']));

        // Venue address
        foreach ($venue_data['Address'] as $key => $value) {
            if ($key === 'PostalCode') {
                $key = 'postal_code';
            } else if ($key === 'Country') {
                $key = 'region';
            }
            update_option(self::CUSTOM_TAXONOMIES[1] . '_' . $venue_id[0] . '_' . $key, $value);
        }

        // Venue meta
        $venue_meta_mapping = array(
            'venue_id' => 'ID',
            'neighborhood' => 'Neighborhood',
            'classification' => 'PrimaryClassification'
        );
        foreach ($venue_meta_mapping as $key => $value) {
            update_option(self::CUSTOM_TAXONOMIES[1] . '_' . $venue_id[0] . '_' . $key, $venue_data[$value]);
        }
    }

    /* ==== Deletion ==== */

    /**
     * Removes metadata posts that have no associated posts.
     * @since 1.1.0
     */
    private static function remove_event_metadata() {
        self::set_import_method('delete/meta');

        // * * Purge taxonomy posts * * *

        $tax_query_args = array(
            'taxonomy'   => self::CUSTOM_TAXONOMIES,
            'hide_empty' => false,
            'orderby'    => 'term_id',
            'count'      => true
        );
        $tax_terms = get_terms($tax_query_args);
        foreach ($tax_terms as $term) {
            if ($term->count === 0) {
                wp_delete_term($term->term_id, $term->taxonomy);
            }
        }
    }

    /**
     * Removes any other metadata associated with non-existent event posts.
     */
    private static function remove_orphaned_data() {
        global $wpdb;
        self::set_import_method('delete/cleanup');

        $wpdb->query(
            "DELETE pm FROM $wpdb->postmeta AS pm
             LEFT JOIN $wpdb->posts AS wp ON wp.ID = pm.post_id
             WHERE wp.ID IS NULL AND wp.post_type = 'events'");
    }

    /* =================================
     * ======= Getters & Setters =======
     * =================================
     */

    /**
     * Returns chunk size.
     * @public @since 1.1.0
     *
     * @return int
     */
    public static function get_chunk_size() {
        return self::CHUNK_SIZE;
    }

    /**
     * Returns import status.
     *
     * (@interal Possible values are 'running', 'free', 'free:canceled', 'error', and 'busy'.)
     *
     * @return string
     */
    private static function get_import_status() {
        wp_cache_delete('vsei_import_status', 'options');
        return get_option('vsei_import_status');
    }

    /**
     * Sets import status.
     *
     * @param string $status
     */
    private static function set_import_status($status) {
        update_option('vsei_import_status', $status);
    }

    /**
     * Returns the import method.
     * @since 1.1.0
     *
     * @return string
     */
    private static function get_import_method() {
        wp_cache_delete('vsei_import_method', 'options');
        return get_option('vsei_import_method');
    }

    /**
     * Sets the import method.
     * @since 1.1.0
     *
     * @param string $method
     */
    private static function set_import_method($method) {
        update_option('vsei_import_method', $method);
    }

    /**
     * Returns time of last importer run.
     *
     * @return string
     */
    private static function get_import_time() {
        wp_cache_delete('vsei_last_updated', 'options');
        return get_option('vsei_last_updated');
    }

    /**
     * Sets time of last importer run.
     *
     * @param string $timestamp
     */
    private static function set_import_time($timestamp) {
        update_option('vsei_last_updated', $timestamp);
    }

    /**
     * Returns number of imported events.
     *
     * @return int
     */
    private static function get_processed_count() {
        wp_cache_delete('vsei_processed_count', 'options');
        return intval(get_option('vsei_processed_count'));
    }

    /**
     * Sets number of imported events.
     *
     * @param int $count
     */
    private static function set_processed_count($count) {
        update_option('vsei_processed_count', intval($count));
    }

    /**
     * Returns the number of added events.
     *
     * @return int
     */
    private static function get_add_count() {
        wp_cache_delete('vsei_add_count', 'options');
        return intval(get_option('vsei_add_count'));
    }

    /**
     * Sets the number of added events.
     *
     * @param int $count
     */
    private static function set_add_count($count) {
        update_option('vsei_add_count', intval($count));
    }

    /**
     * Returns number of deleted events.
     *
     * @return int
     */
    private static function get_delete_count() {
        wp_cache_delete('vsei_delete_count', 'options');
        return intval(get_option('vsei_delete_count'));
    }

    /**
     * Sets number of deleted events.
     *
     * @param int $count
     */
    private static function set_delete_count($count) {
        update_option('vsei_delete_count', intval($count));
    }

    /**
     * Returns the total number of events in the operation.
     *
     * @return int
     */
    private static function get_total_count() {
        wp_cache_delete('vsei_total_count', 'options');
        return intval(get_option('vsei_total_count'));
    }

    /**
     * Sets the total number of events in the operation.
     *
     * @param int $count
     */
    private static function set_total_count($count) {
        update_option('vsei_total_count', intval($count));
    }

    /**
     * Returns the current page.
     *
     * @return int
     */
    private static function get_current_page() {
        wp_cache_delete('vsei_current_page', 'options');
        return intval(get_option('vsei_current_page'));
    }

    /**
     * Sets the current page.
     *
     * @param int $page_num
     */
    private static function set_current_page($page_num) {
        update_option('vsei_current_page', intval($page_num));
    }

    /**
     * Gets data from the last canceled or completed run.
     *
     * `get_last_run_data` retrieves information from the last completed or canceled run. It is currently only used
     *  when resuming an action.
     *
     * @return object
     */
    private static function get_last_run_data() {
        wp_cache_delete('vsei_last_run_data', 'options');
        $run_data = get_option('vsei_last_run_data');
        return json_decode($run_data);
    }

    /**
     * Records data from the last completed or canceled run and saves it to the database.
     *
     * `set_last_run_data` is called whenever a process starts, is canceled, or completes. It records data the client
     *  and server required to resume the run.
     *
     * @param $args
     *
     * @internal @param string $action     - The name of the process that just finished.
     * @internal @param string $fetch_date - The start date for any data fetch required for the run, if required.
     * @internal @param string $listing_id - The ID of the listing to be imported, if required.
     */
    private static function set_last_run_data($args) {
        $last_run_data = array(
            'action' => $args['action'] ?? 'undefined',
            'fetch_date' => $args['date'] ?? '',
            'page' => self::get_current_page(),
            'added' => self::get_add_count(),
            'deleted' => self::get_delete_count(),
            'processed'  => self::get_processed_count(),
            'event_id' => $args['event_id'] ?? ''
        );
        $last_run_json = json_encode($last_run_data) ?: '{}';
        update_option('vsei_last_run_data', $last_run_json);
    }

    /* ==========================
     * ==== Helper Functions ====
     * ==========================
     */

    /**
     * Creates an object that maps COE IDs to WP post IDs.
     */
    private static function create_event_id_mapping() {
        global $wpdb;

        $event_query =
            "SELECT meta_value, post_id
            FROM $wpdb->postmeta, $wpdb->posts
            WHERE $wpdb->postmeta.meta_key = 'event_id'
            AND $wpdb->postmeta.post_id = $wpdb->posts.ID";

        $event_ids = $wpdb->get_results($event_query, OBJECT);
        foreach ($event_ids as $event) {
            self::$existing_events[$event->meta_value] = $event->post_id;
        }
    }

    /**
     * Handles the canceling of runs.
     *
     * `check_and_handle_cancel` checks for a 'free' importer state, which indicates that the importer should stop
     *  processing and start listening for new commands. If the importer does stop, it first records data from the
     *  canceled process, in case that process needs to be resumed.
     *
     * @param array $args - Data from the process, to be saved to the database.
     */
    private static function check_and_handle_cancel($args) {
        if (self::check_import_free()) {
            self::set_last_run_data($args);
            exit(json_encode(array("status" => "canceled")));
        }
    }


    /**
     * Checks for import status of 'free'.
     *
     * @return bool - Is import status free?
     */
    private static function check_import_free() {
        $import_status = explode(':', self::get_import_status());
        if ($import_status[0] !== 'free') {
            return false;
        }
        return true;
    }

    /**
     * Checks for existence of 'events' WP post type.
     *
     * @return bool - Does 'events' post type exist?
     */
    private static function events_exist() {
        if (!post_type_exists('events')) {
            error_log("[VSEI Plugin] Can't run import because post type events does not exist\n");
            return false;
        }
        return true;
    }

    /**
     * Determines the appropriate start date for the run, depending on the given data.
     * @since 1.1.0
     *
     * @param string $interval - The interval to calculate the date from.
     *
     * @return string - The fetch date.
     */
    private static function calculate_start_date($interval='-2 days') {
        if (!empty($_POST['date'])) return $_POST['date'];

        $last_run_date = self::get_import_time();
        if (strtotime($last_run_date) < strtotime($interval)) {
            return $last_run_date;
        }

        return date('Y-m-d', strtotime($interval));
    }

    /**
     * Determines the current page, depending on the given data.
     * @since 1.1.0
     *
     * @param object $previous_data - Data from the most recent run.
     *
     * @return int - The current page.
     */
    private static function calculate_current_page($previous_data) {
        if (isset($_POST['page'])) return $_POST['page'];
        if (isset($previous_data->page)) return $previous_data->page;
        return 0;
    }
}
