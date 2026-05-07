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
    return number_format($value, 2, ',', '.') . ' ₺';
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
    echo 'Geçersiz müşteri ID.';
    exit;
}

try {
    $stmt = db()->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        http_response_code(404);
        echo 'Müşteri bulunamadı.';
        exit;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM customer_transactions
        WHERE customer_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$id]);
    $transactions = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE customer_id = ?");
    $stmt->execute([$id]);
    $totalSales = (float)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE customer_id = ?");
    $stmt->execute([$id]);
    $totalPaid = (float)$stmt->fetchColumn();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Cari ekstre hazırlanırken hata oluştu: ' . e($e->getMessage());
    exit;
}

$companyName = getSettingPdf('company_name', 'MK Denizcilik');
$logoPath = 'assets/mk-logo-pdf.png';
$logoFullPath = __DIR__ . '/' . $logoPath;
if (!file_exists($logoFullPath)) {
    $logoPath = 'assets/mk-logo.png';
    $logoFullPath = __DIR__ . '/' . $logoPath;
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?= e($customer['name']) ?> - Cari Ekstre</title>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; background:#e5e7eb; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:12px; }
        .wrap { max-width: 980px; margin: 18px auto; padding: 0 12px; }
        .toolbar { display:flex; justify-content:space-between; margin-bottom:12px; }
        .toolbar a,.toolbar button { border:0; border-radius:7px; padding:9px 13px; font-weight:800; text-decoration:none; cursor:pointer; }
        .toolbar a { background:#111827; color:#fff; }
        .toolbar button { background:#facc15; color:#111827; }
        .paper { background:#fff; min-height:297mm; padding:18mm 14mm; box-shadow:0 18px 50px rgba(15,23,42,.18); }
        .top { display:grid; grid-template-columns: 1fr 260px; gap:20px; border-bottom:2px solid #111827; padding-bottom:14px; margin-bottom:14px; }
        .logo { max-width:210px; max-height:90px; object-fit:contain; display:block; }
        h1 { margin:0; font-size:22px; letter-spacing:-.04em; }
        .muted { color:#6b7280; }
        .meta { text-align:right; line-height:1.7; }
        .cards { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin:16px 0; }
        .card { border:1px solid #d1d5db; border-radius:10px; padding:10px; }
        .card span { display:block; color:#6b7280; font-size:11px; font-weight:700; margin-bottom:6px; }
        .card strong { font-size:15px; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th { background:#111827; color:#fff; text-align:left; padding:8px; font-size:11px; }
        td { border-bottom:1px solid #e5e7eb; padding:8px; vertical-align:top; }
        tr:nth-child(even) td { background:#f9fafb; }
        .right { text-align:right; }
        .footer { margin-top:18px; padding-top:10px; border-top:1px solid #d1d5db; color:#6b7280; font-size:11px; text-align:center; }
        @media print { body { background:#fff; } .wrap { margin:0; padding:0; max-width:none; } .toolbar { display:none; } .paper { box-shadow:none; min-height:auto; } }
        @page { size:A4; margin:0; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="toolbar">
        <a href="index.php?page=customer_account&id=<?= (int)$customer['id'] ?>">← Cari Hesaba Dön</a>
        <button onclick="window.print()">PDF / Yazdır</button>
    </div>
    <main class="paper">
        <section class="top">
            <div>
                <?php if (file_exists($logoFullPath)): ?>
                    <img src="<?= e($logoPath) ?>?v=<?= filemtime($logoFullPath) ?>" class="logo" alt="MK Denizcilik">
                <?php else: ?>
                    <h1><?= e($companyName) ?></h1>
                <?php endif; ?>
                <p class="muted">Denizcilik üzerine her konuda yanınızdayız.</p>
            </div>
            <div class="meta">
                <h1>CARİ EKSTRE</h1>
                <div>Tarih: <?= date('d.m.Y') ?></div>
                <div>Müşteri No: <?= (int)$customer['id'] ?></div>
            </div>
        </section>

        <h2><?= e($customer['name']) ?></h2>
        <div class="muted">
            Telefon: <?= e($customer['phone'] ?? '-') ?> &nbsp; | &nbsp;
            Vergi No: <?= e($customer['tax_number'] ?? '-') ?>
        </div>

        <section class="cards">
            <div class="card"><span>Güncel Bakiye</span><strong><?= money((float)$customer['balance']) ?></strong></div>
            <div class="card"><span>Toplam Satış</span><strong><?= money($totalSales) ?></strong></div>
            <div class="card"><span>Satışta Ödenen</span><strong><?= money($totalPaid) ?></strong></div>
            <div class="card"><span>Hareket Sayısı</span><strong><?= count($transactions) ?></strong></div>
        </section>

        <table>
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>İşlem</th>
                    <th>Açıklama</th>
                    <th>Ödeme</th>
                    <th class="right">Tutar</th>
                    <th class="right">Bakiye</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?= e($tx['created_at']) ?></td>
                        <td><?= e($tx['transaction_type']) ?></td>
                        <td><?= e($tx['description'] ?? '') ?></td>
                        <td><?= e($tx['payment_type'] ?? '-') ?></td>
                        <td class="right"><?= money((float)$tx['amount']) ?></td>
                        <td class="right"><?= money((float)$tx['balance_after']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?>
                    <tr><td colspan="6">Henüz cari hareket yok.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer">
            Hazırlayan: <?= e(currentUser()['name'] ?? '') ?> | <?= e($companyName) ?>
        </div>
    </main>
</div>
</body>
</html>
