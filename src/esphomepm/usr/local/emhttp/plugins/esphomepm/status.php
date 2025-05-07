<?php
$esphomepm_cfg = parse_ini_file("/boot/config/plugins/esphomepm/esphomepm.cfg");
$esphomepm_device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$esphomepm_costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$esphomepm_costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

if ($esphomepm_device_ip == "") {
	header('Content-Type: application/json');
	echo json_encode(['error' => 'ESPHome Device IP missing']);
	exit;
}

$baseUrl = "http://" . $esphomepm_device_ip;

// Initialize curl
$ch = curl_init();
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HTTPHEADER => ['Accept: application/json'],
	CURLOPT_TIMEOUT => 5,
	CURLOPT_CONNECTTIMEOUT => 5
]);

function getSensorValue($ch, $baseUrl, $sensor) {
	curl_setopt($ch, CURLOPT_URL, $baseUrl . "/sensor/" . $sensor);
	$response = curl_exec($ch);
	
	if (curl_errno($ch)) {
		error_log("ESPHome API Error for $sensor: " . curl_error($ch));
		return 0;
	}
	
	$data = json_decode($response, true);
	return isset($data['value']) ? floatval($data['value']) : 0;
}

// Get sensor values
$power = getSensorValue($ch, $baseUrl, "power");
$daily_energy = getSensorValue($ch, $baseUrl, "daily_energy");
$voltage = getSensorValue($ch, $baseUrl, "voltage");
$current = getSensorValue($ch, $baseUrl, "current");

curl_close($ch);

$json = array(
		'Total' => $daily_energy,
		'Power' => $power,
		'Voltage' => $voltage,
		'Current' => $current,
		'Costs_Price' => $esphomepm_costs_price,
		'Costs_Unit' => $esphomepm_costs_unit
	);

header('Content-Type: application/json');
echo json_encode($json);
?>