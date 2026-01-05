<?php
// Simple cache bridge for Polygon results.
// Expects MySQL access and is intended to be called from index.html in the same folder.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$DB_NAME = getenv('CACHE_DB_NAME') ?: 'u302174108_algorithmback';
$DB_USER = getenv('CACHE_DB_USER') ?: 'u302174108_fathertkt06';
$DB_PASSWORD = getenv('CACHE_DB_PASSWORD') ?: 'V12_Abd!78910';
$DB_HOST = getenv('CACHE_DB_HOST') ?: 'mysql.hostinger.com';
$DB_PORT = getenv('CACHE_DB_PORT') ?: '3306';

$action = $_GET['action'] ?? '';
$date = $_GET['date'] ?? '';

function json_response($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function table_name($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(400, [
            'status' => 'error',
            'detail' => 'Tarih formatı YYYY-MM-DD olmalı',
        ]);
    }
    return 'stocks_' . str_replace('-', '_', $date);
}

function pdo_conn($host, $port, $db, $user, $pass) {
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    return new PDO($dsn, $user, $pass, $opt);
}

try {
    $pdo = pdo_conn($DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASSWORD);
} catch (Throwable $e) {
    json_response(500, [
        'status' => 'error',
        'detail' => 'DB bağlantısı başarısız: ' . $e->getMessage(),
    ]);
}

if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $table = table_name($date);
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $stmt->execute([$DB_NAME, $table]);
        $row = $stmt->fetch();
        if (!$row || intval($row['cnt']) === 0) {
            json_response(404, ['status' => 'miss', 'detail' => 'Önbellek yok']);
        }
        $stmt = $pdo->query("SELECT company_name, ticker FROM `{$table}` ORDER BY company_name ASC");
        $data = [];
        foreach ($stmt as $r) {
            $data[] = ['name' => $r['company_name'], 'ticker' => $r['ticker']];
        }
        json_response(200, ['status' => 'ok', 'detail' => 'cache hit', 'data' => $data]);
    } catch (Throwable $e) {
        json_response(500, ['status' => 'error', 'detail' => 'Sorgu hatası: ' . $e->getMessage()]);
    }
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table = table_name($date);
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $items = $payload['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        json_response(400, ['status' => 'error', 'detail' => 'items boş']);
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                company_name VARCHAR(255) NOT NULL,
                ticker VARCHAR(32) NOT NULL,
                PRIMARY KEY (ticker)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $stmt = $pdo->prepare("INSERT INTO `{$table}` (company_name, ticker) VALUES (?, ?) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name)");
        foreach ($items as $row) {
            $name = $row['name'] ?? '';
            $ticker = $row['ticker'] ?? '';
            if ($name === '' || $ticker === '') {
                continue;
            }
            $stmt->execute([$name, $ticker]);
        }
        json_response(200, ['status' => 'ok', 'detail' => 'cache saved', 'count' => count($items)]);
    } catch (Throwable $e) {
        json_response(500, ['status' => 'error', 'detail' => 'Kaydetme hatası: ' . $e->getMessage()]);
    }
}

json_response(400, ['status' => 'error', 'detail' => 'Geçersiz istek']);
