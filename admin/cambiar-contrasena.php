<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
startAdminSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = '';
$success = (string) ($_SESSION['password_change_success'] ?? '');
unset($_SESSION['password_change_success']);
$username = (string) ($_SESSION['admin_user'] ?? '');
$displayName = $username;

if ($username === '') {
    header('Location: ./', true, 303);
    exit;
}

if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 7200) {
    unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
    header('Location: ./', true, 303);
    exit;
}

try {
    $role = activeUserRole($username);
    if ($role === null) {
        unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
        header('Location: ./', true, 303);
        exit;
    }
    $_SESSION['admin_role'] = $role;
    $profile = panelUserProfile($username);
    if ($profile === null) {
        unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
        header('Location: ./', true, 303);
        exit;
    }
    if (is_string($profile['full_name']) && trim($profile['full_name']) !== '') {
        $displayName = (string) $profile['full_name'];
    }
    $_SESSION['last_activity'] = time();
} catch (PDOException | RuntimeException $exception) {
    error_log('Entresueño password page session check failed: ' . $exception->getMessage());
    http_response_code(503);
    $error = 'No se pudo validar tu sesión. Revisa la conexión con la base de datos.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'No se pudo validar el formulario. Recarga la página e inténtalo de nuevo.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ./', true, 303);
        exit;
    } elseif ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if (strlen($newPassword) > 128) {
            $error = 'La nueva contraseña no puede superar 128 caracteres.';
        } elseif (!hash_equals($newPassword, $confirmation)) {
            $error = 'La nueva contraseña y su confirmación no coinciden.';
        } else {
            try {
                changePanelPassword($username, $currentPassword, $newPassword);
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['password_change_success'] = 'Tu contraseña se actualizó correctamente.';
                header('Location: cambiar-contrasena', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (PDOException | RuntimeException $exception) {
                error_log('Entresueño password change failed: ' . $exception->getMessage());
                $error = 'No se pudo actualizar la contraseña. Revisa la conexión e inténtalo de nuevo.';
            }
        }
    } else {
        $error = 'La acción solicitada no es válida.';
    }
}

$token = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/aras-favicon.svg" type="image/svg+xml">
    <link rel="icon" href="../img/favicon.ico?v=2" sizes="any">
    <title>Modificar contraseña | ARAS</title>
    <link rel="stylesheet" href="admin.css?v=17">
</head>
<body>
    <main class="admin-shell">
        <header class="admin-topbar">
            <a class="admin-brand" href="./"><span aria-hidden="true">A</span> ARAS <small>ADMINISTRACIÓN</small></a>
            <div class="admin-topbar-actions">
                <details class="account-menu">
                    <summary><?= escapeHtml($displayName) ?></summary>
                    <div class="account-menu-panel">
                        <a href="datos-personales">Actualizar datos personales</a>
                        <a href="cambiar-contrasena">Modificar contraseña</a>
                    </div>
                </details>
                <form method="post" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="button-secondary">Cerrar sesión</button>
                </form>
            </div>
        </header>

        <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
        <?php if ($success !== ''): ?><p class="form-success" role="status"><?= escapeHtml($success) ?></p><?php endif; ?>

        <section class="admin-card auth-panel">
            <p class="eyebrow">SEGURIDAD DE LA CUENTA</p>
            <h1>Modificar contraseña</h1>
            <p>Usa al menos 8 caracteres. La contraseña actual es necesaria para confirmar el cambio.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                <input type="hidden" name="action" value="change_password">
                <label for="current-password">Contraseña actual</label>
                <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
                <label for="new-password">Nueva contraseña (mínimo 8 caracteres)</label>
                <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>
                <label for="password-confirmation">Confirmar nueva contraseña</label>
                <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>
                <button type="submit">Guardar nueva contraseña</button>
            </form>
        </section>

        <footer class="admin-footer">
            <span>© <?= date('Y') ?> ARAS · Administración</span>
            <span>Desarrollado por <a href="https://www.strokbig.com" target="_blank" rel="noopener noreferrer">STROKBIG</a></span>
        </footer>
    </main>
</body>
</html>
