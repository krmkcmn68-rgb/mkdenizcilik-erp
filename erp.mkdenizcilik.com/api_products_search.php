<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

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

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!currentUser()) {
    jsonResponse([
        'success' => false,
        'message' => 'Oturum bulunamadı.',
        'items' => [],
    ], 401);
}

$q = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($q, 'UTF-8') < 2) {
    jsonResponse([
        'success' => true,
        'items' => [],
    ]);
}

try {
    $like = '%' . $q . '%';

    $stmt = db()->prepare("
        SELECT 
            id,
            name,
            stock_code,
            barcode,
            category,
            sale_price,
            vat_rate,
            stock,
            unit
        FROM products
        WHERE name LIKE ?
           OR stock_code LIKE ?
           OR barcode LIKE ?
           OR category LIKE ?
        ORDER BY 
            CASE
                WHEN name LIKE ? THEN 1
                WHEN stock_code LIKE ? THEN 2
                WHEN barcode LIKE ? THEN 3
                ELSE 4
            END,
            name ASC
        LIMIT 20
    ");

    $startsWith = $q . '%';

    $stmt->execute([
        $like,
        $like,
        $like,
        $like,
        $startsWith,
        $startsWith,
        $startsWith,
    ]);

    $rows = $stmt->fetchAll();

    $items = [];

    foreach ($rows as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'stock_code' => (string)($row['stock_code'] ?? ''),
            'barcode' => (string)($row['barcode'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'sale_price' => (float)$row['sale_price'],
            'vat_rate' => (float)$row['vat_rate'],
            'stock' => (float)$row['stock'],
            'unit' => (string)($row['unit'] ?? 'adet'),
        ];
    }

    jsonResponse([
        'success' => true,
        'items' => $items,
    ]);

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
        'items' => [],
    ], 500);
}