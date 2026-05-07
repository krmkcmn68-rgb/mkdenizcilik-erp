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

function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function currentUser(): ?array { return $_SESSION['user'] ?? null; }
function isAdmin(): bool { $u = currentUser(); return $u && (($u['role'] ?? '') === 'admin'); }
function requireAdmin(): void { if (!isAdmin()) { header('Location:index.php?page=login'); exit; } }
function money($n): string { return number_format((float)$n, 2, ',', '.') . ' ₺'; }

function barcodeValue(array $p): string
{
    $v = trim((string)($p['barcode'] ?? ''));
    if ($v === '') $v = trim((string)($p['stock_code'] ?? ''));
    if ($v === '') $v = 'MKP' . (int)$p['id'];
    return preg_replace('/[^A-Za-z0-9\-\.\/ ]+/', '', $v) ?: ('MKP' . (int)$p['id']);
}

requireAdmin();

$action = (string)($_POST['action'] ?? '');

if ($action === 'print') {
    $ids = $_POST['product_id'] ?? [];
    $counts = $_POST['count'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
    $products = [];

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($placeholders) ORDER BY name ASC");
        $stmt->execute($ids);

        foreach ($stmt->fetchAll() as $p) {
            $c = (int)($counts[(string)$p['id']] ?? 1);
            $c = max(1, min($c, 100));
            for ($i = 0; $i < $c; $i++) $products[] = $p;
        }
    }
    ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Barkod Etiket Yazdır</title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;background:#f3f4f6;color:#111}.toolbar{position:sticky;top:0;background:#111827;color:#fff;padding:12px;display:flex;gap:8px;align-items:center;z-index:10}.toolbar button{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}.sheet{padding:10mm;display:grid;grid-template-columns:repeat(3,64mm);gap:4mm;align-items:start}.label{width:64mm;height:34mm;background:#fff;border:1px dashed #d1d5db;border-radius:4px;padding:3mm;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden;break-inside:avoid}.brand{font-size:9px;font-weight:800}.name{font-size:10px;font-weight:800;line-height:1.1;min-height:22px;max-height:24px;overflow:hidden}.meta{display:flex;justify-content:space-between;font-size:8px;gap:5px}.price{font-size:11px;font-weight:900;white-space:nowrap}.barcode svg{width:100%;height:38px}@media print{body{background:#fff}.toolbar{display:none}.sheet{padding:0;gap:2mm;grid-template-columns:repeat(3,64mm)}.label{border:0;border-radius:0;page-break-inside:avoid}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨️ Yazdır</button><button onclick="history.back()">← Geri Dön</button><strong><?= count($products) ?> etiket</strong></div>
<main class="sheet">
<?php foreach ($products as $idx => $p): $code = barcodeValue($p); ?>
<div class="label">
    <div class="brand">MK Denizcilik</div>
    <div class="name"><?= e($p['name'] ?? '') ?></div>
    <div class="meta"><span><?= e($p['stock_code'] ?? '') ?></span><span class="price"><?= money($p['sale_price'] ?? 0) ?></span></div>
    <div class="barcode"><svg id="bc<?= (int)$idx ?>" data-code="<?= e($code) ?>"></svg></div>
</div>
<?php endforeach; ?>
<?php if (!$products): ?><p>Etiket seçilmedi.</p><?php endif; ?>
</main>
<script>
document.querySelectorAll('svg[data-code]').forEach(function(el){try{JsBarcode(el,el.dataset.code,{format:'CODE128',displayValue:true,fontSize:10,height:34,margin:0});}catch(e){el.outerHTML='<div style="font-size:10px">Barkod üretilemedi</div>';}})
</script>
</body>
</html>
<?php exit; }

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 500);
if (!in_array($limit, [120,250,500,1000,0], true)) $limit = 500;
$limitSql = $limit > 0 ? ' LIMIT ' . $limit : '';

$totalProducts = (int)db()->query("SELECT COUNT(*) FROM products")->fetchColumn();

if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = db()->prepare("SELECT * FROM products WHERE name LIKE ? OR stock_code LIKE ? OR barcode LIKE ? OR category LIKE ? ORDER BY name ASC" . $limitSql);
    $stmt->execute([$like,$like,$like,$like]);
    $products = $stmt->fetchAll();

    $countStmt = db()->prepare("SELECT COUNT(*) FROM products WHERE name LIKE ? OR stock_code LIKE ? OR barcode LIKE ? OR category LIKE ?");
    $countStmt->execute([$like,$like,$like,$like]);
    $matchingProducts = (int)$countStmt->fetchColumn();
} else {
    $products = db()->query("SELECT * FROM products ORDER BY id DESC" . $limitSql)->fetchAll();
    $matchingProducts = $totalProducts;
}
$shownProducts = count($products);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>MK Denizcilik ERP - Barkod / Etiket</title>

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

