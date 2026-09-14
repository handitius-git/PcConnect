<?php
declare(strict_types=1);
session_start();
require_once dirname(__DIR__) . '/app/lib/bootstrap.php';

/**
 * Tool Admin: Bulk Sync PC → Asset Item → Maintenance Asset.
 *
 * Masalah:
 *   Banyak PC lama punya maintenance_assets (auto-generated legacy) tetapi
 *   pcs.asset_item_id masih NULL, sehingga maintenance asset tersebut orphan
 *   (tidak terhubung ke Asset Management) dan tidak bisa dibuatkan maintenance
 *   asset baru secara otomatis (guard ensure_pc_maintenance_asset menolak PC
 *   tanpa asset_item_id).
 *
 * Solusi:
 *   Tool ini men-sinkronkan semua PC yang asset_item_id IS NULL dengan cara:
 *     1. Cari Asset Item existing yang cocok berdasarkan HOSTNAME identifier.
 *     2. Jika tidak ada, buat Asset Item baru tipe CMP (Desktop Computer).
 *     3. Set pcs.asset_item_id.
 *     4. Pastikan/zona Maintenance Asset terkait (buat baru jika belum ada,
 *        update data jika sudah ada) dan link ke maintenance_asset_items.
 */

function bs_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bs_db_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $cache[$table] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function bs_db_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function bs_schema_ready(PDO $pdo): bool
{
    return bs_db_table_exists($pdo, 'pcs')
        && bs_db_table_exists($pdo, 'asset_items')
        && bs_db_table_exists($pdo, 'asset_types')
        && bs_db_table_exists($pdo, 'asset_statuses')
        && bs_db_table_exists($pdo, 'asset_groups')
        && bs_db_table_exists($pdo, 'asset_identifiers')
        && bs_db_table_exists($pdo, 'asset_type_identifiers')
        && bs_db_table_exists($pdo, 'asset_type_specifications')
        && bs_db_table_exists($pdo, 'asset_specifications')
        && bs_db_table_exists($pdo, 'maintenance_assets')
        && bs_db_table_exists($pdo, 'maintenance_asset_items')
        && bs_db_column_exists($pdo, 'pcs', 'asset_item_id')
        && bs_db_column_exists($pdo, 'pcs', 'maintenance_asset_id')
        && bs_db_column_exists($pdo, 'asset_items', 'asset_group_id')
        && bs_db_column_exists($pdo, 'asset_items', 'asset_type_id')
        && bs_db_column_exists($pdo, 'asset_items', 'asset_status_id')
        && bs_db_column_exists($pdo, 'asset_items', 'asset_name')
        && bs_db_column_exists($pdo, 'asset_items', 'custodian_name')
        && bs_db_column_exists($pdo, 'asset_items', 'location_label')
        && bs_db_column_exists($pdo, 'asset_items', 'asset_type')
        && bs_db_column_exists($pdo, 'asset_items', 'asset_category')
        && bs_db_column_exists($pdo, 'asset_items', 'status');
}

function bs_random_code(): string
{
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
}

function bs_unique_maintenance_asset_code(PDO $pdo, string $base): string
{
    $code = strtoupper(trim($base));
    $try = $code;
    $n = 1;
    do {
        $stmt = $pdo->prepare('SELECT id FROM maintenance_assets WHERE maintenance_asset_code=?');
        $stmt->execute([$try]);
        if (!$stmt->fetchColumn()) {
            return $try;
        }
        $try = $code . '-' . (++$n);
    } while (true);
}

function bs_maintenance_asset_code_seed(string $prefix, string $assetId): string
{
    $assetId = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', $assetId));
    return $prefix . '-' . $assetId;
}

function bs_unsynced_pcs(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT p.*, ma.maintenance_asset_code
        FROM pcs p
        LEFT JOIN maintenance_assets ma ON ma.pc_id COLLATE utf8mb4_unicode_ci = p.pc_id COLLATE utf8mb4_unicode_ci
        WHERE p.asset_item_id IS NULL
        ORDER BY p.pc_id');
    return $stmt->fetchAll();
}

