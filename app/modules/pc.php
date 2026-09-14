<?php

declare(strict_types=1);

function next_pc_id(PDO $pdo): string
{
    $rows = $pdo->query("SELECT pc_id FROM pcs WHERE pc_id REGEXP '^PC[0-9]+$'")->fetchAll(PDO::FETCH_COLUMN);
    $max = 0;
    foreach ($rows as $pcId) {
        if (preg_match('/^PC(\d+)$/', (string)$pcId, $matches)) {
            $max = max($max, (int)$matches[1]);
        }
    }
    do {
        $next = 'PC' . str_pad((string)(++$max), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pcs WHERE pc_id=?');
        $stmt->execute([$next]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $next;
}

function pc_security_code_seed(string $pcId): string
{
    return strtoupper(chr(65 + (crc32($pcId) % 26)) . (crc32($pcId . '-pcconnect') % 10));
}

function pc_security_code_random(): string
{
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
}

function null_if_empty(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function saved_location_datalist_html(): string
{
    $labels = [];
    try {
        $pdo = Database::pdo();
        if (db_table_exists($pdo, 'pcs') && db_column_exists($pdo, 'pcs', 'location_label')) {
            foreach ($pdo->query('SELECT DISTINCT location_label FROM pcs WHERE location_label IS NOT NULL AND location_label<>"" ORDER BY location_label LIMIT 200')->fetchAll(PDO::FETCH_COLUMN) as $label) {
                $labels[(string)$label] = true;
            }
        }
        if (db_table_exists($pdo, 'printers') && db_column_exists($pdo, 'printers', 'location')) {
            foreach ($pdo->query('SELECT DISTINCT location FROM printers WHERE location IS NOT NULL AND location<>"" ORDER BY location LIMIT 200')->fetchAll(PDO::FETCH_COLUMN) as $label) {
                $labels[(string)$label] = true;
            }
        }
    } catch (Throwable $ignored) {
    }
    $html = '<datalist id="savedLocationGroups">';
    foreach (array_keys($labels) as $label) {
        $html .= '<option value="' . e($label) . '"></option>';
    }
    return $html . '</datalist>';
}

/**
 * Memastikan entitas maintenance asset untuk PC.
 * SYARAT MUTLAK: PC WAJIB sudah tersinkronisasi ke Asset Item (asset_item_id > 0).
 * Jika belum tersinkronisasi, fungsi ini return null dan tidak membuat maintenance asset.
 */
function ensure_pc_maintenance_asset(PDO $pdo, array $pc): ?array
{
    $pcId = (string)($pc['pc_id'] ?? '');
    if ($pcId === '') {
        return null;
    }

    // Guard ketat: PC tanpa asset_item_id TIDAK boleh dibuatkan / diberikan Maintenance Asset
    if (empty($pc['asset_item_id'])) {
        return null;
    }

    // Unit aset child bundle TIDAK boleh masuk / dibuatkan Maintenance Asset
    $aiStmt = $pdo->prepare('SELECT asset_mode FROM asset_items WHERE id=?');
    $aiStmt->execute([(int)$pc['asset_item_id']]);
    $aiMode = (string)$aiStmt->fetchColumn();
    if ($aiMode === 'child') {
        return null;
    }
    if (db_table_exists($pdo, 'asset_item_members')) {
        $chkMem = $pdo->prepare('SELECT 1 FROM asset_item_members WHERE child_asset_item_id=? AND detached_at IS NULL LIMIT 1');
        $chkMem->execute([(int)$pc['asset_item_id']]);
        if ($chkMem->fetchColumn()) {
            return null;
        }
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
        $itemStmt = $pdo->prepare('SELECT asset_name, asset_code, company_id, custodian_name, custodian_nik, location_label FROM asset_items WHERE id=?');
        $itemStmt->execute([(int)$pc['asset_item_id']]);
        $assetItem = $itemStmt->fetch() ?: null;

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
            $employeeNik = trim((string)($assetItem['custodian_nik'] ?? ''));
            if ($employeeNik === '') {
                $employeeNik = trim((string)($pc['employee_nik'] ?? ''));
            }
            $locationLabel = trim((string)($assetItem['location_label'] ?? ''));
            if ($locationLabel === '') {
                $locationLabel = trim((string)($pc['location_label'] ?? ''));
            }
            $notes = 'Auto dibuat dari Asset Item terpilih.';
        } else {
            $name = $pcId;
            $companyId = null;
            $ownerName = $pc['owner_name'] ?? null;
            $employeeNik = $pc['employee_nik'] ?? null;
            $locationLabel = trim((string)($pc['location_label'] ?? ''));
            $notes = 'Auto dibuat dari data PC.';
        }

        $code = unique_maintenance_asset_code($pdo, maintenance_asset_code_seed('MNT', $pcId));
        $pdo->prepare('INSERT INTO maintenance_assets (maintenance_asset_code, security_code, maintenance_type, asset_item_id, name, pc_id, company_id, employee_nik, owner_name, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $code,
            (string)($pc['security_code'] ?? pc_security_code_random()),
            'pc_set',
            !empty($pc['asset_item_id']) ? (int)$pc['asset_item_id'] : null,
            $name,
            $pcId,
            $companyId,
            $employeeNik !== '' ? $employeeNik : null,
            $ownerName,
            $locationLabel !== '' ? $locationLabel : null,
            $pc['latitude'] ?? null,
            $pc['longitude'] ?? null,
            max(1, (int)($pc['location_radius_m'] ?? 5)),
            'active',
            $notes,
        ]);
        $rowId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
    }

    if ($row) {
        $pdo->prepare('UPDATE pcs SET maintenance_asset_id=? WHERE pc_id=?')->execute([(int)$row['id'], $pcId]);
        if (!empty($pc['asset_item_id']) && function_exists('link_maintenance_asset_item')) {
            link_maintenance_asset_item($pdo, (int)$row['id'], (int)$pc['asset_item_id'], 'PC / Asset Item');
        }
    }
    return $row ?: null;
}

function sync_pc_maintenance_asset(PDO $pdo, string $pcId): void
{
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch();
    if (!$pc) {
        return;
    }

    // Jika PC tidak memiliki asset_item_id atau unit asetnya adalah child bundle, bersihkan maintenance_asset_id
    $isChildAsset = false;
    if (!empty($pc['asset_item_id'])) {
        $aiStmt = $pdo->prepare('SELECT asset_mode FROM asset_items WHERE id=?');
        $aiStmt->execute([(int)$pc['asset_item_id']]);
        $aiMode = (string)$aiStmt->fetchColumn();
        if ($aiMode === 'child') {
            $isChildAsset = true;
        } elseif (db_table_exists($pdo, 'asset_item_members')) {
            $chkMem = $pdo->prepare('SELECT 1 FROM asset_item_members WHERE child_asset_item_id=? AND detached_at IS NULL LIMIT 1');
            $chkMem->execute([(int)$pc['asset_item_id']]);
            if ($chkMem->fetchColumn()) {
                $isChildAsset = true;
            }
        }
    }

    if (empty($pc['asset_item_id']) || $isChildAsset) {
        if (!empty($pc['maintenance_asset_id'])) {
            $oldMntId = (int)$pc['maintenance_asset_id'];
            $pdo->prepare('UPDATE pcs SET maintenance_asset_id=NULL WHERE pc_id=?')->execute([$pcId]);
            $pdo->prepare('DELETE FROM maintenance_asset_items WHERE maintenance_asset_id=?')->execute([$oldMntId]);
            $pdo->prepare('DELETE FROM maintenance_assets WHERE id=?')->execute([$oldMntId]);
        }
        return;
    }

    $asset = ensure_pc_maintenance_asset($pdo, $pc);
    if (!$asset) {
        return;
    }
    $itemStmt = $pdo->prepare('SELECT asset_name, asset_code, company_id, custodian_name, custodian_nik, location_label FROM asset_items WHERE id=?');
    $itemStmt->execute([(int)$pc['asset_item_id']]);
    $assetItem = $itemStmt->fetch() ?: null;
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
        $employeeNik = trim((string)($assetItem['custodian_nik'] ?? ''));
        if ($employeeNik === '') {
            $employeeNik = trim((string)($pc['employee_nik'] ?? ''));
        }
        $locationLabel = trim((string)($assetItem['location_label'] ?? ''));
        if ($locationLabel === '') {
            $locationLabel = trim((string)($pc['location_label'] ?? ''));
        }
    } else {
        $name = $pcId;
        $companyId = null;
        $ownerName = $pc['owner_name'] ?? null;
        $employeeNik = $pc['employee_nik'] ?? null;
        $locationLabel = trim((string)($pc['location_label'] ?? ''));
    }
    $pdo->prepare('UPDATE maintenance_assets SET security_code=?, name=?, company_id=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=?, asset_item_id=COALESCE(asset_item_id, ?) WHERE id=?')->execute([
        (string)$pc['security_code'],
        $name,
        $companyId,
        $employeeNik !== '' ? $employeeNik : null,
        $ownerName,
        $locationLabel !== '' ? $locationLabel : null,
        $pc['latitude'] ?? null,
        $pc['longitude'] ?? null,
        max(1, (int)($pc['location_radius_m'] ?? 5)),
        !empty($pc['asset_item_id']) ? (int)$pc['asset_item_id'] : null,
        (int)$asset['id'],
    ]);
    if (function_exists('link_maintenance_asset_item')) {
        link_maintenance_asset_item($pdo, (int)$asset['id'], (int)$pc['asset_item_id'], 'PC / Asset Item');
    }
}

function pc_asset_link_summary(PDO $pdo, array $pc): string
{
    $lines = [];
    $itemId = (int)($pc['asset_item_id'] ?? 0);
    if ($itemId > 0) {
        if (!empty($pc['asset_code'])) {
            $rawMode = (string)($pc['asset_mode'] ?? 'standalone');
            $mode = $rawMode === 'group' ? 'Bundle (Parent)' : ($rawMode === 'child' ? 'Bundle (Child)' : 'Single');
            $cat = trim((string)($pc['asset_category'] ?? ''));
            $lines[] = 'Asset Item: ' . $pc['asset_code'] . ' - ' . ($pc['asset_name'] ?? $pc['asset_code']) . ' (' . ($cat !== '' ? $cat . ' / ' : '') . ($pc['asset_type'] ?? 'PC') . ' / ' . $mode . ')';
        } else {
            $stmt = $pdo->prepare('SELECT asset_code, asset_name, asset_type, asset_category, asset_mode FROM asset_items WHERE id=?');
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if ($item) {
                $rawMode = (string)($item['asset_mode'] ?? 'standalone');
                $mode = $rawMode === 'group' ? 'Bundle (Parent)' : ($rawMode === 'child' ? 'Bundle (Child)' : 'Single');
                $cat = trim((string)($item['asset_category'] ?? ''));
                $lines[] = 'Asset Item: ' . $item['asset_code'] . ' - ' . $item['asset_name'] . ' (' . ($cat !== '' ? $cat . ' / ' : '') . $item['asset_type'] . ' / ' . $mode . ')';
            }
        }
    } else {
        $lines[] = '[Belum Sync ke Asset Item]';
    }
    $maintId = (int)($pc['maintenance_asset_id'] ?? 0);
    if ($maintId > 0 && $itemId > 0) {
        if (!empty($pc['maintenance_asset_code'])) {
            $lines[] = 'MNT ID: ' . $pc['maintenance_asset_code'];
        } else {
            $stmt = $pdo->prepare('SELECT maintenance_asset_code FROM maintenance_assets WHERE id=?');
            $stmt->execute([$maintId]);
            $code = (string)($stmt->fetchColumn() ?: '');
            if ($code !== '') {
                $lines[] = 'MNT ID: ' . $code;
            }
        }
    }
    return implode("\n", $lines) ?: '-';
}

function validate_pc_scan_location(PDO $pdo, string $pcId, ?float $scanLat, ?float $scanLng): ?string
{
    $stmt = $pdo->prepare('SELECT latitude, longitude, location_radius_m, location_label FROM pcs WHERE pc_id=?');
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch();
    if (!$pc || $pc['latitude'] === null || $pc['longitude'] === null || $pc['latitude'] === '' || $pc['longitude'] === '') {
        return null;
    }
    if ($scanLat === null || $scanLng === null) {
        return 'GPS teknisi belum terbaca. Aktifkan izin Location/GPS di browser lalu scan ulang.';
    }
    $radius = max(1, (int)($pc['location_radius_m'] ?? 5));
    $distance = geo_distance_m((float)$pc['latitude'], (float)$pc['longitude'], $scanLat, $scanLng);
    if ($distance > $radius) {
        return 'Scan ditolak. Jarak dari titik PC ' . round($distance, 1) . ' meter, maksimal ' . $radius . ' meter' . ($pc['location_label'] ? ' (' . $pc['location_label'] . ')' : '') . '.';
    }
    return null;
}

function pc_table(array $rows, bool $actions = false): string
{
    if (!$rows) {
        return '<p>Belum ada data PC.</p>';
    }
    $pdo = Database::pdo();
    $html = '<table><tr><th>PcID</th><th>NIK</th><th>Pengguna</th><th>Computer Name</th><th>Manajemen Aset</th><th>Analisa Terakhir</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $rowActions = '<a class="btn" href="' . route_url('pc_detail', ['pc_id' => $row['pc_id']]) . '">Detail</a>';
        if ($actions) {
            $rowActions .= ' <a class="btn" href="' . route_url('pc_form', ['pc_id' => $row['pc_id']]) . '">Edit</a>';
        }
        $html .= '<tr><td>' . e($row['pc_id']) . '</td><td>' . e($row['employee_nik'] ?? '-') . '</td><td>' . e($row['owner_name']) . '</td><td>' . e($row['computer_name'] ?? '-') . '</td><td>' . nl2br(e(pc_asset_link_summary($pdo, $row))) . '</td><td>' . e($row['last_analyzed_at'] ?? '-') . '</td><td>' . $rowActions . '</td></tr>';
    }
    return $html . '</table>';
}

