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

function normalizeHeader(string $header): string
{
    $header = trim(mb_strtolower($header, 'UTF-8'));

    $search = ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'];
    $replace = ['i', 'g', 'u', 's', 'o', 'c'];
    $simple = str_replace($search, $replace, $header);
    $simple = preg_replace('/\s+/', ' ', $simple);

    $map = [
        'musteri adi' => 'name',
        'musteri' => 'name',
        'ad soyad' => 'name',
        'firma adi' => 'name',
        'firma' => 'name',
        'name' => 'name',
        'customer' => 'name',
        'customer name' => 'name',

        'telefon' => 'phone',
        'tel' => 'phone',
        'phone' => 'phone',
        'gsm' => 'phone',

        'e posta' => 'email',
        'eposta' => 'email',
        'mail' => 'email',
        'email' => 'email',

        'adres' => 'address',
        'address' => 'address',

        'vergi dairesi' => 'tax_office',
        'tax office' => 'tax_office',
        'tax_office' => 'tax_office',

        'vergi no' => 'tax_number',
        'vergi numarasi' => 'tax_number',
        'tc' => 'tax_number',
        'tc no' => 'tax_number',
        'tax number' => 'tax_number',
        'tax_number' => 'tax_number',

        'bakiye' => 'balance',
        'balance' => 'balance',
        'borc' => 'balance',
        'alacak' => 'balance',
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

function rowsToCustomers(array $rows): array
{
    if (!$rows) {
        return [];
    }

    $headerRow = array_shift($rows);
    $headers = [];

    foreach ($headerRow as $index => $header) {
        $headers[$index] = normalizeHeader((string)$header);
    }

    $customers = [];

    foreach ($rows as $row) {
        $item = [
            'name' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'tax_office' => '',
            'tax_number' => '',
            'balance' => 0,
        ];

        foreach ($headers as $index => $field) {
            $value = $row[$index] ?? '';

            if (!array_key_exists($field, $item)) {
                continue;
            }

            if ($field === 'balance') {
                $item[$field] = cleanNumber($value);
            } else {
                $item[$field] = trim((string)$value);
            }
        }

        if ($item['name'] === '') {
            continue;
        }

        $customers[] = $item;
    }

    return $customers;
}

function importCustomers(array $customers, string $fileName): array
{
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    db()->beginTransaction();

    try {
        foreach ($customers as $customer) {
            if ($customer['name'] === '') {
                $skipped++;
                continue;
            }

            $existing = null;

            if ($customer['tax_number'] !== '') {
                $stmt = db()->prepare("SELECT id FROM customers WHERE tax_number = ? LIMIT 1");
                $stmt->execute([$customer['tax_number']]);
                $existing = $stmt->fetch();
            }

            if (!$existing && $customer['phone'] !== '') {
                $stmt = db()->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
                $stmt->execute([$customer['phone']]);
                $existing = $stmt->fetch();
            }

            if ($existing) {
                $stmt = db()->prepare("
                    UPDATE customers SET
                        name = ?,
                        phone = ?,
                        email = ?,
                        address = ?,
                        tax_office = ?,
                        tax_number = ?,
                        balance = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $customer['name'],
                    $customer['phone'],
                    $customer['email'],
                    $customer['address'],
                    $customer['tax_office'],
                    $customer['tax_number'],
                    $customer['balance'],
                    (int)$existing['id'],
                ]);

                $updated++;
            } else {
                $stmt = db()->prepare("
                    INSERT INTO customers
                    (name, phone, email, address, tax_office, tax_number, balance)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $customer['name'],
                    $customer['phone'],
                    $customer['email'],
                    $customer['address'],
                    $customer['tax_office'],
                    $customer['tax_number'],
                    $customer['balance'],
                ]);

                $inserted++;
            }
        }

        $stmt = db()->prepare("
            INSERT INTO import_logs 
            (import_type, file_name, inserted_count, updated_count, skipped_count, created_by)
            VALUES ('customers', ?, ?, ?, ?, ?)
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
$previewCustomers = [];
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

        $customers = rowsToCustomers($rows);

        if (!$customers) {
            throw new RuntimeException('Dosyada içe aktarılacak müşteri bulunamadı. İlk satır başlık olmalı.');
        }

        $result = importCustomers($customers, $file['name']);
        $previewCustomers = array_slice($customers, 0, 20);

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
        WHERE l.import_type = 'customers'
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
    <title>Müşteri Excel Import - MK Denizcilik ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/style.css?v=6">
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
            <a href="index.php?page=customers" class="active"><span class="menu-dot"></span><span>Müşteriler</span></a>
            <a href="index.php?page=products"><span class="menu-dot"></span><span>Ürünler</span></a>
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
                <h1>Müşteri Excel Import</h1>
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
                    <h2>Müşteri Dosyası Yükle</h2>
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
                            <th>Müşteri Adı</th>
                            <th>Telefon</th>
                            <th>E-posta</th>
                            <th>Adres</th>
                            <th>Vergi Dairesi</th>
                            <th>Vergi No</th>
                            <th>Bakiye</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>ABC Denizcilik</td>
                            <td>0532 000 00 00</td>
                            <td>info@abc.com</td>
                            <td>Beykoz / İstanbul</td>
                            <td>Beykoz</td>
                            <td>1234567890</td>
                            <td>0</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p style="margin-top:14px;color:var(--muted);">
                Vergi no varsa vergi no ile günceller. Vergi no yoksa telefon ile günceller. İkisi de yoksa yeni müşteri ekler.
            </p>
        </section>

        <?php if ($previewCustomers): ?>
            <section class="panel">
                <div class="section-title">
                    <div>
                        <p>Önizleme</p>
                        <h2>İlk 20 Müşteri</h2>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Müşteri</th>
                                <th>Telefon</th>
                                <th>E-posta</th>
                                <th>Vergi Dairesi</th>
                                <th>Vergi No</th>
                                <th>Bakiye</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewCustomers as $c): ?>
                                <tr>
                                    <td><?= e($c['name']) ?></td>
                                    <td><?= e($c['phone']) ?></td>
                                    <td><?= e($c['email']) ?></td>
                                    <td><?= e($c['tax_office']) ?></td>
                                    <td><?= e($c['tax_number']) ?></td>
                                    <td><?= number_format((float)$c['balance'], 2, ',', '.') ?> ₺</td>
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