<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = '';
if (function_exists('readline')) {
    $password = (string) readline('Contrasena del superadministrador (minimo 8 caracteres): ');
} else {
    fwrite(STDOUT, "Contrasena del superadministrador (minimo 8 caracteres): ");
    $password = trim((string) fgets(STDIN));
}

if (strlen($password) < 8) {
    fwrite(STDERR, "La contrasena debe tener al menos 8 caracteres.\n");
    exit(1);
}

fwrite(STDOUT, password_hash($password, PASSWORD_DEFAULT) . PHP_EOL);
