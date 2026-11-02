<?php
/**
 * Production security helpers: CSRF, rate limiting
 */
declare(strict_types=1);

/**
 * Ensure CSRF token exists in session; return it.
 */
function csrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF from header X-CSRF-Token or body csrf_token.
 * Safe methods (GET, HEAD, OPTIONS) skip validation.
 */
function requireCsrf(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    // Allow disabling for local/dev if needed
    if (defined('CSRF_ENABLED') && !CSRF_ENABLED) {
        return;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    // Prefer header only so we do not consume php://input before getJsonInput()
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' && !empty($_POST['csrf_token'])) {
        $provided = (string)$_POST['csrf_token'];
    }

    if ($sessionToken === '' || $provided === '' || !hash_equals($sessionToken, $provided)) {
        apiError('Invalid or missing security token. Please refresh the page and try again.', 403, 'CSRF_FAILED');
    }
}

/**
 * Simple file-based rate limiter.
 * @return true if allowed, false if blocked
 */
function rateLimitAllow(string $key, int $maxAttempts, int $windowSeconds): bool
{
    $dir = __DIR__ . '/../logs/rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    $now = time();
    $data = ['attempts' => [], 'blocked_until' => 0];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $parsed = json_decode($raw ?: '', true);
        if (is_array($parsed)) {
            $data = array_merge($data, $parsed);
        }
    }

    if (!empty($data['blocked_until']) && $now < (int)$data['blocked_until']) {
        return false;
    }

    // Drop old attempts outside window
    $data['attempts'] = array_values(array_filter(
        $data['attempts'] ?? [],
        fn($t) => ($now - (int)$t) < $windowSeconds
    ));

    if (count($data['attempts']) >= $maxAttempts) {
        $data['blocked_until'] = $now + $windowSeconds;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return false;
    }

    $data['attempts'][] = $now;
    $data['blocked_until'] = 0;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function rateLimitReset(string $key): void
{
    $dir = __DIR__ . '/../logs/rate_limit';
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

function clientIp(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

/**
 * Security headers for API responses
 */
function sendSecurityHeaders(): void
{
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
}
