<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}


requirePermission('media');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);

    exit;
}

$allowedSlots = [
    'room_single' => [
        'type' => 'room',
        'title' => 'Single Room',
        'directory' => 'rooms'
    ],

    'room_double' => [
        'type' => 'room',
        'title' => 'Double Room',
        'directory' => 'rooms'
    ],

    'room_twin' => [
        'type' => 'room',
        'title' => 'Twin Room',
        'directory' => 'rooms'
    ],

    'room_deluxe' => [
        'type' => 'room',
        'title' => 'Deluxe Room',
        'directory' => 'rooms'
    ],

    'room_executive' => [
        'type' => 'room',
        'title' => 'Executive Room',
        'directory' => 'rooms'
    ],

    'room_presidential' => [
        'type' => 'room',
        'title' => 'Presidential Room',
        'directory' => 'rooms'
    ],

    'hero_1' => [
        'type' => 'hero',
        'title' => 'Hero Image 1',
        'directory' => 'heroes'
    ],

    'hero_2' => [
        'type' => 'hero',
        'title' => 'Hero Image 2',
        'directory' => 'heroes'
    ],

    'hero_3' => [
        'type' => 'hero',
        'title' => 'Hero Image 3',
        'directory' => 'heroes'
    ],

    'hero_4' => [
        'type' => 'hero',
        'title' => 'Hero Image 4',
        'directory' => 'heroes'
    ],

    'hero_5' => [
        'type' => 'hero',
        'title' => 'Hero Image 5',
        'directory' => 'heroes'
    ],

    'hero_6' => [
        'type' => 'hero',
        'title' => 'Hero Image 6',
        'directory' => 'heroes'
    ]
];

if (
    !isset($_POST['media_key']) ||
    !isset($allowedSlots[$_POST['media_key']])
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid media slot.'
    ]);

    exit;
}

$mediaKey = trim($_POST['media_key']);
$slot = $allowedSlots[$mediaKey];

if (
    !isset($_FILES['image']) ||
    !is_array($_FILES['image'])
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Please select an image.'
    ]);

    exit;
}

$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'The image upload failed.'
    ]);

    exit;
}

$maxSize = 5 * 1024 * 1024;

if ((int)$file['size'] > $maxSize) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Image must not exceed 5 MB.'
    ]);

    exit;
}

$imageInfo = @getimagesize($file['tmp_name']);

if ($imageInfo === false) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'The selected file is not a valid image.'
    ]);

    exit;
}

$mime = $imageInfo['mime'] ?? '';

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp'
];

if (!isset($allowedMimeTypes[$mime])) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Only JPG, PNG and WEBP images are supported.'
    ]);

    exit;
}

$extension = $allowedMimeTypes[$mime];

$projectPublicPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';

$relativeDirectory =
    'images' .
    DIRECTORY_SEPARATOR .
    $slot['directory'];

$uploadDirectory =
    $projectPublicPath .
    DIRECTORY_SEPARATOR .
    $relativeDirectory;

if (!is_dir($uploadDirectory)) {
    if (!mkdir($uploadDirectory, 0755, true)) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to create the image directory.'
        ]);

        exit;
    }
}

$safeKey =
    preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '',
        $mediaKey
    );

$filename =
    $safeKey .
    '_' .
    date('Ymd_His') .
    '_' .
    bin2hex(random_bytes(4)) .
    '.' .
    $extension;

$destination =
    $uploadDirectory .
    DIRECTORY_SEPARATOR .
    $filename;

if (!move_uploaded_file(
    $file['tmp_name'],
    $destination
)) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to save the uploaded image.'
    ]);

    exit;
}

$webPath =
    'images/' .
    $slot['directory'] .
    '/' .
    $filename;

try {
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }

    $findStmt = $pdo->prepare(
        "SELECT image_path
         FROM hotel_media
         WHERE media_key = ?
         LIMIT 1"
    );

    $findStmt->execute([
        $mediaKey
    ]);

    $oldMedia =
        $findStmt->fetch(
            PDO::FETCH_ASSOC
        );

    $oldPath =
        $oldMedia['image_path'] ??
        null;

    $updateStmt = $pdo->prepare(
        "INSERT INTO hotel_media
            (
                media_key,
                media_type,
                title,
                image_path
            )
         VALUES
            (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            media_type = VALUES(media_type),
            title = VALUES(title),
            image_path = VALUES(image_path)"
    );

    $updateStmt->execute([
        $mediaKey,
        $slot['type'],
        $slot['title'],
        $webPath
    ]);

    $pdo->commit();

    /*
     * Delete the previous uploaded image only when it
     * is inside our managed images directory.
     */
    if ($oldPath) {
        $oldRelative =
            str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $oldPath
            );

        $oldAbsolute =
            $projectPublicPath .
            DIRECTORY_SEPARATOR .
            $oldRelative;

        $managedImages =
            realpath(
                $projectPublicPath .
                DIRECTORY_SEPARATOR .
                'images'
            );

        $oldRealDirectory =
            realpath(
                dirname($oldAbsolute)
            );

        if (
            $managedImages &&
            $oldRealDirectory &&
            str_starts_with(
                $oldRealDirectory,
                $managedImages
            ) &&
            is_file($oldAbsolute)
        ) {
            @unlink($oldAbsolute);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $slot['title'] . ' image updated successfully.',
        'data' => [
            'media_key' => $mediaKey,
            'image_path' => $webPath
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    @unlink($destination);

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update the image.'
    ]);
}