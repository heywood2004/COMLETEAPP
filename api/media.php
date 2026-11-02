<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

try {
    $stmt = $pdo->query(
        "SELECT
            id,
            media_key,
            media_type,
            title,
            image_path,
            created_at,
            updated_at
         FROM hotel_media
         ORDER BY media_type, id"
    );

    $media = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, 'Media loaded successfully.', $media, 200);
} catch (Throwable $e) {
    jsonResponse(false, 'Unable to load hotel media.', null, 500);
}
