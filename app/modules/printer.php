<?php

declare(strict_types=1);

function next_printer_id(PDO $pdo): string
{
    $rows = $pdo->query("SELECT prn_id FROM printers WHERE prn_id REGEXP '^PRN[0-9]+$'")->fetchAll(PDO::FETCH_COLUMN);
    $max = 0;
    foreach ($rows as $prnId) {
        if (preg_match('/^PRN(\d+)$/', (string)$prnId, $matches)) {
            $max = max($max, (int)$matches[1]);
        }
    }
    do {
        $next = 'PRN' . str_pad((string)(++$max), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM printers WHERE prn_id=?');
        $stmt->execute([$next]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $next;
}

function printer_schema_ready(PDO $pdo): bool
{
    return db_table_exists($pdo, 'printers')
        && db_column_exists($pdo, 'maintenance_schedules', 'asset_type')
        && db_column_exists($pdo, 'maintenance_schedules', 'printer_id')
        && db_column_exists($pdo, 'printers', 'latitude')
        && db_column_exists($pdo, 'printers', 'longitude')
        && db_column_exists($pdo, 'printers', 'location_radius_m')
        && db_column_nullable($pdo, 'maintenance_schedules', 'pc_id');
}

function printer_schema_warning(): string
{
    return '<section class="panel"><div class="flash err">Schema Printer belum aktif penuh di database. Buka <code>public/printer-upgrade.php</code>, jalankan upgrade sampai status menjadi OK termasuk kolom GPS printer. Untuk sementara maintenance PC tetap bisa dipakai.</div></section>';
}

function printer_asset_code(array $printer): string
{
    $maintenanceCode = trim((string)($printer['maintenance_asset_code'] ?? ''));
    if ($maintenanceCode !== '') {
        return $maintenanceCode . '-' . (string)$printer['security_code'];
    }
    return (string)$printer['prn_id'] . '-' . (string)$printer['security_code'];
}

function printer_asset_link_summary(PDO $pdo, array $printer): string
{
    $lines = [];
    $itemId = (int)($printer['asset_item_id'] ?? 0);
    if ($itemId > 0) {
        if (!empty($printer['asset_code'])) {
            $rawMode = (string)($printer['asset_mode'] ?? 'standalone');
            $mode = $rawMode === 'group' ? 'Bundle (Parent)' : ($rawMode === 'child' ? 'Bundle (Child)' : 'Single');
            $cat = trim((string)($printer['asset_category'] ?? ''));
            $lines[] = 'Asset Item: ' . $printer['asset_code'] . ' - ' . ($printer['asset_name'] ?? $printer['asset_code']) . ' (' . ($cat !== '' ? $cat . ' / ' : '') . ($printer['asset_type'] ?? 'Printer') . ' / ' . $mode . ')';
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
    }
    $maintId = (int)($printer['maintenance_asset_id'] ?? 0);
    if ($maintId > 0) {
        if (!empty($printer['maintenance_asset_code'])) {
            $lines[] = 'MNT ID: ' . $printer['maintenance_asset_code'];
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

function printer_table(array $rows, bool $actions = false): string
{
    if (!$rows) {
        return '<p>Belum ada data printer.</p>';
    }
    $pdo = Database::pdo();
    $canEdit = has_regulation('printers', 'edit');
    $canDelete = has_regulation('printers', 'delete');
    $html = '<table><tr><th>PrnID</th><th>Printer Name</th><th>Location</th><th>Manajemen Aset</th><th>Serial Number</th><th>IP Printer</th><th>Model</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $rowActions = '<a class="btn" href="' . route_url('printer_detail', ['prn_id' => $row['prn_id']]) . '">Detail</a>';
        if ($actions && $canEdit) {
            $rowActions .= ' <a class="btn" href="' . route_url('printer_form', ['prn_id' => $row['prn_id']]) . '">Edit</a>';
        }
        if ($actions && $canDelete) {
            $rowActions .= ' <form method="post" action="' . route_url('printers') . '" style="display:inline;" onsubmit="return confirm(\'Hapus Printer ' . e($row['prn_id']) . '? Semua riwayat dan relasi printer ini akan dihapus.\');">'
                . csrf_field()
                . '<input type="hidden" name="action" value="delete_printer">'
                . '<input type="hidden" name="prn_id" value="' . e($row['prn_id']) . '">'
                . '<button type="submit" class="btn danger" style="padding:4px 8px;font-size:12px;">Hapus</button>'
                . '</form>';
        }
        $html .= '<tr><td>' . e($row['prn_id']) . '</td><td>' . e($row['printer_name']) . '</td><td>' . e($row['location'] ?? '-') . '</td><td>' . nl2br(e(printer_asset_link_summary($pdo, $row))) . '</td><td>' . e($row['serial_number'] ?? '-') . '</td><td>' . e($row['ip_printer'] ?? '-') . '</td><td>' . e($row['model_printer'] ?? '-') . '</td><td>' . $rowActions . '</td></tr>';
    }
    return $html . '</table>';
}

function ensure_printer_maintenance_asset(PDO $pdo, array $printer): ?array
{
    $prnId = (string)($printer['prn_id'] ?? '');
    if ($prnId === '') {
        return null;
    }
    if (!empty($printer['maintenance_asset_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([(int)$printer['maintenance_asset_id']]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }
    $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE printer_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$prnId]);
    $row = $stmt->fetch();
    if (!$row) {
        if (empty($printer['asset_item_id'])) {
            return null;
        }
        $cStmt = $pdo->prepare('SELECT asset_mode FROM asset_items WHERE id=?');
        $cStmt->execute([(int)$printer['asset_item_id']]);
        if ($cStmt->fetchColumn() === 'child') {
            return null;
        }
        if (db_table_exists($pdo, 'asset_item_members')) {
            $chkMem = $pdo->prepare('SELECT 1 FROM asset_item_members WHERE child_asset_item_id=? AND detached_at IS NULL LIMIT 1');
            $chkMem->execute([(int)$printer['asset_item_id']]);
            if ($chkMem->fetchColumn()) {
                return null;
            }
        }
        $name = trim((string)($printer['printer_name'] ?? ''));
        if ($name === '') {
            $name = $prnId;
        }
        $code = unique_maintenance_asset_code($pdo, maintenance_asset_code_seed('MNT', $prnId));
        $pdo->prepare('INSERT INTO maintenance_assets (maintenance_asset_code, security_code, maintenance_type, name, printer_id, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $code,
            (string)($printer['security_code'] ?? pc_security_code_random()),
            'printer',
            $name,
            $prnId,
            $printer['location'] ?? null,
            $printer['latitude'] ?? null,
            $printer['longitude'] ?? null,
            max(1, (int)($printer['location_radius_m'] ?? 5)),
            'active',
            'Auto dibuat dari data printer.',
        ]);
        $rowId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
    }
    if ($row) {
        $pdo->prepare('UPDATE printers SET maintenance_asset_id=? WHERE prn_id=?')->execute([(int)$row['id'], $prnId]);
        if (!empty($printer['asset_item_id']) && function_exists('link_maintenance_asset_item')) {
            link_maintenance_asset_item($pdo, (int)$row['id'], (int)$printer['asset_item_id'], 'Printer / Asset Item');
        }
    }
    return $row ?: null;
}

function sync_printer_maintenance_asset(PDO $pdo, string $prnId): void
{
    if (!db_table_exists($pdo, 'printers')) {
        return;
    }
    $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
    $stmt->execute([$prnId]);
    $printer = $stmt->fetch();
    if (!$printer) {
        return;
    }

    // Jika Printer tidak memiliki asset_item_id atau unit asetnya adalah child bundle, bersihkan maintenance_asset_id
    $isChildAsset = false;
    if (!empty($printer['asset_item_id'])) {
        $cStmt = $pdo->prepare('SELECT asset_mode FROM asset_items WHERE id=?');
        $cStmt->execute([(int)$printer['asset_item_id']]);
        if ($cStmt->fetchColumn() === 'child') {
            $isChildAsset = true;
        } elseif (db_table_exists($pdo, 'asset_item_members')) {
            $chkMem = $pdo->prepare('SELECT 1 FROM asset_item_members WHERE child_asset_item_id=? AND detached_at IS NULL LIMIT 1');
            $chkMem->execute([(int)$printer['asset_item_id']]);
            if ($chkMem->fetchColumn()) {
                $isChildAsset = true;
            }
        }
    }

    if (empty($printer['asset_item_id']) || $isChildAsset) {
        if (!empty($printer['maintenance_asset_id'])) {
            $oldMntId = (int)$printer['maintenance_asset_id'];
            $pdo->prepare('UPDATE printers SET maintenance_asset_id=NULL WHERE prn_id=?')->execute([$prnId]);
            $pdo->prepare('DELETE FROM maintenance_asset_items WHERE maintenance_asset_id=?')->execute([$oldMntId]);
            $pdo->prepare('DELETE FROM maintenance_assets WHERE id=?')->execute([$oldMntId]);
        }
        return;
    }

    $asset = ensure_printer_maintenance_asset($pdo, $printer);
    if (!$asset) {
        return;
    }
    $pdo->prepare('UPDATE maintenance_assets SET security_code=?, name=?, location_label=?, latitude=?, longitude=?, location_radius_m=? WHERE id=?')->execute([
        (string)$printer['security_code'],
        (string)($printer['printer_name'] ?: $prnId),
        $printer['location'] ?? null,
        $printer['latitude'] ?? null,
        $printer['longitude'] ?? null,
        max(1, (int)($printer['location_radius_m'] ?? 5)),
        (int)$asset['id'],
    ]);
    if (!empty($printer['asset_item_id']) && function_exists('link_maintenance_asset_item')) {
        link_maintenance_asset_item($pdo, (int)$asset['id'], (int)$printer['asset_item_id'], 'Printer / Asset Item');
    }
}

function validate_printer_scan_location(PDO $pdo, string $prnId, ?float $scanLat, ?float $scanLng): ?string
{
    $stmt = $pdo->prepare('SELECT latitude, longitude, location_radius_m, location FROM printers WHERE prn_id=?');
    $stmt->execute([$prnId]);
    $printer = $stmt->fetch();
    if (!$printer || $printer['latitude'] === null || $printer['longitude'] === null || $printer['latitude'] === '' || $printer['longitude'] === '') {
        return null;
    }
    if ($scanLat === null || $scanLng === null) {
        return 'GPS teknisi belum terbaca. Aktifkan izin Location/GPS di browser lalu scan ulang.';
    }
    $radius = max(1, (int)($printer['location_radius_m'] ?? 5));
    $distance = geo_distance_m((float)$printer['latitude'], (float)$printer['longitude'], $scanLat, $scanLng);
    if ($distance > $radius) {
        return 'Scan ditolak. Jarak dari titik printer ' . round($distance, 1) . ' meter, maksimal ' . $radius . ' meter' . ($printer['location'] ? ' (' . $printer['location'] . ')' : '') . '.';
    }
    return null;
}

function printer_asset_link_form_html(array $printer): string
{
    try {
        $pdo = Database::pdo();
        ensure_asset_management_schema($pdo);
        $itemSelected = (int)($printer['asset_item_id'] ?? 0) ?: null;
        $html = '<section class="panel"><h2>Tautan Manajemen Aset</h2><p class="muted">Pilih satu <strong>Kode Unit Aset</strong> untuk Printer ini. Kategori otomatis terisi dari Unit Aset (Computer/Printer/Kendaraan/Lain-lain).</p>';
        $html .= '<label>Kode Unit Aset<select name="asset_item_id">' . (function_exists('asset_item_options') ? asset_item_options($pdo, $itemSelected) : '<option value="">Pilih</option>') . '</select></label>';
        $html .= '<div class="actions"><a class="btn" href="' . route_url('asset_item_form', ['asset_category' => 'Printer']) . '">Tambah Unit Aset</a><a class="btn" href="' . route_url('asset_items') . '">Kelola Unit Aset</a></div></section>';
        return $html;
    } catch (Throwable $e) {
        return '<section class="panel"><h2>Tautan Manajemen Aset</h2><div class="flash err">Pilihan aset belum siap: ' . e($e->getMessage()) . '</div></section>';
    }
}

function printer_asset_link_detail_html(PDO $pdo, array $printer): string
{
    $itemId = (int)($printer['asset_item_id'] ?? 0);
    $maintenanceAssetId = (int)($printer['maintenance_asset_id'] ?? 0);
    $maintenanceAssetCode = (string)($printer['maintenance_asset_code'] ?? '');
    if ($maintenanceAssetId > 0 && $maintenanceAssetCode === '') {
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
    $html = '<section class="panel"><div class="split"><div><h2>Tautan Manajemen Aset</h2><p class="muted">Relasi Printer ke Unit Aset dan Maintenance Asset ID.</p></div><a class="btn" href="' . route_url('printer_form', ['prn_id' => $printer['prn_id'] ?? '']) . '">Edit Relasi</a></div>';
    $html .= '<div class="grid two"><div class="mini-card"><strong>Maintenance Asset ID</strong><br><span class="badge">' . e($maintenanceAssetCode !== '' ? $maintenanceAssetCode : '-') . '</span></div>';
    if ($item) {
        $rawMode = (string)($item['asset_mode'] ?? 'standalone');
        $mode = $rawMode === 'group' ? 'Bundle (Parent Aset)' : ($rawMode === 'child' ? 'Bundle (Child Aset)' : 'Single');
        $cat = trim((string)($item['asset_category'] ?? ''));
        $html .= '<div class="mini-card"><strong>Unit Aset Terhubung</strong><table><tr><th>Kode Aset</th><td>' . e($item['asset_code']) . '</td></tr><tr><th>Nama</th><td>' . e($item['asset_name']) . '</td></tr><tr><th>Kategori</th><td><span class="badge ok">' . e($cat !== '' ? $cat : '-') . '</span></td></tr><tr><th>Tipe / Mode</th><td>' . e($item['asset_type'] . ' / ' . $mode) . '</td></tr><tr><th>Company</th><td>' . e($item['company_name'] ?: '-') . '</td></tr><tr><th>Serial</th><td>' . e($item['serial_number'] ?: '-') . '</td></tr></table><p><a class="btn" href="' . route_url('asset_item_form', ['id' => $item['id']]) . '">Buka Unit Aset</a></p></div>';
    } else {
        $html .= '<div class="mini-card"><strong>Unit Aset Terhubung</strong><p class="muted">Belum ada unit aset yang dihubungkan ke Printer ini.</p></div>';
    }
    $html .= '</div></section>';
    return $html;
}

function printer_form_html(array $printer, bool $editing): string
{
    $assetCode = '';
    if ($editing && !empty($printer['prn_id']) && !empty($printer['security_code'])) {
        $assetCode = printer_asset_code($printer);
    }
    $lat = (string)($printer['latitude'] ?? '');
    $lng = (string)($printer['longitude'] ?? '');
    $mapHref = ($lat !== '' && $lng !== '') ? 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng) : 'https://www.google.com/maps';
    $html = '<style>.form-shell{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(0,1.2fr);gap:16px;align-items:start}.form-section{background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:16px;margin-bottom:16px}.form-section h2{margin:0 0 6px}.location-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.gps-status{border:1px dashed #cbd5e1;border-radius:8px;padding:12px;background:#f8fafc}.coord-help{margin:8px 0;color:#64748b}.footer-actions{position:sticky;bottom:0;background:#f5f7fb;border-top:1px solid #dfe5ee;padding:12px 0;margin-top:8px}@media(max-width:980px){.form-shell,.location-grid{grid-template-columns:1fr}.footer-actions{position:static}}</style>';
    $html .= '<section class="panel"><div class="split"><div><h1>' . ($editing ? 'Edit Printer' : 'Tambah Printer Baru') . '</h1><p class="muted">PrnID dan Secret QR dibuat otomatis. Data printer diisi manual sesuai label/perangkat.</p></div><div class="actions"><a class="btn" href="' . route_url('printers') . '">Kembali ke Data Printer</a>';
    if ($assetCode !== '') {
        $html .= '<a class="btn" download="PcConnect-Label-' . e($assetCode) . '.png" href="' . route_url('qr_png', ['code' => $assetCode]) . '">Download Label PNG</a>';
    }
    $html .= '</div></div></section><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="form-shell"><div>';
    $html .= '<section class="form-section"><h2>Identitas Printer</h2>';
    if ($editing) {
        $html .= '<div class="grid two"><label>PrnID<input name="prn_id" value="' . e($printer['prn_id']) . '" readonly></label><label>Secret QR<input name="security_code" value="' . e($printer['security_code']) . '" required></label></div>';
    } else {
        $html .= '<p class="muted">PrnID otomatis dibuat saat simpan, contoh: PRN000001. Secret QR otomatis dibuat random dan tercetak di label.</p>';
    }
    $html .= '<label>Printer Name<input name="printer_name" value="' . e($printer['printer_name']) . '" required></label><label>Serial Number<input name="serial_number" value="' . e($printer['serial_number']) . '"></label><label>IP Printer<input name="ip_printer" value="' . e($printer['ip_printer']) . '" placeholder="192.168.1.xxx"></label><label>Model Printer<input name="model_printer" value="' . e($printer['model_printer']) . '"></label></section>';
    $html .= '<section class="form-section"><h2>Pengguna / PIC Printer</h2><div class="grid two"><label>NIK Pengguna / PIC<input id="printerEmployeeNik" name="employee_nik" value="' . e($printer['employee_nik'] ?? '') . '" placeholder="NIK karyawan"></label><label>Nama Pengguna / PIC<input id="printerOwnerName" name="owner_name" value="' . e($printer['owner_name'] ?? '') . '" placeholder="Nama pemakai / PIC printer"></label></div>' . (function_exists('employee_portal_name_picker_html') ? employee_portal_name_picker_html('printerOwner', 'printerOwnerName', 'printerEmployeeNik') : '') . '</section>';
    $html .= printer_asset_link_form_html($printer);
    $html .= '<section class="form-section"><h2>Kondisi Fisik</h2><textarea name="physical_condition" placeholder="Catatan kondisi awal printer">' . e($printer['physical_condition']) . '</textarea></section></div><div>';
    $html .= saved_location_datalist_html();
    $html .= '<section class="form-section"><div class="split"><div><h2>Titik Lokasi Printer</h2><p class="muted">Koordinat ini menjadi patokan scan QR teknisi. Jika latitude dan longitude diisi, scan hanya valid dalam radius yang diset, default 5 meter.</p></div><div class="actions">' . ($editing ? '<a class="btn" href="' . route_url('printer_location', ['prn_id' => $printer['prn_id']]) . '">Set dari HP</a>' : '') . '<a class="btn" target="_blank" rel="noopener" href="' . e($mapHref) . '">Google Maps</a></div></div><div class="location-grid"><label>Group / Nama Titik Lokasi<input name="location" list="savedLocationGroups" value="' . e($printer['location']) . '" placeholder="Contoh: Lantai 2 - Ruang Finance"></label><label>Radius Meter<input name="location_radius_m" type="number" min="1" max="100" value="' . e($printer['location_radius_m'] ?? 5) . '"></label><label>Latitude<input id="printerLatitude" name="latitude" value="' . e($lat) . '" placeholder="-6.2000000"></label><label>Longitude<input id="printerLongitude" name="longitude" value="' . e($lng) . '" placeholder="106.8166660"></label></div><div class="gps-status"><div class="actions"><button class="btn primary" type="button" id="usePrinterLocation">Ambil GPS Perangkat Ini</button></div><p class="coord-help">Untuk akurasi terbaik, buka dari ponsel saat berdiri di titik printer lalu tekan tombol GPS.</p><p id="printerLocationStatus" class="muted"></p></div></section></div></div>';
    $html .= '<section class="footer-actions"><div class="actions"><button class="btn primary">' . ($editing ? 'Simpan Perubahan' : 'Tambah Printer') . '</button><a class="btn" href="' . route_url('printers') . '">Batal</a>';
    if ($editing && has_regulation('printers', 'delete')) {
        $html .= '<button type="submit" class="btn danger" form="deletePrinterForm" onclick="return confirm(\'Hapus Printer ' . e($printer['prn_id']) . '? Semua data riwayat dan relasi printer ini akan dihapus.\');">Hapus Printer</button>';
    }
    $html .= '</div></section></form>';
    if ($editing && has_regulation('printers', 'delete')) {
        $html .= '<form id="deletePrinterForm" method="post" action="' . route_url('printers') . '" style="display:none;">' . csrf_field() . '<input type="hidden" name="action" value="delete_printer"><input type="hidden" name="prn_id" value="' . e($printer['prn_id']) . '"></form>';
    }
    $html .= '<script>(function(){var btn=document.getElementById("usePrinterLocation"),lat=document.getElementById("printerLatitude"),lng=document.getElementById("printerLongitude"),status=document.getElementById("printerLocationStatus");if(!btn)return;btn.addEventListener("click",function(){if(!navigator.geolocation){status.textContent="Browser tidak mendukung GPS.";return;}status.textContent="Mengambil lokasi...";navigator.geolocation.getCurrentPosition(function(p){lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);status.textContent="Lokasi tersimpan di form. Akurasi perangkat sekitar "+Math.round(p.coords.accuracy)+" meter.";},function(){status.textContent="Gagal mengambil lokasi.";},{enableHighAccuracy:true,timeout:15000,maximumAge:0});});})();</script>';
    return $html;
}

function handle_route_printers(PDO $pdo): void
{
    $user = require_regulation('printers', 'view');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_printer') {
        require_regulation('printers', 'delete');
        $delPrnId = trim((string)($_POST['prn_id'] ?? ''));
        if ($delPrnId !== '') {
            $pdo->prepare('UPDATE maintenance_assets SET printer_id=NULL WHERE printer_id=?')->execute([$delPrnId]);
            $pdo->prepare('DELETE FROM printers WHERE prn_id=?')->execute([$delPrnId]);
            flash('Printer ' . $delPrnId . ' berhasil dihapus.');
        }
        redirect_to('printers');
    }

    render_header('Data Printer', $user);
    if (!printer_schema_ready($pdo)) {
        echo printer_schema_warning();
        render_footer();
        return;
    }
    $rows = $pdo->query('
        SELECT pr.*, 
               ai.asset_code, ai.asset_name, ai.asset_type, ai.asset_category, ai.asset_mode,
               ma.maintenance_asset_code
        FROM printers pr
        LEFT JOIN asset_items ai ON ai.id = pr.asset_item_id
        LEFT JOIN maintenance_assets ma ON ma.id = pr.maintenance_asset_id
        ORDER BY pr.updated_at DESC
    ')->fetchAll();
    $addBtn = has_regulation('printers', 'create') ? '<a class="btn primary" href="' . route_url('printer_form') . '">Tambah Printer</a>' : '';
    echo '<section class="panel"><div class="split"><h1>Data Printer</h1><div class="actions">' . $addBtn . '<a class="btn" href="' . route_url('labels', ['type' => 'printer']) . '">QR Label Printer</a></div></div>' . printer_table($rows, true) . '</section>';
    render_footer();
}

function handle_route_printer_form(PDO $pdo): void
{
    $prnId = strtoupper(trim((string)($_GET['prn_id'] ?? '')));
    $editing = $prnId !== '';
    $user = $editing ? require_regulation('printers', 'edit') : require_regulation('printers', 'create');
    if (!printer_schema_ready($pdo)) {
        render_header('Printer Schema', $user);
        echo printer_schema_warning();
        render_footer();
        return;
    }
    $printer = [
        'prn_id' => '',
        'security_code' => '',
        'printer_name' => '',
        'location' => '',
        'latitude' => '',
        'longitude' => '',
        'location_radius_m' => 5,
        'serial_number' => '',
        'ip_printer' => '',
        'model_printer' => '',
        'owner_name' => '',
        'employee_nik' => '',
        'physical_condition' => '',
        'asset_item_id' => '',
        'maintenance_asset_id' => '',
    ];
    if ($editing) {
        $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
        $stmt->execute([$prnId]);
        $found = $stmt->fetch();
        if (!$found) {
            http_response_code(404);
            exit('Printer tidak ditemukan.');
        }
        $printer = array_merge($printer, $found);
        $printerMaintenanceAsset = ensure_printer_maintenance_asset($pdo, $printer);
        if ($printerMaintenanceAsset) {
            $printer['maintenance_asset_code'] = $printerMaintenanceAsset['maintenance_asset_code'];
            $printer['security_code'] = $printerMaintenanceAsset['security_code'];
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $printerName = trim((string)($_POST['printer_name'] ?? ''));
        if ($printerName === '') {
            flash('Printer Name wajib diisi.', 'err');
            redirect_to('printer_form', $editing ? ['prn_id' => $prnId] : []);
        }
        $postedPrnId = $editing ? $prnId : next_printer_id($pdo);
        $securityCode = $editing ? strtoupper(trim((string)($_POST['security_code'] ?? ''))) : pc_security_code_random();
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $securityCode)) {
            flash('Secret QR hanya boleh huruf/angka, panjang 2-12 karakter.', 'err');
            redirect_to('printer_form', $editing ? ['prn_id' => $prnId] : []);
        }
        $latitude = normalize_decimal_input((string)($_POST['latitude'] ?? ''));
        $longitude = normalize_decimal_input((string)($_POST['longitude'] ?? ''));
        if (($latitude === null) !== ($longitude === null)) {
            flash('Latitude dan longitude harus diisi berpasangan, atau kosongkan keduanya.', 'err');
            redirect_to('printer_form', $editing ? ['prn_id' => $prnId] : []);
        }
        if (($latitude !== null && ($latitude < -90 || $latitude > 90)) || ($longitude !== null && ($longitude < -180 || $longitude > 180))) {
            flash('Latitude/longitude printer tidak valid.', 'err');
            redirect_to('printer_form', $editing ? ['prn_id' => $prnId] : []);
        }
        $printerData = [
            'security_code' => $securityCode,
            'printer_name' => $printerName,
            'location' => trim((string)($_POST['location'] ?? '')),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_radius_m' => max(1, (int)(($_POST['location_radius_m'] ?? '') !== '' ? $_POST['location_radius_m'] : 5)),
            'serial_number' => trim((string)($_POST['serial_number'] ?? '')),
            'ip_printer' => trim((string)($_POST['ip_printer'] ?? '')),
            'model_printer' => trim((string)($_POST['model_printer'] ?? '')),
            'owner_name' => trim((string)($_POST['owner_name'] ?? '')) ?: null,
            'employee_nik' => trim((string)($_POST['employee_nik'] ?? '')) ?: null,
            'physical_condition' => trim((string)($_POST['physical_condition'] ?? '')),
            'asset_item_id' => (int)($_POST['asset_item_id'] ?? 0) > 0 ? (int)$_POST['asset_item_id'] : null,
        ];
        try {
            if (!empty($printerData['asset_item_id'])) {
                $chk = $pdo->prepare("SELECT asset_code, asset_mode FROM asset_items WHERE id=?");
                $chk->execute([(int)$printerData['asset_item_id']]);
                $chkRow = $chk->fetch();
                if ($chkRow && ($chkRow['asset_mode'] ?? '') === 'child') {
                    throw new RuntimeException("Asset item '{$chkRow['asset_code']}' berstatus 'Bundle (Child Asset)' dan tidak dapat dihubungkan ke Printer.");
                }
            }
            if ($editing) {
                $setParts = [];
                foreach (array_keys($printerData) as $col) {
                    $setParts[] = $col . '=?';
                }
                $stmt = $pdo->prepare('UPDATE printers SET ' . implode(', ', $setParts) . ' WHERE prn_id=?');
                $stmt->execute([...array_values($printerData), $prnId]);
                sync_printer_maintenance_asset($pdo, $prnId);
                flash('Data printer berhasil diperbarui.');
                redirect_to('printer_detail', ['prn_id' => $prnId]);
            }
            $columns = array_keys($printerData);
            $placeholders = implode(', ', array_fill(0, count($columns) + 1, '?'));
            $stmt = $pdo->prepare('INSERT INTO printers (prn_id, ' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute([$postedPrnId, ...array_values($printerData)]);
            sync_printer_maintenance_asset($pdo, $postedPrnId);
            flash('Printer baru berhasil ditambahkan.');
            redirect_to('printer_detail', ['prn_id' => $postedPrnId]);
        } catch (Throwable $e) {
            flash('Simpan printer gagal: ' . $e->getMessage(), 'err');
            redirect_to('printer_form', $editing ? ['prn_id' => $prnId] : []);
        }
    }
    render_header($editing ? 'Edit Printer' : 'Tambah Printer', $user);
    echo printer_form_html($printer, $editing);
    render_footer();
}

function handle_route_printer_detail(PDO $pdo): void
{
    $user = require_regulation('printers', 'view');
    if (!printer_schema_ready($pdo)) {
        render_header('Printer Schema', $user);
        echo printer_schema_warning();
        render_footer();
        return;
    }
    $prnId = (string)($_GET['prn_id'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id = ?');
    $stmt->execute([$prnId]);
    $printer = $stmt->fetch();
    if (!$printer) {
        http_response_code(404);
        exit('Printer tidak ditemukan.');
    }
    $printerMaintenanceAsset = ensure_printer_maintenance_asset($pdo, $printer);
    if ($printerMaintenanceAsset) {
        $printer['maintenance_asset_code'] = $printerMaintenanceAsset['maintenance_asset_code'];
        $printer['security_code'] = $printerMaintenanceAsset['security_code'];
    }
    $assetCode = printer_asset_code($printer);
    render_header('Detail Printer', $user);
    $actionsHtml = '';
    if (has_regulation('printers', 'edit')) {
        $actionsHtml .= '<a class="btn primary" href="' . route_url('printer_form', ['prn_id' => $prnId]) . '">Edit Printer</a>';
    }
    if (has_regulation('printers', 'delete')) {
        $actionsHtml .= '<form method="post" action="' . route_url('printers') . '" style="display:inline;" onsubmit="return confirm(\'Hapus Printer ' . e($prnId) . '? Semua relasi dan riwayat printer ini akan dihapus.\');">'
            . csrf_field()
            . '<input type="hidden" name="action" value="delete_printer">'
            . '<input type="hidden" name="prn_id" value="' . e($prnId) . '">'
            . '<button type="submit" class="btn danger">Hapus Printer</button>'
            . '</form>';
    }
    $actionsHtml .= '<a class="btn" href="' . route_url('printer_location', ['prn_id' => $prnId]) . '">Set Lokasi GPS</a>';
    $actionsHtml .= '<a class="btn" href="' . route_url('schedule_form', ['asset' => $assetCode]) . '">Tambah Schedule</a>';
    $actionsHtml .= '<a class="btn" download="PcConnect-Label-' . e($assetCode) . '.png" href="' . route_url('qr_png', ['code' => $assetCode]) . '">Download Label PNG</a>';
    $actionsHtml .= '<a class="btn" href="' . mobile_asset_url($assetCode) . '">Mobile QR URL</a>';

    echo '<section class="panel"><div class="split"><div><h1>' . e($printer['prn_id']) . '</h1><p>' . e($printer['printer_name']) . ' - ' . e($printer['location'] ?: 'Lokasi belum diisi') . '</p><p><span class="badge">Maintenance Asset ID ' . e($assetCode) . '</span></p></div><div class="actions">' . $actionsHtml . '</div></div></section>';
    if (!empty($printer['latitude']) && !empty($printer['longitude'])) {
        $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string)$printer['latitude'] . ',' . (string)$printer['longitude']);
        echo '<section class="panel"><h2>Lokasi Printer</h2><table><tr><th>Nama Lokasi</th><td>' . e($printer['location'] ?: '-') . '</td></tr><tr><th>GPS</th><td>' . e($printer['latitude'] . ', ' . $printer['longitude']) . '</td></tr><tr><th>Radius Scan</th><td>' . e($printer['location_radius_m'] ?: 5) . ' meter</td></tr></table><p><a class="btn" target="_blank" rel="noopener" href="' . e($mapUrl) . '">Buka di Google Maps</a></p></section>';
    }
    $ownerText = !empty($printer['owner_name']) ? ('<strong>' . e($printer['owner_name']) . '</strong>' . (!empty($printer['employee_nik']) ? (' <span class="muted">(NIK: ' . e($printer['employee_nik']) . ')</span>') : '')) : '<span class="muted">-</span>';
    echo '<section class="panel"><h2>Data Printer</h2><table><tr><th>Printer Name</th><td>' . e($printer['printer_name']) . '</td></tr><tr><th>Pengguna / PIC</th><td>' . $ownerText . '</td></tr><tr><th>Location</th><td>' . e($printer['location']) . '</td></tr><tr><th>Serial Number</th><td>' . e($printer['serial_number']) . '</td></tr><tr><th>IP Printer</th><td>' . e($printer['ip_printer']) . '</td></tr><tr><th>Model Printer</th><td>' . e($printer['model_printer']) . '</td></tr><tr><th>Kondisi Fisik</th><td>' . nl2br(e($printer['physical_condition'])) . '</td></tr></table></section>';
    echo printer_asset_link_detail_html($pdo, $printer);
    render_footer();
}

function handle_route_printer_location(PDO $pdo): void
{
    $user = require_role(['admin']);
    if (!printer_schema_ready($pdo)) {
        render_header('Printer Schema', $user);
        echo printer_schema_warning();
        render_footer();
        return;
    }
    $prnId = (string)($_GET['prn_id'] ?? $_POST['prn_id'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
    $stmt->execute([$prnId]);
    $printer = $stmt->fetch();
    if (!$printer) {
        http_response_code(404);
        exit('Printer tidak ditemukan.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lat = normalize_decimal_input((string)($_POST['latitude'] ?? ''));
        $lng = normalize_decimal_input((string)($_POST['longitude'] ?? ''));
        $radius = max(1, (int)(($_POST['location_radius_m'] ?? '') !== '' ? $_POST['location_radius_m'] : 5));
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            flash('Latitude/longitude printer tidak valid.', 'err');
            redirect_to('printer_location', ['prn_id' => $prnId]);
        }
        $pdo->prepare('UPDATE printers SET location=?, latitude=?, longitude=?, location_radius_m=? WHERE prn_id=?')->execute([
            trim((string)($_POST['location'] ?? '')),
            $lat,
            $lng,
            $radius,
            $prnId,
        ]);
        sync_printer_maintenance_asset($pdo, $prnId);
        flash('Lokasi GPS printer berhasil disimpan.');
        redirect_to('printer_detail', ['prn_id' => $prnId]);
    }
    render_header('Set Lokasi ' . $prnId, $user);
    $mapUrl = (!empty($printer['latitude']) && !empty($printer['longitude'])) ? 'https://www.google.com/maps?q=' . rawurlencode((string)$printer['latitude'] . ',' . (string)$printer['longitude']) : 'https://www.google.com/maps';
    echo '<section class="panel"><div class="split"><div><h1>Set Lokasi GPS ' . e($prnId) . '</h1><p class="muted">Buka halaman ini dari ponsel saat berdiri di dekat printer. Ambil GPS ponsel lalu simpan sebagai titik validasi scan QR printer.</p></div><a class="btn" href="' . route_url('printer_detail', ['prn_id' => $prnId]) . '">Kembali</a></div></section>';
    echo '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="prn_id" value="' . e($prnId) . '"><label>Nama / Titik Lokasi<input name="location" value="' . e($printer['location'] ?? '') . '" placeholder="Contoh: Lantai 2 - Ruang Finance"></label><div class="grid three"><label>Latitude<input id="printerLatitude" name="latitude" value="' . e($printer['latitude'] ?? '') . '" required placeholder="-6.2000000"></label><label>Longitude<input id="printerLongitude" name="longitude" value="' . e($printer['longitude'] ?? '') . '" required placeholder="106.8166660"></label><label>Radius Meter<input name="location_radius_m" type="number" min="1" max="100" value="' . e($printer['location_radius_m'] ?? 5) . '"></label></div><div class="actions"><button class="btn primary" type="button" id="usePrinterLocation">Ambil GPS Ponsel Ini</button><a class="btn" target="_blank" rel="noopener" href="' . e($mapUrl) . '">Buka Google Maps</a><button class="btn good">Simpan Lokasi</button></div><p id="printerLocationStatus" class="muted"></p></form></section>';
    echo '<script>(function(){var btn=document.getElementById("usePrinterLocation"),lat=document.getElementById("printerLatitude"),lng=document.getElementById("printerLongitude"),status=document.getElementById("printerLocationStatus");if(!btn)return;btn.addEventListener("click",function(){if(!navigator.geolocation){status.textContent="Browser tidak mendukung GPS.";return;}status.textContent="Mengambil GPS ponsel...";navigator.geolocation.getCurrentPosition(function(p){lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);status.textContent="GPS ponsel terbaca. Akurasi sekitar "+Math.round(p.coords.accuracy)+" meter. Klik Simpan Lokasi.";},function(){status.textContent="Gagal mengambil GPS.";},{enableHighAccuracy:true,timeout:20000,maximumAge:0});});})();</script>';
    render_footer();
}

function handle_route_printer_locations(PDO $pdo): void
{
    $user = require_role(['admin']);
    render_header('Titik Lokasi Printer', $user);
    if (!printer_schema_ready($pdo)) {
        echo printer_schema_warning();
        render_footer();
        return;
    }
    $rows = $pdo->query('SELECT prn_id, printer_name, location, latitude, longitude, location_radius_m, ip_printer, model_printer FROM printers ORDER BY COALESCE(NULLIF(location, ""), "ZZZ"), prn_id')->fetchAll();
    $groups = [];
    foreach ($rows as $row) {
        $key = trim((string)($row['location'] ?? ''));
        if ($key === '') {
            $key = !empty($row['latitude']) && !empty($row['longitude']) ? 'Lokasi tanpa nama' : 'Belum diset';
        }
        $groups[$key][] = $row;
    }
    echo '<section class="panel"><div class="split"><div><h1>Titik Lokasi Printer</h1><p class="muted">Koordinat ini menjadi patokan scan QR printer.</p></div><div class="actions"><a class="btn" href="' . route_url('printers') . '">Kembali ke Data Printer</a><a class="btn primary" href="' . route_url('printer_form') . '">Tambah Printer</a></div></div></section>';
    foreach ($groups as $groupName => $items) {
        $ready = 0;
        foreach ($items as $item) {
            if (!empty($item['latitude']) && !empty($item['longitude'])) {
                $ready++;
            }
        }
        echo '<section class="panel"><h2>' . e($groupName) . ' (' . $ready . '/' . count($items) . ' GPS Siap)</h2><table><tr><th>PrnID</th><th>Printer / Model</th><th>Koordinat</th><th>Radius</th><th>Aksi</th></tr>';
        foreach ($items as $printer) {
            $hasGps = !empty($printer['latitude']) && !empty($printer['longitude']);
            $coord = $hasGps ? e($printer['latitude'] . ', ' . $printer['longitude']) : '<span class="badge danger">Belum ada GPS</span>';
            echo '<tr><td><strong>' . e($printer['prn_id']) . '</strong></td><td>' . e($printer['printer_name']) . '</td><td>' . $coord . '</td><td>' . e($printer['location_radius_m'] ?: 5) . ' m</td><td><a class="btn" href="' . route_url('printer_location', ['prn_id' => $printer['prn_id']]) . '">Set Lokasi</a></td></tr>';
        }
        echo '</table></section>';
    }
    render_footer();
}

