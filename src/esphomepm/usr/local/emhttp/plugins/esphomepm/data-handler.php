<?php
// File: /usr/local/emhttp/plugins/esphomepm/data-handler.php

// Include common functions
require_once __DIR__ . '/include/functions.php';

// Set up error logging for this component
esphomepm_setup_error_logging('data_handler');

// --- Core Logic Functions ---

function initialize_data_file_if_needed() {
    return esphomepm_initialize_data_file();
}


function update_power_consumption_data() {
    $config = esphomepm_load_config();
    if (empty($config['DEVICE_IP']) || !isset($config['COSTS_PRICE'])) {
        error_log("ESPHomePM: update_power_consumption_data - Missing DEVICE_IP or COSTS_PRICE in configuration.");
        return false;
    }

    $power_data = initialize_data_file_if_needed();
    if ($power_data === null) {
        error_log("ESPHomePM: Failed to load or initialize power data. Aborting update.");
        return false;
    }

    // This is the energy for the day that just completed (cron runs at 23:59)
    $e_completed_day = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH']);

    if ($e_completed_day === null) {
        error_log("ESPHomePM: Could not fetch '{$config['DAILY_ENERGY_SENSOR_PATH']}' from ESPHome device {$config['DEVICE_IP']}. Daily update skipped.");
        // Decide if we should still save (to ensure month rollover happens if date changed) or just exit.
        // For now, let's try to process month rollover if applicable, even if sensor read fails.
    } else {
        $e_completed_day = (float)$e_completed_day;
    }


    $c_completed_day = ($e_completed_day !== null) ? $e_completed_day * $config['COSTS_PRICE'] : null;
    
    $date_for_record = date('Y-m-d'); // The date for which this data is recorded (day that just ended)
    $current_processing_month_year = date('Y-m'); // The month we are currently in / processing for

    // --- Month Rollover Logic: Check if the month in data file is different from current processing month ---
    if ($power_data['current_month']['month_year'] != $current_processing_month_year && !empty($power_data['current_month']['month_year'])) {
        // Previous month has ended, archive it
        $prev_month_year_to_archive = $power_data['current_month']['month_year'];
        
        // Ensure we have some data to archive for the previous month
        if ($power_data['current_month']['total_energy_kwh_completed_days'] > 0 || $power_data['current_month']['total_cost_completed_days'] > 0 || !empty($power_data['current_month']['daily_records'])) {
            $power_data['historical_months'][$prev_month_year_to_archive] = [
                'energy_kwh' => (float)$power_data['current_month']['total_energy_kwh_completed_days'],
                'cost' => (float)$power_data['current_month']['total_cost_completed_days']
            ];
        }

        // Prune historical_months
        if (count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
            ksort($power_data['historical_months']); // Sort by YYYY-MM
            while (count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
                array_shift($power_data['historical_months']); // Remove the oldest
            }
        }

        // Reset current_month for the new processing month
        $power_data['current_month']['month_year'] = $current_processing_month_year;
        $power_data['current_month']['daily_records'] = [];
        $power_data['current_month']['total_energy_kwh_completed_days'] = 0.0;
        $power_data['current_month']['total_cost_completed_days'] = 0.0;
    } elseif (empty($power_data['current_month']['month_year'])) {
        // Initialize if it was empty
        $power_data['current_month']['month_year'] = $current_processing_month_year;
    }

    // --- Update current_month daily_records and totals (only if sensor data was successfully fetched) ---
    if ($e_completed_day !== null && $c_completed_day !== null) {
        $power_data['current_month']['daily_records'][$date_for_record] = [
            'energy_kwh' => $e_completed_day,
            'cost' => $c_completed_day
        ];
    }
    
    // Recalculate current month totals from its daily_records (always, even if today's sensor read failed, to ensure consistency if records were manually changed or to reset if it's a new month)
    $current_month_total_energy_calc = 0.0;
    $current_month_total_cost_calc = 0.0;
    if (is_array($power_data['current_month']['daily_records'])) {
        foreach ($power_data['current_month']['daily_records'] as $record) {
            if (isset($record['energy_kwh'])) $current_month_total_energy_calc += (float)$record['energy_kwh'];
            if (isset($record['cost'])) $current_month_total_cost_calc += (float)$record['cost'];
        }
    }
    $power_data['current_month']['total_energy_kwh_completed_days'] = $current_month_total_energy_calc;
    $power_data['current_month']['total_cost_completed_days'] = $current_month_total_cost_calc;

    // --- Update overall_totals ---
    $overall_total_energy_calc = $power_data['current_month']['total_energy_kwh_completed_days'];
    $overall_total_cost_calc = $power_data['current_month']['total_cost_completed_days'];
    if (is_array($power_data['historical_months'])) {
        foreach ($power_data['historical_months'] as $month_data) {
            if (isset($month_data['energy_kwh'])) $overall_total_energy_calc += (float)$month_data['energy_kwh'];
            if (isset($month_data['cost'])) $overall_total_cost_calc += (float)$month_data['cost'];
        }
    }
    $power_data['overall_totals']['total_energy_kwh_all_time'] = $overall_total_energy_calc;
    $power_data['overall_totals']['total_cost_all_time'] = $overall_total_cost_calc;

    // Ensure monitoring_start_date is set if it was missing
    if (empty($power_data['overall_totals']['monitoring_start_date'])) {
        $power_data['overall_totals']['monitoring_start_date'] = $date_for_record;
    }

    // --- Save data ---
    return esphomepm_save_json_data(ESPHOMPM_DATA_FILE, $power_data);
}

// --- Main execution block (if script is called directly) ---
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    // This allows the script to be run directly via cron or command line
    $result = update_power_consumption_data();
    if ($result) {
        error_log("ESPHomePM: data-handler.php executed successfully at " . date('Y-m-d H:i:s'));
        exit(0); // Success exit code
    } else {
        error_log("ESPHomePM: data-handler.php execution failed at " . date('Y-m-d H:i:s'));
        exit(1); // Error exit code
    }
}
?>
