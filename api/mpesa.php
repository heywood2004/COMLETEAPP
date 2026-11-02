<?php
/**
 * ==========================================
 * M-PESA STK PUSH (Safaricom Daraja API)
 * ==========================================
 *
 * Endpoints used via this file (routed by method/action):
 *   POST  action=stk      → Initiate STK Push
 *   GET   action=status   → Poll transaction status
 *   POST  (callback URL)  → handled by mpesa_callback.php
 *
 * Configure credentials in config.php or environment:
 *   MPESA_ENV, MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET,
 *   MPESA_SHORTCODE, MPESA_PASSKEY, MPESA_CALLBACK_URL
 */

require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf') && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();
}


// -------------------------------------------------------
// Config helpers (with safe defaults for sandbox)
// -------------------------------------------------------
function mpesaConfig(string $key, $default = null)
{
    $map = [
        'env'             => defined('MPESA_ENV') ? MPESA_ENV : 'sandbox',
        'consumer_key'    => defined('MPESA_CONSUMER_KEY') ? MPESA_CONSUMER_KEY : '',
        'consumer_secret' => defined('MPESA_CONSUMER_SECRET') ? MPESA_CONSUMER_SECRET : '',
        'shortcode'       => defined('MPESA_SHORTCODE') ? MPESA_SHORTCODE : '174379',
        'passkey'         => defined('MPESA_PASSKEY') ? MPESA_PASSKEY : '',
        'callback_url'    => defined('MPESA_CALLBACK_URL') ? MPESA_CALLBACK_URL : '',
    ];
    return $map[$key] ?? $default;
}

function mpesaBaseUrl(): string
{
    return mpesaConfig('env') === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

/**
 * Normalize Kenyan phone to 2547XXXXXXXX
 */
function mpesaNormalizePhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($phone, '0') && strlen($phone) === 10) {
        $phone = '254' . substr($phone, 1);
    } elseif (str_starts_with($phone, '7') && strlen($phone) === 9) {
        $phone = '254' . $phone;
    } elseif (str_starts_with($phone, '+')) {
        $phone = ltrim($phone, '+');
    }
    if (!preg_match('/^2547\d{8}$/', $phone) && !preg_match('/^2541\d{8}$/', $phone)) {
        // Allow 2547 and 2541 (Safaricom/Airtel sometimes); still validate length
        if (!preg_match('/^254\d{9}$/', $phone)) {
            throw new Exception('Enter a valid M-Pesa phone number (e.g. 07XX XXX XXX).');
        }
    }
    return $phone;
}

/**
 * Get OAuth access token (cached in session for ~50 minutes)
 */
function mpesaAccessToken(): string
{
    if (!empty($_SESSION['mpesa_token']) && !empty($_SESSION['mpesa_token_exp'])
        && time() < (int) $_SESSION['mpesa_token_exp']) {
        return $_SESSION['mpesa_token'];
    }

    $key = mpesaConfig('consumer_key');
    $secret = mpesaConfig('consumer_secret');
    if ($key === '' || $secret === '') {
        throw new Exception('M-Pesa API credentials are not configured. Set MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET.');
    }

    $url = mpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode($key . ':' . $secret)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('M-Pesa auth failed: ' . $err);
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        throw new Exception('M-Pesa auth failed. Check consumer key/secret and environment.');
    }

    $_SESSION['mpesa_token'] = $data['access_token'];
    $_SESSION['mpesa_token_exp'] = time() + 3000; // ~50 min
    return $data['access_token'];
}

function mpesaPassword(string $timestamp): string
{
    $shortcode = mpesaConfig('shortcode');
    $passkey = mpesaConfig('passkey');
    if ($passkey === '') {
        throw new Exception('M-Pesa passkey is not configured (MPESA_PASSKEY).');
    }
    return base64_encode($shortcode . $passkey . $timestamp);
}

/**
 * Initiate STK Push
 */
function mpesaStkPush(string $phone, float $amount, string $accountRef, string $description): array
{
    $phone = mpesaNormalizePhone($phone);
    $amount = (int) round($amount); // M-Pesa expects integer KES
    if ($amount < 1) {
        throw new Exception('Amount must be at least KSh 1.');
    }

    $timestamp = date('YmdHis');
    $token = mpesaAccessToken();
    $shortcode = mpesaConfig('shortcode');
    $callback = mpesaConfig('callback_url');

    if ($callback === '') {
        // Build from current host if not set
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $callback = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? '/api') . '/mpesa_callback.php';
    }

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password'          => mpesaPassword($timestamp),
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => $amount,
        'PartyA'            => $phone,
        'PartyB'            => $shortcode,
        'PhoneNumber'       => $phone,
        'CallBackURL'       => $callback,
        'AccountReference'  => substr($accountRef, 0, 12),
        'TransactionDesc'   => substr($description, 0, 13),
    ];

    $url = mpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('STK Push request failed: ' . $err);
    }

    $data = json_decode($response, true) ?: [];
    if ($httpCode !== 200 || ($data['ResponseCode'] ?? '') !== '0') {
        $msg = $data['errorMessage']
            ?? $data['ResponseDescription']
            ?? $data['errorCode']
            ?? 'STK Push was rejected by M-Pesa.';
        throw new Exception($msg);
    }

    return $data;
}

/**
 * Query STK status from Safaricom
 */
function mpesaQueryStatus(string $checkoutRequestId): array
{
    $timestamp = date('YmdHis');
    $token = mpesaAccessToken();
    $shortcode = mpesaConfig('shortcode');

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password'          => mpesaPassword($timestamp),
        'Timestamp'         => $timestamp,
        'CheckoutRequestID' => $checkoutRequestId,
    ];

    $url = mpesaBaseUrl() . '/mpesa/stkpushquery/v1/query';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response ?: '{}', true) ?: [];
}

