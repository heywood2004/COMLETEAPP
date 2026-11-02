<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * UPDATE USER API
 * ADMIN ONLY
 * ==========================================
 */

require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(
        false,
        'Method Not Allowed.',
        null,
        405
    );
}

$admin = requirePermission('users');

$input = getJsonInput();

$userId = filter_var(
    $input['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

$role = trim(
    strtolower(
        $input['role'] ?? ''
    )
);

if (!$userId) {
    jsonResponse(
        false,
        'Invalid user ID.',
        null,
        400
    );
}

if (!in_array($role, ['user', 'receptionist', 'accountant', 'manager', 'admin'], true)) {
    jsonResponse(
        false,
        'Invalid role.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| PREVENT CHANGING YOUR OWN ROLE
|--------------------------------------------------------------------------
*/

if ((int) $admin['id'] === (int) $userId) {
    jsonResponse(
        false,
        'You cannot change your own role.',
        null,
        403
    );
}

try {

    /*
    |--------------------------------------------------------------------------
    | FIND USER
    |--------------------------------------------------------------------------
    */

    $find = $pdo->prepare("
        SELECT
            id,
            name,
            email,
            role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $find->execute([
        $userId
    ]);

    $user = $find->fetch();

    if (!$user) {
        jsonResponse(
            false,
            'User not found.',
            null,
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PREVENT REMOVING LAST ADMIN
    |--------------------------------------------------------------------------
    */

    if (
        $user['role'] === 'admin' &&
        $role === 'user'
    ) {

        $countStmt = $pdo->query("
            SELECT COUNT(*)
            FROM users
            WHERE role = 'admin'
        ");

        $adminCount =
            (int) $countStmt->fetchColumn();

        if ($adminCount <= 1) {
            jsonResponse(
                false,
                'The last administrator cannot be changed to a regular user.',
                null,
                409
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE ROLE
    |--------------------------------------------------------------------------
    */

    $update = $pdo->prepare("
        UPDATE users
        SET role = ?
        WHERE id = ?
    ");

    $update->execute([
        $role,
        $userId
    ]);


    jsonResponse(
        true,
        'User role updated successfully.',
        [
            'user_id' => (int) $userId,
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $role
        ],
        200
    );

} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to update user role.',
        $e->getMessage(),
        500
    );
}