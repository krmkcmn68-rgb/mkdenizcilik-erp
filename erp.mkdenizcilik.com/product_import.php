<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isAdmin(): bool
{
    $user = currentUser();
    return $user && ($user['role'] ?? '') === 'admin';
}

function firstLetter(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'U';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 1, 'UTF-8');
    }

    return substr($name, 0, 1);
}

function getSetting(string $key, string $default = ''): string
{
    $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return $row ? (string)$row['setting_value'] : $default;
}

function normalizeHeader(string $header): string
{
    $header = trim(mb_strtolower($header, 'UTF-8'));

    $search = ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'];
    $replace = ['i', 'g', 'u', 's', 'o', 'c'];
    $simple = str_replace($search, $replace, $header);
    $simple = preg_replace('/\s+/', ' ', $simple);

    $map = [
        'urun adi' => 'name',
        'urun' => 'name',
        'name' => 'name',
        'product' => 'name',
        'product name' => 'name',
        'product_name' => 'name',

        'stok kodu' => 'stock_code',
        'stok_kodu' => 'stock_code',
        'stock code' => 'stock_code',
        'stock_code' => 'stock_code',
        'kod' => 'stock_code',
        'code' => 'stock_code',

        'barkod' => 'barcode',
        'barcode' => 'barcode',

        'kategori' => 'category',
        'category' => 'category',

        'alis fiyati' => 'purchase_price',
        'alis fiyat' => 'purchase_price',
        'alis' => 'purchase_price',
        'purchase_price' => 'purchase_price',
        'purchase price' => 'purchase_price',
        'cost' => 'purchase_price',

        'alis para birimi' => 'purchase_currency',
        'alis doviz' => 'purchase_currency',
        'para birimi' => 'purchase_currency',
        'doviz' => 'purchase_currency',
        'currency' => 'purchase_currency',
        'purchase_currency' => 'purchase_currency',

        'satis fiyati' => 'sale_price_original',
        'satis fiyat' => 'sale_price_original',
        'satis' => 'sale_price_original',
        'sale_price' => 'sale_price_original',
        'sale price' => 'sale_price_original',
        'price' => 'sale_price_original',

        'satis para birimi' => 'sale_currency',
        'satis doviz' => 'sale_currency',
        'satis currency' => 'sale_currency',
        'sale currency' => 'sale_currency',
        'sale_currency' => 'sale_currency',

        'kdv' => 'vat_rate',
        'kdv orani' => 'vat_rate',
        'vat' => 'vat_rate',
        'vat_rate' => 'vat_rate',

        'stok' => 'stock',
        'stock' => 'stock',
        'miktar' => 'stock',

        'birim' => 'unit',
        'unit' => 'unit',

        'kritik stok' => 'min_stock',
        'min stok' => 'min_stock',
        'minimum stok' => 'min_stock',
        'min_stock' => 'min_stock',
    ];

    return $map[$simple] ?? $simple;
}

function cleanNumber($value): float
{
    $value = trim((string)$value);

    if ($value === '') {
        return 0.0;
    }

    $value = str_replace(['₺', '$', '€', ' '], '', $value);

    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function normalizeCurrency(string $currency): string
{
    $currency = strtoupper(trim($currency));

    if ($currency === '') {
        return 'TRY';
    }

    $currency = str_replace(['₺', 'TL', 'TÜRK LİRASI', 'TURK LIRASI'], 'TRY', $currency);
    $currency = str_replace(['$', 'DOLAR'], 'USD', $currency);
    $currency = str_replace(['€', 'EURO'], 'EUR', $currency);

    if (!in_array($currency, ['TRY', 'USD', 'EUR'], true)) {
        return 'TRY';
    }

    return $currency;
}

function convertToTry(float $price, string $currency): float
{
    $currency = normalizeCurrency($currency);

    if ($currency === 'USD') {
        $rate = cleanNumber(getSetting('usd_rate', '0'));
        return $rate > 0 ? $price * $rate : $price;
    }

    if ($currency === 'EUR') {
        $rate = cleanNumber(getSetting('eur_rate', '0'));
        return $rate > 0 ? $price * $rate : $price;
    }

    return $price;
}

function columnIndexFromCellRef(string $cellRef): int
{
    preg_match('/[A-Z]+/', strtoupper($cellRef), $matches);
    $letters = $matches[0] ?? 'A';

    $index = 0;
    $length = strlen($letters);

    for ($i = 0; $i < $length; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }

    return $index - 1;
}

function readXlsx(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Sunucuda ZipArchive aktif değil. XLSX yerine CSV yükleyebilirsin.');
    }

    $zip = new ZipArchive();

    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Excel dosyası açılamadı.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);

        if ($xml) {
            foreach ($xml->si as $si) {
                $text = '';

                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                }

                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException('Excel içinde ilk sayfa bulunamadı.');
    }

    $xml = simplexml_load_string($sheetXml);

    if (!$xml) {
        $zip->close();
        throw new RuntimeException('Excel sayfası okunamadı.');
    }

    $rows = [];

    foreach ($xml->sheetData->row as $row) {
        $rowData = [];

        foreach ($row->c as $cell) {
            $attributes = $cell->attributes();
            $cellRef = (string)($attributes['r'] ?? '');
            $type = (string)($attributes['t'] ?? '');
            $colIndex = columnIndexFromCellRef($cellRef);

            $value = '';

            if ($type === 's') {
                $stringIndex = (int)$cell->v;
                $value = $sharedStrings[$stringIndex] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)$cell->is->t;
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }

            $rowData[$colIndex] = trim($value);
        }

        if ($rowData) {
            ksort($rowData);
            $max = max(array_keys($rowData));
            $fixed = [];

            for ($i = 0; $i <= $max; $i++) {
                $fixed[$i] = $rowData[$i] ?? '';
            }

            $rows[] = $fixed;
        }
    }

    $zip->close();

    return $rows;
}

