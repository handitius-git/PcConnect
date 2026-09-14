<?php

declare(strict_types=1);

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
    if ($existingCode !== '' && strlen($existingCode) >= 4) {
        return $existingCode;
    }
    $code = (string)random_int(1000, 9999);
    try {
        $pdo->prepare('UPDATE maintenance_schedules SET photo_challenge_code=?, photo_challenge_generated_at=? WHERE id=?')->execute([
            $code,
            date('Y-m-d H:i:s'),
            $scheduleId,
        ]);
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

function smart_maintenance_card(array $schedule): string
{
    $hardware = $schedule['general_specs'] ? json_decode((string)$schedule['general_specs'], true) : [];
    $benchmark = $schedule['benchmark'] ? json_decode((string)$schedule['benchmark'], true) : [];
    $score = 100;
    if ((float)($hardware['ram_gb'] ?? 8) < 8) {
        $score -= 15;
    }
    if (isset($benchmark['storage_write_mbps']) && (float)$benchmark['storage_write_mbps'] < 80) {
        $score -= 20;
    }
    if (isset($benchmark['cpu_quick_ms']) && (float)$benchmark['cpu_quick_ms'] > 2500) {
        $score -= 15;
    }
    $html = '<section class="panel"><h2>Smart Maintenance</h2><div class="grid two"><div><strong>Health Score</strong><br>' . e(max(0, $score)) . '%</div><div><strong>RAM</strong><br>' . e($hardware['ram_gb'] ?? '-') . ' GB</div><div><strong>Storage</strong><br>' . e(isset($benchmark['storage_write_mbps']) ? round((float)$benchmark['storage_write_mbps'], 1) . ' MB/s' : '-') . '</div><div><strong>Benchmark</strong><br>' . e(isset($benchmark['cpu_quick_ms']) ? round((float)$benchmark['cpu_quick_ms'], 1) . ' ms' : '-') . '</div></div>';
    $html .= '<p><strong>AI</strong><br>' . e($schedule['ai_recommendation'] ?: 'Belum ada rekomendasi PcNalisa.') . '</p></section>';
    return $html;
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
    $eqr = trim((string)($_GET['eqr'] ?? $_POST['eqr'] ?? ''));
    if ($eqr !== '' && function_exists('qr_decrypt_payload')) {
        $decrypted = qr_decrypt_payload($eqr);
        if ($decrypted !== null && $decrypted !== '') {
            $code = $decrypted;
        }
    }
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
            flash('Scan selesai valid. Maintenance completed dan report locked.');
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
    $manualNote = ($user['role'] ?? '') === 'technician' ? '<p class="muted">Untuk teknisi, Maintenance Asset ID hanya bisa terisi dari live scan kamera.</p>' : '<p class="muted">Admin masih dapat mengetik Maintenance Asset ID untuk troubleshooting.</p>';
    echo '<section class="panel"><h1>' . e($scanTitle) . '</h1><p class="muted">QR terenkripsi otomatis divalidasi oleh sistem.</p><div id="reader" style="display:none;width:100%;max-width:420px"></div><video id="video" class="scan-video" autoplay muted playsinline></video><p id="cameraStatus" class="camera-note">Tekan Mulai Live Scan. Kamera dan GPS wajib aktif untuk teknisi.</p><p><button type="button" id="startCamera" class="btn primary">Mulai Live Scan</button></p>' . $manualNote . '<form method="post" id="scanForm"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="id" value="' . e($scheduleId) . '"><input type="hidden" name="phase" value="' . e($phase) . '"><input type="hidden" id="lat" name="lat"><input type="hidden" id="lng" name="lng"><input type="hidden" id="eqr" name="eqr" value="' . e($eqr) . '"><label>Maintenance Asset ID<input id="code" name="code" value="' . e($code) . '" placeholder="Terisi otomatis dari live scan QR" required' . $codeReadonly . '></label><button class="btn primary">Validasi QR</button></form></section>';
    echo '<script src="https://unpkg.com/html5-qrcode" onerror="window.__qrLibFailed=true"></script><script>(function(){var code=document.getElementById("code"),eqrInput=document.getElementById("eqr"),status=document.getElementById("cameraStatus"),video=document.getElementById("video"),reader=document.getElementById("reader"),detector=null,timer=null,html5=null,submitted=false,gpsReady=false;function extractData(raw){var v=String(raw||"").trim();try{var u=new URL(v,window.location.href);if(u.searchParams.get("eqr")){return {eqr:u.searchParams.get("eqr")};}if(u.searchParams.get("code")){v=u.searchParams.get("code");}}catch(e){if(v.indexOf("eqr=")>=0){return {eqr:v.split("eqr=").pop().split("&")[0]};}if(v.indexOf("code=")>=0){v=v.split("code=").pop().split("&")[0];}}v=decodeURIComponent(v).trim().toUpperCase();var m=v.match(/[A-Z0-9_-]+-[A-Z0-9]{2,12}/);return {code:m?m[0]:v};}function handleScan(raw){if(submitted){return;}var res=extractData(raw);if(res.eqr){eqrInput.value=res.eqr;code.value="[ENCRYPTED_QR]";status.textContent="QR terenkripsi terbaca. Memvalidasi...";submitted=true;setTimeout(function(){document.getElementById("scanForm").submit();},400);return;}if(res.code){code.value=res.code;status.textContent="QR terbaca: "+res.code+". Memvalidasi...";submitted=true;setTimeout(function(){document.getElementById("scanForm").submit();},400);}}if(navigator.geolocation){navigator.geolocation.getCurrentPosition(function(p){document.getElementById("lat").value=p.coords.latitude.toFixed(7);document.getElementById("lng").value=p.coords.longitude.toFixed(7);gpsReady=true;status.textContent="GPS siap. Akurasi sekitar "+Math.round(p.coords.accuracy)+" meter.";},function(){},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}if("BarcodeDetector" in window){detector=new BarcodeDetector({formats:["qr_code"]});}document.getElementById("startCamera").onclick=async function(){if(window.Html5Qrcode){try{video.style.display="none";reader.style.display="block";html5=new Html5Qrcode("reader");await html5.start({facingMode:"environment"},{fps:10,qrbox:{width:240,height:240}},function(decoded){handleScan(decoded);});return;}catch(e){}}if(navigator.mediaDevices&&navigator.mediaDevices.getUserMedia){try{var s=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}}});video.srcObject=s;video.style.display="block";clearInterval(timer);timer=setInterval(async function(){try{if(detector){var r=await detector.detect(video);if(r&&r[0]){handleScan(r[0].rawValue);}}}catch(e){}},800);}catch(e){status.textContent="Kamera tidak dapat diakses.";}}};})();</script>';
    render_mobile_footer();
}

function mobile_job(PDO $pdo): void
{
    $user = require_role(['admin', 'technician']);
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    ensure_base_schema_from_sql($pdo);
    ensure_photo_challenge_schema($pdo);
    ensure_maintenance_work_schema($pdo);
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
        flash('Scan QR asset terlebih dahulu sebelum memulai pekerjaan.', 'err');
        redirect_to('mobile_schedule');
    }

    $locked = $schedule['status'] === 'completed';
    $hasBeforePhotos = !empty($schedule['before_photos']);

    if (isset($_GET['retake_before']) && $_GET['retake_before'] === '1' && !$locked) {
        $pdo->prepare('UPDATE maintenance_schedules SET before_photos=NULL, before_photo_at=NULL WHERE id=?')->execute([$id]);
        add_timeline($pdo, $id, 'before_photo_reset', 'Foto sebelum di-reset untuk pengambilan ulang.', (int)$user['id']);
        flash('Foto sebelum di-reset. Silakan ambil foto sebelum yang baru.');
        redirect_to('mobile_job', ['id' => $id]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? 'complete_job');

        if ($action === 'save_before') {
            if ($locked) {
                flash('Report sudah locked.', 'err');
                redirect_to('mobile_job', ['id' => $id]);
            }
            $uploadMeta = [];
            $before = save_uploaded_photos('before_photos', $id, 'before', $uploadMeta);
            if (empty($before)) {
                flash('Foto sebelum wajib diambil dari kamera langsung.', 'err');
                redirect_to('mobile_job', ['id' => $id]);
            }
            $photoAuditMeta = build_photo_audit_meta($pdo, $id, $uploadMeta, (string)($_POST['photo_evidence_meta'] ?? ''));
            $locationError = photo_evidence_location_error($photoAuditMeta);
            if ($locationError !== null) {
                flash($locationError, 'err');
                redirect_to('mobile_job', ['id' => $id]);
            }

            $challengeCode = trim((string)($schedule['photo_challenge_code'] ?? ''));
            if ($challengeCode === '') {
                $challengeCode = ensure_photo_challenge($pdo, $id);
            }
            $beforeUrl = $before[0] ?? '';
            $aiAudit = evaluate_photo_challenge_ai($pdo, $id, $beforeUrl, $challengeCode);
            $photoAuditMeta['ai_challenge_audit'] = $aiAudit;
            $photoAuditMeta['photo_challenge_code'] = $challengeCode;

            $nowStr = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE maintenance_schedules SET before_photos=?, before_photo_at=?, photo_audit_meta=? WHERE id=?')->execute([
                json_encode($before, JSON_UNESCAPED_SLASHES),
                $nowStr,
                json_encode($photoAuditMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $id,
            ]);
            $aiNote = !empty($aiAudit['note']) ? ' [AI: ' . $aiAudit['note'] . ']' : '';
            add_timeline($pdo, $id, 'before_photo_saved', 'Foto sebelum berhasil disimpan pada ' . $nowStr . '. Kode challenge: ' . $challengeCode . $aiNote . '. Teknisi mulai pengerjaan checklist.', (int)$user['id']);
            flash('Foto sebelum berhasil disimpan. Checklist job desk kini dapat dipilih dan dikerjakan.');
            redirect_to('mobile_job', ['id' => $id]);
        }

        if ($action === 'complete_job') {
            if ($locked) {
                flash('Report sudah locked.', 'err');
                redirect_to('mobile_job', ['id' => $id]);
            }
            $pdo->prepare('UPDATE schedule_jobs SET is_done=0, done_at=NULL WHERE schedule_id=?')->execute([$id]);
            save_schedule_job_notes($pdo, $id, $_POST['job_notes'] ?? []);
            foreach ($_POST['done'] ?? [] as $jobId) {
                $pdo->prepare('UPDATE schedule_jobs SET is_done=1, done_at=NOW() WHERE schedule_id=? AND job_id=?')->execute([$id, (int)$jobId]);
            }
            $uploadMeta = [];
            $process = save_uploaded_photos('process_photos', $id, 'process', $uploadMeta);
            $after = save_uploaded_photos('after_photos', $id, 'after', $uploadMeta);
            if (empty($after)) {
                flash('Foto sesudah wajib diambil dari kamera langsung.', 'err');
                redirect_to('mobile_job', ['id' => $id]);
            }
            $before = json_decode((string)$schedule['before_photos'], true) ?: [];
            if (empty($before)) {
                flash('Foto sebelum belum tersimpan. Ambil foto sebelum terlebih dahulu.', 'err');
                redirect_to('mobile_job', ['id' => $id]);
            }

            // Ambil photo_audit_meta dari schedule untuk memuat riwayat upload foto sebelum & evaluasi AI
            $schStmt = $pdo->prepare('SELECT photo_audit_meta, photo_challenge_code FROM maintenance_schedules WHERE id=?');
            $schStmt->execute([$id]);
            $schRow = $schStmt->fetch() ?: [];
            $schMeta = json_decode((string)($schRow['photo_audit_meta'] ?? ''), true) ?: [];

            $combinedUploadMeta = array_merge($schMeta['uploads'] ?? [], $uploadMeta);
            $photoAuditMeta = build_photo_audit_meta($pdo, $id, $combinedUploadMeta, (string)($_POST['photo_evidence_meta'] ?? ''));
            $durationConfirmed = (!empty($_POST['duration_confirmed']) && (string)$_POST['duration_confirmed'] === '1');

            if (!empty($schMeta['ai_challenge_audit'])) {
                $photoAuditMeta['ai_challenge_audit'] = $schMeta['ai_challenge_audit'];
            }
            $photoAuditMeta['photo_challenge_code'] = (string)($schRow['photo_challenge_code'] ?? '');

            // Hitung selisih waktu real: waktu foto sesudah dikurangi waktu foto sebelum
            $startTimeStr = (string)($schedule['before_photo_at'] ?? $schedule['arrival_at'] ?? '');
            $startTime = $startTimeStr !== '' ? strtotime($startTimeStr) : 0;
            $endTime = time();
            $elapsedSeconds = $startTime > 0 ? max(0, $endTime - $startTime) : 0;
            $realDurationMinutes = max(0.1, round($elapsedSeconds / 60, 1));
            $minimumMinutes = schedule_minimum_photo_minutes($pdo, $id);

            $photoAuditMeta['real_duration_minutes'] = $realDurationMinutes;
            $photoAuditMeta['minimum_duration_minutes'] = $minimumMinutes;

            // CEGAT jika durasi pengerjaan < minimal job desk dan teknisi belum konfirmasi "Ya":
            if ($realDurationMinutes < $minimumMinutes && !$durationConfirmed) {
                $_SESSION['duration_warning_schedule_id'] = $id;
                flash('Anda mengerjakannya job desk ini di bawah minimal waktu pengerjaan , apakah Anda yakin sudah selesai', 'err');
                redirect_to('mobile_job', ['id' => $id, 'duration_warning' => '1']);
            }

            if ($durationConfirmed) {
                $photoAuditMeta['duration_override_approved'] = true;
            }

            $locationError = photo_evidence_location_error($photoAuditMeta);
            if ($locationError !== null) {
                flash($locationError, 'err');
                redirect_to('mobile_job', ['id' => $id]);
            }
            unset($_SESSION['duration_warning_schedule_id']);

            $signaturePath = save_signature_data((string)($_POST['signature_data'] ?? ''), $id);
            $hash = hash('sha256', $id . '|' . ($schedule['asset_id'] ?? '') . '|' . json_encode($before) . '|' . json_encode($process) . '|' . json_encode($after) . '|' . $signaturePath);
            try {
                $stmt = $pdo->prepare('INSERT INTO maintenance_reports (schedule_id, technician_id, condition_rating, physical_condition, additional_notes, before_photos, process_photos, after_photos, photo_challenge_code, photo_audit_meta, signature_name, signature_path, report_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $id,
                    $user['id'],
                    $_POST['condition_rating'] ?? null,
                    trim((string)($_POST['physical_condition'] ?? '')),
                    trim((string)($_POST['additional_notes'] ?? '')),
                    json_encode($before, JSON_UNESCAPED_SLASHES),
                    json_encode($process, JSON_UNESCAPED_SLASHES),
                    json_encode($after, JSON_UNESCAPED_SLASHES),
                    (string)($schRow['photo_challenge_code'] ?? ''),
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
                $durationLogNote = ($realDurationMinutes < $minimumMinutes)
                    ? " Waktu real pengerjaan: {$realDurationMinutes} menit (di bawah estimasi minimal {$minimumMinutes} menit, disetujui selesai oleh teknisi)."
                    : " Waktu real pengerjaan: {$realDurationMinutes} menit (memenuhi minimal {$minimumMinutes} menit).";
                add_timeline($pdo, $id, 'work_completed', 'Checklist dan foto tersimpan. Schedule langsung completed.' . $durationLogNote . ' Score: ' . $evidenceAudit['score'] . '/100. Hash: ' . $hash, (int)$user['id']);
                flash('Checklist dan foto tersimpan. Schedule maintenance sudah complete dan report locked.', 'ok');
            } catch (Throwable $e) {
                ensure_photo_challenge_schema($pdo);
                flash('Gagal menyimpan report maintenance: ' . $e->getMessage(), 'err');
            }
            redirect_to('mobile_job', ['id' => $id]);
        }
    }

    render_mobile_header('Pengerjaan Maintenance', $user);
    $reportStmt = $pdo->prepare('SELECT * FROM maintenance_reports WHERE schedule_id=? ORDER BY id DESC LIMIT 1');
    $reportStmt->execute([$id]);
    $latestReport = $reportStmt->fetch() ?: [];
    $minimumMinutes = schedule_minimum_photo_minutes($pdo, $id);
    $noteSelect = schedule_job_note_supported($pdo) ? 'sj.note' : "'' note";
    $jobsStmt = $pdo->prepare("SELECT j.*, sj.is_done, sj.done_at, $noteSelect FROM schedule_jobs sj JOIN maintenance_jobs j ON j.id=sj.job_id WHERE sj.schedule_id=? ORDER BY j.title");
    $jobsStmt->execute([$id]);
    $jobsList = $jobsStmt->fetchAll();

    echo '<section class="panel"><h1>' . e($schedule['asset_id']) . '</h1><p>' . e($schedule['owner_name']) . ' - ' . e($schedule['computer_name'] ?: '-') . '</p><p class="muted">Tanggal kerja: ' . e($schedule['scheduled_date']) . '</p><span class="badge">' . e($schedule['asset_type'] ?? 'pc') . '</span> <span class="badge">' . e($schedule['status']) . '</span></section>';
    echo smart_maintenance_card($schedule);

    if (!$hasBeforePhotos && !$locked) {
        $challengeCode = ensure_photo_challenge($pdo, $id, $schedule['photo_challenge_code'] ?? null);

        echo '<section class="panel" style="border-left:4px solid #0284c7;background:#f0f9ff;"><div style="display:flex;gap:10px;align-items:center;"><div><strong style="color:#0369a1;font-size:16px;">Tahap 1 dari 2: Foto Sebelum</strong><p class="muted" style="margin:4px 0 0 0;font-size:13px;">Foto sebelum wajib diambil dari kamera langsung di depan unit aset sebelum checklist job desk dapat dipilih dan dikerjakan.</p></div></div></section>';

        echo '<section class="panel" style="background:#fffbeb;border:2px dashed #f59e0b;border-radius:12px;padding:16px;margin-bottom:16px;text-align:center;">'
            . '<div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#b45309;">Dynamic Challenge Code (Anti-Duplikasi)</div>'
            . '<div style="font-size:42px;font-weight:900;letter-spacing:6px;color:#d97706;margin:8px 0;font-family:monospace;">' . e($challengeCode) . '</div>'
            . '<div style="background:#ffffff;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;margin-top:10px;text-align:left;">'
            . '<div style="font-weight:700;color:#92400e;font-size:13px;display:flex;align-items:center;gap:6px;margin-bottom:6px;">'
            . '📸 <span>Cara Foto yang Benar:</span>'
            . '</div>'
            . '<ol style="margin:0 0 0 18px;padding:0;font-size:13px;color:#78350f;line-height:1.55;">'
            . '<li>Tulis angka <strong style="color:#b45309;font-size:15px;">' . e($challengeCode) . '</strong> pada <strong>potongan kertas putih kecil (± 4 cm x 1 cm)</strong>.</li>'
            . '<li><strong>Dekatkan potongan kertas tersebut dengan stiker QR Code label</strong> pada unit aset.</li>'
            . '<li>Posisikan kamera sehingga <strong>kertas kode, stiker QR code, dan fisik unit terlihat bersamaan</strong> dalam 1 frame foto.</li>'
            . '</ol>'
            . '</div>'
            . '</section>';
        
        echo '<section class="panel"><h2>Daftar Job Desk Terjadwal</h2><p class="muted">Batas durasi kumulatif seluruh pekerjaan: <strong>' . (int)$minimumMinutes . ' Menit</strong></p>';
        if (!$jobsList) {
            echo '<p class="danger-text">Belum ada job desk dipilih untuk schedule ini.</p>';
        } else {
            foreach ($jobsList as $job) {
                $estM = max(1, (int)($job['estimated_minutes'] ?? 5));
                echo '<div class="check-row" style="opacity:0.75;"><input type="checkbox" disabled><div><span class="check-title">' . e($job['title']) . ' <small class="badge muted">+' . $estM . ' m</small></span><br><small class="muted">' . e($job['description'] ?: 'Job desk terdaftar') . '</small></div></div>';
            }
        }
        echo '</section>';

        echo '<section class="panel"><h2>Ambil Foto Sebelum</h2>'
            . '<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:13px;color:#1e40af;line-height:1.45;">'
            . '📌 <strong>Petunjuk Foto:</strong> Dekatkan potongan kertas putih kecil (± 4 cm x 1 cm) berisi kode <strong style="color:#b45309;">' . e($challengeCode) . '</strong> dengan stiker QR Code label berserta terlihat fisik unitnya.'
            . '</div>'
            . '<form method="post" enctype="multipart/form-data" onsubmit="try{localStorage.setItem(\'pcconnect_before_' . (int)$id . '\', Date.now().toString());}catch(e){}">'
            . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="save_before">'
            . '<input type="hidden" id="photoEvidenceMeta" name="photo_evidence_meta" value="">'
            . '<p id="photoCompressStatus" class="camera-note">Gunakan kamera langsung di lokasi unit aset.</p>'
            . '<label>Foto Sebelum (Kamera Langsung)<input class="photo-input" data-photo-group="before" data-photo-slot="1" type="file" name="before_photos[]" accept="image/*" capture="environment" required></label>'
            . '<button class="btn primary" style="width:100%;margin-top:12px;padding:12px;font-size:15px;">Simpan Foto Sebelum & Buka Checklist</button>'
            . '</form></section>';
    } else {
        $startTime = strtotime((string)($schedule['before_photo_at'] ?? $schedule['arrival_at'] ?? 'now'));
        $targetTime = date('H:i', $startTime + ($minimumMinutes * 60));
        $elapsedSec = max(0, time() - $startTime);
        $elapsedMin = round($elapsedSec / 60, 1);

        if (!$locked) {
            $challengeNote = !empty($schedule['photo_challenge_code']) ? ' | Kode Challenge: <strong style="color:#b45309;">' . e($schedule['photo_challenge_code']) . '</strong>' : '';
            echo '<section class="panel" style="border-left:4px solid #16a34a;background:#f0fdf4;"><div style="display:flex;justify-content:space-between;align-items:center;"><div><strong style="color:#15803d;font-size:15px;">Tahap 2 dari 2: Checklist & Pengerjaan</strong><br><span class="muted" style="font-size:12px;">Foto sebelum tersimpan pada: ' . e($schedule['before_photo_at'] ?: $schedule['arrival_at']) . $challengeNote . '</span></div><div><a class="btn" style="font-size:12px;padding:4px 8px;" href="' . route_url('mobile_job', ['id' => $id, 'retake_before' => '1']) . '" onclick="try{localStorage.removeItem(\'pcconnect_before_' . (int)$id . '\');}catch(e){}return confirm(\'Ambil ulang foto sebelum? Waktu mulai pengerjaan akan di-reset.\');">Ambil Ulang Foto</a></div></div></section>';

            $savedBefore = json_decode((string)$schedule['before_photos'], true) ?: [];
            if ($savedBefore) {
                render_photo_block('Foto Sebelum (Tersimpan)', json_encode($savedBefore));
            }

            echo '<section class="panel" style="background:#f8fafc;border:1px solid #e2e8f0;padding:12px 16px;"><div style="display:flex;justify-content:space-between;align-items:center;"><div><strong style="font-size:14px;">⏱️ Durasi Kumulatif Job Desk: ' . (int)$minimumMinutes . ' Menit</strong><br><span class="muted" style="font-size:12px;">Mulai: ' . date('H:i', $startTime) . ' | Selesai Paling Cepat: ' . $targetTime . '</span></div><div id="liveTimerBadge" style="font-weight:bold;font-size:14px;padding:4px 10px;border-radius:6px;background:#e0f2fe;color:#0369a1;">Menghitung...</div></div><div id="liveTimerStatus" style="font-size:12px;margin-top:6px;color:#475569;"></div></section>';
        }

        echo '<section class="panel ' . ($locked ? 'readonly' : '') . '"><h2>Checklist Job Desk</h2>'
            . '<form id="jobForm" method="post" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="complete_job">'
            . '<input type="hidden" id="photoEvidenceMeta" name="photo_evidence_meta" value="">'
            . '<input type="hidden" id="durationConfirmed" name="duration_confirmed" value="0">'
            . '<input type="hidden" id="realDurationMinutes" name="real_duration_minutes" value="">';

        $hasJobs = false;
        foreach ($jobsList as $job) {
            $hasJobs = true;
            $estM = max(1, (int)($job['estimated_minutes'] ?? 5));
            echo '<div class="check-row"><input type="checkbox" name="done[]" value="' . e($job['id']) . '"' . ($job['is_done'] ? ' checked' : '') . ($locked ? ' disabled' : '') . '><div><span class="check-title">' . e($job['title']) . ' <small class="badge muted">+' . $estM . ' m</small></span>' . ($job['done_at'] ? '<small class="muted">Dikerjakan: ' . e($job['done_at']) . '</small>' : '') . '<label class="check-note-label">Keterangan<input name="job_notes[' . e($job['id']) . ']" value="' . e($job['note'] ?? '') . '" placeholder="Boleh kosong" ' . ($locked ? 'readonly' : '') . '></label></div></div>';
        }
        if (!$hasJobs) {
            echo '<p class="danger-text">Belum ada jobdesk dipilih untuk schedule ini.</p>';
        }

        echo '<div class="grid two"><label>Foto Proses 1 (Opsional)<input class="photo-input" data-photo-group="process" data-photo-slot="1" type="file" name="process_photos[]" accept="image/*" capture="environment" ' . ($locked ? 'disabled' : '') . '></label><label>Foto Proses 2 (Opsional)<input class="photo-input" data-photo-group="process" data-photo-slot="2" type="file" name="process_photos[]" accept="image/*" capture="environment" ' . ($locked ? 'disabled' : '') . '></label></div>';
        echo '<label>Foto Sesudah - Kamera Langsung<input class="photo-input" data-photo-group="after" data-photo-slot="1" type="file" name="after_photos[]" accept="image/*" capture="environment" ' . ($locked ? 'disabled' : 'required') . '></label>';
        echo '<label>Kondisi Fisik Akhir<select name="condition_rating" ' . ($locked ? 'disabled' : '') . '><option>Excellent</option><option>Good</option><option>Fair</option><option>Need Repair</option><option>Need Replace</option></select></label>';
        echo '<label>Catatan Kondisi Fisik<textarea name="physical_condition" ' . ($locked ? 'readonly' : '') . '>' . e($latestReport['physical_condition'] ?? $schedule['physical_condition'] ?? '') . '</textarea></label><label>Catatan Teknisi<textarea name="additional_notes" ' . ($locked ? 'readonly' : '') . '>' . e($latestReport['additional_notes'] ?? '') . '</textarea></label>';
        echo '<label>Nama Pemakai PC / User<input name="signature_name" value="' . e($latestReport['signature_name'] ?? '') . '" ' . ($locked ? 'readonly' : '') . '></label><canvas id="sig" class="signature-pad"></canvas><input type="hidden" id="signatureData" name="signature_data"><p><button type="button" class="btn" id="clearSig" ' . ($locked ? 'disabled' : '') . '>Clear Signature</button></p>';
        echo $locked ? '<p class="muted">Report locked. Schedule complete.</p><a class="btn" href="' . route_url('report_print', ['id' => $id]) . '">Lihat Report</a>' : '<button type="submit" id="btnSubmitJob" class="btn primary" style="width:100%;padding:12px;font-size:15px;">Simpan Pekerjaan & Selesai</button>';
        echo '</form></section>';

        // Modal Peringatan Durasi di Bawah Minimal
        echo '<div id="durationWarningModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.75);z-index:99999;align-items:center;justify-content:center;padding:16px;">'
            . '<div style="background:#fff;border-radius:14px;padding:22px 20px;max-width:380px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.3);text-align:center;">'
            . '<div style="font-size:42px;line-height:1;margin-bottom:12px;">⚠️</div>'
            . '<h3 style="margin:0 0 10px;font-size:17px;font-weight:700;color:#0f172a;">Peringatan Durasi</h3>'
            . '<p style="font-size:14px;color:#334155;line-height:1.5;margin:0 0 16px;font-weight:500;">'
            . 'Anda mengerjakannya job desk ini di bawah minimal waktu pengerjaan , apakah Anda yakin sudah selesai'
            . '</p>'
            . '<div id="modalDurationDetail" style="font-size:13px;background:#f1f5f9;border-radius:8px;padding:8px 12px;margin-bottom:18px;color:#475569;text-align:left;">'
            . 'Waktu berjalan: <strong id="modalElapsedText">0 Menit</strong><br>'
            . 'Estimasi minimal: <strong id="modalMinText">0 Menit</strong>'
            . '</div>'
            . '<div style="display:flex;gap:10px;">'
            . '<button type="button" id="btnModalNo" class="btn" style="flex:1;padding:12px;font-size:15px;font-weight:600;background:#e2e8f0;color:#1e293b;border:none;border-radius:8px;cursor:pointer;">Tidak</button>'
            . '<button type="button" id="btnModalYes" class="btn primary" style="flex:1;padding:12px;font-size:15px;font-weight:600;border-radius:8px;cursor:pointer;">Ya</button>'
            . '</div>'
            . '</div>'
            . '</div>';

        if ($locked && $latestReport) {
            render_photo_block('Foto Sebelum Maintenance', $latestReport['before_photos'] ?? '');
            render_photo_block('Foto Proses Maintenance', $latestReport['process_photos'] ?? '');
            render_photo_block('Foto Sesudah Maintenance', $latestReport['after_photos'] ?? '');
        }
    }

    echo '<script>';
    echo '(function(){';
    echo 'var hidden=document.getElementById("photoEvidenceMeta"),form=hidden?hidden.form:null,evidence=[];';
    echo 'function save(){if(hidden){var isConf=document.getElementById("durationConfirmed");hidden.value=JSON.stringify({captured_at:new Date().toISOString(),duration_override_approved:!!(isConf&&isConf.value==="1"),items:evidence});}}';
    echo 'function record(input){var file=input.files&&input.files[0]?input.files[0]:null,item={group:input.getAttribute("data-photo-group")||"",slot:input.getAttribute("data-photo-slot")||"",selected_at:new Date().toISOString(),live_camera_hint:input.hasAttribute("capture"),file_name:file?file.name:"",file_size:file?file.size:0,file_type:file?file.type:"",file_last_modified:file&&file.lastModified?new Date(file.lastModified).toISOString():""};evidence=evidence.filter(function(old){return !(old.group===item.group&&old.slot===item.slot);});evidence.push(item);save();if(navigator.geolocation){navigator.geolocation.getCurrentPosition(function(pos){item.gps={lat:pos.coords.latitude,lng:pos.coords.longitude,accuracy_m:pos.coords.accuracy,taken_at:new Date().toISOString()};save();},function(err){item.gps_error=err.message||"GPS tidak tersedia";save();},{enableHighAccuracy:true,timeout:10000,maximumAge:0});}}';
    echo 'document.querySelectorAll(".photo-input").forEach(function(input){input.addEventListener("change",function(){record(input);});});';
    echo 'if(form){form.addEventListener("submit",save);}';

    echo 'var status=document.getElementById("photoCompressStatus");';
    echo 'async function compressFile(file){if(!file||!file.type.match(/^image\\//)){return file;}if(file.size<=1048576){return file;}var img=new Image();var url=URL.createObjectURL(file);await new Promise(function(resolve,reject){img.onload=resolve;img.onerror=reject;img.src=url;});var maxSide=1600,scale=Math.min(1,maxSide/Math.max(img.width,img.height)),w=Math.max(1,Math.round(img.width*scale)),h=Math.max(1,Math.round(img.height*scale)),canvas=document.createElement("canvas"),ctx=canvas.getContext("2d");canvas.width=w;canvas.height=h;ctx.drawImage(img,0,0,w,h);URL.revokeObjectURL(url);var quality=.82,blob=null;while(quality>=.45){blob=await new Promise(function(resolve){canvas.toBlob(resolve,"image/jpeg",quality);});if(blob&&blob.size<=1048576){break;}quality-=.08;}if(!blob){return file;}return new File([blob],file.name.replace(/\\.[^.]+$/,"")+".jpg",{type:"image/jpeg",lastModified:Date.now()});}';
    echo 'document.querySelectorAll(".photo-input").forEach(function(input){input.addEventListener("change",async function(){if(!input.files||!input.files.length){return;}if(status){status.textContent="Mengompres foto...";}var dt=new DataTransfer();for(var i=0;i<input.files.length;i++){dt.items.add(await compressFile(input.files[i]));}input.files=dt.files;if(status){status.textContent="Foto siap upload, maksimal 1 MB per file.";} });});';

    if ($hasBeforePhotos && !$locked) {
        $startTimeMs = (int)($startTime * 1000);
        $minMs = (int)($minimumMinutes * 60 * 1000);
        echo 'var localBeforeKey = "pcconnect_before_' . (int)$id . '";';
        echo 'var localBefore = null; try{localBefore = localStorage.getItem(localBeforeKey);}catch(e){}';
        echo 'var serverStartTimeMs = ' . $startTimeMs . ';';
        echo 'var startTimeMs = (localBefore && parseInt(localBefore,10) > 0) ? parseInt(localBefore,10) : serverStartTimeMs;';
        echo 'var minMinutes = ' . (int)$minimumMinutes . ';';
        echo 'var minMs = ' . $minMs . ';';

        echo 'function updateTimer(){';
        echo 'var now=Date.now();var elapsedMs=Math.max(0,now-startTimeMs);var elapsedSec=Math.floor(elapsedMs/1000);var elMin=Math.floor(elapsedSec/60);var elSec=elapsedSec%60;var elStr=(elMin<10?"0":"")+elMin+":"+(elSec<10?"0":"")+elSec;';
        echo 'var badge=document.getElementById("liveTimerBadge");var status=document.getElementById("liveTimerStatus");if(!badge)return;';
        echo 'if(elapsedMs<minMs){';
        echo 'var remainSec=Math.ceil((minMs-elapsedMs)/1000);var remMin=Math.floor(remainSec/60);var remSec=remainSec%60;var remStr=(remMin<10?"0":"")+remMin+":"+(remSec<10?"0":"")+remSec;';
        echo 'badge.style.background="#fef3c7";badge.style.color="#b45309";badge.textContent="⏳ "+elStr+" / "+minMinutes+"m";';
        echo 'if(status)status.textContent="Sisa waktu pengerjaan: "+remStr+" lagi sebelum batas minimal kumulasi tercapai.";';
        echo '}else{';
        echo 'badge.style.background="#dcfce7";badge.style.color="#15803d";badge.textContent="✅ "+elStr+" (Lengkap)";';
        echo 'if(status)status.textContent="Batas durasi kumulatif terpenuhi ("+minMinutes+"m). Anda dapat menyelesaikan pengerjaan.";';
        echo '}';
        echo '}updateTimer();setInterval(updateTimer,1000);';

        echo 'var durationModal = document.getElementById("durationWarningModal");';
        echo 'var btnModalYes = document.getElementById("btnModalYes");';
        echo 'var btnModalNo = document.getElementById("btnModalNo");';
        echo 'var jobForm = document.getElementById("jobForm");';

        echo 'function interceptDurationSubmit(e){';
        echo 'var afterInput = jobForm ? jobForm.querySelector("input[name=\'after_photos[]\']") : null;';
        echo 'if(afterInput && !afterInput.files.length){';
        echo 'alert("Foto sesudah wajib diambil dari kamera langsung.");if(e)e.preventDefault();return false;';
        echo '}';
        echo 'var sigCanvas = document.getElementById("sig");';
        echo 'if(sigCanvas){document.getElementById("signatureData").value = sigCanvas.toDataURL("image/png");}';
        echo 'var now=Date.now();var elapsedMs=Math.max(0,now-startTimeMs);var elapsedMin=Math.round((elapsedMs/60000)*10)/10;';
        echo 'document.getElementById("realDurationMinutes").value = elapsedMin;';
        echo 'var durationConfirmed = document.getElementById("durationConfirmed");';
        echo 'if(elapsedMs < minMs && (!durationConfirmed || durationConfirmed.value !== "1")){';
        echo 'if(e){e.preventDefault();e.stopPropagation();}';
        echo 'if(durationModal){';
        echo 'document.getElementById("modalElapsedText").textContent = elapsedMin + " Menit";';
        echo 'document.getElementById("modalMinText").textContent = minMinutes + " Menit";';
        echo 'durationModal.style.display = "flex";';
        echo '}else{';
        echo 'if(confirm("Anda mengerjakannya job desk ini di bawah minimal waktu pengerjaan , apakah Anda yakin sudah selesai")){';
        echo 'if(durationConfirmed)durationConfirmed.value="1";jobForm.submit();';
        echo '}';
        echo '}';
        echo 'return false;';
        echo '}';
        echo 'return true;';
        echo '}';

        echo 'if(jobForm){jobForm.addEventListener("submit", interceptDurationSubmit);}';

        echo 'if(btnModalNo){';
        echo 'btnModalNo.addEventListener("click", function(){';
        echo 'if(durationModal)durationModal.style.display = "none";';
        echo '});}';

        echo 'if(btnModalYes){';
        echo 'btnModalYes.addEventListener("click", function(){';
        echo 'var durationConfirmed = document.getElementById("durationConfirmed");';
        echo 'if(durationConfirmed)durationConfirmed.value = "1";';
        echo 'var hidden = document.getElementById("photoEvidenceMeta");';
        echo 'if(hidden){try{var p=JSON.parse(hidden.value||"{}");p.duration_override_approved=true;hidden.value=JSON.stringify(p);}catch(ex){}}';
        echo 'if(durationModal)durationModal.style.display = "none";';
        echo 'try{localStorage.removeItem(localBeforeKey);}catch(e){}';
        echo 'if(jobForm)jobForm.submit();';
        echo '});}';

        echo 'if(window.location.search.indexOf("duration_warning=1") !== -1 && durationModal){';
        echo 'var now=Date.now();var elapsedMs=Math.max(0,now-startTimeMs);var elapsedMin=Math.round((elapsedMs/60000)*10)/10;';
        echo 'document.getElementById("modalElapsedText").textContent = elapsedMin + " Menit";';
        echo 'document.getElementById("modalMinText").textContent = minMinutes + " Menit";';
        echo 'durationModal.style.display = "flex";';
        echo '}';
    }

    echo 'var c=document.getElementById("sig"),x=c?c.getContext("2d"):null,down=false;function fit(){if(!c)return;c.width=c.clientWidth;c.height=160;if(x){x.lineWidth=2;x.lineCap="round";}}fit();function pos(e){if(!c)return{x:0,y:0};var r=c.getBoundingClientRect(),t=e.touches?e.touches[0]:e;return{x:t.clientX-r.left,y:t.clientY-r.top};}if(c&&x){c.addEventListener("pointerdown",function(e){down=true;var p=pos(e);x.beginPath();x.moveTo(p.x,p.y);});c.addEventListener("pointermove",function(e){if(!down)return;var p=pos(e);x.lineTo(p.x,p.y);x.stroke();});window.addEventListener("pointerup",function(){down=false;});var clr=document.getElementById("clearSig");if(clr)clr.onclick=function(){x.clearRect(0,0,c.width,c.height);};}';
    echo '})();';
    echo '</script>';
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

