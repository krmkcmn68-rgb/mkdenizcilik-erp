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

function redirect(string $page = 'dashboard', string $extra = ''): never
{
    $url = 'index.php?page=' . urlencode($page);

    if ($extra !== '') {
        $url .= '&' . ltrim($extra, '&');
    }

    header('Location: ' . $url);
    exit;
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void
{
    if (!currentUser()) {
        redirect('login');
    }
}

function isAdmin(): bool
{
    $user = currentUser();
    return $user && ($user['role'] ?? '') === 'admin';
}

function pageTitle(string $title): string
{
    return APP_NAME . ' - ' . $title;
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

function canAccess(string $page): bool
{
    if (!currentUser()) {
        return in_array($page, ['login'], true);
    }

    if (isAdmin()) {
        return true;
    }

    $personelPages = ['dashboard', 'kral', 'customers', 'customer_account', 'payments', 'sales', 'passive_sales', 'quotes', 'logout'];

    return in_array($page, $personelPages, true);
}

function getSetting(string $key, string $default = ''): string
{
    $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return $row ? (string)$row['setting_value'] : $default;
}

function cleanMoneyValue(string $value): float
{
    $value = trim($value);
    $value = str_replace(['₺', '$', '€', ' '], '', $value);

    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function settingFloat(string $key): float
{
    return cleanMoneyValue(getSetting($key, '0'));
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

function tableExists(string $table): bool
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function ensureQuoteTables(): void
{
    if (!tableExists('quotes')) {
        db()->exec("
            CREATE TABLE quotes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_no VARCHAR(50) NOT NULL UNIQUE,
                customer_id INT NULL,
                customer_name VARCHAR(255) NOT NULL,
                valid_until DATE NULL,
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                vat_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                note TEXT NULL,
                prepared_by VARCHAR(150) NOT NULL DEFAULT 'Serhat İnan',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_quotes_customer_id (customer_id),
                INDEX idx_quotes_status (status),
                INDEX idx_quotes_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (!tableExists('quote_items')) {
        db()->exec("
            CREATE TABLE quote_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                product_id INT NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
                unit VARCHAR(30) NOT NULL DEFAULT 'adet',
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                line_vat DECIMAL(12,2) NOT NULL DEFAULT 0,
                line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_quote_items_quote_id (quote_id),
                INDEX idx_quote_items_product_id (product_id),
                CONSTRAINT fk_quote_items_quote
                    FOREIGN KEY (quote_id) REFERENCES quotes(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}


function ensureSalesPaymentColumns(): void
{
    if (!columnExists('sales', 'paid_amount')) {
        db()->exec("\n            ALTER TABLE sales\n            ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0\n            AFTER total_amount\n        ");
    }

    if (!columnExists('sales', 'remaining_amount')) {
        db()->exec("\n            ALTER TABLE sales\n            ADD COLUMN remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0\n            AFTER paid_amount\n        ");
    }

    db()->exec("\n        UPDATE sales\n        SET\n            paid_amount = CASE\n                WHEN paid_amount = 0 AND payment_type <> 'veresiye' THEN total_amount\n                ELSE paid_amount\n            END,\n            remaining_amount = CASE\n                WHEN remaining_amount = 0 THEN GREATEST(total_amount - paid_amount, 0)\n                ELSE remaining_amount\n            END\n    ");
}



function ensureDiscountColumns(): void
{
    ensureQuoteTables();

    if (!columnExists('quotes', 'discount_total')) {
        db()->exec("ALTER TABLE quotes ADD COLUMN discount_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER vat_total");
    }

    if (!columnExists('quotes', 'discount_rate')) {
        db()->exec("ALTER TABLE quotes ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER discount_total");
    }

    if (!columnExists('quotes', 'discount_amount')) {
        db()->exec("ALTER TABLE quotes ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_rate");
    }

    if (!columnExists('quote_items', 'discount_rate')) {
        db()->exec("ALTER TABLE quote_items ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER unit_price");
    }

    if (!columnExists('quote_items', 'discount_amount')) {
        db()->exec("ALTER TABLE quote_items ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_rate");
    }

    if (!columnExists('quote_items', 'line_discount')) {
        db()->exec("ALTER TABLE quote_items ADD COLUMN line_discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_amount");
    }

    if (!columnExists('sales', 'discount_total')) {
        db()->exec("ALTER TABLE sales ADD COLUMN discount_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount");
    }

    if (!columnExists('sales', 'discount_rate')) {
        db()->exec("ALTER TABLE sales ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER discount_total");
    }

    if (!columnExists('sales', 'discount_amount')) {
        db()->exec("ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_rate");
    }

    if (!columnExists('sale_items', 'discount_rate')) {
        db()->exec("ALTER TABLE sale_items ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER unit_price");
    }

    if (!columnExists('sale_items', 'discount_amount')) {
        db()->exec("ALTER TABLE sale_items ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_rate");
    }

    if (!columnExists('sale_items', 'line_discount')) {
        db()->exec("ALTER TABLE sale_items ADD COLUMN line_discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_amount");
    }
}

function ensureCustomerTransactionsTable(): void
{
    if (!tableExists('customer_transactions')) {
        db()->exec("
            CREATE TABLE customer_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                transaction_type VARCHAR(30) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_type VARCHAR(30) NULL,
                description TEXT NULL,
                source_type VARCHAR(30) NULL,
                source_id INT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer_transactions_customer_id (customer_id),
                INDEX idx_customer_transactions_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

function addCustomerTransaction(
    int $customerId,
    string $transactionType,
    float $amount,
    string $description = '',
    string $paymentType = '',
    string $sourceType = '',
    ?int $sourceId = null
): void {
    if ($customerId <= 0 || $amount <= 0) {
        return;
    }

    ensureCustomerTransactionsTable();

    $stmt = db()->prepare("SELECT balance FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    $balanceAfter = $customer ? (float)$customer['balance'] : 0.0;

    $stmt = db()->prepare("
        INSERT INTO customer_transactions
        (customer_id, transaction_type, amount, balance_after, payment_type, description, source_type, source_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $customerId,
        $transactionType,
        $amount,
        $balanceAfter,
        $paymentType !== '' ? $paymentType : null,
        $description !== '' ? $description : null,
        $sourceType !== '' ? $sourceType : null,
        $sourceId,
        currentUser()['id'] ?? null,
    ]);
}



function ensureUserManagementColumns(): void
{
    if (!columnExists('users', 'is_active')) {
        db()->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
    }

    db()->exec("UPDATE users SET is_active = 1 WHERE is_active IS NULL");
}

function ensureSidebarNotesTable(): void
{
    if (!tableExists('sidebar_notes')) {
        db()->exec("
            CREATE TABLE sidebar_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                note_text TEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sidebar_notes_active (is_active),
                INDEX idx_sidebar_notes_created_at (created_at),
                INDEX idx_sidebar_notes_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

function sidebarNotes(): array
{
    ensureSidebarNotesTable();

    $stmt = db()->query("
        SELECT
            n.*,
            u.name AS user_name
        FROM sidebar_notes n
        LEFT JOIN users u ON u.id = n.created_by
        WHERE n.is_active = 1
        ORDER BY n.id DESC
        LIMIT 6
    "
    );

    return $stmt->fetchAll();
}


function ensureStockMovementsTable(): void
{
    if (!tableExists('stock_movements')) {
        db()->exec("
            CREATE TABLE stock_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NULL,
                movement_type VARCHAR(30) NOT NULL DEFAULT 'manual',
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
                old_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
                new_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
                description TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_stock_movements_product_id (product_id),
                INDEX idx_stock_movements_type (movement_type),
                INDEX idx_stock_movements_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return;
    }

    if (!columnExists('stock_movements', 'product_id')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN product_id INT NULL AFTER id");
    }

    if (!columnExists('stock_movements', 'movement_type')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN movement_type VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER product_id");
    }

    if (!columnExists('stock_movements', 'quantity')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN quantity DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER movement_type");
    }

    if (!columnExists('stock_movements', 'old_stock')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN old_stock DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity");
    }

    if (!columnExists('stock_movements', 'new_stock')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN new_stock DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER old_stock");
    }

    if (!columnExists('stock_movements', 'description')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN description TEXT NULL AFTER new_stock");
    }

    if (!columnExists('stock_movements', 'created_by')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN created_by INT NULL AFTER description");
    }

    if (!columnExists('stock_movements', 'created_at')) {
        db()->exec("ALTER TABLE stock_movements ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by");
    }
}

function generateQuoteNo(): string
{
    ensureQuoteTables();

    $prefix = 'MK' . date('Ymd');

    $stmt = db()->prepare("
        SELECT quote_no
        FROM quotes
        WHERE quote_no LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetch();

    if (!$last) {
        return $prefix . '001';
    }

    $lastNo = (string)$last['quote_no'];
    $number = (int)substr($lastNo, -3);
    $number++;

    return $prefix . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
}

function ensureProductCurrencyColumns(): void
{
    if (!columnExists('products', 'purchase_currency')) {
        db()->exec("
            ALTER TABLE products
            ADD COLUMN purchase_currency VARCHAR(10) NOT NULL DEFAULT 'TRY'
            AFTER purchase_price
        ");
    }

    if (!columnExists('products', 'purchase_price_try')) {
        db()->exec("
            ALTER TABLE products
            ADD COLUMN purchase_price_try DECIMAL(12,2) NOT NULL DEFAULT 0
            AFTER purchase_currency
        ");
    }

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
            purchase_currency = IFNULL(NULLIF(purchase_currency, ''), 'TRY'),
            purchase_price_try = CASE
                WHEN purchase_price_try IS NULL OR purchase_price_try = 0 THEN purchase_price
                ELSE purchase_price_try
            END,
            sale_currency = IFNULL(NULLIF(sale_currency, ''), 'TRY'),
            sale_price_original = CASE
                WHEN sale_price_original IS NULL OR sale_price_original = 0 THEN sale_price
                ELSE sale_price_original
            END
    ");
}

function normalizeCurrencyInput(string $currency): string
{
    $currency = strtoupper(trim($currency));

    if (in_array($currency, ['TRY', 'USD', 'EUR'], true)) {
        return $currency;
    }

    return 'TRY';
}

function convertToTry(float $amount, string $currency): float
{
    $currency = normalizeCurrencyInput($currency);

    if ($currency === 'USD') {
        $usdRate = settingFloat('usd_rate');
        return $usdRate > 0 ? $amount * $usdRate : $amount;
    }

    if ($currency === 'EUR') {
        $eurRate = settingFloat('eur_rate');
        return $eurRate > 0 ? $amount * $eurRate : $amount;
    }

    return $amount;
}

function renderHeader(string $title): void
{
    $user = currentUser();
    $activePage = $_GET['page'] ?? 'dashboard';
    $notes = [];
    $sidebarLogoPath = file_exists(__DIR__ . '/assets/mk-logo-pdf.png')
        ? 'assets/mk-logo-pdf.png'
        : 'assets/mk-logo.png';

    if ($user) {
        try {
            $notes = sidebarNotes();
        } catch (Throwable $e) {
            $notes = [];
        }
    }
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e(pageTitle($title)) ?></title>
        <link rel="stylesheet" href="assets/style.css?v=14">
        <style>
            /* MK Sidebar Logo + Icon Mode */
            .brand-area {
                gap: 12px;
            }

            .brand-logo-box {
                width: 64px;
                height: 56px;
                min-width: 64px;
                border-radius: 18px;
                padding: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                background: #ffffff;
                border: 1px solid rgba(250, 204, 21, 0.38);
                box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
            }

            .brand-logo-box img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                display: block;
            }

            .sidebar-menu a {
                position: relative;
            }

            .menu-icon {
                width: 25px;
                min-width: 25px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 17px;
                line-height: 1;
                filter: drop-shadow(0 6px 10px rgba(0, 0, 0, 0.20));
            }

            .sidebar-menu .menu-dot {
                display: none;
            }

            .sidebar-collapsed .sidebar {
                width: 86px;
                min-width: 86px;
            }

            .app-shell.sidebar-collapsed {
                grid-template-columns: 86px minmax(0, 1fr);
            }

            .sidebar-collapsed .brand-area {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }

            .sidebar-collapsed .brand-logo-box {
                width: 56px;
                height: 50px;
                min-width: 56px;
                border-radius: 16px;
                padding: 5px;
            }

            .sidebar-collapsed .brand-logo-box img {
                width: 100%;
                height: 100%;
            }

            .sidebar-collapsed .brand-text,
            .sidebar-collapsed .menu-text,
            .sidebar-collapsed .mini-user-text,
            .sidebar-collapsed .logout-text {
                display: none !important;
            }

            .sidebar-collapsed .sidebar-menu {
                align-items: center;
                padding-left: 0;
                padding-right: 0;
            }

            .sidebar-collapsed .sidebar-menu a {
                width: 54px;
                min-height: 54px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 18px;
                margin-left: auto;
                margin-right: auto;
            }

            .sidebar-collapsed .sidebar-menu a.active {
                background: linear-gradient(135deg, #facc15, #f59e0b);
                color: #111827;
                box-shadow: 0 18px 42px rgba(250, 204, 21, 0.24);
            }

            .sidebar-collapsed .sidebar-menu a.active .menu-icon {
                transform: scale(1.08);
            }

            .sidebar-collapsed .menu-icon {
                font-size: 20px;
                width: 54px;
                min-width: 54px;
                height: 54px;
            }

            .sidebar-collapsed .sidebar-toggle {
                left: 50%;
                transform: translateX(-50%);
            }

            .sidebar-collapsed .sidebar-bottom {
                align-items: center;
            }

            .sidebar-collapsed .mini-user {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }

            .sidebar-collapsed .logout-btn {
                width: 54px;
                height: 54px;
                min-width: 54px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 18px;
                margin-left: auto;
                margin-right: auto;
            }

            .sidebar-collapsed .logout-icon {
                font-size: 19px;
            }

            .sidebar-notes {
                margin: 12px 12px 10px;
                padding: 12px;
                border-radius: 18px;
                border: 1px solid rgba(148, 163, 184, 0.14);
                background: rgba(15, 23, 42, 0.36);
            }

            .sidebar-notes-title {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                margin-bottom: 9px;
                color: var(--text);
                font-size: 12px;
                font-weight: 900;
                letter-spacing: -0.02em;
            }

            .sidebar-notes-title span {
                color: var(--muted);
                font-size: 10px;
                font-weight: 800;
            }

            .sidebar-note-form {
                display: grid;
                grid-template-columns: 1fr 36px;
                gap: 7px;
                margin-bottom: 10px;
            }

            .sidebar-note-form textarea {
                min-height: 38px;
                max-height: 90px;
                resize: vertical;
                padding: 9px 10px;
                border-radius: 12px;
                font-size: 11px;
                line-height: 1.25;
            }

            .sidebar-note-form button {
                min-width: 36px;
                width: 36px;
                padding: 0;
                border-radius: 12px;
                font-size: 18px;
                line-height: 1;
            }

            .sidebar-note-list {
                display: flex;
                flex-direction: column;
                gap: 7px;
                max-height: 205px;
                overflow-y: auto;
                padding-right: 2px;
            }

            .sidebar-note-item {
                padding: 8px 9px;
                border-radius: 13px;
                background: rgba(2, 6, 23, 0.32);
                border: 1px solid rgba(148, 163, 184, 0.10);
            }

            .sidebar-note-text {
                margin: 0 0 6px;
                color: var(--text);
                font-size: 11px;
                line-height: 1.32;
                overflow-wrap: anywhere;
            }

            .sidebar-note-meta {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 6px;
                color: var(--muted);
                font-size: 9px;
                font-weight: 700;
            }

            .sidebar-note-delete {
                display: inline;
                margin: 0;
            }

            .sidebar-note-delete button {
                min-width: auto;
                padding: 2px 6px;
                border-radius: 8px;
                font-size: 9px;
                background: rgba(251, 113, 133, 0.12);
                color: #fecdd3;
                border: 1px solid rgba(251, 113, 133, 0.20);
                box-shadow: none;
            }

            .sidebar-note-empty {
                margin: 0;
                color: var(--muted);
                font-size: 11px;
                line-height: 1.35;
            }

            .sidebar-collapsed .sidebar-notes {
                display: none;
            }

            /* MK Denizcilik ERP - Login Sonrası 5sn Deniz / Futuristik Intro */
            .mk-ocean-intro {
                position: fixed;
                inset: 0;
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                background:
                    radial-gradient(circle at 50% 42%, rgba(56, 189, 248, 0.22), transparent 28%),
                    radial-gradient(circle at 70% 72%, rgba(250, 204, 21, 0.14), transparent 32%),
                    linear-gradient(180deg, #020617 0%, #062037 48%, #020617 100%);
                animation: mkIntroHide 5s ease forwards;
            }

            .mk-ocean-intro::before {
                content: "";
                position: absolute;
                inset: -30%;
                background:
                    linear-gradient(115deg, transparent 0%, rgba(56, 189, 248, 0.12) 22%, transparent 38%),
                    linear-gradient(245deg, transparent 0%, rgba(250, 204, 21, 0.08) 24%, transparent 42%);
                animation: mkIntroLightSweep 5s ease-in-out forwards;
            }

            .mk-ocean-intro::after {
                content: "";
                position: absolute;
                inset: 0;
                background-image:
                    linear-gradient(rgba(56, 189, 248, 0.08) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(56, 189, 248, 0.07) 1px, transparent 1px);
                background-size: 42px 42px;
                mask-image: linear-gradient(to bottom, transparent 0%, #000 22%, #000 78%, transparent 100%);
                opacity: 0.55;
                animation: mkGridDrift 5s linear forwards;
            }

            .mk-ocean-core {
                position: relative;
                z-index: 3;
                width: min(640px, 92vw);
                min-height: 430px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                isolation: isolate;
            }

            .mk-sonar-ring,
            .mk-sonar-ring::before,
            .mk-sonar-ring::after {
                position: absolute;
                content: "";
                width: 320px;
                height: 320px;
                border-radius: 999px;
                border: 1px solid rgba(56, 189, 248, 0.34);
                box-shadow:
                    0 0 28px rgba(56, 189, 248, 0.22),
                    inset 0 0 30px rgba(56, 189, 248, 0.10);
                animation: mkSonarPulse 2.4s ease-in-out infinite;
            }

            .mk-sonar-ring::before {
                inset: -46px;
                width: auto;
                height: auto;
                border-color: rgba(250, 204, 21, 0.20);
                animation-delay: 0.35s;
            }

            .mk-sonar-ring::after {
                inset: -88px;
                width: auto;
                height: auto;
                border-color: rgba(56, 189, 248, 0.18);
                animation-delay: 0.7s;
            }

            .mk-intro-logo-wrap {
                position: relative;
                z-index: 4;
                width: 150px;
                height: 118px;
                border-radius: 34px;
                padding: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255, 255, 255, 0.96);
                border: 1px solid rgba(250, 204, 21, 0.50);
                box-shadow:
                    0 0 60px rgba(56, 189, 248, 0.32),
                    0 0 100px rgba(250, 204, 21, 0.14),
                    0 26px 80px rgba(0, 0, 0, 0.42);
                animation: mkLogoFloat 5s ease-in-out forwards;
            }

            .mk-intro-logo-wrap img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                display: block;
            }

            .mk-intro-logo-fallback {
                color: #020617;
                font-size: 44px;
                font-weight: 950;
                letter-spacing: -0.08em;
            }

            .mk-intro-title {
                position: relative;
                z-index: 4;
                margin: 28px 0 8px;
                color: #f8fafc;
                font-size: clamp(28px, 5vw, 52px);
                line-height: 1;
                font-weight: 950;
                letter-spacing: -0.08em;
                text-shadow: 0 0 34px rgba(56, 189, 248, 0.34);
                animation: mkTextReveal 5s ease forwards;
            }

            .mk-intro-subtitle {
                position: relative;
                z-index: 4;
                margin: 0;
                color: rgba(226, 232, 240, 0.78);
                font-size: 13px;
                font-weight: 850;
                letter-spacing: 0.22em;
                text-transform: uppercase;
                animation: mkTextReveal 5s ease forwards;
            }

            .mk-ocean-line {
                position: relative;
                z-index: 4;
                width: min(460px, 78vw);
                height: 8px;
                margin-top: 30px;
                border-radius: 999px;
                overflow: hidden;
                background: rgba(15, 23, 42, 0.82);
                border: 1px solid rgba(148, 163, 184, 0.18);
                box-shadow: inset 0 0 18px rgba(0, 0, 0, 0.48);
            }

            .mk-ocean-line span {
                display: block;
                height: 100%;
                width: 0%;
                border-radius: inherit;
                background: linear-gradient(90deg, #38bdf8, #facc15, #22d3ee);
                box-shadow: 0 0 24px rgba(56, 189, 248, 0.55);
                animation: mkLoadLine 5s cubic-bezier(.22,.8,.22,1) forwards;
            }

            .mk-wave {
                position: absolute;
                left: -10%;
                right: -10%;
                bottom: -58px;
                height: 190px;
                z-index: 2;
                opacity: 0.82;
                background:
                    radial-gradient(80px 32px at 8% 24%, rgba(56, 189, 248, 0.26) 0 48%, transparent 50%),
                    radial-gradient(90px 38px at 28% 28%, rgba(14, 165, 233, 0.34) 0 48%, transparent 50%),
                    radial-gradient(120px 46px at 54% 22%, rgba(56, 189, 248, 0.26) 0 48%, transparent 50%),
                    radial-gradient(90px 36px at 78% 30%, rgba(14, 165, 233, 0.30) 0 48%, transparent 50%),
                    linear-gradient(180deg, rgba(56, 189, 248, 0.10), rgba(8, 47, 73, 0.82));
                filter: blur(0.2px);
                animation: mkWaveMove 5s ease-in-out forwards;
            }

            .mk-ship-scan {
                position: absolute;
                z-index: 4;
                bottom: 126px;
                width: 130px;
                height: 28px;
                opacity: 0.78;
                border-bottom: 3px solid rgba(250, 204, 21, 0.75);
                border-left: 18px solid transparent;
                border-right: 20px solid transparent;
                filter: drop-shadow(0 0 18px rgba(250, 204, 21, 0.28));
                animation: mkShipScan 5s ease-in-out forwards;
            }

            .mk-ship-scan::before {
                content: "";
                position: absolute;
                left: 36px;
                bottom: 18px;
                width: 46px;
                height: 26px;
                border-radius: 10px 10px 2px 2px;
                border: 2px solid rgba(56, 189, 248, 0.62);
                background: rgba(56, 189, 248, 0.09);
            }

            @keyframes mkIntroHide {
                0%, 82% { opacity: 1; visibility: visible; }
                100% { opacity: 0; visibility: hidden; pointer-events: none; }
            }

            @keyframes mkLoadLine {
                0% { width: 0%; }
                62% { width: 74%; }
                100% { width: 100%; }
            }

            @keyframes mkSonarPulse {
                0% { transform: scale(0.82); opacity: 0.20; }
                45% { opacity: 0.72; }
                100% { transform: scale(1.08); opacity: 0.18; }
            }

            @keyframes mkLogoFloat {
                0% { transform: translateY(22px) scale(0.82); opacity: 0; filter: blur(8px); }
                20% { transform: translateY(0) scale(1); opacity: 1; filter: blur(0); }
                72% { transform: translateY(-7px) scale(1.02); opacity: 1; }
                100% { transform: translateY(-18px) scale(0.96); opacity: 0; filter: blur(8px); }
            }

            @keyframes mkTextReveal {
                0%, 14% { opacity: 0; transform: translateY(18px); filter: blur(8px); }
                28%, 76% { opacity: 1; transform: translateY(0); filter: blur(0); }
                100% { opacity: 0; transform: translateY(-16px); filter: blur(8px); }
            }

            @keyframes mkWaveMove {
                0% { transform: translateX(-4%) translateY(28px); }
                55% { transform: translateX(3%) translateY(0); }
                100% { transform: translateX(8%) translateY(34px); opacity: 0; }
            }

            @keyframes mkShipScan {
                0% { transform: translateX(-54vw); opacity: 0; }
                18% { opacity: .75; }
                68% { transform: translateX(54vw); opacity: .75; }
                100% { transform: translateX(68vw); opacity: 0; }
            }

            @keyframes mkIntroLightSweep {
                0% { transform: translateX(-20%) rotate(0deg); opacity: .20; }
                55% { opacity: .85; }
                100% { transform: translateX(22%) rotate(8deg); opacity: 0; }
            }

            @keyframes mkGridDrift {
                0% { transform: translateY(0); opacity: .44; }
                100% { transform: translateY(42px); opacity: 0; }
            }

            @media (max-width: 640px) {
                .mk-intro-logo-wrap {
                    width: 126px;
                    height: 98px;
                    border-radius: 28px;
                }

                .mk-sonar-ring,
                .mk-sonar-ring::before,
                .mk-sonar-ring::after {
                    width: 238px;
                    height: 238px;
                }

                .mk-ship-scan {
                    width: 96px;
                    bottom: 116px;
                }
            }

        
        .sale-list-actions { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; }
        .sale-list-actions form { margin: 0; }

</style>
    </head>
    <body>
    <?php if (($_GET['intro'] ?? '') === '1'): ?>
        <div class="mk-ocean-intro" id="mkOceanIntro" aria-hidden="true">
            <div class="mk-wave"></div>
            <div class="mk-ship-scan"></div>

            <div class="mk-ocean-core">
                <div class="mk-sonar-ring"></div>

                <div class="mk-intro-logo-wrap">
                    <?php
                        $introLogoPath = file_exists(__DIR__ . '/assets/mk-logo-pdf.png')
                            ? 'assets/mk-logo-pdf.png'
                            : (file_exists(__DIR__ . '/assets/mk-logo.png') ? 'assets/mk-logo.png' : '');
                    ?>

                    <?php if ($introLogoPath !== ''): ?>
                        <img src="<?= e($introLogoPath) ?>?v=<?= filemtime(__DIR__ . '/' . $introLogoPath) ?>" alt="MK Denizcilik">
                    <?php else: ?>
                        <div class="mk-intro-logo-fallback">MK</div>
                    <?php endif; ?>
                </div>

                <h2 class="mk-intro-title">MK Denizcilik</h2>
                <p class="mk-intro-subtitle">ERP sistemi başlatılıyor</p>
                <div class="mk-ocean-line"><span></span></div>
            </div>
        </div>

        <script>
            window.setTimeout(function () {
                var intro = document.getElementById('mkOceanIntro');
                if (intro) {
                    intro.remove();
                }

                if (window.history && window.history.replaceState) {
                    var cleanUrl = window.location.href
                        .replace(/[?&]intro=1(&|$)/, function (match, tail) {
                            return tail ? '?' : '';
                        })
                        .replace(/[?&]$/, '');

                    window.history.replaceState({}, document.title, cleanUrl);
                }
            }, 5100);
        </script>
    <?php endif; ?>
    <div class="app-shell" id="appShell">
        <aside class="sidebar" id="sidebar">
            <button type="button" class="sidebar-toggle" id="sidebarToggle" title="Menüyü daralt / aç">
                <span class="toggle-open">‹</span>
                <span class="toggle-closed">›</span>
            </button>

            <div class="brand-area">
                <div class="brand-logo-box" title="MK Denizcilik">
                    <?php if (file_exists(__DIR__ . '/' . $sidebarLogoPath)): ?>
                        <img src="<?= e($sidebarLogoPath) ?>?v=<?= filemtime(__DIR__ . '/' . $sidebarLogoPath) ?>" alt="MK Denizcilik">
                    <?php else: ?>
                        <strong>MK</strong>
                    <?php endif; ?>
                </div>
                <div class="brand-text">
                    <strong>MK Denizcilik</strong>
                    <span>ERP Yönetim Paneli</span>
                </div>
            </div>

            <nav class="sidebar-menu">
                <a href="index.php?page=dashboard" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" title="Güncel Durum">
                    <span class="menu-icon">📊</span>
                    <span class="menu-text">Güncel Durum</span>
                </a>

                <a href="index.php?page=kral" class="<?= $activePage === 'kral' ? 'active' : '' ?>" title="KRAL Asistan">
                    <span class="menu-icon">🤖</span>
                    <span class="menu-text">KRAL</span>
                </a>

                <a href="index.php?page=customers" class="<?= $activePage === 'customers' ? 'active' : '' ?>" title="Müşteriler">
                    <span class="menu-icon">👥</span>
                    <span class="menu-text">Müşteriler</span>
                </a>

                <?php if (isAdmin()): ?>
                    <a href="index.php?page=products" class="<?= $activePage === 'products' ? 'active' : '' ?>" title="Ürünler">
                        <span class="menu-icon">📦</span>
                        <span class="menu-text">Ürünler</span>
                    </a>
                <?php endif; ?>

                <a href="index.php?page=sales" class="<?= $activePage === 'sales' ? 'active' : '' ?>" title="Satış">
                    <span class="menu-icon">🛒</span>
                    <span class="menu-text">Satış</span>
                </a>

                <a href="index.php?page=passive_sales" class="<?= $activePage === 'passive_sales' ? 'active' : '' ?>" title="Tezgah Satışı">
                    <span class="menu-icon">⚡</span>
                    <span class="menu-text">Tezgah Satışı</span>
                </a>

                <a href="index.php?page=payments" class="<?= $activePage === 'payments' ? 'active' : '' ?>" title="Tahsilat / Ödeme">
                    <span class="menu-icon">💳</span>
                    <span class="menu-text">Tahsilat / Ödeme</span>
                </a>

                <a href="index.php?page=quotes" class="<?= $activePage === 'quotes' ? 'active' : '' ?>" title="Teklifler">
                    <span class="menu-icon">🧾</span>
                    <span class="menu-text">Teklifler</span>
                </a>

                <?php if (isAdmin()): ?>
                    <a href="index.php?page=backup" class="<?= $activePage === 'backup' ? 'active' : '' ?>" title="Yedekleme">
                        <span class="menu-icon">💾</span>
                        <span class="menu-text">Yedekleme</span>
                    </a>

                    <a href="index.php?page=reports" class="<?= $activePage === 'reports' ? 'active' : '' ?>" title="Raporlar">
                        <span class="menu-icon">📈</span>
                        <span class="menu-text">Raporlar</span>
                    </a>

                    <a href="index.php?page=export" class="<?= $activePage === 'export' ? 'active' : '' ?>" title="Excel Export">
                        <span class="menu-icon">📤</span>
                        <span class="menu-text">Excel Export</span>
                    </a>

                    <a href="index.php?page=barcode" class="<?= $activePage === 'barcode' ? 'active' : '' ?>" title="Barkod / Etiket">
                        <span class="menu-icon">🏷️</span>
                        <span class="menu-text">Barkod / Etiket</span>
                    </a>

                    <a href="index.php?page=settings" class="<?= $activePage === 'settings' ? 'active' : '' ?>" title="Ayarlar">
                        <span class="menu-icon">⚙️</span>
                        <span class="menu-text">Ayarlar</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-notes">
                <div class="sidebar-notes-title">
                    <strong>Notlar</strong>
                    <span><?= count($notes) ?> aktif</span>
                </div>

                <form method="post" action="index.php?page=<?= e($activePage) ?>" class="sidebar-note-form">
                    <input type="hidden" name="action" value="add_sidebar_note">
                    <input type="hidden" name="return_page" value="<?= e($activePage) ?>">
                    <textarea name="note_text" maxlength="500" placeholder="Kısa not ekle..." required></textarea>
                    <button type="submit" title="Not ekle">+</button>
                </form>

                <div class="sidebar-note-list">
                    <?php foreach ($notes as $note): ?>
                        <div class="sidebar-note-item">
                            <p class="sidebar-note-text"><?= e($note['note_text'] ?? '') ?></p>
                            <div class="sidebar-note-meta">
                                <span><?= e($note['user_name'] ?? 'Kullanıcı') ?> · <?= e(date('d.m', strtotime((string)($note['created_at'] ?? 'now')))) ?></span>

                                <?php if (isAdmin()): ?>
                                    <form method="post" action="index.php?page=<?= e($activePage) ?>" class="sidebar-note-delete" onsubmit="return confirm('Not silinsin mi?')">
                                        <input type="hidden" name="action" value="delete_sidebar_note">
                                        <input type="hidden" name="return_page" value="<?= e($activePage) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$note['id'] ?>">
                                        <button type="submit">Sil</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$notes): ?>
                        <p class="sidebar-note-empty">Henüz not yok. İlk notu ekleyebilirsin.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sidebar-bottom">
                <div class="mini-user">
                    <div class="mini-avatar">
                        <?= e(firstLetter($user['name'] ?? 'U')) ?>
                    </div>
                    <div class="mini-user-text">
                        <strong><?= e($user['name'] ?? '') ?></strong>
                        <span><?= e($user['role'] ?? '') ?></span>
                    </div>
                </div>

                <a href="index.php?page=logout" class="logout-btn" title="Çıkış Yap">
                    <span class="logout-text">Çıkış Yap</span>
                    <span class="logout-icon">⏻</span>
                </a>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">MK Denizcilik ERP</p>
                    <h1><?= e($title) ?></h1>
                </div>

                <div class="top-actions">
                    <div class="role-badge"><?= e($user['role'] ?? '') ?></div>
                    <div class="date-pill"><?= date('d.m.Y') ?></div>
                </div>
            </header>
    <?php
}

function renderFooter(): void
{
    ?>
        </main>
    </div>

    <script>
        (function () {
            const shell = document.getElementById('appShell');
            const button = document.getElementById('sidebarToggle');

            if (!shell || !button) {
                return;
            }

            const saved = localStorage.getItem('mk_sidebar_collapsed');

            if (saved === '1') {
                shell.classList.add('sidebar-collapsed');
            }

            button.addEventListener('click', function () {
                shell.classList.toggle('sidebar-collapsed');

                if (shell.classList.contains('sidebar-collapsed')) {
                    localStorage.setItem('mk_sidebar_collapsed', '1');
                } else {
                    localStorage.setItem('mk_sidebar_collapsed', '0');
                }
            });
        })();
    </script>
    </body>
    </html>
    <?php
}

$page = $_GET['page'] ?? 'dashboard';

if (!canAccess($page)) {
    $page = 'dashboard';
}

$error = '';

ensureUserManagementColumns();

if ($page === 'logout') {
    session_destroy();
    redirect('login');
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];

        redirect('dashboard', 'intro=1');
    } else {
        $error = 'Kullanıcı adı veya şifre hatalı.';
    }
}

if ($page !== 'login') {
    requireLogin();
}

/*
|--------------------------------------------------------------------------
| SIDEBAR NOTLAR POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && currentUser()) {
    $sidebarAction = $_POST['action'] ?? '';

    if ($sidebarAction === 'add_sidebar_note') {
        ensureSidebarNotesTable();

        $noteText = trim((string)($_POST['note_text'] ?? ''));
        $returnPage = trim((string)($_POST['return_page'] ?? $page));

        if (!canAccess($returnPage)) {
            $returnPage = 'dashboard';
        }

        if ($noteText !== '') {
            if (function_exists('mb_substr')) {
                $noteText = mb_substr($noteText, 0, 500, 'UTF-8');
            } else {
                $noteText = substr($noteText, 0, 500);
            }

            $stmt = db()->prepare("
                INSERT INTO sidebar_notes (note_text, is_active, created_by)
                VALUES (?, 1, ?)
            ");
            $stmt->execute([$noteText, currentUser()['id'] ?? null]);
        }

        redirect($returnPage);
    }

    if ($sidebarAction === 'delete_sidebar_note' && isAdmin()) {
        ensureSidebarNotesTable();

        $id = (int)($_POST['id'] ?? 0);
        $returnPage = trim((string)($_POST['return_page'] ?? $page));

        if (!canAccess($returnPage)) {
            $returnPage = 'dashboard';
        }

        if ($id > 0) {
            $stmt = db()->prepare("UPDATE sidebar_notes SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
        }

        redirect($returnPage);
    }
}

/*
|--------------------------------------------------------------------------
| MÜŞTERİLER POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'customers') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_customer') {
        $stmt = db()->prepare("
            INSERT INTO customers (name, phone, email, address, tax_office, tax_number)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['tax_office'] ?? ''),
            trim($_POST['tax_number'] ?? ''),
        ]);

        redirect('customers');
    }

    if ($action === 'update_customer' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);

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
            trim($_POST['name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['tax_office'] ?? ''),
            trim($_POST['tax_number'] ?? ''),
            (float)($_POST['balance'] ?? 0),
            $id,
        ]);

        redirect('customers');
    }

    if ($action === 'delete_customer' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->execute([$id]);

        redirect('customers');
    }

    if ($action === 'delete_all_customers' && isAdmin()) {
        db()->exec("UPDATE sales SET customer_id = NULL");
        db()->exec("DELETE FROM customers");

        redirect('customers');
    }
}


/*
|--------------------------------------------------------------------------
| CARİ HESAP POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'customer_account') {
    ensureCustomerTransactionsTable();

    $action = $_POST['action'] ?? '';
    $customerId = (int)($_POST['customer_id'] ?? 0);

    if ($customerId <= 0) {
        redirect('customers');
    }

    if ($action === 'add_customer_payment') {
        $amount = cleanMoneyValue((string)($_POST['amount'] ?? '0'));
        $paymentType = trim($_POST['payment_type'] ?? 'nakit');
        $description = trim($_POST['description'] ?? 'Tahsilat');

        if ($amount > 0) {
            db()->beginTransaction();
            try {
                $stmt = db()->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $customerId]);

                addCustomerTransaction(
                    $customerId,
                    'tahsilat',
                    $amount,
                    $description !== '' ? $description : 'Tahsilat kaydı',
                    $paymentType,
                    'manual',
                    null
                );

                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $error = $e->getMessage();
            }
        }

        redirect('customer_account', 'id=' . $customerId);
    }

    if ($action === 'add_customer_adjustment' && isAdmin()) {
        $amount = cleanMoneyValue((string)($_POST['amount'] ?? '0'));
        $adjustmentType = $_POST['adjustment_type'] ?? 'borc';
        $description = trim($_POST['description'] ?? 'Manuel cari düzeltme');

        if ($amount > 0) {
            db()->beginTransaction();
            try {
                if ($adjustmentType === 'alacak') {
                    $stmt = db()->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                    $stmt->execute([$amount, $customerId]);
                    $txType = 'alacak_duzeltme';
                } else {
                    $stmt = db()->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                    $stmt->execute([$amount, $customerId]);
                    $txType = 'borc_duzeltme';
                }

                addCustomerTransaction(
                    $customerId,
                    $txType,
                    $amount,
                    $description !== '' ? $description : 'Manuel cari düzeltme',
                    '',
                    'manual',
                    null
                );

                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $error = $e->getMessage();
            }
        }

        redirect('customer_account', 'id=' . $customerId);
    }
}



/*
|--------------------------------------------------------------------------
| TAHSİLAT / ÖDEME POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'payments') {
    ensureCustomerTransactionsTable();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_payment_collection') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $amount = cleanMoneyValue((string)($_POST['amount'] ?? '0'));
        $paymentType = trim((string)($_POST['payment_type'] ?? 'nakit'));
        $description = trim((string)($_POST['description'] ?? 'Tahsilat'));

        if (!in_array($paymentType, ['nakit', 'kart', 'iban'], true)) {
            $paymentType = 'nakit';
        }

        if ($customerId > 0 && $amount > 0) {
            try {
                db()->beginTransaction();

                $stmt = db()->prepare("SELECT id, name, balance FROM customers WHERE id = ? FOR UPDATE");
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch();

                if (!$customer) {
                    throw new RuntimeException('Müşteri bulunamadı.');
                }

                $stmt = db()->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $customerId]);

                addCustomerTransaction(
                    $customerId,
                    'tahsilat',
                    $amount,
                    $description !== '' ? $description : 'Tahsilat kaydı',
                    $paymentType,
                    'payments',
                    null
                );

                db()->commit();
                redirect('payments');
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }

                redirect('payments', 'payment_error=' . urlencode($e->getMessage()));
            }
        }

        redirect('payments');
    }
}

/*
|--------------------------------------------------------------------------
| STOK HAREKETİ POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'product_stock' && isAdmin()) {
    ensureStockMovementsTable();

    $action = $_POST['action'] ?? '';
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        redirect('products');
    }

    if ($action === 'add_stock_movement') {
        $movementType = trim((string)($_POST['movement_type'] ?? 'stock_in'));
        $quantity = (float)($_POST['quantity'] ?? 0);
        $description = trim((string)($_POST['description'] ?? 'Manuel stok hareketi'));

        if (!in_array($movementType, ['stock_in', 'stock_out'], true)) {
            $movementType = 'stock_in';
        }

        if ($quantity > 0) {
            try {
                db()->beginTransaction();

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Ürün bulunamadı.');
                }

                $oldStock = (float)$product['stock'];
                $newStock = $movementType === 'stock_in'
                    ? $oldStock + $quantity
                    : $oldStock - $quantity;

                if ($newStock < 0) {
                    throw new RuntimeException('Stok eksiye düşemez. Mevcut stok: ' . number_format($oldStock, 2, ',', '.') . ' ' . ($product['unit'] ?? ''));
                }

                $stmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
                $stmt->execute([$newStock, $productId]);

                $stmt = db()->prepare("
                    INSERT INTO stock_movements
                    (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $productId,
                    $movementType,
                    $quantity,
                    $oldStock,
                    $newStock,
                    $description !== '' ? $description : 'Manuel stok hareketi',
                    currentUser()['id'] ?? null,
                ]);

                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }

                redirect('product_stock', 'id=' . $productId . '&stock_error=' . urlencode($e->getMessage()));
            }
        }

        redirect('product_stock', 'id=' . $productId);
    }
}

/*
|--------------------------------------------------------------------------
| ÜRÜNLER POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'products' && isAdmin()) {
    ensureProductCurrencyColumns();
    ensureStockMovementsTable();

    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add_product', 'update_product'], true)) {
        $purchaseCurrency = normalizeCurrencyInput($_POST['purchase_currency'] ?? 'TRY');
        $saleCurrency = normalizeCurrencyInput($_POST['sale_currency'] ?? 'TRY');

        $purchasePrice = (float)($_POST['purchase_price'] ?? 0);
        $purchasePriceTry = convertToTry($purchasePrice, $purchaseCurrency);

        $salePriceOriginal = (float)($_POST['sale_price_original'] ?? 0);
        $salePriceTry = convertToTry($salePriceOriginal, $saleCurrency);
    }

    if ($action === 'add_product') {
        $stmt = db()->prepare("
            INSERT INTO products 
            (name, stock_code, barcode, category, purchase_price, purchase_currency, purchase_price_try, sale_price, sale_currency, sale_price_original, vat_rate, stock, unit, min_stock)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['stock_code'] ?? ''),
            trim($_POST['barcode'] ?? ''),
            trim($_POST['category'] ?? ''),
            $purchasePrice,
            $purchaseCurrency,
            $purchasePriceTry,
            $salePriceTry,
            $saleCurrency,
            $salePriceOriginal,
            (float)($_POST['vat_rate'] ?? 20),
            (float)($_POST['stock'] ?? 0),
            trim($_POST['unit'] ?? 'adet'),
            (float)($_POST['min_stock'] ?? 3),
        ]);

        redirect('products');
    }

    if ($action === 'update_product') {
        $id = (int)($_POST['id'] ?? 0);

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
            trim($_POST['name'] ?? ''),
            trim($_POST['stock_code'] ?? ''),
            trim($_POST['barcode'] ?? ''),
            trim($_POST['category'] ?? ''),
            $purchasePrice,
            $purchaseCurrency,
            $purchasePriceTry,
            $salePriceTry,
            $saleCurrency,
            $salePriceOriginal,
            (float)($_POST['vat_rate'] ?? 20),
            (float)($_POST['stock'] ?? 0),
            trim($_POST['unit'] ?? 'adet'),
            (float)($_POST['min_stock'] ?? 3),
            $id,
        ]);

        redirect('products');
    }

    if ($action === 'delete_product') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);

        redirect('products');
    }

    if ($action === 'delete_all_products') {
        db()->exec("UPDATE sale_items SET product_id = NULL");
        db()->exec("DELETE FROM stock_movements");
        db()->exec("DELETE FROM products");

        redirect('products');
    }
}


/*
|--------------------------------------------------------------------------
| TEKLİFLER POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'quotes') {
    ensureQuoteTables();
    ensureDiscountColumns();
    ensureStockMovementsTable();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_quote') {
        $customerId = $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : null;
        $customerName = trim($_POST['customer_name'] ?? '');
        $validUntil = trim($_POST['valid_until'] ?? '');
        $note = trim($_POST['note'] ?? '');

        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $vatRates = $_POST['vat_rate'] ?? [];
        $itemDiscountRates = $_POST['item_discount_rate'] ?? [];
        $itemDiscountAmounts = $_POST['item_discount_amount'] ?? [];
        $quoteDiscountRate = max(0, min((float)($_POST['quote_discount_rate'] ?? 0), 100));
        $quoteDiscountAmount = cleanMoneyValue((string)($_POST['quote_discount_amount'] ?? '0'));

        if (!is_array($productIds)) {
            $productIds = [];
        }

        if ($customerId) {
            $stmt = db()->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch();

            if ($customer) {
                $customerName = $customer['name'];
            }
        }

        if ($customerName === '') {
            $customerName = 'Perakende Müşteri';
        }

        try {
            db()->beginTransaction();

            $quoteNo = generateQuoteNo();
            $itemsToInsert = [];
            $subtotal = 0.0;
            $vatTotal = 0.0;
            $grandTotal = 0.0;
            $lineDiscountTotal = 0.0;

            foreach ($productIds as $index => $productIdRaw) {
                $productId = (int)$productIdRaw;
                $quantity = isset($quantities[$index]) ? (float)$quantities[$index] : 0;

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    continue;
                }

                $postedUnitPrice = isset($unitPrices[$index]) ? (float)$unitPrices[$index] : 0;
                $postedVatRate = isset($vatRates[$index]) ? (float)$vatRates[$index] : (float)$product['vat_rate'];
                $postedDiscountRate = isset($itemDiscountRates[$index]) ? (float)$itemDiscountRates[$index] : 0;
                $postedDiscountAmount = isset($itemDiscountAmounts[$index]) ? cleanMoneyValue((string)$itemDiscountAmounts[$index]) : 0;

                $unitPrice = $postedUnitPrice > 0 ? $postedUnitPrice : (float)$product['sale_price'];
                $vatRate = $postedVatRate >= 0 ? $postedVatRate : (float)$product['vat_rate'];
                $discountRate = max(0, min($postedDiscountRate, 100));

                $lineBase = $quantity * $unitPrice;
                $linePercentDiscount = $lineBase * ($discountRate / 100);
                $discountAmount = max(0, min($postedDiscountAmount, max($lineBase - $linePercentDiscount, 0)));
                $lineDiscount = $linePercentDiscount + $discountAmount;
                $lineSubtotal = max($lineBase - $lineDiscount, 0);
                $lineVat = $lineSubtotal * ($vatRate / 100);
                $lineTotal = $lineSubtotal + $lineVat;

                $lineDiscountTotal += $lineDiscount;
                $subtotal += $lineSubtotal;
                $vatTotal += $lineVat;
                $grandTotal += $lineTotal;

                $itemsToInsert[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'quantity' => $quantity,
                    'unit' => $product['unit'] ?? 'adet',
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_discount' => $lineDiscount,
                    'vat_rate' => $vatRate,
                    'line_subtotal' => $lineSubtotal,
                    'line_vat' => $lineVat,
                    'line_total' => $lineTotal,
                ];
            }

            if (!$itemsToInsert) {
                throw new RuntimeException('Teklife en az 1 ürün satırı eklemelisin.');
            }

            $globalPercentDiscount = $grandTotal * ($quoteDiscountRate / 100);
            $globalAmountDiscount = max(0, min($quoteDiscountAmount, max($grandTotal - $globalPercentDiscount, 0)));
            $globalDiscount = $globalPercentDiscount + $globalAmountDiscount;
            $discountTotal = $lineDiscountTotal + $globalDiscount;
            $grandTotal = max($grandTotal - $globalDiscount, 0);

            $stmt = db()->prepare("
                INSERT INTO quotes
                (quote_no, customer_id, customer_name, valid_until, subtotal, vat_total, discount_total, discount_rate, discount_amount, grand_total, status, note, prepared_by, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, 'Serhat İnan', ?)
            ");

            $stmt->execute([
                $quoteNo,
                $customerId,
                $customerName,
                $validUntil !== '' ? $validUntil : null,
                $subtotal,
                $vatTotal,
                $discountTotal,
                $quoteDiscountRate,
                $globalAmountDiscount,
                $grandTotal,
                $note,
                currentUser()['id'] ?? null,
            ]);

            $quoteId = (int)db()->lastInsertId();

            $itemStmt = db()->prepare("
                INSERT INTO quote_items
                (quote_id, product_id, product_name, quantity, unit, unit_price, discount_rate, discount_amount, line_discount, vat_rate, line_subtotal, line_vat, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($itemsToInsert as $item) {
                $itemStmt->execute([
                    $quoteId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price'],
                    $item['discount_rate'],
                    $item['discount_amount'],
                    $item['line_discount'],
                    $item['vat_rate'],
                    $item['line_subtotal'],
                    $item['line_vat'],
                    $item['line_total'],
                ]);
            }

            db()->commit();
            redirect('quotes');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    if ($action === 'delete_quote' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM quotes WHERE id = ?");
        $stmt->execute([$id]);

        redirect('quotes');
    }


    if ($action === 'convert_quote_to_sale') {
        $id = (int)($_POST['id'] ?? 0);

        try {
            db()->beginTransaction();

            $stmt = db()->prepare("SELECT * FROM quotes WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $quote = $stmt->fetch();

            if (!$quote) {
                throw new RuntimeException('Teklif bulunamadı.');
            }

            if (($quote['status'] ?? '') === 'converted') {
                throw new RuntimeException('Bu teklif zaten satışa çevrilmiş.');
            }

            $stmt = db()->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll();

            if (!$items) {
                throw new RuntimeException('Teklif kalemi bulunamadı.');
            }

            foreach ($items as $item) {
                if (!$item['product_id']) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$item['product_id']]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Ürün bulunamadı: ' . $item['product_name']);
                }

                $currentStock = (float)$product['stock'];
                $quantity = (float)$item['quantity'];

                if ($currentStock < $quantity) {
                    throw new RuntimeException('Yetersiz stok: ' . $product['name'] . ' / Mevcut: ' . number_format($currentStock, 2, ',', '.') . ' ' . ($product['unit'] ?? ''));
                }
            }

            $saleNo = 'MK' . date('YmdHis');
            $paymentType = trim($_POST['payment_type'] ?? 'nakit');
            if (!in_array($paymentType, ['nakit', 'kart', 'iban', 'veresiye'], true)) {
                $paymentType = 'nakit';
            }

            ensureSalesPaymentColumns();
            ensureDiscountColumns();
            $quoteTotalForSale = (float)$quote['grand_total'];
            $quoteDiscountForSale = (float)($quote['discount_total'] ?? 0);
            $quoteDiscountRateForSale = (float)($quote['discount_rate'] ?? 0);
            $quoteDiscountAmountForSale = (float)($quote['discount_amount'] ?? 0);
            $quotePaidForSale = $paymentType === 'veresiye' ? 0.0 : $quoteTotalForSale;
            $quoteRemainingForSale = max($quoteTotalForSale - $quotePaidForSale, 0);

            $stmt = db()->prepare("
                INSERT INTO sales (sale_no, customer_id, customer_name, total_amount, discount_total, discount_rate, discount_amount, paid_amount, remaining_amount, payment_type, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $saleNo,
                $quote['customer_id'] ?: null,
                $quote['customer_name'],
                $quoteTotalForSale,
                $quoteDiscountForSale,
                $quoteDiscountRateForSale,
                $quoteDiscountAmountForSale,
                $quotePaidForSale,
                $quoteRemainingForSale,
                $paymentType,
                'Tekliften satışa çevrildi: ' . $quote['quote_no'],
                currentUser()['id'] ?? null,
            ]);

            $saleId = (int)db()->lastInsertId();

            $saleItemStmt = db()->prepare("
                INSERT INTO sale_items
                (sale_id, product_id, product_name, quantity, unit_price, discount_rate, discount_amount, line_discount, vat_rate, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stockUpdateStmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $movementStmt = db()->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                VALUES (?, 'sale', ?, ?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                if (!$item['product_id']) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$item['product_id']]);
                $product = $stmt->fetch();

                $oldStock = (float)$product['stock'];
                $quantity = (float)$item['quantity'];
                $newStock = $oldStock - $quantity;

                $saleItemStmt->execute([
                    $saleId,
                    (int)$item['product_id'],
                    $item['product_name'],
                    $quantity,
                    (float)$item['unit_price'],
                    (float)($item['discount_rate'] ?? 0),
                    (float)($item['discount_amount'] ?? 0),
                    (float)($item['line_discount'] ?? 0),
                    (float)$item['vat_rate'],
                    (float)$item['line_total'],
                ]);

                $stockUpdateStmt->execute([$newStock, (int)$item['product_id']]);

                $movementStmt->execute([
                    (int)$item['product_id'],
                    $quantity,
                    $oldStock,
                    $newStock,
                    'Teklif satışa çevrildi: ' . $quote['quote_no'] . ' / Satış: ' . $saleNo,
                    currentUser()['id'] ?? null,
                ]);
            }

            if ($quoteRemainingForSale > 0 && $quote['customer_id']) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$quoteRemainingForSale, (int)$quote['customer_id']]);
                addCustomerTransaction((int)$quote['customer_id'], 'satis_borcu', $quoteRemainingForSale, 'Tekliften satış kalan borcu: ' . $saleNo, $paymentType, 'sale', $saleId);
            }

            $stmt = db()->prepare("UPDATE quotes SET status = 'converted' WHERE id = ?");
            $stmt->execute([$id]);

            db()->commit();
            redirect('sales');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}



/*
|--------------------------------------------------------------------------
| TEZGAH / PASİF SATIŞ POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'passive_sales') {
    ensureSalesPaymentColumns();
    ensureDiscountColumns();
    ensureStockMovementsTable();

    $action = $_POST['action'] ?? '';


    if ($action === 'delete_passive_sale' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);

        try {
            db()->beginTransaction();

            $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? AND note LIKE '[PASIF_SATIS]%' FOR UPDATE");
            $stmt->execute([$id]);
            $sale = $stmt->fetch();

            if (!$sale) {
                throw new RuntimeException('Tezgah satışı bulunamadı.');
            }

            $stmt = db()->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll();

            foreach ($items as $item) {
                if (empty($item['product_id'])) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$item['product_id']]);
                $product = $stmt->fetch();

                if (!$product) {
                    continue;
                }

                $oldStock = (float)$product['stock'];
                $newStock = $oldStock + (float)$item['quantity'];

                $stmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
                $stmt->execute([$newStock, (int)$item['product_id']]);

                $stmt = db()->prepare("
                    INSERT INTO stock_movements
                    (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                    VALUES (?, 'manual', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    (int)$item['product_id'],
                    (float)$item['quantity'],
                    $oldStock,
                    $newStock,
                    'Tezgah satışı silindi, stok geri eklendi: ' . $sale['sale_no'],
                    currentUser()['id'] ?? null,
                ]);
            }

            $stmt = db()->prepare("DELETE FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$id]);

            $stmt = db()->prepare("DELETE FROM sales WHERE id = ?");
            $stmt->execute([$id]);

            db()->commit();
            redirect('passive_sales');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    if ($action === 'update_passive_sale' && isAdmin()) {
        $saleId = (int)($_POST['sale_id'] ?? 0);
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $itemDiscountRates = $_POST['item_discount_rate'] ?? [];
        $itemDiscountAmounts = $_POST['item_discount_amount'] ?? [];
        $paymentType = $_POST['payment_type'] ?? 'nakit';
        $paidAmountRaw = trim((string)($_POST['paid_amount'] ?? ''));
        $noteExtra = trim((string)($_POST['note'] ?? ''));

        if (!is_array($productIds)) {
            $productIds = [$productIds];
        }

        if (!is_array($quantities)) {
            $quantities = [$quantities];
        }

        if (!is_array($unitPrices)) {
            $unitPrices = [$unitPrices];
        }

        if (!is_array($itemDiscountRates)) {
            $itemDiscountRates = [$itemDiscountRates];
        }

        if (!is_array($itemDiscountAmounts)) {
            $itemDiscountAmounts = [$itemDiscountAmounts];
        }

        if (!in_array($paymentType, ['nakit', 'kart', 'iban', 'veresiye'], true)) {
            $paymentType = 'nakit';
        }

        try {
            db()->beginTransaction();

            $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? AND note LIKE '[PASIF_SATIS]%' FOR UPDATE");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();

            if (!$sale) {
                throw new RuntimeException('Düzenlenecek tezgah satışı bulunamadı.');
            }

            $stmt = db()->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$saleId]);
            $oldItems = $stmt->fetchAll();

            foreach ($oldItems as $oldItem) {
                if (empty($oldItem['product_id'])) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$oldItem['product_id']]);
                $product = $stmt->fetch();

                if (!$product) {
                    continue;
                }

                $oldStock = (float)$product['stock'];
                $newStock = $oldStock + (float)$oldItem['quantity'];

                $stmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
                $stmt->execute([$newStock, (int)$oldItem['product_id']]);

                $stmt = db()->prepare("
                    INSERT INTO stock_movements
                    (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                    VALUES (?, 'manual', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    (int)$oldItem['product_id'],
                    (float)$oldItem['quantity'],
                    $oldStock,
                    $newStock,
                    'Tezgah satışı düzenleme öncesi stok iadesi: ' . $sale['sale_no'],
                    currentUser()['id'] ?? null,
                ]);
            }

            $stmt = db()->prepare("DELETE FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$saleId]);

            $itemsToInsert = [];
            $grandTotal = 0.0;
            $lineDiscountTotal = 0.0;

            foreach ($productIds as $index => $productIdRaw) {
                $productId = (int)$productIdRaw;
                $quantity = isset($quantities[$index]) ? (float)$quantities[$index] : 0;

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Ürün bulunamadı.');
                }

                $currentStock = (float)$product['stock'];

                if ($currentStock < $quantity) {
                    throw new RuntimeException('Yetersiz stok: ' . $product['name'] . ' / Mevcut: ' . number_format($currentStock, 2, ',', '.') . ' ' . ($product['unit'] ?? ''));
                }

                $postedUnitPrice = isset($unitPrices[$index]) ? (float)$unitPrices[$index] : 0;
                $postedDiscountRate = isset($itemDiscountRates[$index]) ? (float)$itemDiscountRates[$index] : 0;
                $postedDiscountAmount = isset($itemDiscountAmounts[$index]) ? cleanMoneyValue((string)$itemDiscountAmounts[$index]) : 0;
                $unitPrice = $postedUnitPrice > 0 ? $postedUnitPrice : (float)$product['sale_price'];
                $vatRate = (float)($product['vat_rate'] ?? 0);
                $discountRate = max(0, min($postedDiscountRate, 100));
                $lineBase = $quantity * $unitPrice;
                $linePercentDiscount = $lineBase * ($discountRate / 100);
                $discountAmount = max(0, min($postedDiscountAmount, max($lineBase - $linePercentDiscount, 0)));
                $lineDiscount = $linePercentDiscount + $discountAmount;
                $lineTotal = max($lineBase - $lineDiscount, 0);

                $lineDiscountTotal += $lineDiscount;
                $grandTotal += $lineTotal;

                $itemsToInsert[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_discount' => $lineDiscount,
                    'vat_rate' => $vatRate,
                    'line_total' => $lineTotal,
                    'old_stock' => $currentStock,
                    'new_stock' => $currentStock - $quantity,
                ];
            }

            if (!$itemsToInsert) {
                throw new RuntimeException('Tezgah satışına en az 1 ürün eklemelisin.');
            }

            if ($paidAmountRaw === '') {
                $paidAmount = $paymentType === 'veresiye' ? 0.0 : $grandTotal;
            } else {
                $paidAmount = cleanMoneyValue($paidAmountRaw);
            }

            $paidAmount = max(0, min($paidAmount, $grandTotal));
            $remainingAmount = max($grandTotal - $paidAmount, 0);
            $note = '[PASIF_SATIS] Günlük tezgah satışı';

            if ($noteExtra !== '') {
                $note .= ' - ' . $noteExtra;
            }

            $stmt = db()->prepare("
                UPDATE sales SET
                    customer_id = NULL,
                    customer_name = 'İsimsiz Müşteri',
                    total_amount = ?,
                    discount_total = ?,
                    discount_rate = 0,
                    discount_amount = 0,
                    paid_amount = ?,
                    remaining_amount = ?,
                    payment_type = ?,
                    note = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $grandTotal,
                $lineDiscountTotal,
                $paidAmount,
                $remainingAmount,
                $paymentType,
                $note,
                $saleId,
            ]);

            $saleItemStmt = db()->prepare("
                INSERT INTO sale_items
                (sale_id, product_id, product_name, quantity, unit_price, discount_rate, discount_amount, line_discount, vat_rate, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stockUpdateStmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $movementStmt = db()->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                VALUES (?, 'sale', ?, ?, ?, ?, ?)
            ");

            foreach ($itemsToInsert as $item) {
                $saleItemStmt->execute([
                    $saleId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount_rate'],
                    $item['discount_amount'],
                    $item['line_discount'],
                    $item['vat_rate'],
                    $item['line_total'],
                ]);

                $stockUpdateStmt->execute([$item['new_stock'], $item['product_id']]);

                $movementStmt->execute([
                    $item['product_id'],
                    $item['quantity'],
                    $item['old_stock'],
                    $item['new_stock'],
                    'Tezgah satışı güncellendi: ' . $sale['sale_no'],
                    currentUser()['id'] ?? null,
                ]);
            }

            db()->commit();
            redirect('passive_sales');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    if ($action === 'add_passive_sale') {
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $itemDiscountRates = $_POST['item_discount_rate'] ?? [];
        $itemDiscountAmounts = $_POST['item_discount_amount'] ?? [];
        $paymentType = $_POST['payment_type'] ?? 'nakit';
        $paidAmountRaw = trim((string)($_POST['paid_amount'] ?? ''));
        $noteExtra = trim((string)($_POST['note'] ?? ''));

        if (!is_array($productIds)) {
            $productIds = [$productIds];
        }

        if (!is_array($quantities)) {
            $quantities = [$quantities];
        }

        if (!is_array($unitPrices)) {
            $unitPrices = [$unitPrices];
        }

        if (!in_array($paymentType, ['nakit', 'kart', 'iban', 'veresiye'], true)) {
            $paymentType = 'nakit';
        }

        try {
            db()->beginTransaction();

            $itemsToInsert = [];
            $grandTotal = 0.0;
            $lineDiscountTotal = 0.0;

            foreach ($productIds as $index => $productIdRaw) {
                $productId = (int)$productIdRaw;
                $quantity = isset($quantities[$index]) ? (float)$quantities[$index] : 0;

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Ürün bulunamadı.');
                }

                $currentStock = (float)$product['stock'];

                if ($currentStock < $quantity) {
                    throw new RuntimeException('Yetersiz stok: ' . $product['name'] . ' / Mevcut: ' . number_format($currentStock, 2, ',', '.') . ' ' . ($product['unit'] ?? ''));
                }

                $postedUnitPrice = isset($unitPrices[$index]) ? (float)$unitPrices[$index] : 0;
                $postedDiscountRate = isset($itemDiscountRates[$index]) ? (float)$itemDiscountRates[$index] : 0;
                $postedDiscountAmount = isset($itemDiscountAmounts[$index]) ? cleanMoneyValue((string)$itemDiscountAmounts[$index]) : 0;
                $unitPrice = $postedUnitPrice > 0 ? $postedUnitPrice : (float)$product['sale_price'];
                $vatRate = (float)($product['vat_rate'] ?? 0);
                $discountRate = max(0, min($postedDiscountRate, 100));
                $lineBase = $quantity * $unitPrice;
                $linePercentDiscount = $lineBase * ($discountRate / 100);
                $discountAmount = max(0, min($postedDiscountAmount, max($lineBase - $linePercentDiscount, 0)));
                $lineDiscount = $linePercentDiscount + $discountAmount;
                $lineTotal = max($lineBase - $lineDiscount, 0);

                $lineDiscountTotal += $lineDiscount;
                $grandTotal += $lineTotal;

                $itemsToInsert[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_discount' => $lineDiscount,
                    'vat_rate' => $vatRate,
                    'line_total' => $lineTotal,
                    'old_stock' => $currentStock,
                    'new_stock' => $currentStock - $quantity,
                ];
            }

            if (!$itemsToInsert) {
                throw new RuntimeException('Tezgah satışına en az 1 ürün eklemelisin.');
            }

            if ($paidAmountRaw === '') {
                $paidAmount = $paymentType === 'veresiye' ? 0.0 : $grandTotal;
            } else {
                $paidAmount = cleanMoneyValue($paidAmountRaw);
            }

            $paidAmount = max(0, min($paidAmount, $grandTotal));
            $remainingAmount = max($grandTotal - $paidAmount, 0);
            $saleNo = 'TP' . date('YmdHis');
            $note = '[PASIF_SATIS] Günlük tezgah satışı';

            if ($noteExtra !== '') {
                $note .= ' - ' . $noteExtra;
            }

            $stmt = db()->prepare("
                INSERT INTO sales (sale_no, customer_id, customer_name, total_amount, discount_total, discount_rate, discount_amount, paid_amount, remaining_amount, payment_type, note, created_by)
                VALUES (?, NULL, 'İsimsiz Müşteri', ?, ?, 0, 0, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $saleNo,
                $grandTotal,
                $lineDiscountTotal,
                $paidAmount,
                $remainingAmount,
                $paymentType,
                $note,
                currentUser()['id'] ?? null,
            ]);

            $saleId = (int)db()->lastInsertId();

            $saleItemStmt = db()->prepare("
                INSERT INTO sale_items
                (sale_id, product_id, product_name, quantity, unit_price, discount_rate, discount_amount, line_discount, vat_rate, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stockUpdateStmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $movementStmt = db()->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                VALUES (?, 'sale', ?, ?, ?, ?, ?)
            ");

            foreach ($itemsToInsert as $item) {
                $saleItemStmt->execute([
                    $saleId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount_rate'],
                    $item['discount_amount'],
                    $item['line_discount'],
                    $item['vat_rate'],
                    $item['line_total'],
                ]);

                $stockUpdateStmt->execute([$item['new_stock'], $item['product_id']]);

                $movementStmt->execute([
                    $item['product_id'],
                    $item['quantity'],
                    $item['old_stock'],
                    $item['new_stock'],
                    'Tezgah satışı: ' . $saleNo,
                    currentUser()['id'] ?? null,
                ]);
            }

            db()->commit();
            redirect('passive_sales');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| SATIŞ POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'sales') {
    ensureSalesPaymentColumns();
    ensureDiscountColumns();
    ensureStockMovementsTable();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_sale') {
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $itemDiscountRates = $_POST['item_discount_rate'] ?? [];
        $itemDiscountAmounts = $_POST['item_discount_amount'] ?? [];
        $saleDiscountRate = max(0, min((float)($_POST['sale_discount_rate'] ?? 0), 100));
        $saleDiscountAmount = cleanMoneyValue((string)($_POST['sale_discount_amount'] ?? '0'));

        if (!is_array($productIds)) {
            $productIds = [$productIds];
        }

        if (!is_array($quantities)) {
            $quantities = [$quantities];
        }

        if (!is_array($unitPrices)) {
            $unitPrices = [$unitPrices];
        }

        $customerId = $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : null;
        $customerName = trim($_POST['customer_name'] ?? '');
        $paymentType = $_POST['payment_type'] ?? 'nakit';
        $paidAmountRaw = trim((string)($_POST['paid_amount'] ?? ''));
        $note = trim($_POST['note'] ?? '');

        if (!in_array($paymentType, ['nakit', 'kart', 'iban', 'veresiye'], true)) {
            $paymentType = 'nakit';
        }

        try {
            db()->beginTransaction();

            if ($customerId) {
                $stmt = db()->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch();

                if ($customer) {
                    $customerName = $customer['name'];
                }
            }

            if ($customerName === '') {
                $customerName = 'Perakende Müşteri';
            }

            $itemsToInsert = [];
            $grandTotal = 0.0;
            $lineDiscountTotal = 0.0;

            foreach ($productIds as $index => $productIdRaw) {
                $productId = (int)$productIdRaw;
                $quantity = isset($quantities[$index]) ? (float)$quantities[$index] : 0;

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Ürün bulunamadı.');
                }

                $currentStock = (float)$product['stock'];

                if ($currentStock < $quantity) {
                    throw new RuntimeException('Yetersiz stok: ' . $product['name'] . ' / Mevcut: ' . number_format($currentStock, 2, ',', '.') . ' ' . ($product['unit'] ?? ''));
                }

                $postedUnitPrice = isset($unitPrices[$index]) ? (float)$unitPrices[$index] : 0;
                $postedDiscountRate = isset($itemDiscountRates[$index]) ? (float)$itemDiscountRates[$index] : 0;
                $postedDiscountAmount = isset($itemDiscountAmounts[$index]) ? cleanMoneyValue((string)$itemDiscountAmounts[$index]) : 0;
                $unitPrice = $postedUnitPrice > 0 ? $postedUnitPrice : (float)$product['sale_price'];
                $vatRate = (float)($product['vat_rate'] ?? 0);
                $discountRate = max(0, min($postedDiscountRate, 100));
                $lineBase = $quantity * $unitPrice;
                $linePercentDiscount = $lineBase * ($discountRate / 100);
                $discountAmount = max(0, min($postedDiscountAmount, max($lineBase - $linePercentDiscount, 0)));
                $lineDiscount = $linePercentDiscount + $discountAmount;
                $lineTotal = max($lineBase - $lineDiscount, 0);

                $lineDiscountTotal += $lineDiscount;
                $grandTotal += $lineTotal;

                $itemsToInsert[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_discount' => $lineDiscount,
                    'vat_rate' => $vatRate,
                    'line_total' => $lineTotal,
                    'old_stock' => $currentStock,
                    'new_stock' => $currentStock - $quantity,
                ];
            }

            if (!$itemsToInsert) {
                throw new RuntimeException('Satışa en az 1 ürün eklemelisin.');
            }

            $globalPercentDiscount = $grandTotal * ($saleDiscountRate / 100);
            $globalAmountDiscount = max(0, min($saleDiscountAmount, max($grandTotal - $globalPercentDiscount, 0)));
            $globalDiscount = $globalPercentDiscount + $globalAmountDiscount;
            $discountTotal = $lineDiscountTotal + $globalDiscount;
            $grandTotal = max($grandTotal - $globalDiscount, 0);

            if ($paidAmountRaw === '') {
                $paidAmount = $paymentType === 'veresiye' ? 0.0 : $grandTotal;
            } else {
                $paidAmount = cleanMoneyValue($paidAmountRaw);
            }

            if ($paidAmount < 0) {
                $paidAmount = 0.0;
            }

            if ($paidAmount > $grandTotal) {
                $paidAmount = $grandTotal;
            }

            $remainingAmount = max($grandTotal - $paidAmount, 0);
            $saleNo = 'MK' . date('YmdHis');

            $stmt = db()->prepare("
                INSERT INTO sales (sale_no, customer_id, customer_name, total_amount, discount_total, discount_rate, discount_amount, paid_amount, remaining_amount, payment_type, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $saleNo,
                $customerId,
                $customerName,
                $grandTotal,
                $discountTotal,
                $saleDiscountRate,
                $globalAmountDiscount,
                $paidAmount,
                $remainingAmount,
                $paymentType,
                $note,
                currentUser()['id'] ?? null,
            ]);

            $saleId = (int)db()->lastInsertId();

            $saleItemStmt = db()->prepare("
                INSERT INTO sale_items 
                (sale_id, product_id, product_name, quantity, unit_price, discount_rate, discount_amount, line_discount, vat_rate, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stockUpdateStmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $movementStmt = db()->prepare("
                INSERT INTO stock_movements 
                (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                VALUES (?, 'sale', ?, ?, ?, ?, ?)
            ");

            foreach ($itemsToInsert as $item) {
                $saleItemStmt->execute([
                    $saleId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount_rate'],
                    $item['discount_amount'],
                    $item['line_discount'],
                    $item['vat_rate'],
                    $item['line_total'],
                ]);

                $stockUpdateStmt->execute([$item['new_stock'], $item['product_id']]);

                $movementStmt->execute([
                    $item['product_id'],
                    $item['quantity'],
                    $item['old_stock'],
                    $item['new_stock'],
                    'Satış kaydı: ' . $saleNo,
                    currentUser()['id'] ?? null,
                ]);
            }

            if ($remainingAmount > 0 && $customerId) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$remainingAmount, $customerId]);
                addCustomerTransaction($customerId, 'satis_borcu', $remainingAmount, 'Satış kalan borcu: ' . $saleNo, $paymentType, 'sale', $saleId);
            }

            db()->commit();
            redirect('sales');

        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    if ($action === 'update_sale' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);
        $customerId = $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : null;
        $customerName = trim($_POST['customer_name'] ?? '');
        $paymentType = $_POST['payment_type'] ?? 'nakit';
        $paidAmount = cleanMoneyValue((string)($_POST['paid_amount'] ?? '0'));
        $note = trim($_POST['note'] ?? '');

        if (!in_array($paymentType, ['nakit', 'kart', 'iban', 'veresiye'], true)) {
            $paymentType = 'nakit';
        }

        try {
            db()->beginTransaction();

            $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $sale = $stmt->fetch();

            if (!$sale) {
                throw new RuntimeException('Satış bulunamadı.');
            }

            if ($customerId) {
                $stmt = db()->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch();

                if ($customer) {
                    $customerName = $customer['name'];
                }
            }

            if ($customerName === '') {
                $customerName = $sale['customer_name'] ?: 'Perakende Müşteri';
            }

            $totalAmount = (float)$sale['total_amount'];

            if ($paidAmount < 0) {
                $paidAmount = 0.0;
            }

            if ($paidAmount > $totalAmount) {
                $paidAmount = $totalAmount;
            }

            $oldRemaining = (float)($sale['remaining_amount'] ?? 0);
            $newRemaining = max($totalAmount - $paidAmount, 0);

            if (!empty($sale['customer_id']) && $oldRemaining > 0) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$oldRemaining, (int)$sale['customer_id']]);
                addCustomerTransaction((int)$sale['customer_id'], 'satis_duzeltme_alacak', $oldRemaining, 'Satış güncelleme eski kalan borç iptali: ' . $sale['sale_no'], $paymentType, 'sale', $id);
            }

            if ($customerId && $newRemaining > 0) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$newRemaining, $customerId]);
                addCustomerTransaction($customerId, 'satis_duzeltme_borc', $newRemaining, 'Satış güncelleme yeni kalan borç: ' . $sale['sale_no'], $paymentType, 'sale', $id);
            }

            $stmt = db()->prepare("
                UPDATE sales SET
                    customer_id = ?,
                    customer_name = ?,
                    paid_amount = ?,
                    remaining_amount = ?,
                    payment_type = ?,
                    note = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $customerId,
                $customerName,
                $paidAmount,
                $newRemaining,
                $paymentType,
                $note,
                $id,
            ]);

            db()->commit();
            redirect('sales');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    if ($action === 'delete_sale' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);

        try {
            db()->beginTransaction();

            $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $sale = $stmt->fetch();

            if (!$sale) {
                throw new RuntimeException('Satış bulunamadı.');
            }

            $stmt = db()->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll();

            foreach ($items as $item) {
                if (!$item['product_id']) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$item['product_id']]);
                $product = $stmt->fetch();

                if ($product) {
                    $oldStock = (float)$product['stock'];
                    $newStock = $oldStock + (float)$item['quantity'];

                    $stmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
                    $stmt->execute([$newStock, (int)$item['product_id']]);

                    $stmt = db()->prepare("
                        INSERT INTO stock_movements 
                        (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                        VALUES (?, 'manual', ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        (int)$item['product_id'],
                        (float)$item['quantity'],
                        $oldStock,
                        $newStock,
                        'Satış silindi, stok geri eklendi: ' . $sale['sale_no'],
                        currentUser()['id'] ?? null,
                    ]);
                }
            }

            $remainingAmount = (float)($sale['remaining_amount'] ?? 0);
            if ($remainingAmount > 0 && !empty($sale['customer_id'])) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$remainingAmount, (int)$sale['customer_id']]);
                addCustomerTransaction((int)$sale['customer_id'], 'satis_silme_alacak', $remainingAmount, 'Satış silindi, kalan borç iptal: ' . $sale['sale_no'], $sale['payment_type'] ?? '', 'sale', $id);
            }

            $stmt = db()->prepare("DELETE FROM sales WHERE id = ?");
            $stmt->execute([$id]);

            db()->commit();
            redirect('sales');

        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}



/*
|--------------------------------------------------------------------------
| KULLANICI YÖNETİMİ POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'settings' && isAdmin()) {
    ensureUserManagementColumns();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_user') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = ($_POST['role'] ?? 'personel') === 'admin' ? 'admin' : 'personel';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0 && $name !== '' && $username !== '') {
            if ($id === (int)(currentUser()['id'] ?? 0)) {
                $role = 'admin';
                $isActive = 1;
            }

            try {
                $stmt = db()->prepare("\n                    UPDATE users SET\n                        name = ?,\n                        username = ?,\n                        role = ?,\n                        is_active = ?\n                    WHERE id = ?\n                ");
                $stmt->execute([$name, $username, $role, $isActive, $id]);
            } catch (Throwable $e) {
                redirect('settings', 'user_error=' . urlencode('Kullanıcı güncellenemedi. Kullanıcı adı başka bir kullanıcıda olabilir.'));
            }
        }

        redirect('settings');
    }

    if ($action === 'change_user_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = trim($_POST['password'] ?? '');

        if ($id > 0 && $password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
        }

        redirect('settings');
    }

    if ($action === 'toggle_user_status') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0 && $id !== (int)(currentUser()['id'] ?? 0)) {
            $stmt = db()->prepare("UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
            $stmt->execute([$id]);
        }

        redirect('settings');
    }

    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0 && $id !== (int)(currentUser()['id'] ?? 0)) {
            $stmt = db()->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
        }

        redirect('settings');
    }
}

/*
|--------------------------------------------------------------------------
| AYARLAR POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'settings' && isAdmin()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_settings') {
        $settings = [
            'company_name' => trim($_POST['company_name'] ?? 'MK Denizcilik'),
            'usd_rate' => trim($_POST['usd_rate'] ?? '0'),
            'eur_rate' => trim($_POST['eur_rate'] ?? '0'),
            'critical_stock_default' => trim($_POST['critical_stock_default'] ?? '3'),
            'bank_line_1' => trim($_POST['bank_line_1'] ?? 'TEB BANKASI: TR36 0003 2000 0000 0018 0425 34'),
            'bank_line_2' => trim($_POST['bank_line_2'] ?? 'VAKIFBANK: TR79 0001 5001 5800 7322 6017 55'),
            'bank_line_3' => trim($_POST['bank_line_3'] ?? 'GARANTİ BANKASI: TR78 0006 2000 2050 0006 2868 99'),
            'bank_note' => trim($_POST['bank_note'] ?? 'Ödeme sonrası dekont iletiniz.'),
        ];

        foreach ($settings as $key => $value) {
            $stmt = db()->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
        }

        redirect('settings');
    }

    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'] === 'admin' ? 'admin' : 'personel';

        if ($name && $username && $password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = db()->prepare("
                INSERT INTO users (name, username, password_hash, role)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $username, $hash, $role]);
        }

        redirect('settings');
    }
}


/*
|--------------------------------------------------------------------------
| ERP İÇİ RAPOR / EXPORT / YEDEK / BARKOD SAYFALARI
|--------------------------------------------------------------------------
*/

function mkExcelBackupContent(array $headers, array $rows): string
{
    $out = "\xFF\xFE";

    $writeLine = function (array $cols): string {
        $safe = [];
        foreach ($cols as $v) {
            $v = (string)$v;
            $v = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $v);
            $safe[] = $v;
        }

        return mb_convert_encoding(implode("\t", $safe) . "\r\n", 'UTF-16LE', 'UTF-8');
    };

    $out .= $writeLine($headers);

    foreach ($rows as $row) {
        $out .= $writeLine(array_values($row));
    }

    return $out;
}

function mkDownloadExcel(string $filename, array $headers, array $rows): never
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-16LE');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo mkExcelBackupContent($headers, $rows);
    exit;
}

function mkBackupDir(): string
{
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function mkSqlBackupContent(): string
{
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $sql = "-- MK Denizcilik ERP SQL Backup\n";
    $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $tableRow) {
        $table = (string)$tableRow[0];
        $safeTable = str_replace('`', '``', $table);
        $create = db()->query("SHOW CREATE TABLE `{$safeTable}`")->fetch(PDO::FETCH_ASSOC);
        $createValues = array_values($create ?: []);
        $createSql = $createValues[1] ?? '';

        $sql .= "DROP TABLE IF EXISTS `{$safeTable}`;\n";
        $sql .= $createSql . ";\n\n";

        $rows = db()->query("SELECT * FROM `{$safeTable}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = [];
            $vals = [];

            foreach ($row as $col => $value) {
                $cols[] = '`' . str_replace('`', '``', (string)$col) . '`';
                $vals[] = $value === null ? 'NULL' : db()->quote((string)$value);
            }

            $sql .= "INSERT INTO `{$safeTable}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
        }

        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

function mkRunSqlImport(string $sql): void
{
    $sql = trim($sql);
    if ($sql === '') {
        throw new RuntimeException('Import dosyası boş.');
    }

    db()->exec('SET FOREIGN_KEY_CHECKS=0');
    db()->exec($sql);
    db()->exec('SET FOREIGN_KEY_CHECKS=1');
}

function mkBarcodeValue(array $p): string
{
    $v = trim((string)($p['barcode'] ?? ''));
    if ($v === '') {
        $v = trim((string)($p['stock_code'] ?? ''));
    }
    if ($v === '') {
        $v = 'MKP' . (int)$p['id'];
    }

    $v = preg_replace('/[^A-Za-z0-9\-\.\/ ]+/', '', $v);
    return $v !== '' ? $v : ('MKP' . (int)$p['id']);
}

if ($page === 'export' && isAdmin() && isset($_GET['type'])) {
    $type = trim((string)$_GET['type']);

    if ($type === 'products') {
        $rows = db()->query("SELECT id, name, stock_code, barcode, category, purchase_price, purchase_currency, purchase_price_try, sale_price, sale_currency, sale_price_original, vat_rate, stock, unit, min_stock, created_at FROM products ORDER BY id DESC")->fetchAll();
        mkDownloadExcel('urunler.xls', ['ID','Ürün Adı','Stok Kodu','Barkod','Kategori','Alış','Alış Para','Alış TL','Satış TL','Satış Para','Satış Orijinal','KDV','Stok','Birim','Kritik Stok','Tarih'], $rows);
    }

    if ($type === 'customers') {
        $rows = db()->query("SELECT id, name, phone, email, address, tax_office, tax_number, balance, created_at FROM customers ORDER BY id DESC")->fetchAll();
        mkDownloadExcel('musteriler.xls', ['ID','Müşteri','Telefon','E-posta','Adres','Vergi Dairesi','Vergi No','Bakiye','Tarih'], $rows);
    }

    if ($type === 'sales') {
        $rows = db()->query("SELECT id, sale_no, customer_name, total_amount, discount_total, paid_amount, remaining_amount, payment_type, note, created_at FROM sales ORDER BY id DESC")->fetchAll();
        mkDownloadExcel('satislar.xls', ['ID','Satış No','Müşteri','Toplam','İskonto','Ödenen','Kalan','Ödeme Tipi','Not','Tarih'], $rows);
    }

    if ($type === 'sale_items') {
        $rows = db()->query("SELECT si.id, s.sale_no, s.customer_name, si.product_name, si.quantity, si.unit_price, si.discount_rate, si.discount_amount, si.line_discount, si.vat_rate, si.line_total, s.created_at FROM sale_items si LEFT JOIN sales s ON s.id = si.sale_id ORDER BY si.id DESC")->fetchAll();
        mkDownloadExcel('satis_kalemleri.xls', ['ID','Satış No','Müşteri','Ürün','Miktar','Birim Fiyat','İsk.%','İsk.₺','Satır İsk.','KDV','Satır Toplam','Tarih'], $rows);
    }

    if ($type === 'customer_transactions') {
        ensureCustomerTransactionsTable();
        $rows = db()->query("SELECT ct.id, c.name AS customer_name, ct.transaction_type, ct.amount, ct.balance_after, ct.payment_type, ct.description, ct.created_at FROM customer_transactions ct LEFT JOIN customers c ON c.id = ct.customer_id ORDER BY ct.id DESC")->fetchAll();
        mkDownloadExcel('cari_hareketler.xls', ['ID','Müşteri','İşlem','Tutar','İşlem Sonrası Bakiye','Ödeme Tipi','Açıklama','Tarih'], $rows);
    }

    redirect('export');
}

if ($page === 'backup' && isAdmin() && isset($_GET['download'])) {
    $file = basename((string)$_GET['download']);
    $path = mkBackupDir() . '/' . $file;
    if (is_file($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = $ext === 'zip' ? 'application/zip' : ($ext === 'sql' ? 'application/sql; charset=UTF-8' : 'application/octet-stream');
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    redirect('backup');
}

if ($page === 'backup' && isAdmin() && isset($_GET['delete'])) {
    $file = basename((string)$_GET['delete']);
    $path = mkBackupDir() . '/' . $file;
    if (is_file($path)) {
        unlink($path);
    }
    redirect('backup');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'backup' && isAdmin()) {
    $backupAction = $_POST['action'] ?? '';

    if ($backupAction === 'create_full_backup') {
        try {
            $backupDir = mkBackupDir();
            $stamp = date('Ymd_His');
            $baseName = 'mk_erp_yedek_' . $stamp;
            $workDir = $backupDir . '/' . $baseName;
            if (!is_dir($workDir)) {
                mkdir($workDir, 0755, true);
            }

            file_put_contents($workDir . '/veritabani.sql', mkSqlBackupContent());

            $excelSets = [
                'urunler.xls' => [
                    ['ID','Ürün Adı','Stok Kodu','Barkod','Kategori','Alış','Alış Para','Alış TL','Satış TL','Satış Para','Satış Orijinal','KDV','Stok','Birim','Kritik Stok','Tarih'],
                    db()->query("SELECT id, name, stock_code, barcode, category, purchase_price, purchase_currency, purchase_price_try, sale_price, sale_currency, sale_price_original, vat_rate, stock, unit, min_stock, created_at FROM products ORDER BY id DESC")->fetchAll(),
                ],
                'musteriler.xls' => [
                    ['ID','Müşteri','Telefon','E-posta','Adres','Vergi Dairesi','Vergi No','Bakiye','Tarih'],
                    db()->query("SELECT id, name, phone, email, address, tax_office, tax_number, balance, created_at FROM customers ORDER BY id DESC")->fetchAll(),
                ],
                'satislar.xls' => [
                    ['ID','Satış No','Müşteri','Toplam','İskonto','Ödenen','Kalan','Ödeme Tipi','Not','Tarih'],
                    db()->query("SELECT id, sale_no, customer_name, total_amount, discount_total, paid_amount, remaining_amount, payment_type, note, created_at FROM sales ORDER BY id DESC")->fetchAll(),
                ],
                'satis_kalemleri.xls' => [
                    ['ID','Satış No','Müşteri','Ürün','Miktar','Birim Fiyat','İsk.%','İsk.₺','Satır İsk.','KDV','Satır Toplam','Tarih'],
                    db()->query("SELECT si.id, s.sale_no, s.customer_name, si.product_name, si.quantity, si.unit_price, si.discount_rate, si.discount_amount, si.line_discount, si.vat_rate, si.line_total, s.created_at FROM sale_items si LEFT JOIN sales s ON s.id = si.sale_id ORDER BY si.id DESC")->fetchAll(),
                ],
            ];

            foreach ($excelSets as $filename => $data) {
                file_put_contents($workDir . '/' . $filename, mkExcelBackupContent($data[0], $data[1]));
            }

            $createdFile = '';
            if (class_exists('ZipArchive')) {
                $zipName = $baseName . '.zip';
                $zipPath = $backupDir . '/' . $zipName;
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    foreach (glob($workDir . '/*') ?: [] as $filePath) {
                        $zip->addFile($filePath, basename($filePath));
                    }
                    $zip->close();
                    $createdFile = $zipName;
                }
            }

            if ($createdFile === '') {
                $createdFile = $baseName . '_veritabani.sql';
                copy($workDir . '/veritabani.sql', $backupDir . '/' . $createdFile);
            }

            redirect('backup', 'backup_ok=' . urlencode('Yedek oluşturuldu: ' . $createdFile));
        } catch (Throwable $e) {
            redirect('backup', 'backup_error=' . urlencode($e->getMessage()));
        }
    }

    if ($backupAction === 'import_sql_backup') {
        try {
            if (!isset($_FILES['sql_file']) || ($_FILES['sql_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('SQL dosyası yüklenemedi.');
            }

            $name = (string)($_FILES['sql_file']['name'] ?? '');
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'sql') {
                throw new RuntimeException('Sadece .sql dosyası import edilebilir.');
            }

            $tmp = (string)$_FILES['sql_file']['tmp_name'];
            $sql = file_get_contents($tmp);
            if ($sql === false) {
                throw new RuntimeException('SQL dosyası okunamadı.');
            }

            mkRunSqlImport($sql);
            redirect('backup', 'backup_ok=' . urlencode('Veritabanı import tamamlandı.'));
        } catch (Throwable $e) {
            redirect('backup', 'backup_error=' . urlencode($e->getMessage()));
        }
    }
}

if ($page === 'barcode' && isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'print_barcodes') {
    $ids = $_POST['product_id'] ?? [];
    $counts = $_POST['count'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }

    $cleanIds = [];
    foreach ($ids as $idRaw) {
        $id = (int)$idRaw;
        if ($id > 0) {
            $cleanIds[] = $id;
        }
    }

    $products = [];
    if ($cleanIds) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($placeholders) ORDER BY name ASC");
        $stmt->execute($cleanIds);
        foreach ($stmt->fetchAll() as $p) {
            $c = (int)($counts[(string)$p['id']] ?? 1);
            $c = max(1, min($c, 100));
            for ($i = 0; $i < $c; $i++) {
                $products[] = $p;
            }
        }
    }
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1.0">
        <title>Barkod Etiket Yazdır</title>
        <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
        <style>
            *{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;background:#f3f4f6;color:#111}.toolbar{position:sticky;top:0;background:#111827;color:#fff;padding:12px;display:flex;gap:8px;align-items:center;z-index:10}.toolbar button{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}.sheet{padding:10mm;display:grid;grid-template-columns:repeat(3,64mm);gap:4mm;align-items:start}.label{width:64mm;height:34mm;background:#fff;border:1px dashed #d1d5db;border-radius:4px;padding:3mm;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden;break-inside:avoid}.brand{font-size:9px;font-weight:800}.name{font-size:10px;font-weight:800;line-height:1.1;min-height:22px;max-height:24px;overflow:hidden}.meta{display:flex;justify-content:space-between;font-size:8px;gap:5px}.price{font-size:11px;font-weight:900;white-space:nowrap}.barcode svg{width:100%;height:38px}@media print{body{background:#fff}.toolbar{display:none}.sheet{padding:0;gap:2mm;grid-template-columns:repeat(3,64mm)}.label{border:0;border-radius:0;page-break-inside:avoid}}
        </style>
    </head>
    <body>
        <div class="toolbar"><button onclick="window.print()">🖨️ Yazdır</button><button onclick="history.back()">← Geri Dön</button><strong><?= count($products) ?> etiket</strong></div>
        <main class="sheet">
            <?php foreach ($products as $idx => $p): $code = mkBarcodeValue($p); ?>
                <div class="label">
                    <div class="brand">MK Denizcilik</div>
                    <div class="name"><?= e($p['name'] ?? '') ?></div>
                    <div class="meta"><span><?= e($p['stock_code'] ?? '') ?></span><span class="price"><?= number_format((float)($p['sale_price'] ?? 0), 2, ',', '.') ?> ₺</span></div>
                    <div class="barcode"><svg id="bc<?= (int)$idx ?>" data-code="<?= e($code) ?>"></svg></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$products): ?><p>Etiket seçilmedi.</p><?php endif; ?>
        </main>
        <script>
            document.querySelectorAll('svg[data-code]').forEach(function(el){try{JsBarcode(el,el.dataset.code,{format:'CODE128',displayValue:true,fontSize:10,height:34,margin:0});}catch(e){el.outerHTML='<div style="font-size:10px">Barkod üretilemedi</div>';}});
        </script>
    </body>
    </html>
    <?php
    exit;
}

if ($page === 'reports' && isAdmin()) {
    renderHeader('Raporlar');

    $start = trim((string)($_GET['start'] ?? date('Y-m-01')));
    $end = trim((string)($_GET['end'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = date('Y-m-d');
    }

    $salesStmt = db()->prepare("SELECT COUNT(*) AS sale_count, COALESCE(SUM(total_amount),0) AS total_sales, COALESCE(SUM(paid_amount),0) AS paid_total, COALESCE(SUM(remaining_amount),0) AS remaining_total, COALESCE(SUM(discount_total),0) AS discount_total FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
    $salesStmt->execute([$start, $end]);
    $salesSummary = $salesStmt->fetch();

    $paymentStmt = db()->prepare("SELECT payment_type, COUNT(*) AS sale_count, COALESCE(SUM(total_amount),0) AS total_amount FROM sales WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY payment_type ORDER BY total_amount DESC");
    $paymentStmt->execute([$start, $end]);
    $paymentRows = $paymentStmt->fetchAll();

    $topStmt = db()->prepare("SELECT si.product_name, COALESCE(SUM(si.quantity),0) AS qty, COALESCE(SUM(si.line_total),0) AS total_amount FROM sale_items si INNER JOIN sales s ON s.id = si.sale_id WHERE DATE(s.created_at) BETWEEN ? AND ? GROUP BY si.product_name ORDER BY qty DESC, total_amount DESC LIMIT 25");
    $topStmt->execute([$start, $end]);
    $topProducts = $topStmt->fetchAll();

    $recentStmt = db()->prepare("SELECT sale_no, customer_name, total_amount, paid_amount, remaining_amount, payment_type, created_at FROM sales WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC LIMIT 100");
    $recentStmt->execute([$start, $end]);
    $recentSales = $recentStmt->fetchAll();

    $debtorCustomers = db()->query("SELECT name, phone, balance FROM customers WHERE balance > 0 ORDER BY balance DESC LIMIT 50")->fetchAll();
    $lowStocks = db()->query("SELECT name, stock_code, stock, unit, min_stock FROM products WHERE stock <= min_stock ORDER BY stock ASC LIMIT 50")->fetchAll();
    ?>

    <section class="panel">
        <div class="section-title"><div><p>Rapor Merkezi</p><h2>Tarih Aralığı</h2></div></div>
        <form method="get" class="grid-form">
            <input type="hidden" name="page" value="reports">
            <div><label>Başlangıç</label><input type="date" name="start" value="<?= e($start) ?>"></div>
            <div><label>Bitiş</label><input type="date" name="end" value="<?= e($end) ?>"></div>
            <button type="submit">Raporu Getir</button>
            <button type="button" onclick="window.print()">Yazdır</button>
        </form>
    </section>

    <section class="cards dashboard-stats">
        <div class="card"><span>Satış Adedi</span><strong><?= (int)$salesSummary['sale_count'] ?></strong></div>
        <div class="card"><span>Toplam Satış</span><strong><?= number_format((float)$salesSummary['total_sales'], 2, ',', '.') ?> ₺</strong></div>
        <div class="card"><span>Ödenen</span><strong><?= number_format((float)$salesSummary['paid_total'], 2, ',', '.') ?> ₺</strong></div>
        <div class="card danger"><span>Kalan</span><strong><?= number_format((float)$salesSummary['remaining_total'], 2, ',', '.') ?> ₺</strong></div>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Özet</p><h2>Ödeme Tipi Özeti</h2></div></div>
        <div class="table-wrap"><table><thead><tr><th>Ödeme Tipi</th><th>Satış Adedi</th><th>Tutar</th></tr></thead><tbody>
            <?php foreach ($paymentRows as $row): ?><tr><td><?= e($row['payment_type'] ?? '-') ?></td><td><?= (int)$row['sale_count'] ?></td><td><?= number_format((float)$row['total_amount'], 2, ',', '.') ?> ₺</td></tr><?php endforeach; ?>
            <?php if (!$paymentRows): ?><tr><td colspan="3">Kayıt yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Ürün</p><h2>En Çok Satan Ürünler</h2></div></div>
        <div class="table-wrap"><table><thead><tr><th>Ürün</th><th>Miktar</th><th>Tutar</th></tr></thead><tbody>
            <?php foreach ($topProducts as $p): ?><tr><td><?= e($p['product_name']) ?></td><td><?= number_format((float)$p['qty'], 2, ',', '.') ?></td><td><?= number_format((float)$p['total_amount'], 2, ',', '.') ?> ₺</td></tr><?php endforeach; ?>
            <?php if (!$topProducts): ?><tr><td colspan="3">Kayıt yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Satış</p><h2>Son Satışlar</h2></div></div>
        <div class="table-wrap"><table><thead><tr><th>No</th><th>Müşteri</th><th>Toplam</th><th>Ödenen</th><th>Kalan</th><th>Ödeme</th><th>Tarih</th></tr></thead><tbody>
            <?php foreach ($recentSales as $sale): ?>
                <tr><td><?= e($sale['sale_no']) ?></td><td><?= e($sale['customer_name']) ?></td><td><?= number_format((float)$sale['total_amount'], 2, ',', '.') ?> ₺</td><td><?= number_format((float)$sale['paid_amount'], 2, ',', '.') ?> ₺</td><td><?= number_format((float)$sale['remaining_amount'], 2, ',', '.') ?> ₺</td><td><?= e($sale['payment_type']) ?></td><td><?= e($sale['created_at']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$recentSales): ?><tr><td colspan="7">Kayıt yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="dashboard-grid">
        <div class="panel"><div class="section-title"><div><p>Cari</p><h2>Borçlu Müşteriler</h2></div></div><div class="table-wrap compact-table"><table><thead><tr><th>Müşteri</th><th>Telefon</th><th>Bakiye</th></tr></thead><tbody><?php foreach ($debtorCustomers as $c): ?><tr><td><?= e($c['name']) ?></td><td><?= e($c['phone'] ?? '') ?></td><td><?= number_format((float)$c['balance'], 2, ',', '.') ?> ₺</td></tr><?php endforeach; ?><?php if (!$debtorCustomers): ?><tr><td colspan="3">Borçlu müşteri yok.</td></tr><?php endif; ?></tbody></table></div></div>
        <div class="panel"><div class="section-title"><div><p>Stok</p><h2>Kritik Stoklar</h2></div></div><div class="table-wrap compact-table"><table><thead><tr><th>Ürün</th><th>Kod</th><th>Stok</th><th>Kritik</th></tr></thead><tbody><?php foreach ($lowStocks as $p): ?><tr><td><?= e($p['name']) ?></td><td><?= e($p['stock_code']) ?></td><td><?= number_format((float)$p['stock'], 2, ',', '.') ?> <?= e($p['unit']) ?></td><td><?= number_format((float)$p['min_stock'], 2, ',', '.') ?></td></tr><?php endforeach; ?><?php if (!$lowStocks): ?><tr><td colspan="4">Kritik stok yok.</td></tr><?php endif; ?></tbody></table></div></div>
    </section>
    <?php
    renderFooter();
    exit;
}

if ($page === 'export' && isAdmin()) {
    renderHeader('Excel Export');
    ?>
    <section class="panel">
        <div class="section-title"><div><p>Dışa Aktarım</p><h2>Excel Export Merkezi</h2></div></div>
        <div class="alert">Excel çıktıları Türkçe karakter bozulmasın diye Excel uyumlu UTF-16LE formatında oluşturulur.</div>
        <div class="module-grid">
            <a href="index.php?page=export&type=products" class="module-card"><div class="module-icon">Ü</div><div><strong>Ürünler</strong><span>Stok, fiyat, barkod, kategori</span></div></a>
            <a href="index.php?page=export&type=customers" class="module-card"><div class="module-icon">M</div><div><strong>Müşteriler</strong><span>Cari müşteri listesi</span></div></a>
            <a href="index.php?page=export&type=sales" class="module-card"><div class="module-icon">S</div><div><strong>Satışlar</strong><span>Satış başlık kayıtları</span></div></a>
            <a href="index.php?page=export&type=sale_items" class="module-card"><div class="module-icon">K</div><div><strong>Satış Kalemleri</strong><span>Ürün bazlı satışlar</span></div></a>
            <a href="index.php?page=export&type=customer_transactions" class="module-card"><div class="module-icon">C</div><div><strong>Cari Hareketler</strong><span>Tahsilat ve borç hareketleri</span></div></a>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

if ($page === 'backup' && isAdmin()) {
    renderHeader('Yedekleme');
    $backupOk = trim((string)($_GET['backup_ok'] ?? ''));
    $backupError = trim((string)($_GET['backup_error'] ?? ''));
    $backupFiles = glob(mkBackupDir() . '/*.{zip,sql}', GLOB_BRACE) ?: [];
    rsort($backupFiles);
    ?>
    <?php if ($backupOk !== ''): ?><section class="panel"><div class="alert"><?= e($backupOk) ?></div></section><?php endif; ?>
    <?php if ($backupError !== ''): ?><section class="panel"><div class="alert error"><?= e($backupError) ?></div></section><?php endif; ?>

    <section class="panel">
        <div class="section-title"><div><p>Yedekleme</p><h2>SQL + Excel Yedek Paketi</h2></div></div>
        <p>Bu işlem veritabanının SQL yedeğini ve ürün/müşteri/satış Excel yedeklerini aynı paket içinde oluşturur.</p>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="create_full_backup">
            <button type="submit">Tam Yedek Oluştur</button>
        </form>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Import</p><h2>Veritabanı Import</h2></div></div>
        <div class="alert error">Dikkat: SQL import mevcut tabloları değiştirebilir. Import öncesi mutlaka yeni yedek al.</div>
        <form method="post" enctype="multipart/form-data" class="grid-form" onsubmit="return confirm('SQL import yapılacak. Öncesinde yedek aldığından emin misin?')">
            <input type="hidden" name="action" value="import_sql_backup">
            <div class="full"><label>SQL Yedek Dosyası</label><input type="file" name="sql_file" accept=".sql" required></div>
            <button type="submit" class="danger-btn">SQL Import Başlat</button>
        </form>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Geçmiş</p><h2>Yedek Dosyaları</h2></div></div>
        <div class="table-wrap"><table><thead><tr><th>Dosya</th><th>Boyut</th><th>Tarih</th><th>İşlem</th></tr></thead><tbody>
            <?php foreach ($backupFiles as $path): $file = basename($path); ?>
                <tr><td><?= e($file) ?></td><td><?= number_format(filesize($path) / 1024, 2, ',', '.') ?> KB</td><td><?= e(date('d.m.Y H:i:s', filemtime($path))) ?></td><td><div style="display:flex;gap:8px;flex-wrap:wrap;"><a class="small" href="index.php?page=backup&download=<?= urlencode($file) ?>">İndir</a><a class="small danger-btn" href="index.php?page=backup&delete=<?= urlencode($file) ?>" onclick="return confirm('Yedek silinsin mi?')">Sil</a></div></td></tr>
            <?php endforeach; ?>
            <?php if (!$backupFiles): ?><tr><td colspan="4">Henüz yedek yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <?php
    renderFooter();
    exit;
}

if ($page === 'barcode' && isAdmin()) {
    renderHeader('Barkod / Etiket');
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 500);
    if (!in_array($limit, [120, 250, 500, 1000, 0], true)) {
        $limit = 500;
    }
    $limitSql = $limit > 0 ? ' LIMIT ' . $limit : '';
    $totalProducts = (int)db()->query("SELECT COUNT(*) FROM products")->fetchColumn();

    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = db()->prepare("SELECT * FROM products WHERE name LIKE ? OR stock_code LIKE ? OR barcode LIKE ? OR category LIKE ? ORDER BY name ASC" . $limitSql);
        $stmt->execute([$like, $like, $like, $like]);
        $products = $stmt->fetchAll();
        $countStmt = db()->prepare("SELECT COUNT(*) FROM products WHERE name LIKE ? OR stock_code LIKE ? OR barcode LIKE ? OR category LIKE ?");
        $countStmt->execute([$like, $like, $like, $like]);
        $matchingProducts = (int)$countStmt->fetchColumn();
    } else {
        $products = db()->query("SELECT * FROM products ORDER BY id DESC" . $limitSql)->fetchAll();
        $matchingProducts = $totalProducts;
    }
    $shownProducts = count($products);
    ?>
    <section class="cards dashboard-stats">
        <div class="card"><span>Toplam Ürün</span><strong><?= (int)$totalProducts ?></strong></div>
        <div class="card"><span>Eşleşen Ürün</span><strong><?= (int)$matchingProducts ?></strong></div>
        <div class="card"><span>Gösterilen Ürün</span><strong><?= (int)$shownProducts ?></strong></div>
        <div class="card"><span>Limit</span><strong><?= $limit === 0 ? 'Tümü' : (int)$limit ?></strong></div>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Arama</p><h2>Barkod / Etiket</h2></div></div>
        <form method="get" class="grid-form">
            <input type="hidden" name="page" value="barcode">
            <div class="full"><label>Ürün adı / stok kodu / barkod / kategori</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Ürün ara..."></div>
            <div><label>Limit</label><select name="limit"><option value="120" <?= $limit===120?'selected':'' ?>>120</option><option value="250" <?= $limit===250?'selected':'' ?>>250</option><option value="500" <?= $limit===500?'selected':'' ?>>500</option><option value="1000" <?= $limit===1000?'selected':'' ?>>1000</option><option value="0" <?= $limit===0?'selected':'' ?>>Tümü</option></select></div>
            <button type="submit">Ara / Uygula</button>
            <button type="button" onclick="window.location.href='index.php?page=barcode'">Temizle</button>
        </form>
    </section>

    <form method="post">
        <input type="hidden" name="action" value="print_barcodes">
        <section class="panel">
            <div class="section-title"><div><p>Liste</p><h2>Ürün Etiketleri</h2></div><div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" onclick="document.querySelectorAll('.product-check').forEach(c=>c.checked=true)">Tümünü Seç</button><button type="button" onclick="document.querySelectorAll('.product-check').forEach(c=>c.checked=false)">Seçimi Kaldır</button><button type="submit">Seçilenleri Yazdır</button></div></div>
            <div class="table-wrap"><table><thead><tr><th>Seç</th><th>Adet</th><th>Ürün</th><th>Stok Kodu</th><th>Barkod</th><th>Kategori</th><th>Fiyat</th><th>Stok</th></tr></thead><tbody>
                <?php foreach ($products as $p): $code = mkBarcodeValue($p); ?>
                    <tr><td><input class="product-check" type="checkbox" name="product_id[]" value="<?= (int)$p['id'] ?>"></td><td><input style="width:74px;" type="number" min="1" max="100" name="count[<?= (int)$p['id'] ?>]" value="1"></td><td><?= e($p['name'] ?? '') ?><div style="color:var(--muted);font-size:12px;font-weight:800;">Kod: <?= e($code) ?></div></td><td><?= e($p['stock_code'] ?? '') ?></td><td><?= e($p['barcode'] ?? '') ?></td><td><?= e($p['category'] ?? '') ?></td><td><?= number_format((float)($p['sale_price'] ?? 0), 2, ',', '.') ?> ₺</td><td><?= e((string)($p['stock'] ?? '0')) ?> <?= e($p['unit'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$products): ?><tr><td colspan="8">Ürün bulunamadı.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </form>
    <?php
    renderFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if ($page === 'login'):
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> - Giriş</title>
    <link rel="stylesheet" href="assets/style.css?v=14">
</head>
<body class="login-body login-intro-body">
    <div class="mk-intro-logo">
        <img src="assets/mk-logo.png" alt="MK Denizcilik">
    </div>

    <form class="login-card login-card-delayed" method="post" action="index.php?page=login">
        <div class="login-logo">
            <img src="assets/mk-logo.png" alt="MK Denizcilik">
        </div>

        <div class="login-heading">
            <p>MK Denizcilik</p>
            <h1>ERP Giriş Paneli</h1>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <label>Kullanıcı Adı</label>
        <input type="text" name="username" required autofocus autocomplete="username">

        <label>Şifre</label>
        <input type="password" name="password" required autocomplete="current-password">

        <button type="submit">Giriş Yap</button>
    </form>
</body>
</html>
<?php
exit;
endif;


/*
|--------------------------------------------------------------------------
| KRAL ASİSTAN
|--------------------------------------------------------------------------
*/

if ($page === 'kral') {
    ensureCustomerTransactionsTable();
    ensureStockMovementsTable();
    renderHeader('KRAL Asistan');

    $q = trim((string)($_GET['q'] ?? ''));
    $cmd = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);

    $todaySales = (float)db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $todayPassiveSales = (float)db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at) = CURDATE() AND note LIKE '[PASIF_SATIS]%'")->fetchColumn();
    $monthSales = (float)db()->query("
        SELECT COALESCE(SUM(total_amount),0)
        FROM sales
        WHERE YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ")->fetchColumn();

    $todayCollection = 0.0;
    try {
        $todayCollection = (float)db()->query("
            SELECT COALESCE(SUM(amount),0)
            FROM customer_transactions
            WHERE transaction_type = 'tahsilat'
              AND DATE(created_at) = CURDATE()
        ")->fetchColumn();
    } catch (Throwable $e) {
        $todayCollection = 0.0;
    }

    $lowStockCount = (int)db()->query("SELECT COUNT(*) FROM products WHERE stock <= min_stock")->fetchColumn();
    $debtCustomerCount = (int)db()->query("SELECT COUNT(*) FROM customers WHERE balance > 0")->fetchColumn();
    $debtTotal = (float)db()->query("SELECT COALESCE(SUM(balance),0) FROM customers WHERE balance > 0")->fetchColumn();
    $productCount = (int)db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $customerCount = (int)db()->query("SELECT COUNT(*) FROM customers")->fetchColumn();

    $answerText = 'Selam kanka, KRAL hazır. Bana satış, stok, müşteri, hava durumu veya ürün adı yazabilirsin.';
    $resultRows = [];
    $resultType = '';
    $redirectTo = '';
    $weather = null;

    function kralContainsAny(string $cmd, array $words): bool
    {
        foreach ($words as $word) {
            if (strpos($cmd, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    function kralFetchWeather(): array
    {
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=41.1340&longitude=29.0900&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m&timezone=Europe%2FIstanbul';

        $json = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $json = curl_exec($ch);
            curl_close($ch);
        }

        if ($json === false && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => ['timeout' => 8],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $json = @file_get_contents($url, false, $context);
        }

        if (!$json) {
            throw new RuntimeException('Hava durumu servisine bağlanamadım.');
        }

        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['current'])) {
            throw new RuntimeException('Hava durumu cevabı okunamadı.');
        }

        $current = $data['current'];
        $code = (int)($current['weather_code'] ?? -1);

        $map = [
            0 => 'açık',
            1 => 'genelde açık',
            2 => 'parçalı bulutlu',
            3 => 'kapalı',
            45 => 'sisli',
            48 => 'kırağılı sis',
            51 => 'hafif çisenti',
            53 => 'çisenti',
            55 => 'yoğun çisenti',
            61 => 'hafif yağmur',
            63 => 'yağmur',
            65 => 'kuvvetli yağmur',
            71 => 'hafif kar',
            73 => 'kar',
            75 => 'kuvvetli kar',
            80 => 'hafif sağanak',
            81 => 'sağanak',
            82 => 'kuvvetli sağanak',
            95 => 'gök gürültülü',
        ];

        return [
            'desc' => $map[$code] ?? 'bilinmiyor',
            'temp' => (float)($current['temperature_2m'] ?? 0),
            'feel' => (float)($current['apparent_temperature'] ?? 0),
            'humidity' => (float)($current['relative_humidity_2m'] ?? 0),
            'wind' => (float)($current['wind_speed_10m'] ?? 0),
        ];
    }

    if ($q !== '') {

        $customKralAnswers = [
            'naber' => 'İyiyim kanka, KRAL aktif. Ne kontrol edelim?',
            'nasılsın' => 'İyiyim kanka, KRAL aktif. Ne kontrol edelim?',
            'nasilsin' => 'İyiyim kanka, KRAL aktif. Ne kontrol edelim?',
            'patron kim' => 'MK Denizcilik’in kaptanı belli kral 😄',
            'bugün ne yapalım' => 'Önce kritik stoklara bak, sonra tezgah satışını kontrol et kanka.',
            'bugun ne yapalim' => 'Önce kritik stoklara bak, sonra tezgah satışını kontrol et kanka.',
            'serhat' => 'Kral, İmparator, Lüferlerin Hükümdarı.',
            'kerem' => '... :(',
            'ibo' => 'Asimile olmuş efsane.',
            'ibrahim' => 'Asimile olmuş efsane.',
            'engin' => 'Altılıda beşte kalan Engin.',
            'necmi' => 'Rakıefendi Tarikatı Şeyhi.',
            'sebo' => 'Kuş, Atatürk.',
            'samet' => 'Mega farklı düşünen.',
            'gökmen' => 'Kral.',
            'gokmen' => 'Kral.',
        ];

        foreach ($customKralAnswers as $customKey => $customAnswer) {
            if (strpos($cmd, $customKey) !== false) {
                $answerText = $customAnswer;
                $resultType = 'chat';
                break;
            }
        }

        if ($resultType === '') {
        if (kralContainsAny($cmd, ['selam', 'merhaba', 'sa', 'hello'])) {
            $answerText = 'Selam kanka 😄 KRAL aktif. Satış, stok, müşteri, hava durumu ne lazımsa yaz.';
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['naber', 'napıyorsun', 'napiyon', 'nasılsın', 'iyi misin'])) {
            $answerText = 'İyiyim kral, sistem ayakta. Bugünkü satış ' . number_format($todaySales, 2, ',', '.') . ' ₺. Ne kontrol edelim?';
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['teşekkür', 'tesekkur', 'sağ ol', 'sag ol', 'eyvallah'])) {
            $answerText = 'Eyvallah kanka. ERP’de işler karışırsa yaz, KRAL burda.';
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['hava', 'yağmur', 'yagmur', 'sıcaklık', 'sicaklik', 'rüzgar', 'ruzgar'])) {
            try {
                $weather = kralFetchWeather();
                $answerText = 'Beykoz hava durumu: ' . $weather['desc'] . '. Sıcaklık ' . number_format($weather['temp'], 1, ',', '.') . ' °C, hissedilen ' . number_format($weather['feel'], 1, ',', '.') . ' °C. Nem %' . number_format($weather['humidity'], 0, ',', '.') . ', rüzgar ' . number_format($weather['wind'], 1, ',', '.') . ' km/s.';
                $resultType = 'chat';
            } catch (Throwable $e) {
                $answerText = 'Hava durumunu çekemedim kanka. Hosting dış bağlantıyı engelliyor olabilir.';
                $resultType = 'chat';
            }
        } elseif (kralContainsAny($cmd, ['sistem', 'özet', 'ozet', 'durum'])) {
            $answerText = 'Sistem özeti: ' . (int)$productCount . ' ürün, ' . (int)$customerCount . ' müşteri, bugünkü satış ' . number_format($todaySales, 2, ',', '.') . ' ₺, kritik stok ' . (int)$lowStockCount . ', borçlu müşteri ' . (int)$debtCustomerCount . '.';
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['bugün', 'bugünkü', 'ciro', 'kasa', 'satış', 'satis'])) {
            if (kralContainsAny($cmd, ['son satış', 'son satis', 'son 10', 'son 20'])) {
                $answerText = 'Son satışları listeledim kral.';
                $stmt = db()->query("
                    SELECT sale_no, customer_name, total_amount, paid_amount, remaining_amount, payment_type, created_at
                    FROM sales
                    ORDER BY id DESC
                    LIMIT 10
                ");
                $resultRows = $stmt->fetchAll();
                $resultType = 'sales';
            } else {
                $answerText = 'Bugünkü satış ' . number_format($todaySales, 2, ',', '.') . ' ₺. Tezgah satışı ' . number_format($todayPassiveSales, 2, ',', '.') . ' ₺. Tahsilat ' . number_format($todayCollection, 2, ',', '.') . ' ₺.';
                $resultType = 'chat';
            }
        } elseif (kralContainsAny($cmd, ['tezgah', 'pasif'])) {
            $answerText = 'Bugünkü tezgah satışı ' . number_format($todayPassiveSales, 2, ',', '.') . ' ₺ kanka.';
            if (kralContainsAny($cmd, ['git', 'aç', 'ac'])) {
                $redirectTo = 'index.php?page=passive_sales';
            }
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['kritik', 'stok'])) {
            $answerText = 'Kritik stokta ' . (int)$lowStockCount . ' ürün var kral. İlk ürünleri aşağıya bıraktım.';
            $stmt = db()->query("
                SELECT name, stock_code, stock, unit, min_stock, sale_price
                FROM products
                WHERE stock <= min_stock
                ORDER BY stock ASC, name ASC
                LIMIT 12
            ");
            $resultRows = $stmt->fetchAll();
            $resultType = 'products';
        } elseif (kralContainsAny($cmd, ['borç', 'borc', 'cari', 'alacak', 'müşteri', 'musteri'])) {
            $answerText = 'Borçlu müşteri sayısı ' . (int)$debtCustomerCount . ', toplam cari borç ' . number_format($debtTotal, 2, ',', '.') . ' ₺. En yüksek bakiyeleri listeledim.';
            $stmt = db()->query("
                SELECT id, name, phone, balance
                FROM customers
                WHERE balance > 0
                ORDER BY balance DESC, name ASC
                LIMIT 12
            ");
            $resultRows = $stmt->fetchAll();
            $resultType = 'customers';
        } elseif (kralContainsAny($cmd, ['satışa git', 'satisa git', 'satış aç', 'satis ac'])) {
            $answerText = 'Tamam kral, satış ekranına geçebilirsin.';
            $redirectTo = 'index.php?page=sales';
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['ürünlere git', 'urunlere git', 'ürün aç', 'urun ac'])) {
            $answerText = 'Tamam kral, ürün ekranına geçebilirsin.';
            $redirectTo = 'index.php?page=products';
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['müşterilere git', 'musterilere git', 'müşteri aç', 'musteri ac'])) {
            $answerText = 'Tamam kanka, müşteri ekranına geçebilirsin.';
            $redirectTo = 'index.php?page=customers';
            $resultType = 'chat';
        } elseif (kralContainsAny($cmd, ['yedek', 'backup'])) {
            $answerText = 'Yedekleme ekranını açabilirsin kral. SQL + Excel yedek almayı unutma.';
            $redirectTo = 'index.php?page=backup';
            $resultType = 'chat';
        } else {
            $like = '%' . $q . '%';

            $stmt = db()->prepare("
                SELECT id, name, stock_code, category, stock, unit, sale_price
                FROM products
                WHERE name LIKE ?
                   OR stock_code LIKE ?
                   OR barcode LIKE ?
                   OR category LIKE ?
                ORDER BY name ASC
                LIMIT 8
            ");
            $stmt->execute([$like, $like, $like, $like]);
            $products = $stmt->fetchAll();

            $stmt = db()->prepare("
                SELECT id, name, phone, balance
                FROM customers
                WHERE name LIKE ?
                   OR phone LIKE ?
                   OR email LIKE ?
                   OR tax_number LIKE ?
                ORDER BY name ASC
                LIMIT 8
            ");
            $stmt->execute([$like, $like, $like, $like]);
            $customers = $stmt->fetchAll();

            $answerText = '"' . $q . '" için ürün ve müşteri kayıtlarını taradım kanka.';
            $resultRows = [
                'products' => $products,
                'customers' => $customers,
            ];
            $resultType = 'mixed';
        }
    }
    }
?>

    <style>
        .kral-chat-page {
            min-height: calc(100vh - 160px);
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .kral-chat-shell {
            flex: 1;
            min-height: 560px;
            display: grid;
            grid-template-rows: auto 1fr auto;
            border: 1px solid rgba(56, 189, 248, .22);
            border-radius: 28px;
            overflow: hidden;
            background:
                radial-gradient(circle at 15% 0%, rgba(56, 189, 248, .16), transparent 34%),
                radial-gradient(circle at 92% 5%, rgba(250, 204, 21, .10), transparent 30%),
                rgba(15, 23, 42, .46);
            box-shadow: 0 26px 90px rgba(0, 0, 0, .28);
        }

        .kral-chat-head {
            padding: 18px 20px;
            border-bottom: 1px solid rgba(148, 163, 184, .14);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: rgba(2, 6, 23, .26);
        }

        .kral-chat-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .kral-orb {
            width: 44px;
            height: 44px;
            border-radius: 999px;
            background: radial-gradient(circle, #facc15, #38bdf8 62%, rgba(56, 189, 248, .20));
            box-shadow: 0 0 30px rgba(56, 189, 248, .45);
            animation: kralPulse 1.7s ease-in-out infinite;
        }

        @keyframes kralPulse {
            0%, 100% { transform: scale(.94); opacity: .82; }
            50% { transform: scale(1.08); opacity: 1; }
        }

        .kral-chat-title strong {
            display: block;
            font-size: 19px;
            letter-spacing: -.04em;
            color: var(--text);
        }

        .kral-chat-title span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .kral-chat-body {
            padding: 20px;
            overflow: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .kral-message {
            max-width: 820px;
            border: 1px solid rgba(148, 163, 184, .14);
            border-radius: 22px;
            padding: 14px 16px;
            background: rgba(2, 6, 23, .36);
            box-shadow: 0 16px 42px rgba(0, 0, 0, .16);
        }

        .kral-message.user {
            align-self: flex-end;
            background: rgba(250, 204, 21, .11);
            border-color: rgba(250, 204, 21, .22);
        }

        .kral-message.assistant {
            align-self: flex-start;
            background:
                linear-gradient(135deg, rgba(56, 189, 248, .10), transparent),
                rgba(15, 23, 42, .50);
            border-color: rgba(56, 189, 248, .20);
        }

        .kral-message small {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .kral-message p {
            margin: 0;
            color: var(--text);
            line-height: 1.55;
            font-size: 14px;
            font-weight: 700;
        }

        .kral-result-table {
            margin-top: 12px;
            max-width: 100%;
            overflow: auto;
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, .12);
        }

        .kral-result-table table {
            min-width: 720px;
        }

        .kral-chat-foot {
            padding: 16px;
            border-top: 1px solid rgba(148, 163, 184, .14);
            background: rgba(2, 6, 23, .22);
        }

        .kral-suggestions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .kral-suggestions a {
            text-decoration: none;
            color: var(--text);
            border: 1px solid rgba(148, 163, 184, .18);
            background: rgba(15, 23, 42, .52);
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 850;
        }

        .kral-suggestions a:hover {
            border-color: rgba(56, 189, 248, .35);
            background: rgba(56, 189, 248, .12);
        }

        .kral-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
        }

        .kral-form input {
            min-height: 54px;
            border-radius: 18px;
            border-color: rgba(56, 189, 248, .34);
            font-size: 15px;
            font-weight: 800;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, .08);
        }

        .kral-form button {
            min-height: 54px;
            border-radius: 18px;
            background: linear-gradient(135deg, #38bdf8, #facc15);
            color: #06111e;
            white-space: nowrap;
        }

        .kral-go-btn {
            margin-top: 12px;
            display: inline-flex;
            text-decoration: none;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            padding: 10px 13px;
            font-weight: 900;
            color: #06111e;
            background: linear-gradient(135deg, #38bdf8, #facc15);
        }

        @media (max-width: 760px) {
            .kral-chat-shell {
                min-height: 640px;
            }

            .kral-form {
                grid-template-columns: 1fr;
            }

            .kral-chat-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .kral-message {
                max-width: 100%;
            }
        }
    </style>

    <section class="kral-chat-page">
        <div class="kral-chat-shell">
            <div class="kral-chat-head">
                <div class="kral-chat-title">
                    <div class="kral-orb"></div>
                    <div>
                        <strong>KRAL Sohbet</strong>
                        
                    </div>
                </div>

                <div class="role-badge">Online</div>
            </div>

            <div class="kral-chat-body" id="kralChatBody">
                <?php if ($q === ''): ?>
                    <div class="kral-message assistant">
                        <small>KRAL</small>
                        <p><?= e($answerText) ?></p>
                    </div>
                <?php else: ?>
                    <div class="kral-message user">
                        <small>Sen</small>
                        <p><?= e($q) ?></p>
                    </div>

                    <div class="kral-message assistant">
                        <small>KRAL</small>
                        <p><?= e($answerText) ?></p>

                        <?php if ($redirectTo !== ''): ?>
                            <a class="kral-go-btn" href="<?= e($redirectTo) ?>">Sayfaya Git</a>
                        <?php endif; ?>

                        <?php if ($resultType === 'products'): ?>
                            <div class="kral-result-table">
                                <table>
                                    <thead><tr><th>Ürün</th><th>Kod</th><th>Stok</th><th>Kritik</th><th>Satış</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($resultRows as $row): ?>
                                        <tr>
                                            <td><?= e($row['name']) ?></td>
                                            <td><?= e($row['stock_code'] ?? '') ?></td>
                                            <td><?= number_format((float)$row['stock'], 2, ',', '.') ?> <?= e($row['unit'] ?? '') ?></td>
                                            <td><?= number_format((float)$row['min_stock'], 2, ',', '.') ?></td>
                                            <td><?= number_format((float)$row['sale_price'], 2, ',', '.') ?> ₺</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$resultRows): ?><tr><td colspan="5">Kayıt bulunamadı.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($resultType === 'customers'): ?>
                            <div class="kral-result-table">
                                <table>
                                    <thead><tr><th>Müşteri</th><th>Telefon</th><th>Bakiye</th><th>İşlem</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($resultRows as $row): ?>
                                        <tr>
                                            <td><?= e($row['name']) ?></td>
                                            <td><?= e($row['phone'] ?? '') ?></td>
                                            <td><?= number_format((float)$row['balance'], 2, ',', '.') ?> ₺</td>
                                            <td><button type="button" class="small" onclick="window.location.href='index.php?page=customer_account&id=<?= (int)$row['id'] ?>'">Cari Detay</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$resultRows): ?><tr><td colspan="4">Kayıt bulunamadı.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($resultType === 'sales'): ?>
                            <div class="kral-result-table">
                                <table>
                                    <thead><tr><th>No</th><th>Müşteri</th><th>Toplam</th><th>Ödenen</th><th>Kalan</th><th>Ödeme</th><th>Tarih</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($resultRows as $row): ?>
                                        <tr>
                                            <td><?= e($row['sale_no']) ?></td>
                                            <td><?= e($row['customer_name']) ?></td>
                                            <td><?= number_format((float)$row['total_amount'], 2, ',', '.') ?> ₺</td>
                                            <td><?= number_format((float)$row['paid_amount'], 2, ',', '.') ?> ₺</td>
                                            <td><?= number_format((float)$row['remaining_amount'], 2, ',', '.') ?> ₺</td>
                                            <td><?= e($row['payment_type']) ?></td>
                                            <td><?= e($row['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$resultRows): ?><tr><td colspan="7">Kayıt bulunamadı.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($resultType === 'mixed'): ?>
                            <div class="kral-result-table">
                                <table>
                                    <thead><tr><th>Tip</th><th>Ad</th><th>Kod/Telefon</th><th>Stok/Bakiye</th><th>Fiyat</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($resultRows['products'] as $row): ?>
                                        <tr>
                                            <td>Ürün</td>
                                            <td><?= e($row['name']) ?></td>
                                            <td><?= e($row['stock_code'] ?? '') ?></td>
                                            <td><?= number_format((float)$row['stock'], 2, ',', '.') ?> <?= e($row['unit'] ?? '') ?></td>
                                            <td><?= number_format((float)$row['sale_price'], 2, ',', '.') ?> ₺</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($resultRows['customers'] as $row): ?>
                                        <tr>
                                            <td>Müşteri</td>
                                            <td><?= e($row['name']) ?></td>
                                            <td><?= e($row['phone'] ?? '') ?></td>
                                            <td><?= number_format((float)$row['balance'], 2, ',', '.') ?> ₺</td>
                                            <td>-</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$resultRows['products'] && !$resultRows['customers']): ?><tr><td colspan="5">Kayıt bulunamadı.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="kral-chat-foot">
                <form method="get" class="kral-form">
                    <input type="hidden" name="page" value="kral">
                    <input type="text" name="q" value="" placeholder="KRAL'a yaz... örn: naber, 3M ara, bugünkü satış, hava durumu" autofocus>
                    <button type="submit">Gönder</button>
                </form>
            </div>
        </div>
    </section>

    <script>
        (function () {
            var body = document.getElementById('kralChatBody');
            if (body) {
                body.scrollTop = body.scrollHeight;
            }
        })();
    </script>

    <?php
    renderFooter();
    exit;
}



/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/

if ($page === 'dashboard') {
    renderHeader('Güncel Durum');

    $todaySales = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    $monthSales = db()->query("
        SELECT COALESCE(SUM(total_amount),0)
        FROM sales
        WHERE YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ")->fetchColumn();

    $totalRevenue = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales")->fetchColumn();
    $todayPassiveSales = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at) = CURDATE() AND note LIKE '[PASIF_SATIS]%'")->fetchColumn();
    $customerCount = db()->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $productCount = db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $lowStockCount = db()->query("SELECT COUNT(*) FROM products WHERE stock <= min_stock")->fetchColumn();
    $debtTotal = db()->query("SELECT COALESCE(SUM(balance),0) FROM customers WHERE balance > 0")->fetchColumn();

    $recentSales = db()->query("
        SELECT sale_no, customer_name, total_amount, payment_type, created_at
        FROM sales
        ORDER BY id DESC
        LIMIT 6
    ")->fetchAll();

    $lowStocks = db()->query("
        SELECT name, stock, unit, min_stock
        FROM products
        WHERE stock <= min_stock
        ORDER BY stock ASC
        LIMIT 6
    ")->fetchAll();

    $usdRate = getSetting('usd_rate', '0');
    $eurRate = getSetting('eur_rate', '0');
    $currencyLastUpdate = getSetting('currency_last_update', '-');
    ?>
    <section class="dashboard-hero">
        <div>
            <p class="hero-eyebrow">MK Denizcilik ERP</p>
            <h2>Merhaba, <?= e(currentUser()['name'] ?? 'Admin') ?></h2>
            <p>Satış, müşteri, ürün, stok ve kur durumunu tek ekrandan takip edebilirsin.</p>
        </div>

        <div class="hero-actions">
            <button type="button" onclick="window.location.href='index.php?page=sales'">
                Satış Yap
            </button>

            <button type="button" onclick="window.location.href='index.php?page=passive_sales'">
                Tezgah Satışı
            </button>

            <?php if (isAdmin()): ?>
                <button type="button" onclick="window.location.href='live_currency_update.php'">
                    Kuru Güncelle
                </button>
            <?php endif; ?>
        </div>
    </section>

    <section class="cards dashboard-stats">
        <div class="card">
            <span>Bugünkü Satış</span>
            <strong><?= number_format((float)$todaySales, 2, ',', '.') ?> ₺</strong>
        </div>

        <div class="card">
            <span>Bugünkü Tezgah Satışı</span>
            <strong><?= number_format((float)$todayPassiveSales, 2, ',', '.') ?> ₺</strong>
        </div>

        <div class="card">
            <span>Bu Ay Satış</span>
            <strong><?= number_format((float)$monthSales, 2, ',', '.') ?> ₺</strong>
        </div>

        <div class="card">
            <span>Toplam Ciro</span>
            <strong><?= number_format((float)$totalRevenue, 2, ',', '.') ?> ₺</strong>
        </div>

        <div class="card">
            <span>Cari Borç Toplamı</span>
            <strong><?= number_format((float)$debtTotal, 2, ',', '.') ?> ₺</strong>
        </div>

        <div class="card danger">
            <span>Kritik Stok</span>
            <strong><?= (int)$lowStockCount ?></strong>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Modüller</p>
                <h2>Hızlı Erişim</h2>
            </div>
        </div>

        <div class="module-grid">
            <a href="index.php?page=kral" class="module-card">
                <div class="module-icon">K</div>
                <div>
                    <strong>KRAL Asistan</strong>
                    <span>Satış, stok, müşteri ve hızlı komut paneli</span>
                </div>
            </a>

            <a href="index.php?page=customers" class="module-card">
                <div class="module-icon">M</div>
                <div>
                    <strong>Müşteriler</strong>
                    <span>Müşteri ekle, ara, güncelle</span>
                </div>
            </a>

            <?php if (isAdmin()): ?>
                <a href="index.php?page=products" class="module-card">
                    <div class="module-icon">Ü</div>
                    <div>
                        <strong>Ürünler</strong>
                        <span>Stok, fiyat ve ürün yönetimi</span>
                    </div>
                </a>
            <?php endif; ?>

            <a href="index.php?page=sales" class="module-card">
                <div class="module-icon">S</div>
                <div>
                    <strong>Satış</strong>
                    <span>Ürünlü satış ve ödeme kaydı</span>
                </div>
            </a>

            <a href="index.php?page=passive_sales" class="module-card">
                <div class="module-icon">T</div>
                <div>
                    <strong>Tezgah Satışı</strong>
                    <span>İsimsiz günlük küçük satış listesi</span>
                </div>
            </a>

            <a href="index.php?page=payments" class="module-card">
                <div class="module-icon">₺</div>
                <div>
                    <strong>Tahsilat / Ödeme</strong>
                    <span>Hızlı tahsilat ve borçlu müşteriler</span>
                </div>
            </a>

            <a href="index.php?page=quotes" class="module-card">
                <div class="module-icon">T</div>
                <div>
                    <strong>Teklifler</strong>
                    <span>Çoklu ürünlü teklif oluştur</span>
                </div>
            </a>

            <?php if (isAdmin()): ?>
                <a href="product_import.php" class="module-card">
                    <div class="module-icon">X</div>
                    <div>
                        <strong>Ürün Import</strong>
                        <span>Excel ile ürün aktarımı</span>
                    </div>
                </a>

                <a href="customer_import.php" class="module-card">
                    <div class="module-icon">C</div>
                    <div>
                        <strong>Müşteri Import</strong>
                        <span>Excel ile müşteri aktarımı</span>
                    </div>
                </a>

                <a href="live_currency_update.php" class="module-card">
                    <div class="module-icon">₺</div>
                    <div>
                        <strong>Canlı Kur</strong>
                        <span>USD/EUR kuru güncelle</span>
                    </div>
                </a>

                <a href="index.php?page=backup" class="module-card">
                    <div class="module-icon">Y</div>
                    <div>
                        <strong>Yedekleme</strong>
                        <span>Veritabanı yedeği al ve indir</span>
                    </div>
                </a>

                <a href="index.php?page=reports" class="module-card">
                    <div class="module-icon">R</div>
                    <div>
                        <strong>Raporlar</strong>
                        <span>Satış, tahsilat, cari ve stok raporları</span>
                    </div>
                </a>

                <a href="index.php?page=export" class="module-card">
                    <div class="module-icon">E</div>
                    <div>
                        <strong>Excel Export</strong>
                        <span>Ürün, müşteri, satış ve cari Excel indir</span>
                    </div>
                </a>

                <a href="index.php?page=barcode" class="module-card">
                    <div class="module-icon">B</div>
                    <div>
                        <strong>Barkod / Etiket</strong>
                        <span>Ürün barkodu ve fiyat etiketi yazdır</span>
                    </div>
                </a>

                <a href="index.php?page=settings" class="module-card">
                    <div class="module-icon">A</div>
                    <div>
                        <strong>Ayarlar</strong>
                        <span>Kullanıcı, kur ve firma ayarları</span>
                    </div>
                </a>
            <?php endif; ?>
        </div>
    </section>

    <section class="dashboard-grid">
        <div class="panel">
            <div class="section-title">
                <div>
                    <p>Satış</p>
                    <h2>Son Satışlar</h2>
                </div>
            </div>

            <div class="table-wrap compact-table">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Müşteri</th>
                            <th>Tutar</th>
                            <th>Ödeme</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td><?= e($sale['sale_no']) ?></td>
                                <td><?= e($sale['customer_name']) ?></td>
                                <td><?= number_format((float)$sale['total_amount'], 2, ',', '.') ?> ₺</td>
                                <td><?= e($sale['payment_type']) ?></td>
                                <td><?= e($sale['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$recentSales): ?>
                            <tr>
                                <td colspan="5">Henüz satış yok.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="section-title">
                <div>
                    <p>Stok</p>
                    <h2>Kritik Stoklar</h2>
                </div>
            </div>

            <div class="table-wrap compact-table">
                <table>
                    <thead>
                        <tr>
                            <th>Ürün</th>
                            <th>Stok</th>
                            <th>Kritik</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStocks as $product): ?>
                            <tr>
                                <td><?= e($product['name']) ?></td>
                                <td><?= number_format((float)$product['stock'], 2, ',', '.') ?> <?= e($product['unit']) ?></td>
                                <td><?= number_format((float)$product['min_stock'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$lowStocks): ?>
                            <tr>
                                <td colspan="3">Kritik stok yok.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel currency-panel">
        <div class="section-title">
            <div>
                <p>Döviz</p>
                <h2>Kur Bilgisi</h2>
            </div>
        </div>

        <div class="currency-grid">
            <div>
                <span>USD</span>
                <strong><?= number_format((float)$usdRate, 4, ',', '.') ?> ₺</strong>
            </div>

            <div>
                <span>EUR</span>
                <strong><?= number_format((float)$eurRate, 4, ',', '.') ?> ₺</strong>
            </div>

            <div>
                <span>Son Güncelleme</span>
                <strong><?= e($currencyLastUpdate) ?></strong>
            </div>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| MÜŞTERİLER POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'customers') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_customer') {
        $stmt = db()->prepare("
            INSERT INTO customers (name, phone, email, address, tax_office, tax_number)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['tax_office'] ?? ''),
            trim($_POST['tax_number'] ?? ''),
        ]);

        redirect('customers');
    }

    if ($action === 'update_customer' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);

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
            trim($_POST['name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['tax_office'] ?? ''),
            trim($_POST['tax_number'] ?? ''),
            (float)($_POST['balance'] ?? 0),
            $id,
        ]);

        redirect('customers');
    }

    if ($action === 'delete_customer' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->execute([$id]);

        redirect('customers');
    }

    if ($action === 'delete_all_customers' && isAdmin()) {
        db()->exec("UPDATE sales SET customer_id = NULL");
        db()->exec("DELETE FROM customers");

        redirect('customers');
    }
}

/*
|--------------------------------------------------------------------------
| ÜRÜNLER POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'products' && isAdmin()) {
    ensureProductCurrencyColumns();
    ensureStockMovementsTable();

    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add_product', 'update_product'], true)) {
        $purchaseCurrency = normalizeCurrencyInput($_POST['purchase_currency'] ?? 'TRY');
        $saleCurrency = normalizeCurrencyInput($_POST['sale_currency'] ?? 'TRY');

        $purchasePrice = (float)($_POST['purchase_price'] ?? 0);
        $purchasePriceTry = convertToTry($purchasePrice, $purchaseCurrency);

        $salePriceOriginal = (float)($_POST['sale_price_original'] ?? 0);
        $salePriceTry = convertToTry($salePriceOriginal, $saleCurrency);
    }

    if ($action === 'add_product') {
        $stmt = db()->prepare("
            INSERT INTO products 
            (name, stock_code, barcode, category, purchase_price, purchase_currency, purchase_price_try, sale_price, sale_currency, sale_price_original, vat_rate, stock, unit, min_stock)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['stock_code'] ?? ''),
            trim($_POST['barcode'] ?? ''),
            trim($_POST['category'] ?? ''),
            $purchasePrice,
            $purchaseCurrency,
            $purchasePriceTry,
            $salePriceTry,
            $saleCurrency,
            $salePriceOriginal,
            (float)($_POST['vat_rate'] ?? 20),
            (float)($_POST['stock'] ?? 0),
            trim($_POST['unit'] ?? 'adet'),
            (float)($_POST['min_stock'] ?? 3),
        ]);

        redirect('products');
    }

    if ($action === 'update_product') {
        $id = (int)($_POST['id'] ?? 0);

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
            trim($_POST['name'] ?? ''),
            trim($_POST['stock_code'] ?? ''),
            trim($_POST['barcode'] ?? ''),
            trim($_POST['category'] ?? ''),
            $purchasePrice,
            $purchaseCurrency,
            $purchasePriceTry,
            $salePriceTry,
            $saleCurrency,
            $salePriceOriginal,
            (float)($_POST['vat_rate'] ?? 20),
            (float)($_POST['stock'] ?? 0),
            trim($_POST['unit'] ?? 'adet'),
            (float)($_POST['min_stock'] ?? 3),
            $id,
        ]);

        redirect('products');
    }

    if ($action === 'delete_product') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);

        redirect('products');
    }

    if ($action === 'delete_all_products') {
        db()->exec("UPDATE sale_items SET product_id = NULL");
        db()->exec("DELETE FROM stock_movements");
        db()->exec("DELETE FROM products");

        redirect('products');
    }
}


/*
|--------------------------------------------------------------------------
| SATIŞ POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'sales') {
    ensureSalesPaymentColumns();
    ensureDiscountColumns();
    ensureStockMovementsTable();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_sale') {
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $itemDiscountRates = $_POST['item_discount_rate'] ?? [];
        $itemDiscountAmounts = $_POST['item_discount_amount'] ?? [];
        $saleDiscountRate = max(0, min((float)($_POST['sale_discount_rate'] ?? 0), 100));
        $saleDiscountAmount = cleanMoneyValue((string)($_POST['sale_discount_amount'] ?? '0'));

        if (!is_array($productIds)) {
            $productIds = [$productIds];
        }

        if (!is_array($quantities)) {
            $quantities = [$quantities];
        }

        if (!is_array($unitPrices)) {
            $unitPrices = [$unitPrices];
        }

        $customerId = $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : null;
        $customerName = trim($_POST['customer_name'] ?? '');
        $paymentType = $_POST['payment_type'] ?? 'nakit';
        $paidAmountRaw = trim((string)($_POST['paid_amount'] ?? ''));
        $note = trim($_POST['note'] ?? '');

        if (!in_array($paymentType, ['nakit', 'kart', 'iban', 'veresiye'], true)) {
            $paymentType = 'nakit';
        }

        try {
            db()->beginTransaction();

            if ($customerId) {
                $stmt = db()->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch();

                if ($customer) {
                    $customerName = $customer['name'];
                }
            }

            if ($customerName === '') {
                $customerName = 'Perakende Müşteri';
            }

            $itemsToInsert = [];
            $grandTotal = 0.0;
            $lineDiscountTotal = 0.0;

            foreach ($productIds as $index => $productIdRaw) {
                $productId = (int)$productIdRaw;
                $quantity = isset($quantities[$index]) ? (float)$quantities[$index] : 0;

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Ürün bulunamadı.');
                }

                $currentStock = (float)$product['stock'];

                if ($currentStock < $quantity) {
                    throw new RuntimeException('Yetersiz stok: ' . $product['name'] . ' / Mevcut: ' . number_format($currentStock, 2, ',', '.') . ' ' . ($product['unit'] ?? ''));
                }

                $postedUnitPrice = isset($unitPrices[$index]) ? (float)$unitPrices[$index] : 0;
                $postedDiscountRate = isset($itemDiscountRates[$index]) ? (float)$itemDiscountRates[$index] : 0;
                $postedDiscountAmount = isset($itemDiscountAmounts[$index]) ? cleanMoneyValue((string)$itemDiscountAmounts[$index]) : 0;
                $unitPrice = $postedUnitPrice > 0 ? $postedUnitPrice : (float)$product['sale_price'];
                $vatRate = (float)($product['vat_rate'] ?? 0);
                $discountRate = max(0, min($postedDiscountRate, 100));
                $lineBase = $quantity * $unitPrice;
                $linePercentDiscount = $lineBase * ($discountRate / 100);
                $discountAmount = max(0, min($postedDiscountAmount, max($lineBase - $linePercentDiscount, 0)));
                $lineDiscount = $linePercentDiscount + $discountAmount;
                $lineTotal = max($lineBase - $lineDiscount, 0);

                $lineDiscountTotal += $lineDiscount;
                $grandTotal += $lineTotal;

                $itemsToInsert[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_discount' => $lineDiscount,
                    'vat_rate' => $vatRate,
                    'line_total' => $lineTotal,
                    'old_stock' => $currentStock,
                    'new_stock' => $currentStock - $quantity,
                ];
            }

            if (!$itemsToInsert) {
                throw new RuntimeException('Satışa en az 1 ürün eklemelisin.');
            }

            $globalPercentDiscount = $grandTotal * ($saleDiscountRate / 100);
            $globalAmountDiscount = max(0, min($saleDiscountAmount, max($grandTotal - $globalPercentDiscount, 0)));
            $globalDiscount = $globalPercentDiscount + $globalAmountDiscount;
            $discountTotal = $lineDiscountTotal + $globalDiscount;
            $grandTotal = max($grandTotal - $globalDiscount, 0);

            if ($paidAmountRaw === '') {
                $paidAmount = $paymentType === 'veresiye' ? 0.0 : $grandTotal;
            } else {
                $paidAmount = cleanMoneyValue($paidAmountRaw);
            }

            if ($paidAmount < 0) {
                $paidAmount = 0.0;
            }

            if ($paidAmount > $grandTotal) {
                $paidAmount = $grandTotal;
            }

            $remainingAmount = max($grandTotal - $paidAmount, 0);
            $saleNo = 'MK' . date('YmdHis');

            $stmt = db()->prepare("
                INSERT INTO sales (sale_no, customer_id, customer_name, total_amount, discount_total, discount_rate, discount_amount, paid_amount, remaining_amount, payment_type, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $saleNo,
                $customerId,
                $customerName,
                $grandTotal,
                $discountTotal,
                $saleDiscountRate,
                $globalAmountDiscount,
                $paidAmount,
                $remainingAmount,
                $paymentType,
                $note,
                currentUser()['id'] ?? null,
            ]);

            $saleId = (int)db()->lastInsertId();

            $saleItemStmt = db()->prepare("
                INSERT INTO sale_items 
                (sale_id, product_id, product_name, quantity, unit_price, discount_rate, discount_amount, line_discount, vat_rate, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stockUpdateStmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $movementStmt = db()->prepare("
                INSERT INTO stock_movements 
                (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                VALUES (?, 'sale', ?, ?, ?, ?, ?)
            ");

            foreach ($itemsToInsert as $item) {
                $saleItemStmt->execute([
                    $saleId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount_rate'],
                    $item['discount_amount'],
                    $item['line_discount'],
                    $item['vat_rate'],
                    $item['line_total'],
                ]);

                $stockUpdateStmt->execute([$item['new_stock'], $item['product_id']]);

                $movementStmt->execute([
                    $item['product_id'],
                    $item['quantity'],
                    $item['old_stock'],
                    $item['new_stock'],
                    'Satış kaydı: ' . $saleNo,
                    currentUser()['id'] ?? null,
                ]);
            }

            if ($remainingAmount > 0 && $customerId) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$remainingAmount, $customerId]);
                addCustomerTransaction($customerId, 'satis_borcu', $remainingAmount, 'Satış kalan borcu: ' . $saleNo, $paymentType, 'sale', $saleId);
            }

            db()->commit();
            redirect('sales');

        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    if ($action === 'update_sale' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);
        $customerId = $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : null;
        $customerName = trim($_POST['customer_name'] ?? '');
        $paymentType = $_POST['payment_type'] ?? 'nakit';
        $paidAmount = cleanMoneyValue((string)($_POST['paid_amount'] ?? '0'));
        $note = trim($_POST['note'] ?? '');

        if (!in_array($paymentType, ['nakit', 'kart', 'iban', 'veresiye'], true)) {
            $paymentType = 'nakit';
        }

        try {
            db()->beginTransaction();

            $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $sale = $stmt->fetch();

            if (!$sale) {
                throw new RuntimeException('Satış bulunamadı.');
            }

            if ($customerId) {
                $stmt = db()->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch();

                if ($customer) {
                    $customerName = $customer['name'];
                }
            }

            if ($customerName === '') {
                $customerName = $sale['customer_name'] ?: 'Perakende Müşteri';
            }

            $totalAmount = (float)$sale['total_amount'];

            if ($paidAmount < 0) {
                $paidAmount = 0.0;
            }

            if ($paidAmount > $totalAmount) {
                $paidAmount = $totalAmount;
            }

            $oldRemaining = (float)($sale['remaining_amount'] ?? 0);
            $newRemaining = max($totalAmount - $paidAmount, 0);

            if (!empty($sale['customer_id']) && $oldRemaining > 0) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$oldRemaining, (int)$sale['customer_id']]);
                addCustomerTransaction((int)$sale['customer_id'], 'satis_duzeltme_alacak', $oldRemaining, 'Satış güncelleme eski kalan borç iptali: ' . $sale['sale_no'], $paymentType, 'sale', $id);
            }

            if ($customerId && $newRemaining > 0) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$newRemaining, $customerId]);
                addCustomerTransaction($customerId, 'satis_duzeltme_borc', $newRemaining, 'Satış güncelleme yeni kalan borç: ' . $sale['sale_no'], $paymentType, 'sale', $id);
            }

            $stmt = db()->prepare("
                UPDATE sales SET
                    customer_id = ?,
                    customer_name = ?,
                    paid_amount = ?,
                    remaining_amount = ?,
                    payment_type = ?,
                    note = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $customerId,
                $customerName,
                $paidAmount,
                $newRemaining,
                $paymentType,
                $note,
                $id,
            ]);

            db()->commit();
            redirect('sales');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    if ($action === 'delete_sale' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);

        try {
            db()->beginTransaction();

            $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $sale = $stmt->fetch();

            if (!$sale) {
                throw new RuntimeException('Satış bulunamadı.');
            }

            $stmt = db()->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll();

            foreach ($items as $item) {
                if (!$item['product_id']) {
                    continue;
                }

                $stmt = db()->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$item['product_id']]);
                $product = $stmt->fetch();

                if ($product) {
                    $oldStock = (float)$product['stock'];
                    $newStock = $oldStock + (float)$item['quantity'];

                    $stmt = db()->prepare("UPDATE products SET stock = ? WHERE id = ?");
                    $stmt->execute([$newStock, (int)$item['product_id']]);

                    $stmt = db()->prepare("
                        INSERT INTO stock_movements 
                        (product_id, movement_type, quantity, old_stock, new_stock, description, created_by)
                        VALUES (?, 'manual', ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        (int)$item['product_id'],
                        (float)$item['quantity'],
                        $oldStock,
                        $newStock,
                        'Satış silindi, stok geri eklendi: ' . $sale['sale_no'],
                        currentUser()['id'] ?? null,
                    ]);
                }
            }

            $remainingAmount = (float)($sale['remaining_amount'] ?? 0);
            if ($remainingAmount > 0 && !empty($sale['customer_id'])) {
                $stmt = db()->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$remainingAmount, (int)$sale['customer_id']]);
                addCustomerTransaction((int)$sale['customer_id'], 'satis_silme_alacak', $remainingAmount, 'Satış silindi, kalan borç iptal: ' . $sale['sale_no'], $sale['payment_type'] ?? '', 'sale', $id);
            }

            $stmt = db()->prepare("DELETE FROM sales WHERE id = ?");
            $stmt->execute([$id]);

            db()->commit();
            redirect('sales');

        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| AYARLAR POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'settings' && isAdmin()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_settings') {
        $settings = [
            'company_name' => trim($_POST['company_name'] ?? 'MK Denizcilik'),
            'usd_rate' => trim($_POST['usd_rate'] ?? '0'),
            'eur_rate' => trim($_POST['eur_rate'] ?? '0'),
            'critical_stock_default' => trim($_POST['critical_stock_default'] ?? '3'),
            'bank_line_1' => trim($_POST['bank_line_1'] ?? 'TEB BANKASI: TR36 0003 2000 0000 0018 0425 34'),
            'bank_line_2' => trim($_POST['bank_line_2'] ?? 'VAKIFBANK: TR79 0001 5001 5800 7322 6017 55'),
            'bank_line_3' => trim($_POST['bank_line_3'] ?? 'GARANTİ BANKASI: TR78 0006 2000 2050 0006 2868 99'),
            'bank_note' => trim($_POST['bank_note'] ?? 'Ödeme sonrası dekont iletiniz.'),
        ];

        foreach ($settings as $key => $value) {
            $stmt = db()->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
        }

        redirect('settings');
    }

    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'] === 'admin' ? 'admin' : 'personel';

        if ($name && $username && $password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = db()->prepare("
                INSERT INTO users (name, username, password_hash, role)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $username, $hash, $role]);
        }

        redirect('settings');
    }
}

/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if ($page === 'login'):
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> - Giriş</title>
    <link rel="stylesheet" href="assets/style.css?v=14">
</head>
<body class="login-body">
    <form class="login-card" method="post" action="index.php?page=login">
        <div class="login-logo">MK</div>

        <div class="login-heading">
            <p>MK Denizcilik</p>
            <h1>ERP Giriş Paneli</h1>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <label>Kullanıcı Adı</label>
        <input type="text" name="username" required autofocus autocomplete="username">

        <label>Şifre</label>
        <input type="password" name="password" required autocomplete="current-password">

        <button type="submit">Giriş Yap</button>
    </form>
</body>
</html>
<?php
exit;
endif;

/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/

if ($page === 'dashboard') {
    renderHeader('Güncel Durum');

    $todaySales = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $totalRevenue = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales")->fetchColumn();
    $todayPassiveSales = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at) = CURDATE() AND note LIKE '[PASIF_SATIS]%'")->fetchColumn();
    $customerCount = db()->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $productCount = db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $lowStockCount = db()->query("SELECT COUNT(*) FROM products WHERE stock <= min_stock")->fetchColumn();
    ?>
    <section class="cards">
        <div class="card">
            <span>Bugünkü Satış</span>
            <strong><?= number_format((float)$todaySales, 2, ',', '.') ?> ₺</strong>
        </div>
        <div class="card">
            <span>Toplam Ciro</span>
            <strong><?= number_format((float)$totalRevenue, 2, ',', '.') ?> ₺</strong>
        </div>
        <div class="card">
            <span>Müşteri Sayısı</span>
            <strong><?= (int)$customerCount ?></strong>
        </div>
        <div class="card">
            <span>Ürün Sayısı</span>
            <strong><?= (int)$productCount ?></strong>
        </div>
        <div class="card danger">
            <span>Kritik Stok</span>
            <strong><?= (int)$lowStockCount ?></strong>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Özet</p>
                <h2>İşletme Durumu</h2>
            </div>
        </div>
        <p>Satış, müşteri, ürün ve stok durumlarınızı tek ekrandan takip edebilirsiniz.</p>
    </section>
    <?php
    renderFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| MÜŞTERİLER
|--------------------------------------------------------------------------
*/

if ($page === 'customers') {
    renderHeader('Müşteriler');

    $mode = $_GET['mode'] ?? '';
    $search = trim($_GET['q'] ?? '');
    $editCustomer = null;

    if ($mode === 'edit' && isAdmin()) {
        $editId = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editCustomer = $stmt->fetch();
    }

    if ($search !== '') {
        // Akıllı arama:
        // "ser in" yazınca hem "Serhat İnan" hem de içinde bu parçalar geçen kayıtları bulur.
        // Telefon aramasında boşluk, tire, parantez gibi karakterleri önemsemez.
        $searchNormalized = preg_replace('/\s+/', ' ', $search);
        $searchParts = preg_split('/\s+/', $searchNormalized, -1, PREG_SPLIT_NO_EMPTY);

        $whereGroups = [];
        $params = [];

        foreach ($searchParts as $part) {
            $like = '%' . $part . '%';
            $digits = preg_replace('/\D+/', '', $part);

            $group = "(
                name LIKE ?
                OR phone LIKE ?
                OR email LIKE ?
                OR tax_number LIKE ?
                OR tax_office LIKE ?
                OR address LIKE ?
            ";

            array_push($params, $like, $like, $like, $like, $like, $like);

            if ($digits !== '') {
                $digitLike = '%' . $digits . '%';
                $group .= "
                    OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?
                    OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tax_number, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?
                ";
                array_push($params, $digitLike, $digitLike);
            }

            $group .= ")";
            $whereGroups[] = $group;
        }

        $whereSql = implode(' AND ', $whereGroups);

        $stmt = db()->prepare("
            SELECT * FROM customers
            WHERE {$whereSql}
            ORDER BY
                CASE
                    WHEN name LIKE ? THEN 1
                    WHEN name LIKE ? THEN 2
                    ELSE 3
                END,
                id DESC
            LIMIT 150
        ");

        $params[] = $search . '%';
        $params[] = '%' . $search . '%';

        $stmt->execute($params);
        $customers = $stmt->fetchAll();
    } else {
        $customers = db()->query("SELECT * FROM customers ORDER BY id DESC LIMIT 150")->fetchAll();
    }

    $totalCustomerCount = db()->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $listedCustomerCount = count($customers);
    $debtCustomerCount = db()->query("SELECT COUNT(*) FROM customers WHERE balance > 0")->fetchColumn();
    $totalCustomerBalance = db()->query("SELECT COALESCE(SUM(balance), 0) FROM customers")->fetchColumn();
    ?>

    <section class="cards dashboard-stats">
        <div class="card">
            <span>Toplam Müşteri</span>
            <strong><?= (int)$totalCustomerCount ?></strong>
        </div>

        <div class="card">
            <span>Listelenen Müşteri</span>
            <strong><?= (int)$listedCustomerCount ?></strong>
        </div>

        <div class="card danger">
            <span>Borçlu Müşteri</span>
            <strong><?= (int)$debtCustomerCount ?></strong>
        </div>

        <div class="card">
            <span>Toplam Cari Bakiye</span>
            <strong><?= number_format((float)$totalCustomerBalance, 2, ',', '.') ?> ₺</strong>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>İşlem</p>
                <h2>Müşteri İşlemleri</h2>
            </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" onclick="window.location.href='index.php?page=customers&mode=add'">
                Yeni Müşteri Ekle
            </button>

            <?php if (isAdmin()): ?>
                <button type="button" onclick="window.location.href='customer_import.php'">
                    Excel’den Müşteri Aktar
                </button>

                <form method="post" onsubmit="return confirm('Tüm müşteriler silinecek. Bu işlem geri alınamaz. Emin misin?');" style="display:inline;">
                    <input type="hidden" name="action" value="delete_all_customers">
                    <button type="submit" class="danger-btn">
                        Tüm Müşterileri Sil
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Arama</p>
                <h2>Müşteri Ara</h2>
            </div>
        </div>

        <form method="get" class="grid-form">
            <input type="hidden" name="page" value="customers">

            <div class="full">
                <label>Ad, telefon, e-posta, adres, vergi no veya vergi dairesi</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Örn: ser in, kerem, 0552, beykoz...">
            </div>

            <button type="submit">Ara</button>

            <?php if ($search !== ''): ?>
                <button type="button" onclick="window.location.href='index.php?page=customers'">
                    Temizle
                </button>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($mode === 'add' || $editCustomer): ?>
        <section class="panel">
            <div class="section-title">
                <div>
                    <p><?= $editCustomer ? 'Düzenle' : 'Kayıt' ?></p>
                    <h2><?= $editCustomer ? 'Müşteri Güncelle' : 'Yeni Müşteri Ekle' ?></h2>
                </div>
            </div>

            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="<?= $editCustomer ? 'update_customer' : 'add_customer' ?>">
                <?php if ($editCustomer): ?>
                    <input type="hidden" name="id" value="<?= (int)$editCustomer['id'] ?>">
                <?php endif; ?>

                <div>
                    <label>Müşteri Adı</label>
                    <input type="text" name="name" required value="<?= e($editCustomer['name'] ?? '') ?>">
                </div>

                <div>
                    <label>Telefon</label>
                    <input type="text" name="phone" value="<?= e($editCustomer['phone'] ?? '') ?>">
                </div>

                <div>
                    <label>E-posta</label>
                    <input type="email" name="email" value="<?= e($editCustomer['email'] ?? '') ?>">
                </div>

                <div>
                    <label>Vergi Dairesi</label>
                    <input type="text" name="tax_office" value="<?= e($editCustomer['tax_office'] ?? '') ?>">
                </div>

                <div>
                    <label>Vergi No</label>
                    <input type="text" name="tax_number" value="<?= e($editCustomer['tax_number'] ?? '') ?>">
                </div>

                <?php if ($editCustomer && isAdmin()): ?>
                    <div>
                        <label>Bakiye</label>
                        <input type="number" step="0.01" name="balance" value="<?= e((string)($editCustomer['balance'] ?? '0')) ?>">
                    </div>
                <?php endif; ?>

                <div class="full">
                    <label>Adres</label>
                    <textarea name="address"><?= e($editCustomer['address'] ?? '') ?></textarea>
                </div>

                <button type="submit"><?= $editCustomer ? 'Müşteri Güncelle' : 'Müşteri Ekle' ?></button>
                <button type="button" onclick="window.location.href='index.php?page=customers'">Vazgeç</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Liste</p>
                <h2>Müşteri Listesi</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Ad</th>
                        <th>Telefon</th>
                        <th>E-posta</th>
                        <th>Vergi No</th>
                        <th>Bakiye</th>
                        <th>Tarih</th>
                        <?php if (isAdmin()): ?>
                            <th>İşlem</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><?= e($c['name']) ?></td>
                            <td><?= e($c['phone']) ?></td>
                            <td><?= e($c['email']) ?></td>
                            <td><?= e($c['tax_number']) ?></td>
                            <td><?= number_format((float)$c['balance'], 2, ',', '.') ?> ₺</td>
                            <td><?= e($c['created_at']) ?></td>
                            <?php if (isAdmin()): ?>
                                <td>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <button type="button" class="small" onclick="window.location.href='index.php?page=customers&mode=edit&id=<?= (int)$c['id'] ?>'">
                                            Düzenle
                                        </button>

                                        <button type="button" class="small" onclick="window.location.href='index.php?page=customer_account&id=<?= (int)$c['id'] ?>'">
                                            Cari Detay
                                        </button>

                                        <form method="post" onsubmit="return confirm('Müşteri silinsin mi?')">
                                            <input type="hidden" name="action" value="delete_customer">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button class="small danger-btn">Sil</button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$customers): ?>
                        <tr>
                            <td colspan="7">Müşteri bulunamadı.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}



/*
|--------------------------------------------------------------------------
| TAHSİLAT / ÖDEME
|--------------------------------------------------------------------------
*/

if ($page === 'payments') {
    ensureCustomerTransactionsTable();
    renderHeader('Tahsilat / Ödeme');

    $paymentError = trim((string)($_GET['payment_error'] ?? ''));

    $debtCustomers = db()->query("
        SELECT id, name, phone, balance
        FROM customers
        WHERE balance > 0
        ORDER BY balance DESC, name ASC
        LIMIT 150
    ")->fetchAll();

    $allCustomers = db()->query("
        SELECT id, name, balance
        FROM customers
        ORDER BY
            CASE WHEN balance > 0 THEN 0 ELSE 1 END,
            name ASC
        LIMIT 500
    ")->fetchAll();

    $todayCollection = db()->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM customer_transactions
        WHERE transaction_type = 'tahsilat'
          AND DATE(created_at) = CURDATE()
    ")->fetchColumn();

    $monthCollection = db()->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM customer_transactions
        WHERE transaction_type = 'tahsilat'
          AND YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ")->fetchColumn();

    $debtTotal = db()->query("SELECT COALESCE(SUM(balance), 0) FROM customers WHERE balance > 0")->fetchColumn();
    $debtCustomerCount = db()->query("SELECT COUNT(*) FROM customers WHERE balance > 0")->fetchColumn();

    $todayByType = db()->query("
        SELECT COALESCE(payment_type, 'belirsiz') AS payment_type, COALESCE(SUM(amount), 0) AS total_amount
        FROM customer_transactions
        WHERE transaction_type = 'tahsilat'
          AND DATE(created_at) = CURDATE()
        GROUP BY payment_type
        ORDER BY total_amount DESC
    ")->fetchAll();

    $recentCollections = db()->query("
        SELECT
            ct.*,
            c.name AS customer_name,
            u.name AS user_name
        FROM customer_transactions ct
        LEFT JOIN customers c ON c.id = ct.customer_id
        LEFT JOIN users u ON u.id = ct.created_by
        WHERE ct.transaction_type = 'tahsilat'
        ORDER BY ct.id DESC
        LIMIT 100
    ")->fetchAll();
    ?>

    <?php if ($paymentError !== ''): ?>
        <section class="panel">
            <div class="alert error"><?= e($paymentError) ?></div>
        </section>
    <?php endif; ?>

    <section class="cards dashboard-stats">
        <div class="card">
            <span>Bugünkü Tahsilat</span>
            <strong><?= number_format((float)$todayCollection, 2, ',', '.') ?> ₺</strong>
        </div>

        <div class="card">
            <span>Bu Ay Tahsilat</span>
            <strong><?= number_format((float)$monthCollection, 2, ',', '.') ?> ₺</strong>
        </div>

        <div class="card">
            <span>Borçlu Müşteri</span>
            <strong><?= (int)$debtCustomerCount ?></strong>
        </div>

        <div class="card danger">
            <span>Toplam Cari Borç</span>
            <strong><?= number_format((float)$debtTotal, 2, ',', '.') ?> ₺</strong>
        </div>
    </section>

    <section class="dashboard-grid">
        <div class="panel">
            <div class="section-title">
                <div>
                    <p>Hızlı İşlem</p>
                    <h2>Tahsilat Ekle</h2>
                </div>
            </div>

            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="add_payment_collection">

                <div class="full">
                    <label>Müşteri</label>
                    <select name="customer_id" required>
                        <option value="">Seçiniz</option>
                        <?php foreach ($allCustomers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>">
                                <?= e($customer['name']) ?> — Bakiye: <?= number_format((float)$customer['balance'], 2, ',', '.') ?> ₺
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Tahsilat Tutarı</label>
                    <input type="number" step="0.01" min="0.01" name="amount" required>
                </div>

                <div>
                    <label>Ödeme Tipi</label>
                    <select name="payment_type">
                        <option value="nakit">Nakit</option>
                        <option value="kart">Kart</option>
                        <option value="iban">IBAN</option>
                    </select>
                </div>

                <div class="full">
                    <label>Açıklama</label>
                    <input type="text" name="description" value="Tahsilat">
                </div>

                <button type="submit">Tahsilat Kaydet</button>
            </form>
        </div>

        <div class="panel">
            <div class="section-title">
                <div>
                    <p>Bugün</p>
                    <h2>Ödeme Tipi Özeti</h2>
                </div>
            </div>

            <div class="table-wrap compact-table">
                <table>
                    <thead>
                        <tr>
                            <th>Ödeme Tipi</th>
                            <th>Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayByType as $row): ?>
                            <tr>
                                <td><?= e((string)$row['payment_type']) ?></td>
                                <td><?= number_format((float)$row['total_amount'], 2, ',', '.') ?> ₺</td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$todayByType): ?>
                            <tr>
                                <td colspan="2">Bugün tahsilat yok.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Cari</p>
                <h2>Borçlu Müşteriler</h2>
            </div>
            <div class="role-badge"><?= count($debtCustomers) ?> kayıt</div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Müşteri</th>
                        <th>Telefon</th>
                        <th>Bakiye</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($debtCustomers as $customer): ?>
                        <tr>
                            <td><?= e($customer['name']) ?></td>
                            <td><?= e($customer['phone'] ?? '') ?></td>
                            <td><?= number_format((float)$customer['balance'], 2, ',', '.') ?> ₺</td>
                            <td>
                                <button type="button" class="small" onclick="window.location.href='index.php?page=customer_account&id=<?= (int)$customer['id'] ?>'">
                                    Cari Detay
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$debtCustomers): ?>
                        <tr>
                            <td colspan="4">Borçlu müşteri yok.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Geçmiş</p>
                <h2>Son Tahsilatlar</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>Müşteri</th>
                        <th>Tutar</th>
                        <th>Ödeme</th>
                        <th>Açıklama</th>
                        <th>Kullanıcı</th>
                        <th>İşlem Sonrası Bakiye</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCollections as $tx): ?>
                        <tr>
                            <td><?= e($tx['created_at']) ?></td>
                            <td><?= e($tx['customer_name'] ?? '-') ?></td>
                            <td><?= number_format((float)$tx['amount'], 2, ',', '.') ?> ₺</td>
                            <td><?= e($tx['payment_type'] ?? '-') ?></td>
                            <td><?= e($tx['description'] ?? '') ?></td>
                            <td><?= e($tx['user_name'] ?? '-') ?></td>
                            <td><?= number_format((float)$tx['balance_after'], 2, ',', '.') ?> ₺</td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$recentCollections): ?>
                        <tr>
                            <td colspan="7">Henüz tahsilat kaydı yok.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| CARİ HESAP
|--------------------------------------------------------------------------
*/

if ($page === 'customer_account') {
    ensureCustomerTransactionsTable();
    renderHeader('Cari Hesap');

    $customerId = (int)($_GET['id'] ?? 0);

    if ($customerId <= 0) {
        redirect('customers');
    }

    $stmt = db()->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        echo '<section class="panel"><div class="alert error">Müşteri bulunamadı.</div></section>';
        renderFooter();
        exit;
    }

    $stmt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $totalSales = (float)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $totalPaidFromSales = (float)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_transactions WHERE customer_id = ? AND transaction_type = 'tahsilat'");
    $stmt->execute([$customerId]);
    $manualCollections = (float)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT * FROM customer_transactions WHERE customer_id = ? ORDER BY id DESC LIMIT 200");
    $stmt->execute([$customerId]);
    $transactions = $stmt->fetchAll();

    $stmt = db()->prepare("
        SELECT sale_no, total_amount, paid_amount, remaining_amount, payment_type, created_at
        FROM sales
        WHERE customer_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $stmt->execute([$customerId]);
    $customerSales = $stmt->fetchAll();
    ?>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Müşteri Cari Kartı</p>
                <h2><?= e($customer['name']) ?></h2>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" onclick="window.location.href='index.php?page=customers'">Müşterilere Dön</button>
                <button type="button" onclick="window.open('customer_statement_pdf.php?id=<?= (int)$customer['id'] ?>', '_blank')">Cari Ekstre PDF</button>
            </div>
        </div>

        <div class="cards">
            <div class="card">
                <span>Güncel Bakiye</span>
                <strong><?= number_format((float)$customer['balance'], 2, ',', '.') ?> ₺</strong>
            </div>
            <div class="card">
                <span>Toplam Satış</span>
                <strong><?= number_format($totalSales, 2, ',', '.') ?> ₺</strong>
            </div>
            <div class="card">
                <span>Satışta Ödenen</span>
                <strong><?= number_format($totalPaidFromSales, 2, ',', '.') ?> ₺</strong>
            </div>
            <div class="card">
                <span>Manuel Tahsilat</span>
                <strong><?= number_format($manualCollections, 2, ',', '.') ?> ₺</strong>
            </div>
        </div>
    </section>

    <section class="dashboard-grid">
        <div class="panel">
            <div class="section-title"><div><p>Tahsilat</p><h2>Tahsilat Ekle</h2></div></div>
            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="add_customer_payment">
                <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">

                <div>
                    <label>Tahsilat Tutarı</label>
                    <input type="number" step="0.01" min="0.01" name="amount" required>
                </div>

                <div>
                    <label>Ödeme Tipi</label>
                    <select name="payment_type">
                        <option value="nakit">Nakit</option>
                        <option value="kart">Kart</option>
                        <option value="iban">IBAN</option>
                    </select>
                </div>

                <div class="full">
                    <label>Açıklama</label>
                    <input type="text" name="description" value="Tahsilat">
                </div>

                <button type="submit">Tahsilat Kaydet</button>
            </form>
        </div>

        <?php if (isAdmin()): ?>
            <div class="panel">
                <div class="section-title"><div><p>Düzeltme</p><h2>Borç / Alacak Düzelt</h2></div></div>
                <form method="post" class="grid-form">
                    <input type="hidden" name="action" value="add_customer_adjustment">
                    <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">

                    <div>
                        <label>İşlem</label>
                        <select name="adjustment_type">
                            <option value="borc">Borç Ekle</option>
                            <option value="alacak">Alacak / İndirim Ekle</option>
                        </select>
                    </div>

                    <div>
                        <label>Tutar</label>
                        <input type="number" step="0.01" min="0.01" name="amount" required>
                    </div>

                    <div class="full">
                        <label>Açıklama</label>
                        <input type="text" name="description" value="Manuel cari düzeltme">
                    </div>

                    <button type="submit">Düzeltme Kaydet</button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Ekstre</p><h2>Cari Hareketler</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>İşlem</th>
                        <th>Açıklama</th>
                        <th>Ödeme</th>
                        <th>Tutar</th>
                        <th>İşlem Sonrası Bakiye</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><?= e($tx['created_at']) ?></td>
                            <td><?= e($tx['transaction_type']) ?></td>
                            <td><?= e($tx['description'] ?? '') ?></td>
                            <td><?= e($tx['payment_type'] ?? '-') ?></td>
                            <td><?= number_format((float)$tx['amount'], 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)$tx['balance_after'], 2, ',', '.') ?> ₺</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="6">Henüz cari hareket yok. Yeni satış/tahsilat işlemleri burada görünecek.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Satış</p><h2>Son Satışlar</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Satış No</th>
                        <th>Toplam</th>
                        <th>Ödenen</th>
                        <th>Kalan</th>
                        <th>Ödeme</th>
                        <th>Tarih</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customerSales as $sale): ?>
                        <tr>
                            <td><?= e($sale['sale_no']) ?></td>
                            <td><?= number_format((float)$sale['total_amount'], 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($sale['paid_amount'] ?? 0), 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($sale['remaining_amount'] ?? 0), 2, ',', '.') ?> ₺</td>
                            <td><?= e($sale['payment_type']) ?></td>
                            <td><?= e($sale['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$customerSales): ?>
                        <tr><td colspan="6">Bu müşteriye ait satış yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}


/*
|--------------------------------------------------------------------------
| ÜRÜN STOK DETAY
|--------------------------------------------------------------------------
*/

if ($page === 'product_stock' && isAdmin()) {
    ensureProductCurrencyColumns();
    ensureStockMovementsTable();

    renderHeader('Stok Detay');

    $productId = (int)($_GET['id'] ?? 0);
    $stockError = trim((string)($_GET['stock_error'] ?? ''));

    if ($productId <= 0) {
        redirect('products');
    }

    $stmt = db()->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        echo '<section class="panel"><div class="alert error">Ürün bulunamadı.</div></section>';
        renderFooter();
        exit;
    }

    $stmt = db()->prepare("
        SELECT
            sm.*,
            u.name AS user_name
        FROM stock_movements sm
        LEFT JOIN users u ON u.id = sm.created_by
        WHERE sm.product_id = ?
        ORDER BY sm.id DESC
        LIMIT 250
    ");
    $stmt->execute([$productId]);
    $movements = $stmt->fetchAll();

    $stock = (float)($product['stock'] ?? 0);
    $minStock = (float)($product['min_stock'] ?? 0);
    $isCritical = $stock <= $minStock;
    ?>

    <?php if ($stockError !== ''): ?>
        <section class="panel">
            <div class="alert error"><?= e($stockError) ?></div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Ürün Stok Kartı</p>
                <h2><?= e($product['name']) ?></h2>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" onclick="window.location.href='index.php?page=products'">Ürünlere Dön</button>
                <button type="button" onclick="window.location.href='index.php?page=products&mode=edit&id=<?= (int)$product['id'] ?>'">Ürünü Düzenle</button>
            </div>
        </div>

        <div class="cards">
            <div class="card <?= $isCritical ? 'danger' : '' ?>">
                <span>Güncel Stok</span>
                <strong><?= number_format($stock, 2, ',', '.') ?> <?= e($product['unit'] ?? '') ?></strong>
            </div>
            <div class="card">
                <span>Kritik Stok</span>
                <strong><?= number_format($minStock, 2, ',', '.') ?> <?= e($product['unit'] ?? '') ?></strong>
            </div>
            <div class="card">
                <span>Stok Kodu</span>
                <strong><?= e($product['stock_code'] ?? '-') ?></strong>
            </div>
            <div class="card">
                <span>Satış Fiyatı</span>
                <strong><?= number_format((float)$product['sale_price'], 2, ',', '.') ?> ₺</strong>
            </div>
        </div>
    </section>

    <section class="dashboard-grid">
        <div class="panel">
            <div class="section-title">
                <div>
                    <p>Manuel İşlem</p>
                    <h2>Stok Giriş / Çıkış</h2>
                </div>
            </div>

            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="add_stock_movement">
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

                <div>
                    <label>İşlem Tipi</label>
                    <select name="movement_type">
                        <option value="stock_in">Stok Giriş</option>
                        <option value="stock_out">Stok Çıkış</option>
                    </select>
                </div>

                <div>
                    <label>Miktar</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" required>
                </div>

                <div class="full">
                    <label>Açıklama</label>
                    <input type="text" name="description" placeholder="Örn: Sayım düzeltmesi, tedarik girişi, fire çıkışı...">
                </div>

                <button type="submit">Stok Hareketi Kaydet</button>
            </form>
        </div>

        <div class="panel">
            <div class="section-title">
                <div>
                    <p>Durum</p>
                    <h2>Stok Uyarısı</h2>
                </div>
            </div>

            <?php if ($isCritical): ?>
                <div class="alert error">
                    Bu ürün kritik stok seviyesinde veya altında. Mevcut stok <?= number_format($stock, 2, ',', '.') ?> <?= e($product['unit'] ?? '') ?>.
                </div>
            <?php else: ?>
                <div class="alert">
                    Stok seviyesi normal. Kritik sınır: <?= number_format($minStock, 2, ',', '.') ?> <?= e($product['unit'] ?? '') ?>.
                </div>
            <?php endif; ?>

            <div class="table-wrap compact-table" style="margin-top:12px;">
                <table>
                    <tbody>
                        <tr><th>Kategori</th><td><?= e($product['category'] ?? '-') ?></td></tr>
                        <tr><th>Barkod</th><td><?= e($product['barcode'] ?? '-') ?></td></tr>
                        <tr><th>Alış TL</th><td><?= number_format((float)($product['purchase_price_try'] ?? $product['purchase_price'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
                        <tr><th>KDV</th><td>%<?= number_format((float)($product['vat_rate'] ?? 0), 2, ',', '.') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Geçmiş</p>
                <h2>Stok Hareketleri</h2>
            </div>
            <div class="role-badge"><?= count($movements) ?> kayıt</div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>Tip</th>
                        <th>Miktar</th>
                        <th>Eski Stok</th>
                        <th>Yeni Stok</th>
                        <th>Açıklama</th>
                        <th>Kullanıcı</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $movement): ?>
                        <tr>
                            <td><?= e($movement['created_at']) ?></td>
                            <td><?= e($movement['movement_type']) ?></td>
                            <td><?= number_format((float)$movement['quantity'], 2, ',', '.') ?> <?= e($product['unit'] ?? '') ?></td>
                            <td><?= number_format((float)$movement['old_stock'], 2, ',', '.') ?></td>
                            <td><?= number_format((float)$movement['new_stock'], 2, ',', '.') ?></td>
                            <td><?= e($movement['description'] ?? '') ?></td>
                            <td><?= e($movement['user_name'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$movements): ?>
                        <tr>
                            <td colspan="7">Bu ürün için henüz stok hareketi yok.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| ÜRÜNLER
|--------------------------------------------------------------------------
*/

if ($page === 'products' && isAdmin()) {
    ensureProductCurrencyColumns();

    renderHeader('Ürünler');

    $mode = $_GET['mode'] ?? '';
    $search = trim($_GET['q'] ?? '');
    $editProduct = null;
    $productsLimit = 100;
    $searchTooShort = false;

    if ($mode === 'edit') {
        $editId = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editProduct = $stmt->fetch();
    }

    if ($search !== '') {
        if (mb_strlen($search, 'UTF-8') < 2) {
            $searchTooShort = true;
            $products = [];
        } else {
            $stmt = db()->prepare("
                SELECT * FROM products
                WHERE name LIKE ?
                   OR stock_code LIKE ?
                   OR barcode LIKE ?
                   OR category LIKE ?
                ORDER BY id DESC
                LIMIT {$productsLimit}
            ");
            $like = '%' . $search . '%';
            $stmt->execute([$like, $like, $like, $like]);
            $products = $stmt->fetchAll();
        }
    } else {
        // Performans için ürünler sayfasında tüm veritabanını basmıyoruz.
        // İlk açılışta sadece son 100 ürün gelir; arama yapınca yine maksimum 100 sonuç gösterilir.
        $products = db()->query("SELECT * FROM products ORDER BY id DESC LIMIT {$productsLimit}")->fetchAll();
    }

    $totalProductCount = db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $listedProductCount = count($products);
    $totalStockAmount = db()->query("SELECT COALESCE(SUM(stock), 0) FROM products")->fetchColumn();
    $lowStockProductCount = db()->query("SELECT COUNT(*) FROM products WHERE stock <= min_stock")->fetchColumn();
    $totalStockSaleValue = db()->query("SELECT COALESCE(SUM(stock * sale_price), 0) FROM products")->fetchColumn();
    ?>

    <section class="cards dashboard-stats">
        <div class="card">
            <span>Toplam Ürün</span>
            <strong><?= (int)$totalProductCount ?></strong>
        </div>

        <div class="card">
            <span>Listelenen Ürün</span>
            <strong><?= (int)$listedProductCount ?></strong>
        </div>

        <div class="card">
            <span>Toplam Stok</span>
            <strong><?= number_format((float)$totalStockAmount, 2, ',', '.') ?></strong>
        </div>

        <div class="card danger">
            <span>Kritik Stok</span>
            <strong><?= (int)$lowStockProductCount ?></strong>
        </div>

        <div class="card">
            <span>Stok Satış Değeri</span>
            <strong><?= number_format((float)$totalStockSaleValue, 2, ',', '.') ?> ₺</strong>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>İşlem</p>
                <h2>Ürün İşlemleri</h2>
            </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" onclick="window.location.href='index.php?page=products&mode=add'">
                Yeni Ürün Ekle
            </button>

            <button type="button" onclick="window.location.href='product_import.php'">
                Excel’den Ürün Aktar
            </button>

            <form method="post" onsubmit="return confirm('Tüm ürünler silinecek. Bu işlem geri alınamaz. Emin misin?');" style="display:inline;">
                <input type="hidden" name="action" value="delete_all_products">
                <button type="submit" class="danger-btn">
                    Tüm Ürünleri Sil
                </button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Arama</p>
                <h2>Ürün Ara</h2>
            </div>
        </div>

        <form method="get" class="grid-form">
            <input type="hidden" name="page" value="products">

            <div class="full">
                <label>Ürün adı, stok kodu, barkod veya kategori</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Ürün ara...">
            </div>

            <button type="submit">Ara</button>

            <?php if ($search !== ''): ?>
                <button type="button" onclick="window.location.href='index.php?page=products'">
                    Temizle
                </button>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($searchTooShort): ?>
        <section class="panel">
            <div class="alert error">Ürün araması için en az 2 karakter yazmalısın.</div>
        </section>
    <?php elseif ($search === ''): ?>
        <section class="panel">
            <div class="alert">Performans için ilk açılışta sadece son <?= (int)$productsLimit ?> ürün gösteriliyor. Daha fazla sonuç için arama yap.</div>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="alert">Arama sonucunda en fazla <?= (int)$productsLimit ?> ürün gösteriliyor.</div>
        </section>
    <?php endif; ?>

    <?php if ($mode === 'add' || $editProduct): ?>
        <section class="panel">
            <div class="section-title">
                <div>
                    <p><?= $editProduct ? 'Düzenle' : 'Kayıt' ?></p>
                    <h2><?= $editProduct ? 'Ürün Güncelle' : 'Yeni Ürün Ekle' ?></h2>
                </div>
            </div>

            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="<?= $editProduct ? 'update_product' : 'add_product' ?>">
                <?php if ($editProduct): ?>
                    <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
                <?php endif; ?>

                <div>
                    <label>Ürün Adı</label>
                    <input type="text" name="name" required value="<?= e($editProduct['name'] ?? '') ?>">
                </div>

                <div>
                    <label>Stok Kodu</label>
                    <input type="text" name="stock_code" value="<?= e($editProduct['stock_code'] ?? '') ?>">
                </div>

                <div>
                    <label>Barkod</label>
                    <input type="text" name="barcode" value="<?= e($editProduct['barcode'] ?? '') ?>">
                </div>

                <div>
                    <label>Kategori</label>
                    <input type="text" name="category" value="<?= e($editProduct['category'] ?? '') ?>">
                </div>

                <div>
                    <label>Alış Fiyatı</label>
                    <input type="number" step="0.01" name="purchase_price" value="<?= e((string)($editProduct['purchase_price'] ?? '0')) ?>">
                </div>

                <div>
                    <label>Alış Para Birimi</label>
                    <?php $pc = $editProduct['purchase_currency'] ?? 'TRY'; ?>
                    <select name="purchase_currency">
                        <option value="TRY" <?= $pc === 'TRY' ? 'selected' : '' ?>>TRY</option>
                        <option value="USD" <?= $pc === 'USD' ? 'selected' : '' ?>>USD</option>
                        <option value="EUR" <?= $pc === 'EUR' ? 'selected' : '' ?>>EUR</option>
                    </select>
                </div>

                <div>
                    <label>Satış Fiyatı</label>
                    <input type="number" step="0.01" name="sale_price_original" value="<?= e((string)($editProduct['sale_price_original'] ?? $editProduct['sale_price'] ?? '0')) ?>">
                </div>

                <div>
                    <label>Satış Para Birimi</label>
                    <?php $sc = $editProduct['sale_currency'] ?? 'TRY'; ?>
                    <select name="sale_currency">
                        <option value="TRY" <?= $sc === 'TRY' ? 'selected' : '' ?>>TRY</option>
                        <option value="USD" <?= $sc === 'USD' ? 'selected' : '' ?>>USD</option>
                        <option value="EUR" <?= $sc === 'EUR' ? 'selected' : '' ?>>EUR</option>
                    </select>
                </div>

                <div>
                    <label>KDV %</label>
                    <input type="number" step="0.01" name="vat_rate" value="<?= e((string)($editProduct['vat_rate'] ?? '20')) ?>">
                </div>

                <div>
                    <label>Stok</label>
                    <input type="number" step="0.01" name="stock" value="<?= e((string)($editProduct['stock'] ?? '0')) ?>">
                </div>

                <div>
                    <label>Birim</label>
                    <?php $unit = $editProduct['unit'] ?? 'adet'; ?>
                    <select name="unit">
                        <option value="adet" <?= $unit === 'adet' ? 'selected' : '' ?>>adet</option>
                        <option value="kg" <?= $unit === 'kg' ? 'selected' : '' ?>>kg</option>
                        <option value="litre" <?= $unit === 'litre' ? 'selected' : '' ?>>litre</option>
                        <option value="galon" <?= $unit === 'galon' ? 'selected' : '' ?>>galon</option>
                        <option value="metre" <?= $unit === 'metre' ? 'selected' : '' ?>>metre</option>
                    </select>
                </div>

                <div>
                    <label>Kritik Stok</label>
                    <input type="number" step="0.01" name="min_stock" value="<?= e((string)($editProduct['min_stock'] ?? '3')) ?>">
                </div>

                <button type="submit"><?= $editProduct ? 'Ürün Güncelle' : 'Ürün Ekle' ?></button>
                <button type="button" onclick="window.location.href='index.php?page=products'">Vazgeç</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Liste</p>
                <h2>Ürün Listesi / Arama Sonuçları</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Ürün</th>
                        <th>Kategori</th>
                        <th>Stok</th>
                        <th>Alış</th>
                        <th>Alış Para</th>
                        <th>Alış TL</th>
                        <th>Satış</th>
                        <th>Satış Para</th>
                        <th>Satış TL</th>
                        <th>KDV</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr class="<?= ((float)$p['stock'] <= (float)$p['min_stock']) ? 'low-stock' : '' ?>">
                            <td><?= e($p['name']) ?></td>
                            <td><?= e($p['category']) ?></td>
                            <td><?= number_format((float)$p['stock'], 2, ',', '.') ?> <?= e($p['unit']) ?></td>
                            <td><?= number_format((float)$p['purchase_price'], 2, ',', '.') ?></td>
                            <td><?= e($p['purchase_currency'] ?? 'TRY') ?></td>
                            <td><?= number_format((float)($p['purchase_price_try'] ?? $p['purchase_price']), 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($p['sale_price_original'] ?? $p['sale_price']), 2, ',', '.') ?></td>
                            <td><?= e($p['sale_currency'] ?? 'TRY') ?></td>
                            <td><?= number_format((float)$p['sale_price'], 2, ',', '.') ?> ₺</td>
                            <td>%<?= number_format((float)$p['vat_rate'], 2, ',', '.') ?></td>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="small" onclick="window.location.href='index.php?page=product_stock&id=<?= (int)$p['id'] ?>'">
                                        Stok Detay
                                    </button>

                                    <button type="button" class="small" onclick="window.location.href='index.php?page=products&mode=edit&id=<?= (int)$p['id'] ?>'">
                                        Düzenle
                                    </button>

                                    <form method="post" onsubmit="return confirm('Ürün silinsin mi?')">
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button class="small danger-btn">Sil</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$products): ?>
                        <tr>
                            <td colspan="11">Ürün bulunamadı.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}


/*
|--------------------------------------------------------------------------
| TEKLİFLER
|--------------------------------------------------------------------------
*/

if ($page === 'quotes') {
    ensureQuoteTables();
    ensureDiscountColumns();

    renderHeader('Teklifler');

    $customers = db()->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();

    $quotes = db()->query("
        SELECT 
            q.*,
            u.name AS user_name,
            COUNT(qi.id) AS item_count
        FROM quotes q
        LEFT JOIN users u ON u.id = q.created_by
        LEFT JOIN quote_items qi ON qi.quote_id = q.id
        GROUP BY q.id
        ORDER BY q.id DESC
        LIMIT 100
    ")->fetchAll();
?>

    <style>
        .quote-builder { display: grid; grid-template-columns: 1fr 360px; gap: 14px; align-items: start; overflow: visible; position: relative; z-index: 50; }
        .product-picker-panel, .quote-form-card, .quote-lines, .quote-line { overflow: visible !important; }
        .product-picker-panel { position: relative; z-index: 40; margin-bottom: 34px; }
        .quote-line:focus-within { position: relative; z-index: 99999; }
        .quote-form-card { border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.32); padding: 16px; }
        .quote-header-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .quote-header-grid .full { grid-column: 1 / -1; }
        .quote-lines { display: flex; flex-direction: column; gap: 10px; }
        .quote-line { display: grid; grid-template-columns: minmax(220px, 1.4fr) 74px 100px 82px 90px 74px 116px 42px; gap: 10px; align-items: end; padding: 12px; border-radius: 18px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.42); }
        .product-search-box { position: relative; z-index: 10000; }
        .product-search-results { position: absolute; left: 0; right: 0; top: calc(100% + 7px); z-index: 999999; display: none; max-height: 300px; overflow-y: auto; padding: 8px; border-radius: 16px; background: var(--bg-panel-2); border: 1px solid var(--border-strong); box-shadow: var(--shadow); }
        .product-search-results.active { display: flex; flex-direction: column; gap: 7px; }
        .product-result { width: 100%; min-height: 54px; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; gap: 4px; padding: 10px 12px; border-radius: 13px; background: rgba(15, 23, 42, 0.55); border: 1px solid rgba(148, 163, 184, 0.14); color: var(--text); box-shadow: none; text-align: left; }
        .product-result:hover, .product-result.keyboard-active { background: rgba(56, 189, 248, 0.18); border-color: rgba(56, 189, 248, 0.38); transform: none; outline: 2px solid rgba(250, 204, 21, 0.32); }
        .product-result strong { font-size: 13px; font-weight: 800; }
        .product-result span, .selected-product-info { color: var(--muted); font-size: 12px; font-weight: 600; }
        .product-no-result { padding: 12px; color: var(--muted); font-size: 13px; }
        .line-total-preview { min-height: 40px; display: flex; align-items: center; justify-content: flex-end; padding: 0 11px; border-radius: 13px; border: 1px solid rgba(148, 163, 184, 0.16); background: rgba(15, 23, 42, 0.72); color: var(--text); font-weight: 800; white-space: nowrap; }
        .remove-line-btn { width: 40px; min-width: 40px; padding: 0; background: rgba(251, 113, 133, 0.13); color: #fecdd3; border: 1px solid rgba(251, 113, 133, 0.18); box-shadow: none; }
        .quote-actions-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .add-line-btn { background: rgba(56, 189, 248, 0.13); color: #bae6fd; border: 1px solid rgba(56, 189, 248, 0.18); box-shadow: none; }
        .quote-summary { position: sticky; top: 100px; border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.14); background: radial-gradient(circle at top right, rgba(56, 189, 248, 0.13), transparent 34%), rgba(15, 23, 42, 0.48); padding: 16px; }
        .quote-summary h3 { margin: 0 0 14px; color: var(--text); font-size: 17px; letter-spacing: -0.04em; }
        .quote-summary-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(148, 163, 184, 0.12); }
        .quote-summary-row span:first-child { color: var(--muted); font-weight: 700; }
        .quote-summary-row span:last-child { color: var(--text); font-weight: 900; }
        .quote-summary-row.grand { border-bottom: 0; margin-top: 4px; padding: 14px; border-radius: 16px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.18); }
        .quote-summary-row.grand span:last-child { font-size: 20px; color: var(--accent); }
        .quote-list-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        @media (max-width: 1200px) { .quote-builder { grid-template-columns: 1fr; } .quote-summary { position: relative; top: 0; } .quote-line { grid-template-columns: 1fr 1fr; } .remove-line-btn { width: 100%; } }
        @media (max-width: 700px) { .quote-header-grid, .quote-line { grid-template-columns: 1fr; } }
    </style>

    <?php if ($error): ?>
        <section class="panel"><div class="alert error"><?= e($error) ?></div></section>
    <?php endif; ?>

    <section class="panel product-picker-panel">
        <div class="section-title"><div><p>Teklif</p><h2>Çoklu Ürünlü Teklif Oluştur</h2></div></div>
        <form method="post" id="quoteForm">
            <input type="hidden" name="action" value="add_quote">
            <div class="quote-builder">
                <div class="quote-form-card">
                    <div class="quote-header-grid">
                        <div>
                            <label>Kayıtlı Müşteri</label>
                            <select name="customer_id">
                                <option value="">Seçiniz</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Elle Müşteri Adı</label><input type="text" name="customer_name" placeholder="Kayıtlı değilse yaz"></div>
                        <div><label>Geçerlilik Tarihi</label><input type="date" name="valid_until" value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
                        <div><label>Genel İskonto %</label><input type="number" step="0.01" min="0" max="100" name="quote_discount_rate" id="quoteDiscountRate" value="0"></div>
                        <div><label>Genel İskonto ₺</label><input type="number" step="0.01" min="0" name="quote_discount_amount" id="quoteDiscountAmount" value="0"></div>
                        <div class="full"><label>Teklif Notu</label><textarea name="note" placeholder="Ödeme, teslimat, iskonto veya özel not yazabilirsin."></textarea></div>
                    </div>
                    <div class="section-title" style="margin-top:6px;"><div><p>Kalemler</p><h2>Ürün Satırları</h2></div></div>
                    <div class="quote-lines" id="quoteLines">
                        <div class="quote-line">
                            <div class="product-search-box">
                                <label>Ürün Ara / Seç</label>
                                <input type="text" class="quote-product-search" placeholder="En az 2 harf yaz..." autocomplete="off">
                                <input type="hidden" name="product_id[]" class="quote-product-id">
                                <div class="product-search-results quote-product-results"></div>
                                <small class="selected-product-info">Ürün seçilmedi.</small>
                            </div>
                            <div><label>Miktar</label><input type="number" step="0.01" min="0.01" name="quantity[]" class="quote-qty" value="1"></div>
                            <div><label>Birim Fiyat</label><input type="number" step="0.01" min="0" name="unit_price[]" class="quote-price" value="0"></div>
                            <div><label>İskonto %</label><input type="number" step="0.01" min="0" max="100" name="item_discount_rate[]" class="quote-discount" value="0"></div>
                            <div><label>İskonto ₺</label><input type="number" step="0.01" min="0" name="item_discount_amount[]" class="quote-discount-amount" value="0"></div>
                            <div><label>KDV %</label><input type="number" step="0.01" min="0" name="vat_rate[]" class="quote-vat" value="20"></div>
                            <div><label>Satır Toplam</label><div class="line-total-preview">0,00 ₺</div></div>
                            <button type="button" class="remove-line-btn" title="Satırı Sil">×</button>
                        </div>
                    </div>
                    <div class="quote-actions-row"><button type="button" class="add-line-btn" id="addQuoteLine">+ Ürün Satırı Ekle</button><button type="submit">Teklif Kaydet</button></div>
                </div>
                <aside class="quote-summary">
                    <h3>Teklif Özeti</h3>
                    <div class="quote-summary-row"><span>Ürün Satırı</span><span id="quoteLineCount">0</span></div>
                    <div class="quote-summary-row"><span>Ara Toplam</span><span id="quoteSubtotal">0,00 ₺</span></div>
                    <div class="quote-summary-row"><span>KDV Toplam</span><span id="quoteVatTotal">0,00 ₺</span></div>
                    <div class="quote-summary-row"><span>İskonto Toplam</span><span id="quoteDiscountTotal">0,00 ₺</span></div>
                    <div class="quote-summary-row grand"><span>Genel Toplam</span><span id="quoteGrandTotal">0,00 ₺</span></div>
                </aside>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Liste</p><h2>Son 100 Teklif</h2></div></div>
        <div class="table-wrap"><table><thead><tr><th>Teklif No</th><th>Müşteri</th><th>Kalem</th><th>Ara Toplam</th><th>KDV</th><th>İskonto</th><th>Genel Toplam</th><th>Durum</th><th>Hazırlayan</th><th>Tarih</th><th>İşlem</th></tr></thead><tbody>
            <?php foreach ($quotes as $q): ?>
                <tr>
                    <td><?= e($q['quote_no']) ?></td><td><?= e($q['customer_name']) ?></td><td><?= (int)$q['item_count'] ?></td>
                    <td><?= number_format((float)$q['subtotal'], 2, ',', '.') ?> ₺</td><td><?= number_format((float)$q['vat_total'], 2, ',', '.') ?> ₺</td><td><?= number_format((float)($q['discount_total'] ?? 0), 2, ',', '.') ?> ₺</td><td><?= number_format((float)$q['grand_total'], 2, ',', '.') ?> ₺</td>
                    <td><?= e($q['status']) ?></td><td><?= e($q['prepared_by']) ?></td><td><?= e($q['created_at']) ?></td>
                    <td><div class="quote-list-actions"><button type="button" class="small" onclick="window.open('quote_pdf.php?id=<?= (int)$q['id'] ?>', '_blank')">PDF Görüntüle</button><?php if (($q['status'] ?? '') !== 'converted'): ?><form method="post" onsubmit="return confirm('Bu teklif satışa çevrilsin mi? Stoklar düşülecek.')"><input type="hidden" name="action" value="convert_quote_to_sale"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="payment_type" value="nakit"><button class="small">Satışa Çevir</button></form><?php endif; ?><?php if (isAdmin()): ?><form method="post" onsubmit="return confirm('Teklif silinsin mi?')"><input type="hidden" name="action" value="delete_quote"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><button class="small danger-btn">Sil</button></form><?php endif; ?></div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$quotes): ?><tr><td colspan="10">Henüz teklif yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <script>
        const quoteLines = document.getElementById('quoteLines');
        const addQuoteLineButton = document.getElementById('addQuoteLine');
        const quoteLineCount = document.getElementById('quoteLineCount');
        const quoteSubtotal = document.getElementById('quoteSubtotal');
        const quoteVatTotal = document.getElementById('quoteVatTotal');
        const quoteDiscountTotal = document.getElementById('quoteDiscountTotal');
        const quoteGrandTotal = document.getElementById('quoteGrandTotal');
        const quoteDiscountRate = document.getElementById('quoteDiscountRate');
        const quoteDiscountAmount = document.getElementById('quoteDiscountAmount');
        const quoteForm = document.getElementById('quoteForm');
        function formatQuoteTL(value){return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value)+' ₺';}
        function formatQuoteNumber(value){return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value);}
        function escapeQuoteHtml(value){return String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');}
        function calculateQuote(){let subtotal=0,vatTotal=0,grandTotal=0,discountTotal=0,activeLines=0;quoteLines.querySelectorAll('.quote-line').forEach((line)=>{const productId=line.querySelector('.quote-product-id');const qty=parseFloat(line.querySelector('.quote-qty').value||'0');const price=parseFloat(line.querySelector('.quote-price').value||'0');const vat=parseFloat(line.querySelector('.quote-vat').value||'0');const discountRate=Math.max(0,Math.min(parseFloat(line.querySelector('.quote-discount').value||'0'),100));const discountAmountInput=Math.max(0,parseFloat(line.querySelector('.quote-discount-amount').value||'0'));const lineBase=qty*price;const percentDiscount=lineBase*(discountRate/100);const amountDiscount=Math.min(discountAmountInput,Math.max(lineBase-percentDiscount,0));const lineDiscount=percentDiscount+amountDiscount;const lineSubtotal=Math.max(lineBase-lineDiscount,0);const lineVat=lineSubtotal*(vat/100);let lineTotal=lineSubtotal+lineVat;if(productId.value&&qty>0){activeLines++;}subtotal+=lineSubtotal;vatTotal+=lineVat;discountTotal+=lineDiscount;grandTotal+=lineTotal;line.querySelector('.line-total-preview').textContent=formatQuoteTL(lineTotal);});const globalDiscountRate=Math.max(0,Math.min(parseFloat(quoteDiscountRate.value||'0'),100));const globalPercentDiscount=grandTotal*(globalDiscountRate/100);const globalAmountDiscount=Math.max(0,Math.min(parseFloat(quoteDiscountAmount.value||'0'),Math.max(grandTotal-globalPercentDiscount,0)));const globalDiscount=globalPercentDiscount+globalAmountDiscount;discountTotal+=globalDiscount;grandTotal=Math.max(grandTotal-globalDiscount,0);quoteLineCount.textContent=String(activeLines);quoteSubtotal.textContent=formatQuoteTL(subtotal);quoteVatTotal.textContent=formatQuoteTL(vatTotal);quoteDiscountTotal.textContent=formatQuoteTL(discountTotal);quoteGrandTotal.textContent=formatQuoteTL(grandTotal);}
        function closeQuoteResults(line){const results=line.querySelector('.quote-product-results');results.classList.remove('active');results.innerHTML='';line.keyboardIndex=-1;}
        function openQuoteResults(line){line.querySelector('.quote-product-results').classList.add('active');}
        function updateQuoteKeyboardActive(line){const buttons=Array.from(line.querySelectorAll('.quote-product-results .product-result'));buttons.forEach((button,index)=>{button.classList.toggle('keyboard-active',index===line.keyboardIndex);});if(buttons[line.keyboardIndex]){buttons[line.keyboardIndex].scrollIntoView({block:'nearest'});}}
        function selectQuoteProduct(line,item){const search=line.querySelector('.quote-product-search');const productId=line.querySelector('.quote-product-id');const info=line.querySelector('.selected-product-info');const priceInput=line.querySelector('.quote-price');const vatInput=line.querySelector('.quote-vat');const name=item.name||'';const price=Number(item.sale_price||0);const stock=Number(item.stock||0);const unit=item.unit||'';const vat=Number(item.vat_rate||0);productId.value=String(item.id);search.value=name;priceInput.value=price.toFixed(2);vatInput.value=vat.toFixed(2);info.textContent=name+' seçildi. Stok: '+formatQuoteNumber(stock)+' '+unit;closeQuoteResults(line);calculateQuote();}
        function renderQuoteProducts(line,items){const results=line.querySelector('.quote-product-results');results.innerHTML='';line.keyboardItems=items||[];line.keyboardIndex=-1;if(!items.length){results.innerHTML='<div class="product-no-result">Sonuç bulunamadı.</div>';openQuoteResults(line);return;}items.forEach((item,index)=>{const button=document.createElement('button');button.type='button';button.className='product-result';button.dataset.index=String(index);const name=item.name||'';const stockCode=item.stock_code||'-';const category=item.category||'-';const price=Number(item.sale_price||0);const stock=Number(item.stock||0);const unit=item.unit||'';button.innerHTML=`<strong>${escapeQuoteHtml(name)}</strong><span>Kod: ${escapeQuoteHtml(stockCode)} · Kategori: ${escapeQuoteHtml(category)} · ${formatQuoteTL(price)} · Stok: ${formatQuoteNumber(stock)} ${escapeQuoteHtml(unit)}</span>`;button.addEventListener('mouseenter',function(){line.keyboardIndex=index;updateQuoteKeyboardActive(line);});button.addEventListener('click',function(){selectQuoteProduct(line,item);});results.appendChild(button);});line.keyboardIndex=0;openQuoteResults(line);updateQuoteKeyboardActive(line);}
        function searchQuoteProducts(line,query){if(line.searchTimer){clearTimeout(line.searchTimer);}if(line.activeRequestController){line.activeRequestController.abort();}line.searchTimer=setTimeout(function(){const results=line.querySelector('.quote-product-results');line.activeRequestController=new AbortController();results.innerHTML='<div class="product-no-result">Aranıyor...</div>';openQuoteResults(line);fetch('api_products_search.php?q='+encodeURIComponent(query),{signal:line.activeRequestController.signal}).then((response)=>response.json()).then((data)=>{if(!data.success){results.innerHTML='<div class="product-no-result">Arama hatası oluştu.</div>';return;}renderQuoteProducts(line,data.items||[]);}).catch((error)=>{if(error.name==='AbortError'){return;}results.innerHTML='<div class="product-no-result">Bağlantı hatası.</div>';openQuoteResults(line);});},250);}
        function bindQuoteLine(line){const search=line.querySelector('.quote-product-search');const productId=line.querySelector('.quote-product-id');const info=line.querySelector('.selected-product-info');const qtyInput=line.querySelector('.quote-qty');const priceInput=line.querySelector('.quote-price');const discountInput=line.querySelector('.quote-discount');const discountAmountInput=line.querySelector('.quote-discount-amount');const vatInput=line.querySelector('.quote-vat');const removeBtn=line.querySelector('.remove-line-btn');search.addEventListener('input',function(){const query=search.value.trim();productId.value='';info.textContent='Ürün seçilmedi.';calculateQuote();if(query.length<2){closeQuoteResults(line);return;}searchQuoteProducts(line,query);});search.addEventListener('keydown',function(event){const results=line.querySelector('.quote-product-results');const buttons=Array.from(results.querySelectorAll('.product-result'));if(!results.classList.contains('active')||!buttons.length){return;}if(event.key==='ArrowDown'){event.preventDefault();line.keyboardIndex=Math.min((line.keyboardIndex ?? -1)+1,buttons.length-1);updateQuoteKeyboardActive(line);}else if(event.key==='ArrowUp'){event.preventDefault();line.keyboardIndex=Math.max((line.keyboardIndex ?? buttons.length)-1,0);updateQuoteKeyboardActive(line);}else if(event.key==='Enter'){event.preventDefault();const selectedIndex=line.keyboardIndex>=0?line.keyboardIndex:0;const item=(line.keyboardItems||[])[selectedIndex];if(item){selectQuoteProduct(line,item);}}else if(event.key==='Escape'){event.preventDefault();closeQuoteResults(line);}});qtyInput.addEventListener('input',calculateQuote);priceInput.addEventListener('input',calculateQuote);discountInput.addEventListener('input',calculateQuote);discountAmountInput.addEventListener('input',calculateQuote);vatInput.addEventListener('input',calculateQuote);removeBtn.addEventListener('click',function(){const lines=quoteLines.querySelectorAll('.quote-line');if(lines.length<=1){search.value='';productId.value='';info.textContent='Ürün seçilmedi.';qtyInput.value='1';priceInput.value='0';discountInput.value='0';discountAmountInput.value='0';vatInput.value='20';closeQuoteResults(line);calculateQuote();return;}line.remove();calculateQuote();});}
        function addQuoteLine(){const firstLine=quoteLines.querySelector('.quote-line');const clone=firstLine.cloneNode(true);clone.querySelector('.quote-product-search').value='';clone.querySelector('.quote-product-id').value='';clone.querySelector('.quote-product-results').innerHTML='';clone.querySelector('.quote-product-results').classList.remove('active');clone.querySelector('.selected-product-info').textContent='Ürün seçilmedi.';clone.querySelector('.quote-qty').value='1';clone.querySelector('.quote-price').value='0';clone.querySelector('.quote-discount').value='0';clone.querySelector('.quote-discount-amount').value='0';clone.querySelector('.quote-vat').value='20';clone.querySelector('.line-total-preview').textContent='0,00 ₺';quoteLines.appendChild(clone);bindQuoteLine(clone);calculateQuote();}
        quoteLines.querySelectorAll('.quote-line').forEach(bindQuoteLine);addQuoteLineButton.addEventListener('click',addQuoteLine);quoteDiscountRate.addEventListener('input',calculateQuote);quoteDiscountAmount.addEventListener('input',calculateQuote);document.addEventListener('click',function(event){if(!event.target.closest('.product-search-box')){quoteLines.querySelectorAll('.quote-line').forEach(closeQuoteResults);}});quoteForm.addEventListener('submit',function(event){const selectedProducts=Array.from(quoteLines.querySelectorAll('.quote-product-id')).filter((input)=>input.value);if(!selectedProducts.length){event.preventDefault();alert('Lütfen teklife en az 1 ürün ekle.');quoteLines.querySelector('.quote-product-search').focus();}});calculateQuote();
    </script>

    <?php
    renderFooter();
    exit;
}



/*
|--------------------------------------------------------------------------
| TEZGAH / PASİF SATIŞ
|--------------------------------------------------------------------------
*/

if ($page === 'passive_sales') {
    $editPassiveSale = null;
    $editPassiveItems = [];
    $editPassiveId = isAdmin() ? (int)($_GET['edit_id'] ?? 0) : 0;
    $editPassiveNote = '';

ensureSalesPaymentColumns();
    ensureDiscountColumns();

    if ($editPassiveId > 0) {
        $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? AND note LIKE '[PASIF_SATIS]%'");
        $stmt->execute([$editPassiveId]);
        $editPassiveSale = $stmt->fetch();

        if ($editPassiveSale) {
            $stmt = db()->prepare("
                SELECT
                    si.*,
                    p.stock_code,
                    p.category,
                    p.stock,
                    p.unit,
                    p.sale_price AS current_sale_price
                FROM sale_items si
                LEFT JOIN products p ON p.id = si.product_id
                WHERE si.sale_id = ?
                ORDER BY si.id ASC
            ");
            $stmt->execute([$editPassiveId]);
            $editPassiveItems = $stmt->fetchAll();

            $rawNote = (string)($editPassiveSale['note'] ?? '');
            $prefix = '[PASIF_SATIS] Günlük tezgah satışı';
            if (strpos($rawNote, $prefix . ' - ') === 0) {
                $editPassiveNote = substr($rawNote, strlen($prefix . ' - '));
            }
        }
    }

    if (!$editPassiveSale) {
        $editPassiveId = 0;
        $editPassiveItems = [];
        $editPassiveNote = '';
    }


    renderHeader('Tezgah Satışı');

    $todayPassiveTotal = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at) = CURDATE() AND note LIKE '[PASIF_SATIS]%'")->fetchColumn();
    $todayPassiveCount = db()->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at) = CURDATE() AND note LIKE '[PASIF_SATIS]%'")->fetchColumn();

    $todayByPayment = db()->query("
        SELECT payment_type, COALESCE(SUM(total_amount), 0) AS total_amount, COUNT(*) AS sale_count
        FROM sales
        WHERE DATE(created_at) = CURDATE()
          AND note LIKE '[PASIF_SATIS]%'
        GROUP BY payment_type
        ORDER BY total_amount DESC
    ")->fetchAll();

    $passiveSales = db()->query("
        SELECT
            s.*,
            u.name AS user_name,
            (SELECT COUNT(*) FROM sale_items si_count WHERE si_count.sale_id = s.id) AS item_count,
            (SELECT GROUP_CONCAT(CONCAT(REPLACE(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(si_names.quantity AS CHAR))), ',', '.'), ' x ', si_names.product_name) ORDER BY si_names.id SEPARATOR ', ') FROM sale_items si_names WHERE si_names.sale_id = s.id) AS product_names
        FROM sales s
        LEFT JOIN users u ON u.id = s.created_by
        WHERE DATE(s.created_at) = CURDATE()
          AND s.note LIKE '[PASIF_SATIS]%'
        ORDER BY s.id DESC
        LIMIT 200
    ")->fetchAll();

    $editPassiveSale = $editPassiveSale ?? null;
    $editPassiveItems = $editPassiveItems ?? [];
    $editPassiveId = $editPassiveId ?? 0;
    $editPassiveNote = $editPassiveNote ?? '';

    ?>

    <style>
        .product-search-box { position: relative; z-index: 10000; }
        .product-search-results { position: absolute; left: 0; right: 0; top: calc(100% + 7px); z-index: 999999; display: none; max-height: 300px; overflow-y: auto; padding: 8px; border-radius: 16px; background: var(--bg-panel-2); border: 1px solid var(--border-strong); box-shadow: var(--shadow); }
        .product-search-results.active { display: flex; flex-direction: column; gap: 7px; }
        .product-result { width: 100%; min-height: 54px; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; gap: 4px; padding: 10px 12px; border-radius: 13px; background: rgba(15, 23, 42, 0.55); border: 1px solid rgba(148, 163, 184, 0.14); color: var(--text); box-shadow: none; text-align: left; }
        .product-result:hover, .product-result.keyboard-active { background: rgba(56, 189, 248, 0.18); border-color: rgba(56, 189, 248, 0.38); transform: none; outline: 2px solid rgba(250, 204, 21, 0.32); }
        .product-result strong { font-size: 13px; font-weight: 800; }
        .product-result span, .selected-product-info { color: var(--muted); font-size: 12px; font-weight: 600; }
        .product-no-result { padding: 12px; color: var(--muted); font-size: 13px; }
        .passive-builder { display: grid; grid-template-columns: 1fr 320px; gap: 14px; align-items: start; overflow: visible; position: relative; z-index: 50; }
        .passive-form-card, .passive-lines, .passive-line { overflow: visible !important; }
        .passive-form-card { border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.32); padding: 16px; }
        .passive-header-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .passive-header-grid .full { grid-column: 1 / -1; }
        .passive-lines { display: flex; flex-direction: column; gap: 10px; }
        .passive-line { display: grid; grid-template-columns: minmax(220px, 1.4fr) 82px 108px 82px 90px 124px 42px; gap: 10px; align-items: end; padding: 12px; border-radius: 18px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.42); }
        .line-total-preview { min-height: 40px; display: flex; align-items: center; justify-content: flex-end; padding: 0 11px; border-radius: 13px; border: 1px solid rgba(148, 163, 184, 0.16); background: rgba(15, 23, 42, 0.72); color: var(--text); font-weight: 800; white-space: nowrap; }
        .remove-line-btn { width: 40px; min-width: 40px; padding: 0; background: rgba(251, 113, 133, 0.13); color: #fecdd3; border: 1px solid rgba(251, 113, 133, 0.18); box-shadow: none; }
        .passive-actions-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .add-line-btn { background: rgba(56, 189, 248, 0.13); color: #bae6fd; border: 1px solid rgba(56, 189, 248, 0.18); box-shadow: none; }
        .passive-summary { position: sticky; top: 100px; border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.14); background: radial-gradient(circle at top right, rgba(56, 189, 248, 0.13), transparent 34%), rgba(15, 23, 42, 0.48); padding: 16px; }
        .passive-summary h3 { margin: 0 0 14px; color: var(--text); font-size: 17px; letter-spacing: -0.04em; }
        .passive-summary-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(148, 163, 184, 0.12); }
        .passive-summary-row span:first-child { color: var(--muted); font-weight: 700; }
        .passive-summary-row span:last-child { color: var(--text); font-weight: 900; }
        .passive-summary-row.grand { border-bottom: 0; margin-top: 4px; padding: 14px; border-radius: 16px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.18); }
        .passive-summary-row.grand span:last-child { font-size: 20px; color: var(--accent); }
        .sale-list-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        @media (max-width: 1200px) { .passive-builder { grid-template-columns: 1fr; } .passive-summary { position: relative; top: 0; } .passive-line { grid-template-columns: 1fr 1fr; } .remove-line-btn { width: 100%; } }
        @media (max-width: 700px) { .passive-header-grid, .passive-line { grid-template-columns: 1fr; } }
    

        /* Tezgah Satışı ürün arama dropdown fix */
        .passive-sales-create-panel {
            position: relative;
            z-index: 5000;
            overflow: visible !important;
        }

        .passive-sales-list-panel {
            position: relative;
            z-index: 1;
        }

        #passiveForm,
        #passiveForm .passive-builder,
        #passiveForm .passive-form-card,
        #passiveForm .passive-lines,
        #passiveForm .passive-line,
        #passiveForm .product-search-box {
            overflow: visible !important;
        }

        #passiveForm .passive-builder {
            position: relative;
            z-index: 5000;
        }

        #passiveForm .passive-form-card {
            position: relative;
            z-index: 5100;
        }

        #passiveForm .passive-line {
            position: relative;
            z-index: 5200;
        }

        #passiveForm .passive-line:focus-within {
            z-index: 999999 !important;
        }

        #passiveForm .product-search-box {
            position: relative;
            z-index: 999999 !important;
        }

        #passiveForm .product-search-results {
            z-index: 2147483000 !important;
            max-height: 360px;
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.55);
        }

        #passiveForm .product-search-results.active {
            display: flex !important;
            flex-direction: column;
            gap: 7px;
        }

        .passive-lines {
            margin-bottom: 22px;
        }

        .passive-actions-row {
            position: relative;
            z-index: 10;
            margin-top: 18px;
        }

        @media (max-width: 900px) {
            #passiveForm .product-search-results {
                max-height: 300px;
            }
        }

</style>

    <?php if ($error): ?>
        <section class="panel"><div class="alert error"><?= e($error) ?></div></section>
    <?php endif; ?>

    <section class="cards dashboard-stats">
        <div class="card">
            <span>Bugünkü Tezgah Satışı</span>
            <strong><?= number_format((float)$todayPassiveTotal, 2, ',', '.') ?> ₺</strong>
        </div>
        <div class="card">
            <span>Pasif Satış Fişi</span>
            <strong><?= (int)$todayPassiveCount ?></strong>
        </div>
        <?php foreach ($todayByPayment as $row): ?>
            <div class="card">
                <span><?= e(strtoupper((string)$row['payment_type'])) ?></span>
                <strong><?= number_format((float)$row['total_amount'], 2, ',', '.') ?> ₺</strong>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel passive-sales-create-panel">
        <div class="section-title">
            <div>
                <p><?= $editPassiveSale ? 'Düzenleme' : 'Hızlı işlem' ?></p>
                <h2><?= $editPassiveSale ? 'Tezgah Satışı Düzenle' : 'Tezgah Satışı Ekle' ?></h2>
            </div>
            <?php if ($editPassiveSale): ?>
                <button type="button" class="small" onclick="window.location.href='index.php?page=passive_sales'">Düzenlemeyi Kapat</button>
            <?php endif; ?>
        </div>
        <div class="alert">
            <?= $editPassiveSale ? 'Bu tezgah satışını düzenliyorsun. Kaydedince eski stok hareketi geri alınır, yeni kalemlere göre stok tekrar düşer.' : 'Bu ekran isimsiz müşteriye küçük tezgah satışları içindir. Kaydedince stoktan düşer ve günlük ciroya eklenir.' ?>
        </div>

        <form method="post" id="passiveForm" style="margin-top:14px;">
            <input type="hidden" name="action" value="<?= $editPassiveSale ? 'update_passive_sale' : 'add_passive_sale' ?>">
            <input type="hidden" name="sale_id" value="<?= (int)$editPassiveId ?>">

            <div class="passive-builder">
                <div class="passive-form-card">
                    <div class="passive-header-grid">
                        <div>
                            <label>Müşteri</label>
                            <input type="text" value="İsimsiz Müşteri" readonly>
                        </div>
                        <div>
                            <label>Ödeme Tipi</label>
                            <select name="payment_type" id="passivePaymentType">
                                <?php $selectedPassivePayment = $editPassiveSale['payment_type'] ?? 'nakit'; ?>
                                <option value="nakit" <?= $selectedPassivePayment === 'nakit' ? 'selected' : '' ?>>Nakit</option>
                                <option value="kart" <?= $selectedPassivePayment === 'kart' ? 'selected' : '' ?>>Kart</option>
                                <option value="iban" <?= $selectedPassivePayment === 'iban' ? 'selected' : '' ?>>IBAN</option>
                                <option value="veresiye" <?= $selectedPassivePayment === 'veresiye' ? 'selected' : '' ?>>Veresiye</option>
                            </select>
                        </div>
                        <div>
                            <label>Ödenen Tutar</label>
                            <input type="number" step="0.01" min="0" name="paid_amount" id="passivePaidAmount" value="<?= $editPassiveSale ? e((string)($editPassiveSale['paid_amount'] ?? '0')) : '' ?>" placeholder="Boş kalırsa otomatik">
                        </div>
                        <div class="full">
                            <label>Not</label>
                            <textarea name="note" placeholder="Örn: tezgah, balık malzemesi, ufak satış..."><?= e($editPassiveNote) ?></textarea>
                        </div>
                    </div>

                    <div class="section-title" style="margin-top:6px;"><div><p>Kalemler</p><h2>Ürün Kalemleri</h2></div></div>

                    <div class="passive-lines" id="passiveLines">
                        <?php
                            $passiveFormItems = $editPassiveSale && $editPassiveItems ? $editPassiveItems : [[
                                'product_id' => '',
                                'product_name' => '',
                                'quantity' => 1,
                                'unit_price' => 0,
                                'discount_rate' => 0,
                                'discount_amount' => 0,
                                'line_total' => 0,
                                'stock' => '',
                                'unit' => '',
                            ]];
                        ?>

                        <?php foreach ($passiveFormItems as $formItem): ?>
                            <?php
                                $formProductName = (string)($formItem['product_name'] ?? '');
                                $formProductId = (int)($formItem['product_id'] ?? 0);
                                $formStock = $formItem['stock'] ?? '';
                                $formUnit = $formItem['unit'] ?? '';
                                $selectedInfo = $formProductId > 0
                                    ? ($formProductName . ' seçildi.' . ($formStock !== '' ? ' Stok: ' . number_format((float)$formStock, 2, ',', '.') . ' ' . $formUnit : ''))
                                    : 'Ürün seçilmedi.';
                            ?>
                            <div class="passive-line">
                                <div class="product-search-box">
                                    <label>Ürün Ara / Seç</label>
                                    <input type="text" class="passive-product-search" value="<?= e($formProductName) ?>" placeholder="Kurşun, çapari, iğne..." autocomplete="off">
                                    <input type="hidden" name="product_id[]" class="passive-product-id" value="<?= $formProductId > 0 ? (int)$formProductId : '' ?>">
                                    <div class="product-search-results passive-product-results"></div>
                                    <small class="selected-product-info"><?= e($selectedInfo) ?></small>
                                </div>
                                <div><label>Miktar</label><input type="number" step="0.01" min="0.01" name="quantity[]" class="passive-qty" value="<?= e((string)($formItem['quantity'] ?? 1)) ?>"></div>
                                <div><label>Birim Fiyat</label><input type="number" step="0.01" min="0" name="unit_price[]" class="passive-price" value="<?= e((string)($formItem['unit_price'] ?? 0)) ?>"></div>
                                <div><label>İskonto %</label><input type="number" step="0.01" min="0" max="100" name="item_discount_rate[]" class="passive-discount" value="<?= e((string)($formItem['discount_rate'] ?? 0)) ?>"></div>
                                <div><label>İskonto ₺</label><input type="number" step="0.01" min="0" name="item_discount_amount[]" class="passive-discount-amount" value="<?= e((string)($formItem['discount_amount'] ?? 0)) ?>"></div>
                                <div><label>Satır Toplam</label><div class="line-total-preview"><?= number_format((float)($formItem['line_total'] ?? 0), 2, ',', '.') ?> ₺</div></div>
                                <button type="button" class="remove-line-btn" title="Satırı Sil">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="passive-actions-row">
                        <button type="button" class="add-line-btn" id="addPassiveLine">+ Ürün Satırı Ekle</button>
                        <button type="submit"><?= $editPassiveSale ? 'Değişiklikleri Kaydet' : 'Tezgah Satışı Kaydet' ?></button>
                    </div>
                </div>

                <aside class="passive-summary">
                    <h3><?= $editPassiveSale ? 'Düzenleme Özeti' : 'Tezgah Satışı Özeti' ?></h3>
                    <div class="passive-summary-row"><span>Ürün Satırı</span><span id="passiveLineCount">0</span></div>
                    <div class="passive-summary-row"><span>Toplam Tutar</span><span id="passiveGrandTotal">0,00 ₺</span></div>
                    <div class="passive-summary-row"><span>İskonto Toplam</span><span id="passiveDiscountPreview">0,00 ₺</span></div>
                    <div class="passive-summary-row"><span>Ödenen</span><span id="passivePaidPreview">0,00 ₺</span></div>
                    <div class="passive-summary-row grand"><span>Kalan</span><span id="passiveRemainingPreview">0,00 ₺</span></div>
                </aside>
            </div>
        </form>
    </section>

    <section class="panel passive-sales-list-panel">
        <div class="section-title"><div><p>Bugün</p><h2>Bugünkü Tezgah Satışları</h2></div><div class="role-badge"><?= count($passiveSales) ?> kayıt</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Saat</th><th>No</th><th>Ürünler</th><th>Kalem</th><th>Toplam</th><th>Ödenen</th><th>Kalan</th><th>Ödeme</th><th>Personel</th><th>İşlem</th></tr></thead>
                <tbody>
                    <?php foreach ($passiveSales as $s): ?>
                        <tr>
                            <td><?= e(date('H:i', strtotime((string)$s['created_at']))) ?></td>
                            <td><?= e($s['sale_no']) ?></td>
                            <td><?= e($s['product_names'] ?? '-') ?></td>
                            <td><?= (int)($s['item_count'] ?? 0) ?></td>
                            <td><?= number_format((float)$s['total_amount'], 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($s['paid_amount'] ?? 0), 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($s['remaining_amount'] ?? 0), 2, ',', '.') ?> ₺</td>
                            <td><?= e($s['payment_type']) ?></td>
                            <td><?= e($s['user_name'] ?? '-') ?></td>
                            <td>
                                <div class="sale-list-actions">
                                    <button type="button" class="small" onclick="window.open('sale_pdf.php?id=<?= (int)$s['id'] ?>', '_blank')">Proforma</button>
                                    <?php if (isAdmin()): ?>
                                        <button type="button" class="small" onclick="window.location.href='index.php?page=passive_sales&edit_id=<?= (int)$s['id'] ?>'">Düzenle</button>
                                        <form method="post" onsubmit="return confirm('Tezgah satışı silinsin mi? Stok geri eklenecek.')">
                                            <input type="hidden" name="action" value="delete_passive_sale">
                                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="small danger-btn" type="submit">Sil</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$passiveSales): ?><tr><td colspan="10">Bugün pasif satış yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <script>
        const passiveLines = document.getElementById('passiveLines');
        const addPassiveLineButton = document.getElementById('addPassiveLine');
        const passiveForm = document.getElementById('passiveForm');
        const passiveLineCount = document.getElementById('passiveLineCount');
        const passiveGrandTotal = document.getElementById('passiveGrandTotal');
        const passivePaidAmount = document.getElementById('passivePaidAmount');
        const passivePaymentType = document.getElementById('passivePaymentType');
        const passiveDiscountPreview = document.getElementById('passiveDiscountPreview');
        const passivePaidPreview = document.getElementById('passivePaidPreview');
        const passiveRemainingPreview = document.getElementById('passiveRemainingPreview');

        function formatPassiveTL(value) { return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) + ' ₺'; }
        function formatPassiveNumber(value) { return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value); }
        function escapePassiveHtml(value) { return String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }

        function calculatePassive() {
            let total = 0;
            let discountTotal = 0;
            let activeLines = 0;

            passiveLines.querySelectorAll('.passive-line').forEach((line) => {
                const productId = line.querySelector('.passive-product-id');
                const qty = parseFloat(line.querySelector('.passive-qty').value || '0');
                const price = parseFloat(line.querySelector('.passive-price').value || '0');
                const discountRate = Math.max(0, Math.min(parseFloat(line.querySelector('.passive-discount').value || '0'), 100));
                const discountAmountInput = Math.max(0, parseFloat(line.querySelector('.passive-discount-amount').value || '0'));
                const lineBase = qty * price;
                const percentDiscount = lineBase * (discountRate / 100);
                const amountDiscount = Math.min(discountAmountInput, Math.max(lineBase - percentDiscount, 0));
                const lineDiscount = percentDiscount + amountDiscount;
                const lineTotal = Math.max(lineBase - lineDiscount, 0);

                if (productId.value && qty > 0) { activeLines++; }
                discountTotal += lineDiscount;
                total += lineTotal;
                line.querySelector('.line-total-preview').textContent = formatPassiveTL(lineTotal);
            });

            let paid = parseFloat(passivePaidAmount.value || '');
            if (Number.isNaN(paid)) {
                paid = passivePaymentType.value === 'veresiye' ? 0 : total;
            }
            paid = Math.max(0, Math.min(paid, total));
            const remaining = Math.max(total - paid, 0);

            passiveLineCount.textContent = String(activeLines);
            passiveGrandTotal.textContent = formatPassiveTL(total);
            passiveDiscountPreview.textContent = formatPassiveTL(discountTotal);
            passivePaidPreview.textContent = formatPassiveTL(paid);
            passiveRemainingPreview.textContent = formatPassiveTL(remaining);
        }

        function closePassiveResults(line) {
            const results = line.querySelector('.passive-product-results');
            results.classList.remove('active');
            results.innerHTML = '';
            line.keyboardIndex = -1;
        }

        function openPassiveResults(line) { line.querySelector('.passive-product-results').classList.add('active'); }

        function updatePassiveKeyboardActive(line) {
            const buttons = Array.from(line.querySelectorAll('.passive-product-results .product-result'));
            buttons.forEach((button, index) => button.classList.toggle('keyboard-active', index === line.keyboardIndex));
            if (buttons[line.keyboardIndex]) { buttons[line.keyboardIndex].scrollIntoView({ block: 'nearest' }); }
        }

        function selectPassiveProduct(line, item) {
            const search = line.querySelector('.passive-product-search');
            const productId = line.querySelector('.passive-product-id');
            const info = line.querySelector('.selected-product-info');
            const priceInput = line.querySelector('.passive-price');
            const name = item.name || '';
            const price = Number(item.sale_price || 0);
            const stock = Number(item.stock || 0);
            const unit = item.unit || '';
            productId.value = String(item.id);
            search.value = name;
            priceInput.value = price.toFixed(2);
            info.textContent = name + ' seçildi. Stok: ' + formatPassiveNumber(stock) + ' ' + unit;
            closePassiveResults(line);
            calculatePassive();
        }

        function renderPassiveProducts(line, items) {
            const results = line.querySelector('.passive-product-results');
            results.innerHTML = '';
            line.keyboardItems = items || [];
            line.keyboardIndex = -1;

            if (!items.length) {
                results.innerHTML = '<div class="product-no-result">Sonuç bulunamadı.</div>';
                openPassiveResults(line);
                return;
            }

            items.forEach((item, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-result';
                const name = item.name || '';
                const stockCode = item.stock_code || '-';
                const category = item.category || '-';
                const price = Number(item.sale_price || 0);
                const stock = Number(item.stock || 0);
                const unit = item.unit || '';
                button.innerHTML = `<strong>${escapePassiveHtml(name)}</strong><span>Kod: ${escapePassiveHtml(stockCode)} · Kategori: ${escapePassiveHtml(category)} · ${formatPassiveTL(price)} · Stok: ${formatPassiveNumber(stock)} ${escapePassiveHtml(unit)}</span>`;
                button.addEventListener('mouseenter', function () { line.keyboardIndex = index; updatePassiveKeyboardActive(line); });
                button.addEventListener('click', function () { selectPassiveProduct(line, item); });
                results.appendChild(button);
            });

            line.keyboardIndex = 0;
            openPassiveResults(line);
            updatePassiveKeyboardActive(line);
        }

        function searchPassiveProducts(line, query) {
            if (line.searchTimer) { clearTimeout(line.searchTimer); }
            if (line.activeRequestController) { line.activeRequestController.abort(); }

            line.searchTimer = setTimeout(function () {
                const results = line.querySelector('.passive-product-results');
                line.activeRequestController = new AbortController();
                results.innerHTML = '<div class="product-no-result">Aranıyor...</div>';
                openPassiveResults(line);

                fetch('api_products_search.php?q=' + encodeURIComponent(query), { signal: line.activeRequestController.signal })
                    .then((response) => response.json())
                    .then((data) => {
                        if (!data.success) {
                            results.innerHTML = '<div class="product-no-result">Arama hatası oluştu.</div>';
                            return;
                        }
                        renderPassiveProducts(line, data.items || []);
                    })
                    .catch((error) => {
                        if (error.name === 'AbortError') { return; }
                        results.innerHTML = '<div class="product-no-result">Bağlantı hatası.</div>';
                        openPassiveResults(line);
                    });
            }, 220);
        }

        function bindPassiveLine(line) {
            const search = line.querySelector('.passive-product-search');
            const productId = line.querySelector('.passive-product-id');
            const info = line.querySelector('.selected-product-info');
            const qtyInput = line.querySelector('.passive-qty');
            const priceInput = line.querySelector('.passive-price');
            const discountInput = line.querySelector('.passive-discount');
            const discountAmountInput = line.querySelector('.passive-discount-amount');
            const removeBtn = line.querySelector('.remove-line-btn');

            search.addEventListener('input', function () {
                const query = search.value.trim();
                productId.value = '';
                info.textContent = 'Ürün seçilmedi.';
                calculatePassive();

                if (query.length < 2) {
                    closePassiveResults(line);
                    return;
                }
                searchPassiveProducts(line, query);
            });

            search.addEventListener('keydown', function (event) {
                const results = line.querySelector('.passive-product-results');
                const buttons = Array.from(results.querySelectorAll('.product-result'));

                if (!results.classList.contains('active') || !buttons.length) { return; }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    line.keyboardIndex = Math.min((line.keyboardIndex ?? -1) + 1, buttons.length - 1);
                    updatePassiveKeyboardActive(line);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    line.keyboardIndex = Math.max((line.keyboardIndex ?? buttons.length) - 1, 0);
                    updatePassiveKeyboardActive(line);
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    const selectedIndex = line.keyboardIndex >= 0 ? line.keyboardIndex : 0;
                    const item = (line.keyboardItems || [])[selectedIndex];
                    if (item) { selectPassiveProduct(line, item); }
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closePassiveResults(line);
                }
            });

            qtyInput.addEventListener('input', calculatePassive);
            priceInput.addEventListener('input', calculatePassive);
            discountInput.addEventListener('input', calculatePassive);
            discountAmountInput.addEventListener('input', calculatePassive);

            removeBtn.addEventListener('click', function () {
                const lines = passiveLines.querySelectorAll('.passive-line');
                if (lines.length <= 1) {
                    search.value = '';
                    productId.value = '';
                    info.textContent = 'Ürün seçilmedi.';
                    qtyInput.value = '1';
                    priceInput.value = '0';
                    discountInput.value = '0';
                    discountAmountInput.value = '0';
                    closePassiveResults(line);
                    calculatePassive();
                    return;
                }
                line.remove();
                calculatePassive();
            });
        }

        function addPassiveLine() {
            const firstLine = passiveLines.querySelector('.passive-line');
            const clone = firstLine.cloneNode(true);
            clone.querySelector('.passive-product-search').value = '';
            clone.querySelector('.passive-product-id').value = '';
            clone.querySelector('.passive-product-results').innerHTML = '';
            clone.querySelector('.passive-product-results').classList.remove('active');
            clone.querySelector('.selected-product-info').textContent = 'Ürün seçilmedi.';
            clone.querySelector('.passive-qty').value = '1';
            clone.querySelector('.passive-price').value = '0';
            clone.querySelector('.passive-discount').value = '0';
            clone.querySelector('.passive-discount-amount').value = '0';
            clone.querySelector('.line-total-preview').textContent = '0,00 ₺';
            passiveLines.appendChild(clone);
            bindPassiveLine(clone);
            calculatePassive();
        }

        passiveLines.querySelectorAll('.passive-line').forEach(bindPassiveLine);
        addPassiveLineButton.addEventListener('click', addPassiveLine);
        passivePaidAmount.addEventListener('input', calculatePassive);
        passivePaymentType.addEventListener('change', calculatePassive);
        document.addEventListener('click', function (event) {
            if (!event.target.closest('.product-search-box')) {
                passiveLines.querySelectorAll('.passive-line').forEach(closePassiveResults);
            }
        });
        passiveForm.addEventListener('submit', function (event) {
            const selectedProducts = Array.from(passiveLines.querySelectorAll('.passive-product-id')).filter((input) => input.value);
            if (!selectedProducts.length) {
                event.preventDefault();
                alert('Lütfen tezgah satışına en az 1 ürün ekle.');
                passiveLines.querySelector('.passive-product-search').focus();
            }
        });
        calculatePassive();
    </script>

    <?php
    renderFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| SATIŞ
|--------------------------------------------------------------------------
*/

if ($page === 'sales') {
    ensureSalesPaymentColumns();
    ensureDiscountColumns();
    renderHeader('Satış');

    // Performans modu: ürünlerin tamamını sayfaya basmıyoruz.
    // Ürün araması api_products_search.php üzerinden AJAX ile yapılır.
    $customers = db()->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();

    $mode = $_GET['mode'] ?? '';
    $editSale = null;

    if ($mode === 'edit' && isAdmin()) {
        $editId = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM sales WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editSale = $stmt->fetch();
    }

    $saleSearch = trim($_GET['sq'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $paymentFilter = trim($_GET['payment_type'] ?? '');

    $where = [];
    $params = [];

    if ($saleSearch !== '') {
        $where[] = "(s.sale_no LIKE ? OR s.customer_name LIKE ? OR EXISTS (SELECT 1 FROM sale_items sx WHERE sx.sale_id = s.id AND sx.product_name LIKE ?))";
        $like = '%' . $saleSearch . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($dateFrom !== '') {
        $where[] = "DATE(s.created_at) >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "DATE(s.created_at) <= ?";
        $params[] = $dateTo;
    }

    if ($paymentFilter !== '') {
        $where[] = "s.payment_type = ?";
        $params[] = $paymentFilter;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = db()->prepare("
        SELECT 
            s.*,
            u.name AS user_name,
            (SELECT COUNT(*) FROM sale_items si_count WHERE si_count.sale_id = s.id) AS item_count,
            (SELECT GROUP_CONCAT(si_names.product_name ORDER BY si_names.id SEPARATOR ', ') FROM sale_items si_names WHERE si_names.sale_id = s.id) AS product_names
        FROM sales s
        LEFT JOIN users u ON u.id = s.created_by
        {$whereSql}
        ORDER BY s.id DESC
        LIMIT 150
    ");
    $stmt->execute($params);
    $sales = $stmt->fetchAll();
    ?>

    <style>
        .product-search-box { position: relative; z-index: 10000; }
        .product-search-results { position: absolute; left: 0; right: 0; top: calc(100% + 7px); z-index: 999999; display: none; max-height: 300px; overflow-y: auto; padding: 8px; border-radius: 16px; background: var(--bg-panel-2); border: 1px solid var(--border-strong); box-shadow: var(--shadow); }
        .product-search-results.active { display: flex; flex-direction: column; gap: 7px; }
        .product-result { width: 100%; min-height: 54px; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; gap: 4px; padding: 10px 12px; border-radius: 13px; background: rgba(15, 23, 42, 0.55); border: 1px solid rgba(148, 163, 184, 0.14); color: var(--text); box-shadow: none; text-align: left; }
        .product-result:hover, .product-result.keyboard-active { background: rgba(56, 189, 248, 0.18); border-color: rgba(56, 189, 248, 0.38); transform: none; outline: 2px solid rgba(250, 204, 21, 0.32); }
        .product-result strong { font-size: 13px; font-weight: 800; }
        .product-result span, .selected-product-info { color: var(--muted); font-size: 12px; font-weight: 600; }
        .product-no-result { padding: 12px; color: var(--muted); font-size: 13px; }
        .sale-builder { display: grid; grid-template-columns: 1fr 340px; gap: 14px; align-items: start; overflow: visible; position: relative; z-index: 50; }
        .product-picker-panel, .sale-form-card, .sale-lines, .sale-line { overflow: visible !important; }
        .product-picker-panel { position: relative; z-index: 40; margin-bottom: 34px; }
        .sale-line:focus-within { position: relative; z-index: 99999; }
        .sale-form-card { border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.32); padding: 16px; }
        .sale-header-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .sale-header-grid .full { grid-column: 1 / -1; }
        .sale-lines { display: flex; flex-direction: column; gap: 10px; }
        .sale-line { display: grid; grid-template-columns: minmax(220px, 1.4fr) 74px 100px 82px 90px 124px 42px; gap: 10px; align-items: end; padding: 12px; border-radius: 18px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.42); }
        .line-total-preview { min-height: 40px; display: flex; align-items: center; justify-content: flex-end; padding: 0 11px; border-radius: 13px; border: 1px solid rgba(148, 163, 184, 0.16); background: rgba(15, 23, 42, 0.72); color: var(--text); font-weight: 800; white-space: nowrap; }
        .remove-line-btn { width: 40px; min-width: 40px; padding: 0; background: rgba(251, 113, 133, 0.13); color: #fecdd3; border: 1px solid rgba(251, 113, 133, 0.18); box-shadow: none; }
        .sale-actions-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .add-line-btn { background: rgba(56, 189, 248, 0.13); color: #bae6fd; border: 1px solid rgba(56, 189, 248, 0.18); box-shadow: none; }
        .sale-summary { position: sticky; top: 100px; border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.14); background: radial-gradient(circle at top right, rgba(56, 189, 248, 0.13), transparent 34%), rgba(15, 23, 42, 0.48); padding: 16px; }
        .sale-summary h3 { margin: 0 0 14px; color: var(--text); font-size: 17px; letter-spacing: -0.04em; }
        .sale-summary-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(148, 163, 184, 0.12); }
        .sale-summary-row span:first-child { color: var(--muted); font-weight: 700; }
        .sale-summary-row span:last-child { color: var(--text); font-weight: 900; }
        .sale-summary-row.grand { border-bottom: 0; margin-top: 4px; padding: 14px; border-radius: 16px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.18); }
        .sale-summary-row.grand span:last-child { font-size: 20px; color: var(--accent); }
        .filter-actions, .sale-list-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        @media (max-width: 1200px) { .sale-builder { grid-template-columns: 1fr; } .sale-summary { position: relative; top: 0; } .sale-line { grid-template-columns: 1fr 1fr; } .remove-line-btn { width: 100%; } }
        @media (max-width: 700px) { .sale-header-grid, .sale-line { grid-template-columns: 1fr; } }
    </style>

    <?php if ($error): ?>
        <section class="panel"><div class="alert error"><?= e($error) ?></div></section>
    <?php endif; ?>

    <?php if ($editSale): ?>
        <section class="panel">
            <div class="section-title"><div><p>Düzenle</p><h2>Satış Güncelle</h2></div></div>
            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="update_sale">
                <input type="hidden" name="id" value="<?= (int)$editSale['id'] ?>">

                <div>
                    <label>Satış No</label>
                    <input type="text" value="<?= e($editSale['sale_no']) ?>" readonly>
                </div>

                <div>
                    <label>Toplam Tutar</label>
                    <input type="text" value="<?= number_format((float)$editSale['total_amount'], 2, ',', '.') ?> ₺" readonly>
                </div>

                <div>
                    <label>Ödenen Tutar</label>
                    <input type="number" step="0.01" min="0" name="paid_amount" value="<?= e((string)($editSale['paid_amount'] ?? '0')) ?>">
                </div>

                <div>
                    <label>Kayıtlı Müşteri</label>
                    <select name="customer_id">
                        <option value="">Seçiniz</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ((int)($editSale['customer_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Elle Müşteri Adı</label>
                    <input type="text" name="customer_name" value="<?= e($editSale['customer_name']) ?>">
                </div>

                <div>
                    <label>Ödeme Tipi</label>
                    <?php $ep = $editSale['payment_type'] ?? 'nakit'; ?>
                    <select name="payment_type">
                        <option value="nakit" <?= $ep === 'nakit' ? 'selected' : '' ?>>Nakit</option>
                        <option value="kart" <?= $ep === 'kart' ? 'selected' : '' ?>>Kart</option>
                        <option value="iban" <?= $ep === 'iban' ? 'selected' : '' ?>>IBAN</option>
                        <option value="veresiye" <?= $ep === 'veresiye' ? 'selected' : '' ?>>Veresiye</option>
                    </select>
                </div>

                <div class="full">
                    <label>Not</label>
                    <textarea name="note"><?= e($editSale['note'] ?? '') ?></textarea>
                </div>

                <button type="submit">Satışı Güncelle</button>
                <button type="button" onclick="window.location.href='index.php?page=sales'">Vazgeç</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel product-picker-panel">
        <div class="section-title"><div><p>İşlem</p><h2>Çoklu Ürünlü Satış Ekle</h2></div></div>

        <form method="post" id="saleForm">
            <input type="hidden" name="action" value="add_sale">

            <div class="sale-builder">
                <div class="sale-form-card">
                    <div class="sale-header-grid">
                        <div>
                            <label>Kayıtlı Müşteri</label>
                            <select name="customer_id">
                                <option value="">Seçiniz</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label>Elle Müşteri Adı</label>
                            <input type="text" name="customer_name" placeholder="Kayıtlı değilse yaz">
                        </div>

                        <div>
                            <label>Ödeme Tipi</label>
                            <select name="payment_type" id="salePaymentType">
                                <option value="nakit">Nakit</option>
                                <option value="kart">Kart</option>
                                <option value="iban">IBAN</option>
                                <option value="veresiye">Veresiye</option>
                            </select>
                        </div>

                        <div>
                            <label>Ödenen Tutar</label>
                            <input type="number" step="0.01" min="0" name="paid_amount" id="salePaidAmount" placeholder="Boş kalırsa ödeme tipine göre otomatik">
                        </div>

                        <div>
                            <label>Genel İskonto %</label>
                            <input type="number" step="0.01" min="0" max="100" name="sale_discount_rate" id="saleDiscountRate" value="0">
                        </div>

                        <div>
                            <label>Genel İskonto ₺</label>
                            <input type="number" step="0.01" min="0" name="sale_discount_amount" id="saleDiscountAmount" value="0">
                        </div>

                        <div class="full">
                            <label>Not</label>
                            <textarea name="note"></textarea>
                        </div>
                    </div>

                    <div class="section-title" style="margin-top:6px;"><div><p>Kalemler</p><h2>Ürün Satırları</h2></div></div>

                    <div class="sale-lines" id="saleLines">
                        <div class="sale-line">
                            <div class="product-search-box">
                                <label>Ürün Ara / Seç</label>
                                <input type="text" class="sale-product-search" placeholder="En az 2 harf yaz..." autocomplete="off">
                                <input type="hidden" name="product_id[]" class="sale-product-id">
                                <div class="product-search-results sale-product-results"></div>
                                <small class="selected-product-info">Ürün seçilmedi.</small>
                            </div>

                            <div>
                                <label>Miktar</label>
                                <input type="number" step="0.01" min="0.01" name="quantity[]" class="sale-qty" value="1">
                            </div>

                            <div>
                                <label>Birim Fiyat</label>
                                <input type="number" step="0.01" min="0" name="unit_price[]" class="sale-price" value="0">
                            </div>

                            <div>
                                <label>İskonto %</label>
                                <input type="number" step="0.01" min="0" max="100" name="item_discount_rate[]" class="sale-discount" value="0">
                            </div>

                            <div>
                                <label>İskonto ₺</label>
                                <input type="number" step="0.01" min="0" name="item_discount_amount[]" class="sale-discount-amount" value="0">
                            </div>

                            <div>
                                <label>Satır Toplam</label>
                                <div class="line-total-preview">0,00 ₺</div>
                            </div>

                            <button type="button" class="remove-line-btn" title="Satırı Sil">×</button>
                        </div>
                    </div>

                    <div class="sale-actions-row">
                        <button type="button" class="add-line-btn" id="addSaleLine">+ Ürün Satırı Ekle</button>
                        <button type="submit">Satış Kaydet</button>
                    </div>
                </div>

                <aside class="sale-summary">
                    <h3>Satış Özeti</h3>
                    <div class="sale-summary-row"><span>Ürün Satırı</span><span id="saleLineCount">0</span></div>
                    <div class="sale-summary-row"><span>Toplam Tutar</span><span id="saleGrandTotal">0,00 ₺</span></div>
                    <div class="sale-summary-row"><span>İskonto Toplam</span><span id="saleDiscountPreview">0,00 ₺</span></div>
                    <div class="sale-summary-row"><span>Ödenen</span><span id="salePaidPreview">0,00 ₺</span></div>
                    <div class="sale-summary-row grand"><span>Kalan</span><span id="saleRemainingPreview">0,00 ₺</span></div>
                </aside>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Filtre</p><h2>Satış Ara</h2></div></div>
        <form method="get" class="grid-form">
            <input type="hidden" name="page" value="sales">
            <div><label>Satış No / Müşteri / Ürün</label><input type="text" name="sq" value="<?= e($saleSearch) ?>" placeholder="Örn: Ahmet, pompa, MK2026..."></div>
            <div><label>Başlangıç Tarihi</label><input type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
            <div><label>Bitiş Tarihi</label><input type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
            <div><label>Ödeme Tipi</label><select name="payment_type"><option value="">Tümü</option><option value="nakit" <?= $paymentFilter === 'nakit' ? 'selected' : '' ?>>Nakit</option><option value="kart" <?= $paymentFilter === 'kart' ? 'selected' : '' ?>>Kart</option><option value="iban" <?= $paymentFilter === 'iban' ? 'selected' : '' ?>>IBAN</option><option value="veresiye" <?= $paymentFilter === 'veresiye' ? 'selected' : '' ?>>Veresiye</option></select></div>
            <div class="filter-actions"><button type="submit">Filtrele</button><?php if ($saleSearch !== '' || $dateFrom !== '' || $dateTo !== '' || $paymentFilter !== ''): ?><button type="button" onclick="window.location.href='index.php?page=sales'">Temizle</button><?php endif; ?></div>
        </form>
    </section>

    <section class="panel">
        <div class="section-title"><div><p>Liste</p><h2>Satış Listesi</h2></div><div class="role-badge"><?= count($sales) ?> kayıt</div></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No</th><th>Müşteri</th><th>Ürünler</th><th>Kalem</th><th>Toplam</th><th>İskonto</th><th>Ödenen</th><th>Kalan</th><th>Ödeme</th><th>Personel</th><th>Tarih</th><th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $s): ?>
                        <tr>
                            <td><?= e($s['sale_no']) ?></td>
                            <td><?= e($s['customer_name']) ?></td>
                            <td><?= e($s['product_names'] ?? '-') ?></td>
                            <td><?= (int)($s['item_count'] ?? 0) ?></td>
                            <td><?= number_format((float)$s['total_amount'], 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($s['discount_total'] ?? 0), 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($s['paid_amount'] ?? 0), 2, ',', '.') ?> ₺</td>
                            <td><?= number_format((float)($s['remaining_amount'] ?? 0), 2, ',', '.') ?> ₺</td>
                            <td><?= e($s['payment_type']) ?></td>
                            <td><?= e($s['user_name']) ?></td>
                            <td><?= e($s['created_at']) ?></td>
                            <td>
                                <div class="sale-list-actions">
                                    <button type="button" class="small" onclick="window.open('sale_pdf.php?id=<?= (int)$s['id'] ?>', '_blank')">Proforma</button>
                                    <?php if (isAdmin()): ?>
                                        <button type="button" class="small" onclick="window.location.href='index.php?page=sales&mode=edit&id=<?= (int)$s['id'] ?>'">Düzenle</button>
                                        <form method="post" onsubmit="return confirm('Satış silinsin mi? Stok geri eklenecek.')">
                                            <input type="hidden" name="action" value="delete_sale">
                                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="small danger-btn">Sil</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?><tr><td colspan="12">Satış bulunamadı.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <script>
        const saleLines = document.getElementById('saleLines');
        const addSaleLineButton = document.getElementById('addSaleLine');
        const saleForm = document.getElementById('saleForm');
        const saleLineCount = document.getElementById('saleLineCount');
        const saleGrandTotal = document.getElementById('saleGrandTotal');
        const salePaidAmount = document.getElementById('salePaidAmount');
        const salePaymentType = document.getElementById('salePaymentType');
        const saleDiscountRate = document.getElementById('saleDiscountRate');
        const saleDiscountAmount = document.getElementById('saleDiscountAmount');
        const saleDiscountPreview = document.getElementById('saleDiscountPreview');
        const salePaidPreview = document.getElementById('salePaidPreview');
        const saleRemainingPreview = document.getElementById('saleRemainingPreview');

        function formatSaleTL(value) { return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) + ' ₺'; }
        function formatSaleNumber(value) { return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value); }
        function escapeSaleHtml(value) { return String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }

        function calculateSale() {
            let total = 0;
            let discountTotal = 0;
            let activeLines = 0;

            saleLines.querySelectorAll('.sale-line').forEach((line) => {
                const productId = line.querySelector('.sale-product-id');
                const qty = parseFloat(line.querySelector('.sale-qty').value || '0');
                const price = parseFloat(line.querySelector('.sale-price').value || '0');
                const discountRate = Math.max(0, Math.min(parseFloat(line.querySelector('.sale-discount').value || '0'), 100));
                const discountAmountInput = Math.max(0, parseFloat(line.querySelector('.sale-discount-amount').value || '0'));
                const lineBase = qty * price;
                const percentDiscount = lineBase * (discountRate / 100);
                const amountDiscount = Math.min(discountAmountInput, Math.max(lineBase - percentDiscount, 0));
                const lineDiscount = percentDiscount + amountDiscount;
                const lineTotal = Math.max(lineBase - lineDiscount, 0);

                if (productId.value && qty > 0) { activeLines++; }
                discountTotal += lineDiscount;
                total += lineTotal;
                line.querySelector('.line-total-preview').textContent = formatSaleTL(lineTotal);
            });

            const globalDiscountRate = Math.max(0, Math.min(parseFloat(saleDiscountRate.value || '0'), 100));
            const globalPercentDiscount = total * (globalDiscountRate / 100);
            const globalAmountDiscount = Math.max(0, Math.min(parseFloat(saleDiscountAmount.value || '0'), Math.max(total - globalPercentDiscount, 0)));
            const globalDiscount = globalPercentDiscount + globalAmountDiscount;
            discountTotal += globalDiscount;
            total = Math.max(total - globalDiscount, 0);

            let paid;
            if (salePaidAmount.value.trim() === '') {
                paid = salePaymentType.value === 'veresiye' ? 0 : total;
            } else {
                paid = parseFloat(salePaidAmount.value || '0');
            }

            if (paid < 0) { paid = 0; }
            if (paid > total) { paid = total; }

            const remaining = Math.max(total - paid, 0);

            saleLineCount.textContent = String(activeLines);
            saleGrandTotal.textContent = formatSaleTL(total);
            saleDiscountPreview.textContent = formatSaleTL(discountTotal);
            salePaidPreview.textContent = formatSaleTL(paid);
            saleRemainingPreview.textContent = formatSaleTL(remaining);
        }

        function closeSaleResults(line) {
            const results = line.querySelector('.sale-product-results');
            results.classList.remove('active');
            results.innerHTML = '';
            line.keyboardIndex = -1;
        }
        function openSaleResults(line) { line.querySelector('.sale-product-results').classList.add('active'); }

        function updateSaleKeyboardActive(line) {
            const buttons = Array.from(line.querySelectorAll('.sale-product-results .product-result'));
            buttons.forEach((button, index) => {
                button.classList.toggle('keyboard-active', index === line.keyboardIndex);
            });
            if (buttons[line.keyboardIndex]) {
                buttons[line.keyboardIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function selectSaleProduct(line, item) {
            const search = line.querySelector('.sale-product-search');
            const productId = line.querySelector('.sale-product-id');
            const info = line.querySelector('.selected-product-info');
            const priceInput = line.querySelector('.sale-price');
            const name = item.name || '';
            const price = Number(item.sale_price || 0);
            const stock = Number(item.stock || 0);
            const unit = item.unit || '';

            productId.value = String(item.id);
            search.value = name;
            priceInput.value = price.toFixed(2);
            info.textContent = name + ' seçildi. Stok: ' + formatSaleNumber(stock) + ' ' + unit;
            closeSaleResults(line);
            calculateSale();
        }

        function renderSaleProducts(line, items) {
            const results = line.querySelector('.sale-product-results');
            results.innerHTML = '';
            line.keyboardItems = items || [];
            line.keyboardIndex = -1;

            if (!items.length) {
                results.innerHTML = '<div class="product-no-result">Sonuç bulunamadı.</div>';
                openSaleResults(line);
                return;
            }

            items.forEach((item, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-result';
                button.dataset.index = String(index);

                const name = item.name || '';
                const stockCode = item.stock_code || '-';
                const category = item.category || '-';
                const price = Number(item.sale_price || 0);
                const stock = Number(item.stock || 0);
                const unit = item.unit || '';

                button.innerHTML = `<strong>${escapeSaleHtml(name)}</strong><span>Kod: ${escapeSaleHtml(stockCode)} · Kategori: ${escapeSaleHtml(category)} · ${formatSaleTL(price)} · Stok: ${formatSaleNumber(stock)} ${escapeSaleHtml(unit)}</span>`;

                button.addEventListener('mouseenter', function () {
                    line.keyboardIndex = index;
                    updateSaleKeyboardActive(line);
                });

                button.addEventListener('click', function () {
                    selectSaleProduct(line, item);
                });

                results.appendChild(button);
            });

            line.keyboardIndex = 0;
            openSaleResults(line);
            updateSaleKeyboardActive(line);
        }

        function searchSaleProducts(line, query) {
            if (line.searchTimer) { clearTimeout(line.searchTimer); }
            if (line.activeRequestController) { line.activeRequestController.abort(); }

            line.searchTimer = setTimeout(function () {
                const results = line.querySelector('.sale-product-results');
                line.activeRequestController = new AbortController();
                results.innerHTML = '<div class="product-no-result">Aranıyor...</div>';
                openSaleResults(line);

                fetch('api_products_search.php?q=' + encodeURIComponent(query), { signal: line.activeRequestController.signal })
                    .then((response) => response.json())
                    .then((data) => {
                        if (!data.success) {
                            results.innerHTML = '<div class="product-no-result">Arama hatası oluştu.</div>';
                            return;
                        }

                        renderSaleProducts(line, data.items || []);
                    })
                    .catch((error) => {
                        if (error.name === 'AbortError') { return; }
                        results.innerHTML = '<div class="product-no-result">Bağlantı hatası.</div>';
                        openSaleResults(line);
                    });
            }, 250);
        }

        function bindSaleLine(line) {
            const search = line.querySelector('.sale-product-search');
            const productId = line.querySelector('.sale-product-id');
            const info = line.querySelector('.selected-product-info');
            const qtyInput = line.querySelector('.sale-qty');
            const priceInput = line.querySelector('.sale-price');
            const discountInput = line.querySelector('.sale-discount');
            const discountAmountInput = line.querySelector('.sale-discount-amount');
            const removeBtn = line.querySelector('.remove-line-btn');

            search.addEventListener('input', function () {
                const query = search.value.trim();
                productId.value = '';
                info.textContent = 'Ürün seçilmedi.';
                calculateSale();

                if (query.length < 2) {
                    closeSaleResults(line);
                    return;
                }

                searchSaleProducts(line, query);
            });

            search.addEventListener('keydown', function (event) {
                const results = line.querySelector('.sale-product-results');
                const buttons = Array.from(results.querySelectorAll('.product-result'));

                if (!results.classList.contains('active') || !buttons.length) {
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    line.keyboardIndex = Math.min((line.keyboardIndex ?? -1) + 1, buttons.length - 1);
                    updateSaleKeyboardActive(line);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    line.keyboardIndex = Math.max((line.keyboardIndex ?? buttons.length) - 1, 0);
                    updateSaleKeyboardActive(line);
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    const selectedIndex = line.keyboardIndex >= 0 ? line.keyboardIndex : 0;
                    const item = (line.keyboardItems || [])[selectedIndex];
                    if (item) {
                        selectSaleProduct(line, item);
                    }
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closeSaleResults(line);
                }
            });

            qtyInput.addEventListener('input', calculateSale);
            priceInput.addEventListener('input', calculateSale);
            discountInput.addEventListener('input', calculateSale);
            discountAmountInput.addEventListener('input', calculateSale);

            removeBtn.addEventListener('click', function () {
                const lines = saleLines.querySelectorAll('.sale-line');

                if (lines.length <= 1) {
                    search.value = '';
                    productId.value = '';
                    info.textContent = 'Ürün seçilmedi.';
                    qtyInput.value = '1';
                    priceInput.value = '0';
                    discountInput.value = '0';
                    discountAmountInput.value = '0';
                    closeSaleResults(line);
                    calculateSale();
                    return;
                }

                line.remove();
                calculateSale();
            });
        }

        function addSaleLine() {
            const firstLine = saleLines.querySelector('.sale-line');
            const clone = firstLine.cloneNode(true);

            clone.querySelector('.sale-product-search').value = '';
            clone.querySelector('.sale-product-id').value = '';
            clone.querySelector('.sale-product-results').innerHTML = '';
            clone.querySelector('.sale-product-results').classList.remove('active');
            clone.querySelector('.selected-product-info').textContent = 'Ürün seçilmedi.';
            clone.querySelector('.sale-qty').value = '1';
            clone.querySelector('.sale-price').value = '0';
            clone.querySelector('.sale-discount').value = '0';
            clone.querySelector('.sale-discount-amount').value = '0';
            clone.querySelector('.line-total-preview').textContent = '0,00 ₺';

            saleLines.appendChild(clone);
            bindSaleLine(clone);
            calculateSale();
        }

        saleLines.querySelectorAll('.sale-line').forEach(bindSaleLine);
        addSaleLineButton.addEventListener('click', addSaleLine);
        salePaidAmount.addEventListener('input', calculateSale);
        salePaymentType.addEventListener('change', calculateSale);
        saleDiscountRate.addEventListener('input', calculateSale);
        saleDiscountAmount.addEventListener('input', calculateSale);

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.product-search-box')) {
                saleLines.querySelectorAll('.sale-line').forEach(closeSaleResults);
            }
        });

        saleForm.addEventListener('submit', function (event) {
            const selectedProducts = Array.from(saleLines.querySelectorAll('.sale-product-id')).filter((input) => input.value);

            if (!selectedProducts.length) {
                event.preventDefault();
                alert('Lütfen satışa en az 1 ürün ekle.');
                saleLines.querySelector('.sale-product-search').focus();
            }
        });

        calculateSale();
    </script>
    <?php
    renderFooter();
    exit;
}


/*
|--------------------------------------------------------------------------
| AYARLAR
|--------------------------------------------------------------------------
*/

if ($page === 'settings' && isAdmin()) {
    ensureUserManagementColumns();
    renderHeader('Ayarlar');

    $userError = trim($_GET['user_error'] ?? '');
    $editUser = null;

    if (($_GET['mode'] ?? '') === 'edit_user') {
        $editUserId = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare("SELECT id, name, username, role, is_active, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$editUserId]);
        $editUser = $stmt->fetch();
    }

    $users = db()->query("SELECT id, name, username, role, is_active, created_at FROM users ORDER BY id DESC")->fetchAll();
    ?>
    <?php if ($userError !== ''): ?>
        <section class="panel">
            <div class="alert error"><?= e($userError) ?></div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Sistem</p>
                <h2>Genel Ayarlar</h2>
            </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
            <button type="button" onclick="window.location.href='live_currency_update.php'">
                Canlı Kuru Güncelle
            </button>

            <button type="button" onclick="window.location.href='index.php?page=products'">
                Ürünlerde Kur Etkisini Gör
            </button>
        </div>

        <form method="post" class="grid-form">
            <input type="hidden" name="action" value="update_settings">

            <div>
                <label>Firma Adı</label>
                <input type="text" name="company_name" value="<?= e(getSetting('company_name', 'MK Denizcilik')) ?>">
            </div>

            <div>
                <label>USD Kuru</label>
                <input type="number" step="0.0001" name="usd_rate" value="<?= e(getSetting('usd_rate', '0')) ?>">
            </div>

            <div>
                <label>EUR Kuru</label>
                <input type="number" step="0.0001" name="eur_rate" value="<?= e(getSetting('eur_rate', '0')) ?>">
            </div>

            <div>
                <label>Varsayılan Kritik Stok</label>
                <input type="number" step="0.01" name="critical_stock_default" value="<?= e(getSetting('critical_stock_default', '3')) ?>">
            </div>

            <div class="full">
                <label>Banka Bilgisi 1</label>
                <input type="text" name="bank_line_1" value="<?= e(getSetting('bank_line_1', 'TEB BANKASI: TR36 0003 2000 0000 0018 0425 34')) ?>">
            </div>

            <div class="full">
                <label>Banka Bilgisi 2</label>
                <input type="text" name="bank_line_2" value="<?= e(getSetting('bank_line_2', 'VAKIFBANK: TR79 0001 5001 5800 7322 6017 55')) ?>">
            </div>

            <div class="full">
                <label>Banka Bilgisi 3</label>
                <input type="text" name="bank_line_3" value="<?= e(getSetting('bank_line_3', 'GARANTİ BANKASI: TR78 0006 2000 2050 0006 2868 99')) ?>">
            </div>

            <div class="full">
                <label>Banka Notu</label>
                <input type="text" name="bank_note" value="<?= e(getSetting('bank_note', 'Ödeme sonrası dekont iletiniz.')) ?>">
            </div>

            <div class="full">
                <div class="alert">
                    Firma logosu ve marka logoları Plesk dosya yöneticisinden yüklenir.<br>
                    Firma logosu: <strong>assets/mk-logo.png</strong><br>
                    Marka logoları: <strong>assets/brands/moravia.png</strong>, <strong>international.png</strong>, <strong>3m.png</strong>, <strong>karbosan.png</strong>, <strong>teknomarin.png</strong>, <strong>sika.png</strong>
                </div>
            </div>

            <button type="submit">Ayarları Kaydet</button>
        </form>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Kullanıcı</p>
                <h2><?= $editUser ? 'Kullanıcı Düzenle' : 'Yeni Kullanıcı Ekle' ?></h2>
            </div>
        </div>

        <?php if ($editUser): ?>
            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

                <div>
                    <label>Ad Soyad</label>
                    <input type="text" name="name" required value="<?= e($editUser['name']) ?>">
                </div>

                <div>
                    <label>Kullanıcı Adı</label>
                    <input type="text" name="username" required value="<?= e($editUser['username']) ?>">
                </div>

                <div>
                    <label>Rol</label>
                    <?php $editRole = $editUser['role'] ?? 'personel'; ?>
                    <select name="role" <?= ((int)$editUser['id'] === (int)(currentUser()['id'] ?? 0)) ? 'disabled' : '' ?>>
                        <option value="personel" <?= $editRole === 'personel' ? 'selected' : '' ?>>Personel</option>
                        <option value="admin" <?= $editRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                    <?php if ((int)$editUser['id'] === (int)(currentUser()['id'] ?? 0)): ?>
                        <input type="hidden" name="role" value="admin">
                    <?php endif; ?>
                </div>

                <div>
                    <label>Durum</label>
                    <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                        <input type="checkbox" name="is_active" value="1" <?= ((int)$editUser['is_active'] === 1) ? 'checked' : '' ?> <?= ((int)$editUser['id'] === (int)(currentUser()['id'] ?? 0)) ? 'disabled' : '' ?>>
                        Aktif kullanıcı
                    </label>
                    <?php if ((int)$editUser['id'] === (int)(currentUser()['id'] ?? 0)): ?>
                        <input type="hidden" name="is_active" value="1">
                    <?php endif; ?>
                </div>

                <button type="submit">Kullanıcıyı Güncelle</button>
                <button type="button" onclick="window.location.href='index.php?page=settings'">Vazgeç</button>
            </form>

            <hr style="border:0;border-top:1px solid rgba(148,163,184,0.14);margin:18px 0;">

            <form method="post" class="grid-form" onsubmit="return confirm('Bu kullanıcının şifresi değiştirilsin mi?')">
                <input type="hidden" name="action" value="change_user_password">
                <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

                <div>
                    <label>Yeni Şifre</label>
                    <input type="password" name="password" required>
                </div>

                <button type="submit">Şifreyi Değiştir</button>
            </form>
        <?php else: ?>
            <form method="post" class="grid-form">
                <input type="hidden" name="action" value="add_user">

                <div>
                    <label>Ad Soyad</label>
                    <input type="text" name="name" required>
                </div>

                <div>
                    <label>Kullanıcı Adı</label>
                    <input type="text" name="username" required>
                </div>

                <div>
                    <label>Şifre</label>
                    <input type="password" name="password" required>
                </div>

                <div>
                    <label>Rol</label>
                    <select name="role">
                        <option value="personel">Personel</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <button type="submit">Kullanıcı Ekle</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="section-title">
            <div>
                <p>Liste</p>
                <h2>Kullanıcılar</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Ad</th>
                        <th>Kullanıcı Adı</th>
                        <th>Rol</th>
                        <th>Durum</th>
                        <th>Tarih</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php $isSelf = (int)$u['id'] === (int)(currentUser()['id'] ?? 0); ?>
                        <tr>
                            <td><?= e($u['name']) ?></td>
                            <td><?= e($u['username']) ?></td>
                            <td><?= e($u['role']) ?></td>
                            <td><?= ((int)$u['is_active'] === 1) ? 'Aktif' : 'Pasif' ?></td>
                            <td><?= e($u['created_at']) ?></td>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="small" onclick="window.location.href='index.php?page=settings&mode=edit_user&id=<?= (int)$u['id'] ?>'">
                                        Düzenle
                                    </button>

                                    <?php if (!$isSelf): ?>
                                        <form method="post" onsubmit="return confirm('Kullanıcı durumu değiştirilsin mi?')">
                                            <input type="hidden" name="action" value="toggle_user_status">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="small">
                                                <?= ((int)$u['is_active'] === 1) ? 'Pasif Yap' : 'Aktif Yap' ?>
                                            </button>
                                        </form>

                                        <form method="post" onsubmit="return confirm('Kullanıcı silinsin mi?')">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="small danger-btn">Sil</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="role-badge">Sen</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    renderFooter();
    exit;
}

redirect('dashboard');