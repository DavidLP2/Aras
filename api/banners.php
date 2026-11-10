<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $savedBanners = array_filter(siteBanners(), static fn (array $banner): bool => $banner['active']);
    $banners = array_values(array_map(
        static fn (array $banner): array => [
            'id' => $banner['id'],
            'text' => $banner['text'],
            'link' => $banner['link'],
            'image' => 'api/imagen-banner.php?id=' . rawurlencode($banner['id'])
                . '&v=' . rawurlencode(pathinfo($banner['image'], PATHINFO_FILENAME)),
        ],
        $savedBanners
    ));
    if ($banners === []) {
        $hiddenDemoIds = hiddenDemoSiteBannerIds();
        $banners = array_values(array_map(
            static fn (array $banner): array => $banner + ['demo' => true],
            array_filter(
                demoSiteBanners(),
                static fn (array $banner): bool => !in_array($banner['id'], $hiddenDemoIds, true)
            )
        ));
    }

    echo json_encode(['banners' => $banners], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | RuntimeException | TypeError | Error $exception) {
    error_log('ARAS banner API failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudieron cargar los banners.'], JSON_UNESCAPED_UNICODE);
}