function pc_asset_link_form_html(array $pc): string
{
    try {
        $pdo = Database::pdo();
        ensure_asset_management_schema($pdo);
        $itemSelected = (int)($pc['asset_item_id'] ?? 0) ?: null;
        $currentPcId = (string)($pc['pc_id'] ?? '');
        $html = '<section class="panel"><h2>Tautan Manajemen Aset</h2><p class="muted">Pilih <strong>Kode Unit Aset</strong> yang belum terhubung ke PC mana pun untuk PC ini agar Maintenance Asset dan QR dapat diterbitkan secara otomatis.</p>';
        $html .= '<label>Kode Unit Aset<select name="asset_item_id">' . (function_exists('asset_item_options') ? asset_item_options($pdo, $itemSelected, $currentPcId) : '<option value="">Pilih</option>') . '</select></label>';
        $html .= '<div class="actions" style="margin-top:12px;">';
        $html .= '<a class="btn" href="' . route_url('asset_item_form', ['asset_category' => 'Computer']) . '">+ Tambah Unit Aset Baru</a>';
        $html .= '<a class="btn" href="' . route_url('asset_items') . '">Kelola Unit Aset</a>';
        if (!empty($pc['pc_id'])) {
            $html .= '<button class="btn good" type="submit" formaction="' . route_url('pc_asset_sync') . '" formmethod="post">Sinkronkan PC ke Unit Aset</button>';
        }
        $html .= '</div></section>';
        return $html;
    } catch (Throwable $e) {
        return '<section class="panel"><h2>Tautan Manajemen Aset</h2><div class="flash err">Pilihan aset belum siap: ' . e($e->getMessage()) . '</div></section>';
    }
}