function readCsv(string $filePath): array
{
    $content = file_get_contents($filePath);

    if ($content === false) {
        throw new RuntimeException('CSV dosyası okunamadı.');
    }

    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $tmp = tmpfile();

    if (!$tmp) {
        throw new RuntimeException('CSV geçici dosya oluşturulamadı.');
    }

    fwrite($tmp, $content);
    $meta = stream_get_meta_data($tmp);
    $path = $meta['uri'];

    $firstLine = strtok($content, "\n") ?: '';
    $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

    $rows = [];
    $handle = fopen($path, 'r');

    if (!$handle) {
        throw new RuntimeException('CSV dosyası açılamadı.');
    }

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rows[] = $data;
    }

    fclose($handle);
    fclose($tmp);

    return $rows;
}

function rowsToProducts(array $rows): array
{
    if (!$rows) {
        return [];
    }

    $headerRow = array_shift($rows);
    $headers = [];

    foreach ($headerRow as $index => $header) {
        $headers[$index] = normalizeHeader((string)$header);
    }

    $products = [];

    foreach ($rows as $row) {
        $item = [
            'name' => '',
            'stock_code' => '',
            'barcode' => '',
            'category' => '',
            'purchase_price' => 0,
            'purchase_currency' => 'TRY',
            'purchase_price_try' => 0,
            'sale_price_original' => 0,
            'sale_currency' => 'TRY',
            'sale_price' => 0,
            'vat_rate' => 20,
            'stock' => 0,
            'unit' => 'adet',
            'min_stock' => 3,
        ];

        foreach ($headers as $index => $field) {
            $value = $row[$index] ?? '';

            if (!array_key_exists($field, $item)) {
                continue;
            }

            if (in_array($field, ['purchase_price', 'sale_price_original', 'vat_rate', 'stock', 'min_stock'], true)) {
                $item[$field] = cleanNumber($value);
            } elseif (in_array($field, ['purchase_currency', 'sale_currency'], true)) {
                $item[$field] = normalizeCurrency((string)$value);
            } else {
                $item[$field] = trim((string)$value);
            }
        }

        if ($item['name'] === '') {
            continue;
        }

        if ($item['unit'] === '') {
            $item['unit'] = 'adet';
        }

        if ($item['vat_rate'] <= 0) {
            $item['vat_rate'] = 20;
        }

        if ($item['min_stock'] <= 0) {
            $item['min_stock'] = 3;
        }

        if ($item['purchase_currency'] === '') {
            $item['purchase_currency'] = 'TRY';
        }

        if ($item['sale_currency'] === '') {
            $item['sale_currency'] = 'TRY';
        }

        $item['purchase_price_try'] = convertToTry((float)$item['purchase_price'], (string)$item['purchase_currency']);
        $item['sale_price'] = convertToTry((float)$item['sale_price_original'], (string)$item['sale_currency']);

        $products[] = $item;
    }

    return $products;
}

