<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

requireAdmin('products');

try {
    $workbook = buildProductExportWorkbook(readProducts(), productCategories());
    $fileName = 'productos-aras-' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($workbook));
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $workbook;
} catch (JsonException | RuntimeException | Error $exception) {
    error_log('ARAS product export failed: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo exportar el catálogo de productos. Inténtalo de nuevo más tarde.';
}
