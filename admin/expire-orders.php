<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$environment = $argv[1] ?? '';
if (!in_array($environment, ['local', 'production'], true)) {
    fwrite(STDERR, 'Indica el entorno: local o production.' . PHP_EOL);
    exit(2);
}
$_SERVER['SERVER_NAME'] = $environment === 'local' ? 'localhost' : 'entresueno.shop';

require_once dirname(__DIR__) . '/includes/admin-common.php';

try {
    $expiredCount = expireUnpaidOrders();
    fwrite(STDOUT, 'Pedidos vencidos: ' . $expiredCount . PHP_EOL);
} catch (Throwable $exception) {
    error_log('ARAS scheduled order expiration failed: ' . $exception->getMessage());
    fwrite(STDERR, 'No se pudieron vencer los pedidos: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
