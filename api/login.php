<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * UNIFIED LOGIN API
 * ==========================================
 */

require_once __DIR__ . '/auth.php';


/*
|--------------------------------------------------------------------------
| ONLY POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    jsonResponse(
        false,
        'Method Not Allowed.',
        null,
        405
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| GET INPUT
|--------------------------------------------------------------------------
*/

$input = getJsonInput();


$email = strtolower(
    trim($input['email'] ?? '')
);


$password =
    $input['password'] ?? '';


$loginAs =
    strtolower(
        trim(
            $input['login_as'] ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| VALIDATE EMAIL
|--------------------------------------------------------------------------
*/

if ($email === '') {

    jsonResponse(
        false,
        'Email is required.',
        null,
        400
    );

    exit;
}


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    jsonResponse(
        false,
        'Please enter a valid email address.',
        null,
        400
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| VALIDATE PASSWORD
|--------------------------------------------------------------------------
*/

if ($password === '') {

    jsonResponse(
        false,
        'Password is required.',
        null,
        400
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| VALIDATE LOGIN TYPE
|--------------------------------------------------------------------------
*/

if (!in_array(
    $loginAs,
    ['user', 'admin'],
    true
)) {

    jsonResponse(
        false,
        'Please select whether you are logging in as a user or administrator.',
        null,
        400
    );

    exit;
}


try {

    /*
    |--------------------------------------------------------------------------
    | FIND ACCOUNT
    |--------------------------------------------------------------------------
    */

    
/*
|--------------------------------------------------------------------------
| RATE LIMIT LOGIN ATTEMPTS
|--------------------------------------------------------------------------
*/
$rateKey = 'login:' . (function_exists('clientIp') ? clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) . ':' . $email;
$maxAttempts = defined('LOGIN_MAX_ATTEMPTS') ? (int) LOGIN_MAX_ATTEMPTS : 5;
$window = defined('LOGIN_WINDOW_SECONDS') ? (int) LOGIN_WINDOW_SECONDS : 900;
if (function_exists('rateLimitAllow') && !rateLimitAllow($rateKey, $maxAttempts, $window)) {
    jsonResponse(
        false,
        'Too many login attempts. Please wait 15 minutes and try again.',
        null,
        429,
        'RATE_LIMITED'
    );
}

$stmt = $pdo->prepare("
        SELECT
            id,
            name,
            email,
            password_hash,
            role
        FROM users
        WHERE email = ?
        LIMIT 1
    ");


    $stmt->execute([
        $email
    ]);


    $user =
        $stmt->fetch();


    /*
    |--------------------------------------------------------------------------
    | CHECK ACCOUNT
    |--------------------------------------------------------------------------
    */

    if (!$user) {

        jsonResponse(
            false,
            'Invalid email or password.',
            null,
            401
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY PASSWORD
    |--------------------------------------------------------------------------
    */

    if (
        !password_verify(
            $password,
            $user['password_hash']
        )
    ) {

        jsonResponse(
            false,
            'Invalid email or password.',
            null,
            401
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY SELECTED ROLE
    |--------------------------------------------------------------------------
    */

    $staffRoles = ['admin', 'manager', 'receptionist', 'accountant'];
    $isStaff = in_array($user['role'], $staffRoles, true);

    if ($loginAs === 'admin') {
        // "Admin" login option means any staff account
        if (!$isStaff) {
            jsonResponse(
                false,
                'This account is not a staff account (admin, manager, receptionist, or accountant).',
                null,
                403
            );
        }
    } else {
        // Guest login
        if ($isStaff) {
            jsonResponse(
                false,
                'This is a staff account — please select Admin when logging in.',
                null,
                403
            );
        }
        if ($user['role'] !== 'user') {
            jsonResponse(
                false,
                'This account cannot log in as a guest.',
                null,
                403
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REGENERATE SESSION
    |--------------------------------------------------------------------------
    */

    session_regenerate_id(true);
    if (function_exists('rateLimitReset')) {
        rateLimitReset($rateKey);
    }
    if (function_exists('csrfToken')) {
        unset($_SESSION['csrf_token']);
        csrfToken(); // refresh token after login
    }


    /*
    |--------------------------------------------------------------------------
    | SAVE SESSION
    |--------------------------------------------------------------------------
    */

    $_SESSION['user_id'] = (int) $user['id'];

    $_SESSION['user'] = [
        'id'    => (int) $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role']
    ];


    /*
    |--------------------------------------------------------------------------
    | RETURN USER
    |--------------------------------------------------------------------------
    */

    jsonResponse(

        true,

        'Login successful.',

        [

            'user' =>
                $_SESSION['user'],

            'csrf_token' =>
                function_exists('csrfToken') ? csrfToken() : null

        ],

        200

    );


} catch (PDOException $e) {

    jsonResponse(

        false,

        'Login failed.',

        $e->getMessage(),

        500

    );
}