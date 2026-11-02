<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * USER REGISTRATION API
 * ==========================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';


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
}


/*
|--------------------------------------------------------------------------
| GET JSON INPUT
|--------------------------------------------------------------------------
*/

$input = getJsonInput();


$name = trim(
    $input['name'] ?? ''
);

$email = strtolower(
    trim(
        $input['email'] ?? ''
    )
);

$password =
    $input['password'] ?? '';


/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if ($name === '') {

    jsonResponse(
        false,
        'Full name is required.',
        null,
        400
    );
}


if (strlen($name) < 2) {

    jsonResponse(
        false,
        'Name must contain at least 2 characters.',
        null,
        400
    );
}


if (strlen($name) > 100) {

    jsonResponse(
        false,
        'Name is too long.',
        null,
        400
    );
}


if (
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {

    jsonResponse(
        false,
        'Please enter a valid email address.',
        null,
        400
    );
}


if (strlen($password) < 8) {

    jsonResponse(
        false,
        'Password must contain at least 8 characters.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| CHECK PASSWORD
|--------------------------------------------------------------------------
*/

if (strlen($password) > 255) {

    jsonResponse(
        false,
        'Password is too long.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| CREATE ACCOUNT
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | CHECK WHETHER EMAIL ALREADY EXISTS
    |--------------------------------------------------------------------------
    */

    $check = $pdo->prepare("
        SELECT id
        FROM users
        WHERE email = ?
        LIMIT 1
    ");

    $check->execute([
        $email
    ]);


    if ($check->fetch()) {

        jsonResponse(
            false,
            'An account with this email already exists.',
            null,
            409
        );
    }


    /*
    |--------------------------------------------------------------------------
    | HASH PASSWORD
    |--------------------------------------------------------------------------
    */

    $passwordHash =
        password_hash(
            $password,
            PASSWORD_DEFAULT
        );


    if ($passwordHash === false) {

        jsonResponse(
            false,
            'Unable to secure the password.',
            null,
            500
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT USER
    |--------------------------------------------------------------------------
    |
    | Every registration creates a normal USER.
    | Admin accounts will be assigned separately.
    |
    */

    $insert = $pdo->prepare("
        INSERT INTO users (
            name,
            email,
            password_hash,
            role
        )
        VALUES (?, ?, ?, 'user')
    ");


    $insert->execute([
        $name,
        $email,
        $passwordHash
    ]);

    try {
        sendWelcomeEmail($name, $email);
    } catch (Throwable $e) {
        if (function_exists('apiLog')) {
            apiLog('warning', 'Welcome email failed', ['error' => $e->getMessage()]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RETURN SUCCESS
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Registration successful.',
        [
            'user_id' =>
                (int) $pdo->lastInsertId(),

            'name' =>
                $name,

            'email' =>
                $email,

            'role' =>
                'user'
        ],
        201
    );


} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | HANDLE DUPLICATE EMAIL
    |--------------------------------------------------------------------------
    */

    if ((int) $e->errorInfo[1] === 1062) {

        jsonResponse(
            false,
            'An account with this email already exists.',
            null,
            409
        );
    }


    jsonResponse(
        false,
        'Registration failed.',
        $e->getMessage(),
        500
    );
}