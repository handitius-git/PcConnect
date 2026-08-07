<?php
declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/app/lib/helpers.php';

function printer_upgrade_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function printer_upgrade_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function printer_upgrade_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function printer_upgrade_column_collation(PDO $pdo, string $table, string $column): string
{
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(COLLATION_NAME, "-") FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (string)($stmt->fetchColumn() ?: '-');
    } catch (Throwable $e) {
        return 'ERROR: ' . $e->getMessage();
    }
}

function printer_upgrade_foreign_key_name(PDO $pdo, string $table, string $column, string $referencedTable): string
{
    try {
        $stmt = $pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column, $referencedTable]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function printer_upgrade_column_nullable(PDO $pdo, string $table, string $column): string
{
    try {
        $stmt = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (string)($stmt->fetchColumn() ?: '-');
    } catch (Throwable $e) {
        return 'ERROR: ' . $e->getMessage();
    }
}

$pdo = Database::pdo();
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    header('Location: index.php?route=login');
    exit;
}

$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $steps = [
        'Create printers table' => "CREATE TABLE IF NOT EXISTS printers (
            prn_id VARCHAR(60) PRIMARY KEY,
            security_code VARCHAR(12) NOT NULL,
            printer_name VARCHAR(160) NOT NULL,
            location VARCHAR(160) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            location_radius_m INT NOT NULL DEFAULT 5,
            serial_number VARCHAR(160) NULL,
            ip_printer VARCHAR(80) NULL,
            model_printer VARCHAR(160) NULL,
            physical_condition TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($steps as $label => $sql) {
        try {
            $pdo->exec($sql);
            $messages[] = $label . ': OK';
        } catch (Throwable $e) {
            $messages[] = $label . ': FAILED - ' . $e->getMessage();
        }
    }
    if (!printer_upgrade_column_exists($pdo, 'maintenance_schedules', 'asset_type')) {
        try {
            $pdo->exec("ALTER TABLE maintenance_schedules ADD COLUMN asset_type ENUM('pc','printer') NOT NULL DEFAULT 'pc' AFTER id");
            $messages[] = 'Add asset_type: OK';
        } catch (Throwable $e) {
            $messages[] = 'Add asset_type: FAILED - ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Add asset_type: sudah ada';
    }
    if (!printer_upgrade_column_exists($pdo, 'maintenance_schedules', 'printer_id')) {
        try {
            $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN printer_id VARCHAR(60) NULL AFTER pc_id');
            $messages[] = 'Add printer_id: OK';
        } catch (Throwable $e) {
            $messages[] = 'Add printer_id: FAILED - ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Add printer_id: sudah ada';
    }
    if (!printer_upgrade_column_exists($pdo, 'printers', 'latitude')) {
        try {
            $pdo->exec('ALTER TABLE printers ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location');
            $messages[] = 'Add printers.latitude: OK';
        } catch (Throwable $e) {
            $messages[] = 'Add printers.latitude: FAILED - ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Add printers.latitude: sudah ada';
    }
    if (!printer_upgrade_column_exists($pdo, 'printers', 'longitude')) {
        try {
            $pdo->exec('ALTER TABLE printers ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude');
            $messages[] = 'Add printers.longitude: OK';
        } catch (Throwable $e) {
            $messages[] = 'Add printers.longitude: FAILED - ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Add printers.longitude: sudah ada';
    }
    if (!printer_upgrade_column_exists($pdo, 'printers', 'location_radius_m')) {
        try {
            $pdo->exec('ALTER TABLE printers ADD COLUMN location_radius_m INT NOT NULL DEFAULT 5 AFTER longitude');
            $messages[] = 'Add printers.location_radius_m: OK';
        } catch (Throwable $e) {
            $messages[] = 'Add printers.location_radius_m: FAILED - ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Add printers.location_radius_m: sudah ada';
    }
    $pcForeignKey = printer_upgrade_foreign_key_name($pdo, 'maintenance_schedules', 'pc_id', 'pcs');
    if ($pcForeignKey !== '') {
        try {
            $pdo->exec('ALTER TABLE maintenance_schedules DROP FOREIGN KEY `' . str_replace('`', '``', $pcForeignKey) . '`');
            $messages[] = 'Drop old pc_id foreign key (' . $pcForeignKey . '): OK';
        } catch (Throwable $e) {
            $messages[] = 'Drop old pc_id foreign key (' . $pcForeignKey . '): FAILED - ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Drop old pc_id foreign key: tidak ditemukan/sudah drop';
    }
    try {
        $pdo->exec('ALTER TABLE maintenance_schedules MODIFY pc_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        $messages[] = 'Allow pc_id NULL: OK';
    } catch (Throwable $e) {
        $messages[] = 'Allow pc_id NULL: SKIP/FAILED - ' . $e->getMessage();
    }
    if (printer_upgrade_foreign_key_name($pdo, 'maintenance_schedules', 'pc_id', 'pcs') === '') {
        try {
            $pdo->exec('ALTER TABLE maintenance_schedules ADD CONSTRAINT fk_schedule_pc FOREIGN KEY (pc_id) REFERENCES pcs(pc_id) ON DELETE CASCADE');
            $messages[] = 'Recreate pc_id foreign key: OK';
        } catch (Throwable $e) {
            $messages[] = 'Recreate pc_id foreign key: SKIP/FAILED - ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Recreate pc_id foreign key: sudah ada';
    }
    try {
        $pdo->exec('ALTER TABLE printers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $messages[] = 'Normalize printers collation: OK';
    } catch (Throwable $e) {
        $messages[] = 'Normalize printers collation: FAILED - ' . $e->getMessage();
    }
    try {
        $pdo->exec('ALTER TABLE maintenance_schedules MODIFY printer_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        $messages[] = 'Normalize printer_id collation: OK';
    } catch (Throwable $e) {
        $messages[] = 'Normalize printer_id collation: FAILED - ' . $e->getMessage();
    }
    try {
        $pdo->exec('CREATE INDEX idx_schedule_printer ON maintenance_schedules (printer_id)');
        $messages[] = 'Index printer_id: OK';
    } catch (Throwable $e) {
        $messages[] = 'Index printer_id: SKIP/FAILED - ' . $e->getMessage();
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

$tableReady = printer_upgrade_table_exists($pdo, 'printers');
$assetReady = printer_upgrade_column_exists($pdo, 'maintenance_schedules', 'asset_type');
$printerIdReady = printer_upgrade_column_exists($pdo, 'maintenance_schedules', 'printer_id');
$printerLatReady = printer_upgrade_column_exists($pdo, 'printers', 'latitude');
$printerLngReady = printer_upgrade_column_exists($pdo, 'printers', 'longitude');
$printerRadiusReady = printer_upgrade_column_exists($pdo, 'printers', 'location_radius_m');
$ready = $tableReady && $assetReady && $printerIdReady && $printerLatReady && $printerLngReady && $printerRadiusReady;
$pcCollation = printer_upgrade_column_collation($pdo, 'pcs', 'pc_id');
$schedulePcCollation = printer_upgrade_column_collation($pdo, 'maintenance_schedules', 'pc_id');
$schedulePrinterCollation = printer_upgrade_column_collation($pdo, 'maintenance_schedules', 'printer_id');
$printerCollation = printer_upgrade_column_collation($pdo, 'printers', 'prn_id');
$pcNullable = printer_upgrade_column_nullable($pdo, 'maintenance_schedules', 'pc_id');
$pcForeignKey = printer_upgrade_foreign_key_name($pdo, 'maintenance_schedules', 'pc_id', 'pcs') ?: '-';

?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PcConnect Printer Upgrade</title>
    <style>
        body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#172033}
        main{max-width:900px;margin:40px auto;padding:0 18px}
        .panel{background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:18px;margin-bottom:16px}
        .ok{color:#166534}.err{color:#991b1b}.muted{color:#64748b}
        code,pre{background:#f1f5f9;padding:3px 6px;border-radius:5px}
        pre{padding:12px;overflow:auto}
        .btn{display:inline-block;border:1px solid #1457d9;background:#1457d9;color:#fff;text-decoration:none;border-radius:6px;padding:10px 14px;cursor:pointer;font:inherit}
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>PcConnect Printer Upgrade</h1>
        <p class="muted">Build marker: printer-upgrade-20260722-gps</p>
        <p>Status printer schema: <strong class="<?= $ready ? 'ok' : 'err' ?>"><?= $ready ? 'OK, fitur printer siap' : 'Belum lengkap' ?></strong></p>
        <ul>
            <li>Table printers: <?= $tableReady ? 'OK' : 'Belum ada' ?></li>
            <li>maintenance_schedules.asset_type: <?= $assetReady ? 'OK' : 'Belum ada' ?></li>
            <li>maintenance_schedules.printer_id: <?= $printerIdReady ? 'OK' : 'Belum ada' ?></li>
            <li>printers.latitude: <?= $printerLatReady ? 'OK' : 'Belum ada' ?></li>
            <li>printers.longitude: <?= $printerLngReady ? 'OK' : 'Belum ada' ?></li>
            <li>printers.location_radius_m: <?= $printerRadiusReady ? 'OK' : 'Belum ada' ?></li>
            <li>Collation pcs.pc_id: <?= printer_upgrade_e($pcCollation) ?></li>
            <li>Collation maintenance_schedules.pc_id: <?= printer_upgrade_e($schedulePcCollation) ?></li>
            <li>Collation printers.prn_id: <?= printer_upgrade_e($printerCollation) ?></li>
            <li>Collation maintenance_schedules.printer_id: <?= printer_upgrade_e($schedulePrinterCollation) ?></li>
            <li>maintenance_schedules.pc_id nullable: <?= printer_upgrade_e($pcNullable) ?></li>
            <li>pc_id foreign key: <?= printer_upgrade_e($pcForeignKey) ?></li>
        </ul>
    </section>
    <?php if ($messages): ?>
        <section class="panel">
            <h2>Hasil Upgrade</h2>
            <pre><?= printer_upgrade_e(implode("\n", $messages)) ?></pre>
        </section>
    <?php endif; ?>
    <section class="panel">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= printer_upgrade_e(csrf_token()) ?>">
            <button class="btn">Jalankan Upgrade Printer</button>
        </form>
        <p class="muted">Jalankan sekali saja. Kalau ada pesan index sudah ada, itu aman selama status akhirnya OK.</p>
    </section>
    <section class="panel">
        <p><a class="btn" href="index.php?route=maintenance">Buka Maintenance</a> <a class="btn" href="index.php?route=printers">Buka Data Printer</a></p>
        <pre><?= printer_upgrade_e(__FILE__) ?></pre>
    </section>
</main>
</body>
</html>
