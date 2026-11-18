<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$productsPath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'products.json';
$settingsPath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'site-settings.json';
$categoriesPath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'categories.json';
$sourceImagesPath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
$publicImagesPath = $projectRoot . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'productos';
$outputPath = $projectRoot . DIRECTORY_SEPARATOR . 'catalogo-static.json';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$readJson = static function (string $path): array {
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('No se pudo leer ' . basename($path) . '.');
    }

    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException(basename($path) . ' debe contener un objeto o arreglo JSON.');
    }

    return $data;
};

try {
    $storedProducts = $readJson($productsPath);
    $settings = $readJson($settingsPath);
    $storedCategories = $readJson($categoriesPath);
    if (!is_dir($publicImagesPath)
        && !mkdir($publicImagesPath, 0755, true)
        && !is_dir($publicImagesPath)) {
        throw new RuntimeException('No se pudo crear img/productos/.');
    }

    $categoryLabels = [
        'cuidado-personal' => 'Cuidado personal',
        'belleza' => 'Belleza',
        'perfumeria' => 'Perfumería',
    ];
    foreach ($storedCategories as $category) {
        if (is_array($category)
            && isset($category['key'], $category['label'])
            && is_string($category['key'])
            && is_string($category['label'])) {
            $categoryLabels[$category['key']] = $category['label'];
        }
    }

    $products = [];
    $publishedImageNames = [];
    foreach ($storedProducts as $product) {
        if (!is_array($product) || empty($product['active'])) {
            continue;
        }

        $candidateImages = is_array($product['images'] ?? null) ? $product['images'] : [];
        $candidateImages[] = $product['image'] ?? '';
        $imageNames = array_values(array_unique(array_filter(
            $candidateImages,
            static fn ($name): bool => is_string($name) && preg_match('/^[a-f0-9]{32}\.webp$/i', $name) === 1
        )));
        if ($imageNames === []) {
            throw new RuntimeException('Un producto publicado no tiene una imagen válida.');
        }

        $primaryImage = is_string($product['image'] ?? null)
            && preg_match('/^[a-f0-9]{32}\.webp$/i', $product['image']) === 1
            ? $product['image']
            : $imageNames[0];
        foreach ($imageNames as $imageName) {
            $source = $sourceImagesPath . DIRECTORY_SEPARATOR . $imageName;
            $destination = $publicImagesPath . DIRECTORY_SEPARATOR . $imageName;
            if (!is_file($source) || !copy($source, $destination)) {
                throw new RuntimeException('No se pudo publicar la imagen ' . $imageName . '.');
            }
            $publishedImageNames[$imageName] = true;
        }

        $imageUrl = static fn (string $name): string => 'img/productos/' . rawurlencode($name);
        $productCategory = is_string($product['category'] ?? null) ? $product['category'] : '';
        if ($productCategory !== '' && !isset($categoryLabels[$productCategory])) {
            $categoryLabels[$productCategory] = ucfirst(str_replace(['-', '_'], ' ', $productCategory));
        }

        $products[] = [
            'id' => (string) ($product['id'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'price' => (int) ($product['price'] ?? 0),
            'category' => $productCategory,
            'stock' => (int) ($product['stock'] ?? 0),
            'image' => $imageUrl($primaryImage),
            'images' => array_map($imageUrl, array_values(array_filter(
                is_array($product['images'] ?? null) ? $product['images'] : [$primaryImage],
                static fn ($name): bool => is_string($name) && preg_match('/^[a-f0-9]{32}\.webp$/i', $name) === 1
            ))),
            'is_new' => !empty($product['is_new']),
            'variants' => is_array($product['variants'] ?? null) ? $product['variants'] : [],
        ];
    }

    $staticCatalog = [
        'products' => $products,
        'categories' => $categoryLabels,
        'category_images' => [],
        'low_stock_threshold' => max(1, (int) ($settings['low_stock_threshold'] ?? 5)),
        'inventory_enabled' => (bool) ($settings['inventory_enabled'] ?? false),
    ];
    $json = json_encode(
        $staticCatalog,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($outputPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar catalogo-static.json.');
    }

    fwrite(STDOUT, sprintf(
        "Catálogo estático actualizado: %d productos y %d imágenes.\n",
        count($products),
        count($publishedImageNames)
    ));
} catch (JsonException | RuntimeException | Error $exception) {
    fwrite(STDERR, 'No se pudo exportar el catálogo estático: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