function importProducts(array $products, string $fileName): array
{
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    db()->beginTransaction();

    try {
        foreach ($products as $product) {
            if ($product['name'] === '') {
                $skipped++;
                continue;
            }

            $existing = null;

            if ($product['barcode'] !== '') {
                $stmt = db()->prepare("SELECT id FROM products WHERE barcode = ? LIMIT 1");
                $stmt->execute([$product['barcode']]);
                $existing = $stmt->fetch();
            }

            if (!$existing && $product['stock_code'] !== '') {
                $stmt = db()->prepare("SELECT id FROM products WHERE stock_code = ? LIMIT 1");
                $stmt->execute([$product['stock_code']]);
                $existing = $stmt->fetch();
            }

            if ($existing) {
                $stmt = db()->prepare("
                    UPDATE products SET
                        name = ?,
                        stock_code = ?,
                        barcode = ?,
                        category = ?,
                        purchase_price = ?,
                        purchase_currency = ?,
                        purchase_price_try = ?,
                        sale_price = ?,
                        sale_currency = ?,
                        sale_price_original = ?,
                        vat_rate = ?,
                        stock = ?,
                        unit = ?,
                        min_stock = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $product['name'],
                    $product['stock_code'],
                    $product['barcode'],
                    $product['category'],
                    $product['purchase_price'],
                    $product['purchase_currency'],
                    $product['purchase_price_try'],
                    $product['sale_price'],
                    $product['sale_currency'],
                    $product['sale_price_original'],
                    $product['vat_rate'],
                    $product['stock'],
                    $product['unit'],
                    $product['min_stock'],
                    (int)$existing['id'],
                ]);

                $updated++;
            } else {
                $stmt = db()->prepare("
                    INSERT INTO products
                    (name, stock_code, barcode, category, purchase_price, purchase_currency, purchase_price_try, sale_price, sale_currency, sale_price_original, vat_rate, stock, unit, min_stock)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $product['name'],
                    $product['stock_code'],
                    $product['barcode'],
                    $product['category'],
                    $product['purchase_price'],
                    $product['purchase_currency'],
                    $product['purchase_price_try'],
                    $product['sale_price'],
                    $product['sale_currency'],
                    $product['sale_price_original'],
                    $product['vat_rate'],
                    $product['stock'],
                    $product['unit'],
                    $product['min_stock'],
                ]);

                $inserted++;
            }
        }

        $stmt = db()->prepare("
            INSERT INTO import_logs 
            (import_type, file_name, inserted_count, updated_count, skipped_count, created_by)
            VALUES ('products', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $fileName,
            $inserted,
            $updated,
            $skipped,
            currentUser()['id'] ?? null,
        ]);

        db()->commit();

    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
    ];
}

if (!currentUser()) {
    header('Location: index.php?page=login');
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo 'Bu sayfaya sadece admin erişebilir.';
    exit;
}