// -------------------------------------------------------
// Route actions
// -------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$input  = $method === 'GET' ? $_GET : getJsonInput();
$action = strtolower((string) ($input['action'] ?? 'stk'));

if ($action === 'status' && $method === 'GET') {
    $user = requireLogin();
    $checkoutId = trim((string) ($input['checkout_request_id'] ?? ''));
    $localId = (int) ($input['id'] ?? 0);

    if ($checkoutId === '' && $localId <= 0) {
        jsonResponse(false, 'checkout_request_id or id is required.', null, 400);
    }

    try {
        if ($localId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM mpesa_transactions WHERE id = ? LIMIT 1");
            $stmt->execute([$localId]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM mpesa_transactions WHERE checkout_request_id = ? LIMIT 1");
            $stmt->execute([$checkoutId]);
        }
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) {
            jsonResponse(false, 'Transaction not found.', null, 404);
        }

        // If still pending, optionally query Safaricom
        if ($tx['status'] === 'pending' && !empty($tx['checkout_request_id'])) {
            $remote = mpesaQueryStatus($tx['checkout_request_id']);
            $resultCode = $remote['ResultCode'] ?? null;
            if ($resultCode !== null && (string) $resultCode !== '0' && (string) $resultCode !== '') {
                // 0 = success on query when completed; non-zero may mean still processing or failed
            }
            // Prefer local status updated by callback; just return current row
        }

        jsonResponse(true, 'Status retrieved.', [
            'id'                  => (int) $tx['id'],
            'status'              => $tx['status'],
            'amount'              => (float) $tx['amount'],
            'phone'               => $tx['phone'],
            'mpesa_receipt'       => $tx['mpesa_receipt'],
            'checkout_request_id' => $tx['checkout_request_id'],
            'result_desc'         => $tx['result_desc'],
            'invoice_id'          => $tx['invoice_id'] ? (int) $tx['invoice_id'] : null,
        ], 200);
    } catch (Throwable $e) {
        jsonResponse(false, $e->getMessage(), null, 400);
    }
}

// Default: initiate STK
if ($method !== 'POST') {
    jsonResponse(false, 'Method not allowed. Use POST to initiate STK Push.', null, 405);
}

$user = requireLogin();

// Staff need mpesa permission; guests may pay own invoices only (checked below)
if (isStaffRole($user['role'] ?? '') && !userCan($user, 'mpesa')) {
    apiError('You do not have permission to initiate M-Pesa payments.', 403, 'FORBIDDEN');
}

$invoiceId = (int) ($input['invoice_id'] ?? 0);
$phone     = trim((string) ($input['phone'] ?? ''));
$amount    = (float) ($input['amount'] ?? 0);

if ($invoiceId <= 0) {
    jsonResponse(false, 'Invoice ID is required.', null, 400);
}
if ($phone === '') {
    jsonResponse(false, 'M-Pesa phone number is required.', null, 400);
}
if ($amount <= 0) {
    jsonResponse(false, 'Amount must be greater than zero.', null, 400);
}

try {
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }

    $invStmt = $pdo->prepare("
        SELECT i.*, b.id AS booking_id, b.user_id AS booking_user_id, b.guest_name, r.room_number
        FROM invoices i
        INNER JOIN bookings b ON b.id = i.booking_id
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE i.id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $invStmt->execute([$invoiceId]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception('Invoice not found.');
    }
    if ($invoice['status'] === 'cancelled') {
        throw new Exception('This invoice has been cancelled.');
    }

    // Guests may only pay invoices for their own bookings
    if (($user['role'] ?? '') !== 'admin') {
        $ownerId = (int)($invoice['booking_user_id'] ?? 0);
        if ($ownerId !== (int)$user['id']) {
            throw new Exception('You can only pay invoices for your own bookings.');
        }
    }

    $balance = max(0, (float) $invoice['balance_due']);
    if ($amount > $balance + 0.001) {
        throw new Exception('Amount cannot exceed outstanding balance of KSh ' . number_format($balance, 2));
    }

    $accountRef = 'INV' . $invoiceId;
    $desc = 'Room ' . ($invoice['room_number'] ?? '');

    $stk = mpesaStkPush($phone, $amount, $accountRef, $desc);

    $merchantId = $stk['MerchantRequestID'] ?? null;
    $checkoutId = $stk['CheckoutRequestID'] ?? null;

    $ins = $pdo->prepare("
        INSERT INTO mpesa_transactions (
            invoice_id, booking_id, phone, amount,
            account_reference, transaction_desc,
            merchant_request_id, checkout_request_id,
            status, recorded_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $ins->execute([
        $invoiceId,
        $invoice['booking_id'] ?? null,
        mpesaNormalizePhone($phone),
        round($amount, 2),
        $accountRef,
        $desc,
        $merchantId,
        $checkoutId,
        $user['id']
    ]);
    $txId = (int) $pdo->lastInsertId();

    $pdo->commit();

    jsonResponse(true, 'STK Push sent. Ask the guest to enter their M-Pesa PIN on their phone.', [
        'transaction_id'      => $txId,
        'checkout_request_id' => $checkoutId,
        'merchant_request_id' => $merchantId,
        'phone'               => mpesaNormalizePhone($phone),
        'amount'              => round($amount, 2),
        'invoice_id'          => $invoiceId,
        'customer_message'    => $stk['CustomerMessage'] ?? 'Check your phone for the M-Pesa prompt.',
    ], 200);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}
