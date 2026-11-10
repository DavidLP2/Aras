<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
startAdminSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$error = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Escribe un correo electrónico válido.';
        } else {
            try {
                requestPanelPasswordReset($email);
            } catch (Throwable $exception) {
                error_log('Entresueño password recovery request failed: ' . $exception->getMessage());
            }
            $submitted = true;
        }
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
    <title>Recuperar contraseña | ARAS</title>
    <link rel="stylesheet" href="admin.css?v=17">
</head>
<body>
    <main class="admin-shell">
        <header class="admin-topbar">
            <a class="admin-brand" href="./"><span aria-hidden="true">A</span> ARAS <small>ADMINISTRACIÓN</small></a>
        </header>

        <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>

        <div class="auth-login-layout auth-recovery-layout">
            <aside class="auth-login-story">
                <p class="auth-story-kicker">ARAS · TU TIENDA</p>
                <div class="auth-story-copy">
                    <h2>Estamos aquí para ayudarte.</h2>
                    <p>Recupera el acceso a tu cuenta de forma segura y sigue adelante.</p>
                </div>
                <span class="auth-story-note"><span aria-hidden="true">✦</span> Tu tienda, siempre cerca</span>
            </aside>
            <section class="admin-card auth-panel">
                <p class="eyebrow">RECUPERACIÓN DE CUENTA</p>
                <h1>Restablecer contraseña</h1>
                <?php if ($submitted): ?>
                    <p class="form-success" role="status">Si existe una cuenta asociada a ese correo, recibirás un enlace para restablecer la contraseña. Revisa también la carpeta de correo no deseado.</p>
                <?php else: ?>
                    <p>Escribe el correo asociado a tu cuenta y te enviaremos un enlace seguro para crear una nueva contraseña.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                        <label for="email">Correo electrónico</label>
                        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
                        <button type="submit">Enviar enlace de recuperación</button>
                    </form>
                <?php endif; ?>
                <p class="auth-recovery-link"><a href="./">Volver al inicio de sesión</a></p>
            </section>
        </div>

        <footer class="admin-footer">
            <span>© <?= date('Y') ?> ARAS · Administración</span>
            <span>Desarrollado por <a href="https://www.strokbig.com" target="_blank" rel="noopener noreferrer">STROKBIG</a></span>
        </footer>
    </main>
</body>
</html>
