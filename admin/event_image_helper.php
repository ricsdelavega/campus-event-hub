<?php

function handle_event_image_upload(
    PDO $pdo,
    ?int $eventId,
    ?array $file,
    bool $removeImage,
    ?string $currentImage
): array {
    $uploadDir = __DIR__ . '/../uploads/events/';

if ($removeImage) {
        if ($currentImage) {
            $oldPath = $uploadDir . $currentImage;
            if (is_file($oldPath)) {
@unlink($oldPath);
            }

            if ($eventId !== null) {
$stmt = $pdo->prepare(
"UPDATE events SET event_image = NULL WHERE id = :id"
);
$stmt->execute(['id' => $eventId]);
            }
        }

if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
    }

if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [$currentImage, null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The image upload failed (error code '
            . (int) $file['error'] . '). Please try again.'];
    }

    if ($file['size'] <= 0 || $file['size'] > 4 * 1024 * 1024) {
        return [null, 'The image must be between 1 byte and 4 MB.'];
    }

$info = @getimagesize($file['tmp_name']);

    if ($info === false) {
        return [null, 'The uploaded file is not a valid image '
            . '(JPG, PNG, GIF or WebP).'];
    }

$allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp'
    ];

    $type = $info[2];

    if (!isset($allowed[$type])) {
        return [null, 'Only JPG, PNG, GIF or WebP images are allowed.'];
    }

    if (!extension_loaded('gd')) {
$newName = bin2hex(random_bytes(16)) . '.' . $allowed[$type];
        $target  = $uploadDir . $newName;

        $uploadDirResolved = $uploadDir;

        if (!is_dir($uploadDirResolved)
            && !mkdir($uploadDirResolved, 0777, true)
            && !is_dir($uploadDirResolved)) {
            return [null, 'Could not create the upload folder on the server.'];
        }

        if (!@move_uploaded_file($file['tmp_name'], $target)
            && !@copy($file['tmp_name'], $target)) {
            return [null, 'Could not save the image on the server. '
. 'Check folder permissions.'];
        }

if ($currentImage) {
            $oldPath = $uploadDirResolved . $currentImage;

            if (is_file($oldPath)
&& basename($oldPath) === $currentImage) {
@unlink($oldPath);
            }
        }

        if ($eventId !== null) {
            $stmt = $pdo->prepare(
"UPDATE events SET event_image = :image WHERE id = :id"
            );
            $stmt->execute([
'image' => $newName,
'id'    => $eventId
            ]);
        }

        return [$newName, null];
    }

    if (!function_exists('imagecreatefromstring')) {
        return [null, 'The server GD image extension is not available.'];
    }

    $source = @imagecreatefromstring(
        file_get_contents($file['tmp_name'])
    );

    if ($source === false) {
        return [null, 'The image could not be processed. '
            . 'Please try a different file.'];
    }

$maxWidth  = 1920;
    $maxHeight = 1080;

    $srcWidth  = imagesx($source);
    $srcHeight = imagesy($source);

    $scale = min(
        $maxWidth / $srcWidth,
        $maxHeight / $srcHeight,
        1
    );

    $dstWidth  = (int) max(1, round($srcWidth * $scale));
    $dstHeight = (int) max(1, round($srcHeight * $scale));

    $resized = imagecreatetruecolor($dstWidth, $dstHeight);

if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
    }

    imagecopyresampled(
        $resized,
        $source,
        0,
        0,
        0,
        0,
        $dstWidth,
        $dstHeight,
        $srcWidth,
        $srcHeight
    );

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        imagedestroy($source);
        imagedestroy($resized);
        return [null, 'Could not create the upload folder on the server.'];
    }

$newName = bin2hex(random_bytes(16)) . '.' . $allowed[$type];
    $target  = $uploadDir . $newName;

    $saved = false;

    switch ($allowed[$type]) {
        case 'jpg':
            $saved = imagejpeg($resized, $target, 85);
            break;
        case 'png':
            $saved = imagepng($resized, $target, 6);
            break;
        case 'gif':
            $saved = imagegif($resized, $target);
            break;
        case 'webp':
            $saved = function_exists('imagewebp')
? imagewebp($resized, $target, 85)
: false;
            break;
    }

    imagedestroy($source);
    imagedestroy($resized);

    if (!$saved) {
        return [null, 'Could not save the image on the server. '
            . 'Check folder permissions.'];
    }

if ($currentImage) {
        $oldPath = $uploadDir . $currentImage;

        if (
            is_file($oldPath)
            && basename($oldPath) === $currentImage
        ) {
            @unlink($oldPath);
        }
    }

if ($eventId !== null) {
        $stmt = $pdo->prepare(
            "UPDATE events SET event_image = :image WHERE id = :id"
        );
        $stmt->execute([
            'image' => $newName,
            'id'    => $eventId
        ]);
    }

    return [$newName, null];
}
