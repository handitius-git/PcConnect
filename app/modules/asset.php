<?php

declare(strict_types=1);

function asset_nav_html(): string
{
    return '';
}

function asset_company_form_html(?array $company): string
{
    $company = $company ?: ['id' => 0, 'company_code' => '', 'company_name' => '', 'legal_name' => '', 'address' => '', 'is_active' => 1];
    return '<section class="panel"><h1>' . ((int)$company['id'] > 0 ? 'Edit Company' : 'Tambah Company') . '</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . e($company['id']) . '"><div class="grid two"><label>Company Code<input name="company_code" value="' . e($company['company_code']) . '" required></label><label>Company Name<input name="company_name" value="' . e($company['company_name']) . '" required></label></div><label>Legal Name<input name="legal_name" value="' . e($company['legal_name']) . '"></label><label>Address<textarea name="address">' . e($company['address']) . '</textarea></label><label><input style="width:auto" type="checkbox" name="is_active" value="1" ' . ((int)$company['is_active'] ? 'checked' : '') . '> Aktif</label><button class="btn primary">Simpan Company</button></form></section>';
}

function asset_category_form_html(?array $category): string
{
    $category = $category ?: ['id' => 0, 'category_name' => '', 'is_system' => 0, 'is_active' => 1];
    $isSystem = (int)($category['is_system'] ?? 0) === 1;
    $title = (int)$category['id'] > 0 ? 'Edit Kategori' : 'Tambah Kategori';
    $hint = $isSystem ? '<p class="muted">Kategori sistem (Computer, Printer, Kendaraan, Lain-lain) tidak bisa dihapus, hanya bisa diaktifkan/nonaktifkan.</p>' : '<p class="muted">Kategori baru akan otomatis tersedia di form Asset Item.</p>';
    return '<section class="panel"><h1>' . e($title) . '</h1>' . $hint . '<form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . e($category['id']) . '"><input type="hidden" name="action" value="save"><div class="grid two"><label>Nama Kategori<input name="category_name" value="' . e($category['category_name']) . '" required maxlength="60" placeholder="Contoh: Kendaraan, Alat Berat, Furniture"></label><label><input style="width:auto" type="checkbox" name="is_active" value="1" ' . ((int)$category['is_active'] ? 'checked' : '') . '> Aktif (tampil di pilihan)</label></div><div class="actions"><button class="btn primary">Simpan Kategori</button><a class="btn" href="' . route_url('asset_categories') . '">Batal</a></div></form></section>';
}

function cleanup_asset_management_data(PDO $pdo): void
{
    if (db_table_exists($pdo, 'maintenance_schedules') && db_column_exists($pdo, 'maintenance_schedules', 'maintenance_asset_id')) {
        $pdo->exec('UPDATE maintenance_schedules SET maintenance_asset_id=NULL');
    }
    if (db_table_exists($pdo, 'pcs')) {
        if (db_column_exists($pdo, 'pcs', 'asset_item_id')) { $pdo->exec('UPDATE pcs SET asset_item_id=NULL'); }
        if (db_column_exists($pdo, 'pcs', 'asset_bundle_id')) { $pdo->exec('UPDATE pcs SET asset_bundle_id=NULL'); }
        if (db_column_exists($pdo, 'pcs', 'maintenance_asset_id')) { $pdo->exec('UPDATE pcs SET maintenance_asset_id=NULL'); }
    }
    if (db_table_exists($pdo, 'printers')) {
        if (db_column_exists($pdo, 'printers', 'asset_item_id')) { $pdo->exec('UPDATE printers SET asset_item_id=NULL'); }
        if (db_column_exists($pdo, 'printers', 'maintenance_asset_id')) { $pdo->exec('UPDATE printers SET maintenance_asset_id=NULL'); }
    }
    $tables = [
        'asset_repair_parts',
        'asset_repairs',
        'asset_movements',
        'asset_bundle_members',
        'asset_bundles',
        'asset_item_members',
        'maintenance_asset_items',
        'maintenance_assets',
        'asset_items',
        'asset_companies',
    ];
    foreach ($tables as $table) {
        if (db_table_exists($pdo, $table)) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    }
}

