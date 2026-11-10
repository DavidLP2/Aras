<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

$key = $_GET['key'] ?? '';
if (!is_string($key) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
    http_response_code(404);
    exit;
}

try {
    $image = productCategoryImages()[$key] ?? null;
    if (!is_string($image)) {
        http_response_code(404);
        exit;
    }

    $path = storagePath('uploads') . DIRECTORY_SEPARATOR . $image;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/webp');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (JsonException | RuntimeException | TypeError | Error $exception) {
    error_log('ARAS category image API failed: ' . $exception->getMessage());
    http_response_code(500);
}
