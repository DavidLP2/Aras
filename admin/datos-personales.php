<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
startAdminSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$username = (string) ($_SESSION['admin_user'] ?? '');
$error = '';
$success = (string) ($_SESSION['profile_update_success'] ?? '');
unset($_SESSION['profile_update_success']);

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
    $profile = panelUserProfile($username);
    if ($profile === null) {
        unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
        header('Location: ./', true, 303);
        exit;
    }
    $_SESSION['admin_role'] = activeUserRole($username);
    $_SESSION['last_activity'] = time();
} catch (PDOException | RuntimeException $exception) {
    error_log('Entresueño personal details read failed: ' . $exception->getMessage());
    http_response_code(503);
    $profile = null;
    $error = 'No se pudieron cargar tus datos. Revisa la conexión y que la base de datos tenga los campos personales actualizados.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && is_array($profile)) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'No se pudo validar el formulario. Recarga la página e inténtalo de nuevo.';
    } elseif (($_POST['action'] ?? '') !== 'update_profile') {
        $error = 'La acción solicitada no es válida.';
    } else {
        try {
            updatePanelUserProfile(
                (int) $profile['id'],
                (string) ($_POST['full_name'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['phone'] ?? ''),
                (string) ($_POST['national_id'] ?? '')
            );
            $_SESSION['profile_update_success'] = 'Tus datos personales se actualizaron correctamente.';
            header('Location: datos-personales', true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
            $profile['full_name'] = (string) ($_POST['full_name'] ?? '');
            $profile['email'] = (string) ($_POST['email'] ?? '');
            $profile['phone'] = (string) ($_POST['phone'] ?? '');
            $profile['national_id'] = (string) ($_POST['national_id'] ?? '');
        } catch (PDOException | RuntimeException $exception) {
            error_log('Entresueño personal details update failed: ' . $exception->getMessage());
            $error = $exception instanceof PDOException && $exception->getCode() === '23000'
                ? 'Ese correo electrónico ya está asociado a otra cuenta.'
                : 'No se pudieron actualizar tus datos. Revisa la conexión e inténtalo de nuevo.';
            $profile['full_name'] = (string) ($_POST['full_name'] ?? '');
            $profile['email'] = (string) ($_POST['email'] ?? '');
            $profile['phone'] = (string) ($_POST['phone'] ?? '');
            $profile['national_id'] = (string) ($_POST['national_id'] ?? '');
        }
    }
}

$token = csrfToken();
$displayName = is_array($profile) && trim((string) ($profile['full_name'] ?? '')) !== ''
    ? (string) $profile['full_name']
    : $username;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/aras-favicon.svg" type="image/svg+xml">
    <link rel="icon" href="../img/favicon.ico?v=2" sizes="any">
    <title>Actualizar datos personales | ARAS</title>
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
                <form method="post" action="./" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="button-secondary">Cerrar sesión</button>
                </form>
            </div>
        </header>

        <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
        <?php if ($success !== ''): ?><p class="form-success" role="status"><?= escapeHtml($success) ?></p><?php endif; ?>

        <?php if (is_array($profile)): ?>
        <section class="admin-card auth-panel profile-card">
            <p class="eyebrow">PERFIL DE LA CUENTA</p>
            <h1>Actualizar datos personales</h1>
            <p>Estos datos corresponden a la cuenta <strong><?= escapeHtml($username) ?></strong>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                <input type="hidden" name="action" value="update_profile">
                <label for="full-name">Nombre completo</label>
                <input id="full-name" name="full_name" autocomplete="name" maxlength="120" value="<?= escapeHtml((string) ($profile['full_name'] ?? '')) ?>" required>
                <label for="profile-email">Correo electrónico</label>
                <input id="profile-email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= escapeHtml((string) ($profile['email'] ?? '')) ?>" required>
                <label for="profile-phone">Celular (opcional)</label>
                <input id="profile-phone" name="phone" type="tel" autocomplete="tel" maxlength="30" value="<?= escapeHtml((string) ($profile['phone'] ?? '')) ?>">
                <label for="profile-national-id">Cédula (opcional)</label>
                <input id="profile-national-id" name="national_id" inputmode="numeric" autocomplete="off" maxlength="15" pattern="[0-9]{5,15}" value="<?= escapeHtml((string) ($profile['national_id'] ?? '')) ?>">
                <p class="site-settings-help">La cédula debe contener solo números (de 5 a 15 dígitos). El celular admite números y los signos + ( ) . -.</p>
                <button type="submit">Guardar datos personales</button>
            </form>
        </section>
        <?php endif; ?>

        <footer class="admin-footer">
            <span>© <?= date('Y') ?> ARAS · Administración</span>
            <span>Desarrollado por <a href="https://www.strokbig.com" target="_blank" rel="noopener noreferrer">STROKBIG</a></span>
        </footer>
    </main>
</body>
</html>
