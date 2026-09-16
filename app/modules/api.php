<?php

declare(strict_types=1);

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
        'checklist_progress' => $summary['job_progress'] ?? '0/0',
    ];
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
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $eqr = trim((string)($input['eqr'] ?? ''));
    if ($eqr !== '' && function_exists('qr_decrypt_payload')) {
        $decrypted = qr_decrypt_payload($eqr);
        if ($decrypted) {
            $code = $decrypted;
        }
    }
    [$assetType, $assetId] = find_asset_by_code($pdo, $code);
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
    $process = save_uploaded_photos('process_photos', (int)$schedule['id'], 'process', $uploadMeta);
    $before = save_uploaded_photos('before_photos', (int)$schedule['id'], 'before', $uploadMeta);
    $after = save_uploaded_photos('after_photos', (int)$schedule['id'], 'after', $uploadMeta);
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
    $hash = hash('sha256', $schedule['id'] . '|' . ($schedule['asset_id'] ?? '') . '|' . json_encode($before) . '|' . json_encode($process) . '|' . json_encode($after));
    $pdo->prepare('DELETE FROM maintenance_reports WHERE schedule_id=? AND locked_at IS NULL')->execute([$schedule['id']]);
    $stmt = $pdo->prepare('INSERT INTO maintenance_reports (schedule_id, technician_id, condition_rating, physical_condition, additional_notes, before_photos, process_photos, after_photos, photo_audit_meta, signature_name, signature_path, report_hash, locked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');
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
    add_timeline($pdo, (int)$schedule['id'], 'work_saved', 'API checklist dan foto tersimpan. Menunggu scan QR selesai. Hash: ' . $hash, (int)$user['id']);
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

function handle_route_api_ingest(PDO $pdo): void
{
    $token = $_SERVER['HTTP_X_PCCONNECT_TOKEN'] ?? '';
    if (!hash_equals(config_value('agent_token'), $token)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid token']);
        return;
    }
    $rawInput = file_get_contents('php://input');
    [$payload, $jsonError, $cleanLength] = decode_json_payload($rawInput);
    if (!is_array($payload) || empty($payload['pc_id'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid payload', 'json_error' => $jsonError, 'received_bytes' => strlen($rawInput), 'clean_bytes' => $cleanLength]);
        return;
    }
    $result = ingest_analysis_payload($pdo, $payload);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'pc_id' => $result['pc_id'], 'summary' => $result['summary']]);
}

