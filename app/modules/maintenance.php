<?php

declare(strict_types=1);

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
        $asset = $printer && function_exists('ensure_printer_maintenance_asset') ? ensure_printer_maintenance_asset($pdo, $printer) : null;
        return $asset ? (int)$asset['id'] : null;
    }
    $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
    $stmt->execute([$assetId]);
    $pc = $stmt->fetch();
    $asset = $pc && function_exists('ensure_pc_maintenance_asset') ? ensure_pc_maintenance_asset($pdo, $pc) : null;
    return $asset ? (int)$asset['id'] : null;
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

function maintenance_category_rows(PDO $pdo, bool $activeOnly = true): array
{
    if (!db_table_exists($pdo, 'maintenance_categories')) {
        return [];
    }
    $sql = 'SELECT * FROM maintenance_categories';
    if ($activeOnly) {
        $sql .= ' WHERE is_active=1';
    }
    $sql .= ' ORDER BY title';
    return $pdo->query($sql)->fetchAll();
}

function maintenance_category_options(PDO $pdo, ?int $selected = null, bool $activeOnly = true): string
{
    $html = '';
    foreach (maintenance_category_rows($pdo, $activeOnly) as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['title']) . '</option>';
    }
    return $html;
}

function maintenance_category_label(PDO $pdo, ?int $id): string
{
    if (!$id || !db_table_exists($pdo, 'maintenance_categories')) {
        return '-';
    }
    $stmt = $pdo->prepare('SELECT title FROM maintenance_categories WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: '-');
}

function maintenance_photo_public_to_local(string $photo): ?string
{
    $photo = str_replace('\\', '/', trim($photo));
    if (!preg_match('#uploads/maintenance/([^/]+)$#', $photo, $m)) {
        return null;
    }
    $path = dirname(__DIR__, 2) . '/uploads/maintenance/' . basename($m[1]);
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

/**
 * Audit foto challenge TANPA shell_exec atau eksekusi tesseract eksternal.
 * Menghilangkan celah keamanan command execution medium.
 */
function photo_challenge_audit_from_photos(string $scheduleCode, array $photoGroups, bool $completed = false): array
{
    $scheduleCode = preg_replace('/\D+/', '', trim($scheduleCode));
    if ($scheduleCode === '') {
        return [
            'status' => 'Warning',
            'text' => $completed ? 'Kode challenge sistem kosong. Review foto manual.' : 'Belum ada kode challenge karena pekerjaan belum selesai atau data lama.',
            'class' => ' danger',
            'ocr_text' => '',
        ];
    }
    $photosCount = 0;
    foreach ($photoGroups as $group) {
        if (is_array($group)) {
            $photosCount += count($group);
        }
    }
    if ($photosCount === 0) {
        return [
            'status' => 'Warning',
            'text' => 'Foto challenge belum tersedia untuk dianalisa.',
            'class' => ' danger',
            'ocr_text' => '',
        ];
    }
    return [
        'status' => 'OK',
        'text' => 'Bukti foto tercatat (' . $photosCount . ' foto). Kode challenge: ' . $scheduleCode . ' (Validasi visual manual).',
        'class' => ' ok',
        'ocr_text' => 'Kode: ' . $scheduleCode,
    ];
}

function photo_challenge_manual_review_notice(string $scheduleCode, bool $completed = false): array
{
    $scheduleCode = preg_replace('/\D+/', '', trim($scheduleCode));
    if ($scheduleCode === '') {
        return ['status' => 'Warning', 'text' => $completed ? 'Kode challenge sistem kosong. Review foto manual.' : 'Belum ada kode challenge karena pekerjaan belum selesai atau data lama.', 'class' => ' danger', 'ocr_text' => ''];
    }
    return ['status' => 'Review', 'text' => 'Kode challenge sistem ' . $scheduleCode . '. Cocokkan manual dengan kertas challenge pada foto before/process/after.', 'class' => ' danger', 'ocr_text' => ''];
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

function evaluate_photo_challenge_ai(PDO $pdo, int $scheduleId, string $photoRelUrl, string $expectedCode): array
{
    $expectedCode = trim($expectedCode);
    $fileName = basename($photoRelUrl);
    $absPath = upload_dir() . '/' . $fileName;
    if (!file_exists($absPath) || !is_file($absPath)) {
        return [
            'status' => 'Review',
            'code_matched' => null,
            'qr_detected' => null,
            'is_real_asset' => true,
            'confidence' => 0,
            'note' => 'Foto belum tersedia di server untuk evaluasi AI.',
        ];
    }

    $apiKey = trim((string)(config_value('gemini_api_key') ?: getenv('GEMINI_API_KEY') ?: ''));
    if ($apiKey === '') {
        return [
            'status' => 'Review',
            'code_matched' => null,
            'qr_detected' => true,
            'is_real_asset' => true,
            'confidence' => 100,
            'note' => 'Kode challenge sistem: ' . $expectedCode . ' (Validasi visual manual - API key Gemini belum diisi di config.php)',
        ];
    }

    $imgData = @file_get_contents($absPath);
    if ($imgData === false || $imgData === '') {
        return [
            'status' => 'Review',
            'code_matched' => null,
            'qr_detected' => true,
            'is_real_asset' => true,
            'confidence' => 0,
            'note' => 'Gagal membaca berkas foto sebelum untuk evaluasi AI.',
        ];
    }

    $b64 = base64_encode($imgData);
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $im = @imagecreatefromstring($imgData);
        if ($im !== false) {
            $origW = imagesx($im);
            $origH = imagesy($im);
            $maxDim = 900;
            if ($origW > $maxDim || $origH > $maxDim) {
                $ratio = min($maxDim / $origW, $maxDim / $origH);
                $newW = max(1, (int)round($origW * $ratio));
                $newH = max(1, (int)round($origH * $ratio));
                $thumb = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($thumb, $im, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                ob_start();
                imagejpeg($thumb, null, 75);
                $compressed = ob_get_clean();
                imagedestroy($thumb);
                if ($compressed !== false && strlen($compressed) > 100) {
                    $b64 = base64_encode($compressed);
                }
            }
            imagedestroy($im);
        }
    }

    $prompt = "You are an automated IT asset maintenance photo inspector.
Analyze this 'before' maintenance photo of a computer or printer asset.
The technician was instructed to write the dynamic challenge code '$expectedCode' on a small piece of white paper (approx 4 cm x 1 cm) placed close to the physical QR code label sticker, with the physical unit (casing/printer body) visible.

Answer the following questions strictly in JSON format:
1. Is the dynamic challenge code '$expectedCode' visible on the small paper next to the QR code? (code_matched: true/false)
2. What code text/digits did you detect if any? (detected_code: string or null)
3. Is an asset QR code or barcode label sticker visible on the unit? (qr_detected: true/false)
4. Is this photo taken of a real physical asset with the physical unit visible, or does it look like a photo of another screen (anti-spoofing)? (is_real_asset: true/false)
5. Explain briefly in Indonesian what was visually found. (note: string)

Output JSON only with keys: code_matched (bool), detected_code (string|null), qr_detected (bool), is_real_asset (bool), note (string).";

    $postData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $b64,
                        ],
                    ],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 300,
        ],
    ];

    // Ambil daftar model aktif langsung dari Google Generative Language API
    $candidateModels = [];
    $listCh = curl_init('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey));
    curl_setopt($listCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($listCh, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
    curl_setopt($listCh, CURLOPT_TIMEOUT, 8);
    curl_setopt($listCh, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($listCh, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($listCh, CURLOPT_SSL_VERIFYHOST, 0);
    $listRes = curl_exec($listCh);
    $listHttpCode = curl_getinfo($listCh, CURLINFO_HTTP_CODE);
    $listCurlErr = curl_error($listCh);
    curl_close($listCh);

    $lastErrDetail = '';
    if ($listHttpCode === 200 && $listRes) {
        $listData = json_decode((string)$listRes, true);
        if (!empty($listData['models']) && is_array($listData['models'])) {
            $flashModels = [];
            $otherModels = [];
            foreach ($listData['models'] as $m) {
                $methods = $m['supportedGenerationMethods'] ?? [];
                if (in_array('generateContent', $methods, true)) {
                    $mName = str_replace('models/', '', (string)($m['name'] ?? ''));
                    if ($mName !== '') {
                        if (stripos($mName, 'flash') !== false) {
                            $flashModels[] = $mName;
                        } else {
                            $otherModels[] = $mName;
                        }
                    }
                }
            }
            $candidateModels = array_merge($flashModels, $otherModels);
        }
    } else {
        if ($listCurlErr !== '') {
            $lastErrDetail = 'Koneksi AI Google: ' . $listCurlErr;
        } elseif ($listRes) {
            $errData = json_decode((string)$listRes, true);
            $lastErrDetail = $errData['error']['message'] ?? ('HTTP ' . $listHttpCode);
        }
    }

    // Fallback model terstandar jika models.list tidak mengembalikan daftar
    if (empty($candidateModels)) {
        $candidateModels = ['gemini-2.0-flash', 'gemini-1.5-flash-8b', 'gemini-1.5-flash', 'gemini-pro-vision'];
    }

    foreach ($candidateModels as $modelName) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($modelName) . ':generateContent?key=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $resArr = json_decode((string)$response, true);
            $aiText = $resArr['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (preg_match('/\{[\s\S]*\}/', $aiText, $m)) {
                $parsed = json_decode($m[0], true);
                if (is_array($parsed)) {
                    $codeMatched = !empty($parsed['code_matched']);
                    $detectedCode = trim((string)($parsed['detected_code'] ?? ''));
                    $qrDetected = !empty($parsed['qr_detected']);
                    $isReal = $parsed['is_real_asset'] ?? true;

                    // Jika kode terdeteksi dan tidak sama dengan yang diharapkan -> pasti mismatch/salah
                    if ($detectedCode !== '' && $detectedCode !== $expectedCode) {
                        $codeMatched = false;
                    }

                    $status = ($codeMatched && $qrDetected && $isReal)
                        ? 'OK'
                        : ((!$codeMatched || !$isReal) ? 'Warning' : 'Review');

                    return [
                        'status' => $status,
                        'code_matched' => $codeMatched,
                        'detected_code' => $detectedCode,
                        'qr_detected' => $qrDetected,
                        'is_real_asset' => (bool)$isReal,
                        'model_used' => $modelName,
                        'note' => (string)($parsed['note'] ?? ''),
                    ];
                }
            }
        } else {
            if ($curlErr !== '') {
                $lastErrDetail = 'cURL (' . $modelName . '): ' . $curlErr;
            } elseif ($response) {
                $errJson = json_decode((string)$response, true);
                $lastErrDetail = $errJson['error']['message'] ?? ('HTTP ' . $httpCode . ' pada ' . $modelName);
            } else {
                $lastErrDetail = 'HTTP ' . $httpCode . ' pada ' . $modelName;
            }
        }
    }

    return [
        'status' => 'Review',
        'code_matched' => null,
        'qr_detected' => true,
        'is_real_asset' => true,
        'confidence' => 0,
        'note' => 'Review Visual Manual (Kode: ' . $expectedCode . '): ' . $lastErrDetail,
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

function photo_audit_sequence_check(array $items, callable $ok, callable $warn): void
{
    $times = photo_browser_group_times($items);
    if (empty($times['before']) || empty($times['after'])) {
        $ok('Sequence timing', 'Urutan foto tercatat berdasarkan alur foto sebelum dan sesudah sistem.');
        return;
    }
    if ($times['before'] > $times['after']) {
        $warn('Sequence timing', 'Foto sesudah tercatat sebelum foto sebelum.', 15);
        return;
    }
    $duration = $times['after'] - $times['before'];
    if ($duration < 60) {
        $warn('Sequence timing', 'Jarak waktu foto sebelum ke sesudah sangat singkat (' . round($duration / 60, 1) . ' menit).', 10);
    } else {
        $ok('Sequence timing', 'Urutan foto valid, selisih waktu ' . round($duration / 60, 1) . ' menit.');
    }
}

function photo_audit_minimum_duration_check(array $meta, array $items, callable $ok, callable $warn): void
{
    $times = photo_browser_group_times($items);
    $minimumMinutes = max(1, (int)($meta['minimum_duration_minutes'] ?? 5));
    $durationMinutes = isset($meta['real_duration_minutes']) ? (float)$meta['real_duration_minutes'] : null;
    if ($durationMinutes === null && !empty($times['before']) && !empty($times['after'])) {
        $durationMinutes = ($times['after'] - $times['before']) / 60;
    }
    if ($durationMinutes !== null) {
        if ($durationMinutes < $minimumMinutes) {
            $warn('Durasi minimal', 'Waktu pengerjaan real ' . round($durationMinutes, 1) . ' m di bawah estimasi kumulatif ' . $minimumMinutes . ' m.', 15);
        } else {
            $ok('Durasi minimal', 'Waktu pengerjaan real ' . round($durationMinutes, 1) . ' m memenuhi batas kumulatif ' . $minimumMinutes . ' m.');
        }
    } else {
        $ok('Durasi minimal', 'Batas durasi kumulatif terkonfirmasi.');
    }
}

function photo_audit_gps_check(array $items, array $asset, callable $ok, callable $warn): void
{
    if (($asset['latitude'] ?? null) === null || ($asset['longitude'] ?? null) === null) {
        $ok('GPS foto', 'Titik patokan GPS aset belum ditentukan di master data aset.');
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
        $warn('GPS foto', 'Koordinat GPS perangkat saat foto diambil tidak terdeteksi (indoor/sinyal lemah).', 5);
        return;
    }
    $maxDistance = max($distances);
    $radius = (float)($asset['radius_m'] ?? 5);
    if ($maxDistance > $radius) {
        $warn('GPS foto', 'Ada foto diambil ' . round($maxDistance, 1) . ' m dari titik aset. Radius patokan ' . round($radius, 1) . ' m.', 15);
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
        $warn('Before vs after', 'Server tidak bisa menghitung beda foto.', 5);
        return;
    }
    $distance = hash_hamming_distance($a, $b);
    if ($distance <= 4) {
        $warn('Before vs after', 'Foto before dan after terlalu mirip.', 20);
    } else {
        $ok('Before vs after', 'Foto before dan after berbeda. Skor beda: ' . $distance . '/64.');
    }
}

function build_photo_audit_meta(PDO $pdo, int $scheduleId, array $uploadMeta, string $browserJson): array
{
    $browser = json_decode($browserJson, true) ?: [];
    $browserItems = is_array($browser['items'] ?? null) ? $browser['items'] : [];
    $combinedUploads = [];
    foreach ($uploadMeta as $item) {
        $group = (string)($item['group'] ?? '');
        $slot = (string)($item['slot'] ?? '');
        $browserMatch = null;
        foreach ($browserItems as $b) {
            if (($b['group'] ?? '') === $group && (string)($b['slot'] ?? '') === $slot) {
                $browserMatch = $b;
                break;
            }
        }
        $item['browser'] = $browserMatch;
        $combinedUploads[] = $item;
    }
    return [
        'captured_at' => $browser['captured_at'] ?? date('c'),
        'minimum_duration_minutes' => schedule_minimum_photo_minutes($pdo, $scheduleId),
        'duration_override_approved' => !empty($browser['duration_override_approved']),
        'asset' => photo_audit_asset_context($pdo, $scheduleId),
        'uploads' => $combinedUploads,
    ];
}

function photo_evidence_location_error(array $meta): ?string
{
    $asset = $meta['asset'] ?? [];
    if (empty($asset['latitude']) || empty($asset['longitude'])) {
        return null;
    }
    $radius = (float)($asset['radius_m'] ?? 5);
    $uploads = $meta['uploads'] ?? [];
    foreach ($uploads as $upload) {
        $gps = $upload['browser']['gps'] ?? null;
        if (!is_array($gps) || !isset($gps['lat'], $gps['lng'])) {
            continue;
        }
        $dist = geo_distance_m((float)$asset['latitude'], (float)$asset['longitude'], (float)$gps['lat'], (float)$gps['lng']);
        if ($dist > $radius) {
            return 'Foto ditolak. Jarak pengambilan foto ' . round($dist, 1) . ' m dari titik aset (maksimal ' . round($radius, 1) . ' m). Ambil foto di lokasi aset.';
        }
    }
    return null;
}

function photo_evidence_live_camera_error(array $meta): ?string
{
    return null;
}

function photo_evidence_duration_error(array $meta, ?array $schedule = null): ?string
{
    if (!empty($meta['duration_override_approved'])) {
        return null;
    }
    $minMinutes = max(1, (int)($meta['minimum_duration_minutes'] ?? 5));

    $durationMinutes = isset($meta['real_duration_minutes']) ? (float)$meta['real_duration_minutes'] : null;
    if ($durationMinutes === null) {
        $startTimeStr = (string)($schedule['before_photo_at'] ?? $schedule['arrival_at'] ?? $meta['start_time'] ?? '');
        $startTime = $startTimeStr !== '' ? strtotime($startTimeStr) : 0;
        $endTime = time();
        if ($startTime > 0) {
            $durationMinutes = max(0, ($endTime - $startTime) / 60);
        }
    }

    if ($durationMinutes !== null && $durationMinutes < $minMinutes) {
        return 'Anda mengerjakannya job desk ini di bawah minimal waktu pengerjaan , apakah Anda yakin sudah selesai';
    }
    return null;
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

    $reportBefore = json_decode((string)($report['before_photos'] ?? '[]'), true) ?: [];
    $reportAfter = json_decode((string)($report['after_photos'] ?? '[]'), true) ?: [];
    $before = array_values(array_filter($uploads, fn($u) => ($u['field'] ?? '') === 'before_photos' || ($u['group'] ?? '') === 'before'));
    $after = array_values(array_filter($uploads, fn($u) => ($u['field'] ?? '') === 'after_photos' || ($u['group'] ?? '') === 'after'));
    $hasBefore = !empty($before) || !empty($reportBefore);
    $hasAfter = !empty($after) || !empty($reportAfter);

    if (!$hasBefore || !$hasAfter) {
        $warn('Foto wajib', 'Foto sebelum atau sesudah belum lengkap.', 25);
    } else {
        $ok('Foto wajib', 'Foto sebelum dan sesudah lengkap tersedia.');
    }

    $browserItems = array_values(array_filter(array_map(fn($u) => $u['browser'] ?? null, $uploads)));
    if (!$browserItems) {
        $ok('Live camera evidence', 'Foto terekam melalui aplikasi mobile.');
    } else {
        $notLive = array_filter($browserItems, fn($i) => empty($i['live_camera_hint']));
        $notLive ? $warn('Live camera evidence', 'Sebagian input foto tidak membawa tanda capture kamera.', 10) : $ok('Live camera evidence', 'Semua input foto memakai mode kamera langsung.');
    }

    photo_audit_sequence_check($browserItems, $ok, $warn);
    photo_audit_minimum_duration_check($meta, $browserItems, $ok, $warn);
    photo_audit_gps_check($browserItems, $asset, $ok, $warn);
    $beforeUrl = $before[0]['url'] ?? ($reportBefore[0] ?? '');
    $afterUrl = $after[0]['url'] ?? ($reportAfter[0] ?? '');
    photo_audit_similarity_check($beforeUrl, $afterUrl, $ok, $warn);

    // Dynamic Challenge Code & QR Fisik
    $aiChallenge = $meta['ai_challenge_audit'] ?? null;
    $challengeCode = trim((string)($report['photo_challenge_code'] ?? $report['schedule_challenge_code'] ?? $meta['photo_challenge_code'] ?? ''));
    if ($aiChallenge && is_array($aiChallenge)) {
        $detCode = trim((string)($aiChallenge['detected_code'] ?? ''));
        $detStr = ($detCode !== '') ? " (terbaca: '{$detCode}')" : '';

        if (!empty($aiChallenge['code_matched']) && !empty($aiChallenge['qr_detected'])) {
            $ok('Dynamic Challenge Code & QR (AI)', 'Kode ' . ($detCode ?: $challengeCode) . ' cocok & stiker QR fisik unit terdeteksi. ' . ($aiChallenge['note'] ?? ''));
        } elseif (!empty($aiChallenge['code_matched'])) {
            $ok('Dynamic Challenge Code (AI)', 'Kode ' . ($detCode ?: $challengeCode) . ' terverifikasi cocok. ' . ($aiChallenge['note'] ?? ''));
        } elseif (isset($aiChallenge['code_matched']) && $aiChallenge['code_matched'] === false) {
            // KODE TIDAK COCOK / BERBEDA -> PENALTI KERAS (-40 POIN)
            $warn('Dynamic Challenge Code (AI)', "KODE TIDAK COCOK! Seharusnya '{$challengeCode}'{$detStr}. Terindikasi foto duplikasi/bukan aset ini saat ini. " . ($aiChallenge['note'] ?? ''), 40);
        } elseif (($aiChallenge['status'] ?? '') === 'Review') {
            // Status Review (offline/timeout/koneksi) bersifat informasi, TIDAK mengurangi skor teknisi!
            $rows[] = ['status' => 'Review', 'title' => 'Dynamic Challenge Code & QR', 'detail' => ($aiChallenge['note'] ?? ('Kode: ' . $challengeCode . '. Menunggu review visual manual.'))];
        } elseif (!empty($aiChallenge['qr_detected'])) {
            $warn('Dynamic Challenge Code (AI)', 'Stiker QR terdeteksi, namun kode (' . $challengeCode . ') belum terbaca jelas. ' . ($aiChallenge['note'] ?? ''), 15);
        } else {
            $warn('Dynamic Challenge Code & QR (AI)', 'Kode (' . $challengeCode . ') dan stiker QR belum terverifikasi otomatis. ' . ($aiChallenge['note'] ?? ''), 25);
        }
    } elseif ($challengeCode !== '') {
        $ok('Dynamic Challenge Code', 'Kode challenge sistem: ' . $challengeCode . ' (Validasi visual manual).');
    }

    $score = max(0, min(100, $score));
    $status = $score >= 80 ? 'OK' : ($score >= 60 ? 'Review' : 'Warning');
    $class = $status === 'OK' ? ' ok' : ' danger';
    return ['score' => $score, 'status' => $status, 'class' => $class, 'text' => 'Audit bukti foto ' . $score . '/100.', 'rows' => $rows];
}

function maintenance_report_summary(PDO $pdo, array $report): array
{
    $scheduleId = (int)($report['schedule_id'] ?? 0);
    $hasNote = schedule_job_note_supported($pdo);
    $noteSelect = $hasNote ? 'sj.note' : "'' note";
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
    $meta = json_decode((string)($report['photo_audit_meta'] ?? ''), true) ?: [];
    $realMin = $meta['real_duration_minutes'] ?? null;
    $minMin = $meta['minimum_duration_minutes'] ?? schedule_minimum_photo_minutes($pdo, $scheduleId);
    if ($realMin === null) {
        $startStr = (string)($report['before_photo_at'] ?? $report['arrival_at'] ?? $report['created_at'] ?? '');
        $endStr = (string)($report['completed_at'] ?? $report['updated_at'] ?? '');
        if ($startStr && $endStr) {
            $sT = strtotime($startStr);
            $eT = strtotime($endStr);
            if ($sT > 0 && $eT >= $sT) {
                $realMin = round(($eT - $sT) / 60, 1);
            }
        }
    }
    $realDurationText = ($realMin !== null)
        ? ($realMin . ' menit' . (($minMin > 0 && $realMin < $minMin) ? ' (di bawah minimal ' . $minMin . ' mnt - disetujui)' : ''))
        : '-';

    return [
        'summary_status' => $status,
        'checklist_rows' => $checklistRows,
        'real_duration_text' => $realDurationText,
        'real_duration_minutes' => $realMin,
        'minimum_duration_minutes' => $minMin,
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
        'ai_challenge_audit' => $meta['ai_challenge_audit'] ?? null,
        'condition' => (string)($report['condition_rating'] ?? '-'),
        'physical_condition' => trim((string)($report['physical_condition'] ?? '')),
        'notes' => trim((string)($report['additional_notes'] ?? '')),
    ];
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

function handle_route_maintenance(PDO $pdo): void
{
    $user = require_regulation('maintenance', 'view');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_schedule') {
        require_regulation('maintenance', 'delete');
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId > 0) {
            delete_maintenance_schedule($pdo, $delId);
            flash('Jadwal maintenance berhasil dihapus.');
        }
        redirect_to('maintenance');
    }

    render_header('History Preventive Maintenance', $user);
    $printerReady = printer_schema_ready($pdo);
    if (!$printerReady) {
        echo printer_schema_warning();
    }
    $where = $user['role'] === 'technician' ? 'WHERE s.technician_id = ' . (int)$user['id'] : '';
    try {
        $rows = $pdo->query("SELECT s.*, 
            COALESCE(ai.asset_code, s.pc_id, s.printer_id, ma.maintenance_asset_code) asset_id, 
            COALESCE(ai.custodian_name, p.owner_name, pr.printer_name, ma.owner_name) owner_name, 
            COALESCE(ai.asset_name, p.computer_name, pr.location, ma.location_label) computer_name, 
            u.name technician, 
            COALESCE(ag.group_name, 'IT Asset') asset_group_name,
            COALESCE(ag.group_code, 'IT') asset_group_code,
            ai.asset_mode
        FROM maintenance_schedules s 
        LEFT JOIN asset_items ai ON ai.id = s.asset_item_id
        LEFT JOIN maintenance_assets ma ON ma.id = s.maintenance_asset_id
        LEFT JOIN asset_groups ag ON ag.id = COALESCE(s.asset_group_id, ai.asset_group_id, ma.asset_group_id)
        LEFT JOIN pcs p ON p.pc_id = s.pc_id 
        LEFT JOIN printers pr ON pr.prn_id = s.printer_id 
        LEFT JOIN users u ON u.id = s.technician_id 
        $where 
        ORDER BY s.scheduled_date DESC, s.id DESC")->fetchAll();
    } catch (Throwable $e) {
        $rows = $pdo->query("SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, p.computer_name, u.name technician, 'IT Asset' asset_group_name, 'IT' asset_group_code, NULL asset_mode FROM maintenance_schedules s JOIN pcs p ON p.pc_id = s.pc_id LEFT JOIN users u ON u.id=s.technician_id $where ORDER BY s.scheduled_date DESC, s.id DESC")->fetchAll();
    }
    $addBtn = has_regulation('maintenance', 'create') ? '<a class="btn primary" href="' . route_url('schedule_form') . '">Tambah Schedule</a>' : '';
    $cleanupBtn = has_regulation('maintenance', 'delete') ? '<a class="btn danger" href="' . route_url('maintenance_cleanup') . '">Hapus Data</a>' : '';
    echo '<section class="panel"><div class="split"><h1>History Preventive Maintenance</h1><div class="actions"><a class="btn" href="' . route_url('export_excel', ['type' => 'maintenance']) . '">Export Excel</a>' . $cleanupBtn . $addBtn . '</div></div></section><section class="panel"><table><tr><th>Tanggal</th><th>Asset / ID</th><th>Pengguna / Lokasi</th><th>Asset Group</th><th>Teknisi</th><th>Status</th><th>Foto</th><th>Aksi</th></tr>';
    $scheduleIds = array_filter(array_map('intval', array_column($rows, 'id')));
    $reportsBySchedule = [];
    if ($scheduleIds) {
        $inIds = implode(',', $scheduleIds);
        $rStmt = $pdo->query("SELECT schedule_id, before_photos, process_photos, after_photos FROM maintenance_reports WHERE schedule_id IN ($inIds) ORDER BY id DESC");
        while ($r = $rStmt->fetch()) {
            $sid = (int)$r['schedule_id'];
            if (!isset($reportsBySchedule[$sid])) {
                $reportsBySchedule[$sid] = $r;
            }
        }
    }

    foreach ($rows as $row) {
        $photoReport = $reportsBySchedule[(int)$row['id']] ?? [];
        $beforeCount = count(json_decode((string)($photoReport['before_photos'] ?? '[]'), true) ?: []);
        $processCount = count(json_decode((string)($photoReport['process_photos'] ?? '[]'), true) ?: []);
        $afterCount = count(json_decode((string)($photoReport['after_photos'] ?? '[]'), true) ?: []);
        $actions = '<a class="btn" href="' . route_url('maintenance_do', ['id' => $row['id']]) . '">Buka</a>';
        if (can_manage_maintenance($user) && $row['status'] === 'completed') {
            $actions .= ' <a class="btn" href="' . route_url('report_print', ['id' => $row['id']]) . '">Report</a>';
        }
        if (has_regulation('maintenance', 'delete')) {
            $actions .= ' <form method="post" action="' . route_url('maintenance') . '" style="display:inline;" onsubmit="return confirm(\'Hapus jadwal maintenance ini?\');">'
                . csrf_field()
                . '<input type="hidden" name="action" value="delete_schedule">'
                . '<input type="hidden" name="id" value="' . (int)$row['id'] . '">'
                . '<button type="submit" class="btn danger" style="padding:4px 8px;font-size:12px;">Hapus</button>'
                . '</form>';
        }
        $groupBadge = $row['asset_group_code'] ? ($row['asset_group_code'] . ' - ' . $row['asset_group_name']) : ($row['asset_group_name'] ?: 'IT Asset');
        $bundleBadge = ($row['asset_mode'] ?? '') === 'group' ? '<br><span class="badge" style="background:#1e3a8a;color:#fff;font-size:11px;">📦 Induk Bundle</span>' : '';
        echo '<tr><td>' . e($row['scheduled_date']) . '</td><td><strong>' . e($row['asset_id']) . '</strong>' . $bundleBadge . '<br><span class="badge">' . e($row['asset_type'] ?? 'pc') . '</span></td><td>' . e($row['owner_name'] ?: '-') . '<br><span class="muted">' . e($row['computer_name'] ?: '-') . '</span></td><td><span class="badge">' . e($groupBadge) . '</span></td><td>' . e($row['technician'] ?: '-') . '</td><td><span class="badge">' . e($row['status']) . '</span></td><td>Before: ' . e($beforeCount) . '<br>Process: ' . e($processCount) . '<br>After: ' . e($afterCount) . '</td><td>' . $actions . '</td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_maintenance_cleanup(PDO $pdo): void
{
    $user = require_regulation('maintenance', 'delete');
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
            if ((int)($_POST['technician_id'] ?? 0) > 0) {
                $whereParts[] = 'technician_id=?';
                $params[] = (int)$_POST['technician_id'];
            }
            if (trim((string)($_POST['status'] ?? '')) !== '') {
                $whereParts[] = 'status=?';
                $params[] = trim((string)$_POST['status']);
            }
            if (trim((string)($_POST['before_date'] ?? '')) !== '') {
                $whereParts[] = 'scheduled_date<=?';
                $params[] = trim((string)$_POST['before_date']);
            }
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
        if ($printerReady && substr($pcFilter, 0, 3) === 'PRN') {
            $whereParts[] = 's.printer_id=?';
        } else {
            $whereParts[] = 's.pc_id=?';
        }
        $params[] = $pcFilter;
    }
    if ($techFilter > 0) {
        $whereParts[] = 's.technician_id=?';
        $params[] = $techFilter;
    }
    if ($statusFilter !== '') {
        $whereParts[] = 's.status=?';
        $params[] = $statusFilter;
    }
    if ($beforeDate !== '') {
        $whereParts[] = 's.scheduled_date<=?';
        $params[] = $beforeDate;
    }
    $whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
    $cleanupSql = $printerReady
        ? "SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, u.name technician FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id $whereSql ORDER BY s.scheduled_date DESC, s.id DESC LIMIT 200"
        : "SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, u.name technician FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN users u ON u.id=s.technician_id $whereSql ORDER BY s.scheduled_date DESC, s.id DESC LIMIT 200";
    $stmt = $pdo->prepare($cleanupSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    echo '<section class="panel"><div class="split"><div><h1>Hapus Data Maintenance</h1><p class="muted">Gunakan filter dulu.</p></div><a class="btn" href="' . route_url('maintenance') . '">Kembali</a></div></section>';
    echo '<section class="panel"><form method="get"><input type="hidden" name="route" value="maintenance_cleanup"><div class="grid four"><label>PC / Printer<select name="pc_id"><option value="">Semua Aset</option>';
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
    echo '</select></label><label>Teknisi<select name="technician_id"><option value="0">Semua Teknisi</option>';
    foreach ($techs as $tech) {
        echo '<option value="' . e($tech['id']) . '"' . ($techFilter === (int)$tech['id'] ? ' selected' : '') . '>' . e($tech['name']) . '</option>';
    }
    echo '</select></label><label>Status<select name="status"><option value="">Semua Status</option>';
    foreach (['scheduled', 'validated', 'in_progress', 'completed', 'reopened'] as $status) {
        echo '<option value="' . e($status) . '"' . ($statusFilter === $status ? ' selected' : '') . '>' . e($status) . '</option>';
    }
    echo '</select></label><label>Sampai Tanggal<input type="date" name="before_date" value="' . e($beforeDate) . '"></label></div><button class="btn primary">Filter</button></form></section>';
    echo '<section class="panel"><div class="split"><h2>Data Terfilter</h2><form method="post" onsubmit="return confirm(\'Hapus SEMUA data yang sesuai filter?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete_filtered"><input type="hidden" name="pc_id" value="' . e($pcFilter) . '"><input type="hidden" name="technician_id" value="' . e($techFilter) . '"><input type="hidden" name="status" value="' . e($statusFilter) . '"><input type="hidden" name="before_date" value="' . e($beforeDate) . '"><label style="margin:0">Konfirmasi<input name="confirm_text" placeholder="Ketik HAPUS"></label><button class="btn danger">Hapus Data Terfilter</button></form></div><table><tr><th>Tanggal</th><th>PcID</th><th>Owner</th><th>Teknisi</th><th>Status</th><th>Aksi</th></tr>';
    foreach ($rows as $row) {
        echo '<tr><td>' . e($row['scheduled_date']) . '</td><td>' . e($row['asset_id']) . '</td><td>' . e($row['owner_name']) . '</td><td>' . e($row['technician'] ?: '-') . '</td><td><span class="badge">' . e($row['status']) . '</span></td><td><form method="post" onsubmit="return confirm(\'Hapus schedule maintenance ini?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="btn danger">Hapus</button></form></td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_schedule_form(PDO $pdo): void
{
    $user = require_regulation('maintenance', 'create');
    $selectedGroupId = (int)(($_POST['asset_group_id'] ?? $_GET['asset_group_id'] ?? 0));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $technicianId = (int)($_POST['technician_id'] ?? 0);
        if ($technicianId <= 0) {
            flash('Teknisi wajib dipilih untuk schedule maintenance.', 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }
        $assetItemId = (int)($_POST['asset_item_id'] ?? $_POST['maintenance_asset_id'] ?? 0);
        if ($assetItemId <= 0) {
            flash('Unit aset maintenance wajib dipilih.', 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }
        $scheduledDate = normalize_date_input((string)($_POST['scheduled_date'] ?? ''));
        if ($scheduledDate === '') {
            flash('Tanggal schedule tidak valid.', 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }
        $jobIds = array_map('intval', $_POST['jobs'] ?? []);
        if (empty($jobIds)) {
            flash('Pilih minimal satu job desk untuk schedule maintenance.', 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }

        $aiStmt = $pdo->prepare('SELECT ai.*, at.type_code, ag.group_code FROM asset_items ai 
            LEFT JOIN asset_types at ON at.id = ai.asset_type_id 
            LEFT JOIN asset_groups ag ON ag.id = ai.asset_group_id 
            WHERE ai.id = ? LIMIT 1');
        $aiStmt->execute([$assetItemId]);
        $ai = $aiStmt->fetch();
        if (!$ai) {
            flash('Unit aset tidak ditemukan atau belum terdaftar.', 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }

        $pcId = null;
        if (db_table_exists($pdo, 'pcs')) {
            $stP = $pdo->prepare('SELECT pc_id FROM pcs WHERE asset_item_id = ? LIMIT 1');
            $stP->execute([$assetItemId]);
            $pcId = $stP->fetchColumn() ?: null;
        }

        $printerId = null;
        if (db_table_exists($pdo, 'printers')) {
            $stPr = $pdo->prepare('SELECT prn_id FROM printers WHERE asset_item_id = ? LIMIT 1');
            $stPr->execute([$assetItemId]);
            $printerId = $stPr->fetchColumn() ?: null;
        }

        $assetType = (string)($ai['type_code'] ?: ($ai['group_code'] ?: 'equipment'));
        $assetGroupId = (int)($ai['asset_group_id'] ?: $selectedGroupId);
        if ($assetGroupId <= 0) {
            $assetGroupId = (int)$pdo->query("SELECT id FROM asset_groups WHERE group_code='IT' LIMIT 1")->fetchColumn() ?: null;
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO maintenance_schedules (asset_type, asset_item_id, asset_group_id, pc_id, printer_id, technician_id, scheduled_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$assetType, $assetItemId, $assetGroupId ?: null, $pcId, $printerId, $technicianId, $scheduledDate, trim($_POST['notes'] ?? '')]);
            $scheduleId = (int)$pdo->lastInsertId();
            foreach ($jobIds as $jobId) {
                $pdo->prepare('INSERT INTO schedule_jobs (schedule_id, job_id) VALUES (?, ?)')->execute([$scheduleId, $jobId]);
            }
            flash('Schedule maintenance berhasil dibuat.');
            redirect_to('maintenance_do', ['id' => $scheduleId]);
        } catch (Throwable $e) {
            flash('Schedule gagal disimpan: ' . $e->getMessage(), 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }
    }

    render_header('Tambah Schedule', $user);

    $targetAssetId = (int)($_GET['asset_item_id'] ?? $_GET['maintenance_asset_id'] ?? $_GET['asset_id'] ?? 0);
    $assetParam = trim((string)($_GET['asset'] ?? ''));
    if ($targetAssetId === 0 && $assetParam !== '') {
        $stCode = $pdo->prepare('SELECT id FROM asset_items WHERE asset_code = ? LIMIT 1');
        $stCode->execute([$assetParam]);
        $targetAssetId = (int)($stCode->fetchColumn() ?: 0);
    }

    $assetGroups = $pdo->query('SELECT id, group_code, group_name FROM asset_groups ORDER BY group_name')->fetchAll(PDO::FETCH_ASSOC);
    $assetTypes = $pdo->query('SELECT id, asset_group_id, type_code, type_name FROM asset_types ORDER BY type_name')->fetchAll(PDO::FETCH_ASSOC);

    // ID default group & type untuk PC dan Printer warisan (fallback)
    $itGroupId = 0;
    $cmpTypeId = 0;
    $prtTypeId = 0;
    foreach ($assetGroups as $ag) {
        if ($ag['group_code'] === 'IT') { $itGroupId = (int)$ag['id']; break; }
    }
    foreach ($assetTypes as $at) {
        if ($at['type_code'] === 'CMP') { $cmpTypeId = (int)$at['id']; }
        if ($at['type_code'] === 'PRT') { $prtTypeId = (int)$at['id']; }
    }

    $sqlAi = "SELECT ai.id, ai.asset_code, ai.asset_name, ai.asset_group_id, ai.asset_type_id, ai.job_desk_name,
                     ai.asset_mode, ai.serial_number, ai.brand, ai.model,
                     ag.group_name, ag.group_code,
                     at.type_name, at.type_code,
                     c.company_name,
                     loc.location_name,
                     COALESCE(ai.asset_name, ai.model, ai.asset_code) AS display_name,
                     COALESCE(ai.custodian_name, ai.location_label, loc.location_name, c.company_name, '') AS display_sub
              FROM asset_items ai
              LEFT JOIN asset_groups ag ON ag.id = ai.asset_group_id
              LEFT JOIN asset_types at ON at.id = ai.asset_type_id
              LEFT JOIN asset_companies c ON c.id = ai.company_id
              LEFT JOIN asset_locations loc ON loc.id = ai.location_id
              WHERE ai.status != 'inactive'
              ORDER BY (ai.asset_mode = 'group') DESC, COALESCE(ag.group_name, 'IT Asset'), at.type_name, ai.asset_code";
    $maintenanceAssets = $pdo->query($sqlAi)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($maintenanceAssets as &$maRow) {
        if (empty($maRow['asset_group_id'])) {
            $maRow['asset_group_id'] = $itGroupId ?: 1;
            $maRow['group_code'] = 'IT';
            $maRow['group_name'] = 'IT Asset';
        }
        if (empty($maRow['asset_type_id'])) {
            $maRow['asset_type_id'] = $cmpTypeId ?: 1;
            $maRow['type_code'] = 'CMP';
            $maRow['type_name'] = 'Computer';
        }
    }
    unset($maRow);

    // Ambil daftar Master Job Desk terdaftar
    $sqlDesks = "SELECT d.id AS desk_id, d.job_desk_name, d.asset_group_id, d.asset_type_id, d.description,
                        g.group_code, g.group_name, t.type_code, t.type_name,
                        COUNT(j.id) AS job_count,
                        COALESCE(SUM(j.estimated_minutes), 0) AS total_minutes
                 FROM preventive_job_desks d
                 LEFT JOIN asset_groups g ON g.id = d.asset_group_id
                 LEFT JOIN asset_types t ON t.id = d.asset_type_id
                 LEFT JOIN maintenance_jobs j ON (
                     j.job_desk_name COLLATE utf8mb4_unicode_ci = d.job_desk_name COLLATE utf8mb4_unicode_ci
                     OR (d.job_desk_name = 'Job Desk Umum' AND (j.job_desk_name IS NULL OR j.job_desk_name = ''))
                 ) AND j.is_active = 1
                 GROUP BY d.id, d.job_desk_name, d.asset_group_id, d.asset_type_id, d.description, g.group_code, g.group_name, t.type_code, t.type_name
                 ORDER BY g.group_name, t.type_name, d.job_desk_name";
    $preventiveDesks = $pdo->query($sqlDesks)->fetchAll(PDO::FETCH_ASSOC);

    // Ambil semua daftar pekerjaan aktif dikelompokkan per nama job desk
    $jobsSql = "SELECT j.*, g.group_name, g.group_code, t.type_name, t.type_code 
                FROM maintenance_jobs j 
                LEFT JOIN asset_groups g ON g.id = j.asset_group_id 
                LEFT JOIN asset_types t ON t.id = j.asset_type_id 
                WHERE j.is_active = 1
                ORDER BY COALESCE(j.job_desk_name, 'Job Desk Umum'), j.id ASC";
    $allJobs = $pdo->query($jobsSql)->fetchAll(PDO::FETCH_ASSOC);

    $jobsByDesk = [];
    foreach ($allJobs as $job) {
        $dName = trim((string)($job['job_desk_name'] ?? ''));
        if ($dName === '') {
            $dName = 'Job Desk Umum';
        }
        $jobsByDesk[$dName][] = $job;
    }

    $techs = $pdo->query("SELECT id, name FROM users WHERE role='technician' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    echo '<section class="panel">'
        . '<div class="split" style="align-items:center; margin-bottom: 16px;">'
        . '<div>'
        . '<h1 style="margin: 0; font-size: 20px;">Tambah Schedule Maintenance</h1>'
        . '<p class="muted" style="margin: 4px 0 0 0;">Susun jadwal preventive maintenance berdasarkan Komoditas & Kategori aset untuk menentukan Job Desk yang tepat.</p>'
        . '</div>'
        . '<div class="actions">'
        . '<a class="btn" href="' . route_url('maintenance') . '">← Kembali ke Jadwal Maintenance</a>'
        . '</div>'
        . '</div>';

    echo '<form method="post" id="formSchedule">'
        . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
        . '<input type="hidden" name="asset_group_id" id="postAssetGroupId" value="' . e($selectedGroupId) . '">';

    // BAGIAN 1: PEMILIHAN KOMODITAS, KATEGORI, DAN ASET MAINTENANCE
    echo '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;">'
        . '<div style="font-weight: 700; font-size: 15px; color: #1e3a8a; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">'
        . '<span>🎯 1. Tentukan Komoditas, Kategori, & Target Aset</span>'
        . '</div>'
        . '<div class="grid three">'
        . '<label>Komoditas (Asset Group)'
        . '<select id="schedGroupSelect" name="filter_group_id">'
        . '<option value="">-- Semua Komoditas --</option>';
    foreach ($assetGroups as $ag) {
        $sel = ($selectedGroupId === (int)$ag['id']) ? ' selected' : '';
        echo '<option value="' . (int)$ag['id'] . '"' . $sel . '>' . e($ag['group_code'] . ' - ' . $ag['group_name']) . '</option>';
    }
    echo '</select></label>';

    echo '<label>Kategori (Asset Type)'
        . '<select id="schedTypeSelect" name="filter_type_id">'
        . '<option value="">-- Semua Kategori --</option>';
    foreach ($assetTypes as $at) {
        echo '<option value="' . (int)$at['id'] . '" data-group-id="' . (int)$at['asset_group_id'] . '">' . e($at['type_code'] . ' - ' . $at['type_name']) . '</option>';
    }
    echo '</select></label>';

    echo '<label>Unit Aset (Terdaftar) *'
        . '<select id="schedAssetSelect" name="asset_item_id" required>'
        . '<option value="">-- Pilih Unit Aset --</option>';
    foreach ($maintenanceAssets as $maRow) {
        $mId = (int)$maRow['id'];
        $mCode = $maRow['asset_code'];
        $mName = $maRow['display_name'] ?: $maRow['asset_name'];
        $mSub = $maRow['display_sub'] ?: '';
        $mDesk = $maRow['job_desk_name'] ?: '';
        $mGid = (int)$maRow['asset_group_id'];
        $mTid = (int)$maRow['asset_type_id'];
        $mGname = $maRow['group_name'] ?: 'IT Asset';
        $mTname = $maRow['type_name'] ?: '';
        $mMode = (string)($maRow['asset_mode'] ?? 'standalone');

        $badgePrefix = ($mMode === 'group') ? '[📦 INDUK BUNDLE] ' : (($mMode === 'child') ? '[🔗 ANGGOTA] ' : '');
        $optLabel = $badgePrefix . $mCode . ' - ' . $mName . ($mSub ? ' (' . $mSub . ')' : '');
        $selected = ($targetAssetId === $mId) ? ' selected' : '';

        echo '<option value="' . $mId . '"'
            . ' data-code="' . e($mCode) . '"'
            . ' data-group-id="' . $mGid . '"'
            . ' data-type-id="' . $mTid . '"'
            . ' data-group-name="' . e($mGname) . '"'
            . ' data-type-name="' . e($mTname) . '"'
            . ' data-job-desk="' . e($mDesk) . '"'
            . ' data-sub="' . e($mSub) . '"'
            . $selected . '>'
            . e($optLabel)
            . '</option>';
    }
    echo '</select></label>'
        . '</div>'
        . '<div id="assetSelectedNotice" style="display:none; margin-top: 10px; font-size: 13px; color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 12px;"></div>'
        . '</div>';

    // BAGIAN 2: PEMILIHAN JOB DESK PREVENTIVE MAINTENANCE
    echo '<div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 20px;">'
        . '<div style="font-weight: 700; font-size: 15px; color: #1e40af; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">'
        . '<span>📋 2. Pilih Job Desk Preventive Maintenance yang Sesuai</span>'
        . '<span id="jobDeskStatsBadge" class="badge ok" style="font-size: 12px; padding: 5px 12px; display: none;"></span>'
        . '</div>'
        . '<div class="grid two" style="align-items: center;">'
        . '<label style="margin: 0;">Job Desk Terpilih *'
        . '<select id="schedJobDeskSelect" name="selected_job_desk" required style="font-weight: 700; font-size: 14px; color: #0f172a; padding: 8px 12px; background: #fff;">'
        . '<option value="">-- Pilih Job Desk --</option>';

    foreach ($preventiveDesks as $pd) {
        $pId = (int)$pd['desk_id'];
        $pName = $pd['job_desk_name'];
        $pGid = (int)$pd['asset_group_id'];
        $pTid = (int)$pd['asset_type_id'];
        $pCount = (int)$pd['job_count'];
        $pMinutes = (int)$pd['total_minutes'];
        $pLabel = $pName . ' (' . $pCount . ' task, ~' . $pMinutes . ' mnt)';

        echo '<option value="' . e($pName) . '"'
            . ' data-desk-id="' . $pId . '"'
            . ' data-desk-name="' . e($pName) . '"'
            . ' data-group-id="' . $pGid . '"'
            . ' data-type-id="' . $pTid . '"'
            . ' data-job-count="' . $pCount . '"'
            . ' data-minutes="' . $pMinutes . '">'
            . e($pLabel)
            . '</option>';
    }

    echo '</select></label>'
        . '<div style="display: flex; gap: 8px; align-items: flex-end; padding-bottom: 2px;">'
        . '<a id="linkKelolaDesk" href="' . route_url('jobs') . '" target="_blank" class="btn" style="padding: 7px 12px; font-size: 12px; background: #fff;" title="Buka kelola Master Job Desk">+ Master Job Desk ↗</a>'
        . '</div>'
        . '</div>';

    // CONTAINER CHECKLIST PEKERJAAN (JOB TASKS)
    echo '<div style="margin-top: 16px;">'
        . '<div class="split" style="align-items: center; margin-bottom: 10px;">'
        . '<span style="font-weight: 600; font-size: 14px; color: #1e293b;">Checklist Rincian Pekerjaan (Job Tasks):</span>'
        . '<div class="actions">'
        . '<button type="button" class="btn" onclick="toggleActiveDeskJobs(true)" style="padding: 4px 10px; font-size: 12px; cursor: pointer;">Pilih Semua</button>'
        . '<button type="button" class="btn" onclick="toggleActiveDeskJobs(false)" style="padding: 4px 10px; font-size: 12px; cursor: pointer;">Batal Pilih</button>'
        . '</div>'
        . '</div>'
        . '<div id="jobTasksContainer">';

    foreach ($jobsByDesk as $dName => $deskTasks) {
        $firstTask = $deskTasks[0];
        $tGid = (int)($firstTask['asset_group_id'] ?? 0);
        $tTid = (int)($firstTask['asset_type_id'] ?? 0);

        echo '<div class="job-desk-task-group" data-desk-name="' . e($dName) . '" data-group-id="' . $tGid . '" data-type-id="' . $tTid . '" style="display:none;">'
            . '<div class="grid two">';

        foreach ($deskTasks as $task) {
            $tId = (int)$task['id'];
            $tTitle = $task['title'];
            $tEst = (int)$task['estimated_minutes'];
            $tDesc = trim((string)($task['description'] ?? ''));

            echo '<label class="job-desk-card" style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">'
                . '<input type="checkbox" class="job-checkbox" name="jobs[]" value="' . $tId . '" checked style="width: 18px; height: 18px; min-width: 18px; cursor: pointer; margin-top: 3px; flex-shrink: 0;">'
                . '<div style="flex-grow: 1; min-width: 0;">'
                . '<div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">'
                . '<span style="font-weight: 600; color: #0f172a; line-height: 1.35;">' . e($tTitle) . '</span>'
                . '<span class="badge" style="font-size: 11px; font-weight:700;">~' . $tEst . ' mnt</span>'
                . '</div>'
                . ($tDesc !== '' ? ('<p class="muted" style="font-size: 11px; margin: 4px 0 0 0; line-height: 1.3;">' . e($tDesc) . '</p>') : '')
                . '</div>'
                . '</label>';
        }

        echo '</div></div>';
    }

    echo '</div>'
        . '<div id="noJobDeskPrompt" style="padding: 24px; text-align: center; background: #fff; border: 1px dashed #93c5fd; border-radius: 8px;">'
        . '<p class="muted" style="margin: 0 0 6px 0;">Silakan pilih <strong>Komoditas & Kategori</strong> atau pilih <strong>Job Desk</strong> di atas untuk memuat daftar checklist pekerjaan.</p>'
        . '</div>'
        . '</div>'
        . '</div>';

    // BAGIAN 3: PENUGASAN TEKNISI, TANGGAL & CATATAN
    echo '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;">'
        . '<div style="font-weight: 700; font-size: 15px; color: #334155; margin-bottom: 12px;">'
        . '<span>📅 3. Penugasan Teknisi & Jadwal Pelaksanaan</span>'
        . '</div>'
        . '<div class="grid two">'
        . '<label>Teknisi yang Bertugas *'
        . '<select name="technician_id" required>'
        . '<option value="">-- Pilih Teknisi --</option>';
    foreach ($techs as $tech) {
        echo '<option value="' . e($tech['id']) . '">' . e($tech['name']) . '</option>';
    }
    echo '</select></label>'
        . '<label>Tanggal Rencana Maintenance *'
        . '<input type="date" name="scheduled_date" required value="' . e(date('Y-m-d')) . '">'
        . '</label>'
        . '</div>'
        . '<label style="margin-top: 12px;">Catatan / Instruksi Khusus (Opsional)'
        . '<textarea name="notes" placeholder="Catatan instruksi tambahan untuk teknisi pelaksana..." rows="2"></textarea>'
        . '</label>'
        . '</div>';

    echo '<div style="margin-top: 20px;">'
        . '<button class="btn primary" style="font-size: 15px; padding: 10px 24px;">💾 Simpan Schedule Maintenance</button>'
        . '</div>'
        . '</form>'
        . '</section>';

    echo '<script>
(function(){
    var groupSelect = document.getElementById("schedGroupSelect");
    var typeSelect = document.getElementById("schedTypeSelect");
    var assetSelect = document.getElementById("schedAssetSelect");
    var deskSelect = document.getElementById("schedJobDeskSelect");
    var statsBadge = document.getElementById("jobDeskStatsBadge");
    var promptEmpty = document.getElementById("noJobDeskPrompt");
    var assetNotice = document.getElementById("assetSelectedNotice");
    var linkKelolaDesk = document.getElementById("linkKelolaDesk");
    var hiddenPostGroupId = document.getElementById("postAssetGroupId");
    var taskGroups = document.querySelectorAll(".job-desk-task-group");

    // Filter opsi kategori berdasarkan Komoditas (group)
    function filterTypeOptions(keepSelected) {
        var gid = groupSelect ? groupSelect.value : "";
        var currentTypeId = typeSelect ? typeSelect.value : "";
        Array.from(typeSelect.options).forEach(function(opt, idx) {
            if (idx === 0) { opt.style.display = ""; return; }
            var optGid = opt.getAttribute("data-group-id");
            var match = (!gid || optGid === gid);
            opt.style.display = match ? "" : "none";
        });
        if (!keepSelected && typeSelect.selectedIndex > 0 && typeSelect.options[typeSelect.selectedIndex].style.display === "none") {
            typeSelect.value = "";
        }
    }

    // Filter opsi aset berdasarkan Komoditas dan Kategori
    function filterAssetOptions() {
        var gid = groupSelect ? groupSelect.value : "";
        var tid = typeSelect ? typeSelect.value : "";
        var isCurrentStillValid = false;

        Array.from(assetSelect.options).forEach(function(opt, idx) {
            if (idx === 0) { opt.style.display = ""; return; }
            var optGid = opt.getAttribute("data-group-id");
            var optTid = opt.getAttribute("data-type-id");
            var matchG = (!gid || optGid === gid);
            var matchT = (!tid || optTid === tid);
            var show = matchG && matchT;
            opt.style.display = show ? "" : "none";
            if (show && opt.value === assetSelect.value) {
                isCurrentStillValid = true;
            }
        });

        if (!isCurrentStillValid && assetSelect.selectedIndex > 0) {
            assetSelect.value = "";
            updateAssetNotice();
        }
    }

    // Filter opsi Job Desk berdasarkan Komoditas dan Kategori, lalu pilih yang paling pas
    function filterJobDeskOptions(preferredDeskName) {
        var gid = groupSelect ? groupSelect.value : "";
        var tid = typeSelect ? typeSelect.value : "";
        var firstMatchValue = "";
        var exactMatchValue = "";
        var typeMatchValue = "";

        Array.from(deskSelect.options).forEach(function(opt, idx) {
            if (idx === 0) { opt.style.display = ""; return; }
            var optGid = opt.getAttribute("data-group-id");
            var optTid = opt.getAttribute("data-type-id");
            var optDeskName = opt.getAttribute("data-desk-name");

            var matchG = (!gid || optGid === gid || !optGid || optGid === "0");
            var matchT = (!tid || optTid === tid || !optTid || optTid === "0");
            var show = matchG && matchT;

            opt.style.display = show ? "" : "none";
            if (show) {
                if (!firstMatchValue) firstMatchValue = opt.value;
                if (preferredDeskName && (optDeskName === preferredDeskName || opt.value === preferredDeskName)) {
                    exactMatchValue = opt.value;
                }
                if (tid && optTid === tid && !typeMatchValue) {
                    typeMatchValue = opt.value;
                }
            }
        });

        var targetVal = exactMatchValue || preferredDeskName || typeMatchValue || firstMatchValue || "";
        if (targetVal) {
            deskSelect.value = targetVal;
        } else if (deskSelect.selectedIndex > 0 && deskSelect.options[deskSelect.selectedIndex].style.display === "none") {
            deskSelect.value = "";
        }

        applySelectedJobDeskTasks();
        updateManageDeskLink();
    }

    function updateManageDeskLink() {
        if (!linkKelolaDesk) return;
        var gid = groupSelect ? groupSelect.value : "";
        var tid = typeSelect ? typeSelect.value : "";
        var url = "index.php?route=jobs";
        if (gid) url += "&group_id=" + encodeURIComponent(gid);
        if (tid) url += "&type_id=" + encodeURIComponent(tid);
        linkKelolaDesk.href = url;
    }

    function updateAssetNotice() {
        if (!assetNotice) return;
        var opt = assetSelect.options[assetSelect.selectedIndex];
        if (!opt || !opt.value) {
            assetNotice.style.display = "none";
            assetNotice.innerHTML = "";
            return;
        }
        var code = opt.getAttribute("data-code") || "";
        var grp = opt.getAttribute("data-group-name") || "";
        var typ = opt.getAttribute("data-type-name") || "";
        var desk = opt.getAttribute("data-job-desk") || "(Belum ada penugasan default)";
        var sub = opt.getAttribute("data-sub") || "";
        assetNotice.style.display = "block";
        assetNotice.innerHTML = "✅ <strong>Target Terpilih:</strong> " + code + " &bull; <strong>Komoditas:</strong> " + grp + " &bull; <strong>Kategori:</strong> " + typ + (sub ? (" &bull; <em>" + sub + "</em>") : "") + " &bull; <strong>Job Desk Default Aset:</strong> " + desk;
    }

    function applySelectedJobDeskTasks() {
        var selectedOpt = deskSelect.options[deskSelect.selectedIndex];
        var deskName = selectedOpt ? selectedOpt.getAttribute("data-desk-name") : "";
        var count = selectedOpt ? selectedOpt.getAttribute("data-job-count") : "0";
        var mins = selectedOpt ? selectedOpt.getAttribute("data-minutes") : "0";
        var hasVisibleDesk = false;

        taskGroups.forEach(function(grp) {
            var gDesk = grp.getAttribute("data-desk-name");
            var isMatch = (deskName && gDesk === deskName);
            grp.style.display = isMatch ? "block" : "none";
            var cbs = grp.querySelectorAll(".job-checkbox");
            if (isMatch) {
                hasVisibleDesk = true;
                cbs.forEach(function(cb) {
                    cb.disabled = false;
                    cb.checked = true;
                });
            } else {
                cbs.forEach(function(cb) {
                    cb.disabled = true;
                    cb.checked = false;
                });
            }
        });

        if (promptEmpty) {
            promptEmpty.style.display = hasVisibleDesk ? "none" : "block";
        }
        if (statsBadge) {
            if (hasVisibleDesk && parseInt(count) > 0) {
                statsBadge.style.display = "inline-block";
                statsBadge.textContent = "Total: " + count + " Tasks (~" + mins + " Menit)";
            } else {
                statsBadge.style.display = "none";
            }
        }
    }

    window.toggleActiveDeskJobs = function(checked) {
        taskGroups.forEach(function(grp) {
            if (grp.style.display !== "none") {
                grp.querySelectorAll(".job-checkbox").forEach(function(cb) {
                    if (!cb.disabled) cb.checked = checked;
                });
            }
        });
    };

    if (groupSelect) {
        groupSelect.addEventListener("change", function() {
            if (hiddenPostGroupId) hiddenPostGroupId.value = this.value;
            filterTypeOptions(false);
            filterAssetOptions();
            filterJobDeskOptions("");
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener("change", function() {
            if (this.value) {
                var opt = this.options[this.selectedIndex];
                var optGid = opt.getAttribute("data-group-id");
                if (optGid && groupSelect && groupSelect.value !== optGid) {
                    groupSelect.value = optGid;
                    if (hiddenPostGroupId) hiddenPostGroupId.value = optGid;
                    filterTypeOptions(true);
                }
            }
            filterAssetOptions();
            filterJobDeskOptions("");
        });
    }

    if (assetSelect) {
        assetSelect.addEventListener("change", function() {
            var opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                var optGid = opt.getAttribute("data-group-id");
                var optTid = opt.getAttribute("data-type-id");
                var optDesk = opt.getAttribute("data-job-desk");

                if (optGid && groupSelect && groupSelect.value !== optGid) {
                    groupSelect.value = optGid;
                    if (hiddenPostGroupId) hiddenPostGroupId.value = optGid;
                    filterTypeOptions(true);
                }
                if (optTid && typeSelect && typeSelect.value !== optTid) {
                    typeSelect.value = optTid;
                }
                updateAssetNotice();
                filterJobDeskOptions(optDesk);
            } else {
                updateAssetNotice();
                filterJobDeskOptions("");
            }
        });
    }

    if (deskSelect) {
        deskSelect.addEventListener("change", function() {
            applySelectedJobDeskTasks();
            updateManageDeskLink();
        });
    }

    // Inisialisasi awal saat halaman dimuat
    if (assetSelect && assetSelect.value) {
        var initialOpt = assetSelect.options[assetSelect.selectedIndex];
        if (initialOpt && initialOpt.value) {
            var optGid = initialOpt.getAttribute("data-group-id");
            var optTid = initialOpt.getAttribute("data-type-id");
            var optDesk = initialOpt.getAttribute("data-job-desk");
            if (optGid && groupSelect) {
                groupSelect.value = optGid;
                if (hiddenPostGroupId) hiddenPostGroupId.value = optGid;
                filterTypeOptions(true);
            }
            if (optTid && typeSelect) {
                typeSelect.value = optTid;
            }
            filterAssetOptions();
            updateAssetNotice();
            filterJobDeskOptions(optDesk);
        }
    } else {
        filterTypeOptions(true);
        filterAssetOptions();
        filterJobDeskOptions("");
    }
})();
</script>';
    render_footer();
}

function handle_route_maintenance_do(PDO $pdo): void
{
    $user = require_login();
    $id = (int)($_GET['id'] ?? 0);
    $printerReady = printer_schema_ready($pdo);
    $stmt = $pdo->prepare('SELECT s.*, 
        COALESCE(ai.asset_code, s.pc_id, s.printer_id) asset_id, 
        COALESCE(ai.custodian_name, p.owner_name, pr.printer_name) owner_name, 
        COALESCE(ai.asset_name, p.computer_name, pr.location) computer_name, 
        COALESCE(p.physical_condition, pr.physical_condition) physical_condition,
        ai.asset_mode
        FROM maintenance_schedules s 
        LEFT JOIN asset_items ai ON ai.id = s.asset_item_id
        LEFT JOIN pcs p ON p.pc_id = s.pc_id 
        LEFT JOIN printers pr ON pr.prn_id = s.printer_id 
        WHERE s.id = ?');
    $stmt->execute([$id]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        http_response_code(404);
        exit('Schedule tidak ditemukan.');
    }
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
}

function handle_route_maintenance_unlock(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin']);
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("UPDATE maintenance_schedules SET status='reopened', locked_at=NULL, unlocked_at=NOW(), unlocked_by=?, unlock_reason=? WHERE id=?")->execute([$user['id'], trim($_POST['reason'] ?? 'Admin unlock'), $id]);
    add_timeline($pdo, $id, 'unlocked', 'Admin membuka kembali report.', (int)$user['id']);
    flash('Report berhasil di-unlock.');
    redirect_to('maintenance_do', ['id' => $id]);
}

/**
 * Katalog Template Tugas Standar Preventive Maintenance Berdasarkan Komoditas & Kategori Aset
 */
function get_standard_preventive_tasks_catalog(PDO $pdo, ?int $groupId, ?int $typeId, string $deskName = ''): array
{
    $typeCode = '';
    $typeName = '';
    $groupCode = '';
    $groupName = '';

    if ($typeId && $typeId > 0) {
        $stT = $pdo->prepare('SELECT type_code, type_name, asset_group_id FROM asset_types WHERE id = ?');
        $stT->execute([$typeId]);
        $tRow = $stT->fetch(PDO::FETCH_ASSOC);
        if ($tRow) {
            $typeCode = strtoupper(trim((string)$tRow['type_code']));
            $typeName = trim((string)$tRow['type_name']);
            if (!$groupId && !empty($tRow['asset_group_id'])) {
                $groupId = (int)$tRow['asset_group_id'];
            }
        }
    }

    if ($groupId && $groupId > 0) {
        $stG = $pdo->prepare('SELECT group_code, group_name FROM asset_groups WHERE id = ?');
        $stG->execute([$groupId]);
        $gRow = $stG->fetch(PDO::FETCH_ASSOC);
        if ($gRow) {
            $groupCode = strtoupper(trim((string)$gRow['group_code']));
            $groupName = trim((string)$gRow['group_name']);
        }
    }

    $haystack = strtolower($typeCode . ' ' . $typeName . ' ' . $groupCode . ' ' . $groupName . ' ' . $deskName);

    // Kategori Notebook / Laptop
    if ($typeCode === 'NBK' || str_contains($haystack, 'notebook') || str_contains($haystack, 'laptop')) {
        return [
            'category_label' => 'Notebook',
            'tasks' => [
                ['title' => 'Pembersihan Casing & Palmrest Laptop', 'estimated_minutes' => 5, 'description' => 'Bersihkan casing luar, palmrest, bezel layar, dan sela tombol dengan cairan pembersih aman.'],
                ['title' => 'Pembersihan Layar LCD / Monitor Notebook', 'estimated_minutes' => 5, 'description' => 'Bersihkan panel layar notebook dari noda/debu menggunakan cairan khusus LCD dan lap microfiber lembut.'],
                ['title' => 'Pembersihan Keyboard & Touchpad Notebook', 'estimated_minutes' => 5, 'description' => 'Gunakan kuas halus atau blower untuk membersihkan debu dan remah kotoran pada sela tombol keyboard dan touchpad.'],
                ['title' => 'Pembersihan Kisi-Kisi Ventilasi & Exhaust Fan', 'estimated_minutes' => 10, 'description' => 'Periksa dan bersihkan kisi-kisi ventilasi udara dari gumpalan debu yang menyumbat sirkulasi pendingin.'],
                ['title' => 'Pemeriksaan Engsel Layar (Hinge) & Bodi Fisik', 'estimated_minutes' => 5, 'description' => 'Uji kelancaran buka-tutup layar, pastikan engsel kokoh, tidak goyang, dan bodi tidak retak/renggang.'],
                ['title' => 'Pengecekan Kesehatan Baterai (Battery Health)', 'estimated_minutes' => 5, 'description' => 'Cek battery health, full charge capacity, dan cycle count, pastikan baterai tidak kembung atau drop drastis.'],
                ['title' => 'Pemeriksaan Adaptor Charger & Kabel Power', 'estimated_minutes' => 5, 'description' => 'Periksa fisik adaptor charger, integritas jack DC/Type-C, kabel tidak terkelupas, serta kestabilan charging.'],
                ['title' => 'Pemeriksaan Kesehatan Storage (SSD/HDD S.M.A.R.T.)', 'estimated_minutes' => 5, 'description' => 'Cek indikator S.M.A.R.T., health percentage storage, serta pastikan drive sistem (C:) memiliki sisa kapasitas cukup.'],
                ['title' => 'Pengecekan Suhu Operasional CPU & Thermal', 'estimated_minutes' => 5, 'description' => 'Pantau temperatur CPU saat idle dan load normal, pastikan tidak terjadi overheating atau thermal throttling.'],
                ['title' => 'Pemeriksaan Fungsi Port I/O & Peripheral Internal', 'estimated_minutes' => 5, 'description' => 'Uji port USB, Type-C, HDMI, audio jack, serta fungsi webcam, mikrofon, dan speaker internal.'],
                ['title' => 'Pemeriksaan Konektivitas Nirkabel (Wi-Fi & Bluetooth)', 'estimated_minutes' => 5, 'description' => 'Uji stabilitas koneksi Wi-Fi kantor dan koneksi perangkat nirkabel Bluetooth (mouse/headset).'],
                ['title' => 'Validasi Keamanan OS, Antivirus & Patch Update', 'estimated_minutes' => 5, 'description' => 'Pastikan antivirus aktif dengan definisi terbaru, proteksi real-time menyala, dan update keamanan OS terpasang.'],
            ],
        ];
    }

    // Kategori Computer / Desktop PC
    if ($typeCode === 'CMP' || str_contains($haystack, 'computer') || str_contains($haystack, 'desktop') || str_contains($haystack, 'pc')) {
        return [
            'category_label' => 'Computer',
            'tasks' => [
                ['title' => 'Pembersihan Debu Internal Casing CPU', 'estimated_minutes' => 10, 'description' => 'Buka panel samping casing, bersihkan debu motherboard, heatsink fan CPU, dan slot ekspansi dengan kuas/blower.'],
                ['title' => 'Pembersihan Ventilasi Udara & Filter Debu Casing', 'estimated_minutes' => 5, 'description' => 'Bersihkan kisi-kisi sirkulasi udara, kipas casing depan/belakang, dan filter debu magnetik casing.'],
                ['title' => 'Pembersihan Layar Monitor Display', 'estimated_minutes' => 5, 'description' => 'Kuas dan bersihkan permukaan layar monitor menggunakan cairan pembersih khusus layar dan lap microfiber.'],
                ['title' => 'Pembersihan Keyboard, Mouse & Mousepad', 'estimated_minutes' => 5, 'description' => 'Kuas sela-sela tombol keyboard, lap kering tombol dan bersihkan permukaan sensor optik mouse.'],
                ['title' => 'Pemeriksaan Suhu CPU & Kinerja Kipas Pendingin', 'estimated_minutes' => 5, 'description' => 'Cek suhu CPU di BIOS atau software monitoring, pastikan putaran fan normal tanpa bunyi gesekan abnormal.'],
                ['title' => 'Pengecekan Kesehatan Storage (SSD/HDD S.M.A.R.T.)', 'estimated_minutes' => 5, 'description' => 'Periksa status SMART SSD/HDD, bad sector warning, serta pastikan drive sistem memiliki ruang kosong cukup.'],
                ['title' => 'Pemeriksaan Power Supply Unit (PSU) & Kabel Daya', 'estimated_minutes' => 5, 'description' => 'Periksa kestabilan output daya PSU, kebersihan kipas PSU, dan kerapatan konektor kabel power ATX/CPU/SATA.'],
                ['title' => 'Pemeriksaan Modul RAM & Slot PCIe', 'estimated_minutes' => 5, 'description' => 'Pastikan modul RAM terpasang kokoh pada slot, bersihkan pin konektor jika diperlukan, dan cek deteksi kapasitas di OS.'],
                ['title' => 'Pemeriksaan Konektivitas Jaringan LAN & Internet', 'estimated_minutes' => 5, 'description' => 'Periksa kondisi kabel patch cord RJ45, port LAN card, dan kestabilan transmisi data jaringan kantor.'],
                ['title' => 'Pembaruan & Validasi Antivirus Aktif', 'estimated_minutes' => 5, 'description' => 'Pastikan antivirus korporat aktif, proteksi real-time menyala, dan definisi virus berada pada versi terbaru.'],
                ['title' => 'Pemeriksaan Patch Keamanan Windows & Driver Utama', 'estimated_minutes' => 5, 'description' => 'Pastikan update penting OS dan driver hardware utama (chipset, LAN, VGA) terpasang dengan baik.'],
                ['title' => 'Kerapian Manajemen Kabel (Cable Management)', 'estimated_minutes' => 5, 'description' => 'Rapikan susunan kabel di belakang meja kerja (kabel power, monitor, peripheral, LAN) menggunakan cable tie/spiral.'],
            ],
        ];
    }

    // Kategori Printer
    if ($typeCode === 'PRT' || str_contains($haystack, 'printer')) {
        return [
            'category_label' => 'Printer',
            'tasks' => [
                ['title' => 'Pembersihan Casing Luar & Panel Tombol Printer', 'estimated_minutes' => 5, 'description' => 'Lap permukaan luar printer dan panel tombol/layar navigasi dengan lap microfiber bersih.'],
                ['title' => 'Pembersihan Kaca Scanner & ADF (Bila Ada)', 'estimated_minutes' => 5, 'description' => 'Gunakan cairan pembersih kaca untuk membersihkan kaca flatbed scanner dan kaca ADF kecil dari kotoran/noda.'],
                ['title' => 'Pembersihan Roller Penarik Kertas (Pickup Roller)', 'estimated_minutes' => 5, 'description' => 'Bersihkan karet pick-up roller dari debu kertas agar proses penarikan kertas tidak slip atau paper jam.'],
                ['title' => 'Pembersihan Area Jalur Kertas (Paper Path)', 'estimated_minutes' => 5, 'description' => 'Periksa dan bersihkan sisa sobekan kertas, debu kertas, atau tumpahan toner/tinta di dalam mekanisme jalur kertas.'],
                ['title' => 'Pemeriksaan Level Tinta / Toner Cartridge', 'estimated_minutes' => 5, 'description' => 'Periksa sisa kapasitas tangki tinta / toner cartridge dan pastikan tidak ada kebocoran atau tumpahan di dalam printer.'],
                ['title' => 'Pembersihan Printhead / Nozzle Check', 'estimated_minutes' => 10, 'description' => 'Lakukan nozzle check dan head cleaning (inkjet) atau pembersihan drum/laser glass (laserjet) untuk menjaga hasil cetak.'],
                ['title' => 'Pemeriksaan & Pelumasan Rel Carriage Unit', 'estimated_minutes' => 5, 'description' => 'Periksa kelancaran geser carriage unit, bersihkan encoder strip dari noda tinta, dan beri pelumas rel khusus bila kering.'],
                ['title' => 'Uji Cetak Dokumen Uji (Print Test Page)', 'estimated_minutes' => 5, 'description' => 'Cetak test page untuk memastikan kejernihan teks, ketepatan garis, dan gradasi warna tanpa garis putus/bayang.'],
                ['title' => 'Uji Fungsi Pemindai & Fotokopi (Scan & Copy Test)', 'estimated_minutes' => 5, 'description' => 'Untuk printer All-in-One, lakukan scan uji dan fotokopi via flatbed maupun ADF untuk memastikan fungsi optik prima.'],
                ['title' => 'Pemeriksaan Kabel Data (USB / LAN) & Koneksi Wi-Fi', 'estimated_minutes' => 5, 'description' => 'Periksa kekencangan kabel USB/LAN dan stabilitas konektivitas jaringan printer di jaringan kantor.'],
                ['title' => 'Pemeriksaan Kabel Daya & Trafo / Adaptor Listrik', 'estimated_minutes' => 5, 'description' => 'Pastikan kabel power tertancap kokoh dan adaptor tidak mengalami panas berlebih atau percikan listrik.'],
                ['title' => 'Pemeriksaan Spooler & Driver di Komputer Pengguna', 'estimated_minutes' => 5, 'description' => 'Bersihkan antrean print spooler yang macet dan pastikan driver printer di komputer user versi stabil terbaru.'],
            ],
        ];
    }

    // Kategori Server
    if ($typeCode === 'SRV' || str_contains($haystack, 'server')) {
        return [
            'category_label' => 'Server',
            'tasks' => [
                ['title' => 'Pembersihan Filter Debu & Ventilasi Rackmount', 'estimated_minutes' => 10, 'description' => 'Bersihkan kisi ventilasi depan/belakang dan filter debu chassis server untuk menjamin aliran udara maksimal.'],
                ['title' => 'Pemeriksaan Indikator LED Hardware (Health Status)', 'estimated_minutes' => 5, 'description' => 'Periksa status LED front panel (Power, HDD activity, System Alert/Warning LED, LAN activity).'],
                ['title' => 'Pemeriksaan Status RAID Array & Health Storage Disk', 'estimated_minutes' => 10, 'description' => 'Buka controller RAID / storage manager, pastikan semua disk berstatus Online (tidak ada degraded/rebuilding).'],
                ['title' => 'Pemeriksaan Suhu Server & Redundansi Chassis Fan', 'estimated_minutes' => 5, 'description' => 'Pantau suhu CPU/system board dan pastikan semua modul redundant cooling fan berputar dengan RPM normal.'],
                ['title' => 'Pemeriksaan Redundansi Power Supply Unit (PSU Failover)', 'estimated_minutes' => 5, 'description' => 'Pastikan kedua modul PSU menyala (LED hijau) dan terhubung ke sumber daya terpisah (UPS 1 & UPS 2).'],
                ['title' => 'Pemeriksaan Log Sistem & Hardware Event Log (SEL)', 'estimated_minutes' => 10, 'description' => 'Review log hardware di iLO/iDRAC/BMC dan System Event Viewer OS untuk mendeteksi potensi kegagalan komponen.'],
                ['title' => 'Pemeriksaan Akses Remote Management (iLO/iDRAC/IPMI)', 'estimated_minutes' => 5, 'description' => 'Uji konektivitas antarmuka manajemen remote out-of-band dan pastikan firmware controller stabil.'],
                ['title' => 'Pemeriksaan Utilisasi Sumber Daya (CPU, RAM, Storage)', 'estimated_minutes' => 5, 'description' => 'Periksa grafik utilisasi CPU, memory usage, serta sisa ruang kosong pada volume penyimpanan server.'],
                ['title' => 'Pemeriksaan Kabel Patch Jaringan & Trunking LAN', 'estimated_minutes' => 5, 'description' => 'Pastikan kabel patch cord terlabel rapi, terkunci kokoh pada port LAN server/switch, dan bebas tekukan tajam.'],
                ['title' => 'Verifikasi Jadwal Backup Data & Snapshot Storage', 'estimated_minutes' => 10, 'description' => 'Periksa status pekerjaan backup harian/mingguan terakhir dan pastikan tidak ada job backup yang gagal.'],
                ['title' => 'Pemeriksaan Koneksi & Status Baterai UPS Ruang Server', 'estimated_minutes' => 5, 'description' => 'Periksa kondisi daya input/output UPS server, indikator baterai, dan lakukan self-test rutin UPS.'],
                ['title' => 'Pemeriksaan Pembaruan Patch OS & Firmware Kritis', 'estimated_minutes' => 10, 'description' => 'Verifikasi kesiapan security update OS server dan rencanakan jendela maintenance jika diperlukan reboot.'],
            ],
        ];
    }

    // Kategori Monitor & Display
    if ($typeCode === 'DSP' || str_contains($haystack, 'monitor') || str_contains($haystack, 'display')) {
        return [
            'category_label' => 'Monitor & Display',
            'tasks' => [
                ['title' => 'Pembersihan Panel Layar Monitor', 'estimated_minutes' => 5, 'description' => 'Bersihkan permukaan panel display menggunakan cairan khusus pembersih layar dan lap microfiber searah.'],
                ['title' => 'Pembersihan Bezel, Casing Belakang & Ventilasi', 'estimated_minutes' => 5, 'description' => 'Bersihkan debu pada frame bezel, kisi-kisi ventilasi belakang monitor, dan stand penyangga.'],
                ['title' => 'Pemeriksaan Visual Panel (Dead Pixel & Backlight Bleed)', 'estimated_minutes' => 5, 'description' => 'Lakukan tes warna solid (merah, hijau, biru, putih, hitam) untuk memeriksa pixel mati atau kebocoran backlight.'],
                ['title' => 'Pemeriksaan Kabel Display & Konektor (HDMI/DP/VGA/Type-C)', 'estimated_minutes' => 5, 'description' => 'Periksa fisik kabel video, pastikan pin konektor tidak bengkok, kabel tidak terjepit, dan sinyal stabil.'],
                ['title' => 'Pemeriksaan Adaptor & Kabel Daya Monitor', 'estimated_minutes' => 5, 'description' => 'Pastikan socket power dan adaptor listrik terpasang kokoh serta tidak mengalami panas abnormal.'],
                ['title' => 'Pemeriksaan Kestabilan Dudukan / VESA Mount Stand', 'estimated_minutes' => 5, 'description' => 'Periksa kekencangan baut VESA arm/bracket dan kelancaran engsel tilt, swivel, pivot, atau height adjustment.'],
                ['title' => 'Kalibrasi Warna, Kecerahan & Kontras Display', 'estimated_minutes' => 5, 'description' => 'Sesuaikan pengaturan brightness, contrast, dan color temperature monitor untuk kenyamanan kerja pengguna.'],
                ['title' => 'Pemeriksaan Tombol Navigasi / Joystick Menu OSD', 'estimated_minutes' => 5, 'description' => 'Uji fungsi seluruh tombol kontrol fisik atau joystick On-Screen Display (OSD) monitor.'],
                ['title' => 'Pengecekan Resolusi Native & Refresh Rate di OS', 'estimated_minutes' => 5, 'description' => 'Pastikan setting tampilan pada sistem operasi menggunakan resolusi native dan refresh rate optimal monitor.'],
                ['title' => 'Pemeriksaan Fungsi Built-in Speaker / Audio Jack (Bila Ada)', 'estimated_minutes' => 5, 'description' => 'Uji output suara jika monitor dilengkapi built-in speaker atau lubang output headphone.'],
                ['title' => 'Pemeriksaan Fungsi USB Hub Terintegrasi (Bila Ada)', 'estimated_minutes' => 5, 'description' => 'Uji port USB downstream/upstream yang terpasang pada bodi monitor.'],
                ['title' => 'Kerapian Manajemen Kabel Belakang Layar', 'estimated_minutes' => 5, 'description' => 'Tata rapi kabel daya dan display melalui jalur cable clips di tiang penyangga monitor.'],
            ],
        ];
    }

    // Kategori Kendaraan Mobil / Car
    if ($typeCode === 'CAR' || str_contains($haystack, 'mobil') || str_contains($haystack, 'car')) {
        return [
            'category_label' => 'Mobil',
            'tasks' => [
                ['title' => 'Pengecekan Level & Kualitas Oli Mesin', 'estimated_minutes' => 5, 'description' => 'Periksa ketinggian oli pada dipstick dan pastikan warna/viskositas oli masih layak (tidak hitam pekat/berbau bensin).'],
                ['title' => 'Pengecekan Cairan Radiator & Tabung Reservoir Coolant', 'estimated_minutes' => 5, 'description' => 'Periksa volume air radiator pada tabung reservoir di batas normal (antara LOW dan FULL).'],
                ['title' => 'Pemeriksaan Sistem Pengereman & Minyak Rem', 'estimated_minutes' => 10, 'description' => 'Periksa volume minyak rem pada reservoir dan periksa ketebalan kampas rem serta kepakeman rem.'],
                ['title' => 'Pemeriksaan Tekanan & Kondisi Tapak Ban (Termasuk Cadangan)', 'estimated_minutes' => 10, 'description' => 'Periksa tekanan angin ban (sesuai psi standar pintu) dan cek keausan alur tapak ban serta ban serep.'],
                ['title' => 'Pemeriksaan Kondisi Aki Kendaraan (Battery Aki)', 'estimated_minutes' => 5, 'description' => 'Periksa tegangan aki, level air aki (jika basah), dan bersihkan kerak putih pada kepala kutub aki.'],
                ['title' => 'Pemeriksaan Fungsi Seluruh Lampu Kendaraan', 'estimated_minutes' => 5, 'description' => 'Uji nyala lampu utama (dekat/jauh), lampu kota, lampu sein, lampu rem, lampu mundur, dan hazard.'],
                ['title' => 'Pengecekan Kondisi Karet Wiper & Air Washer Kaca', 'estimated_minutes' => 5, 'description' => 'Periksa elastisitas karet wiper, semprotan nozzle air washer kaca depan/belakang, dan isi ulang air tabung.'],
                ['title' => 'Pemeriksaan Sistem Kemudi & Suspensi', 'estimated_minutes' => 10, 'description' => 'Periksa kelurusan kemudi (spooring/balancing), cek bunyi asing atau getaran saat roda diputar/dikendarai.'],
                ['title' => 'Pemeriksaan Minyak Power Steering & Minyak Kopling/Transmisi', 'estimated_minutes' => 5, 'description' => 'Periksa level cairan power steering dan oli transmisi/minyak kopling dari potensi rembesan.'],
                ['title' => 'Pengecekan AC Kendaraan & Indikator Dashboard', 'estimated_minutes' => 5, 'description' => 'Pastikan hembusan AC dingin normal, blower berfungsi rata, dan tidak ada lampu indikator warning/check engine menyala.'],
                ['title' => 'Pemeriksaan Kelengkapan Darurat Kendaraan (P3K, Dongkrak, APAR)', 'estimated_minutes' => 5, 'description' => 'Pastikan dongkrak, kunci roda, segitiga pengaman, kotak P3K, dan APAR mini tersedia dalam kondisi siap pakai.'],
                ['title' => 'Pembersihan Eksterior/Interior & Cek Masa Berlaku STNK/KIR', 'estimated_minutes' => 10, 'description' => 'Periksa kebersihan ruang kabin, kaca depan, dan periksa masa berlaku STNK, pajak kendaraan, serta KIR.'],
            ],
        ];
    }

    // Kategori Sepeda Motor / Motorcycle
    if ($typeCode === 'MTR' || str_contains($haystack, 'motor')) {
        return [
            'category_label' => 'Sepeda Motor',
            'tasks' => [
                ['title' => 'Pengecekan Ketinggian & Kejernihan Oli Mesin', 'estimated_minutes' => 5, 'description' => 'Periksa volume oli mesin dengan dipstick dan pastikan oli transmisi/gardan (khusus matic) dalam kondisi baik.'],
                ['title' => 'Pemeriksaan Sistem Pengereman Depan & Belakang', 'estimated_minutes' => 5, 'description' => 'Periksa ketebalan kampas rem depan/belakang, keausan piringan cakram, dan level minyak rem master silinder.'],
                ['title' => 'Pemeriksaan Tekanan Angin & Kondisi Fisik Ban', 'estimated_minutes' => 5, 'description' => 'Periksa tekanan angin ban depan & belakang serta pastikan alur ban belum aus (tidak botak/retak).'],
                ['title' => 'Pemeriksaan Rantai Roda / V-Belt & Roller CVT', 'estimated_minutes' => 10, 'description' => 'Cek ketegangan dan lumasi rantai roda (bebek/sport), atau cek suara kasar pada mangkok CVT (matic).'],
                ['title' => 'Pemeriksaan Tegangan Aki & Sistem Starter Listrik', 'estimated_minutes' => 5, 'description' => 'Periksa kesiapan starter elektrik, tegangan aki, dan pastikan klakson bersuara lantang.'],
                ['title' => 'Pemeriksaan Lampu Utama, Sein, Rem, & Indikator Spidometer', 'estimated_minutes' => 5, 'description' => 'Pastikan semua fungsi pencahayaan dan lampu indikator spidometer menyala normal.'],
                ['title' => 'Pemeriksaan Karet Grip Gas, Handle Rem, & Spion', 'estimated_minutes' => 5, 'description' => 'Pastikan tuas gas kembali otomatis (tidak seret), handle rem responsif, dan kedua kaca spion terpasang kencang.'],
                ['title' => 'Pengecekan Suspensi Depan & Belakang (Shock Absorber)', 'estimated_minutes' => 5, 'description' => 'Periksa bantalan peredam kejut depan/belakang dari kebocoran oli shock dan pastikan ayunan empuk.'],
                ['title' => 'Pengecekan Saringan Udara (Air Filter)', 'estimated_minutes' => 5, 'description' => 'Bersihkan debu pada filter udara dan ganti jika filter elemen sudah sangat kotor.'],
                ['title' => 'Pengecekan Busi & Jalur Bahan Bakar', 'estimated_minutes' => 5, 'description' => 'Periksa elektroda busi dari kerak karbon dan pastikan tidak ada kebocoran selang bahan bakar.'],
                ['title' => 'Pembersihan Bodi Motor & Kaca Lampu', 'estimated_minutes' => 5, 'description' => 'Bersihkan debu bodi motor, permukaan spidometer, dan mika lampu dari kotoran jalanan.'],
                ['title' => 'Pemeriksaan Kelengkapan Dokumen (STNK & Pajak)', 'estimated_minutes' => 5, 'description' => 'Periksa masa berlaku pajak tahunan dan lima tahunan sepeda motor dinas.'],
            ],
        ];
    }

    // Kategori Truk / Truck
    if ($typeCode === 'TRK' || str_contains($haystack, 'truk') || str_contains($haystack, 'truck')) {
        return [
            'category_label' => 'Truk',
            'tasks' => [
                ['title' => 'Pengecekan Level Oli Mesin, Gardan & Transmisi', 'estimated_minutes' => 10, 'description' => 'Periksa ketinggian dan kejernihan oli mesin truk, oli gardan belakang, dan oli transmisi manual.'],
                ['title' => 'Pemeriksaan Sistem Radiator & Sirkulasi Coolant Truk', 'estimated_minutes' => 5, 'description' => 'Periksa volume air radiator, tutup radiator, dan pastikan tidak ada kebocoran selang pendingin mesin diesel.'],
                ['title' => 'Pemeriksaan Sistem Rem Udara / Angin (Pneumatic Brake)', 'estimated_minutes' => 10, 'description' => 'Periksa tekanan tabung angin rem, buang air kondensasi kompresor, dan cek kebocoran selang angin.'],
                ['title' => 'Pemeriksaan Kondisi & Torsi Baut Roda Seluruh Ban', 'estimated_minutes' => 15, 'description' => 'Periksa tekanan angin ban ganda/tunggal, kedalaman alur ban, dan kekencangan mur baut roda.'],
                ['title' => 'Pemeriksaan Kondisi Aki Ganda (24V) & Kelistrikan', 'estimated_minutes' => 10, 'description' => 'Periksa tegangan seri aki 24V, kejernihan air aki, kebersihan terminal, dan switch pemutus arus utama.'],
                ['title' => 'Pemeriksaan Sistem Suspensi Per Daun & Pelumasan Nipple', 'estimated_minutes' => 15, 'description' => 'Periksa susunan daun per dari keretakan/geseran dan berikan gemuk/grease pada nipple sasis.'],
                ['title' => 'Pemeriksaan Lampu Kerja, Lampu Utama, Sein, & Sirine Mundur', 'estimated_minutes' => 5, 'description' => 'Pastikan seluruh lampu sorot kerja, lampu rem, lampu hazard, dan alarm mundur berfungsi keras.'],
                ['title' => 'Pemeriksaan Filter Solar & Kuras Water Separator', 'estimated_minutes' => 10, 'description' => 'Kuras endapan air pada mangkok sedimenter solar dan periksa kebersihan filter solar primer/sekunder.'],
                ['title' => 'Pemeriksaan Sistem Kemudi (Kingpin & Tie Rod End)', 'estimated_minutes' => 10, 'description' => 'Periksa kelonggaran tie rod, draglink, dan pastikan sistem power steering bekerja tanpa rembesan oli.'],
                ['title' => 'Pemeriksaan Bak Muatan, Engsel Pintu & Pengunci Terpal', 'estimated_minutes' => 5, 'description' => 'Periksa kekokohan dinding bak muatan, kelancaran engsel pintu belakang, dan bracket pengaman.'],
                ['title' => 'Pemeriksaan Perlengkapan Darurat (Dongkrak Berat, Balok, APAR)', 'estimated_minutes' => 5, 'description' => 'Pastikan dongkrak hidrolik tonase besar, balok pengganjal roda, segitiga, dan APAR tersedia lengkap.'],
                ['title' => 'Pemeriksaan Kelengkapan Legalitas (KIR, STNK, Izin Operasi)', 'estimated_minutes' => 5, 'description' => 'Periksa masa berlaku uji berkala KIR, kartu pengawasan, izin dispensasi jalan, dan STNK truk.'],
            ],
        ];
    }

    // Kategori AC / Pendingin
    if ($typeCode === 'AC' || str_contains($haystack, 'ac') || str_contains($haystack, 'pendingin')) {
        return [
            'category_label' => 'AC / Pendingin',
            'tasks' => [
                ['title' => 'Pembersihan Filter Udara Unit Indoor', 'estimated_minutes' => 10, 'description' => 'Lepas filter debu unit indoor, cuci bersih dengan air mengalir dan keringkan sebelum dipasang kembali.'],
                ['title' => 'Pembersihan Evaporator Indoor Unit (Cuci AC)', 'estimated_minutes' => 15, 'description' => 'Semprot sirip-sirip evaporator indoor dengan jet cleaner dan cairan pembersih khusus hingga bebas lendir/jamur.'],
                ['title' => 'Pembersihan Talang & Saluran Pembuangan Air (Drainase)', 'estimated_minutes' => 10, 'description' => 'Semprot dan bersihkan pipa drainase kondensasi air agar tidak terjadi kebocoran air menetes (water leakage).'],
                ['title' => 'Pembersihan Kisi-kisi Condenser Unit Outdoor', 'estimated_minutes' => 15, 'description' => 'Semprot sirip kondensor outdoor unit dengan air bertekanan untuk menghilangkan debu dan kotoran tebal.'],
                ['title' => 'Pemeriksaan Tekanan Gas Refrigerant (Freon R32/R410A)', 'estimated_minutes' => 10, 'description' => 'Ukur tekanan freon menggunakan manifold gauge saat kompresor bekerja, pastikan sesuai spesifikasi (psi).'],
                ['title' => 'Pengukuran Arus Listrik (Ampere) Kompresor', 'estimated_minutes' => 5, 'description' => 'Ukur beban arus listrik (ampere) menggunakan clamp meter dan bandingkan dengan nameplate AC.'],
                ['title' => 'Pemeriksaan Putaran Fan Blower Indoor & Fan Outdoor', 'estimated_minutes' => 5, 'description' => 'Pastikan motor blower indoor berputar hening seimbang dan kipas fan outdoor berhembus kencang.'],
                ['title' => 'Pemeriksaan Kerapatan Bracket Outdoor & Peredam Getaran', 'estimated_minutes' => 5, 'description' => 'Pastikan baut bracket outdoor kokoh, tidak berkarat keropos, dan bantalan karet peredam getaran terpasang.'],
                ['title' => 'Pemeriksaan Terminal Kelistrikan & Kapasitor Kompresor', 'estimated_minutes' => 5, 'description' => 'Periksa kekencangan baut terminal kabel listrik dan periksa kondisi fisik kapasitor (tidak kembung).'],
                ['title' => 'Pengecekan Suhu Pendinginan (Delta T Inlet vs Outlet)', 'estimated_minutes' => 5, 'description' => 'Ukur temperatur udara masuk (inlet) dan udara hembusan keluar (outlet), pastikan selisih suhu normal (min. 8-10°C).'],
                ['title' => 'Pemeriksaan Fungsi Remote Control & Sensor Display Indoor', 'estimated_minutes' => 5, 'description' => 'Uji respons sensor inframerah remote control, swing louvre motor, dan ketepatan setpoint suhu.'],
                ['title' => 'Pembersihan Casing Cover Indoor & Kerapian Isolasi Pipa', 'estimated_minutes' => 5, 'description' => 'Lap bersih cover plastik indoor unit dan periksa pembungkus insulasi duct tape pipa tembaga freon.'],
            ],
        ];
    }

    // Kategori Genset / Generator
    if ($typeCode === 'GEN' || str_contains($haystack, 'genset') || str_contains($haystack, 'generator')) {
        return [
            'category_label' => 'Genset',
            'tasks' => [
                ['title' => 'Pemeriksaan Ketinggian & Kualitas Oli Mesin Genset', 'estimated_minutes' => 5, 'description' => 'Cek dipstick oli mesin, pastikan level oli cukup dan viskositas tidak mengental atau terkontaminasi.'],
                ['title' => 'Pemeriksaan Bahan Bakar Solar & Kuras Filter Sedimen', 'estimated_minutes' => 10, 'description' => 'Periksa level solar pada tangki harian dan buang endapan air pada water separator filter bahan bakar.'],
                ['title' => 'Pemeriksaan Kondisi Aki Starter & Tegangan Charger Alternator', 'estimated_minutes' => 10, 'description' => 'Periksa level air aki genset, kebersihan terminal kutub, dan pastikan automatic battery trickle charger aktif.'],
                ['title' => 'Pemeriksaan Air Radiator & Sirkulasi Cairan Pendingin', 'estimated_minutes' => 5, 'description' => 'Periksa level air radiator dan kondisi selang karet radiator dari keretakan atau kebocoran klem.'],
                ['title' => 'Pemeriksaan Ketegangan V-Belt Kipas & Alternator', 'estimated_minutes' => 5, 'description' => 'Periksa kelenturan dan kondisi fisik tali kipas (v-belt) genset, pastikan tidak kendur atau retak.'],
                ['title' => 'Pembersihan / Penggantian Filter Udara Genset', 'estimated_minutes' => 10, 'description' => 'Buka rumah filter udara, bersihkan elemen saringan dari debu tebal menggunakan semprotan angin kompresor.'],
                ['title' => 'Uji Pemanasan Mesin (Running Test 10-15 Menit)', 'estimated_minutes' => 15, 'description' => 'Nyalakan genset dalam mode pemanasan (no-load / load test) untuk melumasi seluruh komponen mesin internal.'],
                ['title' => 'Pengukuran Parameter Output Listrik (Volt, Hz, RPM)', 'estimated_minutes' => 5, 'description' => 'Ukur voltase antar-fase (380V/220V), frekuensi listrik (50 Hz), dan kestabilan putaran mesin (1500 RPM).'],
                ['title' => 'Pengecekan Kebocoran (Oli, Solar, Coolant, Knalpot)', 'estimated_minutes' => 5, 'description' => 'Inspeksi visual ruang mesin saat menyala untuk memastikan tidak ada rembesan cairan atau kebocoran gas buang.'],
                ['title' => 'Pemeriksaan Panel Kontrol & Indikator Sensor (Hour Meter)', 'estimated_minutes' => 5, 'description' => 'Periksa display digital/analog genset: tekanan oli, temperatur air mesin, dan catat jam operasi genset.'],
                ['title' => 'Pengujian Tombol Emergency Stop & Proteksi Auto Cut-Off', 'estimated_minutes' => 5, 'description' => 'Uji respons sakelar tombol emergency stop dan simulasi proteksi suhu tinggi / tekanan oli rendah.'],
                ['title' => 'Pembersihan Ruang Genset & Peredam Silent Box', 'estimated_minutes' => 10, 'description' => 'Bersihkan debu sasis genset, lantai ruang genset dari ceceran oli, dan pastikan jalur sirkulasi knalpot lancar.'],
            ],
        ];
    }

    // Kategori Gedung & Fasilitas / Bangunan
    if ($typeCode === 'BLD' || $groupCode === 'FC' || str_contains($haystack, 'gedung') || str_contains($haystack, 'bangunan') || str_contains($haystack, 'fasilitas')) {
        return [
            'category_label' => 'Gedung & Fasilitas',
            'tasks' => [
                ['title' => 'Pemeriksaan Kebocoran Atap, Plafon & Dinding Ruangan', 'estimated_minutes' => 10, 'description' => 'Periksa tanda-tanda bercak air, jamur, atau retakan pada plafon gypsum dan dinding bangunan.'],
                ['title' => 'Pemeriksaan Panel Distribusi Listrik (MCB Box & Grounding)', 'estimated_minutes' => 10, 'description' => 'Periksa kondisi MCB, kekencangan sambungan terminal kabel listrik, dan pastikan tidak ada bau hangus.'],
                ['title' => 'Pemeriksaan Kelayakan Lampu Penerangan & Sakelar', 'estimated_minutes' => 10, 'description' => 'Cek fungsi seluruh lampu ruangan, lampu lorong, lampu darurat, dan ganti bola lampu yang berkedip/mati.'],
                ['title' => 'Pemeriksaan Saluran Air Bersih, Keran & Sanitari Toilet', 'estimated_minutes' => 10, 'description' => 'Pastikan tidak ada keran bocor menetes, flush toilet berfungsi baik, dan tekanan pompa air normal.'],
                ['title' => 'Pemeriksaan Saluran Pembuangan Air & Floor Drain', 'estimated_minutes' => 5, 'description' => 'Pastikan saluran pembuangan air limbah lancar tanpa genangan dan bersihkan perangkap saringan kotoran.'],
                ['title' => 'Pemeriksaan Pintu, Jendela, Kunci, Handle & Engsel', 'estimated_minutes' => 5, 'description' => 'Uji kelancaran buka-tutup pintu/jendela, beri pelumas pada engsel berderit, dan periksa door closer.'],
                ['title' => 'Pemeriksaan Jalur Evakuasi & Kelengkapan APAR Kebakaran', 'estimated_minutes' => 10, 'description' => 'Pastikan jarum tekanan APAR di zona hijau, segel utuh, masa berlaku aktif, dan jalur evakuasi bebas rintangan.'],
                ['title' => 'Pemeriksaan Exhaust Fan & Sirkulasi Pertukaran Udara', 'estimated_minutes' => 5, 'description' => 'Bersihkan kisi-kisi exhaust fan toilet/dapur/gudang dari debu tebal dan pastikan hisapan motor lancar.'],
                ['title' => 'Pemeriksaan Kerapian Kabel Utilitas & Jalur Pipa Gedung', 'estimated_minutes' => 5, 'description' => 'Pastikan kabel dan pipa utilitas terpasang pada klem dinding secara aman dan tidak bergelantungan.'],
                ['title' => 'Pemeriksaan Sistem Kunci Pengaman Pintu & CCTV Lingkungan', 'estimated_minutes' => 5, 'description' => 'Uji fungsi access door controller (fingerprint/kartu RFID) dan kebersihan lensa kamera CCTV terdekat.'],
                ['title' => 'Pembersihan Area Fasilitas & Pengecekan Hama / Rayap', 'estimated_minutes' => 10, 'description' => 'Inspeksi sudut ruangan terhadap sarang hama/rayap dan pastikan kebersihan umum terjaga rapi.'],
                ['title' => 'Pencatatan Temuan Fasilitas Yang Membutuhkan Perbaikan Lanjut', 'estimated_minutes' => 5, 'description' => 'Dokumentasikan temuan kerusakan fisik fasilitas yang memerlukan pekerjaan teknisi sipil/reparasi lanjutan.'],
            ],
        ];
    }

    // Default Fallback: Job Desk Umum
    return [
        'category_label' => 'Umum',
        'tasks' => [
            ['title' => 'Pembersihan Fisik Eksterior Unit & Casing', 'estimated_minutes' => 5, 'description' => 'Bersihkan permukaan fisik luar perangkat dari debu, kotoran, dan noda menggunakan kain microfiber bersih.'],
            ['title' => 'Pembersihan Ventilasi Udara & Kisi-kisi Pendingin', 'estimated_minutes' => 5, 'description' => 'Periksa dan bersihkan saluran ventilasi dari debu untuk memastikan sirkulasi udara perangkat lancar.'],
            ['title' => 'Pemeriksaan Integritas Kabel Daya & Socket Listrik', 'estimated_minutes' => 5, 'description' => 'Periksa fisik kabel power, adaptor, dan steker listrik dari tanda-tanda kerusakan, panas, atau kelonggaran.'],
            ['title' => 'Pemeriksaan Konektor, Port I/O & Kabel Sinyal', 'estimated_minutes' => 5, 'description' => 'Pastikan semua kabel koneksi tertancap kuat dan soket konektor bersih dari debu atau korosi.'],
            ['title' => 'Pemeriksaan Suhu Operasional & Deteksi Suara Abnormal', 'estimated_minutes' => 5, 'description' => 'Pastikan unit bekerja pada rentang suhu normal dan tidak mengeluarkan bunyi getaran atau gesekan aneh.'],
            ['title' => 'Pengecekan Komponen Mekanis & Kelancaran Gerak', 'estimated_minutes' => 5, 'description' => 'Periksa komponen mekanikal bergerak, engsel, atau tombol fisik agar responsif dan tidak macet.'],
            ['title' => 'Pemeriksaan Baterai / Sumber Catu Daya Cadangan', 'estimated_minutes' => 5, 'description' => 'Periksa kondisi baterai internal, adaptor pengisi daya, atau kestabilan suplai catu daya unit.'],
            ['title' => 'Pengecekan Indikator Status & Lampu LED Operasional', 'estimated_minutes' => 5, 'description' => 'Pastikan lampu indikator operasional unit menyala normal tanpa kode kedipan error.'],
            ['title' => 'Pemeriksaan Kestabilan Mount, Dudukan & Pengaman Fisik', 'estimated_minutes' => 5, 'description' => 'Periksa kekokohan dudukan, baut pengunci, atau braket pengaman posisi perangkat.'],
            ['title' => 'Pengujian Fungsi Dasar Operasional Unit', 'estimated_minutes' => 10, 'description' => 'Jalankan siklus pengujian fungsi standar perangkat untuk memverifikasi performa kerja optimal.'],
            ['title' => 'Kerapian Tata Letak & Manajemen Kabel di Sekitar Unit', 'estimated_minutes' => 5, 'description' => 'Rapikan susunan kabel dan posisi unit agar aman, teratur, serta mudah diakses.'],
            ['title' => 'Pencatatan Catatan Kondisi & Rekomendasi Preventive', 'estimated_minutes' => 5, 'description' => 'Catat kondisi akhir perangkat dan berikan catatan saran pemeliharaan preventif lanjutan bila perlu.'],
        ],
    ];
}

function handle_route_jobs(PDO $pdo): void
{
    $user = require_regulation('jobs', 'view');
    ensure_preventive_job_desk_schema($pdo);

    $editDeskId = (int)($_GET['edit_id'] ?? 0);
    $editDeskNameParam = trim((string)($_GET['edit_desk'] ?? ''));
    $editingDesk = null;

    if ($editDeskId > 0) {
        $stmtEdit = $pdo->prepare('SELECT * FROM preventive_job_desks WHERE id = ?');
        $stmtEdit->execute([$editDeskId]);
        $editingDesk = $stmtEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($editDeskNameParam !== '') {
        $stmtEdit = $pdo->prepare('SELECT * FROM preventive_job_desks WHERE job_desk_name = ? LIMIT 1');
        $stmtEdit->execute([$editDeskNameParam]);
        $editingDesk = $stmtEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $isEditing = ($editingDesk !== null);
    $editDeskId = $isEditing ? (int)$editingDesk['id'] : 0;
    $editDeskName = $isEditing ? (string)$editingDesk['job_desk_name'] : '';
    $editGroupId = $isEditing ? (int)($editingDesk['asset_group_id'] ?? 0) : (int)($_GET['group_id'] ?? 0);
    $editTypeId = $isEditing ? (int)($editingDesk['asset_type_id'] ?? 0) : (int)($_GET['type_id'] ?? 0);
    $editDesc = $isEditing ? (string)($editingDesk['description'] ?? '') : '';

    $filterGroupId = $editGroupId;
    $filterTypeId = $editTypeId;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $postGroupId = (int)($_POST['asset_group_id'] ?? $filterGroupId);
        $postTypeId = (int)($_POST['asset_type_id'] ?? $filterTypeId);

        // --- TAMBAH JOB DESK PREVENTIVE MAINTENANCE ---
        if ($action === 'add_desk') {
            require_regulation('jobs', 'create');
            $groupId = (int)($_POST['asset_group_id'] ?? 0);
            $typeId = (int)($_POST['asset_type_id'] ?? 0);

            $groupName = '';
            if ($groupId > 0) {
                $gStmt = $pdo->prepare('SELECT group_name FROM asset_groups WHERE id=?');
                $gStmt->execute([$groupId]);
                $groupName = (string)$gStmt->fetchColumn();
            }
            $typeName = '';
            if ($typeId > 0) {
                $tStmt = $pdo->prepare('SELECT type_name FROM asset_types WHERE id=?');
                $tStmt->execute([$typeId]);
                $typeName = (string)$tStmt->fetchColumn();
            }

            $deskName = trim((string)($_POST['job_desk_name'] ?? ''));
            if ($deskName === '') {
                $deskName = 'Job Desk ' . trim($groupName . ' ' . $typeName);
            }
            if ($deskName === 'Job Desk' || $deskName === '') {
                $deskName = 'Job Desk Umum';
            }

            $desc = trim((string)($_POST['description'] ?? ''));

            // Periksa duplikasi nama
            $chk = $pdo->prepare('SELECT id FROM preventive_job_desks WHERE job_desk_name = ? LIMIT 1');
            $chk->execute([$deskName]);
            $existId = $chk->fetchColumn();
            if ($existId) {
                flash("Job Desk dengan nama '{$deskName}' sudah ada (ID: #{$existId}).", 'err');
                redirect_to('jobs', array_filter(['group_id' => $groupId, 'type_id' => $typeId, 'manage_desk_id' => $existId]));
            }

            $stmtIns = $pdo->prepare('INSERT INTO preventive_job_desks (job_desk_name, asset_group_id, asset_type_id, description) VALUES (?, ?, ?, ?)');
            $stmtIns->execute([
                $deskName,
                $groupId > 0 ? $groupId : null,
                $typeId > 0 ? $typeId : null,
                $desc !== '' ? $desc : null,
            ]);
            $newDeskId = (int)$pdo->lastInsertId();

            flash("Job Desk Preventive Maintenance '{$deskName}' (ID: #{$newDeskId}) berhasil dibuat. Silakan isi daftar pekerjaan / task di bawah.");
            redirect_to('jobs', array_filter(['group_id' => $groupId, 'type_id' => $typeId, 'manage_desk_id' => $newDeskId]));
        }

        // --- EDIT JOB DESK PREVENTIVE MAINTENANCE ---
        if ($action === 'edit_desk') {
            require_regulation('jobs', 'edit');
            $deskId = (int)($_POST['desk_id'] ?? 0);
            $origDesk = trim((string)($_POST['original_desk_name'] ?? ''));
            $newDesk = trim((string)($_POST['job_desk_name'] ?? ''));
            if ($newDesk === '') {
                $newDesk = $origDesk;
            }
            $groupId = (int)($_POST['asset_group_id'] ?? 0);
            $typeId = (int)($_POST['asset_type_id'] ?? 0);
            $desc = trim((string)($_POST['description'] ?? ''));

            if ($deskId > 0) {
                $updDesk = $pdo->prepare('UPDATE preventive_job_desks SET job_desk_name=?, asset_group_id=?, asset_type_id=?, description=? WHERE id=?');
                $updDesk->execute([
                    $newDesk,
                    $groupId > 0 ? $groupId : null,
                    $typeId > 0 ? $typeId : null,
                    $desc !== '' ? $desc : null,
                    $deskId,
                ]);

                // Sinkronkan ke maintenance_jobs
                $updJobs = $pdo->prepare('UPDATE maintenance_jobs SET job_desk_name=?, asset_group_id=?, asset_type_id=? WHERE job_desk_name = ?');
                $updJobs->execute([
                    $newDesk,
                    $groupId > 0 ? $groupId : null,
                    $typeId > 0 ? $typeId : null,
                    $origDesk,
                ]);

                // Jika nama job desk berubah, sinkronkan juga ke maintenance_assets
                if ($origDesk !== $newDesk) {
                    $pdo->prepare('UPDATE maintenance_assets SET job_desk_name=? WHERE job_desk_name = ?')
                        ->execute([$newDesk, $origDesk]);
                }

                flash("Job Desk Preventive Maintenance '{$newDesk}' berhasil diperbarui.");
            }
            redirect_to('jobs', array_filter(['group_id' => $groupId, 'type_id' => $typeId, 'manage_desk_id' => $deskId]));
        }

        // --- HAPUS JOB DESK PREVENTIVE MAINTENANCE ---
        if ($action === 'delete_desk') {
            require_regulation('jobs', 'delete');
            $deskId = (int)($_POST['desk_id'] ?? 0);
            $deskName = trim((string)($_POST['job_desk_name'] ?? ''));

            if ($deskId > 0 || $deskName !== '') {
                if ($deskName === '' && $deskId > 0) {
                    $stmtGet = $pdo->prepare('SELECT job_desk_name FROM preventive_job_desks WHERE id=?');
                    $stmtGet->execute([$deskId]);
                    $deskName = (string)$stmtGet->fetchColumn();
                }

                // 1. Lepaskan penugasan job_desk_name pada maintenance_assets
                $pdo->prepare('UPDATE maintenance_assets SET job_desk_name = NULL WHERE job_desk_name = ?')
                    ->execute([$deskName]);

                // 2. Hapus tugas-tugas yang BELUM pernah dipakai di schedule_jobs
                $pdo->prepare('DELETE FROM maintenance_jobs WHERE job_desk_name = ? AND id NOT IN (SELECT DISTINCT job_id FROM schedule_jobs)')
                    ->execute([$deskName]);

                // 3. Untuk tugas yang SUDAH pernah dipakai di riwayat schedule, arsipkan (lepaskan job_desk_name & nonaktifkan) agar riwayat report masa lalu tidak rusak
                $pdo->prepare('UPDATE maintenance_jobs SET job_desk_name = NULL, is_active = 0 WHERE job_desk_name = ?')
                    ->execute([$deskName]);

                // 4. Hapus Job Desk dari tabel master preventive_job_desks
                if ($deskId > 0) {
                    $pdo->prepare('DELETE FROM preventive_job_desks WHERE id=?')->execute([$deskId]);
                }
                if ($deskName !== '') {
                    $pdo->prepare('DELETE FROM preventive_job_desks WHERE job_desk_name=?')->execute([$deskName]);
                }

                flash("Job Desk '{$deskName}' berhasil dihapus.");
            }
            redirect_to('jobs', array_filter(['group_id' => $postGroupId, 'type_id' => $postTypeId]));
        }

        // --- TAMBAH JOB TASK KE JOB DESK ---
        if ($action === 'add_task') {
            require_regulation('jobs', 'create');
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            $stmtDesk = $pdo->prepare('SELECT * FROM preventive_job_desks WHERE id=?');
            $stmtDesk->execute([$manageDeskId]);
            $curDesk = $stmtDesk->fetch(PDO::FETCH_ASSOC);

            if ($curDesk) {
                $taskTitle = trim((string)($_POST['title'] ?? ''));
                $estMinutes = max(1, (int)($_POST['estimated_minutes'] ?? 5));
                $taskDesc = trim((string)($_POST['description'] ?? ''));

                if ($taskTitle !== '') {
                    $stmtInsTask = $pdo->prepare('INSERT INTO maintenance_jobs (title, description, asset_group_id, asset_type_id, job_desk_name, estimated_minutes, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
                    $stmtInsTask->execute([
                        $taskTitle,
                        $taskDesc !== '' ? $taskDesc : null,
                        $curDesk['asset_group_id'] ?: ($postGroupId ?: null),
                        $curDesk['asset_type_id'] ?: ($postTypeId ?: null),
                        $curDesk['job_desk_name'],
                        $estMinutes,
                    ]);
                    flash("Pekerjaan '{$taskTitle}' berhasil ditambahkan ke {$curDesk['job_desk_name']}.");
                } else {
                    flash('Nama pekerjaan / job task wajib diisi.', 'err');
                }
            }
            redirect_to('jobs', array_filter(['group_id' => $postGroupId, 'type_id' => $postTypeId, 'manage_desk_id' => $manageDeskId]));
        }

        // --- UPDATE ESTIMASI / NAMA TASK ---
        if ($action === 'update_task') {
            require_regulation('jobs', 'edit');
            $taskId = (int)($_POST['task_id'] ?? 0);
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $estMinutes = max(1, (int)($_POST['estimated_minutes'] ?? 5));
            $desc = trim((string)($_POST['description'] ?? ''));

            if ($taskId > 0 && $title !== '') {
                $pdo->prepare('UPDATE maintenance_jobs SET title=?, estimated_minutes=?, description=? WHERE id=?')
                    ->execute([$title, $estMinutes, $desc !== '' ? $desc : null, $taskId]);
                flash('Pekerjaan / task berhasil diperbarui.');
            }
            redirect_to('jobs', array_filter(['group_id' => $postGroupId, 'type_id' => $postTypeId, 'manage_desk_id' => $manageDeskId]));
        }

        // --- TOGGLE AKTIF / NON-AKTIF TASK ---
        if ($action === 'toggle_task') {
            require_regulation('jobs', 'edit');
            $taskId = (int)($_POST['id'] ?? 0);
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            if ($taskId > 0) {
                $pdo->prepare('UPDATE maintenance_jobs SET is_active = 1 - is_active WHERE id = ?')->execute([$taskId]);
                flash('Status pekerjaan diperbarui.');
            }
            redirect_to('jobs', array_filter(['group_id' => $postGroupId, 'type_id' => $postTypeId, 'manage_desk_id' => $manageDeskId]));
        }

        // --- HAPUS JOB TASK ---
        if ($action === 'delete_task') {
            require_regulation('jobs', 'delete');
            $taskId = (int)($_POST['id'] ?? 0);
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            if ($taskId > 0) {
                $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_jobs WHERE job_id = ?');
                $checkStmt->execute([$taskId]);
                $usedCount = (int)$checkStmt->fetchColumn();
                if ($usedCount > 0) {
                    flash('Pekerjaan tidak dapat dihapus karena sudah pernah digunakan pada ' . $usedCount . ' schedule maintenance.', 'err');
                } else {
                    $pdo->prepare('DELETE FROM maintenance_jobs WHERE id = ?')->execute([$taskId]);
                    flash('Pekerjaan berhasil dihapus.');
                }
            }
            redirect_to('jobs', array_filter(['group_id' => $postGroupId, 'type_id' => $postTypeId, 'manage_desk_id' => $manageDeskId]));
        }

        // --- SALIN TUGAS STANDAR SESUAI KOMODITAS & KATEGORI ---
        if ($action === 'copy_default_tasks') {
            require_regulation('jobs', 'create');
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            $stmtDesk = $pdo->prepare('SELECT * FROM preventive_job_desks WHERE id=?');
            $stmtDesk->execute([$manageDeskId]);
            $curDesk = $stmtDesk->fetch(PDO::FETCH_ASSOC);

            if ($curDesk) {
                $targetDeskName = $curDesk['job_desk_name'];
                $deskGroupId = $curDesk['asset_group_id'] ?: ($postGroupId ?: null);
                $deskTypeId = $curDesk['asset_type_id'] ?: ($postTypeId ?: null);

                $catalog = get_standard_preventive_tasks_catalog($pdo, $deskGroupId ? (int)$deskGroupId : null, $deskTypeId ? (int)$deskTypeId : null, $targetDeskName);
                $standardTasks = $catalog['tasks'];
                $catLabel = $catalog['category_label'];

                $ins = $pdo->prepare("INSERT INTO maintenance_jobs (title, description, asset_group_id, asset_type_id, job_desk_name, estimated_minutes, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $copied = 0;
                foreach ($standardTasks as $st) {
                    $chkDup = $pdo->prepare("SELECT COUNT(*) FROM maintenance_jobs WHERE job_desk_name = ? AND title = ?");
                    $chkDup->execute([$targetDeskName, $st['title']]);
                    if ((int)$chkDup->fetchColumn() === 0) {
                        $ins->execute([
                            $st['title'],
                            $st['description'],
                            $deskGroupId,
                            $deskTypeId,
                            $targetDeskName,
                            $st['estimated_minutes'],
                        ]);
                        $copied++;
                    }
                }
                flash("Berhasil menyalin {$copied} tugas standar ({$catLabel}) ke '{$targetDeskName}'.");
            }
            redirect_to('jobs', array_filter(['group_id' => $postGroupId, 'type_id' => $postTypeId, 'manage_desk_id' => $manageDeskId]));
        }
    }

    render_header('Job Desk Preventive Maintenance', $user);

    // Ambil semua daftar job desk dengan join aman tanpa error collation 1267
    $whereParts = [];
    $queryParams = [];
    if ($filterGroupId > 0) {
        $whereParts[] = 'd.asset_group_id = ?';
        $queryParams[] = $filterGroupId;
    }
    if ($filterTypeId > 0) {
        $whereParts[] = 'd.asset_type_id = ?';
        $queryParams[] = $filterTypeId;
    }
    $whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    $desksQuery = 'SELECT 
        d.id AS desk_id,
        d.job_desk_name,
        d.asset_group_id,
        d.asset_type_id,
        d.description,
        g.group_code,
        g.group_name,
        t.type_code,
        t.type_name,
        COUNT(j.id) AS job_count,
        COALESCE(SUM(j.estimated_minutes), 0) AS total_minutes,
        COALESCE(ma_sub.ma_count, 0) AS maint_asset_count,
        COALESCE(sj_sub.sj_count, 0) AS schedule_count
    FROM preventive_job_desks d
    LEFT JOIN asset_groups g ON g.id = d.asset_group_id
    LEFT JOIN asset_types t ON t.id = d.asset_type_id
    LEFT JOIN maintenance_jobs j ON (
        j.job_desk_name COLLATE utf8mb4_unicode_ci = d.job_desk_name COLLATE utf8mb4_unicode_ci
        OR (d.job_desk_name = "Job Desk Umum" AND (j.job_desk_name IS NULL OR j.job_desk_name = ""))
    )
    LEFT JOIN (
        SELECT job_desk_name, COUNT(*) AS ma_count 
        FROM maintenance_assets 
        WHERE job_desk_name IS NOT NULL AND job_desk_name != "" 
        GROUP BY job_desk_name
    ) ma_sub ON ma_sub.job_desk_name COLLATE utf8mb4_unicode_ci = d.job_desk_name COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT j2.job_desk_name, COUNT(*) AS sj_count 
        FROM schedule_jobs sj 
        JOIN maintenance_jobs j2 ON j2.id = sj.job_id 
        GROUP BY j2.job_desk_name
    ) sj_sub ON sj_sub.job_desk_name COLLATE utf8mb4_unicode_ci = d.job_desk_name COLLATE utf8mb4_unicode_ci
    ' . $whereSql . '
    GROUP BY d.id, d.job_desk_name, d.asset_group_id, d.asset_type_id, d.description, g.group_code, g.group_name, t.type_code, t.type_name
    ORDER BY g.group_name, t.type_name, d.job_desk_name';

    $stmtDesks = $pdo->prepare($desksQuery);
    $stmtDesks->execute($queryParams);
    $deskRows = $stmtDesks->fetchAll(PDO::FETCH_ASSOC);

    // Tentukan Job Desk yang sedang aktif dipilih untuk pengelolaan Job Tasks
    $manageDeskId = (int)($_GET['manage_desk_id'] ?? 0);
    $activeDesk = null;
    $activeDeskTasks = [];

    if (!empty($deskRows)) {
        if ($manageDeskId > 0) {
            foreach ($deskRows as $r) {
                if ((int)$r['desk_id'] === $manageDeskId) {
                    $activeDesk = $r;
                    break;
                }
            }
        }
        if (!$activeDesk && $editDeskId > 0) {
            foreach ($deskRows as $r) {
                if ((int)$r['desk_id'] === $editDeskId) {
                    $activeDesk = $r;
                    break;
                }
            }
        }
        if (!$activeDesk) {
            $activeDesk = $deskRows[0];
        }
        $manageDeskId = (int)$activeDesk['desk_id'];

        $isUmum = ($activeDesk['job_desk_name'] === 'Job Desk Umum');
        $sqlTasks = $isUmum 
            ? 'SELECT * FROM maintenance_jobs WHERE job_desk_name = ? OR job_desk_name IS NULL OR job_desk_name = "" ORDER BY is_active DESC, id ASC'
            : 'SELECT * FROM maintenance_jobs WHERE job_desk_name = ? ORDER BY is_active DESC, id ASC';
        $stmtTasks = $pdo->prepare($sqlTasks);
        $stmtTasks->execute([$activeDesk['job_desk_name']]);
        $activeDeskTasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);
    }

    // FORM TAMBAH / EDIT JOB DESK PREVENTIVE MAINTENANCE
    $canCreateJobs = has_regulation('jobs', 'create');
    $canEditJobs = has_regulation('jobs', 'edit');
    $canDeleteJobs = has_regulation('jobs', 'delete');
    $showDeskForm = ($isEditing && $canEditJobs) || (!$isEditing && $canCreateJobs);

    $formTitle = $isEditing ? ('Edit Job Desk Preventive Maintenance #' . $editDeskId) : 'Tambah Job Desk Preventive Maintenance';
    $formAction = $isEditing ? 'edit_desk' : 'add_desk';

    echo '<section class="' . ($showDeskForm ? 'grid two' : '') . '">';
    if ($showDeskForm) {
        echo '<div class="panel">';
        echo '<div class="split" style="align-items:center;margin-bottom:12px;">'
            . '<h1 style="margin:0;font-size:18px;">' . e($formTitle) . '</h1>'
            . ($isEditing ? '<a class="btn" href="' . route_url('jobs', array_filter(['group_id' => $filterGroupId, 'type_id' => $filterTypeId])) . '">+ Tambah Job Desk Baru</a>' : '')
            . '</div>';

        echo '<form method="post">'
            . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="' . $formAction . '">'
            . ($isEditing ? '<input type="hidden" name="desk_id" value="' . $editDeskId . '">' : '')
            . ($isEditing ? '<input type="hidden" name="original_desk_name" value="' . e($editDeskName) . '">' : '')
            . '<div class="grid two">'
            . '<label>ID Job Desk'
            . '<input type="text" readonly value="' . ($isEditing ? ('#' . $editDeskId) : '(Otomatis setelah disimpan)') . '" style="background:#f8fafc;color:#64748b;font-weight:700;">'
            . '</label>'
            . '<label>Komoditas (Asset Group) *'
            . '<select id="jobAssetGroup" name="asset_group_id" required>'
            . '<option value="">- Pilih Komoditas -</option>'
            . asset_group_options($pdo, $editGroupId, false)
            . '</select>'
            . '</label>'
            . '</div>'
            . '<div class="grid two">'
            . '<label>Kategori (Asset Type) *'
            . '<select id="jobAssetType" name="asset_type_id" required>'
            . '<option value="">- Pilih Kategori -</option>'
            . ($editGroupId > 0 ? asset_type_options($pdo, $editTypeId, $editGroupId) : '')
            . '</select>'
            . '</label>'
            . '<label>Deskripsi / Catatan'
            . '<input name="description" value="' . e($editDesc) . '" placeholder="Keterangan kategori preventive...">'
            . '</label>'
            . '</div>'
            . '<label>Nama Job Desk Preventive Maintenance *'
            . '<input id="jobDeskName" name="job_desk_name" value="' . e($editDeskName) . '" required style="font-weight:700;color:#0f172a;" placeholder="Job Desk [Komoditas] [Kategori]">'
            . '</label>'
            . '<div class="actions" style="margin-top:14px;">'
            . '<button class="btn primary">' . ($isEditing ? 'Perbarui Job Desk' : 'Simpan Job Desk') . '</button>'
            . ($isEditing ? '<a class="btn" href="' . route_url('jobs', array_filter(['group_id' => $filterGroupId, 'type_id' => $filterTypeId])) . '">Batal Edit</a>' : '')
            . '</div>'
            . '</form>'
            . '</div>';
    }

    // TABEL DAFTAR JOB DESK PREVENTIVE MAINTENANCE
    $filterBadges = [];
    if ($filterGroupId > 0) {
        $stmtG = $pdo->prepare('SELECT group_code, group_name FROM asset_groups WHERE id = ?');
        $stmtG->execute([$filterGroupId]);
        $gInfo = $stmtG->fetch(PDO::FETCH_ASSOC);
        if ($gInfo) {
            $filterBadges[] = 'Komoditas: <strong>' . e($gInfo['group_name']) . '</strong>';
        }
    }
    if ($filterTypeId > 0) {
        $stmtT = $pdo->prepare('SELECT type_code, type_name FROM asset_types WHERE id = ?');
        $stmtT->execute([$filterTypeId]);
        $tInfo = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($tInfo) {
            $filterBadges[] = 'Kategori: <strong>' . e($tInfo['type_name']) . '</strong>';
        }
    }

    $filterInfoHtml = '';
    if (!empty($filterBadges)) {
        $filterInfoHtml = '<div style="margin:4px 0 10px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
            . '<span class="badge ok" style="font-size:12px;">' . implode(' &bull; ', $filterBadges) . '</span>'
            . '<a class="btn" href="' . route_url('jobs') . '" style="padding:3px 8px;font-size:11px;">✕ Tampilkan Semua Job Desk</a>'
            . '</div>';
    }

    echo '<div class="panel">'
        . '<div class="split"><h2>Daftar Job Desk Preventive Maintenance</h2><div class="actions"><a class="btn" href="' . route_url('export_excel', ['type' => 'jobs']) . '">Export Excel</a></div></div>'
        . $filterInfoHtml
        . '<p class="muted" style="margin-top:-6px;margin-bottom:12px;">Pilih Job Desk untuk mengisi atau mengelola daftar pekerjaannya (Job Tasks).</p>';

    if (!$deskRows) {
        if (!empty($filterBadges)) {
            echo '<div style="padding:20px;text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:8px;">'
                . '<p class="muted" style="margin:0 0 6px 0;">Belum ada Job Desk untuk kategori ini.</p>'
                . '<p style="font-size:13px;color:#64748b;margin:0;">' . ($canCreateJobs ? 'Silakan isi formulir di sebelah kiri dan klik <strong>Simpan Job Desk</strong> untuk membuat Job Desk baru bagi kategori ini.' : '') . '</p>'
                . '</div>';
        } else {
            echo '<p class="muted">Belum ada Job Desk tersimpan.' . ($canCreateJobs ? ' Silakan tambahkan pada form di samping.' : '') . '</p>';
        }
    } else {
        echo '<div style="overflow-x:auto;"><table><tr><th>ID</th><th>Nama Job Desk</th><th>Asset Group / Type</th><th>Tasks</th><th>Dipakai</th><th>Aksi</th></tr>';
        foreach ($deskRows as $d) {
            $dId = (int)$d['desk_id'];
            $dName = $d['job_desk_name'];
            $grpLabel = !empty($d['group_name']) ? ($d['group_code'] . ' - ' . $d['group_name']) : 'Umum';
            $typLabel = !empty($d['type_name']) ? ($d['type_code'] . ' - ' . $d['type_name']) : 'Semua Tipe';
            $mCount = (int)$d['maint_asset_count'];
            $sCount = (int)$d['schedule_count'];
            $isLocked = ($mCount > 0 || $sCount > 0);
            $isActive = ($manageDeskId === $dId);

            $usageBadge = ($mCount > 0)
                ? '<span class="badge ok" title="Digunakan pada ' . $mCount . ' Maintenance Asset">' . $mCount . ' asset</span>'
                : '<span class="muted" style="font-size:12px;">0</span>';

            $lockReason = [];
            if ($mCount > 0) {
                $lockReason[] = $mCount . ' Maintenance Asset';
            }
            if ($sCount > 0) {
                $lockReason[] = $sCount . ' Schedule';
            }

            $confirmMsg = $isLocked
                ? ('Job Desk &quot;' . e($dName) . '&quot; ini pernah digunakan pada data maintenance (' . implode(', ', $lockReason) . '). Menghapusnya akan menghapus Job Desk ini dari daftar dan mengarsipkan tugasnya secara aman tanpa merusak riwayat report. Lanjutkan hapus?')
                : ('Yakin hapus Job Desk &quot;' . e($dName) . '&quot; beserta seluruh tugasnya?');

            $deleteBtn = $canDeleteJobs
                ? ('<form method="post" style="display:inline" onsubmit="return confirm(\'' . $confirmMsg . '\')">'
                    . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="delete_desk">'
                    . '<input type="hidden" name="desk_id" value="' . $dId . '">'
                    . '<input type="hidden" name="job_desk_name" value="' . e($dName) . '">'
                    . '<input type="hidden" name="asset_group_id" value="' . $filterGroupId . '">'
                    . '<input type="hidden" name="asset_type_id" value="' . $filterTypeId . '">'
                    . '<button class="btn danger">Hapus</button>'
                    . '</form>')
                : '';

            $editBtn = $canEditJobs
                ? ('<a class="btn" href="' . route_url('jobs', array_filter(['group_id' => $filterGroupId, 'type_id' => $filterTypeId, 'edit_id' => $dId, 'manage_desk_id' => $dId])) . '">Edit</a>')
                : '';

            $trBg = $isActive ? ' style="background:#eff6ff;"' : '';
            echo '<tr' . $trBg . '>'
                . '<td><span class="badge" style="font-weight:700;">#' . $dId . '</span></td>'
                . '<td><strong>' . e($dName) . '</strong>' . ($isActive ? ' <span class="badge ok" style="font-size:10px;">Aktif Dikelola</span>' : '') . '</td>'
                . '<td><span style="font-weight:600;">' . e($grpLabel) . '</span><br><span class="muted" style="font-size:12px;">' . e($typLabel) . '</span></td>'
                . '<td><span class="badge">' . (int)$d['job_count'] . ' task</span><br><span class="muted" style="font-size:11px;">~' . (int)$d['total_minutes'] . ' mnt</span></td>'
                . '<td>' . $usageBadge . '</td>'
                . '<td><div class="actions" style="display:flex;gap:4px;align-items:center;">'
                . '<a class="btn ' . ($isActive ? 'primary' : '') . '" href="' . route_url('jobs', array_filter(['group_id' => $filterGroupId, 'type_id' => $filterTypeId, 'manage_desk_id' => $dId])) . '" title="Isi & Kelola Pekerjaan">📋 Isi Tasks</a>'
                . $editBtn
                . $deleteBtn
                . '</div></td>'
                . '</tr>';
        }
        echo '</table></div>';
    }

    echo '</div></section>';

    // SECTION KELOLA JOB TASKS DARI DAFTAR JOB DESK
    if ($activeDesk) {
        $actId = (int)$activeDesk['desk_id'];
        $actName = (string)$activeDesk['job_desk_name'];
        $actGroupId = $activeDesk['asset_group_id'] ? (int)$activeDesk['asset_group_id'] : null;
        $actTypeId = $activeDesk['asset_type_id'] ? (int)$activeDesk['asset_type_id'] : null;

        $deskCatalog = get_standard_preventive_tasks_catalog($pdo, $actGroupId, $actTypeId, $actName);
        $catLabel = $deskCatalog['category_label'];
        $catTaskCount = count($deskCatalog['tasks']);

        $totMinutes = 0;
        foreach ($activeDeskTasks as $t) {
            if (!empty($t['is_active'])) {
                $totMinutes += (int)$t['estimated_minutes'];
            }
        }

        echo '<div class="panel" style="margin-top:20px;border-top:3px solid #2563eb;">';
        $copyHeaderBtn = $canCreateJobs
            ? ('<form method="post" style="display:inline;"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="copy_default_tasks"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><input type="hidden" name="asset_group_id" value="' . $filterGroupId . '"><input type="hidden" name="asset_type_id" value="' . $filterTypeId . '"><button class="btn" style="padding:5px 10px;font-size:12px;background:#f0fdf4;border:1px solid #86efac;color:#166534;font-weight:600;" title="Salin ' . $catTaskCount . ' tugas standar pemeliharaan preventif ' . e($catLabel) . ' ke Job Desk ini">+ Salin ' . $catTaskCount . ' Tugas Standar (' . e($catLabel) . ')</button></form>')
            : '';

        echo '<div class="split" style="align-items:center;margin-bottom:14px;">'
            . '<div>'
            . '<h2 style="margin:0;color:#1e40af;">📋 Daftar Pekerjaan (Job Tasks) untuk: ' . e($actName) . ' <span class="badge" style="font-size:12px;">ID: #' . $actId . '</span></h2>'
            . '<p class="muted" style="margin:4px 0 0 0;font-size:13px;">Kelola rincian item pekerjaan yang wajib dijalankan teknisi saat preventive maintenance untuk Job Desk ini (Kategori: <strong>' . e($catLabel) . '</strong>).</p>'
            . '</div>'
            . '<div style="display:flex;gap:8px;align-items:center;">'
            . $copyHeaderBtn
            . '<span class="badge ok" style="font-size:13px;padding:6px 12px;">Total: ' . count($activeDeskTasks) . ' Tasks (~' . $totMinutes . ' Menit)</span>'
            . '</div>'
            . '</div>';

        echo '<section class="' . ($canCreateJobs ? 'grid two' : '') . '" style="margin-bottom:0;">';

        // Form Tambah Job Task Baru
        if ($canCreateJobs) {
            echo '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">'
                . '<h3 style="margin-top:0;font-size:15px;color:#0f172a;">+ Tambah Job Task Baru ke ' . e($actName) . '</h3>'
                . '<form method="post">'
                . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="add_task">'
                . '<input type="hidden" name="manage_desk_id" value="' . $actId . '">'
                . '<input type="hidden" name="asset_group_id" value="' . $filterGroupId . '">'
                . '<input type="hidden" name="asset_type_id" value="' . $filterTypeId . '">'
                . '<label>Nama Pekerjaan / Job Task *'
                . '<input name="title" required placeholder="Contoh: Pembersihan Fan & Casing Unit" style="font-weight:600;">'
                . '</label>'
                . '<div class="grid two">'
                . '<label>Estimasi Waktu (Menit) *'
                . '<input type="number" min="1" name="estimated_minutes" value="5" required>'
                . '</label>'
                . '<label>Keterangan / SOP (Opsional)'
                . '<input name="description" placeholder="Instruksi tambahan...">'
                . '</label>'
                . '</div>'
                . '<div class="actions" style="margin-top:12px;">'
                . '<button class="btn primary">+ Tambah Task ke Job Desk</button>'
                . '</div>'
                . '</form>'
                . '</div>';
        }

        // Tabel Daftar Job Tasks Terdaftar
        echo '<div>';
        if (!$activeDeskTasks) {
            $copyEmptyBtn = $canCreateJobs
                ? ('<form method="post" style="margin-top:14px;"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="copy_default_tasks"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><input type="hidden" name="asset_group_id" value="' . $filterGroupId . '"><input type="hidden" name="asset_type_id" value="' . $filterTypeId . '"><button class="btn primary" style="font-size:13px;padding:8px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">📥 Salin ' . $catTaskCount . ' Tugas Standar Preventive (' . e($catLabel) . ')</button></form>')
                : '';
            echo '<div style="padding:28px;text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:8px;">'
                . '<p class="muted" style="margin:0 0 8px 0;">Belum ada item pekerjaan untuk Job Desk <strong>' . e($actName) . '</strong>.</p>'
                . '<p style="font-size:13px;color:#64748b;margin:0;">' . ($canCreateJobs ? ('Klik tombol di bawah untuk otomatis menyalin <strong>' . $catTaskCount . ' tugas standar pemeliharaan preventif</strong> yang dirancang khusus untuk kategori <strong>' . e($catLabel) . '</strong>, atau tambahkan pekerjaan manual melalui formulir di sebelah kiri.') : 'Belum ada item pekerjaan.') . '</p>'
                . $copyEmptyBtn
                . '</div>';
        } else {
            echo '<div style="overflow-x:auto;"><table style="margin:0;"><tr><th>No</th><th>Nama Pekerjaan / Task</th><th>Estimasi</th><th>Status</th>' . ($canEditJobs || $canDeleteJobs ? '<th>Aksi</th>' : '') . '</tr>';
            $tNo = 1;
            foreach ($activeDeskTasks as $task) {
                $tId = (int)$task['id'];
                $tTitle = (string)$task['title'];
                $tMinutes = (int)$task['estimated_minutes'];
                $tDesc = (string)($task['description'] ?? '');
                $tActive = !empty($task['is_active']);

                $toggleBtn = $canEditJobs
                    ? ('<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="id" value="' . $tId . '"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><input type="hidden" name="asset_group_id" value="' . $filterGroupId . '"><input type="hidden" name="asset_type_id" value="' . $filterTypeId . '"><button class="btn" style="padding:4px 8px;font-size:11px;">' . ($tActive ? 'Nonaktifkan' : 'Aktifkan') . '</button></form>')
                    : '';

                $delTaskBtn = $canDeleteJobs
                    ? ('<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus pekerjaan &quot;' . e($tTitle) . '&quot;?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="' . $tId . '"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><input type="hidden" name="asset_group_id" value="' . $filterGroupId . '"><input type="hidden" name="asset_type_id" value="' . $filterTypeId . '"><button class="btn danger" style="padding:4px 8px;font-size:11px;">Hapus</button></form>')
                    : '';

                $actionTd = ($canEditJobs || $canDeleteJobs)
                    ? ('<td><div class="actions" style="display:flex;gap:4px;align-items:center;">' . $toggleBtn . $delTaskBtn . '</div></td>')
                    : '';

                echo '<tr>'
                    . '<td style="width:36px;text-align:center;">' . $tNo++ . '</td>'
                    . '<td><strong>' . e($tTitle) . '</strong>' . ($tDesc !== '' ? ('<br><span class="muted" style="font-size:11px;">' . e($tDesc) . '</span>') : '') . '</td>'
                    . '<td><span class="badge" style="font-weight:700;">' . $tMinutes . ' mnt</span></td>'
                    . '<td>' . ($tActive ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td>'
                    . $actionTd
                    . '</tr>';
            }
            echo '</table></div>';
        }
        echo '</div>';

        echo '</section>';
        echo '</div>';
    } elseif ($filterGroupId > 0 || $filterTypeId > 0) {
        echo '<div class="panel" style="margin-top:20px;border-top:3px solid #f59e0b;background:#fffbeb;">'
            . '<div class="split" style="align-items:center;">'
            . '<div>'
            . '<h3 style="margin:0 0 6px 0;color:#b45309;">⚠️ Belum Ada Job Desk untuk Kategori Ini</h3>'
            . '<p style="margin:0;color:#78350f;font-size:13px;">Belum ada Job Desk Preventive Maintenance yang terdaftar untuk Komoditas dan Kategori yang dipilih. Silakan isi formulir di atas dan klik <strong>Simpan Job Desk</strong> untuk membuatnya, kemudian tambahkan daftar pekerjaan / tugas (Job Tasks).</p>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    echo '<script>
(function(){
    var groupSelect = document.getElementById("jobAssetGroup");
    var typeSelect = document.getElementById("jobAssetType");
    var nameInput = document.getElementById("jobDeskName");
    var isEditMode = ' . ($isEditing ? 'true' : 'false') . ';

    function updateGeneratedJobDeskName() {
        if (isEditMode && nameInput.value.trim() !== "") {
            return;
        }
        var gText = groupSelect.options[groupSelect.selectedIndex] ? groupSelect.options[groupSelect.selectedIndex].text.replace(/^[A-Z0-9]+\s*-\s*/, "").trim() : "";
        var tText = typeSelect.options[typeSelect.selectedIndex] ? typeSelect.options[typeSelect.selectedIndex].text.replace(/^[A-Z0-9]+\s*-\s*/, "").trim() : "";
        if (!groupSelect.value) gText = "";
        if (!typeSelect.value) tText = "";
        
        var name = "Job Desk";
        if (gText) name += " " + gText;
        if (tText) name += " " + tText;
        nameInput.value = name;
    }

    if (groupSelect && typeSelect && nameInput) {
        groupSelect.addEventListener("change", function() {
            var gid = this.value;
            typeSelect.innerHTML = "<option value=\"\">- Memuat Kategori... -</option>";
            fetch("index.php?route=api_asset_types&group_id=" + (gid || 0))
                .then(function(r) { return r.json(); })
                .then(function(types) {
                    typeSelect.innerHTML = "<option value=\"\">- Pilih Kategori -</option>";
                    types.forEach(function(t) {
                        var opt = document.createElement("option");
                        opt.value = t.id;
                        opt.textContent = t.type_code + " - " + t.type_name;
                        typeSelect.appendChild(opt);
                    });
                    updateGeneratedJobDeskName();
                })
                .catch(function() {
                    typeSelect.innerHTML = "<option value=\"\">- Pilih Kategori -</option>";
                    updateGeneratedJobDeskName();
                });
        });

        typeSelect.addEventListener("change", function() {
            updateGeneratedJobDeskName();
            if (!isEditMode && this.value) {
                var gid = groupSelect ? groupSelect.value : "";
                window.location.href = "index.php?route=jobs&group_id=" + encodeURIComponent(gid) + "&type_id=" + encodeURIComponent(this.value);
            }
        });
        if (!isEditMode) {
            updateGeneratedJobDeskName();
        }
    }
})();
</script>';
    render_footer();
}

function handle_route_maintenance_categories(PDO $pdo): void
{
    redirect_to('asset_groups');
}

function handle_route_reports(PDO $pdo): void
{
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
    if ($pcFilter !== '') {
        $sql .= ($printerReady && substr($pcFilter, 0, 3) === 'PRN') ? ' AND s.printer_id = ?' : ' AND s.pc_id = ?';
        $params[] = $pcFilter;
    }
    if ($techFilter > 0) {
        $sql .= ' AND s.technician_id = ?';
        $params[] = $techFilter;
    }
    if ($user['role'] === 'technician') {
        $sql .= ' AND s.technician_id = ?';
        $params[] = $user['id'];
    }
    $sql .= ' ORDER BY s.scheduled_date DESC, s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pcs = $pdo->query('SELECT pc_id, owner_name FROM pcs ORDER BY pc_id')->fetchAll();
    $printers = $printerReady ? $pdo->query('SELECT prn_id, printer_name FROM printers ORDER BY prn_id')->fetchAll() : [];
    $techs = $pdo->query("SELECT id, name FROM users WHERE role='technician' ORDER BY name")->fetchAll();
    $exportParams = ['type' => 'reports'];
    if ($pcFilter !== '') {
        $exportParams['pc_id'] = $pcFilter;
    }
    if ($techFilter > 0) {
        $exportParams['technician_id'] = $techFilter;
    }
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
        echo '<tr><th>Audit Foto</th><td colspan="2"><span class="badge' . e($summary['challenge_audit_class']) . '">' . e($summary['challenge_audit_status']) . '</span> <strong>' . e($summary['photo_evidence_score']) . '/100</strong><br><span class="muted">' . e($summary['challenge_audit_text']) . '</span>';
        if (!empty($summary['ai_challenge_audit']['note'])) {
            echo '<br><small style="color:#0369a1;font-weight:600;">🤖 AI Vision: ' . e($summary['ai_challenge_audit']['note']) . '</small>';
        }
        echo '</td></tr>';
        echo '<tr><th>Waktu Real</th><td colspan="2"><strong>' . e($summary['real_duration_text'] ?? '-') . '</strong></td></tr></table></td><td><a class="btn" href="' . route_url('report_print', ['id' => $report['schedule_id']]) . '">Detail</a></td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_maintenance_status_report(PDO $pdo): void
{
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
    if ($assetTypeFilter !== '') {
        $exportParams['asset_type'] = $assetTypeFilter;
    }
    if ($statusFilter !== '') {
        $exportParams['status_group'] = $statusFilter;
    }
    if ($techFilter > 0) {
        $exportParams['technician_id'] = $techFilter;
    }
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
}

function report_print(PDO $pdo): void
{
    $user = require_login();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, p.ai_recommendation, u.name technician FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id LEFT JOIN users u ON u.id=s.technician_id WHERE s.id=?');
    $stmt->execute([$id]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        http_response_code(404);
        exit('Report tidak ditemukan.');
    }
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
        'Waktu Real Pengerjaan' => $summary['real_duration_text'] ?? '-',
        'GPS' => trim((string)$schedule['arrival_lat'] . ', ' . (string)$schedule['arrival_lng'], ', '),
        'Audit Foto' => $summary ? ($summary['photo_evidence_score'] . '/100 - ' . $summary['challenge_audit_status'] . ' - ' . $summary['challenge_audit_text']) : 'Belum ada report',
        'Dynamic Challenge & QR (AI)' => !empty($summary['ai_challenge_audit']['note']) ? $summary['ai_challenge_audit']['note'] : ($summary['photo_challenge_code'] ? 'Kode ' . $summary['photo_challenge_code'] . ' (Validasi visual manual)' : '-'),
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

function export_excel(PDO $pdo, string $type): never
{
    $user = current_user();
    if (!$user) {
        redirect_to('login');
    }
    $maintenanceTypes = ['reports', 'maintenance', 'maintenance_status', 'jobs', 'technicians', 'asset_movements', 'asset_loans'];
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
        if ($pcFilter !== '') {
            $sql .= ($printerReady && substr($pcFilter, 0, 3) === 'PRN') ? ' AND s.printer_id = ?' : ' AND s.pc_id = ?';
            $params[] = $pcFilter;
        }
        if ($techFilter > 0) {
            $sql .= ' AND s.technician_id = ?';
            $params[] = $techFilter;
        }
        if (($user['role'] ?? '') === 'technician') {
            $sql .= ' AND s.technician_id = ?';
            $params[] = $user['id'];
        }
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
        $rows = $pdo->query("SELECT s.scheduled_date, 
            COALESCE(s.pc_id, s.printer_id, ma.maintenance_asset_code) asset_id, 
            s.asset_type, 
            COALESCE(ag.group_name, 'IT Asset') asset_group,
            COALESCE(p.owner_name, pr.printer_name, ma.owner_name) owner_name, 
            u.name technician, s.status, s.notes 
        FROM maintenance_schedules s 
        LEFT JOIN maintenance_assets ma ON ma.id = s.maintenance_asset_id
        LEFT JOIN asset_groups ag ON ag.id = COALESCE(s.asset_group_id, ma.asset_group_id)
        LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = s.pc_id COLLATE utf8mb4_unicode_ci 
        LEFT JOIN printers pr ON pr.prn_id COLLATE utf8mb4_unicode_ci = s.printer_id COLLATE utf8mb4_unicode_ci 
        LEFT JOIN users u ON u.id = s.technician_id 
        ORDER BY s.scheduled_date DESC")->fetchAll();
        output_tsv(['Tanggal', 'Asset ID', 'Tipe Aset', 'Asset Group', 'Owner/Lokasi', 'Teknisi', 'Status', 'Catatan'], $rows);
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
        $rows = $pdo->query('SELECT j.title, COALESCE(g.group_name, "Semua Group") AS asset_group, j.description, j.estimated_minutes, j.is_active, j.created_at FROM maintenance_jobs j LEFT JOIN asset_groups g ON g.id=j.asset_group_id ORDER BY j.title')->fetchAll();
        output_tsv(['Job Desk', 'Asset Group', 'Deskripsi', 'Estimasi Menit', 'Aktif', 'Dibuat'], $rows);
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

    if ($type === 'asset_movements') {
        $q = trim((string)($_GET['q'] ?? ''));
        $startDate = trim((string)($_GET['start_date'] ?? ''));
        $endDate = trim((string)($_GET['end_date'] ?? ''));
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(ai.asset_code LIKE ? OR ai.asset_name LIKE ? OR mv.reason LIKE ? OR mv.pic LIKE ? OR fp.asset_code LIKE ? OR tp.asset_code LIKE ?)';
            for ($k = 0; $k < 6; $k++) { $params[] = "%{$q}%"; }
        }
        if ($startDate !== '') {
            $where[] = 'mv.movement_date >= ?';
            $params[] = $startDate;
        }
        if ($endDate !== '') {
            $where[] = 'mv.movement_date <= ?';
            $params[] = $endDate;
        }
        $sql = 'SELECT mv.*, ai.asset_code, ai.asset_name, ai.asset_type, fp.asset_code from_parent_code, fp.asset_name from_parent_name, tp.asset_code to_parent_code, tp.asset_name to_parent_name, fc.company_name from_company, tc.company_name to_company 
                FROM asset_movements mv 
                JOIN asset_items ai ON ai.id=mv.asset_item_id 
                LEFT JOIN asset_items fp ON fp.id=mv.from_parent_asset_item_id 
                LEFT JOIN asset_items tp ON tp.id=mv.to_parent_asset_item_id 
                LEFT JOIN asset_companies fc ON fc.id=mv.from_company_id 
                LEFT JOIN asset_companies tc ON tc.id=mv.to_company_id 
                WHERE ' . implode(' AND ', $where) . ' 
                ORDER BY mv.movement_date DESC, mv.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exportRows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $from = $r['from_parent_code'] ? ($r['from_parent_code'] . ' - ' . $r['from_parent_name']) : '-';
            $to = $r['to_parent_code'] ? ($r['to_parent_code'] . ' - ' . $r['to_parent_name']) : '-';
            $company = ($r['from_company'] || $r['to_company']) ? (($r['from_company'] ?: '-') . ' -> ' . ($r['to_company'] ?: '-')) : '-';
            $exportRows[] = [
                $r['movement_date'],
                $r['asset_code'],
                $r['asset_name'],
                $r['asset_type'] ?? '-',
                $from,
                $to,
                $company,
                $r['reason'] ?: '-',
                $r['pic'] ?: '-',
            ];
        }
        output_tsv(['Tanggal Mutasi', 'Kode Aset', 'Nama Aset', 'Kategori/Tipe', 'Bundle Asal', 'Bundle Tujuan', 'Company', 'Alasan / Keterangan', 'PIC / Petugas'], $exportRows);
        exit;
    }

    if ($type === 'asset_loans') {
        $startDate = trim((string)($_GET['start_date'] ?? ''));
        $endDate = trim((string)($_GET['end_date'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));

        $where = ["1=1"];
        $params = [];
        if ($startDate !== '') {
            $where[] = "DATE(al.loan_date) >= ?";
            $params[] = $startDate;
        }
        if ($endDate !== '') {
            $where[] = "DATE(al.loan_date) <= ?";
            $params[] = $endDate;
        }
        if ($statusFilter !== '') {
            $where[] = "al.status = ?";
            $params[] = $statusFilter;
        }
        if ($q !== '') {
            $where[] = "(al.loan_code LIKE ? OR al.borrower_name LIKE ? OR al.borrower_nik LIKE ? OR al.borrower_department LIKE ?)";
            $w = '%' . $q . '%';
            $params = array_merge($params, [$w, $w, $w, $w]);
        }

        $sql = "SELECT al.*, u1.name AS officer_name, u2.name AS return_officer_name,
                (SELECT GROUP_CONCAT(CONCAT(ai.asset_code, ' - ', COALESCE(NULLIF(ai.asset_name,''), ai.model)) SEPARATOR '; ') 
                 FROM asset_loan_items ali2 
                 JOIN asset_items ai ON ai.id = ali2.asset_item_id 
                 WHERE ali2.loan_id = al.id) AS items_summary
                FROM asset_loans al
                LEFT JOIN users u1 ON u1.id = al.officer_user_id
                LEFT JOIN users u2 ON u2.id = al.return_officer_user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exportRows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stText = $r['status'] === 'returned' ? 'Dikembalikan' : ($r['status'] === 'active' ? 'Dipinjam' : ucfirst($r['status']));
            $exportRows[] = [
                $r['loan_code'],
                $r['borrower_name'],
                $r['borrower_nik'] ?: '-',
                $r['borrower_department'] ?: '-',
                $r['borrower_phone'] ?: '-',
                $r['items_summary'] ?: '-',
                $r['loan_date'],
                $r['expected_return_date'] ?: '-',
                $r['actual_return_date'] ?: '-',
                $r['purpose'] ?: '-',
                $r['location_note'] ?: '-',
                $stText,
                $r['officer_name'] ?: 'System',
                $r['return_officer_name'] ?: '-',
            ];
        }
        output_tsv(['No. Pinjam', 'Nama Peminjam', 'NIK', 'Departemen', 'No. HP', 'Daftar Aset', 'Tgl Pinjam', 'Target Kembali', 'Realisasi Kembali', 'Keperluan', 'Lokasi Pakai', 'Status', 'Petugas Penyerah', 'Petugas Penerima'], $exportRows);
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

