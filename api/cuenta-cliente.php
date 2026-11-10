<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';

startCustomerSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function customerAccountResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    try {
        $customer = currentCustomerSession();
        customerAccountResponse([
            'authenticated' => $customer !== null,
            'user' => $customer === null
                ? null
                : ['name' => (string) $customer['name'], 'email' => (string) $customer['email']],
            'orders' => $customer === null ? [] : customerOrders((string) $customer['email']),
            'csrf_token' => csrfToken(),
        ]);
    } catch (PDOException | RuntimeException $exception) {
        error_log('ARAS customer account read failed: ' . $exception->getMessage());
        customerAccountResponse(['error' => 'No se pudo cargar la cuenta. Inténtalo más tarde.'], 500);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    customerAccountResponse(['error' => 'Método no permitido.'], 405);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    customerAccountResponse(['error' => 'La solicitud debe enviarse en formato JSON.'], 415);
}

$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verifyCsrfToken($submittedToken)) {
    customerAccountResponse(['error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.'], 403);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 8192) {
    customerAccountResponse(['error' => 'La solicitud está vacía o excede el tamaño permitido.'], 413);
}

try {
    $request = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new InvalidArgumentException('La solicitud no tiene un formato válido.');
    }

    $action = $request['action'] ?? null;
    if (!is_string($action)) {
        throw new InvalidArgumentException('La acción solicitada no es válida.');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        customerAccountResponse([
            'authenticated' => false,
            'user' => null,
            'orders' => [],
            'csrf_token' => csrfToken(),
        ]);
    }

    if (!in_array($action, ['login', 'register'], true)) {
        throw new InvalidArgumentException('La acción solicitada no es válida.');
    }

    $email = $request['email'] ?? null;
    $password = $request['password'] ?? null;
    if (!is_string($email) || !is_string($password)) {
        throw new InvalidArgumentException('Completa el correo electrónico y la contraseña.');
    }
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 254) {
        throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    }

    if ($action === 'register') {
        $name = $request['name'] ?? null;
        if (!is_string($name)) {
            throw new InvalidArgumentException('Escribe tu nombre.');
        }
        $customer = createCustomerAccount($name, $email, $password);
    } else {
        if (mb_strlen($password, 'UTF-8') > 128) {
            throw new InvalidArgumentException('El correo o la contraseña no son correctos.');
        }
        $customer = authenticateCustomer($email, $password);
        if ($customer === null) {
            customerAccountResponse(['error' => 'El correo o la contraseña no son correctos.'], 401);
        }
    }

    session_regenerate_id(true);
    $_SESSION['customer'] = [
        'id' => (int) $customer['id'],
        'email' => (string) $customer['email'],
        'name' => (string) $customer['name'],
        'last_activity' => time(),
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    customerAccountResponse([
        'authenticated' => true,
        'user' => ['name' => (string) $customer['name'], 'email' => (string) $customer['email']],
        'orders' => customerOrders((string) $customer['email']),
        'csrf_token' => csrfToken(),
    ]);
} catch (JsonException | InvalidArgumentException $exception) {
    customerAccountResponse(['error' => $exception->getMessage()], 422);
} catch (PDOException | RuntimeException $exception) {
    error_log('ARAS customer authentication failed: ' . $exception->getMessage());
    customerAccountResponse(['error' => 'No se pudo completar la solicitud. Inténtalo más tarde.'], 500);
}
