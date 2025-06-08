<?php
// Use bootstrap.php to load all necessary utility functions.
// (ui_utils.php, core_utils.php, data_processing_utils.php)
require_once __DIR__ . '/include/bootstrap.php';

$config = esphomepm_init_script('data_handler', false); // From core_utils.php via bootstrap

if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    $retry = isset($_SERVER['ESPHOMEPM_RETRY']) ? (int)$_SERVER['ESPHOMEPM_RETRY'] : 0;
    $target_date = isset($_SERVER['ESPHOMEPM_TARGET_DATE']) ? $_SERVER['ESPHOMEPM_TARGET_DATE'] : date('Y-m-d');
    
    $current_hour = (int)date('H');
    $current_minute = (int)date('i');

    if ($retry === 0) {
        // esphomepm_validate_cron_time() is from core_utils.php via bootstrap
        if (!esphomepm_validate_cron_time()) {
            exit(0); 
        }
    }
    
    // Call the actual data processing function from data_processing_utils.php (via bootstrap)
    $result = update_power_consumption_data($retry, $target_date);

    // Schedule a single retry via 'at' if the first attempt fails and it's 23:58
    if (!$result && $retry === 0 && $current_hour === 23 && $current_minute === 58) {
        esphomepm_log_error("data-handler.php first attempt failed. Scheduling retry at 23:59 for target date $target_date.", 'WARNING', 'cron_job'); // From core_utils.php
        $cmd = "ESPHOMEPM_RETRY=1 ESPHOMEPM_TARGET_DATE=$target_date php /usr/local/emhttp/plugins/esphomepm/data-handler.php";
        $at_cmd = "echo '$cmd' | at -M now + 1 minute 2>/dev/null";
        shell_exec($at_cmd);
    }
    
    // esphomepm_load_json_data is from core_utils.php via bootstrap
    $power_data = esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
    
    // Prepare data for esphomepm_log_cron_execution (from core_utils.php)
    $log_data = [
        'target_date' => $target_date,
        'retry_count' => $retry,
        'message' => $result ? 'Data update successful.' : 'Data update FAILED.'
    ];

    if ($power_data !== null) {
        $processed_date_for_log = ($target_date === date('Y-m-d')) ? date('Y-m-d', strtotime('-1 day')) : $target_date;
        
        if (isset($power_data['current_month']['daily_records'][$processed_date_for_log])) {
            $log_data['yesterday_energy'] = round($power_data['current_month']['daily_records'][$processed_date_for_log]['energy_kwh'], 3);
            $log_data['yesterday_cost'] = round($power_data['current_month']['daily_records'][$processed_date_for_log]['cost'], 2);
        }
        $log_data['current_month_total_energy'] = round($power_data['current_month']['total_energy_kwh_completed_days'] ?? 0.0, 3);
        $log_data['current_month_total_cost'] = round($power_data['current_month']['total_cost_completed_days'] ?? 0.0, 2);
        $log_data['overall_total_energy'] = round($power_data['overall_totals']['total_energy_kwh_all_time'] ?? 0.0, 3);
        $log_data['overall_total_cost'] = round($power_data['overall_totals']['total_cost_all_time'] ?? 0.0, 2);
    }

    esphomepm_log_cron_execution($log_data); // From core_utils.php

    // Exit with 0 if successful, 1 if failed (for cron job status)
    exit($result ? 0 : 1);
}
?>
