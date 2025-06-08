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
    
    $power_data = esphomepm_initialize_data_file();
    if ($power_data === null) {
        esphomepm_log_error("Failed to load or initialize power data. Aborting update.", 'ERROR', 'data_processing');
        return false;
    }

    // --- Start of Self-Healing and Month Rollover Logic ---
    $actual_current_month_year = date('Y-m');

    // First, purge any daily records that do not belong to the actual current month.
    if (isset($power_data['current_month']['daily_records']) && is_array($power_data['current_month']['daily_records'])) {
        $new_daily_records = [];
        foreach ($power_data['current_month']['daily_records'] as $date => $record) {
            if (substr($date, 0, 7) === $actual_current_month_year) {
                $new_daily_records[$date] = $record;
            } else {
                esphomepm_log_error("Discarding stale daily record from a past month: $date", 'INFO', 'data_processing');
            }
        }
        // If the number of records changed, we have modified the data.
        if (count($new_daily_records) !== count($power_data['current_month']['daily_records'])) {
             $power_data['current_month']['daily_records'] = $new_daily_records;
        }
    }
    
    // Now, handle the main month rollover if the file is still pointing to a previous month.
    $file_month_year = $power_data['current_month']['month_year'] ?? date('Y-m');
    if ($file_month_year < $actual_current_month_year) {
        esphomepm_log_error("Month rollover detected. Archiving month: $file_month_year", 'INFO', 'data_processing');
        
        $final_month_total_energy = (float)($power_data['current_month']['total_energy_kwh_completed_days'] ?? 0.0);
        $final_month_total_cost = (float)($power_data['current_month']['total_cost_completed_days'] ?? 0.0);

        if ($final_month_total_energy > 0) {
            $power_data['historical_months'][$file_month_year] = [
                'energy_kwh' => $final_month_total_energy,
                'cost' => $final_month_total_cost
            ];
        }

        $power_data['current_month'] = [
            'month_year' => $actual_current_month_year,
            'daily_records' => $power_data['current_month']['daily_records'] ?? [],
            'total_energy_kwh_completed_days' => 0.0,
            'total_cost_completed_days' => 0.0
        ];
    }
    // --- End of Self-Healing and Month Rollover Logic ---


    $daily_energy_sensor_result = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH']);
    $e_completed_day_value = null;

    if ($daily_energy_sensor_result === null || !empty($daily_energy_sensor_result['error'])) {
        if (!empty($daily_energy_sensor_result['error'])) {
            esphomepm_log_error("Sensor '{$config['DAILY_ENERGY_SENSOR_PATH']}' reported error: " . $daily_energy_sensor_result['error'], 'WARNING', 'data_processing');
        } else {
            esphomepm_log_error("Failed to fetch '{$config['DAILY_ENERGY_SENSOR_PATH']}'. Retrying.", 'WARNING', 'data_processing');
        }

        if ($retry_count === 0) {
            sleep(5); 
            return update_power_consumption_data(1, $target_date);
        } else {
            esphomepm_log_error("Using fallback value (0.0 kWh) for $target_date after retry.", 'WARNING', 'data_processing');
            $e_completed_day_value = 0.0;
        }
    } else {
        $e_completed_day_value = (float)$daily_energy_sensor_result['value'];
    }

    if ($e_completed_day_value === null) {
        esphomepm_log_error("Logic error: e_completed_day_value is null. Defaulting to 0.0.", 'ERROR', 'data_processing');
        $e_completed_day_value = 0.0;
    }

    $c_completed_day = $e_completed_day_value * (float)$config['COSTS_PRICE'];
    
    $date_for_record = $target_date;
    
    if ($e_completed_day_value >= 0 && $c_completed_day >= 0) {
        $power_data['current_month']['daily_records'][$date_for_record] = [
            'energy_kwh' => $e_completed_day_value,
            'cost' => $c_completed_day
        ];
        esphomepm_log_error("Recorded data for $date_for_record: $e_completed_day_value kWh, $c_completed_day cost", 'INFO', 'data_processing');
    }
    
    // --- Full Recalculation for Data Integrity ---
    $current_month_total_energy_calc = 0.0;
    $current_month_total_cost_calc = 0.0;
    if (is_array($power_data['current_month']['daily_records'])) {
        foreach ($power_data['current_month']['daily_records'] as $record) {
            $current_month_total_energy_calc += (float)($record['energy_kwh'] ?? 0.0);
            $current_month_total_cost_calc += (float)($record['cost'] ?? 0.0);
        }
    }
    $power_data['current_month']['total_energy_kwh_completed_days'] = $current_month_total_energy_calc;
    $power_data['current_month']['total_cost_completed_days'] = $current_month_total_cost_calc;

    $overall_total_energy_calc = $current_month_total_energy_calc;
    $overall_total_cost_calc = $current_month_total_cost_calc;
    if (is_array($power_data['historical_months'])) {
        ksort($power_data['historical_months']);
        foreach ($power_data['historical_months'] as $month_data) {
            $overall_total_energy_calc += (float)($month_data['energy_kwh'] ?? 0.0);
            $overall_total_cost_calc += (float)($month_data['cost'] ?? 0.0);
        }
    }
    $power_data['overall_totals']['total_energy_kwh_all_time'] = $overall_total_energy_calc;
    $power_data['overall_totals']['total_cost_all_time'] = $overall_total_cost_calc;

    if (defined('ESPHOMPM_MAX_HISTORICAL_MONTHS') && count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
        array_shift($power_data['historical_months']);
    }

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
