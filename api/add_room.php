<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * ADD ROOM API
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


/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

$admin = requirePermission('rooms_write');


/*
|--------------------------------------------------------------------------
| GET JSON INPUT
|--------------------------------------------------------------------------
*/

$input = getJsonInput();


$roomNumber =
    trim($input['room_number'] ?? '');


$roomType =
    trim($input['room_type'] ?? '');


$price =
    $input['price'] ?? null;


$capacity =
    $input['capacity'] ?? null;


$description =
    trim($input['description'] ?? '');


/*
|--------------------------------------------------------------------------
| VALIDATE ROOM NUMBER
|--------------------------------------------------------------------------
*/

if ($roomNumber === '') {

    jsonResponse(
        false,
        'Room number is required.',
        null,
        400
    );
}


if (strlen($roomNumber) > 20) {

    jsonResponse(
        false,
        'Room number is too long.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| VALIDATE ROOM TYPE
|--------------------------------------------------------------------------
*/

if ($roomType === '') {

    jsonResponse(
        false,
        'Room type is required.',
        null,
        400
    );
}


if (strlen($roomType) > 50) {

    jsonResponse(
        false,
        'Room type is too long.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| VALIDATE PRICE
|--------------------------------------------------------------------------
*/

if (
    !is_numeric($price) ||
    (float) $price < 0
) {

    jsonResponse(
        false,
        'Please provide a valid room price.',
        null,
        400
    );
}


$price =
    round(
        (float) $price,
        2
    );


/*
|--------------------------------------------------------------------------
| VALIDATE CAPACITY
|--------------------------------------------------------------------------
*/

if (
    filter_var(
        $capacity,
        FILTER_VALIDATE_INT
    ) === false ||
    (int) $capacity < 1
) {

    jsonResponse(
        false,
        'Capacity must be at least 1.',
        null,
        400
    );
}


$capacity =
    (int) $capacity;


/*
|--------------------------------------------------------------------------
| VALIDATE DESCRIPTION
|--------------------------------------------------------------------------
*/

if (strlen($description) > 1000) {

    jsonResponse(
        false,
        'Description is too long.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| INSERT ROOM
|--------------------------------------------------------------------------
*/

try {

    $check =
        $pdo->prepare("
            SELECT id
            FROM rooms
            WHERE room_number = ?
            LIMIT 1
        ");

    $check->execute([
        $roomNumber
    ]);


    if ($check->fetch()) {

        jsonResponse(
            false,
            'A room with this room number already exists.',
            null,
            409
        );
    }


    $insert =
        $pdo->prepare("
            INSERT INTO rooms (
                room_number,
                room_type,
                price,
                capacity,
                description
            )
            VALUES (?, ?, ?, ?, ?)
        ");


    $insert->execute([

        $roomNumber,

        $roomType,

        $price,

        $capacity,

        $description !== ''
            ? $description
            : null

    ]);


    $roomId =
        (int) $pdo->lastInsertId();


    jsonResponse(

        true,

        'Room added successfully.',

        [

            'id' =>
                $roomId,

            'room_number' =>
                $roomNumber,

            'room_type' =>
                $roomType,

            'price' =>
                number_format(
                    $price,
                    2,
                    '.',
                    ''
                ),

            'capacity' =>
                $capacity,

            'description' =>
                $description

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
            'A room with this room number already exists.',
            null,
            409
        );
    }


    jsonResponse(
        false,
        'Unable to add room.',
        $e->getMessage(),
        500
    );
}