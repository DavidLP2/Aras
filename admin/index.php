<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-common.php';
startAdminSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = '';
$success = '';
$csrfError = false;
$categoryDialogOpen = false;
$productImportDialogOpen = false;
$importReport = null;
$selectedCategory = '';
$newLogo = '';
$action = '';
$databaseError = '';
$activeUsers = 0;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['product_import_flash'])) {
    $importFlash = $_SESSION['product_import_flash'];
    unset($_SESSION['product_import_flash']);
    if (is_array($importFlash)) {
        $action = 'import_products';
        $productImportDialogOpen = true;
        $success = is_string($importFlash['success'] ?? null) ? $importFlash['success'] : '';
        $error = is_string($importFlash['error'] ?? null) ? $importFlash['error'] : '';
        $importReport = is_array($importFlash['report'] ?? null) ? $importFlash['report'] : null;
    }
}
try {
    $activeUsers = activePanelUserCount();
} catch (PDOException | RuntimeException $exception) {
    error_log('Entresueño admin database check failed: ' . $exception->getMessage());
    $serverName = strtolower(rtrim((string) ($_SERVER['SERVER_NAME'] ?? ''), '.'));
    $databaseEnvironment = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true)
        ? 'local'
        : 'production';
    $configPath = storagePath('database.' . $databaseEnvironment . '.php');
    $databaseError = !is_file($configPath)
        ? 'Falta storage/database.' . $databaseEnvironment . '.php. Cópialo desde la plantilla del mismo entorno y completa los datos MySQL.'
        : 'No se pudo conectar a la base de datos de ' . $databaseEnvironment . '. Revisa host, nombre de base, usuario, contraseña y que la tabla Usuarios exista.';
}
$configured = $databaseError === '' && $activeUsers > 0;
$permissions = [];

if (isset($_SESSION['admin_user'])) {
    try {
        $role = $databaseError === '' ? activeUserRole((string) $_SESSION['admin_user']) : null;
        if ($role === null) {
            unset($_SESSION['admin_user'], $_SESSION['last_activity']);
            if ($databaseError === '') {
                $error = 'Esta cuenta ya no tiene acceso al panel.';
            } else {
                $configured = false;
            }
        } else {
            $_SESSION['admin_role'] = $role;
            try {
                $permissions = panelUserPermissions((string) $_SESSION['admin_user']);
            } catch (RuntimeException $exception) {
                error_log('Entresueño admin permissions read failed: ' . $exception->getMessage());
                unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
                $databaseError = 'No se pudieron validar los permisos de esta cuenta.';
                $configured = false;
            }
        }
    } catch (PDOException | RuntimeException $exception) {
        error_log('Entresueño admin session validation failed: ' . $exception->getMessage());
        unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
        $databaseError = 'No se pudo validar la cuenta administradora. Revisa la conexión y la tabla Usuarios.';
        $configured = false;
    }
}

