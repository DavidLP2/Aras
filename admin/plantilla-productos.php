<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

try {
    $workbook = buildProductImportWorkbook(productCategories());
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla-productos.xlsx"');
    header('Content-Length: ' . strlen($workbook));
    header('Cache-Control: no-store, max-age=0');
    echo $workbook;
} catch (JsonException | RuntimeException | Error $exception) {
    error_log('ARAS product workbook generation failed: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo generar la plantilla de productos. Inténtalo de nuevo más tarde.';
}
