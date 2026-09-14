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
    if ($hasPcs) {
        $sql = 'SELECT ma.id, ma.maintenance_asset_code, ma.name, ma.maintenance_type 
                FROM maintenance_assets ma 
                LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci 
                WHERE (ma.pc_id IS NULL OR ma.pc_id = "" OR (p.asset_item_id IS NOT NULL AND p.asset_item_id > 0))
                ORDER BY ma.maintenance_asset_code';
    } else {
        $sql = 'SELECT id, maintenance_asset_code, name, maintenance_type FROM maintenance_assets ORDER BY maintenance_asset_code';
    }
    foreach ($pdo->query($sql) as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $label = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (' . $row['maintenance_type'] . ')';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Mengambil daftar maintenance asset.
 * SYARAT: PC yang belum disinkronkan dengan asset item (p.asset_item_id IS NULL / 0) TIDAK AKAN MUNCUL!
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
                   COUNT(mi.id) item_count 
            FROM maintenance_assets ma 
            LEFT JOIN asset_groups ag ON ag.id = ma.asset_group_id
            LEFT JOIN asset_types at ON at.id = ma.asset_type_id
            ' . ($hasPcs ? 'LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci LEFT JOIN asset_items ai_pc ON ai_pc.id = p.asset_item_id ' : '') . '
            LEFT JOIN asset_companies c ON c.id=ma.company_id 
            LEFT JOIN maintenance_asset_items mi ON mi.maintenance_asset_id=ma.id AND mi.detached_at IS NULL 
            ' . ($hasPcs ? 'WHERE (ma.pc_id IS NULL OR ma.pc_id = "" OR (p.asset_item_id IS NOT NULL AND p.asset_item_id > 0 AND (ai_pc.asset_mode IS NULL OR ai_pc.asset_mode <> "child"))) ' : '') . '
            GROUP BY ma.id 
            ORDER BY ma.updated_at DESC, ma.maintenance_asset_code';
    return $pdo->query($sql)->fetchAll();
}