function bs_find_existing_asset_item(PDO $pdo, array $pc): ?int
{
    $computerName = trim((string)($pc['computer_name'] ?? ''));
    if ($computerName === '') {
        return null;
    }
    $sql = "SELECT ai.id
        FROM asset_identifiers ai
        JOIN asset_type_identifiers ati ON ati.id = ai.asset_type_identifier_id
        WHERE ati.identifier_code = 'HOSTNAME'
          AND LOWER(ai.identifier_value) = LOWER(?)
          AND NOT EXISTS (SELECT 1 FROM pcs p2 WHERE p2.asset_item_id = ai.id AND p2.pc_id <> ?)
        ORDER BY ai.id DESC
        LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$computerName, (string)$pc['pc_id']]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

function bs_asset_type_form_config(PDO $pdo, int $typeId): array
{
    $s = $pdo->prepare('SELECT t.*, g.group_code FROM asset_types t JOIN asset_groups g ON g.id=t.asset_group_id WHERE t.id=?');
    $s->execute([$typeId]);
    $type = $s->fetch() ?: [];
    $s = $pdo->prepare('SELECT * FROM asset_type_identifiers WHERE asset_type_id=? ORDER BY display_order,id');
    $s->execute([$typeId]);
    return ['type' => $type, 'identifiers' => $s->fetchAll()];
}

function bs_create_asset_item(PDO $pdo, array $pc): ?int
{
    // Tipe asset tujuan: CMP (Desktop Computer) — sama seperti pc_asset_sync_page.
    $stmt = $pdo->query("SELECT id FROM asset_types WHERE type_code='CMP' AND is_active=1 ORDER BY id LIMIT 1");
    $typeId = (int)($stmt->fetchColumn() ?: 0);
    if ($typeId <= 0) {
        return null;
    }
    $cfg = bs_asset_type_form_config($pdo, $typeId);
    if (!$cfg['type']) {
        return null;
    }

    $statusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code='ACTIVE' LIMIT 1")->fetchColumn();
    if ($statusId <= 0) {
        return null;
    }

    $q = $pdo->prepare('SELECT COUNT(*) FROM asset_items WHERE asset_type_id=?');
    $q->execute([$typeId]);
    $next = (int)$q->fetchColumn() + 1;
    $groupCode = (string)$cfg['type']['group_code'];
    $typeCode = (string)$cfg['type']['type_code'];
    $code = $groupCode . '-' . $typeCode . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);

    // Pastikan asset_code unik.
    $check = $pdo->prepare('SELECT COUNT(*) FROM asset_items WHERE asset_code=?');
    while (true) {
        $check->execute([$code]);
        if ((int)$check->fetchColumn() === 0) {
            break;
        }
        $next++;
        $code = $groupCode . '-' . $typeCode . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    }

    $pcId = (string)$pc['pc_id'];
    $assetName = trim((string)($pc['computer_name'] ?? ''));
    if ($assetName === '') {
        $assetName = $pcId;
    }
    $custodian = trim((string)($pc['owner_name'] ?? ''));
    $location = trim((string)($pc['location_label'] ?? ''));

    $pdo->prepare("INSERT INTO asset_items(asset_code, asset_group_id, asset_type_id, asset_status_id, asset_name, custodian_name, location_label, asset_type, asset_category, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $code,
            (int)$cfg['type']['asset_group_id'],
            $typeId,
            $statusId,
            $assetName,
            $custodian !== '' ? $custodian : null,
            $location !== '' ? $location : null,
            $cfg['type']['type_name'],
            'Computer',
            'active',
        ]);
    $assetId = (int)$pdo->lastInsertId();

    // Identifier HOSTNAME.
    foreach ($cfg['identifiers'] as $x) {
        if ($x['identifier_code'] === 'HOSTNAME') {
            $pdo->prepare('INSERT INTO asset_identifiers(asset_item_id, asset_type_identifier_id, identifier_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE identifier_value=VALUES(identifier_value)')
                ->execute([$assetId, (int)$x['id'], $assetName]);
        }
    }

    // Spesifikasi dari general_specs JSON (kode yang cocok).
    $raw = json_decode((string)($pc['general_specs'] ?? ''), true) ?: [];
    $sp = $pdo->prepare('SELECT * FROM asset_type_specifications WHERE asset_type_id=?');
    $sp->execute([$typeId]);
    foreach ($sp as $s) {
        $key = strtolower((string)$s['specification_code']);
        $value = $raw[$key] ?? $raw[$s['specification_code']] ?? '';
        if (is_array($value)) {
            $value = json_encode($value);
        }
        if ($value !== '') {
            $pdo->prepare('INSERT INTO asset_specifications(asset_item_id, asset_type_specification_id, specification_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE specification_value=VALUES(specification_value)')
                ->execute([$assetId, (int)$s['id'], (string)$value]);
        }
    }

    return $assetId;
}

