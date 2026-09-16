<?php

declare(strict_types=1);

function handle_route_labels(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('QR Label Unit Aset', $user);

    $type = trim((string)($_GET['type'] ?? 'all'));
    $search = trim((string)($_GET['q'] ?? ''));

    // Query Unit Aset (asset_items sebagai Single Source of Truth)
    $sql = "SELECT ai.*, c.company_name, ag.group_name, ag.group_code, at.type_name, at.type_code, loc.location_name
            FROM asset_items ai
            LEFT JOIN asset_companies c ON c.id = ai.company_id
            LEFT JOIN asset_groups ag ON ag.id = ai.asset_group_id
            LEFT JOIN asset_types at ON at.id = ai.asset_type_id
            LEFT JOIN asset_locations loc ON loc.id = ai.location_id
            WHERE ai.status <> 'inactive'";
    
    $params = [];
    if ($type !== 'all' && $type !== '') {
        if ($type === 'bundle') {
            $sql .= " AND ai.asset_mode = 'group'";
        } elseif ($type === 'pc') {
            $sql .= " AND at.type_code IN ('CMP', 'NBK', 'SRV')";
        } elseif ($type === 'printer') {
            $sql .= " AND at.type_code = 'PRT'";
        } elseif ($type === 'vehicle') {
            $sql .= " AND ag.group_code = 'VH'";
        } elseif ($type === 'facility') {
            $sql .= " AND ag.group_code IN ('BLD', 'FCL', 'EQP')";
        } else {
            $sql .= " AND ag.group_code NOT IN ('IT', 'VH', 'BLD', 'FCL')";
        }
    }

    if ($search !== '') {
        $sql .= " AND (ai.asset_code LIKE ? OR ai.asset_name LIKE ? OR ai.custodian_name LIKE ? OR ai.location_label LIKE ? OR ai.serial_number LIKE ?)";
        $sTerm = '%' . $search . '%';
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
    }

    $sql .= " ORDER BY (ai.asset_mode = 'group') DESC, ai.asset_code ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-load bundle relationships jika tabel relasi aktif
    $bundleChildrenMap = [];
    $parentBundleMap = [];
    if (db_table_exists($pdo, 'asset_item_members')) {
        $cStmt = $pdo->query("SELECT aim.parent_asset_item_id, c.id child_id, c.asset_code, c.asset_name, aim.role_name 
                              FROM asset_item_members aim 
                              JOIN asset_items c ON c.id = aim.child_asset_item_id 
                              WHERE aim.detached_at IS NULL ORDER BY c.asset_code ASC");
        while ($cRow = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            $pId = (int)$cRow['parent_asset_item_id'];
            $bundleChildrenMap[$pId][] = $cRow;
        }

        $pStmt = $pdo->query("SELECT aim.child_asset_item_id, p.id parent_id, p.asset_code parent_asset_code, p.asset_name parent_asset_name 
                              FROM asset_item_members aim 
                              JOIN asset_items p ON p.id = aim.parent_asset_item_id 
                              WHERE aim.detached_at IS NULL");
        while ($pRow = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $chId = (int)$pRow['child_asset_item_id'];
            $parentBundleMap[$chId] = $pRow;
        }
    }

    ?>
    <section class="panel no-print">
        <div class="split">
            <div>
                <h1>QR Label Unit Aset</h1>
                <p class="muted" style="margin-top:4px;">Cetak stiker QR Code resmi untuk seluruh Unit Aset (Terenkripsi AES-256). Mendukung pemindaian tunggal untuk paket bundling.</p>
            </div>
            <div class="actions">
                <a class="btn <?= $type === 'all' ? 'primary' : '' ?>" href="<?= route_url('labels') ?>">Semua</a>
                <a class="btn <?= $type === 'bundle' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'bundle']) ?>">📦 Paket Bundling</a>
                <a class="btn <?= $type === 'pc' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'pc']) ?>">PC & Laptop</a>
                <a class="btn <?= $type === 'printer' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'printer']) ?>">Printer</a>
                <a class="btn <?= $type === 'vehicle' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'vehicle']) ?>">Kendaraan</a>
                <a class="btn <?= $type === 'facility' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'facility']) ?>">Fasilitas & Gedung</a>
                <button class="btn good" onclick="window.print()">🖨️ Cetak Semua</button>
            </div>
        </div>

        <form method="get" style="margin-top:14px;display:flex;gap:10px;align-items:center;">
            <input type="hidden" name="route" value="labels">
            <?php if ($type !== 'all'): ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
            <?php endif; ?>
            <input name="q" value="<?= e($search) ?>" placeholder="Cari kode aset, nama unit, pemegang/pengguna, atau lokasi..." style="flex:1;">
            <button class="btn primary" style="width:auto;">Cari</button>
            <?php if ($search !== ''): ?>
                <a class="btn" href="<?= route_url('labels', $type !== 'all' ? ['type' => $type] : []) ?>">Reset</a>
            <?php endif; ?>
        </form>
    </section>

    <?php if (empty($assets)): ?>
        <section class="panel">
            <p class="muted" style="text-align:center;padding:24px 0;">Tidak ada Unit Aset yang ditemukan untuk filter ini.</p>
        </section>
    <?php else: ?>
        <section class="label-grid">
            <?php foreach ($assets as $row): 
                $assetCode = (string)$row['asset_code'];
                $url = mobile_encrypted_asset_url($assetCode);

                $assetMode = (string)($row['asset_mode'] ?? 'standalone');
                $isGroup = ($assetMode === 'group');
                $isChild = ($assetMode === 'child');

                $children = $bundleChildrenMap[(int)$row['id']] ?? [];
                $parentInfo = $parentBundleMap[(int)$row['id']] ?? null;

                $typeName = (string)($row['type_name'] ?: ($row['group_name'] ?: 'Unit Aset'));
                $displayName = (string)($row['asset_name'] ?: ($row['model'] ?: $assetCode));
                $detailLine = trim((string)($row['location_label'] ?: ($row['location_name'] ?: ($row['company_name'] ?: ''))));
                if (!empty($row['custodian_name'])) {
                    $detailLine = trim($row['custodian_name'] . ($detailLine !== '' ? ' • ' . $detailLine : ''));
                }
            ?>
                <div class="qr-label" style="<?= $isGroup ? 'border:2px solid #1e40af;box-shadow:0 2px 8px rgba(30,64,175,0.12);' : '' ?>">
                    <strong style="font-size:17px;margin-top:4px;text-align:center;word-break:break-all;"><?= e($assetCode) ?></strong>
                    
                    <div style="text-align:center;margin:4px 0;">
                        <?= pseudo_qr_hotfix($url) ?>
                    </div>

                    <?php if ($isGroup): ?>
                        <div style="background:#1e3a8a;color:#fff;padding:4px 6px;border-radius:4px;font-weight:700;font-size:11px;text-align:center;margin:2px 0;">
                            📦 INDUK BUNDLE
                        </div>
                        <div style="font-size:10px;color:#1e40af;font-weight:700;text-align:center;margin-bottom:4px;line-height:1.25;">
                            ✨ Scan QR Induk ini untuk pemeliharaan seluruh paket unit
                        </div>
                        <?php if (!empty($children)): ?>
                            <div style="font-size:9.5px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:4px;padding:4px;margin-bottom:4px;text-align:left;line-height:1.35;max-height:80px;overflow-y:auto;">
                                <span style="font-weight:700;color:#334155;">Anggota Paket (<?= count($children) ?>):</span><br>
                                <?php foreach ($children as $ch): ?>
                                    • <?= e($ch['role_name'] ?: 'Item') ?>: <b><?= e($ch['asset_code']) ?></b> (<?= e($ch['asset_name']) ?>)<br>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($isChild): ?>
                        <div style="background:#f1f5f9;border:1px dashed #64748b;color:#475569;padding:2px 4px;border-radius:4px;font-size:10.5px;text-align:center;margin:2px 0;font-weight:600;">
                            🔗 ANGGOTA BUNDLE
                        </div>
                        <?php if ($parentInfo): ?>
                            <div style="text-align:center;color:#64748b;font-size:10px;margin-bottom:2px;">
                                Induk: <b><?= e($parentInfo['parent_asset_code']) ?></b>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="text-align:center;color:#64748b;font-size:10.5px;margin:2px 0;">
                            Unit Standalone (Mandiri)
                        </div>
                    <?php endif; ?>

                    <small style="text-align:center;font-weight:600;color:#334155;"><?= e($typeName) ?> (Encrypted)</small>
                    <small style="text-align:center;color:#0f172a;font-weight:700;font-size:12px;"><?= e($displayName) ?></small>
                    <?php if ($detailLine !== ''): ?>
                        <small style="text-align:center;color:#64748b;font-size:11px;"><?= e($detailLine) ?></small>
                    <?php endif; ?>

                    <a class="btn no-print js-label-download" 
                       style="margin-top:8px;text-align:center;font-size:12px;padding:6px 8px;"
                       data-code="<?= e($assetCode) ?>" 
                       data-type="<?= e($isGroup ? '[INDUK BUNDLE] ' . $typeName : $typeName) ?>" 
                       data-name="<?= e($displayName) ?>" 
                       data-detail="<?= e($detailLine) ?>" 
                       data-payload="<?= e($url) ?>" 
                       download="PcConnect-Label-<?= e($assetCode) ?>.png" 
                       href="<?= route_url('qr_png', ['code' => $assetCode]) ?>">
                        Download Label PNG
                    </a>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?= label_download_script() ?>
    <?php
    render_footer();
}

function handle_route_qr_png(PDO $pdo): void
{
    require_role(['admin', 'corrective_maintenance', 'technician']);
    $assetCode = strtoupper(trim((string)($_GET['code'] ?? '')));
    [$baseCode, $security] = parse_asset_code($assetCode);

    $ai = null;
    $pc = null;
    $printer = null;

    // 1. Cari di asset_items sebagai Single Source of Truth
    if (db_table_exists($pdo, 'asset_items')) {
        $stmt = $pdo->prepare('SELECT ai.*, ag.group_name, at.type_name, c.company_name, loc.location_name
                               FROM asset_items ai
                               LEFT JOIN asset_groups ag ON ag.id = ai.asset_group_id
                               LEFT JOIN asset_types at ON at.id = ai.asset_type_id
                               LEFT JOIN asset_companies c ON c.id = ai.company_id
                               LEFT JOIN asset_locations loc ON loc.id = ai.location_id
                               WHERE ai.asset_code = ? OR ai.asset_code = ? LIMIT 1');
        $stmt->execute([$assetCode, $baseCode]);
        $ai = $stmt->fetch();
    }

    // 2. Fallback PC atau Printer
    if (!$ai && db_table_exists($pdo, 'pcs')) {
        $stmt = $pdo->prepare('SELECT pc_id, security_code, owner_name, computer_name FROM pcs WHERE pc_id=?');
        $stmt->execute([$baseCode]);
        $pc = $stmt->fetch();
    }

    if (!$ai && !$pc && printer_schema_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT prn_id, security_code, printer_name, location, model_printer FROM printers WHERE prn_id=?');
        $stmt->execute([$baseCode]);
        $printer = $stmt->fetch();
    }

    if (!$ai && !$pc && !$printer) {
        http_response_code(404);
        exit('QR tidak ditemukan.');
    }

    $finalCode = $assetCode;
    $typeName = 'Unit Aset';
    $name = '';
    $detail = '';
    $isBundleParent = false;

    if ($ai) {
        $finalCode = (string)$ai['asset_code'];
        $isBundleParent = (($ai['asset_mode'] ?? '') === 'group');
        $typeName = (string)($ai['type_name'] ?: ($ai['group_name'] ?: 'Unit Aset'));
        if ($isBundleParent) {
            $typeName = '[INDUK BUNDLE] ' . $typeName;
        }
        $name = (string)($ai['asset_name'] ?: ($ai['model'] ?: ''));
        $detail = trim((string)($ai['location_label'] ?: ($ai['location_name'] ?: ($ai['custodian_name'] ?: ''))));
    } elseif ($pc) {
        $finalCode = asset_code($pc);
        $typeName = 'PC Asset';
        $name = (string)($pc['owner_name'] ?? '');
        $detail = (string)($pc['computer_name'] ?? '');
    } elseif ($printer) {
        $finalCode = printer_asset_code($printer);
        $typeName = 'Printer Asset';
        $name = (string)($printer['printer_name'] ?? '');
        $detail = trim((string)($printer['location'] ?? '') . ' ' . (string)($printer['model_printer'] ?? ''));
    }

    $encryptedUrl = mobile_encrypted_asset_url($finalCode);
    $qrUrl = qr_image_url($encryptedUrl, 900);
    $png = @file_get_contents($qrUrl);

    if ($png !== false && strlen($png) > 100 && function_exists('imagecreatetruecolor') && function_exists('imagecreatefromstring')) {
        $qr = @imagecreatefromstring($png);
        if ($qr) {
            $width = 900;
            $height = 1180;
            $img = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($img, 255, 255, 255);
            $black = imagecolorallocate($img, 17, 24, 39);
            $blue = imagecolorallocate($img, 30, 64, 175);
            $muted = imagecolorallocate($img, 71, 85, 105);
            imagefilledrectangle($img, 0, 0, $width, $height, $white);
            imagerectangle($img, 20, 20, $width - 21, $height - 21, $isBundleParent ? $blue : $black);

            label_center_text($img, $finalCode, 58, 66, $isBundleParent ? $blue : $black, true);
            label_center_text($img, $typeName, 28, 175, $isBundleParent ? $blue : $muted);

            $qrSize = 610;
            imagecopyresampled($img, $qr, (int)(($width - $qrSize) / 2), 260, 0, 0, $qrSize, $qrSize, imagesx($qr), imagesy($qr));
            imagedestroy($qr);

            $badgeText = $isBundleParent ? 'PcConnect Bundle Parent QR (Scan Paket)' : 'PcConnect Asset QR (Encrypted)';
            label_center_text($img, $badgeText, 30, 925, $isBundleParent ? $blue : $black, true);
            label_center_text($img, $name, 26, 1005, $muted);
            if ($detail !== '') {
                label_center_text($img, $detail, 24, 1065, $muted);
            }

            ob_start();
            imagepng($img);
            $labelPng = (string)ob_get_clean();
            imagedestroy($img);
            header('Content-Type: image/png');
            header('Content-Disposition: attachment; filename="PcConnect-Label-' . preg_replace('/[^A-Z0-9_-]/', '', $finalCode) . '.png"');
            header('Content-Length: ' . strlen($labelPng));
            echo $labelPng;
            exit;
        }
    }

    if ($png !== false && strlen($png) > 100) {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="PcConnect-QR-' . preg_replace('/[^A-Z0-9_-]/', '', $finalCode) . '.png"');
        header('Content-Length: ' . strlen($png));
        echo $png;
        exit;
    }

    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Download label gagal karena server tidak bisa mengambil QR dari generator eksternal.\n";
    echo "QR payload: " . $encryptedUrl;
    exit;
}
