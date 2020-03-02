<?php
// Check for Importer
if (!class_exists('VSEI_Importer')) {
    error_log('[VSEI Plugin] Error: Importer is not defined');
    echo "<h2>Error: Importer not present. Please reinstall plugin.</h2>";
    exit();
}
?>

<div class="vsei-admin-wrapper">
    <h1>Events Importer</h1>
    <p>Fetches events from Visit Seattle's Events console over the past 48 hours or since the last import, whichever is longer.</p>

    <div class="panel loading-message"><span><i class="fa fa-spinner spin"></i> Running backend processes...</span></div>
    <div class="panel error-message"><span>Error: Another operation already in progress. Press 'cancel' to stop this operation.</span></div>

    <div class="panel action-panel">
        <h2>
            Import Status
            <button type="button" id="vsei_refresh" title="Refresh status"><i class="fa fa-refresh"></i></button>
        </h2>
        <ul class="messageWrapper">
            <li><i class="fa fa-spinner spin status-spinner"></i> Importer is <span id="vsei_importer-status">...</span>.</li>
            <li class="js_processing-status count-message">
                <div class="inner-message">Processing <span class="js_current-count js_counter">?</span></div>
                <div class="inner-message">of<span class="js_total-count js_counter">?</span>.</div>
            </li>
            <li class="count-message">
                <div class="inner-message">Imported: <span class="js_imported js_counter add">?</span></div>
                <div class="inner-message">Deleted: <span class="js_deleted js_counter remove">?</span></div>
            </li>
            <li class="js_last-run-status"></li>
            <li class="js_final-count-msg">Currently <span class="js_final-count">?</span> events are live.</li>
        </ul>
    </div>
    <div class="panel action-panel">
        <h2>Importer Actions</h2>
        <ul>
            <li class="js_action-resume"><button id="vsei_resume" class="js_action">Resume</button> the canceled process.</li>
            <li><button id="vsei_update" class="js_action">Import</button> events from <span class="vsei-timespan">the last 48 hours</span>.</li>
            <li class="js_action-cancel"><button id="vsei_cancel">Cancel</button> current operation.</li>
        </ul>
    </div>
    <?php if (array_key_exists('advanced', $_GET)) { ?>
    <div class="panel action-panel">
        <h2>Advanced Actions</h2>
        <ul>
            <li>
                <button id="vsei_update-single" class="js_action">Import Single</button> event with COE ID #
                <input id="vsei_event-id" title="Enter COE ID number" type="text" />
            </li>
            <li><button id="vsei_update-all" class="js_action">Import All</button> <span>events in the console.</span></li>
        </ul>
        <ul>
            <li><button id="vsei_prune" class="js_action">Prune</button> <span>dropped events from the database..</span></li>
            <li><button id="vsei_purge" class="js_action">Purge All</button> <span>events without re-importing.</span></li>
        </ul>
        <ul>
            <li><button id="vsei_reset" class="js_action">Reset All</button> <span>events by deleting, then re-importing everything.</span></li>
        </ul>
    </div>
    <div class="panel action-panel">
        <h2>Cache Actions</h2>
        <ul>
            <li><button id="vsei_clear-cache" class="js_action">Clear Cache</button> <span>of old data, so new data can be fetched.</span></li>
        </ul>
    </div>
    <?php } ?>
    <div class="panel action-panel <?php if (!array_key_exists('advanced', $_GET)) echo "hidden"; ?>">
        <h2>AJAX Parameters</h2>
        <ul>
            <li><label for="vsei_import-start-date">Start date: </label><input type="text" id="vsei_import-start-date" /></li>
        </ul>
    </div>
</div>
<script type="text/javascript">
    VSEI_ImporterClient.run(<?= VSEI_Importer::get_chunk_size(); ?>);
</script>
