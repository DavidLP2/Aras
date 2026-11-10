<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
startAdminSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = '';
$success = (string) ($_SESSION['user_management_success'] ?? '');
unset($_SESSION['user_management_success']);
$superadmins = [];
$superadminExists = false;
$displayName = (string) ($_SESSION['admin_user'] ?? '');

if (isset($_SESSION['admin_user'], $_SESSION['last_activity'])
    && time() - (int) $_SESSION['last_activity'] > 7200) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: ./', true, 303);
    exit;
}

if (isset($_SESSION['admin_user'])) {
    try {
        $role = activeUserRole((string) $_SESSION['admin_user']);
        if ($role === null) {
            unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
        } else {
            $_SESSION['admin_role'] = $role;
            $_SESSION['last_activity'] = time();
            if ($role !== 'Tecnico') {
                header('Location: ./', true, 303);
                exit;
            }
            $profile = panelUserProfile((string) $_SESSION['admin_user']);
            if (is_array($profile) && is_string($profile['full_name']) && trim($profile['full_name']) !== '') {
                $displayName = (string) $profile['full_name'];
            }
        }
    } catch (PDOException | RuntimeException $exception) {
        error_log('Entresueño technical user session check failed: ' . $exception->getMessage());
        http_response_code(503);
        $error = 'No se pudo validar el acceso técnico. Revisa la conexión de la base de datos.';
    }
}

