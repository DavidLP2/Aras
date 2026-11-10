<?php
declare(strict_types=1);

function storagePath(string $file = ''): string
{
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    return $file === '' ? $directory : $directory . DIRECTORY_SEPARATOR . $file;
}

function isLocalAdminHost(): bool
{
    $serverName = strtolower(rtrim((string) ($_SERVER['SERVER_NAME'] ?? ''), '.'));
    return in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);
}

function isAdminRequestHttps(): bool
{
    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function enforceAdminHttps(): void
{
    if (isLocalAdminHost()) {
        return;
    }

    if (isAdminRequestHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
        return;
    }

    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('El acceso administrativo requiere una conexión HTTPS segura.');
    }

    $serverName = strtolower(rtrim((string) ($_SERVER['SERVER_NAME'] ?? ''), '.'));
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $serverName)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('No se pudo determinar de forma segura el dominio HTTPS del panel.');
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if (!str_starts_with($requestUri, '/')
        || str_starts_with($requestUri, '//')
        || str_contains($requestUri, "\r")
        || str_contains($requestUri, "\n")) {
        $requestUri = '/';
    }

    header('Location: https://' . $serverName . $requestUri, true, 308);
    exit;
}

function wompiConfiguration(): array
{
    $serverName = strtolower(rtrim((string) ($_SERVER['SERVER_NAME'] ?? ''), '.'));
    $isLocalServer = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);
    $environment = $isLocalServer ? 'local' : 'production';
    $configPath = storagePath('wompi.' . $environment . '.php');
    if (!is_file($configPath)) {
        throw new RuntimeException(
            'Falta storage/wompi.' . $environment . '.php. Cópialo desde la plantilla del mismo entorno y configura Wompi.'
        );
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('El archivo de configuración de Wompi debe devolver un arreglo PHP.');
    }

    $expectedEnvironment = $isLocalServer ? 'sandbox' : 'production';
    if (($config['environment'] ?? null) !== $expectedEnvironment) {
        throw new RuntimeException('El ambiente de Wompi no coincide con el servidor actual.');
    }
    foreach (['public_key', 'integrity_secret', 'events_secret'] as $key) {
        $value = $config[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || str_contains($value, 'REEMPLAZAR')) {
            throw new RuntimeException(
                'Completa public_key, integrity_secret y events_secret en storage/wompi.' . $environment . '.php.'
            );
        }
    }

    $expectedKeyPrefix = $expectedEnvironment === 'sandbox' ? 'pub_test_' : 'pub_prod_';
    if (!str_starts_with($config['public_key'], $expectedKeyPrefix)) {
        throw new RuntimeException('La llave pública de Wompi no corresponde al ambiente configurado.');
    }

    return $config;
}

function expireUnpaidOrders(): int
{
    $statement = databaseConnection()->prepare(
        'UPDATE `orders`
         SET status = :expired_status, payment_status = :payment_status,
             reviewed_at = CURRENT_TIMESTAMP, reviewed_by = :reviewed_by
         WHERE status = :pending_status
           AND payment_status = :unpaid_status
           AND payment_expires_at <= CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'expired_status' => 'not_concluded',
        'payment_status' => 'expired',
        'reviewed_by' => 'Vencimiento automático',
        'pending_status' => 'pending',
        'unpaid_status' => 'unpaid',
    ]);

    return $statement->rowCount();
}

function isListArray(array $value): bool
{
    $expectedKey = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expectedKey) {
            return false;
        }
        $expectedKey++;
    }

    return true;
}

function databaseConnection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $serverName = strtolower(rtrim((string) ($_SERVER['SERVER_NAME'] ?? ''), '.'));
    $isLocalServer = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);
    $environment = $isLocalServer ? 'local' : 'production';
    $configPath = storagePath('database.' . $environment . '.php');
    if (!is_file($configPath)) {
        throw new RuntimeException(
            'Falta storage/database.' . $environment . '.php. Copia la plantilla correspondiente y configura los datos de conexión.'
        );
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('El archivo de conexión para ' . $environment . ' debe devolver un arreglo PHP.');
    }

    $pendingFields = [];
    foreach ([
        'host' => 'host',
        'database' => 'nombre de base de datos',
        'username' => 'usuario MySQL',
        'password' => 'contraseña MySQL',
    ] as $key => $label) {
        $value = $config[$key] ?? null;
        if (!is_string($value)
            || ($key !== 'password' && trim($value) === '')
            || str_contains($value, 'HOST_MYSQL_')
            || str_contains($value, 'USUARIO_MYSQL_')
            || str_contains($value, 'CAMBIAR_POR_')) {
            $pendingFields[] = $label;
        }
    }

    if ($pendingFields !== []) {
        throw new RuntimeException(
            'Completa estos campos en storage/database.' . $environment . '.php: ' . implode(', ', $pendingFields) . '.'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['database']
    );
    $connection = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}

function panelPermissionSections(): array
{
    return ['announcement', 'add-product', 'products', 'orders', 'settings'];
}

function panelRoles(): array
{
    return ['Superadministrativo', 'Administrativo', 'Tecnico'];
}

function panelUserPermissions(string $username): array
{
    $statement = databaseConnection()->prepare(
        'SELECT role, permissions FROM `Usuarios`
         WHERE username = :username AND is_active = 1
           AND role IN (:superadmin, :admin, :technician)
         LIMIT 1'
    );
    $statement->execute([
        'username' => $username,
        'superadmin' => 'Superadministrativo',
        'admin' => 'Administrativo',
        'technician' => 'Tecnico',
    ]);
    $user = $statement->fetch();
    if (!is_array($user)) {
        return [];
    }
    if ($user['role'] === 'Superadministrativo') {
        return array_merge(['overview'], panelPermissionSections());
    }
    if ($user['role'] !== 'Administrativo') {
        return [];
    }

    try {
        $permissions = json_decode((string) ($user['permissions'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Los permisos guardados de la cuenta no tienen un formato válido.', 0, $exception);
    }
    if (!is_array($permissions) || array_filter($permissions, 'is_string') !== $permissions) {
        throw new RuntimeException('Los permisos guardados de la cuenta no tienen un formato válido.');
    }

    return array_values(array_unique(array_merge(
        ['overview'],
        array_intersect(panelPermissionSections(), $permissions)
    )));
}

function panelUserCanAccessSection(string $username, string $section): bool
{
    return in_array($section, panelUserPermissions($username), true);
}

function panelUserCanPerformAction(array $permissions, string $action, array $request): bool
{
    $section = match ($action) {
        'accept_order', 'reject_order' => 'orders',
        'create_category', 'delete_category' => 'add-product',
        'save_inventory_settings', 'save_site_settings', 'delete_site_logo' => 'settings',
        'save_banner', 'delete_banner', 'toggle_banner', 'toggle_demo_banner', 'delete_demo_banner', 'save_announcement' => 'announcement',
        'delete_product', 'import_products' => 'products',
        'save_product' => trim((string) ($request['product_id'] ?? '')) === ''
            ? 'add-product'
            : 'products',
        default => null,
    };

    return $section !== null && in_array($section, $permissions, true);
}

function authenticatePanelUser(string $username, string $password): ?string
{
    $connection = databaseConnection();
    $statement = $connection->prepare(
        'SELECT id, password_hash, role FROM `Usuarios`
         WHERE username = :username AND role IN (:superadmin, :admin, :technician) AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'username' => $username,
        'superadmin' => 'Superadministrativo',
        'admin' => 'Administrativo',
        'technician' => 'Tecnico',
    ]);
    $user = $statement->fetch();

    if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
        return null;
    }

    $update = $connection->prepare(
        'UPDATE `Usuarios` SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $update->execute(['id' => $user['id']]);

    return (string) $user['role'];
}

function changePanelPassword(string $username, string $currentPassword, string $newPassword): void
{
    $passwordLength = mb_strlen($newPassword, 'UTF-8');
    if ($passwordLength < 8 || $passwordLength > 128) {
        throw new InvalidArgumentException('La nueva contraseña debe tener entre 8 y 128 caracteres.');
    }

    $connection = databaseConnection();
    $statement = $connection->prepare(
        'SELECT id, password_hash FROM `Usuarios`
         WHERE username = :username
           AND role IN (:superadmin, :admin, :technician)
           AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'username' => $username,
        'superadmin' => 'Superadministrativo',
        'admin' => 'Administrativo',
        'technician' => 'Tecnico',
    ]);
    $user = $statement->fetch();

    if (!is_array($user) || !password_verify($currentPassword, (string) $user['password_hash'])) {
        throw new InvalidArgumentException('La contraseña actual no es correcta.');
    }

    $update = $connection->prepare(
        'UPDATE `Usuarios` SET password_hash = :password_hash WHERE id = :id'
    );
    $update->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $user['id'],
    ]);
}

function sendEntresuenoEmail(string $email, string $subject, string $message, ?string $htmlMessage = null): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El correo de destino no es válido.');
    }
    $autoloadPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('Falta instalar PHPMailer. Ejecuta composer install en el proyecto.');
    }
    require_once $autoloadPath;

    $configPath = storagePath('mail.smtp.php');
    if (!is_file($configPath)) {
        throw new RuntimeException('Falta configurar storage/mail.smtp.php con una cuenta Gmail y su contraseña de aplicación.');
    }
    $config = require $configPath;
    if (!is_array($config)
        || !isset(
            $config['host'],
            $config['port'],
            $config['encryption'],
            $config['username'],
            $config['password'],
            $config['from_email'],
            $config['from_name']
        )
        || !is_string($config['host'])
        || !is_int($config['port'])
        || !is_string($config['encryption'])
        || !is_string($config['username'])
        || !is_string($config['password'])
        || !is_string($config['from_email'])
        || !is_string($config['from_name'])
        || !filter_var($config['username'], FILTER_VALIDATE_EMAIL)
        || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)
        || strtolower($config['host']) !== 'smtp.gmail.com'
        || $config['port'] !== 587
        || $config['encryption'] !== 'tls'
        || str_contains($config['password'], 'CONTRASENA_DE_APLICACION')
        || str_contains($config['username'], 'tu-cuenta')
        || str_contains($config['from_email'], 'tu-cuenta')
        || trim($config['password']) === ''
        || $config['from_name'] === '') {
        throw new RuntimeException('Configura Gmail SMTP en storage/mail.smtp.php: cuenta, contraseña de aplicación y remitente válido.');
    }

    $smtpPassword = preg_replace('/\s+/', '', $config['password']);
    if (!is_string($smtpPassword) || $smtpPassword === '') {
        throw new RuntimeException('La contraseña de aplicación de Gmail no es válida.');
    }

    try {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $config['host'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $config['username'];
        $mailer->Password = $smtpPassword;
        $mailer->Port = $config['port'];
        $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($config['from_email'], $config['from_name']);
        $mailer->addReplyTo($config['from_email'], $config['from_name']);
        $mailer->addAddress($email);
        $mailer->Subject = $subject;
        if ($htmlMessage !== null) {
            $mailer->isHTML(true);
            $mailer->Body = $htmlMessage;
        } else {
            $mailer->isHTML(false);
            $mailer->Body = $message;
        }
        $mailer->AltBody = $message;
        $mailer->send();
    } catch (\PHPMailer\PHPMailer\Exception $exception) {
        error_log('Entresueño SMTP delivery failed: ' . $exception->getMessage());
        throw new RuntimeException('Gmail SMTP no aceptó el correo. Revisa el usuario, la contraseña de aplicación y que el remitente sea la cuenta autenticada o un alias autorizado en Gmail.');
    }
}

