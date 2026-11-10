<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$environment = $argv[1] ?? '';
if (!in_array($environment, ['local', 'production'], true)) {
    fwrite(STDERR, "Uso: php admin/create-technical-user.php <local|production>\n");
    exit(1);
}

$_SERVER['SERVER_NAME'] = $environment === 'local' ? 'localhost' : 'entresueno.shop';
require_once dirname(__DIR__) . '/includes/admin-common.php';

$username = '';
if (function_exists('readline')) {
    $username = trim((string) readline('Usuario tecnico: '));
} else {
    fwrite(STDOUT, "Usuario tecnico: ");
    $username = trim((string) fgets(STDIN));
}

if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
    fwrite(STDERR, "El usuario debe tener entre 3 y 40 caracteres: letras, numeros, punto, guion o guion bajo.\n");
    exit(1);
}

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

try {
    $connection = databaseConnection();
    $existingTechnicalUsers = (int) $connection->query(
        "SELECT COUNT(*) FROM `Usuarios` WHERE role = 'Tecnico'"
    )->fetchColumn();
    if ($existingTechnicalUsers > 0) {
        fwrite(STDERR, "Ya existe una cuenta tecnica en esta base de datos; no se creo otra.\n");
        exit(1);
    }

    $statement = $connection->prepare(
        'INSERT INTO `Usuarios` (username, password_hash, role, is_active)
         VALUES (:username, :password_hash, :role, 1)'
    );
    $statement->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'Tecnico',
    ]);
} catch (PDOException | RuntimeException $exception) {
    error_log('Entresueño technical account bootstrap failed: ' . $exception->getMessage());
    if ($exception instanceof RuntimeException) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    } else {
        fwrite(STDERR, "No se pudo conectar o insertar en MySQL (SQLSTATE " . $exception->getCode() . "). Verifica host, credenciales, permisos y tabla Usuarios.\n");
    }
    fwrite(STDERR, "No se creo ninguna cuenta tecnica.\n");
    exit(1);
}

fwrite(STDOUT, "Usuario tecnico creado para entorno $environment.\n");
fwrite(STDOUT, "Usuario: $username\n");
fwrite(STDOUT, "Contrasena temporal: $password\n");
fwrite(STDOUT, "Guarda esta contrasena en un lugar seguro; no se volvera a mostrar.\n");
