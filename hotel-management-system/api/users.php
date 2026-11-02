<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * USERS API
 * ADMIN ONLY
 * ==========================================
 */

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(
        false,
        'Method Not Allowed.',
        null,
        405
    );
}

$admin = requirePermission('users');

try {

    $stmt = $pdo->query("
        SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            u.created_at,
            COUNT(b.id) AS booking_count
        FROM users u
        LEFT JOIN bookings b
            ON b.user_id = u.id
        GROUP BY
            u.id,
            u.name,
            u.email,
            u.role,
            u.created_at
        ORDER BY
            u.created_at DESC,
            u.id DESC
    ");

    $users = $stmt->fetchAll();

    foreach ($users as &$user) {
        $user['id'] = (int) $user['id'];
        $user['booking_count'] = (int) $user['booking_count'];
    }

    unset($user);

    jsonResponse(
        true,
        'Users retrieved successfully.',
        $users,
        200
    );

} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to load users.',
        $e->getMessage(),
        500
    );
}