<?php
// Basic error handling
ini_set('display_errors', 0);
error_reporting(0);

// Set content type to JSON
header('Content-Type: application/json');

// Parse the configuration file
$config_file = '/boot/config/plugins/esphomepm/esphomepm.cfg';
$config = parse_ini_file($config_file);

// Get device IP and cost settings
$device_ip = isset($config['DEVICE_IP']) ? $config['DEVICE_IP'] : '';
$costs_price = isset($config['COSTS_PRICE']) ? $config['COSTS_PRICE'] : '0.27';
$costs_unit = isset($config['COSTS_UNIT']) ? $config['COSTS_UNIT'] : 'GBP';

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
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://$device_ip/sensor/$sensor");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Increased timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Increased connect timeout
    $response = curl_exec($ch);
    $curl_error = curl_errno($ch);
    curl_close($ch);
    
    if ($curl_error) return 0;
    
    $data = json_decode($response, true);
    return isset($data['value']) ? floatval($data['value']) : 0;
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

$response_data['Total'] = getSensorValue('daily_energy', $device_ip);
usleep(200000); // 200ms delay

// Try alternative sensor name if daily_energy returns 0
if ($response_data['Total'] == 0) {
    $response_data['Total'] = getSensorValue('energy_today', $device_ip);
    usleep(200000); // 200ms delay
}

$response_data['Voltage'] = getSensorValue('voltage', $device_ip);
usleep(200000); // 200ms delay

$response_data['Current'] = getSensorValue('current', $device_ip);

// Save the data to cache file
file_put_contents($cache_file, json_encode($response_data));

// Return the data
echo json_encode($response_data);
?>