<?php

declare(strict_types=1);

function maintenance_asset_qr_code(array $asset): string
{
    return (string)$asset['maintenance_asset_code'] . '-' . (string)$asset['security_code'];
}

if (!function_exists('unique_maintenance_asset_code')) {
    function unique_maintenance_asset_code(PDO $pdo, string $base, ?int $ignoreId = null): string
    {
        $code = strtoupper(trim($base));
        if ($code === '') {
            $code = 'MNT-' . date('YmdHis');
        }
        $try = $code;
        $n = 1;
        do {
            $sql = 'SELECT id FROM maintenance_assets WHERE maintenance_asset_code = ?';
            $params = [$try];
            if ($ignoreId !== null && $ignoreId > 0) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) {
                return $try;
            }
            $try = $code . '-' . (++$n);
        } while (true);
    }
}


function maintenance_asset_defaults(): array
{
    return [
        'id' => 0,
        'maintenance_asset_code' => '',
        'security_code' => pc_security_code_random(),
        'maintenance_type' => 'equipment',
        'asset_group_id' => '',
        'asset_type_id' => '',
        'asset_item_id' => 0,
        'job_desk_name' => '',
        'name' => '',
        'company_id' => '',
        'employee_nik' => '',
        'owner_name' => '',
        'location_label' => '',
        'latitude' => '',
        'longitude' => '',
        'location_radius_m' => 5,
        'status' => 'active',
        'notes' => '',
    ];
}

function maintenance_asset_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Pilih Maintenance Asset ID -</option>';
    if (!db_table_exists($pdo, 'maintenance_assets')) {
        return $html;
    }
    $hasPcs = db_table_exists($pdo, 'pcs');
    $sql = 'SELECT ma.id, ma.maintenance_asset_code, ma.name, ma.maintenance_type 
            FROM maintenance_assets ma 
            LEFT JOIN asset_items ai ON ai.id = ma.asset_item_id
            ' . ($hasPcs ? 'LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci LEFT JOIN asset_items ai_pc ON ai_pc.id = p.asset_item_id ' : '') . '
            WHERE ma.status <> "inactive"
              AND (
                  (ma.asset_item_id IS NOT NULL AND ma.asset_item_id > 0 AND (ai.asset_mode IS NULL OR ai.asset_mode <> "child") AND NOT EXISTS (SELECT 1 FROM asset_item_members aim WHERE aim.child_asset_item_id = ai.id AND aim.detached_at IS NULL))
                  ' . ($hasPcs ? 'OR (ma.asset_item_id IS NULL AND (ma.pc_id IS NULL OR ma.pc_id = "" OR (p.asset_item_id IS NOT NULL AND p.asset_item_id > 0 AND (ai_pc.asset_mode IS NULL OR ai_pc.asset_mode <> "child") AND NOT EXISTS (SELECT 1 FROM asset_item_members aim2 WHERE aim2.child_asset_item_id = ai_pc.id AND aim2.detached_at IS NULL))))' : '') . '
              )
            ORDER BY ma.maintenance_asset_code';
    foreach ($pdo->query($sql) as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $label = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (' . $row['maintenance_type'] . ')';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Mengambil daftar maintenance asset.
 * SYARAT: Asset child TIDAK AKAN MUNCUL!
 */
function maintenance_asset_rows(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'maintenance_assets')) {
        return [];
    }
    $hasPcs = db_table_exists($pdo, 'pcs');
    $sql = 'SELECT ma.*, c.company_name, 
                   COALESCE(ag.group_name, "IT Asset") AS group_name, 
                   COALESCE(ag.group_code, "IT") AS group_code, 
                   at.type_name, 
                   at.type_code, 
                   ai.asset_code AS unit_asset_code,
                   ai.asset_name AS unit_asset_name,
                   ai.asset_mode AS unit_asset_mode,
                   ai.source_pc_id,
                   COUNT(mi.id) item_count 
            FROM maintenance_assets ma 
            LEFT JOIN asset_groups ag ON ag.id = ma.asset_group_id
            LEFT JOIN asset_types at ON at.id = ma.asset_type_id
            LEFT JOIN asset_items ai ON ai.id = ma.asset_item_id
            ' . ($hasPcs ? 'LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci LEFT JOIN asset_items ai_pc ON ai_pc.id = p.asset_item_id ' : '') . '
            LEFT JOIN asset_companies c ON c.id=ma.company_id 
            LEFT JOIN maintenance_asset_items mi ON mi.maintenance_asset_id=ma.id AND mi.detached_at IS NULL 
            WHERE (
                (ma.asset_item_id IS NOT NULL AND ma.asset_item_id > 0 AND (ai.asset_mode IS NULL OR ai.asset_mode <> "child") AND NOT EXISTS (SELECT 1 FROM asset_item_members aim WHERE aim.child_asset_item_id = ai.id AND aim.detached_at IS NULL))
                ' . ($hasPcs ? 'OR (ma.asset_item_id IS NULL AND (ma.pc_id IS NULL OR ma.pc_id = "" OR (p.asset_item_id IS NOT NULL AND p.asset_item_id > 0 AND (ai_pc.asset_mode IS NULL OR ai_pc.asset_mode <> "child") AND NOT EXISTS (SELECT 1 FROM asset_item_members aim2 WHERE aim2.child_asset_item_id = ai_pc.id AND aim2.detached_at IS NULL))))' : '') . '
            )
            GROUP BY ma.id 
            ORDER BY ma.updated_at DESC, ma.maintenance_asset_code';
    return $pdo->query($sql)->fetchAll();
}