function sendPanelCredentialsEmail(string $email, string $username, string $password): void
{
    $host = strtolower(rtrim((string) ($_SERVER['SERVER_NAME'] ?? ''), '.'));
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
        throw new RuntimeException('No se pudo determinar el dominio para enviar el correo.');
    }

    $loginUrl = 'https://' . $host . '/admin/';
    $message = implode("\r\n", [
        'Hola,',
        '',
        'Tu cuenta para administrar ARAS ya está lista.',
        '',
        'DATOS DE ACCESO',
        'Usuario: ' . $username,
        'Contraseña temporal: ' . $password,
        '',
        'Ingresa al panel: ' . $loginUrl,
        'Por seguridad, cambia la contraseña desde el menú de tu usuario después de iniciar sesión.',
        '',
        'Si no esperabas este mensaje, comunícate con la persona que administra la tienda.',
    ]);
    $escapedUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escapedPassword = htmlspecialchars($password, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escapedLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $htmlMessage = <<<HTML
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al panel de ARAS</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f1f8;font-family:Arial,Helvetica,sans-serif;color:#30243b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f5f1f8;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #e9e2f0;border-radius:18px;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px;background-color:#4c2b70;color:#ffffff;">
                            <p style="margin:0 0 8px;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#e7d8f5;">ARAS</p>
                            <h1 style="margin:0;font-size:25px;line-height:1.3;color:#ffffff;">Tu cuenta está lista</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px 12px;">
                            <p style="margin:0 0 14px;font-size:16px;line-height:1.6;">Hola, <strong>{$escapedUsername}</strong>.</p>
                            <p style="margin:0;font-size:15px;line-height:1.65;color:#62566d;">Ya puedes ingresar al panel de administración de tu tienda. Guarda estos datos y no los compartas con otras personas.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 24px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8f5fb;border:1px solid #e9e2f0;border-radius:12px;">
                                <tr>
                                    <td style="padding:18px 20px 8px;font-size:12px;font-weight:bold;letter-spacing:1px;color:#766d7d;">USUARIO</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 20px 16px;font-size:16px;font-weight:bold;color:#30243b;overflow-wrap:anywhere;">{$escapedUsername}</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 20px 8px;font-size:12px;font-weight:bold;letter-spacing:1px;color:#766d7d;">CONTRASEÑA TEMPORAL</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 20px 18px;font-family:Consolas,Monaco,monospace;font-size:16px;font-weight:bold;color:#4c2b70;overflow-wrap:anywhere;">{$escapedPassword}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 32px 24px;">
                            <a href="{$escapedLoginUrl}" style="display:inline-block;padding:14px 24px;border-radius:9px;background-color:#7044a3;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">Ingresar al panel</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 26px;">
                            <p style="margin:0;padding:14px 16px;border-left:4px solid #d7c4e8;background-color:#faf8fc;color:#62566d;font-size:13px;line-height:1.6;"><strong>Importante:</strong> cambia esta contraseña desde el menú de tu usuario después de iniciar sesión.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px;background-color:#f8f5fb;color:#81768a;font-size:12px;line-height:1.6;">
                            Si no esperabas este mensaje, comunícate con la persona que administra la tienda.<br>
                            Este es un correo automático; por favor, no respondas.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    sendEntresuenoEmail($email, 'Acceso al panel de ARAS', $message, $htmlMessage);
}

function sendPasswordResetEmail(string $email, string $resetUrl): void
{
    $message = implode("\r\n", [
        'Hola,',
        '',
        'Recibimos una solicitud para restablecer la contraseña de tu cuenta de ARAS.',
        'Usa este enlace dentro de los próximos 30 minutos:',
        $resetUrl,
        '',
        'Si no solicitaste este cambio, ignora este mensaje. Tu contraseña no cambiará.',
    ]);
    sendEntresuenoEmail($email, 'Restablecer contraseña de ARAS', $message);
}

function requestPanelPasswordReset(string $email): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    }

    $connection = databaseConnection();
    $statement = $connection->prepare(
        'SELECT id, email FROM `Usuarios`
         WHERE email = :email AND role IN (:superadmin, :admin) AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'email' => $email,
        'superadmin' => 'Superadministrativo',
        'admin' => 'Administrativo',
    ]);
    $user = $statement->fetch();
    if (!is_array($user) || !is_string($user['email']) || $user['email'] === '') {
        return;
    }

    $recentRequest = $connection->prepare(
        'SELECT created_at FROM `password_reset_tokens`
         WHERE user_id = :user_id AND created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE)
         LIMIT 1'
    );
    $recentRequest->execute(['user_id' => $user['id']]);
    if ($recentRequest->fetchColumn() !== false) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $delete = $connection->prepare('DELETE FROM `password_reset_tokens` WHERE user_id = :user_id');
    $delete->execute(['user_id' => $user['id']]);
    $insert = $connection->prepare(
        'INSERT INTO `password_reset_tokens` (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))'
    );
    $insert->execute([
        'user_id' => $user['id'],
        'token_hash' => $tokenHash,
    ]);

    $host = strtolower(rtrim((string) ($_SERVER['SERVER_NAME'] ?? ''), '.'));
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
        $deleteToken = $connection->prepare('DELETE FROM `password_reset_tokens` WHERE token_hash = :token_hash');
        $deleteToken->execute(['token_hash' => $tokenHash]);
        throw new RuntimeException('No se pudo determinar el dominio para enviar el correo.');
    }
    $scheme = isLocalAdminHost() && !isAdminRequestHttps() ? 'http' : 'https';
    $resetUrl = $scheme . '://' . $host . '/admin/reset-password?token=' . rawurlencode($token);

    try {
        sendPasswordResetEmail((string) $user['email'], $resetUrl);
    } catch (RuntimeException $exception) {
        $deleteToken = $connection->prepare('DELETE FROM `password_reset_tokens` WHERE token_hash = :token_hash');
        $deleteToken->execute(['token_hash' => $tokenHash]);
        throw $exception;
    }
}

function resetPanelPassword(string $token, string $newPassword): bool
{
    $passwordLength = mb_strlen($newPassword, 'UTF-8');
    if ($passwordLength < 8 || $passwordLength > 128) {
        throw new InvalidArgumentException('La nueva contraseña debe tener entre 8 y 128 caracteres.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    $connection = databaseConnection();
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare(
            'SELECT reset.user_id
             FROM `password_reset_tokens` AS reset
             INNER JOIN `Usuarios` AS user ON user.id = reset.user_id
             WHERE reset.token_hash = :token_hash
               AND reset.expires_at > CURRENT_TIMESTAMP
               AND user.role IN (:superadmin, :admin)
               AND user.is_active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'superadmin' => 'Superadministrativo',
            'admin' => 'Administrativo',
        ]);
        $userId = $statement->fetchColumn();
        if ($userId === false) {
            $connection->commit();
            return false;
        }

        $update = $connection->prepare(
            'UPDATE `Usuarios` SET password_hash = :password_hash WHERE id = :id'
        );
        $update->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
        $delete = $connection->prepare('DELETE FROM `password_reset_tokens` WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);
        $connection->commit();

        return true;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function isValidPanelPasswordResetToken(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    $statement = databaseConnection()->prepare(
        'SELECT 1
         FROM `password_reset_tokens` AS reset
         INNER JOIN `Usuarios` AS user ON user.id = reset.user_id
         WHERE reset.token_hash = :token_hash
           AND reset.expires_at > CURRENT_TIMESTAMP
           AND user.role IN (:superadmin, :admin)
           AND user.is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'token_hash' => hash('sha256', $token),
        'superadmin' => 'Superadministrativo',
        'admin' => 'Administrativo',
    ]);

    return $statement->fetchColumn() !== false;
}

function activeUserRole(string $username): ?string
{
    $statement = databaseConnection()->prepare(
        'SELECT role FROM `Usuarios`
         WHERE username = :username
           AND role IN (:superadmin, :admin, :technician)
           AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'username' => $username,
        'superadmin' => 'Superadministrativo',
        'admin' => 'Administrativo',
        'technician' => 'Tecnico',
    ]);
    $role = $statement->fetchColumn();

    return is_string($role) ? $role : null;
}

function panelUserProfile(string $username): ?array
{
    $statement = databaseConnection()->prepare(
        'SELECT id, username, full_name, email, phone, national_id, role, permissions
         FROM `Usuarios`
         WHERE username = :username
           AND role IN (:superadmin, :admin, :technician)
           AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'username' => $username,
        'superadmin' => 'Superadministrativo',
        'admin' => 'Administrativo',
        'technician' => 'Tecnico',
    ]);
    $profile = $statement->fetch();

    return is_array($profile) ? $profile : null;
}

