<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_status_error.log'); // Changed log file name for clarity

// Set content type to JSON
header('Content-Type: application/json');

// Parse the configuration using Unraid's function with fallback
try {
    if (function_exists('parse_plugin_cfg')) {
        $esphomepm_cfg = parse_plugin_cfg("esphomepm", true);
    } else {
        // Fallback to direct file access if function doesn't exist
        $config_file = '/boot/config/plugins/esphomepm/esphomepm.cfg';
        if (file_exists($config_file)) {
            $esphomepm_cfg = parse_ini_file($config_file);
        } else {
            $esphomepm_cfg = [];
        }
    }
} catch (Exception $e) {
    error_log("Error parsing config: " . $e->getMessage());
    $esphomepm_cfg = [];
}

// Get device IP and cost settings with better error handling
$device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : '';
$costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? floatval($esphomepm_cfg['COSTS_PRICE']) : 0.27;
$costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : 'GBP';

// Log the configuration for debugging
error_log("Configuration loaded: Device IP = $device_ip, Cost Price = $costs_price");

// Cache settings
$cache_file = '/tmp/esphomepm_cache.json';
$cache_expiry = 1; // Cache expiry in seconds, reduced for faster updates

// Function to get sensor value with retry and error handling
// This function seems robust and well-written, keeping it mostly as is.
function getSensorValue($sensor, $device_ip, $timeout = 2) { // Default timeout for sensor reads
    if (empty($device_ip)) {
        error_log("getSensorValue: Empty device IP for sensor $sensor");
        return ['value' => 0, 'error' => 'Device IP not configured'];
    }
    
    try {
        $urls = [
            "http://$device_ip/sensor/$sensor",  // Standard ESPHome API format
            // "http://$device_ip/api/sensor/$sensor" // Alternative, often not needed for plain sensor state
        ];
        
        foreach ($urls as $url) {
            error_log("Trying to fetch sensor data from: $url");
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout -1 > 0 ? $timeout -1 : 1); // Connect timeout slightly less
            curl_setopt($ch, CURLOPT_FAILONERROR, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'ESPHomePM-Unraid-Plugin/1.1'); // Updated version
            
            $response = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error_message = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($curl_errno) {
                error_log("getSensorValue: cURL error $curl_errno ($curl_error_message) for $url");
                continue; 
            }
            
            if ($http_code != 200) {
                error_log("getSensorValue: HTTP error $http_code for $url. Response: $response");
                continue; 
            }
            
            error_log("Raw response from $url: $response");
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (is_numeric(trim($response))) { // Trim whitespace before numeric check
                    error_log("getSensorValue: Numeric value detected: $response");
                    return ['value' => floatval(trim($response)), 'error' => null];
                }
                error_log("getSensorValue: JSON parse error: " . json_last_error_msg() . " for response: $response");
                continue; 
            }
            
            if (isset($data['value'])) {
                return ['value' => floatval($data['value']), 'error' => null];
            } else if (isset($data['state'])) {
                return ['value' => floatval($data['state']), 'error' => null];
            } else if (is_numeric($data)) {
                return ['value' => floatval($data), 'error' => null];
            }
            
            error_log("getSensorValue: Unexpected data format for $sensor: " . json_encode($data));
        }
        
        error_log("getSensorValue: All URL formats failed for sensor $sensor on $device_ip");
        return ['value' => 0, 'error' => "Failed to fetch $sensor"];
    } catch (Exception $e) {
        error_log("getSensorValue: Exception for $sensor: " . $e->getMessage());
        return ['value' => 0, 'error' => "Exception fetching $sensor"];
    }
}

// Handle graph point request
if (isset($_GET['graph_point']) && $_GET['graph_point'] === 'true') {
    if (empty($device_ip)) {
        echo json_encode(['power' => 0, 'error' => 'ESPHome Device IP missing']);
        exit;
    }
    $power_data = getSensorValue("power", $device_ip, 1); // Shorter timeout for graph point
    echo json_encode(['power' => $power_data['value'], 'error' => $power_data['error']]);
    exit;
}

// Standard data request logic
if (empty($device_ip)) {
    echo json_encode([
        'power' => 0, 'today_energy' => 0,
        'daily_cost' => 0, 'monthly_cost_est' => 0,
        'costs_price' => $costs_price, 'costs_unit' => $costs_unit,
        'error' => 'ESPHome Device IP missing'
    ]);
    exit;
}

// Check cache for standard requests
if (file_exists($cache_file)) {
    $cache_time = filemtime($cache_file);
    if ((time() - $cache_time) < $cache_expiry) {
        $cached_data_content = file_get_contents($cache_file);
        if ($cached_data_content) {
            $data = json_decode($cached_data_content, true);
            $data['cached'] = true;
            echo json_encode($data);
            exit;
        }
    }
}

// --- Fetch data from ESPHome device for standard request ---
$error_messages = [];

$power = getSensorValue("power", $device_ip);
$daily_energy = getSensorValue("daily_energy", $device_ip);

if ($power === null) {
    $error_messages[] = "Power: Failed to fetch power sensor";
    $power = 0; // Default to 0 if fetch failed
}
if ($daily_energy === null) {
    $error_messages[] = "Daily Energy: Failed to fetch daily_energy sensor";
    $daily_energy = 0; // Default to 0 if fetch failed
}

// Calculations
$costs_price_numeric = is_numeric($costs_price) ? (float)$costs_price : 0.0; // Ensure costs_price is numeric
$daily_cost = $daily_energy * $costs_price_numeric;
$monthly_cost_est = $daily_cost * 30;

// Prepare response
$response_data = [
    'power' => $power,
    'today_energy' => $daily_energy, // Key 'today_energy' for JS, value from 'daily_energy' sensor
    'daily_cost' => round($daily_cost, 2),
    'monthly_cost_est' => round($monthly_cost_est, 2),
    'costs_price' => $costs_price, // Original config value for display
    'costs_unit' => $costs_unit,
    'cached' => false,
    'error' => empty($error_messages) ? null : implode('; ', $error_messages)
];

// Write to cache
if (empty($error_messages)) { // Only cache if no errors during fetch
    file_put_contents($cache_file, json_encode($response_data));
} else {
    error_log("Not caching due to errors: " . implode('; ', $error_messages));
}

echo json_encode($response_data);
?>