if (!isset($_SESSION['admin_user']) && $error === '') {
    header('Location: ./', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'No se pudo validar la sesión del formulario. Recarga esta página e inténtalo de nuevo.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ./', true, 303);
        exit;
    } elseif (($_POST['action'] ?? '') === 'create_superadmin') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
        $generatedPassword = $password === '';

        if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
            $error = 'El usuario debe tener entre 3 y 40 caracteres: letras, números, punto, guion o guion bajo.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Escribe un correo electrónico válido para el cliente.';
        } elseif ($generatedPassword && $passwordConfirmation !== '') {
            $error = 'Deja vacía también la confirmación si quieres que el sistema genere la contraseña.';
        } elseif (!$generatedPassword && (mb_strlen($password, 'UTF-8') < 8 || mb_strlen($password, 'UTF-8') > 128)) {
            $error = 'La contraseña debe tener entre 8 y 128 caracteres.';
        } elseif (!$generatedPassword && !hash_equals($password, $passwordConfirmation)) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                if ($generatedPassword) {
                    $password = bin2hex(random_bytes(8));
                }

                $connection = databaseConnection();
                $connection->beginTransaction();
                $existingSuperadminCount = (int) $connection->query(
                    "SELECT COUNT(*) FROM `Usuarios` WHERE role = 'Superadministrativo'"
                )->fetchColumn();
                if ($existingSuperadminCount > 0) {
                    $connection->rollBack();
                    $error = 'Ya existe la única cuenta Superadministrativo permitida.';
                } else {
                    $duplicateEmail = $connection->prepare(
                        'SELECT 1 FROM `Usuarios` WHERE LOWER(email) = LOWER(:email) LIMIT 1'
                    );
                    $duplicateEmail->execute(['email' => $email]);
                    if ($duplicateEmail->fetchColumn() !== false) {
                        $connection->rollBack();
                        $error = 'Ese correo electrónico ya está asociado a otra cuenta.';
                    } else {
                        $statement = $connection->prepare(
                            'INSERT INTO `Usuarios` (username, email, password_hash, role, is_active)
                             VALUES (:username, :email, :password_hash, :role, 1)'
                        );
                        $statement->execute([
                            'username' => $username,
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'role' => 'Superadministrativo',
                        ]);
                        sendPanelCredentialsEmail($email, $username, $password);
                        $connection->commit();
                        $_SESSION['user_management_success'] = 'Cuenta Superadministrativo creada y correo aceptado por Gmail SMTP. Si no aparece en la bandeja de entrada, revisa spam o verifica el correo destinatario.';
                        header('Location: usuarios', true, 303);
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if (isset($connection) && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                error_log('Entresueño technical account creation failed: ' . $exception->getMessage());
                if ($exception->getCode() === '23000') {
                    $error = 'El correo electrónico o nombre de usuario ya está asociado a otra cuenta.';
                } elseif ($exception->getCode() === '42S22') {
                    $error = 'Falta la columna email en Usuarios. Aplica la actualización de base de datos indicada en el README.';
                } else {
                    $error = 'No se pudo crear la cuenta. Revisa la base de datos e inténtalo de nuevo.';
                }
            } catch (InvalidArgumentException | RuntimeException $exception) {
                if (isset($connection) && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                error_log('Entresueño technical account creation failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            }
        }
    } elseif (in_array((string) ($_POST['action'] ?? ''), [
        'update_superadmin',
        'set_superadmin_status',
        'delete_superadmin',
    ], true)) {
        $error = 'La cuenta técnica solo puede crear la cuenta Superadministrativo inicial.';
    } elseif (($_POST['action'] ?? '') === 'update_superadmin') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($userId === false || $userId < 1) {
            $error = 'La cuenta seleccionada no es válida.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
            $error = 'El usuario debe tener entre 3 y 40 caracteres: letras, números, punto, guion o guion bajo.';
        } elseif (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Escribe un correo electrónico válido de máximo 254 caracteres.';
        } else {
            try {
                $connection = databaseConnection();
                $connection->beginTransaction();
                $currentUser = $connection->prepare(
                    'SELECT username FROM `Usuarios`
                     WHERE id = :id AND role = :role
                     FOR UPDATE'
                );
                $currentUser->execute([
                    'id' => $userId,
                    'role' => 'Superadministrativo',
                ]);
                $existingUser = $currentUser->fetch();

                if (!is_array($existingUser)) {
                    $connection->rollBack();
                    $error = 'No se encontró la cuenta Superadministrativo seleccionada.';
                } else {
                    $duplicateEmail = $connection->prepare(
                        'SELECT 1 FROM `Usuarios`
                         WHERE LOWER(email) = LOWER(:email) AND id <> :id
                         LIMIT 1'
                    );
                    $duplicateEmail->execute(['email' => $email, 'id' => $userId]);
                    $duplicateUsername = $connection->prepare(
                        'SELECT 1 FROM `Usuarios`
                         WHERE username = :username AND id <> :id
                         LIMIT 1'
                    );
                    $duplicateUsername->execute(['username' => $username, 'id' => $userId]);

                    if ($duplicateEmail->fetchColumn() !== false) {
                        $connection->rollBack();
                        $error = 'Ese correo electrónico ya está asociado a otra cuenta.';
                    } elseif ($duplicateUsername->fetchColumn() !== false) {
                        $connection->rollBack();
                        $error = 'Ese nombre de usuario ya está en uso. Elige otro.';
                    } else {
                        $update = $connection->prepare(
                            'UPDATE `Usuarios`
                             SET username = :username, email = :email
                             WHERE id = :id AND role = :role'
                        );
                        $update->execute([
                            'username' => $username,
                            'email' => $email,
                            'id' => $userId,
                            'role' => 'Superadministrativo',
                        ]);
                        $connection->commit();
                        $_SESSION['user_management_success'] = 'Los datos de la cuenta Superadministrativo fueron actualizados.';
                        header('Location: usuarios', true, 303);
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if (isset($connection) && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                error_log('Entresueño superadmin account update failed: ' . $exception->getMessage());
                $error = $exception->getCode() === '23000'
                    ? 'El correo electrónico o nombre de usuario ya está asociado a otra cuenta.'
                    : 'No se pudo actualizar la cuenta. Revisa la conexión con la base de datos e inténtalo de nuevo.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'set_superadmin_status') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $isActive = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1 || !in_array($isActive, [0, 1], true)) {
            $error = 'La cuenta o el estado seleccionado no son válidos.';
        } else {
            try {
                $statement = databaseConnection()->prepare(
                    'UPDATE `Usuarios`
                     SET is_active = :is_active
                     WHERE id = :id AND role = :role'
                );
                $statement->execute([
                    'is_active' => $isActive,
                    'id' => $userId,
                    'role' => 'Superadministrativo',
                ]);
                if ($statement->rowCount() !== 1) {
                    $exists = databaseConnection()->prepare(
                        'SELECT 1 FROM `Usuarios`
                         WHERE id = :id AND role = :role AND is_active = :is_active'
                    );
                    $exists->execute([
                        'id' => $userId,
                        'role' => 'Superadministrativo',
                        'is_active' => $isActive,
                    ]);
                    $error = $exists->fetchColumn() !== false
                        ? 'La cuenta ya tiene ese estado.'
                        : 'No se encontró la cuenta Superadministrativo seleccionada.';
                } else {
                    $_SESSION['user_management_success'] = $isActive === 1
                        ? 'La cuenta Superadministrativo fue activada.'
                        : 'La cuenta Superadministrativo fue inactivada.';
                    header('Location: usuarios', true, 303);
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('Entresueño superadmin account status update failed: ' . $exception->getMessage());
                $error = 'No se pudo cambiar el estado de la cuenta. Revisa la conexión con la base de datos e inténtalo de nuevo.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete_superadmin') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            $error = 'La cuenta seleccionada no es válida.';
        } else {
            try {
                $statement = databaseConnection()->prepare(
                    'DELETE FROM `Usuarios`
                     WHERE id = :id
                       AND role = :role
                       AND username <> :technical_username'
                );
                $statement->execute([
                    'id' => $userId,
                    'role' => 'Superadministrativo',
                    'technical_username' => (string) $_SESSION['admin_user'],
                ]);
                if ($statement->rowCount() !== 1) {
                    $error = 'No se encontró la cuenta Superadministrativo seleccionada.';
                } else {
                    $_SESSION['user_management_success'] = 'La cuenta Superadministrativo fue eliminada. El historial de pedidos no se modificó.';
                    header('Location: usuarios', true, 303);
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('Entresueño superadmin account deletion failed: ' . $exception->getMessage());
                $error = 'No se pudo eliminar la cuenta. Revisa la conexión con la base de datos e inténtalo de nuevo.';
            }
        }
    } else {
        $error = 'La acción solicitada no es válida.';
    }
}

if (isset($_SESSION['admin_user']) && $error !== 'No se pudo validar el acceso técnico. Revisa la conexión de la base de datos.') {
    try {
        $statement = databaseConnection()->query(
            "SELECT id, username, email, is_active, created_at
             FROM `Usuarios`
             WHERE role = 'Superadministrativo'
             ORDER BY created_at DESC, id DESC"
        );
        $superadmins = $statement->fetchAll();
        $superadminExists = $superadmins !== [];
    } catch (PDOException | RuntimeException $exception) {
        error_log('Entresueño superadmin list read failed: ' . $exception->getMessage());
        $error = $exception instanceof PDOException && $exception->getCode() === '42S22'
            ? 'Falta la columna email en Usuarios. Aplica la actualización de base de datos indicada en el README.'
            : 'No se pudo cargar la lista de cuentas. Revisa la conexión con la base de datos.';
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
    <title>Gestión de usuarios | ARAS</title>
    <link rel="stylesheet" href="admin.css?v=17">
</head>
<body>
    <main class="admin-shell">
        <header class="admin-topbar">
            <a class="admin-brand" href="./usuarios"><span aria-hidden="true">A</span> ARAS <small>GESTIÓN TÉCNICA</small></a>
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

        <section class="admin-card user-management-card">
            <p class="eyebrow">ACCESO TÉCNICO · <?= escapeHtml((string) $_SESSION['admin_user']) ?></p>
            <h1>Crear la cuenta principal</h1>
            <p>La cuenta técnica solo se usa para crear una única cuenta Superadministrativo inicial. Después, esa persona podrá crear usuarios administrativos y asignarles permisos.</p>
            <?php if ($superadminExists): ?>
                <p class="form-success" role="status">La única cuenta Superadministrativo ya está creada. No se pueden crear cuentas principales adicionales.</p>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                <input type="hidden" name="action" value="create_superadmin">
                <label for="username">Usuario del cliente</label>
                <input id="username" name="username" autocomplete="off" minlength="3" maxlength="40" pattern="[a-zA-Z0-9._-]{3,40}" aria-describedby="username-validation" required>
                <p id="username-validation" class="field-validation" role="alert" hidden>El usuario debe tener entre 3 y 40 caracteres: letras, números, punto, guion o guion bajo.</p>
                <label for="email">Correo electrónico del cliente</label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
                <button id="toggle-password-fields" type="button" class="button-secondary password-toggle" aria-expanded="false" aria-controls="password-fields">Definir contraseña manualmente (opcional)</button>
                <div id="password-fields" class="password-fields" hidden>
                    <label for="password">Contraseña inicial (mínimo 8 caracteres)</label>
                    <div class="password-input-control">
                        <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="128">
                        <button type="button" class="button-secondary password-visibility-toggle" data-password-toggle aria-pressed="false">Mostrar</button>
                    </div>
                    <label for="password-confirmation">Confirmar contraseña inicial</label>
                    <div class="password-input-control">
                        <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="128">
                        <button type="button" class="button-secondary password-visibility-toggle" data-password-toggle aria-pressed="false">Mostrar</button>
                    </div>
                </div>
                <button type="submit">Crear cuenta Superadministrativo</button>
            </form>
            <p class="user-management-note">Si dejas la contraseña vacía, el sistema generará una. En ambos casos, enviará las credenciales al correo indicado mediante Gmail SMTP autenticado.</p>
            <?php endif; ?>
        </section>

        <section class="admin-section user-list-section">
            <div class="section-heading">
                <div><p class="section-kicker">CUENTA PRINCIPAL</p><h2>Superadministrador</h2></div>
                <span class="count-pill"><?= count($superadmins) ?> de 1 cuenta</span>
            </div>
            <?php if ($superadmins === []): ?>
                <p>Aún no se ha creado la cuenta Superadministrativo principal.</p>
            <?php else: ?>
                <div class="user-table-wrap">
                    <table class="user-table">
                        <thead><tr><th>Usuario</th><th>Correo</th><th>Estado</th><th>Creado</th></tr></thead>
                        <tbody>
                            <?php foreach ($superadmins as $user): ?>
                                <tr>
                                    <td><?= escapeHtml((string) $user['username']) ?></td>
                                    <td><?= escapeHtml((string) ($user['email'] ?? '')) ?></td>
                                    <td><span class="status-pill <?= (int) $user['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int) $user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                                    <td><?= escapeHtml(date('Y-m-d', strtotime((string) $user['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <dialog class="user-edit-dialog" id="user-edit-dialog" aria-labelledby="user-edit-title">
            <form method="post" class="user-edit-dialog-card">
                <div class="user-edit-dialog-heading">
                    <div>
                        <p class="eyebrow">CUENTA DEL CLIENTE</p>
                        <h2 id="user-edit-title">Editar usuario</h2>
                    </div>
                    <button type="button" class="user-edit-dialog-close" data-close-user-edit aria-label="Cerrar">×</button>
                </div>
                <p class="user-edit-description">Actualiza el nombre de usuario y el correo electrónico de esta cuenta.</p>
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                <input type="hidden" name="action" value="update_superadmin">
                <input type="hidden" name="user_id" id="edit-user-id">
                <label for="edit-username">Nombre de usuario</label>
                <input id="edit-username" name="username" autocomplete="off" minlength="3" maxlength="40" pattern="[a-zA-Z0-9._-]{3,40}" required>
                <label for="edit-email">Correo electrónico</label>
                <input id="edit-email" name="email" type="email" autocomplete="email" maxlength="254" required>
                <div class="user-edit-dialog-actions">
                    <button type="button" class="button-secondary" data-close-user-edit>Cancelar</button>
                    <button type="submit">Guardar cambios</button>
                </div>
            </form>
        </dialog>
        <footer class="admin-footer">
            <span>© <?= date('Y') ?> ARAS · Gestión técnica</span>
            <span>Desarrollado por <a href="https://www.strokbig.com" target="_blank" rel="noopener noreferrer">STROKBIG</a></span>
        </footer>
    </main>
    <script src="admin.js?v=8" defer></script>
</body>
</html>