$message = '';
$error = '';
$previewProducts = [];
$logs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Dosya yüklenemedi.');
        }

        $file = $_FILES['import_file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('Sadece XLSX veya CSV dosyası yükleyebilirsin.');
        }

        if ($extension === 'xlsx') {
            $rows = readXlsx($file['tmp_name']);
        } else {
            $rows = readCsv($file['tmp_name']);
        }

        $products = rowsToProducts($rows);

        if (!$products) {
            throw new RuntimeException('Dosyada içe aktarılacak ürün bulunamadı. İlk satır başlık olmalı.');
        }

        $result = importProducts($products, $file['name']);
        $previewProducts = array_slice($products, 0, 20);

        $message = 'Import tamamlandı. Yeni: ' . $result['inserted'] . ' | Güncellenen: ' . $result['updated'] . ' | Atlanan: ' . $result['skipped'];

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $logs = db()->query("
        SELECT l.*, u.name AS user_name
        FROM import_logs l
        LEFT JOIN users u ON u.id = l.created_by
        WHERE l.import_type = 'products'
        ORDER BY l.id DESC
        LIMIT 10
    ")->fetchAll();
} catch (Throwable $e) {
    $logs = [];
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Ürün Excel Import - MK Denizcilik ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/style.css?v=8">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand-area">
            <div class="brand-mark">MK</div>
            <div class="brand-text">
                <strong>MK Denizcilik</strong>
                <span>ERP Yönetim Paneli</span>
            </div>
        </div>

        <nav class="sidebar-menu">
            <a href="index.php?page=dashboard"><span class="menu-dot"></span><span>Güncel Durum</span></a>
            <a href="index.php?page=customers"><span class="menu-dot"></span><span>Müşteriler</span></a>
            <a href="index.php?page=products" class="active"><span class="menu-dot"></span><span>Ürünler</span></a>
            <a href="index.php?page=sales"><span class="menu-dot"></span><span>Satış</span></a>
            <a href="index.php?page=settings"><span class="menu-dot"></span><span>Ayarlar</span></a>
        </nav>

        <div class="sidebar-bottom">
            <div class="mini-user">
                <div class="mini-avatar"><?= e(firstLetter(currentUser()['name'] ?? 'A')) ?></div>
                <div>
                    <strong><?= e(currentUser()['name'] ?? 'Admin') ?></strong>
                    <span><?= e(currentUser()['role'] ?? 'admin') ?></span>
                </div>
            </div>

            <a href="index.php?page=logout" class="logout-btn">Çıkış Yap</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">MK Denizcilik ERP</p>
                <h1>Ürün Excel Import</h1>
            </div>

            <div class="top-actions">
                <div class="role-badge">admin</div>
                <div class="date-pill"><?= date('d.m.Y') ?></div>
            </div>
        </header>

        <?php if ($message): ?>
            <section class="panel">
                <div class="alert" style="background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.18);color:#9af0b7;">
                    <?= e($message) ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($error): ?>
            <section class="panel">
                <div class="alert error"><?= e($error) ?></div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="section-title">
                <div>
                    <p>Import</p>
                    <h2>Ürün Dosyası Yükle</h2>
                </div>
            </div>

            <form method="post" enctype="multipart/form-data" class="grid-form">
                <div class="full">
                    <label>Excel / CSV Dosyası</label>
                    <input type="file" name="import_file" accept=".xlsx,.csv" required>
                </div>

                <button type="submit">Import Et</button>
            </form>
        </section>

        <section class="panel">
            <div class="section-title">
                <div>
                    <p>Şablon</p>
                    <h2>Excel Kolonları</h2>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Ürün Adı</th>
                            <th>Stok Kodu</th>
                            <th>Barkod</th>
                            <th>Kategori</th>
                            <th>Alış Fiyatı</th>
                            <th>Alış Para Birimi</th>
                            <th>Satış Fiyatı</th>
                            <th>Satış Para Birimi</th>
                            <th>KDV</th>
                            <th>Stok</th>
                            <th>Birim</th>
                            <th>Kritik Stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Can Yeleği</td>
                            <td>CY-001</td>
                            <td>868000000001</td>
                            <td>Güvenlik</td>
                            <td>10</td>
                            <td>USD</td>
                            <td>20</td>
                            <td>USD</td>
                            <td>20</td>
                            <td>15</td>
                            <td>adet</td>
                            <td>3</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p style="margin-top:14px;color:var(--muted);">
                Alış ve satış para birimi TRY / USD / EUR olabilir. USD/EUR ise ayarlardaki kurla TL’ye çevrilir.
            </p>
        </section>

        <?php if ($previewProducts): ?>
            <section class="panel">
                <div class="section-title">
                    <div>
                        <p>Önizleme</p>
                        <h2>İlk 20 Ürün</h2>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Stok Kodu</th>
                                <th>Barkod</th>
                                <th>Alış</th>
                                <th>Alış Para</th>
                                <th>Alış TL</th>
                                <th>Satış</th>
                                <th>Satış Para</th>
                                <th>Satış TL</th>
                                <th>Stok</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewProducts as $p): ?>
                                <tr>
                                    <td><?= e($p['name']) ?></td>
                                    <td><?= e($p['stock_code']) ?></td>
                                    <td><?= e($p['barcode']) ?></td>
                                    <td><?= number_format((float)$p['purchase_price'], 2, ',', '.') ?></td>
                                    <td><?= e($p['purchase_currency']) ?></td>
                                    <td><?= number_format((float)$p['purchase_price_try'], 2, ',', '.') ?> ₺</td>
                                    <td><?= number_format((float)$p['sale_price_original'], 2, ',', '.') ?></td>
                                    <td><?= e($p['sale_currency']) ?></td>
                                    <td><?= number_format((float)$p['sale_price'], 2, ',', '.') ?> ₺</td>
                                    <td><?= number_format((float)$p['stock'], 2, ',', '.') ?> <?= e($p['unit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="section-title">
                <div>
                    <p>Geçmiş</p>
                    <h2>Son Import İşlemleri</h2>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Dosya</th>
                            <th>Yeni</th>
                            <th>Güncellenen</th>
                            <th>Atlanan</th>
                            <th>Kullanıcı</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= e($log['file_name']) ?></td>
                                <td><?= (int)$log['inserted_count'] ?></td>
                                <td><?= (int)$log['updated_count'] ?></td>
                                <td><?= (int)$log['skipped_count'] ?></td>
                                <td><?= e($log['user_name'] ?? '-') ?></td>
                                <td><?= e($log['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$logs): ?>
                            <tr>
                                <td colspan="6">Henüz import geçmişi yok.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>