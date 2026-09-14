<?php

declare(strict_types=1);

/**
 * PcConnect Field Service Mobile Module
 * Khusus aplikasi mobile smartphone untuk teknisi Corrective Maintenance di lapangan.
 */

function require_corrective_technician(): array
{
    $user = current_user();
    if (!$user) {
        redirect_to('mobile_service_login');
    }
    if (!in_array($user['role'], ['admin', 'corrective_maintenance'], true)) {
        flash('Akses ditolak. Portal ini khusus untuk teknisi dengan role Corrective Maintenance.', 'err');
        redirect_to('mobile_service_login');
    }
    return $user;
}

function handle_route_mobile_service_login(PDO $pdo): void
{
    ensure_corrective_maintenance_schema($pdo);

    // Jika sudah login dengan role yang sesuai, langsung redirect ke mobile_service
    $curr = current_user();
    if ($curr && in_array($curr['role'], ['admin', 'corrective_maintenance'], true)) {
        redirect_to('mobile_service');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, (string)$user['password_hash'])) {
            if (!in_array($user['role'], ['admin', 'corrective_maintenance'], true)) {
                flash('Akses ditolak. Akun Anda (' . e(function_exists('role_label') ? role_label((string)$user['role']) : $user['role']) . ') bukan role Corrective Maintenance.', 'err');
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['last_activity_at'] = time();
                flash('Selamat datang, ' . $user['name'] . '! Anda berhasil masuk ke Field Service.');
                redirect_to('mobile_service');
            }
        } else {
            flash('Login gagal. Periksa username dan password Anda.', 'err');
        }
    }

    $flash = flash();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
        <title>Login - PcConnect Field Service</title>
        <style>
            * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
            body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #0f172a; color: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
            .login-card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 30px 24px; width: 100%; max-width: 400px; box-shadow: 0 12px 30px rgba(0,0,0,0.5); }
            .brand-logo { text-align: center; margin-bottom: 24px; }
            .brand-icon { font-size: 46px; display: block; margin-bottom: 8px; }
            .brand-title { font-size: 21px; font-weight: 800; color: #38bdf8; margin: 0; }
            .brand-subtitle { font-size: 13px; color: #94a3b8; margin-top: 4px; }
            label { display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin: 16px 0 6px; }
            input { width: 100%; background: #0f172a; border: 1px solid #475569; color: #f8fafc; border-radius: 10px; padding: 13px 14px; font-size: 15px; outline: none; transition: border-color 0.2s; }
            input:focus { border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.25); }
            .btn-login { width: 100%; background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; border: none; border-radius: 10px; padding: 14px; font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 24px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4); }
            .btn-login:active { transform: translateY(1px); filter: brightness(0.95); }
            .flash { padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; line-height: 1.4; }
            .flash-ok { background: #065f46; color: #d1fae5; border: 1px solid #047857; }
            .flash-err { background: #881337; color: #ffe4e6; border: 1px solid #be123c; }
            .login-footer { text-align: center; margin-top: 22px; font-size: 12px; color: #64748b; line-height: 1.5; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="brand-logo">
                <span class="brand-icon">🛠️</span>
                <h1 class="brand-title">PcConnect Field Service</h1>
                <div class="brand-subtitle">Portal Mobile Corrective Maintenance</div>
            </div>

            <?php if ($flash): ?>
                <div class="flash <?= $flash['type'] === 'err' ? 'flash-err' : 'flash-ok' ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <label>Username
                    <input name="username" required autofocus placeholder="Username teknisi">
                </label>
                <label>Password
                    <input type="password" name="password" required placeholder="••••••••">
                </label>
                <button class="btn-login">Masuk ke Field Service →</button>
            </form>

            <div class="login-footer">
                Khusus teknisi role <strong>Corrective Maintenance</strong>.<br>
                Sesi otomatis logout jika tidak aktif selama 30 menit.
            </div>
        </div>
    </body>
    </html>
    <?php
}

function handle_route_mobile_service_logout(): never
{
    unset($_SESSION['user_id'], $_SESSION['last_activity_at'], $_SESSION['csrf'], $_SESSION['pending_mobile_code']);
    flash('Anda telah berhasil logout dari Field Service.');
    redirect_to('mobile_service_login');
}

function render_mobile_service_header(string $title, ?array $user): void
{
    $flash = flash();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
        <title><?= e($title) ?> - PcConnect Field Service</title>
        <style>
            * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
            body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #0f172a; color: #f8fafc; padding-bottom: 72px; }
            a { text-decoration: none; color: inherit; }
            header { background: #1e293b; border-bottom: 1px solid #334155; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
            .brand { font-weight: 700; font-size: 15px; color: #38bdf8; display: flex; align-items: center; gap: 6px; }
            .tech-info { font-size: 12px; color: #94a3b8; display: flex; align-items: center; gap: 6px; }
            .container { padding: 14px; max-width: 600px; margin: 0 auto; }
            .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 16px; margin-bottom: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .card h2 { margin: 0 0 10px; font-size: 16px; color: #f1f5f9; }
            .btn { display: block; width: 100%; text-align: center; border: none; border-radius: 8px; padding: 12px 16px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.2); margin-top: 8px; }
            .btn-primary { background: #0284c7; color: #fff; }
            .btn-primary:active { background: #0369a1; }
            .btn-success { background: #059669; color: #fff; }
            .btn-success:active { background: #047857; }
            .btn-secondary { background: #334155; color: #f8fafc; border: 1px solid #475569; }
            .btn-danger { background: #e11d48; color: #fff; }
            .badge { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 600; }
            .badge-open { background: #fee2e2; color: #991b1b; }
            .badge-progress { background: #fef3c7; color: #92400e; }
            .badge-resolved { background: #dcfce7; color: #166534; }
            .flash { padding: 12px; border-radius: 8px; margin-bottom: 14px; font-size: 14px; }
            .flash-ok { background: #065f46; color: #d1fae5; border: 1px solid #047857; }
            .flash-err { background: #881337; color: #ffe4e6; border: 1px solid #be123c; }
            label { display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin: 10px 0 4px; }
            input, select, textarea { width: 100%; background: #0f172a; border: 1px solid #475569; color: #f8fafc; border-radius: 8px; padding: 10px 12px; font-size: 14px; outline: none; }
            input:focus, select:focus, textarea:focus { border-color: #38bdf8; }
            .nav-bottom { position: fixed; bottom: 0; left: 0; right: 0; background: #1e293b; border-top: 1px solid #334155; display: grid; grid-template-columns: repeat(3, 1fr); padding: 8px 0; z-index: 50; }
            .nav-item { text-align: center; font-size: 11px; color: #94a3b8; display: flex; flex-direction: column; align-items: center; gap: 3px; }
            .nav-item.active { color: #38bdf8; font-weight: 600; }
            .nav-icon { font-size: 18px; }
            .scan-box { width: 100%; max-width: 320px; height: 260px; margin: 12px auto; border-radius: 12px; overflow: hidden; background: #000; border: 2px dashed #38bdf8; position: relative; }
        </style>
    </head>
    <body>
        <header>
            <div class="brand">🛠️ PcConnect <span>Field Service</span></div>
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="tech-info">
                    <span>👤 <?= e($user['name'] ?? 'Teknisi') ?></span>
                    <span style="font-size:10px;padding:2px 6px;background:#0369a1;color:#fff;border-radius:4px;font-weight:700;">
                        <?= e(function_exists('role_label') ? role_label((string)($user['role'] ?? '')) : ($user['role'] ?? '')) ?>
                    </span>
                </div>
                <a href="<?= route_url('mobile_service_logout') ?>" onclick="return confirm('Keluar dari PcConnect Field Service?')" style="background:#dc2626;color:#fff;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:3px;" title="Keluar">
                    🚪 Logout
                </a>
            </div>
        </header>
        <div class="container">
            <?php if ($flash): ?>
                <div class="flash <?= $flash['type'] === 'err' ? 'flash-err' : 'flash-ok' ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>
    <?php
}

function render_mobile_service_footer(string $activeTab = 'dashboard'): void
{
    ?>
        </div>
        <nav class="nav-bottom">
            <a href="<?= route_url('mobile_service') ?>" class="nav-item <?= $activeTab === 'dashboard' ? 'active' : '' ?>">
                <span class="nav-icon">🏠</span>
                <span>Home</span>
            </a>
            <a href="<?= route_url('mobile_service_scan') ?>" class="nav-item <?= $activeTab === 'scan' ? 'active' : '' ?>">
                <span class="nav-icon">📷</span>
                <span>Scan QR</span>
            </a>
            <a href="<?= route_url('mobile_service_tasks') ?>" class="nav-item <?= $activeTab === 'tasks' ? 'active' : '' ?>">
                <span class="nav-icon">📋</span>
                <span>Daftar Tugas</span>
            </a>
        </nav>

        <!-- Auto-logout Inactivity Timer (30 Menit) -->
        <script>
        (function() {
            var idleTimeout = 30 * 60 * 1000; // 30 menit
            var timer;
            function resetTimer() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    alert('Sesi Anda berakhir karena tidak ada aktivitas selama 30 menit.');
                    window.location.href = '<?= route_url('mobile_service_logout') ?>';
                }, idleTimeout);
            }
            window.onload = resetTimer;
            document.onmousemove = resetTimer;
            document.onkeypress = resetTimer;
            document.ontouchstart = resetTimer;
            document.onclick = resetTimer;
            document.onscroll = resetTimer;
        })();
        </script>
    </body>
    </html>
    <?php
}

function handle_route_mobile_service(PDO $pdo): void
{
    $user = require_corrective_technician();
    ensure_corrective_maintenance_schema($pdo);

    // Ambil statistik tugas teknisi
    $stats = $pdo->query("SELECT 
        COUNT(CASE WHEN status IN ('open', 'assigned') THEN 1 END) AS count_open,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) AS count_progress,
        COUNT(CASE WHEN status = 'pending_part' THEN 1 END) AS count_pending_part,
        COUNT(CASE WHEN status IN ('resolved', 'closed') AND DATE(resolved_at) = CURRENT_DATE() THEN 1 END) AS count_resolved_today
    FROM corrective_tickets")->fetch(PDO::FETCH_ASSOC);

    // Ambil 4 tiket tugas terbaru
    $recentTickets = $pdo->query("SELECT t.*, ma.maintenance_asset_code, ma.name AS asset_name
                                  FROM corrective_tickets t
                                  LEFT JOIN maintenance_assets ma ON ma.id = t.maintenance_asset_id
                                  WHERE t.status IN ('open', 'assigned', 'in_progress', 'pending_part')
                                  ORDER BY t.priority = 'critical' DESC, t.created_at DESC
                                  LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);

    render_mobile_service_header('Field Service Mobile', $user);
    ?>
    <!-- Action Cards -->
    <div class="card" style="background:linear-gradient(135deg, #0369a1, #0f172a);border-color:#0284c7;">
        <h2 style="font-size:18px;color:#fff;margin-bottom:6px;">⚡ Perbaikan Cepat di Tempat</h2>
        <p style="font-size:13px;color:#e0f2fe;margin:0 0 14px;">Scan QR Maintenance Asset di unit untuk buka case baru atau langsung selesaikan di lokasi.</p>
        <a href="<?= route_url('mobile_service_scan') ?>" class="btn btn-primary" style="font-size:16px;padding:14px;background:#38bdf8;color:#0f172a;font-weight:700;">
            📷 SCAN QR CODE UNIT
        </a>
    </div>

    <!-- Ringkasan Status -->
    <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:10px;margin-bottom:14px;">
        <div class="card" style="margin:0;padding:12px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:#f87171;"><?= (int)($stats['count_open'] ?? 0) ?></div>
            <div style="font-size:12px;color:#94a3b8;">Tiket Masuk</div>
        </div>
        <div class="card" style="margin:0;padding:12px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:#fbbf24;"><?= (int)($stats['count_progress'] ?? 0) ?></div>
            <div style="font-size:12px;color:#94a3b8;">Sedang Dikerjakan</div>
        </div>
    </div>

    <!-- Tiket Butuh Tindakan -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h2 style="margin:0;">🚨 Tugas Tiket Menunggu</h2>
            <a href="<?= route_url('mobile_service_tasks') ?>" style="font-size:12px;color:#38bdf8;">Lihat Semua →</a>
        </div>

        <?php if (!$recentTickets): ?>
            <p style="font-size:13px;color:#94a3b8;margin:10px 0;">Tidak ada tiket kerusakan yang menunggu.</p>
        <?php else: ?>
            <?php foreach ($recentTickets as $t): ?>
                <div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px;margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <span style="font-weight:700;color:#38bdf8;font-size:14px;"><?= e($t['ticket_code']) ?></span>
                            <div style="font-size:12px;color:#cbd5e1;font-weight:600;margin-top:2px;">
                                <?= e($t['maintenance_asset_code'] ?: ($t['pc_id'] ? 'PC ' . $t['pc_id'] : 'Unit')) ?>
                            </div>
                        </div>
                        <div>
                            <?= ticket_priority_badge($t['priority']) ?>
                        </div>
                    </div>
                    <p style="font-size:13px;margin:6px 0;color:#e2e8f0;"><?= e($t['subject']) ?></p>
                    <div style="font-size:11px;color:#94a3b8;display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                        <span>📍 <?= e($t['location_label'] ?: '-') ?></span>
                        <a href="<?= route_url('mobile_service_ticket', ['id' => $t['id']]) ?>" class="btn btn-primary" style="display:inline-block;width:auto;padding:6px 12px;font-size:12px;margin:0;">
                            Buka Tiket →
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    render_mobile_service_footer('dashboard');
}

function handle_route_mobile_service_scan(PDO $pdo): void
{
    $user = require_corrective_technician();

    $rawInput = trim((string)($_POST['code'] ?? $_GET['code'] ?? $_GET['eqr'] ?? ''));

    if ($rawInput !== '') {
        $maintId = resolve_maintenance_asset_id_from_raw_input($pdo, $rawInput);

        if (!$maintId || $maintId <= 0) {
            flash("Maintenance Asset dengan kode/QR tersebut tidak ditemukan. Pastikan unit telah terdaftar.", 'err');
            redirect_to('mobile_service_scan');
        }

        // Cek apakah ada tiket open/in_progress pada unit ini
        $stmt2 = $pdo->prepare("SELECT id FROM corrective_tickets WHERE maintenance_asset_id = ? AND status IN ('open', 'assigned', 'in_progress', 'pending_part') ORDER BY created_at DESC LIMIT 1");
        $stmt2->execute([$maintId]);
        $existingTicketId = (int)($stmt2->fetchColumn() ?: 0);

        if ($existingTicketId > 0) {
            flash("Unit terverifikasi! Membuka tiket aktif terkait...");
            redirect_to('mobile_service_ticket', ['id' => $existingTicketId, 'scanned' => 1]);
        } else {
            flash("Unit terverifikasi! Silakan catat perbaikan di bawah.");
            redirect_to('mobile_service_direct', ['maintenance_asset_id' => $maintId]);
        }
    }

    render_mobile_service_header('Scan QR Unit Lapangan', $user);
    ?>
    <div class="card" style="text-align:center;">
        <h2>📷 Scan QR Maintenance Asset</h2>
        <p style="font-size:13px;color:#94a3b8;margin:0 0 12px;">Arahkan kamera ke stiker QR Code pada unit fisik.</p>

        <div id="reader" style="width:100%;max-width:320px;margin:0 auto;border-radius:12px;overflow:hidden;background:#000;"></div>
        <p id="camStatus" style="font-size:12px;color:#38bdf8;margin-top:8px;">Menyiapkan kamera live scan...</p>

        <form method="post" id="scanForm" style="margin-top:16px;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <label style="text-align:left;">Atau Ketik Kode Maintenance Asset:
                <input name="code" id="codeManual" placeholder="Contoh: MNT-PC000003" required>
            </label>
            <button class="btn btn-primary" style="margin-top:8px;">Lanjut Eksekusi</button>
        </form>
    </div>

    <script src="https://unpkg.com/html5-qrcode"></script>
    <script>
    (function() {
        var html5QrcodeScanner = null;
        var submitted = false;

        function onScanSuccess(decodedText, decodedResult) {
            if (submitted) return;
            submitted = true;
            document.getElementById('codeManual').value = decodedText;
            document.getElementById('camStatus').textContent = "QR Terbaca: " + decodedText + ". Memproses...";
            document.getElementById('scanForm').submit();
        }

        if (window.Html5Qrcode) {
            var html5Qr = new Html5Qrcode("reader");
            html5Qr.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 220, height: 220 } },
                onScanSuccess
            ).catch(function(err) {
                document.getElementById('camStatus').textContent = "Kamera tidak dapat diakses langsung. Silakan ketik kode manual di bawah.";
            });
        }
    })();
    </script>
    <?php
    render_mobile_service_footer('scan');
}

function handle_route_mobile_service_direct(PDO $pdo): void
{
    $user = require_corrective_technician();
    ensure_corrective_maintenance_schema($pdo);
    $maintId = (int)($_GET['maintenance_asset_id'] ?? 0);
    $unit = get_maintenance_asset_unit($pdo, $maintId);

    if (!$unit) {
        flash('Unit Maintenance Asset tidak ditemukan.', 'err');
        redirect_to('mobile_service_scan');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $code = generate_next_ticket_code($pdo);
        $subject = trim((string)($_POST['subject'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $priority = trim((string)($_POST['priority'] ?? 'medium'));
        $actionType = trim((string)($_POST['action_type'] ?? 'hardware_repair'));
        $solution = trim((string)($_POST['solution_details'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'resolved'));
        $repairCost = (float)($_POST['repair_cost'] ?? 0);
        $vendorName = trim((string)($_POST['vendor_name'] ?? ''));

        if ($subject === '' || $solution === '') {
            flash('Keluhan masalah dan solusi tindakan perbaikan wajib diisi.', 'err');
            redirect_to('mobile_service_direct', ['maintenance_asset_id' => $maintId]);
        }

        // Upload foto sebelum & sesudah
        $photoBefore = null;
        $photoAfter = null;
        $uploadDir = __DIR__ . '/../../public/uploads/tickets';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        if (!empty($_FILES['photo_before']['tmp_name']) && is_uploaded_file($_FILES['photo_before']['tmp_name'])) {
            $ext = strtolower(pathinfo((string)$_FILES['photo_before']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $filename = 'before_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['photo_before']['tmp_name'], $uploadDir . '/' . $filename)) {
                    $photoBefore = 'uploads/tickets/' . $filename;
                }
            }
        }

        if (!empty($_FILES['photo_after']['tmp_name']) && is_uploaded_file($_FILES['photo_after']['tmp_name'])) {
            $ext = strtolower(pathinfo((string)$_FILES['photo_after']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $filename = 'after_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['photo_after']['tmp_name'], $uploadDir . '/' . $filename)) {
                    $photoAfter = 'uploads/tickets/' . $filename;
                }
            }
        }

        $assetCat = $unit['asset_category'];
        $resolvedAt = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : null;

        // 1. Simpan Tiket
        $stmt = $pdo->prepare("INSERT INTO corrective_tickets (
            ticket_code, asset_category, asset_item_id, maintenance_asset_id, pc_id, company_id, 
            location_label, reporter_name, reporter_nik, issue_category, priority, 
            subject, description, photo_before, status, assigned_technician_name, resolved_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $code, $assetCat, $unit['asset_item_id'], $maintId, $unit['pc_id'] ?: null,
            (int)($unit['company_id'] ?? 0) ?: null, $unit['location_label'] ?? '',
            $unit['custodian_name'] ?: $user['name'], $unit['custodian_nik'] ?? '', 'hardware', $priority,
            $subject, $desc, $photoBefore, $status, $user['name'], $resolvedAt
        ]);
        $ticketId = (int)$pdo->lastInsertId();

        // 2. Simpan Tindakan Reparasi
        $repStmt = $pdo->prepare("INSERT INTO corrective_repairs (
            ticket_id, technician_name, action_type, solution_details, vendor_name, repair_cost, photo_after
        ) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $repStmt->execute([
            $ticketId, $user['name'], $actionType, $solution, $vendorName ?: null, $repairCost, $photoAfter
        ]);
        $repairId = (int)$pdo->lastInsertId();

        // 3. Simpan Sparepart jika ada
        $partName = trim((string)($_POST['part_new_name'] ?? ''));
        if ($partName !== '') {
            $partOld = trim((string)($_POST['part_old_name'] ?? ''));
            $partSerial = trim((string)($_POST['part_new_serial'] ?? ''));
            $partCost = (float)($_POST['part_cost'] ?? 0);
            $pdo->prepare("INSERT INTO corrective_repair_parts (repair_id, old_part_name, new_part_name, new_part_serial, part_cost) VALUES (?, ?, ?, ?, ?)")
                ->execute([$repairId, $partOld ?: null, $partName, $partSerial ?: null, $partCost]);
        }

        flash("Perbaikan di tempat berhasil disimpan! Tiket #{$code} selesai.");
        redirect_to('mobile_service');
    }

    render_mobile_service_header('Eksekusi Perbaikan di Tempat', $user);
    ?>
    <div class="card" style="border-left:4px solid #38bdf8;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span class="badge" style="background:#0284c7;color:#fff;">UNIT TERVERIFIKASI</span>
            <?php if (!empty($unit['group_name']) || !empty($unit['type_name'])): ?>
                <span class="badge" style="background:#1e293b;color:#38bdf8;border:1px solid #0284c7;font-size:11px;">
                    <?= e($unit['group_name'] ?: 'IT') ?> &bull; <?= e($unit['type_name'] ?: ($unit['asset_category'] ?? 'Comp')) ?>
                </span>
            <?php endif; ?>
        </div>
        <h2 style="margin:4px 0 2px;color:#38bdf8;"><?= e($unit['maintenance_asset_code']) ?></h2>
        <?php if (!empty($unit['item_asset_code']) && $unit['item_asset_code'] !== $unit['maintenance_asset_code']): ?>
            <div style="font-size:12px;color:#38bdf8;font-weight:600;">🏷️ Kode Asset: <?= e($unit['item_asset_code']) ?></div>
        <?php endif; ?>
        <div style="font-size:14px;font-weight:600;color:#f8fafc;margin-top:2px;"><?= e($unit['name']) ?></div>
        <?php if (!empty($unit['job_desk_name'])): ?>
            <div style="font-size:12px;color:#a5f3fc;margin-top:4px;background:#0369a1;padding:3px 8px;border-radius:6px;display:inline-block;">
                📋 <strong>Job Desk:</strong> <?= e($unit['job_desk_name']) ?>
            </div>
        <?php endif; ?>
        <div style="font-size:12px;color:#94a3b8;margin-top:6px;">
            👤 Pemegang: <?= e($unit['custodian_name'] ?: '-') ?> | 📍 Lokasi: <?= e($unit['location_label'] ?: '-') ?>
        </div>
    </div>

    <div class="card">
        <h2>📝 Form Eksekusi Cepat di Lokasi</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <label>Subjek / Keluhan Masalah *
                <input name="subject" required placeholder="Contoh: Laptop lambat / Ganti RAM / Blue screen">
            </label>

            <label>Tingkat Prioritas
                <select name="priority">
                    <option value="medium" selected>Medium (Standard)</option>
                    <option value="high">High (Mendesak)</option>
                    <option value="critical">Critical (Operasional Macet)</option>
                    <option value="low">Low</option>
                </select>
            </label>

            <label>Foto Kerusakan Awal (Opsional)
                <input type="file" name="photo_before" accept="image/*" capture="environment">
            </label>

            <label>Jenis Tindakan Perbaikan (<?= e($unit['job_desk_name'] ?: 'Job Desk Corrective') ?>) *
                <select name="action_type" required>
                    <?= corrective_action_type_options($pdo, null, (int)($unit['asset_group_id'] ?? 0), (int)($unit['asset_type_id'] ?? 0), $unit['job_desk_name'] ?? null) ?>
                </select>
            </label>

            <label>Tindakan & Solusi yang Dilakukan *
                <textarea name="solution_details" style="min-height:80px;" required placeholder="Jelaskan langkah perbaikan yang telah dilakukan di lokasi..."></textarea>
            </label>

            <!-- Penggantian Part -->
            <div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px;margin:12px 0;">
                <span style="font-weight:700;font-size:13px;color:#38bdf8;">📦 Ada Penggantian Sparepart?</span>
                <label>Nama Part Baru
                    <input name="part_new_name" placeholder="Misal: SSD 512GB Kingston">
                </label>
                <label>Part Lama yang Digantikan
                    <input name="part_old_name" placeholder="Misal: HDD 500GB Seagate">
                </label>
                <label>Serial Number Part Baru
                    <input name="part_new_serial" placeholder="SN part baru">
                </label>
                <label>Harga Part (Rp)
                    <input type="number" name="part_cost" value="0">
                </label>
            </div>

            <label>Foto Bukti Selesai Perbaikan
                <input type="file" name="photo_after" accept="image/*" capture="environment">
            </label>

            <label>Status Akhir Tiket
                <select name="status">
                    <option value="resolved" selected>✓ Resolved (Perbaikan Selesai)</option>
                    <option value="in_progress">⏳ In Progress (Sedang Dikerjakan)</option>
                    <option value="pending_part">📦 Waiting Part (Menunggu Part)</option>
                </select>
            </label>

            <button class="btn btn-success" style="margin-top:16px;font-size:16px;padding:14px;">
                ✓ SIMPAN & SELESAIKAN PEKERJAAN
            </button>
            <a href="<?= route_url('mobile_service') ?>" class="btn btn-secondary" style="margin-top:8px;">Batal</a>
        </form>
    </div>
    <?php
    render_mobile_service_footer('scan');
}

function handle_route_mobile_service_tasks(PDO $pdo): void
{
    $user = require_corrective_technician();
    ensure_corrective_maintenance_schema($pdo);

    $sql = "SELECT t.*, ma.maintenance_asset_code, ma.name AS asset_name
            FROM corrective_tickets t
            LEFT JOIN maintenance_assets ma ON ma.id = t.maintenance_asset_id
            WHERE t.status IN ('open', 'assigned', 'in_progress', 'pending_part')
            ORDER BY t.priority = 'critical' DESC, t.created_at DESC";
    $tickets = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    render_mobile_service_header('Daftar Tugas Tiket', $user);
    ?>
    <div class="card">
        <h2>📋 Daftar Tiket Menunggu Tindakan (<?= count($tickets) ?>)</h2>
        <p style="font-size:13px;color:#94a3b8;margin:0 0 12px;">Pilih tiket untuk eksekusi atau verifikasi di lokasi.</p>

        <?php if (!$tickets): ?>
            <p style="font-size:14px;color:#10b981;text-align:center;padding:24px 0;">🎉 Luar biasa! Seluruh tiket kerusakan telah selesai ditangani.</p>
        <?php else: ?>
            <?php foreach ($tickets as $t): ?>
                <div style="background:#0f172a;border:1px solid #334155;border-radius:10px;padding:14px;margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <span style="font-weight:700;color:#38bdf8;font-size:15px;"><?= e($t['ticket_code']) ?></span>
                            <div style="font-size:13px;font-weight:600;color:#f8fafc;margin-top:2px;">
                                📍 <?= e($t['maintenance_asset_code'] ?: ($t['pc_id'] ? 'PC ' . $t['pc_id'] : 'Unit')) ?> - <?= e($t['asset_name'] ?: '-') ?>
                            </div>
                        </div>
                        <div>
                            <?= ticket_priority_badge($t['priority']) ?>
                        </div>
                    </div>

                    <p style="font-size:14px;margin:8px 0;color:#e2e8f0;font-weight:600;"><?= e($t['subject']) ?></p>
                    <p style="font-size:12px;color:#94a3b8;margin:4px 0;"><?= e(mb_strimwidth((string)$t['description'], 0, 80, '...')) ?></p>

                    <div style="font-size:11px;color:#64748b;margin-top:6px;">
                        Pelapor: <?= e($t['reporter_name']) ?> | Lokasi: <?= e($t['location_label'] ?: '-') ?>
                    </div>

                    <a href="<?= route_url('mobile_service_ticket', ['id' => $t['id']]) ?>" class="btn btn-primary" style="margin-top:10px;">
                        Mulai Kerjakan Tiket Ini →
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    render_mobile_service_footer('tasks');
}

function handle_route_mobile_service_ticket(PDO $pdo): void
{
    $user = require_corrective_technician();
    ensure_corrective_maintenance_schema($pdo);

    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT t.*, ma.maintenance_asset_code, ma.name AS asset_name, 
                                  ma.asset_group_id AS ma_group_id, ma.asset_type_id AS ma_type_id,
                                  ai.asset_group_id AS ai_group_id, ai.asset_type_id AS ai_type_id,
                                  c.company_name
                           FROM corrective_tickets t
                           LEFT JOIN maintenance_assets ma ON ma.id = t.maintenance_asset_id
                           LEFT JOIN asset_items ai ON ai.id = t.asset_item_id
                           LEFT JOIN asset_companies c ON c.id = t.company_id
                           WHERE t.id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        flash('Tiket tidak ditemukan.', 'err');
        redirect_to('mobile_service_tasks');
    }

    $unit = null;
    if (!empty($ticket['maintenance_asset_id'])) {
        $unit = get_maintenance_asset_unit($pdo, (int)$ticket['maintenance_asset_id']);
    }
    $ticketGroupId = (int)($unit['asset_group_id'] ?? ($ticket['ma_group_id'] ?: ($ticket['ai_group_id'] ?? 0)));
    $ticketTypeId = (int)($unit['asset_type_id'] ?? ($ticket['ma_type_id'] ?: ($ticket['ai_type_id'] ?? 0)));

    render_mobile_service_header('Kerjakan Tiket #' . $ticket['ticket_code'], $user);
    ?>
    <div class="card" style="border-left:4px solid #38bdf8;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
                <span style="font-size:12px;color:#94a3b8;">Tiket ID</span>
                <h2 style="margin:0;color:#38bdf8;font-size:18px;"><?= e($ticket['ticket_code']) ?></h2>
            </div>
            <div>
                <?= ticket_status_badge($ticket['status']) ?>
            </div>
        </div>

        <div style="margin-top:10px;font-size:13px;line-height:1.6;">
            <div><strong>Unit:</strong> <?= e($ticket['maintenance_asset_code'] ?: '-') ?> (<?= e($ticket['asset_name'] ?: '-') ?>)</div>
            <?php if (!empty($unit['item_asset_code']) && $unit['item_asset_code'] !== $ticket['maintenance_asset_code']): ?>
                <div><strong>Kode Asset:</strong> <span style="color:#38bdf8;font-weight:600;"><?= e($unit['item_asset_code']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($unit['group_name']) || !empty($unit['type_name'])): ?>
                <div><strong>Kategori:</strong> <span style="color:#38bdf8;font-weight:600;"><?= e($unit['group_name'] ?: 'IT') ?> &bull; <?= e($unit['type_name'] ?: ($unit['asset_category'] ?? 'Comp')) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($unit['job_desk_name'])): ?>
                <div style="margin-top:4px;"><span style="font-size:12px;color:#a5f3fc;background:#0369a1;padding:3px 8px;border-radius:6px;display:inline-block;">📋 <strong>Job Desk:</strong> <?= e($unit['job_desk_name']) ?></span></div>
            <?php endif; ?>
            <div><strong>Lokasi:</strong> <?= e($ticket['location_label'] ?: '-') ?></div>
            <div><strong>Pelapor:</strong> <?= e($ticket['reporter_name']) ?> <?= $ticket['reporter_contact'] ? '(' . e($ticket['reporter_contact']) . ')' : '' ?></div>
        </div>

        <div style="background:#0f172a;border-radius:6px;padding:10px;margin-top:10px;">
            <div style="font-weight:700;color:#f8fafc;font-size:13px;"><?= e($ticket['subject']) ?></div>
            <div style="font-size:12px;color:#cbd5e1;margin-top:4px;"><?= nl2br(e($ticket['description'] ?: '-')) ?></div>
            <?php if ($ticket['photo_before']): ?>
                <div style="margin-top:8px;">
                    <a href="<?= e($ticket['photo_before']) ?>" target="_blank" style="color:#38bdf8;font-size:12px;">📷 Lihat Foto Kerusakan Awal</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>🛠️ Form Tindakan Perbaikan</h2>
        <form method="post" action="<?= route_url('ticket_action') ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
            <input type="hidden" name="technician_name" value="<?= e($user['name']) ?>">

            <label>Jenis Tindakan (<?= e($unit['job_desk_name'] ?? 'Job Desk Corrective') ?>) *
                <select name="action_type" required>
                    <?= corrective_action_type_options($pdo, null, $ticketGroupId, $ticketTypeId, $unit['job_desk_name'] ?? null) ?>
                </select>
            </label>

            <label>Diagnosa / Penyebab Kerusakan
                <textarea name="root_cause_analysis" style="min-height:60px;" placeholder="Penyebab kerusakan jika diketahui..."></textarea>
            </label>

            <label>Tindakan & Solusi Perbaikan *
                <textarea name="solution_details" style="min-height:80px;" required placeholder="Langkah perbaikan yang dilakukan secara detail..."></textarea>
            </label>

            <!-- Penggantian Part -->
            <div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px;margin:12px 0;">
                <span style="font-weight:700;font-size:13px;color:#38bdf8;">📦 Pasang Sparepart Baru? (Opsional)</span>
                <label>Nama Part Baru
                    <input name="parts[0][new_name]" placeholder="Misal: SSD 512GB NVMe">
                </label>
                <label>Part Lama yang Dilepas
                    <input name="parts[0][old_name]" placeholder="Misal: SSD 256GB Rusak">
                </label>
                <label>Serial Number Part Baru
                    <input name="parts[0][new_serial]" placeholder="SN part baru">
                </label>
                <label>Harga Part (Rp)
                    <input type="number" name="parts[0][cost]" value="0">
                </label>
            </div>

            <label>Foto Bukti Selesai Perbaikan
                <input type="file" name="photo_after" accept="image/*" capture="environment">
            </label>

            <label>Update Status Tiket
                <select name="update_status">
                    <option value="resolved" selected>✓ Resolved (Perbaikan Selesai)</option>
                    <option value="in_progress">⏳ In Progress (Sedang Dikerjakan)</option>
                    <option value="pending_part">📦 Waiting Part (Menunggu Part)</option>
                    <option value="closed">✕ Closed (Tutup Tiket)</option>
                </select>
            </label>

            <button class="btn btn-success" style="margin-top:16px;font-size:16px;padding:14px;">
                ✓ SIMPAN TINDAKAN TEKNISI
            </button>
            <a href="<?= route_url('mobile_service_tasks') ?>" class="btn btn-secondary" style="margin-top:8px;">Kembali</a>
        </form>
    </div>
    <?php
    render_mobile_service_footer('tasks');
}

