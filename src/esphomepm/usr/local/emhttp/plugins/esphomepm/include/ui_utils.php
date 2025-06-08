<?php
/**
 * ESPHomePM Plugin - UI Utilities
 *
 * This file contains functions specifically for generating UI-related content and responses.
 */

/**
 * Set JSON response headers
 */
function esphomepm_set_json_headers() {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
}

/**
 * Get the JavaScript code for updating the ESPHomePM dashboard widget
 * 
 * @return string JavaScript code for updating the dashboard widget
 */
function esphomepm_get_dashboard_javascript() {
    // Using NOWDOC to prevent issues with $ in JS code
    $js = <<<'EOT'
<script type="text/javascript">
$(function() {
    // Update ESPHome Power Monitor dashboard widget
    function updateESPHomePMStatus() {
        $.getJSON('/plugins/esphomepm/status.php', function(data) {
            if (!data) return;
            
            // Update current power
            updateValue('.esphomepm-current-power', data.power, 2);
            
            // Use avg_power from status.php if available (already calculated there)
            if (data.avg_power !== undefined) {
                 updateValue('.esphomepm-avg-power', data.avg_power, 0);
            } else if (data.today_energy !== undefined) { // Fallback if avg_power not sent
                const now = new Date();
                const hoursPassed = now.getHours() + (now.getMinutes() / 60);
                if (hoursPassed > 0) {
                    const avgPower = (data.today_energy * 1000) / hoursPassed;
                    updateValue('.esphomepm-avg-power', avgPower, 0);
                } else {
                    updateValue('.esphomepm-avg-power', 0, 0);
                }
            } else {
                updateValue('.esphomepm-avg-power', 0, 0);
            }
            
            // Energy values
            updateValue('.esphomepm-energy-today', data.today_energy, 3);
            updateValue('.esphomepm-energy-month', data.current_month_energy_total, 3);
            updateValue('.esphomepm-energy-total', data.overall_total_energy, 3);
            
            // Cost values
            updateValue('.esphomepm-costs_today', data.daily_cost, 2);
            updateValue('.esphomepm-costs_month', data.current_month_cost_total || data.monthly_cost_est, 2); // monthly_cost_est is a fallback
            updateValue('.esphomepm-costs_total', data.overall_total_cost, 2);
        });
    }

    // Helper function to update values with proper formatting
    function updateValue(selector, value, decimals) {
        if (value !== undefined && value !== null) {
            $(selector).html(parseFloat(value).toFixed(decimals));
        } else {
            // Default to 0 with appropriate decimal places if value is missing
            $(selector).html(Number(0).toFixed(decimals)); 
        }
    }

    // Initial update
    updateESPHomePMStatus();
    
    // Set up automatic refresh every 10 seconds
    setInterval(updateESPHomePMStatus, 10000);
});
</script>
EOT;
    
    return $js;
}


/**
 * Fetch live sensor data & compute daily cost
 *
 * @param array $config Plugin configuration
 * @return array ['power'=>float,'today_energy'=>float,'daily_cost'=>float,'errors'=>array,'avg_power'=>float]
 */
function esphomepm_get_live_data(array $config): array {
    $power_res = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['POWER_SENSOR_PATH'], 5, true);
    $energy_res = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH'], 5, true);
    
    $power = isset($power_res['value']) ? (float)$power_res['value'] : 0.0;
    $daily_energy = isset($energy_res['value']) ? (float)$energy_res['value'] : 0.0;
    
    $errors = [];
    if (!empty($power_res['error'])) $errors[] = 'Power: ' . $power_res['error'];
    if (!empty($energy_res['error'])) $errors[] = 'Daily Energy: ' . $energy_res['error'];
    
    $price = is_numeric($config['COSTS_PRICE']) ? (float)$config['COSTS_PRICE'] : 0.0;
    $daily_cost = $daily_energy * $price;
    
    // Calculate average power for today so far
    $avg_power_today = 0.0;
    if ($daily_energy > 0) {
        $now = new DateTime();
        $start_of_day = new DateTime('today');
        $seconds_passed_today = $now->getTimestamp() - $start_of_day->getTimestamp();
        $hours_passed_today = $seconds_passed_today / 3600;
        if ($hours_passed_today > 0) {
            $avg_power_today = ($daily_energy * 1000) / $hours_passed_today; // Convert kWh to W
        }
    }

    return [
        'power' => round($power, 2),
        'today_energy' => round($daily_energy, 3),
        'daily_cost' => round($daily_cost, 2),
        'avg_power' => round($avg_power_today, 0),
        'errors' => $errors
    ];
}

