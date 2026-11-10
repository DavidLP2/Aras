<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = wompiConfiguration();
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 32768) {
        throw new InvalidArgumentException('El evento de pago está vacío o excede el tamaño permitido.');
    }
    $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($event) || ($event['event'] ?? null) !== 'transaction.updated') {
        http_response_code(200);
        echo json_encode(['received' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $expectedEnvironment = $config['environment'] === 'sandbox' ? 'test' : 'prod';
    if (($event['environment'] ?? null) !== $expectedEnvironment
        || !verifyWompiEvent($event, $config['events_secret'])) {
        http_response_code(401);
        echo json_encode(['error' => 'La firma del evento de Wompi no es válida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    expireUnpaidOrders();
    $transaction = $event['data']['transaction'] ?? null;
    if (!is_array($transaction)) {
        throw new InvalidArgumentException('El evento no contiene una transacción.');
    }
    processWompiTransactionUpdate($transaction);
    http_response_code(200);
    echo json_encode(['received' => true], JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (PDOException | RuntimeException $exception) {
    error_log('ARAS Wompi webhook failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo procesar el evento de pago.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('ARAS Wompi webhook failed unexpectedly: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo procesar el evento de pago.'], JSON_UNESCAPED_UNICODE);
}