function pc_asset_link_detail_html(PDO $pdo, array $pc): string
{
    $itemId = (int)($pc['asset_item_id'] ?? 0);
    $maintenanceAssetId = (int)($pc['maintenance_asset_id'] ?? 0);
    $maintenanceAssetCode = '';
    if ($maintenanceAssetId > 0 && $itemId > 0) {
        $stmt = $pdo->prepare('SELECT maintenance_asset_code FROM maintenance_assets WHERE id=?');
        $stmt->execute([$maintenanceAssetId]);
        $maintenanceAssetCode = (string)($stmt->fetchColumn() ?: '');
    }
    $item = null;
    if ($itemId > 0) {
        $stmt = $pdo->prepare('SELECT ai.*, c.company_name FROM asset_items ai LEFT JOIN asset_companies c ON c.id=ai.company_id WHERE ai.id=?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch() ?: null;
    }
    $html = '<section class="panel"><div class="split"><div><h2>Tautan Manajemen Aset</h2><p class="muted">Relasi PC ke Unit Aset dan Maintenance Asset ID.</p></div><a class="btn" href="' . route_url('pc_form', ['pc_id' => $pc['pc_id'] ?? '']) . '">Edit Relasi</a></div>';
    $html .= '<div class="grid two"><div class="mini-card"><strong>Maintenance Asset ID</strong><br>';
    if ($itemId <= 0) {
        $html .= '<span class="badge danger">Belum Sync ke Unit Aset</span><p class="muted">Maintenance Asset & QR hanya dibuat setelah PC disinkronkan ke Unit Aset.</p>';
    } else {
        $html .= '<span class="badge ok">' . e($maintenanceAssetCode !== '' ? $maintenanceAssetCode : 'Tersinkronisasi') . '</span>';
    }
    $html .= '</div>';
    if ($item) {
        $rawMode = (string)($item['asset_mode'] ?? 'standalone');
        $mode = $rawMode === 'group' ? 'Bundle (Parent Aset)' : ($rawMode === 'child' ? 'Bundle (Child Aset)' : 'Single');
        $cat = trim((string)($item['asset_category'] ?? ''));
        $html .= '<div class="mini-card"><strong>Unit Aset Terhubung</strong><table><tr><th>Kode Aset</th><td>' . e($item['asset_code']) . '</td></tr><tr><th>Nama</th><td>' . e($item['asset_name']) . '</td></tr><tr><th>Kategori</th><td><span class="badge ok">' . e($cat !== '' ? $cat : '-') . '</span></td></tr><tr><th>Tipe / Mode</th><td>' . e($item['asset_type'] . ' / ' . $mode) . '</td></tr><tr><th>Company</th><td>' . e($item['company_name'] ?: '-') . '</td></tr><tr><th>Serial</th><td>' . e($item['serial_number'] ?: '-') . '</td></tr></table><p><a class="btn" href="' . route_url('asset_item_form', ['id' => $item['id']]) . '">Buka Unit Aset</a></p></div>';
    } else {
        $html .= '<div class="mini-card"><strong>Unit Aset Terhubung</strong><p class="muted">Belum ada unit aset yang dihubungkan ke PC ini. Hubungkan lewat Edit PC.</p></div>';
    }
    $html .= '</div></section>';
    return $html;
}

function pc_form_html(array $pc, bool $editing): string
{
    $assetCode = '';
    $hasSync = !empty($pc['asset_item_id']);
    if ($editing && !empty($pc['pc_id']) && !empty($pc['security_code']) && $hasSync) {
        $assetCode = asset_code($pc);
    }
    $pcTitle = $editing ? (string)$pc['pc_id'] : 'PC Baru';
    $pcSubtitle = trim((string)($pc['owner_name'] ?? '') . ' - ' . (string)($pc['computer_name'] ?? ''), ' -');
    if ($pcSubtitle === '') {
        $pcSubtitle = $editing ? 'Identitas PC' : 'PcID, Secret QR, dan Computer Name dibuat otomatis.';
    }
    $html = '<style>.pc-form textarea{min-height:112px;font-family:Consolas,monospace;font-size:13px}.pc-form input,.pc-form textarea{font-size:14px}.pc-form .identity-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.pc-form .location-grid{display:grid;grid-template-columns:minmax(220px,1.2fr) repeat(3,minmax(120px,.55fr));gap:14px}.pc-form .analysis-grid-edit{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.pc-form .analysis-grid-edit details{border:1px solid #dfe5ee;border-radius:8px;padding:12px;background:#fff}.pc-form .analysis-grid-edit summary{font-weight:700;cursor:pointer}.pc-form .analysis-grid-edit details.wide{grid-column:1/-1}.pc-form .gps-status{border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:10px;margin-top:12px}.pc-form .coord-help{font-size:12px;color:#475569}.pc-form .footer-actions{position:sticky;bottom:0;background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:12px;margin-top:16px;box-shadow:0 -8px 22px rgba(15,23,42,.06)}@media(max-width:980px){.pc-form .identity-grid,.pc-form .location-grid,.pc-form .analysis-grid-edit{grid-template-columns:1fr}.pc-form .analysis-grid-edit details.wide{grid-column:auto}.pc-form .footer-actions{position:static}}</style>';
    $html .= '<form class="pc-form" method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $html .= '<section class="panel"><div class="split"><div><h1>' . ($editing ? 'Edit ' . e($pcTitle) : 'Tambah PC Baru') . '</h1><p>' . e($pcSubtitle) . '</p>' . ($assetCode !== '' ? '<p><span class="badge">Maintenance Asset ID ' . e($assetCode) . '</span></p>' : (!$hasSync && $editing ? '<p><span class="badge danger">Belum Tersinkron ke Asset Item</span></p>' : '')) . '</div><div class="actions"><a class="btn" href="' . route_url('pcs') . '">Kembali ke Data PC</a>';
    if ($assetCode !== '') {
        $html .= '<a class="btn" download="PcConnect-Label-' . e($assetCode) . '.png" href="' . route_url('qr_png', ['code' => $assetCode]) . '">Download Label PNG</a>';
        $html .= '<a class="btn" href="' . route_url('pc_location', ['pc_id' => $pc['pc_id']]) . '">Set Lokasi GPS</a>';
    }
    $html .= '</div></div></section>';

    $html .= '<section class="panel"><h2>Identitas PC</h2><div class="identity-grid">';
    if ($editing) {
        $html .= '<label>PcID<input name="pc_id" value="' . e($pc['pc_id']) . '" readonly></label><label>Secret QR<input name="security_code" value="' . e($pc['security_code']) . '" placeholder="Secret QR"></label>';
    } else {
        $html .= '<label>PcID<input value="Otomatis saat disimpan" readonly></label><label>Secret QR<input value="Otomatis random" readonly></label>';
    }
    $html .= employee_picker_html($pc);
    if ($editing) {
        $html .= '<label>Computer Name<input name="computer_name" value="' . e($pc['computer_name']) . '" placeholder="Otomatis dari PcNalisa"></label>';
    } else {
        $html .= '<label>Computer Name<input value="Otomatis dari PcNalisa" readonly></label>';
    }
    $html .= '</div></section>';

    $html .= pc_asset_link_form_html($pc);

    $mapHref = (!empty($pc['latitude']) && !empty($pc['longitude'])) ? 'https://www.google.com/maps?q=' . rawurlencode((string)$pc['latitude'] . ',' . (string)$pc['longitude']) : 'https://www.google.com/maps';
    $html .= saved_location_datalist_html();
    $html .= '<section class="panel"><div class="split"><div><h2>Titik Lokasi PC</h2><p class="muted">Koordinat ini menjadi patokan scan QR teknisi. Jika latitude dan longitude diisi, scan hanya valid dalam radius yang diset, default 5 meter. Nama titik lokasi yang pernah disimpan bisa dipilih ulang dari list.</p></div><div class="actions">' . ($editing ? '<a class="btn" href="' . route_url('pc_location', ['pc_id' => $pc['pc_id']]) . '">Set dari HP</a>' : '') . '<a class="btn" target="_blank" rel="noopener" href="' . e($mapHref) . '">Google Maps</a></div></div><div class="location-grid"><label>Group / Nama Titik Lokasi<input name="location_label" list="savedLocationGroups" value="' . e($pc['location_label'] ?? '') . '" placeholder="Contoh: Lantai 2 - Meja Finance"></label><label>Latitude<input id="pcLatitude" name="latitude" value="' . e($pc['latitude'] ?? '') . '" placeholder="-6.2000000"></label><label>Longitude<input id="pcLongitude" name="longitude" value="' . e($pc['longitude'] ?? '') . '" placeholder="106.8166660"></label><label>Radius Meter<input name="location_radius_m" type="number" min="1" max="100" value="' . e($pc['location_radius_m'] ?? 5) . '"></label></div><div class="gps-status"><div class="actions"><button class="btn primary" type="button" id="useCurrentLocation">Ambil GPS Perangkat Ini</button></div><p class="coord-help">Untuk akurasi terbaik, buka dari ponsel saat berdiri di titik PC lalu tekan tombol GPS.</p><p id="locationStatus" class="muted"></p></div></section>';

    $html .= '<section class="panel"><h2>Kondisi & Rekomendasi</h2><div class="grid two"><label>Kondisi Fisik<textarea name="physical_condition" placeholder="Catatan kondisi awal PC">' . e($pc['physical_condition']) . '</textarea></label><label>AI Recommendation<textarea name="ai_recommendation" placeholder="Rekomendasi maintenance">' . e($pc['ai_recommendation']) . '</textarea></label></div></section>';

    $html .= '<section class="panel"><h2>Data Analisa PcNalisa</h2><p class="muted">Bagian ini otomatis diisi dari upload/agent PcNalisa. Edit manual hanya jika perlu koreksi.</p><div class="analysis-grid-edit">';
    $analysisFields = [
        'general_specs' => 'Spesifikasi Umum',
        'software' => 'Software',
        'device_management' => 'Device Management',
        'benchmark' => 'Benchmark',
        'startup_analysis' => 'Startup Analysis',
    ];
    foreach ($analysisFields as $name => $label) {
        $open = $name === 'device_management' || $name === 'startup_analysis' ? ' open' : '';
        $wide = in_array($name, ['device_management', 'startup_analysis'], true) ? ' class="wide"' : '';
        $html .= '<details' . $wide . $open . '><summary>' . e($label) . '</summary><label>' . e($label) . '<textarea name="' . e($name) . '" placeholder="Boleh isi manual atau nanti diisi otomatis dari PcNalisa JSON">' . e($pc[$name]) . '</textarea></label></details>';
    }
    $html .= '</div></section><section class="footer-actions"><div class="actions"><button class="btn primary">' . ($editing ? 'Simpan Perubahan' : 'Tambah PC') . '</button><a class="btn" href="' . route_url('pcs') . '">Batal</a>';
    if (!$editing) {
        $html .= '<a class="btn" href="' . route_url('upload_analysis') . '">Tambah Lewat JSON PcNalisa</a>';
    }
    $html .= '</div></section></form>';
    $html .= '<script>(function(){var btn=document.getElementById("useCurrentLocation"),lat=document.getElementById("pcLatitude"),lng=document.getElementById("pcLongitude"),status=document.getElementById("locationStatus");if(!btn)return;btn.addEventListener("click",function(){if(!navigator.geolocation){status.textContent="Browser tidak mendukung GPS.";return;}status.textContent="Mengambil lokasi...";navigator.geolocation.getCurrentPosition(function(p){lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);status.textContent="Lokasi tersimpan di form. Akurasi perangkat sekitar "+Math.round(p.coords.accuracy)+" meter.";},function(e){status.textContent="Gagal mengambil lokasi. Pastikan izin Location aktif atau isi manual dari Google Maps.";},{enableHighAccuracy:true,timeout:15000,maximumAge:0});});})();</script>';
    return $html;
}

function pc_form_fallback_html(array $pc, bool $editing, string $error): string
{
    $title = $editing ? 'Edit PC' : 'Tambah PC Baru';
    $html = '<section class="panel"><div class="flash err">Form utama gagal dibuka: ' . e($error) . '</div><h1>' . e($title) . '</h1><p class="muted">Form aman ini tetap bisa dipakai untuk tambah/edit PC.</p></section>';
    $html .= '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '">';
    if ($editing) {
        $html .= '<label>PcID<input name="pc_id" value="' . e($pc['pc_id'] ?? '') . '" readonly></label>';
        $html .= '<label>Secret QR<input name="security_code" value="' . e($pc['security_code'] ?? '') . '" required></label>';
        $html .= '<label>Computer Name<input name="computer_name" value="' . e($pc['computer_name'] ?? '') . '"></label>';
    } else {
        $html .= '<div class="grid two"><label>PcID<input value="Otomatis saat simpan" readonly></label><label>Secret QR<input value="Otomatis random" readonly></label></div>';
    }
    $html .= employee_picker_html($pc, true);
    $html .= saved_location_datalist_html();
    $html .= '<label>Nama / Titik Lokasi<input name="location_label" list="savedLocationGroups" value="' . e($pc['location_label'] ?? '') . '" placeholder="Contoh: Lantai 2 - Meja Finance"></label>';
    $html .= '<div class="grid three"><label>Latitude<input name="latitude" value="' . e($pc['latitude'] ?? '') . '" placeholder="-6.2000000"></label><label>Longitude<input name="longitude" value="' . e($pc['longitude'] ?? '') . '" placeholder="106.8166660"></label><label>Radius Meter<input name="location_radius_m" type="number" min="1" max="100" value="' . e($pc['location_radius_m'] ?? 5) . '"></label></div>';
    $html .= '<label>Kondisi Fisik<textarea name="physical_condition">' . e($pc['physical_condition'] ?? '') . '</textarea></label>';
    $html .= '<div class="actions"><button class="btn primary">' . ($editing ? 'Simpan Perubahan' : 'Tambah PC') . '</button><a class="btn" href="' . route_url('pcs') . '">Kembali ke Data PC</a></div>';
    $html .= '</form></section>';
    return $html;
}

function pc_asset_sync_type_options(PDO $pdo, int $selected = 0): string
{
    $h = '<option value="">- Pilih Tipe Asset -</option>';
    $s = $pdo->prepare("SELECT id,type_code,type_name FROM asset_types WHERE type_code IN ('CMP','NBK') AND is_active=1 ORDER BY type_name");
    $s->execute();
    foreach ($s as $r) {
        $h .= '<option value="' . e($r['id']) . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['type_name']) . '</option>';
    }
    return $h;
}

