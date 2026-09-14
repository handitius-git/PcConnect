<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$out = '';
try {
    require_once dirname(__DIR__) . '/app/lib/bootstrap.php';

    $pdo = Database::pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $out .= "DB OK" . PHP_EOL;

    // Orphan query (same as cleanup tool)
    $stmt = $pdo->query("SELECT ma.id, ma.maintenance_asset_code, ma.pc_id, p.asset_item_id, p.maintenance_asset_id
        FROM maintenance_assets ma
        LEFT JOIN pcs p ON p.pc_id = ma.pc_id
        WHERE ma.pc_id IS NOT NULL AND ma.pc_id <> ''
          AND ma.printer_id IS NULL
          AND NOT EXISTS (SELECT 1 FROM pcs p2 WHERE p2.pc_id = ma.pc_id AND p2.asset_item_id IS NOT NULL)
        ORDER BY ma.maintenance_asset_code");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out .= 'ORPHANS: ' . count($rows) . PHP_EOL;
    foreach ($rows as $row) {
        $out .= json_encode($row) . PHP_EOL;
    }

    $out .= '---' . PHP_EOL;

    // All maintenance assets joined with pcs
    $all = $pdo->query("SELECT ma.id, ma.maintenance_asset_code, ma.pc_id, ma.printer_id, ma.maintenance_type,
        p.asset_item_id, p.maintenance_asset_id
        FROM maintenance_assets ma
        LEFT JOIN pcs p ON p.pc_id = ma.pc_id
        ORDER BY ma.maintenance_asset_code")->fetchAll(PDO::FETCH_ASSOC);
    $out .= 'ALL MA: ' . count($all) . PHP_EOL;
    foreach ($all as $row) {
        $out .= json_encode($row) . PHP_EOL;
    }

    $out .= '---' . PHP_EOL;

    // All pcs with asset_item_id and maintenance_asset_id
    $pcs = $pdo->query("SELECT pc_id, owner_name, asset_item_id, maintenance_asset_id FROM pcs ORDER BY pc_id")->fetchAll(PDO::FETCH_ASSOC);
    $out .= 'ALL PCS: ' . count($pcs) . PHP_EOL;
    foreach ($pcs as $row) {
        $out .= json_encode($row) . PHP_EOL;
    }

    $out .= '---' . PHP_EOL;

    // maintenance_asset_items summary
    $mapped = $pdo->query("SELECT maintenance_asset_id, asset_item_id FROM maintenance_asset_items ORDER BY maintenance_asset_id")->fetchAll(PDO::FETCH_ASSOC);
    $out .= 'MAINT_ASSET_ITEMS: ' . count($mapped) . PHP_EOL;
    foreach ($mapped as $row) {
        $out .= json_encode($row) . PHP_EOL;
    }
} catch (Throwable $e) {
    $out .= 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
}

file_put_contents(__DIR__ . '/verify-output.txt', $out);
echo "OUTPUT WRITTEN";