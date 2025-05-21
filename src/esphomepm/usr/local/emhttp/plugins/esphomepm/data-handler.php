<?php
// File: /usr/local/emhttp/plugins/esphomepm/data-handler.php

// Include common functions
require_once __DIR__ . '/include/functions.php';

// Initialize script (error logging) and load config
$config = esphomepm_init_script('data_handler', false);

// --- Core Logic Functions ---

function initialize_data_file_if_needed() {
    return esphomepm_initialize_data_file();
}


/**
 * Update power consumption data with retry mechanism and fallback strategy
 * 
 * @param int $retry_count Current retry attempt (default: 0)
 * @param string $target_date Date to process in Y-m-d format (default: current date)
 * @return bool True on success, false on failure
 */
function update_power_consumption_data($retry_count = 0, $target_date = null) {
    global $config; // use config initialized at script startup
    // If target_date is not provided, use yesterday's date (since we run at end of day)
    if ($target_date === null) {
        $target_date = date('Y-m-d'); // Today's date
    }
    
    $power_data = initialize_data_file_if_needed();
    if ($power_data === null) {
        esphomepm_log_error("Failed to load or initialize power data. Aborting update.", 'ERROR', 'data_handler');
        return false;
    }

    // This is the energy for the day that just completed (cron runs at 23:59)
    $e_completed_day = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH']);

    if ($e_completed_day === null) {
        // If this is a retry attempt, try to use the last available value or set to 0
        if ($retry_count > 0) {
            // Load existing data to check if we have a previous value for today
            $power_data = esphomepm_initialize_data_file();
            if ($power_data !== null && isset($power_data['current_month']['daily_records'][$target_date])) {
                // Use the existing value for today if available
                $e_completed_day = $power_data['current_month']['daily_records'][$target_date]['energy_kwh'];
                esphomepm_log_error("Using existing value for $target_date: $e_completed_day kWh", 'INFO', 'data_handler');
            } else {
                // No existing value, use 0 as fallback
                $e_completed_day = 0.0;
                esphomepm_log_error("Using fallback value (0) for $target_date", 'WARNING', 'data_handler');
            }
        } else if ($retry_count === 0) {
            esphomepm_log_error("Could not fetch '{$config['DAILY_ENERGY_SENSOR_PATH']}' from ESPHome device {$config['DEVICE_IP']}. Will retry once.", 'WARNING', 'data_handler');
            // First failure, sleep briefly and retry once
            sleep(5); // Wait 5 seconds before retry
            return update_power_consumption_data(1, $target_date); // Retry with incremented counter
        }
    } else {
        $e_completed_day = (float)$e_completed_day;
    }


    $c_completed_day = ($e_completed_day !== null) ? $e_completed_day * $config['COSTS_PRICE'] : null;
    
    // Use target_date for record if provided, otherwise use current date
    $date_for_record = $target_date ?? date('Y-m-d');
    
    // Extract year-month from the date for month processing
    $date_parts = explode('-', $date_for_record);
    if (count($date_parts) !== 3) {
        esphomepm_log_error("Invalid date format: $date_for_record", 'ERROR', 'data_handler');
        return false;
    }
    
    $record_year_month = $date_parts[0] . '-' . $date_parts[1]; // YYYY-MM format
    $current_processing_month_year = date('Y-m'); // Current month we're in now
    
    // --- Enhanced Month Rollover Logic ---
    // First, check if we need to handle any missed months between the last recorded month and now
    if (!empty($power_data['current_month']['month_year']) && 
        $power_data['current_month']['month_year'] != $record_year_month) {
        
        // Archive the current month data before moving to a new month
        $prev_month_year_to_archive = $power_data['current_month']['month_year'];
        
        // Ensure we have some data to archive for the previous month
        if ($power_data['current_month']['total_energy_kwh_completed_days'] > 0 || 
            $power_data['current_month']['total_cost_completed_days'] > 0 || 
            !empty($power_data['current_month']['daily_records'])) {
            
            // Validate the data before archiving
            $total_energy = (float)$power_data['current_month']['total_energy_kwh_completed_days'];
            $total_cost = (float)$power_data['current_month']['total_cost_completed_days'];
            
            // Ensure values are positive
            if ($total_energy < 0) {
                esphomepm_log_error("Negative energy value detected for $prev_month_year_to_archive: $total_energy kWh. Setting to 0.", 'WARNING', 'data_handler');
                $total_energy = 0;
            }
            
            if ($total_cost < 0) {
                esphomepm_log_error("Negative cost value detected for $prev_month_year_to_archive: $total_cost. Setting to 0.", 'WARNING', 'data_handler');
                $total_cost = 0;
            }
            
            $power_data['historical_months'][$prev_month_year_to_archive] = [
                'energy_kwh' => $total_energy,
                'cost' => $total_cost
            ];
            
            esphomepm_log_error("Archived month $prev_month_year_to_archive with $total_energy kWh and $total_cost cost", 'INFO', 'data_handler');
        }

        // Prune historical_months
        if (count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
            ksort($power_data['historical_months']); // Sort by YYYY-MM
            while (count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
                $oldest_month = array_key_first($power_data['historical_months']);
                esphomepm_log_error("Pruning oldest historical month: $oldest_month", 'INFO', 'data_handler');
                array_shift($power_data['historical_months']); // Remove the oldest
            }
        }

        // Reset current_month for the new month of the record we're processing
        $power_data['current_month']['month_year'] = $record_year_month;
        $power_data['current_month']['daily_records'] = [];
        $power_data['current_month']['total_energy_kwh_completed_days'] = 0.0;
        $power_data['current_month']['total_cost_completed_days'] = 0.0;
        
        esphomepm_log_error("Initialized new month: $record_year_month", 'INFO', 'data_handler');
    } elseif (empty($power_data['current_month']['month_year'])) {
        // Initialize if it was empty
        $power_data['current_month']['month_year'] = $record_year_month;
        esphomepm_log_error("Initialized empty month record to: $record_year_month", 'INFO', 'data_handler');
    }
    
    // If the record date is for a different month than the current system month,
    // log this as we're processing historical data
    if ($record_year_month !== $current_processing_month_year) {
        esphomepm_log_error("Processing historical data for $record_year_month (current month is $current_processing_month_year)", 'INFO', 'data_handler');
    }

    // --- Update current_month daily_records and totals (only if sensor data was successfully fetched) ---
    if ($e_completed_day !== null && $c_completed_day !== null) {
        // Validate the data before storing
        if ($e_completed_day < 0) {
            esphomepm_log_error("Negative energy value detected for $date_for_record: $e_completed_day kWh. Setting to 0.", 'WARNING', 'data_handler');
            $e_completed_day = 0;
        }
        
        if ($c_completed_day < 0) {
            esphomepm_log_error("Negative cost value detected for $date_for_record: $c_completed_day. Setting to 0.", 'WARNING', 'data_handler');
            $c_completed_day = 0;
        }
        
        $power_data['current_month']['daily_records'][$date_for_record] = [
            'energy_kwh' => $e_completed_day,
            'cost' => $c_completed_day
        ];
        
        esphomepm_log_error("Recorded data for $date_for_record: $e_completed_day kWh, $c_completed_day cost", 'INFO', 'data_handler');
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
    // Check if this is a retry run with specific parameters
    $retry = isset($_SERVER['ESPHOMEPM_RETRY']) ? (int)$_SERVER['ESPHOMEPM_RETRY'] : 0;
    $target_date = isset($_SERVER['ESPHOMEPM_TARGET_DATE']) ? $_SERVER['ESPHOMEPM_TARGET_DATE'] : date('Y-m-d');
    
    // Validate if this is running at the expected time (skip validation for explicit retries)
    if ($retry === 0) {
        $is_valid_time = esphomepm_validate_cron_time();
        
        // If running at 23:58 and it's the first attempt, schedule a retry at 23:59 if needed
        $current_hour = (int)date('H');
        $current_minute = (int)date('i');
        
        if ($current_hour === 23 && $current_minute === 58) {
            // Set up for potential retry at 23:59
            register_shutdown_function(function() use ($target_date) {
                // Only schedule retry if the current run fails
                if (error_get_last() !== null) {
                    esphomepm_log_error("Scheduling retry at 23:59", 'INFO', 'cron_job');
                    // Use at command to schedule a run in 1 minute
                    $cmd = "echo 'ESPHOMEPM_RETRY=1 ESPHOMEPM_TARGET_DATE=$target_date php /usr/local/emhttp/plugins/esphomepm/data-handler.php' | at -M now + 1 minute 2>/dev/null";
                    shell_exec($cmd);
                }
            });
        }
    }
    
    // This allows the script to be run directly via cron or command line
    $result = esphomepm_update_data_file($retry, $target_date);
    
    // Get the data for logging
    $power_data = esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
    
    if ($power_data !== null) {
        // Get yesterday's data if available
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if (isset($power_data['current_month']['daily_records'][$yesterday])) {
            $log_data['yesterday_energy'] = round($power_data['current_month']['daily_records'][$yesterday]['energy_kwh'], 3);
            $log_data['yesterday_cost'] = round($power_data['current_month']['daily_records'][$yesterday]['cost'], 2);
        }
        
        // Current month and total data
        $log_data['month_energy'] = round($power_data['current_month']['total_energy_kwh_completed_days'], 3);
        $log_data['month_cost'] = round($power_data['current_month']['total_cost_completed_days'], 2);
        $log_data['total_energy'] = round($power_data['overall_totals']['total_energy_kwh_all_time'], 3);
        $log_data['total_cost'] = round($power_data['overall_totals']['total_cost_all_time'], 2);
        $log_data['cost_unit'] = $config['COSTS_UNIT'];
        
        // Add retry information to log
        $log_data['retry_count'] = $retry;
        $log_data['target_date'] = $target_date;
        
        // Log the cron execution details
        esphomepm_log_cron_execution($log_data);
    }
    
    if ($result) {
        esphomepm_log_error("data-handler.php executed successfully" . ($retry > 0 ? " (retry #$retry)" : ""), 'INFO', 'cron_job');
        exit(0); // Success exit code
    } else {
        esphomepm_log_error("data-handler.php execution failed" . ($retry > 0 ? " (retry #$retry)" : ""), 'ERROR', 'cron_job');
        exit(1); // Error exit code
    }
}
?>