function maintenance_assets_table(PDO $pdo, array $rows): string
{
    $html = '<table><tr><th>Maintenance Asset ID</th><th>Asset Group / Type</th><th>Job Desk Preventive</th><th>Tipe</th><th>Nama</th><th>Company</th><th>Pengguna / Lokasi</th><th>Asset Item</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $links = [];
        if (!empty($row['pc_id'])) {
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

        $html .= '<tr>'
            . '<td><strong>' . e($row['maintenance_asset_code']) . '</strong><br><span class="muted">' . e(implode(' / ', $links) ?: 'General asset') . '</span></td>'
            . '<td><span class="badge">' . e($grpLabel) . '</span><br><span class="muted" style="font-size:12px;font-weight:600;">' . e($typeLabel) . '</span></td>'
            . '<td>' . $deskBadge . '</td>'
            . '<td>' . e($row['maintenance_type']) . '</td>'
            . '<td>' . e($row['name']) . '</td>'
            . '<td>' . e($row['company_name'] ?: '-') . '</td>'
            . '<td>' . e($row['owner_name'] ?: '-') . '<br><span class="muted">' . e($row['location_label'] ?: '-') . '</span></td>'
            . '<td>' . e($row['item_count']) . ' item</td>'
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

function maintenance_asset_form_html(PDO $pdo, array $asset, bool $editing): string
{
    $asset = array_merge(maintenance_asset_defaults(), $asset);
    $typeOptions = '';
    foreach (['pc_set' => 'PC Set / Bundle Maintenance', 'printer' => 'Printer', 'vehicle' => 'Kendaraan', 'facility' => 'Fasilitas / Gedung', 'electronics' => 'Elektronik', 'office_equipment' => 'Peralatan Kantor', 'furniture' => 'Furniture', 'equipment' => 'General Equipment'] as $value => $label) {
        $typeOptions .= '<option value="' . e($value) . '"' . (((string)($asset['maintenance_type'] ?? '') === $value) ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $statusOptions = '';
    foreach (['active' => 'Active', 'spare' => 'Spare', 'inactive' => 'Inactive'] as $value => $label) {
        $statusOptions .= '<option value="' . e($value) . '"' . (($asset['status'] ?? '') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    $selectedGroupId = (int)($asset['asset_group_id'] ?? 0);
    $selectedTypeId = (int)($asset['asset_type_id'] ?? 0);
    $selectedJobDesk = (string)($asset['job_desk_name'] ?? '');

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

    $html = '<section class="panel"><div class="split"><div><h1>' . ($editing ? 'Edit Maintenance Asset' : 'Tambah Maintenance Asset') . '</h1><p class="muted">Maintenance Asset ID adalah identitas unit untuk preventive dan corrective maintenance. Asset Item fisik dipasang di bagian anggota di bawah form.</p></div><div class="actions"><a class="btn" href="' . route_url('maintenance_assets') . '">Kembali</a></div></div></section>';
    $html .= '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '">'
        . '<div class="grid four">'
        . '<label>Maintenance Asset ID<input name="maintenance_asset_code" value="' . e($asset['maintenance_asset_code']) . '" placeholder="Contoh: MNT-PC000001" required></label>'
        . '<label>Security Code<input name="security_code" value="' . e($asset['security_code']) . '" required></label>'
        . '<label>Asset Group<select id="maintAssetGroup" name="asset_group_id"><option value="">- Pilih Asset Group -</option>' . asset_group_options($pdo, $selectedGroupId, false) . '</select></label>'
        . '<label>Asset Type<select id="maintAssetType" name="asset_type_id"><option value="">- Pilih Asset Type -</option>' . asset_type_options($pdo, $selectedTypeId, $selectedGroupId) . '</select></label>'
        . '</div>'
        . '<div class="grid three">'
        . '<label>Job Desk Preventive Maintenance<select id="maintJobDesk" name="job_desk_name">' . $jobDeskOptions . '</select></label>'
        . '<label>Tipe Maintenance<select name="maintenance_type">' . $typeOptions . '</select></label>'
        . '<label>Status<select name="status">' . $statusOptions . '</select></label>'
        . '</div>'
        . '<div class="grid two">'
        . '<label>Nama Maintenance Asset<input name="name" value="' . e($asset['name']) . '" required></label>'
        . '<label>Company<select name="company_id">' . company_options($pdo, (int)($asset['company_id'] ?? 0)) . '</select></label>'
        . '</div>'
        . '<div class="grid two">' . employee_picker_html($asset) . '<label>Nama / Titik Lokasi<input name="location_label" list="savedLocationGroups" value="' . e($asset['location_label'] ?? '') . '"></label></div>' . saved_location_datalist_html()
        . '<div class="grid three"><label>Latitude<input name="latitude" value="' . e($asset['latitude'] ?? '') . '"></label><label>Longitude<input name="longitude" value="' . e($asset['longitude'] ?? '') . '"></label><label>Radius Meter<input type="number" min="1" name="location_radius_m" value="' . e($asset['location_radius_m'] ?? 5) . '"></label></div>'
        . '<label>Catatan<textarea name="notes">' . e($asset['notes'] ?? '') . '</textarea></label>'
        . '<div class="actions"><button class="btn primary">Simpan Maintenance Asset</button></div>'
        . '</form></section>'
        . '<script>
(function(){
    var groupSel = document.getElementById("maintAssetGroup");
    var typeSel = document.getElementById("maintAssetType");
    var deskSel = document.getElementById("maintJobDesk");

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

    if (groupSel && typeSel && deskSel) {
        groupSel.addEventListener("change", function(){
            var gid = this.value;
            typeSel.innerHTML = "<option value=\"\">- Memuat Tipe... -</option>";
            fetch("index.php?route=api_asset_types&group_id=" + (gid || 0))
                .then(function(r){ return r.json(); })
                .then(function(types){
                    typeSel.innerHTML = "<option value=\"\">- Pilih Asset Type -</option>";
                    types.forEach(function(t){
                        var opt = document.createElement("option");
                        opt.value = t.id;
                        opt.textContent = t.type_code + " - " + t.type_name;
                        typeSel.appendChild(opt);
                    });
                    loadJobDesks(gid, typeSel.value, true);
                })
                .catch(function(){
                    typeSel.innerHTML = "<option value=\"\">- Pilih Asset Type -</option>";
                    loadJobDesks(gid, 0, false);
                });
        });

        typeSel.addEventListener("change", function(){
            loadJobDesks(groupSel.value, this.value, true);
        });
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

    $parent = active_parent_asset_item($pdo, $assetItemId);
    if ($parent) {
        $parentId = (int)$parent['parent_asset_item_id'];
        if ($maintenanceAssetId) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM maintenance_asset_items WHERE asset_item_id=? AND maintenance_asset_id=? AND detached_at IS NULL');
            $stmt->execute([$parentId, $maintenanceAssetId]);
            $parentLinked = (int)$stmt->fetchColumn() > 0;
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM maintenance_asset_items WHERE asset_item_id=? AND detached_at IS NULL');
            $stmt->execute([$parentId]);
            $parentLinked = (int)$stmt->fetchColumn() > 0;
        }
        if ($parentLinked) {
            $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\n" . $note, (int)$parent['id']]);
            $count++;
        }
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
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = maintenance_asset_post_data($pdo, $id);
        if ($data['maintenance_asset_code'] === '' || $data['security_code'] === '' || $data['name'] === '') {
            flash('Maintenance Asset ID, Secret QR, dan nama wajib diisi.', 'err');
            redirect_to('maintenance_asset_form', $id > 0 ? ['id' => $id] : []);
        }
        try {
            if ($id > 0) {
                $values = array_values($data);
                $values[] = $id;
                $pdo->prepare('UPDATE maintenance_assets SET maintenance_asset_code=?, security_code=?, maintenance_type=?, asset_group_id=?, asset_type_id=?, job_desk_name=?, name=?, company_id=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=?, status=?, notes=? WHERE id=?')->execute($values);
                flash('Maintenance asset berhasil diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO maintenance_assets (maintenance_asset_code, security_code, maintenance_type, asset_group_id, asset_type_id, job_desk_name, name, company_id, employee_nik, owner_name, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                flash('Maintenance asset berhasil ditambahkan.');
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
