<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * FIRST ADMINISTRATOR CREATION API
 * ==========================================
 *
 * This endpoint can only create an administrator
 * when there are currently ZERO admin accounts.
 *
 * Once the first admin exists, this endpoint
 * becomes permanently unavailable until an
 * administrator is created manually again.
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
| GET JSON INPUT
|--------------------------------------------------------------------------
*/

$input = getJsonInput();


$name =
    trim(
        $input['name'] ?? ''
    );


$email =
    strtolower(
        trim(
            $input['email'] ?? ''
        )
    );


$password =
    $input['password'] ?? '';


$confirmPassword =
    $input['confirm_password'] ?? '';


/*
|--------------------------------------------------------------------------
| VALIDATE NAME
|--------------------------------------------------------------------------
*/

if ($name === '') {

    jsonResponse(
        false,
        'Full name is required.',
        null,
        400
    );

    exit;
}


if (strlen($name) < 2) {

    jsonResponse(
        false,
        'Name must contain at least 2 characters.',
        null,
        400
    );

    exit;
}


if (strlen($name) > 100) {

    jsonResponse(
        false,
        'Name is too long.',
        null,
        400
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| VALIDATE EMAIL
|--------------------------------------------------------------------------
*/

if (!filter_var(
    $email,
    FILTER_VALIDATE_EMAIL
)) {

    jsonResponse(
        false,
        'Please provide a valid email address.',
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

if (strlen($password) < 8) {

    jsonResponse(
        false,
        'Password must contain at least 8 characters.',
        null,
        400
    );

    exit;
}


if (strlen($password) > 255) {

    jsonResponse(
        false,
        'Password is too long.',
        null,
        400
    );

    exit;
}


if ($password !== $confirmPassword) {

    jsonResponse(
        false,
        'Passwords do not match.',
        null,
        400
    );

    exit;
}


try {

    /*
    |--------------------------------------------------------------------------
    | CHECK WHETHER AN ADMIN ALREADY EXISTS
    |--------------------------------------------------------------------------
    */

    $adminCheck =
        $pdo->query("
            SELECT COUNT(*)
            FROM users
            WHERE role = 'admin'
        ");


    $adminCount =
        (int) $adminCheck->fetchColumn();


    if ($adminCount > 0) {

        jsonResponse(
            false,
            'Administrator setup is already completed.',
            null,
            403
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK EMAIL
    |--------------------------------------------------------------------------
    */

    $emailCheck =
        $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");


    $emailCheck->execute([
        $email
    ]);


    if ($emailCheck->fetch()) {

        jsonResponse(
            false,
            'An account with this email already exists.',
            null,
            409
        );

        exit;
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

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE ADMIN
    |--------------------------------------------------------------------------
    */

    $insert =
        $pdo->prepare("
            INSERT INTO users (
                name,
                email,
                password_hash,
                role
            )
            VALUES (?, ?, ?, 'admin')
        ");


    $insert->execute([

        $name,

        $email,

        $passwordHash

    ]);


    $adminId =
        (int) $pdo->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    jsonResponse(

        true,

        'Administrator account created successfully.',

        [

            'user_id' =>
                $adminId,

            'name' =>
                $name,

            'email' =>
                $email,

            'role' =>
                'admin'

        ],

        201

    );


} catch (PDOException $e) {

    if (
        isset($e->errorInfo[1]) &&
        (int) $e->errorInfo[1] === 1062
    ) {

        jsonResponse(
            false,
            'An account with this email already exists.',
            null,
            409
        );

        exit;
    }


    jsonResponse(
        false,
        'Unable to create administrator account.',
        $e->getMessage(),
        500
    );
}