<?php

require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}


header('Content-Type: application/json');

try {

    $user = requirePermission('guests');

    $method = $_SERVER['REQUEST_METHOD'];

    /*
     * =====================================================
     * GET
     * =====================================================
     *
     * Admin:
     *     Returns all guests.
     *
     * User:
     *     Returns guests associated with their bookings.
     */

    if ($method === 'GET') {

        if ($user['role'] === 'admin') {

            $stmt = $pdo->query("
                SELECT
                    g.id,
                    g.full_name,
                    g.phone,
                    g.email,
                    g.id_number,
                    g.address,
                    g.nationality,
                    g.notes,
                    g.created_at,
                    g.updated_at,

                    COUNT(DISTINCT b.id) AS booking_count

                FROM guests g

                LEFT JOIN bookings b
                    ON b.guest_id = g.id

                GROUP BY
                    g.id,
                    g.full_name,
                    g.phone,
                    g.email,
                    g.id_number,
                    g.address,
                    g.nationality,
                    g.notes,
                    g.created_at,
                    g.updated_at

                ORDER BY g.created_at DESC
            ");

        } else {

            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    g.id,
                    g.full_name,
                    g.phone,
                    g.email,
                    g.id_number,
                    g.address,
                    g.nationality,
                    g.notes,
                    g.created_at,
                    g.updated_at

                FROM guests g

                INNER JOIN bookings b
                    ON b.guest_id = g.id

                WHERE b.user_id = ?

                ORDER BY g.created_at DESC
            ");

            $stmt->execute([
                $user['id']
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Guests retrieved successfully.',
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

        exit;
    }


    /*
     * =====================================================
     * POST
     * =====================================================
     *
     * Creates a guest profile.
     */

    if ($method === 'POST') {

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            throw new Exception(
                'Invalid request data.'
            );
        }

        $fullName = trim(
            $input['full_name'] ?? ''
        );

        $phone = trim(
            $input['phone'] ?? ''
        );

        $email = trim(
            $input['email'] ?? ''
        );

        $idNumber = trim(
            $input['id_number'] ?? ''
        );

        $address = trim(
            $input['address'] ?? ''
        );

        $nationality = trim(
            $input['nationality'] ?? ''
        );

        $notes = trim(
            $input['notes'] ?? ''
        );


        if ($fullName === '') {
            throw new Exception(
                'Guest full name is required.'
            );
        }


        if (
            $email !== '' &&
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new Exception(
                'Please enter a valid guest email.'
            );
        }


        /*
         * Try to locate an existing guest.
         */

        $existing = null;

        if ($idNumber !== '') {

            $stmt = $pdo->prepare("
                SELECT id
                FROM guests
                WHERE id_number = ?
                LIMIT 1
            ");

            $stmt->execute([
                $idNumber
            ]);

            $existing = $stmt->fetch(
                PDO::FETCH_ASSOC
            );
        }


        if (
            !$existing &&
            $email !== ''
        ) {

            $stmt = $pdo->prepare("
                SELECT id
                FROM guests
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([
                $email
            ]);

            $existing = $stmt->fetch(
                PDO::FETCH_ASSOC
            );
        }


        /*
         * Update an existing guest.
         */

        if ($existing) {

            $stmt = $pdo->prepare("
                UPDATE guests
                SET
                    full_name = ?,
                    phone = ?,
                    email = ?,
                    id_number = ?,
                    address = ?,
                    nationality = ?,
                    notes = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $fullName,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
                $idNumber !== '' ? $idNumber : null,
                $address !== '' ? $address : null,
                $nationality !== '' ? $nationality : null,
                $notes !== '' ? $notes : null,
                $existing['id']
            ]);

            $guestId = (int) $existing['id'];

            echo json_encode([
                'success' => true,
                'message' => 'Guest updated successfully.',
                'data' => [
                    'guest_id' => $guestId
                ]
            ]);

            exit;
        }


        /*
         * Create a new guest.
         */

        $stmt = $pdo->prepare("
            INSERT INTO guests
            (
                full_name,
                phone,
                email,
                id_number,
                address,
                nationality,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $fullName,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
            $idNumber !== '' ? $idNumber : null,
            $address !== '' ? $address : null,
            $nationality !== '' ? $nationality : null,
            $notes !== '' ? $notes : null
        ]);

        $guestId = (int) $pdo->lastInsertId();


        echo json_encode([
            'success' => true,
            'message' => 'Guest created successfully.',
            'data' => [
                'guest_id' => $guestId
            ]
        ]);

        exit;
    }


    /*
     * =====================================================
     * PUT
     * =====================================================
     */

    if ($method === 'PUT') {

        if ($user['role'] !== 'admin') {

            http_response_code(403);

            echo json_encode([
                'success' => false,
                'message' => 'Administrator access required.'
            ]);

            exit;
        }


        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        $guestId = (int) (
            $input['id'] ?? 0
        );

        if ($guestId <= 0) {
            throw new Exception(
                'Invalid guest ID.'
            );
        }


        $stmt = $pdo->prepare("
            UPDATE guests
            SET
                full_name = ?,
                phone = ?,
                email = ?,
                id_number = ?,
                address = ?,
                nationality = ?,
                notes = ?
            WHERE id = ?
        ");

        $stmt->execute([
            trim($input['full_name'] ?? ''),
            trim($input['phone'] ?? '') ?: null,
            trim($input['email'] ?? '') ?: null,
            trim($input['id_number'] ?? '') ?: null,
            trim($input['address'] ?? '') ?: null,
            trim($input['nationality'] ?? '') ?: null,
            trim($input['notes'] ?? '') ?: null,
            $guestId
        ]);


        echo json_encode([
            'success' => true,
            'message' => 'Guest updated successfully.'
        ]);

        exit;
    }


    /*
     * =====================================================
     * DELETE
     * =====================================================
     */

    if ($method === 'DELETE') {

        if ($user['role'] !== 'admin') {

            http_response_code(403);

            echo json_encode([
                'success' => false,
                'message' => 'Administrator access required.'
            ]);

            exit;
        }


        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        $guestId = (int) (
            $input['id'] ?? 0
        );


        if ($guestId <= 0) {
            throw new Exception(
                'Invalid guest ID.'
            );
        }


        /*
         * Existing booking history is preserved.
         * guest_id becomes NULL because of the FK.
         */

        $stmt = $pdo->prepare("
            DELETE FROM guests
            WHERE id = ?
        ");

        $stmt->execute([
            $guestId
        ]);


        echo json_encode([
            'success' => true,
            'message' => 'Guest deleted successfully.'
        ]);

        exit;
    }


    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

} catch (Throwable $error) {

    if (
        http_response_code() < 400
    ) {
        http_response_code(400);
    }

    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ]);
}