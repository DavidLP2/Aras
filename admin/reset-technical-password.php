<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$environment = $argv[1] ?? '';
$username = $argv[2] ?? '';
if (!in_array($environment, ['local', 'production'], true)
    || !preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
    fwrite(STDERR, "Uso: php admin/reset-technical-password.php <local|production> <usuario>\n");
    exit(1);
}

$_SERVER['SERVER_NAME'] = $environment === 'local' ? 'localhost' : 'entresueno.shop';
require_once dirname(__DIR__) . '/includes/admin-common.php';

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

try {
    $statement = databaseConnection()->prepare(
        'UPDATE `Usuarios`
         SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP
         WHERE username = :username AND role = :role AND is_active = 1'
    );
    $statement->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'username' => $username,
        'role' => 'Tecnico',
    ]);

    if ($statement->rowCount() !== 1) {
        fwrite(STDERR, "No se encontró una cuenta técnica activa con ese usuario; no se cambió ninguna contraseña.\n");
        exit(1);
    }
} catch (PDOException | RuntimeException $exception) {
    error_log('Entresueño technical password reset failed: ' . $exception->getMessage());
    fwrite(STDERR, "No se pudo cambiar la contraseña técnica. Verifica la conexión y que la cuenta exista.\n");
    exit(1);
}

fwrite(STDOUT, "Contraseña técnica renovada para el entorno $environment.\n");
fwrite(STDOUT, "Usuario: $username\n");
fwrite(STDOUT, "Nueva contraseña: $password\n");
fwrite(STDOUT, "Guárdala en un lugar seguro; no se volverá a mostrar.\n");
