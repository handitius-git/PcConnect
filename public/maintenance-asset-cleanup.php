<?php
declare(strict_types=1);
session_start();
require_once dirname(__DIR__) . '/app/lib/bootstrap.php';

function ma_cleanup_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ma_cleanup_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ma_cleanup_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Maintenance assets whose PC is NOT linked to any asset item (auto-created orphan data). */
function ma_cleanup_orphan_pc_assets(PDO $pdo): array
{
    if (!ma_cleanup_table_exists($pdo, 'maintenance_assets')
        || !ma_cleanup_table_exists($pdo, 'pcs')
        || !ma_cleanup_column_exists($pdo, 'maintenance_assets', 'pc_id')
        || !ma_cleanup_column_exists($pdo, 'maintenance_assets', 'printer_id')
        || !ma_cleanup_column_exists($pdo, 'pcs', 'asset_item_id')) {
        return [];
    }
    try {
        $stmt = $pdo->query("SELECT ma.*, COALESCE(p.computer_name, p.pc_id) pc_label FROM maintenance_assets ma LEFT JOIN pcs p ON p.pc_id = ma.pc_id WHERE ma.pc_id IS NOT NULL AND ma.pc_id <> '' AND ma.printer_id IS NULL AND NOT EXISTS (SELECT 1 FROM pcs p2 WHERE p2.pc_id = ma.pc_id AND p2.asset_item_id IS NOT NULL) ORDER BY ma.maintenance_asset_code");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
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
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'remove_all') {
        $rows = ma_cleanup_orphan_pc_assets($pdo);
        $removed = 0;
        foreach ($rows as $row) {
            $maId = (int)$row['id'];
            try {
                $pdo->beginTransaction();
                // Lepas relasi yang masih menunjuk ke maintenance asset ini.
                if (ma_cleanup_table_exists($pdo, 'maintenance_schedules')) {
                    $pdo->prepare('UPDATE maintenance_schedules SET maintenance_asset_id=NULL WHERE maintenance_asset_id=?')->execute([$maId]);
                }
                if (ma_cleanup_table_exists($pdo, 'pcs') && $row['pc_id'] !== null && $row['pc_id'] !== '') {
                    $pdo->prepare('UPDATE pcs SET maintenance_asset_id=NULL WHERE pc_id=?')->execute([(string)$row['pc_id']]);
                }
                if (ma_cleanup_table_exists($pdo, 'maintenance_asset_items')) {
                    $pdo->prepare('DELETE FROM maintenance_asset_items WHERE maintenance_asset_id=?')->execute([$maId]);
                }
                $pdo->prepare('DELETE FROM maintenance_assets WHERE id=?')->execute([$maId]);
                $pdo->commit();
                $removed++;
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $ignored) {}
                $messages[] = 'Gagal hapus ' . ma_cleanup_e((string)($row['maintenance_asset_code'] ?? $maId)) . ': ' . $e->getMessage();
            }
        }
        $messages[] = 'Removed: ' . $removed . ' maintenance asset dari PC yang belum tersinkron ke Asset Item.';
    }

    if ($action === 'remove_one') {
        $maId = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=? AND pc_id IS NOT NULL AND pc_id<>"" AND printer_id IS NULL AND NOT EXISTS (SELECT 1 FROM pcs p2 WHERE p2.pc_id = maintenance_assets.pc_id AND p2.asset_item_id IS NOT NULL)');
            $stmt->execute([$maId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            $row = false;
            $messages[] = 'Gagal membaca data maintenance asset: ' . $e->getMessage();
        }
        if (!$row) {
            if ($messages === []) {
                $messages[] = 'Maintenance asset tidak ditemukan atau sudah aman.';
            }
        } else {
            try {
                $pdo->beginTransaction();
                if (ma_cleanup_table_exists($pdo, 'maintenance_schedules')) {
                    $pdo->prepare('UPDATE maintenance_schedules SET maintenance_asset_id=NULL WHERE maintenance_asset_id=?')->execute([$maId]);
                }
                if (ma_cleanup_table_exists($pdo, 'pcs') && $row['pc_id'] !== null && $row['pc_id'] !== '') {
                    $pdo->prepare('UPDATE pcs SET maintenance_asset_id=NULL WHERE pc_id=?')->execute([(string)$row['pc_id']]);
                }
                if (ma_cleanup_table_exists($pdo, 'maintenance_asset_items')) {
                    $pdo->prepare('DELETE FROM maintenance_asset_items WHERE maintenance_asset_id=?')->execute([$maId]);
                }
                $pdo->prepare('DELETE FROM maintenance_assets WHERE id=?')->execute([$maId]);
                $pdo->commit();
                $messages[] = 'Removed: ' . ma_cleanup_e((string)$row['maintenance_asset_code']) . ' (' . ma_cleanup_e((string)$row['pc_id']) . ').';
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $ignored) {}
                $messages[] = 'Gagal hapus: ' . $e->getMessage();
            }
        }
    }
}

$schemaReady = ma_cleanup_table_exists($pdo, 'maintenance_assets')
    && ma_cleanup_table_exists($pdo, 'pcs')
    && ma_cleanup_column_exists($pdo, 'maintenance_assets', 'pc_id')
    && ma_cleanup_column_exists($pdo, 'maintenance_assets', 'printer_id')
    && ma_cleanup_column_exists($pdo, 'pcs', 'asset_item_id')
    && ma_cleanup_column_exists($pdo, 'pcs', 'maintenance_asset_id');

