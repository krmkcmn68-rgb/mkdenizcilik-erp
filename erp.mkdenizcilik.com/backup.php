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

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_backup') {
        try {
            $tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
            $sql = "-- MK Denizcilik ERP Backup\n";
            $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $tableRow) {
                $table = $tableRow[0];
                $create = db()->query("SHOW CREATE TABLE `" . str_replace('`', '``', $table) . "`")->fetch(PDO::FETCH_ASSOC);
                $createSql = $create['Create Table'] ?? array_values($create)[1] ?? '';

                $sql .= "DROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n";
                $sql .= $createSql . ";\n\n";

                $rows = db()->query("SELECT * FROM `" . str_replace('`', '``', $table) . "`")->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $cols = array_map(fn($c) => "`" . str_replace('`', '``', $c) . "`", array_keys($row));
                    $vals = array_map(function ($v) {
                        if ($v === null) return "NULL";
                        return db()->quote((string)$v);
                    }, array_values($row));

                    $sql .= "INSERT INTO `" . str_replace('`', '``', $table) . "` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
                }

                $sql .= "\n";
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            $filename = 'mk_erp_backup_' . date('Ymd_His') . '.sql';
            file_put_contents($backupDir . '/' . $filename, $sql);
            $message = 'Yedek oluşturuldu: ' . $filename;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['download'])) {
    $file = basename((string)$_GET['download']);
    $path = $backupDir . '/' . $file;

    if (is_file($path)) {
        header('Content-Type: application/sql; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

if (isset($_GET['delete'])) {
    $file = basename((string)$_GET['delete']);
    $path = $backupDir . '/' . $file;

    if (is_file($path)) {
        unlink($path);
    }

    header('Location: backup.php');
    exit;
}

$files = glob($backupDir . '/*.sql') ?: [];
rsort($files);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>MK Denizcilik ERP - Yedekleme</title>

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
        <div><strong>Yedekleme</strong><span>Veritabanı yedeği al / indir</span></div>
    </div>
    <div class="actions"><a class="btn" href="index.php?page=dashboard">← ERP’ye Dön</a></div>
</header>
<main class="wrap">
    <section class="hero">
        <p class="eyebrow">Backup Center</p>
        <h1>Veritabanı Yedekleme</h1>
        <p>Tek tıkla SQL yedeği oluşturup bilgisayarına indirebilirsin.</p>
    </section>

    <?php if ($message): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <section class="panel">
        <form method="post">
            <input type="hidden" name="action" value="create_backup">
            <button class="primary" type="submit">Yeni Yedek Oluştur</button>
        </form>
    </section>

    <section class="panel">
        <h2>Yedek Dosyaları</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Dosya</th><th>Boyut</th><th>Tarih</th><th>İşlem</th></tr></thead>
                <tbody>
                <?php foreach ($files as $path): $file = basename($path); ?>
                    <tr>
                        <td><?= e($file) ?></td>
                        <td><?= number_format(filesize($path) / 1024, 2, ',', '.') ?> KB</td>
                        <td><?= date('d.m.Y H:i:s', filemtime($path)) ?></td>
                        <td>
                            <a class="btn" href="backup.php?download=<?= urlencode($file) ?>">İndir</a>
                            <a class="btn danger" href="backup.php?delete=<?= urlencode($file) ?>" onclick="return confirm('Yedek silinsin mi?')">Sil</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$files): ?><tr><td colspan="4">Henüz yedek yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