function employee_picker_shell(string $label, string $searchId, string $listId, array $rows, string $selectedNik = ''): string
{
    $countId = $listId . 'Count';
    $html = '<div class="employee-picker-wrap" style="margin-bottom:12px;padding:12px;border:1px solid #d9e1ee;border-radius:8px;background:#f8fafc"><label style="margin-top:0;font-weight:600;color:#1e293b;">' . e($label) . ' dari Master Pengguna<input id="' . e($searchId) . '" placeholder="Ketik NIK, Nama, atau Departemen..." autocomplete="off"></label><select id="' . e($listId) . '" size="5" style="margin-top:6px;width:100%">';
    foreach ($rows as $row) {
        $nik = (string)($row['nik'] ?? '');
        $name = (string)($row['name'] ?? '');
        $dept = (string)($row['department'] ?? '');
        $sel = ($selectedNik !== '' && $nik === $selectedNik) ? ' selected' : '';
        $html .= '<option value="' . e($nik) . '"' . $sel . '>' . e($nik . ' - ' . $name . ($dept !== '' ? ' - ' . $dept : '')) . '</option>';
    }
    $html .= '</select><div id="' . e($countId) . '" class="muted" style="font-size:12px;margin-top:4px">Menampilkan ' . count($rows) . ' pengguna dari Master Pengguna. Klik salah satu baris untuk mengisi form otomatis.</div></div>';
    return $html;
}

function employee_picker_html(array $pc, bool $fallback = false): string
{
    $currentNik = trim((string)($pc['employee_nik'] ?? ''));
    $currentOwner = (string)($pc['owner_name'] ?? '');
    $html = '';
    try {
        $rows = fetch_employee_options(Database::pdo());
        if ($rows) {
            $html .= employee_picker_shell('Cari dan Pilih', 'employeePortalSearch', 'employeePortalList', $rows, $currentNik);
            $html .= '<input type="hidden" id="employeeNik" name="employee_nik" value="' . e($currentNik) . '"><label>Pengguna<input id="ownerNameInput" name="owner_name" value="' . e($currentOwner) . '" placeholder="Nama pengguna akan otomatis terisi dari pilihan karyawan" required></label>';
            $html .= '<script>(function(){function init(){var search=document.getElementById("employeePortalSearch"),list=document.getElementById("employeePortalList"),count=document.getElementById("employeePortalListCount"),nik=document.getElementById("employeeNik"),pengguna=document.getElementById("ownerNameInput"),endpoint=' . js_value(route_url('employee_search')) . ';if(!search||!list||!nik||!pengguna)return;var rows=' . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';function label(row){return (row.nik||"")+" - "+(row.name||"")+(row.department?" - "+row.department:"");}function setCount(){if(count){count.textContent="Menampilkan "+rows.length+" hasil dari Master Pengguna.";}}function render(newRows){rows=newRows||[];var selectedNik=nik.value;list.innerHTML="";rows.forEach(function(row,idx){var opt=document.createElement("option");opt.value=row.nik||"";opt.textContent=label(row);if(selectedNik&&selectedNik===opt.value){opt.selected=true;list.selectedIndex=idx;}list.appendChild(opt);});setCount();}function apply(){var row=rows.find(function(item){return item.nik===list.value;});if(row){nik.value=row.nik||"";pengguna.value=row.name||"";}}function localFilter(q){q=(q||"").toLowerCase();if(!q){render(rows);return;}var filtered=rows.filter(function(row){return label(row).toLowerCase().indexOf(q)!==-1;});render(filtered);}function load(q){fetch(endpoint+"&limit=200&q="+encodeURIComponent(q||""),{headers:{"Accept":"application/json"}}).then(function(r){return r.json();}).then(function(j){var d=(j&&j.data)?j.data:(Array.isArray(j)?j:[]);render(d);}).catch(function(){localFilter(q);});}var timer=null;search.addEventListener("input",function(){localFilter(search.value);clearTimeout(timer);timer=setTimeout(function(){load(search.value);},250);});list.addEventListener("change",apply);list.addEventListener("dblclick",apply);setCount();apply();}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}})();</script>';
            return $html;
        }
    } catch (Throwable $e) {
    }
    $html .= '<div style="background:#fffbeb;border:1px solid #fde68a;padding:8px 12px;border-radius:6px;color:#92400e;font-size:12px;margin-bottom:10px;">ℹ️ Master Pengguna belum terisi. Buka menu <strong>Setup &rarr; Master &rarr; Master Pengguna</strong> untuk menambah pengguna atau sinkronisasi dari MariaDB Portal. Anda juga dapat mengetik Pengguna secara manual di bawah.</div>';
    $html .= '<label>NIK Karyawan<input name="employee_nik" value="' . e($currentNik) . '" placeholder="Opsional (manual)"></label>';
    $html .= '<label>Pengguna<input name="owner_name" value="' . e($currentOwner) . '" placeholder="Nama pengguna atau departemen" required></label>';
    return $html;
}

