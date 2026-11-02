<?php
/**
 * Hotel settings API – GET all / POST update billing rules
 */
require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}

require_once __DIR__ . '/billing_rules.php';

$user = requireLogin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $settings = getBillingSettings($pdo);
    jsonResponse(true, 'Settings loaded.', $settings, 200);
}

if ($method === 'POST') {
    requirePermission('settings');
    $input = getJsonInput();

    $allowed = [
        'tax_rate',
        'service_charge_rate',
        'currency',
        'min_nights',
        'early_checkout_policy',
        'late_checkout_hour',
        'late_checkout_fee',
        'auto_apply_tax',
        'auto_apply_service',
    ];

    $policies = ['actual', 'reserved', 'minimum'];

    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }
        $upsert = $pdo->prepare("
            INSERT INTO hotel_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];

            switch ($key) {
                case 'tax_rate':
                case 'service_charge_rate':
                case 'late_checkout_fee':
                    $value = max(0, (float) $value);
                    break;
                case 'min_nights':
                    $value = max(1, (int) $value);
                    break;
                case 'late_checkout_hour':
                    $value = max(0, min(23, (int) $value));
                    break;
                case 'early_checkout_policy':
                    $value = strtolower((string) $value);
                    if (!in_array($value, $policies, true)) {
                        throw new Exception('Invalid early checkout policy.');
                    }
                    break;
                case 'auto_apply_tax':
                case 'auto_apply_service':
                    $value = ((int) $value) ? 1 : 0;
                    break;
                case 'currency':
                    $value = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $value), 0, 3)) ?: 'KES';
                    break;
            }

            $upsert->execute([$key, (string) $value]);
        }

        $pdo->commit();
        jsonResponse(true, 'Billing settings updated.', getBillingSettings($pdo), 200);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, $e->getMessage(), null, 400);
    }
}

jsonResponse(false, 'Method not allowed.', null, 405);
