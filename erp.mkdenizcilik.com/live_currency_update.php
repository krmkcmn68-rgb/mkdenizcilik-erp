<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';

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

function cleanRate(string $value): float
{
    $value = trim($value);
    $value = str_replace(['₺', '$', '€', ' '], '', $value);
    $value = str_replace(',', '.', $value);

    return (float)$value;
}

function saveSetting(string $key, string $value): void
{
    $stmt = db()->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

function getTcmbXml(): string
{
    $url = 'https://www.tcmb.gov.tr/kurlar/today.xml';

    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header' => "User-Agent: MK-Denizcilik-ERP\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $xml = @file_get_contents($url, false, $context);

    if ($xml === false || trim($xml) === '') {
        throw new RuntimeException('TCMB kur servisine ulaşılamadı. Hosting dış bağlantıyı engelliyor olabilir.');
    }

    return $xml;
}

function parseRatesFromTcmb(string $xmlContent): array
{
    $xml = @simplexml_load_string($xmlContent);

    if (!$xml) {
        throw new RuntimeException('TCMB XML verisi okunamadı.');
    }

    $rates = [
        'USD' => 0,
        'EUR' => 0,
    ];

    foreach ($xml->Currency as $currency) {
        $code = (string)$currency['CurrencyCode'];

        if ($code === 'USD') {
            $rates['USD'] = cleanRate((string)$currency->ForexSelling);
        }

        if ($code === 'EUR') {
            $rates['EUR'] = cleanRate((string)$currency->ForexSelling);
        }
    }

    if ($rates['USD'] <= 0 || $rates['EUR'] <= 0) {
        throw new RuntimeException('USD veya EUR kuru TCMB verisinden alınamadı.');
    }

    return $rates;
}

function columnExists(string $table, string $column): bool
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function ensureSaleCurrencyColumns(): void
{
    if (!columnExists('products', 'sale_currency')) {
        db()->exec("
            ALTER TABLE products
            ADD COLUMN sale_currency VARCHAR(10) NOT NULL DEFAULT 'TRY'
            AFTER sale_price
        ");
    }

    if (!columnExists('products', 'sale_price_original')) {
        db()->exec("
            ALTER TABLE products
            ADD COLUMN sale_price_original DECIMAL(12,2) NOT NULL DEFAULT 0
            AFTER sale_currency
        ");
    }

    db()->exec("
        UPDATE products
        SET
            sale_currency = IFNULL(NULLIF(sale_currency, ''), 'TRY'),
            sale_price_original = CASE
                WHEN sale_price_original IS NULL OR sale_price_original = 0 THEN sale_price
                ELSE sale_price_original
            END
    ");
}

function updateProductPrices(float $usdRate, float $eurRate): int
{
    ensureSaleCurrencyColumns();

    $products = db()->query("
        SELECT 
            id, 
            purchase_price, 
            purchase_currency,
            sale_price,
            sale_price_original,
            sale_currency
        FROM products
    ")->fetchAll();

    $updated = 0;

    foreach ($products as $product) {
        $purchasePrice = (float)$product['purchase_price'];
        $purchaseCurrency = strtoupper((string)($product['purchase_currency'] ?? 'TRY'));

        $purchasePriceTry = $purchasePrice;

        if ($purchaseCurrency === 'USD') {
            $purchasePriceTry = $purchasePrice * $usdRate;
        } elseif ($purchaseCurrency === 'EUR') {
            $purchasePriceTry = $purchasePrice * $eurRate;
        }

        $saleOriginal = (float)($product['sale_price_original'] ?? 0);
        $saleCurrency = strtoupper((string)($product['sale_currency'] ?? 'TRY'));

        if ($saleOriginal <= 0) {
            $saleOriginal = (float)$product['sale_price'];
        }

        $salePriceTry = $saleOriginal;

        if ($saleCurrency === 'USD') {
            $salePriceTry = $saleOriginal * $usdRate;
        } elseif ($saleCurrency === 'EUR') {
            $salePriceTry = $saleOriginal * $eurRate;
        }

        $stmt = db()->prepare("
            UPDATE products 
            SET 
                purchase_price_try = ?,
                sale_price = ?,
                sale_price_original = ?,
                sale_currency = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $purchasePriceTry,
            $salePriceTry,
            $saleOriginal,
            $saleCurrency,
            (int)$product['id'],
        ]);

        $updated++;
    }

    return $updated;
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

try {
    $xml = getTcmbXml();
    $rates = parseRatesFromTcmb($xml);

    db()->beginTransaction();

    saveSetting('usd_rate', (string)$rates['USD']);
    saveSetting('eur_rate', (string)$rates['EUR']);
    saveSetting('currency_last_update', date('Y-m-d H:i:s'));

    $updatedProducts = updateProductPrices((float)$rates['USD'], (float)$rates['EUR']);

    db()->commit();

    $message = 'Canlı kur güncellendi. USD: ' . number_format((float)$rates['USD'], 4, ',', '.') .
        ' ₺ | EUR: ' . number_format((float)$rates['EUR'], 4, ',', '.') .
        ' ₺ | Güncellenen ürün: ' . $updatedProducts;

} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Canlı Kur Güncelle - MK Denizcilik ERP</title>
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
            <a href="index.php?page=products"><span class="menu-dot"></span><span>Ürünler</span></a>
            <a href="index.php?page=sales"><span class="menu-dot"></span><span>Satış</span></a>
            <a href="index.php?page=settings" class="active"><span class="menu-dot"></span><span>Ayarlar</span></a>
        </nav>

        <div class="sidebar-bottom">
            <div class="mini-user">
                <div class="mini-avatar">A</div>
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
                <h1>Canlı Kur Güncelle</h1>
            </div>

            <div class="top-actions">
                <div class="role-badge">admin</div>
                <div class="date-pill"><?= date('d.m.Y') ?></div>
            </div>
        </header>

        <section class="panel">
            <?php if ($message): ?>
                <div class="alert" style="background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.18);color:#9af0b7;">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endif; ?>

            <p style="color:var(--muted);margin-top:14px;">
                Bu işlem TCMB döviz satış kurunu çeker. USD/EUR alış fiyatlı ürünlerin <strong>Alış TL</strong> değerini,
                USD/EUR satış fiyatlı ürünlerin <strong>Satış TL</strong> değerini günceller.
            </p>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button type="button" onclick="window.location.href='live_currency_update.php'">
                    Tekrar Güncelle
                </button>

                <button type="button" onclick="window.location.href='index.php?page=settings'">
                    Ayarlara Dön
                </button>

                <button type="button" onclick="window.location.href='index.php?page=products'">
                    Ürünlere Git
                </button>
            </div>
        </section>
    </main>
</div>
</body>
</html>