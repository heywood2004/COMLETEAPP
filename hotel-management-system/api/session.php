<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

$user = getCurrentUser();
$token = function_exists('csrfToken') ? csrfToken() : '';

if (!$user) {
    jsonResponse(true, 'No active session.', [
        'authenticated' => false,
        'user' => null,
        'csrf_token' => $token,
    ], 200);
}

jsonResponse(true, 'Active session found.', [
    'authenticated' => true,
    'csrf_token' => $token,
    'user' => [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'phone' => $user['phone'] ?? null,
    ],
], 200);
