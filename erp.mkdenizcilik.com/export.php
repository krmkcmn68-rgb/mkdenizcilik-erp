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
    return $user && (($user['role'] ?? '') === 'admin');
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function money($value): string
{
    return number_format((float)$value, 2, ',', '.') . ' ₺';
}

requireAdmin();

$type = trim((string)($_GET['type'] ?? ''));

function excelOut(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-16LE');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xFF\xFE";

    $writeLine = function (array $cols): void {
        $line = implode("\t", array_map(function ($v) {
            $v = (string)$v;
            $v = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $v);
            return $v;
        }, $cols)) . "\r\n";

        echo mb_convert_encoding($line, 'UTF-16LE', 'UTF-8');
    };

    $writeLine($headers);

    foreach ($rows as $row) {
        $writeLine($row);
    }

    exit;
}

if ($type !== '') {
    if ($type === 'products') {
        $rows = db()->query("SELECT id, name, stock_code, barcode, category, purchase_price, purchase_currency, purchase_price_try, sale_price, sale_currency, sale_price_original, vat_rate, stock, unit, min_stock, created_at FROM products ORDER BY id DESC")->fetchAll();
        excelOut('urunler.xls', ['ID','Ürün Adı','Stok Kodu','Barkod','Kategori','Alış','Alış Para','Alış TL','Satış TL','Satış Para','Satış Orijinal','KDV','Stok','Birim','Kritik Stok','Tarih'], $rows);
    }

    if ($type === 'customers') {
        $rows = db()->query("SELECT id, name, phone, email, address, tax_office, tax_number, balance, created_at FROM customers ORDER BY id DESC")->fetchAll();
        excelOut('musteriler.xls', ['ID','Müşteri','Telefon','E-posta','Adres','Vergi Dairesi','Vergi No','Bakiye','Tarih'], $rows);
    }

    if ($type === 'sales') {
        $rows = db()->query("SELECT id, sale_no, customer_name, total_amount, discount_total, paid_amount, remaining_amount, payment_type, note, created_at FROM sales ORDER BY id DESC")->fetchAll();
        excelOut('satislar.xls', ['ID','Satış No','Müşteri','Toplam','İskonto','Ödenen','Kalan','Ödeme Tipi','Not','Tarih'], $rows);
    }

    if ($type === 'sale_items') {
        $rows = db()->query("
            SELECT si.id, s.sale_no, s.customer_name, si.product_name, si.quantity, si.unit_price, si.discount_rate, si.discount_amount, si.line_discount, si.vat_rate, si.line_total, s.created_at
            FROM sale_items si
            LEFT JOIN sales s ON s.id = si.sale_id
            ORDER BY si.id DESC
        ")->fetchAll();
        excelOut('satis_kalemleri.xls', ['ID','Satış No','Müşteri','Ürün','Miktar','Birim Fiyat','İsk.%','İsk.₺','Satır İsk.','KDV','Satır Toplam','Tarih'], $rows);
    }

    if ($type === 'customer_transactions') {
        $rows = db()->query("
            SELECT ct.id, c.name, ct.transaction_type, ct.amount, ct.balance_after, ct.payment_type, ct.description, ct.created_at
            FROM customer_transactions ct
            LEFT JOIN customers c ON c.id = ct.customer_id
            ORDER BY ct.id DESC
        ")->fetchAll();
        excelOut('cari_hareketler.xls', ['ID','Müşteri','İşlem','Tutar','İşlem Sonrası Bakiye','Ödeme Tipi','Açıklama','Tarih'], $rows);
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>MK Denizcilik ERP - Excel Export</title>

<style>
:root {
    --bg: #07111f;
    --panel: rgba(15, 23, 42, .78);
    --panel2: rgba(15, 23, 42, .58);
    --border: rgba(148, 163, 184, .20);
    --text: #e5eefb;
    --muted: #8ea3bd;
    --accent: #38bdf8;
    --gold: #facc15;
    --danger: #fb7185;
    --green: #34d399;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    color: var(--text);
    background:
        radial-gradient(circle at 20% 0%, rgba(56, 189, 248, .18), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(250, 204, 21, .10), transparent 30%),
        linear-gradient(135deg, #050b14, #07111f 55%, #020617);
    min-height: 100vh;
}
body:before {
    content: "";
    position: fixed;
    inset: 0;
    background-image:
        linear-gradient(rgba(148,163,184,.055) 1px, transparent 1px),
        linear-gradient(90deg, rgba(148,163,184,.055) 1px, transparent 1px);
    background-size: 38px 38px;
    pointer-events: none;
    mask-image: linear-gradient(to bottom, rgba(0,0,0,.85), transparent 90%);
}
.topbar {
    position: sticky;
    top: 0;
    z-index: 30;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 14px 22px;
    border-bottom: 1px solid var(--border);
    background: rgba(2, 6, 23, .80);
    backdrop-filter: blur(18px);
}
.brand { display:flex; align-items:center; gap:12px; }
.brand-logo {
    width: 52px; height: 44px; border-radius: 16px; background:#fff;
    display:flex; align-items:center; justify-content:center; padding:5px;
    box-shadow: 0 0 28px rgba(56,189,248,.22);
}
.brand-logo img { max-width:100%; max-height:100%; object-fit:contain; display:block; }
.brand strong { display:block; font-size:16px; letter-spacing:-.03em; }
.brand span { display:block; color:var(--muted); font-size:12px; font-weight:700; }
.actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.btn, button, a.btn {
    border: 1px solid rgba(56,189,248,.22);
    background: rgba(15, 23, 42, .72);
    color: var(--text);
    border-radius: 14px;
    padding: 10px 13px;
    font-weight: 850;
    text-decoration: none;
    cursor: pointer;
    box-shadow: 0 12px 30px rgba(0,0,0,.18);
}
.btn.primary, button.primary {
    background: linear-gradient(135deg, #38bdf8, #facc15);
    color:#06111e;
    border-color: transparent;
}
.btn.danger, button.danger { color:#fecdd3; border-color:rgba(251,113,133,.35); background:rgba(127,29,29,.20); }
.wrap { max-width: 1320px; margin: 0 auto; padding: 26px; position: relative; z-index: 2; }
.hero {
    border: 1px solid var(--border);
    border-radius: 30px;
    padding: 24px;
    background:
        linear-gradient(135deg, rgba(56,189,248,.14), transparent 34%),
        linear-gradient(315deg, rgba(250,204,21,.10), transparent 34%),
        rgba(15, 23, 42, .55);
    box-shadow: 0 28px 80px rgba(0,0,0,.28);
    margin-bottom: 18px;
}
.eyebrow { color: var(--accent); text-transform: uppercase; letter-spacing:.12em; font-size:12px; font-weight:900; margin:0 0 6px; }
h1 { margin:0; font-size:34px; letter-spacing:-.06em; }
h2 { margin:0; font-size:22px; letter-spacing:-.04em; }
p { color: var(--muted); }
.grid { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 14px; }
.card, .panel {
    border: 1px solid var(--border);
    border-radius: 24px;
    background: var(--panel);
    box-shadow: 0 18px 50px rgba(0,0,0,.24);
}
.card { padding: 18px; min-height: 116px; }
.card span { display:block; color:var(--muted); font-size:12px; font-weight:850; margin-bottom:10px; }
.card strong { display:block; font-size:24px; letter-spacing:-.04em; }
.panel { padding: 18px; margin-top: 16px; overflow:auto; }
.table-wrap { overflow:auto; border-radius:18px; border:1px solid rgba(148,163,184,.14); }
table { width:100%; border-collapse:collapse; min-width:820px; }
th, td { padding:12px 13px; border-bottom:1px solid rgba(148,163,184,.10); text-align:left; font-size:13px; }
th { color:#b8c7db; font-size:12px; text-transform:uppercase; letter-spacing:.05em; background:rgba(2,6,23,.32); }
td { color:var(--text); }
tr:hover td { background:rgba(56,189,248,.06); }
.form-grid { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:12px; align-items:end; }
label { display:block; color:var(--muted); font-size:12px; font-weight:850; margin-bottom:7px; }
input, select {
    width:100%; border:1px solid rgba(148,163,184,.22); border-radius:14px;
    background:rgba(2,6,23,.40); color:var(--text); padding:11px 12px; outline:none;
}
.alert { border:1px solid rgba(56,189,248,.22); background:rgba(56,189,248,.09); color:#dbeafe; padding:12px 14px; border-radius:16px; margin:12px 0; }
.alert.error { border-color:rgba(251,113,133,.28); background:rgba(251,113,133,.10); color:#ffe4e6; }
@media(max-width:1050px){ .grid{grid-template-columns:repeat(2,1fr)} .form-grid{grid-template-columns:1fr 1fr} }
@media(max-width:650px){ .grid,.form-grid{grid-template-columns:1fr} .topbar{align-items:flex-start; flex-direction:column} h1{font-size:27px} .wrap{padding:16px} }
@media print { .topbar, .no-print { display:none !important; } body { background:#fff; color:#111; } .wrap{max-width:none;padding:0} .panel,.card,.hero{box-shadow:none;background:#fff;color:#111;border-color:#ddd} td,th{color:#111} }
</style>

</head>
<body>
<header class="topbar">
    <div class="brand">
        <div class="brand-logo"><?php if (file_exists(__DIR__ . '/assets/mk-logo-pdf.png')): ?><img src="assets/mk-logo-pdf.png"><?php else: ?><strong>MK</strong><?php endif; ?></div>
        <div><strong>Excel Export</strong><span>Türkçe karakter uyumlu dışa aktarım</span></div>
    </div>
    <div class="actions"><a class="btn" href="index.php?page=dashboard">← ERP’ye Dön</a></div>
</header>
<main class="wrap">
    <section class="hero">
        <p class="eyebrow">Export Center</p>
        <h1>Excel Dışa Aktarım</h1>
        <p>Excel dosyaları Türkçe karakter bozulmasın diye UTF-16LE formatında verilir.</p>
    </section>

    <section class="grid">
        <a class="card" href="export.php?type=products"><span>Ürünler</span><strong>Excel İndir</strong><p>Ürün, stok, fiyat, barkod.</p></a>
        <a class="card" href="export.php?type=customers"><span>Müşteriler</span><strong>Excel İndir</strong><p>Cari müşteri listesi.</p></a>
        <a class="card" href="export.php?type=sales"><span>Satışlar</span><strong>Excel İndir</strong><p>Satış başlık kayıtları.</p></a>
        <a class="card" href="export.php?type=sale_items"><span>Satış Kalemleri</span><strong>Excel İndir</strong><p>Ürün bazlı satışlar.</p></a>
        <a class="card" href="export.php?type=customer_transactions"><span>Cari Hareketler</span><strong>Excel İndir</strong><p>Tahsilat ve borç hareketleri.</p></a>
    </section>
</main>
</body>
</html>