function employee_portal_name_picker_html(string $prefix, string $nameInputId, string $usernameInputId = ''): string
{
    $rows = [];
    try {
        $rows = employee_search_rows(Database::pdo(), '', 200);
    } catch (Throwable $ignored) {
    }
    if (!$rows) {
        return '<div style="background:#fffbeb;border:1px solid #fde68a;padding:8px 12px;border-radius:6px;color:#92400e;font-size:12px;margin-top:6px;margin-bottom:12px;">ℹ️ Master Pengguna masih kosong. Anda dapat mengimpor dari MariaDB di menu <strong>Setup &rarr; Master &rarr; Master Pengguna</strong>, atau ketik manual di atas.</div>';
    }
    $searchId = $prefix . 'EmployeeSearch';
    $listId = $prefix . 'EmployeeSelect';
    $html = employee_picker_shell('Cari dan Pilih', $searchId, $listId, $rows);
    $html .= '<script>(function(){function init(){var search=document.getElementById("' . e($searchId) . '"),list=document.getElementById("' . e($listId) . '"),count=document.getElementById("' . e($listId) . 'Count"),nameInput=document.getElementById("' . e($nameInputId) . '"),usernameInput=' . ($usernameInputId !== '' ? 'document.getElementById("' . e($usernameInputId) . '")' : 'null') . ',endpoint=' . js_value(route_url('employee_search')) . ';if(!search||!list||!nameInput)return;var rows=' . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';function label(row){return (row.nik||"")+" - "+(row.name||"")+(row.department?" - "+row.department:"");}function setCount(){if(count){count.textContent="Menampilkan "+rows.length+" pengguna dari Master Pengguna.";}}function render(newRows){rows=newRows||[];list.innerHTML="";rows.forEach(function(row){var opt=document.createElement("option");opt.value=row.nik||"";opt.textContent=label(row);list.appendChild(opt);});setCount();}function apply(){var row=rows.find(function(item){return item.nik===list.value;});if(row){nameInput.value=row.name||"";if(usernameInput&&row.nik){usernameInput.value=row.nik;}}}function localFilter(q){q=(q||"").toLowerCase();if(!q){render(rows);return;}var filtered=rows.filter(function(row){return label(row).toLowerCase().indexOf(q)!==-1;});render(filtered);}function load(q){fetch(endpoint+"&limit=200&q="+encodeURIComponent(q||""),{headers:{"Accept":"application/json"}}).then(function(r){return r.json();}).then(function(j){var d=(j&&j.data)?j.data:(Array.isArray(j)?j:[]);render(d);}).catch(function(){localFilter(q);});}var timer=null;search.addEventListener("input",function(){localFilter(search.value);clearTimeout(timer);timer=setTimeout(function(){load(search.value);},250);});list.addEventListener("change",apply);list.addEventListener("dblclick",apply);setCount();apply();}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}})();</script>';
    return $html;
}

