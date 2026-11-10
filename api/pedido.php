<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

startCustomerSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    http_response_code(415);
    echo json_encode(['error' => 'El pedido debe enviarse en formato JSON.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    expireUnpaidOrders();
    $wompi = wompiConfiguration();
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 32768) {
        throw new InvalidArgumentException('El pedido está vacío o excede el tamaño permitido.');
    }
    $request = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new InvalidArgumentException('El formato del pedido no es válido.');
    }

    $order = createCustomerOrder($request);
    $amountInCents = $order['total'] * 100;
    $signature = hash(
        'sha256',
        $order['reference'] . $amountInCents . 'COP' . $wompi['integrity_secret']
    );
    echo json_encode([
        'order' => $order,
        'checkout' => [
            'public_key' => $wompi['public_key'],
            'reference' => $order['reference'],
            'amount_in_cents' => $amountInCents,
            'currency' => 'COP',
            'integrity_signature' => $signature,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    error_log('Entresueño order database failure: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo guardar el pedido en este momento. Inténtalo de nuevo más tarde.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    if (str_starts_with($exception->getMessage(), 'Uno de los productos ya no tiene esa cantidad disponible.')) {
        http_response_code(409);
        echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        error_log('ARAS order/payment configuration failure: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Los pagos no están disponibles en este momento. Inténtalo más tarde.'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $exception) {
    error_log('Entresueño order creation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo preparar el pedido y su pago. Revisa la configuración de Wompi o inténtalo de nuevo más tarde.'], JSON_UNESCAPED_UNICODE);
}
