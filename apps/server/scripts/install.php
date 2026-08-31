<?php

declare(strict_types=1);

/** Idempotent local database installer. It never drops tables or databases. */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
foreach (['HOSTNAME', 'DATABASE', 'USERNAME', 'PASSWORD'] as $key) {
    if (!array_key_exists($key, $db)) {
        fwrite(STDERR, "Missing DATABASE.{$key} in .env\n");
        exit(1);
    }
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['HOSTNAME'], $db['HOSTPORT'] ?? '3306', $db['DATABASE'], $db['CHARSET'] ?? 'utf8mb4');
$pdo = new PDO($dsn, $db['USERNAME'], $db['PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);

$pdo->exec((string)file_get_contents($root . '/database/schema.sql'));
$ensureColumn = static function (PDO $pdo, string $table, string $column, string $definition): void {
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table) . " AND column_name = " . $pdo->quote($column))->fetchColumn();
    if ($exists === 0) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
};
$ensureColumn($pdo, 'categories', 'name_en', 'VARCHAR(100) NULL AFTER name');
$ensureColumn($pdo, 'categories', 'name_hu', 'VARCHAR(100) NULL AFTER name_en');
$ensureColumn($pdo, 'products', 'name_en', 'VARCHAR(100) NULL AFTER name');
$ensureColumn($pdo, 'products', 'name_hu', 'VARCHAR(100) NULL AFTER name_en');
$ensureColumn($pdo, 'delivery_zones', 'name_en', 'VARCHAR(100) NULL AFTER name');
$ensureColumn($pdo, 'delivery_zones', 'name_hu', 'VARCHAR(100) NULL AFTER name_en');
$ensureColumn($pdo, 'orders', 'delivery_lat', 'DECIMAL(10,7) NULL AFTER address');
$ensureColumn($pdo, 'orders', 'delivery_lng', 'DECIMAL(10,7) NULL AFTER delivery_lat');
$ensureColumn($pdo, 'products', 'description_en', 'VARCHAR(500) NULL AFTER description');
$ensureColumn($pdo, 'products', 'description_hu', 'VARCHAR(500) NULL AFTER description_en');
$columnExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'fulfillment_code'")->fetchColumn();
if ($columnExists === 0) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN fulfillment_code VARCHAR(12) NULL COMMENT '取餐码/配送码/就餐码' AFTER query_token_hash");
}
$pdo->exec((string)file_get_contents($root . '/database/upgrade_fulfillment_code.sql'));
$indexExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'fulfillment_code' AND non_unique = 0")->fetchColumn();
if ($indexExists === 0) {
    $pdo->exec('CREATE UNIQUE INDEX uk_fulfillment_code ON orders (fulfillment_code)');
}
echo "Database schema is ready.\n";

if (in_array('--seed', $argv, true)) {
    $pdo->exec((string)file_get_contents($root . '/database/demo_seed.sql'));
    $pdo->exec((string)file_get_contents($root . '/database/demo_dinner_seed.sql'));
    echo "Demo data is ready. Login: admin / Admin@123456 (change it immediately).\n";
}

if (in_array('--dinner-demo', $argv, true) && !in_array('--seed', $argv, true)) {
    $pdo->exec((string)file_get_contents($root . '/database/demo_dinner_seed.sql'));
    echo "Dinner session, time slots and menu are ready.\n";
}

if (in_array('--translations-demo', $argv, true)) {
    $pdo->exec((string)file_get_contents($root . '/database/demo_translation_seed.sql'));
    echo "Demo category and product translations are ready.\n";
}
