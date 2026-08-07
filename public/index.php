<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/helpers.php';
ensure_session_started();

$route = (string)($_GET['route'] ?? 'dashboard');

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    $cfg = [];
    try { $cfg = app_config(); } catch (Throwable $ignored) {}
    render_header('Setup Database');
    echo '<section class="auth" style="max-width:720px"><h1>PcConnect</h1><p>Koneksi database belum siap.</p><pre>' . e($e->getMessage()) . '</pre>';
    echo '<h2>Yang perlu dicek</h2><ol><li>Pastikan MySQL/MariaDB aktif di server web.</li><li>Buat database <code>' . e($cfg['db_name'] ?? 'pcconnect') . '</code>.</li><li>Import file <code>database/schema.sql</code>.</li><li>Edit <code>config/config.php</code> sesuai user/password database server.</li></ol>';
    echo '<h2>Config saat ini</h2><table><tr><th>Host</th><td>' . e($cfg['db_host'] ?? '-') . '</td></tr><tr><th>Port</th><td>' . e($cfg['db_port'] ?? '-') . '</td></tr><tr><th>Database</th><td>' . e($cfg['db_name'] ?? '-') . '</td></tr><tr><th>User</th><td>' . e($cfg['db_user'] ?? '-') . '</td></tr></table>';
    echo '<h2>Contoh SQL awal</h2><pre>CREATE DATABASE pcconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER \'pcconnect\'@\'localhost\' IDENTIFIED BY \'password-kuat\';
GRANT ALL PRIVILEGES ON pcconnect.* TO \'pcconnect\'@\'localhost\';
FLUSH PRIVILEGES;</pre><p class="muted">Setelah itu ubah <code>db_pass</code> di <code>config/config.php</code>, lalu import <code>database/schema.sql</code>.</p></section>';
    render_footer();
    exit;
}

ensure_printer_schema($pdo);
ensure_pc_location_schema($pdo);
ensure_photo_challenge_schema($pdo);
ensure_job_estimate_schema($pdo);
ensure_employee_source_schema($pdo);
ensure_company_source_schema($pdo);
ensure_asset_management_schema($pdo);
ensure_maintenance_asset_schema($pdo);

enforce_idle_logout($route);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && substr((string)$route, 0, 4) !== 'api_') {
    verify_csrf();
}

switch ($route) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
            $stmt->execute([trim($_POST['username'])]);
            $user = $stmt->fetch();
            if ($user && password_verify((string)$_POST['password'], $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['last_activity_at'] = time();
                if ($user['role'] === 'technician' && !empty($_SESSION['pending_mobile_code'])) {
                    $pendingCode = (string)$_SESSION['pending_mobile_code'];
                    unset($_SESSION['pending_mobile_code']);
                    redirect_to('mobile_scan', ['code' => $pendingCode]);
                }
                if ($user['role'] === 'maintenance_admin') {
                    redirect_to('maintenance');
                }
                redirect_to($user['role'] === 'technician' ? 'mobile_dashboard' : 'dashboard');
            }
            flash('Login gagal. Periksa username dan password.', 'err');
        }
        render_header('Login');
        echo '<section class="auth"><h1>PcConnect</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><label>Username<input name="username" required autofocus></label><label>Password<input type="password" name="password" required></label><button class="btn primary">Login</button></form></section>';
        render_footer();
        break;

    case 'logout':
        session_destroy();
        redirect_to('login');
        break;

    case 'dashboard':
        $user = require_login();
        if ($user['role'] === 'maintenance_admin') {
            redirect_to('maintenance');
        }
        render_header('Dashboard', $user);
        $pcCount = (int)$pdo->query('SELECT COUNT(*) FROM pcs')->fetchColumn();
        $printerCount = db_table_exists($pdo, 'printers') ? (int)$pdo->query('SELECT COUNT(*) FROM printers')->fetchColumn() : 0;
        $analyzed = (int)$pdo->query('SELECT COUNT(*) FROM pcs WHERE last_analyzed_at IS NOT NULL')->fetchColumn();
        $todayStats = $pdo->query("SELECT COUNT(*) total, SUM(arrival_at IS NOT NULL) arrived, SUM(status='completed') completed, SUM(status IN ('validated','in_progress','reopened')) progress FROM maintenance_schedules WHERE scheduled_date = CURDATE()")->fetch();
        echo '<section class="hero"><div><h1>PcConnect CMMS v2.0</h1><p>IT Asset Management, Printer Asset, Preventive Maintenance, PcNalisa, QR tracking, dan mobile technician workflow.</p><div class="actions"><a class="btn primary" href="' . route_url('pcs') . '">Data PC</a><a class="btn primary" href="' . route_url('printers') . '">Data Printer</a><a class="btn good" href="' . route_url('download_agent') . '">PcNalisa</a><a class="btn" href="' . route_url('maintenance') . '">CMMS Maintenance</a></div></div><div class="grid three"><div class="stat"><strong>' . e($pcCount) . '</strong><span>PC Terdaftar</span></div><div class="stat"><strong>' . e($printerCount) . '</strong><span>Printer Terdaftar</span></div><div class="stat"><strong>' . e($analyzed) . '</strong><span>PC Sudah Dianalisa</span></div></div></section>';
        echo '<section class="panel"><h2>Maintenance Hari Ini</h2><div class="grid four"><div class="stat"><strong>' . e($todayStats['total'] ?? 0) . '</strong><span>Job</span></div><div class="stat"><strong>' . e($todayStats['arrived'] ?? 0) . '</strong><span>Datang</span></div><div class="stat"><strong>' . e($todayStats['progress'] ?? 0) . '</strong><span>Progress</span></div><div class="stat"><strong>' . e($todayStats['completed'] ?? 0) . '</strong><span>Done</span></div></div></section>';
        $techRows = $pdo->query("SELECT u.name technician, COUNT(*) total, SUM(s.status='completed') done, SUM(s.status IN ('validated','in_progress','reopened')) progress, SUM(s.status='scheduled') pending FROM maintenance_schedules s LEFT JOIN users u ON u.id=s.technician_id WHERE s.scheduled_date=CURDATE() GROUP BY u.id, u.name ORDER BY u.name")->fetchAll();
        if ($techRows) {
            echo '<section class="panel"><h2>Teknisi Hari Ini</h2><table><tr><th>Teknisi</th><th>Job</th><th>Done</th><th>Progress</th><th>Pending</th></tr>';
            foreach ($techRows as $row) {
                echo '<tr><td>' . e($row['technician'] ?: 'Belum ditentukan') . '</td><td>' . e($row['total']) . '</td><td>' . e($row['done']) . '</td><td>' . e($row['progress']) . '</td><td>' . e($row['pending']) . '</td></tr>';
            }
            echo '</table></section>';
        }
        render_footer();
        break;

    case 'pcs':
        $user = require_role(['admin']);
        render_header('Data PC', $user);
        $rows = $pdo->query('SELECT * FROM pcs ORDER BY updated_at DESC')->fetchAll();
        echo '<section class="panel"><div class="split"><h1>Data PC</h1><div class="actions"><a class="btn primary" href="' . route_url('pc_form') . '">Tambah PC</a><a class="btn" href="' . route_url('export_excel', ['type' => 'pcs']) . '">Export Excel</a></div></div>' . pc_table($rows, true) . '</section>';
        render_footer();
        break;

    case 'asset_dashboard':
        $user = require_role(['admin']);
        render_header('Asset Management', $user);
        echo asset_nav_html();
        $stats = [
            'company' => (int)$pdo->query('SELECT COUNT(*) FROM asset_companies')->fetchColumn(),
            'item' => (int)$pdo->query('SELECT COUNT(*) FROM asset_items')->fetchColumn(),
            'maintenance' => (int)$pdo->query('SELECT COUNT(*) FROM maintenance_assets')->fetchColumn(),
            'repair' => (int)$pdo->query('SELECT COUNT(*) FROM asset_repairs')->fetchColumn(),
        ];
        echo '<section class="panel"><h1>Asset Management</h1><p class="muted">Layer baru untuk identitas aset fisik, company holding, Maintenance Asset ID/QR, mutasi, dan repair history. PC/Printer tetap menjadi detail teknis, sedangkan schedule maintenance memakai Maintenance Asset ID.</p><div class="grid four"><div class="stat"><strong>' . e($stats['company']) . '</strong><span>Company</span></div><div class="stat"><strong>' . e($stats['item']) . '</strong><span>Asset Items</span></div><div class="stat"><strong>' . e($stats['maintenance']) . '</strong><span>Maintenance Assets</span></div><div class="stat"><strong>' . e($stats['repair']) . '</strong><span>Repair Records</span></div></div></section>';
        render_footer();
        break;

    case 'asset_companies':
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
        break;

    case 'asset_items':
        $user = require_role(['admin']);
        render_header('Asset Items', $user);
        echo asset_nav_html();
        $rows = $pdo->query('SELECT ai.*, c.company_name FROM asset_items ai LEFT JOIN asset_companies c ON c.id=ai.company_id ORDER BY ai.updated_at DESC')->fetchAll();
        echo '<section class="panel"><div class="split"><h1>Asset Items</h1><div class="actions"><a class="btn primary" href="' . route_url('asset_item_form') . '">Tambah Asset Item</a><a class="btn" href="' . route_url('export_excel', ['type' => 'asset_items']) . '">Export Excel</a></div></div>' . asset_items_table($pdo, $rows) . '</section>';
        render_footer();
        break;

    case 'maintenance_assets':
        $user = require_role(['admin']);
        render_header('Maintenance Assets', $user);
        echo asset_nav_html();
        $rows = maintenance_asset_rows($pdo);
        echo '<section class="panel"><div class="split"><div><h1>Maintenance Assets / QR</h1><p class="muted">ID ini dipakai untuk QR label dan schedule maintenance. Satu Maintenance Asset bisa berisi satu atau beberapa Asset Item fisik.</p></div><div class="actions"><a class="btn primary" href="' . route_url('maintenance_asset_form') . '">Tambah Maintenance Asset</a><a class="btn" href="' . route_url('labels') . '">QR Label</a></div></div>' . maintenance_assets_table($pdo, $rows) . '</section>';
        render_footer();
        break;

    case 'maintenance_asset_form':
        $user = require_role(['admin']);
        $id = (int)($_GET['id'] ?? 0);
        $asset = maintenance_asset_defaults();
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
            $stmt->execute([$id]);
            $found = $stmt->fetch();
            if (!$found) { http_response_code(404); exit('Maintenance asset tidak ditemukan.'); }
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
                    $data[] = $id;
                    $pdo->prepare('UPDATE maintenance_assets SET maintenance_asset_code=?, security_code=?, maintenance_type=?, name=?, company_id=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=?, status=?, notes=? WHERE id=?')->execute(array_values($data));
                    flash('Maintenance asset berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO maintenance_assets (maintenance_asset_code, security_code, maintenance_type, name, company_id, employee_nik, owner_name, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($data));
                    $id = (int)$pdo->lastInsertId();
                    flash('Maintenance asset berhasil ditambahkan.');
                }
                redirect_to('maintenance_asset_form', ['id' => $id]);
            } catch (Throwable $e) {
                flash('Gagal simpan maintenance asset: ' . $e->getMessage(), 'err');
            }
        }
        render_header($id > 0 ? 'Edit Maintenance Asset' : 'Tambah Maintenance Asset', $user);
        echo asset_nav_html();
        echo maintenance_asset_form_html($pdo, $asset, $id > 0);
        if ($id > 0) {
            echo maintenance_asset_item_manager_html($pdo, $id);
        }
        render_footer();
        break;

    case 'maintenance_asset_item_action':
        $user = require_role(['admin']);
        $maintenanceAssetId = (int)($_POST['maintenance_asset_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        if ($maintenanceAssetId <= 0) { redirect_to('maintenance_assets'); }
        if ($action === 'attach') {
            $assetItemId = (int)($_POST['asset_item_id'] ?? 0);
            if ($assetItemId > 0) {
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
        break;

    case 'asset_item_form':
        $user = require_role(['admin']);
        $id = (int)($_GET['id'] ?? 0);
        $item = asset_item_defaults();
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM asset_items WHERE id=?');
            $stmt->execute([$id]);
            $found = $stmt->fetch();
            if (!$found) { http_response_code(404); exit('Asset item tidak ditemukan.'); }
            $item = array_merge($item, $found);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = asset_item_post_data();
            if ($data['asset_code'] === '' || $data['asset_name'] === '' || $data['asset_type'] === '') {
                flash('Asset code, type, dan nama aset wajib diisi.', 'err');
                redirect_to('asset_item_form', $id > 0 ? ['id' => $id] : []);
            }
            try {
                if ($id > 0) {
                    $data[] = $id;
                    $pdo->prepare('UPDATE asset_items SET asset_code=?, company_id=?, asset_mode=?, asset_type=?, asset_name=?, brand=?, model=?, serial_number=?, manufacture_year=?, warranty_until=?, installed_at=?, purchase_value=?, current_value=?, status=?, location_label=?, notes=? WHERE id=?')->execute(array_values($data));
                    flash('Asset item berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO asset_items (asset_code, company_id, asset_mode, asset_type, asset_name, brand, model, serial_number, manufacture_year, warranty_until, installed_at, purchase_value, current_value, status, location_label, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($data));
                    $id = (int)$pdo->lastInsertId();
                    flash('Asset item berhasil ditambahkan.');
                }
                redirect_to('asset_item_form', ['id' => $id]);
            } catch (Throwable $e) {
                flash('Gagal simpan asset item: ' . $e->getMessage(), 'err');
            }
        }
        render_header($id > 0 ? 'Edit Asset Item' : 'Tambah Asset Item', $user);
        echo asset_nav_html();
        echo asset_item_form_html($pdo, $item, $id > 0);
        if ($id > 0) {
            echo asset_item_maintenance_link_panel_html($pdo, $id);
            echo asset_item_member_manager_html($pdo, $id);
        }
        render_footer();
        break;

    case 'asset_item_member_action':
        $user = require_role(['admin']);
        $parentId = (int)($_POST['parent_asset_item_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        if ($parentId <= 0) { redirect_to('asset_items'); }
        $parent = asset_item_row($pdo, $parentId);
        if (!$parent) { redirect_to('asset_items'); }
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
                $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\nDipindahkan ke " . ($parent['asset_code'] ?? ''), (int)$oldParent['id']]);
                $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_parent_asset_item_id, to_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?, ?)')->execute([$childId, (int)$oldParent['parent_asset_item_id'], $parentId, $date, 'Pindah asset gabungan: ' . ($oldParent['parent_asset_code'] ?? '') . ' -> ' . ($parent['asset_code'] ?? ''), $user['name'] ?? '']);
            } else {
                $pdo->prepare('INSERT INTO asset_movements (asset_item_id, to_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?)')->execute([$childId, $parentId, $date, 'Pasang ke asset gabungan ' . ($parent['asset_code'] ?? ''), $user['name'] ?? '']);
            }
            $pdo->prepare('INSERT INTO asset_item_members (parent_asset_item_id, child_asset_item_id, role_name, attached_at, notes) VALUES (?, ?, ?, ?, ?)')->execute([$parentId, $childId, $role, $date, $notes]);
            flash('Asset item berhasil digabungkan.');
        }
        if ($action === 'detach') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $member = asset_item_member_row($pdo, $memberId);
            if ($member && (int)$member['parent_asset_item_id'] === $parentId) {
                $date = normalize_date_input((string)($_POST['detached_at'] ?? date('Y-m-d')));
                $reason = trim((string)($_POST['reason'] ?? 'Dilepas dari asset gabungan'));
                $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\nDetached: " . $reason, $memberId]);
                $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_parent_asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?)')->execute([(int)$member['child_asset_item_id'], $parentId, $date, $reason, $user['name'] ?? '']);
                flash('Asset item berhasil dipisahkan.');
            }
        }
        redirect_to('asset_item_form', ['id' => $parentId]);
        break;

    case 'asset_bundles':
        $user = require_role(['admin']);
        render_header('Asset Bundles', $user);
        echo asset_nav_html();
        $rows = $pdo->query('SELECT b.*, c.company_name, COUNT(m.id) member_count FROM asset_bundles b LEFT JOIN asset_companies c ON c.id=b.company_id LEFT JOIN asset_bundle_members m ON m.bundle_id=b.id AND m.detached_at IS NULL GROUP BY b.id ORDER BY b.updated_at DESC')->fetchAll();
        echo '<section class="panel"><div class="split"><h1>Asset Bundles / No Aset Pemeliharaan</h1><div class="actions"><a class="btn primary" href="' . route_url('asset_bundle_form') . '">Tambah Bundle</a><a class="btn" href="' . route_url('export_excel', ['type' => 'asset_bundles']) . '">Export Excel</a></div></div>' . asset_bundles_table($rows) . '</section>';
        render_footer();
        break;

    case 'asset_bundle_form':
        $user = require_role(['admin']);
        $id = (int)($_GET['id'] ?? 0);
        $bundle = asset_bundle_defaults();
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM asset_bundles WHERE id=?');
            $stmt->execute([$id]);
            $found = $stmt->fetch();
            if (!$found) { http_response_code(404); exit('Asset bundle tidak ditemukan.'); }
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
                    $data[] = $id;
                    $pdo->prepare('UPDATE asset_bundles SET maintenance_asset_code=?, company_id=?, bundle_type=?, bundle_name=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=?, status=?, notes=? WHERE id=?')->execute(array_values($data));
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
        break;

    case 'asset_bundle_member_action':
        $user = require_role(['admin']);
        $bundleId = (int)($_POST['bundle_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        if ($bundleId <= 0) { redirect_to('asset_bundles'); }
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
        break;

    case 'asset_repairs':
        $user = require_role(['admin']);
        render_header('Repair History', $user);
        echo asset_nav_html();
        $rows = asset_repair_rows($pdo);
        echo '<section class="panel"><div class="split"><h1>Repair History</h1><a class="btn primary" href="' . route_url('asset_repair_form') . '">Tambah Repair</a></div>' . asset_repairs_table($rows) . '</section>';
        render_footer();
        break;

    case 'asset_repair_form':
        $user = require_role(['admin']);
        $id = (int)($_GET['id'] ?? 0);
        $repair = asset_repair_defaults();
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM asset_repairs WHERE id=?');
            $stmt->execute([$id]);
            $found = $stmt->fetch();
            if (!$found) { http_response_code(404); exit('Repair tidak ditemukan.'); }
            $repair = array_merge($repair, $found);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = asset_repair_post_data();
            if ((int)$data['asset_item_id'] <= 0 || $data['repair_date'] === '') {
                flash('Asset item dan tanggal perbaikan wajib diisi.', 'err');
                redirect_to('asset_repair_form', $id > 0 ? ['id' => $id] : []);
            }
            if ($id > 0) {
                $data[] = $id;
                $pdo->prepare('UPDATE asset_repairs SET asset_item_id=?, bundle_id=?, repair_date=?, repair_location=?, repair_vendor=?, problem_description=?, repair_action=?, spare_part_replaced=?, repair_cost=?, warranty_claim=?, technician_or_pic=?, notes=? WHERE id=?')->execute(array_values($data));
                $repairId = $id;
                $pdo->prepare('DELETE FROM asset_repair_parts WHERE repair_id=?')->execute([$repairId]);
            } else {
                $pdo->prepare('INSERT INTO asset_repairs (asset_item_id, bundle_id, repair_date, repair_location, repair_vendor, problem_description, repair_action, spare_part_replaced, repair_cost, warranty_claim, technician_or_pic, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($data));
                $repairId = (int)$pdo->lastInsertId();
            }
            save_repair_parts($pdo, $repairId, $_POST['parts'] ?? []);
            flash('Repair history berhasil disimpan.');
            redirect_to('asset_repairs');
        }
        render_header($id > 0 ? 'Edit Repair' : 'Tambah Repair', $user);
        echo asset_nav_html();
        echo asset_repair_form_html($pdo, $repair, $id);
        render_footer();
        break;

    case 'asset_movements':
        $user = require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            handle_asset_movement_transaction($pdo, $user);
            redirect_to('asset_movements');
        }
        render_header('Mutasi / Tukar Pasang', $user);
        echo asset_nav_html();
        $rows = asset_movement_rows($pdo);
        echo asset_movement_transaction_form_html($pdo, $user);
        echo '<section class="panel"><h1>Riwayat Mutasi / Tukar Pasang</h1><p class="muted">Riwayat ini terbentuk dari transaksi manual di atas dan dari perubahan relasi asset gabungan/maintenance asset.</p>' . asset_movements_table($rows) . '</section>';
        render_footer();
        break;

    case 'pc_locations':
        $user = require_role(['admin']);
        render_header('Titik Lokasi PC', $user);
        $rows = $pdo->query('SELECT pc_id, owner_name, computer_name, location_label, latitude, longitude, location_radius_m FROM pcs ORDER BY COALESCE(NULLIF(location_label, ""), "ZZZ"), pc_id')->fetchAll();
        $groups = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['location_label'] ?? ''));
            if ($key === '') {
                $key = !empty($row['latitude']) && !empty($row['longitude']) ? 'Lokasi tanpa nama' : 'Belum diset';
            }
            $groups[$key][] = $row;
        }
        echo '<style>.location-group{border:1px solid #dfe5ee;border-radius:8px;margin-bottom:16px;overflow:hidden;background:#fff}.location-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}.location-head h2{margin:0}.location-status{display:flex;gap:8px;flex-wrap:wrap}.coord{font-family:Consolas,monospace;font-size:12px;white-space:nowrap}.map-frame{width:100%;height:320px;border:1px solid #dfe5ee;border-radius:8px;background:#f8fafc}</style>';
        echo '<section class="panel"><div class="split"><div><h1>Titik Lokasi PC</h1><p class="muted">Koordinat di sini menjadi patokan scan QR. Untuk PC yang punya titik GPS, teknisi hanya bisa scan dalam radius yang diset, default 5 meter.</p></div><div class="actions"><a class="btn" href="' . route_url('pcs') . '">Kembali ke Data PC</a><a class="btn primary" href="' . route_url('pc_form') . '">Tambah PC</a></div></div></section>';
        foreach ($groups as $groupName => $items) {
            $ready = 0;
            foreach ($items as $item) {
                if (!empty($item['latitude']) && !empty($item['longitude'])) { $ready++; }
            }
            echo '<section class="location-group"><div class="location-head"><h2>' . e($groupName) . '</h2><div class="location-status"><span class="badge ok">' . e($ready) . ' GPS siap</span><span class="badge">' . e(count($items)) . ' PC</span></div></div><table><tr><th>PcID</th><th>Owner / Computer</th><th>Koordinat</th><th>Radius</th><th>Patokan Scan</th><th>Aksi</th></tr>';
            foreach ($items as $pc) {
                $hasGps = !empty($pc['latitude']) && !empty($pc['longitude']);
                $map = $hasGps ? 'https://www.google.com/maps?q=' . rawurlencode((string)$pc['latitude'] . ',' . (string)$pc['longitude']) : '';
                echo '<tr><td><strong>' . e($pc['pc_id']) . '</strong></td><td>' . e($pc['owner_name']) . '<br><span class="muted">' . e($pc['computer_name'] ?: '-') . '</span></td><td>' . ($hasGps ? '<span class="coord">' . e($pc['latitude'] . ', ' . $pc['longitude']) . '</span>' : '<span class="badge danger">Belum ada GPS</span>') . '</td><td>' . e($pc['location_radius_m'] ?: 5) . ' m</td><td>' . ($hasGps ? '<span class="badge ok">Aktif untuk validasi scan</span>' : '<span class="muted">Scan tidak dibatasi lokasi sampai GPS diset.</span>') . '</td><td><div class="actions"><a class="btn" href="' . route_url('pc_location', ['pc_id' => $pc['pc_id']]) . '">Set Lokasi</a><a class="btn" href="' . route_url('pc_form', ['pc_id' => $pc['pc_id']]) . '">Edit</a>' . ($hasGps ? '<a class="btn" target="_blank" rel="noopener" href="' . e($map) . '">Maps</a>' : '') . '</div></td></tr>';
            }
            echo '</table></section>';
        }
        render_footer();
        break;

    case 'pc_form':
        $user = require_role(['admin']);
        $pcId = strtoupper(trim((string)($_GET['pc_id'] ?? '')));
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
            if (!$found) { http_response_code(404); exit('PC tidak ditemukan.'); }
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
                    flash('PcID sudah ada. Gunakan PcID lain atau edit data existing.', 'err');
                    redirect_to('pc_form');
                }
                $columns = array_keys($pcData);
                $placeholders = implode(', ', array_fill(0, count($columns) + 1, '?'));
                $stmt = $pdo->prepare('INSERT INTO pcs (pc_id, ' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
                $stmt->execute([$postedPcId, ...array_values($pcData)]);
                sync_pc_maintenance_asset($pdo, $postedPcId);
                flash('PC baru berhasil ditambahkan. Label QR sudah bisa dicetak.');
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
        break;

    case 'employee_source':
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
                if ($config['bridge_token'] === '') {
                    $oldConfig = company_source_config($pdo);
                    $config['bridge_token'] = (string)($oldConfig['bridge_token'] ?? '');
                }
                save_company_source_config($pdo, $config);
                if (($_POST['action'] ?? '') === 'sync') {
                    try {
                        $count = sync_company_directory($pdo);
                        flash('Sync company dari SQL Server berhasil. Data company aktif: ' . $count . ' row.');
                    } catch (Throwable $e) {
                        flash('Sync company gagal: ' . $e->getMessage(), 'err');
                    }
                } elseif (($_POST['action'] ?? '') === 'import_file') {
                    try {
                        $rows = parse_company_import_upload($config);
                        $count = sync_company_rows_to_local($pdo, $rows);
                        flash('Import company berhasil. Data company diproses: ' . $count . ' row.');
                    } catch (Throwable $e) {
                        flash('Import company gagal: ' . $e->getMessage(), 'err');
                    }
                } elseif (($_POST['action'] ?? '') === 'test') {
                    try {
                        $rows = fetch_company_source_rows($config, 5);
                        flash('Koneksi SQL Server company berhasil. Sample data terbaca: ' . count($rows) . ' row.');
                    } catch (Throwable $e) {
                        flash('Test SQL Server company gagal: ' . $e->getMessage(), 'err');
                    }
                } else {
                    flash('Setup sumber company tersimpan.');
                }
                redirect_to('employee_source');
            }
            $config = [
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'host' => trim((string)($_POST['host'] ?? '')),
                'port' => trim((string)($_POST['port'] ?? '3306')),
                'database' => trim((string)($_POST['database'] ?? '')),
                'username' => trim((string)($_POST['username'] ?? '')),
                'password' => (string)($_POST['password'] ?? ''),
                'table_name' => trim((string)($_POST['table_name'] ?? '')),
                'nik_field' => trim((string)($_POST['nik_field'] ?? 'NIK')),
                'name_field' => trim((string)($_POST['name_field'] ?? 'Nama')),
                'department_field' => trim((string)($_POST['department_field'] ?? '')),
                'limit_rows' => trim((string)($_POST['limit_rows'] ?? '500')),
            ];
            if ($config['password'] === '') {
                $oldConfig = employee_source_config($pdo);
                $config['password'] = (string)($oldConfig['password'] ?? '');
            }
            save_employee_source_config($pdo, $config);
            if (($_POST['action'] ?? '') === 'sync') {
                try {
                    $count = sync_employee_directory($pdo);
                    flash('Sync karyawan dari MariaDB portal berhasil. Data aktif: ' . $count . ' row.');
                } catch (Throwable $e) {
                    flash('Sync karyawan gagal: ' . $e->getMessage(), 'err');
                }
            } elseif (($_POST['action'] ?? '') === 'test') {
                try {
                    $rows = fetch_employee_options($pdo, 5, true);
                    flash('Koneksi MariaDB portal berhasil. Sample data terbaca: ' . count($rows) . ' row.');
                } catch (Throwable $e) {
                    flash('Test MariaDB portal gagal: ' . $e->getMessage(), 'err');
                }
            } else {
                flash('Setup sumber karyawan tersimpan.');
            }
            redirect_to('employee_source');
        }
        render_header('Employee & Company Source', $user);
        echo employee_source_form_html($pdo);
        echo company_source_form_html($pdo);
        render_footer();
        break;

    case 'employee_search':
        require_role(['admin', 'maintenance_admin']);
        header('Content-Type: application/json; charset=utf-8');
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
        echo json_encode(['ok' => true, 'data' => employee_search_rows($pdo, $q, $limit)], JSON_UNESCAPED_UNICODE);
        exit;

    case 'printers':
        $user = require_role(['admin']);
        render_header('Data Printer', $user);
        if (!printer_schema_ready($pdo)) {
            echo printer_schema_warning();
            render_footer();
            break;
        }
        $rows = $pdo->query('SELECT * FROM printers ORDER BY updated_at DESC')->fetchAll();
        echo '<section class="panel"><div class="split"><h1>Data Printer</h1><div class="actions"><a class="btn primary" href="' . route_url('printer_form') . '">Tambah Printer</a><a class="btn" href="' . route_url('labels', ['type' => 'printer']) . '">QR Label Printer</a></div></div>' . printer_table($rows, true) . '</section>';
        render_footer();
        break;

    case 'printer_locations':
        $user = require_role(['admin']);
        render_header('Titik Lokasi Printer', $user);
        if (!printer_schema_ready($pdo)) {
            echo printer_schema_warning();
            render_footer();
            break;
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
        echo '<style>.location-group{border:1px solid #dfe5ee;border-radius:8px;margin-bottom:16px;overflow:hidden;background:#fff}.location-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}.location-head h2{margin:0}.location-status{display:flex;gap:8px;flex-wrap:wrap}.coord{font-family:Consolas,monospace;font-size:12px;white-space:nowrap}</style>';
        echo '<section class="panel"><div class="split"><div><h1>Titik Lokasi Printer</h1><p class="muted">Koordinat ini menjadi patokan scan QR printer. Kalau GPS printer sudah diset, teknisi hanya bisa scan dalam radius yang ditentukan, default 5 meter.</p></div><div class="actions"><a class="btn" href="' . route_url('printers') . '">Kembali ke Data Printer</a><a class="btn primary" href="' . route_url('printer_form') . '">Tambah Printer</a></div></div></section>';
        foreach ($groups as $groupName => $items) {
            $ready = 0;
            foreach ($items as $item) {
                if (!empty($item['latitude']) && !empty($item['longitude'])) { $ready++; }
            }
            echo '<section class="location-group"><div class="location-head"><h2>' . e($groupName) . '</h2><div class="location-status"><span class="badge ok">' . e($ready) . ' GPS siap</span><span class="badge">' . e(count($items)) . ' Printer</span></div></div><table><tr><th>PrnID</th><th>Printer / Model</th><th>Koordinat</th><th>Radius</th><th>Patokan Scan</th><th>Aksi</th></tr>';
            foreach ($items as $printer) {
                $hasGps = !empty($printer['latitude']) && !empty($printer['longitude']);
                $map = $hasGps ? 'https://www.google.com/maps?q=' . rawurlencode((string)$printer['latitude'] . ',' . (string)$printer['longitude']) : '';
                echo '<tr><td><strong>' . e($printer['prn_id']) . '</strong></td><td>' . e($printer['printer_name']) . '<br><span class="muted">' . e($printer['model_printer'] ?: '-') . ($printer['ip_printer'] ? ' / ' . e($printer['ip_printer']) : '') . '</span></td><td>' . ($hasGps ? '<span class="coord">' . e($printer['latitude'] . ', ' . $printer['longitude']) . '</span>' : '<span class="badge danger">Belum ada GPS</span>') . '</td><td>' . e($printer['location_radius_m'] ?: 5) . ' m</td><td>' . ($hasGps ? '<span class="badge ok">Aktif untuk validasi scan</span>' : '<span class="muted">Scan tidak dibatasi lokasi sampai GPS diset.</span>') . '</td><td><div class="actions"><a class="btn" href="' . route_url('printer_location', ['prn_id' => $printer['prn_id']]) . '">Set Lokasi</a><a class="btn" href="' . route_url('printer_form', ['prn_id' => $printer['prn_id']]) . '">Edit</a>' . ($hasGps ? '<a class="btn" target="_blank" rel="noopener" href="' . e($map) . '">Maps</a>' : '') . '</div></td></tr>';
            }
            echo '</table></section>';
        }
        render_footer();
        break;

    case 'printer_form':
        $user = require_role(['admin']);
        if (!printer_schema_ready($pdo)) {
            render_header('Printer Schema', $user);
            echo printer_schema_warning();
            render_footer();
            break;
        }
        $prnId = strtoupper(trim((string)($_GET['prn_id'] ?? '')));
        $editing = $prnId !== '';
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
            'physical_condition' => '',
        ];
        if ($editing) {
            $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
            $stmt->execute([$prnId]);
            $found = $stmt->fetch();
            if (!$found) { http_response_code(404); exit('Printer tidak ditemukan.'); }
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
            $radius = max(1, (int)(($_POST['location_radius_m'] ?? '') !== '' ? $_POST['location_radius_m'] : 5));
            $values = [
                $securityCode,
                $printerName,
                trim((string)($_POST['location'] ?? '')),
                $latitude,
                $longitude,
                $radius,
                trim((string)($_POST['serial_number'] ?? '')),
                trim((string)($_POST['ip_printer'] ?? '')),
                trim((string)($_POST['model_printer'] ?? '')),
                trim((string)($_POST['physical_condition'] ?? '')),
            ];
            if ($editing) {
                $stmt = $pdo->prepare('UPDATE printers SET security_code=?, printer_name=?, location=?, latitude=?, longitude=?, location_radius_m=?, serial_number=?, ip_printer=?, model_printer=?, physical_condition=? WHERE prn_id=?');
                $stmt->execute([...$values, $prnId]);
                sync_printer_maintenance_asset($pdo, $prnId);
                flash('Data printer berhasil diperbarui.');
                redirect_to('printer_detail', ['prn_id' => $prnId]);
            }
            $stmt = $pdo->prepare('INSERT INTO printers (prn_id, security_code, printer_name, location, latitude, longitude, location_radius_m, serial_number, ip_printer, model_printer, physical_condition) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$postedPrnId, ...$values]);
            sync_printer_maintenance_asset($pdo, $postedPrnId);
            flash('Printer baru berhasil ditambahkan. Label QR sudah bisa dicetak.');
            redirect_to('printer_detail', ['prn_id' => $postedPrnId]);
        }
        render_header($editing ? 'Edit Printer' : 'Tambah Printer', $user);
        echo printer_form_html($printer, $editing);
        render_footer();
        break;

    case 'printer_detail':
        $user = require_role(['admin']);
        if (!printer_schema_ready($pdo)) {
            render_header('Printer Schema', $user);
            echo printer_schema_warning();
            render_footer();
            break;
        }
        $prnId = (string)($_GET['prn_id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
        $stmt->execute([$prnId]);
        $printer = $stmt->fetch();
        if (!$printer) { http_response_code(404); exit('Printer tidak ditemukan.'); }
        $printerMaintenanceAsset = ensure_printer_maintenance_asset($pdo, $printer);
        if ($printerMaintenanceAsset) {
            $printer['maintenance_asset_code'] = $printerMaintenanceAsset['maintenance_asset_code'];
            $printer['security_code'] = $printerMaintenanceAsset['security_code'];
        }
        $assetCode = printer_asset_code($printer);
        render_header('Detail Printer', $user);
        echo '<section class="panel"><div class="split"><div><h1>' . e($printer['prn_id']) . '</h1><p>' . e($printer['printer_name']) . ' - ' . e($printer['location'] ?: 'Lokasi belum diisi') . '</p><p><span class="badge">Maintenance Asset ID ' . e($assetCode) . '</span></p></div><div class="actions"><a class="btn primary" href="' . route_url('printer_form', ['prn_id' => $prnId]) . '">Edit Printer</a><a class="btn" href="' . route_url('printer_location', ['prn_id' => $prnId]) . '">Set Lokasi GPS</a><a class="btn" href="' . route_url('schedule_form', ['asset' => $assetCode]) . '">Tambah Schedule</a><a class="btn" download="PcConnect-Label-' . e($assetCode) . '.png" href="' . route_url('qr_png', ['code' => $assetCode]) . '">Download Label PNG</a><a class="btn" href="' . mobile_asset_url($assetCode) . '">Mobile QR URL</a></div></div></section>';
        if (!empty($printer['latitude']) && !empty($printer['longitude'])) {
            $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string)$printer['latitude'] . ',' . (string)$printer['longitude']);
            echo '<section class="panel"><h2>Lokasi Printer</h2><table><tr><th>Nama Lokasi</th><td>' . e($printer['location'] ?: '-') . '</td></tr><tr><th>GPS</th><td>' . e($printer['latitude'] . ', ' . $printer['longitude']) . '</td></tr><tr><th>Radius Scan</th><td>' . e($printer['location_radius_m'] ?: 5) . ' meter</td></tr></table><p><a class="btn" target="_blank" rel="noopener" href="' . e($mapUrl) . '">Buka di Google Maps</a></p></section>';
        }
        echo '<section class="panel"><h2>Data Printer</h2><table><tr><th>Printer Name</th><td>' . e($printer['printer_name']) . '</td></tr><tr><th>Location</th><td>' . e($printer['location']) . '</td></tr><tr><th>Serial Number</th><td>' . e($printer['serial_number']) . '</td></tr><tr><th>IP Printer</th><td>' . e($printer['ip_printer']) . '</td></tr><tr><th>Model Printer</th><td>' . e($printer['model_printer']) . '</td></tr><tr><th>Kondisi Fisik</th><td>' . nl2br(e($printer['physical_condition'])) . '</td></tr></table></section>';
        render_footer();
        break;

    case 'printer_location':
        $user = require_role(['admin']);
        if (!printer_schema_ready($pdo)) {
            render_header('Printer Schema', $user);
            echo printer_schema_warning();
            render_footer();
            break;
        }
        $prnId = (string)($_GET['prn_id'] ?? $_POST['prn_id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
        $stmt->execute([$prnId]);
        $printer = $stmt->fetch();
        if (!$printer) { http_response_code(404); exit('Printer tidak ditemukan.'); }
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
        echo '<section class="panel"><h2>Cara isi manual dari Google Maps</h2><ol><li>Buka Google Maps di ponsel.</li><li>Tekan lama titik lokasi printer sampai muncul pin.</li><li>Salin angka koordinat, contoh <code>-6.200000, 106.816666</code>.</li><li>Masukkan angka pertama ke Latitude dan angka kedua ke Longitude.</li></ol></section>';
        echo '<script>(function(){var btn=document.getElementById("usePrinterLocation"),lat=document.getElementById("printerLatitude"),lng=document.getElementById("printerLongitude"),status=document.getElementById("printerLocationStatus");btn.addEventListener("click",function(){if(!navigator.geolocation){status.textContent="Browser tidak mendukung GPS.";return;}status.textContent="Mengambil GPS ponsel...";navigator.geolocation.getCurrentPosition(function(p){lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);status.textContent="GPS ponsel terbaca. Akurasi sekitar "+Math.round(p.coords.accuracy)+" meter. Klik Simpan Lokasi.";},function(){status.textContent="Gagal mengambil GPS. Pastikan izin Location aktif, HTTPS aktif, dan GPS ponsel menyala.";},{enableHighAccuracy:true,timeout:20000,maximumAge:0});});})();</script>';
        render_footer();
        break;

    case 'pc_detail':
        $user = require_role(['admin']);
        $pcId = (string)($_GET['pc_id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id = ?');
        $stmt->execute([$pcId]);
        $pc = $stmt->fetch();
        if (!$pc) { http_response_code(404); exit('PC tidak ditemukan.'); }
        $pcMaintenanceAsset = ensure_pc_maintenance_asset($pdo, $pc);
        if ($pcMaintenanceAsset) {
            $pc['maintenance_asset_code'] = $pcMaintenanceAsset['maintenance_asset_code'];
            $pc['security_code'] = $pcMaintenanceAsset['security_code'];
        }
        render_header('Detail ' . $pcId, $user);
        echo '<style>.analysis-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.analysis-grid .panel{min-width:0;overflow:hidden}.analysis-table{table-layout:auto}.analysis-table td,.analysis-table th{overflow-wrap:anywhere;word-break:normal}.risk-list{display:grid;gap:10px}.risk-card{border:1px solid #dfe5ee;border-radius:8px;padding:12px;background:#fff}.risk-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap}.risk-title{font-weight:700}.risk-meta{display:flex;gap:6px;flex-wrap:wrap;margin:7px 0}.risk-pill{display:inline-block;border-radius:999px;background:#eef2f7;padding:3px 8px;font-size:12px}.risk-high{background:#fee2e2;color:#991b1b}.risk-medium{background:#fef3c7;color:#92400e}.risk-low{background:#dcfce7;color:#166534}.risk-path{font-family:Consolas,monospace;font-size:12px;background:#f8fafc;border-radius:6px;padding:6px;overflow-wrap:anywhere}.risk-note{margin:6px 0 0}.wide-panel{grid-column:1/-1}@media(max-width:900px){.analysis-grid{grid-template-columns:1fr}}</style>';
        $assetCode = asset_code($pc);
        echo '<section class="panel"><div class="split"><div><h1>' . e($pc['pc_id']) . '</h1><p>' . e($pc['owner_name']) . ' - ' . e($pc['computer_name'] ?: 'Computer name belum ada') . '</p><p><span class="badge">Maintenance Asset ID ' . e($assetCode) . '</span> <span class="badge">NIK ' . e($pc['employee_nik'] ?? '-') . '</span></p></div><div class="actions"><a class="btn primary" href="' . route_url('pc_form', ['pc_id' => $pcId]) . '">Edit PC</a><a class="btn" href="' . route_url('pc_location', ['pc_id' => $pcId]) . '">Set Lokasi GPS</a><a class="btn good" href="' . route_url('download_agent', ['pc_id' => $pcId]) . '">Download PcNalisa PC ini</a><a class="btn" href="' . route_url('upload_analysis', ['pc_id' => $pcId]) . '">Upload JSON Analisa</a><a class="btn" href="' . mobile_asset_url($assetCode) . '">Mobile QR URL</a></div></div></section>';
        echo pc_asset_link_detail_html($pdo, $pc);
        if (!empty($pc['latitude']) && !empty($pc['longitude'])) {
            $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string)$pc['latitude'] . ',' . (string)$pc['longitude']);
            echo '<section class="panel"><h2>Lokasi PC</h2><table><tr><th>Nama Lokasi</th><td>' . e($pc['location_label'] ?: '-') . '</td></tr><tr><th>GPS</th><td>' . e($pc['latitude'] . ', ' . $pc['longitude']) . '</td></tr><tr><th>Radius Scan</th><td>' . e($pc['location_radius_m'] ?: 5) . ' meter</td></tr></table><p><a class="btn" target="_blank" rel="noopener" href="' . e($mapUrl) . '">Buka di Google Maps</a></p></section>';
        }
        echo '<section class="analysis-grid">';
        detail_block('Spesifikasi Umum', $pc['general_specs']);
        detail_block('Software', $pc['software']);
        detail_block('Device Management', $pc['device_management']);
        detail_block('Benchmark', $pc['benchmark']);
        detail_block('Startup Analisa', $pc['startup_analysis']);
        echo '<div class="panel wide-panel"><h2>Rekomendasi AI Analitik</h2><p>' . e($pc['ai_recommendation'] ?: 'Belum ada hasil analisa PcNalisa.') . '</p></div>';
        echo '</section>';
        render_footer();
        break;

    case 'pc_location':
        $user = require_role(['admin']);
        $pcId = (string)($_GET['pc_id'] ?? $_POST['pc_id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id = ?');
        $stmt->execute([$pcId]);
        $pc = $stmt->fetch();
        if (!$pc) { http_response_code(404); exit('PC tidak ditemukan.'); }
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
        echo '<section class="panel"><h2>Cara isi manual dari Google Maps</h2><ol><li>Buka Google Maps di ponsel.</li><li>Tekan lama titik lokasi PC sampai muncul pin.</li><li>Salin angka koordinat, contoh <code>-6.200000, 106.816666</code>.</li><li>Masukkan angka pertama ke Latitude dan angka kedua ke Longitude.</li></ol></section>';
        echo '<script>(function(){var btn=document.getElementById("useCurrentLocation"),lat=document.getElementById("pcLatitude"),lng=document.getElementById("pcLongitude"),status=document.getElementById("locationStatus");btn.addEventListener("click",function(){if(!navigator.geolocation){status.textContent="Browser tidak mendukung GPS.";return;}status.textContent="Mengambil GPS ponsel...";navigator.geolocation.getCurrentPosition(function(p){lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);status.textContent="GPS ponsel terbaca. Akurasi sekitar "+Math.round(p.coords.accuracy)+" meter. Klik Simpan Lokasi.";},function(){status.textContent="Gagal mengambil GPS. Pastikan izin Location aktif, HTTPS aktif, dan GPS ponsel menyala.";},{enableHighAccuracy:true,timeout:20000,maximumAge:0});});})();</script>';
        render_footer();
        break;

    case 'download_agent':
        $user = require_role(['admin']);
        $pcId = trim($_GET['pc_id'] ?? '');
        if ($pcId !== '') {
            $stmt = $pdo->prepare('SELECT pc_id, owner_name FROM pcs WHERE pc_id = ?');
            $stmt->execute([$pcId]);
            $pc = $stmt->fetch();
            if (!$pc) { http_response_code(404); exit('PC tidak ditemukan.'); }
            $agentPath = dirname(__DIR__) . '/tools/PcNalisa-Agent.ps1';
            if (!is_readable($agentPath)) {
                http_response_code(500);
                exit('Template PcNalisa-Agent.ps1 tidak ditemukan atau tidak bisa dibaca. Pastikan folder tools ikut di-upload ke QNAP.');
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
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="PcNalisa-' . preg_replace('/[^A-Za-z0-9_-]/', '', $pc['pc_id']) . '.ps1"');
            header('Content-Length: ' . strlen($configured));
            echo $configured;
            break;
        }
        render_header('Download PcNalisa', $user);
        echo '<section class="panel"><h1>PcNalisa Agent</h1><p>Download agent dan launcher. Untuk file per PC, buka detail PC lalu klik Download PcNalisa PC ini.</p><div class="actions"><a class="btn primary" href="../tools/PcNalisa-Agent.ps1" download>Download PcNalisa-Agent.ps1</a><a class="btn" href="../tools/PcNalisa-Run.cmd" download>Download PcNalisa-Run.cmd</a><a class="btn" href="../tools/PcNalisa-Run-Stress.cmd" download>Download Stress Launcher</a><a class="btn" href="../tools/CARA-PAKAI-PCNALISA.txt" download>Cara Pakai PcNalisa</a><a class="btn" href="' . route_url('upload_analysis') . '">Upload Analisa JSON</a></div></section>';
        echo '<section class="panel"><h2>Cara menjalankan</h2><ol><li>Buka menu <strong>PC</strong>, lalu klik <strong>Detail</strong> pada PC yang ingin dianalisa.</li><li>Klik <strong>Download PcNalisa PC ini</strong>. File per PC sudah berisi PcID, owner, server URL, dan token.</li><li>Simpan file <code>PcNalisa-PCxxxxx.ps1</code> di satu folder bersama <code>PcNalisa-Run.cmd</code>.</li><li>Klik kanan <code>PcNalisa-Run.cmd</code>, pilih <strong>Run as administrator</strong>.</li><li>Untuk uji beban offline CPU/RAM/Storage, jalankan <code>PcNalisa-Run-Stress.cmd</code> sebagai Administrator.</li><li>Tunggu sampai selesai. Jika upload otomatis gagal, upload file JSON fallback lewat menu <strong>Upload Analisa JSON</strong>.</li></ol><h3>Perintah manual PowerShell</h3><pre>powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\PcNalisa-PC000001.ps1"</pre><h3>Perintah stress test manual</h3><pre>powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\PcNalisa-PC000001.ps1" -StressTest -StressSeconds 30</pre><h3>Perintah manual agent umum</h3><pre>powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\PcNalisa-Agent.ps1" -PcID "PC000001" -Owner "Nama User" -ServerUrl "http://server/PcConnect/public/index.php?route=api_ingest" -Token "agent_token_di_config"</pre></section>';
        render_footer();
        break;

    case 'upload_analysis':
        $user = require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = '';
            $uploadError = $_FILES['analysis_json']['error'] ?? UPLOAD_ERR_NO_FILE;
            if (!empty($_FILES['analysis_json']['tmp_name']) && $uploadError === UPLOAD_ERR_OK) {
                $raw = file_get_contents($_FILES['analysis_json']['tmp_name']);
            } else {
                $raw = (string)($_POST['analysis_text'] ?? '');
            }
            [$payload, $jsonError, $cleanLength] = decode_json_payload($raw);
            if (!is_array($payload) || empty($payload['pc_id'])) {
                $reason = 'File JSON tidak valid atau PcID kosong. JSON error: ' . $jsonError . '. Panjang JSON terbaca: ' . $cleanLength . ' bytes.';
                if ($raw === '' && $uploadError !== UPLOAD_ERR_NO_FILE) {
                    $reason .= ' Upload error code: ' . $uploadError . '.';
                }
                flash($reason, 'err');
                redirect_to('upload_analysis', ['pc_id' => $_POST['pc_id'] ?? '']);
            }
            if (!empty($_POST['pc_id']) && $_POST['pc_id'] !== $payload['pc_id']) {
                flash('PcID pada file JSON tidak sama dengan PcID yang dipilih.', 'err');
                redirect_to('upload_analysis', ['pc_id' => $_POST['pc_id']]);
            }
            $result = ingest_analysis_payload($pdo, $payload);
            flash('Analisa JSON berhasil di-import untuk ' . $result['pc_id'] . '.');
            redirect_to('pc_detail', ['pc_id' => $result['pc_id']]);
        }
        $pcId = trim($_GET['pc_id'] ?? '');
        render_header('Upload Analisa JSON', $user);
        echo '<section class="panel"><h1>Upload Fallback JSON PcNalisa</h1><p>Pilih file seperti <code>PcNalisa_PC000001_20260629090902.json</code>. Isi file akan otomatis dimasukkan ke kotak JSON di bawah, jadi tetap berjalan meskipun upload file QNAP/PHP dibatasi.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="pc_id" value="' . e($pcId) . '"><label>File JSON<input id="analysisFile" type="file" name="analysis_json" accept=".json,application/json"></label><label>Isi JSON<textarea id="analysisText" name="analysis_text" placeholder="{ ... }" style="min-height:260px;font-family:Consolas,monospace"></textarea></label><button class="btn primary">Import ke PcConnect</button></form></section><script>document.getElementById("analysisFile").addEventListener("change", function(){var f=this.files&&this.files[0]; if(!f) return; var r=new FileReader(); r.onload=function(){document.getElementById("analysisText").value=String(r.result||"");}; r.readAsText(f);});</script>';
        render_footer();
        break;

    case 'maintenance':
        $user = require_login();
        render_header('Maintenance', $user);
        $printerReady = printer_schema_ready($pdo);
        if (!$printerReady) {
            echo printer_schema_warning();
        }
        $where = $user['role'] === 'technician' ? 'WHERE s.technician_id = ' . (int)$user['id'] : '';
        try {
            if ($printerReady) {
                $rows = $pdo->query("SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, u.name technician FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id $where ORDER BY s.scheduled_date DESC, s.id DESC")->fetchAll();
            } else {
                $rows = $pdo->query("SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, p.computer_name, u.name technician FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id $where ORDER BY s.scheduled_date DESC, s.id DESC")->fetchAll();
            }
        } catch (Throwable $e) {
            echo '<section class="panel"><div class="flash err">Mode printer belum siap di database: ' . e($e->getMessage()) . '. Maintenance PC ditampilkan dalam mode aman.</div></section>';
            $rows = $pdo->query("SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, p.computer_name, u.name technician FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id $where ORDER BY s.scheduled_date DESC, s.id DESC")->fetchAll();
        }
        echo '<section class="panel"><div class="split"><h1>Hardware Maintenance Services</h1><div class="actions"><a class="btn" href="' . route_url('export_excel', ['type' => 'maintenance']) . '">Export Excel</a>';
        if (can_manage_maintenance($user)) {
            echo '<a class="btn danger" href="' . route_url('maintenance_cleanup') . '">Hapus Data</a><a class="btn primary" href="' . route_url('schedule_form') . '">Tambah Schedule</a>';
        }
        echo '</div></div></section><section class="panel"><table><tr><th>Tanggal</th><th>PcID</th><th>Owner</th><th>Teknisi</th><th>Status</th><th>Foto</th><th>Aksi</th></tr>';
        foreach ($rows as $row) {
            $photoStmt = $pdo->prepare('SELECT before_photos, process_photos, after_photos FROM maintenance_reports WHERE schedule_id=? ORDER BY id DESC LIMIT 1');
            $photoStmt->execute([$row['id']]);
            $photoReport = $photoStmt->fetch() ?: [];
            $beforeCount = count(json_decode((string)($photoReport['before_photos'] ?? '[]'), true) ?: []);
            $processCount = count(json_decode((string)($photoReport['process_photos'] ?? '[]'), true) ?: []);
            $afterCount = count(json_decode((string)($photoReport['after_photos'] ?? '[]'), true) ?: []);
            $actions = '<a class="btn" href="' . route_url('maintenance_do', ['id' => $row['id']]) . '">Buka</a>';
            if (can_manage_maintenance($user) && $row['status'] === 'completed') {
                $actions .= ' <a class="btn" href="' . route_url('report_print', ['id' => $row['id']]) . '">Report</a>';
            }
            echo '<tr><td>' . e($row['scheduled_date']) . '</td><td>' . e($row['asset_id']) . '<br><span class="badge">' . e($row['asset_type'] ?? 'pc') . '</span></td><td>' . e($row['owner_name']) . '</td><td>' . e($row['technician'] ?: '-') . '</td><td><span class="badge">' . e($row['status']) . '</span></td><td>Before: ' . e($beforeCount) . '<br>Process: ' . e($processCount) . '<br>After: ' . e($afterCount) . '</td><td>' . $actions . '</td></tr>';
        }
        echo '</table></section>';
        render_footer();
        break;

    case 'maintenance_cleanup':
        $user = require_role(['admin', 'maintenance_admin']);
        $pcFilter = trim((string)($_GET['pc_id'] ?? ''));
        $techFilter = (int)($_GET['technician_id'] ?? 0);
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $beforeDate = trim((string)($_GET['before_date'] ?? ''));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'delete_schedule') {
                delete_maintenance_schedule($pdo, (int)$_POST['id']);
                flash('Data maintenance berhasil dihapus.');
                redirect_to('maintenance_cleanup');
            }
            if ($action === 'delete_filtered') {
                if (trim((string)($_POST['confirm_text'] ?? '')) !== 'HAPUS') {
                    flash('Ketik HAPUS untuk konfirmasi hapus massal.', 'err');
                    redirect_to('maintenance_cleanup');
                }
                $params = [];
                $whereParts = [];
                if (trim((string)($_POST['pc_id'] ?? '')) !== '') {
                    $postedAssetFilter = trim((string)$_POST['pc_id']);
                    $whereParts[] = substr($postedAssetFilter, 0, 3) === 'PRN' ? 'printer_id=?' : 'pc_id=?';
                    $params[] = $postedAssetFilter;
                }
                if ((int)($_POST['technician_id'] ?? 0) > 0) { $whereParts[] = 'technician_id=?'; $params[] = (int)$_POST['technician_id']; }
                if (trim((string)($_POST['status'] ?? '')) !== '') { $whereParts[] = 'status=?'; $params[] = trim((string)$_POST['status']); }
                if (trim((string)($_POST['before_date'] ?? '')) !== '') { $whereParts[] = 'scheduled_date<=?'; $params[] = trim((string)$_POST['before_date']); }
                if (!$whereParts) {
                    flash('Pilih minimal satu filter sebelum hapus massal.', 'err');
                    redirect_to('maintenance_cleanup');
                }
                $stmt = $pdo->prepare('SELECT id FROM maintenance_schedules WHERE ' . implode(' AND ', $whereParts));
                $stmt->execute($params);
                $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                foreach ($ids as $scheduleId) {
                    delete_maintenance_schedule($pdo, $scheduleId);
                }
                flash(count($ids) . ' data maintenance berhasil dihapus.');
                redirect_to('maintenance_cleanup');
            }
        }
        render_header('Hapus Data Maintenance', $user);
        $printerReady = printer_schema_ready($pdo);
        $pcs = $pdo->query('SELECT pc_id, owner_name FROM pcs ORDER BY pc_id')->fetchAll();
        $printers = $printerReady ? $pdo->query('SELECT prn_id, printer_name FROM printers ORDER BY prn_id')->fetchAll() : [];
        $techs = $pdo->query("SELECT id, name FROM users WHERE role='technician' ORDER BY name")->fetchAll();
        $params = [];
        $whereParts = [];
        if ($pcFilter !== '') {
            if ($printerReady && substr($pcFilter, 0, 3) === 'PRN') { $whereParts[] = 's.printer_id=?'; } else { $whereParts[] = 's.pc_id=?'; }
            $params[] = $pcFilter;
        }
        if ($techFilter > 0) { $whereParts[] = 's.technician_id=?'; $params[] = $techFilter; }
        if ($statusFilter !== '') { $whereParts[] = 's.status=?'; $params[] = $statusFilter; }
        if ($beforeDate !== '') { $whereParts[] = 's.scheduled_date<=?'; $params[] = $beforeDate; }
        $whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
        $cleanupSql = $printerReady
            ? "SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, u.name technician FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id $whereSql ORDER BY s.scheduled_date DESC, s.id DESC LIMIT 200"
            : "SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, u.name technician FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id $whereSql ORDER BY s.scheduled_date DESC, s.id DESC LIMIT 200";
        $stmt = $pdo->prepare($cleanupSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        echo '<section class="panel"><div class="split"><div><h1>Hapus Data Maintenance</h1><p class="muted">Gunakan filter dulu. Hapus schedule akan ikut menghapus checklist, report, timeline, dan foto yang tersimpan di report.</p></div><a class="btn" href="' . route_url('maintenance') . '">Kembali</a></div></section>';
        echo '<section class="panel"><form method="get"><input type="hidden" name="route" value="maintenance_cleanup"><div class="grid four"><label>PC / Printer<select name="pc_id"><option value="">Semua Aset</option>';
        foreach ($pcs as $pc) { echo '<option value="' . e($pc['pc_id']) . '"' . ($pcFilter === $pc['pc_id'] ? ' selected' : '') . '>' . e($pc['pc_id'] . ' - ' . $pc['owner_name']) . '</option>'; }
        if ($printerReady) {
            echo '<optgroup label="Printer">';
            foreach ($printers as $printer) { echo '<option value="' . e($printer['prn_id']) . '"' . ($pcFilter === $printer['prn_id'] ? ' selected' : '') . '>' . e($printer['prn_id'] . ' - ' . $printer['printer_name']) . '</option>'; }
            echo '</optgroup>';
        }
        echo '</select></label><label>Teknisi<select name="technician_id"><option value="0">Semua Teknisi</option>';
        foreach ($techs as $tech) { echo '<option value="' . e($tech['id']) . '"' . ($techFilter === (int)$tech['id'] ? ' selected' : '') . '>' . e($tech['name']) . '</option>'; }
        echo '</select></label><label>Status<select name="status"><option value="">Semua Status</option>';
        foreach (['scheduled','validated','in_progress','completed','reopened'] as $status) { echo '<option value="' . e($status) . '"' . ($statusFilter === $status ? ' selected' : '') . '>' . e($status) . '</option>'; }
        echo '</select></label><label>Sampai Tanggal<input type="date" name="before_date" value="' . e($beforeDate) . '"></label></div><button class="btn primary">Filter</button></form></section>';
        echo '<section class="panel"><div class="split"><h2>Data Terfilter</h2><form method="post" onsubmit="return confirm(\'Hapus SEMUA data yang sesuai filter?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete_filtered"><input type="hidden" name="pc_id" value="' . e($pcFilter) . '"><input type="hidden" name="technician_id" value="' . e($techFilter) . '"><input type="hidden" name="status" value="' . e($statusFilter) . '"><input type="hidden" name="before_date" value="' . e($beforeDate) . '"><label style="margin:0">Konfirmasi<input name="confirm_text" placeholder="Ketik HAPUS"></label><button class="btn danger">Hapus Data Terfilter</button></form></div><table><tr><th>Tanggal</th><th>PcID</th><th>Owner</th><th>Teknisi</th><th>Status</th><th>Aksi</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . e($row['scheduled_date']) . '</td><td>' . e($row['asset_id']) . '</td><td>' . e($row['owner_name']) . '</td><td>' . e($row['technician'] ?: '-') . '</td><td><span class="badge">' . e($row['status']) . '</span></td><td><form method="post" onsubmit="return confirm(\'Hapus schedule maintenance ini?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn danger">Hapus</button></form></td></tr>';
        }
        echo '</table></section>';
        render_footer();
        break;

    case 'schedule_form':
        $user = require_role(['admin', 'maintenance_admin']);
        $printerReady = printer_schema_ready($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $technicianId = (int)($_POST['technician_id'] ?? 0);
            if ($technicianId <= 0) {
                flash('Teknisi wajib dipilih untuk schedule maintenance.', 'err');
                redirect_to('schedule_form');
            }
            [$assetType, $assetId] = $printerReady ? parse_schedule_asset((string)($_POST['asset_id'] ?? '')) : ['pc', strtoupper(trim((string)($_POST['pc_id'] ?? '')))];
            if ($assetId === '') {
                flash('Aset PC/Printer wajib dipilih.', 'err');
                redirect_to('schedule_form');
            }
            $scheduledDate = normalize_date_input((string)($_POST['scheduled_date'] ?? ''));
            if ($scheduledDate === '') {
                flash('Tanggal schedule tidak valid.', 'err');
                redirect_to('schedule_form');
            }
            if ($printerReady) {
                try {
                    $maintenanceAssetId = maintenance_asset_id_for_schedule_asset($pdo, $assetType, $assetId);
                    $stmt = $pdo->prepare('INSERT INTO maintenance_schedules (asset_type, maintenance_asset_id, pc_id, printer_id, technician_id, scheduled_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$assetType, $maintenanceAssetId, $assetType === 'pc' ? $assetId : null, $assetType === 'printer' ? $assetId : null, $technicianId, $scheduledDate, trim($_POST['notes'])]);
                } catch (Throwable $e) {
                    flash('Schedule gagal disimpan: ' . $e->getMessage() . '. Buka printer-upgrade.php lalu jalankan upgrade sampai pc_id nullable OK.', 'err');
                    redirect_to('schedule_form');
                }
            } else {
                $maintenanceAssetId = maintenance_asset_id_for_schedule_asset($pdo, 'pc', $assetId);
                $stmt = $pdo->prepare('INSERT INTO maintenance_schedules (maintenance_asset_id, pc_id, technician_id, scheduled_date, notes) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$maintenanceAssetId, $assetId, $technicianId, $scheduledDate, trim($_POST['notes'])]);
            }
            $scheduleId = (int)$pdo->lastInsertId();
            foreach ($_POST['jobs'] ?? [] as $jobId) {
                $pdo->prepare('INSERT INTO schedule_jobs (schedule_id, job_id) VALUES (?, ?)')->execute([$scheduleId, (int)$jobId]);
            }
            flash('Schedule maintenance berhasil dibuat.');
            redirect_to('maintenance_do', ['id' => $scheduleId]);
        }
        render_header('Tambah Schedule', $user);
        if (!$printerReady) {
            echo printer_schema_warning();
        }
        $pcs = $pdo->query('SELECT p.pc_id, COALESCE(ma.security_code, p.security_code) security_code, ma.maintenance_asset_code, p.owner_name, p.computer_name FROM pcs p LEFT JOIN maintenance_assets ma ON ma.id=p.maintenance_asset_id ORDER BY p.pc_id')->fetchAll();
        $printers = $printerReady ? $pdo->query('SELECT pr.prn_id, COALESCE(ma.security_code, pr.security_code) security_code, ma.maintenance_asset_code, pr.printer_name, pr.location FROM printers pr LEFT JOIN maintenance_assets ma ON ma.id=pr.maintenance_asset_id ORDER BY pr.prn_id')->fetchAll() : [];
        $techs = $pdo->query("SELECT id, name FROM users WHERE role='technician' AND is_active=1 ORDER BY name")->fetchAll();
        $jobs = $pdo->query('SELECT * FROM maintenance_jobs WHERE is_active=1 ORDER BY title')->fetchAll();
        echo '<section class="panel"><h1>Tambah Schedule Maintenance</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three">';
        if (!$printerReady) {
            echo '<label>PcID<select name="pc_id" required>';
            foreach ($pcs as $pc) {
                $selected = ($_GET['pc_id'] ?? '') === $pc['pc_id'] ? ' selected' : '';
            echo '<option value="' . e($pc['pc_id']) . '"' . $selected . '>' . e(asset_code($pc) . ' - ' . $pc['owner_name']) . '</option>';
            }
            echo '</select></label>';
        } else {
        echo '<label>Aset<select name="asset_id" required>';
        echo '<optgroup label="PC">';
        foreach ($pcs as $pc) {
            $assetValue = 'pc:' . $pc['pc_id'];
            $selected = ($_GET['asset'] ?? $_GET['pc_id'] ?? '') === $pc['pc_id'] || ($_GET['asset'] ?? '') === asset_code($pc) ? ' selected' : '';
            echo '<option value="' . e($assetValue) . '"' . $selected . '>' . e(asset_code($pc) . ' - ' . $pc['owner_name']) . '</option>';
        }
        echo '</optgroup><optgroup label="Printer">';
        foreach ($printers as $printer) {
            $assetValue = 'printer:' . $printer['prn_id'];
            $selected = ($_GET['asset'] ?? '') === printer_asset_code($printer) || ($_GET['asset'] ?? '') === $printer['prn_id'] ? ' selected' : '';
            echo '<option value="' . e($assetValue) . '"' . $selected . '>' . e(printer_asset_code($printer) . ' - ' . $printer['printer_name'] . ($printer['location'] ? ' - ' . $printer['location'] : '')) . '</option>';
        }
        echo '</optgroup></select></label>';
        }
        echo '<label>Teknisi<select name="technician_id" required><option value="">Pilih Teknisi</option>';
        foreach ($techs as $tech) {
            echo '<option value="' . e($tech['id']) . '">' . e($tech['name']) . '</option>';
        }
        echo '</select></label><label>Tanggal<input type="date" name="scheduled_date" required value="' . e(date('Y-m-d')) . '"></label></div><label>Job Desk</label><div class="grid two">';
        foreach ($jobs as $job) {
            echo '<label><input type="checkbox" name="jobs[]" value="' . e($job['id']) . '" checked> ' . e($job['title']) . '</label>';
        }
        echo '</div><label>Catatan<textarea name="notes"></textarea></label><button class="btn primary">Simpan Schedule</button></form></section>';
        render_footer();
        break;

    case 'maintenance_do':
        $user = require_login();
        $id = (int)($_GET['id'] ?? 0);
        $printerReady = printer_schema_ready($pdo);
        $stmt = $printerReady
            ? $pdo->prepare('SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, COALESCE(p.physical_condition, pr.physical_condition) physical_condition FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.id=?')
            : $pdo->prepare("SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, p.computer_name, p.physical_condition FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id WHERE s.id=?");
        $stmt->execute([$id]);
        $schedule = $stmt->fetch();
        if (!$schedule) { http_response_code(404); exit('Schedule tidak ditemukan.'); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($schedule['status'] === 'completed') {
                flash('Job sudah locked. Admin harus Unlock Report sebelum dapat mengubah.', 'err');
                redirect_to('maintenance_do', ['id' => $id]);
            }
            $pdo->prepare('UPDATE schedule_jobs SET is_done=0, done_at=NULL WHERE schedule_id=?')->execute([$id]);
            save_schedule_job_notes($pdo, $id, $_POST['job_notes'] ?? []);
            foreach ($_POST['done'] ?? [] as $jobId) {
                $pdo->prepare('UPDATE schedule_jobs SET is_done=1, done_at=NOW() WHERE schedule_id=? AND job_id=?')->execute([$id, (int)$jobId]);
            }
            $pdo->prepare("UPDATE maintenance_schedules SET status='completed', completed_at=NOW(), locked_at=NOW() WHERE id=?")->execute([$id]);
            if ($printerReady && ($schedule['asset_type'] ?? 'pc') === 'printer') {
                $pdo->prepare('UPDATE printers SET physical_condition=? WHERE prn_id=?')->execute([trim($_POST['physical_condition']), $schedule['printer_id']]);
            } else {
                $pdo->prepare('UPDATE pcs SET physical_condition=? WHERE pc_id=?')->execute([trim($_POST['physical_condition']), $schedule['pc_id']]);
            }
            $stmt = $pdo->prepare('INSERT INTO maintenance_reports (schedule_id, technician_id, physical_condition, additional_notes) VALUES (?, ?, ?, ?)');
            $stmt->execute([$id, $user['id'], trim($_POST['physical_condition']), trim($_POST['additional_notes'])]);
            flash('Laporan maintenance tersimpan.');
            redirect_to('maintenance');
        }
        render_header('Pengerjaan Maintenance', $user);
        $noteSelect = schedule_job_note_supported($pdo) ? 'sj.note' : "'' note";
        $jobs = $pdo->prepare("SELECT j.*, sj.is_done, $noteSelect FROM schedule_jobs sj JOIN maintenance_jobs j ON j.id=sj.job_id WHERE sj.schedule_id=? ORDER BY j.title");
        $jobs->execute([$id]);
        $locked = $schedule['status'] === 'completed';
        echo '<section class="panel"><div class="split"><div><h1>Maintenance ' . e($schedule['asset_id']) . '</h1><p>' . e($schedule['owner_name'] . ' - ' . ($schedule['computer_name'] ?: '')) . '</p><span class="badge">' . e($schedule['status']) . '</span></div>';
        if (can_manage_maintenance($user) && $locked) {
            echo '<form method="post" action="' . route_url('maintenance_unlock', ['id' => $id]) . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="reason" value="Admin unlock dari detail maintenance"><button class="btn danger">Unlock Report</button></form>';
        }
        echo '</div><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><h2>Checklist Job Desk</h2><div class="grid two">';
        foreach ($jobs as $job) {
            echo '<label><input type="checkbox" name="done[]" value="' . e($job['id']) . '"' . ($job['is_done'] ? ' checked' : '') . ($locked ? ' disabled' : '') . '> ' . e($job['title']) . '<input name="job_notes[' . e($job['id']) . ']" value="' . e($job['note'] ?? '') . '" placeholder="Keterangan, boleh kosong" ' . ($locked ? 'readonly' : '') . '></label>';
        }
        echo '</div><label>Catatan Kondisi Fisik<textarea name="physical_condition"' . ($locked ? ' readonly' : '') . '>' . e($schedule['physical_condition']) . '</textarea></label><label>Catatan Tambahan<textarea name="additional_notes"' . ($locked ? ' readonly' : '') . '></textarea></label>' . ($locked ? '<p class="muted">Checklist locked. Hanya admin dapat unlock.</p>' : '<button class="btn primary">Simpan Laporan</button>') . '</form></section>';
        $reportStmt = $pdo->prepare('SELECT * FROM maintenance_reports WHERE schedule_id=? ORDER BY id DESC LIMIT 1');
        $reportStmt->execute([$id]);
        $latestReport = $reportStmt->fetch() ?: [];
        render_photo_block('Foto Sebelum Maintenance', $latestReport['before_photos'] ?? '');
        render_photo_block('Foto Proses Maintenance', $latestReport['process_photos'] ?? '');
        render_photo_block('Foto Sesudah Maintenance', $latestReport['after_photos'] ?? '');
        if (!empty($latestReport['signature_path'])) {
            echo '<section class="panel"><h2>Digital Signature</h2><p>' . e($latestReport['signature_name'] ?? '') . '</p><img style="max-width:320px;border:1px solid #ddd" src="' . e($latestReport['signature_path']) . '"></section>';
        }
        render_footer();
        break;

    case 'jobs':
        $user = require_role(['admin', 'maintenance_admin']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($_POST['action'] === 'add') {
                $pdo->prepare('INSERT INTO maintenance_jobs (title, description, estimated_minutes) VALUES (?, ?, ?)')->execute([trim($_POST['title']), trim($_POST['description']), max(1, (int)($_POST['estimated_minutes'] ?? 5))]);
                flash('Job desk maintenance ditambahkan.');
            }
            if ($_POST['action'] === 'update_estimate') {
                $pdo->prepare('UPDATE maintenance_jobs SET estimated_minutes=? WHERE id=?')->execute([max(1, (int)($_POST['estimated_minutes'] ?? 5)), (int)$_POST['id']]);
                flash('Estimasi waktu job desk diperbarui.');
            }
            if ($_POST['action'] === 'toggle') {
                $pdo->prepare('UPDATE maintenance_jobs SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_POST['id']]);
                flash('Status job desk diperbarui.');
            }
            redirect_to('jobs');
        }
        render_header('Job Desk', $user);
        echo '<section class="grid two"><div class="panel"><h1>Tambah Job Desk</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="add"><label>Nama Pekerjaan<input name="title" required></label><label>Estimasi Menit<input type="number" min="1" name="estimated_minutes" value="5" required></label><label>Deskripsi<textarea name="description"></textarea></label><button class="btn primary">Tambah</button></form></div><div class="panel"><div class="split"><h2>Daftar Job Desk</h2><a class="btn" href="' . route_url('export_excel', ['type' => 'jobs']) . '">Export Excel</a></div><table><tr><th>Pekerjaan</th><th>Estimasi</th><th>Status</th><th>Aksi</th></tr>';
        foreach ($pdo->query('SELECT * FROM maintenance_jobs ORDER BY is_active DESC, title') as $job) {
            echo '<tr><td><strong>' . e($job['title']) . '</strong><br><span class="muted">' . e($job['description']) . '</span></td><td><form method="post" class="actions"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update_estimate"><input type="hidden" name="id" value="' . e($job['id']) . '"><input style="max-width:90px" type="number" min="1" name="estimated_minutes" value="' . e($job['estimated_minutes'] ?? 5) . '"><button class="btn">Simpan</button></form></td><td>' . ($job['is_active'] ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td><td><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . e($job['id']) . '"><button class="btn">Toggle</button></form></td></tr>';
        }
        echo '</table></div></section>';
        render_footer();
        break;

    case 'reports':
        $user = require_login();
        render_header('Report Maintenance', $user);
        $printerReady = printer_schema_ready($pdo);
        if (!$printerReady) {
            echo printer_schema_warning();
        }
        $pcFilter = trim($_GET['pc_id'] ?? '');
        $techFilter = (int)($_GET['technician_id'] ?? 0);
        $sql = $printerReady
            ? "SELECT r.*, s.id schedule_id, COALESCE(s.pc_id,s.printer_id) pc_id, s.asset_type, s.scheduled_date, s.status schedule_status, s.created_at schedule_created_at, s.photo_challenge_code schedule_challenge_code, u.name technician, COALESCE(p.owner_name, pr.printer_name) owner_name FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id LEFT JOIN maintenance_reports r ON r.id=(SELECT r2.id FROM maintenance_reports r2 WHERE r2.schedule_id=s.id ORDER BY r2.id DESC LIMIT 1) WHERE 1=1"
            : "SELECT r.*, s.id schedule_id, s.pc_id pc_id, 'pc' asset_type, s.scheduled_date, s.status schedule_status, s.created_at schedule_created_at, s.photo_challenge_code schedule_challenge_code, u.name technician, p.owner_name FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id LEFT JOIN maintenance_reports r ON r.id=(SELECT r2.id FROM maintenance_reports r2 WHERE r2.schedule_id=s.id ORDER BY r2.id DESC LIMIT 1) WHERE 1=1";
        $params = [];
        if ($pcFilter !== '') { $sql .= ($printerReady && substr($pcFilter, 0, 3) === 'PRN') ? ' AND s.printer_id = ?' : ' AND s.pc_id = ?'; $params[] = $pcFilter; }
        if ($techFilter > 0) { $sql .= ' AND s.technician_id = ?'; $params[] = $techFilter; }
        if ($user['role'] === 'technician') { $sql .= ' AND s.technician_id = ?'; $params[] = $user['id']; }
        $sql .= ' ORDER BY s.scheduled_date DESC, s.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pcs = $pdo->query('SELECT pc_id, owner_name FROM pcs ORDER BY pc_id')->fetchAll();
        $printers = $printerReady ? $pdo->query('SELECT prn_id, printer_name FROM printers ORDER BY prn_id')->fetchAll() : [];
        $techs = $pdo->query("SELECT id, name FROM users WHERE role='technician' ORDER BY name")->fetchAll();
        $exportParams = ['type' => 'reports'];
        if ($pcFilter !== '') { $exportParams['pc_id'] = $pcFilter; }
        if ($techFilter > 0) { $exportParams['technician_id'] = $techFilter; }
        echo '<section class="panel"><div class="split"><h1>Report Pengerjaan Maintenance</h1><a class="btn" href="' . route_url('export_excel', $exportParams) . '">Export Excel</a></div><form method="get"><input type="hidden" name="route" value="reports"><div class="grid three"><label>Per PC / Printer<select name="pc_id"><option value="">Semua Aset</option>';
        foreach ($pcs as $pc) {
            echo '<option value="' . e($pc['pc_id']) . '"' . ($pcFilter === $pc['pc_id'] ? ' selected' : '') . '>' . e($pc['pc_id'] . ' - ' . $pc['owner_name']) . '</option>';
        }
        if ($printerReady) {
            echo '<optgroup label="Printer">';
            foreach ($printers as $printer) {
                echo '<option value="' . e($printer['prn_id']) . '"' . ($pcFilter === $printer['prn_id'] ? ' selected' : '') . '>' . e($printer['prn_id'] . ' - ' . $printer['printer_name']) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select></label><label>Per Teknisi<select name="technician_id"><option value="0">Semua Teknisi</option>';
        foreach ($techs as $tech) {
            echo '<option value="' . e($tech['id']) . '"' . ($techFilter === (int)$tech['id'] ? ' selected' : '') . '>' . e($tech['name']) . '</option>';
        }
        echo '</select></label><label>&nbsp;<button class="btn primary">Filter Report</button></label></div></form></section><section class="panel"><table><tr><th>Tanggal</th><th>PcID</th><th>Owner</th><th>Teknisi</th><th>Summary Maintenance</th><th>Aksi</th></tr>';
        foreach ($stmt as $report) {
            $summary = maintenance_report_summary($pdo, $report);
            echo '<tr><td>' . e($report['created_at'] ?? $report['scheduled_date'] ?? $report['schedule_created_at']) . '</td><td>' . e($report['pc_id']) . '</td><td>' . e($report['owner_name']) . '</td><td>' . e($report['technician'] ?: '-') . '</td><td><table><tr><th>Pekerjaan</th><th>Status</th><th>Keterangan</th></tr>';
            if ($summary['checklist_rows']) {
                foreach ($summary['checklist_rows'] as $job) {
                    echo '<tr><td>' . e($job['title']) . '</td><td>' . e($job['status']) . '</td><td>' . e($job['note'] ?: '-') . '</td></tr>';
                }
            } else {
                echo '<tr><td colspan="3" class="muted">Belum ada jobdesk dipilih untuk schedule ini.</td></tr>';
            }
            echo '<tr><th>Audit Foto</th><td colspan="2"><span class="badge' . e($summary['challenge_audit_class']) . '">' . e($summary['challenge_audit_status']) . '</span> <strong>' . e($summary['photo_evidence_score']) . '/100</strong><br><span class="muted">' . e($summary['challenge_audit_text']) . '</span></td></tr></table></td><td><a class="btn" href="' . route_url('report_print', ['id' => $report['schedule_id']]) . '">Detail</a></td></tr>';
        }
        echo '</table></section>';
        render_footer();
        break;

    case 'maintenance_status_report':
        $user = require_login();
        render_header('Status Maintenance Aset', $user);
        $printerReady = printer_schema_ready($pdo);
        if (!$printerReady) {
            echo printer_schema_warning();
        }
        $assetTypeFilter = trim((string)($_GET['asset_type'] ?? ''));
        $statusFilter = trim((string)($_GET['status_group'] ?? ''));
        $techFilter = (int)($_GET['technician_id'] ?? 0);
        $rows = maintenance_status_rows($pdo, $printerReady, $assetTypeFilter, $statusFilter, $techFilter, $user);
        $counts = ['completed' => 0, 'pending' => 0, 'expired' => 0, 'total' => count($rows)];
        foreach ($rows as $row) {
            $counts[$row['status_group']] = ($counts[$row['status_group']] ?? 0) + 1;
        }
        $techs = $pdo->query("SELECT id, name FROM users WHERE role='technician' ORDER BY name")->fetchAll();
        $exportParams = ['type' => 'maintenance_status'];
        if ($assetTypeFilter !== '') { $exportParams['asset_type'] = $assetTypeFilter; }
        if ($statusFilter !== '') { $exportParams['status_group'] = $statusFilter; }
        if ($techFilter > 0) { $exportParams['technician_id'] = $techFilter; }
        echo '<section class="panel"><div class="split"><div><h1>Status Maintenance PC & Printer</h1><p class="muted">Expired berarti schedule belum completed dan tanggal kerja sudah lewat lebih dari 7 hari.</p></div><div class="actions"><a class="btn" href="' . route_url('reports') . '">Report Detail</a><a class="btn" href="' . route_url('export_excel', $exportParams) . '">Export Excel</a></div></div></section>';
        echo '<section class="panel"><div class="grid four"><div class="stat"><strong>' . e($counts['total']) . '</strong><span>Total</span></div><div class="stat"><strong>' . e($counts['completed']) . '</strong><span>Sudah Maintenance</span></div><div class="stat"><strong>' . e($counts['pending']) . '</strong><span>Pending</span></div><div class="stat"><strong>' . e($counts['expired']) . '</strong><span>Expired &gt; 7 Hari</span></div></div></section>';
        echo '<section class="panel"><form method="get"><input type="hidden" name="route" value="maintenance_status_report"><div class="grid four"><label>Tipe Aset<select name="asset_type"><option value="">Semua</option><option value="pc"' . ($assetTypeFilter === 'pc' ? ' selected' : '') . '>PC</option><option value="printer"' . ($assetTypeFilter === 'printer' ? ' selected' : '') . '>Printer</option></select></label><label>Status<select name="status_group"><option value="">Semua</option><option value="completed"' . ($statusFilter === 'completed' ? ' selected' : '') . '>Sudah Maintenance</option><option value="pending"' . ($statusFilter === 'pending' ? ' selected' : '') . '>Pending</option><option value="expired"' . ($statusFilter === 'expired' ? ' selected' : '') . '>Expired &gt; 7 Hari</option></select></label><label>Teknisi<select name="technician_id"><option value="0">Semua Teknisi</option>';
        foreach ($techs as $tech) {
            echo '<option value="' . e($tech['id']) . '"' . ($techFilter === (int)$tech['id'] ? ' selected' : '') . '>' . e($tech['name']) . '</option>';
        }
        echo '</select></label><label>&nbsp;<button class="btn primary">Filter</button></label></div></form></section>';
        echo '<section class="panel"><table><tr><th>Tipe</th><th>Asset ID</th><th>Owner / Printer</th><th>Teknisi</th><th>Tanggal</th><th>Status</th><th>Audit Foto</th><th>Aksi</th></tr>';
        foreach ($rows as $row) {
            $badgeClass = $row['status_group'] === 'expired' ? ' danger' : ($row['status_group'] === 'completed' ? ' ok' : '');
            echo '<tr><td>' . e($row['asset_type']) . '</td><td><strong>' . e($row['asset_id']) . '</strong></td><td>' . e($row['asset_name']) . '<br><span class="muted">' . e($row['asset_detail'] ?: '-') . '</span></td><td>' . e($row['technician'] ?: '-') . '</td><td>' . e($row['scheduled_date']) . '</td><td><span class="badge' . $badgeClass . '">' . e($row['status_label']) . '</span><br><span class="muted">' . e($row['raw_status']) . '</span></td><td>' . e($row['audit_flags'] ?: 'OK') . '</td><td><a class="btn" href="' . route_url('maintenance_do', ['id' => $row['schedule_id']]) . '">Buka</a> <a class="btn" href="' . route_url('report_print', ['id' => $row['schedule_id']]) . '">Detail</a></td></tr>';
        }
        echo '</table></section>';
        render_footer();
        break;

    case 'labels':
        $user = require_role(['admin']);
        render_header('QR Label', $user);
        $printerReady = printer_schema_ready($pdo);
        if (!$printerReady) {
            echo printer_schema_warning();
        }
        $type = (string)($_GET['type'] ?? 'all');
        $pcs = $type === 'printer' ? [] : $pdo->query('SELECT p.pc_id, COALESCE(ma.security_code, p.security_code) security_code, ma.maintenance_asset_code, p.owner_name, p.computer_name FROM pcs p LEFT JOIN maintenance_assets ma ON ma.id=p.maintenance_asset_id ORDER BY p.pc_id')->fetchAll();
        $printers = ($type === 'pc' || !$printerReady) ? [] : $pdo->query('SELECT pr.prn_id, COALESCE(ma.security_code, pr.security_code) security_code, ma.maintenance_asset_code, pr.printer_name, pr.location, pr.model_printer FROM printers pr LEFT JOIN maintenance_assets ma ON ma.id=pr.maintenance_asset_id ORDER BY pr.prn_id')->fetchAll();
        echo '<section class="panel no-print"><div class="split"><h1>Cetak QR Label</h1><div class="actions"><a class="btn primary" href="' . route_url('pc_form') . '">Tambah PC</a><a class="btn primary" href="' . route_url('printer_form') . '">Tambah Printer</a><a class="btn" href="' . route_url('labels', ['type' => 'pc']) . '">PC</a><a class="btn" href="' . route_url('labels', ['type' => 'printer']) . '">Printer</a><a class="btn" href="' . route_url('labels') . '">Semua</a><button class="btn primary" onclick="window.print()">Cetak</button></div></div></section><section class="label-grid">';
        foreach ($pcs as $pc) {
            $pc['security_code'] = $pc['security_code'] ?: ensure_security_code($pdo, $pc['pc_id']);
            $assetCode = asset_code($pc);
            $url = mobile_asset_url($assetCode);
            echo '<div class="qr-label"><strong style="font-size:20px;margin-top:10px">' . e($assetCode) . '</strong>' . pseudo_qr_hotfix($url) . '<small>PC Maintenance Asset</small><a class="btn no-print js-label-download" data-code="' . e($assetCode) . '" data-type="PC Maintenance Asset" data-name="' . e($pc['owner_name'] ?? '') . '" data-detail="' . e($pc['computer_name'] ?? '') . '" data-payload="' . e($url) . '" download="PcConnect-Label-' . e($assetCode) . '.png" href="' . route_url('qr_png', ['code' => $assetCode]) . '">Download Label PNG</a></div>';
        }
        foreach ($printers as $printer) {
            $assetCode = printer_asset_code($printer);
            $url = mobile_asset_url($assetCode);
            echo '<div class="qr-label"><strong style="font-size:20px;margin-top:10px">' . e($assetCode) . '</strong>' . pseudo_qr_hotfix($url) . '<small>Printer Maintenance Asset</small><small>' . e($printer['printer_name']) . '</small><a class="btn no-print js-label-download" data-code="' . e($assetCode) . '" data-type="Printer Maintenance Asset" data-name="' . e($printer['printer_name'] ?? '') . '" data-detail="' . e(trim((string)($printer['location'] ?? '') . ' ' . (string)($printer['model_printer'] ?? ''))) . '" data-payload="' . e($url) . '" download="PcConnect-Label-' . e($assetCode) . '.png" href="' . route_url('qr_png', ['code' => $assetCode]) . '">Download Label PNG</a></div>';
        }
        echo '</section>';
        echo label_download_script();
        render_footer();
        break;

    case 'qr_png':
        require_role(['admin']);
        $assetCode = strtoupper(trim((string)($_GET['code'] ?? '')));
        [$pcId, $security] = parse_asset_code($assetCode);
        $maintenanceAsset = null;
        $pc = null;
        $printer = null;
        if (db_table_exists($pdo, 'maintenance_assets')) {
            $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE maintenance_asset_code=? LIMIT 1');
            $stmt->execute([$pcId]);
            $maintenanceAsset = $stmt->fetch() ?: null;
            if ($maintenanceAsset && !hash_equals((string)$maintenanceAsset['security_code'], $security)) {
                $maintenanceAsset = null;
            }
            if ($maintenanceAsset && !empty($maintenanceAsset['pc_id'])) {
                $stmt = $pdo->prepare('SELECT p.*, ma.maintenance_asset_code FROM pcs p JOIN maintenance_assets ma ON ma.pc_id=p.pc_id WHERE p.pc_id=? LIMIT 1');
                $stmt->execute([(string)$maintenanceAsset['pc_id']]);
                $pc = $stmt->fetch() ?: null;
            }
            if ($maintenanceAsset && !empty($maintenanceAsset['printer_id']) && printer_schema_ready($pdo)) {
                $stmt = $pdo->prepare('SELECT pr.*, ma.maintenance_asset_code FROM printers pr JOIN maintenance_assets ma ON ma.printer_id=pr.prn_id WHERE pr.prn_id=? LIMIT 1');
                $stmt->execute([(string)$maintenanceAsset['printer_id']]);
                $printer = $stmt->fetch() ?: null;
            }
        }
        if (!$maintenanceAsset) {
            $stmt = $pdo->prepare('SELECT pc_id, security_code, owner_name, computer_name FROM pcs WHERE pc_id=?');
            $stmt->execute([$pcId]);
            $pc = $stmt->fetch();
        }
        if (!$pc && !$printer && printer_schema_ready($pdo)) {
            $stmt = $pdo->prepare('SELECT prn_id, security_code, printer_name, location, model_printer FROM printers WHERE prn_id=?');
            $stmt->execute([$pcId]);
            $printer = $stmt->fetch();
        }
        if (!$maintenanceAsset && (($pc && !hash_equals((string)$pc['security_code'], $security)) || ($printer && !hash_equals((string)$printer['security_code'], $security)))) {
            $pc = null;
            $printer = null;
        }
        if (!$pc && !$printer && !$maintenanceAsset) {
            http_response_code(404);
            exit('QR tidak ditemukan.');
        }
        if ($pc) {
            $assetCode = asset_code($pc);
        } elseif ($printer) {
            $assetCode = printer_asset_code($printer);
        } else {
            $assetCode = maintenance_asset_qr_code($maintenanceAsset);
        }
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=900x900&format=png&data=' . rawurlencode(mobile_asset_url($assetCode));
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

                label_center_text($img, $assetCode, 58, 66, $black, true);
                label_center_text($img, $pc ? 'PC Maintenance Asset' : ($printer ? 'Printer Maintenance Asset' : 'Maintenance Asset'), 28, 175, $muted);

                $qrSize = 610;
                imagecopyresampled($img, $qr, (int)(($width - $qrSize) / 2), 260, 0, 0, $qrSize, $qrSize, imagesx($qr), imagesy($qr));
                imagedestroy($qr);

                label_center_text($img, 'PcConnect Maintenance QR', 30, 925, $black, true);
                label_center_text($img, $pc ? (string)($pc['owner_name'] ?? '') : ($printer ? (string)($printer['printer_name'] ?? '') : (string)($maintenanceAsset['name'] ?? '')), 26, 1005, $muted);
                $line2 = $pc ? (string)($pc['computer_name'] ?? '') : ($printer ? trim((string)($printer['location'] ?? '') . ' ' . (string)($printer['model_printer'] ?? '')) : (string)($maintenanceAsset['location_label'] ?? ''));
                if ($line2 !== '') {
                    label_center_text($img, $line2, 24, 1065, $muted);
                }

                ob_start();
                imagepng($img);
                $labelPng = (string)ob_get_clean();
                imagedestroy($img);
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="PcConnect-Label-' . preg_replace('/[^A-Z0-9_-]/', '', $assetCode) . '.png"');
                header('Content-Length: ' . strlen($labelPng));
                echo $labelPng;
                exit;
            }
        }
        if ($png !== false && strlen($png) > 100) {
            header('Content-Type: image/png');
            header('Content-Disposition: attachment; filename="PcConnect-QR-' . preg_replace('/[^A-Z0-9_-]/', '', $assetCode) . '.png"');
            header('Content-Length: ' . strlen($png));
            echo $png;
            exit;
        }
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Download label gagal karena server tidak bisa mengambil QR dari generator eksternal.\n";
        echo "Pastikan PHP GD aktif dan server QNAP bisa akses keluar ke https://api.qrserver.com.\n";
        echo "QR payload: " . mobile_asset_url($assetCode);
        exit;

    case 'technicians':
        $user = require_role(['admin', 'maintenance_admin']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($_POST['action'] === 'add') {
                $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, "technician")')->execute([trim($_POST['name']), trim($_POST['username']), password_hash((string)$_POST['password'], PASSWORD_DEFAULT)]);
                flash('Teknisi ditambahkan.');
            }
            if ($_POST['action'] === 'update') {
                $techId = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if ($techId <= 0 || $name === '' || $username === '') {
                    flash('Nama dan username teknisi wajib diisi.', 'err');
                    redirect_to('technicians', ['edit_id' => $techId]);
                }
                try {
                    if ($password !== '') {
                        if (strlen($password) < 8) {
                            flash('Password baru minimal 8 karakter.', 'err');
                            redirect_to('technicians', ['edit_id' => $techId]);
                        }
                        $pdo->prepare('UPDATE users SET name=?, username=?, password_hash=? WHERE id=? AND role="technician"')->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $techId]);
                    } else {
                        $pdo->prepare('UPDATE users SET name=?, username=? WHERE id=? AND role="technician"')->execute([$name, $username, $techId]);
                    }
                    flash('Data teknisi diperbarui.');
                } catch (Throwable $e) {
                    flash('Gagal update teknisi. Username mungkin sudah dipakai.', 'err');
                }
            }
            if ($_POST['action'] === 'toggle') {
                $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role = "technician"')->execute([(int)$_POST['id']]);
                flash('Status teknisi diperbarui.');
            }
            if ($_POST['action'] === 'delete') {
                $techId = (int)$_POST['id'];
                try {
                    delete_user_account($pdo, $techId);
                    flash('Teknisi dan seluruh history maintenance terkait berhasil dihapus.');
                } catch (Throwable $e) {
                    flash('Gagal menghapus teknisi: ' . $e->getMessage(), 'err');
                }
            }
            redirect_to('technicians');
        }
        render_header('Teknisi', $user);
        $editId = (int)($_GET['edit_id'] ?? 0);
        if ($editId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id=? AND role="technician" LIMIT 1');
            $stmt->execute([$editId]);
            $editTech = $stmt->fetch();
            if ($editTech) {
                echo '<section class="panel"><div class="split"><h1>Edit Teknisi</h1><a class="btn" href="' . route_url('technicians') . '">Batal Edit</a></div><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . e($editTech['id']) . '">' . employee_portal_name_picker_html('techEdit', 'techEditNameInput', 'techEditUsernameInput') . '<div class="grid three"><label>Nama Teknisi / Pengguna<input id="techEditNameInput" name="name" value="' . e($editTech['name']) . '" required></label><label>Username / NIK<input id="techEditUsernameInput" name="username" value="' . e($editTech['username']) . '" required></label><label>Password Baru<input type="password" name="password" minlength="8" placeholder="Kosongkan jika tidak diubah"></label></div><button class="btn primary">Simpan Perubahan</button></form></section>';
            }
        }
        echo '<section class="grid two"><div class="panel"><h1>Tambah Teknisi</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="add">' . employee_portal_name_picker_html('tech', 'techNameInput', 'techUsernameInput') . '<label>Nama<input id="techNameInput" name="name" required></label><label>Username<input id="techUsernameInput" name="username" required></label><label>Password<input type="password" name="password" required minlength="8"></label><button class="btn primary">Tambah</button></form></div><div class="panel"><div class="split"><h2>Daftar Teknisi</h2><a class="btn" href="' . route_url('export_excel', ['type' => 'technicians']) . '">Export Excel</a></div><table><tr><th>Nama</th><th>Username</th><th>Status</th><th>Aksi</th></tr>';
        foreach ($pdo->query("SELECT * FROM users WHERE role='technician' ORDER BY name") as $tech) {
            echo '<tr><td>' . e($tech['name']) . '</td><td>' . e($tech['username']) . '</td><td>' . ($tech['is_active'] ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td><td><div class="actions"><a class="btn" href="' . route_url('technicians', ['edit_id' => $tech['id']]) . '">Edit</a><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . e($tech['id']) . '"><button class="btn">' . ($tech['is_active'] ? 'Nonaktifkan' : 'Aktifkan') . '</button></form><form method="post" onsubmit="return confirm(\'Hapus teknisi ini beserta semua history maintenance terkait?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($tech['id']) . '"><button class="btn danger">Delete</button></form></div></td></tr>';
        }
        echo '</table></div></section>';
        render_footer();
        break;

    case 'users':
        $user = require_role(['admin']);
        $maintenanceRoleReady = ensure_maintenance_admin_role($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            $targetId = (int)($_POST['id'] ?? 0);
            if ($action === 'add') {
                $name = trim((string)($_POST['name'] ?? ''));
                $username = trim((string)($_POST['username'] ?? ''));
                $role = (string)($_POST['role'] ?? 'technician');
                $password = (string)($_POST['password'] ?? '');
                if ($name === '' || $username === '' || !in_array($role, ['admin', 'maintenance_admin', 'technician'], true) || strlen($password) < 8) {
                    flash('Nama, username, role, dan password minimal 8 karakter wajib diisi.', 'err');
                    redirect_to('users');
                }
                try {
                    $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)')->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
                    flash('User berhasil ditambahkan.');
                } catch (Throwable $e) {
                    flash('Gagal tambah user. Username mungkin sudah dipakai.', 'err');
                }
                redirect_to('users');
            }
            if ($action === 'reset_password') {
                $password = (string)($_POST['password'] ?? '');
                if ($targetId <= 0 || strlen($password) < 8) {
                    flash('Password baru minimal 8 karakter.', 'err');
                    redirect_to('users');
                }
                $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
                if (db_table_exists($pdo, 'mobile_api_tokens')) {
                    $pdo->prepare('DELETE FROM mobile_api_tokens WHERE user_id=?')->execute([$targetId]);
                }
                flash('Password user berhasil diganti. Token mobile lama dihapus.');
                redirect_to('users');
            }
            if ($action === 'toggle') {
                if ($targetId === (int)$user['id']) {
                    flash('User yang sedang login tidak bisa dinonaktifkan sendiri.', 'err');
                    redirect_to('users');
                }
                $target = load_user_for_admin($pdo, $targetId);
                if (!$target) {
                    flash('User tidak ditemukan.', 'err');
                    redirect_to('users');
                }
                if ($target['role'] === 'admin' && (int)$target['is_active'] === 1 && active_admin_count($pdo) <= 1) {
                    flash('Admin aktif terakhir tidak boleh dinonaktifkan.', 'err');
                    redirect_to('users');
                }
                $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id=?')->execute([$targetId]);
                flash('Status user berhasil diperbarui.');
                redirect_to('users');
            }
            if ($action === 'delete') {
                if ($targetId === (int)$user['id']) {
                    flash('User yang sedang login tidak bisa menghapus dirinya sendiri.', 'err');
                    redirect_to('users');
                }
                $target = load_user_for_admin($pdo, $targetId);
                if (!$target) {
                    flash('User tidak ditemukan.', 'err');
                    redirect_to('users');
                }
                if ($target['role'] === 'admin' && active_admin_count($pdo) <= 1) {
                    flash('Admin terakhir tidak boleh dihapus.', 'err');
                    redirect_to('users');
                }
                try {
                    delete_user_account($pdo, $targetId);
                    flash('User berhasil dihapus.');
                } catch (Throwable $e) {
                    flash('Gagal hapus user: ' . $e->getMessage(), 'err');
                }
                redirect_to('users');
            }
        }
        render_header('Users', $user);
        echo '<section class="panel"><div class="split"><div><h1>User & Password</h1><p class="muted">Kelola admin, admin maintenance, dan teknisi. Untuk ganti password admin, gunakan form Reset Password pada baris admin.</p><p class="muted">Role build: admin + maintenance_admin + technician</p></div><a class="btn" href="' . route_url('technicians') . '">Lihat Teknisi</a></div></section>';
        if (!$maintenanceRoleReady) {
            echo '<section class="panel"><div class="flash err">Database belum bisa otomatis menambah role <strong>Admin Maintenance</strong>. Import manual <code>database/upgrade_v3_roles.sql</code> satu kali di phpMyAdmin/QNAP, lalu reload halaman ini.</div></section>';
        }
        echo '<section class="grid two"><div class="panel"><h2>Tambah User</h2><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="add">' . employee_portal_name_picker_html('user', 'userNameInput', 'userUsernameInput') . '<label>Nama<input id="userNameInput" name="name" required></label><label>Username<input id="userUsernameInput" name="username" required></label><label>Role<select name="role"><option value="admin">Admin Full</option><option value="maintenance_admin">Admin Maintenance - Preventive Maintenance saja</option><option value="technician" selected>Teknisi</option></select></label><label>Password<input type="password" name="password" required minlength="8"></label><button class="btn primary">Tambah User</button></form></div>';
        echo '<div class="panel"><h2>Catatan Aman</h2><ul><li>Minimal harus ada 1 admin aktif.</li><li>Reset password akan menghapus token login mobile lama.</li><li>Hapus teknisi akan menghapus schedule/report maintenance milik teknisi tersebut.</li></ul></div></section>';
        echo '<section class="panel"><h2>Daftar User</h2><table><tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th>Reset Password</th><th>Aksi</th></tr>';
        foreach ($pdo->query('SELECT * FROM users ORDER BY role, name') as $row) {
            echo '<tr><td>' . e($row['name']) . '</td><td>' . e($row['username']) . '</td><td><span class="badge">' . e(role_label((string)$row['role'])) . '</span></td><td>' . ((int)$row['is_active'] === 1 ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td>';
            echo '<td><form method="post" class="actions"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="' . e($row['id']) . '"><input style="min-width:180px" type="password" name="password" minlength="8" placeholder="Password baru" required><button class="btn">Reset</button></form></td>';
            echo '<td><div class="actions"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn">' . ((int)$row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan') . '</button></form><form method="post" onsubmit="return confirm(\'Hapus user ini? Untuk teknisi, history maintenance terkait ikut dihapus.\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn danger">Delete</button></form></div></td></tr>';
        }
        echo '</table></section>';
        render_footer();
        break;

    case 'export_excel':
        $user = require_login();
        export_excel($pdo, (string)($_GET['type'] ?? 'pcs'));
        break;

    case 'maintenance_unlock':
        $user = require_role(['admin', 'maintenance_admin']);
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("UPDATE maintenance_schedules SET status='reopened', locked_at=NULL, unlocked_at=NOW(), unlocked_by=?, unlock_reason=? WHERE id=?")->execute([$user['id'], trim($_POST['reason'] ?? 'Admin unlock'), $id]);
        add_timeline($pdo, $id, 'unlocked', 'Admin membuka kembali report.', (int)$user['id']);
        flash('Report berhasil di-unlock.');
        redirect_to('maintenance_do', ['id' => $id]);
        break;

    case 'mobile_dashboard':
        mobile_dashboard($pdo);
        break;

    case 'mobile_schedule':
        mobile_schedule($pdo);
        break;

    case 'mobile_scan':
        mobile_scan($pdo);
        break;

    case 'mobile_job':
        mobile_job($pdo);
        break;

    case 'mobile_history':
        mobile_history($pdo);
        break;

    case 'report_print':
        report_print($pdo);
        break;

    case 'api_ingest':
        $token = $_SERVER['HTTP_X_PCCONNECT_TOKEN'] ?? '';
        if (!hash_equals(config_value('agent_token'), $token)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'invalid token']);
            break;
        }
        $rawInput = file_get_contents('php://input');
        [$payload, $jsonError, $cleanLength] = decode_json_payload($rawInput);
        if (!is_array($payload) || empty($payload['pc_id'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'invalid payload', 'json_error' => $jsonError, 'received_bytes' => strlen($rawInput), 'clean_bytes' => $cleanLength]);
            break;
        }
        $result = ingest_analysis_payload($pdo, $payload);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'pc_id' => $result['pc_id'], 'summary' => $result['summary']]);
        break;

    case 'api_technician_login':
        api_technician_login($pdo);
        break;

    case 'api_technician_me':
        api_technician_me($pdo);
        break;

    case 'api_technician_schedules':
        api_technician_schedules($pdo);
        break;

    case 'api_technician_schedule':
        api_technician_schedule($pdo);
        break;

    case 'api_technician_scan':
        api_technician_scan($pdo);
        break;

    case 'api_technician_submit':
        api_technician_submit($pdo);
        break;

    case 'api_technician_history':
        api_technician_history($pdo);
        break;

    case 'api_technician_logout':
        api_technician_logout($pdo);
        break;

    default:
        http_response_code(404);
        echo 'Route tidak tersedia pada paket hotfix ini.';
}

function api_input(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    return is_array($json) ? array_merge($_POST, $json) : $_POST;
}

function api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        return trim($m[1]);
    }
    return trim((string)($_SERVER['HTTP_X_PCCONNECT_MOBILE_TOKEN'] ?? ''));
}

function api_require_technician(PDO $pdo): array
{
    $token = api_bearer_token();
    if ($token === '') {
        api_json(['ok' => false, 'error' => 'missing token'], 401);
    }
    $stmt = $pdo->prepare("SELECT u.* FROM mobile_api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND u.role='technician' AND u.is_active=1 AND (t.expires_at IS NULL OR t.expires_at > NOW()) LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch();
    if (!$user) {
        api_json(['ok' => false, 'error' => 'invalid token'], 401);
    }
    $pdo->prepare('UPDATE mobile_api_tokens SET last_used_at=NOW() WHERE token_hash=?')->execute([hash('sha256', $token)]);
    return $user;
}

function api_technician_login(PDO $pdo): never
{
    $input = api_input();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? AND role='technician' AND is_active=1 LIMIT 1");
    $stmt->execute([trim((string)($input['username'] ?? ''))]);
    $user = $stmt->fetch();
    if (!$user || !password_verify((string)($input['password'] ?? ''), (string)$user['password_hash'])) {
        api_json(['ok' => false, 'error' => 'login gagal'], 401);
    }
    $token = bin2hex(random_bytes(32));
    $deviceName = trim((string)($input['device_name'] ?? 'Android Technician'));
    $pdo->prepare('INSERT INTO mobile_api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))')->execute([
        $user['id'],
        hash('sha256', $token),
        $deviceName,
    ]);
    api_json(['ok' => true, 'token' => $token, 'expires_in_days' => 30, 'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'username' => $user['username']]]);
}

function api_technician_me(PDO $pdo): never
{
    $user = api_require_technician($pdo);
    api_json(['ok' => true, 'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'username' => $user['username']]]);
}

function api_schedule_row(PDO $pdo, array $row): array
{
    $summary = maintenance_report_summary($pdo, ['schedule_id' => $row['id']]);
    $assetId = $row['asset_id'] ?? ($row['pc_id'] ?: ($row['printer_id'] ?? ''));
    return [
        'id' => (int)$row['id'],
        'asset_type' => $row['asset_type'] ?? 'pc',
        'asset_id' => $assetId,
        'pc_id' => $assetId,
        'owner_name' => $row['owner_name'] ?? '',
        'computer_name' => $row['computer_name'] ?? '',
        'scheduled_date' => $row['scheduled_date'],
        'status' => $row['status'],
        'notes' => $row['notes'] ?? '',
        'arrival_at' => $row['arrival_at'] ?? null,
        'completed_at' => $row['completed_at'] ?? null,
        'checklist_progress' => $summary['job_progress'],
    ];
}

function api_technician_schedules(PDO $pdo): never
{
    $user = api_require_technician($pdo);
    $status = (string)($_GET['status'] ?? 'open');
    $where = "WHERE s.technician_id=?";
    $params = [$user['id']];
    if ($status === 'open') {
        $where .= " AND s.status<>'completed'";
    } elseif ($status === 'completed') {
        $where .= " AND s.status='completed'";
    }
    $stmt = $pdo->prepare("SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id $where ORDER BY s.scheduled_date ASC, s.id ASC");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = api_schedule_row($pdo, $row);
    }
    api_json(['ok' => true, 'schedules' => $rows]);
}

function api_load_technician_schedule(PDO $pdo, int $scheduleId, int $technicianId): array
{
    $stmt = $pdo->prepare('SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, COALESCE(p.physical_condition, pr.physical_condition) physical_condition, p.general_specs, p.benchmark, p.ai_recommendation FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.id=? AND s.technician_id=?');
    $stmt->execute([$scheduleId, $technicianId]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        api_json(['ok' => false, 'error' => 'schedule tidak ditemukan'], 404);
    }
    return $schedule;
}

function api_schedule_jobs(PDO $pdo, int $scheduleId): array
{
    $noteSelect = schedule_job_note_supported($pdo) ? 'sj.note' : "'' note";
    $stmt = $pdo->prepare("SELECT j.id, j.title, j.description, sj.is_done, sj.done_at, $noteSelect FROM schedule_jobs sj JOIN maintenance_jobs j ON j.id=sj.job_id WHERE sj.schedule_id=? ORDER BY j.title");
    $stmt->execute([$scheduleId]);
    $jobs = [];
    foreach ($stmt as $job) {
        $jobs[] = [
            'id' => (int)$job['id'],
            'title' => $job['title'],
            'description' => $job['description'] ?? '',
            'is_done' => (bool)$job['is_done'],
            'done_at' => $job['done_at'],
            'note' => $job['note'] ?? '',
        ];
    }
    return $jobs;
}

function api_technician_schedule(PDO $pdo): never
{
    $user = api_require_technician($pdo);
    $schedule = api_load_technician_schedule($pdo, (int)($_GET['id'] ?? 0), (int)$user['id']);
    api_json(['ok' => true, 'schedule' => api_schedule_row($pdo, $schedule), 'jobs' => api_schedule_jobs($pdo, (int)$schedule['id']), 'smart' => [
        'physical_condition' => $schedule['physical_condition'] ?? '',
        'ai_recommendation' => $schedule['ai_recommendation'] ?? '',
    ]]);
}

function api_technician_scan(PDO $pdo): never
{
    $user = api_require_technician($pdo);
    $input = api_input();
    $schedule = api_load_technician_schedule($pdo, (int)($input['schedule_id'] ?? 0), (int)$user['id']);
    $phase = in_array((string)($input['phase'] ?? 'start'), ['start', 'end'], true) ? (string)$input['phase'] : 'start';
    [$assetType, $assetId] = find_asset_by_code($pdo, strtoupper(trim((string)($input['code'] ?? ''))));
    if ($assetId === '' || $assetType !== ($schedule['asset_type'] ?? 'pc') || $assetId !== ($schedule['asset_id'] ?? $schedule['pc_id'])) {
        api_json(['ok' => false, 'error' => 'QR tidak valid untuk schedule ini'], 422);
    }
    if ($phase === 'end') {
        $reportStmt = $pdo->prepare('SELECT id FROM maintenance_reports WHERE schedule_id=? ORDER BY id DESC LIMIT 1');
        $reportStmt->execute([$schedule['id']]);
        $reportId = (int)($reportStmt->fetchColumn() ?: 0);
        if ($reportId <= 0) {
            api_json(['ok' => false, 'error' => 'submit checklist dan foto terlebih dahulu'], 422);
        }
        $token = maintenance_scan_token($schedule, 'end');
        add_timeline($pdo, (int)$schedule['id'], 'qr_end_scan', 'API QR selesai tervalidasi. Token: ' . $token, (int)$user['id']);
        $pdo->prepare("UPDATE maintenance_reports SET locked_at=NOW() WHERE id=?")->execute([$reportId]);
        $pdo->prepare("UPDATE maintenance_schedules SET status='completed', completed_at=NOW(), locked_at=NOW() WHERE id=?")->execute([$schedule['id']]);
        api_json(['ok' => true, 'phase' => 'end', 'scan_token' => $token, 'status' => 'completed']);
    }
    $lat = ($input['lat'] ?? '') !== '' ? (float)$input['lat'] : null;
    $lng = ($input['lng'] ?? '') !== '' ? (float)$input['lng'] : null;
    $pdo->prepare("UPDATE maintenance_schedules SET status='in_progress', arrival_at=COALESCE(arrival_at,NOW()), arrival_ip=?, arrival_user_agent=?, arrival_lat=?, arrival_lng=?, arrival_browser=? WHERE id=?")->execute([
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $lat,
        $lng,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
        $schedule['id'],
    ]);
    $token = maintenance_scan_token($schedule, 'start');
    add_timeline($pdo, (int)$schedule['id'], 'qr_start_scan', 'API QR mulai tervalidasi. Token: ' . $token, (int)$user['id']);
    api_json(['ok' => true, 'phase' => 'start', 'scan_token' => $token, 'status' => 'in_progress']);
}

function api_technician_submit(PDO $pdo): never
{
    $user = api_require_technician($pdo);
    $input = api_input();
    $schedule = api_load_technician_schedule($pdo, (int)($input['schedule_id'] ?? 0), (int)$user['id']);
    if ($schedule['status'] === 'completed') {
        api_json(['ok' => false, 'error' => 'report sudah locked'], 409);
    }
    if (empty($schedule['arrival_at'])) {
        api_json(['ok' => false, 'error' => 'scan QR mulai terlebih dahulu'], 422);
    }
    $done = $input['done'] ?? [];
    if (is_string($done)) {
        $decoded = json_decode($done, true);
        $done = is_array($decoded) ? $decoded : [];
    }
    $jobNotes = $input['job_notes'] ?? [];
    if (is_string($jobNotes)) {
        $decoded = json_decode($jobNotes, true);
        $jobNotes = is_array($decoded) ? $decoded : [];
    }
    $pdo->prepare('UPDATE schedule_jobs SET is_done=0, done_at=NULL WHERE schedule_id=?')->execute([$schedule['id']]);
    save_schedule_job_notes($pdo, (int)$schedule['id'], $jobNotes);
    foreach ($done as $jobId) {
        $pdo->prepare('UPDATE schedule_jobs SET is_done=1, done_at=NOW() WHERE schedule_id=? AND job_id=?')->execute([$schedule['id'], (int)$jobId]);
    }
    $uploadMeta = [];
    $process = save_uploaded_photos('process_photos', (int)$schedule['id'], $uploadMeta);
    $before = save_uploaded_photos('before_photos', (int)$schedule['id'], $uploadMeta);
    $after = save_uploaded_photos('after_photos', (int)$schedule['id'], $uploadMeta);
    if (!$before || !$after) {
        api_json(['ok' => false, 'error' => 'foto before dan after wajib dikirim'], 422);
    }
    $photoAuditMeta = build_photo_audit_meta($pdo, (int)$schedule['id'], $uploadMeta, (string)($input['photo_evidence_meta'] ?? ''));
    if (!empty($input['duration_override_approved'])) {
        $photoAuditMeta['duration_override_approved'] = true;
    }
    $liveCameraError = photo_evidence_live_camera_error($photoAuditMeta);
    if ($liveCameraError !== null) {
        api_json(['ok' => false, 'error' => $liveCameraError], 422);
    }
    $durationError = photo_evidence_duration_error($photoAuditMeta);
    if ($durationError !== null) {
        api_json(['ok' => false, 'error' => $durationError, 'requires_duration_override' => true, 'minimum_duration_minutes' => $photoAuditMeta['minimum_duration_minutes'] ?? 5], 422);
    }
    $photoLocationError = photo_evidence_location_error($photoAuditMeta);
    if ($photoLocationError !== null) {
        api_json(['ok' => false, 'error' => $photoLocationError], 422);
    }
    $hash = report_hash([
        'schedule_id' => $schedule['id'],
        'pc_id' => $schedule['asset_id'] ?? $schedule['pc_id'],
        'technician_id' => $user['id'],
        'photo_audit_meta' => $photoAuditMeta,
        'done' => $done,
        'condition_rating' => $input['condition_rating'] ?? '',
        'notes' => $input['additional_notes'] ?? '',
        'completed_at' => date('c'),
    ]);
    $pdo->prepare('DELETE FROM maintenance_reports WHERE schedule_id=? AND locked_at IS NULL')->execute([$schedule['id']]);
    $stmt = $pdo->prepare('INSERT INTO maintenance_reports (schedule_id, technician_id, condition_rating, physical_condition, additional_notes, before_photos, process_photos, after_photos, photo_challenge_code, photo_audit_meta, signature_name, signature_path, report_hash, locked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL)');
    $stmt->execute([
        $schedule['id'],
        $user['id'],
        $input['condition_rating'] ?? null,
        trim((string)($input['physical_condition'] ?? '')),
        trim((string)($input['additional_notes'] ?? '')),
        json_encode($before, JSON_UNESCAPED_SLASHES),
        json_encode($process, JSON_UNESCAPED_SLASHES),
        json_encode($after, JSON_UNESCAPED_SLASHES),
        json_encode($photoAuditMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        trim((string)($input['signature_name'] ?? '')),
        null,
        $hash,
    ]);
    $pdo->prepare("UPDATE maintenance_schedules SET status='in_progress' WHERE id=?")->execute([$schedule['id']]);
    if (($schedule['asset_type'] ?? 'pc') === 'printer') {
        $pdo->prepare('UPDATE printers SET physical_condition=? WHERE prn_id=?')->execute([trim((string)($input['physical_condition'] ?? '')), $schedule['printer_id']]);
    } else {
        $pdo->prepare('UPDATE pcs SET physical_condition=? WHERE pc_id=?')->execute([trim((string)($input['physical_condition'] ?? '')), $schedule['pc_id']]);
    }
    add_timeline($pdo, (int)$schedule['id'], 'work_saved', 'API checklist dan foto tersimpan. Menunggu scan QR selesai. Hash draft: ' . $hash, (int)$user['id']);
    api_json(['ok' => true, 'schedule_id' => (int)$schedule['id'], 'report_hash' => $hash, 'next_action' => 'scan_end']);
}

function api_technician_history(PDO $pdo): never
{
    $user = api_require_technician($pdo);
    $stmt = $pdo->prepare("SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.technician_id=? ORDER BY s.scheduled_date DESC, s.id DESC LIMIT 100");
    $stmt->execute([$user['id']]);
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = api_schedule_row($pdo, $row);
    }
    api_json(['ok' => true, 'history' => $rows]);
}

function api_technician_logout(PDO $pdo): never
{
    $token = api_bearer_token();
    if ($token !== '') {
        $pdo->prepare('DELETE FROM mobile_api_tokens WHERE token_hash=?')->execute([hash('sha256', $token)]);
    }
    api_json(['ok' => true]);
}

function asset_nav_html(): string
{
    return '<section class="panel no-print"><div class="actions"><a class="btn" href="' . route_url('asset_dashboard') . '">Overview</a><a class="btn" href="' . route_url('asset_companies') . '">Company</a><a class="btn" href="' . route_url('asset_items') . '">Asset Items</a><a class="btn" href="' . route_url('maintenance_assets') . '">Maintenance Assets / QR</a><a class="btn" href="' . route_url('asset_repairs') . '">Repair History</a><a class="btn" href="' . route_url('asset_movements') . '">Mutasi / Tukar Pasang</a><a class="btn" href="' . route_url('asset_bundles') . '">Bundle Lama</a></div></section>';
}

function company_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Tanpa company -</option>';
    foreach ($pdo->query('SELECT id, company_code, company_name FROM asset_companies WHERE is_active=1 ORDER BY company_name') as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['company_code'] . ' - ' . $row['company_name']) . '</option>';
    }
    return $html;
}

function asset_item_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">Pilih Asset Item</option>';
    foreach ($pdo->query('SELECT id, asset_code, asset_name, asset_type, asset_mode FROM asset_items ORDER BY asset_code') as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $mode = ($row['asset_mode'] ?? 'standalone') === 'group' ? 'Gabungan' : 'Mandiri';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['asset_code'] . ' - ' . $row['asset_name'] . ' (' . $row['asset_type'] . ' / ' . $mode . ')') . '</option>';
    }
    return $html;
}

function asset_group_item_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Pilih Asset Gabungan -</option>';
    foreach ($pdo->query("SELECT id, asset_code, asset_name, asset_type FROM asset_items WHERE asset_mode='group' ORDER BY asset_code") as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['asset_code'] . ' - ' . $row['asset_name'] . ' (' . $row['asset_type'] . ')') . '</option>';
    }
    return $html;
}

function asset_child_item_options(PDO $pdo, int $parentId = 0): string
{
    $html = '<option value="">Pilih Asset Item Anggota</option>';
    $stmt = $pdo->prepare('SELECT id, asset_code, asset_name, asset_type, asset_mode FROM asset_items WHERE id <> ? ORDER BY asset_code');
    $stmt->execute([$parentId]);
    foreach ($stmt as $row) {
        $mode = ($row['asset_mode'] ?? 'standalone') === 'group' ? 'Gabungan' : 'Mandiri';
        $html .= '<option value="' . e($row['id']) . '">' . e($row['asset_code'] . ' - ' . $row['asset_name'] . ' (' . $row['asset_type'] . ' / ' . $mode . ')') . '</option>';
    }
    return $html;
}

function maintenance_asset_rows(PDO $pdo): array
{
    return $pdo->query('SELECT ma.*, c.company_name, COUNT(mi.id) item_count FROM maintenance_assets ma LEFT JOIN asset_companies c ON c.id=ma.company_id LEFT JOIN maintenance_asset_items mi ON mi.maintenance_asset_id=ma.id AND mi.detached_at IS NULL GROUP BY ma.id ORDER BY ma.updated_at DESC, ma.maintenance_asset_code')->fetchAll();
}

function maintenance_assets_table(PDO $pdo, array $rows): string
{
    $html = '<table><tr><th>Maintenance Asset ID</th><th>Tipe</th><th>Nama</th><th>Company</th><th>Pengguna / Lokasi</th><th>Asset Item</th><th>QR</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $qr = maintenance_asset_qr_code($row);
        $links = [];
        if (!empty($row['pc_id'])) { $links[] = 'PC: ' . $row['pc_id']; }
        if (!empty($row['printer_id'])) { $links[] = 'Printer: ' . $row['printer_id']; }
        $html .= '<tr><td><strong>' . e($row['maintenance_asset_code']) . '</strong><br><span class="muted">' . e(implode(' / ', $links) ?: 'General asset') . '</span></td><td>' . e($row['maintenance_type']) . '</td><td>' . e($row['name']) . '</td><td>' . e($row['company_name'] ?: '-') . '</td><td>' . e($row['owner_name'] ?: '-') . '<br><span class="muted">' . e($row['location_label'] ?: '-') . '</span></td><td>' . e($row['item_count']) . ' item</td><td><span class="badge">' . e($qr) . '</span></td><td><div class="actions"><a class="btn" href="' . route_url('maintenance_asset_form', ['id' => $row['id']]) . '">Buka</a><a class="btn" download="PcConnect-Label-' . e($qr) . '.png" href="' . route_url('qr_png', ['code' => $qr]) . '">Label</a></div></td></tr>';
    }
    return $html . '</table>';
}

function maintenance_asset_defaults(): array
{
    return ['id' => 0, 'maintenance_asset_code' => '', 'security_code' => pc_security_code_random(), 'maintenance_type' => 'equipment', 'name' => '', 'company_id' => '', 'employee_nik' => '', 'owner_name' => '', 'location_label' => '', 'latitude' => '', 'longitude' => '', 'location_radius_m' => 5, 'status' => 'active', 'notes' => ''];
}

function maintenance_asset_post_data(PDO $pdo, int $id = 0): array
{
    $latitude = normalize_decimal_input((string)($_POST['latitude'] ?? ''));
    $longitude = normalize_decimal_input((string)($_POST['longitude'] ?? ''));
    return [
        'maintenance_asset_code' => unique_maintenance_asset_code($pdo, strtoupper(trim((string)($_POST['maintenance_asset_code'] ?? ''))), $id > 0 ? $id : null),
        'security_code' => strtoupper(trim((string)($_POST['security_code'] ?? ''))),
        'maintenance_type' => trim((string)($_POST['maintenance_type'] ?? 'equipment')),
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
    $qr = $editing && !empty($asset['maintenance_asset_code']) ? maintenance_asset_qr_code($asset) : '';
    $typeOptions = '';
    foreach (['pc_set' => 'PC Set / Bundle Maintenance', 'printer' => 'Printer', 'vehicle' => 'Kendaraan', 'electronics' => 'Elektronik', 'office_equipment' => 'Peralatan Kantor', 'furniture' => 'Furniture', 'equipment' => 'General Equipment'] as $value => $label) {
        $typeOptions .= '<option value="' . e($value) . '"' . (((string)($asset['maintenance_type'] ?? '') === $value) ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $statusOptions = '';
    foreach (['active' => 'Active', 'spare' => 'Spare', 'inactive' => 'Inactive'] as $value => $label) {
        $statusOptions .= '<option value="' . e($value) . '"' . (($asset['status'] ?? '') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html = '<section class="panel"><div class="split"><div><h1>' . ($editing ? 'Edit Maintenance Asset' : 'Tambah Maintenance Asset') . '</h1><p class="muted">Maintenance Asset ID adalah identitas untuk schedule dan QR. Asset Item fisik dipasang di bagian anggota di bawah form.</p>' . ($qr !== '' ? '<p><span class="badge">QR ' . e($qr) . '</span></p>' : '') . '</div><div class="actions"><a class="btn" href="' . route_url('maintenance_assets') . '">Kembali</a>' . ($qr !== '' ? '<a class="btn" download="PcConnect-Label-' . e($qr) . '.png" href="' . route_url('qr_png', ['code' => $qr]) . '">Download Label PNG</a>' : '') . '</div></div></section>';
    $html .= '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three"><label>Maintenance Asset ID<input name="maintenance_asset_code" value="' . e($asset['maintenance_asset_code']) . '" placeholder="Contoh: MNT-PC000001" required></label><label>Secret QR<input name="security_code" value="' . e($asset['security_code']) . '" required></label><label>Tipe Maintenance<select name="maintenance_type">' . $typeOptions . '</select></label></div><div class="grid three"><label>Nama Maintenance Asset<input name="name" value="' . e($asset['name']) . '" required></label><label>Company<select name="company_id">' . company_options($pdo, (int)($asset['company_id'] ?? 0)) . '</select></label><label>Status<select name="status">' . $statusOptions . '</select></label></div><div class="grid two">' . employee_picker_html($asset) . '<label>Nama / Titik Lokasi<input name="location_label" list="savedLocationGroups" value="' . e($asset['location_label'] ?? '') . '"></label></div>' . saved_location_datalist_html() . '<div class="grid three"><label>Latitude<input name="latitude" value="' . e($asset['latitude'] ?? '') . '"></label><label>Longitude<input name="longitude" value="' . e($asset['longitude'] ?? '') . '"></label><label>Radius Meter<input type="number" min="1" name="location_radius_m" value="' . e($asset['location_radius_m'] ?? 5) . '"></label></div><label>Catatan<textarea name="notes">' . e($asset['notes'] ?? '') . '</textarea></label><div class="actions"><button class="btn primary">Simpan Maintenance Asset</button></div></form></section>';
    return $html;
}

function maintenance_asset_item_manager_html(PDO $pdo, int $maintenanceAssetId): string
{
    $stmt = $pdo->prepare('SELECT mi.*, ai.asset_code, ai.asset_name, ai.asset_type, ai.asset_mode FROM maintenance_asset_items mi JOIN asset_items ai ON ai.id=mi.asset_item_id WHERE mi.maintenance_asset_id=? ORDER BY mi.detached_at IS NULL DESC, mi.attached_at DESC, mi.id DESC');
    $stmt->execute([$maintenanceAssetId]);
    $html = '<section class="panel"><h2>Asset Item Fisik Dalam Maintenance Asset</h2><p class="muted">Contoh: Maintenance Asset PC Set bisa berisi CPU, monitor, UPS, dan item pendukung lain. Jika perangkat ditukar, lepas item lama lalu pasang item baru.</p><form method="post" action="' . route_url('maintenance_asset_item_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="attach"><input type="hidden" name="maintenance_asset_id" value="' . e($maintenanceAssetId) . '"><div class="grid three"><label>Asset Item<select name="asset_item_id" required>' . asset_item_options($pdo) . '</select></label><label>Role<input name="role_name" placeholder="CPU / Monitor / Printer / Unit"></label><label>&nbsp;<button class="btn primary">Pasang Item</button></label></div></form><table><tr><th>Asset Item</th><th>Role</th><th>Pasang</th><th>Lepas</th><th>Aksi</th></tr>';
    foreach ($stmt as $row) {
        $html .= '<tr><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . ' <span class="muted">(' . e($row['asset_type']) . ' / ' . e($row['asset_mode']) . ')</span></td><td>' . e($row['role_name'] ?: '-') . '</td><td>' . e($row['attached_at']) . '</td><td>' . e($row['detached_at'] ?: '-') . '</td><td>';
        if (empty($row['detached_at'])) {
            $html .= '<form method="post" action="' . route_url('maintenance_asset_item_action') . '" onsubmit="return confirm(\'Lepas asset item ini dari maintenance asset?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="detach"><input type="hidden" name="maintenance_asset_id" value="' . e($maintenanceAssetId) . '"><input type="hidden" name="member_id" value="' . e($row['id']) . '"><button class="btn danger">Pisahkan</button></form>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</table></section>';
}

function asset_bundle_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Tidak terkait bundle -</option>';
    foreach ($pdo->query('SELECT id, maintenance_asset_code, bundle_name FROM asset_bundles ORDER BY maintenance_asset_code') as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['maintenance_asset_code'] . ' - ' . $row['bundle_name']) . '</option>';
    }
    return $html;
}

function pc_asset_link_summary(PDO $pdo, array $pc): string
{
    $lines = [];
    $itemId = (int)($pc['asset_item_id'] ?? 0);
    if ($itemId > 0) {
        $stmt = $pdo->prepare('SELECT asset_code, asset_name, asset_type, asset_mode FROM asset_items WHERE id=?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item) {
            $mode = ($item['asset_mode'] ?? 'standalone') === 'group' ? 'Gabungan' : 'Mandiri';
            $lines[] = 'Asset Item: ' . $item['asset_code'] . ' - ' . $item['asset_name'] . ' (' . $item['asset_type'] . ' / ' . $mode . ')';
        }
    }
    return $lines ? implode("\n", $lines) : '-';
}

function pc_asset_link_detail_html(PDO $pdo, array $pc): string
{
    $itemId = (int)($pc['asset_item_id'] ?? 0);
    $maintenanceAssetId = (int)($pc['maintenance_asset_id'] ?? 0);
    $maintenanceAssetCode = (string)($pc['maintenance_asset_code'] ?? '');
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
    $html = '<section class="panel"><div class="split"><div><h2>Asset Management Link</h2><p class="muted">Relasi PC ke Asset Item dan Maintenance Asset ID.</p></div><a class="btn" href="' . route_url('pc_form', ['pc_id' => $pc['pc_id'] ?? '']) . '">Edit Relasi</a></div>';
    $html .= '<div class="grid two"><div class="mini-card"><strong>Maintenance Asset ID</strong><br><span class="badge">' . e($maintenanceAssetCode !== '' ? $maintenanceAssetCode : '-') . '</span></div>';
    if ($item) {
        $mode = ($item['asset_mode'] ?? 'standalone') === 'group' ? 'Asset Gabungan' : 'Asset Mandiri';
        $html .= '<div class="mini-card"><strong>Asset Item Terhubung</strong><table><tr><th>Asset Code</th><td>' . e($item['asset_code']) . '</td></tr><tr><th>Nama</th><td>' . e($item['asset_name']) . '</td></tr><tr><th>Tipe / Mode</th><td>' . e($item['asset_type'] . ' / ' . $mode) . '</td></tr><tr><th>Company</th><td>' . e($item['company_name'] ?: '-') . '</td></tr><tr><th>Serial</th><td>' . e($item['serial_number'] ?: '-') . '</td></tr></table><p><a class="btn" href="' . route_url('asset_item_form', ['id' => $item['id']]) . '">Buka Asset Item</a></p></div>';
    } else {
        $html .= '<div class="mini-card"><strong>Asset Item Terhubung</strong><p class="muted">Belum ada asset item yang dihubungkan ke PC ini.</p></div>';
    }
    return $html . '</div></section>';
}

function asset_item_maintenance_asset_labels(PDO $pdo, int $assetItemId): array
{
    if ($assetItemId <= 0 || !db_table_exists($pdo, 'maintenance_assets') || !db_table_exists($pdo, 'maintenance_asset_items')) {
        return [];
    }
    $labels = [];

    $stmt = $pdo->prepare('SELECT ma.maintenance_asset_code, ma.name FROM maintenance_asset_items mai JOIN maintenance_assets ma ON ma.id=mai.maintenance_asset_id WHERE mai.asset_item_id=? AND mai.detached_at IS NULL ORDER BY ma.maintenance_asset_code');
    $stmt->execute([$assetItemId]);
    foreach ($stmt->fetchAll() as $row) {
        $labels[$row['maintenance_asset_code']] = $row['maintenance_asset_code'] . ' - ' . $row['name'];
    }

    if (db_table_exists($pdo, 'asset_item_members')) {
        $stmt = $pdo->prepare('SELECT ma.maintenance_asset_code, ma.name, parent.asset_code parent_code FROM asset_item_members aim JOIN asset_items parent ON parent.id=aim.parent_asset_item_id JOIN maintenance_asset_items mai ON mai.asset_item_id=parent.id AND mai.detached_at IS NULL JOIN maintenance_assets ma ON ma.id=mai.maintenance_asset_id WHERE aim.child_asset_item_id=? AND aim.detached_at IS NULL ORDER BY ma.maintenance_asset_code');
        $stmt->execute([$assetItemId]);
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['maintenance_asset_code'] . '|parent|' . $row['parent_code'];
            $labels[$key] = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (via gabungan ' . $row['parent_code'] . ')';
        }
    }

    if (db_column_exists($pdo, 'pcs', 'asset_item_id') && db_column_exists($pdo, 'pcs', 'maintenance_asset_id')) {
        $stmt = $pdo->prepare('SELECT ma.maintenance_asset_code, ma.name, p.pc_id FROM pcs p JOIN maintenance_assets ma ON ma.id=p.maintenance_asset_id WHERE p.asset_item_id=? ORDER BY ma.maintenance_asset_code');
        $stmt->execute([$assetItemId]);
        foreach ($stmt->fetchAll() as $row) {
            $labels['pc|' . $row['maintenance_asset_code']] = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (PC ' . $row['pc_id'] . ')';
        }
    }

    if (db_table_exists($pdo, 'printers') && db_column_exists($pdo, 'printers', 'asset_item_id') && db_column_exists($pdo, 'printers', 'maintenance_asset_id')) {
        $stmt = $pdo->prepare('SELECT ma.maintenance_asset_code, ma.name, pr.prn_id FROM printers pr JOIN maintenance_assets ma ON ma.id=pr.maintenance_asset_id WHERE pr.asset_item_id=? ORDER BY ma.maintenance_asset_code');
        $stmt->execute([$assetItemId]);
        foreach ($stmt->fetchAll() as $row) {
            $labels['printer|' . $row['maintenance_asset_code']] = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (Printer ' . $row['prn_id'] . ')';
        }
    }

    return array_values($labels);
}

function asset_item_maintenance_link_panel_html(PDO $pdo, int $assetItemId): string
{
    $labels = asset_item_maintenance_asset_labels($pdo, $assetItemId);
    $html = '<section class="panel"><h2>Maintenance Asset Terelasi</h2>';
    if (!$labels) {
        return $html . '<p class="muted">Belum ada Maintenance Asset ID aktif yang terhubung dengan asset item ini.</p></section>';
    }
    $html .= '<table><tr><th>Maintenance Asset ID</th></tr>';
    foreach ($labels as $label) {
        $html .= '<tr><td>' . e($label) . '</td></tr>';
    }
    return $html . '</table></section>';
}

function asset_company_form_html(?array $company): string
{
    $company = $company ?: ['id' => 0, 'company_code' => '', 'company_name' => '', 'legal_name' => '', 'address' => '', 'is_active' => 1];
    return '<section class="panel"><h1>' . ((int)$company['id'] > 0 ? 'Edit Company' : 'Tambah Company') . '</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . e($company['id']) . '"><div class="grid two"><label>Company Code<input name="company_code" value="' . e($company['company_code']) . '" required></label><label>Company Name<input name="company_name" value="' . e($company['company_name']) . '" required></label></div><label>Legal Name<input name="legal_name" value="' . e($company['legal_name']) . '"></label><label>Address<textarea name="address">' . e($company['address']) . '</textarea></label><label><input style="width:auto" type="checkbox" name="is_active" value="1" ' . ((int)$company['is_active'] ? 'checked' : '') . '> Aktif</label><button class="btn primary">Simpan Company</button></form></section>';
}

function asset_item_defaults(): array
{
    return [
        'id' => 0,
        'asset_code' => '',
        'company_id' => 0,
        'asset_mode' => 'standalone',
        'asset_type' => 'CPU',
        'asset_name' => '',
        'brand' => '',
        'model' => '',
        'serial_number' => '',
        'manufacture_year' => 0,
        'warranty_until' => '',
        'installed_at' => '',
        'purchase_value' => 0,
        'current_value' => 0,
        'status' => 'active',
        'location_label' => '',
        'notes' => '',
    ];
}

function asset_item_post_data(): array
{
    return [
        'asset_code' => strtoupper(trim((string)($_POST['asset_code'] ?? ''))),
        'company_id' => (int)($_POST['company_id'] ?? 0) ?: null,
        'asset_mode' => in_array(($_POST['asset_mode'] ?? 'standalone'), ['standalone', 'group'], true) ? (string)$_POST['asset_mode'] : 'standalone',
        'asset_type' => trim((string)($_POST['asset_type'] ?? '')),
        'asset_name' => trim((string)($_POST['asset_name'] ?? '')),
        'brand' => trim((string)($_POST['brand'] ?? '')),
        'model' => trim((string)($_POST['model'] ?? '')),
        'serial_number' => trim((string)($_POST['serial_number'] ?? '')),
        'manufacture_year' => (int)($_POST['manufacture_year'] ?? 0) ?: null,
        'warranty_until' => normalize_date_input((string)($_POST['warranty_until'] ?? '')) ?: null,
        'installed_at' => normalize_date_input((string)($_POST['installed_at'] ?? '')) ?: null,
        'purchase_value' => (float)str_replace(',', '.', (string)($_POST['purchase_value'] ?? 0)),
        'current_value' => (float)str_replace(',', '.', (string)($_POST['current_value'] ?? 0)),
        'status' => trim((string)($_POST['status'] ?? 'active')),
        'location_label' => trim((string)($_POST['location_label'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
}

function asset_item_form_html(PDO $pdo, array $item, bool $editing): string
{
    $item = array_merge(asset_item_defaults(), $item);
    $types = ['PC Set', 'CPU', 'Monitor', 'Notebook', 'Printer', 'UPS', 'Scanner', 'Network', 'Other'];
    $typeOptions = '';
    foreach ($types as $type) {
        $selected = ((string)($item['asset_type'] ?? '') === $type) ? ' selected' : '';
        $typeOptions .= '<option value="' . e($type) . '"' . $selected . '>' . e($type) . '</option>';
    }
    $mode = (string)($item['asset_mode'] ?? 'standalone');
    $modeOptions = '<option value="standalone"' . ($mode === 'standalone' ? ' selected' : '') . '>Asset Mandiri</option><option value="group"' . ($mode === 'group' ? ' selected' : '') . '>Asset Gabungan</option>';
    return '<section class="panel"><h1>' . ($editing ? 'Edit Asset Item' : 'Tambah Asset Item') . '</h1><p class="muted">Semua aset tetap memakai Asset Item ID. Pilih <strong>Asset Gabungan</strong> untuk no aset pemeliharaan seperti PC set yang berisi CPU + monitor.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid four"><label>Asset Code<input name="asset_code" value="' . e($item['asset_code']) . '" required></label><label>Mode Aset<select name="asset_mode">' . $modeOptions . '</select></label><label>Company<select name="company_id">' . company_options($pdo, (int)($item['company_id'] ?? 0)) . '</select></label><label>Asset Type<select name="asset_type">' . $typeOptions . '</select></label></div><div class="grid three"><label>Nama Aset<input name="asset_name" value="' . e($item['asset_name']) . '" required></label><label>Merek<input name="brand" value="' . e($item['brand']) . '"></label><label>Model<input name="model" value="' . e($item['model']) . '"></label></div><div class="grid three"><label>Serial Number<input name="serial_number" value="' . e($item['serial_number']) . '"></label><label>Tahun Pembuatan<input type="number" name="manufacture_year" value="' . e($item['manufacture_year']) . '"></label><label>Status<select name="status"><option value="active"' . ($item['status'] === 'active' ? ' selected' : '') . '>Active</option><option value="spare"' . ($item['status'] === 'spare' ? ' selected' : '') . '>Spare</option><option value="repair"' . ($item['status'] === 'repair' ? ' selected' : '') . '>Repair</option><option value="disposed"' . ($item['status'] === 'disposed' ? ' selected' : '') . '>Disposed</option></select></label></div><div class="grid four"><label>Garansi Sampai<input type="date" name="warranty_until" value="' . e($item['warranty_until']) . '"></label><label>Tanggal Pemasangan<input type="date" name="installed_at" value="' . e($item['installed_at']) . '"></label><label>Nilai Awal (Rp)<input type="number" step="0.01" name="purchase_value" value="' . e($item['purchase_value']) . '"></label><label>Nilai Current (Rp)<input type="number" step="0.01" name="current_value" value="' . e($item['current_value']) . '"></label></div><label>Lokasi<input name="location_label" list="savedLocationGroups" value="' . e($item['location_label']) . '"></label>' . saved_location_datalist_html() . '<label>Notes<textarea name="notes">' . e($item['notes']) . '</textarea></label><div class="actions"><button class="btn primary">Simpan Asset Item</button><a class="btn" href="' . route_url('asset_items') . '">Kembali</a></div></form></section>';
}

function asset_items_table(PDO $pdo, array $rows): string
{
    if (!$rows) { return '<p>Belum ada asset item.</p>'; }
    $html = '<table><tr><th>Asset Code</th><th>Maintenance Asset ID</th><th>Company</th><th>Mode</th><th>Type</th><th>Nama / Merek</th><th>Nilai</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $mode = ($row['asset_mode'] ?? 'standalone') === 'group' ? '<span class="badge ok">Gabungan</span>' : '<span class="badge">Mandiri</span>';
        $maintenanceLabels = asset_item_maintenance_asset_labels($pdo, (int)$row['id']);
        $maintenanceText = $maintenanceLabels ? implode("\n", $maintenanceLabels) : '-';
        $html .= '<tr><td><strong>' . e($row['asset_code']) . '</strong><br><span class="muted">SN: ' . e($row['serial_number'] ?: '-') . '</span></td><td>' . nl2br(e($maintenanceText)) . '</td><td>' . e($row['company_name'] ?: '-') . '</td><td>' . $mode . '</td><td>' . e($row['asset_type']) . '</td><td>' . e($row['asset_name']) . '<br><span class="muted">' . e(trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? ''))) . '</span></td><td>Awal: Rp ' . e(number_format((float)$row['purchase_value'], 0, ',', '.')) . '<br>Current: Rp ' . e(number_format((float)$row['current_value'], 0, ',', '.')) . '</td><td><span class="badge">' . e($row['status']) . '</span></td><td><a class="btn" href="' . route_url('asset_item_form', ['id' => $row['id']]) . '">Buka</a> <a class="btn" href="' . route_url('asset_repair_form', ['asset_item_id' => $row['id']]) . '">Repair</a></td></tr>';
    }
    return $html . '</table>';
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
        'employee_nik' => trim((string)($_POST['employee_nik'] ?? '')),
        'owner_name' => trim((string)($_POST['owner_name'] ?? '')),
        'location_label' => trim((string)($_POST['location_label'] ?? '')),
        'latitude' => normalize_decimal_input((string)($_POST['latitude'] ?? '')),
        'longitude' => normalize_decimal_input((string)($_POST['longitude'] ?? '')),
        'location_radius_m' => max(1, (int)($_POST['location_radius_m'] ?? 5)),
        'status' => trim((string)($_POST['status'] ?? 'active')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
}

function asset_bundle_form_html(PDO $pdo, array $bundle, bool $editing): string
{
    $bundle = array_merge(asset_bundle_defaults(), $bundle);
    $types = ['PC Set', 'Notebook', 'Printer', 'Network Set', 'Other'];
    $typeOptions = '';
    foreach ($types as $type) {
        $selected = ((string)($bundle['bundle_type'] ?? '') === $type) ? ' selected' : '';
        $typeOptions .= '<option value="' . e($type) . '"' . $selected . '>' . e($type) . '</option>';
    }
    return '<section class="panel"><h1>' . ($editing ? 'Edit Asset Bundle' : 'Tambah Asset Bundle') . '</h1><p class="muted">Bundle adalah no aset pemeliharaan. CPU dan monitor boleh punya asset code masing-masing, tetapi maintenance menganggap bundle ini sebagai satu paket.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three"><label>No Aset Pemeliharaan<input name="maintenance_asset_code" value="' . e($bundle['maintenance_asset_code']) . '" required></label><label>Company<select name="company_id">' . company_options($pdo, (int)($bundle['company_id'] ?? 0)) . '</select></label><label>Bundle Type<select name="bundle_type">' . $typeOptions . '</select></label></div><div class="grid three"><label>Nama Bundle<input name="bundle_name" value="' . e($bundle['bundle_name']) . '" required></label><label>NIK Pengguna<input name="employee_nik" value="' . e($bundle['employee_nik']) . '"></label><label>Pengguna<input name="owner_name" value="' . e($bundle['owner_name']) . '"></label></div><div class="grid four"><label>Group / Nama Titik Lokasi<input name="location_label" list="savedLocationGroups" value="' . e($bundle['location_label']) . '"></label><label>Latitude<input name="latitude" value="' . e($bundle['latitude']) . '"></label><label>Longitude<input name="longitude" value="' . e($bundle['longitude']) . '"></label><label>Radius Meter<input type="number" min="1" name="location_radius_m" value="' . e($bundle['location_radius_m']) . '"></label></div>' . saved_location_datalist_html() . '<label>Status<select name="status"><option value="active"' . ($bundle['status'] === 'active' ? ' selected' : '') . '>Active</option><option value="spare"' . ($bundle['status'] === 'spare' ? ' selected' : '') . '>Spare</option><option value="inactive"' . ($bundle['status'] === 'inactive' ? ' selected' : '') . '>Inactive</option></select></label><label>Notes<textarea name="notes">' . e($bundle['notes']) . '</textarea></label><div class="actions"><button class="btn primary">Simpan Bundle</button><a class="btn" href="' . route_url('asset_bundles') . '">Kembali</a></div></form></section>';
}

function asset_bundles_table(array $rows): string
{
    if (!$rows) { return '<p>Belum ada asset bundle.</p>'; }
    $html = '<table><tr><th>No Aset Pemeliharaan</th><th>Company</th><th>Bundle</th><th>Pengguna / Lokasi</th><th>Member</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $html .= '<tr><td><strong>' . e($row['maintenance_asset_code']) . '</strong></td><td>' . e($row['company_name'] ?: '-') . '</td><td>' . e($row['bundle_name']) . '<br><span class="muted">' . e($row['bundle_type']) . '</span></td><td>' . e($row['owner_name'] ?: '-') . '<br><span class="muted">' . e($row['location_label'] ?: '-') . '</span></td><td>' . e($row['member_count']) . ' item</td><td><span class="badge">' . e($row['status']) . '</span></td><td><a class="btn" href="' . route_url('asset_bundle_form', ['id' => $row['id']]) . '">Buka</a></td></tr>';
    }
    return $html . '</table>';
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

function asset_item_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM asset_items WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

function asset_item_member_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM asset_item_members WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

function active_parent_asset_item(PDO $pdo, int $childId): array
{
    $stmt = $pdo->prepare('SELECT m.*, p.asset_code parent_asset_code, p.asset_name parent_asset_name FROM asset_item_members m JOIN asset_items p ON p.id=m.parent_asset_item_id WHERE m.child_asset_item_id=? AND m.detached_at IS NULL ORDER BY m.id DESC LIMIT 1');
    $stmt->execute([$childId]);
    return $stmt->fetch() ?: [];
}

function asset_item_contains_child(PDO $pdo, int $parentId, int $targetChildId, array $seen = []): bool
{
    if ($parentId <= 0 || $targetChildId <= 0) {
        return false;
    }
    if ($parentId === $targetChildId) {
        return true;
    }
    if (isset($seen[$parentId])) {
        return false;
    }
    $seen[$parentId] = true;
    $stmt = $pdo->prepare('SELECT child_asset_item_id FROM asset_item_members WHERE parent_asset_item_id=? AND detached_at IS NULL');
    $stmt->execute([$parentId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
        $childId = (int)$childId;
        if ($childId === $targetChildId || asset_item_contains_child($pdo, $childId, $targetChildId, $seen)) {
            return true;
        }
    }
    return false;
}

function asset_item_member_manager_html(PDO $pdo, int $parentId): string
{
    $parent = asset_item_row($pdo, $parentId);
    if (!$parent) {
        return '';
    }
    if (($parent['asset_mode'] ?? 'standalone') !== 'group') {
        return '<section class="panel"><h2>Anggota Asset Gabungan</h2><p class="muted">Ubah Mode Aset menjadi <strong>Asset Gabungan</strong> lalu simpan jika asset ini akan berisi CPU, monitor, UPS, atau item lain.</p></section>';
    }
    $stmt = $pdo->prepare('SELECT m.*, c.asset_code, c.asset_name, c.asset_type, c.asset_mode, ac.company_name FROM asset_item_members m JOIN asset_items c ON c.id=m.child_asset_item_id LEFT JOIN asset_companies ac ON ac.id=c.company_id WHERE m.parent_asset_item_id=? ORDER BY m.detached_at IS NULL DESC, m.attached_at DESC, m.id DESC');
    $stmt->execute([$parentId]);
    $html = '<section class="panel"><h2>Anggota Asset Gabungan</h2><p class="muted">Pasang asset item lain ke gabungan ini. Jika item sedang tergabung di asset lain, sistem otomatis melepas dari gabungan lama dan mencatat mutasi.</p><form method="post" action="' . route_url('asset_item_member_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="attach"><input type="hidden" name="parent_asset_item_id" value="' . e($parentId) . '"><div class="grid four"><label>Asset Item Anggota<select name="child_asset_item_id" required>' . asset_child_item_options($pdo, $parentId) . '</select></label><label>Role<input name="role_name" placeholder="CPU / Monitor / UPS"></label><label>Tanggal Pasang<input type="date" name="attached_at" value="' . e(date('Y-m-d')) . '"></label><label>Catatan<input name="notes"></label></div><button class="btn primary">Gabungkan Item</button></form><table><tr><th>Asset Item</th><th>Mode</th><th>Role</th><th>Company</th><th>Pasang</th><th>Lepas</th><th>Aksi</th></tr>';
    foreach ($stmt as $row) {
        $mode = ($row['asset_mode'] ?? 'standalone') === 'group' ? 'Gabungan' : 'Mandiri';
        $html .= '<tr><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . ' <span class="muted">(' . e($row['asset_type']) . ')</span></td><td><span class="badge">' . e($mode) . '</span></td><td>' . e($row['role_name'] ?: '-') . '</td><td>' . e($row['company_name'] ?: '-') . '</td><td>' . e($row['attached_at']) . '</td><td>' . e($row['detached_at'] ?: '-') . '</td><td>';
        if (empty($row['detached_at'])) {
            $html .= '<form method="post" action="' . route_url('asset_item_member_action') . '"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="detach"><input type="hidden" name="parent_asset_item_id" value="' . e($parentId) . '"><input type="hidden" name="member_id" value="' . e($row['id']) . '"><input type="date" name="detached_at" value="' . e(date('Y-m-d')) . '"><input name="reason" placeholder="Alasan lepas/tukar"><button class="btn danger">Pisahkan</button></form>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</table></section>';
}

function asset_bundle_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM asset_bundles WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

function asset_bundle_member_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT m.*, b.company_id FROM asset_bundle_members m JOIN asset_bundles b ON b.id=m.bundle_id WHERE m.id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
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

function asset_repair_form_html(PDO $pdo, array $repair, int $id): string
{
    return '<section class="panel"><h1>' . ($id > 0 ? 'Edit Repair History' : 'Tambah Repair History') . '</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three"><label>Asset Item<select name="asset_item_id" required>' . asset_item_options($pdo, (int)$repair['asset_item_id']) . '</select></label><label>Bundle<select name="bundle_id">' . asset_bundle_options($pdo, (int)$repair['bundle_id']) . '</select></label><label>Tanggal Perbaikan<input type="date" name="repair_date" value="' . e($repair['repair_date']) . '" required></label></div><div class="grid three"><label>Tempat Perbaikan<input name="repair_location" value="' . e($repair['repair_location']) . '"></label><label>Vendor / Bengkel<input name="repair_vendor" value="' . e($repair['repair_vendor']) . '"></label><label>PIC / Teknisi<input name="technician_or_pic" value="' . e($repair['technician_or_pic']) . '"></label></div><label>Kerusakan<textarea name="problem_description">' . e($repair['problem_description']) . '</textarea></label><label>Tindakan Perbaikan<textarea name="repair_action">' . e($repair['repair_action']) . '</textarea></label><div class="grid two"><label>Spare Part Diganti<textarea name="spare_part_replaced">' . e($repair['spare_part_replaced']) . '</textarea></label><label>Catatan<textarea name="notes">' . e($repair['notes']) . '</textarea></label></div><div class="grid two"><label>Biaya Repair (Rp)<input type="number" step="0.01" name="repair_cost" value="' . e($repair['repair_cost']) . '"></label><label><input style="width:auto" type="checkbox" name="warranty_claim" value="1" ' . ((int)$repair['warranty_claim'] ? 'checked' : '') . '> Warranty Claim</label></div><h2>Detail Spare Part</h2><p class="muted">Opsional. Isi 1-3 part yang diganti agar history lebih rapi.</p><div class="grid three">' . repair_part_inputs_html($pdo, $id) . '</div><div class="actions"><button class="btn primary">Simpan Repair</button><a class="btn" href="' . route_url('asset_repairs') . '">Kembali</a></div></form></section>';
}

function repair_part_inputs_html(PDO $pdo, int $repairId): string
{
    $rows = [];
    if ($repairId > 0) {
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
    foreach ($parts as $part) {
        $name = trim((string)($part['part_name'] ?? ''));
        if ($name === '') { continue; }
        $pdo->prepare('INSERT INTO asset_repair_parts (repair_id, part_name, part_brand, part_serial, qty, unit_cost, old_part_condition) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$repairId, $name, trim((string)($part['part_brand'] ?? '')), trim((string)($part['part_serial'] ?? '')), (float)($part['qty'] ?? 1), (float)($part['unit_cost'] ?? 0), trim((string)($part['old_part_condition'] ?? ''))]);
    }
}

function asset_repair_rows(PDO $pdo): array
{
    return $pdo->query('SELECT r.*, ai.asset_code, ai.asset_name, b.maintenance_asset_code FROM asset_repairs r JOIN asset_items ai ON ai.id=r.asset_item_id LEFT JOIN asset_bundles b ON b.id=r.bundle_id ORDER BY r.repair_date DESC, r.id DESC')->fetchAll();
}

function asset_repairs_table(array $rows): string
{
    if (!$rows) { return '<p>Belum ada repair history.</p>'; }
    $html = '<table><tr><th>Tanggal</th><th>Asset</th><th>Bundle</th><th>Tempat / Vendor</th><th>Kerusakan</th><th>Spare Part</th><th>Biaya</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . e($row['repair_date']) . '</td><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . '</td><td>' . e($row['maintenance_asset_code'] ?: '-') . '</td><td>' . e($row['repair_location'] ?: '-') . '<br><span class="muted">' . e($row['repair_vendor'] ?: '-') . '</span></td><td>' . e($row['problem_description'] ?: '-') . '</td><td>' . e($row['spare_part_replaced'] ?: '-') . '</td><td>Rp ' . e(number_format((float)$row['repair_cost'], 0, ',', '.')) . '</td><td><a class="btn" href="' . route_url('asset_repair_form', ['id' => $row['id']]) . '">Edit</a></td></tr>';
    }
    return $html . '</table>';
}

function maintenance_asset_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Pilih Maintenance Asset ID -</option>';
    foreach ($pdo->query('SELECT id, maintenance_asset_code, name, maintenance_type FROM maintenance_assets ORDER BY maintenance_asset_code') as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $label = $row['maintenance_asset_code'] . ' - ' . $row['name'] . ' (' . $row['maintenance_type'] . ')';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

function primary_group_item_for_maintenance_asset(PDO $pdo, int $maintenanceAssetId, int $excludeAssetItemId = 0): array
{
    $stmt = $pdo->prepare('SELECT ai.* FROM maintenance_asset_items mai JOIN asset_items ai ON ai.id=mai.asset_item_id WHERE mai.maintenance_asset_id=? AND mai.detached_at IS NULL AND ai.asset_mode="group" AND ai.id<>? ORDER BY mai.id DESC LIMIT 1');
    $stmt->execute([$maintenanceAssetId, $excludeAssetItemId]);
    return $stmt->fetch() ?: [];
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

function asset_movement_transaction_form_html(PDO $pdo, array $user): string
{
    $actionOptions = '<option value="company_move">Mutasi company asset item</option><option value="attach_maintenance">Pasang asset item ke Maintenance Asset ID</option><option value="detach_maintenance">Lepas asset item dari Maintenance Asset ID</option><option value="attach_group">Tukar/Pasang ke Asset Gabungan</option><option value="detach_group">Lepas dari Asset Gabungan</option>';
    return '<section class="panel"><h1>Transaksi Mutasi / Tukar Pasang</h1><p class="muted">Gunakan halaman ini untuk menjalankan transaksi pindah company, pasang/lepas asset item ke Maintenance Asset ID, atau tukar/pasang anggota asset gabungan. Sistem akan langsung mengubah relasi dan mencatat history.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><div class="grid three"><label>Tipe Transaksi<select name="transaction_type" required>' . $actionOptions . '</select></label><label>Asset Item<select name="asset_item_id" required>' . asset_item_options($pdo) . '</select></label><label>Tanggal Transaksi<input type="date" name="movement_date" value="' . e(date('Y-m-d')) . '" required></label></div><div class="grid three"><label>Tujuan Company<select name="to_company_id">' . company_options($pdo) . '</select></label><label>Maintenance Asset ID<select name="maintenance_asset_id">' . maintenance_asset_options($pdo) . '</select></label><label>Tujuan Asset Gabungan<select name="parent_asset_item_id">' . asset_group_item_options($pdo) . '</select></label></div><div class="grid two"><label>PIC<input name="pic" value="' . e($user['name'] ?? '') . '"></label><label>Role / Posisi Item<input name="role_name" placeholder="CPU / Monitor / Printer / Unit"></label></div><label>Alasan / Catatan<textarea name="reason" placeholder="Contoh: pindah company, monitor ditukar, CPU dipasang ke PC set baru"></textarea></label><div class="actions"><button class="btn primary">Simpan Transaksi</button></div></form></section>';
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
    $date = normalize_date_input((string)($_POST['movement_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');
    $reason = trim((string)($_POST['reason'] ?? ''));
    $pic = trim((string)($_POST['pic'] ?? '')) ?: (string)($user['name'] ?? '');
    $role = trim((string)($_POST['role_name'] ?? 'Asset Item')) ?: 'Asset Item';

    try {
        if ($type === 'company_move') {
            $toCompanyId = (int)($_POST['to_company_id'] ?? 0) ?: null;
            $fromCompanyId = (int)($item['company_id'] ?? 0) ?: null;
            if ($toCompanyId === null) {
                flash('Tujuan company wajib dipilih untuk transaksi mutasi company.', 'err');
                return;
            }
            $pdo->prepare('UPDATE asset_items SET company_id=? WHERE id=?')->execute([$toCompanyId, $assetItemId]);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, from_company_id, to_company_id, movement_date, reason, pic) VALUES (?, ?, ?, ?, ?, ?)')->execute([$assetItemId, $fromCompanyId, $toCompanyId, $date, $reason ?: 'Mutasi company asset item', $pic]);
            flash('Mutasi company asset item berhasil disimpan.');
            return;
        }

        if ($type === 'attach_maintenance') {
            $maintenanceAssetId = (int)($_POST['maintenance_asset_id'] ?? 0);
            if ($maintenanceAssetId <= 0) {
                flash('Maintenance Asset ID wajib dipilih.', 'err');
                return;
            }
            detach_asset_item_from_other_maintenance_assets($pdo, $assetItemId, $maintenanceAssetId, $date, $reason ?: 'Dipindahkan ke Maintenance Asset ID baru');
            $oldParent = active_parent_asset_item($pdo, $assetItemId);
            $targetGroup = primary_group_item_for_maintenance_asset($pdo, $maintenanceAssetId, $assetItemId);
            if ($oldParent && (!$targetGroup || (int)$oldParent['parent_asset_item_id'] !== (int)$targetGroup['id'])) {
                $pdo->prepare('UPDATE asset_item_members SET detached_at=?, notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute([$date, "\nDipindahkan ke Maintenance Asset ID #" . $maintenanceAssetId, (int)$oldParent['id']]);
            }
            if ($targetGroup && (int)$targetGroup['id'] !== $assetItemId && !asset_item_contains_child($pdo, $assetItemId, (int)$targetGroup['id'])) {
                $activeParent = active_parent_asset_item($pdo, $assetItemId);
                if (!$activeParent || (int)$activeParent['parent_asset_item_id'] !== (int)$targetGroup['id']) {
                    $pdo->prepare('INSERT INTO asset_item_members (parent_asset_item_id, child_asset_item_id, role_name, attached_at, notes) VALUES (?, ?, ?, ?, ?)')->execute([(int)$targetGroup['id'], $assetItemId, $role, $date, $reason]);
                }
            }
            link_maintenance_asset_item($pdo, $maintenanceAssetId, $assetItemId, $role, $date);
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?)')->execute([$assetItemId, $date, $reason ?: 'Pasang ke Maintenance Asset ID #' . $maintenanceAssetId, $pic]);
            flash('Asset item berhasil dipasang ke Maintenance Asset ID.');
            return;
        }

        if ($type === 'detach_maintenance') {
            $maintenanceAssetId = (int)($_POST['maintenance_asset_id'] ?? 0);
            $detachedCount = detach_asset_item_from_maintenance_assets($pdo, $assetItemId, $maintenanceAssetId ?: null, $date, $reason ?: 'Lepas dari Maintenance Asset ID');
            if ($detachedCount <= 0 && $maintenanceAssetId > 0) {
                $detachedCount = detach_asset_item_from_maintenance_assets($pdo, $assetItemId, null, $date, $reason ?: 'Lepas dari semua Maintenance Asset ID aktif');
            }
            if ($detachedCount <= 0) {
                flash('Relasi aktif ke Maintenance Asset ID tidak ditemukan untuk asset item ini.', 'err');
                return;
            }
            $pdo->prepare('INSERT INTO asset_movements (asset_item_id, movement_date, reason, pic) VALUES (?, ?, ?, ?)')->execute([$assetItemId, $date, $reason ?: 'Lepas dari Maintenance Asset ID' . ($maintenanceAssetId > 0 ? ' #' . $maintenanceAssetId : ''), $pic]);
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
    return $pdo->query('SELECT mv.*, ai.asset_code, ai.asset_name, fb.maintenance_asset_code from_bundle, tb.maintenance_asset_code to_bundle, fp.asset_code from_parent_code, fp.asset_name from_parent_name, tp.asset_code to_parent_code, tp.asset_name to_parent_name, fc.company_name from_company, tc.company_name to_company FROM asset_movements mv JOIN asset_items ai ON ai.id=mv.asset_item_id LEFT JOIN asset_bundles fb ON fb.id=mv.from_bundle_id LEFT JOIN asset_bundles tb ON tb.id=mv.to_bundle_id LEFT JOIN asset_items fp ON fp.id=mv.from_parent_asset_item_id LEFT JOIN asset_items tp ON tp.id=mv.to_parent_asset_item_id LEFT JOIN asset_companies fc ON fc.id=mv.from_company_id LEFT JOIN asset_companies tc ON tc.id=mv.to_company_id ORDER BY mv.movement_date DESC, mv.id DESC')->fetchAll();
}

function asset_movements_table(array $rows): string
{
    if (!$rows) { return '<p>Belum ada mutasi/tukar pasang.</p>'; }
    $html = '<table><tr><th>Tanggal</th><th>Asset</th><th>Dari Gabungan</th><th>Ke Gabungan</th><th>Company</th><th>Alasan</th><th>PIC</th></tr>';
    foreach ($rows as $row) {
        $from = $row['from_parent_code'] ? $row['from_parent_code'] . ' - ' . $row['from_parent_name'] : ($row['from_bundle'] ?: '-');
        $to = $row['to_parent_code'] ? $row['to_parent_code'] . ' - ' . $row['to_parent_name'] : ($row['to_bundle'] ?: '-');
        $html .= '<tr><td>' . e($row['movement_date']) . '</td><td><strong>' . e($row['asset_code']) . '</strong><br>' . e($row['asset_name']) . '</td><td>' . e($from) . '</td><td>' . e($to) . '</td><td>' . e(($row['from_company'] ?: '-') . ' -> ' . ($row['to_company'] ?: '-')) . '</td><td>' . e($row['reason'] ?: '-') . '</td><td>' . e($row['pic'] ?: '-') . '</td></tr>';
    }
    return $html . '</table>';
}

function pc_table(array $rows, bool $actions = false): string
{
    if (!$rows) { return '<p>Belum ada data PC.</p>'; }
    $pdo = Database::pdo();
    $html = '<table><tr><th>PcID</th><th>NIK</th><th>Pengguna</th><th>Computer Name</th><th>Asset Management</th><th>Analisa Terakhir</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $rowActions = '<a class="btn" href="' . route_url('pc_detail', ['pc_id' => $row['pc_id']]) . '">Detail</a>';
        if ($actions) {
            $rowActions .= ' <a class="btn" href="' . route_url('pc_form', ['pc_id' => $row['pc_id']]) . '">Edit</a>';
        }
        $html .= '<tr><td>' . e($row['pc_id']) . '</td><td>' . e($row['employee_nik'] ?? '-') . '</td><td>' . e($row['owner_name']) . '</td><td>' . e($row['computer_name'] ?? '-') . '</td><td>' . nl2br(e(pc_asset_link_summary($pdo, $row))) . '</td><td>' . e($row['last_analyzed_at'] ?? '-') . '</td><td>' . $rowActions . '</td></tr>';
    }
    return $html . '</table>';
}

function printer_table(array $rows, bool $actions = false): string
{
    if (!$rows) { return '<p>Belum ada data printer.</p>'; }
    $html = '<table><tr><th>PrnID</th><th>Printer Name</th><th>Location</th><th>Serial Number</th><th>IP Printer</th><th>Model</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        $rowActions = '<a class="btn" href="' . route_url('printer_detail', ['prn_id' => $row['prn_id']]) . '">Detail</a>';
        if ($actions) {
            $rowActions .= ' <a class="btn" href="' . route_url('printer_form', ['prn_id' => $row['prn_id']]) . '">Edit</a>';
        }
        $html .= '<tr><td>' . e($row['prn_id']) . '</td><td>' . e($row['printer_name']) . '</td><td>' . e($row['location'] ?? '-') . '</td><td>' . e($row['serial_number'] ?? '-') . '</td><td>' . e($row['ip_printer'] ?? '-') . '</td><td>' . e($row['model_printer'] ?? '-') . '</td><td>' . $rowActions . '</td></tr>';
    }
    return $html . '</table>';
}

function pc_security_code_seed(string $pcId): string
{
    return strtoupper(chr(65 + (crc32($pcId) % 26)) . (crc32($pcId . '-pcconnect') % 10));
}

function load_user_for_admin(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function active_admin_count(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
}

function role_label(string $role): string
{
    return [
        'admin' => 'Admin Full',
        'maintenance_admin' => 'Admin Maintenance',
        'technician' => 'Teknisi',
    ][$role] ?? $role;
}

function db_supports_maintenance_admin(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
        $row = $stmt->fetch();
        return $row && strpos((string)($row['Type'] ?? ''), 'maintenance_admin') !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_maintenance_admin_role(PDO $pdo): bool
{
    if (db_supports_maintenance_admin($pdo)) {
        return true;
    }
    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','maintenance_admin','technician') NOT NULL DEFAULT 'technician'");
        return db_supports_maintenance_admin($pdo);
    } catch (Throwable $e) {
        return false;
    }
}

function db_table_exists(PDO $pdo, string $table): bool
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

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function db_foreign_key_name(PDO $pdo, string $table, string $column, string $referencedTable): string
{
    try {
        $stmt = $pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column, $referencedTable]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function db_column_nullable(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return strtoupper((string)$stmt->fetchColumn()) === 'YES';
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_printer_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS printers (
            prn_id VARCHAR(60) PRIMARY KEY,
            security_code VARCHAR(12) NOT NULL,
            printer_name VARCHAR(160) NOT NULL,
            location VARCHAR(160) NULL,
            serial_number VARCHAR(160) NULL,
            ip_printer VARCHAR(80) NULL,
            model_printer VARCHAR(160) NULL,
            physical_condition TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!db_column_exists($pdo, 'maintenance_schedules', 'asset_type')) {
            $pdo->exec("ALTER TABLE maintenance_schedules ADD COLUMN asset_type ENUM('pc','printer') NOT NULL DEFAULT 'pc' AFTER id");
        }
        if (!db_column_exists($pdo, 'maintenance_schedules', 'printer_id')) {
            $pdo->exec("ALTER TABLE maintenance_schedules ADD COLUMN printer_id VARCHAR(60) NULL AFTER pc_id");
        }
        if (!db_column_exists($pdo, 'printers', 'latitude')) {
            $pdo->exec('ALTER TABLE printers ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location');
        }
        if (!db_column_exists($pdo, 'printers', 'longitude')) {
            $pdo->exec('ALTER TABLE printers ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude');
        }
        if (!db_column_exists($pdo, 'printers', 'location_radius_m')) {
            $pdo->exec('ALTER TABLE printers ADD COLUMN location_radius_m INT NOT NULL DEFAULT 5 AFTER longitude');
        }
        $pcForeignKey = db_foreign_key_name($pdo, 'maintenance_schedules', 'pc_id', 'pcs');
        if ($pcForeignKey !== '') {
            try { $pdo->exec('ALTER TABLE maintenance_schedules DROP FOREIGN KEY `' . str_replace('`', '``', $pcForeignKey) . '`'); } catch (Throwable $ignored) {}
        }
        try { $pdo->exec('ALTER TABLE maintenance_schedules MODIFY pc_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'); } catch (Throwable $ignored) {}
        try { $pdo->exec('ALTER TABLE maintenance_schedules ADD CONSTRAINT fk_schedule_pc FOREIGN KEY (pc_id) REFERENCES pcs(pc_id) ON DELETE CASCADE'); } catch (Throwable $ignored) {}
        try { $pdo->exec('ALTER TABLE printers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); } catch (Throwable $ignored) {}
        try { $pdo->exec('ALTER TABLE maintenance_schedules MODIFY printer_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_schedule_printer ON maintenance_schedules (printer_id)'); } catch (Throwable $ignored) {}
    } catch (Throwable $ignored) {
    }
}

function ensure_pc_location_schema(PDO $pdo): void
{
    try {
        if (!db_column_exists($pdo, 'pcs', 'location_label')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN location_label VARCHAR(180) NULL AFTER computer_name');
        }
        if (!db_column_exists($pdo, 'pcs', 'latitude')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location_label');
        }
        if (!db_column_exists($pdo, 'pcs', 'longitude')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude');
        }
        if (!db_column_exists($pdo, 'pcs', 'location_radius_m')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN location_radius_m INT NOT NULL DEFAULT 5 AFTER longitude');
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_employee_source_schema(PDO $pdo): void
{
    try {
        if (!db_column_exists($pdo, 'pcs', 'employee_nik')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN employee_nik VARCHAR(80) NULL AFTER security_code');
        }
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_source_config (
            config_key VARCHAR(80) PRIMARY KEY,
            config_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_directory (
            nik VARCHAR(80) PRIMARY KEY,
            employee_name VARCHAR(200) NOT NULL,
            department VARCHAR(200) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            synced_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_name (employee_name),
            INDEX idx_employee_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignored) {
    }
}

function ensure_company_source_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS company_source_config (
            config_key VARCHAR(80) PRIMARY KEY,
            config_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignored) {
    }
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
    if (($config['enabled'] ?? '0') !== '1') {
        throw new RuntimeException('Koneksi employee MariaDB portal belum diaktifkan.');
    }
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
    $limit = max(1, min(20000, $limit));
    $table = mysql_identifier((string)$config['table_name']);
    $nikField = mysql_identifier((string)$config['nik_field']);
    $nameField = mysql_identifier((string)$config['name_field']);
    $departmentFieldRaw = trim((string)($config['department_field'] ?? ''));
    $departmentSelect = $departmentFieldRaw !== '' ? ', CAST(' . mysql_identifier($departmentFieldRaw) . ' AS CHAR) AS department' : ", CAST('' AS CHAR) AS department";
    $sql = 'SELECT CAST(' . $nikField . ' AS CHAR) AS nik, CAST(' . $nameField . ' AS CHAR) AS name' . $departmentSelect . ' FROM ' . $table . ' WHERE ' . $nikField . ' IS NOT NULL AND ' . $nameField . ' IS NOT NULL ORDER BY ' . $nameField . ' LIMIT ' . (int)$limit;
    return normalize_employee_rows($remote->query($sql)->fetchAll());
}

function sync_employee_directory(PDO $appPdo): int
{
    ensure_employee_source_schema($appPdo);
    $config = employee_source_config($appPdo);
    $limit = max(1, min(20000, (int)($config['limit_rows'] ?? 500)));
    $rows = fetch_employee_portal_rows($config, $limit);
    $appPdo->beginTransaction();
    try {
        $appPdo->exec('UPDATE employee_directory SET is_active = 0');
        $stmt = $appPdo->prepare('REPLACE INTO employee_directory (nik, employee_name, department, is_active, synced_at) VALUES (?, ?, ?, 1, NOW())');
        foreach ($rows as $row) {
            $stmt->execute([$row['nik'], $row['name'], $row['department']]);
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

function fetch_employee_directory_cache(PDO $pdo, int $limit): array
{
    ensure_employee_source_schema($pdo);
    $limit = max(1, min(20000, $limit));
    $stmt = $pdo->query('SELECT nik, employee_name AS name, department FROM employee_directory WHERE is_active = 1 ORDER BY employee_name LIMIT ' . (int)$limit);
    return normalize_employee_rows($stmt->fetchAll());
}

function employee_search_rows(PDO $pdo, string $query, int $limit = 200): array
{
    ensure_employee_source_schema($pdo);
    $limit = max(1, min(500, $limit));
    $query = trim($query);
    if ($query === '') {
        $stmt = $pdo->query('SELECT nik, employee_name AS name, department FROM employee_directory WHERE is_active = 1 ORDER BY employee_name LIMIT ' . (int)$limit);
        return normalize_employee_rows($stmt->fetchAll());
    }
    $stmt = $pdo->prepare('SELECT nik, employee_name AS name, department FROM employee_directory WHERE is_active = 1 AND (employee_name LIKE ? OR nik LIKE ? OR department LIKE ?) ORDER BY employee_name LIMIT ' . (int)$limit);
    $like = '%' . $query . '%';
    $stmt->execute([$like, $like, $like]);
    return normalize_employee_rows($stmt->fetchAll());
}

function js_value(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function employee_picker_shell(string $title, string $searchId, string $selectId, array $rows, string $selectedNik = ''): string
{
    $countId = $selectId . 'Count';
    $html = '<div class="employee-picker" style="border:1px solid #cbd5e1;border-radius:8px;padding:14px;background:#f8fafc;margin:10px 0">';
    $html .= '<label style="display:block;margin:0"><span style="display:block;font-weight:700;margin-bottom:6px">' . e($title) . '</span><input id="' . e($searchId) . '" autocomplete="off" placeholder="Ketik nama, NIK, atau departemen" style="margin:0;border-bottom-left-radius:0;border-bottom-right-radius:0"></label>';
    $html .= '<select id="' . e($selectId) . '" size="8" style="width:100%;min-height:210px;margin:0;border-top:0;border-top-left-radius:0;border-top-right-radius:0;font-family:inherit;background:#fff">';
    foreach ($rows as $row) {
        $nik = (string)($row['nik'] ?? '');
        $name = (string)($row['name'] ?? '');
        $dept = trim((string)($row['department'] ?? ''));
        $label = $nik . ' - ' . $name . ($dept !== '' ? ' - ' . $dept : '');
        $html .= '<option value="' . e($nik) . '"' . ($selectedNik !== '' && $selectedNik === $nik ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select><p class="muted" id="' . e($countId) . '" style="margin:8px 0 0">Menampilkan ' . count($rows) . ' hasil awal. Ketik untuk mencari data lain.</p></div>';
    return $html;
}

function fetch_employee_options(PDO $appPdo, int $limit = 0, bool $force = false): array
{
    $config = employee_source_config($appPdo);
    if (($config['enabled'] ?? '0') !== '1' && !$force) {
        return [];
    }
    $limit = $limit > 0 ? $limit : max(1, min(2000, (int)($config['limit_rows'] ?? 500)));
    if ($force) {
        return fetch_employee_portal_rows($config, $limit);
    }
    return fetch_employee_directory_cache($appPdo, $limit);
}

function find_employee_by_nik(PDO $appPdo, string $nik): ?array
{
    $nik = trim($nik);
    if ($nik === '') {
        return null;
    }
    $config = employee_source_config($appPdo);
    ensure_employee_source_schema($appPdo);
    $stmt = $appPdo->prepare('SELECT nik, employee_name AS name, department FROM employee_directory WHERE nik = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$nik]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function employee_source_form_html(PDO $pdo): string
{
    $config = employee_source_config($pdo);
    $cacheCount = 0;
    $lastSync = '-';
    try {
        ensure_employee_source_schema($pdo);
        $cacheCount = (int)$pdo->query('SELECT COUNT(*) FROM employee_directory WHERE is_active = 1')->fetchColumn();
        $lastSyncValue = $pdo->query('SELECT MAX(synced_at) FROM employee_directory')->fetchColumn();
        $lastSync = $lastSyncValue ? (string)$lastSyncValue : '-';
    } catch (Throwable $ignored) {
    }
    $html = '<section class="panel"><h1>Setup Employee MariaDB Portal</h1><p class="muted">Dipakai untuk memilih karyawan di Add/Edit PC. Data dari MariaDB portal akan disinkron ke cache lokal PcConnect.</p><div class="grid three"><div class="stat"><strong>' . e((string)$cacheCount) . '</strong><span>Karyawan aktif lokal</span></div><div class="stat"><strong>' . e($lastSync) . '</strong><span>Sync terakhir</span></div><div class="stat"><strong>MariaDB</strong><span>Sumber portal</span></div></div></section>';
    $html .= '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><label><input type="checkbox" name="enabled" value="1" ' . (($config['enabled'] ?? '0') === '1' ? 'checked' : '') . ' style="width:auto"> Aktifkan sinkronisasi karyawan dari MariaDB portal</label><div class="grid three">';
    $html .= '<label>IP / Host MariaDB Portal<input name="host" value="' . e($config['host']) . '" placeholder="portal.domain.com atau 192.168.1.10"></label>';
    $html .= '<label>Port<input name="port" value="' . e($config['port']) . '" placeholder="3306"></label>';
    $html .= '<label>Database<input name="database" value="' . e($config['database']) . '" placeholder="portal_hrd"></label>';
    $html .= '<label>Username<input name="username" value="' . e($config['username']) . '" placeholder="user_mariadb"></label>';
    $html .= '<label>Password<input type="password" name="password" placeholder="Kosongkan jika tidak diubah"></label>';
    $html .= '<label>Limit Row<input type="number" min="1" max="20000" name="limit_rows" value="' . e($config['limit_rows']) . '"></label></div>';
    $html .= '<h2>Mapping Field</h2><div class="grid four">';
    $html .= '<label>Table / View<input name="table_name" value="' . e($config['table_name']) . '" placeholder="employees atau hrd.karyawan"></label>';
    $html .= '<label>Field NIK<input name="nik_field" value="' . e($config['nik_field']) . '" placeholder="NIK"></label>';
    $html .= '<label>Field Nama<input name="name_field" value="' . e($config['name_field']) . '" placeholder="Nama"></label>';
    $html .= '<label>Field Departemen (opsional)<input name="department_field" value="' . e($config['department_field']) . '" placeholder="Department"></label></div>';
    $html .= '<p class="muted">Nama table boleh format <code>database.table</code>. Field hanya huruf/angka/underscore/spasi/tanda minus agar query aman. Tombol Sync akan mengambil data dari portal dan menyimpan cache lokal PcConnect.</p><div class="actions"><button class="btn primary" name="action" value="save">Simpan Setup</button><button class="btn" name="action" value="test">Simpan & Test Koneksi</button><button class="btn ok" name="action" value="sync">Simpan & Sync Karyawan</button></div></form></section>';
    try {
        $rows = fetch_employee_options($pdo, 10);
        if ($rows) {
            $html .= '<section class="panel"><h2>Preview Cache Karyawan Lokal</h2><table><tr><th>NIK</th><th>Nama</th><th>Departemen</th></tr>';
            foreach ($rows as $row) {
                $html .= '<tr><td>' . e($row['nik'] ?? '') . '</td><td>' . e($row['name'] ?? '') . '</td><td>' . e($row['department'] ?? '') . '</td></tr>';
            }
            $html .= '</table></section>';
        }
    } catch (Throwable $e) {
        if (($config['enabled'] ?? '0') === '1') {
            $html .= '<section class="panel"><div class="flash err">Preview gagal: ' . e($e->getMessage()) . '</div></section>';
        }
    }
    return $html;
}

function sqlsrv_driver_ready(): bool
{
    return extension_loaded('pdo_sqlsrv') || in_array('sqlsrv', PDO::getAvailableDrivers(), true);
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

function sqlsrv_identifier(string $identifier, bool $allowDot = false): string
{
    $identifier = trim($identifier);
    $pattern = $allowDot ? '/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+){0,2}$/' : '/^[A-Za-z0-9_]+$/';
    if (!preg_match($pattern, $identifier)) {
        throw new RuntimeException('Nama table/field SQL Server tidak valid: ' . $identifier);
    }
    $parts = explode('.', $identifier);
    return implode('.', array_map(static fn(string $part): string => '[' . str_replace(']', ']]', $part) . ']', $parts));
}

function company_source_connection_from_config(array $config): PDO
{
    if (($config['enabled'] ?? '0') !== '1') {
        throw new RuntimeException('Koneksi company SQL Server belum diaktifkan.');
    }
    if (!sqlsrv_driver_ready()) {
        throw new RuntimeException('pdo_sqlsrv belum aktif di PHP QNAP. Install/aktifkan driver SQL Server untuk mode direct SQL Server.');
    }
    $host = trim((string)($config['host'] ?? ''));
    $database = trim((string)($config['database'] ?? ''));
    if ($host === '' || $database === '') {
        throw new RuntimeException('IP/host dan database SQL Server wajib diisi.');
    }
    $port = (int)($config['port'] ?? 1433);
    $server = $host . ($port > 0 ? ',' . $port : '');
    $dsn = 'sqlsrv:Server=' . $server . ';Database=' . $database;
    if (($config['trust_server_certificate'] ?? '1') === '1') {
        $dsn .= ';TrustServerCertificate=1';
    }
    return new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function normalize_company_rows(array $rows): array
{
    $normalized = [];
    foreach ($rows as $row) {
        $externalId = trim((string)($row['external_company_id'] ?? $row['id'] ?? ''));
        $name = trim((string)($row['company_name'] ?? $row['nama_unit_usaha'] ?? $row['name'] ?? ''));
        if ($externalId === '' || $name === '') {
            continue;
        }
        $normalized[] = [
            'external_company_id' => $externalId,
            'company_name' => $name,
            'company_code' => company_code_from_external_id($externalId),
        ];
    }
    return $normalized;
}

function company_code_from_external_id(string $externalId): string
{
    $code = strtoupper(preg_replace('/[^A-Z0-9_-]+/i', '-', trim($externalId)) ?? '');
    $code = trim($code, '-_');
    if ($code === '') {
        $code = 'CMP-' . substr(sha1($externalId), 0, 8);
    }
    return substr($code, 0, 40);
}

function fetch_company_source_rows(array $config, int $limit): array
{
    if (($config['mode'] ?? 'bridge') === 'bridge') {
        return fetch_company_bridge_rows($config, $limit);
    }
    $remote = company_source_connection_from_config($config);
    $limit = max(1, min(20000, $limit));
    $table = sqlsrv_identifier((string)$config['table_name'], true);
    $idField = sqlsrv_identifier((string)$config['id_field']);
    $nameField = sqlsrv_identifier((string)$config['name_field']);
    $sql = 'SELECT TOP (' . (int)$limit . ') CAST(' . $idField . ' AS NVARCHAR(80)) AS external_company_id, CAST(' . $nameField . ' AS NVARCHAR(180)) AS company_name FROM ' . $table . ' WHERE ' . $idField . ' IS NOT NULL AND ' . $nameField . ' IS NOT NULL ORDER BY ' . $nameField;
    return normalize_company_rows($remote->query($sql)->fetchAll());
}

function company_bridge_url(array $config, string $path, array $query = []): string
{
    $base = rtrim(trim((string)($config['bridge_url'] ?? '')), '/');
    if ($base === '') {
        throw new RuntimeException('Bridge API URL wajib diisi untuk mode Bridge API Windows.');
    }
    $url = $base . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function company_bridge_request(array $config, string $path, array $query = []): array
{
    $url = company_bridge_url($config, $path, $query);
    $headers = ["Accept: application/json\r\n"];
    $token = trim((string)($config['bridge_token'] ?? ''));
    if ($token !== '') {
        $headers[] = 'X-Api-Token: ' . $token . "\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => implode('', $headers),
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Bridge API tidak merespons: ' . $url);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Response Bridge API bukan JSON valid: ' . substr($body, 0, 160));
    }
    if (empty($data['ok'])) {
        throw new RuntimeException((string)($data['error'] ?? 'Bridge API mengembalikan error.'));
    }
    return $data;
}

function fetch_company_bridge_rows(array $config, int $limit): array
{
    if (($config['enabled'] ?? '0') !== '1') {
        throw new RuntimeException('Koneksi company bridge belum diaktifkan.');
    }
    $limit = max(1, min(20000, $limit));
    $data = company_bridge_request($config, 'companies', ['limit' => $limit]);
    return normalize_company_rows((array)($data['data'] ?? []));
}

function sync_company_rows_to_local(PDO $appPdo, array $rows): int
{
    ensure_company_source_schema($appPdo);
    ensure_asset_management_schema($appPdo);
    $rows = normalize_company_rows($rows);
    $appPdo->beginTransaction();
    try {
        $findExternal = $appPdo->prepare('SELECT id FROM asset_companies WHERE external_company_id=? LIMIT 1');
        $findCode = $appPdo->prepare('SELECT id FROM asset_companies WHERE company_code=? LIMIT 1');
        $update = $appPdo->prepare('UPDATE asset_companies SET company_code=?, company_name=?, is_active=1, external_company_id=? WHERE id=?');
        $insert = $appPdo->prepare('INSERT INTO asset_companies (company_code, company_name, external_company_id, is_active) VALUES (?, ?, ?, 1)');
        foreach ($rows as $row) {
            $id = null;
            $findExternal->execute([$row['external_company_id']]);
            $id = $findExternal->fetchColumn();
            if (!$id) {
                $findCode->execute([$row['company_code']]);
                $id = $findCode->fetchColumn();
            }
            if ($id) {
                $update->execute([$row['company_code'], $row['company_name'], $row['external_company_id'], (int)$id]);
            } else {
                $insert->execute([$row['company_code'], $row['company_name'], $row['external_company_id']]);
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

function sync_company_directory(PDO $appPdo): int
{
    $config = company_source_config($appPdo);
    $limit = max(1, min(20000, (int)($config['limit_rows'] ?? 500)));
    return sync_company_rows_to_local($appPdo, fetch_company_source_rows($config, $limit));
}

function parse_company_import_upload(array $config): array
{
    if (empty($_FILES['company_file']) || !is_array($_FILES['company_file'])) {
        throw new RuntimeException('File CSV/JSON belum dipilih.');
    }
    $file = $_FILES['company_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload file gagal. Kode error: ' . (string)($file['error'] ?? 'unknown'));
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('File upload tidak valid.');
    }
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file maksimal 5 MB.');
    }
    $name = strtolower((string)($file['name'] ?? ''));
    $content = file_get_contents($tmp);
    if ($content === false || trim($content) === '') {
        throw new RuntimeException('File import kosong.');
    }
    $idField = trim((string)($config['id_field'] ?? 'pt_id')) ?: 'pt_id';
    $nameField = trim((string)($config['name_field'] ?? 'pt_name')) ?: 'pt_name';
    if (str_ends_with($name, '.json') || str_starts_with(ltrim($content), '[') || str_starts_with(ltrim($content), '{')) {
        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw new RuntimeException('JSON tidak valid.');
        }
        $rows = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
        return company_import_rows_from_arrays($rows, $idField, $nameField);
    }
    return company_import_rows_from_csv($tmp, $idField, $nameField);
}

function company_import_rows_from_arrays(array $rows, string $idField, string $nameField): array
{
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $result[] = [
            'external_company_id' => (string)($row[$idField] ?? $row['pt_id'] ?? $row['id'] ?? $row['external_company_id'] ?? ''),
            'company_name' => (string)($row[$nameField] ?? $row['pt_name'] ?? $row['name'] ?? $row['company_name'] ?? ''),
        ];
    }
    return normalize_company_rows($result);
}

function company_import_rows_from_csv(string $path, string $idField, string $nameField): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('File CSV tidak bisa dibaca.');
    }
    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            throw new RuntimeException('CSV tidak memiliki header.');
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        $header = array_map(static fn($value): string => trim((string)$value), $header);
        $lowerHeader = array_map(static fn(string $value): string => strtolower($value), $header);
        $idIndex = array_search(strtolower($idField), $lowerHeader, true);
        $nameIndex = array_search(strtolower($nameField), $lowerHeader, true);
        if ($idIndex === false) {
            $idIndex = array_search('pt_id', $lowerHeader, true);
        }
        if ($nameIndex === false) {
            $nameIndex = array_search('pt_name', $lowerHeader, true);
        }
        if ($idIndex === false || $nameIndex === false) {
            throw new RuntimeException('Header CSV wajib berisi field ID dan Nama Unit Usaha. Contoh: pt_id,pt_name');
        }
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $rows[] = [
                'external_company_id' => (string)($line[$idIndex] ?? ''),
                'company_name' => (string)($line[$nameIndex] ?? ''),
            ];
        }
        return normalize_company_rows($rows);
    } finally {
        fclose($handle);
    }
}

function company_source_form_html(PDO $pdo): string
{
    $config = company_source_config($pdo);
    $companyCount = 0;
    try {
        ensure_asset_management_schema($pdo);
        $companyCount = (int)$pdo->query('SELECT COUNT(*) FROM asset_companies WHERE is_active=1')->fetchColumn();
    } catch (Throwable $ignored) {
    }
    $driverBadge = sqlsrv_driver_ready() ? '<span class="badge ok">pdo_sqlsrv aktif</span>' : '<span class="badge danger">pdo_sqlsrv belum aktif</span>';
    $html = '<section class="panel"><h1>Setup Company SQL Server 2019</h1><p class="muted">Dipakai untuk sinkron nama perusahaan/unit usaha ke Asset Management. Field <code>id</code> dari SQL Server disimpan sebagai kode eksternal, dan nama unit usaha masuk ke daftar Company PcConnect.</p><div class="grid three"><div class="stat"><strong>' . e((string)$companyCount) . '</strong><span>Company aktif lokal</span></div><div class="stat"><strong>' . e(($config['mode'] ?? 'bridge') === 'bridge' ? 'Bridge API' : 'Direct SQL') . '</strong><span>Sumber company</span></div><div class="stat"><strong>' . $driverBadge . '</strong><span>Driver PHP QNAP</span></div></div></section>';
    $mode = (string)($config['mode'] ?? 'bridge');
    $html .= '<section class="panel"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="module" value="company_source"><label><input type="checkbox" name="enabled" value="1" ' . (($config['enabled'] ?? '0') === '1' ? 'checked' : '') . ' style="width:auto"> Aktifkan sinkronisasi company dari SQL Server 2019</label><div class="grid three">';
    $html .= '<label>Mode Koneksi<select name="mode"><option value="bridge"' . ($mode === 'bridge' ? ' selected' : '') . '>Bridge API Windows (rekomendasi QNAP)</option><option value="direct"' . ($mode === 'direct' ? ' selected' : '') . '>Direct SQL Server (butuh pdo_sqlsrv)</option></select></label>';
    $html .= '<label>Bridge API URL<input name="bridge_url" value="' . e($config['bridge_url'] ?? '') . '" placeholder="http://IP-WINDOWS:8788"></label>';
    $html .= '<label>Bridge API Token<input type="password" name="bridge_token" placeholder="Kosongkan jika tidak diubah"></label></div>';
    $html .= '<h2>Credential SQL Server</h2><div class="grid three">';
    $html .= '<label>IP / Host SQL Server<input name="host" value="' . e($config['host']) . '" placeholder="192.168.1.107"></label>';
    $html .= '<label>Port<input name="port" value="' . e($config['port']) . '" placeholder="1433"></label>';
    $html .= '<label>Database<input name="database" value="' . e($config['database']) . '" placeholder="HRD"></label>';
    $html .= '<label>Username<input name="username" value="' . e($config['username']) . '" placeholder="sa"></label>';
    $html .= '<label>Password<input type="password" name="password" placeholder="Kosongkan jika tidak diubah"></label>';
    $html .= '<label>Limit Row<input type="number" min="1" max="20000" name="limit_rows" value="' . e($config['limit_rows']) . '"></label></div>';
    $html .= '<h2>Mapping Field Company</h2><div class="grid three">';
    $html .= '<label>Table / View<input name="table_name" value="' . e($config['table_name']) . '" placeholder="dbo.UnitUsaha"></label>';
    $html .= '<label>Field ID<input name="id_field" value="' . e($config['id_field']) . '" placeholder="id"></label>';
    $html .= '<label>Field Nama Unit Usaha<input name="name_field" value="' . e($config['name_field']) . '" placeholder="nama_unit_usaha"></label></div>';
    $html .= '<label><input type="checkbox" name="trust_server_certificate" value="1" ' . (($config['trust_server_certificate'] ?? '1') === '1' ? 'checked' : '') . ' style="width:auto"> Trust SQL Server Certificate</label>';
    $html .= '<p class="muted">Mode Bridge API Windows tidak membutuhkan <code>pdo_sqlsrv</code> di QNAP. Untuk sementara, gunakan import manual CSV/JSON bila belum memakai bridge/direct SQL.</p><div class="actions"><button class="btn primary" name="action" value="save">Simpan Setup</button><button class="btn" name="action" value="test">Simpan & Test Koneksi</button><button class="btn good" name="action" value="sync">Simpan & Sync Company</button></div>';
    $html .= '<hr style="border:0;border-top:1px solid #e2e8f0;margin:18px 0"><h2>Import Manual Company</h2><p class="muted">Upload CSV/JSON hasil export company. CSV minimal memiliki header sesuai mapping di atas, contoh <code>pt_id,pt_name</code>. JSON boleh array object atau format <code>{"data":[...]}</code>.</p><div class="grid two"><label>File CSV / JSON<input type="file" name="company_file" accept=".csv,.json,text/csv,application/json"></label><label>Contoh Format<textarea readonly style="min-height:86px">pt_id,pt_name&#10;01,SOA GROUP&#10;02,PT CONTOH</textarea></label></div><div class="actions"><button class="btn good" name="action" value="import_file">Import File Company</button></div></form></section>';
    try {
        $rows = $pdo->query('SELECT company_code, company_name, external_company_id FROM asset_companies WHERE is_active=1 ORDER BY company_name LIMIT 10')->fetchAll();
        if ($rows) {
            $html .= '<section class="panel"><h2>Preview Company Lokal</h2><table><tr><th>Kode</th><th>Nama Unit Usaha</th><th>ID SQL Server</th></tr>';
            foreach ($rows as $row) {
                $html .= '<tr><td>' . e($row['company_code'] ?? '') . '</td><td>' . e($row['company_name'] ?? '') . '</td><td>' . e($row['external_company_id'] ?? '') . '</td></tr>';
            }
            $html .= '</table></section>';
        }
    } catch (Throwable $ignored) {
    }
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
            $html .= '<input type="hidden" id="employeeNik" name="employee_nik" value="' . e($currentNik) . '"><label>Pengguna<input id="ownerNameInput" name="owner_name" value="' . e($currentOwner) . '" placeholder="Nama pengguna akan otomatis terisi dari karyawan portal" required></label>';
            $html .= '<script>(function(){function init(){var search=document.getElementById("employeePortalSearch"),list=document.getElementById("employeePortalList"),count=document.getElementById("employeePortalListCount"),nik=document.getElementById("employeeNik"),pengguna=document.getElementById("ownerNameInput"),endpoint=' . js_value(route_url('employee_search')) . ';if(!search||!list||!nik||!pengguna)return;var rows=' . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';function label(row){return (row.nik||"")+" - "+(row.name||"")+(row.department?" - "+row.department:"");}function setCount(){if(count){count.textContent="Menampilkan "+rows.length+" hasil. Pilih satu baris untuk mengisi Pengguna.";}}function render(newRows){rows=newRows||[];var selectedNik=nik.value;list.innerHTML="";rows.forEach(function(row,idx){var opt=document.createElement("option");opt.value=row.nik||"";opt.textContent=label(row);if(selectedNik&&selectedNik===opt.value){opt.selected=true;list.selectedIndex=idx;}list.appendChild(opt);});setCount();}function apply(){var row=rows.find(function(item){return item.nik===list.value;});if(row){nik.value=row.nik||"";pengguna.value=row.name||"";}}function localFilter(q){q=(q||"").toLowerCase();if(!q){render(rows);return;}var filtered=rows.filter(function(row){return label(row).toLowerCase().indexOf(q)!==-1;});render(filtered);}function load(q){fetch(endpoint+"&limit=200&q="+encodeURIComponent(q||""),{headers:{"Accept":"application/json"}}).then(function(r){return r.json();}).then(function(j){if(j&&j.ok){render(j.data);}}).catch(function(){localFilter(q);});}var timer=null;search.addEventListener("input",function(){localFilter(search.value);clearTimeout(timer);timer=setTimeout(function(){load(search.value);},250);});list.addEventListener("change",apply);list.addEventListener("dblclick",apply);setCount();apply();}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}})();</script>';
            return $html;
        }
    } catch (Throwable $e) {
        $html .= '<p class="muted">Data karyawan portal belum tersedia: ' . e($e->getMessage()) . '</p>';
    }
    $html .= '<label>NIK Karyawan<input name="employee_nik" value="' . e($currentNik) . '" placeholder="Opsional, isi manual jika cache karyawan belum aktif"></label>';
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
        return '<p class="muted">Cache Employee Portal belum tersedia. Jalankan Employee Portal &gt; Simpan & Sync Karyawan bila ingin memilih nama dari portal.</p>';
    }
    $searchId = $prefix . 'EmployeeSearch';
    $listId = $prefix . 'EmployeeSelect';
    $html = employee_picker_shell('Cari dan Pilih', $searchId, $listId, $rows);
    $html .= '<script>(function(){function init(){var search=document.getElementById("' . e($searchId) . '"),list=document.getElementById("' . e($listId) . '"),count=document.getElementById("' . e($listId) . 'Count"),nameInput=document.getElementById("' . e($nameInputId) . '"),usernameInput=' . ($usernameInputId !== '' ? 'document.getElementById("' . e($usernameInputId) . '")' : 'null') . ',endpoint=' . js_value(route_url('employee_search')) . ';if(!search||!list||!nameInput)return;var rows=' . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';function label(row){return (row.nik||"")+" - "+(row.name||"")+(row.department?" - "+row.department:"");}function setCount(){if(count){count.textContent="Menampilkan "+rows.length+" hasil. Pilih satu baris untuk mengisi Nama dan Username/NIK.";}}function render(newRows){rows=newRows||[];list.innerHTML="";rows.forEach(function(row){var opt=document.createElement("option");opt.value=row.nik||"";opt.textContent=label(row);list.appendChild(opt);});setCount();}function apply(){var row=rows.find(function(item){return item.nik===list.value;});if(row){nameInput.value=row.name||"";if(usernameInput&&row.nik){usernameInput.value=row.nik;}}}function localFilter(q){q=(q||"").toLowerCase();if(!q){render(rows);return;}var filtered=rows.filter(function(row){return label(row).toLowerCase().indexOf(q)!==-1;});render(filtered);}function load(q){fetch(endpoint+"&limit=200&q="+encodeURIComponent(q||""),{headers:{"Accept":"application/json"}}).then(function(r){return r.json();}).then(function(j){if(j&&j.ok){render(j.data);}}).catch(function(){localFilter(q);});}var timer=null;search.addEventListener("input",function(){localFilter(search.value);clearTimeout(timer);timer=setTimeout(function(){load(search.value);},250);});list.addEventListener("change",apply);list.addEventListener("dblclick",apply);setCount();}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}})();</script>';
    return $html;
}

function ensure_photo_challenge_schema(PDO $pdo): void
{
    try {
        if (db_table_exists($pdo, 'maintenance_schedules')) {
            if (!db_column_exists($pdo, 'maintenance_schedules', 'photo_challenge_code')) {
                $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN photo_challenge_code VARCHAR(12) NULL AFTER arrival_browser');
            }
            if (!db_column_exists($pdo, 'maintenance_schedules', 'photo_challenge_generated_at')) {
                $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN photo_challenge_generated_at DATETIME NULL AFTER photo_challenge_code');
            }
        }
        if (db_table_exists($pdo, 'maintenance_reports')) {
            if (!db_column_exists($pdo, 'maintenance_reports', 'process_photos')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN process_photos LONGTEXT NULL AFTER before_photos');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'photo_challenge_code')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN photo_challenge_code VARCHAR(12) NULL AFTER after_photos');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'photo_audit_meta')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN photo_audit_meta LONGTEXT NULL AFTER photo_challenge_code');
            }
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_job_estimate_schema(PDO $pdo): void
{
    try {
        if (db_table_exists($pdo, 'maintenance_jobs') && !db_column_exists($pdo, 'maintenance_jobs', 'estimated_minutes')) {
            $pdo->exec('ALTER TABLE maintenance_jobs ADD COLUMN estimated_minutes INT NOT NULL DEFAULT 5 AFTER description');
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_asset_management_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_code VARCHAR(40) NOT NULL UNIQUE,
            company_name VARCHAR(180) NOT NULL,
            legal_name VARCHAR(220) NULL,
            address TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            asset_code VARCHAR(80) NOT NULL UNIQUE,
            company_id INT NULL,
            asset_mode VARCHAR(20) NOT NULL DEFAULT 'standalone',
            asset_type VARCHAR(60) NOT NULL,
            asset_name VARCHAR(180) NOT NULL,
            brand VARCHAR(120) NULL,
            model VARCHAR(160) NULL,
            serial_number VARCHAR(160) NULL,
            manufacture_year INT NULL,
            warranty_until DATE NULL,
            installed_at DATE NULL,
            purchase_value DECIMAL(18,2) NOT NULL DEFAULT 0,
            current_value DECIMAL(18,2) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'active',
            location_label VARCHAR(180) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_asset_item_company (company_id),
            INDEX idx_asset_item_mode (asset_mode),
            INDEX idx_asset_item_type (asset_type),
            INDEX idx_asset_item_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_item_members (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            parent_asset_item_id BIGINT NOT NULL,
            child_asset_item_id BIGINT NOT NULL,
            role_name VARCHAR(80) NULL,
            attached_at DATE NOT NULL,
            detached_at DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_asset_item_member_parent (parent_asset_item_id),
            INDEX idx_asset_item_member_child (child_asset_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_bundles (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            maintenance_asset_code VARCHAR(80) NOT NULL UNIQUE,
            company_id INT NULL,
            bundle_type VARCHAR(80) NOT NULL,
            bundle_name VARCHAR(180) NOT NULL,
            employee_nik VARCHAR(80) NULL,
            owner_name VARCHAR(180) NULL,
            location_label VARCHAR(180) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            location_radius_m INT NOT NULL DEFAULT 5,
            status VARCHAR(40) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_asset_bundle_company (company_id),
            INDEX idx_asset_bundle_type (bundle_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_bundle_members (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            bundle_id BIGINT NOT NULL,
            asset_item_id BIGINT NOT NULL,
            role_name VARCHAR(80) NULL,
            attached_at DATE NOT NULL,
            detached_at DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bundle_member_bundle (bundle_id),
            INDEX idx_bundle_member_item (asset_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_movements (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            asset_item_id BIGINT NOT NULL,
            from_bundle_id BIGINT NULL,
            to_bundle_id BIGINT NULL,
            from_parent_asset_item_id BIGINT NULL,
            to_parent_asset_item_id BIGINT NULL,
            from_company_id INT NULL,
            to_company_id INT NULL,
            movement_date DATE NOT NULL,
            reason TEXT NULL,
            pic VARCHAR(160) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_movement_item (asset_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_repairs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            asset_item_id BIGINT NOT NULL,
            bundle_id BIGINT NULL,
            repair_date DATE NOT NULL,
            repair_location VARCHAR(180) NULL,
            repair_vendor VARCHAR(180) NULL,
            problem_description TEXT NULL,
            repair_action TEXT NULL,
            spare_part_replaced TEXT NULL,
            repair_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
            warranty_claim TINYINT(1) NOT NULL DEFAULT 0,
            technician_or_pic VARCHAR(160) NULL,
            attachment_path VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_repair_item (asset_item_id),
            INDEX idx_repair_bundle (bundle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_repair_parts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            repair_id BIGINT NOT NULL,
            part_name VARCHAR(180) NOT NULL,
            part_brand VARCHAR(120) NULL,
            part_serial VARCHAR(160) NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 1,
            unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
            old_part_condition VARCHAR(160) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_repair_part_repair (repair_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!db_column_exists($pdo, 'asset_companies', 'external_company_id')) {
            $pdo->exec('ALTER TABLE asset_companies ADD COLUMN external_company_id VARCHAR(80) NULL AFTER company_code');
        }
        if (!db_column_exists($pdo, 'asset_items', 'asset_mode')) {
            $pdo->exec("ALTER TABLE asset_items ADD COLUMN asset_mode VARCHAR(20) NOT NULL DEFAULT 'standalone' AFTER company_id");
        }
        if (!db_column_exists($pdo, 'asset_movements', 'from_parent_asset_item_id')) {
            $pdo->exec('ALTER TABLE asset_movements ADD COLUMN from_parent_asset_item_id BIGINT NULL AFTER to_bundle_id');
        }
        if (!db_column_exists($pdo, 'asset_movements', 'to_parent_asset_item_id')) {
            $pdo->exec('ALTER TABLE asset_movements ADD COLUMN to_parent_asset_item_id BIGINT NULL AFTER from_parent_asset_item_id');
        }
        if (!db_column_exists($pdo, 'pcs', 'asset_item_id')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN asset_item_id BIGINT NULL AFTER computer_name');
        }
        if (!db_column_exists($pdo, 'pcs', 'asset_bundle_id')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN asset_bundle_id BIGINT NULL AFTER asset_item_id');
        }
        try { $pdo->exec('CREATE INDEX idx_asset_company_external ON asset_companies (external_company_id)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_asset_item_mode ON asset_items (asset_mode)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_asset_movement_from_parent ON asset_movements (from_parent_asset_item_id)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_asset_movement_to_parent ON asset_movements (to_parent_asset_item_id)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_pcs_asset_item ON pcs (asset_item_id)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_pcs_asset_bundle ON pcs (asset_bundle_id)'); } catch (Throwable $ignored) {}
    } catch (Throwable $ignored) {
    }
}

function ensure_maintenance_asset_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_assets (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            maintenance_asset_code VARCHAR(80) NOT NULL UNIQUE,
            security_code VARCHAR(12) NOT NULL,
            maintenance_type VARCHAR(60) NOT NULL DEFAULT 'pc_set',
            name VARCHAR(180) NOT NULL,
            pc_id VARCHAR(60) NULL,
            printer_id VARCHAR(60) NULL,
            company_id INT NULL,
            employee_nik VARCHAR(80) NULL,
            owner_name VARCHAR(180) NULL,
            location_label VARCHAR(180) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            location_radius_m INT NOT NULL DEFAULT 5,
            status VARCHAR(40) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_maintenance_asset_pc (pc_id),
            INDEX idx_maintenance_asset_printer (printer_id),
            INDEX idx_maintenance_asset_type (maintenance_type),
            INDEX idx_maintenance_asset_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_asset_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            maintenance_asset_id BIGINT NOT NULL,
            asset_item_id BIGINT NOT NULL,
            role_name VARCHAR(80) NULL,
            attached_at DATE NOT NULL,
            detached_at DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_maint_asset_item_asset (maintenance_asset_id),
            INDEX idx_maint_asset_item_item (asset_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!db_column_exists($pdo, 'pcs', 'maintenance_asset_id')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN maintenance_asset_id BIGINT NULL AFTER asset_bundle_id');
        }
        if (db_table_exists($pdo, 'printers') && !db_column_exists($pdo, 'printers', 'maintenance_asset_id')) {
            $pdo->exec('ALTER TABLE printers ADD COLUMN maintenance_asset_id BIGINT NULL AFTER model_printer');
        }
        if (db_table_exists($pdo, 'printers') && !db_column_exists($pdo, 'printers', 'asset_item_id')) {
            $pdo->exec('ALTER TABLE printers ADD COLUMN asset_item_id BIGINT NULL AFTER maintenance_asset_id');
        }
        if (!db_column_exists($pdo, 'maintenance_schedules', 'maintenance_asset_id')) {
            $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN maintenance_asset_id BIGINT NULL AFTER asset_type');
        }
        try { $pdo->exec('CREATE INDEX idx_pcs_maintenance_asset ON pcs (maintenance_asset_id)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_printers_maintenance_asset ON printers (maintenance_asset_id)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_printers_asset_item ON printers (asset_item_id)'); } catch (Throwable $ignored) {}
        try { $pdo->exec('CREATE INDEX idx_schedule_maintenance_asset ON maintenance_schedules (maintenance_asset_id)'); } catch (Throwable $ignored) {}
        migrate_existing_maintenance_assets($pdo);
    } catch (Throwable $ignored) {
    }
}

function migrate_existing_maintenance_assets(PDO $pdo): void
{
    foreach ($pdo->query('SELECT * FROM pcs WHERE maintenance_asset_id IS NULL OR maintenance_asset_id=0')->fetchAll() as $pc) {
        ensure_pc_maintenance_asset($pdo, $pc);
    }
    if (db_table_exists($pdo, 'printers') && db_column_exists($pdo, 'printers', 'maintenance_asset_id')) {
        foreach ($pdo->query('SELECT * FROM printers WHERE maintenance_asset_id IS NULL OR maintenance_asset_id=0')->fetchAll() as $printer) {
            ensure_printer_maintenance_asset($pdo, $printer);
        }
    }
    if (db_column_exists($pdo, 'maintenance_schedules', 'maintenance_asset_id')) {
        $pdo->exec("UPDATE maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id SET s.maintenance_asset_id=p.maintenance_asset_id WHERE s.maintenance_asset_id IS NULL AND s.pc_id IS NOT NULL AND p.maintenance_asset_id IS NOT NULL");
        if (db_table_exists($pdo, 'printers') && db_column_exists($pdo, 'printers', 'maintenance_asset_id')) {
            $pdo->exec("UPDATE maintenance_schedules s JOIN printers pr ON pr.prn_id=s.printer_id SET s.maintenance_asset_id=pr.maintenance_asset_id WHERE s.maintenance_asset_id IS NULL AND s.printer_id IS NOT NULL AND pr.maintenance_asset_id IS NOT NULL");
        }
    }
}

function maintenance_asset_code_seed(string $prefix, string $assetId): string
{
    $assetId = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', $assetId));
    return $prefix . '-' . $assetId;
}

function unique_maintenance_asset_code(PDO $pdo, string $base, ?int $ignoreId = null): string
{
    $code = strtoupper(trim($base));
    $try = $code;
    $n = 1;
    do {
        $sql = 'SELECT id FROM maintenance_assets WHERE maintenance_asset_code=?';
        $params = [$try];
        if ($ignoreId) {
            $sql .= ' AND id<>?';
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

function ensure_pc_maintenance_asset(PDO $pdo, array $pc): ?array
{
    $pcId = (string)($pc['pc_id'] ?? '');
    if ($pcId === '') {
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
        $name = trim((string)($pc['owner_name'] ?? '') . ' - ' . (string)($pc['computer_name'] ?? ''), ' -');
        if ($name === '') {
            $name = $pcId;
        }
        $code = unique_maintenance_asset_code($pdo, maintenance_asset_code_seed('MNT', $pcId));
        $pdo->prepare('INSERT INTO maintenance_assets (maintenance_asset_code, security_code, maintenance_type, name, pc_id, company_id, employee_nik, owner_name, location_label, latitude, longitude, location_radius_m, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $code,
            (string)($pc['security_code'] ?? pc_security_code_random()),
            'pc_set',
            $name,
            $pcId,
            null,
            $pc['employee_nik'] ?? null,
            $pc['owner_name'] ?? null,
            $pc['location_label'] ?? null,
            $pc['latitude'] ?? null,
            $pc['longitude'] ?? null,
            max(1, (int)($pc['location_radius_m'] ?? 5)),
            'active',
            'Auto dibuat dari data PC lama.',
        ]);
        $rowId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
    }
    if ($row) {
        $pdo->prepare('UPDATE pcs SET maintenance_asset_id=? WHERE pc_id=?')->execute([(int)$row['id'], $pcId]);
        if (!empty($pc['asset_item_id'])) {
            link_maintenance_asset_item($pdo, (int)$row['id'], (int)$pc['asset_item_id'], 'PC / Asset Item');
        }
    }
    return $row ?: null;
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
            'Auto dibuat dari data printer lama.',
        ]);
        $rowId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE id=?');
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
    }
    if ($row) {
        $pdo->prepare('UPDATE printers SET maintenance_asset_id=? WHERE prn_id=?')->execute([(int)$row['id'], $prnId]);
        if (!empty($printer['asset_item_id'])) {
            link_maintenance_asset_item($pdo, (int)$row['id'], (int)$printer['asset_item_id'], 'Printer / Asset Item');
        }
    }
    return $row ?: null;
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

function sync_pc_maintenance_asset(PDO $pdo, string $pcId): void
{
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch();
    if (!$pc) {
        return;
    }
    $asset = ensure_pc_maintenance_asset($pdo, $pc);
    if (!$asset) {
        return;
    }
    $name = trim((string)($pc['owner_name'] ?? '') . ' - ' . (string)($pc['computer_name'] ?? ''), ' -');
    if ($name === '') {
        $name = $pcId;
    }
    $pdo->prepare('UPDATE maintenance_assets SET security_code=?, name=?, employee_nik=?, owner_name=?, location_label=?, latitude=?, longitude=?, location_radius_m=? WHERE id=?')->execute([
        (string)$pc['security_code'],
        $name,
        $pc['employee_nik'] ?? null,
        $pc['owner_name'] ?? null,
        $pc['location_label'] ?? null,
        $pc['latitude'] ?? null,
        $pc['longitude'] ?? null,
        max(1, (int)($pc['location_radius_m'] ?? 5)),
        (int)$asset['id'],
    ]);
    if (!empty($pc['asset_item_id'])) {
        link_maintenance_asset_item($pdo, (int)$asset['id'], (int)$pc['asset_item_id'], 'PC / Asset Item');
    }
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
    if (!empty($printer['asset_item_id'])) {
        link_maintenance_asset_item($pdo, (int)$asset['id'], (int)$printer['asset_item_id'], 'Printer / Asset Item');
    }
}

function maintenance_asset_qr_code(array $asset): string
{
    return (string)$asset['maintenance_asset_code'] . '-' . (string)$asset['security_code'];
}

function printer_asset_code(array $printer): string
{
    $maintenanceCode = trim((string)($printer['maintenance_asset_code'] ?? ''));
    if ($maintenanceCode !== '') {
        return $maintenanceCode . '-' . (string)$printer['security_code'];
    }
    return (string)$printer['prn_id'] . '-' . (string)$printer['security_code'];
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

function normalize_date_input(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : '';
}

function normalize_decimal_input(string $value): ?float
{
    $value = trim(str_replace(',', '.', $value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function geo_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
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

function delete_maintenance_schedule_rows(PDO $pdo, int $scheduleId): void
{
    if (db_table_exists($pdo, 'maintenance_reports')) {
        $pdo->prepare('DELETE FROM maintenance_reports WHERE schedule_id=?')->execute([$scheduleId]);
    }
    if (db_table_exists($pdo, 'schedule_jobs')) {
        $pdo->prepare('DELETE FROM schedule_jobs WHERE schedule_id=?')->execute([$scheduleId]);
    }
    if (db_table_exists($pdo, 'maintenance_timeline')) {
        $pdo->prepare('DELETE FROM maintenance_timeline WHERE schedule_id=?')->execute([$scheduleId]);
    }
    $pdo->prepare('DELETE FROM maintenance_schedules WHERE id=?')->execute([$scheduleId]);
}

function delete_maintenance_schedule(PDO $pdo, int $scheduleId): void
{
    if ($scheduleId <= 0) {
        return;
    }
    $pdo->beginTransaction();
    try {
        delete_maintenance_schedule_rows($pdo, $scheduleId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_user_account(PDO $pdo, int $userId): void
{
    $target = load_user_for_admin($pdo, $userId);
    if (!$target) {
        return;
    }
    $pdo->beginTransaction();
    try {
        if ($target['role'] === 'technician') {
            $stmt = $pdo->prepare('SELECT id FROM maintenance_schedules WHERE technician_id=?');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $scheduleId) {
                delete_maintenance_schedule_rows($pdo, (int)$scheduleId);
            }
            if (db_table_exists($pdo, 'maintenance_reports')) {
                $pdo->prepare('DELETE FROM maintenance_reports WHERE technician_id=?')->execute([$userId]);
            }
        }
        if (db_table_exists($pdo, 'mobile_api_tokens')) {
            $pdo->prepare('DELETE FROM mobile_api_tokens WHERE user_id=?')->execute([$userId]);
        }
        if (db_table_exists($pdo, 'maintenance_timeline')) {
            $pdo->prepare('UPDATE maintenance_timeline SET actor_user_id=NULL WHERE actor_user_id=?')->execute([$userId]);
        }
        $pdo->prepare('UPDATE maintenance_schedules SET unlocked_by=NULL WHERE unlocked_by=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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

function enforce_idle_logout(string $route): void
{
    $route = (string)$route;
    if (substr($route, 0, 4) === 'api_' || in_array($route, ['login', 'logout'], true) || empty($_SESSION['user_id'])) {
        return;
    }
    $timeoutSeconds = 3600;
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? time());
    if ((time() - $lastActivity) > $timeoutSeconds) {
        unset($_SESSION['user_id'], $_SESSION['last_activity_at'], $_SESSION['csrf'], $_SESSION['pending_mobile_code']);
        flash('Sesi login otomatis berakhir karena tidak ada aktivitas selama 1 jam. Silakan login ulang.', 'err');
        redirect_to('login');
    }
    $_SESSION['last_activity_at'] = time();
}

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

function parse_schedule_asset(string $value): array
{
    $value = trim($value);
    if (substr($value, 0, 8) === 'printer:') {
        return ['printer', strtoupper(substr($value, 8))];
    }
    if (substr($value, 0, 3) === 'pc:') {
        return ['pc', strtoupper(substr($value, 3))];
    }
    $assetId = strtoupper($value);
    return [substr($assetId, 0, 3) === 'PRN' ? 'printer' : 'pc', $assetId];
}

function maintenance_asset_id_for_schedule_asset(PDO $pdo, string $assetType, string $assetId): ?int
{
    if (!db_table_exists($pdo, 'maintenance_assets')) {
        return null;
    }
    if ($assetType === 'printer') {
        $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
        $stmt->execute([$assetId]);
        $printer = $stmt->fetch();
        $asset = $printer ? ensure_printer_maintenance_asset($pdo, $printer) : null;
        return $asset ? (int)$asset['id'] : null;
    }
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
    $stmt->execute([$assetId]);
    $pc = $stmt->fetch();
    $asset = $pc ? ensure_pc_maintenance_asset($pdo, $pc) : null;
    return $asset ? (int)$asset['id'] : null;
}

function find_asset_by_code(PDO $pdo, string $code): array
{
    [$assetId, $security] = parse_asset_code($code);
    if ($assetId === '') {
        return ['', '', []];
    }
    if (db_table_exists($pdo, 'maintenance_assets')) {
        $stmt = $pdo->prepare('SELECT * FROM maintenance_assets WHERE maintenance_asset_code=? AND status<>"inactive" LIMIT 1');
        $stmt->execute([$assetId]);
        $maintenanceAsset = $stmt->fetch();
        if ($maintenanceAsset && hash_equals((string)$maintenanceAsset['security_code'], $security)) {
            if (!empty($maintenanceAsset['printer_id'])) {
                $stmt = $pdo->prepare('SELECT pr.*, ma.maintenance_asset_code, ma.id maintenance_asset_id FROM printers pr JOIN maintenance_assets ma ON ma.printer_id=pr.prn_id WHERE pr.prn_id=? LIMIT 1');
                $stmt->execute([(string)$maintenanceAsset['printer_id']]);
                $printer = $stmt->fetch();
                return $printer ? ['printer', (string)$printer['prn_id'], $printer] : ['', '', []];
            }
            if (!empty($maintenanceAsset['pc_id'])) {
                $stmt = $pdo->prepare('SELECT p.*, ma.maintenance_asset_code, ma.id maintenance_asset_id FROM pcs p JOIN maintenance_assets ma ON ma.pc_id=p.pc_id WHERE p.pc_id=? LIMIT 1');
                $stmt->execute([(string)$maintenanceAsset['pc_id']]);
                $pc = $stmt->fetch();
                return $pc ? ['pc', (string)$pc['pc_id'], $pc] : ['', '', []];
            }
            return ['maintenance_asset', (string)$maintenanceAsset['id'], $maintenanceAsset];
        }
    }
    if (substr($assetId, 0, 3) === 'PRN') {
        $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
        $stmt->execute([$assetId]);
        $printer = $stmt->fetch();
        if ($printer && hash_equals((string)$printer['security_code'], $security)) {
            return ['printer', $assetId, $printer];
        }
        return ['', '', []];
    }
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
    $stmt->execute([$assetId]);
    $pc = $stmt->fetch();
    if ($pc && hash_equals((string)$pc['security_code'], $security)) {
        return ['pc', $assetId, $pc];
    }
    return ['', '', []];
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

function pc_form_fallback_html(array $pc, bool $editing, string $error): string
{
    $title = $editing ? 'Edit PC' : 'Tambah PC Baru';
    $html = '<section class="panel"><div class="flash err">Form utama gagal dibuka: ' . e($error) . '</div><h1>' . e($title) . '</h1><p class="muted">Form aman ini tetap bisa dipakai untuk tambah/edit PC. PcID dan Secret QR dibuat otomatis saat tambah PC.</p></section>';
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

function pc_asset_link_form_html(array $pc): string
{
    try {
        $pdo = Database::pdo();
        ensure_asset_management_schema($pdo);
        $itemSelected = (int)($pc['asset_item_id'] ?? 0) ?: null;
        $html = '<section class="panel"><h2>Asset Management Link</h2><p class="muted">Pilih satu <strong>Asset Item Kode</strong> untuk PC ini. Untuk notebook/PC all-in-one pilih Asset Mandiri. Untuk CPU + monitor terpisah, buat Asset Item dengan mode <strong>Asset Gabungan</strong>, lalu isi anggotanya dari halaman Asset Item tersebut.</p>';
        $html .= '<label>Asset Item Kode<select name="asset_item_id">' . asset_item_options($pdo, $itemSelected) . '</select></label>';
        $html .= '<div class="actions"><a class="btn" href="' . route_url('asset_item_form') . '">Tambah Asset Item</a><a class="btn" href="' . route_url('asset_items') . '">Kelola Asset Items</a></div></section>';
        return $html;
    } catch (Throwable $e) {
        return '<section class="panel"><h2>Asset Management Link</h2><div class="flash err">Pilihan aset belum siap: ' . e($e->getMessage()) . '</div></section>';
    }
}

function pc_form_html(array $pc, bool $editing): string
{
    $assetCode = '';
    if ($editing && !empty($pc['pc_id']) && !empty($pc['security_code'])) {
        $assetCode = asset_code($pc);
    }
    $pcTitle = $editing ? (string)$pc['pc_id'] : 'PC Baru';
    $pcSubtitle = trim((string)($pc['owner_name'] ?? '') . ' - ' . (string)($pc['computer_name'] ?? ''), ' -');
    if ($pcSubtitle === '') {
        $pcSubtitle = $editing ? 'Identitas PC' : 'PcID, Secret QR, dan Computer Name dibuat otomatis.';
    }
    $html = '<style>.pc-form textarea{min-height:112px;font-family:Consolas,monospace;font-size:13px}.pc-form input,.pc-form textarea{font-size:14px}.pc-form .identity-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.pc-form .location-grid{display:grid;grid-template-columns:minmax(220px,1.2fr) repeat(3,minmax(120px,.55fr));gap:14px}.pc-form .analysis-grid-edit{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.pc-form .analysis-grid-edit details{border:1px solid #dfe5ee;border-radius:8px;padding:12px;background:#fff}.pc-form .analysis-grid-edit summary{font-weight:700;cursor:pointer}.pc-form .analysis-grid-edit details.wide{grid-column:1/-1}.pc-form .gps-status{border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;padding:10px;margin-top:12px}.pc-form .coord-help{font-size:12px;color:#475569}.pc-form .footer-actions{position:sticky;bottom:0;background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:12px;margin-top:16px;box-shadow:0 -8px 22px rgba(15,23,42,.06)}@media(max-width:980px){.pc-form .identity-grid,.pc-form .location-grid,.pc-form .analysis-grid-edit{grid-template-columns:1fr}.pc-form .analysis-grid-edit details.wide{grid-column:auto}.pc-form .footer-actions{position:static}}</style>';
    $html .= '<form class="pc-form" method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $html .= '<section class="panel"><div class="split"><div><h1>' . ($editing ? 'Edit ' . e($pcTitle) : 'Tambah PC Baru') . '</h1><p>' . e($pcSubtitle) . '</p>' . ($assetCode !== '' ? '<p><span class="badge">Maintenance Asset ID ' . e($assetCode) . '</span></p>' : '') . '</div><div class="actions"><a class="btn" href="' . route_url('pcs') . '">Kembali ke Data PC</a>';
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
    $html .= '<section class="form-section"><h2>Kondisi Fisik</h2><textarea name="physical_condition" placeholder="Catatan kondisi awal printer">' . e($printer['physical_condition']) . '</textarea></section></div><div>';
    $html .= saved_location_datalist_html();
    $html .= '<section class="form-section"><div class="split"><div><h2>Titik Lokasi Printer</h2><p class="muted">Koordinat ini menjadi patokan scan QR teknisi. Jika latitude dan longitude diisi, scan hanya valid dalam radius yang diset, default 5 meter. Nama titik lokasi yang pernah disimpan bisa dipilih ulang dari list.</p></div><div class="actions">' . ($editing ? '<a class="btn" href="' . route_url('printer_location', ['prn_id' => $printer['prn_id']]) . '">Set dari HP</a>' : '') . '<a class="btn" target="_blank" rel="noopener" href="' . e($mapHref) . '">Google Maps</a></div></div><div class="location-grid"><label>Group / Nama Titik Lokasi<input name="location" list="savedLocationGroups" value="' . e($printer['location']) . '" placeholder="Contoh: Lantai 2 - Ruang Finance"></label><label>Radius Meter<input name="location_radius_m" type="number" min="1" max="100" value="' . e($printer['location_radius_m'] ?? 5) . '"></label><label>Latitude<input id="printerLatitude" name="latitude" value="' . e($lat) . '" placeholder="-6.2000000"></label><label>Longitude<input id="printerLongitude" name="longitude" value="' . e($lng) . '" placeholder="106.8166660"></label></div><div class="gps-status"><div class="actions"><button class="btn primary" type="button" id="usePrinterLocation">Ambil GPS Perangkat Ini</button></div><p class="coord-help">Untuk akurasi terbaik, buka dari ponsel saat berdiri di titik printer lalu tekan tombol GPS.</p><p id="printerLocationStatus" class="muted"></p></div></section></div></div>';
    $html .= '<section class="footer-actions"><div class="actions"><button class="btn primary">' . ($editing ? 'Simpan Perubahan' : 'Tambah Printer') . '</button><a class="btn" href="' . route_url('printers') . '">Batal</a></div></section></form>';
    $html .= '<script>(function(){var btn=document.getElementById("usePrinterLocation"),lat=document.getElementById("printerLatitude"),lng=document.getElementById("printerLongitude"),status=document.getElementById("printerLocationStatus");if(!btn)return;btn.addEventListener("click",function(){if(!navigator.geolocation){status.textContent="Browser tidak mendukung GPS.";return;}status.textContent="Mengambil lokasi...";navigator.geolocation.getCurrentPosition(function(p){lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);status.textContent="Lokasi tersimpan di form. Akurasi perangkat sekitar "+Math.round(p.coords.accuracy)+" meter.";},function(){status.textContent="Gagal mengambil lokasi. Pastikan izin Location aktif atau isi manual dari Google Maps.";},{enableHighAccuracy:true,timeout:15000,maximumAge:0});});})();</script>';
    return $html;
}

function maintenance_report_summary(PDO $pdo, array $report): array
{
    $scheduleId = (int)($report['schedule_id'] ?? 0);
    static $hasScheduleJobNote = null;
    if ($hasScheduleJobNote === null) {
        try {
            $hasScheduleJobNote = (bool)$pdo->query("SHOW COLUMNS FROM schedule_jobs LIKE 'note'")->fetch();
        } catch (Throwable $e) {
            $hasScheduleJobNote = false;
        }
    }
    $noteSelect = $hasScheduleJobNote ? 'sj.note' : "'' note";
    $jobsStmt = $pdo->prepare("SELECT j.title, sj.is_done, sj.done_at, $noteSelect FROM schedule_jobs sj JOIN maintenance_jobs j ON j.id=sj.job_id WHERE sj.schedule_id=? ORDER BY j.title");
    $jobsStmt->execute([$scheduleId]);
    $checklistRows = [];
    $doneJobs = [];
    $pendingJobs = [];
    foreach ($jobsStmt as $job) {
        $checklistRows[] = [
            'title' => (string)$job['title'],
            'status' => ((int)$job['is_done'] === 1 ? 'Done' : 'Pending'),
            'done_at' => (string)($job['done_at'] ?: ''),
            'note' => trim((string)($job['note'] ?? '')),
        ];
        if ((int)$job['is_done'] === 1) {
            $doneJobs[] = (string)$job['title'];
        } else {
            $pendingJobs[] = (string)$job['title'];
        }
    }
    $timelineStmt = $pdo->prepare('SELECT event_type FROM maintenance_timeline WHERE schedule_id=?');
    $timelineStmt->execute([$scheduleId]);
    $events = $timelineStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $beforeCount = count(json_decode((string)($report['before_photos'] ?? '[]'), true) ?: []);
    $processCount = count(json_decode((string)($report['process_photos'] ?? '[]'), true) ?: []);
    $afterCount = count(json_decode((string)($report['after_photos'] ?? '[]'), true) ?: []);
    $total = count($doneJobs) + count($pendingJobs);
    $done = count($doneJobs);
    $scanStart = in_array('qr_start_scan', $events, true) ? 'Valid' : 'Belum tercatat';
    $scanEnd = in_array('qr_end_scan', $events, true) ? 'Valid' : 'Belum tercatat';
    $status = $total > 0 && $done === $total && $scanEnd === 'Valid' ? 'Selesai lengkap' : 'Perlu review';
    $photoEvidence = photo_evidence_audit_summary($pdo, $report);
    return [
        'summary_status' => $status,
        'checklist_rows' => $checklistRows,
        'job_progress' => $done . '/' . $total,
        'done_jobs' => $doneJobs ? implode(', ', $doneJobs) : '-',
        'pending_jobs' => $pendingJobs ? implode(', ', $pendingJobs) : '-',
        'photo_summary' => 'Before: ' . $beforeCount . ', Process: ' . $processCount . ', After: ' . $afterCount,
        'scan_summary' => 'Mulai: ' . $scanStart . ', Selesai: ' . $scanEnd,
        'photo_challenge_code' => trim((string)($report['photo_challenge_code'] ?? '')),
        'schedule_challenge_code' => trim((string)($report['schedule_challenge_code'] ?? '')),
        'challenge_audit_status' => $photoEvidence['status'],
        'challenge_audit_text' => $photoEvidence['text'],
        'challenge_audit_class' => $photoEvidence['class'],
        'challenge_ocr_text' => '',
        'photo_evidence_score' => $photoEvidence['score'],
        'photo_evidence_rows' => $photoEvidence['rows'],
        'condition' => (string)($report['condition_rating'] ?? '-'),
        'physical_condition' => trim((string)($report['physical_condition'] ?? '')),
        'notes' => trim((string)($report['additional_notes'] ?? '')),
    ];
}

function build_photo_audit_meta(PDO $pdo, int $scheduleId, array $uploadMeta, string $browserJson): array
{
    $browser = json_decode($browserJson, true);
    if (!is_array($browser)) {
        $browser = ['items' => []];
    }
    $items = is_array($browser['items'] ?? null) ? $browser['items'] : [];
    $used = [];
    foreach ($uploadMeta as $idx => $upload) {
        $field = (string)($upload['field'] ?? '');
        $group = str_replace('_photos', '', $field);
        foreach ($items as $itemIdx => $item) {
            if (isset($used[$itemIdx]) || (string)($item['group'] ?? '') !== $group) {
                continue;
            }
            $uploadMeta[$idx]['browser'] = $item;
            $used[$itemIdx] = true;
            break;
        }
    }
    return [
        'server_received_at' => date('c'),
        'browser_captured_at' => (string)($browser['captured_at'] ?? ''),
        'minimum_duration_minutes' => schedule_minimum_photo_minutes($pdo, $scheduleId),
        'duration_override_approved' => !empty($browser['duration_override_approved']),
        'asset' => photo_audit_asset_context($pdo, $scheduleId),
        'uploads' => $uploadMeta,
    ];
}

function photo_audit_asset_context(PDO $pdo, int $scheduleId): array
{
    $stmt = $pdo->prepare("SELECT s.asset_type, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.latitude,pr.latitude) latitude, COALESCE(p.longitude,pr.longitude) longitude, COALESCE(p.location_radius_m,pr.location_radius_m,5) radius_m FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.id=? LIMIT 1");
    $stmt->execute([$scheduleId]);
    $row = $stmt->fetch() ?: [];
    return [
        'asset_type' => (string)($row['asset_type'] ?? 'pc'),
        'asset_id' => (string)($row['asset_id'] ?? ''),
        'latitude' => $row['latitude'] !== null && $row['latitude'] !== '' ? (float)$row['latitude'] : null,
        'longitude' => $row['longitude'] !== null && $row['longitude'] !== '' ? (float)$row['longitude'] : null,
        'radius_m' => max(5.0, (float)($row['radius_m'] ?? 5)),
    ];
}

function photo_evidence_audit_summary(PDO $pdo, array $report): array
{
    $meta = json_decode((string)($report['photo_audit_meta'] ?? ''), true);
    if (!is_array($meta)) {
        $meta = ['uploads' => [], 'asset' => photo_audit_asset_context($pdo, (int)($report['schedule_id'] ?? 0))];
    }
    $uploads = is_array($meta['uploads'] ?? null) ? $meta['uploads'] : [];
    $asset = is_array($meta['asset'] ?? null) ? $meta['asset'] : photo_audit_asset_context($pdo, (int)($report['schedule_id'] ?? 0));
    $rows = [];
    $score = 100;
    $warn = function (string $title, string $detail, int $minus = 10) use (&$rows, &$score): void {
        $rows[] = ['status' => 'Warning', 'title' => $title, 'detail' => $detail];
        $score -= $minus;
    };
    $ok = function (string $title, string $detail) use (&$rows): void {
        $rows[] = ['status' => 'OK', 'title' => $title, 'detail' => $detail];
    };

    $before = array_values(array_filter($uploads, fn($u) => ($u['field'] ?? '') === 'before_photos'));
    $after = array_values(array_filter($uploads, fn($u) => ($u['field'] ?? '') === 'after_photos'));
    if (!$before || !$after) {
        $warn('Foto wajib', 'Foto sebelum atau sesudah belum lengkap.', 25);
    } else {
        $ok('Foto wajib', 'Foto sebelum dan sesudah tersedia.');
    }

    $browserItems = array_values(array_filter(array_map(fn($u) => $u['browser'] ?? null, $uploads)));
    if (!$browserItems) {
        $warn('Live camera evidence', 'Metadata browser foto belum tercatat. Pastikan halaman mobile sudah versi terbaru dan foto diambil dari kamera.', 15);
    } else {
        $notLive = array_filter($browserItems, fn($i) => empty($i['live_camera_hint']));
        $notLive ? $warn('Live camera evidence', 'Sebagian input foto tidak membawa tanda capture kamera.', 10) : $ok('Live camera evidence', 'Semua input foto memakai mode kamera langsung.');
    }

    photo_audit_sequence_check($browserItems, $ok, $warn);
    photo_audit_minimum_duration_check($meta, $browserItems, $ok, $warn);
    photo_audit_gps_check($browserItems, $asset, $ok, $warn);
    photo_audit_similarity_check($before[0]['url'] ?? '', $after[0]['url'] ?? '', $ok, $warn);

    $score = max(0, min(100, $score));
    $status = $score >= 80 ? 'OK' : ($score >= 60 ? 'Review' : 'Warning');
    $class = $status === 'OK' ? ' ok' : ' danger';
    return ['score' => $score, 'status' => $status, 'class' => $class, 'text' => 'Audit bukti foto ' . $score . '/100. Review warning yang muncul.', 'rows' => $rows];
}

function photo_evidence_location_error(array $meta): ?string
{
    $asset = is_array($meta['asset'] ?? null) ? $meta['asset'] : [];
    if (($asset['latitude'] ?? null) === null || ($asset['longitude'] ?? null) === null) {
        return null;
    }
    $radius = (float)($asset['radius_m'] ?? 5);
    $required = ['before' => false, 'after' => false];
    foreach (($meta['uploads'] ?? []) as $upload) {
        $browser = is_array($upload['browser'] ?? null) ? $upload['browser'] : [];
        $group = (string)($browser['group'] ?? str_replace('_photos', '', (string)($upload['field'] ?? '')));
        if (!array_key_exists($group, $required)) {
            continue;
        }
        $gps = is_array($browser['gps'] ?? null) ? $browser['gps'] : [];
        if (!isset($gps['lat'], $gps['lng'])) {
            return 'GPS foto ' . $group . ' tidak tercatat. Aktifkan izin lokasi, lalu ambil ulang foto dari kamera di dekat aset.';
        }
        $distance = geo_distance_m((float)$asset['latitude'], (float)$asset['longitude'], (float)$gps['lat'], (float)$gps['lng']);
        if ($distance > $radius) {
            return 'Foto ' . $group . ' diambil sekitar ' . round($distance, 1) . ' m dari titik aset. Maksimal radius ' . round($radius, 1) . ' m.';
        }
        if (($gps['accuracy_m'] ?? 999) > 100) {
            return 'Akurasi GPS foto ' . $group . ' terlalu rendah (' . round((float)$gps['accuracy_m'], 1) . ' m). Tunggu GPS stabil lalu ambil ulang foto.';
        }
        $required[$group] = true;
    }
    foreach ($required as $group => $ok) {
        if (!$ok) {
            return 'Metadata GPS foto ' . $group . ' belum lengkap. Ambil ulang foto dari kamera dan izinkan lokasi.';
        }
    }
    return null;
}

function photo_evidence_live_camera_error(array $meta): ?string
{
    $required = ['before' => false, 'after' => false];
    foreach (($meta['uploads'] ?? []) as $upload) {
        $browser = is_array($upload['browser'] ?? null) ? $upload['browser'] : [];
        $group = (string)($browser['group'] ?? str_replace('_photos', '', (string)($upload['field'] ?? '')));
        if (!array_key_exists($group, $required)) {
            continue;
        }
        if (empty($browser['live_camera_hint'])) {
            return 'Foto ' . $group . ' wajib dari kamera langsung. Ambil ulang dari kamera, bukan galeri/file lama.';
        }
        $selected = strtotime((string)($browser['selected_at'] ?? ''));
        $modified = strtotime((string)($browser['file_last_modified'] ?? ''));
        if ($selected <= 0 || $modified <= 0 || abs($selected - $modified) > 600) {
            return 'Foto ' . $group . ' terindikasi bukan foto baru dari kamera live. Ambil ulang foto langsung di lokasi aset.';
        }
        $required[$group] = true;
    }
    foreach ($required as $group => $ok) {
        if (!$ok) {
            return 'Metadata live camera foto ' . $group . ' belum lengkap. Ambil ulang foto dari kamera.';
        }
    }
    return null;
}

function schedule_minimum_photo_minutes(PDO $pdo, int $scheduleId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(1, COALESCE(j.estimated_minutes, 5))), 0) FROM schedule_jobs sj JOIN maintenance_jobs j ON j.id=sj.job_id WHERE sj.schedule_id=?');
        $stmt->execute([$scheduleId]);
        return max(5, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        return 5;
    }
}

function photo_browser_group_times(array $items): array
{
    $times = [];
    foreach ($items as $item) {
        $group = (string)($item['group'] ?? '');
        $time = strtotime((string)($item['selected_at'] ?? ''));
        if ($group !== '' && $time > 0) {
            $times[$group] = isset($times[$group]) ? min($times[$group], $time) : $time;
        }
    }
    return $times;
}

function photo_evidence_duration_error(array $meta): ?string
{
    if (!empty($meta['duration_override_approved'])) {
        return null;
    }
    $items = [];
    foreach (($meta['uploads'] ?? []) as $upload) {
        if (is_array($upload['browser'] ?? null)) {
            $items[] = $upload['browser'];
        }
    }
    $times = photo_browser_group_times($items);
    if (empty($times['before']) || empty($times['after'])) {
        return 'Waktu foto sebelum/sesudah belum lengkap. Ambil ulang foto dari kamera live.';
    }
    if ($times['before'] > $times['after']) {
        return 'Urutan foto tidak valid. Foto sesudah tercatat sebelum foto sebelum.';
    }
    $durationSeconds = $times['after'] - $times['before'];
    $minimumMinutes = max(5, (int)($meta['minimum_duration_minutes'] ?? 5));
    if ($durationSeconds < ($minimumMinutes * 60)) {
        return 'Durasi foto sebelum ke sesudah baru ' . round($durationSeconds / 60, 1) . ' menit. Minimum pekerjaan berdasarkan jobdesk adalah ' . $minimumMinutes . ' menit. Jika memang valid, centang persetujuan warning lalu simpan ulang.';
    }
    return null;
}

function photo_audit_sequence_check(array $items, callable $ok, callable $warn): void
{
    $times = photo_browser_group_times($items);
    if (empty($times['before']) || empty($times['after'])) {
        $warn('Sequence timing', 'Waktu foto before/after dari browser tidak lengkap. Bobot audit bagian ini 25 poin.', 25);
        return;
    }
    if ($times['before'] > $times['after']) {
        $warn('Sequence timing', 'Foto sesudah tercatat sebelum foto sebelum. Bobot audit bagian ini 25 poin.', 25);
        return;
    }
    $duration = $times['after'] - $times['before'];
    if ($duration < 300) {
        $warn('Sequence timing', 'Jarak waktu before ke after hanya ' . round($duration / 60, 1) . ' menit, kurang dari batas dasar 5 menit. Bobot audit bagian ini 25 poin.', 25);
    } else {
        $ok('Sequence timing', 'Urutan foto valid, durasi before ke after ' . round($duration / 60, 1) . ' menit. Bobot audit bagian ini 25 poin.');
    }
}

function photo_audit_minimum_duration_check(array $meta, array $items, callable $ok, callable $warn): void
{
    $times = photo_browser_group_times($items);
    $minimumMinutes = max(5, (int)($meta['minimum_duration_minutes'] ?? 5));
    if (empty($times['before']) || empty($times['after'])) {
        $warn('Durasi minimum jobdesk', 'Waktu foto tidak lengkap sehingga durasi minimum tidak bisa dihitung. Bobot audit bagian ini 25 poin.', 25);
        return;
    }
    $durationMinutes = max(0, ($times['after'] - $times['before']) / 60);
    if ($durationMinutes < $minimumMinutes) {
        if (!empty($meta['duration_override_approved'])) {
            $warn('Durasi minimum jobdesk', 'Durasi ' . round($durationMinutes, 1) . ' menit lebih pendek dari minimum ' . $minimumMinutes . ' menit, tetapi teknisi menyetujui warning. Bobot penuh 25 poin, override memotong 12 poin.', 12);
        } else {
            $warn('Durasi minimum jobdesk', 'Durasi ' . round($durationMinutes, 1) . ' menit lebih pendek dari minimum ' . $minimumMinutes . ' menit. Bobot audit bagian ini 25 poin.', 25);
        }
        return;
    }
    $ok('Durasi minimum jobdesk', 'Durasi foto ' . round($durationMinutes, 1) . ' menit memenuhi minimum jobdesk ' . $minimumMinutes . ' menit. Bobot audit bagian ini 25 poin.');
}

function photo_audit_gps_check(array $items, array $asset, callable $ok, callable $warn): void
{
    if (($asset['latitude'] ?? null) === null || ($asset['longitude'] ?? null) === null) {
        $warn('GPS foto', 'Titik lokasi aset belum diisi, jarak foto tidak bisa divalidasi.', 10);
        return;
    }
    $distances = [];
    foreach ($items as $item) {
        $gps = $item['gps'] ?? null;
        if (!is_array($gps) || !isset($gps['lat'], $gps['lng'])) {
            continue;
        }
        $distance = geo_distance_m((float)$asset['latitude'], (float)$asset['longitude'], (float)$gps['lat'], (float)$gps['lng']);
        $distances[] = $distance;
        if (($gps['accuracy_m'] ?? 999) > 50) {
            $warn('GPS foto', 'Akurasi GPS foto sekitar ' . round((float)$gps['accuracy_m'], 1) . ' m, kurang presisi.', 5);
        }
    }
    if (!$distances) {
        $warn('GPS foto', 'GPS saat foto diambil tidak tercatat. Teknisi mungkin menolak izin lokasi atau browser tidak mendukung.', 20);
        return;
    }
    $maxDistance = max($distances);
    $radius = (float)($asset['radius_m'] ?? 5);
    if ($maxDistance > $radius) {
        $warn('GPS foto', 'Ada foto diambil ' . round($maxDistance, 1) . ' m dari titik aset. Radius patokan ' . round($radius, 1) . ' m.', 25);
    } else {
        $ok('GPS foto', 'Semua GPS foto berada dalam radius aset. Jarak terjauh ' . round($maxDistance, 1) . ' m.');
    }
}

function photo_audit_similarity_check(string $beforeUrl, string $afterUrl, callable $ok, callable $warn): void
{
    $beforePath = maintenance_photo_public_to_local($beforeUrl);
    $afterPath = maintenance_photo_public_to_local($afterUrl);
    if ($beforePath === null || $afterPath === null) {
        $warn('Before vs after', 'File foto before/after tidak ditemukan untuk dibandingkan.', 10);
        return;
    }
    $a = image_average_hash($beforePath);
    $b = image_average_hash($afterPath);
    if ($a === null || $b === null) {
        $warn('Before vs after', 'Server tidak bisa menghitung beda foto. Pastikan ekstensi GD aktif.', 5);
        return;
    }
    $distance = hash_hamming_distance($a, $b);
    if ($distance <= 4) {
        $warn('Before vs after', 'Foto before dan after terlalu mirip. Kemungkinan foto duplikat atau perubahan tidak terlihat.', 20);
    } else {
        $ok('Before vs after', 'Foto before dan after berbeda. Skor beda: ' . $distance . '/64.');
    }
}

function photo_challenge_audit_from_report(array $report, bool $completed = false): array
{
    $photos = [];
    foreach (['before_photos', 'process_photos', 'after_photos'] as $field) {
        $decoded = json_decode((string)($report[$field] ?? '[]'), true);
        if (is_array($decoded)) {
            $photos[] = $decoded;
        }
    }
    return photo_challenge_audit_from_photos((string)($report['schedule_challenge_code'] ?? ''), $photos, $completed);
}

function photo_challenge_manual_review_notice(string $scheduleCode, bool $completed = false): array
{
    $scheduleCode = preg_replace('/\D+/', '', trim($scheduleCode));
    if ($scheduleCode === '') {
        return ['status' => 'Warning', 'text' => $completed ? 'Kode challenge sistem kosong. Review foto manual.' : 'Belum ada kode challenge karena pekerjaan belum selesai atau data lama.', 'class' => ' danger', 'ocr_text' => ''];
    }
    return ['status' => 'Review', 'text' => 'Kode challenge sistem ' . $scheduleCode . '. Cocokkan manual dengan kertas challenge pada foto before/process/after.', 'class' => ' danger', 'ocr_text' => ''];
}

function photo_challenge_audit_from_photos(string $scheduleCode, array $photoGroups, bool $completed = false): array
{
    $scheduleCode = preg_replace('/\D+/', '', trim($scheduleCode));
    if ($scheduleCode === '') {
        return ['status' => 'Warning', 'text' => $completed ? 'Kode challenge sistem kosong. Review foto manual.' : 'Belum ada kode challenge karena pekerjaan belum selesai atau data lama.', 'class' => ' danger', 'ocr_text' => ''];
    }
    $photos = [];
    foreach ($photoGroups as $group) {
        if (is_array($group)) {
            foreach ($group as $photo) {
                $path = maintenance_photo_public_to_local((string)$photo);
                if ($path !== null) {
                    $photos[] = $path;
                }
            }
        }
    }
    if (!$photos) {
        return ['status' => 'Warning', 'text' => 'Foto challenge belum tersedia untuk dianalisa.', 'class' => ' danger', 'ocr_text' => ''];
    }
    $tesseract = find_tesseract_binary();
    if ($tesseract === null) {
        return ['status' => 'Warning', 'text' => 'OCR/AI offline belum aktif di server. Install Tesseract OCR di QNAP agar tulisan tangan pada foto bisa dianalisa otomatis.', 'class' => ' danger', 'ocr_text' => ''];
    }
    $seen = [];
    foreach ($photos as $path) {
        $text = ocr_digits_from_photo($tesseract, $path);
        if ($text !== '') {
            $seen[] = $text;
        }
        if (str_starts_with($text, 'OCR_ERROR:')) {
            continue;
        }
        $groups = [];
        preg_match_all('/\d+/', $text, $groups);
        $candidates = $groups[0] ?? [];
        $joinedDigits = preg_replace('/\D+/', '', $text);
        if ($joinedDigits !== '') {
            $candidates[] = $joinedDigits;
        }
        foreach (array_unique($candidates) as $digits) {
            if (hash_equals($scheduleCode, $digits)) {
                return ['status' => 'OK', 'text' => 'OCR offline menemukan kode challenge ' . $scheduleCode . ' pada foto.', 'class' => ' ok', 'ocr_text' => implode(' | ', $seen)];
            }
        }
    }
    $ocrText = trim(implode(' | ', array_filter($seen)));
    return ['status' => 'Warning', 'text' => $ocrText !== '' ? 'OCR offline belum menemukan kode ' . $scheduleCode . '. Angka terbaca: ' . $ocrText . '. Review foto manual.' : 'OCR offline tidak menemukan angka challenge yang jelas. Review foto manual.', 'class' => ' danger', 'ocr_text' => $ocrText];
}

function maintenance_photo_public_to_local(string $photo): ?string
{
    $photo = str_replace('\\', '/', trim($photo));
    if (!preg_match('#uploads/maintenance/([^/]+)$#', $photo, $m)) {
        return null;
    }
    $path = dirname(__DIR__) . '/uploads/maintenance/' . basename($m[1]);
    return is_file($path) ? $path : null;
}

function image_average_hash(string $path): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) < 100) {
        return null;
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return null;
    }
    $small = imagecreatetruecolor(8, 8);
    imagecopyresampled($small, $src, 0, 0, 0, 0, 8, 8, imagesx($src), imagesy($src));
    imagedestroy($src);
    $values = [];
    $total = 0;
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $gray = (int)(((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3);
            $values[] = $gray;
            $total += $gray;
        }
    }
    imagedestroy($small);
    $avg = $total / 64;
    $bits = '';
    foreach ($values as $value) {
        $bits .= $value >= $avg ? '1' : '0';
    }
    return $bits;
}

function hash_hamming_distance(string $a, string $b): int
{
    $max = min(strlen($a), strlen($b));
    $distance = abs(strlen($a) - strlen($b));
    for ($i = 0; $i < $max; $i++) {
        if ($a[$i] !== $b[$i]) {
            $distance++;
        }
    }
    return $distance;
}

function find_tesseract_binary(): ?string
{
    foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract', '/opt/bin/tesseract'] as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
        $found = trim((string)@shell_exec('command -v tesseract 2>/dev/null'));
        if ($found !== '') {
            return $found;
        }
    }
    return null;
}

function ocr_digits_from_photo(string $tesseract, string $path): string
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
        return '';
    }
    $images = array_merge([$path], ocr_preprocessed_images($path));
    $texts = [];
    $diagnostics = [];
    foreach ($images as $imagePath) {
        foreach ([6, 7, 8, 11, 13] as $psm) {
            $cmd = ocr_tesseract_env_prefix()
                . escapeshellcmd($tesseract) . ' '
                . escapeshellarg($imagePath)
                . ' stdout -l eng --psm ' . $psm
                . ' -c tessedit_char_whitelist=0123456789 2>&1';
            $raw = trim((string)@shell_exec($cmd));
            $digits = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^0-9\s]+/', ' ', $raw)));
            if ($digits !== '') {
                $texts[] = $digits;
            } elseif ($raw !== '' && stripos($raw, 'error') !== false) {
                $diagnostics[] = substr((string)preg_replace('/\s+/', ' ', $raw), 0, 180);
            }
        }
    }
    foreach (array_slice($images, 1) as $tmp) {
        @unlink($tmp);
    }
    if ($texts) {
        return implode(' | ', array_unique($texts));
    }
    return $diagnostics ? 'OCR_ERROR: ' . implode(' | ', array_unique($diagnostics)) : '';
}

function ocr_tesseract_env_prefix(): string
{
    foreach (['/opt/share/tessdata', '/usr/share/tessdata', '/usr/local/share/tessdata'] as $dir) {
        if (is_dir($dir)) {
            return 'TESSDATA_PREFIX=' . escapeshellarg($dir) . ' ';
        }
    }
    return 'TESSDATA_PREFIX=/opt/share/tessdata ';
}

function ocr_preprocessed_images(string $path): array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagefilter') || !function_exists('imagepng')) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) < 100) {
        return [];
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return [];
    }
    $width = imagesx($src);
    $height = imagesy($src);
    $scale = min(3.0, max(1.0, 2200 / max(1, max($width, $height))));
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($resized, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($src);

    $outputs = [];
    $gray = imagecreatetruecolor($newWidth, $newHeight);
    imagecopy($gray, $resized, 0, 0, 0, 0, $newWidth, $newHeight);
    imagefilter($gray, IMG_FILTER_GRAYSCALE);
    imagefilter($gray, IMG_FILTER_CONTRAST, -70);
    $grayPath = tempnam(sys_get_temp_dir(), 'pcconnect-ocr-gray-');
    if ($grayPath && imagepng($gray, $grayPath)) {
        $outputs[] = $grayPath;
    }

    $bw = imagecreatetruecolor($newWidth, $newHeight);
    imagecopy($bw, $gray, 0, 0, 0, 0, $newWidth, $newHeight);
    for ($y = 0; $y < $newHeight; $y++) {
        for ($x = 0; $x < $newWidth; $x++) {
            $rgb = imagecolorat($bw, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $avg = (int)(($r + $g + $b) / 3);
            $color = $avg < 150 ? imagecolorallocate($bw, 0, 0, 0) : imagecolorallocate($bw, 255, 255, 255);
            imagesetpixel($bw, $x, $y, $color);
        }
    }
    $bwPath = tempnam(sys_get_temp_dir(), 'pcconnect-ocr-bw-');
    if ($bwPath && imagepng($bw, $bwPath)) {
        $outputs[] = $bwPath;
    }
    imagedestroy($resized);
    imagedestroy($gray);
    imagedestroy($bw);
    return $outputs;
}

function photo_challenge_audit(string $scheduleCode, string $reportCode, bool $completed = false): array
{
    $scheduleCode = trim($scheduleCode);
    $reportCode = trim($reportCode);
    if ($scheduleCode !== '' && $reportCode !== '' && hash_equals($scheduleCode, $reportCode)) {
        return ['status' => 'OK', 'text' => 'Kode challenge schedule dan report cocok.', 'class' => ' ok'];
    }
    if ($scheduleCode === '' && $reportCode === '') {
        return [
            'status' => 'Warning',
            'text' => $completed ? 'Kode challenge belum tercatat. Review foto before/after secara manual.' : 'Belum ada kode challenge karena pekerjaan belum selesai atau data lama.',
            'class' => ' danger',
        ];
    }
    if ($scheduleCode === '') {
        return ['status' => 'Warning', 'text' => 'Kode schedule kosong, report mencatat kode ' . $reportCode . '. Review foto challenge manual.', 'class' => ' danger'];
    }
    if ($reportCode === '') {
        return ['status' => 'Warning', 'text' => 'Report belum mencatat kode challenge. Kode schedule: ' . $scheduleCode . '.', 'class' => ' danger'];
    }
    return ['status' => 'Warning', 'text' => 'Kode challenge tidak cocok. Schedule: ' . $scheduleCode . ', Report: ' . $reportCode . '.', 'class' => ' danger'];
}

function checklist_summary_text(array $summary): string
{
    $lines = [];
    foreach ($summary['checklist_rows'] ?? [] as $row) {
        $note = $row['note'] !== '' ? $row['note'] : '-';
        $doneAt = $row['done_at'] !== '' ? ' @ ' . $row['done_at'] : '';
        $lines[] = $row['title'] . ' | ' . $row['status'] . $doneAt . ' | Keterangan: ' . $note;
    }
    return $lines ? implode("\n", $lines) : '-';
}

function schedule_job_note_supported(PDO $pdo): bool
{
    static $supported = null;
    if ($supported === null) {
        try {
            $supported = (bool)$pdo->query("SHOW COLUMNS FROM schedule_jobs LIKE 'note'")->fetch();
        } catch (Throwable $e) {
            $supported = false;
        }
    }
    return $supported;
}

function save_schedule_job_notes(PDO $pdo, int $scheduleId, array $notes): void
{
    if (!schedule_job_note_supported($pdo)) {
        return;
    }
    foreach ($notes as $jobId => $note) {
        $pdo->prepare('UPDATE schedule_jobs SET note=? WHERE schedule_id=? AND job_id=?')->execute([trim((string)$note), $scheduleId, (int)$jobId]);
    }
}

function maintenance_summary_text(array $summary): string
{
    return 'Checklist Pekerjaan:' . "\n" . checklist_summary_text($summary) . "\n\n"
        . 'Status: ' . $summary['summary_status'] . "\n"
        . 'Checklist: ' . $summary['job_progress'] . "\n"
        . 'Pekerjaan selesai: ' . $summary['done_jobs'] . "\n"
        . 'Pekerjaan pending: ' . $summary['pending_jobs'] . "\n"
        . 'Foto: ' . $summary['photo_summary'] . "\n"
        . 'Audit foto: ' . $summary['photo_evidence_score'] . '/100 - ' . $summary['challenge_audit_status'] . ' - ' . $summary['challenge_audit_text'] . "\n"
        . 'Scan QR: ' . $summary['scan_summary'] . "\n"
        . 'Kondisi: ' . $summary['condition'] . "\n"
        . 'Catatan kondisi: ' . ($summary['physical_condition'] ?: '-') . "\n"
        . 'Catatan teknisi: ' . ($summary['notes'] ?: '-');
}

function maintenance_status_rows(PDO $pdo, bool $printerReady, string $assetTypeFilter, string $statusFilter, int $techFilter, array $user): array
{
    $params = [];
    $where = ['1=1'];
    if ($assetTypeFilter !== '') {
        $where[] = $printerReady ? 's.asset_type=?' : "'pc'=?";
        $params[] = $assetTypeFilter;
    }
    if ($techFilter > 0) {
        $where[] = 's.technician_id=?';
        $params[] = $techFilter;
    }
    if (($user['role'] ?? '') === 'technician') {
        $where[] = 's.technician_id=?';
        $params[] = (int)$user['id'];
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $sql = $printerReady
        ? "SELECT s.id schedule_id, s.asset_type, COALESCE(s.pc_id,s.printer_id) asset_id, s.status raw_status, s.scheduled_date, s.arrival_at, s.arrival_lat, s.arrival_lng, s.completed_at, s.photo_challenge_code schedule_challenge_code, u.name technician, COALESCE(p.owner_name, pr.printer_name) asset_name, COALESCE(p.computer_name, pr.location) asset_detail, COALESCE(p.latitude, pr.latitude) asset_latitude, COALESCE(p.longitude, pr.longitude) asset_longitude, COALESCE(p.location_radius_m, pr.location_radius_m) asset_radius_m, r.before_photos, r.process_photos, r.after_photos, r.photo_challenge_code report_challenge_code, r.photo_audit_meta, r.created_at report_created_at FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id LEFT JOIN maintenance_reports r ON r.id=(SELECT r2.id FROM maintenance_reports r2 WHERE r2.schedule_id=s.id ORDER BY r2.id DESC LIMIT 1) $whereSql"
        : "SELECT s.id schedule_id, 'pc' asset_type, s.pc_id asset_id, s.status raw_status, s.scheduled_date, s.arrival_at, s.arrival_lat, s.arrival_lng, s.completed_at, s.photo_challenge_code schedule_challenge_code, u.name technician, p.owner_name asset_name, p.computer_name asset_detail, p.latitude asset_latitude, p.longitude asset_longitude, p.location_radius_m asset_radius_m, r.before_photos, r.process_photos, r.after_photos, r.photo_challenge_code report_challenge_code, r.photo_audit_meta, r.created_at report_created_at FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id LEFT JOIN maintenance_reports r ON r.id=(SELECT r2.id FROM maintenance_reports r2 WHERE r2.schedule_id=s.id ORDER BY r2.id DESC LIMIT 1) $whereSql";
    $sql .= ' ORDER BY s.scheduled_date DESC, s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    $today = new DateTimeImmutable(date('Y-m-d'));
    foreach ($stmt->fetchAll() as $row) {
        $scheduled = DateTimeImmutable::createFromFormat('Y-m-d', (string)$row['scheduled_date']) ?: $today;
        $daysLate = (int)$scheduled->diff($today)->format('%r%a');
        $isCompleted = (string)$row['raw_status'] === 'completed';
        $statusGroup = $isCompleted ? 'completed' : ($daysLate > 7 ? 'expired' : 'pending');
        if ($statusFilter !== '' && $statusFilter !== $statusGroup) {
            continue;
        }
        $beforeCount = count(json_decode((string)($row['before_photos'] ?? '[]'), true) ?: []);
        $afterCount = count(json_decode((string)($row['after_photos'] ?? '[]'), true) ?: []);
        $flags = [];
        if ($statusGroup === 'expired') {
            $flags[] = 'Lewat ' . $daysLate . ' hari';
        }
        if ($isCompleted && ($beforeCount <= 0 || $afterCount <= 0)) {
            $flags[] = 'Foto belum lengkap';
        }
        $photoAudit = photo_evidence_audit_summary($pdo, [
            'schedule_id' => $row['schedule_id'],
            'photo_audit_meta' => (string)($row['photo_audit_meta'] ?? ''),
            'before_photos' => (string)($row['before_photos'] ?? ''),
            'after_photos' => (string)($row['after_photos'] ?? ''),
            'created_at' => (string)($row['report_created_at'] ?? ''),
        ]);
        if ($isCompleted) {
            $flags[] = 'Audit foto ' . $photoAudit['score'] . '/100';
            if ($photoAudit['status'] !== 'OK') {
                $flags[] = $photoAudit['text'];
            }
        }
        if ($isCompleted && empty($row['arrival_at'])) {
            $flags[] = 'Scan awal tidak tercatat';
        }
        if (!empty($row['asset_latitude']) && !empty($row['asset_longitude'])) {
            if ($row['arrival_lat'] === null || $row['arrival_lng'] === null || $row['arrival_lat'] === '' || $row['arrival_lng'] === '') {
                $flags[] = 'GPS scan kosong';
            } else {
                $distance = geo_distance_m((float)$row['asset_latitude'], (float)$row['asset_longitude'], (float)$row['arrival_lat'], (float)$row['arrival_lng']);
                $flags[] = 'Jarak scan awal ' . round($distance, 1) . ' m';
            }
        }
        $row['status_group'] = $statusGroup;
        $row['status_label'] = $statusGroup === 'completed' ? 'Sudah Maintenance' : ($statusGroup === 'expired' ? 'Expired > 7 Hari' : 'Pending');
        $row['audit_flags'] = implode('; ', $flags);
        $rows[] = $row;
    }
    return $rows;
}

function export_excel(PDO $pdo, string $type): never
{
    $user = current_user();
    if (!$user) {
        redirect_to('login');
    }
    $maintenanceTypes = ['reports', 'maintenance', 'maintenance_status', 'jobs', 'technicians'];
    if (($user['role'] ?? '') === 'technician' && $type !== 'reports') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    if (in_array($type, $maintenanceTypes, true) && !can_manage_maintenance($user) && ($user['role'] ?? '') !== 'technician') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    if (!in_array($type, $maintenanceTypes, true) && !is_full_admin($user)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    $filename = 'pcconnect-' . preg_replace('/[^a-z0-9_-]/i', '', $type) . '-' . date('Ymd-His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";

    if ($type === 'reports') {
        $printerReady = printer_schema_ready($pdo);
        $pcFilter = trim((string)($_GET['pc_id'] ?? ''));
        $techFilter = (int)($_GET['technician_id'] ?? 0);
        $sql = $printerReady
            ? "SELECT r.*, s.id schedule_id, COALESCE(s.pc_id,s.printer_id) pc_id, s.asset_type, s.scheduled_date, s.status schedule_status, s.arrival_at, s.completed_at, s.photo_challenge_code schedule_challenge_code, COALESCE(p.owner_name, pr.printer_name) owner_name, u.name technician FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id LEFT JOIN maintenance_reports r ON r.id=(SELECT r2.id FROM maintenance_reports r2 WHERE r2.schedule_id=s.id ORDER BY r2.id DESC LIMIT 1) WHERE 1=1"
            : "SELECT r.*, s.id schedule_id, s.pc_id, 'pc' asset_type, s.scheduled_date, s.status schedule_status, s.arrival_at, s.completed_at, s.photo_challenge_code schedule_challenge_code, p.owner_name, u.name technician FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id LEFT JOIN maintenance_reports r ON r.id=(SELECT r2.id FROM maintenance_reports r2 WHERE r2.schedule_id=s.id ORDER BY r2.id DESC LIMIT 1) WHERE 1=1";
        $params = [];
        if ($pcFilter !== '') { $sql .= ($printerReady && substr($pcFilter, 0, 3) === 'PRN') ? ' AND s.printer_id = ?' : ' AND s.pc_id = ?'; $params[] = $pcFilter; }
        if ($techFilter > 0) { $sql .= ' AND s.technician_id = ?'; $params[] = $techFilter; }
        if (($user['role'] ?? '') === 'technician') { $sql .= ' AND s.technician_id = ?'; $params[] = $user['id']; }
        $sql .= ' ORDER BY s.scheduled_date DESC, s.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $report) {
            $summary = maintenance_report_summary($pdo, $report);
            $rows[] = [
                $report['created_at'] ?? $report['scheduled_date'],
                $report['scheduled_date'],
                $report['pc_id'],
                $report['owner_name'],
                $report['asset_type'] ?? 'pc',
                $report['technician'],
                $report['schedule_status'],
                checklist_summary_text($summary),
                $summary['photo_evidence_score'] . '/100',
                $summary['challenge_audit_status'],
                $summary['challenge_audit_text'],
                $summary['photo_summary'],
                $summary['scan_summary'],
                $summary['condition'],
                $summary['physical_condition'] ?: '-',
                $summary['notes'] ?: '-',
                maintenance_summary_text($summary),
            ];
        }
        output_tsv(['Tanggal Report', 'Tanggal Schedule', 'Asset ID', 'Owner/Printer', 'Tipe Aset', 'Teknisi', 'Status Schedule', 'Checklist Pekerjaan / Status / Keterangan', 'Audit Foto Score', 'Audit Foto Status', 'Warning Audit Foto', 'Foto', 'Scan QR', 'Kondisi', 'Catatan Kondisi', 'Catatan Teknisi', 'Summary Lengkap'], $rows);
        exit;
    }

    if ($type === 'maintenance') {
        if (printer_schema_ready($pdo)) {
            $rows = $pdo->query("SELECT s.scheduled_date, COALESCE(s.pc_id,s.printer_id) asset_id, s.asset_type, COALESCE(p.owner_name, pr.printer_name) owner_name, u.name technician, s.status, s.notes FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id ORDER BY s.scheduled_date DESC")->fetchAll();
        } else {
            $rows = $pdo->query("SELECT s.scheduled_date, s.pc_id asset_id, 'pc' asset_type, p.owner_name, u.name technician, s.status, s.notes FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id ORDER BY s.scheduled_date DESC")->fetchAll();
        }
        output_tsv(['Tanggal', 'Asset ID', 'Tipe Aset', 'Owner/Printer', 'Teknisi', 'Status', 'Catatan'], $rows);
        exit;
    }

    if ($type === 'maintenance_status') {
        $printerReady = printer_schema_ready($pdo);
        $rows = maintenance_status_rows(
            $pdo,
            $printerReady,
            trim((string)($_GET['asset_type'] ?? '')),
            trim((string)($_GET['status_group'] ?? '')),
            (int)($_GET['technician_id'] ?? 0),
            $user
        );
        $exportRows = [];
        foreach ($rows as $row) {
            $exportRows[] = [
                $row['asset_type'],
                $row['asset_id'],
                $row['asset_name'],
                $row['asset_detail'],
                $row['technician'],
                $row['scheduled_date'],
                $row['status_label'],
                $row['raw_status'],
                $row['arrival_at'],
                $row['completed_at'],
                $row['audit_flags'] ?: 'OK',
            ];
        }
        output_tsv(['Tipe Aset', 'Asset ID', 'Owner/Printer', 'Detail/Lokasi', 'Teknisi', 'Tanggal Schedule', 'Status Laporan', 'Status Sistem', 'Scan Awal', 'Completed', 'Audit Foto / Flags'], $exportRows);
        exit;
    }

    if ($type === 'jobs') {
        $rows = $pdo->query('SELECT title, description, estimated_minutes, is_active, created_at FROM maintenance_jobs ORDER BY title')->fetchAll();
        output_tsv(['Job Desk', 'Deskripsi', 'Estimasi Menit', 'Aktif', 'Dibuat'], $rows);
        exit;
    }

    if ($type === 'technicians') {
        $rows = $pdo->query("SELECT name, username, is_active, created_at FROM users WHERE role='technician' ORDER BY name")->fetchAll();
        output_tsv(['Nama', 'Username', 'Aktif', 'Dibuat'], $rows);
        exit;
    }

    if ($type === 'asset_items') {
        $rows = $pdo->query('SELECT ai.asset_code, c.company_name, ai.asset_type, ai.asset_name, ai.brand, ai.model, ai.serial_number, ai.manufacture_year, ai.warranty_until, ai.installed_at, ai.purchase_value, ai.current_value, ai.status, ai.location_label, ai.notes FROM asset_items ai LEFT JOIN asset_companies c ON c.id=ai.company_id ORDER BY ai.asset_code')->fetchAll();
        output_tsv(['Asset Code', 'Company', 'Type', 'Nama Aset', 'Merek', 'Model', 'Serial Number', 'Tahun', 'Garansi', 'Tanggal Pasang', 'Nilai Awal', 'Nilai Current', 'Status', 'Lokasi', 'Notes'], $rows);
        exit;
    }

    if ($type === 'asset_bundles') {
        $rows = $pdo->query('SELECT b.maintenance_asset_code, c.company_name, b.bundle_type, b.bundle_name, b.employee_nik, b.owner_name, b.location_label, b.latitude, b.longitude, b.location_radius_m, b.status, b.notes FROM asset_bundles b LEFT JOIN asset_companies c ON c.id=b.company_id ORDER BY b.maintenance_asset_code')->fetchAll();
        output_tsv(['No Aset Pemeliharaan', 'Company', 'Bundle Type', 'Nama Bundle', 'NIK', 'Pengguna', 'Lokasi', 'Latitude', 'Longitude', 'Radius', 'Status', 'Notes'], $rows);
        exit;
    }

    $rows = $pdo->query('SELECT pc_id, employee_nik, owner_name, computer_name, physical_condition, last_analyzed_at, updated_at FROM pcs ORDER BY pc_id')->fetchAll();
    output_tsv(['PcID', 'NIK', 'Owner', 'Computer Name', 'Kondisi Fisik', 'Analisa Terakhir', 'Update'], $rows);
    exit;
}

function output_tsv(array $headers, array $rows): void
{
    echo implode("\t", array_map('excel_cell', $headers)) . "\r\n";
    foreach ($rows as $row) {
        echo implode("\t", array_map('excel_cell', array_values($row))) . "\r\n";
    }
}

function excel_cell(mixed $value): string
{
    $text = (string)$value;
    $text = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $text);
    return $text;
}

function pseudo_qr_hotfix(string $value): string
{
    $src = qr_image_url($value, 180);
    return '<img class="qr-img" alt="QR payload: ' . e($value) . '" src="' . e($src) . '">';
}

function qr_image_url(string $value, int $size = 900): string
{
    $size = max(120, min(1200, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&format=png&data=' . rawurlencode($value);
}

function label_download_script(): string
{
    return '<script>(function(){function fitText(ctx,text,maxWidth,startSize,minSize){text=String(text||"").trim();var size=startSize;ctx.font="700 "+size+"px Arial";while(size>minSize&&ctx.measureText(text).width>maxWidth){size-=2;ctx.font="700 "+size+"px Arial";}return size;}function drawCentered(ctx,text,y,size,color,bold){text=String(text||"").trim();if(!text)return;ctx.fillStyle=color||"#111827";ctx.font=(bold?"700 ":"400 ")+size+"px Arial";ctx.textAlign="center";ctx.textBaseline="top";ctx.fillText(text,450,y);}function loadImage(src){return new Promise(function(resolve,reject){var img=new Image();img.crossOrigin="anonymous";img.onload=function(){resolve(img);};img.onerror=reject;img.src=src;});}async function downloadLabel(btn){var code=btn.dataset.code||"",payload=btn.dataset.payload||"",type=btn.dataset.type||"Maintenance Asset",name=btn.dataset.name||"",detail=btn.dataset.detail||"",qrSrc=' . js_value(qr_image_url('__PAYLOAD__', 900)) . '.replace("__PAYLOAD__",encodeURIComponent(payload));var qr=await loadImage(qrSrc);var canvas=document.createElement("canvas"),ctx=canvas.getContext("2d");canvas.width=900;canvas.height=1180;ctx.fillStyle="#fff";ctx.fillRect(0,0,900,1180);ctx.strokeStyle="#111827";ctx.lineWidth=2;ctx.strokeRect(20,20,860,1140);var codeSize=fitText(ctx,code,780,58,28);drawCentered(ctx,code,66,codeSize,"#111827",true);drawCentered(ctx,type,175,28,"#475569",false);ctx.drawImage(qr,145,260,610,610);drawCentered(ctx,"PcConnect Maintenance QR",925,30,"#111827",true);drawCentered(ctx,name,1005,26,"#475569",false);drawCentered(ctx,detail,1065,24,"#475569",false);var a=document.createElement("a");a.href=canvas.toDataURL("image/png");a.download="PcConnect-Label-"+code.replace(/[^A-Z0-9_-]/g,"")+".png";document.body.appendChild(a);a.click();a.remove();}document.addEventListener("click",function(ev){var btn=ev.target.closest(".js-label-download");if(!btn)return;ev.preventDefault();var old=btn.textContent;btn.textContent="Membuat PNG...";downloadLabel(btn).catch(function(){window.location.href=btn.href;}).finally(function(){btn.textContent=old;});});})();</script>';
}

function label_font_path(bool $bold = false): ?string
{
    $candidates = $bold ? [
        'C:/Windows/Fonts/arialbd.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    ] : [
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function label_center_text($img, string $text, int $size, int $y, int $color, bool $bold = false): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    $maxChars = $size >= 50 ? 22 : ($size >= 30 ? 38 : 52);
    if (strlen($text) > $maxChars) {
        $text = substr($text, 0, $maxChars - 3) . '...';
    }
    $fontPath = label_font_path($bold);
    if ($fontPath && function_exists('imagettfbbox') && function_exists('imagettftext')) {
        $box = imagettfbbox($size, 0, $fontPath, $text);
        if ($box) {
            $textWidth = $box[2] - $box[0];
            $textHeight = abs($box[7] - $box[1]);
            $x = (int)((imagesx($img) - $textWidth) / 2);
            imagettftext($img, $size, 0, max(24, $x), $y + $textHeight, $color, $fontPath, $text);
            return;
        }
    }

    $font = 5;
    $scale = max(2, (int)round($size / 10));
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $tmp = imagecreatetruecolor($textWidth + 8, $textHeight + 8);
    $bg = imagecolorallocate($tmp, 255, 255, 255);
    imagefilledrectangle($tmp, 0, 0, imagesx($tmp), imagesy($tmp), $bg);
    imagecolortransparent($tmp, $bg);
    imagestring($tmp, $font, 4, 4, $text, $color);
    $scaledWidth = imagesx($tmp) * $scale;
    $scaledHeight = imagesy($tmp) * $scale;
    $x = (int)((imagesx($img) - $scaledWidth) / 2);
    imagecopyresampled($img, $tmp, max(24, $x), $y, 0, 0, $scaledWidth, $scaledHeight, imagesx($tmp), imagesy($tmp));
    imagedestroy($tmp);
}

function detail_block(string $title, ?string $json): void
{
    $data = $json ? json_decode($json, true) : null;
    echo '<div class="panel"><h2>' . e($title) . '</h2>';
    echo $data ? render_analysis_section($title, $data) : '<p>Belum ada data.</p>';
    echo '</div>';
}

function decode_json_payload(string $raw): array
{
    $clean = normalize_json_text($raw);
    $payload = json_decode($clean, true);
    return [$payload, json_last_error_msg(), strlen($clean)];
}

function normalize_json_text(string $raw): string
{
    $text = $raw;
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        $text = substr($text, 3);
    }
    if ((str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }
    $text = trim($text);
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end >= $start) {
        $text = substr($text, $start, $end - $start + 1);
    }
    return $text;
}

function render_analysis_section(string $title, array $data): string
{
    if ($title === 'Spesifikasi Umum') {
        $html = ai_insight_box('AI Hardware Insight', hardware_ai_insights($data));
        $html .= kv_table([
            'Processor' => $data['processor'] ?? '-',
            'Core / Logical' => ($data['processor_cores'] ?? '-') . ' / ' . ($data['processor_logical'] ?? '-'),
            'RAM' => ($data['ram_gb'] ?? '-') . ' GB',
            'Storage' => summarize_items($data['storage'] ?? []),
            'GPU' => summarize_gpu($data['gpu'] ?? []),
            'Manufacturer' => $data['manufacturer'] ?? '-',
            'Model' => $data['model'] ?? '-',
            'BIOS' => $data['bios_version'] ?? '-',
        ]);
        return $html;
    }

    if ($title === 'Software') {
        $html = ai_insight_box('AI Software Insight', software_ai_insights($data));
        $html .= kv_table([
            'OS' => ($data['os_caption'] ?? '-') . ' build ' . ($data['os_build'] ?? '-'),
            'Version' => $data['os_version'] ?? '-',
            'Architecture' => $data['architecture'] ?? '-',
        ]);
        $html .= '<h3>Office / Spreadsheet</h3>' . simple_table($data['office_apps'] ?? [], ['DisplayName', 'DisplayVersion', 'Publisher']);
        $html .= '<h3>Antivirus</h3>' . simple_table($data['antivirus'] ?? [], ['displayName', 'productState']);
        return $html;
    }

    if ($title === 'Device Management') {
        $html = ai_insight_box('AI Device Insight', device_ai_insights($data));
        $html .= '<h3>Network</h3>' . simple_table($data['network'] ?? [], ['Name', 'AdapterType', 'NetConnectionStatus', 'Speed']);
        $warnings = $data['driver_warnings'] ?? [];
        $driverRisk = $data['driver_risk'] ?? [];
        $html .= '<h3>AI Driver Risk Priority</h3>' . ($driverRisk ? risk_cards($driverRisk, 'driver') : '<p>Tidak ada driver berisiko berdasarkan rule offline.</p>');
        $html .= '<h3>Driver Warning dari Device Manager</h3>' . ($warnings ? simple_table($warnings, ['Name', 'Manufacturer', 'ConfigManagerErrorCode', 'Status']) : '<p>Tidak ada warning driver terdeteksi.</p>');
        if (isset($data['total_drivers'])) {
            $html .= '<p class="muted">Total driver terbaca: ' . e($data['total_drivers']) . '. Risk score adalah analitik offline, bukan vonis malware/driver rusak.</p>';
        }
        return $html;
    }

    if ($title === 'Benchmark') {
        $html = ai_insight_box('AI Performance Insight', benchmark_ai_insights($data));
        $html .= kv_table([
            'CPU quick time' => isset($data['cpu_quick_ms']) ? round((float)$data['cpu_quick_ms'], 2) . ' ms' : '-',
            'CPU quick score' => $data['cpu_quick_score'] ?? '-',
            'RAM copy 64MB' => isset($data['ram_copy_64mb_ms']) ? round((float)$data['ram_copy_64mb_ms'], 2) . ' ms' : '-',
            'RAM throughput' => isset($data['ram_copy_mbps']) ? round((float)$data['ram_copy_mbps'], 2) . ' MB/s' : '-',
            'Storage write 32MB' => isset($data['storage_write_mbps']) ? round((float)$data['storage_write_mbps'], 2) . ' MB/s' : legacy_storage_write($data),
            'Storage read 32MB' => isset($data['storage_read_mbps']) ? round((float)$data['storage_read_mbps'], 2) . ' MB/s' : '-',
            'Mode' => $data['benchmark_mode'] ?? '-',
        ]);
        $stress = is_array($data['stress_test'] ?? null) ? $data['stress_test'] : [];
        if ($stress) {
            $html .= '<h3>Stress Test Offline</h3>';
            $html .= kv_table([
                'Status' => !empty($stress['enabled']) ? 'Dijalankan' : 'Tidak dijalankan',
                'Durasi' => isset($stress['duration_seconds']) ? $stress['duration_seconds'] . ' detik' : '-',
                'CPU workers' => $stress['cpu_workers'] ?? '-',
                'CPU ops/sec' => $stress['cpu_ops_per_second'] ?? '-',
                'RAM test' => isset($stress['ram_test_mb']) ? $stress['ram_test_mb'] . ' MB' : '-',
                'RAM fill' => isset($stress['ram_fill_mbps']) ? $stress['ram_fill_mbps'] . ' MB/s' : '-',
                'Storage write stress' => isset($stress['storage_write_mbps']) ? $stress['storage_write_mbps'] . ' MB/s' : '-',
                'Storage read stress' => isset($stress['storage_read_mbps']) ? $stress['storage_read_mbps'] . ' MB/s' : '-',
                'AI Offline' => $stress['ai_offline_recommendation'] ?? '-',
            ]);
        }
        $html .= '<p>' . e(benchmark_summary($data)) . '</p>';
        return $html;
    }

    if ($title === 'Startup Analisa') {
        $items = array_slice(normalize_list($data['startup_items'] ?? []), 0, 30);
        $services = array_slice(normalize_list($data['auto_services_not_running'] ?? []), 0, 30);
        $suspicious = array_slice(normalize_list($data['suspicious_background_processes'] ?? []), 0, 30);
        $topMemory = array_slice(normalize_list($data['top_memory_processes'] ?? []), 0, 20);
        $startupIntel = array_slice(normalize_list($data['startup_intelligence'] ?? []), 0, 40);
        $highRisk = normalize_list($data['high_risk_startup'] ?? []);
        $mediumRisk = normalize_list($data['medium_risk_startup'] ?? []);
        $html = ai_insight_box('AI Startup Insight', startup_ai_insights($data));
        $html .= '<h3>AI Startup Risk Priority</h3>' . ($startupIntel ? risk_cards($startupIntel, 'startup') : '<p>Belum ada startup intelligence. Jalankan PcNalisa agent terbaru.</p>');
        $html .= '<p class="muted">High risk: ' . e(count($highRisk)) . ', Medium risk: ' . e(count($mediumRisk)) . ', Low risk: ' . e($data['low_risk_startup_count'] ?? '-') . '. Risk score adalah analitik offline dan perlu validasi admin.</p>';
        $html .= '<h3>AI Background Risk Review</h3>' . ($suspicious ? risk_cards($suspicious, 'background') : '<p>Tidak ada proses mencurigakan berdasarkan rule offline.</p>');
        $html .= '<h3>Top Memory Process</h3>' . ($topMemory ? simple_table($topMemory, ['Name', 'MemoryMB', 'ExecutablePath']) : '<p>Belum ada data proses background.</p>');
        $html .= '<h3>AI Startup Item Review</h3>' . ($items ? risk_cards($items, 'startup_item') : '<p>Belum ada startup item terbaca.</p>');
        $html .= '<h3>Auto Services Not Running</h3>' . ($services ? simple_table($services, ['Name', 'DisplayName', 'State', 'StartMode']) : '<p>Tidak ada service otomatis bermasalah.</p>');
        return $html;
    }

    return '<pre>' . e(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}

function kv_table(array $rows): string
{
    $html = '<table class="analysis-table">';
    foreach ($rows as $key => $value) {
        $html .= '<tr><th style="width:34%">' . e($key) . '</th><td>' . e(format_value($value)) . '</td></tr>';
    }
    return $html . '</table>';
}

function simple_table(mixed $rows, array $columns): string
{
    $items = normalize_list($rows);
    if (!$items) {
        return '<p>Belum ada data.</p>';
    }
    $html = '<table class="analysis-table"><tr>';
    foreach ($columns as $column) {
        $html .= '<th>' . e(labelize($column)) . '</th>';
    }
    $html .= '</tr>';
    foreach ($items as $row) {
        $html .= '<tr>';
        foreach ($columns as $column) {
            $html .= '<td>' . e(format_value($row[$column] ?? '-')) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
}

function ai_insight_box(string $title, array $lines): string
{
    if (!$lines) {
        return '';
    }
    $html = '<div class="risk-card" style="background:#f8fafc"><div class="risk-title">' . e($title) . '</div><ul style="margin:8px 0 0 18px;padding:0">';
    foreach ($lines as $line) {
        $html .= '<li>' . e($line) . '</li>';
    }
    return $html . '</ul></div>';
}

function hardware_ai_insights(array $data): array
{
    $lines = [];
    $ram = (float)($data['ram_gb'] ?? 0);
    $processor = (string)($data['processor'] ?? '');
    if ($ram > 0 && $ram < 8) {
        $lines[] = 'RAM di bawah 8 GB: prioritas upgrade jika user membuka browser banyak tab, Office berat, atau aplikasi desain/ERP.';
    } elseif ($ram >= 8 && $ram < 16) {
        $lines[] = 'RAM cukup untuk pekerjaan standar, tetapi 16 GB lebih aman untuk multitasking dan umur pakai lebih panjang.';
    } elseif ($ram >= 16) {
        $lines[] = 'RAM sudah sehat untuk workload kantor modern.';
    }
    if (preg_match('/celeron|atom|pentium/i', $processor)) {
        $lines[] = 'Processor kelas entry-level: jangan jadikan kandidat aplikasi berat; fokus maintenance pada startup, storage, dan RAM.';
    } elseif ($processor !== '') {
        $lines[] = 'Processor terbaca: cocokkan performa nyata dengan benchmark, bukan hanya nama CPU.';
    }
    $storageText = summarize_items($data['storage'] ?? []);
    if (preg_match('/HDD|Hard/i', $storageText)) {
        $lines[] = 'Storage terindikasi HDD: jika PC terasa lambat, upgrade SSD biasanya berdampak paling besar.';
    }
    return $lines ?: ['Data hardware belum cukup untuk insight tajam. Jalankan PcNalisa agent terbaru sebagai Administrator.'];
}

function software_ai_insights(array $data): array
{
    $lines = [];
    if (empty($data['antivirus'])) {
        $lines[] = 'Antivirus tidak terbaca: pastikan Windows Security/antivirus aktif dan update.';
    } else {
        $lines[] = 'Antivirus terbaca. Validasi status update dan real-time protection di PC jika ada keluhan keamanan.';
    }
    if (empty($data['office_apps'])) {
        $lines[] = 'Office/spreadsheet tidak terbaca: cek apakah user memang tidak membutuhkan aplikasi produktivitas.';
    }
    $build = (int)($data['os_build'] ?? 0);
    if ($build > 0 && $build < 19045) {
        $lines[] = 'Build Windows relatif lama: jadwalkan Windows Update bertahap dan cek kompatibilitas aplikasi.';
    }
    return $lines ?: ['Software terlihat wajar dari data yang tersedia.'];
}

function device_ai_insights(array $data): array
{
    $driverRisk = normalize_list($data['driver_risk'] ?? []);
    $warnings = normalize_list($data['driver_warnings'] ?? []);
    $high = 0;
    $medium = 0;
    foreach ($driverRisk as $row) {
        if (!is_array($row)) { continue; }
        if (strcasecmp((string)($row['RiskLevel'] ?? ''), 'High') === 0) { $high++; }
        if (strcasecmp((string)($row['RiskLevel'] ?? ''), 'Medium') === 0) { $medium++; }
    }
    $lines = [];
    if ($high > 0) {
        $lines[] = 'Ada ' . $high . ' driver high risk: update/reinstall driver resmi vendor sebelum menyimpulkan hardware rusak.';
    } elseif ($medium > 0) {
        $lines[] = 'Ada ' . $medium . ' driver medium risk: review bila ada keluhan device, jaringan, audio, display, atau USB.';
    } else {
        $lines[] = 'Tidak ada driver risk prioritas tinggi dari rule offline.';
    }
    if ($warnings) {
        $lines[] = 'Device Manager memberi warning pada ' . count($warnings) . ' item. Cocokkan error code dengan perangkat fisik.';
    }
    return $lines;
}

function benchmark_ai_insights(array $data): array
{
    $lines = [];
    $write = isset($data['storage_write_mbps']) ? (float)$data['storage_write_mbps'] : null;
    $read = isset($data['storage_read_mbps']) ? (float)$data['storage_read_mbps'] : null;
    $ram = isset($data['ram_copy_mbps']) ? (float)$data['ram_copy_mbps'] : null;
    $cpuMs = isset($data['cpu_quick_ms']) ? (float)$data['cpu_quick_ms'] : null;
    if ($write !== null && $write < 80) {
        $lines[] = 'Storage write rendah: cek health SSD/HDD, ruang kosong, mode controller, dan proses backup/sync.';
    }
    if ($read !== null && $read < 120) {
        $lines[] = 'Storage read rendah: indikasi bottleneck saat booting dan membuka aplikasi.';
    }
    if ($ram !== null && $ram < 1500) {
        $lines[] = 'Throughput RAM rendah: ulang benchmark setelah menutup aplikasi berat; jika tetap rendah cek konfigurasi RAM.';
    }
    if ($cpuMs !== null && $cpuMs > 2500) {
        $lines[] = 'CPU quick test lambat: cek suhu, power plan, background process, dan usia CPU.';
    }
    $stress = is_array($data['stress_test'] ?? null) ? $data['stress_test'] : [];
    if (!empty($stress['enabled'])) {
        if (isset($stress['storage_write_mbps']) && (float)$stress['storage_write_mbps'] < 60) {
            $lines[] = 'Stress storage write rendah: cek health disk, ruang kosong, antivirus realtime, dan proses sync/backup.';
        }
        if (isset($stress['ram_fill_mbps']) && (float)$stress['ram_fill_mbps'] < 800) {
            $lines[] = 'Stress RAM rendah: ulang test setelah restart; jika tetap rendah cek konfigurasi RAM.';
        }
        if (!empty($stress['ai_offline_recommendation'])) {
            $lines[] = 'Stress AI offline: ' . $stress['ai_offline_recommendation'];
        }
    }
    return $lines ?: ['Benchmark ringan berada dalam batas wajar. Gunakan sebagai pembanding antar PC sejenis.'];
}

function startup_ai_insights(array $data): array
{
    $intel = normalize_list($data['startup_intelligence'] ?? []);
    $suspicious = normalize_list($data['suspicious_background_processes'] ?? []);
    $services = normalize_list($data['auto_services_not_running'] ?? []);
    $high = 0;
    $medium = 0;
    foreach ($intel as $row) {
        if (!is_array($row)) { continue; }
        if (strcasecmp((string)($row['RiskLevel'] ?? ''), 'High') === 0) { $high++; }
        if (strcasecmp((string)($row['RiskLevel'] ?? ''), 'Medium') === 0) { $medium++; }
    }
    $lines = [];
    if ($high > 0) {
        $lines[] = 'Startup high risk ditemukan: prioritas cek item dari Temp/AppData, command encoded/download, atau scheduled task script.';
    } elseif ($medium > 0) {
        $lines[] = 'Startup medium risk ditemukan: validasi dengan kebutuhan user sebelum disable.';
    } else {
        $lines[] = 'Tidak ada startup high/medium risk dari data terbaru.';
    }
    if ($suspicious) {
        $lines[] = count($suspicious) . ' background process masuk review. Cek publisher, path, dan command line.';
    }
    if ($services) {
        $lines[] = count($services) . ' service auto tidak running. Review hanya service yang terkait aplikasi kerja, antivirus, backup, atau driver.';
    }
    return $lines;
}

function risk_cards(mixed $rows, string $type): string
{
    $items = normalize_list($rows);
    if (!$items) {
        return '<p>Belum ada data.</p>';
    }
    $items = array_slice($items, 0, 25);
    $html = '<div class="risk-list">';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $level = (string)($item['RiskLevel'] ?? 'Low');
        $levelClass = strtolower($level) === 'high' ? 'risk-high' : (strtolower($level) === 'medium' ? 'risk-medium' : 'risk-low');
        if ($type === 'driver') {
            $title = (string)($item['DeviceName'] ?? 'Unknown device');
            $subtitle = trim((string)($item['DeviceClass'] ?? '-') . ' / ' . (string)($item['Provider'] ?? '-'));
            $path = trim('Driver: ' . (string)($item['Version'] ?? '-') . ' | Date: ' . (string)($item['DriverDate'] ?? '-') . ' | Signed: ' . format_value($item['IsSigned'] ?? '-'));
        } elseif ($type === 'background') {
            $assessment = background_display_assessment($item);
            $level = $assessment['level'];
            $levelClass = strtolower($level) === 'high' ? 'risk-high' : (strtolower($level) === 'medium' ? 'risk-medium' : 'risk-low');
            $title = (string)($item['Name'] ?? 'Unknown process');
            $subtitle = 'Process ID ' . (string)($item['ProcessId'] ?? '-') . ' / Memory ' . (string)($item['MemoryMB'] ?? '-') . ' MB';
            $path = (string)($item['Path'] ?? ($item['ExecutablePath'] ?? '-'));
            $item['RiskScore'] = $assessment['score'];
            $item['Reasons'] = $assessment['reasons'];
            $item['SuggestedAction'] = $assessment['action'];
        } elseif ($type === 'startup_item') {
            $assessment = startup_item_display_assessment($item);
            $level = $assessment['level'];
            $levelClass = strtolower($level) === 'high' ? 'risk-high' : (strtolower($level) === 'medium' ? 'risk-medium' : 'risk-low');
            $title = (string)($item['Name'] ?? 'Unknown startup item');
            $subtitle = trim((string)($item['Location'] ?? '-') . ' / User ' . (string)($item['User'] ?? '-'));
            $path = (string)($item['Command'] ?? '-');
            $item['RiskScore'] = $assessment['score'];
            $item['Reasons'] = $assessment['reasons'];
            $item['SuggestedAction'] = $assessment['action'];
        } else {
            $title = (string)($item['Name'] ?? 'Unknown startup');
            $subtitle = trim((string)($item['Source'] ?? '-') . ' / ' . (string)($item['Publisher'] ?? '-'));
            $path = (string)($item['Path'] ?? ($item['Command'] ?? '-'));
        }
        $html .= '<article class="risk-card"><div class="risk-head"><div><div class="risk-title">' . e($title) . '</div><div class="muted">' . e($subtitle ?: '-') . '</div></div><div><span class="risk-pill ' . $levelClass . '">' . e($level) . '</span> <span class="risk-pill">Score ' . e($item['RiskScore'] ?? '-') . '</span></div></div>';
        $html .= '<div class="risk-meta">';
        foreach (['Signature', 'Signer', 'Manufacturer', 'InfName'] as $metaKey) {
            if (!empty($item[$metaKey])) {
                $html .= '<span class="risk-pill">' . e(labelize($metaKey)) . ': ' . e($item[$metaKey]) . '</span>';
            }
        }
        $html .= '</div>';
        if ($path !== '') {
            $html .= '<div class="risk-path">' . e($path) . '</div>';
        }
        $html .= '<p class="risk-note"><strong>Alasan:</strong> ' . e($item['Reasons'] ?? '-') . '</p>';
        $html .= '<p class="risk-note"><strong>Saran:</strong> ' . e($item['SuggestedAction'] ?? '-') . '</p>';
        if ($type === 'driver') {
            $html .= driver_search_actions($item);
        }
        $html .= '</article>';
    }
    return $html . '</div>';
}

function driver_search_actions(array $item): string
{
    $device = trim((string)($item['DeviceName'] ?? ''));
    $provider = trim((string)($item['Provider'] ?? $item['Manufacturer'] ?? ''));
    $version = trim((string)($item['Version'] ?? ''));
    $inf = trim((string)($item['InfName'] ?? ''));
    $deviceId = trim((string)($item['DeviceID'] ?? ''));
    $hardwareId = driver_hardware_id($deviceId);
    $queryParts = array_filter([$provider, $device, $hardwareId ?: $inf, $version, 'official driver']);
    $query = trim(implode(' ', $queryParts));
    if ($query === '') {
        return '';
    }
    $catalogQuery = $hardwareId ?: ($device ?: $inf);
    $html = '<div class="actions" style="margin-top:10px">';
    if ($catalogQuery !== '') {
        $html .= '<a class="btn" target="_blank" rel="noopener" href="https://www.catalog.update.microsoft.com/Search.aspx?q=' . rawurlencode($catalogQuery) . '">Microsoft Update Catalog</a>';
    }
    $html .= '<a class="btn" target="_blank" rel="noopener" href="https://www.google.com/search?q=' . rawurlencode($query) . '">Cari Driver Resmi</a>';
    $html .= '</div><p class="muted" style="margin:6px 0 0">Pilih driver dari vendor resmi/Microsoft. Jangan install dari situs driver pack tidak resmi.</p>';
    return $html;
}

function driver_hardware_id(string $deviceId): string
{
    if ($deviceId === '') {
        return '';
    }
    if (preg_match('/(VEN_[A-F0-9]{4}&DEV_[A-F0-9]{4})/i', $deviceId, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/(VID_[A-F0-9]{4}&PID_[A-F0-9]{4})/i', $deviceId, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

function background_display_assessment(array $item): array
{
    $score = 0;
    $reasons = [];
    $reasonText = (string)($item['Reasons'] ?? '');
    $path = (string)($item['Path'] ?? ($item['ExecutablePath'] ?? ''));
    $command = (string)($item['CommandLine'] ?? '');
    if ($reasonText !== '') {
        $reasons[] = $reasonText;
        $score += 25;
    }
    if (preg_match('/\\\\AppData\\\\Local\\\\Temp\\\\|\\\\Windows\\\\Temp\\\\/i', $path)) {
        $score += 30;
        $reasons[] = 'Lokasi proses berada di folder temp.';
    }
    if (preg_match('/powershell.+(-enc|-encodedcommand)|frombase64string|downloadstring|invoke-webrequest|bitsadmin|certutil.+-urlcache|mshta|wscript|cscript/i', $command)) {
        $score += 45;
        $reasons[] = 'Command line mirip pola downloader/script tersembunyi.';
    }
    if ((float)($item['MemoryMB'] ?? 0) > 800) {
        $score += 10;
        $reasons[] = 'Pemakaian memory tinggi, cek apakah sesuai aplikasi user.';
    }
    return risk_assessment_result($score, $reasons, 'Validasi proses, lokasi file, publisher, dan kebutuhan user sebelum disable.');
}

function startup_item_display_assessment(array $item): array
{
    $score = 0;
    $reasons = [];
    $command = (string)($item['Command'] ?? '');
    $location = (string)($item['Location'] ?? '');
    if (preg_match('/\\\\AppData\\\\Local\\\\Temp\\\\|\\\\Windows\\\\Temp\\\\/i', $command)) {
        $score += 35;
        $reasons[] = 'Startup berjalan dari folder temp.';
    }
    if (preg_match('/\\\\AppData\\\\Roaming\\\\/i', $command) && !preg_match('/OneDrive|Teams|Spotify|Telegram|WhatsApp|Zoom/i', $command)) {
        $score += 18;
        $reasons[] = 'Startup berjalan dari AppData Roaming dan perlu validasi.';
    }
    if (preg_match('/powershell.+(-enc|-encodedcommand)|frombase64string|downloadstring|invoke-webrequest|bitsadmin|certutil.+-urlcache|mshta|wscript|cscript/i', $command)) {
        $score += 45;
        $reasons[] = 'Command startup memakai pola script/downloader mencurigakan.';
    }
    if (preg_match('/Run/i', $location)) {
        $score += 5;
        $reasons[] = 'Item auto-start dari registry/run location.';
    }
    return risk_assessment_result($score, $reasons, 'Pastikan startup ini memang dibutuhkan. Disable hanya setelah dikonfirmasi dengan user/admin.');
}

function risk_assessment_result(int $score, array $reasons, string $defaultAction): array
{
    $score = min(100, max(0, $score));
    $level = 'Low';
    if ($score >= 60) {
        $level = 'High';
        $action = 'Prioritas review. Disable sementara atau isolasi bila tidak dikenal, lalu cek hash/publisher/path.';
    } elseif ($score >= 30) {
        $level = 'Medium';
        $action = $defaultAction;
    } else {
        $action = 'Monitor saja bila sesuai aplikasi user.';
    }
    if (!$reasons) {
        $reasons[] = 'Tidak ada indikator mencurigakan kuat dari rule offline.';
    }
    return ['level' => $level, 'score' => $score, 'reasons' => implode(' ', array_unique($reasons)), 'action' => $action];
}

function normalize_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    if ($value === []) {
        return [];
    }
    $isList = array_keys($value) === range(0, count($value) - 1);
    return $isList ? $value : [$value];
}

function summarize_items(mixed $value): string
{
    $items = normalize_list($value);
    if (!$items && is_array($value)) {
        $items = [$value];
    }
    $parts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            $parts[] = (string)$item;
            continue;
        }
        $size = isset($item['Size']) ? ' (' . round(((float)$item['Size']) / 1000000000, 0) . ' GB)' : '';
        $parts[] = trim(($item['Model'] ?? 'Storage') . $size . ' - ' . ($item['InterfaceType'] ?? '-') . ' - ' . ($item['Status'] ?? '-'));
    }
    return implode("\n", $parts);
}

function summarize_gpu(mixed $value): string
{
    $items = normalize_list($value);
    if (!$items && is_array($value)) {
        $items = [$value];
    }
    $parts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            $parts[] = (string)$item;
            continue;
        }
        $ram = isset($item['AdapterRAM']) && (float)$item['AdapterRAM'] > 0 ? ' (' . round(((float)$item['AdapterRAM']) / 1073741824, 2) . ' GB)' : '';
        $res = (!empty($item['CurrentHorizontalResolution']) && !empty($item['CurrentVerticalResolution'])) ? ' ' . $item['CurrentHorizontalResolution'] . 'x' . $item['CurrentVerticalResolution'] : '';
        $parts[] = trim(($item['Name'] ?? 'GPU') . $ram . $res . ' - Driver ' . ($item['DriverVersion'] ?? '-'));
    }
    return $parts ? implode("\n", $parts) : '-';
}

function legacy_storage_write(array $data): string
{
    return isset($data['storage_write_8mb_ms']) ? round((float)$data['storage_write_8mb_ms'], 2) . ' ms legacy' : '-';
}

function benchmark_summary(array $data): string
{
    $notes = [];
    if (isset($data['storage_write_mbps']) && (float)$data['storage_write_mbps'] < 80) {
        $notes[] = 'Storage write rendah, cek mode controller, health SSD/HDD, dan ruang kosong.';
    }
    if (isset($data['ram_copy_mbps']) && (float)$data['ram_copy_mbps'] < 1500) {
        $notes[] = 'Throughput RAM rendah untuk benchmark ringan, cek beban background saat tes.';
    }
    if (isset($data['cpu_quick_ms']) && (float)$data['cpu_quick_ms'] > 2500) {
        $notes[] = 'CPU quick test lambat, cek thermal throttling atau proses background.';
    }
    return $notes ? implode(' ', $notes) : 'Benchmark ringan berada dalam kondisi wajar. Gunakan sebagai pembanding antar PC dengan metode yang sama.';
}

function format_value(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'Ya' : 'Tidak';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return (string)$value;
}

function labelize(string $value): string
{
    return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $value)));
}

function ingest_analysis_payload(PDO $pdo, array $payload): array
{
    $pcId = (string)$payload['pc_id'];
    $stmt = $pdo->prepare('SELECT pc_id FROM pcs WHERE pc_id=?');
    $stmt->execute([$pcId]);
    if (!$stmt->fetch()) {
        $securityCode = strtoupper(chr(65 + (crc32($pcId) % 26)) . (crc32($pcId . '-pcconnect') % 10));
        $pdo->prepare('INSERT INTO pcs (pc_id, security_code, owner_name, computer_name) VALUES (?, ?, ?, ?)')->execute([$pcId, $securityCode, $payload['owner'] ?? 'Unknown', $payload['computer_name'] ?? null]);
    }
    $summary = analytical_summary($payload);
    $pdo->prepare('UPDATE pcs SET computer_name=?, general_specs=?, software=?, device_management=?, benchmark=?, startup_analysis=?, ai_recommendation=?, last_analyzed_at=NOW() WHERE pc_id=?')->execute([
        $payload['computer_name'] ?? null,
        json_encode($payload['hardware'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($payload['software'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($payload['devices'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($payload['benchmark'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($payload['startup'] ?? [], JSON_UNESCAPED_UNICODE),
        $summary['recommendation'],
        $pcId,
    ]);
    $pdo->prepare('INSERT INTO analysis_runs (pc_id, payload, hardware_score, software_score, device_score, benchmark_score, startup_score, recommendation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $pcId,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        $summary['hardware_score'],
        $summary['software_score'],
        $summary['device_score'],
        $summary['benchmark_score'],
        $summary['startup_score'],
        $summary['recommendation'],
    ]);
    return ['pc_id' => $pcId, 'summary' => $summary];
}

function mobile_dashboard(PDO $pdo): void
{
    $user = require_role(['admin', 'technician']);
    expire_stale_mobile_work_or_logout($pdo, $user);
    sync_completed_mobile_schedules($pdo, (int)$user['id']);
    render_mobile_header('Dashboard Teknisi', $user);
    $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(status='completed') done, SUM(status<>'completed' AND arrival_at IS NOT NULL AND EXISTS (SELECT 1 FROM maintenance_reports r WHERE r.schedule_id=maintenance_schedules.id)) waiting_end_scan, SUM(status<>'completed' AND arrival_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM maintenance_reports r WHERE r.schedule_id=maintenance_schedules.id)) progress, SUM(status<>'completed' AND arrival_at IS NULL) pending FROM maintenance_schedules WHERE technician_id=? AND scheduled_date<=CURDATE()");
    $stmt->execute([$user['id']]);
    $stats = $stmt->fetch();
    $openTotal = (int)($stats['progress'] ?? 0) + (int)($stats['waiting_end_scan'] ?? 0) + (int)($stats['pending'] ?? 0);
    echo '<section class="panel"><h1>Dashboard</h1><p class="muted">Halo, ' . e($user['name']) . '</p><div class="grid two"><div class="stat"><strong>' . e($openTotal) . '</strong><span>Job Terbuka</span></div><div class="stat"><strong>' . e($stats['done'] ?? 0) . '</strong><span>Done</span></div><div class="stat"><strong>' . e($stats['progress'] ?? 0) . '</strong><span>Progress Checklist</span></div><div class="stat"><strong>' . e($stats['pending'] ?? 0) . '</strong><span>Pending Scan Mulai</span></div></div></section>';
    echo '<section class="panel"><div class="actions"><a class="btn primary" href="' . route_url('mobile_schedule') . '">List PC Schedule</a><a class="btn" href="' . route_url('logout') . '">Logout</a></div></section>';
    render_mobile_footer();
}

function schedule_has_event(PDO $pdo, int $scheduleId, string $eventType): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM maintenance_timeline WHERE schedule_id=? AND event_type=?');
    $stmt->execute([$scheduleId, $eventType]);
    return (int)$stmt->fetchColumn() > 0;
}

function maintenance_scan_token(array $schedule, string $phase): string
{
    $seed = implode('|', [
        (string)($schedule['asset_id'] ?? $schedule['pc_id'] ?? $schedule['printer_id'] ?? ''),
        (string)$schedule['id'],
        (string)$schedule['scheduled_date'],
        date('Y-m-d'),
        $phase,
        session_id(),
    ]);
    return strtoupper(substr(hash('sha256', $seed), 0, 10));
}

function ensure_photo_challenge(PDO $pdo, int $scheduleId, ?string $existingCode = null): string
{
    $existingCode = trim((string)$existingCode);
    if ($existingCode !== '') {
        return $existingCode;
    }
    $code = (string)random_int(10, 99);
    try {
        $pdo->prepare('UPDATE maintenance_schedules SET photo_challenge_code=?, photo_challenge_generated_at=NOW() WHERE id=?')->execute([$code, $scheduleId]);
    } catch (Throwable $ignored) {
    }
    return $code;
}

function sync_completed_mobile_schedules(PDO $pdo, int $technicianId): void
{
    try {
        $pdo->prepare("UPDATE maintenance_schedules s SET s.status='completed', s.completed_at=COALESCE(s.completed_at,NOW()), s.locked_at=COALESCE(s.locked_at,NOW()) WHERE s.technician_id=? AND s.status<>'completed' AND EXISTS (SELECT 1 FROM maintenance_timeline t WHERE t.schedule_id=s.id AND t.event_type='qr_end_scan')")->execute([$technicianId]);
        $pdo->prepare("UPDATE maintenance_reports r JOIN maintenance_schedules s ON s.id=r.schedule_id SET r.locked_at=COALESCE(r.locked_at,NOW()) WHERE s.technician_id=? AND s.status<>'completed'")->execute([$technicianId]);
        $pdo->prepare("UPDATE maintenance_schedules s SET s.status='completed', s.completed_at=COALESCE(s.completed_at,NOW()), s.locked_at=COALESCE(s.locked_at,NOW()) WHERE s.technician_id=? AND s.status<>'completed' AND EXISTS (SELECT 1 FROM maintenance_reports r WHERE r.schedule_id=s.id)")->execute([$technicianId]);
    } catch (Throwable $ignored) {
    }
}

function expire_stale_mobile_work_or_logout(PDO $pdo, array $user, ?int $scheduleId = null): void
{
    if (($user['role'] ?? '') !== 'technician') {
        return;
    }
    $sql = "SELECT id FROM maintenance_schedules WHERE technician_id=? AND status<>'completed' AND arrival_at IS NOT NULL AND arrival_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    $params = [(int)$user['id']];
    if ($scheduleId !== null && $scheduleId > 0) {
        $sql .= ' AND id=?';
        $params[] = $scheduleId;
    }
    $sql .= ' ORDER BY arrival_at ASC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expiredId = (int)($stmt->fetchColumn() ?: 0);
    if ($expiredId <= 0) {
        return;
    }

    try {
        $pdo->prepare('UPDATE schedule_jobs SET is_done=0, done_at=NULL, note=NULL WHERE schedule_id=?')->execute([$expiredId]);
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->prepare('DELETE FROM maintenance_reports WHERE schedule_id=? AND locked_at IS NULL')->execute([$expiredId]);
    } catch (Throwable $ignored) {
    }
    add_timeline($pdo, $expiredId, 'mobile_session_expired', 'Scan mulai expired karena teknisi tidak scan selesai dalam 1 jam. Schedule di-reset dan foto harus diambil ulang.', (int)$user['id']);
    $pdo->prepare("UPDATE maintenance_schedules SET status='scheduled', arrival_at=NULL, arrival_ip=NULL, arrival_user_agent=NULL, arrival_lat=NULL, arrival_lng=NULL, arrival_browser=NULL, photo_challenge_code=NULL, photo_challenge_generated_at=NULL WHERE id=? AND status<>'completed'")->execute([$expiredId]);
    unset($_SESSION['user_id'], $_SESSION['pending_mobile_code']);
    flash('Sesi maintenance expired karena lebih dari 1 jam belum scan selesai. Silakan login ulang dan mulai dari Scan Mulai. Foto maintenance harus diambil ulang.', 'err');
    redirect_to('login');
}

function mobile_schedule(PDO $pdo): void
{
    $user = require_role(['admin', 'technician']);
    expire_stale_mobile_work_or_logout($pdo, $user);
    sync_completed_mobile_schedules($pdo, (int)$user['id']);
    render_mobile_header('Schedule', $user);
    $stmt = $pdo->prepare("SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, EXISTS (SELECT 1 FROM maintenance_reports r WHERE r.schedule_id=s.id) has_report FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.technician_id=? AND s.status<>'completed' ORDER BY s.scheduled_date ASC, s.id ASC");
    $stmt->execute([$user['id']]);
    echo '<section class="panel"><h1>Schedule Saya</h1><p class="muted">Pilih aset sesuai label PC/Printer, lalu scan QR sebelum mulai pekerjaan.</p></section>';
    $hasRows = false;
    foreach ($stmt as $row) {
        $hasRows = true;
        $canOpen = !empty($row['arrival_at']);
        $waitingEndScan = $canOpen && (int)($row['has_report'] ?? 0) === 1;
        echo '<section class="panel"><div class="split"><div><strong>' . e($row['asset_id']) . '</strong><br><span class="muted">' . e($row['owner_name']) . ' - ' . e($row['computer_name'] ?: '-') . '</span><br><span class="muted">Tanggal kerja: ' . e($row['scheduled_date']) . '</span><br><span class="badge">' . e($row['asset_type'] ?? 'pc') . '</span> <span class="badge">' . e($row['status']) . '</span></div><div class="actions">';
        if ($waitingEndScan) {
            echo '<a class="btn good" href="' . route_url('mobile_scan', ['id' => $row['id'], 'phase' => 'end']) . '">Scan Selesai</a>';
        } else {
            echo $canOpen ? '<a class="btn primary" href="' . route_url('mobile_job', ['id' => $row['id']]) . '">Buka</a>' : '<a class="btn primary" href="' . route_url('mobile_scan', ['id' => $row['id'], 'phase' => 'start']) . '">Scan Mulai</a>';
        }
        echo '</div></div>';
        if ($waitingEndScan) {
            echo '<p class="muted">Checklist dan foto sudah tersimpan. Scan QR sekali lagi untuk mengubah status menjadi Done.</p>';
        }
        if (!empty($row['notes'])) {
            echo '<p class="muted">' . e($row['notes']) . '</p>';
        }
        echo '</section>';
    }
    if (!$hasRows) {
        echo '<section class="panel"><p class="muted">Tidak ada schedule maintenance terbuka untuk user Anda.</p></section>';
    }
    render_mobile_footer();
}

function mobile_scan(PDO $pdo): void
{
    $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
    $scheduleId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $phase = (string)($_GET['phase'] ?? $_POST['phase'] ?? 'start');
    if (!in_array($phase, ['start', 'end'], true)) {
        $phase = 'start';
    }
    if (!current_user()) {
        if ($code !== '') {
            $_SESSION['pending_mobile_code'] = $code;
        }
        redirect_to('login');
    }
    $user = require_role(['admin', 'technician']);
    expire_stale_mobile_work_or_logout($pdo, $user, $scheduleId > 0 ? $scheduleId : null);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        [$assetType, $assetId] = find_asset_by_code($pdo, $code);
        if ($assetId === '') {
            flash('INVALID QR. Kode asset tidak valid.', 'err');
            redirect_to('mobile_scan');
        }
        $assetColumn = $assetType === 'printer' ? 'printer_id' : 'pc_id';
        if ($scheduleId > 0) {
            if ($user['role'] === 'admin') {
                $stmt = $pdo->prepare("SELECT * FROM maintenance_schedules WHERE id=? AND $assetColumn=? LIMIT 1");
                $stmt->execute([$scheduleId, $assetId]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM maintenance_schedules WHERE id=? AND $assetColumn=? AND technician_id=? LIMIT 1");
                $stmt->execute([$scheduleId, $assetId, $user['id']]);
            }
        } else {
            if ($user['role'] === 'admin') {
                $stmt = $pdo->prepare("SELECT * FROM maintenance_schedules WHERE $assetColumn=? AND status<>'completed' ORDER BY scheduled_date ASC, id ASC LIMIT 1");
                $stmt->execute([$assetId]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM maintenance_schedules WHERE $assetColumn=? AND technician_id=? AND status<>'completed' ORDER BY scheduled_date ASC, id ASC LIMIT 1");
                $stmt->execute([$assetId, $user['id']]);
            }
        }
        $schedule = $stmt->fetch();
        if (!$schedule) {
            flash($user['role'] === 'admin' ? 'Schedule terbuka tidak ada untuk aset ini.' : 'Schedule tidak ada untuk aset ini atau bukan assignment Anda.', 'err');
            redirect_to('mobile_schedule');
        }
        $lat = ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null;
        $lng = ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null;
        if ($assetType === 'pc') {
            $locationError = validate_pc_scan_location($pdo, $assetId, $lat, $lng);
            if ($locationError !== null) {
                flash($locationError, 'err');
                redirect_to('mobile_scan', ['id' => $schedule['id'], 'phase' => $phase]);
            }
        } elseif ($assetType === 'printer') {
            $locationError = validate_printer_scan_location($pdo, $assetId, $lat, $lng);
            if ($locationError !== null) {
                flash($locationError, 'err');
                redirect_to('mobile_scan', ['id' => $schedule['id'], 'phase' => $phase]);
            }
        }
        if ($phase === 'end') {
            if (empty($schedule['arrival_at'])) {
                flash('Scan mulai belum dilakukan untuk schedule ini.', 'err');
                redirect_to('mobile_scan', ['id' => $schedule['id'], 'phase' => 'start']);
            }
            $reportStmt = $pdo->prepare('SELECT id FROM maintenance_reports WHERE schedule_id=? ORDER BY id DESC LIMIT 1');
            $reportStmt->execute([$schedule['id']]);
            $reportId = (int)($reportStmt->fetchColumn() ?: 0);
            if ($reportId <= 0) {
                flash('Simpan checklist dan foto terlebih dahulu, lalu scan QR selesai.', 'err');
                redirect_to('mobile_job', ['id' => $schedule['id']]);
            }
            $scanToken = maintenance_scan_token($schedule, 'end');
            add_timeline($pdo, (int)$schedule['id'], 'qr_end_scan', 'QR selesai tervalidasi. Token: ' . $scanToken, (int)$user['id']);
            $pdo->prepare("UPDATE maintenance_reports SET locked_at=NOW() WHERE id=?")->execute([$reportId]);
            $pdo->prepare("UPDATE maintenance_schedules SET status='completed', completed_at=NOW(), locked_at=NOW() WHERE id=?")->execute([$schedule['id']]);
            flash('Scan selesai valid. Maintenance completed dan report locked. Secret label tetap sama, token sistem berubah otomatis.');
            redirect_to('mobile_job', ['id' => $schedule['id']]);
        }
        if (empty($schedule['arrival_at']) || !in_array($schedule['status'], ['validated', 'in_progress', 'reopened'], true)) {
            $pdo->prepare("UPDATE maintenance_schedules SET status='in_progress', arrival_at=COALESCE(arrival_at,NOW()), arrival_ip=?, arrival_user_agent=?, arrival_lat=?, arrival_lng=?, arrival_browser=? WHERE id=?")->execute([
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $lat,
                $lng,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
                $schedule['id'],
            ]);
            $scanToken = maintenance_scan_token($schedule, 'start');
            add_timeline($pdo, (int)$schedule['id'], 'qr_start_scan', 'QR mulai tervalidasi dan pekerjaan dibuka. Token: ' . $scanToken, (int)$user['id']);
        }
        flash('QR mulai valid. Checklist maintenance terbuka.');
        redirect_to('mobile_job', ['id' => $schedule['id']]);
    }

    render_mobile_header('Scan QR', $user);
    if (($user['role'] ?? '') === 'technician' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $code = '';
    }
    $scanTitle = $phase === 'end' ? 'Scan QR Selesai' : 'Scan QR Mulai';
    $codeReadonly = ($user['role'] ?? '') === 'technician' ? ' readonly' : '';
    $manualNote = ($user['role'] ?? '') === 'technician' ? '<p class="muted">Untuk teknisi, Maintenance Asset ID hanya bisa terisi dari live scan kamera. Input manual dan foto QR dari galeri dinonaktifkan.</p>' : '<p class="muted">Admin masih dapat mengetik Maintenance Asset ID untuk troubleshooting.</p>';
    echo '<section class="panel"><h1>' . e($scanTitle) . '</h1><p class="muted">QR wajib berisi Maintenance Asset ID + secret tetap seperti MNT-PC000123-A7. QR lama PC000123-A7 atau PRN000001-AB12CD34 tetap diterima selama masa transisi. Sistem juga membuat token log otomatis berdasarkan aset, schedule, tanggal, dan fase scan.</p><div id="reader" style="display:none;width:100%;max-width:420px"></div><video id="video" class="scan-video" autoplay muted playsinline></video><p id="cameraStatus" class="camera-note">Tekan Mulai Live Scan. Kamera dan GPS wajib aktif untuk teknisi.</p><p><button type="button" id="startCamera" class="btn primary">Mulai Live Scan</button></p>' . $manualNote . '<form method="post" id="scanForm"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . e($scheduleId) . '"><input type="hidden" name="phase" value="' . e($phase) . '"><input type="hidden" id="lat" name="lat"><input type="hidden" id="lng" name="lng"><label>Maintenance Asset ID<input id="code" name="code" value="' . e($code) . '" placeholder="Terisi otomatis dari live scan QR" required' . $codeReadonly . '></label><button class="btn primary">Validasi QR</button></form></section>';
    echo '<script src="https://unpkg.com/html5-qrcode" onerror="window.__qrLibFailed=true"></script><script>(function(){var code=document.getElementById("code"),status=document.getElementById("cameraStatus"),video=document.getElementById("video"),reader=document.getElementById("reader"),detector=null,timer=null,html5=null,submitted=false,gpsReady=false;function extractCode(raw){var v=String(raw||"").trim();try{var u=new URL(v,window.location.href);if(u.searchParams.get("code")){v=u.searchParams.get("code");}}catch(e){if(v.indexOf("code=")>=0){v=v.split("code=").pop().split("&")[0];}}v=decodeURIComponent(v).trim().toUpperCase();var m=v.match(/[A-Z0-9_-]+-[A-Z0-9]{2,12}/);return m?m[0]:v;}function setCode(raw){if(submitted){return;}var v=extractCode(raw);if(v){code.value=v;status.textContent="QR terbaca: "+v+". Memvalidasi GPS dan schedule...";if(navigator.vibrate){navigator.vibrate(120);}submitted=true;setTimeout(function(){document.getElementById("scanForm").submit();},500);}}function insecure(){return location.protocol!=="https:"&&location.hostname!=="localhost"&&location.hostname!=="127.0.0.1";}if(navigator.geolocation){navigator.geolocation.getCurrentPosition(function(p){document.getElementById("lat").value=p.coords.latitude.toFixed(7);document.getElementById("lng").value=p.coords.longitude.toFixed(7);gpsReady=true;status.textContent="GPS siap. Akurasi sekitar "+Math.round(p.coords.accuracy)+" meter.";},function(){status.textContent="GPS belum aktif. Izinkan Location agar scan aset yang punya titik lokasi bisa divalidasi.";},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}if("BarcodeDetector" in window){detector=new BarcodeDetector({formats:["qr_code"]});}document.getElementById("startCamera").onclick=async function(){if(insecure()){status.textContent="Live scan kamera diblokir browser karena halaman masih HTTP. Aktifkan HTTPS di server.";return;}if(!gpsReady&&navigator.geolocation){status.textContent="Menunggu GPS akurat. Jika muncul izin Location, pilih Allow.";navigator.geolocation.getCurrentPosition(function(p){document.getElementById("lat").value=p.coords.latitude.toFixed(7);document.getElementById("lng").value=p.coords.longitude.toFixed(7);gpsReady=true;status.textContent="GPS siap. Arahkan kamera ke QR.";},function(){status.textContent="GPS belum aktif. Scan aset dengan titik lokasi akan ditolak sampai Location diizinkan.";},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}if(window.Html5Qrcode){try{video.style.display="none";reader.style.display="block";status.textContent="Live scanner aktif. Izinkan kamera lalu arahkan ke QR.";html5=new Html5Qrcode("reader");await html5.start({facingMode:"environment"},{fps:10,qrbox:{width:240,height:240}},function(decoded){setCode(decoded);});return;}catch(e){status.textContent="Live scanner gagal dibuka. Coba izinkan kamera, pastikan HTTPS aktif, lalu tekan Mulai Live Scan lagi.";}}if(!detector){status.textContent="Browser belum mendukung live QR scanner. Gunakan Chrome/Edge terbaru di HTTPS.";return;}if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){status.textContent="Kamera tidak tersedia di browser ini. Gunakan Chrome/Edge terbaru di HTTPS.";return;}try{var s=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}}});video.srcObject=s;video.style.display="block";status.textContent="Kamera aktif. Arahkan ke QR label.";clearInterval(timer);timer=setInterval(async function(){try{var r=await detector.detect(video);if(r&&r[0]){setCode(r[0].rawValue);}}catch(e){}},800);}catch(e){status.textContent="Kamera diblokir/tidak tersedia. Izinkan kamera dan gunakan HTTPS.";}};})();</script>';
    render_mobile_footer();
}

function mobile_job(PDO $pdo): void
{
    $user = require_role(['admin', 'technician']);
    $id = (int)($_GET['id'] ?? 0);
    expire_stale_mobile_work_or_logout($pdo, $user, $id > 0 ? $id : null);
    if ($user['role'] === 'admin') {
        $stmt = $pdo->prepare('SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, COALESCE(p.physical_condition, pr.physical_condition) physical_condition, p.general_specs, p.benchmark, p.ai_recommendation FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.id=?');
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare('SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, COALESCE(p.physical_condition, pr.physical_condition) physical_condition, p.general_specs, p.benchmark, p.ai_recommendation FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.id=? AND s.technician_id=?');
        $stmt->execute([$id, $user['id']]);
    }
    $schedule = $stmt->fetch();
    if (!$schedule) {
        http_response_code(404);
        exit('Schedule tidak ditemukan.');
    }
    if (empty($schedule['arrival_at'])) {
        flash('Scan QR asset terlebih dahulu sebelum checklist.', 'err');
        redirect_to('mobile_schedule');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($schedule['status'] === 'completed') {
            flash('Report sudah locked dan tidak bisa diubah.', 'err');
            redirect_to('mobile_job', ['id' => $id]);
        }
        $pdo->prepare('UPDATE schedule_jobs SET is_done=0, done_at=NULL WHERE schedule_id=?')->execute([$id]);
        save_schedule_job_notes($pdo, $id, $_POST['job_notes'] ?? []);
        foreach ($_POST['done'] ?? [] as $jobId) {
            $pdo->prepare('UPDATE schedule_jobs SET is_done=1, done_at=NOW() WHERE schedule_id=? AND job_id=?')->execute([$id, (int)$jobId]);
        }
        $uploadMeta = [];
        $before = save_uploaded_photos('before_photos', $id, $uploadMeta);
        $process = save_uploaded_photos('process_photos', $id, $uploadMeta);
        $after = save_uploaded_photos('after_photos', $id, $uploadMeta);
        if (!$before || !$after) {
            flash('Foto sebelum dan foto sesudah wajib diambil sebelum scan QR selesai.', 'err');
            redirect_to('mobile_job', ['id' => $id]);
        }
        $photoAuditMeta = build_photo_audit_meta($pdo, $id, $uploadMeta, (string)($_POST['photo_evidence_meta'] ?? ''));
        $liveCameraError = photo_evidence_live_camera_error($photoAuditMeta);
        if ($liveCameraError !== null) {
            flash($liveCameraError, 'err');
            redirect_to('mobile_job', ['id' => $id]);
        }
        $durationError = photo_evidence_duration_error($photoAuditMeta);
        if ($durationError !== null) {
            $_SESSION['duration_warning_schedule_id'] = $id;
            flash($durationError, 'err');
            redirect_to('mobile_job', ['id' => $id, 'duration_warning' => 1]);
        }
        $photoLocationError = photo_evidence_location_error($photoAuditMeta);
        if ($photoLocationError !== null) {
            flash($photoLocationError, 'err');
            redirect_to('mobile_job', ['id' => $id]);
        }
        $signaturePath = save_signature_image((string)($_POST['signature_data'] ?? ''), $id);
        $hashData = [
            'schedule_id' => $id,
            'pc_id' => $schedule['asset_id'],
            'technician_id' => $user['id'],
            'photo_audit_meta' => $photoAuditMeta,
            'done' => $_POST['done'] ?? [],
            'condition_rating' => $_POST['condition_rating'] ?? '',
            'notes' => $_POST['additional_notes'] ?? '',
            'signature_name' => $_POST['signature_name'] ?? '',
            'completed_at' => date('c'),
        ];
        $hash = report_hash($hashData);
        $pdo->prepare('DELETE FROM maintenance_reports WHERE schedule_id=? AND locked_at IS NULL')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO maintenance_reports (schedule_id, technician_id, condition_rating, physical_condition, additional_notes, before_photos, process_photos, after_photos, photo_challenge_code, photo_audit_meta, signature_name, signature_path, report_hash, locked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL)');
        $stmt->execute([
            $id,
            $user['id'],
            $_POST['condition_rating'] ?? null,
            trim((string)($_POST['physical_condition'] ?? '')),
            trim((string)($_POST['additional_notes'] ?? '')),
            json_encode($before, JSON_UNESCAPED_SLASHES),
            json_encode($process, JSON_UNESCAPED_SLASHES),
            json_encode($after, JSON_UNESCAPED_SLASHES),
            json_encode($photoAuditMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            trim((string)($_POST['signature_name'] ?? '')),
            $signaturePath,
            $hash,
        ]);
        $pdo->prepare("UPDATE maintenance_reports SET locked_at=NOW() WHERE schedule_id=? AND locked_at IS NULL")->execute([$id]);
        $pdo->prepare("UPDATE maintenance_schedules SET status='completed', completed_at=NOW(), locked_at=NOW() WHERE id=?")->execute([$id]);
        if (($schedule['asset_type'] ?? 'pc') === 'printer') {
            $pdo->prepare('UPDATE printers SET physical_condition=? WHERE prn_id=?')->execute([trim((string)($_POST['physical_condition'] ?? '')), $schedule['printer_id']]);
        } else {
            $pdo->prepare('UPDATE pcs SET physical_condition=? WHERE pc_id=?')->execute([trim((string)($_POST['physical_condition'] ?? '')), $schedule['pc_id']]);
        }
        $evidenceAudit = photo_evidence_audit_summary($pdo, array_merge($latestReport ?? [], ['schedule_id' => $id, 'photo_audit_meta' => json_encode($photoAuditMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'before_photos' => json_encode($before, JSON_UNESCAPED_SLASHES), 'after_photos' => json_encode($after, JSON_UNESCAPED_SLASHES)]));
        add_timeline($pdo, $id, 'work_completed', 'Checklist dan foto tersimpan. Schedule langsung completed dan report locked. Photo evidence score: ' . $evidenceAudit['score'] . '/100. Hash: ' . $hash, (int)$user['id']);
        flash('Checklist dan foto tersimpan. Schedule maintenance sudah complete dan report locked. Audit foto: ' . $evidenceAudit['score'] . '/100.', 'ok');
        redirect_to('mobile_job', ['id' => $id]);
    }

    render_mobile_header('Checklist', $user);
    $locked = $schedule['status'] === 'completed';
    $endScanned = schedule_has_event($pdo, $id, 'qr_end_scan');
    echo '<section class="panel"><h1>' . e($schedule['asset_id']) . '</h1><p>' . e($schedule['owner_name']) . ' - ' . e($schedule['computer_name'] ?: '-') . '</p><p class="muted">Tanggal kerja: ' . e($schedule['scheduled_date']) . '</p><span class="badge">' . e($schedule['asset_type'] ?? 'pc') . '</span> <span class="badge">' . e($schedule['status']) . '</span></section>';
    echo smart_maintenance_card($schedule);
    $reportStmt = $pdo->prepare('SELECT * FROM maintenance_reports WHERE schedule_id=? ORDER BY id DESC LIMIT 1');
    $reportStmt->execute([$id]);
    $latestReport = $reportStmt->fetch() ?: [];
    $minimumMinutes = schedule_minimum_photo_minutes($pdo, $id);
    $noteSelect = schedule_job_note_supported($pdo) ? 'sj.note' : "'' note";
    $jobs = $pdo->prepare("SELECT j.*, sj.is_done, sj.done_at, $noteSelect FROM schedule_jobs sj JOIN maintenance_jobs j ON j.id=sj.job_id WHERE sj.schedule_id=? ORDER BY j.title");
    $jobs->execute([$id]);
    echo '<section class="panel ' . ($locked ? 'readonly' : '') . '"><h2>Checklist Job</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" id="photoEvidenceMeta" name="photo_evidence_meta" value="">';
    $hasJobs = false;
    foreach ($jobs as $job) {
        $hasJobs = true;
        echo '<div class="check-row"><input type="checkbox" name="done[]" value="' . e($job['id']) . '"' . ($job['is_done'] ? ' checked' : '') . ($locked ? ' disabled' : '') . '><div><span class="check-title">' . e($job['title']) . '</span>' . ($job['done_at'] ? '<small class="muted">Dikerjakan: ' . e($job['done_at']) . '</small>' : '') . '<label class="check-note-label">Keterangan<input name="job_notes[' . e($job['id']) . ']" value="' . e($job['note'] ?? '') . '" placeholder="Boleh kosong" ' . ($locked ? 'readonly' : '') . '></label></div></div>';
    }
    if (!$hasJobs) {
        echo '<p class="danger-text">Belum ada jobdesk dipilih untuk schedule ini. Admin perlu edit/buat ulang schedule dengan memilih jobdesk maintenance.</p>';
    }
    echo '<p id="photoCompressStatus" class="camera-note">Foto wajib dari kamera langsung, diambil berurutan di lokasi aset. Sistem menyimpan waktu browser, GPS foto, sequence timing, dan membandingkan before/after. Jarak waktu foto sebelum ke sesudah minimal ' . e($minimumMinutes) . ' menit.</p>';
    if (($_GET['duration_warning'] ?? '') === '1' && (int)($_SESSION['duration_warning_schedule_id'] ?? 0) === $id && !$locked) {
        echo '<label class="danger-text"><input type="checkbox" id="durationOverride" value="1"> Saya menyetujui warning durasi pekerjaan kurang dari estimasi jobdesk dan tetap menyimpan report.</label>';
    }
    echo '<label>Foto Sebelum - Kamera Langsung<input class="photo-input" data-photo-group="before" data-photo-slot="1" type="file" name="before_photos[]" accept="image/*" capture="environment" required ' . ($locked ? 'disabled' : '') . '></label>';
    echo '<div class="grid two"><label>Foto Proses 1 (Opsional)<input class="photo-input" data-photo-group="process" data-photo-slot="1" type="file" name="process_photos[]" accept="image/*" capture="environment" ' . ($locked ? 'disabled' : '') . '></label><label>Foto Proses 2 (Opsional)<input class="photo-input" data-photo-group="process" data-photo-slot="2" type="file" name="process_photos[]" accept="image/*" capture="environment" ' . ($locked ? 'disabled' : '') . '></label></div>';
    echo '<label>Foto Sesudah - Kamera Langsung<input class="photo-input" data-photo-group="after" data-photo-slot="1" type="file" name="after_photos[]" accept="image/*" capture="environment" required ' . ($locked ? 'disabled' : '') . '></label>';
    echo '<label>Kondisi<select name="condition_rating" ' . ($locked ? 'disabled' : '') . '><option>Excellent</option><option>Good</option><option>Fair</option><option>Need Repair</option><option>Need Replace</option></select></label>';
    echo '<label>Catatan Kondisi Fisik<textarea name="physical_condition" ' . ($locked ? 'readonly' : '') . '>' . e($latestReport['physical_condition'] ?? $schedule['physical_condition'] ?? '') . '</textarea></label><label>Catatan Teknisi<textarea name="additional_notes" ' . ($locked ? 'readonly' : '') . '>' . e($latestReport['additional_notes'] ?? '') . '</textarea></label>';
    echo '<label>Nama Pemakai PC<input name="signature_name" value="' . e($latestReport['signature_name'] ?? '') . '" ' . ($locked ? 'readonly' : '') . '></label><canvas id="sig" class="signature-pad"></canvas><input type="hidden" id="signatureData" name="signature_data"><p><button type="button" class="btn" id="clearSig" ' . ($locked ? 'disabled' : '') . '>Clear Signature</button></p>';
    echo $locked ? '<p class="muted">Report locked. Schedule complete. Scan selesai: ' . ($endScanned ? 'sudah valid' : 'tidak tercatat') . '.</p><a class="btn" href="' . route_url('report_print', ['id' => $id]) . '">Lihat Report</a>' : '<button class="btn primary" onclick="document.getElementById(\'signatureData\').value=document.getElementById(\'sig\').toDataURL(\'image/png\')">Simpan Pekerjaan & Complete</button>';
    echo '</form></section>';
    if ($latestReport) {
        render_photo_block('Foto Sebelum Maintenance', $latestReport['before_photos'] ?? '');
        render_photo_block('Foto Proses Maintenance', $latestReport['process_photos'] ?? '');
        render_photo_block('Foto Sesudah Maintenance', $latestReport['after_photos'] ?? '');
    }
    echo '<script>(function(){var hidden=document.getElementById("photoEvidenceMeta"),form=hidden?hidden.form:null,evidence=[];function save(){if(hidden){var override=document.getElementById("durationOverride");hidden.value=JSON.stringify({captured_at:new Date().toISOString(),duration_override_approved:!!(override&&override.checked),items:evidence});}}function record(input){var file=input.files&&input.files[0]?input.files[0]:null,item={group:input.getAttribute("data-photo-group")||"",slot:input.getAttribute("data-photo-slot")||"",selected_at:new Date().toISOString(),live_camera_hint:input.hasAttribute("capture"),file_name:file?file.name:"",file_size:file?file.size:0,file_type:file?file.type:"",file_last_modified:file&&file.lastModified?new Date(file.lastModified).toISOString():""};evidence=evidence.filter(function(old){return !(old.group===item.group&&old.slot===item.slot);});evidence.push(item);save();if(navigator.geolocation){navigator.geolocation.getCurrentPosition(function(pos){item.gps={lat:pos.coords.latitude,lng:pos.coords.longitude,accuracy_m:pos.coords.accuracy,taken_at:new Date().toISOString()};save();},function(err){item.gps_error=err.message||"GPS tidak tersedia";save();},{enableHighAccuracy:true,timeout:10000,maximumAge:0});}}document.querySelectorAll(".photo-input").forEach(function(input){input.addEventListener("change",function(){record(input);});});var override=document.getElementById("durationOverride");if(override){override.addEventListener("change",save);}if(form){form.addEventListener("submit",save);}})();</script>';
    echo '<script>(function(){var status=document.getElementById("photoCompressStatus");async function compressFile(file){if(!file||!file.type.match(/^image\\//)){return file;}if(file.size<=1048576){return file;}var img=new Image();var url=URL.createObjectURL(file);await new Promise(function(resolve,reject){img.onload=resolve;img.onerror=reject;img.src=url;});var maxSide=1600,scale=Math.min(1,maxSide/Math.max(img.width,img.height)),w=Math.max(1,Math.round(img.width*scale)),h=Math.max(1,Math.round(img.height*scale)),canvas=document.createElement("canvas"),ctx=canvas.getContext("2d");canvas.width=w;canvas.height=h;ctx.drawImage(img,0,0,w,h);URL.revokeObjectURL(url);var quality=.82,blob=null;while(quality>=.45){blob=await new Promise(function(resolve){canvas.toBlob(resolve,"image/jpeg",quality);});if(blob&&blob.size<=1048576){break;}quality-=.08;}if(!blob){return file;}return new File([blob],file.name.replace(/\\.[^.]+$/,"")+".jpg",{type:"image/jpeg",lastModified:Date.now()});}document.querySelectorAll(".photo-input").forEach(function(input){input.addEventListener("change",async function(){if(!input.files||!input.files.length){return;}if(status){status.textContent="Mengompres foto...";}var dt=new DataTransfer();for(var i=0;i<input.files.length;i++){dt.items.add(await compressFile(input.files[i]));}input.files=dt.files;if(status){status.textContent="Foto siap upload, maksimal 1 MB per file.";} });});var c=document.getElementById("sig"),x=c.getContext("2d"),down=false;function fit(){c.width=c.clientWidth;c.height=160;x.lineWidth=2;x.lineCap="round";}fit();function pos(e){var r=c.getBoundingClientRect(),t=e.touches?e.touches[0]:e;return{x:t.clientX-r.left,y:t.clientY-r.top};}c.addEventListener("pointerdown",function(e){down=true;var p=pos(e);x.beginPath();x.moveTo(p.x,p.y);});c.addEventListener("pointermove",function(e){if(!down)return;var p=pos(e);x.lineTo(p.x,p.y);x.stroke();});window.addEventListener("pointerup",function(){down=false;});document.getElementById("clearSig").onclick=function(){x.clearRect(0,0,c.width,c.height);};})();</script>';
    render_mobile_footer();
}

function mobile_history(PDO $pdo): void
{
    $user = require_technician();
    expire_stale_mobile_work_or_logout($pdo, $user);
    render_mobile_header('History', $user);
    $stmt = $pdo->prepare("SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.technician_id=? ORDER BY s.scheduled_date DESC, s.id DESC LIMIT 80");
    $stmt->execute([$user['id']]);
    echo '<section class="panel"><h1>History</h1></section>';
    foreach ($stmt as $row) {
        echo '<section class="panel"><div class="split"><div><strong>' . e($row['asset_id']) . '</strong><br><span class="muted">' . e($row['scheduled_date']) . ' - ' . e($row['owner_name']) . '</span><br><span class="badge">' . e($row['status']) . '</span></div><a class="btn" href="' . route_url('mobile_job', ['id' => $row['id']]) . '">Buka</a></div></section>';
    }
    render_mobile_footer();
}

function smart_maintenance_card(array $schedule): string
{
    $hardware = $schedule['general_specs'] ? json_decode((string)$schedule['general_specs'], true) : [];
    $benchmark = $schedule['benchmark'] ? json_decode((string)$schedule['benchmark'], true) : [];
    $score = 100;
    if ((float)($hardware['ram_gb'] ?? 8) < 8) { $score -= 15; }
    if (isset($benchmark['storage_write_mbps']) && (float)$benchmark['storage_write_mbps'] < 80) { $score -= 20; }
    if (isset($benchmark['cpu_quick_ms']) && (float)$benchmark['cpu_quick_ms'] > 2500) { $score -= 15; }
    $html = '<section class="panel"><h2>Smart Maintenance</h2><div class="grid two"><div><strong>Health Score</strong><br>' . e(max(0, $score)) . '%</div><div><strong>RAM</strong><br>' . e($hardware['ram_gb'] ?? '-') . ' GB</div><div><strong>Storage</strong><br>' . e(isset($benchmark['storage_write_mbps']) ? round((float)$benchmark['storage_write_mbps'], 1) . ' MB/s' : '-') . '</div><div><strong>Benchmark</strong><br>' . e(isset($benchmark['cpu_quick_ms']) ? round((float)$benchmark['cpu_quick_ms'], 1) . ' ms' : '-') . '</div></div>';
    $html .= '<p><strong>AI</strong><br>' . e($schedule['ai_recommendation'] ?: 'Belum ada rekomendasi PcNalisa.') . '</p></section>';
    return $html;
}

function report_print(PDO $pdo): void
{
    $user = require_login();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, p.ai_recommendation, u.name technician FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id WHERE s.id=?');
    $stmt->execute([$id]);
    $schedule = $stmt->fetch();
    if (!$schedule) { http_response_code(404); exit('Report tidak ditemukan.'); }
    if ($user['role'] === 'technician' && (int)$schedule['technician_id'] !== (int)$user['id']) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $stmt = $pdo->prepare('SELECT * FROM maintenance_reports WHERE schedule_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    $report = $stmt->fetch() ?: [];
    if ($report) {
        $report['schedule_challenge_code'] = $schedule['photo_challenge_code'] ?? '';
        $report['schedule_status'] = $schedule['status'] ?? '';
    }
    $summary = $report ? maintenance_report_summary($pdo, $report) : [];
    render_header('Maintenance Report', $user);
    echo '<section class="panel no-print"><div class="split"><h1>Maintenance Report</h1><button class="btn primary" onclick="window.print()">Print / Save PDF</button></div></section>';
    echo '<section class="panel"><h1>Maintenance Report ' . e($schedule['asset_id']) . '</h1><table>';
    foreach ([
        'Owner' => $schedule['owner_name'],
        'Asset Type' => $schedule['asset_type'] ?? 'pc',
        'Computer/Location' => $schedule['computer_name'],
        'Teknisi' => $schedule['technician'],
        'Tanggal' => $schedule['scheduled_date'],
        'Arrival' => $schedule['arrival_at'],
        'Completed' => $schedule['completed_at'],
        'GPS' => trim((string)$schedule['arrival_lat'] . ', ' . (string)$schedule['arrival_lng'], ', '),
        'Audit Foto' => $summary ? ($summary['photo_evidence_score'] . '/100 - ' . $summary['challenge_audit_status'] . ' - ' . $summary['challenge_audit_text']) : 'Belum ada report',
        'Status' => $schedule['status'],
        'Hash' => $report['report_hash'] ?? '-',
    ] as $k => $v) {
        echo '<tr><th>' . e($k) . '</th><td>' . e($v ?: '-') . '</td></tr>';
    }
    echo '</table></section>';
    if ($summary) {
        echo '<section class="panel"><h2>Summary Maintenance</h2><table><tr><th>Pekerjaan</th><th>Status</th><th>Keterangan</th></tr>';
        foreach ($summary['checklist_rows'] as $job) {
            echo '<tr><td>' . e($job['title']) . '</td><td>' . e($job['status']) . '</td><td>' . e($job['note'] ?: '-') . '</td></tr>';
        }
        echo '</table></section>';
        echo '<section class="panel"><h2>Audit Bukti Foto</h2><table><tr><th>Status</th><th>Item</th><th>Detail</th></tr>';
        foreach ($summary['photo_evidence_rows'] as $row) {
            echo '<tr><td><span class="badge' . (((string)$row['status'] === 'OK') ? ' ok' : ' danger') . '">' . e($row['status']) . '</span></td><td>' . e($row['title']) . '</td><td>' . e($row['detail']) . '</td></tr>';
        }
        echo '</table></section>';
    }
    echo '<section class="panel"><h2>Catatan</h2><p><strong>Kondisi:</strong> ' . e($report['condition_rating'] ?? '-') . '</p><p>' . nl2br(e($report['additional_notes'] ?? '-')) . '</p><p><strong>AI Recommendation:</strong> ' . e($schedule['ai_recommendation'] ?: '-') . '</p></section>';
    render_photo_block('Foto Before', $report['before_photos'] ?? '');
    render_photo_block('Foto Process', $report['process_photos'] ?? '');
    render_photo_block('Foto After', $report['after_photos'] ?? '');
    if (!empty($report['signature_path'])) {
        echo '<section class="panel"><h2>Digital Signature</h2><p>' . e($report['signature_name'] ?? '') . '</p><img style="max-width:320px;border:1px solid #ddd" src="' . e($report['signature_path']) . '"></section>';
    }
    $timeline = $pdo->prepare('SELECT t.*, u.name actor FROM maintenance_timeline t LEFT JOIN users u ON u.id=t.actor_user_id WHERE t.schedule_id=? ORDER BY t.created_at');
    $timeline->execute([$id]);
    echo '<section class="panel"><h2>Timeline</h2><table><tr><th>Waktu</th><th>Event</th><th>Actor</th><th>Note</th></tr>';
    foreach ($timeline as $row) {
        echo '<tr><td>' . e($row['created_at']) . '</td><td>' . e($row['event_type']) . '</td><td>' . e($row['actor'] ?: '-') . '</td><td>' . e($row['event_note']) . '</td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function render_photo_block(string $title, string $json): void
{
    $photos = $json ? json_decode($json, true) : [];
    if (!$photos) {
        return;
    }
    echo '<section class="panel"><h2>' . e($title) . '</h2><p class="muted">Klik foto untuk memperbesar.</p><div class="photo-grid">';
    foreach ($photos as $photo) {
        echo '<a href="' . e($photo) . '" target="_blank" rel="noopener"><img style="width:100%;border-radius:6px;border:1px solid #ddd;cursor:zoom-in" src="' . e($photo) . '"></a>';
    }
    echo '</div></section>';
}
