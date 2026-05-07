<?php
declare(strict_types=1);

define('APP_NAME', 'MK Denizcilik ERP');

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'mkdenizcilik_com_mk_erp');
define('DB_USER', 'mkdenizcilik_com_mk_erp_user');

// ŞİFREYİ BURAYA SEN YAZACAKSIN
define('DB_PASS', 'By19081903By.');

define('BASE_URL', '');
define('DEFAULT_TIMEZONE', 'Europe/Istanbul');

date_default_timezone_set(DEFAULT_TIMEZONE);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
