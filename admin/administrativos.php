<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
startAdminSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$username = (string) ($_SESSION['admin_user'] ?? '');
if ($username === '') {
    header('Location: ./', true, 303);
    exit;
}
if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 7200) {
    unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
    header('Location: ./', true, 303);
    exit;
}

$error = '';
$success = (string) ($_SESSION['administrative_users_success'] ?? '');
unset($_SESSION['administrative_users_success']);
$role = null;
try {
    $role = activeUserRole($username);
} catch (PDOException | RuntimeException $exception) {
    error_log('ARAS administrative user authorization failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo validar el acceso a la gestión de usuarios.');
}
if ($role !== 'Superadministrativo') {
    header('Location: ./', true, 303);
    exit;
}
$_SESSION['admin_role'] = $role;
$_SESSION['last_activity'] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'No se pudo validar la sesión del formulario. Recarga la página e inténtalo de nuevo.';
    } elseif (($_POST['action'] ?? '') === 'create_administrative') {
        $newUsername = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $submittedPermissions = $_POST['permissions'] ?? [];
        if (!is_array($submittedPermissions) || array_filter($submittedPermissions, 'is_string') !== $submittedPermissions) {
            $error = 'Selecciona permisos válidos para la cuenta.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $newUsername)) {
            $error = 'El usuario debe tener entre 3 y 40 caracteres: letras, números, punto, guion o guion bajo.';
        } elseif (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Escribe un correo electrónico válido de máximo 254 caracteres.';
        } else {
            $permissions = array_values(array_unique($submittedPermissions));
            if (array_diff($permissions, panelPermissionSections()) !== []) {
                $error = 'La selección contiene permisos que no existen.';
            } else {
                $password = bin2hex(random_bytes(8));
                $connection = null;
                try {
                    $connection = databaseConnection();
                    $connection->beginTransaction();
                    $statement = $connection->prepare(
                        'INSERT INTO `Usuarios` (username, email, password_hash, role, permissions, is_active)
                         VALUES (:username, :email, :password_hash, :role, :permissions, 1)'
                    );
                    $statement->execute([
                        'username' => $newUsername,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => 'Administrativo',
                        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                    ]);
                    sendPanelCredentialsEmail($email, $newUsername, $password);
                    $connection->commit();
                    $_SESSION['administrative_users_success'] = 'Cuenta administrativa creada y credenciales enviadas al correo indicado.';
                    header('Location: administrativos', true, 303);
                    exit;
                } catch (PDOException $exception) {
                    if ($connection instanceof PDO && $connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    error_log('ARAS administrative user creation failed: ' . $exception->getMessage());
                    $error = $exception->getCode() === '23000'
                        ? 'Ese nombre de usuario o correo ya está asociado a otra cuenta.'
                        : 'No se pudo crear la cuenta. Revisa la conexión e inténtalo de nuevo.';
                } catch (InvalidArgumentException | RuntimeException | JsonException $exception) {
                    if ($connection instanceof PDO && $connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    error_log('ARAS administrative user creation failed: ' . $exception->getMessage());
                    $error = 'No se pudo crear la cuenta o enviar sus credenciales. Revisa la configuración de correo e inténtalo de nuevo.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'update_administrative') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $submittedPermissions = $_POST['permissions'] ?? [];
        if ($userId === false || $userId < 1) {
            $error = 'La cuenta seleccionada no es válida.';
        } elseif (!is_array($submittedPermissions) || array_filter($submittedPermissions, 'is_string') !== $submittedPermissions) {
            $error = 'Selecciona permisos válidos para la cuenta.';
        } else {
            $permissions = array_values(array_unique($submittedPermissions));
            if (array_diff($permissions, panelPermissionSections()) !== []) {
                $error = 'La selección contiene permisos que no existen.';
            } else {
                try {
                    $statement = databaseConnection()->prepare(
                        'UPDATE `Usuarios` SET permissions = :permissions
                         WHERE id = :id AND role = :role'
                    );
                    $statement->execute([
                        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                        'id' => $userId,
                        'role' => 'Administrativo',
                    ]);
                    if ($statement->rowCount() === 0) {
                        $exists = databaseConnection()->prepare(
                            'SELECT 1 FROM `Usuarios` WHERE id = :id AND role = :role LIMIT 1'
                        );
                        $exists->execute(['id' => $userId, 'role' => 'Administrativo']);
                        if ($exists->fetchColumn() === false) {
                            $error = 'No se encontró la cuenta administrativa seleccionada.';
                        } else {
                            $_SESSION['administrative_users_success'] = 'Los permisos de la cuenta se guardaron.';
                            header('Location: administrativos', true, 303);
                            exit;
                        }
                    } else {
                        $_SESSION['administrative_users_success'] = 'Los permisos de la cuenta se actualizaron.';
                        header('Location: administrativos', true, 303);
                        exit;
                    }
                } catch (PDOException | JsonException $exception) {
                    error_log('ARAS administrative permissions update failed: ' . $exception->getMessage());
                    $error = 'No se pudieron actualizar los permisos. Revisa la conexión e inténtalo de nuevo.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'set_administrative_status') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $isActive = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1 || !in_array($isActive, [0, 1], true)) {
            $error = 'La cuenta o el estado seleccionado no son válidos.';
        } else {
            try {
                $statement = databaseConnection()->prepare(
                    'UPDATE `Usuarios` SET is_active = :is_active
                     WHERE id = :id AND role = :role'
                );
                $statement->execute([
                    'is_active' => $isActive,
                    'id' => $userId,
                    'role' => 'Administrativo',
                ]);
                if ($statement->rowCount() !== 1) {
                    $error = 'No se encontró la cuenta administrativa o ya tiene ese estado.';
                } else {
                    $_SESSION['administrative_users_success'] = $isActive === 1
                        ? 'La cuenta administrativa fue activada.'
                        : 'La cuenta administrativa fue inactivada.';
                    header('Location: administrativos', true, 303);
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('ARAS administrative user status update failed: ' . $exception->getMessage());
                $error = 'No se pudo cambiar el estado de la cuenta. Revisa la conexión e inténtalo de nuevo.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ./', true, 303);
        exit;
    } else {
        $error = 'La acción solicitada no es válida.';
    }
}

try {
    $users = databaseConnection()->query(
        "SELECT id, username, email, permissions, is_active, created_at
         FROM `Usuarios` WHERE role = 'Administrativo'
         ORDER BY created_at DESC, id DESC"
    )->fetchAll();
} catch (PDOException | RuntimeException $exception) {
    error_log('ARAS administrative user list read failed: ' . $exception->getMessage());
    $users = [];
    $error = 'No se pudo cargar la lista de cuentas. Revisa la conexión con la base de datos.';
}

$token = csrfToken();
$permissionLabels = [
    'announcement' => 'Banners de inicio',
    'add-product' => 'Agregar productos y administrar categorías',
    'products' => 'Ver, editar y eliminar productos',
    'orders' => 'Consultar y gestionar pedidos',
    'settings' => 'Configuración e inventario',
];
$profile = null;
try {
    $profile = panelUserProfile($username);
} catch (PDOException | RuntimeException $exception) {
    error_log('ARAS administrative user profile read failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo cargar el perfil de la cuenta.');
}
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
    <title>Usuarios administrativos | ARAS</title>
    <link rel="stylesheet" href="admin.css?v=17">
</head>
<body>
    <main class="admin-shell">
        <header class="admin-topbar">
            <a class="admin-brand" href="./"><span aria-hidden="true">A</span> ARAS <small>ADMINISTRACIÓN</small></a>
            <div class="admin-topbar-actions">
                <details class="account-menu">
                    <summary><?= escapeHtml((string) $displayName) ?></summary>
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
            <p class="eyebrow">GESTIÓN DE ACCESOS</p>
            <h1>Crear usuario administrativo</h1>
            <p>Elige qué secciones podrá consultar o modificar. El resumen general siempre estará disponible. Solo el Superadministrador puede crear y asignar permisos.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                <input type="hidden" name="action" value="create_administrative">
                <label for="username">Nombre de usuario</label>
                <input id="username" name="username" autocomplete="off" minlength="3" maxlength="40" pattern="[a-zA-Z0-9._-]{3,40}" required>
                <label for="email">Correo electrónico</label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
                <fieldset class="permission-options">
                    <legend>Permisos del panel</legend>
                    <?php foreach ($permissionLabels as $key => $label): ?>
                        <label class="checkbox-label"><input type="checkbox" name="permissions[]" value="<?= escapeHtml($key) ?>"> <?= escapeHtml($label) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit">Crear cuenta administrativa</button>
            </form>
            <p class="user-management-note">Se generará una contraseña temporal y se enviará al correo ingresado. El usuario podrá cambiarla al iniciar sesión.</p>
        </section>

        <section class="admin-section user-list-section">
            <div class="section-heading">
                <div><p class="section-kicker">ACCESOS DEL EQUIPO</p><h2>Usuarios administrativos</h2></div>
                <span class="count-pill"><?= count($users) ?> cuentas</span>
            </div>
            <?php if ($users === []): ?>
                <p>Aún no hay usuarios administrativos.</p>
            <?php else: ?>
                <div class="administrative-user-list">
                    <?php foreach ($users as $user): ?>
                        <?php
                        try {
                            $userPermissions = json_decode(
                                (string) ($user['permissions'] ?? '[]'),
                                true,
                                512,
                                JSON_THROW_ON_ERROR
                            );
                            if (!is_array($userPermissions)
                                || array_filter($userPermissions, 'is_string') !== $userPermissions) {
                                throw new JsonException('Invalid permission format.');
                            }
                            $permissionsAreValid = true;
                        } catch (JsonException $exception) {
                            error_log('ARAS administrative account has invalid stored permissions: ' . $exception->getMessage());
                            $userPermissions = [];
                            $permissionsAreValid = false;
                        }
                        ?>
                        <article class="administrative-user-card">
                            <div class="section-heading">
                                <div><h3><?= escapeHtml((string) $user['username']) ?></h3><p><?= escapeHtml((string) $user['email']) ?></p></div>
                                <span class="status-pill <?= (int) $user['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int) $user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span>
                            </div>
                            <?php if ($permissionsAreValid): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                <input type="hidden" name="action" value="update_administrative">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <fieldset class="permission-options">
                                    <legend>Permisos asignados</legend>
                                    <?php foreach ($permissionLabels as $key => $label): ?>
                                        <label class="checkbox-label"><input type="checkbox" name="permissions[]" value="<?= escapeHtml($key) ?>" <?= in_array($key, $userPermissions, true) ? 'checked' : '' ?>> <?= escapeHtml($label) ?></label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <button type="submit" class="button-secondary">Guardar permisos</button>
                            </form>
                            <?php else: ?>
                                <p class="form-error" role="alert">No se pudieron interpretar los permisos guardados. Esta cuenta queda sin acceso a las secciones y no se editará hasta corregir sus datos.</p>
                            <?php endif; ?>
                            <form method="post" class="administrative-user-status">
                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                <input type="hidden" name="action" value="set_administrative_status">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= (int) $user['is_active'] === 1 ? '0' : '1' ?>">
                                <button type="submit" class="button-secondary"><?= (int) $user['is_active'] === 1 ? 'Desactivar cuenta' : 'Activar cuenta' ?></button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <footer class="admin-footer">
            <span>© <?= date('Y') ?> ARAS · Administración</span>
            <span>Desarrollado por <a href="https://www.strokbig.com" target="_blank" rel="noopener noreferrer">STROKBIG</a></span>
        </footer>
    </main>
</body>
</html>
