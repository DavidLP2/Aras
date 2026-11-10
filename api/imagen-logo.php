<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

try {
    $settings = readJsonFile(storagePath('site-settings.json'), []);
    $fileName = is_array($settings) ? ($settings['logo'] ?? '') : '';
    if (!is_string($fileName) || !preg_match('/^[a-f0-9]{32}\.webp$/', $fileName)) {
        http_response_code(404);
        exit;
    }

    $path = storagePath('uploads') . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/webp');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache, must-revalidate');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (JsonException | RuntimeException $exception) {
    error_log('Entresueño logo image API failed: ' . $exception->getMessage());
    http_response_code(500);
}