/** Lepas asset item tsb dari maintenance asset lain, lalu link ke MA in target. */
function bs_link_maintenance_asset_item(PDO $pdo, int $maintenanceAssetId, int $assetItemId, string $roleName): void
{
    if ($maintenanceAssetId <= 0 || $assetItemId <= 0) {
        return;
    }
    $attachedAt = date('Y-m-d');

    $stmt = $pdo->prepare('SELECT id FROM maintenance_asset_items WHERE asset_item_id=? AND maintenance_asset_id<>? AND detached_at IS NULL');
    $stmt->execute([$assetItemId, $maintenanceAssetId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $pdo->prepare('UPDATE maintenance_asset_items SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')
            ->execute([$attachedAt, "\nAuto detached karena asset item dipasang ke Maintenance Asset ID #" . $maintenanceAssetId, (int)$mid]);
    }

    $stmt = $pdo->prepare('SELECT id FROM maintenance_asset_items WHERE maintenance_asset_id=? AND asset_item_id=? AND detached_at IS NULL LIMIT 1');
    $stmt->execute([$maintenanceAssetId, $assetItemId]);
    $existing = (int)($stmt->fetchColumn() ?: 0);
    if ($existing > 0) {
        $pdo->prepare('UPDATE maintenance_asset_items SET role_name=?, attached_at=? WHERE id=?')
            ->execute([$roleName ?: 'Asset Item', $attachedAt, $existing]);
        return;
    }
    $pdo->prepare('INSERT INTO maintenance_asset_items (maintenance_asset_id, asset_item_id, role_name, attached_at) VALUES (?, ?, ?, ?)')
        ->execute([$maintenanceAssetId, $assetItemId, $roleName ?: 'Asset Item', $attachedAt]);
}

function bs_ensure_pc_maintenance_asset(PDO $pdo, array $pc): ?array
{
    $pcId = (string)($pc['pc_id'] ?? '');
    if ($pcId === '' || empty($pc['asset_item_id'])) {
        return null;
    }

    if (!empty($pc['maintenance_asset_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([(int)$pc['maintenance_asset_id']]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE pc_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$pcId]);
    $row = $stmt->fetch();
    if (!$row) {
        $assetItem = null;
        $itemStmt = $pdo->prepare('SELECT asset_name, asset_code, company_id, custodian_name, location_label FROM asset_items WHERE id=?');
        $itemStmt->execute([(int)$pc['asset_item_id']]);
        $assetItem = $itemStmt->fetch() ?: null;

        if ($assetItem) {
            $name = trim((string)($assetItem['asset_name'] ?? '') . ' - ' . (string)($assetItem['asset_code'] ?? ''), ' -');
            if ($name === '') {
                $name = $pcId;
            }
        } else {
            $name = trim((string)($pc['owner_name'] ?? '') . ' - ' . (string)($pc['computer_name'] ?? ''), ' -');
            if ($name === '') {
                $name = $pcId;
            }
        }

        $companyId = null;
        $ownerName = $pc['owner_name'] ?? null;
        $locationLabel = trim((string)($pc['location_label'] ?? ''));
        if ($assetItem) {
            $companyId = (int)($assetItem['company_id'] ?? 0) > 0 ? (int)$assetItem['company_id'] : null;
            $ownerName = trim((string)($assetItem['custodian_name'] ?? ''));
            if ($ownerName === '') {
                $ownerName = trim((string)($pc['owner_name'] ?? ''));
            }
            $locationLabel = trim((string)($assetItem['location_label'] ?? ''));
            if ($locationLabel === '') {
                $locationLabel = trim((string)($pc['location_label'] ?? ''));
            }
        }

        $code = bs_unique_maintenance_asset_code($pdo, bs_maintenance_asset_code_seed('MNT', $pcId));
        $pdo->prepare('INSERT INTO maintenance_assets (maintenance_asset_code, security_code, maintenance_type, name, pc_id, company_id, employee_nik, owner_name, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $code,
                (string)($pc['security_code'] ?? bs_random_code()),
                'pc_set',
                $name,
                $pcId,
                $companyId,
                $pc['employee_nik'] ?? null,
                $ownerName,
                $locationLabel !== '' ? $locationLabel : null,
                $pc['latitude'] ?? null,
                $pc['longitude'] ?? null,
                max(1, (int)($pc['location_radius_m'] ?? 5)),
                'active',
                'Auto dibuat dari Bulk Sync PC ke Asset Item.',
            ]);
        $rowId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
    }

    if ($row) {
        $pdo->prepare('UPDATE pcs SET maintenance_asset_id=? WHERE pc_id=?')->execute([(int)$row['id'], $pcId]);
        bs_link_maintenance_asset_item($pdo, (int)$row['id'], (int)$pc['asset_item_id'], 'PC / Asset Item');
    }
    return $row ?: null;
}

function bs_sync_pc_maintenance_asset(PDO $pdo, array $pc): void
{
    if (empty($pc['asset_item_id'])) {
        return;
    }
    $asset = bs_ensure_pc_maintenance_asset($pdo, $pc);
    if (!$asset) {
        return;
    }

    $pcId = (string)$pc['pc_id'];
    $assetItem = null;
    $itemStmt = $pdo->prepare('SELECT asset_name, asset_code, company_id, custodian_name, location_label FROM asset_items WHERE id=?');
    $itemStmt->execute([(int)$pc['asset_item_id']]);
    $assetItem = $itemStmt->fetch() ?: null;

    $name = $pcId;
    $companyId = null;
    $ownerName = $pc['owner_name'] ?? null;
    $locationLabel = trim((string)($pc['location_label'] ?? ''));

    if ($assetItem) {
        $name = trim((string)($assetItem['asset_name'] ?? '') . ' - ' . (string)($assetItem['asset_code'] ?? ''), ' -');
        if ($name === '') {
            $name = $pcId;
        }
        $companyId = (int)($assetItem['company_id'] ?? 0) > 0 ? (int)$assetItem['company_id'] : null;
        $ownerName = trim((string)($assetItem['custodian_name'] ?? ''));
        if ($ownerName === '') {
            $ownerName = trim((string)($pc['owner_name'] ?? ''));
        }
        $locationLabel = trim((string)($assetItem['location_label'] ?? ''));
        if ($locationLabel === '') {
            $locationLabel = trim((string)($pc['location_label'] ?? ''));
        }
    } else {
        $name = trim((string)($pc['owner_name'] ?? '') . ' - ' . (string)($pc['computer_name'] ?? ''), ' -');
        if ($name === '') {
            $name = $pcId;
        }
    }

    $pdo->prepare('UPDATE maintenance_assets SET security_code=?, name=?, company_id=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=? WHERE id=?')
        ->execute([
            (string)$pc['security_code'],
            $name,
            $companyId,
            $pc['employee_nik'] ?? null,
            $ownerName,
            $locationLabel !== '' ? $locationLabel : null,
            $pc['latitude'] ?? null,
            $pc['longitude'] ?? null,
            max(1, (int)($pc['location_radius_m'] ?? 5)),
            (int)$asset['id'],
        ]);

    bs_link_maintenance_asset_item($pdo, (int)$asset['id'], (int)$pc['asset_item_id'], 'PC / Asset Item');
}

function bs_sync_one(PDO $pdo, array $pc): array
{
    $pcId = (string)$pc['pc_id'];
    $computerName = (string)($pc['computer_name'] ?? '');
    try {
        // 1. Cari asset item existing by hostname.
        $assetId = bs_find_existing_asset_item($pdo, $pc);
        $created = false;
        if ($assetId) {
            $check = $pdo->prepare('SELECT id FROM asset_items WHERE id=?');
            $check->execute([$assetId]);
            if (!$check->fetchColumn()) {
                $assetId = null;
            }
        }
        // 2. Buat asset item baru jika tidak ditemukan.
        if (!$assetId) {
            $assetId = bs_create_asset_item($pdo, $pc);
            $created = true;
        }
        if (!$assetId) {
            return ['status' => 'error', 'message' => 'Asset Item gagal dibuat/ditemukan. Pastikan tipe asset CMP dan status ACTIVE sudah tersedia.'];
        }

        // 3. Set pcs.asset_item_id.
        $pdo->prepare('UPDATE pcs SET asset_item_id=? WHERE pc_id=?')->execute([$assetId, $pcId]);
        $pc['asset_item_id'] = $assetId;

        // 4. Sync maintenance asset (buat/update/link).
        $asset = bs_ensure_pc_maintenance_asset($pdo, $pc);
        if (!$asset) {
            return ['status' => 'error', 'message' => 'Asset Item OK (#'.$assetId.') tapi Maintenance Asset gagal dibuat.'];
        }
        bs_sync_pc_maintenance_asset($pdo, $pc);

        $kind = $created ? 'baru' : 'existing';
        $message = 'Asset Item #' . $assetId . ' (' . $kind . '), MA: ' . $asset['maintenance_asset_code'];
        return ['status' => 'ok', 'message' => $message];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function bs_bulk_run(PDO $pdo): array
{
    $results = [];
    foreach (bs_unsynced_pcs($pdo) as $pc) {
        $results[] = [
            'pc_id' => (string)$pc['pc_id'],
            'computer_name' => (string)($pc['computer_name'] ?? ''),
            'owner_name' => (string)($pc['owner_name'] ?? ''),
            'result' => bs_sync_one($pdo, $pc),
        ];
    }
    return $results;
}

$pdo = Database::pdo();
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    header('Location: index.php?route=login');
    exit;
}

$messages = [];
$runResults = [];
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $pcId = strtoupper(trim((string)($_POST['pc_id'] ?? '')));

    if ($action === 'bulk_sync') {
        $runResults = bs_bulk_run($pdo);
        $okCount = count(array_filter($runResults, fn($r) => $r['result']['status'] === 'ok'));
        $errCount = count($runResults) - $okCount;
        $messages[] = 'Bulk sync selesai: ' . $okCount . ' sukses, ' . $errCount . ' gagal.';
    } elseif ($action === 'sync_one' && $pcId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
        $stmt->execute([$pcId]);
        $pc = $stmt->fetch();
        if (!$pc) {
            $messages[] = 'PC ' . $pcId . ' tidak ditemukan.';
        } else {
            $res = bs_sync_one($pdo, $pc);
            if ($res['status'] === 'ok') {
                $messages[] = 'Sinkronisasi ' . $pcId . ' sukses: ' . $res['message'];
            } else {
                $messages[] = 'Gagal sinkronisasi ' . $pcId . ': ' . $res['message'];
            }
        }
    }
}

$totalPc = (int)$pdo->query('SELECT COUNT(*) FROM pcs')->fetchColumn();
$syncedPc = (int)$pdo->query('SELECT COUNT(*) FROM pcs WHERE asset_item_id IS NOT NULL')->fetchColumn();
$orphanMa = 0;
try {
    $orphanMa = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_assets ma WHERE ma.pc_id IS NOT NULL AND ma.pc_id <> '' AND ma.printer_id IS NULL AND NOT EXISTS (SELECT 1 FROM pcs p2 WHERE p2.pc_id = ma.pc_id AND p2.asset_item_id IS NOT NULL)")->fetchColumn();
} catch (Throwable $ignored) {}

$unsynced = bs_unsynced_pcs($pdo);
$schemaReady = bs_schema_ready($pdo);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bulk Sync PC ke Asset Item</title>
<style>
    body{font-family:Arial,system-ui,sans-serif;background:#f1f5f9;margin:0;padding:24px;color:#0f172a}
    .panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:16px;max-width:1180px}
    h1,h2{margin:0 0 8px}
    p{margin:6px 0}
    .muted{color:#64748b;font-size:13px}
    .badge{display:inline-block;background:#eef2f7;border-radius:999px;padding:3px 10px;font-size:12px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0;font-size:13.5px;vertical-align:top}
    th{background:#f8fafc}
    .danger{background:#dc2626;color:#fff;border:0;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px}
    .danger:hover{background:#b91c1c}
    .good{background:#0f8a5f;color:#fff;border:0;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px}
    .good:hover{background:#0b6e4b}
    .ok{color:#16a34a;font-weight:600}
    .err{color:#dc2626;font-weight:600}
    input[type=submit]{font-size:13px}
    form.inline{display:inline}
    a{color:#2563eb}
    code{background:#f1f5f9;padding:2px 5px;border-radius:4px}
    .result-ok{background:#f0fdf4;color:#166534;padding:6px 10px;border-radius:6px}
    .result-err{background:#fef2f2;color:#991b1b;padding:6px 10px;border-radius:6px}
</style>
</head>
<body>
    <div class="panel">
        <h1>Bulk Sync PC ke Asset Item</h1>
        <p class="muted">Tool admin untuk menyinkronkan semua PC yang <strong>belum</strong> ditautkan ke Asset Item (<code>pcs.asset_item_id</code> kosong) menjadi Asset Item & Maintenance Asset yang benar.</p>
        <p>
            <span class="badge">Total PC: <?php echo bs_e((string)$totalPc); ?></span>
            <span class="badge" style="background:#dcfce7;color:#166534">Sudah sinkron: <?php echo bs_e((string)$syncedPc); ?></span>
            <span class="badge" style="background:#fee2e2;color:#991b1b">Belum sinkron: <?php echo bs_e((string)count($unsynced)); ?></span>
            <span class="badge" style="background:#fef9c3;color:#854d0e">Maintenance Asset Orphan: <?php echo bs_e((string)$orphanMa); ?></span>
        </p>
        <?php if (!$schemaReady): ?>
        <div class="panel" style="border-color:#fbbf24;background:#fffbeb">
            <h2 style="color:#92400e;margin:0 0 6px">Schema database belum lengkap</h2>
            <p>Kolom/tabel yang dibutuhkan tool ini belum ada di database server. Buka <strong><a href="index.php">index.php</a></strong> sekali agar skema database diperbarui otomatis, lalu kembali ke halaman ini.</p>
        </div>
        <?php endif; ?>
        <p class="muted">
            Cara kerja: (1) cari Asset Item existing dengan HOSTNAME identifier yang cocok, (2) jika tidak ada, buat Asset Item baru tipe Desktop Computer (CMP), 
            (3) set <code>pcs.asset_item_id</code>, (4) pastikan Maintenance Asset terkait dibuat/update dan Asset Item di-link ke <code>maintenance_asset_items</code> 
            sehingga Maintenance Asset tidak lagi orphan.
        </p>
    </div>

    <?php if ($messages): ?>
    <div class="panel">
        <?php foreach ($messages as $msg): ?>
            <p><?php echo bs_e($msg); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($runResults): ?>
    <div class="panel">
        <h2>Hasil Bulk Sync</h2>
        <table>
            <tr><th>PC</th><th>Computer Name</th><th>User</th><th>Hasil</th></tr>
            <?php foreach ($runResults as $r): ?>
            <tr>
                <td><strong><?php echo bs_e($r['pc_id']); ?></strong></td>
                <td><?php echo bs_e($r['computer_name'] ?: '-'); ?></td>
                <td><?php echo bs_e($r['owner_name'] ?: '-'); ?></td>
                <td>
                    <?php if ($r['result']['status'] === 'ok'): ?>
                        <span class="result-ok">SUKSES — <?php echo bs_e($r['result']['message']); ?></span>
                    <?php else: ?>
                        <span class="result-err">GAGAL — <?php echo bs_e($r['result']['message']); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="panel">
        <?php if (!$schemaReady): ?>
            <p class="err">Tool tidak bisa dipakai sampai schema database lengkap. Buka <a href="index.php">index.php</a> sekali agar migrasi skema berjalan otomatis.</p>
        <?php elseif ($unsynced): ?>
            <div class="actions" style="margin-bottom:12px">
                <form method="post" onsubmit="return confirm('Sync SEMUA PC yang belum tersinkron ke Asset Item? Proses ini akan membuat Asset Item baru untuk PC yang tidak memiliki hostname yang cocok.');">
                    <input type="hidden" name="csrf" value="<?php echo bs_e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="bulk_sync">
                    <button class="good" type="submit">Sync Semua PC Belum Sinkron (<?php echo count($unsynced); ?>)</button>
                </form>
                <p class="muted" style="margin-top:8px">Anda juga bisa sync satu per satu dengan tombol Sync pada baris tabel.</p>
            </div>
            <table>
                <tr><th>PC</th><th>Computer Name</th><th>User</th><th>Lokasi</th><th>Maintenance Asset</th><th>Aksi</th></tr>
                <?php foreach ($unsynced as $row): ?>
                    <tr>
                        <td><strong><?php echo bs_e((string)$row['pc_id']); ?></strong></td>
                        <td><?php echo bs_e((string)($row['computer_name'] ?? '')); ?></td>
                        <td><?php echo bs_e((string)($row['owner_name'] ?? '')); ?></td>
                        <td><?php echo bs_e((string)($row['location_label'] ?? '')); ?></td>
                        <td><?php echo bs_e((string)($row['maintenance_asset_code'] ?? '- (belum ada)')); ?></td>
                        <td>
                            <form class="inline" method="post" onsubmit="return confirm('Sync PC <?php echo bs_e((string)$row['pc_id']); ?> ke Asset Item?');">
                                <input type="hidden" name="csrf" value="<?php echo bs_e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="sync_one">
                                <input type="hidden" name="pc_id" value="<?php echo bs_e((string)$row['pc_id']); ?>">
                                <button class="good" type="submit">Sync</button>
                            </form>
                            <a class="btn" style="display:inline-block;border:1px solid #c7d0df;background:#fff;color:#172033;text-decoration:none;border-radius:6px;padding:7px 12px;cursor:pointer;font-size:13px" href="index.php?route=pc_asset_sync&pc_id=<?php echo bs_e(rawurlencode((string)$row['pc_id'])); ?>">Manual</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="ok">Semua PC sudah tersinkron ke Asset Item.</p>
        <?php endif; ?>
        <p style="margin-top:12px">
            <a href="index.php?route=pcs">&laquo; Kembali ke Pendataan PC</a> &nbsp;|&nbsp;
            <a href="index.php?route=maintenance_assets">Maintenance Assets / QR</a> &nbsp;|&nbsp;
            <a href="index.php?route=maintenance_cleanup">Hapus Data Maintenance</a>
        </p>
    </div>
</body>
</html>