/**
 * Build a unified response combining live & historical data for UI display
 *
 * @param array $config Plugin configuration
 * @return array Response payload
 */
function esphomepm_build_summary(array $config): array {
    $live_data = esphomepm_get_live_data($config);
    $historical_data = esphomepm_load_historical_data(); // From core_utils.php

    $all_errors = $live_data['errors'] ?? [];

    // Initialize with defaults to prevent errors if historical_data is null or incomplete
    $current_month_energy_completed = 0.0;
    $current_month_cost_completed = 0.0;
    $current_month_year = date('Y-m');
    $historical_months_data = [];
    $overall_total_energy_all_time = 0.0;
    $overall_total_cost_all_time = 0.0;
    $monitoring_start_date = date('Y-m-d');

    if (is_array($historical_data)) {
        $current_month_energy_completed = (float)($historical_data['current_month']['total_energy_kwh_completed_days'] ?? 0.0);
        $current_month_cost_completed = (float)($historical_data['current_month']['total_cost_completed_days'] ?? 0.0);
        $current_month_year = $historical_data['current_month']['month_year'] ?? date('Y-m');
        $historical_months_data = is_array($historical_data['historical_months'] ?? null) ? $historical_data['historical_months'] : [];
        $overall_total_energy_all_time = (float)($historical_data['overall_totals']['total_energy_kwh_all_time'] ?? 0.0);
        $overall_total_cost_all_time = (float)($historical_data['overall_totals']['total_cost_all_time'] ?? 0.0);
        $monitoring_start_date = $historical_data['overall_totals']['monitoring_start_date'] ?? date('Y-m-d');
    } else {
        $all_errors[] = "Historical data file not found, unreadable, or corrupt. Check system logs.";
    }

    // Combine completed day totals with today's live data
    $current_month_energy_total_so_far = $current_month_energy_completed + $live_data['today_energy'];
    $current_month_cost_total_so_far = $current_month_cost_completed + $live_data['daily_cost'];
    
    $overall_total_energy_plus_today = $overall_total_energy_all_time + $live_data['today_energy'];
    $overall_total_cost_plus_today = $overall_total_cost_all_time + $live_data['daily_cost'];

    // Average daily energy for the current month (including today)
    $days_in_current_month_so_far = (int)date('j'); // Day of the month
    $average_daily_energy_current_month = $days_in_current_month_so_far ? round($current_month_energy_total_so_far / $days_in_current_month_so_far, 3) : 0.0;

    return [
        'power' => $live_data['power'],
        'today_energy' => $live_data['today_energy'],
        'daily_cost' => $live_data['daily_cost'],
        'avg_power' => $live_data['avg_power'], // Average power for today
        'costs_price' => $config['COSTS_PRICE'], 
        'costs_unit' => $config['COSTS_UNIT'],
        
        // Current month totals (completed days only from historical data)
        'current_month_energy_completed_days' => round($current_month_energy_completed, 3),
        'current_month_cost_completed_days' => round($current_month_cost_completed, 2),
        
        // Current month totals (including today's live data)
        'current_month_energy_total' => round($current_month_energy_total_so_far, 3),
        'current_month_cost_total' => round($current_month_cost_total_so_far, 2),
        
        'average_daily_energy' => $average_daily_energy_current_month, // Average daily energy for current month
        
        'current_month' => [
            'month_year' => $current_month_year,
            'total_energy_kwh_completed_days' => round($current_month_energy_completed, 3),
            'total_cost_completed_days' => round($current_month_cost_completed, 2)
        ],
        
        'historical_data_available' => is_array($historical_data) && !empty($historical_data),
        'historical_months' => $historical_months_data,
        
        // Overall totals (including today's live data)
        'overall_total_energy' => round($overall_total_energy_plus_today, 3),
        'overall_total_cost' => round($overall_total_cost_plus_today, 2),
        'monitoring_start_date' => $monitoring_start_date,
        
        // 'monthly_cost_est' was somewhat redundant with 'current_month_cost_total'. 
        // If a true projection is needed later, it would involve days_in_month etc.
        // For now, current_month_cost_total reflects the cost up to this moment in the month.
        'monthly_cost_est' => round($current_month_cost_total_so_far, 2), 

        'error' => empty($all_errors) ? null : implode('; ', $all_errors)
    ];
}

?>
