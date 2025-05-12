<?php
// File: /usr/local/emhttp/plugins/esphomepm/data-handler.php

// Enable error reporting for debugging (send to log file)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_data_handler_error.log');

// Configuration and constants
define('ESPHOMPM_PLUGIN_NAME', 'esphomepm');
define('ESPHOMPM_CONFIG_FILE', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . '/' . ESPHOMPM_PLUGIN_NAME . '.cfg');
define('ESPHOMPM_DATA_FILE', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . '/esphomepm_data.json');
define('ESPHOMPM_DATA_FORMAT_VERSION', '1.0');
define('ESPHOMPM_MAX_HISTORICAL_MONTHS', 13); // Keep current year + last year approx

// --- Helper Functions ---

function load_plugin_config() {
    $config = [];
    if (file_exists(ESPHOMPM_CONFIG_FILE)) {
        $raw_cfg = parse_ini_file(ESPHOMPM_CONFIG_FILE);
        if ($raw_cfg !== false) {
            $config['DEVICE_IP'] = isset($raw_cfg['DEVICE_IP']) ? $raw_cfg['DEVICE_IP'] : "";
            $config['COSTS_PRICE'] = isset($raw_cfg['COSTS_PRICE']) ? (float)$raw_cfg['COSTS_PRICE'] : 0.0;
            // Optional: allow user to specify sensor paths, default to 'power' and 'daily_energy'
            $config['POWER_SENSOR_PATH'] = !empty($raw_cfg['POWER_SENSOR_PATH']) ? $raw_cfg['POWER_SENSOR_PATH'] : 'power';
            $config['DAILY_ENERGY_SENSOR_PATH'] = !empty($raw_cfg['DAILY_ENERGY_SENSOR_PATH']) ? $raw_cfg['DAILY_ENERGY_SENSOR_PATH'] : 'daily_energy';
        }
    }
    return $config;
}

function load_json_data($file_path) {
    if (!file_exists($file_path)) {
        return null;
    }
    $json_content = file_get_contents($file_path);
    if ($json_content === false) {
        error_log("ESPHomePM: Failed to read data file: $file_path");
        return null;
    }
    $data = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ESPHomePM: JSON decode error for $file_path: " . json_last_error_msg());
        return null; // Or handle by attempting to fix/backup/reinitialize
    }
    return $data;
}

function save_json_data($file_path, $data) {
    $json_output = json_encode($data, JSON_PRETTY_PRINT);
    if ($json_output === false) {
        error_log("ESPHomePM: JSON encode error: " . json_last_error_msg());
        return false;
    }
    if (file_put_contents($file_path, $json_output) === false) {
        error_log("ESPHomePM: Failed to write data file: $file_path");
        return false;
    }
    return true;
}

function fetch_esphome_sensor_data($device_ip, $sensor_path, $timeout = 5) {
    if (empty($device_ip) || empty($sensor_path)) {
        error_log("ESPHomePM: fetch_esphome_sensor_data - Empty device IP or sensor path.");
        return null;
    }
    
    $url = "http://$device_ip/sensor/$sensor_path";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout > 1 ? $timeout -1 : 1,
        CURLOPT_FAILONERROR => false, // We want to check HTTP code manually
        CURLOPT_HEADER => false,
        CURLOPT_USERAGENT => 'ESPHomePM-Unraid-Plugin/DataHandler'
    ]);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_errno = curl_errno($ch);

    if ($curl_errno) {
        error_log("ESPHomePM: cURL Error for $url: [$curl_errno] " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        $sensor_data = json_decode($response_body, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($sensor_data['value'])) {
            return (float)$sensor_data['value'];
        } else {
            error_log("ESPHomePM: Invalid JSON or missing 'value' in response from $url. Response: $response_body");
            return null;
        }
    } else {
        error_log("ESPHomePM: HTTP Error $http_code from $url. Response: $response_body");
        return null;
    }
}

// --- Core Logic Functions ---

function initialize_data_file_if_needed() {
    if (!file_exists(ESPHOMPM_DATA_FILE)) {
        $default_data = [
            'data_format_version' => ESPHOMPM_DATA_FORMAT_VERSION,
            'current_month' => [
                'month_year' => date('Y-m'),
                'daily_records' => [],
                'total_energy_kwh_completed_days' => 0.0,
                'total_cost_completed_days' => 0.0
            ],
            'historical_months' => [],
            'overall_totals' => [
                'monitoring_start_date' => date('Y-m-d'),
                'total_energy_kwh_all_time' => 0.0,
                'total_cost_all_time' => 0.0
            ]
        ];
        if (!save_json_data(ESPHOMPM_DATA_FILE, $default_data)) {
            error_log("ESPHomePM: CRITICAL - Failed to initialize data file " . ESPHOMPM_DATA_FILE);
            return null;
        }
        return $default_data;
    }
    
    $data = load_json_data(ESPHOMPM_DATA_FILE);
    if ($data === null) { // File exists but is unreadable/corrupt
         error_log("ESPHomePM: CRITICAL - Data file " . ESPHOMPM_DATA_FILE . " is corrupt or unreadable. Attempting to backup and re-initialize.");
         // Basic backup, could be improved
         @copy(ESPHOMPM_DATA_FILE, ESPHOMPM_DATA_FILE . '.' . time() . '.corrupt.bak');
         return initialize_data_file_if_needed(); // Recursive call to create a new one
    }

    // Version check (simple for now, can be expanded for migration logic)
    if (!isset($data['data_format_version']) || $data['data_format_version'] !== ESPHOMPM_DATA_FORMAT_VERSION) {
        error_log("ESPHomePM: Data file version mismatch or missing. Expected: " . ESPHOMPM_DATA_FORMAT_VERSION . ". Found: " . ($data['data_format_version'] ?? 'N/A') . ". Consider backup/migration.");
        // For now, let's attempt to proceed but log heavily or re-initialize if too different
        // A more robust solution would be a migration path or forcing re-init for major changes.
        // Let's add missing top-level keys if they don't exist for basic compatibility
        if (!isset($data['current_month'])) $data['current_month'] = ['month_year' => date('Y-m'), 'daily_records' => [], 'total_energy_kwh_completed_days' => 0.0, 'total_cost_completed_days' => 0.0];
        if (!isset($data['historical_months'])) $data['historical_months'] = [];
        if (!isset($data['overall_totals'])) $data['overall_totals'] = ['monitoring_start_date' => date('Y-m-d'), 'total_energy_kwh_all_time' => 0.0, 'total_cost_all_time' => 0.0];
        $data['data_format_version'] = ESPHOMPM_DATA_FORMAT_VERSION; // Stamp with current version
    }
    return $data;
}


function update_power_consumption_data() {
    $config = load_plugin_config();
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
    $e_completed_day = fetch_esphome_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH']);

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
    return save_json_data(ESPHOMPM_DATA_FILE, $power_data);
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