$orphans = ma_cleanup_orphan_pc_assets($pdo);
$totalMa = ma_cleanup_table_exists($pdo, 'maintenance_assets') ? (int)$pdo->query('SELECT COUNT(*) FROM maintenance_assets')->fetchColumn() : 0;
$linkedCount = 0;
if ($schemaReady) {
    $linkedCount = (int)$pdo->query("SELECT COUNT(*) FROM pcs WHERE asset_item_id IS NOT NULL AND maintenance_asset_id IS NOT NULL")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cleanup Maintenance Asset dari PC Belum Sinkron Asset</title>
<style>
    body{font-family:Arial,system-ui,sans-serif;background:#f1f5f9;margin:0;padding:24px;color:#0f172a}
    .panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:16px;max-width:980px}
    h1,h2{margin:0 0 8px}
    p{margin:6px 0}
    .muted{color:#64748b;font-size:13px}
    .badge{display:inline-block;background:#eef2f7;border-radius:999px;padding:3px 10px;font-size:12px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0;font-size:13.5px;vertical-align:top}
    th{background:#f8fafc}
    .danger{background:#dc2626;color:#fff;border:0;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px}
    .danger:hover{background:#b91c1c}
    .ok{color:#16a34a;font-weight:600}
    .err{color:#dc2626;font-weight:600}
    input[type=submit]{font-size:13px}
    form.inline{display:inline}
    a{color:#2563eb}
    code{background:#f1f5f9;padding:2px 5px;border-radius:4px}
</style>
</head>
<body>
    <div class="panel">
        <h1>Cleanup Maintenance Asset dari PC Belum Sinkron Asset</h1>
        <p class="muted">Tool admin untuk menghapus Maintenance Asset / QR yang dibuat otomatis dari data PC yang <strong>belum</strong> ditautkan ke Asset Item (pcs.asset_item_id kosong). Maintenance Asset seperti ini hanya membawa data PC dan tidak terhubung ke Asset Management.</p>
        <p>
            <span class="badge">Total Maintenance Asset: <?php echo ma_cleanup_e((string)$totalMa); ?></span>
            <span class="badge">PC tersinkron & Maintenance Asset: <?php echo ma_cleanup_e((string)$linkedCount); ?></span>
            <span class="badge" style="background:#fee2e2;color:#991b1b">Orphan PC (belum sinkron): <?php echo ma_cleanup_e((string)count($orphans)); ?></span>
        </p>
        <?php if (!$schemaReady): ?>
        <div class="panel" style="border-color:#fbbf24;background:#fffbeb">
            <h2 style="color:#92400e;margin:0 0 6px">Schema database belum lengkap</h2>
            <p>Kolom/tabel yang dibutuhkan tool ini belum ada di database server. Buka <strong><a href="index.php">index.php</a></strong> sekali agar skema database diperbarui otomatis, lalu kembali ke halaman ini.</p>
            <p class="muted">Database: <code><?php echo ma_cleanup_e(Database::pdo()->query('SELECT DATABASE()')->fetchColumn() ?: ''); ?></code></p>
        </div>
        <?php endif; ?>
        <p class="muted">Yang dihapus: maintenance_assets yang pc_id-nya diisi tetapi PC tersebut tidak punya asset_item_id. Schedule maintenance, relasi maintenance_asset_id pada PC, dan maintenance_asset_items yang menunjuk ke asset ini ikut dilepas/dihapus agar tidak menjadi data yatim.</p>
    </div>

    <?php if ($messages): ?>
    <div class="panel">
        <?php foreach ($messages as $msg): ?>
            <p><?php echo ma_cleanup_e($msg); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
        <?php if (!$schemaReady): ?>
            <p class="err">Tool tidak bisa dipakai sampai schema database lengkap. Buka <a href="index.php">index.php</a> sekali agar migrasi skema berjalan otomatis.</p>
        <?php elseif ($orphans): ?>
            <div class="actions" style="margin-bottom:12px">
                <form method="post" onsubmit="return confirm('Hapus SEMUA maintenance asset dari PC yang belum tersinkron ke Asset Item?');">
                    <input type="hidden" name="csrf" value="<?php echo ma_cleanup_e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="remove_all">
                    <button class="danger" type="submit">Hapus Semua Orphan Maintenance Asset (<?php echo count($orphans); ?>)</button>
                </form>
                <p class="muted" style="margin-top:8px">Anda juga bisa menghapus satu per satu dengan tombol Hapus pada baris tabel.</p>
            </div>
            <table>
                <tr><th>ID</th><th>Maintenance Asset ID</th><th>PC</th><th>Nama</th><th>Company</th><th>Lokasi</th><th>Aksi</th></tr>
                <?php foreach ($orphans as $row): ?>
                    <tr>
                        <td><?php echo ma_cleanup_e((string)$row['id']); ?></td>
                        <td><strong><?php echo ma_cleanup_e((string)$row['maintenance_asset_code']); ?></strong></td>
                        <td><?php echo ma_cleanup_e((string)($row['pc_label'] ?? $row['pc_id'])); ?></td>
                        <td><?php echo ma_cleanup_e((string)($row['name'] ?? '')); ?></td>
                        <td><?php echo ma_cleanup_e((string)($row['company_id'] ?? '')); ?></td>
                        <td><?php echo ma_cleanup_e((string)($row['location_label'] ?? '')); ?></td>
                        <td>
                            <form class="inline" method="post" onsubmit="return confirm('Hapus maintenance asset ini?');">
                                <input type="hidden" name="csrf" value="<?php echo ma_cleanup_e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="remove_one">
                                <input type="hidden" name="id" value="<?php echo ma_cleanup_e((string)$row['id']); ?>">
                                <button class="danger" type="submit">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="ok">Tidak ada maintenance asset orphan dari PC yang belum tersinkron ke Asset Item.</p>
        <?php endif; ?>
        <p style="margin-top:12px"><a href="index.php?route=maintenance_assets">&laquo; Kembali ke Maintenance Assets</a></p>
    </div>
</body>
</html>