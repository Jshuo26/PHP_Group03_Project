<?php

function validatePublicImageUpload(array $file, string $purpose, int $maxBytes): ?array
{
    $messages = [
        'profile picture' => 'Please choose a JPG, PNG, or WebP profile picture up to 2 MB.',
        'book cover' => 'Please choose a JPG, PNG, or WebP book cover up to 5 MB.',
    ];
    $message = $messages[$purpose] ?? 'Please choose a supported image file.';

    foreach (['error', 'tmp_name', 'size', 'name'] as $key) {
        if (!array_key_exists($key, $file) || !is_scalar($file[$key])) {
            throw new RuntimeException($message);
        }
    }

    $error = (int) $file['error'];
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('The image is too large. ' . ($purpose === 'book cover' ? 'Book covers may be up to 5 MB.' : 'Profile pictures may be up to 2 MB.'));
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException($message);
    }

    $tmpPath = (string) $file['tmp_name'];
    $declaredSize = (int) $file['size'];
    $actualSize = filesize($tmpPath);
    if ($declaredSize < 1 || $declaredSize > $maxBytes || $actualSize === false
        || $actualSize < 1 || $actualSize > $maxBytes || $declaredSize !== $actualSize) {
        throw new RuntimeException($message);
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $extensionMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    if (!isset($extensionMap[$extension])) {
        throw new RuntimeException($message);
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('Image uploads are temporarily unavailable.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    if (!is_string($mime) || $mime !== $extensionMap[$extension]) {
        throw new RuntimeException($message);
    }

    $imageInfo = @getimagesize($tmpPath);
    if (!is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== $mime
        || !isset($imageInfo[0], $imageInfo[1])) {
        throw new RuntimeException($message);
    }
    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];
    if ($width < 1 || $height < 1 || $width > 5000 || $height > 5000
        || $width * $height > 12000000) {
        throw new RuntimeException('Image dimensions must not exceed 5,000 pixels per side or 12 megapixels.');
    }

    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('Image uploads are temporarily unavailable.');
    }
    $image = @imagecreatefromstring((string) file_get_contents($tmpPath));
    if ($image === false) {
        throw new RuntimeException($message);
    }

    ob_start();
    $written = false;
    try {
        if ($mime === 'image/jpeg') {
            $written = imagejpeg($image, null, 88);
        } elseif ($mime === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $written = imagepng($image, null, 6);
        } elseif ($mime === 'image/webp') {
            if (function_exists('imagewebp')) {
                $written = imagewebp($image, null, 86);
            } else {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $written = imagepng($image, null, 6);
            }
        }
        $bytes = ob_get_contents();
    } finally {
        ob_end_clean();
        imagedestroy($image);
    }

    if (!$written || !is_string($bytes) || $bytes === '' || strlen($bytes) > $maxBytes) {
        throw new RuntimeException('The image could not be safely processed. Try a smaller image.');
    }

    $normalizedExtension = $mime === 'image/jpeg' ? 'jpg'
        : (($mime === 'image/webp' && !function_exists('imagewebp')) ? 'png'
            : ($mime === 'image/png' ? 'png' : 'webp'));
    return ['bytes' => $bytes, 'extension' => $normalizedExtension];
}

function savePublicImageUpload(array $image, string $directory): array
{
    if (!isset($image['bytes'], $image['extension']) || !is_string($image['bytes'])
        || !in_array($image['extension'], ['jpg', 'png', 'webp'], true)) {
        throw new RuntimeException('The image could not be saved.');
    }

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The image could not be saved.');
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $filename = bin2hex(random_bytes(16)) . '.' . $image['extension'];
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            continue;
        }

        $length = strlen($image['bytes']);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($handle, substr($image['bytes'], $written));
            if ($count === false || $count === 0) {
                break;
            }
            $written += $count;
        }
        fclose($handle);

        if ($written !== $length) {
            @unlink($path);
            throw new RuntimeException('The image could not be saved.');
        }

        @chmod($path, 0644);
        return ['filename' => $filename, 'path' => $path];
    }

    throw new RuntimeException('The image could not be saved.');
}

function appProfilePicturePath(string $storedPath): ?string
{
    if (!preg_match('~^/uploads/profile-pictures/([a-f0-9]{32})\.(jpg|png|webp)$~D', $storedPath, $matches)) {
        return null;
    }

    $directory = realpath(__DIR__ . '/../uploads/profile-pictures');
    if ($directory === false) {
        return null;
    }

    $path = $directory . DIRECTORY_SEPARATOR . $matches[1] . '.' . $matches[2];
    $realPath = realpath($path);
    return $realPath !== false && is_file($realPath)
        && str_starts_with($realPath, $directory . DIRECTORY_SEPARATOR)
        ? $realPath
        : null;
}
