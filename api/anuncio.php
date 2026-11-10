<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $announcement = readJsonFile(storagePath('announcement.json'), null);
    if (!is_array($announcement) || empty($announcement['active'])) {
        echo json_encode(['announcement' => null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    echo json_encode([
        'announcement' => [
            'title' => (string) ($announcement['title'] ?? ''),
            'message' => (string) ($announcement['message'] ?? ''),
            'image' => 'api/imagen-anuncio.php',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | RuntimeException $exception) {
    error_log('Entresueño announcement API failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo cargar el anuncio.'], JSON_UNESCAPED_UNICODE);
}