function handle_route_employee_search(PDO $pdo): void
{
    require_role(['admin', 'maintenance_admin']);
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $rows = employee_search_rows($pdo, $query, $limit);
    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_asset_types(PDO $pdo): void
{
    require_login();
    $groupId = (int)($_GET['group_id'] ?? 0);
    if ($groupId > 0) {
        $gStmt = $pdo->prepare("SELECT group_code FROM asset_groups WHERE id = ?");
        $gStmt->execute([$groupId]);
        $gCode = strtoupper(trim((string)$gStmt->fetchColumn()));
        $stmt = $pdo->prepare('SELECT t.id, t.asset_group_id, t.type_code, t.type_name, COALESCE(g.group_code, "") AS group_code 
                               FROM asset_types t 
                               LEFT JOIN asset_groups g ON g.id = t.asset_group_id 
                               WHERE (t.is_active=1 OR t.is_active IS NULL) 
                                 AND (t.asset_group_id = ? OR UPPER(TRIM(g.group_code)) = ?) 
                               ORDER BY t.type_name');
        $stmt->execute([$groupId, $gCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows) && function_exists('repair_asset_type_groups')) {
            repair_asset_type_groups($pdo);
            $stmt->execute([$groupId, $gCode]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $stmt = $pdo->query('SELECT t.id, t.asset_group_id, t.type_code, t.type_name, COALESCE(g.group_code, "") AS group_code 
                             FROM asset_types t 
                             LEFT JOIN asset_groups g ON g.id = t.asset_group_id 
                             WHERE (t.is_active=1 OR t.is_active IS NULL) 
                             ORDER BY t.type_name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows) && function_exists('repair_asset_type_groups')) {
            repair_asset_type_groups($pdo);
            $rows = $pdo->query('SELECT t.id, t.asset_group_id, t.type_code, t.type_name, COALESCE(g.group_code, "") AS group_code 
                                 FROM asset_types t 
                                 LEFT JOIN asset_groups g ON g.id = t.asset_group_id 
                                 WHERE (t.is_active=1 OR t.is_active IS NULL) 
                                 ORDER BY t.type_name')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_asset_type_config(PDO $pdo): void
{
    require_login();
    $typeId = (int)($_GET['type_id'] ?? 0);
    $groupId = (int)($_GET['group_id'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(asset_type_form_config($pdo, $typeId, $groupId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_job_desks(PDO $pdo): void
{
    require_login();
    $groupId = (int)($_GET['group_id'] ?? 0);
    $typeId = (int)($_GET['type_id'] ?? 0);
    $sql = 'SELECT DISTINCT job_desk_name, asset_group_id, asset_type_id 
            FROM (
                SELECT job_desk_name, asset_group_id, asset_type_id FROM preventive_job_desks WHERE is_active=1
                UNION
                SELECT job_desk_name, asset_group_id, asset_type_id FROM maintenance_jobs WHERE is_active=1
            ) t 
            WHERE job_desk_name IS NOT NULL AND job_desk_name != ""';
    $params = [];
    if ($groupId > 0) {
        $sql .= ' AND (asset_group_id = ? OR asset_group_id IS NULL)';
        $params[] = $groupId;
    }
    if ($typeId > 0) {
        $sql .= ' AND (asset_type_id = ? OR asset_type_id IS NULL)';
        $params[] = $typeId;
    }
    $sql .= ' ORDER BY job_desk_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_eligible_maintenance_asset_items(PDO $pdo): void
{
    require_login();
    $groupId = (int)($_GET['group_id'] ?? 0);
    $typeId = (int)($_GET['type_id'] ?? 0);
    $maintId = (int)($_GET['maintenance_asset_id'] ?? 0);
    $curAssetItemId = (int)($_GET['current_asset_item_id'] ?? 0);

    $where = [];
    $params = [];

    if ($groupId > 0) {
        $where[] = "ai.asset_group_id = ?";
        $params[] = $groupId;
    }
    if ($typeId > 0) {
        $where[] = "ai.asset_type_id = ?";
        $params[] = $typeId;
    }

    // Hanya Unit Aset Standalone atau Bundle Parent (Child tidak boleh masuk Maintenance Asset)
    $where[] = "(ai.asset_mode IS NULL OR ai.asset_mode <> 'child')";
    $where[] = "NOT EXISTS (SELECT 1 FROM asset_item_members aim WHERE aim.child_asset_item_id = ai.id AND aim.detached_at IS NULL)";

    // Unit aset yang sudah pernah dimasukkan ke Maintenance Asset aktif dilarang muncul kembali
    $where[] = "(ai.id = ? OR (
        NOT EXISTS (
            SELECT 1 FROM maintenance_assets ma 
            LEFT JOIN pcs p_chk ON p_chk.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci 
            LEFT JOIN printers pr_chk ON pr_chk.prn_id COLLATE utf8mb4_unicode_ci = ma.printer_id COLLATE utf8mb4_unicode_ci
            WHERE (ma.asset_item_id = ai.id OR (ma.pc_id IS NOT NULL AND ma.pc_id != '' AND p_chk.asset_item_id = ai.id) OR (ma.printer_id IS NOT NULL AND ma.printer_id != '' AND pr_chk.asset_item_id = ai.id))
              AND ma.status <> 'inactive'
              AND (? <= 0 OR ma.id <> ?)
        )
        AND NOT EXISTS (
            SELECT 1 FROM maintenance_asset_items mai 
            WHERE mai.asset_item_id = ai.id 
              AND mai.detached_at IS NULL 
              AND (? <= 0 OR mai.maintenance_asset_id <> ?)
        )
    ))";
    $params[] = $curAssetItemId;
    $params[] = $maintId;
    $params[] = $maintId;
    $params[] = $maintId;
    $params[] = $maintId;

    $sql = "SELECT ai.id, ai.asset_code, ai.asset_name, ai.asset_type, ai.asset_category, ai.asset_mode, ai.brand, ai.model, ai.serial_number,
                   ai.company_id, ac.company_name, ai.location_id, ai.location_label,
                   COALESCE(NULLIF(ai.custodian_name, ''), p.owner_name, '') AS custodian_name,
                   COALESCE(NULLIF(ai.custodian_nik, ''), p.employee_nik, '') AS custodian_nik,
                   ai.source_pc_id, COALESCE(NULLIF(ai.source_pc_id, ''), p.pc_id) AS pc_id, p.computer_name, prn.prn_id
            FROM asset_items ai
            LEFT JOIN asset_companies ac ON ac.id = ai.company_id
            LEFT JOIN pcs p ON (p.asset_item_id = ai.id OR (ai.source_pc_id IS NOT NULL AND ai.source_pc_id != '' AND p.pc_id COLLATE utf8mb4_unicode_ci = ai.source_pc_id COLLATE utf8mb4_unicode_ci))
            LEFT JOIN printers prn ON prn.asset_item_id = ai.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY ai.asset_code ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($items) && db_table_exists($pdo, 'asset_item_members')) {
        $parentIds = array_map(fn($it) => (int)$it['id'], array_filter($items, fn($it) => ($it['asset_mode'] ?? '') === 'group'));
        if (!empty($parentIds)) {
            $inParents = implode(',', $parentIds);
            $cStmt = $pdo->query("SELECT aim.parent_asset_item_id, aim.role_name, c.asset_code, c.asset_name, c.serial_number, c.asset_type
                                  FROM asset_item_members aim
                                  JOIN asset_items c ON c.id = aim.child_asset_item_id
                                  WHERE aim.parent_asset_item_id IN ($inParents) AND aim.detached_at IS NULL
                                  ORDER BY aim.id ASC");
            $childrenMap = [];
            while ($cRow = $cStmt->fetch(PDO::FETCH_ASSOC)) {
                $childrenMap[(int)$cRow['parent_asset_item_id']][] = $cRow;
            }
            foreach ($items as &$it) {
                $it['bundle_children'] = $childrenMap[(int)$it['id']] ?? [];
            }
            unset($it);
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_master_items(PDO $pdo): void
{
    require_login();
    $groupId = (int)($_GET['group_id'] ?? 0);
    $typeId = (int)($_GET['type_id'] ?? 0);
    $brandId = (int)($_GET['brand_id'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));

    $sql = 'SELECT ami.*, ag.group_name, ag.group_code, at.type_name, at.type_code, ab.brand_name, ab.brand_code 
            FROM asset_master_items ami 
            LEFT JOIN asset_groups ag ON ag.id = ami.asset_group_id 
            LEFT JOIN asset_types at ON at.id = ami.asset_type_id 
            LEFT JOIN asset_brands ab ON ab.id = ami.brand_id 
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
    if ($brandId > 0) {
        $sql .= ' AND ami.brand_id = ?';
        $params[] = $brandId;
    }
    if ($q !== '') {
        $sql .= ' AND (ami.item_code LIKE ? OR ami.item_name LIKE ? OR ami.model_name LIKE ? OR ab.brand_name LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY ami.item_name ASC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_asset_brands(PDO $pdo): void
{
    require_login();
    $groupId = (int)($_GET['group_id'] ?? 0);
    $typeId = (int)($_GET['type_id'] ?? 0);

    $where = ["b.is_active = 1"];
    $params = [];
    if ($groupId > 0) {
        $where[] = "(b.asset_group_id = ? OR b.asset_group_id IS NULL)";
        $params[] = $groupId;
    }
    if ($typeId > 0) {
        $where[] = "(b.asset_type_id = ? OR b.asset_type_id IS NULL)";
        $params[] = $typeId;
    }

    $sql = "SELECT b.id, b.asset_group_id, b.asset_type_id, b.brand_code, b.brand_name, 
                   g.group_code, g.group_name, t.type_code, t.type_name 
            FROM asset_brands b 
            LEFT JOIN asset_groups g ON g.id = b.asset_group_id 
            LEFT JOIN asset_types t ON t.id = b.asset_type_id 
            WHERE " . implode(' AND ', $where) . " 
            ORDER BY b.brand_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_trace_identifier(PDO $pdo): void
{
    require_login();
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => []]);
        return;
    }

    $wildcard = '%' . $q . '%';
    $sql = "SELECT DISTINCT ai.id AS asset_item_id, ai.asset_code, ai.asset_name, ai.serial_number,
                   ai.model, ai.brand, ai.status, ai.asset_mode, ai.custodian_name, ai.custodian_nik,
                   ai.location_label, c.company_name,
                   ag.group_name, ag.group_code,
                   at.type_name, at.type_code,
                   ami.item_code AS master_item_code, ami.item_name AS master_item_name,
                   ati.identifier_name, ati.identifier_code,
                   aid.identifier_value,
                   ma.maintenance_asset_code
            FROM asset_items ai
            LEFT JOIN asset_identifiers aid ON aid.asset_item_id = ai.id
            LEFT JOIN asset_type_identifiers ati ON ati.id = aid.asset_type_identifier_id
            LEFT JOIN asset_groups ag ON ag.id = ai.asset_group_id
            LEFT JOIN asset_types at ON at.id = ai.asset_type_id
            LEFT JOIN asset_master_items ami ON ami.id = ai.master_item_id
            LEFT JOIN asset_companies c ON c.id = ai.company_id
            LEFT JOIN maintenance_assets ma ON ma.asset_item_id = ai.id
            WHERE aid.identifier_value LIKE ?
               OR ai.serial_number LIKE ?
               OR ai.asset_code LIKE ?
            ORDER BY ai.asset_code ASC
            LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$wildcard, $wildcard, $wildcard]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'query' => $q, 'count' => count($results), 'data' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_pengguna_assets(PDO $pdo): void
{
    require_login();
    $nik = trim((string)($_GET['nik'] ?? ''));
    $name = trim((string)($_GET['name'] ?? ''));
    if (function_exists('get_user_linked_assets')) {
        $assets = get_user_linked_assets($pdo, $nik, $name);
    } else {
        $assets = ['pcs' => [], 'assets' => [], 'printers' => []];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $assets], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_route_api_pc_import(PDO $pdo): void
{
    require_login();
    header('Content-Type: application/json; charset=utf-8');

    // Mode 1: Search list of PCs
    if (isset($_GET['q'])) {
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT p.pc_id, p.computer_name, p.owner_name, p.employee_nik, p.asset_item_id, p.location_label,
                       p.general_specs, ai.asset_code
                FROM pcs p
                LEFT JOIN asset_items ai ON ai.id = p.asset_item_id
                WHERE p.pc_id LIKE ? OR p.computer_name LIKE ? OR p.owner_name LIKE ? OR p.employee_nik LIKE ? OR p.general_specs LIKE ?
                ORDER BY p.pc_id ASC
                LIMIT 50";
        $params = array_fill(0, 5, "%{$q}%");
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map employee_directory safely without collation collision
        $nikList = [];
        foreach ($results as $r) {
            $nik = trim((string)($r['employee_nik'] ?? ''));
            if ($nik !== '') {
                $nikList[] = $nik;
            }
        }
        $edMap = [];
        if (!empty($nikList) && db_table_exists($pdo, 'employee_directory')) {
            $inClause = implode(',', array_fill(0, count($nikList), '?'));
            $sEd = $pdo->prepare("SELECT nik, employee_name, department FROM employee_directory WHERE nik IN ({$inClause})");
            $sEd->execute($nikList);
            foreach ($sEd->fetchAll(PDO::FETCH_ASSOC) as $edRow) {
                $edMap[trim((string)$edRow['nik'])] = $edRow;
            }
        }
        foreach ($results as &$r) {
            $nik = trim((string)($r['employee_nik'] ?? ''));
            $r['directory_name'] = $edMap[$nik]['employee_name'] ?? '';
            $r['department'] = $edMap[$nik]['department'] ?? '';

            // Extract manufacturer & model from general_specs
            $rawSpecs = json_decode((string)($r['general_specs'] ?? ''), true) ?: [];
            $mfr = trim((string)($rawSpecs['manufacturer'] ?? ($rawSpecs['system']['manufacturer'] ?? '')));
            $mdl = trim((string)($rawSpecs['model'] ?? ($rawSpecs['system']['model'] ?? '')));

            if ($mfr === '' && !empty($r['computer_name'])) {
                if (stripos($r['computer_name'], 'LENOVO') !== false) {
                    $mfr = 'LENOVO';
                } elseif (stripos($r['computer_name'], 'DELL') !== false) {
                    $mfr = 'Dell';
                } elseif (stripos($r['computer_name'], 'HP') !== false) {
                    $mfr = 'HP';
                }
            }

            $r['manufacturer'] = $mfr;
            $r['model'] = $mdl;
            $r['specs'] = [
                'manufacturer' => $mfr,
                'model' => $mdl,
                'processor' => trim((string)($rawSpecs['processor'] ?? '')),
                'ram' => isset($rawSpecs['ram_gb']) ? ($rawSpecs['ram_gb'] . ' GB') : '',
            ];
            unset($r['general_specs']);
        }
        unset($r);

        echo json_encode(['ok' => true, 'data' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Mode 2: Get single PC details for import
    $pcId = strtoupper(trim((string)($_GET['pc_id'] ?? '')));
    if ($pcId === '') {
        echo json_encode(['ok' => false, 'error' => 'PC ID tidak diberikan']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT p.*, ai.asset_code 
                           FROM pcs p 
                           LEFT JOIN asset_items ai ON ai.id = p.asset_item_id
                           WHERE p.pc_id = ?");
    $stmt->execute([$pcId]);
    $pc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pc) {
        echo json_encode(['ok' => false, 'error' => 'Data PC tidak ditemukan untuk ID: ' . $pcId]);
        exit;
    }

    $empNik = trim((string)($pc['employee_nik'] ?? ''));
    $empName = '';
    $dept = '';

    if (db_table_exists($pdo, 'employee_directory')) {
        // Fetch official Master Pengguna record by NIK
        if ($empNik !== '') {
            $sEd = $pdo->prepare("SELECT nik, employee_name, department FROM employee_directory WHERE nik = ? LIMIT 1");
            $sEd->execute([$empNik]);
            $edRow = $sEd->fetch(PDO::FETCH_ASSOC);
            if ($edRow) {
                $empName = (string)$edRow['employee_name'];
                $dept = (string)$edRow['department'];
            }
        }

        // Fallback: search employee_directory by owner_name if employee_nik was empty or not matched
        if ($empName === '' && !empty($pc['owner_name'])) {
            $cleanOwner = trim((string)$pc['owner_name']);
            $sEd = $pdo->prepare("SELECT nik, employee_name, department FROM employee_directory WHERE UPPER(employee_name) = UPPER(?) OR employee_name LIKE ? LIMIT 1");
            $sEd->execute([$cleanOwner, '%' . $cleanOwner . '%']);
            $matchedEd = $sEd->fetch(PDO::FETCH_ASSOC);
            if ($matchedEd) {
                $empNik = (string)$matchedEd['nik'];
                $empName = (string)$matchedEd['employee_name'];
                $dept = (string)$matchedEd['department'];
            }
        }
    }
    if ($empName === '') {
        $empName = (string)($pc['owner_name'] ?? '');
    }

    $rawSpecs = json_decode((string)($pc['general_specs'] ?? ''), true) ?: [];
    $rawSoft = json_decode((string)($pc['software'] ?? ''), true) ?: [];

    // Extract processor
    $processor = trim((string)($rawSpecs['processor'] ?? ''));

    // Extract RAM
    $ramGb = $rawSpecs['ram_gb'] ?? '';
    $ram = $ramGb !== '' ? ($ramGb . ' GB') : '';

    // Extract storage
    $storage = '';
    if (!empty($rawSpecs['storage'])) {
        if (is_array($rawSpecs['storage'])) {
            $sList = [];
            $diskArr = isset($rawSpecs['storage'][0]) ? $rawSpecs['storage'] : [$rawSpecs['storage']];
            foreach ($diskArr as $d) {
                $m = trim((string)($d['Model'] ?? ''));
                $sz = (float)($d['Size'] ?? 0);
                $szGb = $sz > 0 ? round($sz / (1024 * 1024 * 1024)) . ' GB' : '';
                $sList[] = trim($szGb . ($m ? " ({$m})" : ''));
            }
            $storage = implode(', ', array_filter($sList));
        } elseif (is_string($rawSpecs['storage'])) {
            $storage = $rawSpecs['storage'];
        }
    }

    // Extract GPU
    $gpu = '';
    if (!empty($rawSpecs['gpu'])) {
        if (is_array($rawSpecs['gpu'])) {
            $gList = [];
            $gpuArr = isset($rawSpecs['gpu'][0]) ? $rawSpecs['gpu'] : [$rawSpecs['gpu']];
            foreach ($gpuArr as $g) {
                if (!empty($g['Name'])) {
                    $gList[] = trim((string)$g['Name']);
                }
            }
            $gpu = implode(', ', array_filter($gList));
        } elseif (is_string($rawSpecs['gpu'])) {
            $gpu = $rawSpecs['gpu'];
        }
    }

    // Extract OS
    $os = trim((string)($rawSoft['os_caption'] ?? ''));
    if ($os === '' && !empty($rawSpecs['os_caption'])) {
        $os = trim((string)$rawSpecs['os_caption']);
    }

    // Extract Manufacturer / Brand & Model
    $manufacturer = trim((string)($rawSpecs['manufacturer'] ?? ($rawSpecs['system']['manufacturer'] ?? '')));
    $model = trim((string)($rawSpecs['model'] ?? ($rawSpecs['system']['model'] ?? '')));
    if ($manufacturer === '' && !empty($pc['computer_name'])) {
        if (stripos($pc['computer_name'], 'LENOVO') !== false) {
            $manufacturer = 'LENOVO';
        } elseif (stripos($pc['computer_name'], 'DELL') !== false) {
            $manufacturer = 'Dell';
        } elseif (stripos($pc['computer_name'], 'HP') !== false) {
            $manufacturer = 'HP';
        }
    }

    echo json_encode([
        'ok' => true,
        'pc_id' => $pc['pc_id'],
        'computer_name' => (string)($pc['computer_name'] ?? ''),
        'custodian_nik' => $empNik,
        'custodian_name' => $empName,
        'department' => $dept,
        'location_label' => (string)($pc['location_label'] ?? ''),
        'asset_item_id' => (int)($pc['asset_item_id'] ?? 0),
        'existing_asset_code' => (string)($pc['asset_code'] ?? ''),
        'manufacturer' => $manufacturer,
        'model' => $model,
        'specs' => [
            'processor' => $processor,
            'ram' => $ram,
            'storage' => $storage,
            'os' => $os,
            'gpu' => $gpu,
            'manufacturer' => $manufacturer,
            'model' => $model,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handle_route_api_ai_resolve_model(PDO $pdo): void
{
    require_login();
    $mfr = trim((string)($_REQUEST['manufacturer'] ?? ''));
    $model = trim((string)($_REQUEST['model'] ?? ''));
    $processor = trim((string)($_REQUEST['processor'] ?? ''));
    $ram = trim((string)($_REQUEST['ram'] ?? ''));
    $storage = trim((string)($_REQUEST['storage'] ?? ''));

    // Match brand
    $cleanMfr = preg_replace('/\b(inc|corp|corporation|ltd|co|limited|computer|gmbh|systems)\b\.?/i', '', $mfr);
    $cleanMfr = trim($cleanMfr, " ,.-");

    $brandId = null;
    $brandName = '';
    $brands = $pdo->query("SELECT id, brand_code, brand_name FROM asset_brands WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    // First try exact / substring match
    foreach ($brands as $b) {
        $bName = trim($b['brand_name']);
        if ($bName !== '' && (stripos($cleanMfr, $bName) !== false || stripos($mfr, $bName) !== false || stripos($bName, $cleanMfr) !== false)) {
            $brandId = (int)$b['id'];
            $brandName = $bName;
            break;
        }
    }
    // Specific aliases
    if (!$brandId) {
        $aliases = [
            'hewlett-packard' => 'HP',
            'hp' => 'HP',
            'asustek' => 'ASUS',
            'asus' => 'ASUS',
            'lenovo' => 'Lenovo',
            'dell' => 'Dell',
            'acer' => 'Acer',
            'apple' => 'Apple',
            'msi' => 'MSI',
        ];
        foreach ($aliases as $aliasKey => $aliasVal) {
            if (stripos($mfr, $aliasKey) !== false) {
                foreach ($brands as $b) {
                    if (strcasecmp($b['brand_name'], $aliasVal) === 0) {
                        $brandId = (int)$b['id'];
                        $brandName = $b['brand_name'];
                        break 2;
                    }
                }
            }
        }
    }

    $popularModel = '';
    $method = 'fallback';

    // 1. Try Gemini AI if API key is configured
    $apiKey = trim((string)(config_value('gemini_api_key') ?: getenv('GEMINI_API_KEY') ?: ''));
    if ($apiKey !== '' && ($model !== '' || $mfr !== '')) {
        $prompt = "You are an IT hardware expert. Identify the popular commercial marketing product model name for this PC hardware.\nManufacturer: {$mfr}\nSystem Model/Board: {$model}\nCPU: {$processor}\n\nRespond with ONLY a JSON object: {\"popular_model\": \"string (e.g. ThinkPad T480, OptiPlex 3080, Latitude 5490, EliteBook 840 G5, etc.)\"}";
        $candidateModels = ['gemini-2.0-flash', 'gemini-1.5-flash'];
        foreach ($candidateModels as $cMod) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($cMod) . ':generateContent?key=' . urlencode($apiKey);
            $payload = [
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 150,
                ]
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $res) {
                $j = json_decode((string)$res, true);
                $aiTxt = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if (preg_match('/\{[\s\S]*\}/', $aiTxt, $mMatch)) {
                    $aiObj = json_decode($mMatch[0], true);
                    if (!empty($aiObj['popular_model'])) {
                        $popularModel = trim((string)$aiObj['popular_model']);
                        $method = 'gemini_ai (' . $cMod . ')';
                        break;
                    }
                }
            }
        }
    }

    // 2. Rule-based resolver / dictionary if AI did not return a result
    if ($popularModel === '') {
        $uModel = strtoupper($model);
        // Lenovo Machine Types
        $lenovoPatterns = [
            '/20L7|20L8/' => 'ThinkPad T480',
            '/20NX|20NY/' => 'ThinkPad T490',
            '/20UD|20UE/' => 'ThinkPad T14 Gen 1 (AMD)',
            '/20S0|20S1/' => 'ThinkPad T14 Gen 1 (Intel)',
            '/20W0|20W1/' => 'ThinkPad T14 Gen 2 (Intel)',
            '/20XK|20XL/' => 'ThinkPad T14 Gen 2 (AMD)',
            '/20QD|20QE/' => 'ThinkPad X1 Carbon 7th',
            '/20U9|20UA/' => 'ThinkPad X1 Carbon 8th',
            '/20XW|20XX/' => 'ThinkPad X1 Carbon 9th',
            '/20KF|20KE/' => 'ThinkPad X280',
            '/20K6|20K5/' => 'ThinkPad X270',
            '/20L5|20L6/' => 'ThinkPad T580',
            '/20N2|20N3/' => 'ThinkPad T490s',
            '/20N4|20N5/' => 'ThinkPad T590',
            '/20Q0|20Q1/' => 'ThinkPad X390',
            '/20NS/' => 'ThinkPad L390',
            '/80E4/' => 'Lenovo G40-80',
            '/80XU/' => 'IdeaPad 320',
            '/F0D4/' => 'IdeaCentre AIO 520',
            '/10AA|10AB/' => 'ThinkCentre M73',
            '/10MA/' => 'ThinkCentre M710s',
            '/10FM|10FL/' => 'ThinkCentre M900',
            '/10ST|10SU/' => 'ThinkCentre M720q Tiny',
            '/11DT|11DU/' => 'ThinkCentre M70q Tiny',
        ];
        foreach ($lenovoPatterns as $pattern => $name) {
            if (preg_match($pattern, $uModel)) {
                $popularModel = $name;
                $method = 'smart_regex';
                break;
            }
        }
        // If model already contains well-known series name:
        if ($popularModel === '') {
            $wellKnown = ['THINKPAD', 'IDEAPAD', 'THINKCENTRE', 'OPTIPLEX', 'LATITUDE', 'VOSTRO', 'PRECISION', 'ELITEBOOK', 'PROBOOK', 'PRODESK', 'ELITEDESK', 'PAVILION', 'ASPIRE', 'VIVOBOOK', 'ZENBOOK', 'MACBOOK'];
            foreach ($wellKnown as $wk) {
                if (stripos($model, $wk) !== false) {
                    $popularModel = $model;
                    $method = 'direct';
                    break;
                }
            }
        }
        if ($popularModel === '') {
            $popularModel = $model ?: 'Standard PC';
        }
    }

    // Short CPU description for item name
    $shortCpu = '';
    if ($processor !== '') {
        if (preg_match('/(Core\s+i[3579]-[\w]+)/i', $processor, $cm)) {
            $shortCpu = $cm[1];
        } elseif (preg_match('/(Ryzen\s+[3579]\s+[\w]+)/i', $processor, $rm)) {
            $shortCpu = $rm[1];
        } elseif (preg_match('/(i[3579]-[\w]+)/i', $processor, $cm2)) {
            $shortCpu = 'Core ' . $cm2[1];
        }
    }

    // Suggested Item Name: e.g. "Lenovo ThinkPad T480 Core i5-8250U 8GB"
    $suggestedNameParts = array_filter([
        $brandName ?: $cleanMfr,
        $popularModel,
        $shortCpu,
    ]);
    $suggestedItemName = implode(' ', $suggestedNameParts);

    api_json([
        'ok' => true,
        'brand_id' => $brandId,
        'brand_name' => $brandName,
        'popular_model' => $popularModel,
        'raw_model' => $model,
        'method' => $method,
        'suggested_item_name' => $suggestedItemName,
    ]);
}




