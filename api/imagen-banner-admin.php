<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
requireAdmin('announcement');

try {
    $bannerId = $_GET['id'] ?? '';
    if (!is_string($bannerId) || !preg_match('/^[a-f0-9]{32}$/', $bannerId)) {
        http_response_code(404);
        exit;
    }

    $banner = null;
    foreach (siteBanners() as $candidate) {
        if ($candidate['id'] === $bannerId) {
            $banner = $candidate;
            break;
        }
    }
    if ($banner === null) {
        http_response_code(404);
        exit;
    }

    $path = storagePath('uploads') . DIRECTORY_SEPARATOR . $banner['image'];
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/webp');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (JsonException | RuntimeException | TypeError | Error $exception) {
    error_log('ARAS admin banner image API failed: ' . $exception->getMessage());
    http_response_code(500);
}
