<?php

declare(strict_types=1);

function enforce_idle_logout(string $route): void
{
    $route = (string)$route;
    if (substr($route, 0, 4) === 'api_' || in_array($route, ['login', 'logout', 'mobile_service_login', 'mobile_service_logout'], true) || empty($_SESSION['user_id'])) {
        return;
    }
    // Auto-logout setelah 30 menit (1800 detik) tidak digunakan
    $timeoutSeconds = 1800;
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? 0);
    $now = time();
    if ($lastActivity > 0 && ($now - $lastActivity) > $timeoutSeconds) {
        $isFieldService = str_starts_with($route, 'mobile_service') || str_starts_with($route, 'mobile_repair');
        unset($_SESSION['user_id'], $_SESSION['last_activity_at'], $_SESSION['csrf'], $_SESSION['pending_mobile_code']);
        if ($isFieldService) {
            redirect_to('mobile_service_login');
        } else {
            redirect_to('login');
        }
    }
    $_SESSION['last_activity_at'] = $now;
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
        'technician' => 'Teknisi Preventive',
        'corrective_maintenance' => 'Corrective Maintenance',
        'loan_officer' => 'Petugas Peminjaman Aset',
    ][$role] ?? $role;
}

function db_supports_maintenance_admin(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
        $row = $stmt->fetch();
        $type = (string)($row['Type'] ?? '');
        return strpos($type, 'maintenance_admin') !== false && strpos($type, 'corrective_maintenance') !== false && strpos($type, 'loan_officer') !== false;
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
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','maintenance_admin','technician','corrective_maintenance','loan_officer') NOT NULL DEFAULT 'technician'");
        return db_supports_maintenance_admin($pdo);
    } catch (Throwable $e) {
        return false;
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
                if (function_exists('delete_maintenance_schedule_rows')) {
                    delete_maintenance_schedule_rows($pdo, (int)$scheduleId);
                }
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
        if (db_table_exists($pdo, 'maintenance_schedules')) {
            $pdo->prepare('UPDATE maintenance_schedules SET unlocked_by=NULL WHERE unlocked_by=?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handle_route_login(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
        $stmt->execute([trim((string)($_POST['username'] ?? ''))]);
        $user = $stmt->fetch();
        if ($user && password_verify((string)($_POST['password'] ?? ''), (string)$user['password_hash'])) {
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
            if ($user['role'] === 'corrective_maintenance') {
                redirect_to('mobile_service');
            }
            if ($user['role'] === 'loan_officer') {
                redirect_to('mobile_asset_loans');
            }
            redirect_to($user['role'] === 'technician' ? 'mobile_dashboard' : 'dashboard');
        }
        flash('Username atau password salah.', 'err');
        redirect_to('login');
    }
    render_header('Login');
    echo '<section class="auth"><h1>PcConnect</h1><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><label>Username<input name="username" required autofocus></label><label>Password<input type="password" name="password" required></label><button class="btn primary">Login</button></form></section>';
    render_footer();
}

function handle_route_logout(): never
{
    session_destroy();
    redirect_to('login');
}

function handle_route_users(PDO $pdo): void
{
    $user = require_role(['admin']);
    $maintenanceRoleReady = ensure_maintenance_admin_role($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $targetId = (int)($_POST['id'] ?? 0);
        if ($action === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'technician');
            if (!in_array($role, ['admin', 'maintenance_admin', 'technician', 'corrective_maintenance'], true)) {
                $role = 'technician';
            }
            if ($name === '' || $username === '' || strlen($password) < 8) {
                flash('Nama, username, dan password (min 8 karakter) wajib diisi.', 'err');
                redirect_to('users');
            }
            try {
                $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)')->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
                flash('User baru berhasil ditambahkan.');
            } catch (Throwable $e) {
                flash('Gagal menambah user. Username mungkin sudah dipakai.', 'err');
            }
            redirect_to('users');
        }
        if ($action === 'update') {
            $target = load_user_for_admin($pdo, $targetId);
            if (!$target) {
                flash('User tidak ditemukan.', 'err');
                redirect_to('users');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $role = (string)($_POST['role'] ?? 'technician');
            if (!in_array($role, ['admin', 'maintenance_admin', 'technician', 'corrective_maintenance'], true)) {
                $role = 'technician';
            }
            if ($name === '' || $username === '') {
                flash('Nama dan username wajib diisi.', 'err');
                redirect_to('users', ['edit_id' => $targetId]);
            }
            if ($target['role'] === 'admin' && $role !== 'admin' && (int)$target['is_active'] === 1 && active_admin_count($pdo) <= 1) {
                flash('Role admin aktif terakhir tidak boleh diubah.', 'err');
                redirect_to('users', ['edit_id' => $targetId]);
            }
            try {
                $pdo->prepare('UPDATE users SET name=?, username=?, role=? WHERE id=?')->execute([$name, $username, $role, $targetId]);
                flash('Data user berhasil diperbarui.');
            } catch (Throwable $e) {
                flash('Gagal memperbarui user. Username mungkin sudah dipakai.', 'err');
                redirect_to('users', ['edit_id' => $targetId]);
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
    echo '<section class="panel"><div class="split"><div><h1>User & Password</h1><p class="muted">Kelola admin, admin maintenance, teknisi preventive, dan corrective maintenance.</p><p class="muted">Role build: admin + maintenance_admin + technician + corrective_maintenance</p></div><a class="btn" href="' . route_url('technicians') . '">Lihat Teknisi</a></div></section>';
    if (!$maintenanceRoleReady) {
        echo '<section class="panel"><div class="flash err">Database belum siap untuk role tambahan. Silakan refresh halaman ini untuk auto-migration.</div></section>';
    }
    $editUser = null;
    $editId = (int)($_GET['edit_id'] ?? 0);
    if ($editId > 0) {
        $editUser = load_user_for_admin($pdo, $editId);
    }
    if ($editUser) {
        $roleOptions = '';
        foreach ([
            'admin' => 'Admin Full',
            'maintenance_admin' => 'Admin Maintenance (Preventive)',
            'technician' => 'Teknisi Preventive Maintenance',
            'corrective_maintenance' => 'Corrective Maintenance (Field Service & Reparasi)',
            'loan_officer' => 'Petugas Peminjaman Aset (Mobile & Web)'
        ] as $roleValue => $roleLabel) {
            $roleOptions .= '<option value="' . e($roleValue) . '"' . ($editUser['role'] === $roleValue ? ' selected' : '') . '>' . e($roleLabel) . '</option>';
        }
        echo '<section class="panel"><div class="split"><h2>Edit User</h2><a class="btn" href="' . route_url('users') . '">Batal Edit</a></div><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . e($editUser['id']) . '"><div class="grid three"><label>Nama<input name="name" value="' . e($editUser['name']) . '" required></label><label>Username<input name="username" value="' . e($editUser['username']) . '" required></label><label>Role<select name="role" required>' . $roleOptions . '</select></label></div><button class="btn primary">Simpan Perubahan</button></form></section>';
    }
    echo '<section class="grid two"><div class="panel"><h2>Tambah User</h2><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="add">' . employee_portal_name_picker_html('user', 'userNameInput', 'userUsernameInput') . '<label>Nama<input id="userNameInput" name="name" required></label><label>Username<input id="userUsernameInput" name="username" required></label><label>Role<select name="role"><option value="admin">Admin Full</option><option value="maintenance_admin">Admin Maintenance (Preventive)</option><option value="technician">Teknisi - Preventive Maintenance</option><option value="corrective_maintenance">Corrective Maintenance - Field Service & Reparasi</option><option value="loan_officer" selected>Petugas Peminjaman Aset (Mobile & Web)</option></select></label><label>Password<input type="password" name="password" required minlength="8"></label><button class="btn primary">Tambah User</button></form></div>';
    echo '<div class="panel"><h2>Catatan Hak Akses Role</h2><ul><li><strong>Admin Full:</strong> Akses penuh ke seluruh menu & sistem.</li><li><strong>Admin Maintenance:</strong> Manajemen jadwal & checklist preventive maintenance.</li><li><strong>Teknisi:</strong> Akses aplikasi mobile scan Preventive Maintenance.</li><li><strong>Corrective Maintenance:</strong> Akses aplikasi mobile "PcConnect Field Service" (Troubleshoot, Service QR, Part Replacement).</li><li><strong>Petugas Peminjaman Aset:</strong> Akses serah-terima alat kerja & unit aset, pencatatan peminjaman (scan QR/kode), dan konfirmasi pengembalian melalui Mobile Peminjaman & Web Desktop.</li></ul></div></section>';
    echo '<section class="panel"><h2>Daftar User</h2><table><tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th>Reset Password</th><th>Aksi</th></tr>';
    foreach ($pdo->query('SELECT * FROM users ORDER BY role, name') as $row) {
        echo '<tr><td>' . e($row['name']) . '</td><td>' . e($row['username']) . '</td><td><span class="badge">' . e(role_label((string)$row['role'])) . '</span></td><td>' . ((int)$row['is_active'] === 1 ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td>';
        echo '<td><form method="post" class="actions"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="' . e($row['id']) . '"><input style="min-width:180px" type="password" name="password" minlength="8" placeholder="Password baru" required><button class="btn">Reset</button></form></td>';
        echo '<td><div class="actions"><a class="btn" href="' . route_url('users', ['edit_id' => $row['id']]) . '">Edit</a><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn">' . ((int)$row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan') . '</button></form><form method="post" onsubmit="return confirm(\'Hapus user ini? Untuk teknisi, history maintenance terkait ikut dihapus.\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn danger">Delete</button></form></div></td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_technicians(PDO $pdo): void
{
    $user = require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($_POST['action'] === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($name === '' || $username === '' || strlen($password) < 8) {
                flash('Nama, username, dan password (min 8 karakter) wajib diisi.', 'err');
                redirect_to('technicians');
            }
            try {
                $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, "technician")')->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
                flash('Teknisi berhasil ditambahkan.');
            } catch (Throwable $e) {
                flash('Gagal menambah teknisi. Username mungkin sudah dipakai.', 'err');
            }
        }
        if ($_POST['action'] === 'update') {
            $techId = (int)$_POST['id'];
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($name === '' || $username === '') {
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
}

function handle_route_dashboard(PDO $pdo): void
{
    $user = require_login();
    if ($user['role'] === 'maintenance_admin') {
        redirect_to('maintenance');
    }
    if ($user['role'] === 'technician') {
        redirect_to('mobile_dashboard');
    }
    render_header('Dashboard', $user);
    if (db_table_exists($pdo, 'asset_groups')) {
        $groupStats = $pdo->query('SELECT g.group_name, COUNT(i.id) AS total_items FROM asset_groups g LEFT JOIN asset_items i ON i.asset_group_id = g.id AND i.status = \'active\' GROUP BY g.id, g.group_name ORDER BY g.group_name')->fetchAll();
        if ($groupStats) {
            echo '<section class="grid four" style="margin-bottom: 20px;">';
            foreach ($groupStats as $st) {
                echo '<div class="stat" style="border-left: 4px solid #1457d9;"><strong>' . e($st['total_items']) . '</strong><span>' . e($st['group_name']) . '</span></div>';
            }
            echo '</section>';
        }
    }

    $pcCount = (int)$pdo->query('SELECT COUNT(*) FROM pcs')->fetchColumn();
    $dueCount = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_schedules WHERE scheduled_date = CURDATE() AND status != 'completed'")->fetchColumn();
    $activeTech = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'technician' AND is_active = 1")->fetchColumn();
    $problemCount = (int)$pdo->query("SELECT COUNT(*) FROM pcs WHERE physical_condition IN ('rusak', 'perlu_perbaikan')")->fetchColumn();
    echo '<section class="grid four"><div class="stat"><strong>' . e($pcCount) . '</strong><span>Total PC</span></div><div class="stat"><strong>' . e($dueCount) . '</strong><span>Maintenance Hari Ini</span></div><div class="stat"><strong>' . e($activeTech) . '</strong><span>Teknisi Aktif</span></div><div class="stat"><strong>' . e($problemCount) . '</strong><span>PC Masalah Fisik</span></div></section>';
    $todayRows = $pdo->query("SELECT s.id, s.scheduled_date, s.status, s.notes, u.name technician, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = s.pc_id COLLATE utf8mb4_unicode_ci LEFT JOIN printers pr ON pr.prn_id COLLATE utf8mb4_unicode_ci = s.printer_id COLLATE utf8mb4_unicode_ci LEFT JOIN users u ON u.id = s.technician_id WHERE s.scheduled_date = CURDATE() ORDER BY s.id DESC LIMIT 10")->fetchAll();
    echo '<section class="panel"><h2>Jadwal Hari Ini</h2><table><tr><th>ID</th><th>Aset</th><th>Pengguna / Lokasi</th><th>Teknisi</th><th>Status</th><th>Catatan</th></tr>';
    foreach ($todayRows as $row) {
        echo '<tr><td>' . e($row['id']) . '</td><td>' . e($row['computer_name'] ?? '-') . '</td><td>' . e($row['owner_name'] ?? '-') . '</td><td>' . e($row['technician'] ?: 'Belum ditentukan') . '</td><td><span class="badge ' . ($row['status'] === 'completed' ? 'ok' : '') . '">' . e($row['status']) . '</span></td><td>' . e($row['notes']) . '</td></tr>';
    }
    echo '</table></section>';
    $techRows = $pdo->query("SELECT u.name technician, COUNT(*) total, SUM(s.status='completed') done, SUM(s.status IN ('validated','in_progress','reopened')) progress, SUM(s.status='scheduled') pending FROM maintenance_schedules s LEFT JOIN users u ON u.id=s.technician_id WHERE s.scheduled_date=CURDATE() GROUP BY u.id, u.name ORDER BY u.name")->fetchAll();
    if ($techRows) {
        echo '<section class="panel"><h2>Teknisi Hari Ini</h2><table><tr><th>Teknisi</th><th>Job</th><th>Done</th><th>Progress</th><th>Pending</th></tr>';
        foreach ($techRows as $row) {
            echo '<tr><td>' . e($row['technician'] ?: 'Belum ditentukan') . '</td><td>' . e($row['total']) . '</td><td>' . e($row['done']) . '</td><td>' . e($row['progress']) . '</td><td>' . e($row['pending']) . '</td></tr>';
        }
        echo '</table></section>';
    }
    render_footer();
}