function fetch_employee_options(PDO $pdo, int $limit = 500): array
{
    if (!db_table_exists($pdo, 'employee_directory')) {
        return [];
    }
    $hasName = db_column_exists($pdo, 'employee_directory', 'name');
    $nameExpr = $hasName ? "COALESCE(NULLIF(name, ''), employee_name, '')" : "employee_name";
    $stmt = $pdo->prepare("SELECT nik, $nameExpr AS name, department FROM employee_directory WHERE is_active = 1 ORDER BY name ASC LIMIT ?");
    $stmt->bindValue(1, max(1, min(5000, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function employee_search_rows(PDO $pdo, string $term = '', int $limit = 200): array
{
    if (!db_table_exists($pdo, 'employee_directory')) {
        return [];
    }
    $hasName = db_column_exists($pdo, 'employee_directory', 'name');
    $nameExpr = $hasName ? "COALESCE(NULLIF(name, ''), employee_name, '')" : "employee_name";
    $term = trim($term);
    $limit = max(1, min(500, $limit));
    if ($term === '') {
        $stmt = $pdo->prepare("SELECT nik, $nameExpr AS name, department FROM employee_directory WHERE is_active = 1 ORDER BY name ASC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $wildcard = '%' . $term . '%';
    $stmt = $pdo->prepare("SELECT nik, $nameExpr AS name, department FROM employee_directory WHERE is_active = 1 AND (nik LIKE ? OR $nameExpr LIKE ? OR department LIKE ?) ORDER BY name ASC LIMIT ?");
    $stmt->bindValue(1, $wildcard);
    $stmt->bindValue(2, $wildcard);
    $stmt->bindValue(3, $wildcard);
    $stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function find_employee_by_nik(PDO $pdo, string $nik): ?array
{
    $nik = trim($nik);
    if ($nik === '' || !db_table_exists($pdo, 'employee_directory')) {
        return null;
    }
    $hasName = db_column_exists($pdo, 'employee_directory', 'name');
    $nameExpr = $hasName ? "COALESCE(NULLIF(name, ''), employee_name, '')" : "employee_name";
    $stmt = $pdo->prepare("SELECT nik, $nameExpr AS name, department FROM employee_directory WHERE nik = ? LIMIT 1");
    $stmt->execute([$nik]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function decode_json_payload(string $raw): array
{
    $raw = trim($raw);
    $clean = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $data = json_decode($clean, true);
    return [$data, json_last_error_msg(), strlen($clean)];
}

function ingest_analysis_payload(PDO $pdo, array $payload): array
{
    $pcId = strtoupper(trim((string)($payload['pc_id'] ?? '')));
    $owner = trim((string)($payload['owner_name'] ?? ''));
    $computerName = trim((string)($payload['computer_name'] ?? ''));
    $specs = is_array($payload['general_specs'] ?? null) ? json_encode($payload['general_specs'], JSON_UNESCAPED_UNICODE) : (string)($payload['general_specs'] ?? '');
    $software = is_array($payload['software'] ?? null) ? json_encode($payload['software'], JSON_UNESCAPED_UNICODE) : (string)($payload['software'] ?? '');
    $device = is_array($payload['device_management'] ?? null) ? json_encode($payload['device_management'], JSON_UNESCAPED_UNICODE) : (string)($payload['device_management'] ?? '');
    $benchmark = is_array($payload['benchmark'] ?? null) ? json_encode($payload['benchmark'], JSON_UNESCAPED_UNICODE) : (string)($payload['benchmark'] ?? '');
    $startup = is_array($payload['startup_analysis'] ?? null) ? json_encode($payload['startup_analysis'], JSON_UNESCAPED_UNICODE) : (string)($payload['startup_analysis'] ?? '');
    $summary = analytical_summary([
        'general_specs' => $specs,
        'software' => $software,
        'device_management' => $device,
        'benchmark' => $benchmark,
        'startup_analysis' => $startup,
    ]);

    $stmt = $pdo->prepare('SELECT pc_id, security_code, asset_item_id FROM pcs WHERE pc_id = ?');
    $stmt->execute([$pcId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE pcs SET owner_name = COALESCE(NULLIF(owner_name,""), ?), computer_name = ?, general_specs = ?, software = ?, device_management = ?, benchmark = ?, startup_analysis = ?, ai_recommendation = ?, last_analyzed_at = NOW() WHERE pc_id = ?')->execute([
            $owner, $computerName, $specs, $software, $device, $benchmark, $startup, $summary, $pcId
        ]);
    } else {
        $sec = pc_security_code_seed($pcId);
        $pdo->prepare('INSERT INTO pcs (pc_id, security_code, owner_name, computer_name, general_specs, software, device_management, benchmark, startup_analysis, ai_recommendation, last_analyzed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([
            $pcId, $sec, $owner, $computerName, $specs, $software, $device, $benchmark, $startup, $summary
        ]);
    }

    if (db_table_exists($pdo, 'analysis_runs')) {
        $pdo->prepare('INSERT INTO analysis_runs (pc_id, raw_payload, summary, created_at) VALUES (?, ?, ?, NOW())')->execute([
            $pcId, json_encode($payload, JSON_UNESCAPED_UNICODE), $summary
        ]);
    }

    sync_pc_maintenance_asset($pdo, $pcId);
    return ['pc_id' => $pcId, 'summary' => $summary];
}

function handle_route_pcs(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('Data PC', $user);
    $q = trim((string)($_GET['q'] ?? ''));
    $baseSql = "SELECT p.*, 
                       ai.asset_code, ai.asset_name, ai.asset_type, ai.asset_category, ai.asset_mode,
                       ma.maintenance_asset_code
                FROM pcs p
                LEFT JOIN asset_items ai ON ai.id = p.asset_item_id
                LEFT JOIN maintenance_assets ma ON ma.id = p.maintenance_asset_id";
    if ($q !== '') {
        $stmt = $pdo->prepare("$baseSql WHERE p.pc_id LIKE ? OR p.owner_name LIKE ? OR p.computer_name LIKE ? ORDER BY p.pc_id");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    } else {
        $stmt = $pdo->query("$baseSql ORDER BY p.pc_id");
    }
    echo '<section class="panel"><div class="split"><h1>Data PC</h1><div class="actions"><a class="btn primary" href="' . route_url('pc_form') . '">Tambah PC</a><a class="btn" href="' . route_url('pc_locations') . '">Kelola Lokasi GPS</a><a class="btn" href="' . route_url('upload_analysis') . '">Upload JSON Analisa</a><a class="btn" href="' . route_url('export_excel', ['type' => 'pcs']) . '">Export Excel</a></div></div>';
    echo '<form method="get" style="margin-top:14px"><input type="hidden" name="route" value="pcs"><div class="grid two"><label>Cari PC<input name="q" value="' . e($q) . '" placeholder="Cari PcID, pengguna, atau computer name..."></label><div class="actions" style="align-items:flex-end"><button class="btn primary">Cari</button><a class="btn" href="' . route_url('pcs') . '">Reset</a></div></div></form></section>';
    echo '<section class="panel">' . pc_table($stmt->fetchAll(), true) . '</section>';
    render_footer();
}

function handle_route_pc_form(PDO $pdo): void
{
    $user = require_role(['admin']);
    $pcId = strtoupper(trim((string)($_GET['pc_id'] ?? $_POST['pc_id'] ?? '')));
    $editing = $pcId !== '';
    $pc = [
        'pc_id' => '',
        'security_code' => '',
        'employee_nik' => '',
        'owner_name' => '',
        'computer_name' => '',
        'location_label' => '',
        'latitude' => '',
        'longitude' => '',
        'location_radius_m' => 5,
        'physical_condition' => '',
        'general_specs' => '',
        'software' => '',
        'device_management' => '',
        'benchmark' => '',
        'startup_analysis' => '',
        'ai_recommendation' => '',
        'asset_item_id' => '',
        'asset_bundle_id' => '',
    ];
    if ($editing) {
        $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
        $stmt->execute([$pcId]);
        $found = $stmt->fetch();
        if (!$found) {
            http_response_code(404);
            exit('PC tidak ditemukan.');
        }
        $pc = array_merge($pc, $found);
        $pcMaintenanceAsset = ensure_pc_maintenance_asset($pdo, $pc);
        if ($pcMaintenanceAsset) {
            $pc['maintenance_asset_code'] = $pcMaintenanceAsset['maintenance_asset_code'];
            $pc['security_code'] = $pcMaintenanceAsset['security_code'];
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $employeeNik = trim((string)($_POST['employee_nik'] ?? ''));
        $ownerName = trim((string)($_POST['owner_name'] ?? ''));
        if ($employeeNik !== '') {
            try {
                $employee = find_employee_by_nik($pdo, $employeeNik);
                if ($employee) {
                    $ownerName = (string)$employee['name'];
                }
            } catch (Throwable $ignored) {
            }
        }
        if ($ownerName === '') {
            flash('Pengguna PC wajib diisi.', 'err');
            redirect_to('pc_form', $editing ? ['pc_id' => $pcId] : []);
        }

        $postedPcId = $editing ? $pcId : next_pc_id($pdo);
        $securityCode = $editing ? strtoupper(trim((string)($_POST['security_code'] ?? ''))) : pc_security_code_random();
        $computerName = $editing ? trim((string)($_POST['computer_name'] ?? '')) : '';
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $securityCode)) {
            flash('Secret QR hanya boleh huruf/angka, panjang 2-12 karakter.', 'err');
            redirect_to('pc_form', $editing ? ['pc_id' => $pcId] : []);
        }
        $latitude = normalize_decimal_input((string)($_POST['latitude'] ?? ''));
        $longitude = normalize_decimal_input((string)($_POST['longitude'] ?? ''));
        if (($latitude === null) !== ($longitude === null)) {
            flash('Latitude dan longitude harus diisi lengkap, atau kosongkan keduanya.', 'err');
            redirect_to('pc_form', $editing ? ['pc_id' => $pcId] : []);
        }
        if (($latitude !== null && ($latitude < -90 || $latitude > 90)) || ($longitude !== null && ($longitude < -180 || $longitude > 180))) {
            flash('Koordinat lokasi PC tidak valid.', 'err');
            redirect_to('pc_form', $editing ? ['pc_id' => $pcId] : []);
        }
        $pcData = [
            'security_code' => $securityCode,
            'employee_nik' => $employeeNik !== '' ? $employeeNik : null,
            'owner_name' => $ownerName,
            'computer_name' => $computerName,
            'asset_item_id' => (int)($_POST['asset_item_id'] ?? 0) > 0 ? (int)$_POST['asset_item_id'] : null,
            'asset_bundle_id' => null,
            'location_label' => trim((string)($_POST['location_label'] ?? '')),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_radius_m' => max(1, (int)(($_POST['location_radius_m'] ?? '') !== '' ? $_POST['location_radius_m'] : 5)),
            'physical_condition' => trim((string)($_POST['physical_condition'] ?? '')),
            'general_specs' => null_if_empty((string)($_POST['general_specs'] ?? '')),
            'software' => null_if_empty((string)($_POST['software'] ?? '')),
            'device_management' => null_if_empty((string)($_POST['device_management'] ?? '')),
            'benchmark' => null_if_empty((string)($_POST['benchmark'] ?? '')),
            'startup_analysis' => null_if_empty((string)($_POST['startup_analysis'] ?? '')),
            'ai_recommendation' => null_if_empty((string)($_POST['ai_recommendation'] ?? '')),
        ];
        $analysisPcColumns = ['general_specs', 'software', 'device_management', 'benchmark', 'startup_analysis', 'ai_recommendation'];
        if (!$editing) {
            foreach ($analysisPcColumns as $column) {
                if ($pcData[$column] === null) {
                    unset($pcData[$column]);
                }
            }
        }
        $requiredPcColumns = ['security_code', 'owner_name', 'computer_name'];
        foreach (array_keys($pcData) as $column) {
            if (!in_array($column, $requiredPcColumns, true) && !db_column_exists($pdo, 'pcs', $column)) {
                unset($pcData[$column]);
            }
        }
        try {
            if (!empty($pcData['asset_item_id'])) {
                $chk = $pdo->prepare("SELECT asset_code, asset_mode FROM asset_items WHERE id=?");
                $chk->execute([(int)$pcData['asset_item_id']]);
                $chkRow = $chk->fetch();
                if ($chkRow && ($chkRow['asset_mode'] ?? '') === 'child') {
                    throw new RuntimeException("Asset item '{$chkRow['asset_code']}' berstatus 'Bundle (Child Asset)' dan tidak dapat disinkronkan ke PC. Silakan pilih Parent Asset atau pisahkan asset ini terlebih dahulu.");
                }
            }
            if ($editing) {
                $setParts = [];
                foreach (array_keys($pcData) as $column) {
                    $setParts[] = $column . '=?';
                }
                $stmt = $pdo->prepare('UPDATE pcs SET ' . implode(', ', $setParts) . ' WHERE pc_id=?');
                $stmt->execute([...array_values($pcData), $pcId]);
                sync_pc_maintenance_asset($pdo, $pcId);
                flash('Data PC berhasil diperbarui.');
                redirect_to('pc_detail', ['pc_id' => $pcId]);
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pcs WHERE pc_id=?');
            $stmt->execute([$postedPcId]);
            if ((int)$stmt->fetchColumn() > 0) {
                flash('PcID sudah ada. Gunakan PcID lain.', 'err');
                redirect_to('pc_form');
            }
            $columns = array_keys($pcData);
            $placeholders = implode(', ', array_fill(0, count($columns) + 1, '?'));
            $stmt = $pdo->prepare('INSERT INTO pcs (pc_id, ' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute([$postedPcId, ...array_values($pcData)]);
            sync_pc_maintenance_asset($pdo, $postedPcId);
            flash('PC baru berhasil ditambahkan.');
            redirect_to('pc_detail', ['pc_id' => $postedPcId]);
        } catch (Throwable $e) {
            flash('Simpan PC gagal: ' . $e->getMessage(), 'err');
            redirect_to('pc_form');
        }
    }
    render_header($editing ? 'Edit PC' : 'Tambah PC', $user);
    try {
        echo pc_form_html($pc, $editing);
    } catch (Throwable $e) {
        echo pc_form_fallback_html($pc, $editing, $e->getMessage());
    }
    render_footer();
}

function handle_route_pc_detail(PDO $pdo): void
{
    $user = require_role(['admin']);
    $pcId = (string)($_GET['pc_id'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id = ?');
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch();
    if (!$pc) {
        http_response_code(404);
        exit('PC tidak ditemukan.');
    }
    $hasSync = !empty($pc['asset_item_id']);
    $pcMaintenanceAsset = ensure_pc_maintenance_asset($pdo, $pc);
    if ($pcMaintenanceAsset) {
        $pc['maintenance_asset_code'] = $pcMaintenanceAsset['maintenance_asset_code'];
        $pc['security_code'] = $pcMaintenanceAsset['security_code'];
    }
    render_header('Detail ' . $pcId, $user);
    echo '<style>.analysis-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.analysis-grid .panel{min-width:0;overflow:hidden}.analysis-table{table-layout:auto}.analysis-table td,.analysis-table th{overflow-wrap:anywhere;word-break:normal}.wide-panel{grid-column:1/-1}@media(max-width:900px){.analysis-grid{grid-template-columns:1fr}}</style>';
    $assetCode = $hasSync ? asset_code($pc) : '';
    echo '<section class="panel"><div class="split"><div><h1>' . e($pc['pc_id']) . '</h1><p>' . e($pc['owner_name']) . ' - ' . e($pc['computer_name'] ?: 'Computer name belum ada') . '</p>';
    if ($hasSync && $assetCode !== '') {
        echo '<p><span class="badge">Maintenance Asset ID ' . e($assetCode) . '</span> <span class="badge">NIK ' . e($pc['employee_nik'] ?? '-') . '</span></p>';
    } else {
        echo '<p><span class="badge danger">Belum Sinkron ke Asset Item</span> <span class="badge">NIK ' . e($pc['employee_nik'] ?? '-') . '</span></p>';
    }
    echo '</div><div class="actions"><a class="btn primary" href="' . route_url('ticket_form', ['pc_id' => $pcId]) . '">+ Buat Tiket / Reparasi</a><a class="btn" href="' . route_url('pc_form', ['pc_id' => $pcId]) . '">Edit PC</a><a class="btn" href="' . route_url('pc_location', ['pc_id' => $pcId]) . '">Set Lokasi GPS</a><a class="btn good" href="' . route_url('download_agent', ['pc_id' => $pcId]) . '">Download PcNalisa PC ini</a><a class="btn" href="' . route_url('upload_analysis', ['pc_id' => $pcId]) . '">Upload JSON Analisa</a>';
    if ($hasSync && $assetCode !== '') {
        echo '<a class="btn" href="' . mobile_asset_url($assetCode) . '">Mobile QR URL</a>';
    }
    echo '</div></div></section>';
    echo pc_asset_link_detail_html($pdo, $pc);

    // Corrective Tickets & Repairs Panel
    if (db_table_exists($pdo, 'corrective_tickets')) {
        $tStmt = $pdo->prepare("SELECT t.*, 
            (SELECT COUNT(*) FROM corrective_repairs cr WHERE cr.ticket_id = t.id) AS repair_count,
            (SELECT COALESCE(SUM(cr.repair_cost), 0) FROM corrective_repairs cr WHERE cr.ticket_id = t.id) AS total_repair_cost,
            (SELECT COALESCE(SUM(crp.part_cost), 0) FROM corrective_repairs cr JOIN corrective_repair_parts crp ON crp.repair_id = cr.id WHERE cr.ticket_id = t.id) AS total_part_cost
            FROM corrective_tickets t
            WHERE t.pc_id = ? OR (t.asset_item_id IS NOT NULL AND t.asset_item_id = ?)
            ORDER BY t.created_at DESC");
        $tStmt->execute([$pcId, (int)($pc['asset_item_id'] ?? 0)]);
        $pcTickets = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<section class="panel"><div class="split"><h2>Riwayat Tiket & Reparasi (Corrective Maintenance)</h2><a class="btn primary" href="' . route_url('ticket_form', ['pc_id' => $pcId]) . '">+ Laporkan Kerusakan PC Ini</a></div>';
        if (!$pcTickets) {
            echo '<p class="muted">Belum ada tiket atau perbaikan yang tercatat untuk PC ini.</p>';
        } else {
            echo '<table><thead><tr><th>Tiket & Tanggal</th><th>Prioritas</th><th>Subjek & Keluhan</th><th>Pelapor</th><th>Status</th><th>Total Biaya</th><th>Aksi</th></tr></thead><tbody>';
            foreach ($pcTickets as $pt) {
                $cost = (float)$pt['total_repair_cost'] + (float)$pt['total_part_cost'];
                echo '<tr>';
                echo '<td><strong>' . e($pt['ticket_code']) . '</strong><br><span class="muted">' . e(date('d M Y H:i', strtotime($pt['created_at']))) . '</span></td>';
                echo '<td>' . ticket_priority_badge($pt['priority']) . '</td>';
                echo '<td><strong>' . e($pt['subject']) . '</strong><br><span class="muted">' . e(mb_strimwidth((string)$pt['description'], 0, 60, '...')) . '</span></td>';
                echo '<td>' . e($pt['reporter_name']) . '</td>';
                echo '<td>' . ticket_status_badge($pt['status']) . '</td>';
                echo '<td>' . ($cost > 0 ? '<strong>Rp ' . number_format($cost, 0, ',', '.') . '</strong>' : '<span class="muted">Rp 0</span>') . '</td>';
                echo '<td><a class="btn" href="' . route_url('ticket_detail', ['id' => $pt['id']]) . '">Lihat / Handle</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    if (!empty($pc['latitude']) && !empty($pc['longitude'])) {
        $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string)$pc['latitude'] . ',' . (string)$pc['longitude']);
        echo '<section class="panel"><h2>Lokasi PC</h2><table><tr><th>Nama Lokasi</th><td>' . e($pc['location_label'] ?: '-') . '</td></tr><tr><th>GPS</th><td>' . e($pc['latitude'] . ', ' . $pc['longitude']) . '</td></tr><tr><th>Radius Scan</th><td>' . e($pc['location_radius_m'] ?: 5) . ' meter</td></tr></table><p><a class="btn" target="_blank" rel="noopener" href="' . e($mapUrl) . '">Buka di Google Maps</a></p></section>';
    }
    echo '<section class="analysis-grid">';
    detail_block('Spesifikasi Umum', $pc['general_specs'] ?? '');
    detail_block('Software', $pc['software'] ?? '');
    detail_block('Device Management', $pc['device_management'] ?? '');
    detail_block('Benchmark', $pc['benchmark'] ?? '');
    detail_block('Startup Analisa', $pc['startup_analysis'] ?? '');
    echo '<div class="panel wide-panel"><h2>Rekomendasi AI Analitik</h2><p>' . e($pc['ai_recommendation'] ?: 'Belum ada hasil analisa PcNalisa.') . '</p></div>';
    echo '</section>';
    render_footer();
}

function handle_route_pc_location(PDO $pdo): void
{
    $user = require_role(['admin']);
    $pcId = (string)($_GET['pc_id'] ?? $_POST['pc_id'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id = ?');
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch();
    if (!$pc) {
        http_response_code(404);
        exit('PC tidak ditemukan.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lat = normalize_decimal_input((string)($_POST['latitude'] ?? ''));
        $lng = normalize_decimal_input((string)($_POST['longitude'] ?? ''));
        $radius = max(1, (int)(($_POST['location_radius_m'] ?? '') !== '' ? $_POST['location_radius_m'] : 5));
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            flash('Latitude/longitude tidak valid.', 'err');
            redirect_to('pc_location', ['pc_id' => $pcId]);
        }
        $pdo->prepare('UPDATE pcs SET location_label=?, latitude=?, longitude=?, location_radius_m=? WHERE pc_id=?')->execute([
            trim((string)($_POST['location_label'] ?? '')),
            $lat,
            $lng,
            $radius,
            $pcId,
        ]);
        sync_pc_maintenance_asset($pdo, $pcId);
        flash('Lokasi GPS PC berhasil disimpan.');
        redirect_to('pc_detail', ['pc_id' => $pcId]);
    }
    render_header('Set Lokasi ' . $pcId, $user);
    $mapUrl = (!empty($pc['latitude']) && !empty($pc['longitude'])) ? 'https://www.google.com/maps?q=' . rawurlencode((string)$pc['latitude'] . ',' . (string)$pc['longitude']) : 'https://www.google.com/maps';
    echo '<section class="panel"><div class="split"><div><h1>Set Lokasi GPS ' . e($pcId) . '</h1><p class="muted">Buka halaman ini dari ponsel saat berdiri di dekat PC. Ambil GPS ponsel lalu simpan sebagai titik validasi scan QR.</p></div><a class="btn" href="' . route_url('pc_detail', ['pc_id' => $pcId]) . '">Kembali</a></div></section>';
    echo '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="pc_id" value="' . e($pcId) . '"><label>Nama / Titik Lokasi<input name="location_label" value="' . e($pc['location_label'] ?? '') . '" placeholder="Contoh: Lantai 2 - Meja Finance"></label><div class="grid three"><label>Latitude<input id="pcLatitude" name="latitude" value="' . e($pc['latitude'] ?? '') . '" required placeholder="-6.2000000"></label><label>Longitude<input id="pcLongitude" name="longitude" value="' . e($pc['longitude'] ?? '') . '" required placeholder="106.8166660"></label><label>Radius Meter<input name="location_radius_m" type="number" min="1" max="100" value="' . e($pc['location_radius_m'] ?? 5) . '"></label></div><div class="actions"><button class="btn primary" type="button" id="useCurrentLocation">Ambil GPS Ponsel Ini</button><a class="btn" target="_blank" rel="noopener" href="' . e($mapUrl) . '">Buka Google Maps</a><button class="btn good">Simpan Lokasi</button></div><p id="locationStatus" class="muted"></p></form></section>';
    echo '<script>(function(){var btn=document.getElementById("useCurrentLocation"),lat=document.getElementById("pcLatitude"),lng=document.getElementById("pcLongitude"),status=document.getElementById("locationStatus");btn.addEventListener("click",function(){if(!navigator.geolocation){status.textContent="Browser tidak mendukung GPS.";return;}status.textContent="Mengambil GPS ponsel...";navigator.geolocation.getCurrentPosition(function(p){lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);status.textContent="GPS ponsel terbaca. Akurasi sekitar "+Math.round(p.coords.accuracy)+" meter. Klik Simpan Lokasi.";},function(){status.textContent="Gagal mengambil GPS.";},{enableHighAccuracy:true,timeout:20000,maximumAge:0});});})();</script>';
    render_footer();
}

function handle_route_pc_locations(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('Kelola Lokasi PC', $user);
    $rows = $pdo->query('SELECT pc_id, owner_name, computer_name, location_label, latitude, longitude, location_radius_m FROM pcs ORDER BY pc_id')->fetchAll();
    echo '<section class="panel"><div class="split"><h1>Kelola Lokasi GPS PC</h1><a class="btn" href="' . route_url('pcs') . '">Kembali ke PC</a></div></section>';
    echo '<section class="panel"><table><tr><th>PcID</th><th>Pengguna</th><th>Computer Name</th><th>Nama Lokasi</th><th>GPS</th><th>Radius</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $gps = (!empty($row['latitude']) && !empty($row['longitude'])) ? e($row['latitude'] . ', ' . $row['longitude']) : '<span class="muted">Belum diset</span>';
        echo '<tr><td><strong>' . e($row['pc_id']) . '</strong></td><td>' . e($row['owner_name']) . '</td><td>' . e($row['computer_name'] ?: '-') . '</td><td>' . e($row['location_label'] ?: '-') . '</td><td>' . $gps . '</td><td>' . e($row['location_radius_m'] ?: 5) . ' m</td><td><a class="btn" href="' . route_url('pc_location', ['pc_id' => $row['pc_id']]) . '">Set GPS</a></td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_pc_asset_sync(PDO $pdo): void
{
    require_role(['admin']);
    $pcId = strtoupper(trim((string)($_GET['pc_id'] ?? $_POST['pc_id'] ?? '')));
    if ($pcId === '') {
        flash('PcID tidak valid.', 'err');
        redirect_to('pcs');
    }
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id = ?');
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch();
    if (!$pc) {
        flash('PC tidak ditemukan.', 'err');
        redirect_to('pcs');
    }

    $assetItemId = (int)($_POST['asset_item_id'] ?? $pc['asset_item_id'] ?? 0);
    if ($assetItemId > 0) {
        $chk = $pdo->prepare("SELECT asset_code, asset_mode FROM asset_items WHERE id=?");
        $chk->execute([$assetItemId]);
        $chkRow = $chk->fetch();
        if ($chkRow && ($chkRow['asset_mode'] ?? '') === 'child') {
            flash("Asset item '{$chkRow['asset_code']}' berstatus 'Bundle (Child Asset)' dan tidak dapat disinkronkan ke PC.", 'err');
            redirect_to('pc_form', ['pc_id' => $pcId]);
        }
        $otherPcStmt = $pdo->prepare("SELECT pc_id FROM pcs WHERE asset_item_id = ? AND pc_id <> ? LIMIT 1");
        $otherPcStmt->execute([$assetItemId, $pcId]);
        $otherPc = $otherPcStmt->fetchColumn();
        if ($otherPc) {
            flash("Asset item terpilih sudah tertaut ke PC {$otherPc}. Silakan pilih aset lain atau biarkan kosong untuk membuat aset baru.", 'err');
            redirect_to('pc_form', ['pc_id' => $pcId]);
        }
        $pdo->prepare('UPDATE pcs SET asset_item_id = ? WHERE pc_id = ?')->execute([$assetItemId, $pcId]);
        sync_pc_maintenance_asset($pdo, $pcId);
        flash("PC {$pcId} berhasil disinkronkan ke Asset Item terpilih.");
    } else {
        try {
            ensure_asset_master_schema($pdo);
            $compName = strtoupper(trim((string)($pc['computer_name'] ?: $pcId)));
            $isNotebook = (str_contains($compName, 'NOTEBOOK') || str_contains($compName, 'LAPTOP') || str_contains($compName, 'THINKP') || str_starts_with($compName, 'NB'));
            $prefTypeCode = $isNotebook ? 'NBK' : 'CMP';

            $typeStmt = $pdo->prepare("SELECT id FROM asset_types WHERE type_code = ? AND is_active = 1 LIMIT 1");
            $typeStmt->execute([$prefTypeCode]);
            $typeId = (int)($typeStmt->fetchColumn() ?: 0);
            if ($typeId <= 0) {
                $typeStmt = $pdo->query("SELECT id FROM asset_types WHERE type_code IN ('CMP', 'NBK') AND is_active = 1 ORDER BY id ASC LIMIT 1");
                $typeId = (int)($typeStmt->fetchColumn() ?: 0);
            }
            if ($typeId > 0) {
                $c = asset_type_form_config($pdo, $typeId);
                $statusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'ACTIVE' OR is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
                $typeCodeActual = (string)($c['type']['type_code'] ?? $prefTypeCode);
                $code = generate_next_asset_code($pdo, (string)($c['type']['group_code'] ?? 'IT'), $typeCodeActual);
                $assetName = trim((string)($pc['computer_name'] ?: $pcId));
                $owner = trim((string)($pc['owner_name'] ?? ''));
                $nik = trim((string)($pc['employee_nik'] ?? ''));
                $loc = trim((string)($pc['location_label'] ?? ''));
                $companyId = (int)($pc['company_id'] ?? 0) > 0 ? (int)$pc['company_id'] : null;

                $pdo->prepare("INSERT INTO asset_items (asset_code, asset_group_id, asset_type_id, asset_status_id, asset_mode, asset_name, custodian_name, custodian_nik, company_id, location_label, asset_type, asset_category, status) VALUES (?, ?, ?, ?, 'standalone', ?, ?, ?, ?, ?, ?, 'Computer', 'active')")
                    ->execute([
                        $code,
                        (int)($c['type']['asset_group_id'] ?? 1),
                        $typeId,
                        $statusId ?: 1,
                        $assetName,
                        $owner ?: null,
                        $nik ?: null,
                        $companyId,
                        $loc ?: null,
                        $c['type']['type_name'] ?? ($isNotebook ? 'Notebook' : 'Desktop Computer')
                    ]);
                $newItemId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE pcs SET asset_item_id = ? WHERE pc_id = ?')->execute([$newItemId, $pcId]);
                sync_pc_maintenance_asset($pdo, $pcId);
                flash("Asset Item baru ({$code}) berhasil dibuat dan disinkronkan ke PC {$pcId}.");
            } else {
                flash('Tipe asset komputer belum tersedia di master asset.', 'err');
            }
        } catch (Throwable $e) {
            flash('Gagal membuat Asset Item: ' . $e->getMessage(), 'err');
        }
    }
    redirect_to('pc_detail', ['pc_id' => $pcId]);
}

function handle_route_download_agent(): void
{
    require_role(['admin']);
    $pdo = Database::pdo();
    $pcId = trim($_GET['pc_id'] ?? '');
    if ($pcId === '') {
        http_response_code(400);
        exit('PcID tidak valid.');
    }
    $stmt = $pdo->prepare('SELECT pc_id, owner_name FROM pcs WHERE pc_id = ?');
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch();
    if (!$pc) {
        http_response_code(404);
        exit('PC tidak ditemukan.');
    }
    $agentPath = dirname(__DIR__, 2) . '/tools/PcNalisa-Agent.ps1';
    if (!is_readable($agentPath)) {
        http_response_code(500);
        exit('Template PcNalisa-Agent.ps1 tidak ditemukan.');
    }
    $source = file_get_contents($agentPath);
    if ($source === false) {
        http_response_code(500);
        exit('Template PcNalisa-Agent.ps1 gagal dibaca.');
    }
    $configured = str_replace(
        ['__PCCONNECT_PC_ID__', '__PCCONNECT_OWNER__', '__PCCONNECT_SERVER_URL__', '__PCCONNECT_TOKEN__'],
        [
            str_replace('"', '`"', (string)$pc['pc_id']),
            str_replace('"', '`"', (string)$pc['owner_name']),
            str_replace('"', '`"', absolute_route_url('api_ingest')),
            str_replace('"', '`"', (string)config_value('agent_token')),
        ],
        $source
    );
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="PcNalisa-' . $pc['pc_id'] . '.ps1"');
    echo $configured;
    exit;
}

function handle_route_upload_analysis(PDO $pdo): void
{
    $user = require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['analysis_json']['tmp_name'])) {
        $raw = file_get_contents($_FILES['analysis_json']['tmp_name']);
        if ($raw === false) {
            flash('Gagal membaca berkas JSON.', 'err');
            redirect_to('upload_analysis');
        }
        [$payload, $err] = decode_json_payload($raw);
        if (!is_array($payload) || empty($payload['pc_id'])) {
            flash('Format JSON tidak valid atau pc_id kosong: ' . $err, 'err');
            redirect_to('upload_analysis');
        }
        $result = ingest_analysis_payload($pdo, $payload);
        flash('Data analisa PC ' . $result['pc_id'] . ' berhasil diunggah.');
        redirect_to('pc_detail', ['pc_id' => $result['pc_id']]);
    }
    render_header('Upload JSON Analisa', $user);
    echo '<section class="panel"><h1>Upload JSON Analisa Manual</h1><p class="muted">Gunakan form ini jika workstation tidak terhubung langsung ke jaringan server dan hasil analisa dijalankan offline.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf_token() . '"><label>Pilih file JSON PcNalisa<input type="file" name="analysis_json" accept=".json" required></label><button class="btn primary">Upload dan Proses</button></form></section>';
    render_footer();
}
