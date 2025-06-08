<?php
/**
 * ESPHomePM Plugin - Data Processing Utilities
 *
 * This file contains functions related to the daily data handling, cron job operations,
 * and data file integrity.
 */

/**
 * Update power consumption data with retry mechanism and fallback strategy
 * 
 * @param int $retry_count Current retry attempt (default: 0)
 * @param string $target_date Date to process in Y-m-d format (default: current date)
 * @return bool True on success, false on failure
 */
function update_power_consumption_data($retry_count = 0, $target_date = null) {
    global $config; 
    if ($target_date === null) {
        $target_date = date('Y-m-d');
    }
    
    $power_data = esphomepm_initialize_data_file(); // MODIFIED from initialize_data_file_if_needed()
    if ($power_data === null) {
        esphomepm_log_error("Failed to load or initialize power data. Aborting update.", 'ERROR', 'data_processing'); // MODIFIED context
        return false;
    }

    $daily_energy_sensor_result = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH']);
    $e_completed_day_value = null; // Will hold the final float value

    // Check if fetch failed OR if sensor reported an error in the returned data
    if ($daily_energy_sensor_result === null || !empty($daily_energy_sensor_result['error'])) {
        
        if (!empty($daily_energy_sensor_result['error'])) {
            // Log the specific error reported by the sensor
            esphomepm_log_error("Sensor '{$config['DAILY_ENERGY_SENSOR_PATH']}' reported error: " . $daily_energy_sensor_result['error'] . ". Device: {$config['DEVICE_IP']}. Will attempt retry/fallback.", 'WARNING', 'data_processing');
        } else {
            // esphomepm_fetch_sensor_data itself returned null (e.g., cURL issue)
            esphomepm_log_error("Failed to fetch '{$config['DAILY_ENERGY_SENSOR_PATH']}' (call to esphomepm_fetch_sensor_data returned null). Device: {$config['DEVICE_IP']}. Will attempt retry/fallback.", 'WARNING', 'data_processing');
        }

        // Retry/Fallback Logic
        if ($retry_count > 0) { // This is the second attempt (after a recursive call)
            esphomepm_log_error("Processing retry attempt for '{$config['DAILY_ENERGY_SENSOR_PATH']}'.", 'INFO', 'data_processing');
            $current_data_state = esphomepm_initialize_data_file(); 
            if ($current_data_state !== null && isset($current_data_state['current_month']['daily_records'][$target_date]['energy_kwh'])) {
                $e_completed_day_value = (float)$current_data_state['current_month']['daily_records'][$target_date]['energy_kwh'];
                esphomepm_log_error("Using existing value for $target_date after retry: $e_completed_day_value kWh", 'INFO', 'data_processing');
            } else {
                $e_completed_day_value = 0.0; // Fallback value
                esphomepm_log_error("Using fallback value (0.0 kWh) for $target_date after retry.", 'WARNING', 'data_processing');
            }
        } else { // $retry_count === 0, this is the first attempt that failed
            esphomepm_log_error("First attempt to fetch '{$config['DAILY_ENERGY_SENSOR_PATH']}' failed or sensor reported error. Retrying once after 5s.", 'WARNING', 'data_processing');
            sleep(5); 
            return update_power_consumption_data(1, $target_date); // Recursive call for retry
        }
    } else {
        // Success: $daily_energy_sensor_result is an array with a 'value' and no 'error'
        $e_completed_day_value = (float)$daily_energy_sensor_result['value'];
    }

    // Ensure $e_completed_day_value is a float, even if all paths above didn't explicitly set it (should not happen with current logic)
    if ($e_completed_day_value === null) {
        esphomepm_log_error("Logic error: e_completed_day_value is null after sensor fetch/retry. Defaulting to 0.0.", 'ERROR', 'data_processing');
        $e_completed_day_value = 0.0;
    }

    // Cost calculation using the definite float value
    $c_completed_day = $e_completed_day_value * (float)$config['COSTS_PRICE'];
    
    $date_for_record = $target_date ?? date('Y-m-d');
    
    $date_parts = explode('-', $date_for_record);
    if (count($date_parts) !== 3) {
        esphomepm_log_error("Invalid date format: $date_for_record", 'ERROR', 'data_processing'); // MODIFIED context
        return false;
    }
    
    $record_year_month = $date_parts[0] . '-' . $date_parts[1]; 
    $current_processing_month_year = date('Y-m'); 
    
    if (!empty($power_data['current_month']['month_year']) && 
        $power_data['current_month']['month_year'] != $record_year_month) {
        
        $prev_month_year_to_archive = $power_data['current_month']['month_year'];
        
        if ($power_data['current_month']['total_energy_kwh_completed_days'] > 0 || 
            $power_data['current_month']['total_cost_completed_days'] > 0 || 
            !empty($power_data['current_month']['daily_records'])) {
            
            $total_energy = (float)$power_data['current_month']['total_energy_kwh_completed_days'];
            $total_cost = (float)$power_data['current_month']['total_cost_completed_days'];
            
            if ($total_energy < 0) {
                esphomepm_log_error("Negative energy value detected for $prev_month_year_to_archive: $total_energy kWh. Setting to 0.", 'WARNING', 'data_processing'); // MODIFIED context
                $total_energy = 0;
            }
            
            if ($total_cost < 0) {
                esphomepm_log_error("Negative cost value detected for $prev_month_year_to_archive: $total_cost. Setting to 0.", 'WARNING', 'data_processing'); // MODIFIED context
                $total_cost = 0;
            }
            
            $power_data['historical_months'][$prev_month_year_to_archive] = [
                'energy_kwh' => $total_energy,
                'cost' => $total_cost
            ];
            
            esphomepm_log_error("Archived month $prev_month_year_to_archive with $total_energy kWh and $total_cost cost", 'INFO', 'data_processing'); // MODIFIED context
        }

        // Ensure ESPHOMPM_MAX_HISTORICAL_MONTHS is defined (should be in core_utils.php)
        if (defined('ESPHOMPM_MAX_HISTORICAL_MONTHS') && count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
            ksort($power_data['historical_months']); 
            while (count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
                $oldest_month = array_key_first($power_data['historical_months']); 
                esphomepm_log_error("Pruning oldest historical month: $oldest_month", 'INFO', 'data_processing'); // MODIFIED context
                array_shift($power_data['historical_months']); 
            }
        }

        $power_data['current_month']['month_year'] = $record_year_month;
        $power_data['current_month']['daily_records'] = [];
        $power_data['current_month']['total_energy_kwh_completed_days'] = 0.0;
        $power_data['current_month']['total_cost_completed_days'] = 0.0;
        
        esphomepm_log_error("Initialized new month: $record_year_month", 'INFO', 'data_processing'); // MODIFIED context
    } elseif (empty($power_data['current_month']['month_year'])) {
        $power_data['current_month']['month_year'] = $record_year_month;
        esphomepm_log_error("Initialized empty month record to: $record_year_month", 'INFO', 'data_processing'); // MODIFIED context
    }
    
    if ($record_year_month !== $current_processing_month_year) {
        esphomepm_log_error("Processing historical data for $record_year_month (current month is $current_processing_month_year)", 'INFO', 'data_processing'); // MODIFIED context
    }

    if ($e_completed_day_value !== null && $c_completed_day !== null) {
        if ($e_completed_day_value < 0) {
            esphomepm_log_error("Negative energy value detected for $date_for_record: $e_completed_day_value kWh. Setting to 0.", 'WARNING', 'data_processing'); // MODIFIED context
            $e_completed_day_value = 0;
        }
        
        if ($c_completed_day < 0) {
            esphomepm_log_error("Negative cost value detected for $date_for_record: $c_completed_day. Setting to 0.", 'WARNING', 'data_processing'); // MODIFIED context
            $c_completed_day = 0;
        }
        
        $power_data['current_month']['daily_records'][$date_for_record] = [
            'energy_kwh' => $e_completed_day_value,
            'cost' => $c_completed_day
        ];
        
        esphomepm_log_error("Recorded data for $date_for_record: $e_completed_day_value kWh, $c_completed_day cost", 'INFO', 'data_processing'); // MODIFIED context
    }
    
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

    if (empty($power_data['overall_totals']['monitoring_start_date'])) {
        $power_data['overall_totals']['monitoring_start_date'] = $date_for_record;
    }

    return esphomepm_save_json_data(ESPHOMPM_DATA_FILE, $power_data);
}

/**
 * Wrapper to update data file (cron or manual)
 * This function now directly calls the main data update logic.
 *
 * @param int $retry_count
 * @param string|null $target_date
 * @return bool
 */
function esphomepm_update_data_file(int $retry_count = 0, string $target_date = null): bool {
    return update_power_consumption_data($retry_count, $target_date);
}

?>