if (isset($_SESSION['admin_user']) && ($_SESSION['admin_role'] ?? '') === 'Tecnico') {
    header('Location: usuarios', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $csrfError = true;
        $error = 'Este formulario pertenece a una sesión anterior o la cookie del panel no llegó. Recarga el acceso y vuelve a intentarlo.';
    } else {
        if ($action === 'login' && $configured) {
            $username = (string) ($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            try {
                $authenticatedRole = authenticatePanelUser($username, $password);
            } catch (PDOException | RuntimeException $exception) {
                error_log('Entresueño admin authentication failed: ' . $exception->getMessage());
                $authenticatedRole = null;
                $error = 'No se pudo validar el acceso. Revisa la conexión y la tabla de administradores.';
            }
            if ($authenticatedRole !== null) {
                session_regenerate_id(true);
                $_SESSION['admin_user'] = $username;
                $_SESSION['admin_role'] = $authenticatedRole;
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header(
                    'Location: ' . ($authenticatedRole === 'Tecnico' ? 'usuarios' : './'),
                    true,
                    303
                );
                exit;
            }
            if ($error === '') {
                $error = 'Usuario o contraseña incorrectos.';
            }
        } elseif (isset($_SESSION['admin_user'])
            && $action !== 'logout'
            && !panelUserCanPerformAction($permissions, $action, $_POST)) {
            $error = 'Tu cuenta no tiene permiso para realizar esta acción.';
        } elseif (isset($_SESSION['admin_user'])
            && in_array($_SESSION['admin_role'] ?? '', ['Superadministrativo', 'Administrativo'], true)
            && in_array($action, ['accept_order', 'reject_order'], true)) {
            $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
            if ($orderId === false || $orderId < 1) {
                $error = 'El pedido seleccionado no es válido.';
            } else {
                $newStatus = $action === 'accept_order' ? 'sold' : 'not_concluded';
                try {
                    changeOrderStatus($orderId, $newStatus, (string) $_SESSION['admin_user']);
                    $success = $newStatus === 'sold'
                        ? 'Pedido aceptado como vendido.'
                        : 'Pedido marcado como no concluido.';
                } catch (PDOException | RuntimeException $exception) {
                    error_log('Entresueño order review database failure: ' . $exception->getMessage());
                    $error = 'No se pudo actualizar el pedido. Revisa la base de datos e inténtalo de nuevo.';
                } catch (InvalidArgumentException | RuntimeException $exception) {
                    $error = $exception->getMessage();
                }
            }
        } elseif ($action === 'logout' && isset($_SESSION['admin_user'])) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $success = 'Sesión cerrada.';
        } elseif (isset($_SESSION['admin_user']) && $action === 'create_category') {
            try {
                $selectedCategory = createProductCategory(
                    (string) ($_POST['category_name'] ?? ''),
                    $_FILES['image'] ?? []
                );
                $success = 'Categoría creada. Ya puedes seleccionarla para agregar productos.';
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('Entresueño category creation failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
                $categoryDialogOpen = true;
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'delete_category') {
            try {
                deleteProductCategory((string) ($_POST['category_key'] ?? ''));
                $success = 'Categoría eliminada.';
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('Entresueño category deletion failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
                $categoryDialogOpen = true;
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'save_inventory_settings') {
            try {
                $settings = siteSettings();
                $inventoryEnabled = isset($_POST['inventory_enabled']);
                if ($inventoryEnabled) {
                    $lowStockThreshold = filter_var(
                        $_POST['low_stock_threshold'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => 100000]]
                    );
                    if ($lowStockThreshold === false) {
                        throw new RuntimeException('El valor debe ser un número entero entre 1 y 100.000.');
                    }
                    $settings['low_stock_threshold'] = $lowStockThreshold;
                }
                $settings['inventory_enabled'] = $inventoryEnabled;
                $settings['updated_at'] = gmdate('c');
                writeJsonFile(storagePath('site-settings.json'), $settings);
                $success = 'La configuración de inventario se actualizó correctamente.';
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('Entresueño inventory settings save failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'save_site_settings') {
            try {
                $settings = siteSettings();
                $oldLogo = $settings['logo'];
                if (!isset($_FILES['logo']) || (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Selecciona una imagen para actualizar el logo.');
                }

                $newLogo = saveUploadedImage($_FILES['logo']);
                $settings['logo'] = $newLogo;
                $settings['updated_at'] = gmdate('c');
                writeJsonFile(storagePath('site-settings.json'), $settings);
                if ($oldLogo !== '' && $oldLogo !== $newLogo) {
                    try {
                        removeUploadedImage($oldLogo);
                    } catch (RuntimeException $cleanupException) {
                        error_log('Entresueño previous logo cleanup failed: ' . $cleanupException->getMessage());
                    }
                }
                $success = 'El logo de la tienda se actualizó correctamente.';
            } catch (JsonException | RuntimeException | Error $exception) {
                if ($newLogo !== '') {
                    try {
                        removeUploadedImage($newLogo);
                    } catch (RuntimeException $cleanupException) {
                        error_log('Entresueño temporary logo cleanup failed: ' . $cleanupException->getMessage());
                    }
                    $newLogo = '';
                }
                error_log('Entresueño site settings save failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'delete_site_logo') {
            try {
                $settings = siteSettings();
                $oldLogo = $settings['logo'];
                if ($oldLogo === '') {
                    throw new RuntimeException('No hay un logo guardado para eliminar.');
                }

                $settings['logo'] = '';
                $settings['updated_at'] = gmdate('c');
                writeJsonFile(storagePath('site-settings.json'), $settings);
                try {
                    removeUploadedImage($oldLogo);
                } catch (RuntimeException $cleanupException) {
                    error_log('ARAS deleted logo cleanup failed: ' . $cleanupException->getMessage());
                }
                $success = 'El logo de la tienda se eliminó correctamente.';
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('ARAS logo deletion failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'save_banner') {
            $newImage = '';
            $bannerLock = null;
            $bannerWritten = false;
            try {
                $bannerLock = fopen(storagePath('banners.lock'), 'c');
                if (!is_resource($bannerLock) || !flock($bannerLock, LOCK_EX)) {
                    throw new RuntimeException('No se pudo bloquear la lista de banners para actualizarla.');
                }

                $banners = siteBanners();
                $bannerId = is_string($_POST['banner_id'] ?? null) ? $_POST['banner_id'] : '';
                $bannerIndex = null;
                foreach ($banners as $index => $banner) {
                    if ($banner['id'] === $bannerId && $bannerId !== '') {
                        $bannerIndex = $index;
                        break;
                    }
                }
                if ($bannerId !== '' && $bannerIndex === null) {
                    throw new RuntimeException('No se encontró el banner que intentas actualizar.');
                }
                if ($bannerIndex === null && count($banners) >= 10) {
                    throw new RuntimeException('Puedes publicar un máximo de 10 banners.');
                }

                $text = is_string($_POST['text'] ?? null) ? trim($_POST['text']) : '';
                $link = is_string($_POST['link'] ?? null) ? trim($_POST['link']) : '';
                if (mb_strlen($text, 'UTF-8') > 300 || mb_strlen($link, 'UTF-8') > 2048) {
                    throw new RuntimeException('El texto admite 300 caracteres y el enlace 2.048.');
                }
                if ($link !== '') {
                    $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
                    if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                        throw new RuntimeException('El enlace debe comenzar con http:// o https://.');
                    }
                }

                $oldBanner = $bannerIndex === null ? null : $banners[$bannerIndex];
                $imageName = $oldBanner['image'] ?? '';
                if (isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newImage = saveUploadedImage($_FILES['image']);
                    $imageName = $newImage;
                }
                if ($imageName === '') {
                    throw new RuntimeException('Cada banner necesita una imagen.');
                }

                $savedBanner = [
                    'id' => $oldBanner['id'] ?? bin2hex(random_bytes(16)),
                    'image' => $imageName,
                    'text' => $text,
                    'link' => $link,
                    'active' => isset($_POST['active']),
                ];
                if ($bannerIndex === null) {
                    $banners[] = $savedBanner;
                } else {
                    $banners[$bannerIndex] = $savedBanner;
                }
                writeJsonFile(storagePath('banners.json'), array_values($banners));
                $bannerWritten = true;
                if ($newImage !== '' && $oldBanner !== null) {
                    $oldImageStillUsed = count(array_filter(
                        $banners,
                        static fn (array $banner): bool => $banner['image'] === $oldBanner['image']
                    )) > 0;
                    if (!$oldImageStillUsed) {
                        try {
                            removeUploadedImage($oldBanner['image']);
                        } catch (RuntimeException $cleanupException) {
                            error_log('ARAS replaced banner image cleanup failed: ' . $cleanupException->getMessage());
                        }
                    }
                }
                $success = $savedBanner['active']
                    ? 'El banner se guardó y está publicado.'
                    : 'El banner se guardó como oculto.';
            } catch (JsonException | RuntimeException | Error $exception) {
                if ($newImage !== '' && !$bannerWritten) {
                    try {
                        removeUploadedImage($newImage);
                    } catch (RuntimeException $cleanupException) {
                        error_log('ARAS temporary banner image cleanup failed: ' . $cleanupException->getMessage());
                    }
                }
                error_log('ARAS banner save failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            } finally {
                if (is_resource($bannerLock)) {
                    flock($bannerLock, LOCK_UN);
                    fclose($bannerLock);
                }
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'delete_banner') {
            $bannerLock = null;
            try {
                $bannerLock = fopen(storagePath('banners.lock'), 'c');
                if (!is_resource($bannerLock) || !flock($bannerLock, LOCK_EX)) {
                    throw new RuntimeException('No se pudo bloquear la lista de banners para actualizarla.');
                }
                $bannerId = is_string($_POST['banner_id'] ?? null) ? $_POST['banner_id'] : '';
                $banners = siteBanners();
                $removedBanner = null;
                $banners = array_values(array_filter(
                    $banners,
                    static function (array $banner) use ($bannerId, &$removedBanner): bool {
                        if ($banner['id'] === $bannerId) {
                            $removedBanner = $banner;
                            return false;
                        }
                        return true;
                    }
                ));
                if ($removedBanner === null) {
                    throw new RuntimeException('No se encontró el banner que intentas eliminar.');
                }
                writeJsonFile(storagePath('banners.json'), $banners);
                if (!array_filter($banners, static fn (array $banner): bool => $banner['image'] === $removedBanner['image'])) {
                    try {
                        removeUploadedImage($removedBanner['image']);
                    } catch (RuntimeException $cleanupException) {
                        error_log('ARAS deleted banner image cleanup failed: ' . $cleanupException->getMessage());
                    }
                }
                $success = 'El banner se eliminó correctamente.';
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('ARAS banner deletion failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            } finally {
                if (is_resource($bannerLock)) {
                    flock($bannerLock, LOCK_UN);
                    fclose($bannerLock);
                }
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'toggle_banner') {
            $bannerLock = null;
            try {
                $bannerLock = fopen(storagePath('banners.lock'), 'c');
                if (!is_resource($bannerLock) || !flock($bannerLock, LOCK_EX)) {
                    throw new RuntimeException('No se pudo bloquear la lista de banners para actualizarla.');
                }
                $bannerId = is_string($_POST['banner_id'] ?? null) ? $_POST['banner_id'] : '';
                $banners = siteBanners();
                $bannerIndex = null;
                foreach ($banners as $index => $banner) {
                    if ($banner['id'] === $bannerId) {
                        $bannerIndex = $index;
                        break;
                    }
                }
                if ($bannerIndex === null) {
                    throw new RuntimeException('No se encontró el banner que intentas actualizar.');
                }
                $banners[$bannerIndex]['active'] = !$banners[$bannerIndex]['active'];
                writeJsonFile(storagePath('banners.json'), array_values($banners));
                $success = $banners[$bannerIndex]['active']
                    ? 'El banner ahora se muestra en la tienda.'
                    : 'El banner se ocultó de la tienda.';
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('ARAS banner visibility update failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            } finally {
                if (is_resource($bannerLock)) {
                    flock($bannerLock, LOCK_UN);
                    fclose($bannerLock);
                }
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'toggle_demo_banner') {
            $bannerLock = null;
            try {
                $bannerLock = fopen(storagePath('banners.lock'), 'c');
                if (!is_resource($bannerLock) || !flock($bannerLock, LOCK_EX)) {
                    throw new RuntimeException('No se pudo bloquear la lista de banners para actualizarla.');
                }
                $bannerId = is_string($_POST['banner_id'] ?? null) ? $_POST['banner_id'] : '';
                if (!in_array($bannerId, array_column(demoSiteBanners(), 'id'), true)) {
                    throw new RuntimeException('El banner de demostración seleccionado no es válido.');
                }
                $hiddenDemoIds = hiddenDemoSiteBannerIds();
                if (in_array($bannerId, $hiddenDemoIds, true)) {
                    $hiddenDemoIds = array_values(array_filter(
                        $hiddenDemoIds,
                        static fn (string $hiddenId): bool => $hiddenId !== $bannerId
                    ));
                    $success = 'El banner de muestra quedó habilitado para la tienda.';
                } else {
                    $hiddenDemoIds[] = $bannerId;
                    $success = 'El banner de muestra se ocultó de la tienda.';
                }
                writeJsonFile(storagePath('hidden-demo-banners.json'), $hiddenDemoIds);
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('ARAS demo banner visibility update failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            } finally {
                if (is_resource($bannerLock)) {
                    flock($bannerLock, LOCK_UN);
                    fclose($bannerLock);
                }
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'delete_demo_banner') {
            $bannerLock = null;
            try {
                $bannerLock = fopen(storagePath('banners.lock'), 'c');
                if (!is_resource($bannerLock) || !flock($bannerLock, LOCK_EX)) {
                    throw new RuntimeException('No se pudo bloquear la lista de banners para actualizarla.');
                }
                $bannerId = is_string($_POST['banner_id'] ?? null) ? $_POST['banner_id'] : '';
                $demoIds = array_column(demoSiteBanners(), 'id');
                if (!in_array($bannerId, $demoIds, true)) {
                    throw new RuntimeException('El banner de demostración seleccionado no es válido.');
                }
                $hiddenDemoIds = hiddenDemoSiteBannerIds();
                if (in_array($bannerId, $hiddenDemoIds, true)) {
                    throw new RuntimeException('Este banner de demostración ya fue eliminado.');
                }
                $hiddenDemoIds[] = $bannerId;
                writeJsonFile(storagePath('hidden-demo-banners.json'), $hiddenDemoIds);
                $success = 'El banner de demostración se eliminó de la tienda.';
            } catch (JsonException | RuntimeException | Error $exception) {
                error_log('ARAS demo banner deletion failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            } finally {
                if (is_resource($bannerLock)) {
                    flock($bannerLock, LOCK_UN);
                    fclose($bannerLock);
                }
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'save_announcement') {
            $error = 'La sección de anuncio fue reemplazada por el administrador de banners.';
        } elseif (isset($_SESSION['admin_user']) && $action === 'import_products') {
            $inventoryLock = null;
            $categoryLock = null;
            $newlyUploadedImages = [];
            $temporaryImportImages = [];
            $productsWritten = false;
            $createdCategoryCount = 0;
            try {
                $upload = $_FILES['products_csv'] ?? [];
                if (!isset($upload['error'], $upload['tmp_name'], $upload['size'])
                    || is_array($upload['error'])
                    || (int) $upload['error'] !== UPLOAD_ERR_OK
                    || !is_uploaded_file((string) $upload['tmp_name'])) {
                    throw new RuntimeException('Selecciona una plantilla Excel o un archivo CSV válido para importar.');
                }
                $fileExtension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($fileExtension, ['csv', 'xlsx'], true)) {
                    throw new RuntimeException('Usa la plantilla de Excel (.xlsx) o un archivo CSV.');
                }
                $maximumFileSize = $fileExtension === 'xlsx' ? 30 * 1024 * 1024 : 5 * 1024 * 1024;
                if ((int) $upload['size'] < 1 || (int) $upload['size'] > $maximumFileSize) {
                    throw new RuntimeException($fileExtension === 'xlsx'
                        ? 'El archivo Excel debe pesar menos de 30 MB.'
                        : 'El archivo CSV debe pesar menos de 5 MB.');
                }
                $inventoryEnabled = siteSettings()['inventory_enabled'];
                $importCategories = productCategories();
                $importIssues = [];
                if ($fileExtension === 'xlsx') {
                    $workbook = readProductWorkbook((string) $upload['tmp_name']);
                    $headers = $workbook['headers'];
                    $sourceRows = $workbook['rows'];
                    $temporaryImportImages = $workbook['temporary_images'];
                } else {
                    $handle = fopen((string) $upload['tmp_name'], 'rb');
                    if (!is_resource($handle)) {
                        throw new RuntimeException('No se pudo leer el archivo CSV.');
                    }
                    try {
                        $firstLine = fgets($handle);
                        if ($firstLine === false) {
                            throw new RuntimeException('El archivo CSV está vacío.');
                        }
                        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
                        rewind($handle);
                        $rawHeaders = fgetcsv($handle, 0, $delimiter, '"', '\\');
                        if (!is_array($rawHeaders)) {
                            throw new RuntimeException('El archivo CSV está vacío.');
                        }
                        $rawHeaders[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawHeaders[0]);
                        $headers = normalizeProductImportHeaders($rawHeaders);
                        if ($headers === null) {
                            throw new RuntimeException('Los encabezados de la primera fila no coinciden con la plantilla de productos.');
                        }
                        $sourceRows = [];
                        $lineNumber = 1;
                        while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                            $lineNumber++;
                            if ($values === [null] || $values === []) {
                                continue;
                            }
                            $sourceRows[] = ['line' => $lineNumber, 'values' => $values, 'image_path' => null];
                            if (count($sourceRows) > 500) {
                                throw new RuntimeException('Puedes importar hasta 500 productos por archivo.');
                            }
                        }
                    } finally {
                        fclose($handle);
                    }
                }

                $expectedColumns = count($headers);
                $rows = [];
                $totalRows = count($sourceRows);
                if ($totalRows === 0) {
                    throw new RuntimeException('El archivo no contiene filas de productos.');
                }
                foreach ($sourceRows as $sourceRow) {
                    $values = $sourceRow['values'];
                    if (isListArray($values)) {
                        if (count($values) !== $expectedColumns) {
                            $importIssues[] = ['line' => $sourceRow['line'], 'reason' => 'La fila no tiene todas las columnas esperadas.'];
                            continue;
                        }
                        $row = array_combine($headers, array_map(
                            static fn ($value): string => trim((string) $value),
                            $values
                        ));
                    } else {
                        $row = $values;
                    }
                    foreach (['name', 'price', 'category', 'stock', 'active', 'is_new', 'image'] as $requiredField) {
                        $row[$requiredField] ??= '';
                    }
                    $name = $row['name'];
                    $price = filter_var($row['price'], FILTER_VALIDATE_INT);
                    $stock = filter_var($row['stock'], FILTER_VALIDATE_INT);
                    $category = $row['category'];
                    $rowError = '';
                    if ($name === '') {
                        $rowError = 'Falta el nombre del producto.';
                    } elseif (mb_strlen($name, 'UTF-8') > 120) {
                        $rowError = 'El nombre del producto supera los 120 caracteres.';
                    } elseif ($price === false || $price < 1 || $price > 100000000) {
                        $rowError = 'El precio debe ser un número entero entre 1 y 100.000.000.';
                    } elseif ($category === '' || mb_strlen($category, 'UTF-8') > 50) {
                        $rowError = 'Escribe una categoría existente o el nombre de una nueva (máximo 50 caracteres).';
                    } elseif ($inventoryEnabled && ($stock === false || $stock < 0 || $stock > 100000)) {
                        $rowError = 'Las existencias deben ser un número entero entre 0 y 100.000.';
                    } elseif (mb_strlen($row['image'], 'UTF-8') > 255) {
                        $rowError = 'El contenido de la columna de imagen supera los 255 caracteres.';
                    }
                    if ($rowError !== '') {
                        $importIssues[] = ['line' => $sourceRow['line'], 'reason' => $rowError];
                        continue;
                    }
                    if (!in_array(mb_strtolower($row['active'], 'UTF-8'), ['0', '1', 'true', 'false', 'sí', 'si', 'no'], true)) {
                        $importIssues[] = ['line' => $sourceRow['line'], 'reason' => 'Indica Sí/No o 1/0 para publicar el producto.'];
                        continue;
                    }
                    if (!in_array(mb_strtolower($row['is_new'], 'UTF-8'), ['0', '1', 'true', 'false', 'sí', 'si', 'no'], true)) {
                        $importIssues[] = ['line' => $sourceRow['line'], 'reason' => 'Indica Sí/No o 1/0 para marcarlo como nuevo.'];
                        continue;
                    }

                    try {
                        if (isset($row['variants'])) {
                            $variants = trim($row['variants']) === ''
                                ? []
                                : validateProductVariants(json_decode($row['variants'], true, 32, JSON_THROW_ON_ERROR));
                        } else {
                            $variants = [];
                            foreach (['variant_1', 'variant_2', 'variant_3'] as $variantColumn) {
                                $variantValue = trim((string) ($row[$variantColumn] ?? ''));
                                if ($variantValue !== '') {
                                    $variants[] = parseProductImportOptionGroup($variantValue);
                                }
                            }
                            $variants = validateProductVariants($variants);
                        }
                    } catch (JsonException | RuntimeException $exception) {
                        $importIssues[] = [
                            'line' => $sourceRow['line'],
                            'reason' => 'No se pudieron leer las opciones del producto: ' . $exception->getMessage(),
                        ];
                        continue;
                    }

                    $rows[] = [
                        'name' => $name,
                        'price' => $price,
                        'category' => $category,
                        'stock' => $inventoryEnabled ? $stock : 0,
                        'active' => in_array(mb_strtolower($row['active'], 'UTF-8'), ['1', 'true', 'sí', 'si'], true),
                        'is_new' => in_array(mb_strtolower($row['is_new'], 'UTF-8'), ['1', 'true', 'sí', 'si'], true),
                        'image' => $row['image'],
                        'image_path' => $sourceRow['image_path'] ?? null,
                        'variants' => $variants,
                        'line' => $sourceRow['line'],
                    ];
                }

                if ($totalRows === 0) {
                    throw new RuntimeException('El archivo no contiene filas de productos.');
                }

                $categoryLock = fopen(storagePath('categories.lock'), 'c');
                if (!is_resource($categoryLock) || !flock($categoryLock, LOCK_EX)) {
                    throw new RuntimeException('No se pudo bloquear la lista de categorías para actualizarla.');
                }
                $categoriesBeforeImport = readJsonFile(storagePath('categories.json'), []);
                if (!is_array($categoriesBeforeImport) || !isListArray($categoriesBeforeImport)) {
                    throw new RuntimeException('Las categorías guardadas tienen un formato inválido.');
                }
                $categoriesForImport = productCategories();
                $savedCategoriesForImport = $categoriesBeforeImport;
                $inventoryLock = acquireProductInventoryLock();
                $products = readProducts();
                $existingProductCount = count($products);
                $defaultImage = $products[0]['image'] ?? '';
                foreach ($rows as $row) {
                    $imageReference = $row['image'];
                    $imagePath = $row['image_path'];
                    if ($imagePath === '!duplicate!') {
                        $importIssues[] = [
                            'line' => $row['line'],
                            'reason' => 'Hay más de una foto dentro de la celda Foto. Deja solo una por producto.',
                        ];
                        continue;
                    }
                    if ($imagePath === '!oversized!') {
                        $importIssues[] = [
                            'line' => $row['line'],
                            'reason' => 'La foto supera el máximo de 5 MB.',
                        ];
                        continue;
                    }
                    if (is_string($imagePath) && $imagePath !== '') {
                        try {
                            $image = saveImageFile($imagePath, (int) filesize($imagePath));
                            $newlyUploadedImages[] = $image;
                        } catch (RuntimeException $exception) {
                            $importIssues[] = [
                                'line' => $row['line'],
                                'reason' => 'No se pudo procesar la foto insertada en la columna Foto: ' . $exception->getMessage(),
                            ];
                            continue;
                        }
                    } else {
                        $image = $imageReference !== '' ? $imageReference : $defaultImage;
                    }
                    if (!is_string($image)
                        || !preg_match('/^[a-f0-9]{32}\.webp$/', $image)
                        || !is_file(storagePath('uploads' . DIRECTORY_SEPARATOR . $image))) {
                        $imageReferenceError = in_array(mb_strtoupper(trim($imageReference), 'UTF-8'), ['#VALUE!', '#¡VALOR!'], true)
                            ? 'Excel insertó la foto como «imagen en celda», un formato que no se puede importar. Vuelve a insertarla con Insertar > Imágenes > Colocar sobre celdas y ajusta la imagen encima de la celda Foto.'
                            : ($imageReference === ''
                                ? 'Inserta una foto sobre la celda Foto o carga un producto con una imagen predeterminada.'
                                : 'No se encontró "' . $imageReference . '" entre las imágenes ya cargadas.');
                        $importIssues[] = [
                            'line' => $row['line'],
                            'reason' => $imageReferenceError,
                        ];
                        continue;
                    }
                    [$categoryKey, $categoryCreated] = ensureProductImportCategory(
                        $row['category'],
                        $categoriesForImport,
                        $savedCategoriesForImport
                    );
                    if ($categoryCreated) {
                        $createdCategoryCount++;
                    }
                    $products[] = [
                        'id' => bin2hex(random_bytes(16)),
                        'name' => $row['name'],
                        'price' => $row['price'],
                        'category' => $categoryKey,
                        'stock' => $row['stock'],
                        'image' => $image,
                        'images' => [$image],
                        'active' => $row['active'],
                        'is_new' => $row['is_new'],
                        'variants' => $row['variants'],
                    ];
                }
                $importedCount = count($products) - $existingProductCount;
                if ($importedCount > 0) {
                    if ($createdCategoryCount > 0) {
                        writeJsonFile(storagePath('categories.json'), $savedCategoriesForImport);
                    }
                    writeJsonFile(storagePath('products.json'), array_values($products));
                    $productsWritten = true;
                }
                $importReport = [
                    'imported' => $importedCount,
                    'total' => $totalRows,
                    'issues' => $importIssues,
                ];
                $skippedCount = $totalRows - $importedCount;
                if ($importedCount > 0) {
                    $success = 'Importación completa: ' . $importedCount . ' de ' . $totalRows
                        . ' productos agregados; ' . $skippedCount . ' omitidos.';
                    if ($createdCategoryCount > 0) {
                        $success .= ' También se crearon ' . $createdCategoryCount
                            . ' categoría(s) nueva(s).';
                    }
                } else {
                    $error = 'No se agregó ningún producto. Revisa los motivos de las filas omitidas.';
                }
            } catch (JsonException | RuntimeException | Error $exception) {
                if (!$productsWritten) {
                    foreach ($newlyUploadedImages as $fileName) {
                        try {
                            removeUploadedImage($fileName);
                        } catch (RuntimeException $cleanupException) {
                            error_log('ARAS unused product import image cleanup failed: ' . $cleanupException->getMessage());
                        }
                    }
                }
                error_log('ARAS product CSV import failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            } finally {
                releaseProductInventoryLock($inventoryLock);
                if (is_resource($categoryLock)) {
                    flock($categoryLock, LOCK_UN);
                    fclose($categoryLock);
                }
                foreach ($temporaryImportImages as $temporaryPath) {
                    if (is_file($temporaryPath) && !unlink($temporaryPath)) {
                        error_log('ARAS could not remove temporary product workbook image.');
                    }
                }
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'save_product') {
            $newImages = [];
            $inventoryLock = null;
            try {
                $inventoryLock = acquireProductInventoryLock();
                $products = readProducts();
                $id = trim((string) ($_POST['product_id'] ?? ''));
                $isNew = $id === '';
                if (!$isNew && !preg_match('/^[a-f0-9]{32}$/', $id)) {
                    throw new RuntimeException('Identificador de producto inválido.');
                }
                $existingIndex = null;
                foreach ($products as $index => $product) {
                    if (is_array($product) && ($product['id'] ?? null) === $id) {
                        $existingIndex = $index;
                        break;
                    }
                }
                if (!$isNew && $existingIndex === null) {
                    throw new RuntimeException('No se encontró el producto que intentas editar.');
                }

                $name = trim((string) ($_POST['name'] ?? ''));
                $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);
                $inventoryEnabled = siteSettings()['inventory_enabled'];
                $stock = $inventoryEnabled
                    ? filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT)
                    : ($isNew ? 0 : (int) $products[$existingIndex]['stock']);
                $category = (string) ($_POST['category'] ?? '');
                if ($name === '' || mb_strlen($name, 'UTF-8') > 120
                    || $price === false || $price < 1 || $price > 100000000
                    || ($inventoryEnabled && ($stock === false || $stock < 0 || $stock > 100000))
                    || !array_key_exists($category, productCategories())) {
                    throw new RuntimeException('Revisa el nombre, precio, categoría y cantidad disponible.');
                }

                $oldImages = $isNew ? [] : $products[$existingIndex]['images'];
                if (isset($_FILES['images'])) {
                    $newImages = saveUploadedImages($_FILES['images']);
                }
                $imageOrder = $_POST['image_order'] ?? null;
                if ($imageOrder === null) {
                    $productImages = array_values(array_merge($oldImages, $newImages));
                } else {
                    if (!is_array($imageOrder) || !isListArray($imageOrder)) {
                        throw new RuntimeException('El orden de las fotos no es válido.');
                    }

                    $availableImages = [];
                    foreach ($oldImages as $oldImage) {
                        $availableImages[$oldImage] = $oldImage;
                    }
                    foreach ($newImages as $newImageIndex => $newImage) {
                        $availableImages['new:' . $newImageIndex] = $newImage;
                    }
                    if (count($imageOrder) !== count($availableImages)
                        || array_filter($imageOrder, 'is_string') !== $imageOrder
                        || count(array_unique($imageOrder)) !== count($imageOrder)) {
                        throw new RuntimeException('El orden de las fotos no coincide con las imágenes seleccionadas.');
                    }

                    $productImages = [];
                    foreach ($imageOrder as $imageKey) {
                        if (!array_key_exists($imageKey, $availableImages)) {
                            throw new RuntimeException('Una foto del orden seleccionado no pertenece a este producto.');
                        }
                        $productImages[] = $availableImages[$imageKey];
                    }
                }
                if ($productImages === []) {
                    throw new RuntimeException('Cada producto necesita al menos una foto.');
                }
                if (count($productImages) > 10) {
                    throw new RuntimeException('Cada producto puede tener un máximo de 10 fotos. Quita fotos o selecciona menos.');
                }

                $savedProduct = [
                    'id' => $isNew ? bin2hex(random_bytes(16)) : $id,
                    'name' => $name,
                    'price' => $price,
                    'category' => $category,
                    'stock' => $stock,
                    'image' => $productImages[0],
                    'images' => $productImages,
                    'active' => isset($_POST['active']),
                    'is_new' => isset($_POST['is_new']),
                    'variants' => validateProductVariants(
                        trim((string) ($_POST['variants_json'] ?? '')) === ''
                            ? []
                            : json_decode((string) $_POST['variants_json'], true, 32, JSON_THROW_ON_ERROR)
                    ),
                ];
                if ($isNew) {
                    $products[] = $savedProduct;
                } else {
                    $products[$existingIndex] = $savedProduct;
                }
                writeJsonFile(storagePath('products.json'), array_values($products));
                $success = $isNew ? 'Producto agregado al catálogo.' : 'Producto actualizado.';
            } catch (JsonException | RuntimeException | Error $exception) {
                foreach ($newImages as $newImage) {
                    try {
                        removeUploadedImage($newImage);
                    } catch (RuntimeException $cleanupException) {
                        error_log('Entresueño temporary product image cleanup failed: ' . $cleanupException->getMessage());
                    }
                }
                error_log('Entresueño product save failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            } finally {
                releaseProductInventoryLock($inventoryLock);
            }
        } elseif (isset($_SESSION['admin_user']) && $action === 'delete_product') {
            $inventoryLock = null;
            try {
                $inventoryLock = acquireProductInventoryLock();
                $id = (string) ($_POST['product_id'] ?? '');
                $products = readProducts();
                $found = null;
                $products = array_values(array_filter($products, static function ($product) use ($id, &$found): bool {
                    if (is_array($product) && ($product['id'] ?? null) === $id) {
                        $found = $product;
                        return false;
                    }
                    return true;
                }));
                if ($found === null) {
                    throw new RuntimeException('No se encontró el producto para eliminar.');
                }
                writeJsonFile(storagePath('products.json'), $products);
                try {
                    foreach (($found['images'] ?? [$found['image'] ?? '']) as $image) {
                        removeUploadedImage($image);
                    }
                } catch (RuntimeException $cleanupException) {
                    error_log('Entresueño deleted product image cleanup failed: ' . $cleanupException->getMessage());
                }
                $success = 'Producto eliminado del catálogo.';
            } catch (JsonException | RuntimeException $exception) {
                error_log('Entresueño product delete failed: ' . $exception->getMessage());
                $error = 'No se pudo eliminar el producto. Inténtalo de nuevo.';
            } finally {
                releaseProductInventoryLock($inventoryLock);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $action === 'import_products'
    && isset($_SESSION['admin_user'])) {
    $_SESSION['product_import_flash'] = [
        'success' => $success,
        'error' => $error,
        'report' => $importReport,
    ];
    header('Location: ./?section=products', true, 303);
    exit;
}

$isCategoryAjaxRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($action, ['create_category', 'delete_category'], true)
    && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($isCategoryAjaxRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    if (!isset($_SESSION['admin_user']) && !$csrfError && $error === '') {
        $error = 'Debes iniciar sesión para administrar las categorías.';
    }
    if ($csrfError) {
        http_response_code(403);
    } elseif ($error !== '') {
        http_response_code(str_contains($error, 'permiso') ? 403 : 422);
    }

    try {
        $categoryLabels = productCategories();
        $categoryImages = [];
        foreach (productCategoryImages() as $key => $image) {
            $categoryImages[$key] = '../api/imagen-categoria.php?key=' . rawurlencode($key);
        }
        echo json_encode([
            'success' => $error === '' && !$csrfError,
            'message' => $success,
            'error' => $error,
            'created_key' => $selectedCategory,
            'categories' => $categoryLabels,
            'category_images' => $categoryImages,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException | RuntimeException | Error $exception) {
        error_log('ARAS category AJAX response failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'No se pudieron actualizar las categorías.',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$sessionExpired = isset($_SESSION['admin_user'], $_SESSION['last_activity'])
    && time() - (int) $_SESSION['last_activity'] > 7200;
if ($sessionExpired) {
    unset($_SESSION['admin_user'], $_SESSION['last_activity']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $error = 'La sesión expiró. Inicia sesión de nuevo.';
}
$isLoggedIn = isset($_SESSION['admin_user']);
if ($isLoggedIn) {
    $_SESSION['last_activity'] = time();
}

$banners = [];
$demoBanners = [];
$hiddenDemoIds = [];
$hasActiveBanners = false;
$siteSettings = ['logo' => '', 'low_stock_threshold' => 5, 'inventory_enabled' => true];
$displayName = (string) ($_SESSION['admin_user'] ?? '');
$products = [];
$categories = [];
if ($isLoggedIn) {
    try {
        $profile = panelUserProfile((string) $_SESSION['admin_user']);
        if ($profile === null) {
            unset($_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['last_activity']);
            $isLoggedIn = false;
        } elseif (is_string($profile['full_name']) && trim($profile['full_name']) !== '') {
            $displayName = (string) $profile['full_name'];
        }
        $banners = siteBanners();
        $hiddenDemoIds = hiddenDemoSiteBannerIds();
        $hasActiveBanners = count(array_filter(
            $banners,
            static fn (array $banner): bool => $banner['active']
        )) > 0;
        $demoBanners = demoSiteBanners();
        $siteSettings = siteSettings();
        $products = readProducts();
        $categories = productCategories();
    } catch (PDOException $exception) {
        error_log('Entresueño admin profile read failed: ' . $exception->getMessage());
        $error = $exception->getCode() === '42S22'
            ? 'Faltan campos de datos personales en Usuarios. Aplica la actualización de base de datos indicada en el README.'
            : 'No se pudieron leer los datos de la cuenta. Revisa la conexión con la base de datos.';
    } catch (JsonException | RuntimeException | Error $exception) {
        error_log('Entresueño admin data read failed: ' . $exception->getMessage());
        $error = 'No se pudieron leer los datos guardados.';
    }
}
$token = csrfToken();
$availableSections = array_merge(['overview'], panelPermissionSections());
$section = (string) ($_GET['section'] ?? $_POST['return_section'] ?? 'overview');
if ($section === 'add-product') {
    $section = 'products';
}
if (!in_array($section, $availableSections, true)) {
    $section = 'overview';
}
if ($isLoggedIn
    && !in_array($section, $permissions, true)
    && !($section === 'products' && in_array('add-product', $permissions, true))) {
    $section = 'overview';
    $error = 'Tu cuenta no tiene permiso para consultar esa sección.';
}
$orders = [];
$pendingOrders = 0;
$soldProducts = 0;
$soldValue = 0;
if ($isLoggedIn && in_array('orders', $permissions, true)) {
    try {
        expireUnpaidOrders();
        $pendingOrders = (int) databaseConnection()->query(
            "SELECT COUNT(*) FROM `orders` WHERE status = 'pending'"
        )->fetchColumn();
    } catch (PDOException | RuntimeException $exception) {
        error_log('Entresueño pending order count failed: ' . $exception->getMessage());
        if ($section === 'overview') {
            $error = 'No se pudo cargar el conteo de pedidos. Verifica que hayas aplicado el esquema de pedidos.';
        }
    }
    try {
        $salesSummary = databaseConnection()->query(
            "SELECT
                COALESCE((
                    SELECT SUM(items.quantity)
                    FROM `order_items` AS items
                    INNER JOIN `orders` AS sold_orders ON sold_orders.id = items.order_id
                    WHERE sold_orders.status = 'sold'
                ), 0) AS sold_products,
                COALESCE(SUM(CASE WHEN status = 'sold' THEN subtotal ELSE 0 END), 0) AS sold_value
             FROM `orders`"
        )->fetch();
        $soldProducts = (int) ($salesSummary['sold_products'] ?? 0);
        $soldValue = (int) ($salesSummary['sold_value'] ?? 0);
    } catch (PDOException | RuntimeException $exception) {
        error_log('ARAS sales summary read failed: ' . $exception->getMessage());
        if ($section === 'overview') {
            $error = 'No se pudo cargar el resumen de ventas. Verifica que estén instaladas las tablas orders y order_items.';
        }
    }
}
if ($isLoggedIn && $section === 'orders' && in_array('orders', $permissions, true)) {
    try {
        $orders = databaseConnection()->query(
            'SELECT id, reference, status, payment_status, payment_expires_at,
                    payment_transaction_id, customer_name, customer_phone,
                    delivery_method, city, delivery_zone, delivery_address,
                    delivery_latitude, delivery_longitude, subtotal, shipping,
                    total, created_at, reviewed_at, reviewed_by
             FROM `orders`
             ORDER BY FIELD(status, "pending", "sold", "not_concluded"), created_at DESC
             LIMIT 200'
        )->fetchAll();
        $itemsQuery = databaseConnection()->prepare(
            'SELECT product_name, unit_price, quantity FROM `order_items` WHERE order_id = :order_id ORDER BY id'
        );
        foreach ($orders as &$order) {
            $itemsQuery->execute(['order_id' => $order['id']]);
            $order['items'] = $itemsQuery->fetchAll();
        }
        unset($order);
    } catch (PDOException | RuntimeException $exception) {
        error_log('Entresueño admin orders read failed: ' . $exception->getMessage());
        $error = 'No se pudieron cargar los pedidos. Verifica que hayas aplicado las tablas orders y order_items del esquema de base de datos.';
    }
}
$publishedProducts = count(array_filter($products, static fn (array $product): bool => !empty($product['active'])));
$lowStockInventory = $siteSettings['inventory_enabled'] ? array_values(array_filter(
    $products,
    static fn (array $product): bool => !empty($product['active'])
        && (int) $product['stock'] > 0
        && (int) $product['stock'] <= $siteSettings['low_stock_threshold']
)) : [];
$lowStockProducts = count($lowStockInventory);
$lowStockDescriptions = array_map(
    static fn (array $product): string => (string) $product['name'] . ': '
        . (int) $product['stock'] . ' '
        . ((int) $product['stock'] === 1 ? 'unidad' : 'unidades'),
    $lowStockInventory
);
$outOfStockProducts = $siteSettings['inventory_enabled'] ? count(array_filter(
    $products,
    static fn (array $product): bool => !empty($product['active']) && (int) $product['stock'] === 0
)) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/aras-favicon.svg" type="image/svg+xml">
    <link rel="icon" href="../img/favicon.ico?v=2" sizes="any">
    <title>Panel de tienda | ARAS</title>
    <link rel="stylesheet" href="admin.css?v=47">
</head>
<body>
    <main class="admin-shell">
        <header class="admin-topbar">
            <a class="admin-brand" href="./"><span aria-hidden="true">A</span> ARAS <small>ADMINISTRACIÓN</small></a>
            <div class="admin-topbar-actions">
                <?php if ($isLoggedIn): ?>
                    <details class="account-menu">
                        <summary><?= escapeHtml($displayName ?? (string) $_SESSION['admin_user']) ?></summary>
                        <div class="account-menu-panel">
                            <a href="datos-personales">Actualizar datos personales</a>
                            <a href="cambiar-contrasena">Modificar contraseña</a>
                        </div>
                    </details>
                    <form method="post" class="logout-form" data-logout-form>
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="button-secondary">Cerrar sesión</button>
                    </form>
                <?php endif; ?>
            </div>
        </header>
        <?php if ($error !== ''): ?>
            <p class="form-error" role="alert"><?= escapeHtml($error) ?></p>
            <?php if ($csrfError): ?><p><a class="button-link" href="./">Recargar formulario de acceso</a></p><?php endif; ?>
        <?php endif; ?>
        <?php if ($success !== ''): ?><p class="form-success" role="status"><?= escapeHtml($success) ?></p><?php endif; ?>
        <?php if (!$configured): ?>
            <section class="admin-card auth-panel">
                <p class="eyebrow">ACCESO ADMINISTRATIVO</p>
                <h1>Panel no disponible</h1>
                <?php if ($databaseError !== ''): ?>
                    <p><?= escapeHtml($databaseError) ?></p>
                <?php else: ?>
                    <p>Aún no hay una cuenta administrativa activa. El responsable técnico debe ejecutar <code>php admin/create-technical-user.php local</code> en XAMPP o <code>php admin/create-technical-user.php production</code> en el servidor para crear el acceso técnico inicial.</p>
                <?php endif; ?>
            </section>
        <?php elseif (!$isLoggedIn): ?>
            <div class="auth-login-layout">
                <aside class="auth-login-story">
                    <p class="auth-story-kicker">ARAS · TU TIENDA</p>
                    <div class="auth-story-copy">
                        <h2>Pequeños momentos, grandes historias.</h2>
                        <p>Tu tienda y todo lo que la hace especial, siempre cerca de ti.</p>
                    </div>
                    <span class="auth-story-note"><span aria-hidden="true">✦</span> Un espacio hecho para ti</span>
                </aside>
                <section class="admin-card auth-panel">
                    <p class="eyebrow">ACCESO ADMINISTRATIVO</p>
                    <h1>Qué alegría tenerte de vuelta</h1>
                    <p>Inicia sesión para continuar con tu tienda.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                        <input type="hidden" name="action" value="login">
                        <label for="username">Usuario</label>
                        <input id="username" name="username" autocomplete="username" required>
                        <label for="password">Contraseña</label>
                        <div class="password-input-control">
                            <input id="password" name="password" type="password" autocomplete="current-password" required>
                            <button type="button" class="button-secondary password-visibility-toggle" data-password-toggle aria-pressed="false">Mostrar</button>
                        </div>
                        <button type="submit" class="login-submit" data-login-submit>
                            <span class="login-spinner" aria-hidden="true"></span>
                            <span data-login-submit-label>Iniciar sesión</span>
                        </button>
                    </form>
                    <p class="auth-recovery-link"><a href="forgot-password">¿Olvidaste tu contraseña?</a></p>
                </section>
            </div>
        <?php else: ?>
            <div class="admin-layout">
                <aside class="admin-sidebar">
                    <p class="sidebar-label">MENÚ PRINCIPAL</p>
                    <nav aria-label="Secciones de administración">
                        <a href="./?section=overview" class="sidebar-link <?= $section === 'overview' ? 'active' : '' ?>" <?= $section === 'overview' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">⌂</span> Resumen</a>
                        <?php if (in_array('announcement', $permissions, true)): ?>
                        <a href="./?section=announcement" class="sidebar-link <?= $section === 'announcement' ? 'active' : '' ?>" <?= $section === 'announcement' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">✦</span> Banners de inicio</a>
                        <?php endif; ?>
                        <?php if (in_array('products', $permissions, true) || in_array('add-product', $permissions, true)): ?>
                        <a href="./?section=products" class="sidebar-link <?= $section === 'products' ? 'active' : '' ?>" <?= $section === 'products' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">▦</span> Productos</a>
                        <?php endif; ?>
                        <?php if (in_array('orders', $permissions, true)): ?>
                        <a href="./?section=orders" class="sidebar-link <?= $section === 'orders' ? 'active' : '' ?>" <?= $section === 'orders' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">🛍</span> Pedidos <span class="sidebar-count"><?= $pendingOrders ?></span></a>
                        <?php endif; ?>
                        <?php if (in_array('settings', $permissions, true)): ?>
                        <a href="./?section=settings" class="sidebar-link <?= $section === 'settings' ? 'active' : '' ?>" <?= $section === 'settings' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">⚙</span> Configuración</a>
                        <?php endif; ?>
                    </nav>
                    <div class="sidebar-note">
                        <span>Consejo</span>
                        <p>Mantén al día tu catálogo y la disponibilidad de los productos.</p>
                    </div>
                </aside>

                <div class="admin-content">
                    <?php if ($section === 'overview'): ?>
                    <section class="dashboard-welcome">
                        <div>
                            <p class="eyebrow">RESUMEN DE LA TIENDA</p>
                            <h1>Panel de administración</h1>
                            <p>Hola, <strong><?= escapeHtml($displayName ?? (string) $_SESSION['admin_user']) ?></strong>. Aquí puedes mantener ARAS al día.</p>
                        </div>
                    </section>

                    <section class="summary-cards" aria-label="Resumen de la tienda">
                        <?php if (in_array('products', $permissions, true)): ?>
                        <article class="summary-card">
                            <span class="summary-icon">▦</span>
                            <div><p>Productos publicados</p><strong><?= $publishedProducts ?></strong></div>
                        </article>
                        <?php if ($siteSettings['inventory_enabled'] && in_array('products', $permissions, true)): ?>
                            <article class="summary-card">
                                <span class="summary-icon stock-warning">!</span>
                                <div><p>Pocas unidades</p><strong><?= $lowStockProducts ?></strong></div>
                            </article>
                            <article class="summary-card">
                                <span class="summary-icon stock-empty">×</span>
                                <div><p>Agotados</p><strong><?= $outOfStockProducts ?></strong></div>
                            </article>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if (in_array('orders', $permissions, true)): ?>
                        <article class="summary-card">
                            <span class="summary-icon order-icon">🛍</span>
                            <div><p>Pedidos por revisar</p><strong><?= $pendingOrders ?></strong></div>
                        </article>
                        <article class="summary-card">
                            <span class="summary-icon order-icon">✓</span>
                            <div><p>Productos vendidos</p><strong><?= $soldProducts ?></strong></div>
                        </article>
                        <article class="summary-card">
                            <span class="summary-icon order-icon">$</span>
                            <div><p>Valor vendido</p><strong>$<?= number_format($soldValue, 0, ',', '.') ?></strong></div>
                        </article>
                        <?php endif; ?>
                    </section>
                    <?php if ($lowStockProducts > 0 && in_array('products', $permissions, true)): ?>
                        <aside class="inventory-alert" role="status" aria-label="Alerta de inventario bajo">
                            <span class="inventory-alert-icon" aria-hidden="true">!</span>
                            <div class="inventory-alert-content">
                                <div class="inventory-alert-heading">
                                    <strong>Atención: inventario bajo</strong>
                                    <span class="inventory-alert-count"><?= $lowStockProducts ?> <?= $lowStockProducts === 1 ? 'producto' : 'productos' ?></span>
                                </div>
                                <p><?= escapeHtml(implode(' · ', $lowStockDescriptions)) ?>. Revisa el inventario y considera reabastecerlo.</p>
                            </div>
                            <a class="inventory-alert-action" href="./?section=products">Revisar productos <span aria-hidden="true">→</span></a>
                        </aside>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($section === 'announcement'): ?>
                    <section class="admin-section" id="announcement">
                        <div class="section-heading">
                            <div><p class="section-kicker">DESTACA TU COLECCIÓN</p><h2>Banners de inicio</h2></div>
                            <span class="count-pill"><?= count($banners) ?> subidos · <?= count($demoBanners) ?> de muestra</span>
                        </div>
                        <p>Agrega imágenes panorámicas para mostrarlas en un carrusel automático de ancho completo en la tienda. Cada banner puede incluir texto y un enlace opcionales.</p>
                        <p class="banner-measurement-note"><strong>Medida recomendada: 1920 × 750 px</strong> (panorámico, relación 2,56:1). Mantén logotipos y textos importantes en la zona central para evitar recortes en distintas pantallas. JPG, PNG o WebP; máximo 5 MB.</p>
                        <?php if (count($banners) < 10): ?>
                            <article class="banner-admin-card banner-admin-new">
                                <div class="banner-new-heading">
                                    <h3>Agregar banner</h3>
                                    <label for="new-banner-image">Imagen</label>
                                </div>
                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                    <input type="hidden" name="action" value="save_banner">
                                    <input type="hidden" name="return_section" value="announcement">
                                    <div class="image-upload" data-image-upload>
                                        <input class="image-upload-input" id="new-banner-image" type="file" name="image" accept="image/jpeg,image/png,image/webp" required data-image-input>
                                        <label class="image-upload-dropzone" for="new-banner-image">
                                            <img class="image-upload-preview" data-image-preview alt="Vista previa del nuevo banner" hidden>
                                            <span class="image-upload-empty" data-image-empty>
                                                <span class="image-upload-icon" aria-hidden="true">↑</span>
                                                <strong>Selecciona una imagen</strong>
                                                <span>Formatos JPG, PNG o WebP · máximo 5 MB</span>
                                            </span>
                                            <span class="image-upload-change">Elegir imagen</span>
                                        </label>
                                        <div class="image-upload-meta">
                                            <span data-image-name role="status" aria-live="polite">Ninguna imagen seleccionada</span>
                                            <button class="image-upload-reset" type="button" data-image-reset hidden>Quitar selección</button>
                                        </div>
                                    </div>
                                    <div class="banner-live-preview" data-banner-preview>
                                        <img src="../img/aras-banner-demo.svg" alt="Vista previa de ejemplo del banner" data-banner-preview-image>
                                        <span data-banner-preview-text hidden></span>
                                    </div>
                                    <p class="site-settings-help">Esta muestra se reemplazará al seleccionar tu imagen y escribir el texto del banner.</p>
                                    <label for="new-banner-text">Texto (opcional)</label>
                                    <input id="new-banner-text" name="text" maxlength="300">
                                    <label for="new-banner-link">Enlace (opcional)</label>
                                    <input id="new-banner-link" name="link" type="url" maxlength="2048" placeholder="https://">
                                    <label class="checkbox-label"><input type="checkbox" name="active" value="1" checked> Mostrar en la tienda</label>
                                    <button type="submit">Agregar banner</button>
                                </form>
                            </article>
                        <?php endif; ?>
                        <div class="banner-admin-list">
                            <?php foreach ($demoBanners as $demoBanner): ?>
                                <?php $demoBannerHidden = in_array($demoBanner['id'], $hiddenDemoIds, true); ?>
                                <article class="banner-admin-card">
                                    <div class="banner-admin-heading">
                                        <h3><?= escapeHtml($demoBanner['text']) ?></h3>
                                        <span class="status-pill <?= $demoBannerHidden || $hasActiveBanners ? 'status-inactive' : 'status-active' ?>">
                                            <?= $demoBannerHidden ? 'Oculto' : ($hasActiveBanners ? 'De muestra · no publicado' : 'En tienda') ?>
                                        </span>
                                    </div>
                                    <div class="banner-live-preview">
                                        <img src="../<?= escapeHtml($demoBanner['image']) ?>" alt="Banner de demostración">
                                        <span><?= escapeHtml($demoBanner['text']) ?></span>
                                    </div>
                                    <p class="site-settings-help">Los banners de muestra solo aparecen en la tienda cuando no hay banners subidos publicados.</p>
                                    <div class="banner-admin-actions">
                                        <form method="post" class="banner-visibility-form">
                                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                            <input type="hidden" name="action" value="toggle_demo_banner">
                                            <input type="hidden" name="return_section" value="announcement">
                                            <input type="hidden" name="banner_id" value="<?= escapeHtml($demoBanner['id']) ?>">
                                            <button type="submit" class="<?= $demoBannerHidden ? '' : 'button-secondary' ?>">
                                                <?= $demoBannerHidden ? 'Mostrar en la tienda' : 'Ocultar de la tienda' ?>
                                            </button>
                                        </form>
                                        <form method="post" class="banner-delete-form" data-confirm="¿Eliminar este banner de demostración de la tienda?">
                                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                            <input type="hidden" name="action" value="delete_demo_banner">
                                            <input type="hidden" name="return_section" value="announcement">
                                            <input type="hidden" name="banner_id" value="<?= escapeHtml($demoBanner['id']) ?>">
                                            <button type="submit" class="button-danger">Eliminar banner</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            <?php foreach ($banners as $banner): ?>
                                <?php
                                $bannerInputId = 'banner-image-' . $banner['id'];
                                $bannerEditId = 'banner-edit-' . $banner['id'];
                                ?>
                                <article class="banner-admin-card">
                                    <div class="banner-admin-heading">
                                        <h3><?= !empty($banner['text']) ? escapeHtml($banner['text']) : 'Banner sin texto' ?></h3>
                                        <span class="status-pill <?= $banner['active'] ? 'status-active' : 'status-inactive' ?>">
                                            <?= $banner['active'] ? 'Publicado' : 'Oculto' ?>
                                        </span>
                                        <button class="banner-edit-toggle" type="button" aria-label="Editar banner" aria-expanded="false" aria-controls="<?= escapeHtml($bannerEditId) ?>" title="Editar banner">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path></svg>
                                        </button>
                                    </div>
                                    <div class="banner-live-preview" data-banner-preview>
                                        <img src="../api/imagen-banner-admin.php?id=<?= rawurlencode($banner['id']) ?>" alt="Vista previa del banner en la tienda" data-banner-preview-image>
                                        <span data-banner-preview-text <?= $banner['text'] === '' ? 'hidden' : '' ?>><?= escapeHtml($banner['text']) ?></span>
                                    </div>
                                    <p class="site-settings-help">Vista previa aproximada de cómo se verá en la tienda.</p>
                                    <dialog class="banner-edit-dialog" id="<?= escapeHtml($bannerEditId) ?>" aria-labelledby="<?= escapeHtml($bannerEditId) ?>-title">
                                        <div class="banner-edit-dialog-card">
                                            <div class="banner-edit-dialog-heading">
                                                <div>
                                                    <p class="eyebrow">BANNERS DE INICIO</p>
                                                    <h2 id="<?= escapeHtml($bannerEditId) ?>-title">Editar banner</h2>
                                                </div>
                                                <button class="banner-edit-dialog-close" type="button" data-close-banner-edit aria-label="Cerrar edición">×</button>
                                            </div>
                                            <form method="post" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                                <input type="hidden" name="action" value="save_banner">
                                                <input type="hidden" name="return_section" value="announcement">
                                                <input type="hidden" name="banner_id" value="<?= escapeHtml($banner['id']) ?>">
                                                <label for="<?= escapeHtml($bannerInputId) ?>">Imagen<?= $banner['image'] !== '' ? ' (opcional para conservar la actual)' : '' ?></label>
                                                <div class="image-upload" data-image-upload>
                                                    <input class="image-upload-input" id="<?= escapeHtml($bannerInputId) ?>" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-image-input>
                                                    <label class="image-upload-dropzone" for="<?= escapeHtml($bannerInputId) ?>">
                                                        <img class="image-upload-preview" src="../api/imagen-banner-admin.php?id=<?= rawurlencode($banner['id']) ?>" alt="Vista previa del banner" data-image-preview>
                                                        <span class="image-upload-empty" data-image-empty hidden>
                                                            <span class="image-upload-icon" aria-hidden="true">↑</span>
                                                            <strong>Selecciona una imagen</strong>
                                                            <span>1920 × 750 px recomendado · máximo 5 MB</span>
                                                        </span>
                                                        <span class="image-upload-change">Cambiar imagen</span>
                                                    </label>
                                                    <div class="image-upload-meta">
                                                        <span data-image-name role="status" aria-live="polite">Imagen actual</span>
                                                        <button class="image-upload-reset" type="button" data-image-reset hidden>Quitar selección</button>
                                                    </div>
                                                </div>
                                                <label for="banner-text-<?= escapeHtml($banner['id']) ?>">Texto (opcional)</label>
                                                <input id="banner-text-<?= escapeHtml($banner['id']) ?>" name="text" maxlength="300" value="<?= escapeHtml($banner['text']) ?>">
                                                <label for="banner-link-<?= escapeHtml($banner['id']) ?>">Enlace (opcional)</label>
                                                <input id="banner-link-<?= escapeHtml($banner['id']) ?>" name="link" type="url" maxlength="2048" value="<?= escapeHtml($banner['link']) ?>" placeholder="https://">
                                                <label class="checkbox-label"><input type="checkbox" name="active" value="1" <?= $banner['active'] ? 'checked' : '' ?>> Mostrar en la tienda</label>
                                                <div class="banner-edit-dialog-actions">
                                                    <button class="button-secondary" type="button" data-close-banner-edit>Cancelar</button>
                                                    <button type="submit">Guardar banner</button>
                                                </div>
                                            </form>
                                        </div>
                                    </dialog>
                                    <div class="banner-admin-actions">
                                        <form method="post" class="banner-visibility-form">
                                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                            <input type="hidden" name="action" value="toggle_banner">
                                            <input type="hidden" name="return_section" value="announcement">
                                            <input type="hidden" name="banner_id" value="<?= escapeHtml($banner['id']) ?>">
                                            <button type="submit" class="<?= $banner['active'] ? 'button-secondary' : '' ?>">
                                                <?= $banner['active'] ? 'Ocultar de la tienda' : 'Mostrar en la tienda' ?>
                                            </button>
                                        </form>
                                        <form method="post" class="banner-delete-form" data-confirm="¿Eliminar este banner?">
                                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                            <input type="hidden" name="action" value="delete_banner">
                                            <input type="hidden" name="return_section" value="announcement">
                                            <input type="hidden" name="banner_id" value="<?= escapeHtml($banner['id']) ?>">
                                            <button type="submit" class="button-danger">Eliminar banner</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($section === 'settings'): ?>
                    <section class="admin-section" id="settings">
                        <div class="section-heading">
                            <div><p class="section-kicker">PERSONALIZA TU TIENDA</p><h2>Configuración</h2></div>
                        </div>
                        <p>Actualiza el logo que aparece en el encabezado junto a la navegación de tu tienda.</p>
                        <div class="site-settings-actions">
                            <form method="post" enctype="multipart/form-data" class="site-settings-form">
                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                <input type="hidden" name="action" value="save_site_settings">
                                <input type="hidden" name="return_section" value="settings">
                                <label for="site-logo">Logo de la tienda</label>
                                <div class="image-upload" data-image-upload>
                                    <input class="image-upload-input" id="site-logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp" data-image-input required>
                                    <label class="image-upload-dropzone site-logo-dropzone" for="site-logo">
                                        <img class="image-upload-preview site-logo-preview" data-image-preview <?= $siteSettings['logo'] !== '' ? 'src="../api/imagen-logo.php"' : '' ?> alt="Vista previa del logo" <?= $siteSettings['logo'] === '' ? 'hidden' : '' ?>>
                                        <span class="image-upload-empty" data-image-empty <?= $siteSettings['logo'] !== '' ? 'hidden' : '' ?>>
                                            <span class="image-upload-icon" aria-hidden="true">↑</span>
                                            <strong>Selecciona el logo</strong>
                                            <span>JPG, PNG o WebP · máximo 5 MB</span>
                                        </span>
                                        <span class="image-upload-change">Elegir imagen</span>
                                    </label>
                                    <div class="image-upload-meta">
                                        <span data-image-name role="status" aria-live="polite"><?= $siteSettings['logo'] !== '' ? 'Logo actual' : 'Ninguna imagen seleccionada' ?></span>
                                        <button class="image-upload-reset" type="button" data-image-reset hidden>Quitar selección</button>
                                    </div>
                                </div>
                                <p class="site-settings-help">Recomendado: logo horizontal con fondo transparente. Se aceptan archivos JPG, PNG o WebP de hasta 5 MB.</p>
                                <button type="submit">Guardar logo</button>
                            </form>
                            <?php if ($siteSettings['logo'] !== ''): ?>
                                <form method="post" data-confirm="¿Eliminar el logo actual de la tienda?">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                    <input type="hidden" name="action" value="delete_site_logo">
                                    <input type="hidden" name="return_section" value="settings">
                                    <button type="submit" class="button-danger">Eliminar logo</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="site-settings-form inventory-threshold-settings">
                            <h3>Control de inventario</h3>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                <input type="hidden" name="action" value="save_inventory_settings">
                                <input type="hidden" name="return_section" value="settings">
                                <label class="checkbox-label"><input type="checkbox" name="inventory_enabled" value="1" <?= $siteSettings['inventory_enabled'] ? 'checked' : '' ?>> Gestionar las cantidades disponibles de los productos</label>
                                <p class="inventory-settings-description">Desactiva esta opción si no llevas inventario. Los productos publicados aparecerán como “Disponible”, no se mostrarán avisos de pocas unidades o agotado y los pedidos no modificarán las cantidades.</p>
                                <div data-inventory-threshold <?= $siteSettings['inventory_enabled'] ? '' : 'hidden' ?>>
                                    <label for="low-stock-threshold">Mostrar “Pocas unidades” cuando queden</label>
                                    <div class="field-group">
                                        <input id="low-stock-threshold" name="low_stock_threshold" type="number" min="1" max="100000" step="1" value="<?= (int) $siteSettings['low_stock_threshold'] ?>" <?= $siteSettings['inventory_enabled'] ? 'required' : 'disabled' ?>>
                                        <span>unidades o menos</span>
                                    </div>
                                    <p class="site-settings-help">Ingresa un número entero entre 1 y 100.000.</p>
                                </div>
                                <button type="submit">Guardar configuración de inventario</button>
                            </form>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($section === 'products'): ?>
                    <?php if (in_array('add-product', $permissions, true)): ?>
                    <dialog class="category-dialog product-create-dialog" id="product-create-dialog" aria-labelledby="product-create-title" data-open="<?= $action === 'save_product' && trim((string) ($_POST['product_id'] ?? '')) === '' && $error !== '' ? 'true' : 'false' ?>">
                        <div class="category-dialog-card">
                            <div class="category-dialog-heading">
                                <div><p class="eyebrow">GESTIONA TU CATÁLOGO</p><h2 id="product-create-title">Agregar producto</h2></div>
                                <button type="button" class="category-dialog-close" data-close-product-create aria-label="Cerrar">×</button>
                            </div>
                            <p class="category-dialog-description">Completa la información del producto para incorporarlo al catálogo.</p>
                            <?php if ($action === 'save_product' && trim((string) ($_POST['product_id'] ?? '')) === '' && $error !== ''): ?><p class="form-error category-dialog-error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
                            <form method="post" enctype="multipart/form-data" class="product-create-form">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                            <input type="hidden" name="action" value="save_product">
                            <input type="hidden" name="return_section" value="products">
                            <div class="product-create-basics field-full <?= $siteSettings['inventory_enabled'] ? 'has-stock' : 'without-stock' ?>">
                                <div class="product-create-section-heading field-full">
                                    <h3>Información básica</h3>
                                    <p>Nombre, precio, categoría y existencias del producto.</p>
                                </div>
                                <div class="field-group field-full">
                                    <label for="new-name">Nombre del producto</label>
                                    <input id="new-name" name="name" maxlength="120" required>
                                </div>
                                <div class="field-group">
                                    <label for="new-price">Precio (COP)</label>
                                    <input id="new-price" name="price" type="number" min="1" max="100000000" step="1" required>
                                </div>
                                <div class="field-group">
                                    <label for="new-category">Categoría</label>
                                    <select id="new-category" name="category" required>
                                        <?php foreach ($categories as $key => $label): ?>
                                            <option value="<?= escapeHtml($key) ?>" <?= $key === $selectedCategory ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="button-secondary category-manage-trigger" data-open-category-dialog>＋ Crear o administrar categorías</button>
                                </div>
                                <?php if ($siteSettings['inventory_enabled']): ?>
                                    <div class="field-group">
                                        <label for="new-stock">Cantidad disponible</label>
                                        <input id="new-stock" name="stock" type="number" min="0" max="100000" step="1" required>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="product-create-support field-full">
                                <div class="field-group product-create-panel">
                                    <label>Opciones para el cliente <span>(opcional)</span></label>
                                    <div class="variant-builder" data-variant-builder>
                                        <input type="hidden" name="variants_json" value="[]">
                                        <div class="variant-builder-groups" data-variant-groups></div>
                                        <button type="button" class="button-secondary variant-add-group" data-add-variant-group>＋ Añadir opciones como talla, color o tamaño</button>
                                        <p class="site-settings-help">El cliente elegirá estas opciones al comprar. El precio y las existencias son iguales para todas. Déjalo vacío si no aplica a este producto.</p>
                                    </div>
                                </div>
                                <div class="field-group product-create-panel">
                                    <label for="new-images">Fotos del producto</label>
                                    <div class="image-upload product-gallery-upload" data-image-gallery-upload data-existing-count="0" data-required="true">
                                        <input class="image-upload-input" id="new-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required data-image-gallery-input>
                                        <label class="image-upload-dropzone" for="new-images">
                                            <span class="image-upload-empty">
                                                <span class="image-upload-icon" aria-hidden="true">↑</span>
                                                <strong>Selecciona hasta 10 fotos</strong>
                                                <span>JPG, PNG o WebP · máximo 3 MB por foto</span>
                                            </span>
                                            <span class="image-upload-change">Elegir fotos</span>
                                        </label>
                                        <div class="product-gallery-previews product-gallery-items" data-gallery-items aria-live="polite"></div>
                                        <p class="site-settings-help" data-gallery-status>La primera foto será la principal. Después de seleccionarlas, puedes cambiar el orden con las flechas.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="product-create-publish-row field-full">
                                <label class="checkbox-label"><input type="checkbox" name="is_new" value="1"> Destacar como producto nuevo</label>
                                <label class="checkbox-label"><input type="checkbox" name="active" value="1" checked> Publicar en el catálogo al guardar</label>
                            </div>
                            <button type="submit" class="field-full">Agregar producto</button>
                            </form>
                        </div>
                    </dialog>
                    <dialog class="category-dialog" id="category-dialog" aria-labelledby="category-dialog-title" data-open="<?= $categoryDialogOpen ? 'true' : 'false' ?>">
                        <div class="category-dialog-card">
                            <div class="category-dialog-heading">
                                <div>
                                    <p class="eyebrow">ORGANIZA TU CATÁLOGO</p>
                                    <h2 id="category-dialog-title">Administrar categorías</h2>
                                </div>
                                <button type="button" class="category-dialog-close" data-close-category-dialog aria-label="Cerrar">×</button>
                            </div>
                            <p class="category-dialog-description">Crea y elimina categorías predeterminadas o personalizadas. Para proteger el catálogo, solo se pueden eliminar categorías sin productos asociados.</p>
                            <?php if ($categoryDialogOpen && $error !== ''): ?><p class="form-error category-dialog-error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
                            <form method="post" enctype="multipart/form-data" class="category-create-form" data-category-action>
                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                <input type="hidden" name="action" value="create_category">
                                <input type="hidden" name="return_section" value="products">
                                <label for="category-name">Nombre de la nueva categoría</label>
                                <input id="category-name" name="category_name" maxlength="50" required placeholder="Ej. Pijamas para pareja">
                                <label for="category-image">Imagen de la categoría</label>
                                <div class="image-upload category-image-upload" data-image-upload>
                                    <input class="image-upload-input" id="category-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required data-image-input>
                                    <label class="image-upload-dropzone" for="category-image">
                                        <img class="image-upload-preview" data-image-preview alt="Vista previa de la categoría" hidden>
                                        <span class="image-upload-empty" data-image-empty>
                                            <span class="image-upload-icon" aria-hidden="true">↑</span>
                                            <strong>Selecciona una imagen</strong>
                                            <span>JPG, PNG o WebP · máximo 5 MB</span>
                                        </span>
                                        <span class="image-upload-change">Elegir imagen</span>
                                    </label>
                                    <div class="image-upload-meta">
                                        <span data-image-name role="status" aria-live="polite">Ninguna imagen seleccionada</span>
                                        <button class="image-upload-reset" type="button" data-image-reset hidden>Quitar selección</button>
                                    </div>
                                </div>
                                <button type="submit">Crear categoría</button>
                            </form>
                            <div class="category-dialog-list" data-category-list>
                                <h3>Categorías disponibles</h3>
                                <?php if ($categories === []): ?>
                                    <p class="category-dialog-empty">No hay categorías disponibles.</p>
                                <?php else: ?>
                                    <?php foreach ($categories as $key => $label): ?>
                                        <div class="category-dialog-item">
                                            <span><?= escapeHtml($label) ?></span>
                                            <form method="post" data-category-action data-confirm="¿Eliminar la categoría <?= escapeHtml($label) ?>? Solo se eliminará si no tiene productos asociados.">
                                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_key" value="<?= escapeHtml($key) ?>">
                                                <input type="hidden" name="return_section" value="products">
                                                <button type="submit" class="button-danger">Eliminar</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </dialog>
                    <?php endif; ?>

                    <section class="admin-section" id="products">
                        <div class="section-heading product-section-heading">
                            <div><p class="section-kicker">GESTIONA TU CATÁLOGO</p><h2>Productos</h2></div>
                            <div class="product-heading-actions">
                                <span class="count-pill" data-products-count data-total="<?= count($products) ?>"><?= count($products) ?> en total</span>
                                <?php if (in_array('add-product', $permissions, true) || in_array('products', $permissions, true)): ?>
                                    <div class="product-add-menu">
                                        <?php if (in_array('add-product', $permissions, true)): ?>
                                            <button class="product-add-main" type="button" data-open-product-create>Agregar producto</button>
                                            <?php if (in_array('products', $permissions, true)): ?>
                                                <details class="product-add-dropdown">
                                                    <summary aria-label="Más opciones para agregar productos" title="Más opciones">⌄</summary>
                                                    <div class="product-add-dropdown-menu">
                                                        <button type="button" data-open-product-import>Agregar productos masivo</button>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                        <?php elseif (in_array('products', $permissions, true)): ?>
                                            <button class="product-add-main" type="button" data-open-product-import>Agregar productos masivo</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <dialog class="category-dialog product-import-dialog" id="product-import-dialog" aria-labelledby="product-import-title" data-open="<?= $productImportDialogOpen ? 'true' : 'false' ?>">
                            <div class="category-dialog-card">
                                <div class="category-dialog-heading">
                                    <div>
                                        <p class="eyebrow">GESTIONA TU CATÁLOGO</p>
                                        <h2 id="product-import-title">Importa tus productos</h2>
                                    </div>
                                    <button type="button" class="category-dialog-close" data-close-product-import aria-label="Cerrar">×</button>
                                </div>
                                <p class="category-dialog-description">Agrega hasta 500 productos de una vez. Descarga la plantilla y completa una fila por producto. Para incluir fotos, usa Insertar &gt; Imágenes &gt; Colocar sobre celdas y ajusta cada imagen sobre su celda Foto; no uses «Colocar en celda». No necesitas adjuntar archivos por separado.</p>
                                <a class="product-import-template-link" href="plantilla-productos.php" download>Descargar plantilla de Excel con categorías actuales</a>
                                <?php if ($action === 'import_products' && $error !== ''): ?><p class="form-error category-dialog-error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
                                <?php if (is_array($importReport)): ?>
                                    <section class="product-import-result <?= $importReport['imported'] === 0 ? 'is-error' : '' ?>" aria-live="polite">
                                        <strong>Se agregaron <?= (int) $importReport['imported'] ?> de <?= (int) $importReport['total'] ?> productos.</strong>
                                        <?php if ($importReport['issues'] !== []): ?>
                                            <details open>
                                                <summary><?= count($importReport['issues']) ?> fila(s) omitida(s). Ver motivo</summary>
                                                <ul>
                                                    <?php foreach ($importReport['issues'] as $issue): ?>
                                                        <li>Fila <?= (int) $issue['line'] ?>: <?= escapeHtml($issue['reason']) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php else: ?>
                                            <p>Todos los productos del archivo se agregaron correctamente.</p>
                                        <?php endif; ?>
                                    </section>
                                <?php endif; ?>
                                <form method="post" enctype="multipart/form-data" class="product-import-form">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                    <input type="hidden" name="action" value="import_products">
                                    <input type="hidden" name="return_section" value="products">
                                    <ol class="product-import-steps">
                                        <li><strong>Completa la plantilla.</strong> Escribe un producto por fila y no cambies los encabezados. En Categorías puedes elegir una existente o escribir una nueva; al importar se creará automáticamente.</li>
                                        <li><strong>Agrega las fotos si quieres.</strong> En Excel, usa <em>Insertar &gt; Imágenes &gt; Colocar sobre celdas</em>. Mueve y ajusta cada imagen encima de la celda Foto de su fila. No uses «Colocar en celda».</li>
                                        <li><strong>Importa.</strong> Se agregarán las filas correctas y verás el motivo de las que se omitan.</li>
                                    </ol>
                                    <div class="product-import-fields">
                                        <label for="products-csv">Archivo de productos</label>
                                        <input id="products-csv" name="products_csv" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                                        <small>Usa la plantilla de Excel (.xlsx), máximo 30 MB. También se aceptan CSV anteriores de hasta 5 MB; estos no incluyen fotos.</small>
                                    </div>
                                    <details class="product-import-help">
                                        <summary>¿Qué va en cada columna?</summary>
                                        <dl>
                                            <div><dt>Nombre</dt><dd>Nombre del producto.</dd></div>
                                            <div><dt>Precio</dt><dd>Precio en pesos, solo números.</dd></div>
                                            <div><dt>Categoría</dt><dd>Elige una categoría del desplegable o escribe el nombre de una nueva (máximo 50 caracteres). Las categorías nuevas se crean automáticamente al importar. La hoja «Categorías» incluye sus nombres y claves actuales. Opciones vigentes: <?= escapeHtml(implode(', ', array_map(
                                                static fn ($key, $label): string => $label . ' (' . $key . ')',
                                                array_keys($categories),
                                                array_values($categories)
                                            ))) ?>.</dd></div>
                                            <div><dt>Existencias</dt><dd>Unidades disponibles.</dd></div>
                                            <div><dt>Publicado y Nuevo</dt><dd>Elige Sí o No.</dd></div>
                                            <div><dt>Foto</dt><dd>Inserta una sola imagen con <em>Colocar sobre celdas</em> y ajústala encima de la celda Foto; «Colocar en celda» no es compatible. JPG, PNG o WebP; máximo 5 MB por foto y 25 MB entre todas. Si la dejas vacía, se usa la foto predeterminada del catálogo.</dd></div>
                                            <div><dt>Opción 1, 2 y 3</dt><dd>Opcionales. Escribe el nombre y sus alternativas separadas por <code>|</code>. Ejemplo: <code>Tamaño: 30 ml | 50 ml</code>. Para agregar muestras de color: <code>Color: Rojo=#FF0000 | Degradado=#F28C28+#9B287B</code>.</dd></div>
                                        </dl>
                                        <p>El precio y las existencias aplican al producto completo, no a cada opción.</p>
                                    </details>
                                    <button type="submit">Importar productos</button>
                                </form>
                            </div>
                        </dialog>
                        <?php if ($products === []): ?>
                            <div class="empty-state"><span aria-hidden="true">▦</span><h3>Tu catálogo está listo para empezar</h3><p>Cuando agregues un producto, aparecerá aquí para que puedas editarlo.</p><?php if (in_array('add-product', $permissions, true)): ?><button type="button" data-open-product-create>Agregar el primer producto</button><?php endif; ?></div>
                        <?php else: ?>
                            <div class="product-filters" role="search" aria-label="Buscar y filtrar productos">
                                <label class="product-search-field" for="product-search">Buscar producto
                                    <input id="product-search" type="search" placeholder="Escribe el nombre del producto" autocomplete="off">
                                </label>
                                <label for="product-visibility-filter">Estado
                                    <select id="product-visibility-filter">
                                        <option value="all">Todos</option>
                                        <option value="published">Publicados</option>
                                        <option value="hidden">Ocultos</option>
                                    </select>
                                </label>
                                <label for="product-category-filter">Categoría
                                    <select id="product-category-filter">
                                        <option value="all">Todas las categorías</option>
                                        <?php foreach ($categories as $key => $label): ?>
                                            <option value="<?= escapeHtml($key) ?>"><?= escapeHtml($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="product-list">
                                <?php foreach ($products as $product): ?>
                                    <?php if (!is_array($product)) { continue; } ?>
                                    <?php
                                    $productStock = (int) ($product['stock'] ?? 0);
                                    $productStatus = empty($product['active'])
                                        ? 'Oculto'
                                        : (!$siteSettings['inventory_enabled']
                                            ? 'Disponible'
                                            : ($productStock === 0 ? 'Agotado' : ($productStock <= $siteSettings['low_stock_threshold'] ? 'Pocas unidades' : 'Publicado')));
                                    $productStatusClass = empty($product['active'])
                                        ? 'status-inactive'
                                        : (!$siteSettings['inventory_enabled']
                                            ? 'status-active'
                                            : ($productStock === 0 ? 'status-empty' : ($productStock <= $siteSettings['low_stock_threshold'] ? 'status-warning' : 'status-active')));
                                    ?>
                                    <article
                                        class="product-editor"
                                        data-product-name="<?= escapeHtml(mb_strtolower((string) ($product['name'] ?? ''), 'UTF-8')) ?>"
                                        data-product-category="<?= escapeHtml((string) ($product['category'] ?? '')) ?>"
                                        data-product-active="<?= !empty($product['active']) ? 'true' : 'false' ?>">
                                        <img class="product-thumb" src="../api/imagen-producto.php?id=<?= rawurlencode((string) ($product['id'] ?? '')) ?>" alt="">
                                        <div class="product-summary">
                                            <span class="status-pill <?= $productStatusClass ?>"><?= $productStatus ?></span>
                                            <h3><?= escapeHtml((string) ($product['name'] ?? 'Producto')) ?></h3>
                                            <p><?= escapeHtml($categories[(string) ($product['category'] ?? '')] ?? 'Categoría') ?> · $<?= number_format((int) ($product['price'] ?? 0), 0, ',', '.') ?></p>
                                            <?php if ($siteSettings['inventory_enabled']): ?>
                                                <span class="stock-summary">Stock: <strong><?= $productStock ?></strong></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (in_array('products', $permissions, true)): ?>
                                        <button class="product-edit-toggle" type="button" aria-expanded="false" aria-controls="product-edit-form-<?= escapeHtml((string) ($product['id'] ?? '')) ?>">Editar</button>
                                        <div class="product-edit-form-area" id="product-edit-form-<?= escapeHtml((string) ($product['id'] ?? '')) ?>" hidden>
                                            <form method="post" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                                <input type="hidden" name="action" value="save_product">
                                                <input type="hidden" name="return_section" value="products">
                                                <input type="hidden" name="product_id" value="<?= escapeHtml((string) ($product['id'] ?? '')) ?>">
                                                <label>Nombre</label>
                                                <input name="name" maxlength="120" value="<?= escapeHtml((string) ($product['name'] ?? '')) ?>" required>
                                                <label>Precio (COP)</label>
                                                <input name="price" type="number" min="1" max="100000000" value="<?= (int) ($product['price'] ?? 1) ?>" required>
                                                <label>Categoría</label>
                                                <select name="category" required>
                                                    <?php foreach ($categories as $key => $label): ?><option value="<?= escapeHtml($key) ?>" <?= ($product['category'] ?? '') === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php endforeach; ?>
                                                </select>
                                                <?php if ($siteSettings['inventory_enabled']): ?>
                                                    <label>Cantidad disponible</label>
                                                    <input name="stock" type="number" min="0" max="100000" value="<?= $productStock ?>" required>
                                                <?php endif; ?>
                                                <label>Opciones para el cliente (opcional)</label>
                                                <div class="variant-builder" data-variant-builder>
                                                    <input type="hidden" name="variants_json" value="<?= escapeHtml(json_encode($product['variants'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>">
                                                    <div class="variant-builder-groups" data-variant-groups></div>
                                                    <button type="button" class="button-secondary variant-add-group" data-add-variant-group>＋ Añadir opciones como talla, color o tamaño</button>
                                                    <p class="site-settings-help">El cliente elegirá estas opciones al comprar. El precio y las existencias son iguales para todas. Déjalo vacío si no aplica a este producto.</p>
                                                </div>
                                                <label class="checkbox-label"><input type="checkbox" name="is_new" value="1" <?= !empty($product['is_new']) ? 'checked' : '' ?>> Destacar como producto nuevo</label>
                                                    <label>Fotos del producto</label>
                                                    <div class="image-upload product-gallery-upload" data-image-gallery-upload data-existing-count="<?= count($product['images']) ?>">
                                                        <div class="product-gallery-previews product-gallery-items" data-gallery-items aria-live="polite">
                                                            <?php foreach ($product['images'] as $imageIndex => $_image): ?>
                                                                <div class="product-gallery-item" data-gallery-item data-image-key="<?= escapeHtml($_image) ?>">
                                                                    <img src="../api/imagen-producto.php?id=<?= rawurlencode((string) ($product['id'] ?? '')) ?>&amp;index=<?= (int) $imageIndex ?>" alt="Foto <?= $imageIndex + 1 ?> de <?= escapeHtml((string) ($product['name'] ?? 'producto')) ?>" loading="lazy">
                                                                    <span class="product-gallery-position"><?= $imageIndex === 0 ? 'Principal' : 'Foto ' . ($imageIndex + 1) ?></span>
                                                                    <div class="product-gallery-item-actions">
                                                                        <button type="button" data-gallery-move="up" aria-label="Mover foto <?= $imageIndex + 1 ?> arriba" <?= $imageIndex === 0 ? 'disabled' : '' ?>>↑</button>
                                                                        <button type="button" data-gallery-move="down" aria-label="Mover foto <?= $imageIndex + 1 ?> abajo" <?= $imageIndex === count($product['images']) - 1 ? 'disabled' : '' ?>>↓</button>
                                                                    </div>
                                                                    <input type="hidden" name="image_order[]" value="<?= escapeHtml($_image) ?>">
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <input class="image-upload-input" id="product-images-<?= escapeHtml((string) ($product['id'] ?? '')) ?>" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-image-gallery-input>
                                                        <label class="image-upload-dropzone product-gallery-add" for="product-images-<?= escapeHtml((string) ($product['id'] ?? '')) ?>">
                                                            <span class="image-upload-empty">
                                                                <span class="image-upload-icon" aria-hidden="true">＋</span>
                                                                <strong>Añadir fotos</strong>
                                                                <span>JPG, PNG o WebP · máximo 3 MB por foto</span>
                                                            </span>
                                                            <span class="image-upload-change">Elegir fotos</span>
                                                        </label>
                                                        <p class="site-settings-help" data-gallery-status>Hay <?= count($product['images']) ?> de 10 fotos. Puedes añadir <?= 10 - count($product['images']) ?> más y cambiar su orden con las flechas.</p>
                                                    </div>
                                                <label class="checkbox-label"><input type="checkbox" name="active" value="1" <?= !empty($product['active']) ? 'checked' : '' ?>> Publicado</label>
                                                <button type="submit">Guardar cambios</button>
                                            </form>
                                            <form method="post" class="delete-form" data-confirm="¿Eliminar este producto? Esta acción no se puede deshacer.">
                                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="product_id" value="<?= escapeHtml((string) ($product['id'] ?? '')) ?>">
                                                <button type="submit" class="button-secondary">Eliminar producto</button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <p class="product-filter-empty" data-product-filter-empty hidden>No hay productos que coincidan con los filtros seleccionados.</p>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <?php if ($section === 'orders'): ?>
                    <section class="admin-section" id="orders">
                        <div class="section-heading">
                            <div><p class="section-kicker">VENTAS Y SOLICITUDES</p><h2>Pedidos</h2></div>
                            <span class="count-pill"><?= $pendingOrders ?> por revisar</span>
                        </div>
                        <p>Los pedidos de la tienda aparecen aquí para revisión. Puedes abrir la ubicación confirmada en el mapa. Los pedidos no se aceptan como vendidos hasta que Wompi confirme el pago.</p>
                        <?php if ($orders === []): ?>
                            <div class="empty-state"><span aria-hidden="true">🛍</span><h3>No hay pedidos registrados</h3><p>Cuando un cliente complete una solicitud por la tienda, aparecerá aquí para revisión.</p></div>
                        <?php else: ?>
                            <div class="order-list">
                                <?php foreach ($orders as $order): ?>
                                    <?php
                                    $orderStatusLabels = ['pending' => 'Pendiente', 'sold' => 'Vendida', 'not_concluded' => 'No concluida'];
                                    $orderStatusClasses = ['pending' => 'status-warning', 'sold' => 'status-active', 'not_concluded' => 'status-inactive'];
                                    $paymentStatusLabels = [
                                        'not_applicable' => 'No aplica',
                                        'unpaid' => 'Pendiente de pago',
                                        'paid' => 'Pagado',
                                        'expired' => 'Vencido',
                                        'paid_late' => 'Pago recibido después del vencimiento',
                                    ];
                                    $storedCity = (string) $order['city'];
                                    $orderCity = $storedCity === 'medellin'
                                        ? 'Medellín'
                                        : ($storedCity === 'bogota' ? 'Bogotá' : $storedCity);
                                    $orderMethod = $order['delivery_method'] === 'delivery' ? 'Domicilio' : 'Recogida';
                                    $orderMapUrl = is_numeric($order['delivery_latitude'] ?? null)
                                        && is_numeric($order['delivery_longitude'] ?? null)
                                        ? sprintf(
                                            'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=17/%s/%s',
                                            rawurlencode((string) $order['delivery_latitude']),
                                            rawurlencode((string) $order['delivery_longitude']),
                                            rawurlencode((string) $order['delivery_latitude']),
                                            rawurlencode((string) $order['delivery_longitude'])
                                        )
                                        : '';
                                    ?>
                                    <article class="order-card">
                                        <div class="order-heading">
                                            <div><span class="order-reference"><?= escapeHtml((string) $order['reference']) ?></span><span class="order-date"><?= escapeHtml(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></span></div>
                                            <span class="status-pill <?= $orderStatusClasses[$order['status']] ?? 'status-inactive' ?>"><?= $orderStatusLabels[$order['status']] ?? 'Estado desconocido' ?></span>
                                        </div>
                                        <div class="order-details">
                                            <p><strong>Cliente:</strong> <?= escapeHtml((string) ($order['customer_name'] ?: 'No indicado')) ?><?= !empty($order['customer_phone']) ? ' · ' . escapeHtml((string) $order['customer_phone']) : '' ?></p>
                                            <p><strong>Entrega:</strong> <?= escapeHtml($orderMethod) ?> · <?= escapeHtml($orderCity) ?><?= !empty($order['delivery_address']) ? ' · ' . escapeHtml((string) $order['delivery_address']) : '' ?></p>
                                            <p><strong>Pago:</strong> <?= escapeHtml($paymentStatusLabels[$order['payment_status']] ?? 'Desconocido') ?><?= $order['payment_status'] === 'unpaid' && !empty($order['payment_expires_at']) ? ' · vence ' . escapeHtml(date('d/m/Y H:i', strtotime((string) $order['payment_expires_at']))) : '' ?><?= !empty($order['payment_transaction_id']) ? ' · Wompi ' . escapeHtml((string) $order['payment_transaction_id']) : '' ?></p>
                                            <?php if ($order['payment_status'] === 'paid_late'): ?>
                                                <p class="order-reviewed">El pago requiere revisión manual antes de coordinar la entrega.</p>
                                            <?php endif; ?>
                                            <?php if ($orderMapUrl !== ''): ?>
                                                <p><a href="<?= escapeHtml($orderMapUrl) ?>" target="_blank" rel="noopener noreferrer">Ver ubicación confirmada en el mapa</a></p>
                                            <?php endif; ?>
                                        </div>
                                        <ul class="order-items">
                                            <?php foreach ($order['items'] as $item): ?>
                                                <li><?= (int) $item['quantity'] ?> × <?= escapeHtml((string) $item['product_name']) ?> <span><?= '$' . number_format((int) $item['unit_price'], 0, ',', '.') ?></span></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <div class="order-totals">
                                            <span>Subtotal: $<?= number_format((int) $order['subtotal'], 0, ',', '.') ?></span>
                                            <span>Envío: $<?= number_format((int) $order['shipping'], 0, ',', '.') ?></span>
                                            <strong>Total: $<?= number_format((int) $order['total'], 0, ',', '.') ?></strong>
                                        </div>
                                        <?php if ($order['status'] === 'pending'): ?>
                                            <div class="order-actions">
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                                    <input type="hidden" name="action" value="accept_order">
                                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                                    <button type="submit" <?= !in_array($order['payment_status'], ['paid', 'not_applicable'], true) ? 'disabled' : '' ?>>Aceptar como vendida</button>
                                                </form>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                                    <input type="hidden" name="action" value="reject_order">
                                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                                    <button type="submit" class="button-secondary">Rechazar · No concluida</button>
                                                </form>
                                            </div>
                                        <?php elseif (!empty($order['reviewed_by'])): ?>
                                            <p class="order-reviewed">Revisada por <?= escapeHtml((string) $order['reviewed_by']) ?><?= !empty($order['reviewed_at']) ? ' · ' . escapeHtml(date('d/m/Y H:i', strtotime((string) $order['reviewed_at']))) : '' ?></p>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <footer class="admin-footer">
            <span>© <?= date('Y') ?> ARAS · Administración</span>
            <span>Desarrollado por <a href="https://www.strokbig.com" target="_blank" rel="noopener noreferrer">STROKBIG</a></span>
        </footer>
    </main>
    <script src="admin.js?v=24" defer></script>
</body>
</html>
