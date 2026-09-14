<?php

declare(strict_types=1);

function handle_route_labels(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('QR Label Maintenance Asset', $user);

    $type = trim((string)($_GET['type'] ?? 'all'));
    $search = trim((string)($_GET['q'] ?? ''));

    // Query Maintenance Assets
    $sql = "SELECT ma.*, c.company_name, ag.group_name, ag.group_code, at.type_name, at.type_code,
                   p.owner_name AS pc_owner, p.computer_name,
                   pr.printer_name, pr.location AS prn_location, pr.model_printer
            FROM maintenance_assets ma
            LEFT JOIN asset_companies c ON c.id = ma.company_id
            LEFT JOIN asset_groups ag ON ag.id = ma.asset_group_id
            LEFT JOIN asset_types at ON at.id = ma.asset_type_id
            LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci
            LEFT JOIN printers pr ON pr.prn_id COLLATE utf8mb4_unicode_ci = ma.printer_id COLLATE utf8mb4_unicode_ci
            WHERE ma.status <> 'inactive'";
    
    $params = [];
    if ($type !== 'all' && $type !== '') {
        if ($type === 'pc') {
            $sql .= " AND ma.maintenance_type = 'pc_set'";
        } elseif ($type === 'printer') {
            $sql .= " AND ma.maintenance_type = 'printer'";
        } elseif ($type === 'vehicle') {
            $sql .= " AND ma.maintenance_type = 'vehicle'";
        } elseif ($type === 'facility') {
            $sql .= " AND ma.maintenance_type = 'facility'";
        } else {
            $sql .= " AND ma.maintenance_type NOT IN ('pc_set', 'printer', 'vehicle', 'facility')";
        }
    }

    if ($search !== '') {
        $sql .= " AND (ma.maintenance_asset_code LIKE ? OR ma.name LIKE ? OR ma.owner_name LIKE ? OR ma.location_label LIKE ?)";
        $sTerm = '%' . $search . '%';
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
    }

    $sql .= " ORDER BY ma.maintenance_asset_code ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ?>
    <section class="panel no-print">
        <div class="split">
            <div>
                <h1>QR Label Maintenance Asset</h1>
                <p class="muted" style="margin-top:4px;">Cetak stiker QR Code resmi untuk unit Maintenance Asset (Terenkripsi AES-256).</p>
            </div>
            <div class="actions">
                <a class="btn <?= $type === 'all' ? 'primary' : '' ?>" href="<?= route_url('labels') ?>">Semua</a>
                <a class="btn <?= $type === 'pc' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'pc']) ?>">PC / Komputer</a>
                <a class="btn <?= $type === 'printer' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'printer']) ?>">Printer</a>
                <a class="btn <?= $type === 'vehicle' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'vehicle']) ?>">Kendaraan</a>
                <a class="btn <?= $type === 'facility' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'facility']) ?>">Fasilitas</a>
                <a class="btn <?= $type === 'other' ? 'primary' : '' ?>" href="<?= route_url('labels', ['type' => 'other']) ?>">Lainnya</a>
                <button class="btn good" onclick="window.print()">🖨️ Cetak Semua</button>
            </div>
        </div>

        <form method="get" style="margin-top:14px;display:flex;gap:10px;align-items:center;">
            <input type="hidden" name="route" value="labels">
            <?php if ($type !== 'all'): ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
            <?php endif; ?>
            <input name="q" value="<?= e($search) ?>" placeholder="Cari kode unit, nama aset, pengguna, atau lokasi..." style="flex:1;">
            <button class="btn primary" style="width:auto;">Cari</button>
            <?php if ($search !== ''): ?>
                <a class="btn" href="<?= route_url('labels', $type !== 'all' ? ['type' => $type] : []) ?>">Reset</a>
            <?php endif; ?>
        </form>
    </section>

    <?php if (empty($assets)): ?>
        <section class="panel">
            <p class="muted" style="text-align:center;padding:24px 0;">Tidak ada Maintenance Asset yang ditemukan untuk filter ini.</p>
        </section>
    <?php else: ?>
        <section class="label-grid">
            <?php foreach ($assets as $row): 
                $secCode = trim((string)($row['security_code'] ?? ''));
                if ($secCode === '') {
                    $secCode = 'SEC01';
                }
                $assetCode = (string)$row['maintenance_asset_code'] . '-' . $secCode;
                $url = mobile_encrypted_asset_url($assetCode);

                // Format type name
                $typeName = 'Maintenance Asset';
                if ($row['maintenance_type'] === 'pc_set') {
                    $typeName = 'PC Maintenance Asset';
                } elseif ($row['maintenance_type'] === 'printer') {
                    $typeName = 'Printer Maintenance Asset';
                } elseif ($row['maintenance_type'] === 'vehicle') {
                    $typeName = 'Vehicle Maintenance Asset';
                } elseif ($row['maintenance_type'] === 'facility') {
                    $typeName = 'Facility Maintenance Asset';
                }

                $displayName = $row['name'] ?: ($row['owner_name'] ?: ($row['pc_owner'] ?: ($row['printer_name'] ?: 'Unit Asset')));
                $detailLine = trim((string)($row['location_label'] ?: ($row['company_name'] ?: '')));
            ?>
                <div class="qr-label">
                    <strong style="font-size:18px;margin-top:8px;text-align:center;word-break:break-all;"><?= e($assetCode) ?></strong>
                    <div style="text-align:center;margin:4px 0;">
                        <?= pseudo_qr_hotfix($url) ?>
                    </div>
                    <small style="text-align:center;font-weight:600;color:#334155;"><?= e($typeName) ?> (Encrypted)</small>
                    <small style="text-align:center;color:#475569;font-weight:700;"><?= e($displayName) ?></small>
                    <?php if ($detailLine !== ''): ?>
                        <small style="text-align:center;color:#64748b;font-size:11px;"><?= e($detailLine) ?></small>
                    <?php endif; ?>
                    <a class="btn no-print js-label-download" 
                       style="margin-top:8px;text-align:center;font-size:12px;padding:6px 8px;"
                       data-code="<?= e($assetCode) ?>" 
                       data-type="<?= e($typeName) ?>" 
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

    $maintenanceAsset = null;
    $pc = null;
    $printer = null;

    if (db_table_exists($pdo, 'maintenance_assets')) {
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE maintenance_asset_code=? LIMIT 1');
        $stmt->execute([$baseCode]);
        $maintenanceAsset = $stmt->fetch() ?: null;
        
        if (!$maintenanceAsset) {
            $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE pc_id=? LIMIT 1');
            $stmt->execute([$baseCode]);
            $maintenanceAsset = $stmt->fetch() ?: null;
        }

        if (!$maintenanceAsset) {
            $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE printer_id=? LIMIT 1');
            $stmt->execute([$baseCode]);
            $maintenanceAsset = $stmt->fetch() ?: null;
        }

        if ($maintenanceAsset && !empty($maintenanceAsset['pc_id'])) {
            $stmt = $pdo->prepare('SELECT p.*, ma.maintenance_asset_code FROM pcs p JOIN maintenance_assets ma ON ma.pc_id COLLATE utf8mb4_unicode_ci = p.pc_id COLLATE utf8mb4_unicode_ci WHERE p.pc_id=? LIMIT 1');
            $stmt->execute([(string)$maintenanceAsset['pc_id']]);
            $pc = $stmt->fetch() ?: null;
        }

        if ($maintenanceAsset && !empty($maintenanceAsset['printer_id']) && printer_schema_ready($pdo)) {
            $stmt = $pdo->prepare('SELECT pr.*, ma.maintenance_asset_code FROM printers pr JOIN maintenance_assets ma ON ma.printer_id COLLATE utf8mb4_unicode_ci = pr.prn_id COLLATE utf8mb4_unicode_ci WHERE pr.prn_id=? LIMIT 1');
            $stmt->execute([(string)$maintenanceAsset['printer_id']]);
            $printer = $stmt->fetch() ?: null;
        }
    }

    if (!$maintenanceAsset) {
        $stmt = $pdo->prepare('SELECT pc_id, security_code, owner_name, computer_name FROM pcs WHERE pc_id=?');
        $stmt->execute([$baseCode]);
        $pc = $stmt->fetch();
    }

    if (!$pc && !$printer && printer_schema_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT prn_id, security_code, printer_name, location, model_printer FROM printers WHERE prn_id=?');
        $stmt->execute([$baseCode]);
        $printer = $stmt->fetch();
    }

    if (!$pc && !$printer && !$maintenanceAsset) {
        http_response_code(404);
        exit('QR tidak ditemukan.');
    }

    $finalCode = $assetCode;
    $typeName = 'Maintenance Asset';
    $name = '';
    $detail = '';

    if ($maintenanceAsset) {
        $finalCode = (string)$maintenanceAsset['maintenance_asset_code'] . '-' . (string)$maintenanceAsset['security_code'];
        $name = (string)($maintenanceAsset['name'] ?: ($maintenanceAsset['owner_name'] ?: ''));
        $detail = (string)($maintenanceAsset['location_label'] ?: '');
        if ($maintenanceAsset['maintenance_type'] === 'pc_set') {
            $typeName = 'PC Maintenance Asset';
        } elseif ($maintenanceAsset['maintenance_type'] === 'printer') {
            $typeName = 'Printer Maintenance Asset';
        } elseif ($maintenanceAsset['maintenance_type'] === 'vehicle') {
            $typeName = 'Vehicle Maintenance Asset';
        } elseif ($maintenanceAsset['maintenance_type'] === 'facility') {
            $typeName = 'Facility Maintenance Asset';
        }
    } elseif ($pc) {
        $finalCode = asset_code($pc);
        $typeName = 'PC Maintenance Asset';
        $name = (string)($pc['owner_name'] ?? '');
        $detail = (string)($pc['computer_name'] ?? '');
    } elseif ($printer) {
        $finalCode = printer_asset_code($printer);
        $typeName = 'Printer Maintenance Asset';
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
            $muted = imagecolorallocate($img, 71, 85, 105);
            imagefilledrectangle($img, 0, 0, $width, $height, $white);
            imagerectangle($img, 20, 20, $width - 21, $height - 21, $black);

            label_center_text($img, $finalCode, 58, 66, $black, true);
            label_center_text($img, $typeName, 28, 175, $muted);

            $qrSize = 610;
            imagecopyresampled($img, $qr, (int)(($width - $qrSize) / 2), 260, 0, 0, $qrSize, $qrSize, imagesx($qr), imagesy($qr));
            imagedestroy($qr);

            label_center_text($img, 'PcConnect Maintenance QR (Encrypted)', 30, 925, $black, true);
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