function updatePanelUserProfile(
    int $userId,
    string $fullName,
    string $email,
    string $phone,
    string $nationalId
): void {
    $fullName = trim($fullName);
    $email = trim($email);
    $phone = trim($phone);
    $nationalId = trim($nationalId);

    if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
        throw new InvalidArgumentException('Escribe tu nombre completo (máximo 120 caracteres).');
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Escribe un correo electrónico válido de máximo 254 caracteres.');
    }
    if ($phone !== '' && (strlen($phone) > 30 || !preg_match('/^\+?[0-9\s().-]{7,30}$/', $phone))) {
        throw new InvalidArgumentException('El celular debe tener entre 7 y 30 caracteres y usar solo números, espacios o los signos + ( ) . -');
    }
    if ($nationalId !== '' && !preg_match('/^[0-9]{5,15}$/', $nationalId)) {
        throw new InvalidArgumentException('La cédula debe contener entre 5 y 15 dígitos.');
    }

    $connection = databaseConnection();
    $connection->beginTransaction();
    try {
        $duplicateEmail = $connection->prepare(
            'SELECT 1 FROM `Usuarios`
             WHERE LOWER(email) = LOWER(:email) AND id <> :id
             LIMIT 1'
        );
        $duplicateEmail->execute(['email' => $email, 'id' => $userId]);
        if ($duplicateEmail->fetchColumn() !== false) {
            throw new InvalidArgumentException('Ese correo electrónico ya está asociado a otra cuenta.');
        }

        $update = $connection->prepare(
            'UPDATE `Usuarios`
             SET full_name = :full_name, email = :email, phone = :phone, national_id = :national_id
             WHERE id = :id AND is_active = 1'
        );
        $update->execute([
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'national_id' => $nationalId !== '' ? $nationalId : null,
            'id' => $userId,
        ]);
        if ($update->rowCount() === 0) {
            $exists = $connection->prepare(
                'SELECT 1 FROM `Usuarios` WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $exists->execute(['id' => $userId]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('La cuenta ya no está activa. Vuelve a iniciar sesión.');
            }
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function isActiveSuperadmin(string $username): bool
{
    $statement = databaseConnection()->prepare(
        'SELECT 1 FROM `Usuarios`
         WHERE username = :username AND role = :role AND is_active = 1
         LIMIT 1'
    );
    $statement->execute(['username' => $username, 'role' => 'Superadministrativo']);

    return $statement->fetchColumn() !== false;
}

function activePanelUserCount(): int
{
    $statement = databaseConnection()->query(
        "SELECT COUNT(*) FROM `Usuarios`
         WHERE role IN ('Superadministrativo', 'Administrativo', 'Tecnico') AND is_active = 1"
    );

    return (int) $statement->fetchColumn();
}

function acquireProductInventoryLock(): mixed
{
    $lock = fopen(storagePath('products.lock'), 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('No se pudo bloquear el inventario para actualizarlo.');
    }

    return $lock;
}

function releaseProductInventoryLock(mixed $lock): void
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function createCustomerOrder(array $request): array
{
    $inventoryEnabled = siteSettings()['inventory_enabled'];
    $items = $request['items'] ?? null;
    $method = $request['method'] ?? null;
    $city = $request['city'] ?? null;
    $zone = $request['delivery_zone'] ?? null;
    $latitude = $request['latitude'] ?? null;
    $longitude = $request['longitude'] ?? null;
    foreach (['name', 'phone', 'address', 'city', 'delivery_zone'] as $field) {
        if (array_key_exists($field, $request) && !is_string($request[$field])) {
            throw new InvalidArgumentException('Los datos de contacto o entrega no son válidos.');
        }
    }
    $name = trim($request['name'] ?? '');
    $phone = trim($request['phone'] ?? '');
    $address = trim($request['address'] ?? '');
    $city = trim($request['city'] ?? '');
    $zone = $request['delivery_zone'] ?? '';

    if (!is_array($items) || $items === [] || count($items) > 40
        || $method !== 'delivery'
        || !in_array($zone, ['medellin', 'antioquia'], true)
        || $city === '') {
        throw new InvalidArgumentException('El pedido no contiene productos o datos de entrega válidos.');
    }
    if (mb_strlen($name, 'UTF-8') > 120 || mb_strlen($phone, 'UTF-8') > 30 || mb_strlen($city, 'UTF-8') > 120
        || mb_strlen($address, 'UTF-8') > 255) {
        throw new InvalidArgumentException('Los datos de contacto o entrega exceden el tamaño permitido.');
    }
    if ($name === '' || $phone === '' || $address === '') {
        throw new InvalidArgumentException('Completa nombre, celular, municipio y dirección para el domicilio.');
    }
    if (!is_numeric($latitude) || !is_numeric($longitude)
        || !is_finite((float) $latitude) || !is_finite((float) $longitude)
        || (float) $latitude < -5 || (float) $latitude > 13.5
        || (float) $longitude < -82 || (float) $longitude > -66) {
        throw new InvalidArgumentException('Confirma una ubicación válida en el mapa para Colombia.');
    }

    $products = readProducts();
    $productsById = [];
    foreach ($products as $product) {
        if (!empty($product['active'])) {
            $productsById[$product['id']] = $product;
        }
    }

    $itemQuantities = [];
    $productQuantities = [];
    foreach ($items as $item) {
        if (!is_array($item)
            || !isset($item['id'], $item['quantity'])
            || !is_string($item['id'])
            || !preg_match('/^[a-f0-9]{32}$/', $item['id'])) {
            throw new InvalidArgumentException('Uno de los productos enviados no es válido.');
        }

        $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 100) {
            throw new InvalidArgumentException('La cantidad de un producto debe estar entre 1 y 100.');
        }
        $product = $productsById[$item['id']] ?? null;
        if (!is_array($product)) {
            throw new InvalidArgumentException('Uno de los productos seleccionados ya no está disponible.');
        }

        $requestedVariants = $item['variants'] ?? [];
        if (!is_array($requestedVariants) || !isListArray($requestedVariants)
            || count($requestedVariants) !== count($product['variants'])) {
            throw new InvalidArgumentException('Selecciona una opción válida para cada variación del producto.');
        }
        $selectedVariants = [];
        foreach ($product['variants'] as $variantIndex => $variant) {
            $requestedVariant = $requestedVariants[$variantIndex] ?? null;
            if (!is_array($requestedVariant)
                || ($requestedVariant['name'] ?? null) !== $variant['name']
                || !is_string($requestedVariant['value'] ?? null)) {
                throw new InvalidArgumentException('Una variación seleccionada no es válida.');
            }
            $selectedOption = null;
            foreach ($variant['options'] as $option) {
                if ($option['label'] === $requestedVariant['value']) {
                    $selectedOption = $option;
                    break;
                }
            }
            if ($selectedOption === null) {
                throw new InvalidArgumentException('Una opción seleccionada ya no está disponible.');
            }
            $selectedVariants[] = [
                'name' => $variant['name'],
                'value' => $selectedOption['label'],
            ];
        }

        $lineKey = $item['id'] . ':' . hash('sha256', json_encode($selectedVariants, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $itemQuantities[$lineKey] = [
            'id' => $item['id'],
            'variants' => $selectedVariants,
            'quantity' => ($itemQuantities[$lineKey]['quantity'] ?? 0) + $quantity,
        ];
        $productQuantities[$item['id']] = ($productQuantities[$item['id']] ?? 0) + $quantity;
        if ($productQuantities[$item['id']] > 100 || $itemQuantities[$lineKey]['quantity'] > 100) {
            throw new InvalidArgumentException('La cantidad de un producto debe estar entre 1 y 100.');
        }
    }

    $orderItems = [];
    $totalItems = 0;
    $subtotal = 0;
    foreach ($itemQuantities as $itemQuantity) {
        $productId = $itemQuantity['id'];
        $quantity = $itemQuantity['quantity'];
        $product = $productsById[$productId] ?? null;
        if (!is_array($product)
            || ($inventoryEnabled && (int) $product['stock'] < $productQuantities[$productId])) {
            throw new RuntimeException('Uno de los productos ya no tiene esa cantidad disponible. Actualiza el catálogo e inténtalo de nuevo.');
        }
        $variantDescription = implode(' · ', array_map(
            static fn (array $variant): string => $variant['name'] . ': ' . $variant['value'],
            $itemQuantity['variants']
        ));
        $lineName = (string) $product['name']
            . ($variantDescription !== '' ? ' (' . $variantDescription . ')' : '');
        if (mb_strlen($lineName, 'UTF-8') > 120) {
            throw new InvalidArgumentException('La selección de talla, color o presentación hace demasiado largo el nombre del producto.');
        }

        $totalItems += $quantity;
        $subtotal += (int) $product['price'] * $quantity;
        $orderItems[] = [
            'id' => $productId,
            'name' => $lineName,
            'price' => (int) $product['price'],
            'quantity' => $quantity,
            'variants' => $itemQuantity['variants'],
        ];
    }

    if ($totalItems > 100 || $subtotal > 1000000000) {
        throw new InvalidArgumentException('El pedido supera los límites permitidos.');
    }

    $shipping = $zone === 'medellin' ? 8000 : 14000;
    $reference = 'ARAS-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $connection = databaseConnection();
    $customer = currentCustomerSession();
    $customerEmail = is_array($customer) ? (string) $customer['email'] : null;

    try {
        $connection->beginTransaction();
        $order = $connection->prepare(
            'INSERT INTO `orders`
                    (reference, status, payment_status, payment_expires_at, customer_name, customer_phone, customer_email,
                     delivery_method, city, delivery_zone, delivery_address, delivery_latitude,
                     delivery_longitude, subtotal, shipping, total)
                 VALUES
                    (:reference, :status, :payment_status, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR),
                     :customer_name, :customer_phone, :customer_email, :delivery_method, :city, :delivery_zone, :delivery_address,
                     :delivery_latitude, :delivery_longitude, :subtotal, :shipping, :total)'
            );
            $order->execute([
                'reference' => $reference,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'customer_name' => $name === '' ? null : $name,
            'customer_phone' => $phone === '' ? null : $phone,
            'customer_email' => $customerEmail,
            'delivery_method' => $method,
            'city' => $city,
            'delivery_zone' => $zone,
            'delivery_address' => $address,
            'delivery_latitude' => number_format((float) $latitude, 7, '.', ''),
            'delivery_longitude' => number_format((float) $longitude, 7, '.', ''),
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $subtotal + $shipping,
        ]);
        $orderId = (int) $connection->lastInsertId();
        $line = $connection->prepare(
            'INSERT INTO `order_items` (order_id, product_id, product_name, unit_price, quantity)
             VALUES (:order_id, :product_id, :product_name, :unit_price, :quantity)'
        );
        foreach ($orderItems as $item) {
            $line->execute([
                'order_id' => $orderId,
                'product_id' => $item['id'],
                'product_name' => $item['name'],
                'unit_price' => $item['price'],
                'quantity' => $item['quantity'],
            ]);
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    $expiryQuery = $connection->prepare(
        'SELECT payment_expires_at FROM `orders` WHERE id = :id'
    );
    $expiryQuery->execute(['id' => $orderId]);
    $paymentExpiresAt = $expiryQuery->fetchColumn();
    if (!is_string($paymentExpiresAt)) {
        throw new RuntimeException('No se pudo recuperar la fecha límite de pago del pedido.');
    }

    return [
        'id' => $orderId,
        'reference' => $reference,
        'payment_expires_at' => $paymentExpiresAt,
        'items' => $orderItems,
        'subtotal' => $subtotal,
        'shipping' => $shipping,
        'total' => $subtotal + $shipping,
        'city' => $city,
        'delivery_zone' => $zone,
        'delivery_latitude' => (float) $latitude,
        'delivery_longitude' => (float) $longitude,
    ];
}

function changeOrderStatus(
    int $orderId,
    string $status,
    string $adminUsername,
    ?string $paymentTransactionId = null
): void
{
    if ($orderId < 1 || !in_array($status, ['sold', 'not_concluded'], true)) {
        throw new InvalidArgumentException('El pedido o estado solicitado no es válido.');
    }

    $lock = acquireProductInventoryLock();

    $connection = null;
    $originalProducts = null;
    $productsWritten = false;
    try {
        $connection = databaseConnection();
        $connection->beginTransaction();
        $orderQuery = $connection->prepare(
            'SELECT id, status, payment_status, payment_expires_at,
                    payment_expires_at > CURRENT_TIMESTAMP AS payment_window_open
             FROM `orders` WHERE id = :id FOR UPDATE'
        );
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch();
        if (!is_array($order)) {
            throw new RuntimeException('No se encontró el pedido solicitado.');
        }
        if ($order['status'] !== 'pending') {
            throw new RuntimeException('Este pedido ya fue procesado.');
        }
        if ($status === 'sold' && $order['payment_status'] === 'unpaid'
            && ($adminUsername !== 'Wompi'
                || (int) $order['payment_window_open'] !== 1)) {
            throw new RuntimeException('Este pedido debe pagarse en Wompi antes de descontar inventario.');
        }

        if ($status === 'sold' && siteSettings()['inventory_enabled']) {
            $originalProducts = readProducts();
            $itemsQuery = $connection->prepare(
                'SELECT product_id, product_name, quantity FROM `order_items` WHERE order_id = :order_id'
            );
            $itemsQuery->execute(['order_id' => $orderId]);
            $items = $itemsQuery->fetchAll();

            foreach ($items as $item) {
                $matched = false;
                foreach ($originalProducts as &$product) {
                    if (($product['id'] ?? '') !== $item['product_id']) {
                        continue;
                    }
                    $matched = true;
                    $quantity = (int) $item['quantity'];
                    if (empty($product['active']) || (int) $product['stock'] < $quantity) {
                        throw new RuntimeException(
                            'No hay stock suficiente para vender "' . (string) $item['product_name'] . '". Ajusta el inventario o marca el pedido como No concluido.'
                        );
                    }
                    $product['stock'] = (int) $product['stock'] - $quantity;
                    break;
                }
                unset($product);
                if (!$matched) {
                    throw new RuntimeException('Un producto del pedido ya no existe en el catálogo; no se descontó inventario.');
                }
            }

            writeJsonFile(storagePath('products.json'), array_values($originalProducts));
            $productsWritten = true;
        }

        $update = $connection->prepare(
            'UPDATE `orders`
             SET status = :status,
                 payment_status = IF(:is_wompi = 1, :payment_status, payment_status),
                 payment_transaction_id = IF(:is_wompi = 1, :transaction_id, payment_transaction_id),
                 reviewed_at = CURRENT_TIMESTAMP, reviewed_by = :reviewed_by
             WHERE id = :id AND status = :pending'
        );
        $update->execute([
            'status' => $status,
            'is_wompi' => $adminUsername === 'Wompi' ? 1 : 0,
            'payment_status' => 'paid',
            'transaction_id' => $adminUsername === 'Wompi' ? $paymentTransactionId : null,
            'reviewed_by' => $adminUsername,
            'id' => $orderId,
            'pending' => 'pending',
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('El pedido cambió de estado y no pudo procesarse.');
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        if ($productsWritten && is_array($originalProducts)) {
            try {
                writeJsonFile(storagePath('products.json'), $originalProducts);
            } catch (Throwable $restoreException) {
                error_log('Entresueño failed to restore inventory after order transaction failure: ' . $restoreException->getMessage());
            }
        }
        throw $exception;
    } finally {
        releaseProductInventoryLock($lock);
    }
}

function verifyWompiEvent(array $event, string $eventsSecret): bool
{
    $properties = $event['signature']['properties'] ?? null;
    $timestamp = $event['timestamp'] ?? null;
    $checksum = $event['signature']['checksum'] ?? null;
    if (!is_array($properties) || !is_int($timestamp) || !is_string($checksum)) {
        return false;
    }

    $signatureData = '';
    foreach ($properties as $property) {
        if (!is_string($property) || $property === '') {
            return false;
        }
        $value = $event['data'] ?? null;
        $segments = explode('.', $property);
        foreach ($segments as $index => $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
            if ($index < count($segments) - 1 && !is_array($value)) {
                return false;
            }
        }
        if (!is_scalar($value)) {
            return false;
        }
        $signatureData .= (string) $value;
    }

    $expectedChecksum = hash('sha256', $signatureData . $timestamp . $eventsSecret);
    return hash_equals(strtolower($expectedChecksum), strtolower($checksum));
}

function processWompiTransactionUpdate(array $transaction): void
{
    $reference = $transaction['reference'] ?? null;
    $transactionId = $transaction['id'] ?? null;
    $amountInCents = $transaction['amount_in_cents'] ?? null;
    if (!is_string($reference) || !is_string($transactionId)
        || !is_int($amountInCents) || ($transaction['currency'] ?? null) !== 'COP') {
        throw new InvalidArgumentException('El evento de pago no contiene datos de transacción válidos.');
    }

    $connection = databaseConnection();
    $query = $connection->prepare(
        'SELECT id, status, payment_status, payment_expires_at,
                payment_expires_at <= CURRENT_TIMESTAMP AS payment_expired, total
         FROM `orders` WHERE reference = :reference LIMIT 1'
    );
    $query->execute(['reference' => $reference]);
    $order = $query->fetch();
    if (!is_array($order)) {
        error_log('Wompi notified an unknown ARAS order reference: ' . $reference);
        return;
    }
    if ($amountInCents !== (int) $order['total'] * 100) {
        throw new InvalidArgumentException('El monto de la transacción de Wompi no coincide con el pedido.');
    }
    if (($transaction['status'] ?? null) !== 'APPROVED') {
        return;
    }

    if ($order['payment_status'] === 'paid') {
        return;
    }
    $expired = $order['payment_status'] === 'expired'
        || $order['status'] !== 'pending'
        || (int) $order['payment_expired'] === 1;
    if ($expired) {
        $statement = $connection->prepare(
            'UPDATE `orders` SET payment_status = :payment_status, payment_transaction_id = :transaction_id
             WHERE id = :id AND payment_status != :paid_status'
        );
        $statement->execute([
            'payment_status' => 'paid_late',
            'transaction_id' => $transactionId,
            'id' => $order['id'],
            'paid_status' => 'paid',
        ]);
        error_log('Wompi approved a late payment for ARAS order ' . $reference . '; manual review is required.');
        return;
    }

    try {
        changeOrderStatus((int) $order['id'], 'sold', 'Wompi', $transactionId);
    } catch (RuntimeException $exception) {
        if (!str_starts_with($exception->getMessage(), 'No hay stock suficiente')
            && !str_starts_with($exception->getMessage(), 'Un producto del pedido ya no existe')) {
            throw $exception;
        }
        $statement = $connection->prepare(
            'UPDATE `orders` SET payment_status = :payment_status, payment_transaction_id = :transaction_id
             WHERE id = :id AND payment_status = :unpaid_status AND status = :pending_status'
        );
        $statement->execute([
            'payment_status' => 'paid',
            'transaction_id' => $transactionId,
            'id' => $order['id'],
            'unpaid_status' => 'unpaid',
            'pending_status' => 'pending',
        ]);
        error_log('Wompi payment approved for ARAS order ' . $reference . ', but inventory needs manual review: ' . $exception->getMessage());
        return;
    }
}

function readJsonFile(string $path, mixed $default): mixed
{
    if (!is_file($path)) {
        return $default;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('No se pudo leer el archivo de datos.');
    }

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

function writeJsonFile(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('No se pudieron guardar los datos.');
    }

    if (!rename($temporaryPath, $path)) {
        unlink($temporaryPath);
        throw new RuntimeException('No se pudo finalizar el guardado de los datos.');
    }
}

function siteSettings(): array
{
    $defaults = [
        'logo' => '',
        'low_stock_threshold' => 5,
        'inventory_enabled' => true,
    ];
    $settings = readJsonFile(storagePath('site-settings.json'), []);
    if (!is_array($settings)) {
        throw new RuntimeException('La configuración guardada tiene un formato inválido.');
    }

    $logo = $settings['logo'] ?? $defaults['logo'];
    $lowStockThreshold = $settings['low_stock_threshold'] ?? $defaults['low_stock_threshold'];
    $inventoryEnabled = $settings['inventory_enabled'] ?? $defaults['inventory_enabled'];
    if (!is_string($logo)
        || ($logo !== '' && !preg_match('/^[a-f0-9]{32}\.webp$/', $logo))) {
        throw new RuntimeException('El logo guardado tiene un formato inválido.');
    }
    if (filter_var($lowStockThreshold, FILTER_VALIDATE_INT) === false
        || (int) $lowStockThreshold < 1
        || (int) $lowStockThreshold > 100000) {
        throw new RuntimeException('El umbral de pocas unidades guardado no es válido.');
    }
    if (!is_bool($inventoryEnabled)) {
        throw new RuntimeException('La configuración del control de inventario no es válida.');
    }

    return [
        'logo' => $logo,
        'low_stock_threshold' => (int) $lowStockThreshold,
        'inventory_enabled' => $inventoryEnabled,
    ];
}

function siteBanners(): array
{
    $banners = readJsonFile(storagePath('banners.json'), []);
    if (!is_array($banners) || !isListArray($banners) || count($banners) > 10) {
        throw new RuntimeException('La lista de banners guardada tiene un formato inválido.');
    }

    foreach ($banners as $banner) {
        if (!is_array($banner)
            || !isset($banner['id'], $banner['image'], $banner['text'], $banner['link'], $banner['active'])
            || !is_string($banner['id'])
            || !preg_match('/^[a-f0-9]{32}$/', $banner['id'])
            || !is_string($banner['image'])
            || !preg_match('/^[a-f0-9]{32}\.webp$/', $banner['image'])
            || !is_string($banner['text'])
            || mb_strlen($banner['text'], 'UTF-8') > 300
            || !is_string($banner['link'])
            || mb_strlen($banner['link'], 'UTF-8') > 2048
            || !is_bool($banner['active'])) {
            throw new RuntimeException('Uno de los banners guardados tiene datos inválidos.');
        }
        if ($banner['link'] !== '') {
            $scheme = strtolower((string) parse_url($banner['link'], PHP_URL_SCHEME));
            if (!filter_var($banner['link'], FILTER_VALIDATE_URL)
                || !in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException('Uno de los enlaces de banner guardados no es válido.');
            }
        }
    }

    return $banners;
}

function demoSiteBanners(): array
{
    return [
        [
            'id' => 'demo',
            'text' => 'NUEVA TEMPORADA · Descubre la colección ARAS',
            'link' => './#categorias',
            'image' => 'img/aras-banner-demo.svg',
        ],
        [
            'id' => 'demo-style',
            'text' => 'COLOR Y COMODIDAD · Elige el que va contigo',
            'link' => './#coleccion',
            'image' => 'img/aras-banner-style.svg',
        ],
        [
            'id' => 'demo-moment',
            'text' => 'CADA MOMENTO · Un estilo para ti',
            'link' => './#coleccion',
            'image' => 'img/aras-banner-moment.svg',
        ],
    ];
}

function hiddenDemoSiteBannerIds(): array
{
    $hiddenIds = readJsonFile(storagePath('hidden-demo-banners.json'), []);
    $validIds = array_column(demoSiteBanners(), 'id');
    if (!is_array($hiddenIds)
        || !isListArray($hiddenIds)
        || array_filter($hiddenIds, static fn ($id): bool => !is_string($id) || !in_array($id, $validIds, true)) !== []) {
        throw new RuntimeException('La lista de banners de demostración eliminados tiene un formato inválido.');
    }

    return array_values(array_unique($hiddenIds));
}

function startAdminSession(): void
{
    enforceAdminHttps();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('ArasAdminV1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !isLocalAdminHost() || isAdminRequestHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function startCustomerSession(): void
{
    enforceAdminHttps();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('ArasCustomerV1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !isLocalAdminHost() || isAdminRequestHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function currentCustomerSession(): ?array
{
    $customer = $_SESSION['customer'] ?? null;
    if (!is_array($customer)
        || !isset($customer['id'], $customer['email'], $customer['name'], $customer['last_activity'])
        || time() - (int) $customer['last_activity'] > 7200) {
        unset($_SESSION['customer']);
        return null;
    }

    $_SESSION['customer']['last_activity'] = time();
    return $_SESSION['customer'];
}

function customerOrders(string $email): array
{
    $statement = databaseConnection()->prepare(
        'SELECT reference, status, payment_status, total, created_at
         FROM `orders`
         WHERE customer_email = :email
         ORDER BY created_at DESC, id DESC
         LIMIT 100'
    );
    $statement->execute(['email' => $email]);
    return $statement->fetchAll();
}

function authenticateCustomer(string $email, string $password): ?array
{
    $statement = databaseConnection()->prepare(
        'SELECT id, email, full_name, password_hash
         FROM `customers`
         WHERE email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $customer = $statement->fetch();
    if (!is_array($customer) || !password_verify($password, (string) $customer['password_hash'])) {
        return null;
    }

    if (password_needs_rehash((string) $customer['password_hash'], PASSWORD_DEFAULT)) {
        $updateHash = databaseConnection()->prepare(
            'UPDATE `customers` SET password_hash = :password_hash WHERE id = :id'
        );
        $updateHash->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $customer['id'],
        ]);
    }

    $updateLogin = databaseConnection()->prepare(
        'UPDATE `customers` SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $updateLogin->execute(['id' => $customer['id']]);

    return [
        'id' => (int) $customer['id'],
        'email' => (string) $customer['email'],
        'name' => (string) $customer['full_name'],
    ];
}

function createCustomerAccount(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = strtolower(trim($email));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
        throw new InvalidArgumentException('Escribe tu nombre (máximo 120 caracteres).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 254) {
        throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    }
    $passwordLength = mb_strlen($password, 'UTF-8');
    if ($passwordLength < 10 || $passwordLength > 128) {
        throw new InvalidArgumentException('La contraseña debe tener entre 10 y 128 caracteres.');
    }

    $insert = databaseConnection()->prepare(
        'INSERT INTO `customers` (email, full_name, password_hash)
         VALUES (:email, :full_name, :password_hash)'
    );
    try {
        $insert->execute([
            'email' => $email,
            'full_name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            throw new InvalidArgumentException('No se pudo crear la cuenta con esos datos. Si ya tienes una, inicia sesión.');
        }
        throw $exception;
    }

    return [
        'id' => (int) databaseConnection()->lastInsertId(),
        'email' => $email,
        'name' => $name,
    ];
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(mixed $submittedToken): bool
{
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function requireAdmin(?string $requiredSection = null): void
{
    startAdminSession();
    $sessionExpired = isset($_SESSION['admin_user'], $_SESSION['last_activity'])
        && time() - (int) $_SESSION['last_activity'] > 7200;

    if ($sessionExpired) {
        unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
    }

    if (!isset($_SESSION['admin_user'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Debes iniciar sesión para administrar el catálogo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $username = (string) $_SESSION['admin_user'];
        $role = activeUserRole($username);
        $authorized = in_array($role, ['Superadministrativo', 'Administrativo'], true)
            && ($requiredSection === null || panelUserCanAccessSection($username, $requiredSection));
        if (!$authorized) {
            if ($role === null) {
                unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
            }
            http_response_code($role === null ? 401 : 403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'No tienes permiso para realizar esta acción.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (PDOException | RuntimeException $exception) {
        error_log('Entresueño admin authorization check failed: ' . $exception->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No se pudo validar el acceso administrativo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['last_activity'] = time();
}

function productCategories(): array
{
    $defaultCategories = [
        'cuidado-personal' => 'Cuidado personal',
        'belleza' => 'Belleza',
        'perfumeria' => 'Perfumería',
    ];

    $hiddenCategories = readJsonFile(storagePath('categories.hidden.json'), []);
    if (!is_array($hiddenCategories) || !isListArray($hiddenCategories)) {
        throw new RuntimeException('Las categorías ocultas tienen un formato inválido.');
    }
    foreach ($hiddenCategories as $key) {
        if (!is_string($key) || !array_key_exists($key, $defaultCategories)) {
            throw new RuntimeException('Una categoría oculta tiene datos inválidos.');
        }
    }

    $categories = array_diff_key($defaultCategories, array_fill_keys($hiddenCategories, true));
    $savedCategories = readJsonFile(storagePath('categories.json'), []);
    if (!is_array($savedCategories) || !isListArray($savedCategories)) {
        throw new RuntimeException('Las categorías guardadas tienen un formato inválido.');
    }

    foreach ($savedCategories as $category) {
        if (!is_array($category)
            || !isset($category['key'], $category['label'])
            || !is_string($category['key'])
            || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $category['key'])
            || !is_string($category['label'])
            || trim($category['label']) === ''
            || mb_strlen($category['label'], 'UTF-8') > 50
            || (isset($category['image'])
                && (!is_string($category['image']) || !preg_match('/^[a-f0-9]{32}\.webp$/', $category['image'])))
            || array_key_exists($category['key'], $categories)) {
            throw new RuntimeException('Una categoría guardada tiene datos inválidos.');
        }

        $categories[$category['key']] = $category['label'];
    }

    return $categories;
}

function productCategoryImages(): array
{
    productCategories();
    $savedCategories = readJsonFile(storagePath('categories.json'), []);
    $images = [];
    foreach ($savedCategories as $category) {
        if (isset($category['image'])) {
            $images[$category['key']] = $category['image'];
        }
    }

    return $images;
}

function createProductCategory(string $label, array $imageUpload): string
{
    $label = trim($label);
    if ($label === '' || mb_strlen($label, 'UTF-8') > 50) {
        throw new RuntimeException('El nombre de la categoría debe tener entre 1 y 50 caracteres.');
    }

    $lock = fopen(storagePath('categories.lock'), 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('No se pudo bloquear la lista de categorías para actualizarla.');
    }

    $savedImage = '';
    try {
        $existingCategories = productCategories();
        foreach ($existingCategories as $existingLabel) {
            if (mb_strtolower($existingLabel, 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
                throw new RuntimeException('Ya existe una categoría con ese nombre.');
            }
        }

        $slugLabel = strtr(mb_strtolower($label, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        $key = preg_replace('/[^a-z0-9]+/', '-', $slugLabel);
        $key = trim((string) $key, '-');
        if ($key === '' || array_key_exists($key, $existingCategories)) {
            throw new RuntimeException('No se pudo crear un identificador único para la categoría.');
        }

        $savedCategories = readJsonFile(storagePath('categories.json'), []);
        if (!is_array($savedCategories) || !isListArray($savedCategories)) {
            throw new RuntimeException('Las categorías guardadas tienen un formato inválido.');
        }
        $savedImage = saveUploadedImage($imageUpload);
        $savedCategories[] = ['key' => $key, 'label' => $label, 'image' => $savedImage];
        writeJsonFile(storagePath('categories.json'), $savedCategories);

        return $key;
    } catch (JsonException | RuntimeException | Error $exception) {
        if ($savedImage !== '') {
            try {
                removeUploadedImage($savedImage);
            } catch (RuntimeException $cleanupException) {
                error_log('ARAS temporary category image cleanup failed: ' . $cleanupException->getMessage());
            }
        }
        throw $exception;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function deleteProductCategory(string $key): void
{
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
        throw new RuntimeException('La categoría seleccionada no es válida.');
    }

    $categoryLock = fopen(storagePath('categories.lock'), 'c');
    if (!is_resource($categoryLock) || !flock($categoryLock, LOCK_EX)) {
        if (is_resource($categoryLock)) {
            fclose($categoryLock);
        }
        throw new RuntimeException('No se pudo bloquear la lista de categorías para actualizarla.');
    }

    $inventoryLock = null;
    try {
        $categories = productCategories();
        if (!array_key_exists($key, $categories)) {
            throw new RuntimeException('La categoría seleccionada ya no existe.');
        }

        $defaultCategories = [
            'cuidado-personal' => 'Cuidado personal',
            'belleza' => 'Belleza',
            'perfumeria' => 'Perfumería',
        ];
        $savedCategories = readJsonFile(storagePath('categories.json'), []);
        if (!is_array($savedCategories) || !isListArray($savedCategories)) {
            throw new RuntimeException('Las categorías guardadas tienen un formato inválido.');
        }

        $savedCategoryIndex = null;
        $removedCategoryImage = '';
        foreach ($savedCategories as $index => $category) {
            if (is_array($category) && ($category['key'] ?? null) === $key) {
                $savedCategoryIndex = $index;
                $removedCategoryImage = is_string($category['image'] ?? null) ? $category['image'] : '';
                break;
            }
        }

        $inventoryLock = acquireProductInventoryLock();
        $products = readJsonFile(storagePath('products.json'), []);
        if (!is_array($products) || !isListArray($products)) {
            throw new RuntimeException('El catálogo guardado tiene un formato inválido.');
        }

        foreach ($products as $product) {
            if (is_array($product) && ($product['category'] ?? null) === $key) {
                throw new RuntimeException('No se puede eliminar esta categoría porque tiene productos asociados. Cambia primero esos productos a otra categoría.');
            }
        }

        if (array_key_exists($key, $defaultCategories)) {
            $hiddenCategories = readJsonFile(storagePath('categories.hidden.json'), []);
            if (!is_array($hiddenCategories)
                || !isListArray($hiddenCategories)
                || array_filter($hiddenCategories, static fn (mixed $hiddenKey): bool => !is_string($hiddenKey)) !== []) {
                throw new RuntimeException('Las categorías ocultas tienen un formato inválido.');
            }
            if (!in_array($key, $hiddenCategories, true)) {
                $hiddenCategories[] = $key;
                writeJsonFile(storagePath('categories.hidden.json'), $hiddenCategories);
            }
        }

        if ($savedCategoryIndex !== null) {
            array_splice($savedCategories, $savedCategoryIndex, 1);
            writeJsonFile(storagePath('categories.json'), $savedCategories);
            if ($removedCategoryImage !== '') {
                try {
                    removeUploadedImage($removedCategoryImage);
                } catch (RuntimeException $cleanupException) {
                    error_log('ARAS deleted category image cleanup failed: ' . $cleanupException->getMessage());
                }
            }
        }
    } finally {
        if ($inventoryLock !== null) {
            releaseProductInventoryLock($inventoryLock);
        }
        flock($categoryLock, LOCK_UN);
        fclose($categoryLock);
    }
}

function readProducts(): array
{
    $products = readJsonFile(storagePath('products.json'), []);
    if (!is_array($products) || !isListArray($products)) {
        throw new RuntimeException('El catálogo guardado tiene un formato inválido.');
    }

    $categories = productCategories();
    foreach ($products as &$product) {
        if (!is_array($product)
            || !isset($product['id'], $product['name'], $product['price'], $product['category'], $product['stock'], $product['image'])
            || !is_string($product['id'])
            || !preg_match('/^[a-f0-9]{32}$/', $product['id'])
            || !is_string($product['name'])
            || filter_var($product['price'], FILTER_VALIDATE_INT) === false
            || !is_string($product['category'])
            || !array_key_exists($product['category'], $categories)
            || filter_var($product['stock'], FILTER_VALIDATE_INT) === false
            || !is_string($product['image'])
            || !preg_match('/^[a-f0-9]{32}\.webp$/', $product['image'])
            || (array_key_exists('is_new', $product) && !is_bool($product['is_new']))
            || !is_bool($product['active'])) {
            throw new RuntimeException('Un producto guardado tiene datos inválidos.');
        }
        $product['variants'] = validateProductVariants($product['variants'] ?? []);
        if (!array_key_exists('images', $product)) {
            $product['images'] = [$product['image']];
        }
        if (!is_array($product['images'])
            || !isListArray($product['images'])
            || count($product['images']) < 1
            || count($product['images']) > 10
            || $product['images'][0] !== $product['image']) {
            throw new RuntimeException('La galería de imágenes de un producto tiene un formato inválido.');
        }
        foreach ($product['images'] as $image) {
            if (!is_string($image) || !preg_match('/^[a-f0-9]{32}\.webp$/', $image)) {
                throw new RuntimeException('Una imagen de producto tiene un nombre inválido.');
            }
        }
    }
    unset($product);

    return $products;
}

function validateProductVariants(mixed $variants): array
{
    if (!is_array($variants) || !isListArray($variants) || count($variants) > 3) {
        throw new RuntimeException('Las variaciones deben ser una lista de hasta 3 opciones.');
    }

    $validated = [];
    foreach ($variants as $variant) {
        if (!is_array($variant)
            || !is_string($variant['name'] ?? null)
            || !is_array($variant['options'] ?? null)
            || !isListArray($variant['options'])
            || count($variant['options']) < 1
            || count($variant['options']) > 30) {
            throw new RuntimeException('Cada variación necesita un nombre y entre 1 y 30 opciones.');
        }

        $name = trim($variant['name']);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 40) {
            throw new RuntimeException('El nombre de cada variación debe tener entre 1 y 40 caracteres.');
        }

        $options = [];
        $seenLabels = [];
        foreach ($variant['options'] as $option) {
            if (!is_array($option) || !is_string($option['label'] ?? null)) {
                throw new RuntimeException('Una opción de variación no tiene un nombre válido.');
            }
            $label = trim($option['label']);
            if ($label === '' || mb_strlen($label, 'UTF-8') > 50) {
                throw new RuntimeException('El nombre de cada opción debe tener entre 1 y 50 caracteres.');
            }
            $normalizedLabel = mb_strtolower($label, 'UTF-8');
            if (isset($seenLabels[$normalizedLabel])) {
                throw new RuntimeException('No repitas opciones dentro de una misma variación.');
            }
            $seenLabels[$normalizedLabel] = true;

            $validatedOption = ['label' => $label];
            $colors = $option['colors'] ?? [];
            if (!is_array($colors) || !isListArray($colors) || count($colors) > 4) {
                throw new RuntimeException('Los colores deben ser una lista de hasta 4 tonos hexadecimales.');
            }
            if ($colors !== []) {
                foreach ($colors as $color) {
                    if (!is_string($color) || !preg_match('/^#[a-fA-F0-9]{6}$/', $color)) {
                        throw new RuntimeException('Los colores deben usar el formato hexadecimal #RRGGBB.');
                    }
                }
                $validatedOption['colors'] = array_values($colors);
            }
            $options[] = $validatedOption;
        }

        $validated[] = ['name' => $name, 'options' => $options];
    }

    return $validated;
}

function productImportColumnAliases(): array
{
    return [
        'name' => ['name', 'nombre'],
        'price' => ['price', 'precio'],
        'category' => ['category', 'categoría', 'categoria'],
        'stock' => ['stock', 'existencias', 'inventario'],
        'active' => ['active', 'publicado'],
        'is_new' => ['is_new', 'nuevo'],
        'image' => ['image', 'foto', 'imagen'],
        'variants' => ['variants', 'opciones'],
        'variant_1' => ['variant_1', 'opción 1', 'opcion 1'],
        'variant_2' => ['variant_2', 'opción 2', 'opcion 2'],
        'variant_3' => ['variant_3', 'opción 3', 'opcion 3'],
    ];
}

function normalizeProductImportHeaders(array $headers): ?array
{
    $aliases = productImportColumnAliases();
    $normalized = [];
    foreach ($headers as $header) {
        $header = mb_strtolower(trim((string) $header), 'UTF-8');
        $column = null;
        foreach ($aliases as $key => $names) {
            if (in_array($header, $names, true)) {
                $column = $key;
                break;
            }
        }
        if ($column === null || in_array($column, $normalized, true)) {
            return null;
        }
        $normalized[] = $column;
    }

    $required = ['name', 'price', 'category', 'stock', 'active', 'is_new', 'image'];
    if (array_slice($normalized, 0, count($required)) !== $required) {
        return null;
    }
    $variantColumns = array_slice($normalized, count($required));
    if ($variantColumns !== ['variants'] && $variantColumns !== ['variant_1', 'variant_2', 'variant_3']) {
        return null;
    }

    return $normalized;
}

function parseProductImportOptionGroup(string $value): array
{
    $separator = strpos($value, ':');
    if ($separator === false) {
        throw new RuntimeException('Escribe el nombre de la opción seguido de dos puntos, por ejemplo: Tamaño: 30 ml | 50 ml.');
    }
    $name = trim(substr($value, 0, $separator));
    $optionValues = array_map('trim', explode('|', substr($value, $separator + 1)));
    if ($name === '' || $optionValues === [] || in_array('', $optionValues, true)) {
        throw new RuntimeException('Cada opción necesita un nombre y al menos un valor, separados con |.');
    }

    $options = [];
    foreach ($optionValues as $optionValue) {
        $colorSeparator = strpos($optionValue, '=');
        $label = $colorSeparator === false ? $optionValue : trim(substr($optionValue, 0, $colorSeparator));
        if ($label === '') {
            throw new RuntimeException('Cada valor de opción debe tener un nombre.');
        }
        $option = ['label' => $label];
        if ($colorSeparator !== false) {
            $colors = array_map('trim', explode('+', substr($optionValue, $colorSeparator + 1)));
            if (count($colors) > 4 || in_array('', $colors, true)) {
                throw new RuntimeException('Un color debe usar #RRGGBB; para un degradado, separa hasta cuatro tonos con +.');
            }
            $option['colors'] = $colors;
        }
        $options[] = $option;
    }

    return ['name' => $name, 'options' => $options];
}

function readProductWorkbookZip(string $path): array
{
    $archive = file_get_contents($path);
    if (!is_string($archive) || strlen($archive) < 22 || strlen($archive) > 30 * 1024 * 1024) {
        throw new RuntimeException('El archivo Excel está vacío o supera el límite de 30 MB.');
    }

    $directoryEnd = strrpos($archive, "PK\x05\x06");
    if ($directoryEnd === false || $directoryEnd + 22 > strlen($archive)) {
        throw new RuntimeException('El archivo no es un libro Excel .xlsx válido.');
    }
    $end = unpack('vdisk/vdirectory_disk/vdisk_entries/ventry_count/Vdirectory_size/Vdirectory_offset/vcomment_length', substr($archive, $directoryEnd + 4, 18));
    if (!is_array($end)
        || $end['disk'] !== 0
        || $end['directory_disk'] !== 0
        || $end['disk_entries'] !== $end['entry_count']
        || $end['entry_count'] > 2000
        || $end['directory_offset'] + $end['directory_size'] > strlen($archive)) {
        throw new RuntimeException('El libro Excel está dañado o contiene demasiados archivos.');
    }

    $entries = [];
    $offset = $end['directory_offset'];
    $totalUncompressed = 0;
    for ($index = 0; $index < $end['entry_count']; $index++) {
        if (substr($archive, $offset, 4) !== "PK\x01\x02" || $offset + 46 > strlen($archive)) {
            throw new RuntimeException('No se pudo leer el contenido del libro Excel.');
        }
        $entry = unpack('vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk/vinternal/Vexternal/Vlocal_offset', substr($archive, $offset + 4, 42));
        if (!is_array($entry)) {
            throw new RuntimeException('No se pudo leer un archivo dentro del libro Excel.');
        }
        $recordLength = 46 + $entry['name_length'] + $entry['extra_length'] + $entry['comment_length'];
        $name = substr($archive, $offset + 46, $entry['name_length']);
        $offset += $recordLength;
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || ($entry['flags'] & 1) !== 0) {
            throw new RuntimeException('El libro Excel contiene una ruta de archivo no permitida.');
        }
        if (!in_array($entry['method'], [0, 8], true)
            || $entry['uncompressed'] > 32 * 1024 * 1024
            || $totalUncompressed + $entry['uncompressed'] > 50 * 1024 * 1024) {
            throw new RuntimeException('El libro Excel contiene archivos comprimidos no compatibles o demasiado grandes.');
        }

        $localOffset = $entry['local_offset'];
        if (substr($archive, $localOffset, 4) !== "PK\x03\x04" || $localOffset + 30 > strlen($archive)) {
            throw new RuntimeException('No se pudo extraer un archivo del libro Excel.');
        }
        $local = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr($archive, $localOffset + 4, 26));
        $dataOffset = $localOffset + 30 + $local['name_length'] + $local['extra_length'];
        $compressed = substr($archive, $dataOffset, $entry['compressed']);
        if (strlen($compressed) !== $entry['compressed']) {
            throw new RuntimeException('Un archivo dentro del libro Excel está incompleto.');
        }
        $contents = $entry['method'] === 8 ? gzinflate($compressed) : $compressed;
        if (!is_string($contents)
            || strlen($contents) !== $entry['uncompressed']
            || sprintf('%u', crc32($contents)) !== sprintf('%u', $entry['crc'])) {
            throw new RuntimeException('Un archivo dentro del libro Excel no se pudo verificar.');
        }
        $entries[$name] = $contents;
        $totalUncompressed += strlen($contents);
    }

    return $entries;
}

function productWorkbookPath(string $base, string $target): string
{
    $parts = str_starts_with($target, '/') ? [] : explode('/', trim($base, '/'));
    foreach (explode('/', ltrim(rawurldecode($target), '/')) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function readProductWorkbookXml(string $xml): SimpleXMLElement
{
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$document instanceof SimpleXMLElement) {
        throw new RuntimeException('El contenido interno del libro Excel está dañado.');
    }
    return $document;
}

function readProductWorkbook(string $path): array
{
    $files = readProductWorkbookZip($path);
    if (!isset($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'])) {
        throw new RuntimeException('El archivo no contiene una hoja de cálculo Excel válida.');
    }
    $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $packageNamespace = 'http://schemas.openxmlformats.org/package/2006/relationships';

    $workbook = readProductWorkbookXml($files['xl/workbook.xml']);
    $workbookRelationships = readProductWorkbookXml($files['xl/_rels/workbook.xml.rels']);
    $relationshipTargets = [];
    foreach ($workbookRelationships->children($packageNamespace)->Relationship as $relationship) {
        $attributes = $relationship->attributes();
        $relationshipTargets[(string) $attributes['Id']] = (string) $attributes['Target'];
    }
    $sheets = $workbook->children($mainNamespace)->sheets->children($mainNamespace)->sheet;
    if (count($sheets) < 1) {
        throw new RuntimeException('El libro Excel no tiene hojas para importar.');
    }
    $sheetRelationship = (string) $sheets[0]->attributes($relationshipNamespace)['id'];
    if (!isset($relationshipTargets[$sheetRelationship])) {
        throw new RuntimeException('No se pudo localizar la hoja principal del libro Excel.');
    }
    $sheetPath = productWorkbookPath('xl', $relationshipTargets[$sheetRelationship]);
    if (!isset($files[$sheetPath])) {
        throw new RuntimeException('La hoja de productos está incompleta.');
    }
    $sharedStrings = [];
    if (isset($files['xl/sharedStrings.xml'])) {
        $sharedDocument = readProductWorkbookXml($files['xl/sharedStrings.xml']);
        foreach ($sharedDocument->children($mainNamespace)->si as $item) {
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                $text .= (string) $part;
            }
            $sharedStrings[] = $text;
        }
    }

    $sheet = readProductWorkbookXml($files[$sheetPath]);
    $sheetRows = [];
    $lastRow = 0;
    foreach ($sheet->children($mainNamespace)->sheetData->children($mainNamespace)->row as $row) {
        $rowNumber = (int) $row->attributes()['r'];
        if ($rowNumber < 1 || $rowNumber > 10000) {
            throw new RuntimeException('La plantilla no puede contener más de 10.000 filas.');
        }
        $lastRow = max($lastRow, $rowNumber);
        foreach ($row->children($mainNamespace)->c as $cell) {
            $cellAttributes = $cell->attributes();
            $reference = (string) $cellAttributes['r'];
            if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $match)) {
                continue;
            }
            $columnIndex = 0;
            foreach (str_split(strtoupper($match[1])) as $letter) {
                $columnIndex = $columnIndex * 26 + ord($letter) - 64;
            }
            $columnIndex--;
            $type = (string) $cellAttributes['t'];
            if ($type === 'inlineStr') {
                $value = '';
                foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                    $value .= (string) $part;
                }
            } else {
                $value = (string) $cell->children($mainNamespace)->v;
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                }
            }
            $sheetRows[$rowNumber][$columnIndex] = trim($value);
        }
    }

    $headerCells = $sheetRows[1] ?? [];
    if ($headerCells === []) {
        throw new RuntimeException('La primera fila debe contener los nombres de las columnas.');
    }
    ksort($headerCells);
    $headers = normalizeProductImportHeaders(array_values($headerCells));
    if ($headers === null) {
        throw new RuntimeException('Los encabezados de la primera fila no coinciden con la plantilla de productos.');
    }
    $imageColumn = array_search('image', $headers, true);
    $imagesByRow = [];
    $temporaryImages = [];
    $drawingId = null;
    $sheetRelationshipsPath = dirname($sheetPath) . '/_rels/' . basename($sheetPath) . '.rels';
    if (isset($files[$sheetRelationshipsPath])) {
        $sheetRelationships = readProductWorkbookXml($files[$sheetRelationshipsPath]);
        foreach ($sheetRelationships->children($packageNamespace)->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            if (str_ends_with((string) $attributes['Type'], '/drawing')) {
                $drawingId = productWorkbookPath(dirname($sheetPath), (string) $attributes['Target']);
                break;
            }
        }
    }
    if ($drawingId !== null && isset($files[$drawingId])) {
        $drawing = readProductWorkbookXml($files[$drawingId]);
        $drawingRelationshipsPath = dirname($drawingId) . '/_rels/' . basename($drawingId) . '.rels';
        $drawingRelationships = isset($files[$drawingRelationshipsPath])
            ? readProductWorkbookXml($files[$drawingRelationshipsPath])
            : null;
        $mediaTargets = [];
        if ($drawingRelationships instanceof SimpleXMLElement) {
            foreach ($drawingRelationships->children($packageNamespace)->Relationship as $relationship) {
                $attributes = $relationship->attributes();
                $mediaTargets[(string) $attributes['Id']] = productWorkbookPath(dirname($drawingId), (string) $attributes['Target']);
            }
        }
        $drawingNamespace = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
        $drawing->registerXPathNamespace('xdr', $drawingNamespace);
        $totalImageSize = 0;
        foreach (array_merge(
            $drawing->xpath('//xdr:oneCellAnchor') ?: [],
            $drawing->xpath('//xdr:twoCellAnchor') ?: []
        ) as $anchor) {
            $from = $anchor->children($drawingNamespace)->from;
            if ((int) $from->children($drawingNamespace)->col !== $imageColumn) {
                continue;
            }
            $rowNumber = (int) $from->children($drawingNamespace)->row + 1;
            $anchor->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $blips = $anchor->xpath('.//a:blip') ?: [];
            if ($rowNumber < 2 || $rowNumber > $lastRow || count($blips) !== 1) {
                continue;
            }
            $embeddedId = (string) $blips[0]->attributes($relationshipNamespace)['embed'];
            $mediaPath = $mediaTargets[$embeddedId] ?? '';
            if ($mediaPath === '' || !isset($files[$mediaPath])) {
                continue;
            }
            if (isset($imagesByRow[$rowNumber])) {
                $imagesByRow[$rowNumber] = '!duplicate!';
                continue;
            }
            $imageContents = $files[$mediaPath];
            $imageSize = strlen($imageContents);
            $totalImageSize += $imageSize;
            if ($imageSize > 5 * 1024 * 1024 || $totalImageSize > 25 * 1024 * 1024) {
                $imagesByRow[$rowNumber] = '!oversized!';
                continue;
            }
            $temporaryPath = tempnam(sys_get_temp_dir(), 'aras-xlsx-image-');
            if (!is_string($temporaryPath) || file_put_contents($temporaryPath, $imageContents) !== strlen($imageContents)) {
                if (is_string($temporaryPath) && is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
                foreach ($temporaryImages as $previousTemporaryPath) {
                    if (is_file($previousTemporaryPath)) {
                        unlink($previousTemporaryPath);
                    }
                }
                throw new RuntimeException('No se pudo preparar una foto de la hoja de cálculo.');
            }
            $temporaryImages[] = $temporaryPath;
            $imagesByRow[$rowNumber] = $temporaryPath;
        }
    }

    $rows = [];
    for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
        $cells = $sheetRows[$rowNumber] ?? [];
        $values = [];
        foreach ($headers as $columnIndex => $key) {
            $values[$key] = $cells[$columnIndex] ?? '';
        }
        if (implode('', $values) === '' && !isset($imagesByRow[$rowNumber])) {
            continue;
        }
        $rows[] = ['line' => $rowNumber, 'values' => $values, 'image_path' => $imagesByRow[$rowNumber] ?? null];
        if (count($rows) > 500) {
            foreach ($temporaryImages as $temporaryPath) {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
            throw new RuntimeException('Puedes importar hasta 500 productos por archivo.');
        }
    }

    return ['headers' => $headers, 'rows' => $rows, 'temporary_images' => $temporaryImages];
}

function productWorkbookEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function createProductWorkbookZip(array $files): string
{
    $archive = '';
    $directory = '';
    $timestamp = getdate();
    $dosTime = (($timestamp['hours'] & 31) << 11) | (($timestamp['minutes'] & 63) << 5) | (int) ($timestamp['seconds'] / 2);
    $dosDate = ((max(1980, $timestamp['year']) - 1980) << 9) | (($timestamp['mon'] & 15) << 5) | ($timestamp['mday'] & 31);

    foreach ($files as $name => $contents) {
        if (!is_string($contents) || strlen($name) > 65535) {
            throw new RuntimeException('No se pudo preparar la descarga de la plantilla Excel.');
        }
        $nameLength = strlen($name);
        $uncompressedSize = strlen($contents);
        $compressed = gzdeflate($contents, 6);
        if (!is_string($compressed)) {
            throw new RuntimeException('No se pudo comprimir la plantilla Excel.');
        }
        $compressedSize = strlen($compressed);
        $crc = crc32($contents);
        $offset = strlen($archive);
        $archive .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, $nameLength, 0)
            . $name . $compressed;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, $nameLength, 0, 0, 0, 0, 0, $offset)
            . $name;
    }

    $directoryOffset = strlen($archive);
    $directorySize = strlen($directory);
    $entryCount = count($files);
    return $archive . $directory . pack('VvvvvVVv', 0x06054b50, 0, 0, $entryCount, $entryCount, $directorySize, $directoryOffset, 0);
}

function buildProductImportWorkbook(array $categories): string
{
    $templatePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'plantilla-productos.xlsx';
    $files = readProductWorkbookZip($templatePath);
    foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $requiredFile) {
        if (!isset($files[$requiredFile])) {
            throw new RuntimeException('No se pudo preparar la plantilla de productos.');
        }
    }

    $categoryRows = '';
    $rowNumber = 3;
    foreach ($categories as $key => $label) {
        $safeLabel = productWorkbookEscape((string) $label);
        $safeKey = productWorkbookEscape((string) $key);
        $categoryRows .= '<row r="' . $rowNumber . '"><c r="A' . $rowNumber . '" s="2" t="inlineStr"><is><t>' . $safeLabel . '</t></is></c>'
            . '<c r="B' . $rowNumber . '" s="2" t="inlineStr"><is><t>' . $safeKey . '</t></is></c></row>';
        $rowNumber++;
    }
    if ($rowNumber === 3) {
        $categoryRows = '<row r="3"><c r="A3" s="2" t="inlineStr"><is><t></t></is></c><c r="B3" s="2" t="inlineStr"><is><t></t></is></c></row>';
        $rowNumber++;
    }
    $lastCategoryRow = $rowNumber - 1;
    $files['xl/worksheets/sheet3.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="1" width="32" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/></cols>'
        . '<sheetData><row r="1" ht="30" customHeight="1"><c r="A1" s="3" t="inlineStr"><is><t>Categorías disponibles</t></is></c></row>'
        . '<row r="2" ht="24" customHeight="1"><c r="A2" s="1" t="inlineStr"><is><t>Nombre de categoría</t></is></c><c r="B2" s="1" t="inlineStr"><is><t>Clave para CSV</t></is></c></row>'
        . $categoryRows
        . '</sheetData><autoFilter ref="A2:B' . $lastCategoryRow . '"/><mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells></worksheet>';

    $sheet = $files['xl/worksheets/sheet1.xml'];
    if (str_contains($sheet, '<dataValidations count="2">')) {
        $sheet = str_replace('<dataValidations count="2">', '<dataValidations count="3">', $sheet);
        $sheet = str_replace('</dataValidations>', '<dataValidation type="list" allowBlank="1" showErrorMessage="0" sqref="C2:C501"><formula1>ListaCategorias</formula1></dataValidation></dataValidations>', $sheet);
    } else {
        throw new RuntimeException('No se pudo agregar el selector de categorías a la plantilla.');
    }
    $files['xl/worksheets/sheet1.xml'] = $sheet;

    $workbook = $files['xl/workbook.xml'];
    $workbook = str_replace('</sheets>', '<sheet name="Categorías" sheetId="3" r:id="rId4"/></sheets>', $workbook);
    $definedNames = '<definedNames><definedName name="ListaCategorias">&apos;Categorías&apos;!$A$3:$A$' . $lastCategoryRow . '</definedName></definedNames>';
    if (!str_contains($workbook, '<calcPr')) {
        throw new RuntimeException('No se pudo completar la estructura de la plantilla de Excel.');
    }
    $workbook = preg_replace_callback(
        '/(<calcPr\b)/',
        static fn (array $matches): string => $definedNames . $matches[1],
        $workbook,
        1,
        $replacementCount
    );
    if (!is_string($workbook) || $replacementCount !== 1) {
        throw new RuntimeException('No se pudo agregar la lista de categorías a la plantilla.');
    }
    $files['xl/workbook.xml'] = $workbook;

    $relationships = $files['xl/_rels/workbook.xml.rels'];
    $relationships = str_replace('</Relationships>', '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/></Relationships>', $relationships);
    $files['xl/_rels/workbook.xml.rels'] = $relationships;

    $contentTypes = $files['[Content_Types].xml'];
    $contentTypes = str_replace('</Types>', '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>', $contentTypes);
    $files['[Content_Types].xml'] = $contentTypes;

    $styles = $files['xl/styles.xml'];
    $styles = str_replace('fontId="1" fillId="3" borderId="1"', 'fontId="0" fillId="3" borderId="1"', $styles);
    $files['xl/styles.xml'] = $styles;
    $instructions = $files['xl/worksheets/sheet2.xml'];
    $instructions = str_replace('</worksheet>', '<mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells></worksheet>', $instructions);
    $files['xl/worksheets/sheet2.xml'] = $instructions;

    return createProductWorkbookZip($files);
}

function productImportCategorySlug(string $label): string
{
    $slugLabel = strtr(mb_strtolower(trim($label), 'UTF-8'), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ü' => 'u', 'ñ' => 'n',
    ]);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $slugLabel), '-');
}

function ensureProductImportCategory(string $label, array &$categories, array &$savedCategories): array
{
    $label = trim($label);
    foreach ($categories as $key => $existingLabel) {
        if ($key === $label || mb_strtolower($existingLabel, 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
            return [$key, false];
        }
    }
    if ($label === '' || mb_strlen($label, 'UTF-8') > 50) {
        throw new RuntimeException('El nombre de una categoría nueva debe tener entre 1 y 50 caracteres.');
    }
    $baseKey = productImportCategorySlug($label);
    if ($baseKey === '') {
        throw new RuntimeException('No se pudo generar una clave para la categoría "' . $label . '".');
    }
    $key = $baseKey;
    $suffix = 2;
    while (array_key_exists($key, $categories)) {
        $key = $baseKey . '-' . $suffix;
        $suffix++;
    }

    $categories[$key] = $label;
    $savedCategories[] = ['key' => $key, 'label' => $label];
    return [$key, true];
}

function saveUploadedImage(array $upload, int $maximumSize = 5 * 1024 * 1024): string
{
    if (!isset($upload['error'], $upload['tmp_name'], $upload['size'])
        || is_array($upload['error'])
        || (int) $upload['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file((string) $upload['tmp_name'])) {
        throw new RuntimeException('No se pudo recibir la imagen. Intenta subirla otra vez.');
    }

    if ((int) $upload['size'] < 1 || (int) $upload['size'] > $maximumSize) {
        throw new RuntimeException('La imagen supera el límite permitido de ' . (int) ceil($maximumSize / 1048576) . ' MB.');
    }

    return saveImageFile((string) $upload['tmp_name'], (int) $upload['size'], $maximumSize);
}

function saveImageFile(string $sourcePath, int $size, int $maximumSize = 5 * 1024 * 1024): string
{
    if ($size < 1 || $size > $maximumSize || !is_file($sourcePath)) {
        throw new RuntimeException('La imagen supera el límite permitido de ' . (int) ceil($maximumSize / 1048576) . ' MB.');
    }

    $imageInfo = getimagesize($sourcePath);
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->file($sourcePath);

    if ($imageInfo === false
        || !in_array($detectedMimeType, $allowedMimeTypes, true)
        || ($imageInfo['mime'] ?? '') !== $detectedMimeType) {
        throw new RuntimeException('Formato de imagen no permitido. Usa JPG, PNG o WebP.');
    }

    [$width, $height] = $imageInfo;
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 24000000) {
        throw new RuntimeException('Las dimensiones máximas son 6000 × 6000 píxeles.');
    }

    $contents = file_get_contents($sourcePath);
    $image = $contents === false ? false : imagecreatefromstring($contents);
    if ($image === false) {
        throw new RuntimeException('No se pudo procesar la imagen subida.');
    }
    if (!imageistruecolor($image) && !imagepalettetotruecolor($image)) {
        imagedestroy($image);
        throw new RuntimeException('No se pudo convertir la paleta de colores de la imagen.');
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);

    $uploadDirectory = storagePath('uploads');
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        imagedestroy($image);
        throw new RuntimeException('No se pudo crear el directorio de imágenes.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.webp';
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
    $saved = imagewebp($image, $destination, 86);
    imagedestroy($image);

    if (!$saved) {
        if (is_file($destination) && !unlink($destination)) {
            error_log('Entresueño could not remove a partial image upload.');
        }
        throw new RuntimeException('No se pudo guardar la imagen procesada.');
    }

    chmod($destination, 0640);
    return $fileName;
}

function saveUploadedImages(
    array $uploads,
    int $maximumCount = 10,
    int $maximumSize = 3 * 1024 * 1024
): array
{
    if (!isset($uploads['name'], $uploads['type'], $uploads['tmp_name'], $uploads['error'], $uploads['size'])
        || !is_array($uploads['name'])
        || !is_array($uploads['type'])
        || !is_array($uploads['tmp_name'])
        || !is_array($uploads['error'])
        || !is_array($uploads['size'])) {
        throw new RuntimeException('No se pudo recibir la galería de imágenes. Selecciona las fotos otra vez.');
    }

    $fileCount = count($uploads['name']);
    if ($fileCount > $maximumCount) {
        throw new RuntimeException('Puedes subir un máximo de ' . $maximumCount . ' fotos por producto.');
    }
    if ($fileCount === 0 || !array_filter(
        $uploads['error'],
        static fn ($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE
    )) {
        return [];
    }
    if (count($uploads['type']) !== $fileCount
        || count($uploads['tmp_name']) !== $fileCount
        || count($uploads['error']) !== $fileCount
        || count($uploads['size']) !== $fileCount) {
        throw new RuntimeException('Los datos de las fotos recibidas no son válidos.');
    }

    $savedImages = [];
    try {
        for ($index = 0; $index < $fileCount; $index++) {
            $savedImages[] = saveUploadedImage([
                'name' => $uploads['name'][$index] ?? '',
                'type' => $uploads['type'][$index] ?? '',
                'tmp_name' => $uploads['tmp_name'][$index] ?? '',
                'error' => $uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $uploads['size'][$index] ?? 0,
            ], $maximumSize);
        }
    } catch (RuntimeException | Error $exception) {
        foreach ($savedImages as $fileName) {
            try {
                removeUploadedImage($fileName);
            } catch (RuntimeException $cleanupException) {
                error_log('Entresueño partial product gallery cleanup failed: ' . $cleanupException->getMessage());
            }
        }
        throw $exception;
    }

    return $savedImages;
}

function removeUploadedImage(mixed $fileName): void
{
    if (is_string($fileName) && preg_match('/^[a-f0-9]{32}\.webp$/', $fileName)) {
        $path = storagePath('uploads') . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('No se pudo eliminar una imagen reemplazada.');
        }
    }
}
