<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function money(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function trDate($value): string
{
    if (empty($value)) {
        return '-';
    }

    $time = strtotime((string)$value);

    if (!$time) {
        return '-';
    }

    return date('d.m.Y', $time);
}

function getSettingPdf(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

if (!currentUser()) {
    header('Location: index.php?page=login');
    exit;
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo 'Geçersiz teklif ID.';
    exit;
}

try {
    $stmt = db()->prepare("
        SELECT 
            q.*,
            u.name AS user_name
        FROM quotes q
        LEFT JOIN users u ON u.id = q.created_by
        WHERE q.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $quote = $stmt->fetch();

    if (!$quote) {
        http_response_code(404);
        echo 'Teklif bulunamadı.';
        exit;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM quote_items
        WHERE quote_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    $customerInfo = null;
    if (!empty($quote['customer_id'])) {
        $customerStmt = db()->prepare("SELECT phone, address, tax_office, tax_number FROM customers WHERE id = ? LIMIT 1");
        $customerStmt->execute([(int)$quote['customer_id']]);
        $customerInfo = $customerStmt->fetch() ?: null;
    }

} catch (Throwable $ex) {
    http_response_code(500);
    echo 'PDF verisi hazırlanırken hata oluştu: ' . e($ex->getMessage());
    exit;
}

$companyName = getSettingPdf('company_name', 'MK Denizcilik');
$companySub = 'Denizcilik üzerine her konuda yanınızdayız.';
$preparedBy = !empty($quote['prepared_by']) ? (string)$quote['prepared_by'] : 'Serhat İnan';

$bankLine1 = getSettingPdf('bank_line_1', 'TEB BANKASI: TR36 0003 2000 0000 0018 0425 34');
$bankLine2 = getSettingPdf('bank_line_2', 'VAKIFBANK: TR79 0001 5001 5800 7322 6017 55');
$bankLine3 = getSettingPdf('bank_line_3', 'GARANTİ BANKASI: TR78 0006 2000 2050 0006 2868 99');
$bankNote = getSettingPdf('bank_note', 'Ödeme sonrası dekont iletiniz.');

$createdAtText = trDate($quote['created_at'] ?? null);
$validUntilText = trDate($quote['valid_until'] ?? null);

$subtotal = (float)($quote['subtotal'] ?? 0);
$vatTotal = (float)($quote['vat_total'] ?? 0);
$grandTotal = (float)($quote['grand_total'] ?? 0);
$discountTotal = (float)($quote['discount_total'] ?? 0);
$globalDiscountRate = (float)($quote['discount_rate'] ?? 0);
$globalDiscountAmount = (float)($quote['discount_amount'] ?? 0);

$lineDiscountTotal = 0.0;
$grossSubtotal = 0.0;

foreach ($items as $calcItem) {
    $qty = (float)($calcItem['quantity'] ?? 0);
    $unitPrice = (float)($calcItem['unit_price'] ?? 0);
    $lineDiscount = (float)($calcItem['line_discount'] ?? 0);

    $grossSubtotal += $qty * $unitPrice;
    $lineDiscountTotal += $lineDiscount;
}

$globalDiscount = max($discountTotal - $lineDiscountTotal, 0);
$globalPercentDiscount = max($globalDiscount - $globalDiscountAmount, 0);

if ($grossSubtotal <= 0 && $subtotal > 0) {
    $grossSubtotal = $subtotal + $lineDiscountTotal;
}


$logoPath = 'assets/mk-logo-pdf.png';
$logoFullPath = __DIR__ . '/' . $logoPath;

if (!file_exists($logoFullPath)) {
    $logoPath = 'assets/mk-logo.png';
    $logoFullPath = __DIR__ . '/' . $logoPath;
}

$brandLogosTop = [
    ['name' => 'Moravia', 'file' => 'moravia.png'],
    ['name' => 'International', 'file' => 'international.png'],
    ['name' => '3M', 'file' => '3m.png'],
    ['name' => 'Karbosan', 'file' => 'karbosan.png'],
    ['name' => 'Teknomarin', 'file' => 'teknomarin.png'],
    ['name' => 'Sika', 'file' => 'sika.png'],
];

$brandLogosBottom = [
    ['name' => 'Webesten', 'file' => 'webesten.png'],
    ['name' => 'Matromarine Products', 'file' => 'matromarine.png'],
    ['name' => 'Seaflo', 'file' => 'seaflo.png'],
    ['name' => 'Sealux Marine', 'file' => 'sealux.png'],
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?= e($quote['quote_no'] ?? 'Teklif') ?> - Teklif</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #2b2b2b;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
        }

        .screen-wrap {
            max-width: 900px;
            margin: 18px auto;
            padding: 0 12px;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .toolbar a,
        .toolbar button {
            border: 0;
            border-radius: 6px;
            padding: 9px 13px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .toolbar a {
            background: #111827;
            color: #fff;
        }

        .toolbar button {
            background: #facc15;
            color: #111827;
        }

        .paper {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 9mm 8mm;
            position: relative;
        }

        .top-row {
            display: grid;
            grid-template-columns: 1fr 210px;
            gap: 16px;
            align-items: start;
            min-height: 86px;
        }

        .company-line {
            display: none;
        }

        .company-logo-area {
            padding-left: 16px;
            min-height: 78px;
            display: flex;
            align-items: center;
        }

        .company-logo {
            width: 178px;
            max-height: 72px;
            object-fit: contain;
            display: block;
        }

        .company-logo-fallback {
            width: 178px;
            min-height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 900;
            color: #111;
            border: 1px solid #eee;
        }

        .doc-meta {
            padding-top: 12px;
            font-size: 11px;
        }

        .doc-meta .title {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 10px;
            text-align: left;
            letter-spacing: 0;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td {
            padding: 2px 0;
            vertical-align: top;
        }

        .meta-table td:first-child {
            width: 78px;
        }

        .meta-table td:nth-child(2) {
            width: 8px;
            text-align: center;
        }

        .customer-area {
            margin-top: 14px;
            width: 470px;
        }

        .customer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .customer-table td {
            padding: 4px 0;
            vertical-align: top;
        }

        .customer-table td:first-child {
            width: 82px;
        }

        .customer-table td:nth-child(2) {
            width: 10px;
            text-align: center;
        }

        .customer-name {
            color: #0057d9;
            font-weight: 800;
            text-transform: uppercase;
        }

        .items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 22px;
            table-layout: fixed;
        }

        .items thead th {
            color: #b00000;
            font-size: 10px;
            font-weight: 800;
            padding: 0 4px 4px;
            border-bottom: 1px solid #d35959;
            text-align: left;
        }

        .items tbody td {
            font-size: 10px;
            padding: 3px 4px;
            vertical-align: middle;
            line-height: 1.15;
        }

        .items tbody tr:nth-child(even) {
            background: #dcdcff;
        }

        .items .code {
            width: 76px;
        }

        .items .name {
            width: auto;
        }

        .items .qty {
            width: 54px;
            text-align: right;
        }

        .items .unit {
            width: 38px;
            text-align: center;
        }

        .items .price {
            width: 58px;
            text-align: right;
        }

        .items .discount-rate {
            width: 42px;
            text-align: center;
        }

        .items .discount-amount {
            width: 52px;
            text-align: right;
        }

        .items .vat {
            width: 38px;
            text-align: center;
        }

        .items .total {
            width: 72px;
            text-align: right;
        }
        .bottom-grid { display: grid; grid-template-columns: 1fr 235px; gap: 24px; margin-top: 22px; align-items: start; }
        .totals-section { grid-column: 2; }
        .notes-bank-area { position: absolute; left: 26mm; right: 26mm; bottom: 78mm; font-size: 10px; color: #111; }
        .special-notes { min-height: 24mm; margin-bottom: 12mm; }
        .pdf-script-title { font-family: Georgia, 'Times New Roman', serif; font-size: 22px; font-style: italic; font-weight: 500; margin: 0 0 7px; color: #111; }
        .special-note-text { max-width: 145mm; min-height: 16mm; line-height: 1.45; white-space: pre-wrap; color: #222; }
        .bank-details-title { font-family: Georgia, 'Times New Roman', serif; font-size: 18px; font-style: italic; font-weight: 500; margin: 0 0 7px; color: #111; }
        .bank-details-lines { line-height: 1.45; color: #111; }
        .bank-details-lines div { margin-bottom: 2px; }


        .totals {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            font-weight: 800;
        }

        .totals td {
            padding: 4px 0;
        }

        .totals td:first-child {
            width: 128px;
            text-align: left;
        }

        .totals td:nth-child(2) {
            width: 10px;
            text-align: center;
        }

        .totals td:last-child {
            text-align: right;
            width: 80px;
        }
        .brand-strip-wrap {
            position: absolute;
            left: 9mm;
            right: 9mm;
            bottom: 39mm;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .brand-strip,
        .brand-strip-bottom {
            display: grid;
            align-items: center;
            justify-items: center;
            gap: 12px;
        }

        .brand-strip {
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .brand-strip-bottom {
            width: 84%;
            margin: 0 auto;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .brand-logo-item {
            width: 100%;
            height: 62px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .brand-logo-item img {
            width: 100%;
            height: 100%;
            max-width: 132px;
            max-height: 58px;
            object-fit: contain;
            display: block;
        }

        .brand-logo-item strong {
            font-size: 12px;
            font-weight: 900;
            color: #0b3b88;
            text-align: center;
            white-space: nowrap;
        }

        .grand-total-row td {
            padding-top: 7px;
            font-size: 11px;
            color: #8b0000;
        }

        .footer-note {
            position: absolute;
            left: 8mm;
            right: 8mm;
            bottom: 11mm;
            font-size: 9px;
            color: #333;
            text-align: center;
        }

        @media print {
            html,
            body {
                background: #fff;
            }

            .screen-wrap {
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .toolbar {
                display: none;
            }

            .paper {
                width: 210mm;
                min-height: 297mm;
                margin: 0;
                box-shadow: none;
                border: 0;
            }
        }

        @page {
            size: A4;
            margin: 0;
        }
    </style>
</head>

<body>
<div class="screen-wrap">
    <div class="toolbar">
        <a href="index.php?page=quotes">← Tekliflere Dön</a>
        <button type="button" onclick="window.print()">PDF / Yazdır</button>
    </div>

    <main class="paper">
        <section class="top-row">
            <div>
                <div class="company-logo-area">
                    <?php if (file_exists($logoFullPath)): ?>
                        <img 
                            src="<?= e($logoPath) ?>?v=<?= filemtime($logoFullPath) ?>" 
                            alt="MK Denizcilik" 
                            class="company-logo"
                        >
                    <?php else: ?>
                        <div class="company-logo-fallback">MK DENİZCİLİK</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="doc-meta">
                <div class="title">TEKLİF</div>

                <table class="meta-table">
                    <tr>
                        <td>Teklif Tarihi</td>
                        <td>:</td>
                        <td><?= e($createdAtText) ?></td>
                    </tr>
                    <tr>
                        <td>Belge No</td>
                        <td>:</td>
                        <td><?= e($quote['quote_no'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td>Geçerlilik</td>
                        <td>:</td>
                        <td><?= e($validUntilText) ?></td>
                    </tr>
                </table>
            </div>
        </section>

        <section class="customer-area">
            <table class="customer-table">
                <tr>
                    <td>Sayın</td>
                    <td>:</td>
                    <td class="customer-name"><?= e($quote['customer_name'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td>Telefon</td>
                    <td>:</td>
                    <td><?= e($customerInfo['phone'] ?? '') ?></td>
                </tr>
                <tr>
                    <td>Adres</td>
                    <td>:</td>
                    <td><?= e($customerInfo['address'] ?? '') ?></td>
                </tr>
            </table>
        </section>

        <table class="items">
            <thead>
                <tr>
                    <th class="code">Stok Kodu</th>
                    <th class="name">Stok Adı</th>
                    <th class="qty">Miktar</th>
                    <th class="unit">Br.</th>
                    <th class="price">B.Fiyatı</th>
                    <th class="discount-rate">İsk.%</th>
                    <th class="discount-amount">İsk.₺</th>
                    <th class="vat">KDV</th>
                    <th class="total">Tutar</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $productId = !empty($item['product_id']) ? (int)$item['product_id'] : 0;
                    $stockCode = $productId > 0 ? 'UR ' . str_pad((string)$productId, 5, '0', STR_PAD_LEFT) : '-';
                    ?>
                    <tr>
                        <td class="code"><?= e($stockCode) ?></td>
                        <td class="name"><?= e($item['product_name'] ?? '-') ?></td>
                        <td class="qty"><?= money((float)($item['quantity'] ?? 0)) ?></td>
                        <td class="unit"><?= e($item['unit'] ?? 'AD') ?></td>
                        <td class="price"><?= money((float)($item['unit_price'] ?? 0)) ?></td>
                        <td class="discount-rate">%<?= money((float)($item['discount_rate'] ?? 0)) ?></td>
                        <td class="discount-amount"><?= money((float)($item['discount_amount'] ?? 0)) ?></td>
                        <td class="vat">%<?= money((float)($item['vat_rate'] ?? 0)) ?></td>
                        <td class="total"><?= money((float)($item['line_total'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$items): ?>
                    <tr>
                        <td colspan="9">Teklif kalemi bulunamadı.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <section class="bottom-grid">
            <div></div>

            <div class="totals-section">
                <table class="totals">
                    <tr>
                        <td>Ara Toplam</td>
                        <td>:</td>
                        <td><?= money($grossSubtotal) ?></td>
                    </tr>
                    <tr>
                        <td>İskonto</td>
                        <td>:</td>
                        <td><?= money($discountTotal) ?></td>
                    </tr>
                    <tr>
                        <td>KDV</td>
                        <td>:</td>
                        <td><?= money($vatTotal) ?></td>
                    </tr>
                    <tr class="grand-total-row">
                        <td>Genel Toplam</td>
                        <td>:</td>
                        <td><?= money($grandTotal) ?></td>
                    </tr>
                </table>
            </div>
        </section>

        <section class="notes-bank-area">
            <div class="special-notes">
                <h3 class="pdf-script-title">Özel notlar</h3>
                <div class="special-note-text"><?= e(trim((string)($quote['note'] ?? '')) !== '' ? (string)$quote['note'] : 'Özel not bulunmamaktadır.') ?></div>
            </div>

            <div class="bank-details">
                <h3 class="bank-details-title">Banka hesap bilgileri</h3>
                <div class="bank-details-lines">
                    <div><?= e($bankLine1) ?></div>
                    <div><?= e($bankLine2) ?></div>
                    <div><?= e($bankLine3) ?></div>
                </div>
            </div>
        </section>
        <section class="brand-strip-wrap">
            <div class="brand-strip">
                <?php foreach ($brandLogosTop as $brand): ?>
                    <?php
                        $brandPath = 'assets/brands/' . $brand['file'];
                        $brandFullPath = __DIR__ . '/' . $brandPath;
                    ?>

                    <div class="brand-logo-item">
                        <?php if (file_exists($brandFullPath)): ?>
                            <img
                                src="<?= e($brandPath) ?>?v=<?= filemtime($brandFullPath) ?>"
                                alt="<?= e($brand['name']) ?>"
                            >
                        <?php else: ?>
                            <strong><?= e($brand['name']) ?></strong>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="brand-strip-bottom">
                <?php foreach ($brandLogosBottom as $brand): ?>
                    <?php
                        $brandPath = 'assets/brands/' . $brand['file'];
                        $brandFullPath = __DIR__ . '/' . $brandPath;
                    ?>

                    <div class="brand-logo-item">
                        <?php if (file_exists($brandFullPath)): ?>
                            <img
                                src="<?= e($brandPath) ?>?v=<?= filemtime($brandFullPath) ?>"
                                alt="<?= e($brand['name']) ?>"
                            >
                        <?php else: ?>
                            <strong><?= e($brand['name']) ?></strong>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="footer-note">
            Hazırlayan: <?= e($preparedBy) ?> &nbsp; | &nbsp; <?= e($companySub) ?>
        </div>
    </main>
</div>
</body>
</html>