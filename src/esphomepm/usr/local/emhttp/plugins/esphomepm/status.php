<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_error.log');

// Set content type to JSON
header('Content-Type: application/json');

// Include the monthly_data.php file to use its functions
include_once(dirname(__FILE__) . '/monthly_data.php');

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
$costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : '0.27';
$costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : 'GBP';

// Log the configuration for debugging
error_log("Configuration loaded: Device IP = $device_ip");

// Cache settings
$cache_file = '/tmp/esphomepm_cache.json';
$cache_expiry = 2; // Cache expiry in seconds

// Test mode - return dummy data for testing
if (isset($_GET['test']) && $_GET['test'] === 'true') {
    echo json_encode([
        'Power' => 35.08,
        'Total' => 0.6,
        'Voltage' => 242.6,
        'Current' => 0.244,
        'Costs_Price' => $costs_price,
        'Costs_Unit' => $costs_unit
    ]);
    exit;
}

// Check if device IP is set
if (empty($device_ip)) {
    echo json_encode([
        'error' => 'ESPHome Device IP missing',
        'Power' => 0,
        'Total' => 0,
        'Voltage' => 0,
        'Current' => 0,
        'Costs_Price' => $costs_price,
        'Costs_Unit' => $costs_unit
    ]);
    exit;
}

// Check if we have a valid cache file that's not expired
if (file_exists($cache_file)) {
    $cache_time = filemtime($cache_file);
    $current_time = time();
    
    // If cache is still valid, use it
    if (($current_time - $cache_time) < $cache_expiry) {
        $cached_data = file_get_contents($cache_file);
        if ($cached_data) {
            // Add a cache indicator for debugging
            $data = json_decode($cached_data, true);
            $data['cached'] = true;
            echo json_encode($data);
            exit;
        }
    }
}

// Function to get sensor value with retry and error handling
function getSensorValue($sensor, $device_ip) {
    if (empty($device_ip)) {
        error_log("getSensorValue: Empty device IP");
        return 0;
    }
    
    try {
        $url = "http://$device_ip/sensor/$sensor";
        error_log("Fetching sensor data from: $url");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Increased timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Increased connect timeout
        $response = curl_exec($ch);
        $curl_error = curl_errno($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($curl_error) {
            error_log("getSensorValue: cURL error $curl_error for $url");
            return 0;
        }
        
        if ($http_code != 200) {
            error_log("getSensorValue: HTTP error $http_code for $url");
            return 0;
        }
        
        error_log("Raw response from $sensor: $response");
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("getSensorValue: JSON parse error: " . json_last_error_msg() . " for response: $response");
            return 0;
        }
        
        $value = isset($data['value']) ? floatval($data['value']) : 0;
        error_log("Sensor $sensor value: $value");
        return $value;
    } catch (Exception $e) {
        error_log("getSensorValue: Exception: " . $e->getMessage());
        return 0;
    }
}

// Prepare the response data
$response_data = [
    'Power' => 0,
    'Total' => 0,
    'Voltage' => 0,
    'Current' => 0,
    'Costs_Price' => $costs_price,
    'Costs_Unit' => $costs_unit,
    'cached' => false
];

// Check if we can connect to the device
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://$device_ip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Increased timeout
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Increased connect timeout
$response = curl_exec($ch);
$curl_error = curl_errno($ch);
curl_close($ch);

if ($curl_error) {
    $response_data['error'] = 'Cannot connect to ESPHome device';
    echo json_encode($response_data);
    exit;
}

// Get sensor values with a small delay between requests to avoid overloading the device
$response_data['Power'] = getSensorValue('power', $device_ip);
usleep(200000); // 200ms delay

// Get total daily energy - try both possible sensor names
$daily_energy = getSensorValue('daily_energy', $device_ip);
usleep(200000); // 200ms delay

// If daily_energy returns 0, try alternative sensor name
if ($daily_energy == 0) {
    $daily_energy = getSensorValue('total_daily_energy', $device_ip);
    usleep(200000); // 200ms delay
}

$response_data['Total'] = $daily_energy;

// Get voltage and current
$response_data['Voltage'] = getSensorValue('voltage', $device_ip);
usleep(200000); // 200ms delay

$response_data['Current'] = getSensorValue('current', $device_ip);

// Periodically update the monthly data (every hour)
// We'll use a timestamp file to track when the last update was performed
$update_marker = '/tmp/esphomepm_last_update.txt';
$update_interval = 3600; // 1 hour in seconds

$should_update = false;
if (!file_exists($update_marker)) {
    $should_update = true;
    error_log("No update marker found, performing initial update");
} else {
    $last_update = intval(file_get_contents($update_marker));
    $current_time = time();
    if (($current_time - $last_update) > $update_interval) {
        $should_update = true;
        error_log("Update interval exceeded, performing update");
    }
}

// Only update if we have valid energy data
if ($should_update && $daily_energy > 0) {
    error_log("Performing background update of monthly data with energy=$daily_energy, price=$costs_price");
    // Update the monthly data with the current reading
    $update_result = updateMonthlyData($daily_energy, $costs_price);
    error_log("Background update result: " . json_encode($update_result));
    
    // Update the timestamp file
    file_put_contents($update_marker, time());
}


}

// Save the data to cache file
file_put_contents($cache_file, json_encode($response_data));

// Return the data
echo json_encode($response_data);
?>