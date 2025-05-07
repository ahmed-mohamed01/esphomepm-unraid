<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set headers to prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Check if this is a direct access test
if (isset($_GET['test']) && $_GET['test'] === 'direct') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'message' => 'Direct access to status.php is working']);
    exit;
}

// Load configuration using parse_plugin_cfg (same method as in ESPHomePMSettings.page)
$esphomepm_cfg = parse_plugin_cfg("esphomepm", true);

// Get configuration values with defaults
$esphomepm_device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$esphomepm_costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$esphomepm_costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

// Debug information
$debug = [];
$debug['config_source'] = 'parse_plugin_cfg';
$debug['device_ip'] = $esphomepm_device_ip;
$debug['request_uri'] = $_SERVER['REQUEST_URI'];
$debug['script_name'] = $_SERVER['SCRIPT_NAME'];
$debug['server_name'] = $_SERVER['SERVER_NAME'];
$debug['server_port'] = $_SERVER['SERVER_PORT'];
$debug['http_host'] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'not set';

// Check if device IP is set
if (empty($esphomepm_device_ip)) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'ESPHome Device IP missing',
        'debug' => $debug
    ]);
    exit;
}

$baseUrl = "http://" . $esphomepm_device_ip;
$debug['base_url'] = $baseUrl;

// Initialize curl with more debugging options
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_TIMEOUT => 10,          // Increased timeout
    CURLOPT_CONNECTTIMEOUT => 10,   // Increased connect timeout
    CURLOPT_FOLLOWLOCATION => true, // Follow redirects
    CURLOPT_MAXREDIRS => 5,         // Maximum number of redirects to follow
    CURLOPT_NOBODY => false,        // We want the body
    CURLOPT_HEADER => true,         // Get headers to debug redirects
    CURLOPT_VERBOSE => true,        // Verbose output
    CURLOPT_SSL_VERIFYPEER => false, // Don't verify SSL
    CURLOPT_SSL_VERIFYHOST => 0     // Don't verify host
]);

// Create a temporary file for CURL debug output
$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose);

function getSensorValue($ch, $baseUrl, $sensor, &$debug) {
    // ESPHome uses a specific REST API format
    // The correct format is: http://<ip-address>/sensor/<sensor_id>
    $sensorId = strtolower(str_replace(' ', '_', $sensor));
    $url = $baseUrl . "/sensor/" . $sensorId;
    
    // Store the URL for debugging
    $debug[$sensor . '_url'] = $url;
    
    // Set the URL for this request
    curl_setopt($ch, CURLOPT_URL, $url);
    
    // Execute the request
    $response = curl_exec($ch);
    
    // Get verbose debug information
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    $debug[$sensor . '_verbose'] = substr($verboseLog, 0, 500); // Limit debug output
    
    // Get curl info and errors
    $info = curl_getinfo($ch);
    $debug[$sensor . '_info'] = $info;
    $debug[$sensor . '_curl_error'] = curl_error($ch);
    $debug[$sensor . '_curl_errno'] = curl_errno($ch);
    $debug[$sensor . '_http_code'] = $info['http_code'];
    $debug[$sensor . '_redirect_count'] = $info['redirect_count'];
    $debug[$sensor . '_redirect_url'] = $info['redirect_url'];
    
    // Check for curl errors
    if (curl_errno($ch)) {
        error_log("ESPHome API Error for $sensor: " . curl_error($ch));
        return 0;
    }
    
    // Check for HTTP errors
    if ($info['http_code'] >= 400) {
        error_log("ESPHome API HTTP Error for $sensor: " . $info['http_code']);
        return 0;
    }
    
    // Split headers and body if CURLOPT_HEADER is true
    if (isset($info['header_size'])) {
        $headerSize = $info['header_size'];
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $debug[$sensor . '_header'] = $header;
        $debug[$sensor . '_body'] = substr($body, 0, 200); // Limit debug output
        $response = $body; // Use only the body for further processing
    } else {
        $debug[$sensor . '_response'] = substr($response, 0, 200); // Limit debug output
    }
    
    // Try to parse the response as JSON
    $data = json_decode($response, true);
    $debug[$sensor . '_parsed'] = is_array($data);
    
    // Return the value if available, otherwise 0
    return isset($data['value']) ? floatval($data['value']) : 0;
}

// Try a direct connection to the ESPHome device first to test connectivity
curl_setopt($ch, CURLOPT_URL, $baseUrl);
$mainResponse = curl_exec($ch);
$mainInfo = curl_getinfo($ch);

// Store main connection debug info
$debug['main_connection'] = [
    'url' => $baseUrl,
    'http_code' => $mainInfo['http_code'],
    'redirect_count' => $mainInfo['redirect_count'],
    'redirect_url' => $mainInfo['redirect_url'],
    'curl_error' => curl_error($ch),
    'curl_errno' => curl_errno($ch)
];

// Check if we can connect to the ESPHome device
if (curl_errno($ch) || $mainInfo['http_code'] >= 400) {
    curl_close($ch);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Cannot connect to ESPHome device',
        'Total' => 0,
        'Power' => 0,
        'Voltage' => 0,
        'Current' => 0,
        'Costs_Price' => $esphomepm_costs_price,
        'Costs_Unit' => $esphomepm_costs_unit,
        'Debug' => $debug
    ]);
    exit;
}

// If we can connect, try to get the sensor values
// Using the exact sensor names from the ESPHome device
// Based on the screenshot, the sensor names are: Power, Daily Energy, Voltage, Current
try {
    $power = getSensorValue($ch, $baseUrl, "Power", $debug);
    $daily_energy = getSensorValue($ch, $baseUrl, "Daily Energy", $debug);
    $voltage = getSensorValue($ch, $baseUrl, "Voltage", $debug);
    $current = getSensorValue($ch, $baseUrl, "Current", $debug);
    
    $success = true;
} catch (Exception $e) {
    $debug['exception'] = $e->getMessage();
    $power = 0;
    $daily_energy = 0;
    $voltage = 0;
    $current = 0;
    $success = false;
}

curl_close($ch);

$json = array(
    'success' => $success,
    'Total' => $daily_energy,
    'Power' => $power,
    'Voltage' => $voltage,
    'Current' => $current,
    'Costs_Price' => $esphomepm_costs_price,
    'Costs_Unit' => $esphomepm_costs_unit,
    'Debug' => $debug
);

header('Content-Type: application/json');
echo json_encode($json);
?>