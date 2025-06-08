<?php
require_once '/usr/local/emhttp/plugins/esphomepm/include/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config_path = ESPHOMPM_CONFIG_FILE;
    $config_dir = dirname($config_path);

    if (!is_dir($config_dir)) {
        mkdir($config_dir, 0755, true);
    }

    $new_config = [
        'DEVICE_NAME' => $_POST['DEVICE_NAME'] ?? 'Unraid Server PM',
        'DEVICE_IP' => $_POST['DEVICE_IP'] ?? '',
        'COSTS_PRICE' => $_POST['COSTS_PRICE'] ?? '0.0',
        'COSTS_UNIT' => $_POST['COSTS_UNIT'] ?? 'USD',
    ];

    $file_content = '';
    foreach ($new_config as $key => $value) {
        $file_content .= "$key=\"$value\"\n";
    }

    file_put_contents($config_path, $file_content);

    header('Location: /Settings/ESPHomePMSettings');
    exit;
}
?> 