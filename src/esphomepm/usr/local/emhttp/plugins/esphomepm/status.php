<?php
// Include common functions
require_once __DIR__ . '/include/functions.php';

// Set up error logging for this component
esphomepm_setup_error_logging('status');

// Set content type to JSON
esphomepm_set_json_headers();

// Load configuration
$config = esphomepm_load_config();

// Initialize config variables with defaults
$device_ip = $config['DEVICE_IP'] ?? "";
$costs_price = $config['COSTS_PRICE'] ?? 0.0;
$costs_unit = $config['COSTS_UNIT'] ?? "";
$power_sensor_path = $config['POWER_SENSOR_PATH'] ?? 'power';
$daily_energy_sensor_path = $config['DAILY_ENERGY_SENSOR_PATH'] ?? 'daily_energy';

// Function to get sensor value with retry and error handling - wrapper around the common function
function getSensorValue($sensor, $device_ip, $timeout = 2) {
    return esphomepm_fetch_sensor_data($device_ip, $sensor, $timeout, true);
}

// Handle graph point request
if (isset($_GET['graph_point']) && $_GET['graph_point'] === 'true') {
    if (empty($device_ip)) {
        esphomepm_log_error("Graph point request with missing device IP", 'WARNING', 'status');
        echo json_encode(['power' => 0, 'error' => 'ESPHome Device IP missing']);
        exit;
    }
    $power_data = getSensorValue("power", $device_ip, 1); // Shorter timeout for graph point
    echo json_encode(['power' => $power_data['value'], 'error' => $power_data['error']]);
    exit;
}

// Function to load historical data from JSON file - wrapper around the common function
function load_historical_data() {
    return esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
}

// Standard data request logic
if (empty($device_ip)) {
    echo json_encode([
        'power' => 0, 'today_energy' => 0,
        'daily_cost' => 0, 'monthly_cost_est' => 0,
        'costs_price' => $costs_price, 'costs_unit' => $costs_unit,
        'historical_data_available' => false,
        'error' => 'ESPHome Device IP missing'
    ]);
    exit;
}

// --- Fetch data from ESPHome device for standard request ---
$error_messages = [];

$power_result = getSensorValue($power_sensor_path, $device_ip);
$daily_energy_result = getSensorValue($daily_energy_sensor_path, $device_ip);

$power = 0;
if (isset($power_result['value'])) {
    $power = $power_result['value'];
}
if (isset($power_result['error']) && $power_result['error'] !== null) {
    $error_messages[] = "Power: " . $power_result['error'];
}

$daily_energy = 0;
if (isset($daily_energy_result['value'])) {
    $daily_energy = $daily_energy_result['value'];
}
if (isset($daily_energy_result['error']) && $daily_energy_result['error'] !== null) {
    $error_messages[] = "Daily Energy: " . $daily_energy_result['error'];
}

// Calculations
$costs_price_numeric = is_numeric($costs_price) ? (float)$costs_price : 0.0; // Ensure costs_price is numeric
$daily_cost = $daily_energy * $costs_price_numeric;

// --- Load historical data ---
$historical_data = load_historical_data();
$historical_data_available = ($historical_data !== null);

// Default values for historical data
$current_month_energy_completed_days = 0.0;
$current_month_cost_completed_days = 0.0;
$historical_months = [];
$overall_total_energy = 0.0;
$overall_total_cost = 0.0;
$monitoring_start_date = date('Y-m-d'); // Default to today if no historical data

// Extract historical data if available
if ($historical_data_available) {
    // Current month completed days (excluding today)
    if (isset($historical_data['current_month']['total_energy_kwh_completed_days'])) {
        $current_month_energy_completed_days = (float)$historical_data['current_month']['total_energy_kwh_completed_days'];
    }
    if (isset($historical_data['current_month']['total_cost_completed_days'])) {
        $current_month_cost_completed_days = (float)$historical_data['current_month']['total_cost_completed_days'];
    }
    
    // Historical months
    if (isset($historical_data['historical_months']) && is_array($historical_data['historical_months'])) {
        $historical_months = $historical_data['historical_months'];
    }
    
    // Overall totals
    if (isset($historical_data['overall_totals']['total_energy_kwh_all_time'])) {
        $overall_total_energy = (float)$historical_data['overall_totals']['total_energy_kwh_all_time'];
    }
    if (isset($historical_data['overall_totals']['total_cost_all_time'])) {
        $overall_total_cost = (float)$historical_data['overall_totals']['total_cost_all_time'];
    }
    if (isset($historical_data['overall_totals']['monitoring_start_date'])) {
        $monitoring_start_date = $historical_data['overall_totals']['monitoring_start_date'];
    }
}

// Calculate current month totals including today's values
$current_month_energy_total = $current_month_energy_completed_days + $daily_energy;
$current_month_cost_total = $current_month_cost_completed_days + $daily_cost;

// Update overall totals to include today's values
// The values from the data file only include completed days up to midnight the previous day
// We need to add today's energy and cost to get the current up-to-date totals
$overall_total_energy += $daily_energy;
$overall_total_cost += $daily_cost;

// Prepare response
$response_data = [
    // Live data
    'power' => $power,
    'today_energy' => $daily_energy,
    'daily_cost' => round($daily_cost, 2),
    
    // Configuration
    'costs_price' => $costs_price,
    'costs_unit' => $costs_unit,
    
    // Current month data
    'current_month_energy_completed_days' => round($current_month_energy_completed_days, 3),
    'current_month_cost_completed_days' => round($current_month_cost_completed_days, 2),
    'current_month_energy_total' => round($current_month_energy_total, 3), // Including today
    'current_month_cost_total' => round($current_month_cost_total, 2),    // Including today
    
    // Historical and overall data
    'historical_data_available' => $historical_data_available,
    'historical_months' => $historical_months,
    'overall_total_energy' => round($overall_total_energy, 3),
    'overall_total_cost' => round($overall_total_cost, 2),
    'monitoring_start_date' => $monitoring_start_date,
    
    // For backward compatibility
    'monthly_cost_est' => round($current_month_cost_total, 2), // Now using actual data instead of daily*30
    
    // Error information
    'error' => empty($error_messages) ? null : implode('; ', $error_messages)
];

echo json_encode($response_data);
?>