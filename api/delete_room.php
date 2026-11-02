<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * DELETE ROOM API
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

$input =
    getJsonInput();


$roomId =
    filter_var(
        $input['id'] ?? null,
        FILTER_VALIDATE_INT
    );


if (!$roomId) {

    jsonResponse(
        false,
        'Invalid room ID.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| CHECK ROOM
|--------------------------------------------------------------------------
*/

try {

    $stmt =
        $pdo->prepare("
            SELECT
                id,
                room_number
            FROM rooms
            WHERE id = ?
            LIMIT 1
        ");


    $stmt->execute([
        $roomId
    ]);


    $room =
        $stmt->fetch();


    if (!$room) {

        jsonResponse(
            false,
            'Room not found.',
            null,
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK BOOKING HISTORY
    |--------------------------------------------------------------------------
    */

    $bookingCheck =
        $pdo->prepare("
            SELECT COUNT(*)
            FROM bookings
            WHERE room_id = ?
        ");


    $bookingCheck->execute([
        $roomId
    ]);


    $bookingCount =
        (int) $bookingCheck->fetchColumn();


    if ($bookingCount > 0) {

        jsonResponse(

            false,

            'This room cannot be deleted because it has booking history.',

            [

                'booking_count' =>
                    $bookingCount

            ],

            409

        );
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE ROOM
    |--------------------------------------------------------------------------
    */

    $delete =
        $pdo->prepare("
            DELETE FROM rooms
            WHERE id = ?
        ");


    $delete->execute([
        $roomId
    ]);


    jsonResponse(

        true,

        'Room deleted successfully.',

        [

            'id' =>
                (int) $roomId,

            'room_number' =>
                $room['room_number']

        ],

        200

    );


} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to delete room.',
        $e->getMessage(),
        500
    );
}