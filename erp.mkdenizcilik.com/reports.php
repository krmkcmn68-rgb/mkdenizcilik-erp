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

$start = trim((string)($_GET['start'] ?? date('Y-m-01')));
$end = trim((string)($_GET['end'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $end = date('Y-m-d');
}

$salesStmt = db()->prepare("
    SELECT
        COUNT(*) AS sale_count,
        COALESCE(SUM(total_amount), 0) AS total_sales,
        COALESCE(SUM(paid_amount), 0) AS paid_total,
        COALESCE(SUM(remaining_amount), 0) AS remaining_total,
        COALESCE(SUM(discount_total), 0) AS discount_total
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$salesStmt->execute([$start, $end]);
$salesSummary = $salesStmt->fetch();

$paymentRowsStmt = db()->prepare("
    SELECT payment_type, COUNT(*) AS sale_count, COALESCE(SUM(total_amount), 0) AS total_amount
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY payment_type
    ORDER BY total_amount DESC
");
$paymentRowsStmt->execute([$start, $end]);
$paymentRows = $paymentRowsStmt->fetchAll();

$topProductsStmt = db()->prepare("
    SELECT
        si.product_name,
        COALESCE(SUM(si.quantity), 0) AS qty,
        COALESCE(SUM(si.line_total), 0) AS total_amount
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY si.product_name
    ORDER BY qty DESC, total_amount DESC
    LIMIT 25
");
$topProductsStmt->execute([$start, $end]);
$topProducts = $topProductsStmt->fetchAll();

$recentSalesStmt = db()->prepare("
    SELECT sale_no, customer_name, total_amount, paid_amount, remaining_amount, payment_type, created_at
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
    ORDER BY id DESC
    LIMIT 100
");
$recentSalesStmt->execute([$start, $end]);
$recentSales = $recentSalesStmt->fetchAll();

$debtorCustomers = db()->query("
    SELECT name, phone, balance
    FROM customers
    WHERE balance > 0
    ORDER BY balance DESC
    LIMIT 50
")->fetchAll();

$lowStocks = db()->query("
    SELECT name, stock_code, stock, unit, min_stock
    FROM products
    WHERE stock <= min_stock
    ORDER BY stock ASC
    LIMIT 50
")->fetchAll();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>MK Denizcilik ERP - Raporlar</title>

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
        <div class="brand-logo">
            <?php if (file_exists(__DIR__ . '/assets/mk-logo-pdf.png')): ?>
                <img src="assets/mk-logo-pdf.png" alt="MK">
            <?php elseif (file_exists(__DIR__ . '/assets/mk-logo.png')): ?>
                <img src="assets/mk-logo.png" alt="MK">
            <?php else: ?>
                <strong>MK</strong>
            <?php endif; ?>
        </div>
        <div><strong>Raporlar</strong><span>Satış, cari ve stok analizleri</span></div>
    </div>
    <div class="actions">
        <a class="btn" href="index.php?page=dashboard">← ERP’ye Dön</a>
        <button onclick="window.print()">Yazdır</button>
    </div>
</header>

<main class="wrap">
    <section class="hero">
        <p class="eyebrow">MK Command Reports</p>
        <h1>Rapor Merkezi</h1>
        <p>Seçili tarih aralığı için satış, ödeme, ürün, cari ve stok özetlerini buradan takip edebilirsin.</p>
    </section>

    <section class="panel no-print">
        <form method="get" class="form-grid">
            <div>
                <label>Başlangıç</label>
                <input type="date" name="start" value="<?= e($start) ?>">
            </div>
            <div>
                <label>Bitiş</label>
                <input type="date" name="end" value="<?= e($end) ?>">
            </div>
            <button class="primary" type="submit">Raporu Getir</button>
            <a class="btn" href="reports.php">Bu Ay</a>
        </form>
    </section>

    <section class="grid">
        <div class="card"><span>Satış Adedi</span><strong><?= (int)$salesSummary['sale_count'] ?></strong></div>
        <div class="card"><span>Toplam Satış</span><strong><?= money($salesSummary['total_sales']) ?></strong></div>
        <div class="card"><span>Ödenen</span><strong><?= money($salesSummary['paid_total']) ?></strong></div>
        <div class="card"><span>Kalan</span><strong><?= money($salesSummary['remaining_total']) ?></strong></div>
    </section>

    <section class="panel">
        <h2>Ödeme Tipi Özeti</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Ödeme Tipi</th><th>Satış Adedi</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($paymentRows as $row): ?>
                    <tr><td><?= e($row['payment_type'] ?? '-') ?></td><td><?= (int)$row['sale_count'] ?></td><td><?= money($row['total_amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$paymentRows): ?><tr><td colspan="3">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>En Çok Satan Ürünler</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Ürün</th><th>Miktar</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($topProducts as $p): ?>
                    <tr><td><?= e($p['product_name']) ?></td><td><?= number_format((float)$p['qty'], 2, ',', '.') ?></td><td><?= money($p['total_amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$topProducts): ?><tr><td colspan="3">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Son Satışlar</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>No</th><th>Müşteri</th><th>Toplam</th><th>Ödenen</th><th>Kalan</th><th>Ödeme</th><th>Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($recentSales as $s): ?>
                    <tr>
                        <td><?= e($s['sale_no']) ?></td>
                        <td><?= e($s['customer_name']) ?></td>
                        <td><?= money($s['total_amount']) ?></td>
                        <td><?= money($s['paid_amount']) ?></td>
                        <td><?= money($s['remaining_amount']) ?></td>
                        <td><?= e($s['payment_type']) ?></td>
                        <td><?= e($s['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentSales): ?><tr><td colspan="7">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid">
        <div class="panel" style="grid-column: span 2;">
            <h2>Borçlu Müşteriler</h2>
            <div class="table-wrap"><table>
                <thead><tr><th>Müşteri</th><th>Telefon</th><th>Bakiye</th></tr></thead>
                <tbody>
                <?php foreach ($debtorCustomers as $c): ?>
                    <tr><td><?= e($c['name']) ?></td><td><?= e($c['phone'] ?? '') ?></td><td><?= money($c['balance']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$debtorCustomers): ?><tr><td colspan="3">Borçlu müşteri yok.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="panel" style="grid-column: span 2;">
            <h2>Kritik Stoklar</h2>
            <div class="table-wrap"><table>
                <thead><tr><th>Ürün</th><th>Kod</th><th>Stok</th><th>Kritik</th></tr></thead>
                <tbody>
                <?php foreach ($lowStocks as $p): ?>
                    <tr><td><?= e($p['name']) ?></td><td><?= e($p['stock_code']) ?></td><td><?= number_format((float)$p['stock'], 2, ',', '.') ?> <?= e($p['unit']) ?></td><td><?= number_format((float)$p['min_stock'], 2, ',', '.') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$lowStocks): ?><tr><td colspan="4">Kritik stok yok.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </section>
</main>
</body>
</html>