function maintenance_assets_table(PDO $pdo, array $rows): string
{
    $html = '<table><tr><th>Maintenance Asset ID</th><th>Komoditas & Kategori</th><th>Unit Aset Fisik</th><th>Job Desk Preventive</th><th>Tipe</th><th>Nama</th><th>Company</th><th>Pengguna / Lokasi</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $links = [];
        if (!empty($row['source_pc_id'])) {
            $links[] = 'PC: ' . $row['source_pc_id'];
        } elseif (!empty($row['pc_id'])) {
            $links[] = 'PC: ' . $row['pc_id'];
        }
        if (!empty($row['printer_id'])) {
            $links[] = 'Printer: ' . $row['printer_id'];
        }
        $grpLabel = $row['group_code'] ? ($row['group_code'] . ' - ' . $row['group_name']) : $row['group_name'];
        $typeLabel = !empty($row['type_name']) ? ($row['type_code'] . ' - ' . $row['type_name']) : '-';
        $deskBadge = !empty($row['job_desk_name']) ? '<span class="badge ok" style="font-size:11px;font-weight:700;">' . e($row['job_desk_name']) . '</span>' : '<span class="muted">-</span>';
        $statusBadge = ($row['status'] ?? 'active') === 'active' 
            ? '<span class="badge ok">Active</span>' 
            : (($row['status'] ?? '') === 'spare' ? '<span class="badge">Spare</span>' : '<span class="badge danger">Inactive</span>');

        $unitAssetHtml = '<span class="muted" style="color:#ef4444;font-size:12px;">⚠️ Belum ditautkan unit aset</span>';
        if (!empty($row['unit_asset_code'])) {
            $rawMode = (string)($row['unit_asset_mode'] ?? 'standalone');
            $modeBadge = $rawMode === 'group' 
                ? '<span class="badge ok" style="font-size:10px;">Bundle (Parent)</span>' 
                : '<span class="badge" style="font-size:10px;">Single</span>';
            $importBadge = !empty($row['source_pc_id']) 
                ? ' <span class="badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;font-size:10px;">📥 PC ' . e($row['source_pc_id']) . '</span>' 
                : '';
            $unitAssetHtml = '<strong>' . e($row['unit_asset_code']) . '</strong> ' . $modeBadge . $importBadge . '<br><span style="font-size:12px;color:#475569;">' . e($row['unit_asset_name']) . '</span>'
                . '<div style="font-size:11px;color:#64748b;margin-top:2px;">' . e($row['item_count']) . ' item fisik terhubung</div>';
        }

        $html .= '<tr>'
            . '<td><strong>' . e($row['maintenance_asset_code']) . '</strong><br><span class="muted">' . e(implode(' / ', $links) ?: 'General asset') . '</span></td>'
            . '<td><span class="badge">' . e($grpLabel) . '</span><br><span class="muted" style="font-size:12px;font-weight:600;">' . e($typeLabel) . '</span></td>'
            . '<td>' . $unitAssetHtml . '</td>'
            . '<td>' . $deskBadge . '</td>'
            . '<td>' . e($row['maintenance_type']) . '</td>'
            . '<td>' . e($row['name']) . '</td>'
            . '<td>' . e($row['company_name'] ?: '-') . '</td>'
            . '<td>' . e($row['owner_name'] ?: '-') . '<br><span class="muted">' . e($row['location_label'] ?: '-') . '</span></td>'
            . '<td>' . $statusBadge . '</td>'
            . '<td><div class="actions"><a class="btn primary" href="' . route_url('maintenance_asset_form', ['id' => $row['id']]) . '">Edit / Detail</a></div></td>'
            . '</tr>';
    }
    return $html . '</table>';
}

function maintenance_asset_post_data(PDO $pdo, int $id = 0): array
{
    $latitude = normalize_decimal_input((string)($_POST['latitude'] ?? ''));
    $longitude = normalize_decimal_input((string)($_POST['longitude'] ?? ''));
    return [
        'maintenance_asset_code' => unique_maintenance_asset_code($pdo, strtoupper(trim((string)($_POST['maintenance_asset_code'] ?? ''))), $id > 0 ? $id : null),
        'security_code' => strtoupper(trim((string)($_POST['security_code'] ?? ''))),
        'maintenance_type' => trim((string)($_POST['maintenance_type'] ?? 'equipment')),
        'asset_group_id' => (int)($_POST['asset_group_id'] ?? 0) > 0 ? (int)$_POST['asset_group_id'] : null,
        'asset_type_id' => (int)($_POST['asset_type_id'] ?? 0) > 0 ? (int)$_POST['asset_type_id'] : null,
        'asset_item_id' => (int)($_POST['asset_item_id'] ?? 0) > 0 ? (int)$_POST['asset_item_id'] : null,
        'job_desk_name' => null_if_empty(trim((string)($_POST['job_desk_name'] ?? ''))),
        'name' => trim((string)($_POST['name'] ?? '')),
        'company_id' => (int)($_POST['company_id'] ?? 0) > 0 ? (int)$_POST['company_id'] : null,
        'employee_nik' => null_if_empty((string)($_POST['employee_nik'] ?? '')),
        'owner_name' => null_if_empty((string)($_POST['owner_name'] ?? '')),
        'location_label' => null_if_empty((string)($_POST['location_label'] ?? '')),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'location_radius_m' => max(1, (int)(($_POST['location_radius_m'] ?? '') !== '' ? $_POST['location_radius_m'] : 5)),
        'status' => trim((string)($_POST['status'] ?? 'active')),
        'notes' => null_if_empty((string)($_POST['notes'] ?? '')),
    ];
}

