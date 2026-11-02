<?php
/**
 * ==========================================
 * Hotel Management System
 * Database Configuration + API Error Handling
 * ==========================================
 */

declare(strict_types=1);

// -----------------------------------------------------
// Environment
// -----------------------------------------------------
// Set to false in production to hide internal error details
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true);  // set false in production
}
if (!defined('CSRF_ENABLED')) {
    define('CSRF_ENABLED', true);
}
if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('LOGIN_WINDOW_SECONDS')) {
    define('LOGIN_WINDOW_SECONDS', 900); // 15 minutes
}

// -----------------------------------------------------
// Database Configuration
// -----------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'hotel_management_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application constants
define('APP_NAME', "Heywood's Hotel");
define('TAX_RATE', 16.00);
define('UPLOAD_DIR', __DIR__ . '/../uploads/media/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// -----------------------------------------------------
// M-Pesa Daraja API (STK Push)
// -----------------------------------------------------
define('MPESA_ENV', 'sandbox');
define('MPESA_CONSUMER_KEY', '');
define('MPESA_CONSUMER_SECRET', '');
define('MPESA_SHORTCODE', '174379');
define('MPESA_PASSKEY', '');
define('MPESA_CALLBACK_URL', '');

// -----------------------------------------------------
// Email notifications
// -----------------------------------------------------
// Set MAIL_ENABLED to true and configure From / SMTP to send mail.
define('MAIL_ENABLED', false);                    // flip to true when ready
define('MAIL_FROM', 'noreply@heywoodshotel.local');
define('MAIL_FROM_NAME', "Heywood's Hotel");
define('MAIL_REPLY_TO', 'front.desk@heywoodshotel.local');
// Optional SMTP (leave MAIL_SMTP_HOST empty to use PHP mail())
define('MAIL_SMTP_HOST', '');                     // e.g. smtp.gmail.com
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_USER', '');
define('MAIL_SMTP_PASS', '');
define('MAIL_SMTP_SECURE', 'tls');                // tls | ssl | ''

// -----------------------------------------------------
// SMS notifications (Africa's Talking / Twilio / webhook)
// -----------------------------------------------------
define('SMS_ENABLED', false);
define('SMS_PROVIDER', 'africastalking');   // africastalking | twilio | webhook
define('SMS_SENDER_ID', '');                // approved sender ID / Twilio From number
// Africa's Talking
define('SMS_AT_USERNAME', 'sandbox');       // sandbox username or your AT username
define('SMS_AT_API_KEY', '');
define('SMS_AT_SANDBOX', true);             // false for live AT
// Twilio
define('SMS_TWILIO_SID', '');
define('SMS_TWILIO_TOKEN', '');
// Generic
define('SMS_WEBHOOK_URL', '');



// -----------------------------------------------------
// Error logging
// -----------------------------------------------------
function apiLog(string $level, string $message, array $context = []): void
{
    $line = sprintf(
        "[%s] %s %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logDir . '/api.log', $line, FILE_APPEND);
    if (APP_DEBUG) {
        error_log(trim($line));
    }
}

/**
 * Create PDO Database Connection
 */
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false
        ]
    );
} catch (PDOException $e) {
    apiLog('error', 'Database connection failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please try again later.',
        'code'    => 'DB_CONNECTION_ERROR',
        'data'    => APP_DEBUG ? ['error' => $e->getMessage()] : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send JSON Response
 * Shape: { success, message, data, code? }
 */
function jsonResponse(bool $success, string $message, $data = null, int $statusCode = 200, ?string $code = null): void
{
    // Clear any accidental output that would break JSON
    if (ob_get_length()) {
        @ob_clean();
    }

    if (function_exists('sendSecurityHeaders')) {
        sendSecurityHeaders();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $payload = [
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ];
    if ($code !== null && $code !== '') {
        $payload['code'] = $code;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Standardized error response helpers
 */
function apiError(string $message, int $statusCode = 400, ?string $code = null, $data = null): void
{
    jsonResponse(false, $message, $data, $statusCode, $code ?? httpStatusToCode($statusCode));
}

function apiSuccess(string $message, $data = null, int $statusCode = 200): void
{
    jsonResponse(true, $message, $data, $statusCode);
}

function httpStatusToCode(int $status): string
{
    return match ($status) {
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHORIZED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        422 => 'VALIDATION_ERROR',
        429 => 'RATE_LIMITED',
        500 => 'SERVER_ERROR',
        503 => 'SERVICE_UNAVAILABLE',
        default => 'ERROR'
    };
}

/**
 * Map exceptions to safe client messages
 */
function handleApiException(Throwable $e, string $fallback = 'An unexpected error occurred.'): void
{
    if ($e instanceof PDOException) {
        apiLog('error', 'PDO exception', [
            'message' => $e->getMessage(),
            'code'    => $e->getCode()
        ]);

        // Duplicate key
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = $e->errorInfo[1] ?? 0;
        if ($sqlState === '23000' || $driverCode == 1062) {
            apiError('A record with this value already exists.', 409, 'DUPLICATE_ENTRY');
        }
        if ($sqlState === '23000' || $driverCode == 1451) {
            apiError('Cannot delete this record because it is linked to other data.', 409, 'FOREIGN_KEY_CONSTRAINT');
        }

        apiError(
            APP_DEBUG ? ('Database error: ' . $e->getMessage()) : 'A database error occurred. Please try again.',
            500,
            'DB_ERROR',
            APP_DEBUG ? ['sql_state' => $sqlState, 'driver_code' => $driverCode] : null
        );
    }

    // Validation / business logic exceptions use 400
    $msg = $e->getMessage();
    $code = (int) $e->getCode();

    // Allow throw new Exception('msg', 404) style
    if ($code >= 400 && $code < 600) {
        apiLog('warning', $msg, ['code' => $code]);
        apiError($msg, $code);
    }

    apiLog('error', 'Unhandled exception', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine()
    ]);

    apiError(
        APP_DEBUG ? $msg : $fallback,
        500,
        'SERVER_ERROR',
        APP_DEBUG ? [
            'exception' => get_class($e),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ] : null
    );
}

/**
 * Validate required fields in an array
 * @return string|null Error message or null if ok
 */
function requireFields(array $input, array $fields): ?string
{
    foreach ($fields as $field) {
        $value = $input[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return "The field '{$field}' is required.";
        }
    }
    return null;
}

/**
 * Ensure request method matches
 */
function requireMethod(string ...$methods): void
{
    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($current, $allowed, true)) {
        apiError(
            'Method not allowed. Use: ' . implode(', ', $allowed),
            405,
            'METHOD_NOT_ALLOWED'
        );
    }
}

/**
 * Read JSON Request Body (safe)
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $input = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        apiError('Invalid JSON in request body: ' . json_last_error_msg(), 400, 'INVALID_JSON');
    }
    return is_array($input) ? $input : [];
}

/**
 * Validate Date Format (YYYY-MM-DD)
 */
function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Sanitize User Input
 */
function clean(?string $value): string
{
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Global handlers so uncaught errors still return JSON
 */
set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        handleApiException($e);
    }
    apiLog('error', 'Uncaught exception', ['message' => $e->getMessage()]);
    exit;
});

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    // Convert warnings/errors to exceptions in API context
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
