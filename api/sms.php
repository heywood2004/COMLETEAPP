<?php
/**
 * ==========================================
 * Hotel Management System – SMS helper
 * ==========================================
 * Primary provider: Africa's Talking (Kenya-friendly)
 * Falls back to logging when SMS_ENABLED is false.
 */

declare(strict_types=1);

/**
 * Normalize phone to international form without +
 * Accepts 07..., 7..., 2547..., +2547...
 */
function smsNormalizePhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);
    if ($phone === '') {
        return '';
    }
    if (str_starts_with($phone, '0') && strlen($phone) === 10) {
        $phone = '254' . substr($phone, 1);
    } elseif (str_starts_with($phone, '7') && strlen($phone) === 9) {
        $phone = '254' . $phone;
    } elseif (str_starts_with($phone, '1') && strlen($phone) === 9) {
        $phone = '254' . $phone;
    }
    return $phone;
}

/**
 * Send SMS. Returns true on accepted/queued.
 */
function sendSms(string $to, string $message): bool
{
    $to = smsNormalizePhone($to);
    $message = trim($message);

    if ($to === '' || $message === '') {
        apiLog('warning', 'sendSms: missing to or message');
        return false;
    }
    if (!preg_match('/^254\d{9}$/', $to)) {
        apiLog('warning', 'sendSms: invalid phone', ['to' => $to]);
        return false;
    }

    if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
        apiLog('info', 'sendSms: skipped (SMS_ENABLED=false)', [
            'to' => $to,
            'message' => mb_substr($message, 0, 120)
        ]);
        return false;
    }

    $provider = defined('SMS_PROVIDER') ? strtolower(SMS_PROVIDER) : 'africastalking';

    try {
        if ($provider === 'africastalking' || $provider === 'at') {
            return sendSmsAfricaTalking($to, $message);
        }
        if ($provider === 'twilio') {
            return sendSmsTwilio($to, $message);
        }
        // Generic HTTP webhook
        return sendSmsWebhook($to, $message);
    } catch (Throwable $e) {
        apiLog('error', 'sendSms failed', ['error' => $e->getMessage(), 'to' => $to]);
        return false;
    }
}

function sendSmsAfricaTalking(string $to, string $message): bool
{
    $username = defined('SMS_AT_USERNAME') ? SMS_AT_USERNAME : '';
    $apiKey  = defined('SMS_AT_API_KEY') ? SMS_AT_API_KEY : '';
    $from    = defined('SMS_SENDER_ID') ? SMS_SENDER_ID : '';
    $sandbox = defined('SMS_AT_SANDBOX') ? (bool) SMS_AT_SANDBOX : true;

    if ($username === '' || $apiKey === '') {
        apiLog('error', 'Africa\'s Talking credentials missing');
        return false;
    }

    $url = $sandbox
        ? 'https://api.sandbox.africastalking.com/version1/messaging'
        : 'https://api.africastalking.com/version1/messaging';

    $post = [
        'username' => $username,
        'to'       => '+' . $to,
        'message'  => $message,
    ];
    if ($from !== '') {
        $post['from'] = $from;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'apiKey: ' . $apiKey,
        ],
    ]);
    $response = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        apiLog('error', 'AT SMS curl error', ['error' => $err]);
        return false;
    }

    $data = json_decode($response, true);
    apiLog('info', 'AT SMS response', ['http' => $http, 'body' => $data ?: $response]);

    // Africa's Talking returns 201 on success typically
    if ($http >= 200 && $http < 300) {
        $status = $data['SMSMessageData']['Recipients'][0]['status'] ?? '';
        // "Success" or "Sent" etc.
        if ($status === '' || stripos($status, 'success') !== false || stripos($status, 'sent') !== false) {
            return true;
        }
        // Still accept if API accepted the request
        return true;
    }
    return false;
}

function sendSmsTwilio(string $to, string $message): bool
{
    $sid   = defined('SMS_TWILIO_SID') ? SMS_TWILIO_SID : '';
    $token = defined('SMS_TWILIO_TOKEN') ? SMS_TWILIO_TOKEN : '';
    $from  = defined('SMS_SENDER_ID') ? SMS_SENDER_ID : '';

    if ($sid === '' || $token === '' || $from === '') {
        apiLog('error', 'Twilio credentials missing');
        return false;
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
    $post = [
        'To'   => '+' . $to,
        'From' => $from,
        'Body' => $message,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERPWD        => $sid . ':' . $token,
    ]);
    $response = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    apiLog('info', 'Twilio SMS response', ['http' => $http, 'body' => $response]);
    return $http >= 200 && $http < 300;
}

function sendSmsWebhook(string $to, string $message): bool
{
    $url = defined('SMS_WEBHOOK_URL') ? SMS_WEBHOOK_URL : '';
    if ($url === '') {
        apiLog('error', 'SMS webhook URL not configured');
        return false;
    }
    $payload = json_encode(['to' => $to, 'message' => $message]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $response = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    apiLog('info', 'Webhook SMS', ['http' => $http, 'body' => $response]);
    return $http >= 200 && $http < 300;
}

/**
 * Resolve best phone for a booking/user
 */
function resolveGuestPhone(?array $booking = null, ?array $user = null): string
{
    $candidates = [];
    if ($booking) {
        $candidates[] = $booking['guest_phone'] ?? '';
        $candidates[] = $booking['phone'] ?? '';
    }
    if ($user) {
        $candidates[] = $user['phone'] ?? '';
    }
    foreach ($candidates as $p) {
        $n = smsNormalizePhone((string) $p);
        if ($n !== '') return $n;
    }
    return '';
}

function smsAppName(): string
{
    return defined('APP_NAME') ? APP_NAME : "Heywood's Hotel";
}

function sendBookingSms(array $booking, string $phone): bool
{
    if ($phone === '') return false;
    $id = (int) ($booking['id'] ?? $booking['booking_id'] ?? 0);
    $room = trim(($booking['room_number'] ?? '') . ' ' . ($booking['room_type'] ?? ''));
    $checkIn = $booking['check_in'] ?? '';
    $checkOut = $booking['check_out'] ?? '';
    $msg = smsAppName() . ": Booking #{$id} confirmed. Room {$room}. Check-in {$checkIn}, check-out {$checkOut}. We look forward to your stay.";
    return sendSms($phone, $msg);
}

function sendCancellationSms(array $booking, string $phone): bool
{
    if ($phone === '') return false;
    $id = (int) ($booking['id'] ?? $booking['booking_id'] ?? 0);
    $msg = smsAppName() . ": Booking #{$id} has been cancelled. Contact the front desk if this was unexpected.";
    return sendSms($phone, $msg);
}

function sendCheckinSms(array $booking, string $phone): bool
{
    if ($phone === '') return false;
    $room = $booking['room_number'] ?? '';
    $msg = smsAppName() . ": You are checked in to room {$room}. Enjoy your stay!";
    return sendSms($phone, $msg);
}