function maintenance_job_desks_by_asset(PDO $pdo, ?int $groupId, ?int $typeId): array
{
    if (!db_table_exists($pdo, 'preventive_job_desks') && !db_table_exists($pdo, 'maintenance_jobs')) {
        return [];
    }
    $sources = [];
    if (db_table_exists($pdo, 'preventive_job_desks')) {
        $sources[] = 'SELECT job_desk_name, asset_group_id, asset_type_id FROM preventive_job_desks WHERE is_active=1';
    }
    if (db_table_exists($pdo, 'maintenance_jobs')) {
        $sources[] = 'SELECT job_desk_name, asset_group_id, asset_type_id FROM maintenance_jobs WHERE is_active=1';
    }
    if (empty($sources)) {
        return [];
    }
    $sql = 'SELECT DISTINCT job_desk_name 
            FROM (' . implode(' UNION ', $sources) . ') t 
            WHERE job_desk_name IS NOT NULL AND job_desk_name != ""';
    $params = [];
    if ($groupId !== null && $groupId > 0) {
        $sql .= ' AND (asset_group_id = ? OR asset_group_id IS NULL)';
        $params[] = $groupId;
    }
    if ($typeId !== null && $typeId > 0) {
        $sql .= ' AND (asset_type_id = ? OR asset_type_id IS NULL)';
        $params[] = $typeId;
    }
    $sql .= ' ORDER BY job_desk_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function maintenance_all_job_desks(PDO $pdo): array
{
    return maintenance_job_desks_by_asset($pdo, null, null);
}

function maintenance_asset_form_html(PDO $pdo, array $asset, bool $editing): string
{
    $asset = array_merge(maintenance_asset_defaults(), $asset);
    $selectedGroupId = (int)($asset['asset_group_id'] ?? 0);
    $selectedTypeId = (int)($asset['asset_type_id'] ?? 0);
    $selectedAssetItemId = (int)($asset['asset_item_id'] ?? 0);
    $selectedJobDesk = (string)($asset['job_desk_name'] ?? '');

    // Preload current linked asset item if exists
    $curAssetItem = null;
    if ($selectedAssetItemId > 0) {
        $caiStmt = $pdo->prepare('SELECT ai.*, c.company_name, p.pc_id, p.computer_name, p.owner_name AS pc_owner_name, p.employee_nik AS pc_employee_nik, prn.prn_id 
                                  FROM asset_items ai 
                                  LEFT JOIN asset_companies c ON c.id=ai.company_id 
                                  LEFT JOIN pcs p ON (p.asset_item_id=ai.id OR (ai.source_pc_id IS NOT NULL AND ai.source_pc_id != "" AND p.pc_id COLLATE utf8mb4_unicode_ci = ai.source_pc_id COLLATE utf8mb4_unicode_ci)) 
                                  LEFT JOIN printers prn ON prn.asset_item_id=ai.id 
                                  WHERE ai.id=?');
        $caiStmt->execute([$selectedAssetItemId]);
        $curAssetItem = $caiStmt->fetch(PDO::FETCH_ASSOC);
        if ($curAssetItem) {
            if ($selectedGroupId <= 0 && !empty($curAssetItem['asset_group_id'])) {
                $selectedGroupId = (int)$curAssetItem['asset_group_id'];
                $asset['asset_group_id'] = $selectedGroupId;
            }
            if ($selectedTypeId <= 0 && !empty($curAssetItem['asset_type_id'])) {
                $selectedTypeId = (int)$curAssetItem['asset_type_id'];
                $asset['asset_type_id'] = $selectedTypeId;
            }
            if (empty($asset['company_id']) && !empty($curAssetItem['company_id'])) {
                $asset['company_id'] = (int)$curAssetItem['company_id'];
            }
            if (empty($asset['location_label']) && !empty($curAssetItem['location_label'])) {
                $asset['location_label'] = (string)$curAssetItem['location_label'];
            }
            if (empty($asset['name'])) {
                $asset['name'] = trim((string)$curAssetItem['asset_code'] . ' - ' . (string)$curAssetItem['asset_name']);
            }
            if (empty($asset['maintenance_asset_code']) && !empty($curAssetItem['asset_code'])) {
                $asset['maintenance_asset_code'] = 'MNT-' . (string)$curAssetItem['asset_code'];
            }
            if (empty($asset['owner_name'])) {
                $asset['owner_name'] = trim((string)($curAssetItem['custodian_name'] ?: ($curAssetItem['pc_owner_name'] ?? '')));
            }
            if (empty($asset['employee_nik'])) {
                $asset['employee_nik'] = trim((string)($curAssetItem['custodian_nik'] ?: ($curAssetItem['pc_employee_nik'] ?? '')));
            }
        }
    }

    $typeOptions = '';
    foreach (['pc_set' => 'PC Set / Bundle Maintenance', 'printer' => 'Printer', 'vehicle' => 'Kendaraan', 'facility' => 'Fasilitas / Gedung', 'electronics' => 'Elektronik', 'office_equipment' => 'Peralatan Kantor', 'furniture' => 'Furniture', 'equipment' => 'General Equipment'] as $value => $label) {
        $typeOptions .= '<option value="' . e($value) . '"' . (((string)($asset['maintenance_type'] ?? '') === $value) ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $statusOptions = '';
    foreach (['active' => 'Active', 'spare' => 'Spare', 'inactive' => 'Inactive'] as $value => $label) {
        $statusOptions .= '<option value="' . e($value) . '"' . (($asset['status'] ?? '') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    $desks = [];
    if ($selectedGroupId > 0 || $selectedTypeId > 0) {
        $desks = maintenance_job_desks_by_asset($pdo, $selectedGroupId > 0 ? $selectedGroupId : null, $selectedTypeId > 0 ? $selectedTypeId : null);
    }
    if (empty($desks)) {
        $desks = maintenance_all_job_desks($pdo);
    }
    $jobDeskOptions = '<option value="">- Pilih Job Desk Preventive Maintenance -</option>';
    if ($selectedJobDesk !== '' && !in_array($selectedJobDesk, $desks, true)) {
        $jobDeskOptions .= '<option value="' . e($selectedJobDesk) . '" selected>' . e($selectedJobDesk) . '</option>';
    }
    foreach ($desks as $dName) {
        $sel = ($selectedJobDesk === $dName) ? ' selected' : '';
        $jobDeskOptions .= '<option value="' . e($dName) . '"' . $sel . '>' . e($dName) . '</option>';
    }

    $initialAssetOptions = '<option value="">-- Pilih Komoditas & Kategori Dahulu --</option>';
    if ($curAssetItem) {
        $mLabel = ($curAssetItem['asset_mode'] === 'group') ? '[BUNDLE PARENT]' : '[SINGLE]';
        $initialAssetOptions = '<option value="' . e($curAssetItem['id']) . '" selected>' . e($curAssetItem['asset_code'] . ' - ' . $curAssetItem['asset_name'] . ' ' . $mLabel) . '</option>';
    }

    $html = '<section class="panel"><div class="split"><div><h1>' . ($editing ? 'Edit Maintenance Asset' : 'Tambah Maintenance Asset') . '</h1><p class="muted">Pilih Komoditas dan Kategori untuk mendapatkan daftar Unit Aset fisik (Parent / Standalone) yang bisa dipelihara / diservis.</p></div><div class="actions"><a class="btn" href="' . route_url('maintenance_assets') . '">Kembali ke Daftar</a></div></div></section>';
    $html .= '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '">'
        . '<h2>1. Klasifikasi Aset</h2>'
        . '<div class="grid two">'
        . '<label>Komoditas (Grup Aset) *<select id="maintAssetGroup" name="asset_group_id" onchange="onMaintGroupChanged()" required>' . asset_group_options($pdo, $selectedGroupId, true, '- Pilih Komoditas (Grup Aset) -') . '</select></label>'
        . '<label>Kategori (Tipe Aset) *<select id="maintAssetType" name="asset_type_id" onchange="onMaintTypeChanged()" required>' . asset_type_options($pdo, $selectedTypeId, $selectedGroupId) . '</select></label>'
        . '</div>'
        . '<h2>2. Pemilihan Unit Aset Fisik (Parent / Standalone)</h2>'
        . '<div style="margin-bottom:18px;">'
        . '<label>Pilih Unit Aset yang Dipelihara / Diservis *<select id="maintAssetItemId" name="asset_item_id" onchange="onMaintAssetItemChanged(true)" required style="font-size:14px;padding:8px 12px;width:100%;">' . $initialAssetOptions . '</select></label>'
        . '<small id="maintAssetHint" style="display:block;color:#64748b;margin-top:4px;">Unit aset yang sudah masuk ke Maintenance Asset aktif atau berstatus Child Asset tidak akan muncul di pilihan.</small>'
        . '<div id="maintBundleInfoContainer" style="display:none;margin-top:12px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">'
        . '<div style="font-weight:700;color:#166534;font-size:13px;margin-bottom:6px;display:flex;align-items:center;gap:6px;"><span>📦 Komponen Bundle Fisik yang Terhubung:</span><span class="badge ok" style="font-size:10px;">Auto-Linked ke Item Pemeliharaan</span></div>'
        . '<div id="maintBundleInfoTable"></div>'
        . '</div>'
        . '<div id="maintPcBadgeContainer" style="display:none;margin-top:8px;"></div>'
        . '</div>'
        . '<h2>3. Identitas Maintenance Asset & Job Desk</h2>'
        . '<div class="grid four">'
        . '<label>Maintenance Asset ID *<input id="maintAssetCode" name="maintenance_asset_code" value="' . e($asset['maintenance_asset_code']) . '" placeholder="Contoh: MNT-PC000001" required></label>'
        . '<label>Secret QR Code *<input name="security_code" value="' . e($asset['security_code']) . '" required></label>'
        . '<label>Job Desk Preventive Maintenance<select id="maintJobDesk" name="job_desk_name">' . $jobDeskOptions . '</select></label>'
        . '<label>Tipe Maintenance *<select id="maintType" name="maintenance_type">' . $typeOptions . '</select></label>'
        . '</div>'
        . '<div class="grid three">'
        . '<label style="grid-column: span 2;">Nama Maintenance Asset *<input id="maintName" name="name" value="' . e($asset['name']) . '" required placeholder="Contoh: AST-CMP-000001 - PC Kantor"></label>'
        . '<label>Status *<select name="status">' . $statusOptions . '</select></label>'
        . '</div>'
        . '<h2>4. Lokasi, Perusahaan & Pengguna (Custodian)</h2>'
        . '<div id="maintCustodianBadge" style="' . (!empty($asset['owner_name']) ? 'display:block;' : 'display:none;') . 'background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;margin-bottom:12px;color:#166534;font-size:13px;">' . (!empty($asset['owner_name']) ? ('👤 <strong>Pengguna:</strong> ' . e($asset['owner_name']) . (!empty($asset['employee_nik']) ? ' (NIK: ' . e($asset['employee_nik']) . ')' : '') . ' • <span style="color:#059669;font-weight:600;">Otomatis disinkronkan dari Unit Aset / Data PC</span>') : '') . '</div>'
        . '<div class="grid three">'
        . '<label>Company *<select id="maintCompanyId" name="company_id">' . company_options($pdo, (int)($asset['company_id'] ?? 0)) . '</select></label>'
        . '<label>Nama / Titik Lokasi<input id="maintLocationLabel" name="location_label" list="savedLocationGroups" value="' . e($asset['location_label'] ?? '') . '" placeholder="Gedung / Lantai / Ruangan"></label>'
        . '<label>Radius Meter<input type="number" min="1" name="location_radius_m" value="' . e($asset['location_radius_m'] ?? 5) . '"></label>'
        . '</div>'
        . '<div class="grid two">' . employee_picker_html($asset) . '</div>' . saved_location_datalist_html()
        . '<div class="grid two"><label>Latitude<input name="latitude" value="' . e($asset['latitude'] ?? '') . '"></label><label>Longitude<input name="longitude" value="' . e($asset['longitude'] ?? '') . '"></label></div>'
        . '<label>Catatan Tambahan<textarea name="notes">' . e($asset['notes'] ?? '') . '</textarea></label>'
        . '<div class="actions"><button class="btn primary">Simpan Maintenance Asset</button><a class="btn" href="' . route_url('maintenance_assets') . '">Batal</a></div>'
        . '</form></section>'
        . '<script>
(function(){
    var currentMaintId = ' . (int)$asset['id'] . ';
    var selectedAssetItemId = ' . (int)$selectedAssetItemId . ';
    var groupSel = document.getElementById("maintAssetGroup");
    var typeSel = document.getElementById("maintAssetType");
    var itemSel = document.getElementById("maintAssetItemId");
    var deskSel = document.getElementById("maintJobDesk");
    var codeInp = document.getElementById("maintAssetCode");
    var nameInp = document.getElementById("maintName");
    var compSel = document.getElementById("maintCompanyId");
    var typeMaintSel = document.getElementById("maintType");
    var nikInp = document.getElementById("employeeNik");
    var ownerInp = document.getElementById("ownerNameInput");
    var locInp = document.getElementById("maintLocationLabel");
    var bundleContainer = document.getElementById("maintBundleInfoContainer");
    var bundleTable = document.getElementById("maintBundleInfoTable");
    var pcBadgeContainer = document.getElementById("maintPcBadgeContainer");

    var itemsCache = {};

    window.onMaintGroupChanged = function(){
        var gid = groupSel.value;
        typeSel.innerHTML = "<option value=\"\">- Memuat Kategori... -</option>";
        itemSel.innerHTML = "<option value=\"\">-- Pilih Kategori Terlebih Dahulu --</option>";
        renderBundleInfo(null);
        if(!gid){
            typeSel.innerHTML = "<option value=\"\">- Pilih Kategori -</option>";
            return;
        }
        fetch("index.php?route=api_asset_types&group_id=" + gid)
            .then(function(r){ return r.json(); })
            .then(function(types){
                typeSel.innerHTML = "<option value=\"\">- Pilih Kategori (Tipe Aset) -</option>";
                types.forEach(function(t){
                    var opt = document.createElement("option");
                    opt.value = t.id;
                    opt.textContent = t.type_code + " - " + t.type_name;
                    typeSel.appendChild(opt);
                });
                loadJobDesks(gid, typeSel.value, true);
                loadEligibleItems(gid, typeSel.value, selectedAssetItemId);
            })
            .catch(function(){
                typeSel.innerHTML = "<option value=\"\">- Pilih Kategori (Tipe Aset) -</option>";
            });
    };

    window.onMaintTypeChanged = function(){
        var gid = groupSel.value;
        var tid = typeSel.value;
        loadJobDesks(gid, tid, true);
        loadEligibleItems(gid, tid, selectedAssetItemId);
    };

    function loadJobDesks(groupId, typeId, autoSelect) {
        var url = "index.php?route=api_job_desks&group_id=" + (groupId || 0) + "&type_id=" + (typeId || 0);
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(desks){
                var cur = deskSel.value;
                deskSel.innerHTML = "<option value=\"\">- Pilih Job Desk Preventive Maintenance -</option>";
                var foundMatch = false;
                desks.forEach(function(d){
                    var opt = document.createElement("option");
                    opt.value = d.job_desk_name;
                    opt.textContent = d.job_desk_name;
                    if (d.job_desk_name === cur) {
                        opt.selected = true;
                        foundMatch = true;
                    }
                    deskSel.appendChild(opt);
                });
                if (!foundMatch && autoSelect && desks.length === 1) {
                    deskSel.selectedIndex = 1;
                }
            })
            .catch(function(){});
    }

    function loadEligibleItems(gid, tid, keepSelectedId){
        var targetSelectId = keepSelectedId || (itemSel.value ? parseInt(itemSel.value, 10) : selectedAssetItemId);
        itemSel.innerHTML = "<option value=\"\">⏳ Memuat unit aset yang tersedia...</option>";
        var url = "index.php?route=api_eligible_maintenance_asset_items&group_id=" + (gid || 0) + "&type_id=" + (tid || 0) + "&maintenance_asset_id=" + currentMaintId + "&current_asset_item_id=" + selectedAssetItemId;
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(items){
                itemsCache = {};
                itemSel.innerHTML = "";
                if(!items || items.length === 0){
                    itemSel.innerHTML = "<option value=\"\">-- Tidak ada Unit Aset tersedia (semua sudah masuk maintenance atau berstatus Child) --</option>";
                    renderBundleInfo(null);
                    return;
                }
                var defaultOpt = document.createElement("option");
                defaultOpt.value = "";
                defaultOpt.textContent = "-- Pilih Unit Aset Fisik (" + items.length + " unit tersedia) --";
                itemSel.appendChild(defaultOpt);

                var autoPickIdx = -1;
                items.forEach(function(it, idx){
                    itemsCache[it.id] = it;
                    var opt = document.createElement("option");
                    opt.value = it.id;
                    var modeLabel = (it.asset_mode === "group") ? "[BUNDLE PARENT]" : "[SINGLE]";
                    var custLabel = it.custodian_name ? (" • Custodian: " + it.custodian_name) : "";
                    var pcLabel = it.pc_id ? (" • PC: " + it.pc_id) : "";
                    opt.textContent = it.asset_code + " - " + it.asset_name + " " + modeLabel + custLabel + pcLabel;
                    if(targetSelectId && parseInt(it.id, 10) === targetSelectId){
                        opt.selected = true;
                        autoPickIdx = idx;
                    }
                    itemSel.appendChild(opt);
                });

                if(autoPickIdx !== -1){
                    onMaintAssetItemChanged(false);
                } else {
                    renderBundleInfo(null);
                }
            })
            .catch(function(err){
                itemSel.innerHTML = "<option value=\"\">Gagal memuat unit aset: " + err.message + "</option>";
            });
    }

    window.onMaintAssetItemChanged = function(isUserTriggered){
        if(isUserTriggered === undefined) isUserTriggered = true;
        var itemId = itemSel.value;
        var it = itemsCache[itemId];
        if(!it){
            renderBundleInfo(null);
            return;
        }

        renderBundleInfo(it);

        if(isUserTriggered || !codeInp.value || codeInp.value.indexOf("MNT-") === 0){
            if(!currentMaintId || isUserTriggered){
                codeInp.value = "MNT-" + it.asset_code;
            }
        }
        if(isUserTriggered || !nameInp.value){
            nameInp.value = it.asset_code + " - " + it.asset_name;
        }
        if(it.company_id && compSel){
            compSel.value = it.company_id;
        }
        if(it.location_label && locInp && (isUserTriggered || !locInp.value)){
            locInp.value = it.location_label;
        }
        var effOwnerInp = document.getElementById("ownerNameInput") || document.querySelector("input[name=\"owner_name\"]");
        var effNikInp = document.getElementById("employeeNik") || document.querySelector("input[name=\"employee_nik\"]");
        var empList = document.getElementById("employeePortalList");
        var empSearch = document.getElementById("employeePortalSearch");
        var custBadge = document.getElementById("maintCustodianBadge");

        if(it.custodian_name || it.custodian_nik){
            if(effOwnerInp && (isUserTriggered || !effOwnerInp.value)){
                effOwnerInp.value = it.custodian_name || "";
            }
            if(effNikInp && (isUserTriggered || !effNikInp.value)){
                effNikInp.value = it.custodian_nik || "";
            }
            if(empSearch && it.custodian_name && (isUserTriggered || !empSearch.value)){
                empSearch.value = it.custodian_name;
            }
            if(empList){
                var found = false;
                for(var eIdx = 0; eIdx < empList.options.length; eIdx++){
                    var oVal = empList.options[eIdx].value;
                    var oTxt = empList.options[eIdx].textContent.toLowerCase();
                    if((it.custodian_nik && oVal === it.custodian_nik) || 
                       (it.custodian_name && oTxt.indexOf(it.custodian_name.toLowerCase()) !== -1)){
                        empList.selectedIndex = eIdx;
                        found = true;
                        break;
                    }
                }
                if(!found && isUserTriggered){
                    empList.selectedIndex = -1;
                }
            }
            if(custBadge){
                var badgeInfo = "👤 <strong>Pengguna:</strong> " + escapeHtml(it.custodian_name || "-");
                if(it.custodian_nik){
                    badgeInfo += " (NIK: " + escapeHtml(it.custodian_nik) + ")";
                }
                badgeInfo += " • <span style=\"color:#059669;font-weight:600;\">Otomatis disinkronkan dari Unit Aset / Data PC</span>";
                custBadge.innerHTML = badgeInfo;
                custBadge.style.display = "block";
            }
        } else {
            if(custBadge && isUserTriggered){
                custBadge.style.display = "none";
                custBadge.innerHTML = "";
            }
        }

        // Auto infer maintenance type
        if(typeMaintSel && (isUserTriggered || !typeMaintSel.value || typeMaintSel.value === "equipment")){
            var cat = ((it.asset_type || "") + " " + (it.asset_category || "")).toLowerCase();
            if(cat.indexOf("computer") !== -1 || cat.indexOf("komputer") !== -1 || cat.indexOf("pc") !== -1 || cat.indexOf("laptop") !== -1 || cat.indexOf("server") !== -1){
                typeMaintSel.value = "pc_set";
            } else if(cat.indexOf("printer") !== -1){
                typeMaintSel.value = "printer";
            } else if(cat.indexOf("kendaraan") !== -1 || cat.indexOf("mobil") !== -1 || cat.indexOf("motor") !== -1 || cat.indexOf("vehicle") !== -1){
                typeMaintSel.value = "vehicle";
            } else if(cat.indexOf("elektronik") !== -1 || cat.indexOf("ac") !== -1){
                typeMaintSel.value = "electronics";
            } else if(cat.indexOf("kantor") !== -1 || cat.indexOf("office") !== -1){
                typeMaintSel.value = "office_equipment";
            } else if(cat.indexOf("furniture") !== -1){
                typeMaintSel.value = "furniture";
            }
        }
    };

    function renderBundleInfo(it){
        if(!bundleContainer) return;
        if(!it || it.asset_mode !== "group" || !it.bundle_children || it.bundle_children.length === 0){
            bundleContainer.style.display = "none";
        } else {
            bundleContainer.style.display = "block";
            var h = "<table style=\"width:100%;font-size:12px;background:#fff;border-radius:6px;border-collapse:collapse;margin-top:6px;\">";
            h += "<thead><tr style=\"background:#dcfce7;text-align:left;\"><th style=\"padding:6px 8px;\">Kode Aset Anggota</th><th style=\"padding:6px 8px;\">Nama Perangkat</th><th style=\"padding:6px 8px;\">Role Bundle</th><th style=\"padding:6px 8px;\">SN</th><th style=\"padding:6px 8px;\">Tipe</th></tr></thead><tbody>";
            it.bundle_children.forEach(function(c){
                h += "<tr style=\"border-bottom:1px solid #f0fdf4;\"><td style=\"padding:6px 8px;font-weight:700;\">" + escapeHtml(c.asset_code) + "</td><td style=\"padding:6px 8px;\">" + escapeHtml(c.asset_name) + "</td><td style=\"padding:6px 8px;color:#15803d;font-weight:600;\">" + escapeHtml(c.role_name || "-") + "</td><td style=\"padding:6px 8px;color:#64748b;\">" + escapeHtml(c.serial_number || "-") + "</td><td style=\"padding:6px 8px;\">" + escapeHtml(c.asset_type || "-") + "</td></tr>";
            });
            h += "</tbody></table>";
            bundleTable.innerHTML = h;
        }

        if(pcBadgeContainer){
            if(it && (it.pc_id || it.prn_id)){
                var bHtml = "";
                if(it.pc_id){
                    bHtml += "<span class=\"badge\" style=\"background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;padding:5px 12px;font-weight:600;\">🖥️ Data PC Terhubung: " + escapeHtml(it.pc_id) + (it.computer_name ? " (" + escapeHtml(it.computer_name) + ")" : "") + "</span> ";
                }
                if(it.prn_id){
                    bHtml += "<span class=\"badge\" style=\"background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:5px 12px;font-weight:600;\">🖨️ Data Printer Terhubung: " + escapeHtml(it.prn_id) + "</span>";
                }
                pcBadgeContainer.innerHTML = bHtml;
                pcBadgeContainer.style.display = "block";
            } else {
                pcBadgeContainer.style.display = "none";
                pcBadgeContainer.innerHTML = "";
            }
        }
    }

    function escapeHtml(s){
        if(!s) return "";
        return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }

    // Initial load if group and type are selected
    if(groupSel && groupSel.value){
        loadJobDesks(groupSel.value, typeSel ? typeSel.value : 0, false);
        loadEligibleItems(groupSel.value, typeSel ? typeSel.value : 0, selectedAssetItemId);
    }
})();
</script>';
    return $html;
}

function maintenance_asset_item_manager_html(PDO $pdo, int $maintenanceAssetId): string
{
    $stmt = $pdo->prepare('SELECT mi.id AS maintenance_item_id, ai.id AS asset_item_id, ai.asset_code, ai.asset_name, ai.asset_type, ai.asset_mode, ai.serial_number, ac.company_name, mi.role_name, mi.attached_at, mi.detached_at, NULL AS parent_asset_code FROM maintenance_asset_items mi JOIN asset_items ai ON ai.id=mi.asset_item_id LEFT JOIN asset_companies ac ON ac.id=ai.company_id WHERE mi.maintenance_asset_id=? AND mi.detached_at IS NULL ORDER BY mi.attached_at DESC, mi.id DESC');
    $stmt->execute([$maintenanceAssetId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activeIds = array_flip(array_map('intval', array_column($rows, 'asset_item_id')));

    $stmt = $pdo->prepare('SELECT aim.id AS member_id, c.id AS asset_item_id, c.asset_code, c.asset_name, c.asset_type, c.asset_mode, c.serial_number, ac.company_name, aim.role_name, aim.attached_at, aim.detached_at, p.asset_code AS parent_asset_code FROM asset_item_members aim JOIN asset_items c ON c.id=aim.child_asset_item_id JOIN asset_items p ON p.id=aim.parent_asset_item_id JOIN maintenance_asset_items mai ON mai.asset_item_id=aim.parent_asset_item_id AND mai.maintenance_asset_id=? AND mai.detached_at IS NULL LEFT JOIN asset_companies ac ON ac.id=c.company_id WHERE aim.detached_at IS NULL ORDER BY aim.attached_at DESC, aim.id DESC');
    $stmt->execute([$maintenanceAssetId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($activeIds[(int)$row['asset_item_id']])) {
            continue;
        }
        $rows[] = $row;
    }

    $html = '<section class="panel"><h2>Asset Item Fisik Dalam Maintenance Asset</h2><p class="muted">Contoh: Maintenance Asset PC Set bisa berisi CPU, monitor, UPS, dan item pendukung lain. Jika perangkat ditukar, lepas item lama lalu pasang item baru.</p><form method="post" action="' . route_url('maintenance_asset_item_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="attach"><input type="hidden" name="maintenance_asset_id" value="' . e($maintenanceAssetId) . '"><div class="grid three"><label>Asset Item<select name="asset_item_id" required>' . asset_item_options($pdo) . '</select></label><label>Role<input name="role_name" placeholder="CPU / Monitor / Printer / Unit"></label><label>&nbsp;<button class="btn primary">Pasang Item</button></label></div></form><table><tr><th>Asset Item</th><th>Role</th><th>Pasang</th><th>Lepas</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $rawMode = (string)($row['asset_mode'] ?? 'standalone');
        $modeLabel = $rawMode === 'group' ? 'Bundle (Parent)' : ($rawMode === 'child' ? 'Bundle (Child)' : 'Single');
        $html .= '<tr><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . ' <span class="muted">(' . e($row['asset_type']) . ' / ' . e($modeLabel) . ')</span>' . ($row['parent_asset_code'] ? '<br><span class="muted">via asset gabungan ' . e($row['parent_asset_code']) . '</span>' : '') . '</td><td>' . e($row['role_name'] ?: '-') . '</td><td>' . e($row['attached_at']) . '</td><td>' . e($row['detached_at'] ?: '-') . '</td><td>';
        if (empty($row['detached_at'])) {
            $html .= '<form method="post" action="' . route_url('maintenance_asset_item_action') . '" onsubmit="return confirm(\'Lepas asset item ini dari maintenance asset?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="detach"><input type="hidden" name="maintenance_asset_id" value="' . e($maintenanceAssetId) . '"><input type="hidden" name="member_id" value="' . e($row['maintenance_item_id'] ?? $row['member_id']) . '"><button class="btn danger">Pisahkan</button></form>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</table></section>';
}

function link_maintenance_asset_item(PDO $pdo, int $maintenanceAssetId, int $assetItemId, string $roleName, ?string $attachedAt = null): void
{
    if ($maintenanceAssetId <= 0 || $assetItemId <= 0) {
        return;
    }
    $attachedAt = normalize_date_input((string)($attachedAt ?? date('Y-m-d'))) ?: date('Y-m-d');
    detach_asset_item_from_other_maintenance_assets($pdo, $assetItemId, $maintenanceAssetId, $attachedAt, 'Auto detached karena asset item dipasang ke Maintenance Asset ID #' . $maintenanceAssetId);
    $stmt = $pdo->prepare('SELECT id FROM maintenance_asset_items WHERE maintenance_asset_id=? AND asset_item_id=? AND detached_at IS NULL LIMIT 1');
    $stmt->execute([$maintenanceAssetId, $assetItemId]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $pdo->prepare('UPDATE maintenance_asset_items SET role_name=?, attached_at=? WHERE id=?')->execute([$roleName ?: 'Asset Item', $attachedAt, $existingId]);
        return;
    }
    $pdo->prepare('INSERT INTO maintenance_asset_items (maintenance_asset_id, asset_item_id, role_name, attached_at) VALUES (?, ?, ?, ?)')->execute([$maintenanceAssetId, $assetItemId, $roleName ?: 'Asset Item', $attachedAt]);
}

function detach_asset_item_from_other_maintenance_assets(PDO $pdo, int $assetItemId, int $keepMaintenanceAssetId, string $date, string $note): int
{
    $stmt = $pdo->prepare('SELECT id FROM maintenance_asset_items WHERE asset_item_id=? AND maintenance_asset_id<>? AND detached_at IS NULL');
    $stmt->execute([$assetItemId, $keepMaintenanceAssetId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        return 0;
    }
    foreach ($ids as $id) {
        $pdo->prepare('UPDATE maintenance_asset_items SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\n" . $note, $id]);
    }
    return count($ids);
}

function detach_asset_item_from_maintenance_assets(PDO $pdo, int $assetItemId, ?int $maintenanceAssetId, string $date, string $note): int
{
    $count = 0;
    if ($maintenanceAssetId) {
        $stmt = $pdo->prepare('SELECT id FROM maintenance_asset_items WHERE asset_item_id=? AND maintenance_asset_id=? AND detached_at IS NULL');
        $stmt->execute([$assetItemId, $maintenanceAssetId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM maintenance_asset_items WHERE asset_item_id=? AND detached_at IS NULL');
        $stmt->execute([$assetItemId]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
        $pdo->prepare('UPDATE maintenance_asset_items SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\n" . $note, (int)$memberId]);
        $count++;
    }
    return $count;
}

function handle_route_maintenance_assets(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('Maintenance Assets', $user);
    if (function_exists('asset_nav_html')) {
        echo asset_nav_html();
    }
    $rows = maintenance_asset_rows($pdo);
    echo '<section class="panel"><div class="split"><div><h1>Maintenance Assets</h1><p class="muted">Master unit pemeliharaan untuk penjadwalan preventive dan perbaikan corrective maintenance.</p></div><div class="actions"><a class="btn primary" href="' . route_url('maintenance_asset_form') . '">Tambah Maintenance Asset</a></div></div>' . maintenance_assets_table($pdo, $rows) . '</section>';
    render_footer();
}

function handle_route_maintenance_asset_form(PDO $pdo): void
{
    $user = require_role(['admin']);
    $id = (int)($_GET['id'] ?? 0);
    $asset = maintenance_asset_defaults();
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if (!$found) {
            http_response_code(404);
            exit('Maintenance asset tidak ditemukan.');
        }
        $asset = array_merge($asset, $found);
    } elseif (!empty($_GET['asset_item_id'])) {
        $targetAssetId = (int)$_GET['asset_item_id'];
        $asset['asset_item_id'] = $targetAssetId;
        // Cek apakah sudah terdaftar di maintenance asset aktif
        $chkExisting = $pdo->prepare('SELECT id, maintenance_asset_code FROM maintenance_assets WHERE asset_item_id = ? AND status <> "inactive" LIMIT 1');
        $chkExisting->execute([$targetAssetId]);
        $existingMaint = $chkExisting->fetch(PDO::FETCH_ASSOC);
        if ($existingMaint) {
            flash('Unit Aset ini sudah terdaftar di Maintenance Asset (' . $existingMaint['maintenance_asset_code'] . '). Anda dialihkan ke halaman edit.', 'info');
            redirect_to('maintenance_asset_form', ['id' => (int)$existingMaint['id']]);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = maintenance_asset_post_data($pdo, $id);
        if ($data['maintenance_asset_code'] === '' || $data['security_code'] === '' || $data['name'] === '') {
            flash('Maintenance Asset ID, Secret QR, dan nama wajib diisi.', 'err');
            redirect_to('maintenance_asset_form', $id > 0 ? ['id' => $id] : []);
        }
        if (empty($data['asset_item_id'])) {
            flash('Unit Aset fisik (Parent / Standalone) wajib dipilih.', 'err');
            redirect_to('maintenance_asset_form', $id > 0 ? ['id' => $id] : []);
        }

        try {
            $chkStmt = $pdo->prepare('SELECT ai.id, ai.asset_code, ai.asset_name, ai.asset_mode, ai.custodian_name, ai.custodian_nik, ai.source_pc_id, p.pc_id, p.owner_name AS pc_owner_name, p.employee_nik AS pc_employee_nik, prn.prn_id 
                                      FROM asset_items ai 
                                      LEFT JOIN pcs p ON (p.asset_item_id = ai.id OR (ai.source_pc_id IS NOT NULL AND ai.source_pc_id != "" AND p.pc_id COLLATE utf8mb4_unicode_ci = ai.source_pc_id COLLATE utf8mb4_unicode_ci)) 
                                      LEFT JOIN printers prn ON prn.asset_item_id = ai.id 
                                      WHERE ai.id=? LIMIT 1');
            $chkStmt->execute([(int)$data['asset_item_id']]);
            $chkItem = $chkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$chkItem) {
                flash('Unit Aset fisik tidak ditemukan.', 'err');
                redirect_to('maintenance_asset_form', $id > 0 ? ['id' => $id] : []);
            }
            $effOwner = trim((string)($chkItem['custodian_name'] ?: ($chkItem['pc_owner_name'] ?? '')));
            $effNik = trim((string)($chkItem['custodian_nik'] ?: ($chkItem['pc_employee_nik'] ?? '')));
            if (empty($data['owner_name']) && $effOwner !== '') {
                $data['owner_name'] = $effOwner;
            }
            if (empty($data['employee_nik']) && $effNik !== '') {
                $data['employee_nik'] = $effNik;
            }
            if (($chkItem['asset_mode'] ?? '') === 'child') {
                flash('Unit Aset dengan status Bundle (Child Asset) tidak boleh dijadikan Maintenance Asset.', 'err');
                redirect_to('maintenance_asset_form', $id > 0 ? ['id' => $id] : []);
            }
            if (db_table_exists($pdo, 'asset_item_members')) {
                $cMemStmt = $pdo->prepare('SELECT 1 FROM asset_item_members WHERE child_asset_item_id=? AND detached_at IS NULL LIMIT 1');
                $cMemStmt->execute([(int)$data['asset_item_id']]);
                if ($cMemStmt->fetchColumn()) {
                    flash('Unit Aset ini aktif sebagai anggota bundle lain dan tidak dapat dijadikan Maintenance Asset.', 'err');
                    redirect_to('maintenance_asset_form', $id > 0 ? ['id' => $id] : []);
                }
            }
            $dupStmt = $pdo->prepare('SELECT id, maintenance_asset_code FROM maintenance_assets WHERE asset_item_id=? AND id<>? AND status<>"inactive" LIMIT 1');
            $dupStmt->execute([(int)$data['asset_item_id'], $id]);
            $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                flash('Unit Aset ini sudah digunakan di Maintenance Asset ' . $dup['maintenance_asset_code'] . '.', 'err');
                redirect_to('maintenance_asset_form', $id > 0 ? ['id' => $id] : []);
            }

            if ($id > 0) {
                $values = array_values($data);
                $values[] = $id;
                $pdo->prepare('UPDATE maintenance_assets SET maintenance_asset_code=?, security_code=?, maintenance_type=?, asset_group_id=?, asset_type_id=?, asset_item_id=?, job_desk_name=?, name=?, company_id=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=?, status=?, notes=? WHERE id=?')->execute($values);
                flash('Maintenance asset berhasil diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO maintenance_assets (maintenance_asset_code, security_code, maintenance_type, asset_group_id, asset_type_id, asset_item_id, job_desk_name, name, company_id, employee_nik, owner_name, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                flash('Maintenance asset berhasil ditambahkan.');
            }

            $linkedPc = $chkItem['source_pc_id'] ?: ($chkItem['pc_id'] ?? null);
            if ($linkedPc && db_column_exists($pdo, 'maintenance_assets', 'pc_id')) {
                $pdo->prepare('UPDATE maintenance_assets SET pc_id = ? WHERE id = ?')->execute([$linkedPc, $id]);
            }

            // Otomatis tautkan unit aset fisik utama ke maintenance_asset_items
            link_maintenance_asset_item($pdo, $id, (int)$data['asset_item_id'], 'Unit Utama');

            // Jika unit aset adalah bundle parent, tautkan semua komponen bundle anak
            if (db_table_exists($pdo, 'asset_item_members')) {
                $aimStmt = $pdo->prepare('SELECT child_asset_item_id, role_name FROM asset_item_members WHERE parent_asset_item_id=? AND detached_at IS NULL');
                $aimStmt->execute([(int)$data['asset_item_id']]);
                foreach ($aimStmt->fetchAll(PDO::FETCH_ASSOC) as $cm) {
                    link_maintenance_asset_item($pdo, $id, (int)$cm['child_asset_item_id'], $cm['role_name'] ?: 'Anggota Bundle');
                }
            }

            // Sinkronisasi data PC jika unit aset terkait PC
            if (db_table_exists($pdo, 'pcs')) {
                $fPc = trim((string)($chkItem['source_pc_id'] ?: ($chkItem['pc_id'] ?? '')));
                if ($fPc === '') {
                    $pcStmt = $pdo->prepare('SELECT pc_id FROM pcs WHERE asset_item_id=? LIMIT 1');
                    $pcStmt->execute([(int)$data['asset_item_id']]);
                    $fPc = trim((string)($pcStmt->fetchColumn() ?: ''));
                }
                if ($fPc !== '') {
                    $pdo->prepare('UPDATE maintenance_assets SET pc_id=? WHERE id=?')->execute([$fPc, $id]);
                    $pdo->prepare('UPDATE pcs SET maintenance_asset_id=? WHERE pc_id COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci')->execute([$id, $fPc]);
                }
            }

            // Sinkronisasi data Printer jika unit aset terkait Printer
            if (db_table_exists($pdo, 'printers')) {
                $fPrn = trim((string)($chkItem['prn_id'] ?? ''));
                if ($fPrn === '') {
                    $prnStmt = $pdo->prepare('SELECT prn_id FROM printers WHERE asset_item_id=? LIMIT 1');
                    $prnStmt->execute([(int)$data['asset_item_id']]);
                    $fPrn = trim((string)($prnStmt->fetchColumn() ?: ''));
                }
                if ($fPrn !== '') {
                    $pdo->prepare('UPDATE maintenance_assets SET printer_id=? WHERE id=?')->execute([$fPrn, $id]);
                    $pdo->prepare('UPDATE printers SET maintenance_asset_id=? WHERE prn_id COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci')->execute([$id, $fPrn]);
                }
            }

            redirect_to('maintenance_asset_form', ['id' => $id]);
        } catch (Throwable $e) {
            flash('Gagal simpan maintenance asset: ' . $e->getMessage(), 'err');
        }
    }
    render_header($id > 0 ? 'Edit Maintenance Asset' : 'Tambah Maintenance Asset', $user);
    if (function_exists('asset_nav_html')) {
        echo asset_nav_html();
    }
    echo maintenance_asset_form_html($pdo, $asset, $id > 0);
    if ($id > 0) {
        echo maintenance_asset_item_manager_html($pdo, $id);
    }
    render_footer();
}

function handle_route_maintenance_asset_item_action(PDO $pdo): void
{
    $user = require_role(['admin']);
    $maintenanceAssetId = (int)($_POST['maintenance_asset_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($maintenanceAssetId <= 0) {
        redirect_to('maintenance_assets');
    }
    if ($action === 'attach') {
        $assetItemId = (int)($_POST['asset_item_id'] ?? 0);
        if ($assetItemId > 0) {
            $stmtCheck = $pdo->prepare('SELECT asset_code, asset_mode FROM asset_items WHERE id=?');
            $stmtCheck->execute([$assetItemId]);
            $itemInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            $isChild = false;
            if ($itemInfo && ($itemInfo['asset_mode'] === 'child')) {
                $isChild = true;
            }
            if (!$isChild && db_table_exists($pdo, 'asset_item_members')) {
                $stmtMem = $pdo->prepare('SELECT 1 FROM asset_item_members WHERE child_asset_item_id=? AND detached_at IS NULL LIMIT 1');
                $stmtMem->execute([$assetItemId]);
                if ($stmtMem->fetchColumn()) {
                    $isChild = true;
                }
            }
            if ($isChild) {
                flash('Unit aset dengan status Bundle (Child Asset) tidak dapat dimasukkan ke Maintenance Asset.', 'err');
                redirect_to('maintenance_asset_form', ['id' => $maintenanceAssetId]);
            }

            $oldParent = active_parent_asset_item($pdo, $assetItemId);
            if ($oldParent) {
                $attachedAt = normalize_date_input((string)($_POST['attached_at'] ?? date('Y-m-d')));
                $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$attachedAt, "\nDipindahkan ke Maintenance Asset ID #" . $maintenanceAssetId, (int)$oldParent['id']]);
                $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?)')->execute([$assetItemId, (int)$oldParent['parent_asset_item_id'], $attachedAt, 'Dipindahkan dari asset gabungan ' . ($oldParent['parent_asset_code'] ?? ''), $user['name'] ?? '']);
            }
            link_maintenance_asset_item($pdo, $maintenanceAssetId, $assetItemId, trim((string)($_POST['role_name'] ?? 'Asset Item')));
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, movement_date, reason, pic) VALUES (?, CURDATE(), ?, ?)')->execute([$assetItemId, 'Dipakai sebagai bagian Maintenance Asset ID #' . $maintenanceAssetId, $user['name'] ?? '']);
            flash('Asset item berhasil ditautkan ke maintenance asset.');
        }
    }
    if ($action === 'detach') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM maintenance_asset_items WHERE id=? AND maintenance_asset_id=?');
        $stmt->execute([$memberId, $maintenanceAssetId]);
        $member = $stmt->fetch();
        if ($member) {
            $pdo->prepare('UPDATE maintenance_asset_items SET detached_at=CURDATE(), notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute(["\nDetached dari maintenance asset.", $memberId]);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, movement_date, reason, pic) VALUES (?, CURDATE(), ?, ?)')->execute([(int)$member['asset_item_id'], 'Dilepas dari Maintenance Asset ID #' . $maintenanceAssetId, $user['name'] ?? '']);
            flash('Asset item berhasil dilepas dari maintenance asset.');
        }
    }
    redirect_to('maintenance_asset_form', ['id' => $maintenanceAssetId]);
}
