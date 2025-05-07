<?php
// Basic error handling
ini_set('display_errors', 0);
error_reporting(0);

// Set content type to JSON
header('Content-Type: application/json');

// Set cache control headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Test mode - return dummy data for testing
if (isset($_GET['test']) && $_GET['test'] === 'true') {
    echo json_encode([
        'Power' => 35.08,
        'Total' => 0.6,
        'Voltage' => 242.6,
        'Current' => 0.244,
        'Costs_Price' => '0.27',
        'Costs_Unit' => 'GBP'
    ]);
    exit;
}

// Get device IP from config file
$config_file = "/boot/config/plugins/esphomepm/esphomepm.cfg";
$device_ip = "";
$costs_price = "0.27";
$costs_unit = "GBP";

if (file_exists($config_file)) {
    $config = parse_ini_file($config_file);
    $device_ip = isset($config['DEVICE_IP']) ? $config['DEVICE_IP'] : "";
    $costs_price = isset($config['COSTS_PRICE']) ? $config['COSTS_PRICE'] : "0.27";
    $costs_unit = isset($config['COSTS_UNIT']) ? $config['COSTS_UNIT'] : "GBP";
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

// Function to get sensor value
function getSensorValue($sensor, $device_ip) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://$device_ip/sensor/$sensor");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $response = curl_exec($ch);
    $curl_error = curl_errno($ch);
    curl_close($ch);
    
    if ($curl_error) return 0;
    
    $data = json_decode($response, true);
    return isset($data['value']) ? floatval($data['value']) : 0;
}

// Check if we can connect to the device
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://$device_ip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
$response = curl_exec($ch);
$curl_error = curl_errno($ch);
curl_close($ch);

if ($curl_error) {
    echo json_encode([
        'error' => 'Cannot connect to ESPHome device',
        'Power' => 0,
        'Total' => 0,
        'Voltage' => 0,
        'Current' => 0,
        'Costs_Price' => $costs_price,
        'Costs_Unit' => $costs_unit
    ]);
    exit;
}

// Get sensor values
$power = getSensorValue('power', $device_ip);
$daily_energy = getSensorValue('daily_energy', $device_ip);

// Try alternative sensor name if daily_energy returns 0
if ($daily_energy == 0) {
    $daily_energy = getSensorValue('energy_today', $device_ip);
}

$voltage = getSensorValue('voltage', $device_ip);
$current = getSensorValue('current', $device_ip);

// Return the data
echo json_encode([
    'Power' => $power,
    'Total' => $daily_energy,
    'Voltage' => $voltage,
    'Current' => $current,
    'Costs_Price' => $costs_price,
    'Costs_Unit' => $costs_unit
]);
?>