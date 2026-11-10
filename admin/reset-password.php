<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
startAdminSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$error = '';
$success = false;
$resetToken = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$tokenIsValid = false;

try {
    $tokenIsValid = isValidPanelPasswordResetToken($resetToken);
} catch (PDOException | RuntimeException $exception) {
    error_log('Entresueño password reset token check failed: ' . $exception->getMessage());
    http_response_code(503);
    $error = 'No se pudo validar el enlace en este momento. Inténtalo más tarde.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Recarga el enlace de recuperación.';
    } else {
        $password = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if (mb_strlen($password, 'UTF-8') > 128) {
            $error = 'La contraseña no puede superar 128 caracteres.';
        } elseif (!hash_equals($password, $confirmation)) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                $success = resetPanelPassword($resetToken, $password);
                if (!$success) {
                    $tokenIsValid = false;
                    $error = 'El enlace ya fue utilizado o venció. Solicita uno nuevo.';
                }
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (PDOException | RuntimeException $exception) {
                error_log('Entresueño password reset failed: ' . $exception->getMessage());
                http_response_code(503);
                $error = 'No se pudo cambiar la contraseña en este momento. Inténtalo más tarde.';
            }
        }
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/aras-favicon.svg" type="image/svg+xml">
    <link rel="icon" href="../img/favicon.ico?v=2" sizes="any">
    <title>Nueva contraseña | ARAS</title>
    <link rel="stylesheet" href="admin.css?v=17">
</head>
<body>
    <main class="admin-shell">
        <header class="admin-topbar">
            <a class="admin-brand" href="./"><span aria-hidden="true">A</span> ARAS <small>ADMINISTRACIÓN</small></a>
        </header>

        <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
        <?php if ($success): ?><p class="form-success" role="status">Tu contraseña se actualizó correctamente. Ya puedes iniciar sesión.</p><?php endif; ?>

        <section class="admin-card auth-panel">
            <?php if ($success): ?>
                <a class="button-link" href="./">Ir al inicio de sesión</a>
            <?php elseif ($tokenIsValid): ?>
                <p class="eyebrow">SEGURIDAD DE LA CUENTA</p>
                <h1>Crear nueva contraseña</h1>
                <p>Elige una contraseña de entre 8 y 128 caracteres.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrf) ?>">
                    <input type="hidden" name="token" value="<?= escapeHtml($resetToken) ?>">
                    <label for="new-password">Nueva contraseña</label>
                    <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>
                    <label for="password-confirmation">Confirmar contraseña</label>
                    <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>
                    <button type="submit">Guardar nueva contraseña</button>
                </form>
            <?php else: ?>
                <p class="eyebrow">ENLACE NO DISPONIBLE</p>
                <h1>Solicita un nuevo enlace</h1>
                <p>Este enlace ya fue utilizado o venció.</p>
                <a class="button-link" href="forgot-password">Recuperar contraseña</a>
            <?php endif; ?>
        </section>

        <footer class="admin-footer">
            <span>© <?= date('Y') ?> ARAS · Administración</span>
            <span>Desarrollado por <a href="https://www.strokbig.com" target="_blank" rel="noopener noreferrer">STROKBIG</a></span>
        </footer>
    </main>
</body>
</html>
