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
    $appName = app_name();
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
    echo '<style>
    .login-wrapper{display:flex;min-height:calc(100vh - 40px);width:100%;align-items:stretch;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(15,23,42,.18);border:1px solid #e2e8f0}
    .login-quote-side{flex:1.1;background:linear-gradient(145deg,#0b1329 0%,#1e293b 55%,#0f172a 100%);color:#fff;padding:60px 48px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
    .login-quote-side::before{content:"";position:absolute;top:-100px;left:-100px;width:320px;height:320px;background:radial-gradient(circle,rgba(37,99,235,.25) 0%,rgba(37,99,235,0) 70%);border-radius:50%;pointer-events:none}
    .login-quote-side::after{content:"";position:absolute;bottom:-80px;right:-80px;width:300px;height:300px;background:radial-gradient(circle,rgba(14,165,233,.2) 0%,rgba(14,165,233,0) 70%);border-radius:50%;pointer-events:none}
    .quote-brand{display:flex;align-items:center;gap:14px;position:relative;z-index:2}
    .quote-logo{width:46px;height:46px;background:linear-gradient(135deg,#2563eb,#38bdf8);border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 20px rgba(37,99,235,.4)}
    .quote-logo svg{width:26px;height:26px;stroke:#fff;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
    .quote-brand h2{margin:0;font-size:24px;font-weight:800;color:#fff;letter-spacing:-.02em}
    .quote-brand span{font-size:12px;color:#94a3b8;display:block;margin-top:2px}
    .quote-center{margin:auto 0;position:relative;z-index:2;max-width:480px;padding:30px 0}
    .quote-mark{font-size:64px;line-height:1;color:#38bdf8;font-family:Georgia,serif;opacity:.6;margin-bottom:8px}
    .quote-text{font-size:20px;line-height:1.6;font-weight:400;color:#f1f5f9;margin:0 0 20px 0;font-style:italic}
    .quote-author{display:flex;align-items:center;gap:12px}
    .quote-line{width:36px;height:2px;background:#38bdf8}
    .quote-sub{font-size:13px;color:#94a3b8;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
    .quote-pills{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:2;margin-top:20px}
    .quote-pill{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:#e2e8f0;padding:6px 12px;border-radius:999px;font-size:12px}
    .login-form-side{flex:.9;padding:60px 48px;display:flex;flex-direction:column;justify-content:center;background:#fff}
    .login-form-box{max-width:380px;width:100%;margin:0 auto}
    .login-header{margin-bottom:32px}
    .login-header h1{font-size:26px;font-weight:800;color:#0f172a;margin:0 0 8px 0;letter-spacing:-.02em}
    .login-header p{color:#64748b;font-size:14px;margin:0}
    .login-group{margin-bottom:20px}
    .login-group label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px}
    .input-wrap{position:relative;display:flex;align-items:center}
    .input-wrap input{padding-right:42px;height:44px;border-radius:8px;border:1px solid #cbd5e1;font-size:14px;width:100%}
    .input-wrap input:focus{border-color:#2563eb;outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
    .toggle-pwd{position:absolute;right:10px;background:none;border:none;color:#64748b;cursor:pointer;padding:6px;display:flex;align-items:center;justify-content:center;border-radius:4px}
    .toggle-pwd:hover{color:#0f172a}
    .btn-login{width:100%;height:46px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;transition:all .15s;box-shadow:0 4px 14px rgba(37,99,235,.35);display:flex;align-items:center;justify-content:center;gap:8px;margin-top:10px}
    .btn-login:hover{background:linear-gradient(135deg,#1e40af,#1d4ed8);transform:translateY(-1px);box-shadow:0 6px 20px rgba(37,99,235,.4)}
    .login-footer-info{margin-top:32px;text-align:center;font-size:12px;color:#94a3b8;display:flex;align-items:center;justify-content:center;gap:6px}
    @media(max-width:860px){
        .login-wrapper{flex-direction:column;border-radius:0;box-shadow:none;border:none}
        .login-quote-side{padding:36px 24px}
        .login-form-side{padding:40px 24px}
        .quote-center{margin:20px 0}
        .quote-text{font-size:17px}
    }
    </style>';

    echo '<div style="max-width:1080px;margin:30px auto;padding:12px;">';
    echo '<div class="login-wrapper">';
    
    // Left Quote Panel
    echo '<div class="login-quote-side">';
    echo '  <div class="quote-brand">';
    echo '    <div class="quote-logo"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></div>';
    echo '    <div>';
    echo '      <h2>' . e($appName) . '</h2>';
    echo '      <span>Enterprise Asset & Maintenance System</span>';
    echo '    </div>';
    echo '  </div>';

    echo '  <div class="quote-center">';
    echo '    <div class="quote-mark">“</div>';
    echo '    <p class="quote-text">Keberlanjutan operasional berawal dari keteraturan data dan keandalan tata kelola setiap aset perusahaan.</p>';
    echo '    <div class="quote-author">';
    echo '      <div class="quote-line"></div>';
    echo '      <span class="quote-sub">Aset & Maintenance Management</span>';
    echo '    </div>';
    echo '    <div class="quote-pills">';
    echo '      <span class="quote-pill">📦 Manajemen Aset & Pinjaman</span>';
    echo '      <span class="quote-pill">🛠️ Preventive & Corrective PM</span>';
    echo '      <span class="quote-pill">🔒 Regulasi Berbasis Role</span>';
    echo '    </div>';
    echo '  </div>';

    echo '  <div style="font-size:12px;color:#64748b;">© ' . date('Y') . ' ' . e($appName) . ' • Terintegrasi Multi-Device</div>';
    echo '</div>';

    // Right Form Panel
    echo '<div class="login-form-side">';
    echo '  <div class="login-form-box">';
    echo '    <div class="login-header">';
    echo '      <h1>Selamat Datang</h1>';
    echo '      <p>Silakan masuk dengan akun Anda untuk mengakses sistem ' . e($appName) . '</p>';
    echo '    </div>';

    echo '    <form method="post" autocomplete="on">';
    echo '      <input type="hidden" name="csrf" value="' . csrf_token() . '">';
    echo '      <div class="login-group">';
    echo '        <label for="loginUser">Username atau NIK</label>';
    echo '        <div class="input-wrap">';
    echo '          <input id="loginUser" name="username" placeholder="Masukkan username / NIK" required autofocus>';
    echo '        </div>';
    echo '      </div>';

    echo '      <div class="login-group">';
    echo '        <label for="loginPass">Password</label>';
    echo '        <div class="input-wrap">';
    echo '          <input type="password" id="loginPass" name="password" placeholder="Masukkan password" required>';
    echo '          <button type="button" class="toggle-pwd" onclick="togglePasswordVisibility()" title="Lihat/Sembunyikan Password">';
    echo '            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    echo '          </button>';
    echo '        </div>';
    echo '      </div>';

    echo '      <button class="btn-login" type="submit">';
    echo '        <span>Masuk ke Sistem</span>';
    echo '        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
    echo '      </button>';
    echo '    </form>';

    echo '    <div class="login-footer-info">';
    echo '      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>';
    echo '      <span>Koneksi & Akses Terenkripsi Aman</span>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '</div>'; // End login-wrapper
    echo '</div>'; // End container

    echo '<script>
    function togglePasswordVisibility() {
        var p = document.getElementById("loginPass");
        if (!p) return;
        p.type = p.type === "password" ? "text" : "password";
    }
    </script>';

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
            require_regulation('users', 'create');
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'technician');
            if (!in_array($role, ['admin', 'maintenance_admin', 'technician', 'corrective_maintenance', 'loan_officer'], true)) {
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
            require_regulation('users', 'edit');
            $target = load_user_for_admin($pdo, $targetId);
            if (!$target) {
                flash('User tidak ditemukan.', 'err');
                redirect_to('users');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $role = (string)($_POST['role'] ?? 'technician');
            if (!in_array($role, ['admin', 'maintenance_admin', 'technician', 'corrective_maintenance', 'loan_officer'], true)) {
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
            require_regulation('users', 'delete');
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
    echo '<section class="panel"><div class="split"><div><h1>User & Password</h1><p class="muted">Kelola akun administrator, staf, teknisi, dan petugas peminjaman aset.</p></div><a class="btn" href="' . route_url('technicians') . '">Lihat Teknisi</a></div></section>';
    if (!$maintenanceRoleReady) {
        echo '<section class="panel"><div class="flash err">Database belum siap untuk role tambahan. Silakan refresh halaman ini untuk auto-migration.</div></section>';
    }
    $editUser = null;
    $editId = (int)($_GET['edit_id'] ?? 0);
    if ($editId > 0 && has_regulation('users', 'edit', $user)) {
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
    if (has_regulation('users', 'create', $user)) {
        echo '<section class="grid two"><div class="panel"><h2>Tambah User</h2><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="add">' . employee_portal_name_picker_html('user', 'userNameInput', 'userUsernameInput') . '<label>Nama<input id="userNameInput" name="name" required></label><label>Username<input id="userUsernameInput" name="username" required></label><label>Role<select name="role"><option value="admin">Admin Full</option><option value="maintenance_admin">Admin Maintenance (Preventive)</option><option value="technician">Teknisi - Preventive Maintenance</option><option value="corrective_maintenance">Corrective Maintenance - Field Service & Reparasi</option><option value="loan_officer" selected>Petugas Peminjaman Aset (Mobile & Web)</option></select></label><label>Password<input type="password" name="password" required minlength="8"></label><button class="btn primary">Tambah User</button></form></div>';
        echo '<div class="panel"><h2>Catatan Hak Akses Role</h2><ul><li><strong>Admin Full:</strong> Akses penuh ke seluruh menu & sistem.</li><li><strong>Admin Maintenance:</strong> Manajemen jadwal & checklist preventive maintenance.</li><li><strong>Teknisi:</strong> Akses aplikasi mobile scan Preventive Maintenance.</li><li><strong>Corrective Maintenance:</strong> Akses aplikasi mobile "AsetConnect Field Service" (Troubleshoot, Service QR, Part Replacement).</li><li><strong>Petugas Peminjaman Aset:</strong> Akses serah-terima alat kerja & unit aset, pencatatan peminjaman (scan QR/kode), dan konfirmasi pengembalian melalui Mobile Peminjaman & Web Desktop.</li></ul></div></section>';
    }
    echo '<section class="panel"><h2>Daftar User</h2><table><tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th>Reset Password</th><th>Aksi</th></tr>';
    $canEdit = has_regulation('users', 'edit', $user);
    $canDelete = has_regulation('users', 'delete', $user);
    foreach ($pdo->query('SELECT * FROM users ORDER BY role, name') as $row) {
        $actions = '';
        if ($canEdit) {
            $actions .= '<a class="btn" href="' . route_url('users', ['edit_id' => $row['id']]) . '">Edit</a>';
            $actions .= '<form method="post" style="display:inline;"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn">' . ((int)$row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan') . '</button></form>';
        }
        if ($canDelete) {
            $actions .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'Hapus user ini? Untuk teknisi, history maintenance terkait ikut dihapus.\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn danger">Delete</button></form>';
        }
        echo '<tr><td>' . e($row['name']) . '</td><td>' . e($row['username']) . '</td><td><span class="badge">' . e(role_label((string)$row['role'])) . '</span></td><td>' . ((int)$row['is_active'] === 1 ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td>';
        echo '<td><form method="post" class="actions"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="' . e($row['id']) . '"><input style="min-width:180px" type="password" name="password" minlength="8" placeholder="Password baru" required><button class="btn">Reset</button></form></td>';
        echo '<td><div class="actions">' . ($actions ?: '-') . '</div></td></tr>';
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

