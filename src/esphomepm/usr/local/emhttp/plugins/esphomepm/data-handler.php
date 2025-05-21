<?php
require_once __DIR__ . '/include/functions.php';
$config = esphomepm_init_script('data_handler', false);

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
    global $config; 
    if ($target_date === null) {
        $target_date = date('Y-m-d');
    }
    
    $power_data = initialize_data_file_if_needed();
    if ($power_data === null) {
        esphomepm_log_error("Failed to load or initialize power data. Aborting update.", 'ERROR', 'data_handler');
        return false;
    }

    $e_completed_day = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH']);

    if ($e_completed_day === null) {
        if ($retry_count > 0) {
            $power_data = esphomepm_initialize_data_file();
            if ($power_data !== null && isset($power_data['current_month']['daily_records'][$target_date])) {
                $e_completed_day = $power_data['current_month']['daily_records'][$target_date]['energy_kwh'];
                esphomepm_log_error("Using existing value for $target_date: $e_completed_day kWh", 'INFO', 'data_handler');
            } else {
                $e_completed_day = 0.0;
                esphomepm_log_error("Using fallback value (0) for $target_date", 'WARNING', 'data_handler');
            }
        } else if ($retry_count === 0) {
            esphomepm_log_error("Could not fetch '{$config['DAILY_ENERGY_SENSOR_PATH']}' from ESPHome device {$config['DEVICE_IP']}. Will retry once.", 'WARNING', 'data_handler');
            sleep(5); 
            return update_power_consumption_data(1, $target_date); 
        }
    } else {
        $e_completed_day = (float)$e_completed_day;
    }

    $c_completed_day = ($e_completed_day !== null) ? $e_completed_day * $config['COSTS_PRICE'] : null;
    
    $date_for_record = $target_date ?? date('Y-m-d');
    
    $date_parts = explode('-', $date_for_record);
    if (count($date_parts) !== 3) {
        esphomepm_log_error("Invalid date format: $date_for_record", 'ERROR', 'data_handler');
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

        if (count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
            ksort($power_data['historical_months']); 
            while (count($power_data['historical_months']) > ESPHOMPM_MAX_HISTORICAL_MONTHS) {
                $oldest_month = array_key_first($power_data['historical_months']); 
                esphomepm_log_error("Pruning oldest historical month: $oldest_month", 'INFO', 'data_handler');
                array_shift($power_data['historical_months']); 
            }
        }

        $power_data['current_month']['month_year'] = $record_year_month;
        $power_data['current_month']['daily_records'] = [];
        $power_data['current_month']['total_energy_kwh_completed_days'] = 0.0;
        $power_data['current_month']['total_cost_completed_days'] = 0.0;
        
        esphomepm_log_error("Initialized new month: $record_year_month", 'INFO', 'data_handler');
    } elseif (empty($power_data['current_month']['month_year'])) {
        $power_data['current_month']['month_year'] = $record_year_month;
        esphomepm_log_error("Initialized empty month record to: $record_year_month", 'INFO', 'data_handler');
    }
    
    if ($record_year_month !== $current_processing_month_year) {
        esphomepm_log_error("Processing historical data for $record_year_month (current month is $current_processing_month_year)", 'INFO', 'data_handler');
    }

    if ($e_completed_day !== null && $c_completed_day !== null) {
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

if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    $retry = isset($_SERVER['ESPHOMEPM_RETRY']) ? (int)$_SERVER['ESPHOMEPM_RETRY'] : 0;
    $target_date = isset($_SERVER['ESPHOMEPM_TARGET_DATE']) ? $_SERVER['ESPHOMEPM_TARGET_DATE'] : date('Y-m-d');
    
    if ($retry === 0) {
        $is_valid_time = esphomepm_validate_cron_time();
        
        $current_hour = (int)date('H');
        $current_minute = (int)date('i');
        
        if ($current_hour === 23 && $current_minute === 58) {
            register_shutdown_function(function() use ($target_date) {
                if (error_get_last() !== null) {
                    esphomepm_log_error("Scheduling retry at 23:59", 'INFO', 'cron_job');
                    $cmd = "echo 'ESPHOMEPM_RETRY=1 ESPHOMEPM_TARGET_DATE=$target_date php /usr/local/emhttp/plugins/esphomepm/data-handler.php' | at -M now + 1 minute 2>/dev/null";
                    shell_exec($cmd);
                }
            });
        }
    }
    
    $result = esphomepm_update_data_file($retry, $target_date);
    
    $power_data = esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
    
    if ($power_data !== null) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if (isset($power_data['current_month']['daily_records'][$yesterday])) {
            $log_data['yesterday_energy'] = round($power_data['current_month']['daily_records'][$yesterday]['energy_kwh'], 3);
            $log_data['yesterday_cost'] = round($power_data['current_month']['daily_records'][$yesterday]['cost'], 2);
        }
        
        $log_data['month_energy'] = round($power_data['current_month']['total_energy_kwh_completed_days'], 3);
        $log_data['month_cost'] = round($power_data['current_month']['total_cost_completed_days'], 2);
        $log_data['total_energy'] = round($power_data['overall_totals']['total_energy_kwh_all_time'], 3);
        $log_data['total_cost'] = round($power_data['overall_totals']['total_cost_all_time'], 2);
        $log_data['cost_unit'] = $config['COSTS_UNIT'];
        
        $log_data['retry_count'] = $retry;
        $log_data['target_date'] = $target_date;
        
        esphomepm_log_cron_execution($log_data);
    }
    
    if ($result) {
        esphomepm_log_error("data-handler.php executed successfully" . ($retry > 0 ? " (retry #$retry)" : ""), 'INFO', 'cron_job');
        exit(0); 
    } else {
        esphomepm_log_error("data-handler.php execution failed" . ($retry > 0 ? " (retry #$retry)" : ""), 'ERROR', 'cron_job');
        exit(1); 
    }
}
?>