function asset_items_bulk_maintenance_labels(PDO $pdo, array $assetItemIds): array
{
    $assetItemIds = array_filter(array_map('intval', $assetItemIds));
    if (!$assetItemIds || !db_table_exists($pdo, 'maintenance_assets') || !db_table_exists($pdo, 'maintenance_asset_items')) {
        return [];
    }
    $inIds = implode(',', $assetItemIds);
    $labelsByItem = [];
    foreach ($assetItemIds as $id) {
        $labelsByItem[$id] = [];
    }

    try {
        $stmt = $pdo->query("SELECT mai.asset_item_id, ma.maintenance_asset_code, ma.name, ma.pc_id 
                             FROM maintenance_asset_items mai 
                             JOIN maintenance_assets ma ON ma.id = mai.maintenance_asset_id 
                             WHERE mai.asset_item_id IN ($inIds) AND mai.detached_at IS NULL 
                             ORDER BY ma.maintenance_asset_code");
        while ($row = $stmt->fetch()) {
            $itemId = (int)$row['asset_item_id'];
            $suffix = !empty($row['pc_id']) ? ' (PC ' . $row['pc_id'] . ')' : '';
            $labelsByItem[$itemId][$row['maintenance_asset_code']] = $row['maintenance_asset_code'] . ' - ' . $row['name'] . $suffix;
        }

        if (db_column_exists($pdo, 'pcs', 'asset_item_id') && db_column_exists($pdo, 'pcs', 'maintenance_asset_id')) {
            $stmt = $pdo->query("SELECT p.asset_item_id, ma.maintenance_asset_code, ma.name, p.pc_id 
                                 FROM pcs p 
                                 JOIN maintenance_assets ma ON ma.id = p.maintenance_asset_id 
                                 WHERE p.asset_item_id IN ($inIds) 
                                 ORDER BY ma.maintenance_asset_code");
            while ($row = $stmt->fetch()) {
                $itemId = (int)$row['asset_item_id'];
                $labelsByItem[$itemId][$row['maintenance_asset_code']] = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (PC ' . $row['pc_id'] . ')';
            }
        }

        if (db_table_exists($pdo, 'printers') && db_column_exists($pdo, 'printers', 'asset_item_id') && db_column_exists($pdo, 'printers', 'maintenance_asset_id')) {
            $stmt = $pdo->query("SELECT pr.asset_item_id, ma.maintenance_asset_code, ma.name, pr.prn_id 
                                 FROM printers pr 
                                 JOIN maintenance_assets ma ON ma.id = pr.maintenance_asset_id 
                                 WHERE pr.asset_item_id IN ($inIds) 
                                 ORDER BY ma.maintenance_asset_code");
            while ($row = $stmt->fetch()) {
                $itemId = (int)$row['asset_item_id'];
                $labelsByItem[$itemId][$row['maintenance_asset_code']] = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (Printer ' . $row['prn_id'] . ')';
            }
        }
    } catch (Throwable $ignored) {
    }

    $result = [];
    foreach ($labelsByItem as $itemId => $labels) {
        $result[$itemId] = array_values($labels);
    }
    return $result;
}

function asset_items_table(PDO $pdo, array $rows): string
{
    if (!$rows) {
        return '<p>Belum ada asset item.</p>';
    }
    $itemIds = array_column($rows, 'id');
    $bulkLabels = asset_items_bulk_maintenance_labels($pdo, $itemIds);

    // Eager-load parent & child relationships for all items in the current page
    $childrenByParent = [];
    $parentByChild = [];
    if ($itemIds && db_table_exists($pdo, 'asset_item_members')) {
        $inIds = implode(',', array_map('intval', $itemIds));
        try {
            $stmtChildren = $pdo->query("SELECT aim.parent_asset_item_id, c.id, c.asset_code, c.asset_name, c.asset_type, aim.role_name 
                                         FROM asset_item_members aim 
                                         JOIN asset_items c ON c.id = aim.child_asset_item_id 
                                         WHERE aim.parent_asset_item_id IN ($inIds) AND aim.detached_at IS NULL 
                                         ORDER BY aim.attached_at ASC, aim.id ASC");
            while ($r = $stmtChildren->fetch()) {
                $pId = (int)$r['parent_asset_item_id'];
                $childrenByParent[$pId][] = $r;
            }

            $stmtParents = $pdo->query("SELECT aim.child_asset_item_id, p.id, p.asset_code, p.asset_name, p.asset_type, aim.role_name 
                                        FROM asset_item_members aim 
                                        JOIN asset_items p ON p.id = aim.parent_asset_item_id 
                                        WHERE aim.child_asset_item_id IN ($inIds) AND aim.detached_at IS NULL");
            while ($r = $stmtParents->fetch()) {
                $cId = (int)$r['child_asset_item_id'];
                $parentByChild[$cId] = $r;
            }
        } catch (Throwable $ignored) {
        }
    }

    $identifiersByItem = [];
    if ($itemIds && db_table_exists($pdo, 'asset_identifiers')) {
        $inIds = implode(',', array_map('intval', $itemIds));
        try {
            $sIdf = $pdo->query("SELECT ai.asset_item_id, ati.identifier_name, ai.identifier_value 
                                 FROM asset_identifiers ai 
                                 JOIN asset_type_identifiers ati ON ati.id = ai.asset_type_identifier_id 
                                 WHERE ai.asset_item_id IN ($inIds) AND ai.identifier_value <> ''");
            while ($rIdf = $sIdf->fetch(PDO::FETCH_ASSOC)) {
                $identifiersByItem[(int)$rIdf['asset_item_id']][] = $rIdf['identifier_name'] . ': ' . $rIdf['identifier_value'];
            }
        } catch (Throwable $ignored) {
        }
    }

    $specsByItem = [];
    if ($itemIds && db_table_exists($pdo, 'asset_specifications')) {
        $inIds = implode(',', array_map('intval', $itemIds));
        try {
            $sSpec = $pdo->query("SELECT asp.asset_item_id, ats.specification_name, asp.specification_value 
                                 FROM asset_specifications asp 
                                 JOIN asset_type_specifications ats ON ats.id = asp.asset_type_specification_id 
                                 WHERE asp.asset_item_id IN ($inIds) AND asp.specification_value <> ''");
            while ($rSpec = $sSpec->fetch(PDO::FETCH_ASSOC)) {
                $specsByItem[(int)$rSpec['asset_item_id']][] = $rSpec['specification_name'] . ': ' . $rSpec['specification_value'];
            }
        } catch (Throwable $ignored) {
        }
    }

    $html = '<table><tr><th>Asset Code</th><th>Maintenance Asset ID</th><th>Company</th><th>Pengguna / Custodian</th><th>Category</th><th>Mode Asset</th><th>Type</th><th>Nama / Merek</th><th>Nilai</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $rawMode = (string)($row['asset_mode'] ?? 'standalone');
        $itemId = (int)$row['id'];
        if ($rawMode === 'group') {
            $childList = $childrenByParent[$itemId] ?? [];
            $mode = '<span class="badge ok">Bundle (Parent Asset)</span>';
            if ($childList) {
                $childDetails = [];
                foreach ($childList as $ch) {
                    $role = !empty($ch['role_name']) ? ' (' . e($ch['role_name']) . ')' : '';
                    $childDetails[] = '• <strong>' . e($ch['asset_code']) . '</strong>' . $role;
                }
                $mode .= '<div style="font-size:11px;color:#475569;margin-top:4px;line-height:1.4;">' . implode('<br>', $childDetails) . '</div>';
            } else {
                $mode .= '<div style="font-size:11px;color:#94a3b8;margin-top:4px;">0 Child Asset</div>';
            }
        } elseif ($rawMode === 'child') {
            $parentInfo = $parentByChild[$itemId] ?? null;
            $mode = '<span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a">Bundle (Child Asset)</span>';
            if ($parentInfo) {
                $role = !empty($parentInfo['role_name']) ? ' (' . e($parentInfo['role_name']) . ')' : '';
                $mode .= '<div style="font-size:11px;color:#475569;margin-top:4px;">Parent: <strong>' . e($parentInfo['asset_code']) . '</strong>' . $role . '</div>';
            }
        } else {
            $mode = '<span class="badge">Single</span>';
        }

        $maintenanceLabels = $bulkLabels[(int)$row['id']] ?? [];
        $maintenanceText = $maintenanceLabels ? implode("\n", $maintenanceLabels) : '-';
        $custodianText = !empty($row['custodian_name']) ? ('<strong>' . e($row['custodian_name']) . '</strong>' . (!empty($row['custodian_nik']) ? ('<br><span class="muted">NIK: ' . e($row['custodian_nik']) . '</span>') : '')) : '<span class="muted">-</span>';
        $locationText = !empty($row['location_label']) ? '<br><span class="muted" style="font-size:11px">📍 ' . e($row['location_label']) . '</span>' : '';

        $idfs = $identifiersByItem[$itemId] ?? [];
        $idfHtml = '';
        if ($idfs) {
            $idfHtml = '<div style="font-size:11px;color:#0284c7;margin-top:4px;line-height:1.3;">' . implode('<br>', array_map('e', array_slice($idfs, 0, 3))) . (count($idfs) > 3 ? '<br><span class="muted">+ ' . (count($idfs) - 3) . ' identifier lainnya</span>' : '') . '</div>';
        }

        $specs = $specsByItem[$itemId] ?? [];
        $specHtml = '';
        if ($specs) {
            $specHtml = '<div style="font-size:11px;color:#475569;margin-top:4px;line-height:1.3;">' . implode(' • ', array_map('e', array_slice($specs, 0, 3))) . (count($specs) > 3 ? '<br><span class="muted">+ ' . (count($specs) - 3) . ' spesifikasi lainnya</span>' : '') . '</div>';
        }

        $html .= '<tr><td><strong>' . e($row['asset_code']) . '</strong><br><span class="muted">SN: ' . e($row['serial_number'] ?: '-') . '</span>' . $idfHtml . '</td><td>' . nl2br(e($maintenanceText)) . '</td><td>' . e($row['company_name'] ?: '-') . $locationText . '</td><td>' . $custodianText . '</td><td>' . e($row['asset_category'] ?? '-') . '</td><td>' . $mode . '</td><td>' . e($row['asset_type']) . '</td><td>' . e($row['asset_name']) . '<br><span class="muted">' . e(trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? ''))) . '</span>' . $specHtml . '</td><td>Awal: Rp ' . e(number_format((float)$row['purchase_value'], 0, ',', '.')) . '<br>Current: Rp ' . e(number_format((float)$row['current_value'], 0, ',', '.')) . '</td><td><span class="badge">' . e($row['status']) . '</span></td><td><a class="btn" href="' . route_url('asset_item_form', ['id' => $row['id']]) . '">Buka</a> <a class="btn" href="' . route_url('asset_repair_form', ['asset_item_id' => $row['id']]) . '">Repair</a></td></tr>';
    }
    return $html . '</table>';
}

function asset_master_item_defaults(): array
{
    return [
        'id' => 0,
        'master_item_id' => 0,
        'brand_id' => 0,
        'location_id' => 0,
        'asset_code' => '',
        'asset_group_id' => 0,
        'asset_type_id' => 0,
        'asset_status_id' => 0,
        'asset_name' => '',
        'brand' => '',
        'model' => '',
        'custodian_name' => '',
        'custodian_nik' => '',
        'notes' => '',
        'asset_mode' => 'standalone',
        'company_id' => 0,
        'purchase_value' => 0,
        'current_value' => 0,
        'warranty_until' => '',
        'installed_at' => '',
        'location_label' => '',
    ];
}

function asset_master_item_form_html(PDO $pdo, array $i, bool $editing): string
{
    $types = asset_type_options($pdo, (int)$i['asset_type_id'], (int)$i['asset_group_id']);
    $config = asset_type_form_config($pdo, (int)$i['asset_type_id'], (int)$i['asset_group_id']);
    $existing = [];
    $existingSpecs = [];
    $masterItemSpecs = [];
    $masterItemText = '';
    if (!empty($i['master_item_id'])) {
        $miStmt = $pdo->prepare("SELECT ami.*, ab.brand_name FROM asset_master_items ami LEFT JOIN asset_brands ab ON ab.id = ami.brand_id WHERE ami.id = ?");
        $miStmt->execute([(int)$i['master_item_id']]);
        $mi = $miStmt->fetch(PDO::FETCH_ASSOC);
        if ($mi) {
            $bStr = !empty($mi['brand_name']) ? ' [' . $mi['brand_name'] . ']' : '';
            $masterItemText = $mi['item_code'] . ' - ' . $mi['item_name'] . $bStr;
            $masterItemSpecs = json_decode((string)($mi['specifications'] ?? ''), true) ?: [];
        }
    }
    if ($editing) {
        $s = $pdo->prepare('SELECT asset_type_identifier_id, identifier_value FROM asset_identifiers WHERE asset_item_id=?');
        $s->execute([$i['id']]);
        foreach ($s as $r) {
            $existing[$r['asset_type_identifier_id']] = $r['identifier_value'];
        }
        if (db_table_exists($pdo, 'asset_specifications')) {
            $sSp = $pdo->prepare('SELECT asset_type_specification_id, specification_value FROM asset_specifications WHERE asset_item_id=?');
            $sSp->execute([$i['id']]);
            foreach ($sSp as $r) {
                $existingSpecs[$r['asset_type_specification_id']] = $r['specification_value'];
            }
        }
    } else {
        if (!empty($masterItemSpecs)) {
            $existingSpecs = $masterItemSpecs;
        }
    }
    $fields = '';
    if (!empty($config['identifiers'])) {
        foreach ($config['identifiers'] as $x) {
            $inputType = $x['data_type'] === 'date' ? 'date' : ($x['data_type'] === 'number' ? 'number' : 'text');
            $fields .= '<label>' . e($x['identifier_name']) . ($x['is_required'] ? ' *' : '') . '<input type="' . $inputType . '" name="identifiers[' . $x['id'] . ']" value="' . e($existing[$x['id']] ?? '') . '" ' . ($x['is_required'] ? 'required' : '') . ' placeholder="Masukkan ' . e($x['identifier_name']) . '"></label>';
        }
    } else {
        $fields = '<div class="muted" style="grid-column:1/-1;padding:8px 0;font-size:13px;">ℹ️ Pilih Komoditas dan Kategori di atas untuk memuat kolom identifier yang terdaftar.</div>';
    }

    $specFields = '';
    if (!empty($config['specifications'])) {
        foreach ($config['specifications'] as $sp) {
            $inputType = $sp['data_type'] === 'date' ? 'date' : ($sp['data_type'] === 'number' ? 'number' : 'text');
            $specFields .= '<label>' . e($sp['specification_name']) . ($sp['is_required'] ? ' *' : '') . '<input type="' . $inputType . '" name="specifications[' . $sp['id'] . ']" value="' . e($existingSpecs[$sp['id']] ?? '') . '" ' . ($sp['is_required'] ? 'required' : '') . ' placeholder="Masukkan ' . e($sp['specification_name']) . '"></label>';
        }
    } else {
        $specFields = '<div class="muted" style="grid-column:1/-1;padding:8px 0;font-size:13px;">ℹ️ Pilih Komoditas dan Kategori di atas untuk memuat kolom spesifikasi yang terdaftar.</div>';
    }
    $code = $i['asset_code'] ?: ($config['type']['next_asset_code'] ?? '');
    if ($code === '') {
        if (!empty($i['asset_group_id'])) {
            $gCode = (string)$pdo->query("SELECT group_code FROM asset_groups WHERE id = " . (int)$i['asset_group_id'])->fetchColumn();
            $code = $gCode ? ($gCode . '-[Pilih Kategori]-...') : 'Pilih Komoditas dan Kategori Aset';
        } else {
            $code = 'Pilih Komoditas dan Kategori Aset';
        }
    }
    $rawItemMode = (string)($i['asset_mode'] ?? 'standalone');
    $modeOptions = '<option value="standalone"' . ($rawItemMode === 'standalone' ? ' selected' : '') . '>Single (Default)</option><option value="group"' . ($rawItemMode === 'group' ? ' selected' : '') . '>Bundle (Parent Aset)</option>';
    if ($rawItemMode === 'child') {
        $modeOptions .= '<option value="child" selected>Bundle (Child Aset)</option>';
    }
    $modeHint = $rawItemMode === 'child'
        ? 'Aset ini merupakan Bundle (Child Aset) dari Parent Aset. Untuk mengubah ke Single, lepaskan dari Parent Aset di menu Anggota Bundle Aset.'
        : ($rawItemMode === 'group' ? 'Parent Bundle dapat berisi CPU, monitor, UPS, dll. Anggota dikelola di panel Anggota di bawah form.' : 'Single = aset mandiri.');

    $brandOptions = asset_brand_options($pdo, (int)($i['brand_id'] ?? 0), true, '-- Pilih Brand / Merk --');
    $locationOptions = asset_location_options($pdo, (int)($i['location_id'] ?? 0), true, '-- Pilih Lokasi Unit Aset --');


    if (function_exists('repair_asset_type_groups')) {
        repair_asset_type_groups($pdo);
    }

    $allTypesStmt = $pdo->query('SELECT t.id, t.asset_group_id, t.type_code, t.type_name, COALESCE(g.group_code, "") AS group_code 
                                 FROM asset_types t 
                                 LEFT JOIN asset_groups g ON g.id = t.asset_group_id 
                                 WHERE (t.is_active=1 OR t.is_active IS NULL) 
                                 ORDER BY t.type_name');
    $allTypesData = $allTypesStmt ? $allTypesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $jsonAllTypes = json_encode($allTypesData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

    $searchPlaceholder = 'Ketik nama atau model barang untuk mencari...';

    $linkedPcInfo = null;
    if ($editing && !empty($i['id']) && db_table_exists($pdo, 'pcs')) {
        $lpStmt = $pdo->prepare("SELECT pc_id, computer_name, owner_name FROM pcs WHERE asset_item_id = ? LIMIT 1");
        $lpStmt->execute([(int)$i['id']]);
        $linkedPcInfo = $lpStmt->fetch(PDO::FETCH_ASSOC);
    }

    $specLogs = [];
    if ($editing && !empty($i['id']) && db_table_exists($pdo, 'asset_specification_logs')) {
        $slStmt = $pdo->prepare("SELECT * FROM asset_specification_logs WHERE asset_item_id = ? ORDER BY changed_at DESC LIMIT 50");
        $slStmt->execute([(int)$i['id']]);
        $specLogs = $slStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $headerBadge = '';
    if ($linkedPcInfo) {
        $headerBadge = '<div style="margin-top:6px;"><span class="badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;padding:4px 10px;border-radius:6px;font-size:13px;font-weight:600;">🖥️ Terhubung PC ID: <span id="linkedPcBadgeText">' . e($linkedPcInfo['pc_id']) . ' (' . e($linkedPcInfo['computer_name']) . ')</span></span></div>';
    } else {
        $headerBadge = '<div id="linkedPcBadgeContainer" style="display:none;margin-top:6px;"><span class="badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;padding:4px 10px;border-radius:6px;font-size:13px;font-weight:600;">🖥️ Terhubung PC ID: <span id="linkedPcBadgeText"></span></span></div>';
    }

    $logHtml = '';
    if ($editing && !empty($specLogs)) {
        $logHtml .= '<div style="margin-top:16px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;">';
        $logHtml .= '<h3 style="margin-top:0;margin-bottom:10px;font-size:14px;color:#1e293b;display:flex;align-items:center;gap:6px;">🕒 Riwayat Upgrade / Perubahan Spesifikasi Unit</h3>';
        $logHtml .= '<div style="overflow-x:auto;"><table style="width:100%;font-size:13px;border-collapse:collapse;">';
        $logHtml .= '<thead><tr style="background:#f1f5f9;text-align:left;"><th style="padding:6px 10px;">Waktu</th><th style="padding:6px 10px;">Spesifikasi</th><th style="padding:6px 10px;">Nilai Lama</th><th style="padding:6px 10px;">Nilai Baru</th><th style="padding:6px 10px;">Teknisi</th><th style="padding:6px 10px;">Catatan</th></tr></thead><tbody>';
        foreach ($specLogs as $sl) {
            $logHtml .= '<tr style="border-bottom:1px solid #e2e8f0;">';
            $logHtml .= '<td style="padding:6px 10px;white-space:nowrap;color:#64748b;">' . e($sl['changed_at']) . '</td>';
            $logHtml .= '<td style="padding:6px 10px;font-weight:600;">' . e($sl['specification_name']) . '</td>';
            $logHtml .= '<td style="padding:6px 10px;color:#dc2626;"><del>' . e($sl['old_value'] ?: '-') . '</del></td>';
            $logHtml .= '<td style="padding:6px 10px;color:#16a34a;font-weight:600;">' . e($sl['new_value']) . '</td>';
            $logHtml .= '<td style="padding:6px 10px;">' . e($sl['technician_name'] ?: '-') . '</td>';
            $logHtml .= '<td style="padding:6px 10px;color:#64748b;">' . e($sl['notes'] ?: '-') . '</td>';
            $logHtml .= '</tr>';
        }
        $logHtml .= '</tbody></table></div></div>';
    }

    $pcModalHtml = '
<div id="pcImportModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(2px);z-index:999999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:860px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;">📥</span>
                <div>
                    <h3 style="margin:0;font-size:16px;font-weight:700;color:#1e293b;">Import dari Data PC</h3>
                    <small style="color:#64748b;">Pilih PC untuk mengisi otomatis Pengguna (Master Pengguna), Computer Name, dan Telemetri Spesifikasi</small>
                </div>
            </div>
            <button type="button" onclick="closeImportPcModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;line-height:1;">✕</button>
        </div>
        <div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;background:#ffffff;">
            <div style="display:flex;gap:10px;">
                <input type="text" id="pcImportSearch" placeholder="Ketik PC ID, Computer Name, Nama Pengguna, NIK..." style="flex:1;" oninput="debouncePcSearch(this.value)">
                <button type="button" class="btn" onclick="fetchPcList()">Cari</button>
            </div>
        </div>
        <div style="padding:0;overflow-y:auto;flex:1;" id="pcImportTableContainer">
            <div style="text-align:center;padding:40px;color:#64748b;">Ketik pencarian atau tunggu daftar PC dimuat...</div>
        </div>
        <div style="padding:12px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;">
            <button type="button" class="btn" onclick="closeImportPcModal()">Batal</button>
        </div>
    </div>
</div>';

    $pcModalHtml .= '
<div id="pcSpecDiffModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(3px);z-index:9999999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:780px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3);overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#fef3c7;">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:24px;">⚠️</span>
                <div>
                    <h3 style="margin:0;font-size:16px;font-weight:700;color:#92400e;">Konfirmasi Perbedaan Spesifikasi PC vs Master Barang</h3>
                    <small style="color:#b45309;">Ditemukan perbedaan antara spesifikasi standar katalog dan telemetri fisik PC</small>
                </div>
            </div>
            <button type="button" onclick="closePcSpecDiffModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#92400e;line-height:1;">✕</button>
        </div>
        <div style="padding:16px 20px;overflow-y:auto;flex:1;">
            <p style="margin-top:0;font-size:13px;color:#475569;">
                Spesifikasi telemetri PC yang diimpor memiliki perbedaan dengan spesifikasi standar Master Barang yang dipilih. Silakan tentukan opsi untuk masing-masing spesifikasi:
            </p>
            <div id="pcSpecDiffTableContainer"></div>
            
            <div id="pcSpecDiffReasonSection" style="margin-top:16px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <label style="font-weight:700;font-size:13px;color:#1e293b;display:block;margin-bottom:8px;">Alasan Perubahan Spesifikasi:</label>
                <div style="display:flex;gap:16px;margin-bottom:10px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;margin:0;">
                        <input type="radio" name="specChangeReasonRadio" value="upgrade" checked onchange="onSpecChangeReasonChanged()">
                        <span><strong>Upgrade Fisik</strong> (Komponen/RAM/Storage telah ditambah/diganti)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;margin:0;">
                        <input type="radio" name="specChangeReasonRadio" value="correction" onchange="onSpecChangeReasonChanged()">
                        <span><strong>Koreksi Data</strong> (Salah input awal / bukan upgrade fisik)</span>
                    </label>
                </div>
                <div id="pcSpecUpgradeNoteBox">
                    <label style="font-size:12px;color:#475569;display:block;margin-bottom:4px;">Catatan Upgrade (Akan dicatat di Riwayat Audit Upgrade Spesifikasi):</label>
                    <input type="text" id="pcSpecUpgradeNoteInput" style="width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;" placeholder="Contoh: Upgrade kapasitas RAM / SSD saat serah terima PC">
                </div>
            </div>
        </div>
        <div style="padding:12px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="btn" onclick="closePcSpecDiffModal()">Batal</button>
            <button type="button" class="btn primary" onclick="applyPcSpecDifferences()">Terapkan Perubahan</button>
        </div>
    </div>
</div>';

    return '<section class="panel"><div class="split" style="align-items:center;margin-bottom:16px;"><div><h1 style="margin:0;">' . ($editing ? 'Edit' : 'Tambah') . ' Unit Aset</h1>' . $headerBadge . '</div><div><button type="button" class="btn warning" id="btnImportPc" onclick="openImportPcModal()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-weight:600;" title="Pilih Master Barang terlebih dahulu sebelum Import PC"><span style="font-size:16px;">📥</span> Import dari Data PC</button></div></div><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="linked_pc_id" id="linkedPcId" value="' . e($linkedPcInfo['pc_id'] ?? '') . '"><div id="upgradeHiddenInputs"></div><h2>1. Klasifikasi Aset & Master Barang</h2><div class="grid three"><label>Komoditas (Grup Aset) *<select id="assetGroup" name="asset_group_id" onchange="onGroupSelectChanged()" required>' . asset_group_options($pdo, (int)$i['asset_group_id'], true, '- Pilih Komoditas (Grup Aset) -') . '</select></label><label>Kategori (Tipe Aset) *<select id="assetType" name="asset_type_id" onchange="reloadAssetForm()" required>' . $types . '</select></label><label>ID Aset (Kode Unit)<input id="assetCode" name="asset_code" value="' . e($code) . '" readonly></label></div><div class="grid two"><div style="position:relative;"><label for="masterItemSearch" style="display:flex;justify-content:space-between;align-items:center;"><span>Pilih / Cari Master Barang <span style="color:#ef4444;" title="Wajib dipilih">*</span></span><a href="' . route_url('asset_master_items') . '" target="_blank" style="font-size:12px;color:#0284c7;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:3px;" title="Buka form Master Barang di tab baru">➕ Tambah Master Barang Baru ↗</a></label><div style="display:flex;gap:6px;align-items:stretch;"><div style="position:relative;flex:1;"><input type="text" id="masterItemSearch" placeholder="' . e($searchPlaceholder) . '" autocomplete="off" value="' . e($masterItemText) . '" style="width:100%;box-sizing:border-box;padding-right:58px;background:#fff;cursor:text;" onfocus="onMasterItemInput(this.value)" onclick="onMasterItemInput(this.value)" oninput="onMasterItemInput(this.value)" onkeydown="onMasterItemKeyDown(event)"><button type="button" id="masterItemClearBtn" style="display:' . (!empty($i['master_item_id']) ? 'block' : 'none') . ';position:absolute;right:28px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;padding:4px 8px;z-index:2;" onclick="clearMasterItem()" title="Hapus pilihan">✕</button><span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;color:#94a3b8;font-size:12px;">▾</span><input type="hidden" id="masterItemIdInput" name="master_item_id" value="' . (int)($i['master_item_id'] ?? 0) . '"><div id="masterItemResults" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);max-height:280px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 12px 30px rgba(0,0,0,0.18);z-index:99999;"></div></div><a href="' . route_url('asset_master_items') . '" target="_blank" class="btn" style="padding:0 14px;background:#0284c7;color:#fff;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:bold;text-decoration:none;white-space:nowrap;" title="Tambah Master Barang Baru (Buka di Tab Baru)">➕</a></div><small id="masterItemHint" style="display:block;color:#64748b;margin-top:4px">' . (!empty($i['master_item_id']) ? '<span style="color:#166534;font-weight:600;">✓ Master Barang Terpilih (Siap Import dari PC)</span>' : 'Pilih Master Barang terlebih dahulu sebelum Import dari Data PC. Ketik nama untuk mencari.') . '</small></div><label>Mode Aset<select name="asset_mode" id="assetModeSelect">' . $modeOptions . '</select><small id="assetModeHint" style="display:block;color:#64748b;margin-top:4px">' . e($modeHint) . '</small></label></div><h2>2. Identifikasi & Serial Number Unit</h2><div id="identifierFields" class="grid three">' . $fields . '</div><h2>3. Spesifikasi Teknis Unit</h2><div id="specificationFields" class="grid three">' . $specFields . '</div>' . $logHtml . '<h2>4. Detail Barang & Merek</h2><div class="grid three"><label>Brand / Merk<select id="assetBrandId" name="brand_id">' . $brandOptions . '</select></label><label>Nama Brand (Teks)<input id="assetBrandInput" name="brand" value="' . e($i['brand']) . '" placeholder="Auto terisi dari master merk"></label><label>Model / Varian<input id="assetModelInput" name="model" value="' . e($i['model']) . '" placeholder="Contoh: ThinkPad T480 / OptiPlex 3080"></label></div><div class="grid two"><label>Nama Unit Aset (Deskriptif)<input id="assetNameInput" name="asset_name" value="' . e($i['asset_name']) . '" placeholder="Contoh: Laptop ThinkPad T480 IT"></label><label>Keterangan Tambahan<textarea name="notes" style="min-height:42px">' . e($i['notes']) . '</textarea></label></div><h2>5. Lokasi & Tanggung Jawab (Custodian)</h2><div class="grid three"><label>Lokasi Unit Aset *<select name="location_id" id="assetLocationId" required>' . $locationOptions . '</select></label><label>Detail Ruangan / Gedung<input name="location_label" id="assetLocationLabel" value="' . e($i['location_label']) . '" placeholder="Gedung / Lantai / Ruangan"></label><label>Company<select name="company_id">' . company_options($pdo, (int)$i['company_id']) . '</select></label></div><div class="grid two"><label>NIK Pengguna / Custodian<input id="assetCustodianNik" name="custodian_nik" value="' . e($i['custodian_nik'] ?? '') . '" placeholder="NIK karyawan"></label><label>Nama Pengguna / Custodian<input id="assetCustodianName" name="custodian_name" value="' . e($i['custodian_name'] ?? '') . '" placeholder="Nama pemakai / PIC aset"></label></div>' . (function_exists('employee_portal_name_picker_html') ? employee_portal_name_picker_html('assetCustodian', 'assetCustodianName', 'assetCustodianNik') : '') . '<h2>6. Pembelian / Garansi / Status</h2><div class="grid four"><label>Tanggal Perolehan / Beli<input type="date" name="installed_at" value="' . e($i['installed_at']) . '"></label><label>Harga Perolehan (Rp)<input type="number" name="purchase_value" value="' . e($i['purchase_value']) . '"></label><label>Garansi Berakhir<input type="date" name="warranty_until" value="' . e($i['warranty_until']) . '"></label><label>Status Unit *<select name="asset_status_id" required>' . asset_status_options($pdo, (int)$i['asset_status_id']) . '</select></label></div><div class="actions"><a class="btn" href="' . route_url('asset_items') . '">Batal</a><button class="btn primary">Simpan Unit Aset</button></div></form>' . $pcModalHtml . '</section><script>
(function(){
    var sel = document.getElementById("assetModeSelect");
    var hint = document.getElementById("assetModeHint");
    if(sel && hint){
        function upd(){
            if(sel.value === "group"){
                hint.textContent = "Setelah Simpan Aset, panel Anggota Bundle Aset akan muncul di bawah form.";
            } else if(sel.value === "child"){
                hint.textContent = "Aset ini merupakan Bundle (Child Aset) dari Parent Aset.";
            } else {
                hint.textContent = "Single = aset mandiri. Bundle = parent yang bisa berisi CPU, monitor, UPS, dll.";
            }
        }
        sel.addEventListener("change", upd);
    }
})();

window._currentMasterItemSpecs = ' . json_encode($masterItemSpecs ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';
window.allAssetTypes = ' . $jsonAllTypes . ';


window.reloadAssetForm = async function(){
    var g = document.getElementById("assetGroup");
    var t = document.getElementById("assetType");
    var c = document.getElementById("assetCode");
    var idf = document.getElementById("identifierFields");
    var spf = document.getElementById("specificationFields");
    if(!t) return;
    var tid = parseInt(t.value, 10) || 0;
    var gid = g ? (parseInt(g.value, 10) || 0) : 0;
    var groupCode = "";
    if(g && g.selectedOptions && g.selectedOptions[0] && g.selectedOptions[0].value){
        groupCode = g.selectedOptions[0].textContent.split(" - ")[0].trim();
    }
    if(gid <= 0 || tid <= 0){
        if(idf){
            idf.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;padding:8px 0;font-size:13px;\">ℹ️ Pilih Komoditas dan Kategori di atas untuk memuat kolom identifier yang terdaftar.</div>";
        }
        if(spf){
            spf.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;padding:8px 0;font-size:13px;\">ℹ️ Pilih Komoditas dan Kategori di atas untuk memuat kolom spesifikasi yang terdaftar.</div>";
        }
        if(c){
            c.value = groupCode ? (groupCode + "-[Pilih Kategori]-...") : "Pilih Komoditas dan Kategori Aset";
        }
        return;
    }
    try {
        var r = await fetch("index.php?route=api_asset_type_config&type_id=" + tid + "&group_id=" + gid);
        var all = await r.json();
        if(c && all.type && all.type.next_asset_code) c.value = all.type.next_asset_code;
        if(idf){
            if(Array.isArray(all.identifiers) && all.identifiers.length > 0){
                idf.innerHTML = all.identifiers.map(function(x){
                    var dt = x.data_type === "date" ? "date" : (x.data_type === "number" ? "number" : "text");
                    var req = x.is_required == 1 ? " required" : "";
                    var star = x.is_required == 1 ? " *" : "";
                    return "<label>" + (x.identifier_name || "") + star + "<input type=\"" + dt + "\" name=\"identifiers[" + x.id + "]\"" + req + " placeholder=\"Masukkan " + (x.identifier_name || "") + "\"></label>";
                }).join("");
            } else {
                idf.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;padding:8px 0;font-size:13px;\">ℹ️ Tidak ada identifier khusus untuk kategori ini. Daftarkan di menu <strong>Setup &rarr; Master &rarr; Identifier Aset</strong> jika diperlukan.</div>";
            }
        }
        if(spf){
            if(Array.isArray(all.specifications) && all.specifications.length > 0){
                spf.innerHTML = all.specifications.map(function(sp){
                    var dt = sp.data_type === "date" ? "date" : (sp.data_type === "number" ? "number" : "text");
                    var req = sp.is_required == 1 ? " required" : "";
                    var star = sp.is_required == 1 ? " *" : "";
                    return "<label>" + (sp.specification_name || "") + star + "<input type=\"" + dt + "\" name=\"specifications[" + sp.id + "]\"" + req + " placeholder=\"Masukkan " + (sp.specification_name || "") + "\"></label>";
                }).join("");
            } else {
                spf.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;padding:8px 0;font-size:13px;\">ℹ️ Tidak ada spesifikasi khusus untuk kategori ini. Daftarkan di menu <strong>Setup &rarr; Master &rarr; Spesifikasi Aset</strong> jika diperlukan.</div>";
            }
        }
    } catch(e){
        console.error("reloadAssetForm error:", e);
    }
};

window.onGroupSelectChanged = async function(preserveSelectedType){
    var g = document.getElementById("assetGroup");
    var t = document.getElementById("assetType");
    var c = document.getElementById("assetCode");
    if(!g || !t) return;
    var gid = parseInt(g.value, 10) || 0;
    var opt = g.selectedOptions ? g.selectedOptions[0] : null;
    var groupText = opt ? opt.textContent.trim() : "";
    var groupCode = (groupText && gid > 0) ? groupText.split(" - ")[0].trim() : "";

    if(gid <= 0){
        t.innerHTML = "<option value=\"\">- Pilih Komoditas Terlebih Dahulu -</option>";
        if(c) c.value = "Pilih Komoditas dan Kategori Aset";
        window.reloadAssetForm();
        return;
    }

    if(c && groupCode){
        c.value = groupCode + "-[Pilih Kategori]-...";
    }

    var prevVal = preserveSelectedType ? t.value : "";
    var filtered = [];
    if(Array.isArray(window.allAssetTypes)){
        filtered = window.allAssetTypes.filter(function(x){
            var xGid = parseInt(x.asset_group_id, 10) || 0;
            if(xGid === gid) return true;
            if(groupCode && x.group_code && x.group_code.toUpperCase() === groupCode.toUpperCase()) return true;
            return false;
        });
    }

    function renderTypeOptions(list){
        var opts = "<option value=\"\">- Pilih Kategori (Tipe) -</option>";
        if(Array.isArray(list) && list.length > 0){
            list.forEach(function(x){
                var sel = (prevVal && String(x.id) === String(prevVal)) ? " selected" : "";
                opts += "<option value=\"" + x.id + "\"" + sel + ">" + (x.type_code || "") + " - " + (x.type_name || "") + "</option>";
            });
        } else {
            opts = "<option value=\"\">- Belum ada kategori untuk komoditas ini -</option>";
        }
        t.innerHTML = opts;
    }

    if(filtered.length > 0){
        renderTypeOptions(filtered);
    } else {
        t.innerHTML = "<option value=\"\">Memuat Kategori...</option>";
        try {
            var res = await fetch("index.php?route=api_asset_types&group_id=" + gid);
            var r = await res.json();
            if(Array.isArray(r) && r.length > 0){
                renderTypeOptions(r);
                if(Array.isArray(window.allAssetTypes)){
                    r.forEach(function(item){
                        if(!window.allAssetTypes.some(function(it){ return it.id == item.id; })){
                            window.allAssetTypes.push(item);
                        }
                    });
                }
            } else {
                renderTypeOptions([]);
            }
        } catch(e){
            console.error("Fetch categories error:", e);
            renderTypeOptions([]);
        }
    }
    window.reloadAssetForm();
};

var agEl = document.getElementById("assetGroup");
if(agEl){
    agEl.addEventListener("change", function(){ window.onGroupSelectChanged(); });
}
var atEl = document.getElementById("assetType");
if(atEl){
    atEl.addEventListener("change", function(){ window.reloadAssetForm(); });
}
if(agEl && parseInt(agEl.value, 10) > 0 && atEl && atEl.options.length <= 1){
    window.onGroupSelectChanged(true);
}

function escapeHtml(str){
    return String(str || "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

function highlightMatch(text, q){
    if(!text) return "";
    var safeText = escapeHtml(text);
    if(!q) return safeText;
    var cleanQ = escapeHtml(q).trim();
    if(!cleanQ) return safeText;
    var idx = safeText.toLowerCase().indexOf(cleanQ.toLowerCase());
    if(idx === -1) return safeText;
    var before = safeText.substring(0, idx);
    var matched = safeText.substring(idx, idx + cleanQ.length);
    var after = safeText.substring(idx + cleanQ.length);
    return before + "<mark style=\"background:#fef08a;color:#854d0e;padding:0 2px;border-radius:2px;font-weight:700;\">" + matched + "</mark>" + after;
}

window._lastMasterItems = [];
window._activeMasterItemIndex = 0;

window.updateActiveMasterItem = function(newIdx){
    var sResults = document.getElementById("masterItemResults");
    if(!sResults) return;
    var items = sResults.querySelectorAll(".mi-item");
    if(!items.length) return;
    if(newIdx < 0) newIdx = 0;
    if(newIdx >= items.length) newIdx = items.length - 1;
    window._activeMasterItemIndex = newIdx;
    items.forEach(function(el, idx){
        if(idx === window._activeMasterItemIndex){
            el.style.backgroundColor = "#e0f2fe";
            el.style.borderLeft = "4px solid #0284c7";
            el.scrollIntoView({block: "nearest"});
        } else {
            el.style.backgroundColor = "#fff";
            el.style.borderLeft = "4px solid transparent";
        }
    });
};

window.onMasterItemKeyDown = function(e){
    var sResults = document.getElementById("masterItemResults");
    if(!sResults || sResults.style.display === "none") return;
    if(!window._lastMasterItems || !window._lastMasterItems.length) return;

    if(e.key === "ArrowDown"){
        e.preventDefault();
        window.updateActiveMasterItem((window._activeMasterItemIndex || 0) + 1);
    } else if(e.key === "ArrowUp"){
        e.preventDefault();
        window.updateActiveMasterItem((window._activeMasterItemIndex || 0) - 1);
    } else if(e.key === "Enter" || e.key === "Tab"){
        if(window._activeMasterItemIndex >= 0 && window._activeMasterItemIndex < window._lastMasterItems.length){
            e.preventDefault();
            window.selectMasterItemByIndex(window._activeMasterItemIndex);
        }
    } else if(e.key === "Escape"){
        sResults.style.display = "none";
    }
};

window.onMasterItemInput = async function(q){
    var sResults = document.getElementById("masterItemResults");
    if(!sResults) return;
    q = (q || "").trim();
    if(!q){
        sResults.style.display = "none";
        return;
    }
    try {
        var url = "index.php?route=api_master_items&q=" + encodeURIComponent(q);
        var r = await fetch(url);
        var items = await r.json();
        var addBtnHtml = "<div style=\"padding:10px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;\"><a href=\"index.php?route=asset_master_items\" target=\"_blank\" style=\"color:#0284c7;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;\">➕ Tambah Master Barang Baru di Tab Baru ↗</a></div>";
        if(!Array.isArray(items) || items.length === 0){
            sResults.innerHTML = "<div style=\"padding:12px 14px;color:#94a3b8;font-size:13px;text-align:center;\">Tidak ditemukan barang yang cocok dengan \"<strong>" + escapeHtml(q) + "</strong>\".</div>" + addBtnHtml;
            sResults.style.display = "block";
            window._lastMasterItems = [];
            window._activeMasterItemIndex = -1;
            return;
        }
        var html = "";
        window._lastMasterItems = items;
        window._activeMasterItemIndex = 0;
        items.forEach(function(it, idx){
            var codeH = highlightMatch(it.item_code, q);
            var nameH = highlightMatch(it.item_name, q);
            var brandH = it.brand_name ? " <span class=\"badge ok\" style=\"font-size:11px;margin-left:4px;\">" + highlightMatch(it.brand_name, q) + "</span>" : "";
            var modelH = it.model_name ? " <span style=\"color:#64748b;font-size:12px;margin-left:4px;\">(" + highlightMatch(it.model_name, q) + ")</span>" : "";
            var bg = (idx === 0) ? "background-color:#e0f2fe;border-left:4px solid #0284c7;" : "background-color:#fff;border-left:4px solid transparent;";
            html += "<div class=\"mi-item\" id=\"mi-opt-" + idx + "\" onmouseover=\"updateActiveMasterItem(" + idx + ")\" onmousedown=\"selectMasterItemByIndex(" + idx + ")\" onclick=\"selectMasterItemByIndex(" + idx + ")\" style=\"padding:10px 14px;cursor:pointer;user-select:none;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;" + bg + "\"><div><strong>" + codeH + "</strong> - " + nameH + brandH + modelH + "</div><span style=\"font-size:11px;color:#0284c7;font-weight:600;\">Pilih ↵</span></div>";
        });
        html += addBtnHtml;
        sResults.innerHTML = html;
        sResults.style.display = "block";
    } catch(e) {
        sResults.style.display = "none";
    }
};

window.selectMasterItemByIndex = function(idx){
    if(!window._lastMasterItems || !window._lastMasterItems[idx]) return;
    window.selectMasterItemObj(window._lastMasterItems[idx]);
};

window.selectMasterItemObj = async function(it){
    if(!it) return;
    var sInput = document.getElementById("masterItemSearch");
    var sHidden = document.getElementById("masterItemIdInput");
    var sClearBtn = document.getElementById("masterItemClearBtn");
    var sResults = document.getElementById("masterItemResults");
    var sHint = document.getElementById("masterItemHint");
    var gEl = document.getElementById("assetGroup");
    var tEl = document.getElementById("assetType");

    if(sHidden) sHidden.value = it.id;
    if(sInput) sInput.value = it.item_code + " - " + it.item_name + (it.brand_name ? " [" + it.brand_name + "]" : "");
    if(sResults) sResults.style.display = "none";
    if(sClearBtn) sClearBtn.style.display = "block";
    if(sHint) sHint.innerHTML = "<span style=\"color:#166534;font-weight:600;\">✓ Master Barang Terpilih: " + escapeHtml(it.item_name) + " (Siap Import dari PC)</span>";

    if(it.asset_group_id && gEl){
        gEl.value = it.asset_group_id;
        await window.onGroupSelectChanged();
    }
    if(it.asset_type_id && tEl){
        tEl.value = it.asset_type_id;
        await window.reloadAssetForm();
    }
    if(it.brand_id){
        var bSel = document.getElementById("assetBrandId");
        if(bSel) bSel.value = it.brand_id;
    }
    if(it.brand_name){
        var bIn = document.getElementById("assetBrandInput");
        if(bIn) bIn.value = it.brand_name;
    }
    if(it.model_name){
        var mIn = document.getElementById("assetModelInput");
        if(mIn) mIn.value = it.model_name;
    }
    if(it.item_name){
        var nIn = document.getElementById("assetNameInput");
        if(nIn && (!nIn.value || nIn.value.includes("AST-") || nIn.value.includes("Pilih"))) {
            nIn.value = it.item_name;
        }
    }

    var specsObj = {};
    if(it.specifications){
        try {
            specsObj = typeof it.specifications === "string" ? JSON.parse(it.specifications) : it.specifications;
        } catch(e){
            specsObj = {};
        }
    }
    window._currentMasterItemSpecs = specsObj || {};
    if(specsObj && typeof specsObj === "object"){
        for(var spId in specsObj){
            var inps = document.getElementsByName("specifications[" + spId + "]");
            if(inps && inps.length > 0 && specsObj[spId] !== undefined && specsObj[spId] !== null){
                inps[0].value = specsObj[spId];
                inps[0].style.transition = "background-color 0.5s";
                inps[0].style.backgroundColor = "#e0f2fe";
                setTimeout(function(el){ return function(){ el.style.backgroundColor = ""; }; }(inps[0]), 1500);
            }
        }
    }
};

window.clearMasterItem = function(){
    var sInput = document.getElementById("masterItemSearch");
    var sHidden = document.getElementById("masterItemIdInput");
    var sClearBtn = document.getElementById("masterItemClearBtn");
    var sHint = document.getElementById("masterItemHint");
    var sResults = document.getElementById("masterItemResults");
    if(sHidden) sHidden.value = "";
    if(sInput) sInput.value = "";
    if(sClearBtn) sClearBtn.style.display = "none";
    if(sResults) sResults.style.display = "none";
    if(sHint) sHint.innerHTML = "<span style=\"color:#dc2626;font-weight:600;\">⚠️ Pilih Master Barang terlebih dahulu sebelum Import dari Data PC.</span>";
    window._currentMasterItemSpecs = {};
};

document.addEventListener("click", function(e){
    var sResults = document.getElementById("masterItemResults");
    if(!e.target.closest("#masterItemSearch") && !e.target.closest("#masterItemResults") && !e.target.closest("#masterItemClearBtn")){
        if(sResults) sResults.style.display = "none";
    }
});

var bSelect = document.getElementById("assetBrandId");
if(bSelect){
    bSelect.addEventListener("change", function(){
        var opt = bSelect.selectedOptions[0];
        if(opt && opt.value){
            var txt = opt.textContent.replace(/\s*\([^)]*\)$/, "").trim();
            var bIn = document.getElementById("assetBrandInput");
            if(bIn) bIn.value = txt;
        }
    });
}

window.openImportPcModal = function(){
    var mId = parseInt(document.getElementById("masterItemIdInput")?.value, 10) || 0;
    if(mId <= 0){
        alert("⚠️ Perhatian:\n\nMaster Barang harus dipilih terlebih dahulu sebelum bisa Import dari Data PC!\n\nSilakan pilih Master Barang pada formulir di bawah, atau klik tombol [➕] untuk membuat Master Barang baru di tab baru.");
        var sIn = document.getElementById("masterItemSearch");
        if(sIn) {
            sIn.focus();
            sIn.style.outline = "2px solid #ef4444";
            sIn.scrollIntoView({behavior: "smooth", block: "center"});
            setTimeout(function(){
                sIn.style.outline = "";
            }, 3500);
        }
        return;
    }
    var m = document.getElementById("pcImportModal");
    if(m){
        m.style.display = "flex";
        var sIn = document.getElementById("pcImportSearch");
        if(sIn) {
            sIn.focus();
            window.fetchPcList(sIn.value);
        } else {
            window.fetchPcList("");
        }
    }
};

window.closeImportPcModal = function(){
    var m = document.getElementById("pcImportModal");
    if(m) m.style.display = "none";
};

var _pcSearchTimer = null;
window.debouncePcSearch = function(val){
    if(_pcSearchTimer) clearTimeout(_pcSearchTimer);
    _pcSearchTimer = setTimeout(function(){
        window.fetchPcList(val);
    }, 300);
};

window.fetchPcList = async function(query){
    var container = document.getElementById("pcImportTableContainer");
    if(!container) return;
    if(typeof query === "undefined"){
        query = (document.getElementById("pcImportSearch")?.value || "").trim();
    }
    container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#64748b;\">⏳ Memuat daftar PC...</div>";
    try {
        var resp = await fetch("index.php?route=api_pc_import&q=" + encodeURIComponent(query));
        var res = await resp.json();
        if(!res.ok || !Array.isArray(res.data) || res.data.length === 0){
            container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#64748b;\">Tidak ditemukan PC dengan kata kunci \"" + (query || "") + "\".</div>";
            return;
        }
        var html = "<table style=\"width:100%;font-size:13px;border-collapse:collapse;\"><thead><tr style=\"background:#f8fafc;border-bottom:1px solid #e2e8f0;text-align:left;\"><th style=\"padding:10px 14px;\">PC ID</th><th style=\"padding:10px 14px;\">Nama Komputer</th><th style=\"padding:10px 14px;\">Pengguna (Master Pengguna)</th><th style=\"padding:10px 14px;\">Lokasi</th><th style=\"padding:10px 14px;text-align:center;\">Aksi</th></tr></thead><tbody>";
        res.data.forEach(function(pc){
            var cust = pc.directory_name ? (pc.directory_name + (pc.employee_nik ? " (" + pc.employee_nik + ")" : "")) : (pc.owner_name || "-");
            var linkedBadge = pc.asset_code ? ("<br><span style=\"color:#0284c7;font-size:11px;\">🔗 Terhubung: " + pc.asset_code + "</span>") : "";
            html += "<tr style=\"border-bottom:1px solid #f1f5f9;\"><td style=\"padding:10px 14px;font-weight:700;\">" + pc.pc_id + linkedBadge + "</td><td style=\"padding:10px 14px;\">" + (pc.computer_name || "-") + "</td><td style=\"padding:10px 14px;\">" + cust + "</td><td style=\"padding:10px 14px;\">" + (pc.location_label || "-") + "</td><td style=\"padding:10px 14px;text-align:center;\"><button type=\"button\" class=\"btn small primary\" data-pc=\"" + pc.pc_id + "\" onclick=\"selectPcForImport(this.dataset.pc)\">Pilih PC</button></td></tr>";
        });
        html += "</tbody></table>";
        container.innerHTML = html;
    } catch(err){
        container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#dc2626;\">Gagal memuat data PC: " + err.message + "</div>";
    }
};

window.selectPcForImport = async function(pcId){
    var mId = parseInt(document.getElementById("masterItemIdInput")?.value, 10) || 0;
    if(mId <= 0){
        alert("⚠️ Perhatian: Master Barang harus dipilih terlebih dahulu sebelum bisa Import dari Data PC!");
        window.closeImportPcModal();
        return;
    }
    var container = document.getElementById("pcImportTableContainer");
    if(container) container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#0284c7;\">⏳ Memuat rincian telemetri & spesifikasi " + pcId + "...</div>";
    try {
        var resp = await fetch("index.php?route=api_pc_import&pc_id=" + encodeURIComponent(pcId));
        var res = await resp.json();
        if(!res.ok){
            alert("Gagal mengambil data PC: " + (res.error || "Unknown error"));
            window.fetchPcList();
            return;
        }

        // Auto-select IT group and Computer category if not selected
        var gSel = document.getElementById("assetGroup");
        var tSel = document.getElementById("assetType");
        if(gSel && (!gSel.value || gSel.value === "0")){
            for(var i=0; i<gSel.options.length; i++){
                if(gSel.options[i].text.toUpperCase().indexOf("IT") !== -1){
                    gSel.selectedIndex = i;
                    await window.onGroupSelectChanged();
                    break;
                }
            }
        }
        if(tSel && (!tSel.value || tSel.value === "0")){
            for(var j=0; j<tSel.options.length; j++){
                var txt = tSel.options[j].text.toUpperCase();
                if(txt.indexOf("CMP") !== -1 || txt.indexOf("COMPUTER") !== -1 || txt.indexOf("KOMPUTER") !== -1){
                    tSel.selectedIndex = j;
                    await window.reloadAssetForm();
                    break;
                }
            }
        }

        // Set linked PC ID
        var hLinked = document.getElementById("linkedPcId");
        if(hLinked) hLinked.value = res.pc_id;

        // Update badge
        var badgeText = document.getElementById("linkedPcBadgeText");
        var badgeContainer = document.getElementById("linkedPcBadgeContainer");
        if(badgeText){
            badgeText.textContent = res.pc_id + " (" + (res.computer_name || "-") + ")";
            if(badgeContainer) badgeContainer.style.display = "block";
        }

        // Populate Custodian (Master Pengguna)
        var cNik = document.getElementById("assetCustodianNik");
        var cName = document.getElementById("assetCustodianName");
        if(cNik) cNik.value = res.custodian_nik || "";
        if(cName) cName.value = res.custodian_name || "";

        // Populate Location
        var locLabel = document.getElementById("assetLocationLabel");
        if(locLabel && !locLabel.value && res.location_label){
            locLabel.value = res.location_label;
        }

        // Populate Brand & Model
        var bIn = document.getElementById("assetBrandInput");
        var bSel = document.getElementById("assetBrandId");
        if(res.specs && res.specs.manufacturer){
            if(bIn) bIn.value = res.specs.manufacturer;
            if(bSel){
                for(var k=0; k<bSel.options.length; k++){
                    if(bSel.options[k].text.toUpperCase().indexOf(res.specs.manufacturer.toUpperCase()) !== -1){
                        bSel.selectedIndex = k;
                        break;
                    }
                }
            }
        }
        var mIn = document.getElementById("assetModelInput");
        if(mIn && res.specs && res.specs.model){
            mIn.value = res.specs.model;
        }

        // Populate Asset Name
        var nIn = document.getElementById("assetNameInput");
        if(nIn && (!nIn.value || nIn.value.indexOf("AST-") === 0 || nIn.value === "Pilih Komoditas dan Kategori Aset")){
            var combinedName = "";
            if(res.specs && res.specs.model){
                combinedName = (res.specs.manufacturer ? res.specs.manufacturer + " " : "") + res.specs.model;
            } else if(res.computer_name){
                combinedName = "PC " + res.computer_name;
            } else {
                combinedName = "PC " + res.pc_id;
            }
            nIn.value = combinedName;
        }

        // Auto-match Specification inputs in #specificationFields
        var specContainer = document.getElementById("specificationFields");
        var diffsList = [];
        if(specContainer && res.specs){
            var rawSpecInputs = specContainer.getElementsByTagName("input");
            for(var si = 0; si < rawSpecInputs.length; si++){
                var inp = rawSpecInputs[si];
                if(!inp.name || inp.name.indexOf("specifications[") !== 0) continue;
                var mId = inp.name.match(/specifications\[(\d+)\]/);
                var spId = mId ? mId[1] : "";
                var lbl = (inp.closest("label") ? inp.closest("label").textContent : "").replace(/\*/g, "").trim();
                var lblLower = lbl.toLowerCase();
                var val = "";
                if(lblLower.indexOf("proc") !== -1 || lblLower.indexOf("cpu") !== -1 || lblLower.indexOf("prosesor") !== -1){
                    val = res.specs.processor || "";
                } else if(lblLower.indexOf("ram") !== -1 || lblLower.indexOf("memory") !== -1 || lblLower.indexOf("memori") !== -1){
                    val = res.specs.ram || "";
                } else if(lblLower.indexOf("storage") !== -1 || lblLower.indexOf("penyimpanan") !== -1 || lblLower.indexOf("ssd") !== -1 || lblLower.indexOf("hdd") !== -1 || lblLower.indexOf("disk") !== -1){
                    val = res.specs.storage || "";
                } else if(lblLower.indexOf("os") !== -1 || lblLower.indexOf("sistem operasi") !== -1 || lblLower.indexOf("windows") !== -1){
                    val = res.specs.os || "";
                } else if(lblLower.indexOf("gpu") !== -1 || lblLower.indexOf("vga") !== -1 || lblLower.indexOf("grafis") !== -1 || lblLower.indexOf("graphics") !== -1){
                    val = res.specs.gpu || "";
                }

                if(!val) continue;

                var currentVal = (inp.value || "").trim();
                var masterVal = (window._currentMasterItemSpecs && window._currentMasterItemSpecs[spId] !== undefined) ? String(window._currentMasterItemSpecs[spId]).trim() : "";
                var baselineVal = currentVal || masterVal;

                if(baselineVal && val){
                    var normBase = baselineVal.replace(/\s+/g, " ").trim().toLowerCase();
                    var normVal = val.replace(/\s+/g, " ").trim().toLowerCase();
                    if(normBase !== normVal){
                        diffsList.push({
                            spId: spId,
                            specName: lbl || ("Spesifikasi #" + spId),
                            baselineVal: baselineVal,
                            pcVal: val,
                            inp: inp
                        });
                        continue;
                    }
                }

                inp.value = val;
                inp.style.transition = "background-color 0.5s";
                inp.style.backgroundColor = "#dcfce7";
                setTimeout(function(el){ return function(){ el.style.backgroundColor = ""; }; }(inp), 2500);
            }
        }

        // Auto-match Identifiers in #identifierFields (Hostname / Computer Name)
        var idContainer = document.getElementById("identifierFields");
        if(idContainer && res.computer_name){
            var rawIdInputs = idContainer.getElementsByTagName("input");
            for(var ii = 0; ii < rawIdInputs.length; ii++){
                var idInp = rawIdInputs[ii];
                if(!idInp.name || idInp.name.indexOf("identifiers[") !== 0) continue;
                var idLbl = (idInp.closest("label") ? idInp.closest("label").textContent : "").toLowerCase();
                if(idLbl.indexOf("hostname") !== -1 || idLbl.indexOf("computer") !== -1 || idLbl.indexOf("komputer") !== -1){
                    idInp.value = res.computer_name;
                    idInp.style.transition = "background-color 0.5s";
                    idInp.style.backgroundColor = "#dcfce7";
                    setTimeout(function(el){ return function(){ el.style.backgroundColor = ""; }; }(idInp), 2500);
                }
            }
        }

        if(diffsList.length > 0){
            window._pendingPcImport = {
                res: res,
                diffs: diffsList
            };

            var tableHtml = "<table style=\"width:100%;font-size:13px;border-collapse:collapse;margin-top:8px;\">";
            tableHtml += "<thead><tr style=\"background:#f1f5f9;text-align:left;\">";
            tableHtml += "<th style=\"padding:8px 10px;border-bottom:1px solid #cbd5e1;\">Spesifikasi</th>";
            tableHtml += "<th style=\"padding:8px 10px;border-bottom:1px solid #cbd5e1;\">Standar Master Barang</th>";
            tableHtml += "<th style=\"padding:8px 10px;border-bottom:1px solid #cbd5e1;\">Telemetri Fisik PC</th>";
            tableHtml += "<th style=\"padding:8px 10px;border-bottom:1px solid #cbd5e1;text-align:center;\">Pilihan Nilai</th>";
            tableHtml += "</tr></thead><tbody>";
            diffsList.forEach(function(d, idx){
                var shortPc = d.pcVal.length > 25 ? d.pcVal.substring(0, 22) + "..." : d.pcVal;
                var shortBase = d.baselineVal.length > 25 ? d.baselineVal.substring(0, 22) + "..." : d.baselineVal;
                tableHtml += "<tr style=\"border-bottom:1px solid #e2e8f0;\">";
                tableHtml += "<td style=\"padding:8px 10px;font-weight:600;\">" + escapeHtml(d.specName) + "</td>";
                tableHtml += "<td style=\"padding:8px 10px;color:#475569;\">" + escapeHtml(d.baselineVal) + "</td>";
                tableHtml += "<td style=\"padding:8px 10px;color:#0284c7;font-weight:600;\">" + escapeHtml(d.pcVal) + "</td>";
                tableHtml += "<td style=\"padding:8px 10px;text-align:center;\">";
                tableHtml += "<select class=\"pc-spec-diff-choice\" data-idx=\"" + idx + "\" style=\"font-size:12px;padding:4px 8px;border-radius:4px;border:1px solid #cbd5e1;\">";
                tableHtml += "<option value=\"pc\" selected>Gunakan Data PC (" + escapeHtml(shortPc) + ")</option>";
                tableHtml += "<option value=\"master\">Tetap Master Barang (" + escapeHtml(shortBase) + ")</option>";
                tableHtml += "</select>";
                tableHtml += "</td>";
                tableHtml += "</tr>";
            });
            tableHtml += "</tbody></table>";
            var containerDiff = document.getElementById("pcSpecDiffTableContainer");
            if(containerDiff) containerDiff.innerHTML = tableHtml;

            var upgradeNoteInp = document.getElementById("pcSpecUpgradeNoteInput");
            if(upgradeNoteInp){
                var diffNames = diffsList.map(function(d){ return d.specName; }).join(", ");
                upgradeNoteInp.value = "Upgrade spesifikasi (" + diffNames + ") dari telemetri PC " + res.pc_id;
            }

            window.closeImportPcModal();
            var diffModal = document.getElementById("pcSpecDiffModal");
            if(diffModal) diffModal.style.display = "flex";
            return;
        }

        window.closeImportPcModal();
        alert("✅ Berhasil mengimpor data PC: " + res.pc_id + " (" + (res.computer_name || "") + ")\nPengguna: " + (res.custodian_name || "-") + "\nSpesifikasi hardware telah diterapkan ke form unit aset.");
    } catch(err){
        alert("Terjadi kesalahan saat memproses data PC: " + err.message);
        window.fetchPcList();
    }
};

window.closePcSpecDiffModal = function(){
    var m = document.getElementById("pcSpecDiffModal");
    if(m) m.style.display = "none";
};

window.onSpecChangeReasonChanged = function(){
    var r = document.querySelector("input[name=\"specChangeReasonRadio\"]:checked");
    var reason = r ? r.value : "upgrade";
    var noteBox = document.getElementById("pcSpecUpgradeNoteBox");
    if(noteBox){
        noteBox.style.display = (reason === "upgrade") ? "block" : "none";
    }
};

window.applyPcSpecDifferences = function(){
    if(!window._pendingPcImport || !Array.isArray(window._pendingPcImport.diffs)) return;
    var res = window._pendingPcImport.res;
    var diffs = window._pendingPcImport.diffs;
    var choices = document.querySelectorAll(".pc-spec-diff-choice");
    var r = document.querySelector("input[name=\"specChangeReasonRadio\"]:checked");
    var reason = r ? r.value : "upgrade";
    var customNoteInp = document.getElementById("pcSpecUpgradeNoteInput");
    var customNote = customNoteInp ? customNoteInp.value.trim() : "";
    var hiddenDiv = document.getElementById("upgradeHiddenInputs");
    if(hiddenDiv) hiddenDiv.innerHTML = "";

    var anyPcChosen = false;
    choices.forEach(function(sel){
        var idx = parseInt(sel.dataset.idx, 10);
        var d = diffs[idx];
        if(!d) return;
        if(sel.value === "pc"){
            anyPcChosen = true;
            if(d.inp) {
                d.inp.value = d.pcVal;
                d.inp.style.transition = "background-color 0.5s";
                d.inp.style.backgroundColor = "#dcfce7";
                setTimeout(function(el){ return function(){ el.style.backgroundColor = ""; }; }(d.inp), 2500);
            }
            if(reason === "upgrade" && hiddenDiv){
                var hSp = document.createElement("input");
                hSp.type = "hidden";
                hSp.name = "upgrade_flag_" + d.spId;
                hSp.value = "1";
                hiddenDiv.appendChild(hSp);

                var hNote = document.createElement("input");
                hNote.type = "hidden";
                hNote.name = "upgrade_note_" + d.spId;
                hNote.value = customNote || ("Upgrade " + d.specName + " ke " + d.pcVal + " (PC " + res.pc_id + ")");
                hiddenDiv.appendChild(hNote);
            }
        } else {
            if(d.inp) {
                d.inp.value = d.baselineVal;
            }
        }
    });

    if(anyPcChosen && reason === "upgrade" && hiddenDiv){
        var hFlag = document.createElement("input");
        hFlag.type = "hidden";
        hFlag.name = "upgrade_flag";
        hFlag.value = "1";
        hiddenDiv.appendChild(hFlag);

        var hGlobalNote = document.createElement("input");
        hGlobalNote.type = "hidden";
        hGlobalNote.name = "upgrade_notes_custom";
        hGlobalNote.value = customNote || ("Upgrade spesifikasi fisik dari telemetri PC " + res.pc_id);
        hiddenDiv.appendChild(hGlobalNote);
    }

    window.closePcSpecDiffModal();
    var statusMsg = anyPcChosen
        ? (reason === "upgrade" 
            ? "Spesifikasi PC telah diterapkan dan ditandai sebagai UPGRADE FISIK (akan dicatat di riwayat audit spesifikasi)."
            : "Spesifikasi PC telah diterapkan sebagai KOREKSI DATA.")
        : "Spesifikasi standar Master Barang tetap dipertahankan.";
    alert("✅ Berhasil memproses data PC: " + res.pc_id + "\n\n" + statusMsg);
};

document.addEventListener("click", function(e){
    var m = document.getElementById("pcImportModal");
    if(m && e.target === m){
        window.closeImportPcModal();
    }
    var mDiff = document.getElementById("pcSpecDiffModal");
    if(mDiff && e.target === mDiff){
        window.closePcSpecDiffModal();
    }
});
</script>';
}

function generate_next_asset_code(PDO $pdo, string $groupCode, string $typeCode): string
{
    $groupCode = trim($groupCode);
    $typeCode = trim($typeCode);
    if ($groupCode === '' || $typeCode === '') {
        return 'AST-' . date('YmdHis');
    }
    $prefix = $groupCode . '-' . $typeCode . '-';

    $stmt = $pdo->prepare("SELECT asset_code FROM asset_items WHERE asset_code LIKE ?");
    $stmt->execute([$prefix . '%']);
    $maxNum = 0;
    while ($row = $stmt->fetch()) {
        $code = (string)$row['asset_code'];
        $suffix = substr($code, strlen($prefix));
        if (preg_match('/^(\d+)/', $suffix, $matches)) {
            $num = (int)$matches[1];
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }

    $nextNum = max(1, $maxNum + 1);
    while ($nextNum < $maxNum + 10000) {
        $candidate = $prefix . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT 1 FROM asset_items WHERE asset_code = ? LIMIT 1");
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
        $nextNum++;
    }

    return $prefix . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);
}

function save_asset_master_item(PDO $pdo, int $id, array $post): int
{
    $g = (int)($post['asset_group_id'] ?? 0);
    $t = (int)($post['asset_type_id'] ?? 0);
    $st = (int)($post['asset_status_id'] ?? 0);
    $masterItemId = (int)($post['master_item_id'] ?? 0) ?: null;
    $brandId = (int)($post['brand_id'] ?? 0) ?: null;
    $mode = in_array(($post['asset_mode'] ?? 'standalone'), ['standalone', 'group', 'child'], true) ? (string)$post['asset_mode'] : 'standalone';
    if ($id > 0 && active_parent_asset_item($pdo, $id)) {
        $mode = 'child';
    }
    if (!$g || !$t || !$st) {
        throw new RuntimeException('Komoditas, Kategori, dan Status wajib diisi.');
    }
    $c = asset_type_form_config($pdo, $t);
    if (!$c['type'] || (int)$c['type']['asset_group_id'] !== $g) {
        throw new RuntimeException('Kategori tidak sesuai Komoditas.');
    }
    foreach ($c['identifiers'] as $x) {
        $v = trim((string)($post['identifiers'][$x['id']] ?? ''));
        if ($x['is_unique'] && $v !== '') {
            $q = $pdo->prepare('SELECT ai.id FROM asset_identifiers ai WHERE ai.asset_type_identifier_id=? AND ai.identifier_value=? AND ai.asset_item_id<>?');
            $q->execute([$x['id'], $v, $id]);
            if ($q->fetch()) {
                throw new RuntimeException($x['identifier_name'] . ' sudah digunakan asset lain.');
            }
        }
    }
    if (!empty($c['specifications'])) {
        foreach ($c['specifications'] as $sp) {
            $v = trim((string)($post['specifications'][$sp['id']] ?? ''));
            if (!empty($sp['is_required']) && $v === '') {
                throw new RuntimeException('Spesifikasi ' . $sp['specification_name'] . ' wajib diisi.');
            }
        }
    }
    $expectedPrefix = (string)($c['type']['group_code'] ?? '') . '-' . (string)($c['type']['type_code'] ?? '') . '-';
    if ($id) {
        $q = $pdo->prepare('SELECT asset_code FROM asset_items WHERE id=?');
        $q->execute([$id]);
        $existingCode = (string)$q->fetchColumn();
        if ($expectedPrefix !== '-' && !str_starts_with($existingCode, $expectedPrefix)) {
            $code = generate_next_asset_code($pdo, (string)$c['type']['group_code'], (string)$c['type']['type_code']);
        } else {
            $code = $existingCode;
        }
    } else {
        $code = generate_next_asset_code($pdo, (string)$c['type']['group_code'], (string)$c['type']['type_code']);
    }
    $assetName = trim((string)($post['asset_name'] ?? ''));
    if ($assetName === '' || (isset($existingCode) && $assetName === $existingCode)) {
        $assetName = $code;
    }

    $brandName = trim((string)($post['brand'] ?? ''));
    if ($brandId && $brandName === '') {
        $brandName = (string)$pdo->query("SELECT brand_name FROM asset_brands WHERE id = {$brandId}")->fetchColumn() ?: '';
    }
    if (!$brandId && $brandName !== '') {
        $foundBId = (int)$pdo->query("SELECT id FROM asset_brands WHERE UPPER(brand_name) = UPPER(" . $pdo->quote($brandName) . ") LIMIT 1")->fetchColumn();
        if ($foundBId > 0) {
            $brandId = $foundBId;
        }
    }

    $locationId = (int)($post['location_id'] ?? 0) ?: null;
    $locationLabel = trim((string)($post['location_label'] ?? ''));
    if (!$locationId) {
        throw new RuntimeException('Lokasi Unit Aset wajib dipilih.');
    }
    if ($locationId && $locationLabel === '' && db_table_exists($pdo, 'asset_locations')) {
        $locName = (string)$pdo->query("SELECT location_name FROM asset_locations WHERE id = {$locationId}")->fetchColumn() ?: '';
        if ($locName !== '') {
            $locationLabel = $locName;
        }
    }

    $custodianName = trim((string)($post['custodian_name'] ?? ''));
    $custodianNik = trim((string)($post['custodian_nik'] ?? ''));
    $p = [
        $masterItemId,
        $brandId,
        $code,
        $g,
        $t,
        $st,
        $mode,
        $assetName,
        $brandName,
        trim((string)($post['model'] ?? '')),
        $custodianName,
        $custodianNik,
        trim((string)($post['notes'] ?? '')),
        (int)($post['company_id'] ?? 0) ?: null,
        $locationId,
        $locationLabel,
        trim((string)($post['installed_at'] ?? '')) ?: null,
        (float)($post['purchase_value'] ?? 0),
        trim((string)($post['warranty_until'] ?? '')) ?: null,
    ];
    $oldSpecs = [];
    if ($id > 0 && db_table_exists($pdo, 'asset_specifications')) {
        $sOld = $pdo->prepare('SELECT asset_type_specification_id, specification_value FROM asset_specifications WHERE asset_item_id=?');
        $sOld->execute([$id]);
        foreach ($sOld->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oldSpecs[(int)$r['asset_type_specification_id']] = (string)$r['specification_value'];
        }
    }

    if ($id) {
        $pdo->prepare('UPDATE asset_items SET master_item_id=?,brand_id=?,asset_code=?,asset_group_id=?,asset_type_id=?,asset_status_id=?,asset_mode=?,asset_name=?,brand=?,model=?,custodian_name=?,custodian_nik=?,notes=?,company_id=?,location_id=?,location_label=?,installed_at=?,purchase_value=?,warranty_until=?,asset_type=? WHERE id=?')->execute([...$p, $c['type']['type_name'], $id]);
    } else {
        $pdo->prepare("INSERT INTO asset_items(master_item_id,brand_id,asset_code,asset_group_id,asset_type_id,asset_status_id,asset_mode,asset_name,brand,model,custodian_name,custodian_nik,notes,company_id,location_id,location_label,installed_at,purchase_value,warranty_until,asset_type,asset_category,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Legacy','active')")->execute([...$p, $c['type']['type_name']]);
        $id = (int)$pdo->lastInsertId();
    }
    foreach ($c['identifiers'] as $x) {
        $v = trim((string)($post['identifiers'][$x['id']] ?? ''));
        $pdo->prepare('INSERT INTO asset_identifiers(asset_item_id,asset_type_identifier_id,identifier_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE identifier_value=VALUES(identifier_value)')->execute([$id, $x['id'], $v]);
        if ($v !== '' && (stripos((string)$x['identifier_code'], 'SERIAL') !== false || stripos((string)$x['identifier_code'], 'SN') !== false || stripos((string)$x['identifier_name'], 'Serial') !== false)) {
            $pdo->prepare('UPDATE asset_items SET serial_number=? WHERE id=?')->execute([$v, $id]);
        }
    }
    // Fetch Master Barang baseline specs if master_item_id is set
    $masterSpecs = [];
    $masterItemId = (int)($post['master_item_id'] ?? 0);
    if ($masterItemId > 0) {
        $miRow = $pdo->query("SELECT specifications FROM asset_master_items WHERE id = {$masterItemId}")->fetch(PDO::FETCH_ASSOC);
        if ($miRow && !empty($miRow['specifications'])) {
            $masterSpecs = json_decode((string)$miRow['specifications'], true) ?: [];
        }
    }

    if (!empty($c['specifications']) && db_table_exists($pdo, 'asset_specifications')) {
        foreach ($c['specifications'] as $sp) {
            $spId = (int)$sp['id'];
            $v = trim((string)($post['specifications'][$spId] ?? ''));
            $pdo->prepare('INSERT INTO asset_specifications(asset_item_id,asset_type_specification_id,specification_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE specification_value=VALUES(specification_value)')->execute([$id, $spId, $v]);

            if (db_table_exists($pdo, 'asset_specification_logs')) {
                $oldVal = $oldSpecs[$spId] ?? ($masterSpecs[$spId] ?? null);
                $isUpgradeFlagged = !empty($post['upgrade_flag_' . $spId]) || !empty($post['upgrade_flag']);
                $customUpgradeNote = trim((string)($post['upgrade_note_' . $spId] ?? ($post['upgrade_notes_custom'] ?? '')));

                if ($oldVal !== null && $oldVal !== '' && $v !== '' && $oldVal !== $v) {
                    $changeType = $isUpgradeFlagged ? 'upgrade' : 'update';
                    $notes = $customUpgradeNote !== '' ? $customUpgradeNote : ($isUpgradeFlagged ? 'Upgrade spesifikasi unit aset' : 'Perubahan spesifikasi unit aset dari form.');
                    if (!empty($post['linked_pc_id'])) {
                        $notes .= ' (PC: ' . trim((string)$post['linked_pc_id']) . ')';
                    }
                    $techName = $_SESSION['user']['username'] ?? 'admin';
                    $logStmt = $pdo->prepare('INSERT INTO asset_specification_logs (asset_item_id, asset_type_specification_id, specification_name, old_value, new_value, change_type, notes, technician_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $logStmt->execute([$id, $spId, $sp['specification_name'], $oldVal, $v, $changeType, $notes, $techName]);
                }
            }
        }
    }
    if (!empty($post['linked_pc_id']) && db_table_exists($pdo, 'pcs')) {
        $linkedPcId = trim((string)$post['linked_pc_id']);
        $pdo->prepare('UPDATE pcs SET asset_item_id = ? WHERE pc_id = ?')->execute([$id, $linkedPcId]);
        if (function_exists('sync_pc_maintenance_asset')) {
            sync_pc_maintenance_asset($pdo, $linkedPcId);
        }
    }
    if (db_table_exists($pdo, 'pcs') && function_exists('sync_pc_maintenance_asset')) {
        $stmtPc = $pdo->prepare('SELECT pc_id FROM pcs WHERE asset_item_id=?');
        $stmtPc->execute([$id]);
        foreach ($stmtPc->fetchAll() as $rPc) {
            sync_pc_maintenance_asset($pdo, (string)$rPc['pc_id']);
        }
    }
    if (db_table_exists($pdo, 'printers') && function_exists('sync_printer_maintenance_asset')) {
        $stmtPrn = $pdo->prepare('SELECT prn_id FROM printers WHERE asset_item_id=?');
        $stmtPrn->execute([$id]);
        foreach ($stmtPrn->fetchAll() as $rPrn) {
            sync_printer_maintenance_asset($pdo, (string)$rPrn['prn_id']);
        }
    }
    return $id;
}

function asset_child_item_options(PDO $pdo, int $parentId = 0): string
{
    $html = '<option value="">Pilih Unit Aset Anggota</option>';
    $stmt = $pdo->prepare('SELECT id, asset_code, asset_name, asset_type, asset_category, asset_mode FROM asset_items WHERE id <> ? ORDER BY asset_code');
    $stmt->execute([$parentId]);
    $boundChildIds = [];
    if ($parentId > 0) {
        $boundStmt = $pdo->prepare('SELECT child_asset_item_id FROM asset_item_members WHERE detached_at IS NULL AND parent_asset_item_id <> ?');
        $boundStmt->execute([$parentId]);
        $boundChildIds = array_map('intval', $boundStmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $boundStmt = $pdo->query('SELECT child_asset_item_id FROM asset_item_members WHERE detached_at IS NULL');
        $boundChildIds = array_map('intval', $boundStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $boundSet = array_flip($boundChildIds);
    foreach ($stmt as $row) {
        if (isset($boundSet[(int)$row['id']])) {
            continue;
        }
        $rawMode = (string)($row['asset_mode'] ?? 'standalone');
        $mode = $rawMode === 'group' ? 'Bundle (Parent)' : ($rawMode === 'child' ? 'Bundle (Child)' : 'Single');
        $html .= '<option value="' . e($row['id']) . '">' . e($row['asset_code'] . ' - ' . $row['asset_name'] . ' (' . ($row['asset_category'] ?? '-') . ' / ' . $row['asset_type'] . ' / ' . $mode . ')') . '</option>';
    }
    return $html;
}

function asset_group_item_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Pilih Unit Aset Gabungan -</option>';
    foreach ($pdo->query("SELECT id, asset_code, asset_name, asset_type, asset_category FROM asset_items WHERE asset_mode='group' ORDER BY asset_code") as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['asset_code'] . ' - ' . $row['asset_name'] . ' (' . ($row['asset_category'] ?? '-') . ' / ' . $row['asset_type'] . ')') . '</option>';
    }
    return $html;
}

function asset_item_member_manager_html(PDO $pdo, int $parentId): string
{
    $parent = asset_item_row($pdo, $parentId);
    if (!$parent) {
        return '';
    }
    if (($parent['asset_mode'] ?? 'standalone') !== 'group') {
        return '<section class="panel"><h2>Anggota Unit Aset Bundle</h2><p class="muted">Ubah Mode Asset menjadi <strong>Bundle (Parent Asset)</strong> lalu simpan jika unit aset ini akan berisi CPU, monitor, UPS, atau item lain.</p></section>';
    }
    $stmt = $pdo->prepare('SELECT m.*, c.asset_code, c.asset_name, c.asset_type, c.asset_mode, ac.company_name FROM asset_item_members m JOIN asset_items c ON c.id=m.child_asset_item_id LEFT JOIN asset_companies ac ON ac.id=c.company_id WHERE m.parent_asset_item_id=? ORDER BY m.detached_at IS NULL DESC, m.attached_at DESC, m.id DESC');
    $stmt->execute([$parentId]);
    $html = '<section class="panel"><h2>Anggota Unit Aset Bundle</h2><p class="muted">Pasang unit aset lain ke bundle ini. Unit aset yang digabungkan akan otomatis berstatus <strong>Bundle (Child Asset)</strong>.</p><form method="post" action="' . route_url('asset_item_member_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="attach"><input type="hidden" name="parent_asset_item_id" value="' . e($parentId) . '"><div class="grid four"><label>Unit Aset Anggota<select name="child_asset_item_id" required>' . asset_child_item_options($pdo, $parentId) . '</select></label><label>Role<input name="role_name" placeholder="CPU / Monitor / UPS"></label><label>Tanggal Pasang<input type="date" name="attached_at" value="' . e(date('Y-m-d')) . '"></label><label>Catatan<input name="notes"></label></div><button class="btn primary">Gabungkan Unit Aset</button></form><table><tr><th>Unit Aset</th><th>Mode Asset</th><th>Role</th><th>Company</th><th>Pasang</th><th>Lepas</th><th>Aksi</th></tr>';
    foreach ($stmt as $row) {
        $rawMode = (string)($row['asset_mode'] ?? 'standalone');
        $modeBadge = $rawMode === 'group' ? '<span class="badge ok">Bundle (Parent Asset)</span>' : ($rawMode === 'child' ? '<span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a">Bundle (Child Asset)</span>' : '<span class="badge">Single</span>');
        $html .= '<tr><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . ' <span class="muted">(' . e($row['asset_type']) . ')</span></td><td>' . $modeBadge . '</td><td>' . e($row['role_name'] ?: '-') . '</td><td>' . e($row['company_name'] ?: '-') . '</td><td>' . e($row['attached_at']) . '</td><td>' . e($row['detached_at'] ?: '-') . '</td><td>';
        if (empty($row['detached_at'])) {
            $html .= '<form method="post" action="' . route_url('asset_item_member_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="detach"><input type="hidden" name="parent_asset_item_id" value="' . e($parentId) . '"><input type="hidden" name="member_id" value="' . e($row['id']) . '"><input type="date" name="detached_at" value="' . e(date('Y-m-d')) . '"><input name="reason" placeholder="Alasan lepas/tukar"><button class="btn danger">Pisahkan</button></form>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</table></section>';
}

function asset_item_maintenance_link_panel_html(PDO $pdo, int $assetItemId): string
{
    $labels = asset_item_maintenance_asset_labels($pdo, $assetItemId);
    $html = '<section class="panel"><h2>Maintenance Asset Terelasi</h2>';
    if (!$labels) {
        return $html . '<p class="muted">Belum ada Maintenance Asset ID aktif yang terhubung dengan unit aset ini.</p></section>';
    }
    $html .= '<table><tr><th>Maintenance Asset ID</th></tr>';
    foreach ($labels as $label) {
        $html .= '<tr><td>' . e($label) . '</td></tr>';
    }
    return $html . '</table></section>';
}

function asset_bundle_defaults(): array
{
    return [
        'id' => 0,
        'maintenance_asset_code' => '',
        'company_id' => 0,
        'bundle_type' => 'PC Set',
        'bundle_name' => '',
        'employee_nik' => '',
        'owner_name' => '',
        'location_label' => '',
        'latitude' => 0,
        'longitude' => 0,
        'location_radius_m' => 5,
        'status' => 'active',
        'notes' => '',
    ];
}

function asset_bundle_post_data(): array
{
    return [
        'maintenance_asset_code' => strtoupper(trim((string)($_POST['maintenance_asset_code'] ?? ''))),
        'company_id' => (int)($_POST['company_id'] ?? 0) ?: null,
        'bundle_type' => trim((string)($_POST['bundle_type'] ?? '')),
        'bundle_name' => trim((string)($_POST['bundle_name'] ?? '')),
        'employee_nik' => null_if_empty((string)($_POST['employee_nik'] ?? '')),
        'owner_name' => null_if_empty((string)($_POST['owner_name'] ?? '')),
        'location_label' => null_if_empty((string)($_POST['location_label'] ?? '')),
        'latitude' => normalize_decimal_input((string)($_POST['latitude'] ?? '')),
        'longitude' => normalize_decimal_input((string)($_POST['longitude'] ?? '')),
        'location_radius_m' => max(1, (int)(($_POST['location_radius_m'] ?? '') !== '' ? $_POST['location_radius_m'] : 5)),
        'status' => trim((string)($_POST['status'] ?? 'active')),
        'notes' => null_if_empty((string)($_POST['notes'] ?? '')),
    ];
}

function asset_bundles_table(array $rows): string
{
    if (!$rows) {
        return '<p>Belum ada bundle.</p>';
    }
    $html = '<table><tr><th>No Aset Pemeliharaan</th><th>Tipe</th><th>Nama Bundle</th><th>Company</th><th>Pengguna / Lokasi</th><th>Member</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $html .= '<tr><td><strong>' . e($row['maintenance_asset_code']) . '</strong></td><td>' . e($row['bundle_type']) . '</td><td>' . e($row['bundle_name']) . '</td><td>' . e($row['company_name'] ?: '-') . '</td><td>' . e($row['owner_name'] ?: '-') . '<br><span class="muted">' . e($row['location_label'] ?: '-') . '</span></td><td>' . e($row['member_count']) . ' item</td><td><span class="badge">' . e($row['status']) . '</span></td><td><a class="btn" href="' . route_url('asset_bundle_form', ['id' => $row['id']]) . '">Buka</a></td></tr>';
    }
    return $html . '</table>';
}

function asset_bundle_form_html(PDO $pdo, array $bundle, bool $editing): string
{
    $bundle = array_merge(asset_bundle_defaults(), $bundle);
    $types = ['PC Set', 'Workstation', 'Laptop Set', 'Printer Station', 'Server Rack', 'Other'];
    $typeOptions = '';
    foreach ($types as $type) {
        $selected = ((string)($bundle['bundle_type'] ?? '') === $type) ? ' selected' : '';
        $typeOptions .= '<option value="' . e($type) . '"' . $selected . '>' . e($type) . '</option>';
    }
    $userPicker = function_exists('employee_portal_name_picker_html') ? employee_portal_name_picker_html('bundleOwner', 'bundleOwnerName', 'bundleEmployeeNik') : '';
    return '<section class="panel"><h1>' . ($editing ? 'Edit Asset Bundle' : 'Tambah Asset Bundle') . '</h1><p class="muted">Asset bundle dipakai untuk menggabungkan beberapa Asset Item menjadi satu kesatuan no aset pemeliharaan fisik.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three"><label>No Aset Pemeliharaan<input name="maintenance_asset_code" value="' . e($bundle['maintenance_asset_code']) . '" required></label><label>Tipe Bundle<select name="bundle_type">' . $typeOptions . '</select></label><label>Nama Bundle<input name="bundle_name" value="' . e($bundle['bundle_name']) . '" required></label></div><div class="grid three"><label>Company<select name="company_id">' . company_options($pdo, (int)($bundle['company_id'] ?? 0)) . '</select></label><label>Status<select name="status"><option value="active"' . ($bundle['status'] === 'active' ? ' selected' : '') . '>Active</option><option value="spare"' . ($bundle['status'] === 'spare' ? ' selected' : '') . '>Spare</option><option value="inactive"' . ($bundle['status'] === 'inactive' ? ' selected' : '') . '>Inactive</option></select></label><label>Lokasi<input name="location_label" list="savedLocationGroups" value="' . e($bundle['location_label']) . '"></label></div><div class="grid two"><label>NIK Pengguna<input id="bundleEmployeeNik" name="employee_nik" value="' . e($bundle['employee_nik'] ?? '') . '" placeholder="NIK karyawan"></label><label>Nama Pengguna / Pemakai<input id="bundleOwnerName" name="owner_name" value="' . e($bundle['owner_name']) . '" placeholder="Nama pemakai bundle"></label></div>' . $userPicker . '<div class="grid two"><label>Latitude<input name="latitude" value="' . e($bundle['latitude']) . '"></label><label>Longitude<input name="longitude" value="' . e($bundle['longitude']) . '"></label></div>' . saved_location_datalist_html() . '<label>Notes<textarea name="notes">' . e($bundle['notes']) . '</textarea></label><div class="actions"><button class="btn primary">Simpan Bundle</button><a class="btn" href="' . route_url('asset_bundles') . '">Kembali</a></div></form></section>';
}

function asset_bundle_member_manager_html(PDO $pdo, int $bundleId): string
{
    $stmt = $pdo->prepare('SELECT m.*, ai.asset_code, ai.asset_name, ai.asset_type, c.company_name FROM asset_bundle_members m JOIN asset_items ai ON ai.id=m.asset_item_id LEFT JOIN asset_companies c ON c.id=ai.company_id WHERE m.bundle_id=? ORDER BY m.detached_at IS NULL DESC, m.attached_at DESC');
    $stmt->execute([$bundleId]);
    $html = '<section class="panel"><h2>Member Bundle</h2><form method="post" action="' . route_url('asset_bundle_member_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="attach"><input type="hidden" name="bundle_id" value="' . e($bundleId) . '"><div class="grid four"><label>Asset Item<select name="asset_item_id" required>' . asset_item_options($pdo) . '</select></label><label>Role<input name="role_name" placeholder="CPU / Monitor / Notebook"></label><label>Tanggal Pasang<input type="date" name="attached_at" value="' . e(date('Y-m-d')) . '"></label><label>Catatan<input name="notes"></label></div><button class="btn primary">Pasang ke Bundle</button></form><table><tr><th>Asset</th><th>Role</th><th>Company</th><th>Pasang</th><th>Lepas</th><th>Aksi</th></tr>';
    foreach ($stmt as $row) {
        $html .= '<tr><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . ' <span class="muted">(' . e($row['asset_type']) . ')</span></td><td>' . e($row['role_name'] ?: '-') . '</td><td>' . e($row['company_name'] ?: '-') . '</td><td>' . e($row['attached_at']) . '</td><td>' . e($row['detached_at'] ?: '-') . '</td><td>';
        if (empty($row['detached_at'])) {
            $html .= '<form method="post" action="' . route_url('asset_bundle_member_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="detach"><input type="hidden" name="bundle_id" value="' . e($bundleId) . '"><input type="hidden" name="member_id" value="' . e($row['id']) . '"><input type="date" name="detached_at" value="' . e(date('Y-m-d')) . '"><input name="reason" placeholder="Alasan lepas/tukar"><button class="btn danger">Lepas</button></form>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</table></section>';
}

function asset_bundle_repairs_html(PDO $pdo, int $bundleId): string
{
    $stmt = $pdo->prepare('SELECT r.*, ai.asset_code, ai.asset_name FROM asset_repairs r JOIN asset_items ai ON ai.id=r.asset_item_id WHERE r.bundle_id=? ORDER BY r.repair_date DESC, r.id DESC');
    $stmt->execute([$bundleId]);
    $html = '<section class="panel"><div class="split"><h2>Repair History Bundle</h2><a class="btn" href="' . route_url('asset_repair_form', ['bundle_id' => $bundleId]) . '">Tambah Repair</a></div><table><tr><th>Tanggal</th><th>Asset</th><th>Tempat/Vendor</th><th>Spare Part</th><th>Biaya</th></tr>';
    foreach ($stmt as $row) {
        $html .= '<tr><td>' . e($row['repair_date']) . '</td><td>' . e($row['asset_code'] . ' - ' . $row['asset_name']) . '</td><td>' . e($row['repair_location'] ?: '-') . '<br><span class="muted">' . e($row['repair_vendor'] ?: '-') . '</span></td><td>' . e($row['spare_part_replaced'] ?: '-') . '</td><td>Rp ' . e(number_format((float)$row['repair_cost'], 0, ',', '.')) . '</td></tr>';
    }
    return $html . '</table></section>';
}

function asset_bundle_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Tidak terkait bundle -</option>';
    if (!db_table_exists($pdo, 'asset_bundles')) {
        return $html;
    }
    foreach ($pdo->query('SELECT id, maintenance_asset_code, bundle_name FROM asset_bundles ORDER BY maintenance_asset_code') as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['maintenance_asset_code'] . ' - ' . $row['bundle_name']) . '</option>';
    }
    return $html;
}

function asset_repair_defaults(): array
{
    return [
        'id' => 0,
        'asset_item_id' => (int)($_GET['asset_item_id'] ?? 0),
        'bundle_id' => (int)($_GET['bundle_id'] ?? 0),
        'repair_date' => date('Y-m-d'),
        'repair_location' => '',
        'repair_vendor' => '',
        'problem_description' => '',
        'repair_action' => '',
        'spare_part_replaced' => '',
        'repair_cost' => 0,
        'warranty_claim' => 0,
        'technician_or_pic' => '',
        'notes' => '',
        'selected_jobs' => '[]',
    ];
}

function asset_repair_post_data(): array
{
    return [
        'asset_item_id' => (int)($_POST['asset_item_id'] ?? 0),
        'bundle_id' => (int)($_POST['bundle_id'] ?? 0) ?: null,
        'repair_date' => normalize_date_input((string)($_POST['repair_date'] ?? date('Y-m-d'))),
        'repair_location' => trim((string)($_POST['repair_location'] ?? '')),
        'repair_vendor' => trim((string)($_POST['repair_vendor'] ?? '')),
        'problem_description' => trim((string)($_POST['problem_description'] ?? '')),
        'repair_action' => trim((string)($_POST['repair_action'] ?? '')),
        'spare_part_replaced' => trim((string)($_POST['spare_part_replaced'] ?? '')),
        'repair_cost' => (float)str_replace(',', '.', (string)($_POST['repair_cost'] ?? 0)),
        'warranty_claim' => isset($_POST['warranty_claim']) ? 1 : 0,
        'technician_or_pic' => trim((string)($_POST['technician_or_pic'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
}

function repair_part_inputs_html(PDO $pdo, int $repairId): string
{
    $rows = [];
    if ($repairId > 0 && db_table_exists($pdo, 'asset_repair_parts')) {
        $stmt = $pdo->prepare('SELECT * FROM asset_repair_parts WHERE repair_id=? ORDER BY id LIMIT 3');
        $stmt->execute([$repairId]);
        $rows = $stmt->fetchAll();
    }
    while (count($rows) < 3) {
        $rows[] = ['part_name' => '', 'part_brand' => '', 'part_serial' => '', 'qty' => 1, 'unit_cost' => 0, 'old_part_condition' => ''];
    }
    $html = '';
    foreach ($rows as $idx => $part) {
        $html .= '<div class="panel"><label>Part Name<input name="parts[' . $idx . '][part_name]" value="' . e($part['part_name']) . '"></label><label>Brand<input name="parts[' . $idx . '][part_brand]" value="' . e($part['part_brand']) . '"></label><label>Serial<input name="parts[' . $idx . '][part_serial]" value="' . e($part['part_serial']) . '"></label><label>Qty<input type="number" step="0.01" name="parts[' . $idx . '][qty]" value="' . e($part['qty']) . '"></label><label>Unit Cost<input type="number" step="0.01" name="parts[' . $idx . '][unit_cost]" value="' . e($part['unit_cost']) . '"></label><label>Kondisi Part Lama<input name="parts[' . $idx . '][old_part_condition]" value="' . e($part['old_part_condition']) . '"></label></div>';
    }
    return $html;
}

function save_repair_parts(PDO $pdo, int $repairId, array $parts): void
{
    if (!db_table_exists($pdo, 'asset_repair_parts')) {
        return;
    }
    foreach ($parts as $part) {
        $name = trim((string)($part['part_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $pdo->prepare('INSERT INTO asset_repair_parts (repair_id, part_name, part_brand, part_serial, qty, unit_cost, old_part_condition) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$repairId, $name, trim((string)($part['part_brand'] ?? '')), trim((string)($part['part_serial'] ?? '')), (float)($part['qty'] ?? 1), (float)($part['unit_cost'] ?? 0), trim((string)($part['old_part_condition'] ?? ''))]);
    }
}

function asset_repair_rows(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'asset_repairs')) {
        return [];
    }
    return $pdo->query('SELECT r.*, ai.asset_code, ai.asset_name, b.maintenance_asset_code FROM asset_repairs r JOIN asset_items ai ON ai.id=r.asset_item_id LEFT JOIN asset_bundles b ON b.id=r.bundle_id ORDER BY r.repair_date DESC, r.id DESC')->fetchAll();
}

function asset_repairs_table(array $rows): string
{
    if (!$rows) {
        return '<p>Belum ada repair history.</p>';
    }
    $html = '<table><tr><th>Tanggal</th><th>Asset</th><th>Bundle</th><th>Tempat / Vendor</th><th>Kerusakan</th><th>Spare Part</th><th>Biaya</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . e($row['repair_date']) . '</td><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . '</td><td>' . e($row['maintenance_asset_code'] ?: '-') . '</td><td>' . e($row['repair_location'] ?: '-') . '<br><span class="muted">' . e($row['repair_vendor'] ?: '-') . '</span></td><td>' . e($row['problem_description'] ?: '-') . '</td><td>' . e($row['spare_part_replaced'] ?: '-') . '</td><td>Rp ' . e(number_format((float)$row['repair_cost'], 0, ',', '.')) . '</td><td><a class="btn" href="' . route_url('asset_repair_form', ['id' => $row['id']]) . '">Edit</a></td></tr>';
    }
    return $html . '</table>';
}

function asset_repair_form_html(PDO $pdo, array $repair, int $id): string
{
    $savedJobs = [];
    if (!empty($repair['selected_jobs'])) {
        $savedJobs = json_decode((string)$repair['selected_jobs'], true) ?: [];
    }

    $jobsSection = '';
    if (db_table_exists($pdo, 'maintenance_jobs')) {
        $allJobs = $pdo->query('SELECT j.*, g.group_name, g.group_code, t.type_name, t.type_code 
                                FROM maintenance_jobs j 
                                LEFT JOIN asset_groups g ON g.id=j.asset_group_id 
                                LEFT JOIN asset_types t ON t.id=j.asset_type_id 
                                WHERE j.is_active=1 
                                ORDER BY COALESCE(j.job_desk_name, g.group_name, "zzz"), j.title')->fetchAll();
        if ($allJobs) {
            $groupedJobs = [];
            foreach ($allJobs as $job) {
                $dName = $job['job_desk_name'] ?: ('Job Desk ' . ($job['group_name'] ?: 'Umum'));
                $groupedJobs[$dName][] = $job;
            }
            $jobsSection .= '<div style="margin: 22px 0 14px; border-top: 1px solid #e2e8f0; padding-top: 18px;">'
                . '<div class="split" style="align-items:center; margin-bottom: 12px;">'
                . '<div><h2 style="margin:0;">Job Desk / Pekerjaan Perbaikan</h2><p class="muted" style="margin:4px 0 0;">Centang job yang dikerjakan pada perbaikan aset ini.</p></div>'
                . '<div class="actions">'
                . '<button type="button" class="btn" onclick="toggleAllRepairJobs(true)" style="padding:5px 12px;font-size:12px;cursor:pointer;">Pilih Semua</button>'
                . '<button type="button" class="btn" onclick="toggleAllRepairJobs(false)" style="padding:5px 12px;font-size:12px;cursor:pointer;">Batal Pilih</button>'
                . '</div>'
                . '</div>';
            foreach ($groupedJobs as $deskName => $deskJobs) {
                $jobsSection .= '<div class="job-desk-group" style="margin-bottom: 14px;">'
                    . '<div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 12px; margin-bottom: 8px; font-weight: 700; color: #1e293b; display:flex; justify-content:space-between; align-items:center;">'
                    . '<span>' . e($deskName) . '</span>'
                    . '<span class="badge">' . count($deskJobs) . ' item</span>'
                    . '</div>'
                    . '<div class="grid two">';
                foreach ($deskJobs as $job) {
                    $checked = in_array((int)$job['id'], array_map('intval', $savedJobs), true) ? ' checked' : '';
                    $badge = !empty($job['group_code']) ? '<span class="badge ok" style="font-size: 11px; margin-left: 8px;">' . e($job['group_code']) . '</span>' : '';
                    $jobsSection .= '<label class="job-desk-card" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; margin: 0; font-weight: normal; user-select: none;">'
                        . '<input type="checkbox" class="repair-job-checkbox" name="repair_jobs[]" value="' . e($job['id']) . '"' . $checked . ' style="width: 20px; height: 20px; min-width: 20px; cursor: pointer; margin: 0; flex-shrink: 0;">'
                        . '<div style="flex-grow: 1; display: flex; justify-content: space-between; align-items: center; gap: 8px; min-width: 0;">'
                        . '<span style="font-weight: 600; color: #1e293b; line-height: 1.35;">' . e($job['title']) . '</span>'
                        . $badge
                        . '</div>'
                        . '</label>';
                }
                $jobsSection .= '</div></div>';
            }
            $jobsSection .= '<script>
function toggleAllRepairJobs(checked){
    document.querySelectorAll(".repair-job-checkbox").forEach(function(cb){cb.checked=checked;});
}
</script></div>';
        }
    }

    return '<section class="panel"><h1>' . ($id > 0 ? 'Edit Repair History' : 'Tambah Repair History') . '</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three"><label>Asset Item<select name="asset_item_id" required>' . asset_item_options($pdo, (int)$repair['asset_item_id']) . '</select></label><label>Bundle<select name="bundle_id">' . asset_bundle_options($pdo, (int)$repair['bundle_id']) . '</select></label><label>Tanggal Perbaikan<input type="date" name="repair_date" value="' . e($repair['repair_date']) . '" required></label></div><div class="grid three"><label>Tempat Perbaikan<input name="repair_location" value="' . e($repair['repair_location']) . '"></label><label>Vendor / Bengkel<input name="repair_vendor" value="' . e($repair['repair_vendor']) . '"></label><label>PIC / Teknisi<input name="technician_or_pic" value="' . e($repair['technician_or_pic']) . '"></label></div>' . $jobsSection . '<label>Kerusakan<textarea name="problem_description">' . e($repair['problem_description']) . '</textarea></label><label>Tindakan Perbaikan<textarea name="repair_action">' . e($repair['repair_action']) . '</textarea></label><div class="grid two"><label>Spare Part Diganti<textarea name="spare_part_replaced">' . e($repair['spare_part_replaced']) . '</textarea></label><label>Catatan<textarea name="notes">' . e($repair['notes']) . '</textarea></label></div><div class="grid two"><label>Biaya Repair (Rp)<input type="number" step="0.01" name="repair_cost" value="' . e($repair['repair_cost']) . '"></label><label><input style="width:auto" type="checkbox" name="warranty_claim" value="1" ' . ((int)$repair['warranty_claim'] ? 'checked' : '') . '> Warranty Claim</label></div><h2>Detail Spare Part</h2><p class="muted">Opsional. Isi 1-3 part yang diganti.</p><div class="grid three">' . repair_part_inputs_html($pdo, $id) . '</div><div class="actions"><button class="btn primary">Simpan Repair</button><a class="btn" href="' . route_url('asset_repairs') . '">Kembali</a></div></form></section>';
}

function asset_movement_transaction_form_html(PDO $pdo, array $user): string
{
    $actionOptions = '<option value="company_move">Mutasi company asset item</option><option value="attach_maintenance">Pasang asset item ke Maintenance Asset ID</option><option value="detach_maintenance">Lepas asset item dari Maintenance Asset ID</option><option value="attach_group">Tukar/Pasang ke Asset Gabungan</option><option value="detach_group">Lepas dari Asset Gabungan</option>';
    return '<section class="panel"><h1>Transaksi Mutasi / Tukar Pasang</h1><p class="muted">Gunakan halaman ini untuk menjalankan transaksi pindah company, pasang/lepas asset item ke Maintenance Asset ID, atau tukar/pasang anggota asset gabungan.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three"><label>Tipe Transaksi<select name="transaction_type" required>' . $actionOptions . '</select></label><label>Asset Item<select name="asset_item_id" required>' . asset_item_options($pdo) . '</select></label><label>Tanggal Transaksi<input type="date" name="movement_date" value="' . e(date('Y-m-d')) . '" required></label></div><div class="grid three"><label>Tujuan Company<select name="to_company_id">' . company_options($pdo) . '</select></label><label>Maintenance Asset ID<select name="maintenance_asset_id">' . (function_exists('maintenance_asset_options') ? maintenance_asset_options($pdo) : '<option value="">Pilih</option>') . '</select></label><label>Tujuan Asset Gabungan<select name="parent_asset_item_id">' . asset_group_item_options($pdo) . '</select></label></div><div class="grid two"><label>PIC<input name="pic" value="' . e($user['name'] ?? '') . '"></label><label>Role / Posisi Item<input name="role_name" placeholder="CPU / Monitor / Printer / Unit"></label></div><label>Alasan / Catatan<textarea name="reason" placeholder="Contoh: pindah company, monitor ditukar, CPU dipasang ke PC set baru"></textarea></label><div class="actions"><button class="btn primary">Simpan Transaksi</button></div></form></section>';
}

function handle_asset_movement_transaction(PDO $pdo, array $user): void
{
    $assetItemId = (int)($_POST['asset_item_id'] ?? 0);
    $item = asset_item_row($pdo, $assetItemId);
    if (!$item) {
        flash('Asset item wajib dipilih.', 'err');
        return;
    }
    $type = (string)($_POST['transaction_type'] ?? '');
    $date = normalize_date_input((string)($_POST['movement_date'] ?? date('Y-m-d')));
    $pic = trim((string)($_POST['pic'] ?? ($user['name'] ?? '')));
    $role = trim((string)($_POST['role_name'] ?? 'Asset Item'));
    $reason = trim((string)($_POST['reason'] ?? ''));

    try {
        if ($type === 'company_move') {
            $toCompanyId = (int)($_POST['to_company_id'] ?? 0);
            $fromCompanyId = (int)($item['company_id'] ?? 0) ?: null;
            if ($toCompanyId <= 0) {
                flash('Pilih company tujuan mutasi.', 'err');
                return;
            }
            $pdo->prepare('UPDATE asset_items SET company_id=? WHERE id=?')->execute([$toCompanyId, $assetItemId]);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_company_id, to_company_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?, ?)')->execute([$assetItemId, $fromCompanyId, $toCompanyId, $date, $reason ?: 'Mutasi antar company', $pic]);
            flash('Mutasi company asset item berhasil disimpan.');
            return;
        }
        if ($type === 'attach_maintenance') {
            $maintenanceAssetId = (int)($_POST['maintenance_asset_id'] ?? 0);
            if ($maintenanceAssetId <= 0) {
                flash('Pilih Maintenance Asset ID tujuan.', 'err');
                return;
            }
            if (function_exists('link_maintenance_asset_item')) {
                link_maintenance_asset_item($pdo, $maintenanceAssetId, $assetItemId, $role, $date);
            }
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?)')->execute([$assetItemId, $date, $reason ?: 'Pasang ke Maintenance Asset ID #' . $maintenanceAssetId, $pic]);
            flash('Asset item berhasil dipasang ke Maintenance Asset ID.');
            return;
        }
        if ($type === 'detach_maintenance') {
            $maintenanceAssetId = (int)($_POST['maintenance_asset_id'] ?? 0);
            $detachedCount = 0;
            if (function_exists('detach_asset_item_from_maintenance_assets')) {
                $detachedCount = detach_asset_item_from_maintenance_assets($pdo, $assetItemId, $maintenanceAssetId > 0 ? $maintenanceAssetId : null, $date, $reason ?: 'Lepas dari Maintenance Asset ID');
            }
            if ($detachedCount <= 0) {
                flash('Relasi aktif ke Maintenance Asset ID tidak ditemukan untuk asset item ini.', 'err');
                return;
            }
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?)')->execute([$assetItemId, $date, $reason ?: 'Lepas dari Maintenance Asset ID', $pic]);
            flash('Asset item berhasil dilepas dari Maintenance Asset ID.');
            return;
        }
        if ($type === 'attach_group') {
            $parentId = (int)($_POST['parent_asset_item_id'] ?? 0);
            $parent = asset_item_row($pdo, $parentId);
            if (!$parent || ($parent['asset_mode'] ?? 'standalone') !== 'group' || $parentId === $assetItemId) {
                flash('Tujuan Asset Gabungan tidak valid.', 'err');
                return;
            }
            if (asset_item_contains_child($pdo, $assetItemId, $parentId)) {
                flash('Gabungan ditolak karena akan membuat lingkaran asset gabungan.', 'err');
                return;
            }
            $oldParent = active_parent_asset_item($pdo, $assetItemId);
            if ($oldParent && (int)$oldParent['parent_asset_item_id'] !== $parentId) {
                $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\nDipindahkan via transaksi mutasi ke " . ($parent['asset_code'] ?? ''), (int)$oldParent['id']]);
            }
            if ($oldParent && (int)$oldParent['parent_asset_item_id'] === $parentId) {
                $pdo->prepare('UPDATE asset_item_members SET role_name=?, attached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$role, $date, "\nUpdate via transaksi mutasi: " . ($reason ?: '-'), (int)$oldParent['id']]);
            } else {
                $pdo->prepare('INSERT INTO asset_item_members (parent_asset_item_id, child_asset_item_id, role_name, attached_at, notes) VALUES (?, ?, ?, ?, ?)')->execute([$parentId, $assetItemId, $role, $date, $reason]);
            }
            $pdo->prepare("UPDATE asset_items SET asset_mode='child' WHERE id=?")->execute([$assetItemId]);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_parent_asset_item_id, to_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?, ?)')->execute([$assetItemId, $oldParent ? (int)$oldParent['parent_asset_item_id'] : null, $parentId, $date, $reason ?: 'Tukar/Pasang ke asset gabungan ' . ($parent['asset_code'] ?? ''), $pic]);
            flash('Transaksi tukar/pasang asset gabungan berhasil disimpan.');
            return;
        }
        if ($type === 'detach_group') {
            $oldParent = active_parent_asset_item($pdo, $assetItemId);
            if (!$oldParent) {
                flash('Asset item ini tidak sedang tergabung pada Asset Gabungan.', 'err');
                return;
            }
            $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\nDetached via transaksi mutasi: " . ($reason ?: '-'), (int)$oldParent['id']]);
            $childActive = active_parent_asset_item($pdo, $assetItemId);
            if (!$childActive) {
                $pdo->prepare("UPDATE asset_items SET asset_mode='standalone' WHERE id=?")->execute([$assetItemId]);
            }
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?)')->execute([$assetItemId, (int)$oldParent['parent_asset_item_id'], $date, $reason ?: 'Lepas dari asset gabungan', $pic]);
            flash('Asset item berhasil dilepas dari Asset Gabungan.');
            return;
        }
        flash('Tipe transaksi tidak valid.', 'err');
    } catch (Throwable $e) {
        flash('Gagal simpan transaksi asset: ' . $e->getMessage(), 'err');
    }
}

function asset_movement_rows(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'asset_movements')) {
        return [];
    }
    return $pdo->query('SELECT mv.*, ai.asset_code, ai.asset_name, fb.maintenance_asset_code from_bundle, tb.maintenance_asset_code to_bundle, fp.asset_code from_parent_code, fp.asset_name from_parent_name, tp.asset_code to_parent_code, tp.asset_name to_parent_name, fc.company_name from_company, tc.company_name to_company FROM asset_movements mv JOIN asset_items ai ON ai.id=mv.asset_item_id LEFT JOIN asset_bundles fb ON fb.id=mv.from_bundle_id LEFT JOIN asset_bundles tb ON tb.id=mv.to_bundle_id LEFT JOIN asset_items fp ON fp.id=mv.from_parent_asset_item_id LEFT JOIN asset_items tp ON tp.id=mv.to_parent_asset_item_id LEFT JOIN asset_companies fc ON fc.id=mv.from_company_id LEFT JOIN asset_companies tc ON tc.id=mv.to_company_id ORDER BY mv.movement_date DESC, mv.id DESC')->fetchAll();
}

function asset_movements_table(array $rows): string
{
    if (!$rows) {
        return '<p>Belum ada mutasi/tukar pasang.</p>';
    }
    $html = '<table><tr><th>Tanggal</th><th>Asset</th><th>Dari Gabungan</th><th>Ke Gabungan</th><th>Company</th><th>Alasan</th><th>PIC</th></tr>';
    foreach ($rows as $row) {
        $from = $row['from_parent_code'] ? $row['from_parent_code'] . ' - ' . $row['from_parent_name'] : ($row['from_bundle'] ?: '-');
        $to = $row['to_parent_code'] ? $row['to_parent_code'] . ' - ' . $row['to_parent_name'] : ($row['to_bundle'] ?: '-');
        $html .= '<tr><td>' . e($row['movement_date']) . '</td><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . '</td><td>' . e($from) . '</td><td>' . e($to) . '</td><td>' . e(($row['from_company'] ?: '-') . ' -> ' . ($row['to_company'] ?: '-')) . '</td><td>' . e($row['reason'] ?: '-') . '</td><td>' . e($row['pic'] ?: '-') . '</td></tr>';
    }
    return $html . '</table>';
}

if (!function_exists('asset_group_options')) {
    function asset_group_options(PDO $pdo, int $selected = 0, bool $includeEmpty = true, string $emptyLabel = '- Pilih Komoditas (Group) -'): string
    {
        $h = $includeEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
        if (!db_table_exists($pdo, 'asset_groups')) {
            return $h;
        }
        foreach ($pdo->query('SELECT * FROM asset_groups WHERE is_active=1 ORDER BY group_name') as $r) {
            $h .= '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['group_code'] . ' - ' . $r['group_name']) . '</option>';
        }
        return $h;
    }
}

function asset_type_options(PDO $pdo, int $selected = 0, int $groupId = 0, bool $includeEmpty = true, string $emptyLabel = '- Pilih Kategori (Tipe) -'): string
{
    $h = $includeEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
    if (!db_table_exists($pdo, 'asset_types')) {
        return $h;
    }
    if ($groupId <= 0 && $selected <= 0) {
        return '<option value="">- Pilih Komoditas Terlebih Dahulu -</option>';
    }
    if ($groupId > 0) {
        $gCode = (string)$pdo->query("SELECT group_code FROM asset_groups WHERE id = {$groupId}")->fetchColumn();
        $gCode = strtoupper(trim($gCode));
        $s = $pdo->prepare('SELECT t.* FROM asset_types t LEFT JOIN asset_groups g ON g.id = t.asset_group_id WHERE (t.is_active=1 OR t.is_active IS NULL) AND (t.asset_group_id = ? OR UPPER(TRIM(g.group_code)) = ?) ORDER BY t.type_name');
        $s->execute([$groupId, $gCode]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows) && function_exists('repair_asset_type_groups')) {
            repair_asset_type_groups($pdo);
            $s->execute([$groupId, $gCode]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as $r) {
            $h .= '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['type_code'] . ' - ' . $r['type_name']) . '</option>';
        }
    } else {
        $sql = 'SELECT * FROM asset_types WHERE (is_active=1 OR is_active IS NULL) ORDER BY type_name';
        $s = $pdo->query($sql);
        foreach ($s as $r) {
            $h .= '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['type_code'] . ' - ' . $r['type_name']) . '</option>';
        }
    }
    return $h;
}

function asset_brand_options(PDO $pdo, int $selected = 0, bool $includeEmpty = true, string $emptyLabel = '- Pilih Brand / Merk -'): string
{
    $h = $includeEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
    if (!db_table_exists($pdo, 'asset_brands')) {
        return $h;
    }
    foreach ($pdo->query('SELECT * FROM asset_brands WHERE is_active=1 ORDER BY brand_name ASC') as $r) {
        $h .= '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['brand_name'] . ($r['brand_code'] ? ' (' . $r['brand_code'] . ')' : '')) . '</option>';
    }
    return $h;
}

function asset_master_item_options(PDO $pdo, int $selected = 0, int $groupId = 0, int $typeId = 0, bool $includeEmpty = true, string $emptyLabel = '- Pilih dari Master Barang (Katalog Model) -'): string
{
    $h = $includeEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
    if (!db_table_exists($pdo, 'asset_master_items')) {
        return $h;
    }
    $sql = 'SELECT ami.*, ab.brand_name, ag.group_code, at.type_code 
            FROM asset_master_items ami 
            LEFT JOIN asset_brands ab ON ab.id = ami.brand_id 
            LEFT JOIN asset_groups ag ON ag.id = ami.asset_group_id 
            LEFT JOIN asset_types at ON at.id = ami.asset_type_id 
            WHERE ami.is_active = 1';
    $params = [];
    if ($groupId > 0) {
        $sql .= ' AND ami.asset_group_id = ?';
        $params[] = $groupId;
    }
    if ($typeId > 0) {
        $sql .= ' AND ami.asset_type_id = ?';
        $params[] = $typeId;
    }
    $sql .= ' ORDER BY ami.item_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $bName = !empty($r['brand_name']) ? ' [' . $r['brand_name'] . ']' : '';
        $h .= '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . ' data-group="' . (int)$r['asset_group_id'] . '" data-type="' . (int)$r['asset_type_id'] . '" data-brand-id="' . (int)($r['brand_id'] ?? 0) . '" data-brand-name="' . e($r['brand_name'] ?? '') . '" data-model="' . e($r['model_name'] ?? '') . '" data-name="' . e($r['item_name']) . '">' . e($r['item_code'] . ' - ' . $r['item_name'] . $bName) . '</option>';
    }
    return $h;
}

function asset_location_options(PDO $pdo, int $selected = 0, bool $includeEmpty = true, string $emptyLabel = '- Pilih Lokasi Unit Aset -'): string
{
    $h = $includeEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
    if (!db_table_exists($pdo, 'asset_locations')) {
        return $h;
    }
    foreach ($pdo->query('SELECT * FROM asset_locations WHERE is_active=1 ORDER BY location_name ASC') as $r) {
        $h .= '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . ' data-code="' . e($r['location_code']) . '">' . e($r['location_name'] . ' (' . $r['location_code'] . ')') . '</option>';
    }
    return $h;
}

function asset_status_options(PDO $pdo, int $selected = 0): string
{
    $h = '<option value="">- Pilih Status Asset -</option>';
    if (!db_table_exists($pdo, 'asset_statuses')) {
        return $h;
    }
    foreach ($pdo->query('SELECT * FROM asset_statuses WHERE is_active=1 ORDER BY status_name') as $r) {
        $h .= '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['status_name']) . '</option>';
    }
    return $h;
}

function asset_type_form_config(PDO $pdo, int $typeId, int $groupId = 0): array
{
    if ($typeId <= 0 || !db_table_exists($pdo, 'asset_types')) {
        return ['type' => [], 'identifiers' => [], 'specifications' => []];
    }
    $sql = 'SELECT t.*, COALESCE(g.group_code, "") AS group_code FROM asset_types t LEFT JOIN asset_groups g ON g.id=t.asset_group_id WHERE t.id=?';
    $params = [$typeId];
    if ($groupId > 0) {
        $sql .= ' AND t.asset_group_id=?';
        $params[] = $groupId;
    }
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $type = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$type) {
        return ['type' => [], 'identifiers' => [], 'specifications' => []];
    }
    $s = $pdo->prepare('SELECT * FROM asset_type_identifiers WHERE asset_type_id=? ORDER BY display_order,id');
    $s->execute([$typeId]);
    $identifiers = $s->fetchAll(PDO::FETCH_ASSOC);

    $specifications = [];
    if (db_table_exists($pdo, 'asset_type_specifications')) {
        $sSpec = $pdo->prepare('SELECT * FROM asset_type_specifications WHERE asset_type_id=? ORDER BY display_order,id');
        $sSpec->execute([$typeId]);
        $specifications = $sSpec->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($type) {
        $grpCode = strtoupper(trim((string)($type['group_code'] ?? '')));
        if ($grpCode === '') {
            $tCode = strtoupper(trim((string)($type['type_code'] ?? '')));
            if (in_array($tCode, ['CAR', 'MTR', 'TRK'])) $grpCode = 'VH';
            elseif (in_array($tCode, ['AC', 'BLD', 'GEN', 'FC'])) $grpCode = 'FC';
            else $grpCode = 'IT';
        }
        $type['next_asset_code'] = generate_next_asset_code($pdo, $grpCode, (string)($type['type_code'] ?? ''));
    }
    return ['type' => $type, 'identifiers' => $identifiers, 'specifications' => $specifications];
}

function asset_master_page(PDO $pdo, string $route, array $user): void
{
    if ($route === 'asset_specifications') {
        asset_specification_master_page($pdo, $user);
        return;
    }
    if ($route === 'asset_maintenance_templates') {
        asset_maintenance_template_page($pdo, $user);
        return;
    }
    if ($route === 'asset_identifiers') {
        asset_identifier_master_page($pdo, $user);
        return;
    }
    $cfg = [
        'asset_groups' => ['Master Komoditas', 'asset_groups', 'group_code', 'group_name'],
        'asset_types' => ['Master Kategori', 'asset_types', 'type_code', 'type_name'],
        'asset_brands' => ['Master Brand / Merk', 'asset_brands', 'brand_code', 'brand_name'],
        'asset_locations' => ['Master Lokasi', 'asset_locations', 'location_code', 'location_name'],
        'asset_statuses' => ['Status Asset', 'asset_statuses', 'status_code', 'status_name'],
    ];
    if (!isset($cfg[$route])) {
        redirect_to('asset_dashboard');
    }
    [$title, $table, $codeCol, $nameCol] = $cfg[$route];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'delete') {
            $delId = (int)($_POST['id'] ?? 0);
            if ($delId <= 0) {
                flash('ID data tidak valid.', 'err');
                redirect_to($route);
            }
            try {
                if ($route === 'asset_types') {
                    $unitCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_items WHERE asset_type_id = {$delId}")->fetchColumn();
                    $masterCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_master_items WHERE asset_type_id = {$delId}")->fetchColumn();
                    $idCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_type_identifiers WHERE asset_type_id = {$delId}")->fetchColumn();
                    if ($unitCount > 0 || $masterCount > 0 || $idCount > 0) {
                        flash("Kategori tidak dapat dihapus karena masih digunakan ({$unitCount} unit aset, {$masterCount} master barang, {$idCount} identifier).", 'err');
                        redirect_to($route);
                    }
                } elseif ($route === 'asset_groups') {
                    $typeCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_types WHERE asset_group_id = {$delId}")->fetchColumn();
                    $unitCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_items WHERE asset_group_id = {$delId}")->fetchColumn();
                    if ($typeCount > 0 || $unitCount > 0) {
                        flash("Komoditas tidak dapat dihapus karena masih memiliki {$typeCount} kategori atau {$unitCount} unit aset.", 'err');
                        redirect_to($route);
                    }
                } elseif ($route === 'asset_brands') {
                    $unitCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_items WHERE brand_id = {$delId}")->fetchColumn();
                    $masterCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_master_items WHERE brand_id = {$delId}")->fetchColumn();
                    if ($unitCount > 0 || $masterCount > 0) {
                        flash("Brand tidak dapat dihapus karena masih digunakan ({$unitCount} unit aset, {$masterCount} master barang).", 'err');
                        redirect_to($route);
                    }
                } elseif ($route === 'asset_locations') {
                    $unitCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_items WHERE location_id = {$delId}")->fetchColumn();
                    if ($unitCount > 0) {
                        flash("Lokasi tidak dapat dihapus karena masih digunakan oleh {$unitCount} unit aset.", 'err');
                        redirect_to($route);
                    }
                } elseif ($route === 'asset_statuses') {
                    $unitCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_items WHERE asset_status_id = {$delId}")->fetchColumn();
                    if ($unitCount > 0) {
                        flash("Status tidak dapat dihapus karena masih digunakan oleh {$unitCount} unit aset.", 'err');
                        redirect_to($route);
                    }
                }

                $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$delId]);
                flash($title . ' berhasil dihapus.');
            } catch (Throwable $e) {
                flash('Gagal menghapus: ' . $e->getMessage(), 'err');
            }
            redirect_to($route);
        }

        $id = (int)($_POST['id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;
        $groupId = (int)($_POST['asset_group_id'] ?? 0);
        if ($name === '' || ($route === 'asset_types' && $groupId <= 0)) {
            flash('Semua field wajib diisi.', 'err');
            redirect_to($route, $id ? ['id' => $id] : []);
        }
        if ($code === '') {
            $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 8));
        }
        try {
            if ($route === 'asset_types') {
                $sql = $id ? 'UPDATE ' . $table . ' SET asset_group_id=?, ' . $codeCol . '=?, ' . $nameCol . '=?, is_active=? WHERE id=?' : 'INSERT INTO ' . $table . ' (asset_group_id,' . $codeCol . ',' . $nameCol . ',is_active) VALUES (?,?,?,?)';
                $p = $id ? [$groupId, $code, $name, $active, $id] : [$groupId, $code, $name, $active];
            } else {
                $sql = $id ? 'UPDATE ' . $table . ' SET ' . $codeCol . '=?, ' . $nameCol . '=?, is_active=? WHERE id=?' : 'INSERT INTO ' . $table . ' (' . $codeCol . ',' . $nameCol . ',is_active) VALUES (?,?,?)';
                $p = $id ? [$code, $name, $active, $id] : [$code, $name, $active];
            }
            $pdo->prepare($sql)->execute($p);
            flash($title . ' berhasil disimpan.');
        } catch (Throwable $e) {
            flash('Gagal simpan: ' . $e->getMessage(), 'err');
        }
        redirect_to($route);
    }
    $edit = null;
    if (($id = (int)($_GET['id'] ?? 0)) > 0) {
        $s = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id=?');
        $s->execute([$id]);
        $edit = $s->fetch();
    }
    render_header($title, $user);
    echo asset_nav_html();
    echo '<section class="panel"><div class="split"><h1>' . e($edit ? 'Edit ' : 'Tambah ') . e($title) . '</h1>';
    if ($edit) {
        echo '<a class="btn" href="' . route_url($route) . '">+ Tambah ' . e($title) . ' Baru</a>';
    }
    echo '</div><form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . e($edit['id'] ?? '') . '">';
    if ($route === 'asset_types') {
        echo '<label>Komoditas (Grup Aset) *<select name="asset_group_id" required>' . asset_group_options($pdo, (int)($edit['asset_group_id'] ?? 0)) . '</select></label>';
    }
    $placeholderCode = $route === 'asset_brands' ? 'LEN' : ($route === 'asset_locations' ? 'HO' : ($route === 'asset_groups' ? 'IT' : 'LPT'));
    $placeholderName = $route === 'asset_brands' ? 'Lenovo' : ($route === 'asset_locations' ? 'Head Office' : ($route === 'asset_groups' ? 'IT & Komputer' : 'Laptop'));
    echo '<div class="grid two"><label>Kode ' . e($title) . ' *<input name="code" required value="' . e($edit[$codeCol] ?? '') . '" placeholder="Contoh: ' . $placeholderCode . '"></label><label>Nama ' . e($title) . ' *<input name="name" required value="' . e($edit[$nameCol] ?? '') . '" placeholder="Contoh: ' . $placeholderName . '"></label></div><label><input style="width:auto" type="checkbox" name="is_active" ' . ((!$edit || (int)$edit['is_active']) ? 'checked' : '') . '> Aktif</label><div class="actions" style="margin-top:14px"><button class="btn primary">Simpan ' . e($title) . '</button>';
    if ($edit) {
        echo ' <a class="btn" href="' . route_url($route) . '">Batal</a>';
    }
    echo '</div></form></section>';

    $filterGroupId = (int)($_GET['group_id'] ?? 0);
    echo '<section class="panel">';
    if ($route === 'asset_types') {
        echo '<div class="split"><h2>Daftar ' . e($title) . '</h2>'
            . '<form method="get" class="actions" style="margin:0">'
            . '<input type="hidden" name="route" value="asset_types">'
            . '<select name="group_id" onchange="this.form.submit()">' . asset_group_options($pdo, $filterGroupId, true, 'Semua Komoditas') . '</select>'
            . ($filterGroupId ? ' <a class="btn" href="' . route_url('asset_types') . '">Reset</a>' : '')
            . '</form></div>';
        echo '<table><thead><tr><th>ID</th><th>Komoditas (Grup Aset)</th><th>Kode Kategori</th><th>Nama Kategori</th><th>Total Master Model</th><th>Total Unit Fisik</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';
    } else {
        echo '<h2>Daftar ' . e($title) . '</h2>';
        echo '<table><thead><tr><th>ID</th><th>Kode</th><th>Nama</th>' . ($route === 'asset_brands' ? '<th>Total Master Barang</th><th>Total Unit Fisik</th>' : ($route === 'asset_groups' ? '<th>Total Kategori</th><th>Total Unit Fisik</th>' : ($route === 'asset_locations' ? '<th>Total Unit Aset</th>' : ''))) . '<th>Status</th><th>Aksi</th></tr></thead><tbody>';
    }
    
    if ($route === 'asset_types') {
        $sql = 'SELECT t.*, g.group_name, g.group_code,
            (SELECT COUNT(*) FROM asset_master_items ami WHERE ami.asset_type_id = t.id) AS master_count,
            (SELECT COUNT(*) FROM asset_items ai WHERE ai.asset_type_id = t.id) AS unit_count 
            FROM asset_types t LEFT JOIN asset_groups g ON g.id=t.asset_group_id '
            . ($filterGroupId > 0 ? 'WHERE t.asset_group_id = ' . (int)$filterGroupId . ' ' : '')
            . 'ORDER BY COALESCE(g.group_name, "Z"), t.type_name';
        $rows = $pdo->query($sql)->fetchAll();
    } elseif ($route === 'asset_groups') {
        $rows = $pdo->query('SELECT g.*, 
            (SELECT COUNT(*) FROM asset_types t WHERE t.asset_group_id = g.id) AS type_count,
            (SELECT COUNT(*) FROM asset_items ai WHERE ai.asset_group_id = g.id) AS unit_count 
            FROM asset_groups g ORDER BY g.group_name')->fetchAll();
    } elseif ($route === 'asset_brands') {
        $rows = $pdo->query('SELECT b.*, 
            (SELECT COUNT(*) FROM asset_master_items ami WHERE ami.brand_id = b.id) AS master_count,
            (SELECT COUNT(*) FROM asset_items ai WHERE ai.brand_id = b.id) AS unit_count 
            FROM asset_brands b ORDER BY b.brand_name ASC')->fetchAll();
    } elseif ($route === 'asset_locations') {
        $rows = $pdo->query('SELECT l.*, 
            (SELECT COUNT(*) FROM asset_items ai WHERE ai.location_id = l.id) AS unit_count 
            FROM asset_locations l ORDER BY l.location_name ASC')->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . $nameCol)->fetchAll();
    }
    
    foreach ($rows as $r) {
        $delBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus ' . e($title) . ' ini?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn danger" style="padding:4px 8px;font-size:12px">Hapus</button></form>';
        
        if ($route === 'asset_types') {
            $grpBadge = '<span class="badge" style="background:#e0f2fe;color:#0369a1;font-weight:600;">' . e($r['group_code'] . ' - ' . $r['group_name']) . '</span>';
            echo '<tr>'
                . '<td>' . e($r['id']) . '</td>'
                . '<td>' . $grpBadge . '</td>'
                . '<td><strong>' . e($r['type_code']) . '</strong></td>'
                . '<td>' . e($r['type_name']) . '</td>'
                . '<td>' . (int)$r['master_count'] . ' Model</td>'
                . '<td><strong>' . (int)$r['unit_count'] . ' Unit</strong></td>'
                . '<td>' . ((int)$r['is_active'] ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td>'
                . '<td><div class="actions" style="display:flex;gap:6px;align-items:center;"><a class="btn" href="' . route_url($route, ['id' => $r['id']]) . '">Edit</a> ' . $delBtn . '</div></td>'
                . '</tr>';
        } else {
            $extraCol = '';
            if ($route === 'asset_groups') {
                $extraCol = '<td>' . (int)$r['type_count'] . ' Kategori</td><td><strong>' . (int)$r['unit_count'] . ' Unit</strong></td>';
            } elseif ($route === 'asset_brands') {
                $extraCol = '<td>' . (int)$r['master_count'] . ' Model</td><td><strong>' . (int)$r['unit_count'] . ' Unit</strong></td>';
            } elseif ($route === 'asset_locations') {
                $extraCol = '<td><strong>' . (int)$r['unit_count'] . ' Unit</strong></td>';
            }
            echo '<tr><td>' . e($r['id']) . '</td><td><strong>' . e($r[$codeCol]) . '</strong></td><td>' . e($r[$nameCol]) . '</td>' . $extraCol . '<td>' . ((int)$r['is_active'] ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td><td><div class="actions" style="display:flex;gap:6px;align-items:center;"><a class="btn" href="' . route_url($route, ['id' => $r['id']]) . '">Edit</a> ' . $delBtn . '</div></td></tr>';
        }
    }
    echo '</tbody></table></section>';
    render_footer();
}

function asset_identifier_master_page(PDO $pdo, array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'delete') {
            $delId = (int)($_POST['id'] ?? 0);
            if ($delId > 0) {
                try {
                    $pdo->prepare('DELETE FROM asset_identifiers WHERE asset_type_identifier_id = ?')->execute([$delId]);
                    $pdo->prepare('DELETE FROM asset_type_identifiers WHERE id = ?')->execute([$delId]);
                    flash('Identifier Aset berhasil dihapus.');
                } catch (Throwable $e) {
                    flash('Gagal menghapus: ' . $e->getMessage(), 'err');
                }
            }
            redirect_to('asset_identifiers');
        }

        $id = (int)($_POST['id'] ?? 0);
        $groupId = (int)($_POST['asset_group_id'] ?? 0);
        $type = (int)($_POST['asset_type_id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['identifier_code'] ?? '')));
        $name = trim((string)($_POST['identifier_name'] ?? ''));
        if (!$groupId || !$type || $code === '' || $name === '') {
            flash('Komoditas, Kategori, Kode Identifier, dan Nama Identifier wajib diisi.', 'err');
            redirect_to('asset_identifiers', $id ? ['id' => $id] : []);
        }
        $p = [$type, $code, $name, $_POST['data_type'] ?? 'text', isset($_POST['is_required']) ? 1 : 0, isset($_POST['is_unique']) ? 1 : 0, isset($_POST['is_searchable']) ? 1 : 0, max(1, (int)($_POST['display_order'] ?? 1))];
        $sql = $id ? 'UPDATE asset_type_identifiers SET asset_type_id=?,identifier_code=?,identifier_name=?,data_type=?,is_required=?,is_unique=?,is_searchable=?,display_order=? WHERE id=?' : 'INSERT INTO asset_type_identifiers(asset_type_id,identifier_code,identifier_name,data_type,is_required,is_unique,is_searchable,display_order) VALUES (?,?,?,?,?,?,?,?)';
        if ($id) {
            $p[] = $id;
        }
        try {
            $pdo->prepare($sql)->execute($p);
            flash('Identifier Aset berhasil disimpan.');
        } catch (Throwable $e) {
            flash('Gagal: ' . $e->getMessage(), 'err');
        }
        redirect_to('asset_identifiers');
    }

    $edit = null;
    $editGroupId = 0;
    if (($editId = (int)($_GET['id'] ?? 0)) > 0) {
        $stmt = $pdo->prepare('SELECT ati.*, t.asset_group_id FROM asset_type_identifiers ati JOIN asset_types t ON t.id=ati.asset_type_id WHERE ati.id = ?');
        $stmt->execute([$editId]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($edit) {
            $editGroupId = (int)$edit['asset_group_id'];
        }
    }

    render_header('Identifier Aset', $user);
    echo asset_nav_html();
    echo '<section class="panel"><div class="split"><h1>' . ($edit ? 'Edit' : 'Tambah') . ' Identifier Aset</h1>';
    if ($edit) {
        echo '<a class="btn" href="' . route_url('asset_identifiers') . '">+ Tambah Identifier Baru</a>';
    }
    echo '</div><form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '">';
    
    // Harus isi Komoditas dan Kategori terlebih dahulu
    echo '<div class="grid two">';
    echo '<label>Komoditas (Grup Aset) *<select id="idfGroup" name="asset_group_id" onchange="onIdfGroupChanged()" required>' . asset_group_options($pdo, $editGroupId, true, '- Pilih Komoditas (Grup Aset) -') . '</select></label>';
    echo '<label>Kategori (Tipe Aset) *<select id="idfType" name="asset_type_id" required>' . asset_type_options($pdo, (int)($edit['asset_type_id'] ?? 0), $editGroupId, true, $editGroupId ? '- Pilih Kategori -' : '- Pilih Komoditas Terlebih Dahulu -') . '</select></label>';
    echo '</div>';

    echo '<div class="grid two">';
    echo '<label>Kode Identifier *<input name="identifier_code" required value="' . e($edit['identifier_code'] ?? '') . '" placeholder="Contoh: SERIAL, MAC, IMEI, PLAT"></label>';
    echo '<label>Nama Identifier *<input name="identifier_name" required value="' . e($edit['identifier_name'] ?? '') . '" placeholder="Contoh: Serial Number, MAC Address, Nomor Polisi"></label>';
    echo '</div>';

    echo '<div class="grid four">';
    echo '<label>Tipe Data<select name="data_type">';
    $currDt = $edit['data_type'] ?? 'text';
    foreach (['text', 'number', 'date', 'ip'] as $dt) {
        echo '<option value="' . $dt . '"' . ($currDt === $dt ? ' selected' : '') . '>' . $dt . '</option>';
    }
    echo '</select></label>';
    echo '<label><input style="width:auto" type="checkbox" name="is_required" ' . (!empty($edit['is_required']) ? 'checked' : '') . '> Wajib Diisi (Required)</label>';
    echo '<label><input style="width:auto" type="checkbox" name="is_unique" ' . (!empty($edit['is_unique']) ? 'checked' : '') . '> Nilai Unik (Tidak Boleh Duplikat)</label>';
    echo '<label>Urutan Tampil<input type="number" name="display_order" value="' . (int)($edit['display_order'] ?? 1) . '" min="1"></label>';
    echo '</div>';

    echo '<div class="actions" style="margin-top:14px">';
    if ($edit) {
        echo '<a class="btn" href="' . route_url('asset_identifiers') . '">Batal</a> ';
    }
    echo '<button class="btn primary">Simpan Identifier</button>';
    echo '</div></form></section>';

    // JavaScript to dynamically populate Kategori when Komoditas changes
    echo '<script>
    window.onIdfGroupChanged = async function(){
        var idfGroup = document.getElementById("idfGroup");
        var idfType = document.getElementById("idfType");
        if(!idfGroup || !idfType) return;
        var gid = parseInt(idfGroup.value, 10) || 0;
        if(gid === 0){
            idfType.innerHTML = "<option value=\"\">- Pilih Komoditas Terlebih Dahulu -</option>";
            return;
        }
        idfType.innerHTML = "<option value=\"\">Memuat Kategori...</option>";
        try {
            var r = await fetch("index.php?route=api_asset_types&group_id=" + gid);
            var types = await r.json();
            var h = "<option value=\"\">- Pilih Kategori -</option>";
            if(Array.isArray(types) && types.length > 0){
                types.forEach(function(t){
                    h += "<option value=\"" + t.id + "\">" + (t.type_code ? t.type_code + " - " : "") + t.type_name + "</option>";
                });
            } else {
                h = "<option value=\"\">- Belum ada kategori untuk komoditas ini -</option>";
            }
            idfType.innerHTML = h;
        } catch(e){
            console.error("Gagal load kategori:", e);
        }
    };
    </script>';

    echo '<section class="panel"><h2>Daftar Identifier Aset</h2><table><thead><tr><th>ID</th><th>Komoditas (Grup)</th><th>Kategori (Tipe)</th><th>Kode Identifier</th><th>Nama Identifier</th><th>Tipe Data</th><th>Required</th><th>Unique</th><th>Total Unit Terisi</th><th>Order</th><th>Aksi</th></tr></thead><tbody>';

    $rows = $pdo->query('SELECT i.*, t.type_name, t.type_code, ag.group_name, ag.group_code,
        (SELECT COUNT(DISTINCT aid.asset_item_id) FROM asset_identifiers aid WHERE aid.asset_type_identifier_id = i.id AND aid.identifier_value != "") AS unit_count
        FROM asset_type_identifiers i 
        JOIN asset_types t ON t.id=i.asset_type_id 
        LEFT JOIN asset_groups ag ON ag.id=t.asset_group_id
        ORDER BY ag.group_name ASC, t.type_name ASC, i.display_order ASC')->fetchAll();

    foreach ($rows as $r) {
        $delBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus Identifier ini?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn danger" style="padding:4px 8px;font-size:12px">Hapus</button></form>';
        $uCnt = (int)($r['unit_count'] ?? 0);
        $unitLink = $uCnt > 0 
            ? '<a href="' . route_url('asset_items', ['type_id' => $r['asset_type_id']]) . '" class="badge ok" style="text-decoration:none;">' . $uCnt . ' Unit</a>' 
            : '<span class="muted">0 Unit</span>';
        $grpName = !empty($r['group_name']) ? ('<span class="badge" style="background:#e0f2fe;color:#0369a1;">' . e($r['group_code'] . ' - ' . $r['group_name']) . '</span>') : '-';

        echo '<tr>'
            . '<td>' . e($r['id']) . '</td>'
            . '<td>' . $grpName . '</td>'
            . '<td><strong>' . e($r['type_name']) . '</strong><br><span class="muted" style="font-size:11px;">' . e($r['type_code']) . '</span></td>'
            . '<td><strong>' . e($r['identifier_code']) . '</strong></td>'
            . '<td>' . e($r['identifier_name']) . '</td>'
            . '<td><code>' . e($r['data_type']) . '</code></td>'
            . '<td>' . (!empty($r['is_required']) ? '<span class="badge danger">Ya</span>' : 'Tidak') . '</td>'
            . '<td>' . (!empty($r['is_unique']) ? '<span class="badge ok">Ya</span>' : 'Tidak') . '</td>'
            . '<td>' . $unitLink . '</td>'
            . '<td>' . e($r['display_order']) . '</td>'
            . '<td><div class="actions" style="display:flex;gap:6px;align-items:center;"><a class="btn" href="' . route_url('asset_identifiers', ['id' => $r['id']]) . '">Edit</a> ' . $delBtn . '</div></td>'
            . '</tr>';
    }
    echo '</tbody></table></section>';
    render_footer();
}

function asset_specification_master_page(PDO $pdo, array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'delete') {
            $delId = (int)($_POST['id'] ?? 0);
            if ($delId > 0) {
                try {
                    $pdo->prepare('DELETE FROM asset_specifications WHERE asset_type_specification_id = ?')->execute([$delId]);
                    $pdo->prepare('DELETE FROM asset_type_specifications WHERE id = ?')->execute([$delId]);
                    flash('Spesifikasi Aset berhasil dihapus.');
                } catch (Throwable $e) {
                    flash('Gagal menghapus: ' . $e->getMessage(), 'err');
                }
            }
            redirect_to('asset_specifications');
        }

        $id = (int)($_POST['id'] ?? 0);
        $groupId = (int)($_POST['asset_group_id'] ?? 0);
        $type = (int)($_POST['asset_type_id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['specification_code'] ?? '')));
        $name = trim((string)($_POST['specification_name'] ?? ''));
        if (!$groupId || !$type || $code === '' || $name === '') {
            flash('Komoditas, Kategori, Kode Spesifikasi, dan Nama Spesifikasi wajib diisi.', 'err');
            redirect_to('asset_specifications', $id ? ['id' => $id] : []);
        }
        $p = [$type, $code, $name, $_POST['data_type'] ?? 'text', isset($_POST['is_required']) ? 1 : 0, isset($_POST['is_searchable']) ? 1 : 0, max(1, (int)($_POST['display_order'] ?? 1))];
        $sql = $id ? 'UPDATE asset_type_specifications SET asset_type_id=?,specification_code=?,specification_name=?,data_type=?,is_required=?,is_searchable=?,display_order=? WHERE id=?' : 'INSERT INTO asset_type_specifications(asset_type_id,specification_code,specification_name,data_type,is_required,is_searchable,display_order) VALUES (?,?,?,?,?,?,?)';
        if ($id) {
            $p[] = $id;
        }
        try {
            $pdo->prepare($sql)->execute($p);
            flash('Spesifikasi Aset berhasil disimpan.');
        } catch (Throwable $e) {
            flash('Gagal: ' . $e->getMessage(), 'err');
        }
        redirect_to('asset_specifications');
    }

    $edit = null;
    $editGroupId = 0;
    if (($editId = (int)($_GET['id'] ?? 0)) > 0) {
        $stmt = $pdo->prepare('SELECT ats.*, t.asset_group_id FROM asset_type_specifications ats JOIN asset_types t ON t.id=ats.asset_type_id WHERE ats.id = ?');
        $stmt->execute([$editId]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($edit) {
            $editGroupId = (int)$edit['asset_group_id'];
        }
    }

    render_header('Spesifikasi Aset', $user);
    echo asset_nav_html();
    echo '<section class="panel"><div class="split"><h1>' . ($edit ? 'Edit' : 'Tambah') . ' Spesifikasi Aset</h1>';
    if ($edit) {
        echo '<a class="btn" href="' . route_url('asset_specifications') . '">+ Tambah Spesifikasi Baru</a>';
    }
    echo '</div><form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '">';
    
    // Harus isi Komoditas dan Kategori terlebih dahulu
    echo '<div class="grid two">';
    echo '<label>Komoditas (Grup Aset) *<select id="specGroup" name="asset_group_id" onchange="onSpecGroupChanged()" required>' . asset_group_options($pdo, $editGroupId, true, '- Pilih Komoditas (Grup Aset) -') . '</select></label>';
    echo '<label>Kategori (Tipe Aset) *<select id="specType" name="asset_type_id" required>' . asset_type_options($pdo, (int)($edit['asset_type_id'] ?? 0), $editGroupId, true, $editGroupId ? '- Pilih Kategori -' : '- Pilih Komoditas Terlebih Dahulu -') . '</select></label>';
    echo '</div>';

    echo '<div class="grid two">';
    echo '<label>Kode Spesifikasi *<input name="specification_code" required value="' . e($edit['specification_code'] ?? '') . '" placeholder="Contoh: PROCESSOR, RAM, STORAGE, RESOLUSI, WARNA, CC"></label>';
    echo '<label>Nama Spesifikasi *<input name="specification_name" required value="' . e($edit['specification_name'] ?? '') . '" placeholder="Contoh: Processor / CPU, Kapasitas RAM, Tipe Storage, Kapasitas Mesin"></label>';
    echo '</div>';

    echo '<div class="grid four">';
    echo '<label>Tipe Data<select name="data_type">';
    $currDt = $edit['data_type'] ?? 'text';
    foreach (['text', 'number', 'date'] as $dt) {
        echo '<option value="' . $dt . '"' . ($currDt === $dt ? ' selected' : '') . '>' . $dt . '</option>';
    }
    echo '</select></label>';
    echo '<label><input style="width:auto" type="checkbox" name="is_required" ' . (!empty($edit['is_required']) ? 'checked' : '') . '> Wajib Diisi (Required)</label>';
    echo '<label><input style="width:auto" type="checkbox" name="is_searchable" ' . (!isset($edit['is_searchable']) || !empty($edit['is_searchable']) ? 'checked' : '') . '> Searchable</label>';
    echo '<label>Urutan Tampil<input type="number" name="display_order" value="' . (int)($edit['display_order'] ?? 1) . '" min="1"></label>';
    echo '</div>';

    echo '<div class="actions" style="margin-top:14px">';
    if ($edit) {
        echo '<a class="btn" href="' . route_url('asset_specifications') . '">Batal</a> ';
    }
    echo '<button class="btn primary">Simpan Spesifikasi</button>';
    echo '</div></form></section>';

    // JavaScript to dynamically populate Kategori when Komoditas changes
    echo '<script>
    window.onSpecGroupChanged = async function(){
        var specGroup = document.getElementById("specGroup");
        var specType = document.getElementById("specType");
        if(!specGroup || !specType) return;
        var gid = parseInt(specGroup.value, 10) || 0;
        if(gid === 0){
            specType.innerHTML = "<option value=\"\">- Pilih Komoditas Terlebih Dahulu -</option>";
            return;
        }
        specType.innerHTML = "<option value=\"\">Memuat Kategori...</option>";
        try {
            var r = await fetch("index.php?route=api_asset_types&group_id=" + gid);
            var types = await r.json();
            var h = "<option value=\"\">- Pilih Kategori -</option>";
            if(Array.isArray(types) && types.length > 0){
                types.forEach(function(t){
                    h += "<option value=\"" + t.id + "\">" + (t.type_code ? t.type_code + " - " : "") + t.type_name + "</option>";
                });
            } else {
                h = "<option value=\"\">- Belum ada kategori untuk komoditas ini -</option>";
            }
            specType.innerHTML = h;
        } catch(e){
            console.error("Gagal load kategori:", e);
        }
    };
    </script>';

    echo '<section class="panel"><h2>Daftar Spesifikasi Aset</h2><table><thead><tr><th>ID</th><th>Komoditas (Grup)</th><th>Kategori (Tipe)</th><th>Kode Spesifikasi</th><th>Nama Spesifikasi</th><th>Tipe Data</th><th>Required</th><th>Searchable</th><th>Total Unit Terisi</th><th>Order</th><th>Aksi</th></tr></thead><tbody>';

    $rows = $pdo->query('SELECT s.*, t.type_name, t.type_code, ag.group_name, ag.group_code,
        (SELECT COUNT(DISTINCT asp.asset_item_id) FROM asset_specifications asp WHERE asp.asset_type_specification_id = s.id AND asp.specification_value != "") AS unit_count
        FROM asset_type_specifications s 
        JOIN asset_types t ON t.id=s.asset_type_id 
        LEFT JOIN asset_groups ag ON ag.id=t.asset_group_id
        ORDER BY ag.group_name ASC, t.type_name ASC, s.display_order ASC')->fetchAll();

    foreach ($rows as $r) {
        $delBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus Spesifikasi ini?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn danger" style="padding:4px 8px;font-size:12px">Hapus</button></form>';
        $uCnt = (int)($r['unit_count'] ?? 0);
        $unitLink = $uCnt > 0 
            ? '<a href="' . route_url('asset_items', ['type_id' => $r['asset_type_id']]) . '" class="badge ok" style="text-decoration:none;">' . $uCnt . ' Unit</a>' 
            : '<span class="muted">0 Unit</span>';
        $grpName = !empty($r['group_name']) ? ('<span class="badge" style="background:#e0f2fe;color:#0369a1;">' . e($r['group_code'] . ' - ' . $r['group_name']) . '</span>') : '-';

        echo '<tr>'
            . '<td>' . e($r['id']) . '</td>'
            . '<td>' . $grpName . '</td>'
            . '<td><strong>' . e($r['type_name']) . '</strong><br><span class="muted" style="font-size:11px;">' . e($r['type_code']) . '</span></td>'
            . '<td><strong>' . e($r['specification_code']) . '</strong></td>'
            . '<td>' . e($r['specification_name']) . '</td>'
            . '<td><code>' . e($r['data_type']) . '</code></td>'
            . '<td>' . (!empty($r['is_required']) ? '<span class="badge danger">Ya</span>' : 'Tidak') . '</td>'
            . '<td>' . (!empty($r['is_searchable']) ? '<span class="badge ok">Ya</span>' : 'Tidak') . '</td>'
            . '<td>' . $unitLink . '</td>'
            . '<td>' . e($r['display_order']) . '</td>'
            . '<td><div class="actions" style="display:flex;gap:6px;align-items:center;"><a class="btn" href="' . route_url('asset_specifications', ['id' => $r['id']]) . '">Edit</a> ' . $delBtn . '</div></td>'
            . '</tr>';
    }
    echo '</tbody></table></section>';
    render_footer();
}

function asset_maintenance_template_page(PDO $pdo, array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'delete') {
            $delId = (int)($_POST['id'] ?? 0);
            if ($delId > 0) {
                try {
                    $pdo->prepare('DELETE FROM asset_maintenance_templates WHERE id = ?')->execute([$delId]);
                    flash('Template maintenance berhasil dihapus.');
                } catch (Throwable $e) {
                    flash('Gagal menghapus: ' . $e->getMessage(), 'err');
                }
            }
            redirect_to('asset_maintenance_templates');
        }

        $p = [(int)($_POST['asset_type_id'] ?? 0), trim((string)($_POST['template_name'] ?? '')), (string)($_POST['frequency'] ?? 'monthly'), max(1, (int)($_POST['interval_value'] ?? 1)), (string)($_POST['meter_type'] ?? 'calendar')];
        if (!$p[0] || $p[1] === '') {
            flash('Tipe Asset dan nama template wajib diisi.', 'err');
            redirect_to('asset_maintenance_templates');
        }
        try {
            $pdo->prepare('INSERT INTO asset_maintenance_templates(asset_type_id,template_name,frequency,interval_value,meter_type) VALUES (?,?,?,?,?)')->execute($p);
            flash('Template maintenance tersimpan.');
        } catch (Throwable $e) {
            flash('Gagal: ' . $e->getMessage(), 'err');
        }
        redirect_to('asset_maintenance_templates');
    }
    render_header('Template Maintenance Reguler', $user);
    echo '<section class="panel"><h1>Template Maintenance Reguler</h1><p class="muted">Template ini disiapkan per Tipe Asset.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid four"><label>Tipe Asset<select name="asset_type_id" required>' . asset_type_options($pdo) . '</select></label><label>Nama Template<input name="template_name" required placeholder="Quarterly Inspection"></label><label>Frekuensi<select name="frequency"><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option></select></label><label>Basis<select name="meter_type"><option value="calendar">Calendar</option><option value="kilometer">Kilometer</option><option value="hour">Hour Meter</option></select></label></div><label>Interval<input type="number" name="interval_value" value="1" min="1"></label><button class="btn primary">Simpan Template</button></form></section><section class="panel"><table><tr><th>Tipe Asset</th><th>Template</th><th>Frekuensi</th><th>Basis</th><th>Aksi</th></tr>';
    foreach ($pdo->query('SELECT m.*,t.type_name FROM asset_maintenance_templates m JOIN asset_types t ON t.id=m.asset_type_id ORDER BY t.type_name,m.template_name') as $r) {
        $delBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus Template ini?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn danger" style="padding:4px 8px;font-size:12px">Hapus</button></form>';
        echo '<tr><td>' . e($r['type_name']) . '</td><td>' . e($r['template_name']) . '</td><td>' . e($r['frequency'] . ' / ' . $r['interval_value']) . '</td><td>' . e($r['meter_type']) . '</td><td>' . $delBtn . '</td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function employee_source_defaults(): array
{
    return [
        'enabled' => '0',
        'host' => '',
        'port' => '3306',
        'database' => '',
        'username' => '',
        'password' => '',
        'table_name' => 'employees',
        'nik_field' => 'NIK',
        'name_field' => 'Nama',
        'department_field' => '',
        'limit_rows' => '500',
    ];
}

function employee_source_config(PDO $pdo): array
{
    $config = employee_source_defaults();
    try {
        if (!db_table_exists($pdo, 'employee_source_config')) {
            return $config;
        }
        foreach ($pdo->query('SELECT config_key, config_value FROM employee_source_config') as $row) {
            $key = (string)$row['config_key'];
            if (array_key_exists($key, $config)) {
                $config[$key] = (string)($row['config_value'] ?? '');
            }
        }
    } catch (Throwable $ignored) {
    }
    return $config;
}

function save_employee_source_config(PDO $pdo, array $config): void
{
    ensure_employee_source_schema($pdo);
    $allowed = employee_source_defaults();
    $stmt = $pdo->prepare('REPLACE INTO employee_source_config (config_key, config_value) VALUES (?, ?)');
    foreach ($allowed as $key => $default) {
        $stmt->execute([$key, (string)($config[$key] ?? $default)]);
    }
}

function mysql_identifier(string $identifier): string
{
    $identifier = trim($identifier);
    if (!preg_match('/^[A-Za-z0-9_ -]+(\.[A-Za-z0-9_ -]+)?$/', $identifier)) {
        throw new RuntimeException('Nama table/field MariaDB tidak valid: ' . $identifier);
    }
    $parts = explode('.', $identifier);
    return implode('.', array_map(static fn(string $part): string => '`' . str_replace('`', '``', $part) . '`', $parts));
}

function employee_portal_connection_from_config(array $config): PDO
{
    $host = trim((string)($config['host'] ?? ''));
    $database = trim((string)($config['database'] ?? ''));
    if ($host === '' || $database === '') {
        throw new RuntimeException('Host/IP dan database MariaDB portal wajib diisi.');
    }
    $port = (int)($config['port'] ?? 3306);
    $dsn = 'mysql:host=' . $host . ';port=' . ($port > 0 ? $port : 3306) . ';dbname=' . $database . ';charset=utf8mb4';
    return new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

function normalize_employee_rows(array $rows): array
{
    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $nik = trim((string)($row['nik'] ?? $row['NIK'] ?? ''));
        $name = trim((string)($row['name'] ?? $row['Nama'] ?? $row['employee_name'] ?? ''));
        if ($nik === '' || $name === '') {
            continue;
        }
        $normalized[] = [
            'nik' => $nik,
            'name' => $name,
            'department' => trim((string)($row['department'] ?? $row['Department'] ?? '')),
        ];
    }
    return $normalized;
}

function fetch_employee_portal_rows(array $config, int $limit): array
{
    $remote = employee_portal_connection_from_config($config);
    $limit = max(1, min(50000, $limit));
    $table = mysql_identifier((string)($config['table_name'] ?: 'employees'));
    $nikField = mysql_identifier((string)($config['nik_field'] ?: 'NIK'));
    $nameField = mysql_identifier((string)($config['name_field'] ?: 'Nama'));
    $departmentFieldRaw = trim((string)($config['department_field'] ?? ''));
    $departmentSelect = $departmentFieldRaw !== '' ? ', CAST(' . mysql_identifier($departmentFieldRaw) . ' AS CHAR) AS department' : ", CAST('' AS CHAR) AS department";
    $sql = 'SELECT CAST(' . $nikField . ' AS CHAR) AS nik, CAST(' . $nameField . ' AS CHAR) AS name' . $departmentSelect . ' FROM ' . $table . ' WHERE ' . $nikField . ' IS NOT NULL AND ' . $nameField . ' IS NOT NULL ORDER BY ' . $nameField . ' LIMIT ' . (int)$limit;
    return normalize_employee_rows($remote->query($sql)->fetchAll());
}

function import_employee_directory(PDO $appPdo): int
{
    ensure_employee_source_schema($appPdo);
    $config = employee_source_config($appPdo);
    $limit = max(1, min(50000, (int)($config['limit_rows'] ?? 5000)));
    $rows = fetch_employee_portal_rows($config, $limit);
    if (!$rows) {
        return 0;
    }
    $appPdo->beginTransaction();
    try {
        $hasName = db_column_exists($appPdo, 'employee_directory', 'name');
        $hasEmpName = db_column_exists($appPdo, 'employee_directory', 'employee_name');

        if ($hasName && $hasEmpName) {
            $stmt = $appPdo->prepare('REPLACE INTO employee_directory (nik, name, employee_name, department, is_active, synced_at) VALUES (?, ?, ?, ?, 1, NOW())');
            foreach ($rows as $row) {
                $stmt->execute([$row['nik'], $row['name'], $row['name'], $row['department']]);
            }
        } elseif ($hasEmpName) {
            $stmt = $appPdo->prepare('REPLACE INTO employee_directory (nik, employee_name, department, is_active, synced_at) VALUES (?, ?, ?, 1, NOW())');
            foreach ($rows as $row) {
                $stmt->execute([$row['nik'], $row['name'], $row['department']]);
            }
        } else {
            $stmt = $appPdo->prepare('REPLACE INTO employee_directory (nik, name, department, is_active, synced_at) VALUES (?, ?, ?, 1, NOW())');
            foreach ($rows as $row) {
                $stmt->execute([$row['nik'], $row['name'], $row['department']]);
            }
        }
        $appPdo->commit();
    } catch (Throwable $e) {
        if ($appPdo->inTransaction()) {
            $appPdo->rollBack();
        }
        throw $e;
    }
    return count($rows);
}

function sync_employee_directory(PDO $appPdo): int
{
    return import_employee_directory($appPdo);
}

function employee_source_form_html(PDO $pdo): string
{
    $config = employee_source_config($pdo);
    $cacheCount = 0;
    $lastSync = '-';
    $localRows = [];
    try {
        ensure_employee_source_schema($pdo);
        $cacheCount = (int)$pdo->query('SELECT COUNT(*) FROM employee_directory WHERE is_active = 1')->fetchColumn();
        $lastSyncValue = $pdo->query('SELECT MAX(synced_at) FROM employee_directory')->fetchColumn();
        $lastSync = $lastSyncValue ? (string)$lastSyncValue : '-';

        $hasName = db_column_exists($pdo, 'employee_directory', 'name');
        $nameExpr = $hasName ? "COALESCE(NULLIF(name, ''), employee_name, '')" : "employee_name";
        $localStmt = $pdo->query("SELECT nik, $nameExpr AS name, department, synced_at FROM employee_directory WHERE is_active = 1 ORDER BY name ASC LIMIT 100");
        $localRows = $localStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {
    }

    $portalTarget = (!empty($config['host']) && !empty($config['database']))
        ? (e($config['host']) . ' / ' . e($config['database']))
        : 'Belum diatur';

    $html = '<section class="panel"><h1>Import Data Karyawan dari MariaDB Portal ke Database Lokal</h1><p class="muted">Fitur ini mengimpor seluruh master karyawan dari server MariaDB portal dan menyimpannya ke <strong>database lokal PcConnect</strong> (tabel <code>employee_directory</code>). Saat menambah atau mengedit <strong>PC</strong> dan <strong>Asset Item</strong>, form akan mengambil data pemakai/karyawan dari database lokal ini secara cepat dan aman.</p><div class="grid three"><div class="stat"><strong style="color:#0284c7;">' . e((string)$cacheCount) . '</strong><span>Karyawan di Database Lokal</span></div><div class="stat"><strong>' . e($lastSync) . '</strong><span>Terakhir Di-import</span></div><div class="stat"><strong style="font-size:15px;">' . $portalTarget . '</strong><span>Sumber MariaDB Portal</span></div></div></section>';

    $html .= '<section class="panel"><h2>1. Konfigurasi Koneksi MariaDB Portal</h2><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="enabled" value="1"><div class="grid three">';
    $html .= '<label>IP / Host MariaDB Portal<input name="host" value="' . e($config['host']) . '" placeholder="Contoh: 192.168.1.10 atau portal.domain.com"></label>';
    $html .= '<label>Port<input name="port" value="' . e($config['port'] ?: '3306') . '" placeholder="3306"></label>';
    $html .= '<label>Database<input name="database" value="' . e($config['database']) . '" placeholder="Contoh: portal_hrd"></label>';
    $html .= '<label>Username<input name="username" value="' . e($config['username']) . '" placeholder="Contoh: user_mariadb"></label>';
    $html .= '<label>Password<input type="password" name="password" placeholder="Kosongkan jika tidak diubah"></label>';
    $html .= '<label>Maksimal Baris Import<input type="number" min="1" max="50000" name="limit_rows" value="' . e($config['limit_rows'] ?: '5000') . '"></label></div>';
    $html .= '<h2>2. Pemetaan Field Tabel MariaDB</h2><div class="grid four">';
    $html .= '<label>Table / View Karyawan<input name="table_name" value="' . e($config['table_name'] ?: 'employees') . '" placeholder="Contoh: employees"></label>';
    $html .= '<label>Field NIK<input name="nik_field" value="' . e($config['nik_field'] ?: 'NIK') . '" placeholder="Contoh: NIK"></label>';
    $html .= '<label>Field Nama Karyawan<input name="name_field" value="' . e($config['name_field'] ?: 'Nama') . '" placeholder="Contoh: Nama"></label>';
    $html .= '<label>Field Departemen<input name="department_field" value="' . e($config['department_field']) . '" placeholder="Contoh: Department"></label></div>';
    $html .= '<div class="actions" style="margin-top:16px;">';
    $html .= '<button class="btn ok" style="font-weight:700;padding:10px 20px;font-size:14px;" name="action" value="import">📥 Import Data Karyawan ke Database Lokal</button>';
    $html .= '<button class="btn" name="action" value="test">🔌 Test Koneksi MariaDB</button>';
    $html .= '<button class="btn primary" name="action" value="save">💾 Simpan Konfigurasi</button>';
    if ($cacheCount > 0) {
        $html .= '<button class="btn danger" name="action" value="truncate_local" onclick="return confirm(\'Yakin ingin mengosongkan seluruh data karyawan di database lokal?\');">🗑️ Kosongkan Database Lokal</button>';
    }
    $html .= '</div></form></section>';

    $html .= '<section class="panel"><div class="split"><div><h2>Daftar Karyawan di Database Lokal PcConnect</h2><p class="muted">Data berikut tersimpan secara lokal dan otomatis muncul saat memilih Pengguna di form PC dan Asset Item.</p></div>';
    if ($cacheCount > 100) {
        $html .= '<span class="badge">Menampilkan 100 dari ' . (int)$cacheCount . ' data</span>';
    }
    $html .= '</div>';

    if (!$localRows) {
        $html .= '<div style="background:#fffbeb;border:1px solid #fde68a;padding:14px;border-radius:8px;color:#92400e;text-align:center;">Database lokal masih kosong. Lengkapi form koneksi MariaDB di atas lalu klik tombol <strong>📥 Import Data Karyawan ke Database Lokal</strong>.</div>';
    } else {
        $html .= '<table><tr><th>No</th><th>NIK</th><th>Nama Karyawan</th><th>Departemen</th><th>Waktu Terakhir Di-import</th></tr>';
        $no = 1;
        foreach ($localRows as $lr) {
            $html .= '<tr><td>' . $no++ . '</td><td><strong>' . e($lr['nik']) . '</strong></td><td>' . e($lr['name']) . '</td><td>' . e($lr['department'] ?: '-') . '</td><td><span class="muted">' . e($lr['synced_at'] ?: '-') . '</span></td></tr>';
        }
        $html .= '</table>';
    }
    $html .= '</section>';

    return $html;
}

function company_source_defaults(): array
{
    return [
        'enabled' => '0',
        'mode' => 'bridge',
        'bridge_url' => '',
        'bridge_token' => '',
        'host' => '',
        'port' => '1433',
        'database' => '',
        'username' => '',
        'password' => '',
        'table_name' => 'dbo.Company',
        'id_field' => 'id',
        'name_field' => 'nama_unit_usaha',
        'limit_rows' => '500',
        'trust_server_certificate' => '1',
    ];
}

function company_source_config(PDO $pdo): array
{
    $config = company_source_defaults();
    try {
        ensure_company_source_schema($pdo);
        foreach ($pdo->query('SELECT config_key, config_value FROM company_source_config') as $row) {
            $key = (string)$row['config_key'];
            if (array_key_exists($key, $config)) {
                $config[$key] = (string)($row['config_value'] ?? '');
            }
        }
    } catch (Throwable $ignored) {
    }
    return $config;
}

function save_company_source_config(PDO $pdo, array $config): void
{
    ensure_company_source_schema($pdo);
    $allowed = company_source_defaults();
    $stmt = $pdo->prepare('REPLACE INTO company_source_config (config_key, config_value) VALUES (?, ?)');
    foreach ($allowed as $key => $default) {
        $stmt->execute([$key, (string)($config[$key] ?? $default)]);
    }
}

function company_source_form_html(PDO $pdo): string
{
    $config = company_source_config($pdo);
    $html = '<section class="panel"><h1>Setup Sumber Company (SQL Server / Bridge)</h1><p class="muted">Dipakai untuk sinkronisasi master company dari unit usaha ERP.</p></section>';
    $html .= '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="module" value="company_source"><label><input type="checkbox" name="enabled" value="1" ' . (($config['enabled'] ?? '0') === '1' ? 'checked' : '') . ' style="width:auto"> Aktifkan sinkronisasi company</label><div class="grid two"><label>Mode<select name="mode"><option value="bridge"' . (($config['mode'] ?? 'bridge') === 'bridge' ? ' selected' : '') . '>Bridge HTTP / Webhook</option><option value="direct"' . (($config['mode'] ?? 'bridge') === 'direct' ? ' selected' : '') . '>Direct SQL Server (pdo_sqlsrv)</option></select></label><label>Bridge URL<input name="bridge_url" value="' . e($config['bridge_url']) . '" placeholder="http://192.168.1.xxx:8080/api/companies"></label></div><div class="actions"><button class="btn primary">Simpan Company Source</button></div></form></section>';
    return $html;
}

function handle_route_asset_dashboard(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('Manajemen Aset', $user);
    echo asset_nav_html();
    $stats = [
        'company' => (int)$pdo->query('SELECT COUNT(*) FROM asset_companies')->fetchColumn(),
        'item' => (int)$pdo->query('SELECT COUNT(*) FROM asset_items')->fetchColumn(),
        'maintenance' => (int)$pdo->query('SELECT COUNT(*) FROM maintenance_assets')->fetchColumn(),
        'repair' => (int)$pdo->query('SELECT COUNT(*) FROM asset_repairs')->fetchColumn(),
    ];
    echo '<section class="panel"><div class="split"><div><h1>Manajemen Aset</h1><p class="muted">Layer identitas aset fisik, company holding, Maintenance Asset ID/QR, mutasi, dan repair history.</p></div></div><div class="grid four"><div class="stat"><strong>' . e($stats['company']) . '</strong><span>Company</span></div><div class="stat"><strong>' . e($stats['item']) . '</strong><span>Unit Aset</span></div><div class="stat"><strong>' . e($stats['maintenance']) . '</strong><span>Maintenance Assets</span></div><div class="stat"><strong>' . e($stats['repair']) . '</strong><span>Repair Records</span></div></div></section>';
    render_footer();
}

function handle_route_asset_management_cleanup(PDO $pdo): void
{
    $user = require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $confirm = trim((string)($_POST['confirm_text'] ?? ''));
        if ($confirm !== 'BERSIHKAN') {
            flash('Ketik BERSIHKAN untuk mengkonfirmasi pembersihan manajemen aset.', 'err');
            redirect_to('asset_management_cleanup');
        }
        cleanup_asset_management_data($pdo);
        flash('Data manajemen aset berhasil dibersihkan. Data PC/printer tetap aman.', 'good');
        redirect_to('asset_dashboard');
    }
    render_header('Bersihkan Manajemen Aset', $user);
    echo asset_nav_html();
    echo '<section class="panel"><h1>Bersihkan Manajemen Aset</h1><p class="muted">Ini akan menghapus seluruh data manajemen aset. Data PC/printer dan table utama tetap utuh.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><label>Ketik <strong>BERSIHKAN</strong> untuk konfirmasi<input name="confirm_text" required></label><div class="actions"><button class="btn danger">Bersihkan Manajemen Aset</button><a class="btn" href="' . route_url('asset_dashboard') . '">Batal</a></div></form></section>';
    render_footer();
}

function handle_route_asset_companies(PDO $pdo): void
{
    $user = require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['company_code'] ?? '')));
        $name = trim((string)($_POST['company_name'] ?? ''));
        if ($code === '' || $name === '') {
            flash('Company code dan company name wajib diisi.', 'err');
            redirect_to('asset_companies');
        }
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE asset_companies SET company_code=?, company_name=?, legal_name=?, address=?, is_active=? WHERE id=?')->execute([$code, $name, trim((string)($_POST['legal_name'] ?? '')), trim((string)($_POST['address'] ?? '')), isset($_POST['is_active']) ? 1 : 0, $id]);
                flash('Company berhasil diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO asset_companies (company_code, company_name, legal_name, address) VALUES (?, ?, ?, ?)')->execute([$code, $name, trim((string)($_POST['legal_name'] ?? '')), trim((string)($_POST['address'] ?? ''))]);
                flash('Company berhasil ditambahkan.');
            }
        } catch (Throwable $e) {
            flash('Gagal simpan company: ' . $e->getMessage(), 'err');
        }
        redirect_to('asset_companies');
    }
    render_header('Company Asset', $user);
    echo asset_nav_html();
    $edit = null;
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM asset_companies WHERE id=?');
        $stmt->execute([(int)$_GET['id']]);
        $edit = $stmt->fetch() ?: null;
    }
    echo asset_company_form_html($edit);
    echo '<section class="panel"><h2>Daftar Company</h2><table><tr><th>Kode</th><th>Company</th><th>Legal Name</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($pdo->query('SELECT * FROM asset_companies ORDER BY company_name') as $row) {
        echo '<tr><td>' . e($row['company_code']) . '</td><td>' . e($row['company_name']) . '</td><td>' . e($row['legal_name'] ?: '-') . '</td><td>' . ((int)$row['is_active'] ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td><td><a class="btn" href="' . route_url('asset_companies', ['id' => $row['id']]) . '">Edit</a></td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_asset_categories(PDO $pdo): void
{
    $user = require_role(['admin']);
    ensure_asset_management_schema($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM asset_categories WHERE id=?');
            $stmt->execute([$id]);
            $cat = $stmt->fetch();
            if (!$cat) {
                flash('Kategori tidak ditemukan.', 'err');
            } elseif ((int)$cat['is_system'] === 1) {
                flash('Kategori sistem tidak bisa dihapus.', 'err');
            } else {
                $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM asset_items WHERE asset_category=?');
                $stmt2->execute([$cat['category_name']]);
                $used = (int)$stmt2->fetchColumn();
                if ($used > 0) {
                    flash('Kategori "' . $cat['category_name'] . '" masih dipakai ' . $used . ' asset item.', 'err');
                } else {
                    $pdo->prepare('DELETE FROM asset_categories WHERE id=?')->execute([$id]);
                    flash('Kategori "' . $cat['category_name'] . '" berhasil dihapus.');
                }
            }
            redirect_to('asset_categories');
        }
        $id = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim((string)($_POST['category_name'] ?? '')), 0, 60);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            flash('Nama kategori wajib diisi.', 'err');
            redirect_to('asset_categories', $id > 0 ? ['id' => $id] : []);
        }
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT * FROM asset_categories WHERE id=?');
                $stmt->execute([$id]);
                $existing = $stmt->fetch();
                if (!$existing) {
                    flash('Kategori tidak ditemukan.', 'err');
                    redirect_to('asset_categories');
                }
                $stmt = $pdo->prepare('SELECT id FROM asset_categories WHERE category_name=? AND id<>?');
                $stmt->execute([$name, $id]);
                if ($stmt->fetch()) {
                    flash('Nama kategori sudah ada.', 'err');
                    redirect_to('asset_categories', ['id' => $id]);
                }
                $oldName = (string)$existing['category_name'];
                $pdo->prepare('UPDATE asset_categories SET category_name=?, is_active=? WHERE id=?')->execute([$name, $isActive, $id]);
                if ($oldName !== $name) {
                    $pdo->prepare('UPDATE asset_items SET asset_category=? WHERE asset_category=?')->execute([$name, $oldName]);
                }
                flash('Kategori berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare('SELECT id FROM asset_categories WHERE category_name=?');
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    flash('Nama kategori sudah ada.', 'err');
                    redirect_to('asset_categories');
                }
                $pdo->prepare('INSERT INTO asset_categories (category_name, is_system, is_active) VALUES (?, 0, ?)')->execute([$name, $isActive]);
                flash('Kategori "' . $name . '" berhasil ditambahkan.');
            }
        } catch (Throwable $e) {
            flash('Gagal simpan kategori: ' . $e->getMessage(), 'err');
        }
        redirect_to('asset_categories');
    }
    render_header('Kategori Asset', $user);
    echo asset_nav_html();
    $edit = null;
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM asset_categories WHERE id=?');
        $stmt->execute([(int)$_GET['id']]);
        $edit = $stmt->fetch() ?: null;
    }
    echo asset_category_form_html($edit);
    echo '<section class="panel"><h2>Daftar Kategori</h2><table><tr><th>Nama Kategori</th><th>Sistem</th><th>Status</th><th>Pemakaian</th><th>Aksi</th></tr>';
    foreach ($pdo->query('SELECT ac.*, (SELECT COUNT(*) FROM asset_items ai WHERE ai.asset_category=ac.category_name) usage_count FROM asset_categories ac ORDER BY ac.is_system DESC, ac.category_name') as $row) {
        $sysBadge = (int)$row['is_system'] ? '<span class="badge ok">Sistem</span>' : '<span class="badge">Custom</span>';
        $activeBadge = (int)$row['is_active'] ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>';
        echo '<tr><td><strong>' . e($row['category_name']) . '</strong></td><td>' . $sysBadge . '</td><td>' . $activeBadge . '</td><td>' . e($row['usage_count']) . ' item</td><td><div class="actions"><a class="btn" href="' . route_url('asset_categories', ['id' => $row['id']]) . '">Edit</a>';
        if ((int)$row['is_system'] !== 1) {
            echo '<form method="post" onsubmit="return confirm(\'Hapus kategori ' . e($row['category_name']) . '?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn danger">Hapus</button></form>';
        }
        echo '</div></td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_asset_master_items(PDO $pdo): void
{
    $user = require_role(['admin']);
    
    // Handle Save / Delete POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // Check if physical asset items exist
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM asset_items WHERE master_item_id = ?");
                $stmtCheck->execute([$id]);
                $count = (int)$stmtCheck->fetchColumn();
                if ($count > 0) {
                    flash("Tidak dapat menghapus Master Barang karena terdapat {$count} unit aset fisik yang terdaftar pada katalog ini.", 'err');
                } else {
                    $pdo->prepare("DELETE FROM asset_master_items WHERE id = ?")->execute([$id]);
                    flash("Master Barang berhasil dihapus.");
                }
            }
            redirect_to('asset_master_items');
        }

        $id = (int)($_POST['id'] ?? 0);
        $groupId = (int)($_POST['asset_group_id'] ?? 0);
        $typeId = (int)($_POST['asset_type_id'] ?? 0);
        $brandId = (int)($_POST['brand_id'] ?? 0) ?: null;
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $itemCode = strtoupper(trim((string)($_POST['item_code'] ?? '')));
        $modelName = trim((string)($_POST['model_name'] ?? ''));
        $specificationsRaw = $_POST['specifications'] ?? [];
        if (is_array($specificationsRaw)) {
            $cleanSpecs = [];
            foreach ($specificationsRaw as $k => $v) {
                $cleanSpecs[(int)$k] = trim((string)$v);
            }
            $specifications = json_encode($cleanSpecs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $specifications = trim((string)$specificationsRaw);
        }
        $description = trim((string)($_POST['description'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!$groupId || !$typeId || $itemName === '') {
            flash('Komoditas, Kategori, dan Nama Barang wajib diisi.', 'err');
            redirect_to('asset_master_items', $id ? ['id' => $id] : []);
        }

        if ($itemCode === '') {
            $gCode = (string)$pdo->query("SELECT group_code FROM asset_groups WHERE id = {$groupId}")->fetchColumn() ?: 'GEN';
            $tCode = (string)$pdo->query("SELECT type_code FROM asset_types WHERE id = {$typeId}")->fetchColumn() ?: 'ITEM';
            $bCode = $brandId ? ((string)$pdo->query("SELECT brand_code FROM asset_brands WHERE id = {$brandId}")->fetchColumn() ?: 'BRD') : 'GEN';
            $nextSeq = (int)$pdo->query("SELECT COUNT(*) + 1 FROM asset_master_items WHERE asset_group_id = {$groupId} AND asset_type_id = {$typeId}")->fetchColumn();
            $itemCode = strtoupper($gCode . '-' . $tCode . '-' . $bCode . '-' . str_pad((string)$nextSeq, 3, '0', STR_PAD_LEFT));
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE asset_master_items SET item_code=?, item_name=?, asset_group_id=?, asset_type_id=?, brand_id=?, model_name=?, specifications=?, description=?, is_active=? WHERE id=?");
                $stmt->execute([$itemCode, $itemName, $groupId, $typeId, $brandId, $modelName, $specifications, $description, $isActive, $id]);
                flash('Master Barang berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO asset_master_items (item_code, item_name, asset_group_id, asset_type_id, brand_id, model_name, specifications, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$itemCode, $itemName, $groupId, $typeId, $brandId, $modelName, $specifications, $description, $isActive]);
                flash('Master Barang baru berhasil ditambahkan.');
            }
        } catch (Throwable $e) {
            flash('Gagal simpan: ' . $e->getMessage(), 'err');
        }
        redirect_to('asset_master_items');
    }

    $editId = (int)($_GET['id'] ?? 0);
    $edit = null;
    $existingMiSpecs = [];
    if ($editId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM asset_master_items WHERE id = ?");
        $stmt->execute([$editId]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($edit && !empty($edit['specifications'])) {
            $existingMiSpecs = json_decode((string)$edit['specifications'], true) ?: [];
        }
    }

    // Filter params
    $filterGroup = (int)($_GET['group_id'] ?? 0);
    $filterType = (int)($_GET['type_id'] ?? 0);
    $filterBrand = (int)($_GET['brand_id'] ?? 0);
    $filterQ = trim((string)($_GET['q'] ?? ''));

    $where = ["1=1"];
    $params = [];
    if ($filterGroup > 0) {
        $where[] = "ami.asset_group_id = ?";
        $params[] = $filterGroup;
    }
    if ($filterType > 0) {
        $where[] = "ami.asset_type_id = ?";
        $params[] = $filterType;
    }
    if ($filterBrand > 0) {
        $where[] = "ami.brand_id = ?";
        $params[] = $filterBrand;
    }
    if ($filterQ !== '') {
        $where[] = "(ami.item_code LIKE ? OR ami.item_name LIKE ? OR ami.model_name LIKE ?)";
        $params[] = "%{$filterQ}%";
        $params[] = "%{$filterQ}%";
        $params[] = "%{$filterQ}%";
    }

    $sql = "SELECT ami.*, ag.group_name, ag.group_code, at.type_name, at.type_code, ab.brand_name,
            (SELECT COUNT(*) FROM asset_items ai WHERE ai.master_item_id = ami.id) AS total_units,
            (SELECT COUNT(*) FROM asset_items ai WHERE ai.master_item_id = ami.id AND ai.status = 'active') AS active_units
            FROM asset_master_items ami
            LEFT JOIN asset_groups ag ON ag.id = ami.asset_group_id
            LEFT JOIN asset_types at ON at.id = ami.asset_type_id
            LEFT JOIN asset_brands ab ON ab.id = ami.brand_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY ag.group_name ASC, at.type_name ASC, ami.item_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    render_header('Master Barang (Katalog Model)', $user);
    echo asset_nav_html();

    // Form Section
    echo '<section class="panel">';
    echo '<div class="split" style="align-items:center;"><div><h1 style="margin:0;">' . ($edit ? 'Edit Master Barang' : 'Tambah Master Barang (Katalog SKU)') . '</h1></div>';
    echo '<div style="display:flex;gap:8px;">';
    echo '<button type="button" class="btn warning" id="btnMiImportPc" onclick="openMiImportPcModal()" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;"><span style="font-size:16px;">📥</span> Import dari Data PC</button>';
    if ($edit) {
        echo '<a class="btn" href="' . route_url('asset_master_items') . '">+ Tambah Barang Baru</a>';
    }
    echo '</div></div>';

    echo '<form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '">';
    echo '<div class="grid three">';
    echo '<label>Komoditas (Grup Aset) *<select id="miGroup" name="asset_group_id" onchange="onMiGroupChanged()" required>' . asset_group_options($pdo, (int)($edit['asset_group_id'] ?? 0), true, '- Pilih Komoditas -') . '</select></label>';
    echo '<label>Kategori (Tipe Aset) *<select id="miType" name="asset_type_id" onchange="onMiTypeChanged()" required>' . asset_type_options($pdo, (int)($edit['asset_type_id'] ?? 0), (int)($edit['asset_group_id'] ?? 0)) . '</select></label>';
    echo '<label>Brand / Merk<select id="miBrandId" name="brand_id">' . asset_brand_options($pdo, (int)($edit['brand_id'] ?? 0)) . '</select></label>';
    echo '</div>';
    echo '<div class="grid three">';
    echo '<label>Kode Barang / SKU<input id="miItemCode" name="item_code" value="' . e($edit['item_code'] ?? '') . '" placeholder="Auto jika dikosongkan (contoh: IT-LPT-LEN-001)"></label>';
    echo '<label>Nama Barang / Model Lengkap *<input id="miItemName" name="item_name" required value="' . e($edit['item_name'] ?? '') . '" placeholder="Contoh: Lenovo ThinkPad T480 Core i5 8GB 256GB"></label>';
    echo '<label>Model / Varian Spesifik<input id="miModelName" name="model_name" value="' . e($edit['model_name'] ?? '') . '" placeholder="Contoh: ThinkPad T480 / OptiPlex 3080"></label>';
    echo '</div>';

    // Dynamic Category Specifications container
    echo '<div style="margin-top:14px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">';
    echo '<h3 style="margin:0 0 10px 0;font-size:14px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:6px;"><span>⚙️ Spesifikasi Standar Master Barang (Sesuai Komoditas & Kategori)</span></h3>';
    echo '<div id="miSpecificationFields" class="grid three"><div class="muted" style="grid-column:1/-1;font-size:13px;padding:4px 0;">ℹ️ Pilih Komoditas dan Kategori di atas untuk memuat kolom spesifikasi standar.</div></div>';
    echo '</div>';

    echo '<div class="grid one" style="margin-top:12px;">';
    echo '<label>Deskripsi / Keterangan Katalog<textarea name="description" placeholder="Catatan kegunaan, part pengganti, atau informasi garansi vendor" style="min-height:50px;">' . e($edit['description'] ?? '') . '</textarea></label>';
    echo '</div>';

    echo '<div class="split" style="margin-top:8px">';
    echo '<label style="margin:0"><input style="width:auto" type="checkbox" name="is_active" ' . ((!$edit || (int)$edit['is_active']) ? 'checked' : '') . '> Aktif (Tampil di pemilihan unit aset fisik)</label>';
    echo '<div class="actions">';
    if ($edit) {
        echo '<a class="btn" href="' . route_url('asset_master_items') . '">Batal</a>';
    }
    echo '<button class="btn primary">Simpan Master Barang</button>';
    echo '</div>';
    echo '</div>';
    echo '</form></section>';

    // List & Filter Section
    echo '<section class="panel">';
    echo '<div class="split"><h2>Daftar Katalog Master Barang (' . count($items) . ')</h2>';
    echo '<form method="get" class="actions" style="margin:0">';
    echo '<input type="hidden" name="route" value="asset_master_items">';
    echo '<select name="group_id" onchange="this.form.submit()">' . asset_group_options($pdo, $filterGroup, true, 'Semua Komoditas') . '</select>';
    echo '<select name="brand_id" onchange="this.form.submit()">' . asset_brand_options($pdo, $filterBrand, true, 'Semua Brand') . '</select>';
    echo '<input name="q" value="' . e($filterQ) . '" placeholder="Cari nama / model / SKU..." style="width:200px">';
    echo '<button class="btn">Filter</button>';
    if ($filterGroup || $filterBrand || $filterQ !== '') {
        echo '<a class="btn" href="' . route_url('asset_master_items') . '">Reset</a>';
    }
    echo '</form>';
    echo '</div>';

    if (!$items) {
        echo '<p class="muted" style="margin-top:16px">Belum ada Master Barang yang sesuai filter.</p>';
    } else {
        echo '<table style="margin-top:16px"><thead><tr><th>SKU / Kode</th><th>Nama Barang & Model</th><th>Komoditas</th><th>Kategori</th><th>Brand</th><th>Total Unit Fisik</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';
        foreach ($items as $row) {
            $unitText = '<strong>' . (int)$row['total_units'] . ' Unit</strong>';
            if ((int)$row['total_units'] > 0) {
                $unitText .= ' <span class="badge ok">' . (int)$row['active_units'] . ' Aktif</span>';
                $unitLink = '<a href="' . route_url('asset_items', ['master_item_id' => $row['id']]) . '" title="Lihat unit fisik">' . $unitText . '</a>';
            } else {
                $unitLink = '<span class="muted">0 Unit</span>';
            }

            echo '<tr>';
            echo '<td><strong>' . e($row['item_code']) . '</strong></td>';
            echo '<td><strong>' . e($row['item_name']) . '</strong>' . (!empty($row['model_name']) ? '<br><span class="muted">Model: ' . e($row['model_name']) . '</span>' : '') . '</td>';
            echo '<td>' . e($row['group_name'] ?: '-') . '</td>';
            echo '<td>' . e($row['type_name'] ?: '-') . '</td>';
            echo '<td>' . e($row['brand_name'] ?: '-') . '</td>';
            echo '<td>' . $unitLink . '</td>';
            echo '<td>' . ((int)$row['is_active'] ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td>';
            echo '<td><div class="actions"><a class="btn" href="' . route_url('asset_master_items', ['id' => $row['id']]) . '">Edit</a>';
            if ((int)$row['total_units'] === 0) {
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus Master Barang ini?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$row['id'] . '"><button class="btn danger">Hapus</button></form>';
            }
            echo '</div></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';

    // PC Import Modal for Master Barang
    echo '
<div id="miPcImportModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(2px);z-index:999999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:860px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;">📥</span>
                <div>
                    <h3 style="margin:0;font-size:16px;font-weight:700;color:#1e293b;">Import Master Barang dari Data PC</h3>
                    <small style="color:#64748b;">Pilih PC untuk mengisi Brand, Model Populer (AI), dan Spesifikasi Standar Katalog</small>
                </div>
            </div>
            <button type="button" onclick="closeMiImportPcModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;line-height:1;">✕</button>
        </div>
        <div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;background:#ffffff;">
            <div style="display:flex;gap:10px;">
                <input type="text" id="miPcImportSearch" placeholder="Ketik PC ID, Computer Name, Brand, NIK..." style="flex:1;" oninput="debounceMiPcSearch(this.value)">
                <button type="button" class="btn" onclick="fetchMiPcList()">Cari</button>
            </div>
        </div>
        <div style="padding:0;overflow-y:auto;flex:1;" id="miPcImportTableContainer">
            <div style="text-align:center;padding:40px;color:#64748b;">Ketik pencarian atau tunggu daftar PC dimuat...</div>
        </div>
        <div style="padding:12px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;">
            <button type="button" class="btn" onclick="closeMiImportPcModal()">Batal</button>
        </div>
    </div>
</div>';

    // Script block for Master Barang
    $existingMiSpecsJson = json_encode($existingMiSpecs ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    echo '<script>
    window._existingMiSpecs = ' . $existingMiSpecsJson . ';

    window.loadMiSpecifications = async function(typeId, groupId, fillValues){
        var container = document.getElementById("miSpecificationFields");
        if(!container) return;
        var tid = parseInt(typeId, 10) || 0;
        var gid = parseInt(groupId, 10) || 0;
        if(tid <= 0 || gid <= 0){
            container.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;font-size:13px;padding:4px 0;\">ℹ️ Pilih Komoditas dan Kategori di atas untuk memuat kolom spesifikasi standar.</div>";
            return;
        }
        container.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;font-size:13px;padding:4px 0;\">⏳ Memuat kolom spesifikasi...</div>";
        try {
            var r = await fetch("index.php?route=api_asset_type_config&type_id=" + tid + "&group_id=" + gid);
            var all = await r.json();
            if(Array.isArray(all.specifications) && all.specifications.length > 0){
                var vals = fillValues || window._existingMiSpecs || {};
                var html = "";
                all.specifications.forEach(function(sp){
                    var v = vals[sp.id] !== undefined ? vals[sp.id] : "";
                    var dt = (sp.data_type === "number") ? "number" : ((sp.data_type === "date") ? "date" : "text");
                    var star = sp.is_required ? " *" : "";
                    var req = sp.is_required ? " required" : "";
                    html += "<label>" + (sp.specification_name || "") + star + "<input type=\"" + dt + "\" name=\"specifications[" + sp.id + "]\" value=\"" + String(v).replace(/"/g, "&quot;") + "\"" + req + " placeholder=\"Masukkan " + (sp.specification_name || "") + "\"></label>";
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;font-size:13px;padding:4px 0;\">ℹ️ Tidak ada kolom spesifikasi khusus yang terdaftar untuk kategori ini.</div>";
            }
        } catch(e) {
            container.innerHTML = "<div class=\"muted\" style=\"grid-column:1/-1;font-size:13px;padding:4px 0;color:#dc2626;\">Gagal memuat spesifikasi: " + e.message + "</div>";
        }
    };

    window.onMiGroupChanged = async function(){
        var miGroup = document.getElementById("miGroup");
        var miType = document.getElementById("miType");
        if(!miGroup || !miType) return;
        var gid = parseInt(miGroup.value, 10) || 0;
        if(gid === 0){
            miType.innerHTML = "<option value=\"\">- Pilih Komoditas Terlebih Dahulu -</option>";
            window.loadMiSpecifications(0, 0);
            return;
        }
        miType.innerHTML = "<option value=\"\">Memuat Kategori...</option>";
        try {
            var r = await fetch("index.php?route=api_asset_types&group_id=" + gid);
            var data = await r.json();
            var opts = "<option value=\"\">- Pilih Kategori -</option>";
            if(Array.isArray(data) && data.length > 0){
                data.forEach(function(item){
                    opts += "<option value=\"" + item.id + "\">" + item.type_code + " - " + item.type_name + "</option>";
                });
            } else {
                opts = "<option value=\"\">- Belum ada kategori untuk komoditas ini -</option>";
            }
            miType.innerHTML = opts;
            window.loadMiSpecifications(0, gid);
        } catch(e) {
            miType.innerHTML = "<option value=\"\">Gagal memuat kategori</option>";
        }
    };

    window.onMiTypeChanged = function(){
        var miGroup = document.getElementById("miGroup");
        var miType = document.getElementById("miType");
        if(miGroup && miType){
            window.loadMiSpecifications(miType.value, miGroup.value);
        }
    };

    // Auto-load specifications on edit or pre-selected category
    (function(){
        var miGroup = document.getElementById("miGroup");
        var miType = document.getElementById("miType");
        if(miGroup && miType && parseInt(miType.value, 10) > 0){
            window.loadMiSpecifications(miType.value, miGroup.value, window._existingMiSpecs);
        }
    })();

    // PC Import for Master Items
    window.openMiImportPcModal = function(){
        var m = document.getElementById("miPcImportModal");
        if(m){
            m.style.display = "flex";
            var sIn = document.getElementById("miPcImportSearch");
            if(sIn){
                sIn.focus();
                window.fetchMiPcList(sIn.value);
            } else {
                window.fetchMiPcList("");
            }
        }
    };

    window.closeMiImportPcModal = function(){
        var m = document.getElementById("miPcImportModal");
        if(m) m.style.display = "none";
    };

    var _miPcSearchTimer = null;
    window.debounceMiPcSearch = function(val){
        if(_miPcSearchTimer) clearTimeout(_miPcSearchTimer);
        _miPcSearchTimer = setTimeout(function(){
            window.fetchMiPcList(val);
        }, 300);
    };

    window.fetchMiPcList = async function(query){
        var container = document.getElementById("miPcImportTableContainer");
        if(!container) return;
        if(typeof query === "undefined"){
            query = (document.getElementById("miPcImportSearch")?.value || "").trim();
        }
        container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#64748b;\">⏳ Memuat daftar PC...</div>";
        try {
            var resp = await fetch("index.php?route=api_pc_import&q=" + encodeURIComponent(query));
            var res = await resp.json();
            if(!res.ok || !Array.isArray(res.data) || res.data.length === 0){
                container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#64748b;\">Tidak ditemukan PC dengan kata kunci \"" + (query || "") + "\".</div>";
                return;
            }
            var html = "<table style=\"width:100%;font-size:13px;border-collapse:collapse;\"><thead><tr style=\"background:#f8fafc;border-bottom:1px solid #e2e8f0;text-align:left;\"><th style=\"padding:10px 14px;\">PC ID</th><th style=\"padding:10px 14px;\">Nama Komputer</th><th style=\"padding:10px 14px;\">Brand / Manufaktur</th><th style=\"padding:10px 14px;\">Model Hardware</th><th style=\"padding:10px 14px;text-align:center;\">Aksi</th></tr></thead><tbody>";
            res.data.forEach(function(pc){
                var mfr = pc.specs ? (pc.specs.manufacturer || "-") : "-";
                var mdl = pc.specs ? (pc.specs.model || "-") : "-";
                html += "<tr style=\"border-bottom:1px solid #f1f5f9;\"><td style=\"padding:10px 14px;font-weight:700;\">" + pc.pc_id + "</td><td style=\"padding:10px 14px;\">" + (pc.computer_name || "-") + "</td><td style=\"padding:10px 14px;\">" + mfr + "</td><td style=\"padding:10px 14px;\">" + mdl + "</td><td style=\"padding:10px 14px;text-align:center;\"><button type=\"button\" class=\"btn small primary\" data-pc=\"" + pc.pc_id + "\" onclick=\"selectPcForMi(this.dataset.pc)\">Pilih PC</button></td></tr>";
            });
            html += "</tbody></table>";
            container.innerHTML = html;
        } catch(err){
            container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#dc2626;\">Gagal memuat data PC: " + err.message + "</div>";
        }
    };

    window.selectPcForMi = async function(pcId){
        var container = document.getElementById("miPcImportTableContainer");
        if(container) container.innerHTML = "<div style=\"text-align:center;padding:30px;color:#0284c7;\">⏳ Mengimpor telemetri hardware & menganalisis model populer (AI)...</div>";
        try {
            var resp = await fetch("index.php?route=api_pc_import&pc_id=" + encodeURIComponent(pcId));
            var res = await resp.json();
            if(!res.ok){
                alert("Gagal mengambil data PC: " + (res.error || "Unknown error"));
                window.fetchMiPcList();
                return;
            }

            // Auto-select IT group and Computer/Laptop category if not set
            var gSel = document.getElementById("miGroup");
            var tSel = document.getElementById("miType");
            if(gSel && (!gSel.value || gSel.value === "0")){
                for(var i=0; i<gSel.options.length; i++){
                    if(gSel.options[i].text.toUpperCase().indexOf("IT") !== -1){
                        gSel.selectedIndex = i;
                        await window.onMiGroupChanged();
                        break;
                    }
                }
            }
            if(tSel && (!tSel.value || tSel.value === "0")){
                for(var j=0; j<tSel.options.length; j++){
                    var txt = tSel.options[j].text.toUpperCase();
                    if(txt.indexOf("CMP") !== -1 || txt.indexOf("COMPUTER") !== -1 || txt.indexOf("KOMPUTER") !== -1 || txt.indexOf("LPT") !== -1 || txt.indexOf("LAPTOP") !== -1){
                        tSel.selectedIndex = j;
                        break;
                    }
                }
            }

            // Await specifications container for current group & type
            if(tSel && gSel && parseInt(tSel.value, 10) > 0){
                await window.loadMiSpecifications(tSel.value, gSel.value);
            }

            // Resolve Brand & Model via AI endpoint
            var mfr = res.specs ? (res.specs.manufacturer || "") : "";
            var rawModel = res.specs ? (res.specs.model || "") : "";
            var cpu = res.specs ? (res.specs.processor || "") : "";
            var ram = res.specs ? (res.specs.ram || "") : "";
            var storage = res.specs ? (res.specs.storage || "") : "";

            var aiUrl = "index.php?route=api_ai_resolve_model&manufacturer=" + encodeURIComponent(mfr) + "&model=" + encodeURIComponent(rawModel) + "&processor=" + encodeURIComponent(cpu) + "&ram=" + encodeURIComponent(ram) + "&storage=" + encodeURIComponent(storage);
            var aiReq = await fetch(aiUrl);
            var aiRes = await aiReq.json();

            // Populate Brand
            var bSel = document.getElementById("miBrandId");
            if(bSel && aiRes.ok){
                if(aiRes.brand_id){
                    bSel.value = aiRes.brand_id;
                } else if(aiRes.brand_name){
                    for(var k=0; k<bSel.options.length; k++){
                        if(bSel.options[k].text.toUpperCase().indexOf(aiRes.brand_name.toUpperCase()) !== -1){
                            bSel.selectedIndex = k;
                            break;
                        }
                    }
                }
            }

            // Populate Model Name
            var mIn = document.getElementById("miModelName");
            if(mIn){
                mIn.value = (aiRes.ok && aiRes.popular_model) ? aiRes.popular_model : rawModel;
            }

            // Populate Item Name
            var nIn = document.getElementById("miItemName");
            if(nIn){
                nIn.value = (aiRes.ok && aiRes.suggested_item_name) ? aiRes.suggested_item_name : (mfr + " " + (aiRes.popular_model || rawModel));
            }

            // Map PC hardware telemetry to specifications in #miSpecificationFields
            var miSpecContainer = document.getElementById("miSpecificationFields");
            if(miSpecContainer && res.specs){
                var inputs = miSpecContainer.getElementsByTagName("input");
                for(var si = 0; si < inputs.length; si++){
                    var inp = inputs[si];
                    if(!inp.name || inp.name.indexOf("specifications[") !== 0) continue;
                    var lbl = (inp.closest("label") ? inp.closest("label").textContent : "").toLowerCase();
                    var val = "";
                    if(lbl.indexOf("proc") !== -1 || lbl.indexOf("cpu") !== -1 || lbl.indexOf("prosesor") !== -1){
                        val = res.specs.processor;
                    } else if(lbl.indexOf("ram") !== -1 || lbl.indexOf("memory") !== -1 || lbl.indexOf("memori") !== -1){
                        val = res.specs.ram;
                    } else if(lbl.indexOf("storage") !== -1 || lbl.indexOf("penyimpanan") !== -1 || lbl.indexOf("ssd") !== -1 || lbl.indexOf("hdd") !== -1 || lbl.indexOf("disk") !== -1){
                        val = res.specs.storage;
                    } else if(lbl.indexOf("os") !== -1 || lbl.indexOf("sistem operasi") !== -1 || lbl.indexOf("windows") !== -1){
                        val = res.specs.os;
                    } else if(lbl.indexOf("gpu") !== -1 || lbl.indexOf("vga") !== -1 || lbl.indexOf("grafis") !== -1 || lbl.indexOf("graphics") !== -1){
                        val = res.specs.gpu;
                    }
                    if(val){
                        inp.value = val;
                        inp.style.transition = "background-color 0.5s";
                        inp.style.backgroundColor = "#dcfce7";
                        setTimeout(function(el){ return function(){ el.style.backgroundColor = ""; }; }(inp), 2500);
                    }
                }
            }

            window.closeMiImportPcModal();
            var modelDisplay = (aiRes.ok && aiRes.popular_model) ? aiRes.popular_model : rawModel;
            alert("✅ Berhasil mengimpor data Master Barang dari PC: " + res.pc_id + "\n\nModel Populer: " + modelDisplay + "\nBrand: " + (aiRes.brand_name || mfr) + "\nSpesifikasi standar telah terisi.");
        } catch(err){
            alert("Terjadi kesalahan saat memproses data PC: " + err.message);
            window.fetchMiPcList();
        }
    };
    </script>';

    render_footer();
}


function handle_route_asset_items(PDO $pdo): void
{
    $user = require_role(['admin']);
    
    $masterItemId = (int)($_GET['master_item_id'] ?? 0);
    $groupId = (int)($_GET['group_id'] ?? 0);
    $typeId = (int)($_GET['type_id'] ?? 0);
    $brandId = (int)($_GET['brand_id'] ?? 0);
    $locationId = (int)($_GET['location_id'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));

    $where = ["1=1"];
    $params = [];
    if ($masterItemId > 0) {
        $where[] = "ai.master_item_id = ?";
        $params[] = $masterItemId;
    }
    if ($groupId > 0) {
        $where[] = "ai.asset_group_id = ?";
        $params[] = $groupId;
    }
    if ($typeId > 0) {
        $where[] = "ai.asset_type_id = ?";
        $params[] = $typeId;
    }
    if ($brandId > 0) {
        $where[] = "ai.brand_id = ?";
        $params[] = $brandId;
    }
    if ($locationId > 0) {
        $where[] = "ai.location_id = ?";
        $params[] = $locationId;
    }
    if ($q !== '') {
        $where[] = "(ai.asset_code LIKE ? OR ai.asset_name LIKE ? OR ai.serial_number LIKE ? OR ai.custodian_name LIKE ? OR ai.custodian_nik LIKE ? OR ai.model LIKE ? OR ai.location_label LIKE ? OR EXISTS (SELECT 1 FROM asset_identifiers aid WHERE aid.asset_item_id = ai.id AND aid.identifier_value LIKE ?) OR EXISTS (SELECT 1 FROM asset_specifications asp WHERE asp.asset_item_id = ai.id AND asp.specification_value LIKE ?))";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    $sql = "SELECT ai.*, c.company_name, ami.item_name AS master_item_name, ami.item_code AS master_item_code 
            FROM asset_items ai 
            LEFT JOIN asset_companies c ON c.id = ai.company_id 
            LEFT JOIN asset_master_items ami ON ami.id = ai.master_item_id 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY ai.updated_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    render_header('Unit Aset', $user);
    echo asset_nav_html();

    $filterTitle = 'Unit Aset';
    if ($masterItemId > 0) {
        $mInfo = $pdo->query("SELECT item_code, item_name FROM asset_master_items WHERE id = {$masterItemId}")->fetch(PDO::FETCH_ASSOC);
        if ($mInfo) {
            $filterTitle .= ' - Katalog: ' . htmlspecialchars($mInfo['item_code'] . ' (' . $mInfo['item_name'] . ')');
        }
    }

    echo '<section class="panel">';
    echo '<div class="split"><h1>' . $filterTitle . '</h1><div class="actions"><a class="btn primary" href="' . route_url('asset_item_form', $masterItemId ? ['master_item_id' => $masterItemId] : []) . '">+ Tambah Unit Aset</a><a class="btn" href="' . route_url('export_excel', ['type' => 'asset_items']) . '">Export Excel</a></div></div>';

    // Filters
    echo '<form method="get" class="actions" style="margin:16px 0 8px 0">';
    echo '<input type="hidden" name="route" value="asset_items">';
    if ($masterItemId > 0) {
        echo '<input type="hidden" name="master_item_id" value="' . $masterItemId . '">';
    }
    echo '<select name="group_id" onchange="this.form.submit()">' . asset_group_options($pdo, $groupId, true, 'Semua Komoditas') . '</select>';
    echo '<select name="brand_id" onchange="this.form.submit()">' . asset_brand_options($pdo, $brandId, true, 'Semua Brand') . '</select>';
    echo '<select name="location_id" onchange="this.form.submit()">' . asset_location_options($pdo, $locationId, true, 'Semua Lokasi') . '</select>';
    echo '<input name="q" value="' . e($q) . '" placeholder="Cari kode / SN / NIK / nama / lokasi..." style="width:200px">';
    echo '<button class="btn">Filter</button>';
    if ($masterItemId || $groupId || $brandId || $locationId || $q !== '') {
        echo '<a class="btn" href="' . route_url('asset_items') . '">Reset Filter</a>';
    }
    echo '</form>';

    echo asset_items_table($pdo, $rows);
    echo '</section>';
    render_footer();
}

function handle_route_asset_item_form(PDO $pdo): void
{
    $user = require_role(['admin']);
    $id = (int)($_GET['id'] ?? 0);
    $item = asset_master_item_defaults();
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM asset_items WHERE id=?');
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if (!$found) {
            http_response_code(404);
            exit('Unit Aset tidak ditemukan.');
        }
        $item = array_merge($item, $found);
    } elseif (!empty($_GET['master_item_id'])) {
        $mId = (int)$_GET['master_item_id'];
        $mStmt = $pdo->prepare('SELECT ami.*, ab.brand_name FROM asset_master_items ami LEFT JOIN asset_brands ab ON ab.id=ami.brand_id WHERE ami.id=?');
        $mStmt->execute([$mId]);
        $mFound = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($mFound) {
            $item['master_item_id'] = (int)$mFound['id'];
            $item['asset_group_id'] = (int)$mFound['asset_group_id'];
            $item['asset_type_id'] = (int)$mFound['asset_type_id'];
            $item['brand_id'] = (int)($mFound['brand_id'] ?? 0);
            $item['brand'] = (string)($mFound['brand_name'] ?? '');
            $item['model'] = (string)($mFound['model_name'] ?? '');
            $item['asset_name'] = (string)($mFound['item_name'] ?? '');
        }
    }
    if (!empty($_GET['asset_group_id'])) {
        $item['asset_group_id'] = (int)$_GET['asset_group_id'];
    }
    if (!empty($_GET['asset_type_id'])) {
        $item['asset_type_id'] = (int)$_GET['asset_type_id'];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $id = save_asset_master_item($pdo, $id, $_POST);
            flash('Unit Aset berhasil disimpan.');
            redirect_to('asset_item_form', ['id' => $id]);
        } catch (Throwable $e) {
            flash('Gagal simpan unit aset: ' . $e->getMessage(), 'err');
        }
    }
    render_header($id > 0 ? 'Edit Unit Aset' : 'Tambah Unit Aset', $user);
    echo asset_nav_html();
    echo asset_master_item_form_html($pdo, $item, $id > 0);
    if ($id > 0) {
        echo asset_item_maintenance_link_panel_html($pdo, $id);
        echo asset_item_member_manager_html($pdo, $id);

        if (db_table_exists($pdo, 'corrective_tickets')) {
            $tStmt = $pdo->prepare("SELECT t.*, 
                (SELECT COUNT(*) FROM corrective_repairs cr WHERE cr.ticket_id = t.id) AS repair_count,
                (SELECT COALESCE(SUM(cr.repair_cost), 0) FROM corrective_repairs cr WHERE cr.ticket_id = t.id) AS total_repair_cost,
                (SELECT COALESCE(SUM(crp.part_cost), 0) FROM corrective_repairs cr JOIN corrective_repair_parts crp ON crp.repair_id = cr.id WHERE cr.ticket_id = t.id) AS total_part_cost
                FROM corrective_tickets t
                WHERE t.asset_item_id = ?
                ORDER BY t.created_at DESC");
            $tStmt->execute([$id]);
            $itemTickets = $tStmt->fetchAll(PDO::FETCH_ASSOC);

            echo '<section class="panel"><div class="split"><h2>Riwayat Tiket & Reparasi Aset Ini</h2><a class="btn primary" href="' . route_url('ticket_form', ['asset_item_id' => $id, 'category' => $item['asset_category'] ?? 'IT']) . '">+ Buat Tiket Masalah / Reparasi</a></div>';
            if (!$itemTickets) {
                echo '<p class="muted">Belum ada tiket atau perbaikan yang tercatat untuk aset ini.</p>';
            } else {
                echo '<table><thead><tr><th>Tiket & Tanggal</th><th>Prioritas</th><th>Subjek & Keluhan</th><th>Pelapor</th><th>Status</th><th>Total Biaya</th><th>Aksi</th></tr></thead><tbody>';
                foreach ($itemTickets as $it) {
                    $cost = (float)$it['total_repair_cost'] + (float)$it['total_part_cost'];
                    echo '<tr>';
                    echo '<td><strong>' . e($it['ticket_code']) . '</strong><br><span class="muted">' . e(date('d M Y H:i', strtotime($it['created_at']))) . '</span></td>';
                    echo '<td>' . (function_exists('ticket_priority_badge') ? ticket_priority_badge($it['priority']) : e($it['priority'])) . '</td>';
                    echo '<td><strong>' . e($it['subject']) . '</strong><br><span class="muted">' . e(mb_strimwidth((string)$it['description'], 0, 60, '...')) . '</span></td>';
                    echo '<td>' . e($it['reporter_name']) . '</td>';
                    echo '<td>' . (function_exists('ticket_status_badge') ? ticket_status_badge($it['status']) : e($it['status'])) . '</td>';
                    echo '<td>' . ($cost > 0 ? '<strong>Rp ' . number_format($cost, 0, ',', '.') . '</strong>' : '<span class="muted">Rp 0</span>') . '</td>';
                    echo '<td><a class="btn" href="' . route_url('ticket_detail', ['id' => $it['id']]) . '">Lihat / Handle</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '</section>';
        }
    }
    render_footer();
}

function handle_route_asset_item_member_action(PDO $pdo): void
{
    $user = require_role(['admin']);
    $parentId = (int)($_POST['parent_asset_item_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($parentId <= 0) {
        redirect_to('asset_items');
    }
    $parent = asset_item_row($pdo, $parentId);
    if (!$parent) {
        redirect_to('asset_items');
    }
    if (($parent['asset_mode'] ?? 'standalone') !== 'group') {
        flash('Anggota hanya bisa dipasang pada Asset Item dengan mode Asset Gabungan.', 'err');
        redirect_to('asset_item_form', ['id' => $parentId]);
    }
    if ($action === 'attach') {
        $childId = (int)($_POST['child_asset_item_id'] ?? 0);
        if ($childId <= 0 || $childId === $parentId) {
            flash('Asset anggota tidak valid.', 'err');
            redirect_to('asset_item_form', ['id' => $parentId]);
        }
        $child = asset_item_row($pdo, $childId);
        if (!$child) {
            flash('Asset anggota tidak ditemukan.', 'err');
            redirect_to('asset_item_form', ['id' => $parentId]);
        }
        if (asset_item_contains_child($pdo, $childId, $parentId)) {
            flash('Gabungan ditolak karena akan membuat lingkaran asset gabungan.', 'err');
            redirect_to('asset_item_form', ['id' => $parentId]);
        }
        $date = normalize_date_input((string)($_POST['attached_at'] ?? date('Y-m-d')));
        $role = trim((string)($_POST['role_name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $oldParent = active_parent_asset_item($pdo, $childId);
        if ($oldParent && (int)$oldParent['parent_asset_item_id'] === $parentId) {
            $pdo->prepare('UPDATE asset_item_members SET role_name=?, attached_at=?, notes=? WHERE id=?')->execute([$role, $date, $notes, (int)$oldParent['id']]);
            flash('Anggota asset gabungan berhasil diperbarui.');
            redirect_to('asset_item_form', ['id' => $parentId]);
        }
        if ($oldParent && (int)$oldParent['parent_asset_item_id'] !== $parentId) {
            flash('Asset item ini masih terikat pada bundle lain. Lepas dari parent terlebih dahulu.', 'err');
            redirect_to('asset_item_form', ['id' => $parentId]);
        } else {
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, to_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?)')->execute([$childId, $parentId, $date, 'Pasang ke asset gabungan ' . ($parent['asset_code'] ?? ''), $user['name'] ?? '']);
        }
        $pdo->prepare('INSERT INTO asset_item_members (parent_asset_item_id, child_asset_item_id, role_name, attached_at, notes) VALUES (?, ?, ?, ?, ?)')->execute([$parentId, $childId, $role, $date, $notes]);
        $pdo->prepare("UPDATE asset_items SET asset_mode='child' WHERE id=?")->execute([$childId]);
        flash('Asset item berhasil digabungkan.');
    }
    if ($action === 'detach') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $member = asset_item_member_row($pdo, $memberId);
        if ($member && (int)$member['parent_asset_item_id'] === $parentId) {
            $date = normalize_date_input((string)($_POST['detached_at'] ?? date('Y-m-d')));
            $reason = trim((string)($_POST['reason'] ?? 'Dilepas dari asset gabungan'));
            $childId = (int)$member['child_asset_item_id'];
            $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\nDetached: " . $reason, $memberId]);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?)')->execute([$childId, $parentId, $date, $reason, $user['name'] ?? '']);
            $childActive = active_parent_asset_item($pdo, $childId);
            if (!$childActive) {
                $pdo->prepare("UPDATE asset_items SET asset_mode='standalone' WHERE id=?")->execute([$childId]);
            }
            flash('Asset item berhasil dipisahkan.');
        }
    }
    redirect_to('asset_item_form', ['id' => $parentId]);
}

function handle_route_asset_bundles(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('Asset Bundles', $user);
    echo asset_nav_html();
    $rows = $pdo->query('SELECT b.*, c.company_name, COUNT(m.id) member_count FROM asset_bundles b LEFT JOIN asset_companies c ON c.id=b.company_id LEFT JOIN asset_bundle_members m ON m.bundle_id=b.id AND m.detached_at IS NULL GROUP BY b.id ORDER BY b.updated_at DESC')->fetchAll();
    echo '<section class="panel"><div class="split"><h1>Asset Bundles / No Aset Pemeliharaan</h1><div class="actions"><a class="btn primary" href="' . route_url('asset_bundle_form') . '">Tambah Bundle</a><a class="btn" href="' . route_url('export_excel', ['type' => 'asset_bundles']) . '">Export Excel</a></div></div>' . asset_bundles_table($rows) . '</section>';
    render_footer();
}

function handle_route_asset_bundle_form(PDO $pdo): void
{
    $user = require_role(['admin']);
    $id = (int)($_GET['id'] ?? 0);
    $bundle = asset_bundle_defaults();
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM asset_bundles WHERE id=?');
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if (!$found) {
            http_response_code(404);
            exit('Asset bundle tidak ditemukan.');
        }
        $bundle = array_merge($bundle, $found);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = asset_bundle_post_data();
        if ($data['maintenance_asset_code'] === '' || $data['bundle_name'] === '' || $data['bundle_type'] === '') {
            flash('No aset pemeliharaan, tipe bundle, dan nama bundle wajib diisi.', 'err');
            redirect_to('asset_bundle_form', $id > 0 ? ['id' => $id] : []);
        }
        try {
            if ($id > 0) {
                $values = array_values($data);
                $values[] = $id;
                $pdo->prepare('UPDATE asset_bundles SET maintenance_asset_code=?, company_id=?, bundle_type=?, bundle_name=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=?, status=?, notes=? WHERE id=?')->execute($values);
                flash('Asset bundle berhasil diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO asset_bundles (maintenance_asset_code, company_id, bundle_type, bundle_name, employee_nik, owner_name, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                flash('Asset bundle berhasil ditambahkan.');
            }
            redirect_to('asset_bundle_form', ['id' => $id]);
        } catch (Throwable $e) {
            flash('Gagal simpan asset bundle: ' . $e->getMessage(), 'err');
        }
    }
    render_header($id > 0 ? 'Edit Asset Bundle' : 'Tambah Asset Bundle', $user);
    echo asset_nav_html();
    echo asset_bundle_form_html($pdo, $bundle, $id > 0);
    if ($id > 0) {
        echo asset_bundle_member_manager_html($pdo, $id);
        echo asset_bundle_repairs_html($pdo, $id);
    }
    render_footer();
}

function handle_route_asset_bundle_member_action(PDO $pdo): void
{
    $user = require_role(['admin']);
    $bundleId = (int)($_POST['bundle_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($bundleId <= 0) {
        redirect_to('asset_bundles');
    }
    if ($action === 'attach') {
        $itemId = (int)($_POST['asset_item_id'] ?? 0);
        if ($itemId > 0) {
            $item = asset_item_row($pdo, $itemId);
            $bundle = asset_bundle_row($pdo, $bundleId);
            $pdo->prepare('INSERT INTO asset_bundle_members (bundle_id, asset_item_id, role_name, attached_at, notes) VALUES (?, ?, ?, ?, ?)')->execute([$bundleId, $itemId, trim((string)($_POST['role_name'] ?? '')), normalize_date_input((string)($_POST['attached_at'] ?? date('Y-m-d'))), trim((string)($_POST['notes'] ?? ''))]);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, to_bundle_id, to_company_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?, ?)')->execute([$itemId, $bundleId, $bundle['company_id'] ?? null, normalize_date_input((string)($_POST['attached_at'] ?? date('Y-m-d'))), 'Attach ke bundle ' . ($bundle['maintenance_asset_code'] ?? ''), $user['name'] ?? '']);
            flash('Asset item berhasil dipasang ke bundle.');
        }
    }
    if ($action === 'detach') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $member = asset_bundle_member_row($pdo, $memberId);
        if ($member) {
            $date = normalize_date_input((string)($_POST['detached_at'] ?? date('Y-m-d')));
            $pdo->prepare('UPDATE asset_bundle_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\nDetached: " . trim((string)($_POST['reason'] ?? '')), $memberId]);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_bundle_id, from_company_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?, ?)')->execute([(int)$member['asset_item_id'], $bundleId, $member['company_id'] ?? null, $date, trim((string)($_POST['reason'] ?? 'Detach dari bundle')), $user['name'] ?? '']);
            flash('Asset item berhasil dilepas dari bundle.');
        }
    }
    redirect_to('asset_bundle_form', ['id' => $bundleId]);
}

function handle_route_asset_repairs(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('Repair History', $user);
    echo asset_nav_html();
    $rows = asset_repair_rows($pdo);
    echo '<section class="panel"><div class="split"><h1>Repair History</h1><a class="btn primary" href="' . route_url('asset_repair_form') . '">Tambah Repair</a></div>' . asset_repairs_table($rows) . '</section>';
    render_footer();
}

function handle_route_asset_repair_form(PDO $pdo): void
{
    $user = require_role(['admin']);
    $id = (int)($_GET['id'] ?? 0);
    $repair = asset_repair_defaults();
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM asset_repairs WHERE id=?');
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if (!$found) {
            http_response_code(404);
            exit('Repair tidak ditemukan.');
        }
        $repair = array_merge($repair, $found);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = asset_repair_post_data();
        if ((int)$data['asset_item_id'] <= 0 || $data['repair_date'] === '') {
            flash('Asset item dan tanggal perbaikan wajib diisi.', 'err');
            redirect_to('asset_repair_form', $id > 0 ? ['id' => $id] : []);
        }
        if ($id > 0) {
            $values = array_values($data);
            $values[] = $id;
            $pdo->prepare('UPDATE asset_repairs SET asset_item_id=?, bundle_id=?, repair_date=?, repair_location=?, repair_vendor=?, problem_description=?, repair_action=?, spare_part_replaced=?, repair_cost=?, warranty_claim=?, technician_or_pic=?, notes=? WHERE id=?')->execute($values);
            $repairId = $id;
            $pdo->prepare('DELETE FROM asset_repair_parts WHERE repair_id=?')->execute([$repairId]);
        } else {
            $pdo->prepare('INSERT INTO asset_repairs (asset_item_id, bundle_id, repair_date, repair_location, repair_vendor, problem_description, repair_action, spare_part_replaced, repair_cost, warranty_claim, technician_or_pic, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($data));
            $repairId = (int)$pdo->lastInsertId();
        }
        save_repair_parts($pdo, $repairId, $_POST['parts'] ?? []);

        $selectedJobs = array_map('intval', (array)($_POST['repair_jobs'] ?? []));
        if (db_column_exists($pdo, 'asset_repairs', 'selected_jobs')) {
            $pdo->prepare('UPDATE asset_repairs SET selected_jobs=? WHERE id=?')->execute([json_encode($selectedJobs), $repairId]);
        }
        if (trim($data['repair_action']) === '' && !empty($selectedJobs)) {
            $inQ = implode(',', array_fill(0, count($selectedJobs), '?'));
            $titleStmt = $pdo->prepare("SELECT title FROM maintenance_jobs WHERE id IN ($inQ)");
            $titleStmt->execute($selectedJobs);
            $jobTitles = $titleStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($jobTitles) {
                $autoAction = implode("\n", array_map(static fn($t) => '- ' . $t, $jobTitles));
                $pdo->prepare('UPDATE asset_repairs SET repair_action=? WHERE id=?')->execute([$autoAction, $repairId]);
            }
        }

        flash('Repair history berhasil disimpan.');
        redirect_to('asset_repairs');
    }
    render_header($id > 0 ? 'Edit Repair' : 'Tambah Repair', $user);
    echo asset_nav_html();
    echo asset_repair_form_html($pdo, $repair, $id);
    render_footer();
}

function handle_route_asset_movements(PDO $pdo): void
{
    $user = require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_asset_movement_transaction($pdo, $user);
        redirect_to('asset_movements');
    }
    render_header('Mutasi / Tukar Pasang', $user);
    echo asset_nav_html();
    $rows = asset_movement_rows($pdo);
    echo asset_movement_transaction_form_html($pdo, $user);
    echo '<section class="panel"><h1>Riwayat Mutasi / Tukar Pasang</h1><p class="muted">Riwayat terbentuk dari transaksi di atas dan relasi asset.</p>' . asset_movements_table($rows) . '</section>';
    render_footer();
}

function handle_route_employee_source(PDO $pdo): void
{
    $user = require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['module'] ?? '') === 'company_source') {
            ensure_company_source_schema($pdo);
            $config = [
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'mode' => in_array(($_POST['mode'] ?? 'bridge'), ['bridge', 'direct'], true) ? (string)$_POST['mode'] : 'bridge',
                'bridge_url' => trim((string)($_POST['bridge_url'] ?? '')),
                'bridge_token' => (string)($_POST['bridge_token'] ?? ''),
                'host' => trim((string)($_POST['host'] ?? '')),
                'port' => trim((string)($_POST['port'] ?? '1433')),
                'database' => trim((string)($_POST['database'] ?? '')),
                'username' => trim((string)($_POST['username'] ?? '')),
                'password' => (string)($_POST['password'] ?? ''),
                'table_name' => trim((string)($_POST['table_name'] ?? 'dbo.Company')),
                'id_field' => trim((string)($_POST['id_field'] ?? 'id')),
                'name_field' => trim((string)($_POST['name_field'] ?? 'nama_unit_usaha')),
                'limit_rows' => trim((string)($_POST['limit_rows'] ?? '500')),
                'trust_server_certificate' => isset($_POST['trust_server_certificate']) ? '1' : '0',
            ];
            if ($config['password'] === '') {
                $oldConfig = company_source_config($pdo);
                $config['password'] = (string)($oldConfig['password'] ?? '');
            }
            save_company_source_config($pdo, $config);
            flash('Setup sumber company tersimpan.');
            redirect_to('employee_source');
        }
        $config = [
            'enabled' => '1',
            'host' => trim((string)($_POST['host'] ?? '')),
            'port' => trim((string)($_POST['port'] ?? '3306')),
            'database' => trim((string)($_POST['database'] ?? '')),
            'username' => trim((string)($_POST['username'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
            'table_name' => trim((string)($_POST['table_name'] ?? 'employees')),
            'nik_field' => trim((string)($_POST['nik_field'] ?? 'NIK')),
            'name_field' => trim((string)($_POST['name_field'] ?? 'Nama')),
            'department_field' => trim((string)($_POST['department_field'] ?? '')),
            'limit_rows' => trim((string)($_POST['limit_rows'] ?? '5000')),
        ];
        if ($config['password'] === '') {
            $oldConfig = employee_source_config($pdo);
            $config['password'] = (string)($oldConfig['password'] ?? '');
        }
        save_employee_source_config($pdo, $config);

        if (in_array(($_POST['action'] ?? ''), ['import', 'sync'], true)) {
            try {
                $count = import_employee_directory($pdo);
                flash('Import data karyawan ke database lokal berhasil! Total ' . $count . ' data karyawan tersimpan.');
            } catch (Throwable $e) {
                flash('Import karyawan gagal: ' . $e->getMessage(), 'err');
            }
        } elseif (($_POST['action'] ?? '') === 'test') {
            try {
                $remoteRows = fetch_employee_portal_rows($config, 5);
                flash('Koneksi MariaDB portal berhasil! Sample terbaca ' . count($remoteRows) . ' karyawan dari portal (misal: ' . (!empty($remoteRows[0]['name']) ? $remoteRows[0]['name'] : 'OK') . ').');
            } catch (Throwable $e) {
                flash('Test MariaDB portal gagal: ' . $e->getMessage(), 'err');
            }
        } elseif (($_POST['action'] ?? '') === 'truncate_local') {
            try {
                $pdo->exec('DELETE FROM employee_directory');
                flash('Seluruh data karyawan di database lokal berhasil dikosongkan.');
            } catch (Throwable $e) {
                flash('Gagal mengosongkan data karyawan lokal: ' . $e->getMessage(), 'err');
            }
        } else {
            flash('Konfigurasi koneksi MariaDB portal berhasil disimpan.');
        }
        redirect_to('employee_source');
    }
    render_header('Employee & Company Source', $user);
    echo employee_source_form_html($pdo);
    echo company_source_form_html($pdo);
    render_footer();
}

