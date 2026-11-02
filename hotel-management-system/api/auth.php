<?php
/**
 * Authentication + staff role permissions
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_start();
}

if (function_exists('csrfToken')) {
    csrfToken();
}

function getCurrentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array
{
    $user = getCurrentUser();

    if ($user === null) {
        apiError('Authentication required. Please log in.', 401, 'UNAUTHORIZED');
    }

    if (empty($user['id']) || empty($user['role'])) {
        $_SESSION = [];
        apiError('Session expired. Please log in again.', 401, 'SESSION_INVALID');
    }

    return $user;
}

/** Staff roles (not regular guest users) */
function isStaffRole(string $role): bool
{
    return in_array($role, ['admin', 'manager', 'receptionist', 'accountant'], true);
}

/**
 * Permission matrix
 */
function rolePermissions(string $role): array
{
    $all = [
        'dashboard', 'rooms_read', 'rooms_write', 'bookings', 'walkin',
        'checkin', 'checkout', 'guests', 'payments', 'mpesa', 'invoices',
        'reports', 'night_audit', 'housekeeping', 'inventory', 'users',
        'settings', 'media'
    ];

    return match ($role) {
        'admin' => $all,
        'manager' => array_values(array_diff($all, [])), // full ops; can restrict later
        'receptionist' => [
            'dashboard', 'rooms_read', 'bookings', 'walkin', 'checkin', 'checkout',
            'guests', 'invoices', 'housekeeping', 'night_audit'
        ],
        'accountant' => [
            'dashboard', 'rooms_read', 'bookings', 'payments', 'mpesa', 'invoices',
            'reports', 'night_audit'
        ],
        default => [], // guest user
    };
}

function userCan(array $user, string $permission): bool
{
    $role = $user['role'] ?? 'user';
    if ($role === 'admin') {
        return true;
    }
    return in_array($permission, rolePermissions($role), true);
}

function requirePermission(string $permission): array
{
    $user = requireLogin();
    if (!userCan($user, $permission)) {
        apiError('You do not have permission to perform this action.', 403, 'FORBIDDEN');
    }
    return $user;
}

function requireAdmin(): array
{
    return requirePermission('users'); // admin/manager level for user management
}

function requireStaff(): array
{
    $user = requireLogin();
    if (!isStaffRole($user['role'] ?? '')) {
        apiError('Staff access required.', 403, 'FORBIDDEN');
    }
    return $user;
}
