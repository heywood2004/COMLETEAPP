<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * DELETE USER API
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

if (!$userId) {
    jsonResponse(
        false,
        'Invalid user ID.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| PREVENT SELF-DELETION
|--------------------------------------------------------------------------
*/

if ((int) $admin['id'] === (int) $userId) {
    jsonResponse(
        false,
        'You cannot delete your own account.',
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
    | PREVENT DELETING LAST ADMIN
    |--------------------------------------------------------------------------
    */

    if ($user['role'] === 'admin') {

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
                'The last administrator cannot be deleted.',
                null,
                409
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE USER
    |--------------------------------------------------------------------------
    |
    | bookings.user_id uses ON DELETE SET NULL,
    | so existing booking records are preserved.
    |--------------------------------------------------------------------------
    */

    $delete = $pdo->prepare("
        DELETE FROM users
        WHERE id = ?
    ");

    $delete->execute([
        $userId
    ]);


    jsonResponse(
        true,
        'User deleted successfully.',
        [
            'user_id' => (int) $userId,
            'name' => $user['name'],
            'email' => $user['email']
        ],
        200
    );

} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to delete user.',
        $e->getMessage(),
        500
    );
}