<style>.count-input{width:76px}.barcode-preview{font-size:12px;color:var(--muted);font-weight:800}.limit-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}</style>
</head>
<body>
<header class="topbar">
    <div class="brand">
        <div class="brand-logo"><?php if (file_exists(__DIR__ . '/assets/mk-logo-pdf.png')): ?><img src="assets/mk-logo-pdf.png"><?php else: ?><strong>MK</strong><?php endif; ?></div>
        <div><strong>Barkod / Etiket</strong><span>Ürün etiketi yazdır</span></div>
    </div>
    <div class="actions"><a class="btn" href="index.php?page=dashboard">← ERP’ye Dön</a></div>
</header>

<main class="wrap">
    <section class="hero"><p class="eyebrow">Label Center</p><h1>Barkod / Etiket</h1><p>Ürün seç, etiket adedini gir, yazdır.</p></section>

    <section class="grid">
        <div class="card"><span>Toplam Ürün</span><strong><?= (int)$totalProducts ?></strong></div>
        <div class="card"><span>Eşleşen Ürün</span><strong><?= (int)$matchingProducts ?></strong></div>
        <div class="card"><span>Gösterilen Ürün</span><strong><?= (int)$shownProducts ?></strong></div>
        <div class="card"><span>Limit</span><strong><?= $limit === 0 ? 'Tümü' : (int)$limit ?></strong></div>
    </section>

    <section class="panel">
        <form method="get" class="form-grid">
            <div style="grid-column:span 2;"><label>Ürün adı / stok kodu / barkod / kategori</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Ürün ara..."></div>
            <div><label>Limit</label><select name="limit"><option value="120" <?= $limit===120?'selected':'' ?>>120</option><option value="250" <?= $limit===250?'selected':'' ?>>250</option><option value="500" <?= $limit===500?'selected':'' ?>>500</option><option value="1000" <?= $limit===1000?'selected':'' ?>>1000</option><option value="0" <?= $limit===0?'selected':'' ?>>Tümü</option></select></div>
            <button class="primary" type="submit">Ara / Uygula</button>
        </form>
        <div class="limit-row">
            <a class="btn" href="barcode.php?limit=120<?= $q!==''?'&q='.urlencode($q):'' ?>">120</a>
            <a class="btn" href="barcode.php?limit=250<?= $q!==''?'&q='.urlencode($q):'' ?>">250</a>
            <a class="btn" href="barcode.php?limit=500<?= $q!==''?'&q='.urlencode($q):'' ?>">500</a>
            <a class="btn" href="barcode.php?limit=1000<?= $q!==''?'&q='.urlencode($q):'' ?>">1000</a>
            <a class="btn primary" href="barcode.php?limit=0<?= $q!==''?'&q='.urlencode($q):'' ?>">Tümü</a>
        </div>
    </section>

    <form method="post">
        <input type="hidden" name="action" value="print">
        <section class="panel">
            <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
                <h2>Ürün Etiketleri</h2>
                <div>
                    <button type="button" onclick="document.querySelectorAll('.product-check').forEach(c=>c.checked=true)">Tümünü Seç</button>
                    <button type="button" onclick="document.querySelectorAll('.product-check').forEach(c=>c.checked=false)">Seçimi Kaldır</button>
                    <button class="primary" type="submit">Seçilenleri Yazdır</button>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Seç</th><th>Adet</th><th>Ürün</th><th>Stok Kodu</th><th>Barkod</th><th>Kategori</th><th>Fiyat</th><th>Stok</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $p): $code = barcodeValue($p); ?>
                        <tr>
                            <td><input class="product-check" type="checkbox" name="product_id[]" value="<?= (int)$p['id'] ?>"></td>
                            <td><input class="count-input" type="number" min="1" max="100" name="count[<?= (int)$p['id'] ?>]" value="1"></td>
                            <td><?= e($p['name'] ?? '') ?><div class="barcode-preview">Kod: <?= e($code) ?></div></td>
                            <td><?= e($p['stock_code'] ?? '') ?></td><td><?= e($p['barcode'] ?? '') ?></td><td><?= e($p['category'] ?? '') ?></td><td><?= money($p['sale_price'] ?? 0) ?></td><td><?= e((string)($p['stock'] ?? '0')) ?> <?= e($p['unit'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$products): ?><tr><td colspan="8">Ürün bulunamadı.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </form>
</main>
</body>
</html>
