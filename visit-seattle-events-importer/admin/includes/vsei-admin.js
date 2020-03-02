/**
 * Class VSEI_ImporterClient - Runs import process by requesting server actions and displays feedback to the user.
 *
 * @package VSEI/admin/includes
 * @version 1.1.0
 * @author Visit Seattle <webmaster@visitseattle.org>
 * @constructor
 */
var VSEI_ImporterClient = (function($) {
    /**
     * The public importer object.
     * @public
     *
     * @type {{ status: string, previousStatus: string, runningAction: string, chunkSize: number }}
     */
    var importer = {
        status:         '',
        previousStatus: '',
        runningAction:  '',
        chunkSize:      0
    };

    /** @const {number} - Standard heartbeat/fetch timespan. */
    const HEARTBEAT_DURATION = 30 * 1000;

    /** @const {number} - The short heartbeat timespan. */
    const HEARTBEAT_DURATION_QUICK = 5 * 1000;

    /** @const {number} - The standard interval for fetching new/changed events. */
    const INTERVAL_HOURS = 48; // Minimum time interval to query new posts

    /** @arg @protected {object} - The daemon controlling the import heartbeat. */
    var importDaemon;

    /* ===== Event Handling ===== */

    /**
     * Registers handlers for user-initiated events.
     *
     * @listens blur
     * @listens click
     */
    var setupEventHandlers = function() {
        var $errorMessage = $('.error-message'),
            $loadingMessage = $('.loading-message');

        // * * Input blur * *
        $(document).on('blur', '#vsei_import-start-date', function() {
            var date = $(this).attr('value'),
                normalizedDate;
            if (date) {
                normalizedDate = moment($(this).attr('value')).format('YYYY-MM-DD');
                $(this).attr('value', normalizedDate);
            }
        });

        // * * Refresh button * *
        $(document).on('click', '#vsei_refresh', function() {
            $('#vsei_refresh').children('i').addClass('spin');
            MiniDaemon.forceCall(importDaemon);
            importDaemon.synchronize();
        });

        // * * Action Buttons * *
        $(document)
        // Resume
            .on('click', '#vsei_resume', function() {
                importer.runningAction = 'manual_resume';
                runImporterProcess('vsei_run_resume');
            })
            // Cancel
            .on('click', '#vsei_cancel', function() {
                if (confirm('Are you sure you want to cancel this action? Changes that have already occurred will not be undone.')) {
                    importer.runningAction = 'cancel';
                    runImporterProcess('vsei_run_cancel');
                    // Give immediate feedback
                    $loadingMessage.show();
                    displayFriendlyStatus('canceling');
                }
            })
            // Update single
            .on('click', '#vsei_update-single', function() {
                var eventID = $('#vsei_event-id').val();
                // Data validity checker
                if (!eventID || isNaN(eventID)) {
                    console.log("[VSEI Plugin] Single import failed. Invalid ID given.");
                    $errorMessage.html("<span>Error: Invalid ID given.</span>");
                    $errorMessage.show();
                } else {
                    importer.runningAction = 'import_single';
                    runImporterProcess('vsei_run_import_single', {
                        'import_action': 'import_single',
                        'event_id':      eventID
                    });
                }
            })
            // Update new
            .on('click', '#vsei_update', function() {
                var startDate = $('#vsei_import-start-date').attr('value');
                importer.runningAction = 'import_new';
                runImporterProcess('vsei_run_delete_stale', {
                    'import_action': 'import_new',
                    'date':          startDate
                });
            })
            // Update all
            .on('click', '#vsei_update-all', function() {
                if (confirm("Are you sure you want to IMPORT ALL EVENTS? This action can take a long time.")) {
                    var startDate = $('#vsei_import-start-date').attr('value');
                    importer.runningAction = 'import_all';
                    runImporterProcess('vsei_run_delete_stale', {
                        'import_action': 'import_all',
                        'date':          startDate
                    });
                }
            })
            // Reset all
            .on('click', '#vsei_reset', function() {
                if (confirm('Are you sure you want to RESET ALL EVENTS? This action can take a long time.')) {
                    importer.runningAction = 'reset_all';
                    runImporterProcess('vsei_run_delete_all', {
                        'import_action': 'reset_all'
                    });
                }
            })
            // Purge all
            .on('click', '#vsei_purge', function() {
                if (confirm('Are you sure you want to DELETE ALL EVENTS? This action takes a long time and cannot be undone.')) {
                    importer.runningAction = 'delete_all';
                    runImporterProcess('vsei_run_delete_all', {
                        'import_action': 'delete_all'
                    });
                }
            })
            // Prune invalid
            .on('click', '#vsei_prune', function() {
                var startDate = $('#vsei_import-start-date').attr('value');
                importer.runningAction = 'delete_stale';
                runImporterProcess('vsei_run_delete_stale', {
                    'import_action': 'delete_stale',
                    'date':          startDate
                });
            })
            // Clear cache
            .on('click', '#vsei_clear-cache', function() {
                importer.runningAction = 'clear_cache';
                runImporterProcess('vsei_run_clear_cache', {
                    'import_action': 'clear_cache'
                });
            });
    };

    /* ===== Process Controllers ===== */

    /**
     * Fetches data about Importer's current status via MiniDaemon interval.
     */
    var heartbeat = function() {
        // Pause heartbeat while polling
        importDaemon.pause();
        $.post(
            ajaxurl,
            { 'action': 'vsei_fetch_import_status' },
            function(response) {
                console.log('[VSEI Plugin] ' + response);
                importDaemon.start();
                handleServerResponse(JSON.parse(response));
            });
    };

    /**
     * Primary function to handle server responses.
     *
     * `handlerServerResponse` is the primary function to determine how the Importer should operate. It reads in the
     *  current importer status and determines what to do next. This can include displaying results, requesting a new
     *  action phase, or completing the current action.
     *
     * @param {object} importData - Parsed JSON data returned from Importer.
     */
    var handleServerResponse = function(importData) {
        importer.previousStatus = importer.status;
        importer.status = importData.status;

        // Determine what should happen now
        switch (importData.status) {
            case 'running':
                importDaemon.rate = HEARTBEAT_DURATION_QUICK;
                displayServerStatus(importData);
                break;
            case 'free':
                importDaemon.rate = HEARTBEAT_DURATION;
                displayServerStatus(importData);
                if (importer.previousStatus && importer.previousStatus !== 'free') {
                    // Continuing run
                    requestNextAction(importData);
                } else {
                    // End of run
                    completeAction(importData);
                }
                break;
            case 'free:canceled':
                importDaemon.rate = HEARTBEAT_DURATION;
                displayServerStatus(importData);
                completeAction(importData);
                break;
            case 'busy':
                $('.js_action-cancel').show();
                displayFriendlyStatus('busy', '');
                break;
            default:
                console.log("[VSEI Plugin] Error: Unhandled status '" + importData.status + "' returned from importer.");
        }
        importDaemon.synchronize();
    };

    /**
     * Determines next action phase, depending on returned data.
     *
     * `requestNextAction` determines next action phase depending on the main running action. Either triggers the next
     *  page of the same phase, starts a new phase, or completes the action.
     *
     * @since 1.1.0
     * @param {object} importData - Parsed JSON data returned from the Importer server.
     */
    var requestNextAction = function(importData) {
        var startDate = $('#vsei_import-start-date').attr('value'),
            importerMethod = importData.method.split('/')[0],
            processed = parseInt(importData.processed),
            total = parseInt(importData.total),
            page = parseInt(importData.page);

        switch (importer.runningAction) {
            case 'cancel':
                break;
            case 'import_all':
                if (importerMethod === 'delete') {
                    if (processed < total) {
                        runImporterProcess('vsei_run_delete_stale', {
                            'import_action': importer.runningAction,
                            'date':          startDate,
                            'init':          'resume'
                        });
                    } else {
                        runImporterProcess('vsei_run_import_all', {
                            'import_action': importer.runningAction,
                            'init':          'soft'
                        });
                    }
                } else if (importerMethod === 'import') {
                    if (processed < total) {
                        if (processed < ((page + 1) * importer.chunkSize)) {
                            // Resume by requesting the same chunk
                            runImporterProcess('vsei_run_import_all', {
                                'import_action': importer.runningAction,
                                'page':          page,
                                'init':          'resume'
                            });
                        } else {
                            // Request the next chunk
                            runImporterProcess('vsei_run_import_all', {
                                'import_action': importer.runningAction,
                                'page':          page + 1,
                                'init':          'resume'
                            });
                        }
                    } else {
                        completeAction(importData);
                    }
                }
                break;
            case 'import_new':
                if (importerMethod === 'delete') {
                    if (processed < total) {
                        runImporterProcess('vsei_run_delete_stale', {
                            'import_action': importer.runningAction,
                            'date':          startDate,
                            'init':          'resume'
                        });
                    } else {
                        runImporterProcess('vsei_run_import_new', {
                            'import_action': importer.runningAction,
                            'date':          startDate,
                            'init':          'soft'
                        });
                    }
                } else if (importerMethod === 'import') {
                    if (processed < total) {
                        if (processed < (page * importer.chunkSize)) {
                            // Resume by requesting same chunk
                            runImporterProcess('vsei_run_import_new', {
                                'import_action': importer.runningAction,
                                'date':          startDate,
                                'page':          page,
                                'init':          'resume'
                            });
                        } else {
                            // Request the same chunk
                            runImporterProcess('vsei_run_import_new', {
                                'import_action': importer.runningAction,
                                'date':          startDate,
                                'page':          page + 1,
                                'init':          'resume'
                            });
                        }
                    } else {
                        completeAction(importData);
                    }
                }
                break;
            case 'import_single':
                // Only needs one request
                completeAction(importData);
                break;
            case 'reset_all':
                if (importerMethod === 'delete') {
                    if (processed < total) {
                        runImporterProcess('vsei_run_delete_all', {
                            'import_action': importer.runningAction,
                            'page':          page + 1,
                            'init':          'resume'
                        });
                    } else {
                        runImporterProcess('vsei_run_import_all', {
                            'import_action': importer.runningAction,
                            'init':          'soft'
                        });
                    }
                } else if (importerMethod === 'import') {
                    if (processed < total) {
                        if (processed < (page * importer.chunkSize)) {
                            // Resume by requesting same chunk
                            runImporterProcess('vsei_run_import_all', {
                                'import_action': importer.runningAction,
                                'page':          page,
                                'init':          'resume'
                            });
                        } else {
                            // Request next chunk
                            runImporterProcess('vsei_run_import_all', {
                                'import_action': importer.runningAction,
                                'page':          page + 1,
                                'init':          'resume'
                            });
                        }
                    } else {
                        completeAction(importData);
                    }
                }
                break;
            case 'delete_all':
                if (processed < total) {
                    runImporterProcess('vsei_run_delete_all', {
                        'import_action': importer.runningAction,
                        'page':          page + 1,
                        'init':          'resume'
                    });
                } else {
                    completeAction(importData);
                }
                break;
            case 'delete_stale':
                if (processed < total) {
                    runImporterProcess('vsei_run_delete_stale', {
                        'import_action': importer.runningAction,
                        'date':          startDate,
                        'init':          'resume'
                    });
                } else {
                    completeAction(importData);
                }
                break;
            case 'clear_cache':
                // Only needs one request
                completeAction(importData);
                break;
            default:
                console.log("[VSEI Plugin] Error: Unhandled action '" + importer.runningAction + "' returned from importer.");
                completeAction(importData);
        }
    };

    /**
     * Completes an Importer action.
     *
     * `completeAction` ends the action by reducing heartbeat speed, toggling interface items (including resume button),
     * fetching/displaying information about the completed run, and updating timespan for fetching new/chaged.
     *
     * @since 1.1.0
     * @param {object} importData - Parsed JSON data returned from the server Importer.
     */
    var completeAction = function(importData) {
        var $cancelButton = $('.js_action-cancel'),
            $resumeButton = $('.js_action-resume'),
            $actionButton = $('.js_action'),
            $counters = $('.count-message'),
            previousStatus = importer.previousStatus ? importer.previousStatus.split(':')[0] : '';

        $actionButton.prop('disabled', false);
        $cancelButton.hide();
        $counters.hide();

        // Display finished run info
        displayLastRunInfo(importData);
        fetchFinalCount();
        updateActionTimespan(importData.timestamp);

        // Determine whether to show resume button
        if (importData.status === 'free:canceled') {
            $resumeButton.show();
        } else {
            $resumeButton.hide();
        }
    };

    /**
     * Completes an Importer action that has encountered an error.
     *
     * `completeActionWithError` runs if the server encounters an error. It sends the 'cancel' action to the server,
     * toggles the UI to the error state, slows the heartbeat, and displays the server error message.
     *
     * @since 1.1.0
     * @param {string} errorMessage - The error returned from the server.
     */
    var completeActionWithError = function(errorMessage) {
        var $cancelButton = $('.js_action-cancel'),
            $resumeButton = $('.js_action-resume'),
            $actionButtons = $('.js_action'),
            $errorMessage = $('.error-message');

        // Send cancel action
        runImporterProcess('vsei_run_cancel');

        // Toggle buttons
        $actionButtons.prop('disabled', false);
        $cancelButton.hide();
        $resumeButton.show();

        // Toggle status messages
        $errorMessage.html('<span>Error: ' + errorMessage + '</span>');
        $errorMessage.show();
    };

    /* ==== Server Communication ==== */

    /**
     * Runs Importer action by sending a request to the server.
     *
     * @since 1.1.0
     * @param {string} processName - The name of the action/process being triggered.
     * @param {object} [data] - Data required by the action.
     */
    var runImporterProcess = function(processName, data) {
        var args = { 'action': processName };
        console.log("[VSEI Plugin] Starting action '" + processName + "'.");

        handleRunStart();

        // Add action related data to arguments list
        if (typeof(data) !== 'undefined') {
            Object.assign(args, data);
        }

        $.post(ajaxurl, args, errorChecker)
         .fail(function(xhr) {
             // Ignore gateway timeouts
             if (xhr.status !== 504) {
                 completeActionWithError("Request to server returned a failure code. Press 'resume' to continue the action.");
             }
         });
    };

    /**
     * Handles setup required to start Importer action.
     *
     * `handleRunStart` is triggered before every server action request. It determines the current action, speeds up
     *  the heartbeat, and provides immediate user feedback.
     */
    var handleRunStart = function() {
        var $errorMessage = $('.error-message'),
            $loadingMessage = $('.loading-message'),
            $actionButtons = $('.js_action');

        // Tell client it's running, in case of slow server response
        importer.status = 'running';

        // Determine action to resume
        if (importer.runningAction === 'manual_resume') {
            fetchRunningAction();
        }

        // Speed up heartbeat
        importDaemon.rate = HEARTBEAT_DURATION_QUICK;
        importDaemon.synchronize();

        // Show immediate feedback to user
        $errorMessage.hide();
        $loadingMessage.show();
        $actionButtons.prop('disabled', true);

    };

    /**
     * Checks current action for an error state.
     *
     * `errorChecker` always runs on completion of a server request. It checks the response for an error and, if
     * found, prints it to the screen. It also slows the heartbeat.
     *
     * @param {string} response - The results of the completed action.
     */
    var errorChecker = function(response) {
        var $loadingMessage = $('.loading-message'),
            parsedRes = response ? JSON.parse(response) : {},
            message;

        if (parsedRes.status && parsedRes.status === 'error') {
            $loadingMessage.hide();
            message = !!parsedRes.message ? parsedRes.message : 'Another operation is already running.';
            completeActionWithError(message);
        }

    };

    /* ==== User Interface ==== */

    /**
     * Alters the user interface based on the current server status.
     *
     * @since 1.1.0
     * @param {object} importData - Parsed JSON data returned from Importer.
     */
    var displayServerStatus = function(importData) {
        var $loadingMessage = $('.loading-message'),
            $countMessages = $('.count-message'),
            $lastRunInfo = $('.js_last-run-status'),
            $finalCount = $('.js_final-count-msg'),
            $processingMsg = $('.js_processing-status'),
            $counters = $('.js_counter'),
            $cancelButton = $('.js_action-cancel'),
            $actionButton = $('.js_action'),
            $resumeButton = $('.js_action-resume'),
            $refreshIcon = $('#vsei_refresh').children('i'),
            $totalCount = $('.js_total-count'),
            $currentCount = $('.js_current-count'),
            $addedCount = $('.js_imported'),
            $deleteCount = $('.js_deleted'),
            primaryStatus = importData.status.split(':')[0],
            primaryMethod = importData.method.split('/')[0];

        // If action is being canceled, don't display the running status
        if (importData.status !== 'canceling') {
            displayFriendlyStatus(importData.status, importData.method);
        }

        // Return UI to default state
        $loadingMessage.hide();
        $lastRunInfo.hide();
        $countMessages.hide();
        $resumeButton.hide();
        $counters.text('0');
        $refreshIcon.removeClass('spin');

        // Change UI to running state
        if (primaryStatus !== 'free') {
            $finalCount.hide();
            $actionButton.prop('disabled', true);
            $cancelButton.show();
        }

        // Print data about current action
        if (primaryMethod === 'import' || primaryMethod === 'delete') {
            $totalCount.text(importData.total);
            $currentCount.text(importData.processed);
            $addedCount.text(importData.added);
            $deleteCount.text(importData.deleted);
            $countMessages.show();
        } else if (primaryMethod === 'cache') {
            $totalCount.text(importData.total);
            $currentCount.text(importData.processed);
            $processingMsg.show();
        } else {
            console.log("[VSEI Plugin] Unhandled method " + importData.method + " when displaying server status.");
        }
    };

    /**
     * Fetches total number of published events via Importer and displays results.
     */
    var fetchFinalCount = function() {
        var $totalCount = $('.js_final-count'),
            $finalCount = $('.js_final-count-msg');
        $.post(ajaxurl, { 'action': 'vsei_fetch_total_count' }, function(results) {
            $totalCount.text(results);
            $finalCount.show();
        });
    };

    /**
     * Fetches the name of the currently running action from the server.
     *
     * `fetchRunningAction` is called when resuming an action, in case multiple requests are needed to complete it.
     *  Without knowing the action's name, the client will not be able to determine subsequent steps.
     *
     *  @since 1.1.0
     */
    var fetchRunningAction = function() {
        $.post(ajaxurl, { 'action': 'vsei_fetch_running_action' }, function(action) {
            if (action) {
                importer.runningAction = action;
            }
        });
    };

    /**
     * Fetches, processes, and displays data about last Importer run.
     *
     * @param {object} importData - Parsed JSON data about the last completed process.
     */
    var displayLastRunInfo = function(importData) {
        var $lastRunMessage = $('.js_last-run-status'),
            relativeTime = moment(importData.timestamp).fromNow(),
            friendlyTimestamp = moment(importData.timestamp, 'YYYY-MM-DD HH:mm:ss')
                .format('ddd, MMM Do [at] H:mm a');

        if (Object.keys(importData).length === 0) {
            $lastRunMessage.text("No data available from last run.");
        } else {
            $lastRunMessage.text("Last run on " + friendlyTimestamp
                + " (" + relativeTime + ")"
                + " processed " + importData.processed
                + " of " + importData.total + " total listings with "
                + importData.added + " additions and "
                + importData.deleted + " deletions.");
            $lastRunMessage.show();
        }
    };

    /**
     * Displays a user-friendly description of the Importer's status & alters related UI elements.
     *
     * @param {string} status - The Importer's status.
     * @param {string} [method] - The method being run by the Importer.
     */
    var displayFriendlyStatus = function(status, method) {
        var $status = $('#vsei_importer-status'),
            $spinner = $('.status-spinner');

        if (typeof(method) === 'undefined') method = '';

        $status.removeClass();

        switch (status) {
            case 'free':
            case 'free:canceled':
                $status.text('waiting for commands');
                $status.addClass('idle');
                $spinner.hide();
                break;
            case 'error':
                $status.text('doing something unexpected');
                $status.addClass('danger');
                $spinner.hide();
                break;
            case 'canceling':
                $status.text('canceling an operation');
                $status.addClass('running');
                $spinner.show();
                break;
            case 'busy':
                $status.text('waiting for server response');
                $status.addClass('running');
                $spinner.show();
                break;
            case 'running':
                $status.addClass('running');
                $spinner.show();
                switch (method) {
                    case 'import/fetch':
                        $status.text('fetching event data');
                        break;
                    case 'import/update':
                        $status.text('importing events');
                        break;
                    case 'delete/fetch':
                        $status.text('fetching list of events to be deleted');
                        break;
                    case 'delete/prune':
                        $status.text('removing dropped events');
                        break;
                    case 'delete/purge':
                        $status.text('removing events');
                        break;
                    case 'delete/cleanup':
                        $status.text('cleaning up the database');
                        break;
                    case 'delete/meta':
                        $status.text('removing event metadata');
                        break;
                    case 'cache/delete':
                        $status.text('removing old data from cache');
                        break;
                    default:
                        console.log("[VSEI Plugin] Unhandled method in displayFriendlyStatus: " + method);
                        $status.text('doing something undefined. Please contact IT if this persists.');
                        $status.addClass('danger');
                        $spinner.hide();
                }
                break;
            default:
                console.log("[VSEI Plugin] Unhandled status in displayFriendlyStatus: " + status);
                $status.text('doing something undefined. Please contact IT if this persists.');
                $status.addClass('danger');
        }
    };

    /**
     * Displays the current fetch interval as a human-readable string as part of the import button's description.
     *
     * @param {string} lastUpdated - Timestamp from last completed run.
     */
    var updateActionTimespan = function(lastUpdated) {
        var $timespan = $('.vsei-timespan');
        if (moment().diff(lastUpdated, 'hours') > INTERVAL_HOURS) {
            $timespan.text(moment(lastUpdated).fromNow());
        } else {
            $timespan.text('the last ' + INTERVAL_HOURS + ' hours');
        }
    };

    /* ===== Operational methods ===== */

    /**
     * Public method to run the Importer client.
     *
     * `run` starts the client daemon and initializes the client class with the chunk size, which is set via
     *  JavaScript and comes from the Importer server class.
     *
     * @param chunkSize - The # of listings per page.
     */
    importer.run = function(chunkSize) {
        console.log("[VSEI Plugin] Running importer client.");
        importer.chunkSize = parseInt(chunkSize) || 100;
        setupEventHandlers();
        // Init heartbeat daemon
        importDaemon = new MiniDaemon(null, heartbeat, HEARTBEAT_DURATION);
        importDaemon.start();
        MiniDaemon.forceCall(importDaemon);
    };

    return importer;
})(jQuery);
