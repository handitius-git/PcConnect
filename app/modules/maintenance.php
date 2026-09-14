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
    $user = require_login();
    render_header('History Preventive Maintenance', $user);
    $printerReady = printer_schema_ready($pdo);
    if (!$printerReady) {
        echo printer_schema_warning();
    }
    $where = $user['role'] === 'technician' ? 'WHERE s.technician_id = ' . (int)$user['id'] : '';
    try {
        $rows = $pdo->query("SELECT s.*, 
            COALESCE(s.pc_id, s.printer_id, ma.maintenance_asset_code) asset_id, 
            COALESCE(p.owner_name, pr.printer_name, ma.owner_name) owner_name, 
            COALESCE(p.computer_name, pr.location, ma.location_label) computer_name, 
            u.name technician, 
            COALESCE(ag.group_name, 'IT Asset') asset_group_name,
            COALESCE(ag.group_code, 'IT') asset_group_code
        FROM maintenance_schedules s 
        LEFT JOIN maintenance_assets ma ON ma.id = s.maintenance_asset_id
        LEFT JOIN asset_groups ag ON ag.id = COALESCE(s.asset_group_id, ma.asset_group_id)
        LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = s.pc_id COLLATE utf8mb4_unicode_ci 
        LEFT JOIN printers pr ON pr.prn_id COLLATE utf8mb4_unicode_ci = s.printer_id COLLATE utf8mb4_unicode_ci 
        LEFT JOIN users u ON u.id = s.technician_id 
        $where 
        ORDER BY s.scheduled_date DESC, s.id DESC")->fetchAll();
    } catch (Throwable $e) {
        $rows = $pdo->query("SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, p.computer_name, u.name technician, 'IT Asset' asset_group_name, 'IT' asset_group_code FROM maintenance_schedules s JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = s.pc_id COLLATE utf8mb4_unicode_ci LEFT JOIN users u ON u.id=s.technician_id $where ORDER BY s.scheduled_date DESC, s.id DESC")->fetchAll();
    }
    echo '<section class="panel"><div class="split"><h1>History Preventive Maintenance</h1><div class="actions"><a class="btn" href="' . route_url('export_excel', ['type' => 'maintenance']) . '">Export Excel</a>';
    if (can_manage_maintenance($user)) {
        echo '<a class="btn danger" href="' . route_url('maintenance_cleanup') . '">Hapus Data</a><a class="btn primary" href="' . route_url('schedule_form') . '">Tambah Schedule</a>';
    }
    echo '</div></div></section><section class="panel"><table><tr><th>Tanggal</th><th>Asset / ID</th><th>Pengguna / Lokasi</th><th>Asset Group</th><th>Teknisi</th><th>Status</th><th>Foto</th><th>Aksi</th></tr>';
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
        $groupBadge = $row['asset_group_code'] ? ($row['asset_group_code'] . ' - ' . $row['asset_group_name']) : ($row['asset_group_name'] ?: 'IT Asset');
        echo '<tr><td>' . e($row['scheduled_date']) . '</td><td><strong>' . e($row['asset_id']) . '</strong><br><span class="badge">' . e($row['asset_type'] ?? 'pc') . '</span></td><td>' . e($row['owner_name'] ?: '-') . '<br><span class="muted">' . e($row['computer_name'] ?: '-') . '</span></td><td><span class="badge">' . e($groupBadge) . '</span></td><td>' . e($row['technician'] ?: '-') . '</td><td><span class="badge">' . e($row['status']) . '</span></td><td>Before: ' . e($beforeCount) . '<br>Process: ' . e($processCount) . '<br>After: ' . e($afterCount) . '</td><td>' . $actions . '</td></tr>';
    }
    echo '</table></section>';
    render_footer();
}

function handle_route_maintenance_cleanup(PDO $pdo): void
{
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
    $user = require_role(['admin', 'maintenance_admin']);
    $selectedGroupId = (int)(($_POST['asset_group_id'] ?? $_GET['asset_group_id'] ?? 0));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $technicianId = (int)($_POST['technician_id'] ?? 0);
        if ($technicianId <= 0) {
            flash('Teknisi wajib dipilih untuk schedule maintenance.', 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }
        $maintenanceAssetId = (int)($_POST['maintenance_asset_id'] ?? 0);
        if ($maintenanceAssetId <= 0) {
            flash('Aset maintenance wajib dipilih.', 'err');
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

        $maStmt = $pdo->prepare('SELECT ma.*, p.computer_name, pr.printer_name FROM maintenance_assets ma 
            LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci 
            LEFT JOIN printers pr ON pr.prn_id COLLATE utf8mb4_unicode_ci = ma.printer_id COLLATE utf8mb4_unicode_ci 
            WHERE ma.id = ? LIMIT 1');
        $maStmt->execute([$maintenanceAssetId]);
        $ma = $maStmt->fetch();
        if (!$ma) {
            flash('Aset maintenance tidak ditemukan atau belum terdaftar.', 'err');
            redirect_to('schedule_form', ['asset_group_id' => $selectedGroupId]);
        }

        $pcId = !empty($ma['pc_id']) ? $ma['pc_id'] : null;
        $printerId = !empty($ma['printer_id']) ? $ma['printer_id'] : null;
        $assetType = $pcId ? 'pc' : ($printerId ? 'printer' : ($ma['maintenance_type'] ?: 'equipment'));
        $assetGroupId = (int)($ma['asset_group_id'] ?: $selectedGroupId);
        if ($assetGroupId <= 0 && ($pcId || $printerId)) {
            $assetGroupId = (int)$pdo->query("SELECT id FROM asset_groups WHERE group_code='IT' LIMIT 1")->fetchColumn() ?: null;
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO maintenance_schedules (asset_type, maintenance_asset_id, asset_group_id, pc_id, printer_id, technician_id, scheduled_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$assetType, $maintenanceAssetId, $assetGroupId ?: null, $pcId, $printerId, $technicianId, $scheduledDate, trim($_POST['notes'] ?? '')]);
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

    $whereMa = "WHERE ma.status != 'inactive' AND (ma.pc_id IS NULL OR ma.pc_id = '' OR (p.asset_item_id IS NOT NULL AND p.asset_item_id > 0))";
    $paramsMa = [];
    if ($selectedGroupId > 0) {
        $whereMa .= " AND (ma.asset_group_id = ? OR (ma.asset_group_id IS NULL AND ? = (SELECT id FROM asset_groups WHERE group_code='IT' LIMIT 1)))";
        $paramsMa[] = $selectedGroupId;
        $paramsMa[] = $selectedGroupId;
    }
    $sqlMa = "SELECT ma.*, 
                     COALESCE(ag.group_name, 'IT Asset') group_name, 
                     COALESCE(ag.group_code, 'IT') group_code,
                     COALESCE(p.computer_name, pr.printer_name, ma.name) display_name,
                     COALESCE(p.owner_name, pr.location, ma.owner_name, ma.location_label) display_sub
              FROM maintenance_assets ma
              LEFT JOIN asset_groups ag ON ag.id = ma.asset_group_id
              LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci
              LEFT JOIN printers pr ON pr.prn_id COLLATE utf8mb4_unicode_ci = ma.printer_id COLLATE utf8mb4_unicode_ci
              $whereMa
              ORDER BY COALESCE(ag.group_name, 'IT Asset'), ma.maintenance_asset_code";
    $stmtMa = $pdo->prepare($sqlMa);
    $stmtMa->execute($paramsMa);
    $maintenanceAssets = $stmtMa->fetchAll();

    $techs = $pdo->query("SELECT id, name FROM users WHERE role='technician' AND is_active=1 ORDER BY name")->fetchAll();

    $jobsSql = 'SELECT j.*, g.group_name, g.group_code, t.type_name, t.type_code 
                FROM maintenance_jobs j 
                LEFT JOIN asset_groups g ON g.id=j.asset_group_id 
                LEFT JOIN asset_types t ON t.id=j.asset_type_id 
                WHERE j.is_active=1';
    $jobsParams = [];
    if ($selectedGroupId > 0) {
        $jobsSql .= ' AND (j.asset_group_id = ? OR j.asset_group_id IS NULL)';
        $jobsParams[] = $selectedGroupId;
    }
    $jobsSql .= ' ORDER BY COALESCE(j.job_desk_name, g.group_name, "zzz"), j.title';
    $stmtJobs = $pdo->prepare($jobsSql);
    $stmtJobs->execute($jobsParams);
    $jobs = $stmtJobs->fetchAll();

    echo '<section class="panel"><h1>Tambah Schedule Maintenance</h1><form method="get"><input type="hidden" name="route" value="schedule_form"><div class="grid three"><label>Filter Asset Group<select name="asset_group_id" onchange="this.form.submit()"><option value="0">Semua Asset Group</option>' . asset_group_options($pdo, $selectedGroupId, false) . '</select></label><label>&nbsp;<button class="btn primary">Terapkan Filter</button></label></div></form></section>';

    echo '<section class="panel"><form method="post"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="asset_group_id" value="' . e($selectedGroupId) . '"><div class="grid three">';
    echo '<label>Aset Maintenance (Terdaftar)<select name="maintenance_asset_id" required>';
    if (!$maintenanceAssets) {
        echo '<option value="">Tidak ada aset maintenance tersedia untuk filter ini</option>';
    } else {
        $currentGroup = null;
        foreach ($maintenanceAssets as $maRow) {
            $grp = $maRow['group_code'] ? ($maRow['group_code'] . ' - ' . $maRow['group_name']) : $maRow['group_name'];
            if ($currentGroup !== $grp) {
                if ($currentGroup !== null) {
                    echo '</optgroup>';
                }
                $currentGroup = $grp;
                echo '<optgroup label="' . e($currentGroup) . '">';
            }
            $subInfo = trim(($maRow['display_name'] ? $maRow['display_name'] . ' ' : '') . ($maRow['display_sub'] ? '(' . $maRow['display_sub'] . ')' : ''));
            $optLabel = $maRow['maintenance_asset_code'] . ' - ' . ($subInfo ?: $maRow['name']);
            $selected = ((int)($_GET['maintenance_asset_id'] ?? 0) === (int)$maRow['id'] || (int)($_POST['maintenance_asset_id'] ?? 0) === (int)$maRow['id']) ? ' selected' : '';
            $deskAttr = !empty($maRow['job_desk_name']) ? ' data-job-desk="' . e($maRow['job_desk_name']) . '"' : '';
            echo '<option value="' . (int)$maRow['id'] . '"' . $deskAttr . $selected . '>' . e($optLabel) . '</option>';
        }
        if ($currentGroup !== null) {
            echo '</optgroup>';
        }
    }
    echo '</select></label>';

    echo '<label>Teknisi<select name="technician_id" required><option value="">Pilih Teknisi</option>';
    foreach ($techs as $tech) {
        echo '<option value="' . e($tech['id']) . '">' . e($tech['name']) . '</option>';
    }
    echo '</select></label><label>Tanggal<input type="date" name="scheduled_date" required value="' . e(date('Y-m-d')) . '"></label></div>';
    echo '<div class="split" style="margin: 22px 0 10px; align-items: center;">'
        . '<label style="margin: 0; font-size: 16px; font-weight: 700;">Pilih Job Desk</label>'
        . '<div class="actions">'
        . '<button type="button" class="btn" onclick="toggleAllJobs(true)" style="padding: 6px 12px; font-size: 13px; cursor: pointer;">Pilih Semua (Select All)</button>'
        . '<button type="button" class="btn" onclick="toggleAllJobs(false)" style="padding: 6px 12px; font-size: 13px; cursor: pointer;">Batal Pilih (Unselect All)</button>'
        . '</div>'
        . '</div>';
    echo '<div id="jobListContainer">';
    if (!$jobs) {
        echo '<p class="muted">' . e($selectedGroupId > 0 ? 'Belum ada job desk aktif untuk Asset Group ini.' : 'Belum ada job desk aktif.') . '</p>';
    } else {
        $groupedJobs = [];
        foreach ($jobs as $job) {
            $dName = $job['job_desk_name'] ?: ('Job Desk ' . ($job['group_name'] ?: 'Umum'));
            $groupedJobs[$dName][] = $job;
        }
        foreach ($groupedJobs as $deskTitle => $deskJobs) {
            echo '<div class="job-desk-group" data-desk-name="' . e($deskTitle) . '" style="margin-bottom: 16px; padding: 4px;">'
                . '<div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 7px 12px; margin-bottom: 8px; font-weight: 700; color: #1e293b; display: flex; justify-content: space-between; align-items: center;">'
                . '<span>' . e($deskTitle) . '</span>'
                . '<span class="badge">' . count($deskJobs) . ' item</span>'
                . '</div>'
                . '<div class="grid two">';
            foreach ($deskJobs as $job) {
                $badge = !empty($job['group_code']) ? '<span class="badge ok" style="font-size: 11px; margin-left: 8px;">' . e($job['group_code']) . '</span>' : '';
                $est = !empty($job['estimated_minutes']) ? '<span class="muted" style="font-size: 12px; font-weight: normal; margin-left: 6px;">(~' . (int)$job['estimated_minutes'] . ' mnt)</span>' : '';
                echo '<label class="job-desk-card" style="display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; margin: 0; font-weight: normal; user-select: none;">'
                    . '<input type="checkbox" class="job-checkbox" name="jobs[]" value="' . e($job['id']) . '" checked style="width: 20px; height: 20px; min-width: 20px; cursor: pointer; margin: 0; flex-shrink: 0;">'
                    . '<div style="flex-grow: 1; display: flex; justify-content: space-between; align-items: center; gap: 8px; min-width: 0;">'
                    . '<span style="font-weight: 600; color: #1e293b; line-height: 1.35;">' . e($job['title']) . $est . '</span>'
                    . $badge
                    . '</div>'
                    . '</label>';
            }
            echo '</div></div>';
        }
    }
    echo '</div>';
    echo '<script>'
        . 'function toggleAllJobs(checked){'
        . 'document.querySelectorAll(".job-checkbox").forEach(function(cb){cb.checked=checked;});'
        . '}'
        . 'var assetSelect = document.querySelector("select[name=\"maintenance_asset_id\"]");'
        . 'function applyJobDeskForSelectedAsset(){'
        . 'if(!assetSelect)return;'
        . 'var opt = assetSelect.options[assetSelect.selectedIndex];'
        . 'if(!opt)return;'
        . 'var assignedDesk = opt.getAttribute("data-job-desk");'
        . 'if(assignedDesk){'
        . 'var hasMatch = false;'
        . 'document.querySelectorAll(".job-desk-group").forEach(function(grp){'
        . 'var gDesk = grp.getAttribute("data-desk-name");'
        . 'var isMatch = (gDesk === assignedDesk);'
        . 'if(isMatch) hasMatch = true;'
        . 'grp.querySelectorAll(".job-checkbox").forEach(function(cb){cb.checked = isMatch;});'
        . 'grp.style.border = isMatch ? "2px solid #3b82f6" : "none";'
        . 'grp.style.borderRadius = isMatch ? "8px" : "0";'
        . '});'
        . '}'
        . '}'
        . 'if(assetSelect){'
        . 'assetSelect.addEventListener("change", applyJobDeskForSelectedAsset);'
        . 'applyJobDeskForSelectedAsset();'
        . '}'
        . '</script>';
    echo '<label style="margin-top: 18px;">Catatan<textarea name="notes"></textarea></label><button class="btn primary">Simpan Schedule</button></form></section>';
    render_footer();
}

function handle_route_maintenance_do(PDO $pdo): void
{
    $user = require_login();
    $id = (int)($_GET['id'] ?? 0);
    $printerReady = printer_schema_ready($pdo);
    $stmt = $printerReady
        ? $pdo->prepare('SELECT s.*, COALESCE(s.pc_id,s.printer_id) asset_id, COALESCE(p.owner_name, pr.printer_name) owner_name, COALESCE(p.computer_name, pr.location) computer_name, COALESCE(p.physical_condition, pr.physical_condition) physical_condition FROM maintenance_schedules s LEFT JOIN pcs p ON p.pc_id=s.pc_id LEFT JOIN printers pr ON pr.prn_id=s.printer_id WHERE s.id=?')
        : $pdo->prepare("SELECT s.*, s.pc_id asset_id, 'pc' asset_type, p.owner_name, p.computer_name, p.physical_condition FROM maintenance_schedules s JOIN pcs p ON p.pc_id=s.pc_id WHERE s.id=?");
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

function handle_route_jobs(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin']);
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
    $editGroupId = $isEditing ? (int)($editingDesk['asset_group_id'] ?? 0) : 0;
    $editTypeId = $isEditing ? (int)($editingDesk['asset_type_id'] ?? 0) : 0;
    $editDesc = $isEditing ? (string)($editingDesk['description'] ?? '') : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        // --- TAMBAH JOB DESK PREVENTIVE MAINTENANCE ---
        if ($action === 'add_desk') {
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
                redirect_to('jobs', ['manage_desk_id' => $existId]);
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
            redirect_to('jobs', ['manage_desk_id' => $newDeskId]);
        }

        // --- EDIT JOB DESK PREVENTIVE MAINTENANCE ---
        if ($action === 'edit_desk') {
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
            redirect_to('jobs', ['manage_desk_id' => $deskId]);
        }

        // --- HAPUS JOB DESK PREVENTIVE MAINTENANCE ---
        if ($action === 'delete_desk') {
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
            redirect_to('jobs');
        }

        // --- TAMBAH JOB TASK KE JOB DESK ---
        if ($action === 'add_task') {
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
                        $curDesk['asset_group_id'] ?: null,
                        $curDesk['asset_type_id'] ?: null,
                        $curDesk['job_desk_name'],
                        $estMinutes,
                    ]);
                    flash("Pekerjaan '{$taskTitle}' berhasil ditambahkan ke {$curDesk['job_desk_name']}.");
                } else {
                    flash('Nama pekerjaan / job task wajib diisi.', 'err');
                }
            }
            redirect_to('jobs', ['manage_desk_id' => $manageDeskId]);
        }

        // --- UPDATE ESTIMASI / NAMA TASK ---
        if ($action === 'update_task') {
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
            redirect_to('jobs', ['manage_desk_id' => $manageDeskId]);
        }

        // --- TOGGLE AKTIF / NON-AKTIF TASK ---
        if ($action === 'toggle_task') {
            $taskId = (int)($_POST['id'] ?? 0);
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            if ($taskId > 0) {
                $pdo->prepare('UPDATE maintenance_jobs SET is_active = 1 - is_active WHERE id = ?')->execute([$taskId]);
                flash('Status pekerjaan diperbarui.');
            }
            redirect_to('jobs', ['manage_desk_id' => $manageDeskId]);
        }

        // --- HAPUS JOB TASK ---
        if ($action === 'delete_task') {
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
            redirect_to('jobs', ['manage_desk_id' => $manageDeskId]);
        }

        // --- SALIN TUGAS DARI JOB DESK UMUM ---
        if ($action === 'copy_default_tasks') {
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            $stmtDesk = $pdo->prepare('SELECT * FROM preventive_job_desks WHERE id=?');
            $stmtDesk->execute([$manageDeskId]);
            $curDesk = $stmtDesk->fetch(PDO::FETCH_ASSOC);

            if ($curDesk) {
                $targetDeskName = $curDesk['job_desk_name'];
                $defaultJobs = $pdo->query("SELECT title, description, estimated_minutes FROM maintenance_jobs WHERE job_desk_name = 'Job Desk Umum' OR job_desk_name IS NULL OR job_desk_name = '' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                $ins = $pdo->prepare("INSERT INTO maintenance_jobs (title, description, asset_group_id, asset_type_id, job_desk_name, estimated_minutes, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $copied = 0;
                foreach ($defaultJobs as $dj) {
                    $chkDup = $pdo->prepare("SELECT COUNT(*) FROM maintenance_jobs WHERE job_desk_name = ? AND title = ?");
                    $chkDup->execute([$targetDeskName, $dj['title']]);
                    if ((int)$chkDup->fetchColumn() === 0) {
                        $ins->execute([
                            $dj['title'],
                            $dj['description'],
                            $curDesk['asset_group_id'] ?: null,
                            $curDesk['asset_type_id'] ?: null,
                            $targetDeskName,
                            $dj['estimated_minutes'],
                        ]);
                        $copied++;
                    }
                }
                flash("Berhasil menyalin {$copied} tugas standar ke '{$targetDeskName}'.");
            }
            redirect_to('jobs', ['manage_desk_id' => $manageDeskId]);
        }
    }

    render_header('Job Desk Preventive Maintenance', $user);

    // Ambil semua daftar job desk dengan join aman tanpa error collation 1267
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
    GROUP BY d.id, d.job_desk_name, d.asset_group_id, d.asset_type_id, d.description, g.group_code, g.group_name, t.type_code, t.type_name
    ORDER BY g.group_name, t.type_name, d.job_desk_name';

    $deskRows = $pdo->query($desksQuery)->fetchAll(PDO::FETCH_ASSOC);

    // Tentukan Job Desk yang sedang aktif dipilih untuk pengelolaan Job Tasks
    $manageDeskId = (int)($_GET['manage_desk_id'] ?? 0);
    if ($manageDeskId === 0) {
        if ($editDeskId > 0) {
            $manageDeskId = $editDeskId;
        } elseif (!empty($deskRows)) {
            $manageDeskId = (int)$deskRows[0]['desk_id'];
        }
    }

    $activeDesk = null;
    $activeDeskTasks = [];
    if ($manageDeskId > 0) {
        foreach ($deskRows as $r) {
            if ((int)$r['desk_id'] === $manageDeskId) {
                $activeDesk = $r;
                break;
            }
        }
        if ($activeDesk) {
            $isUmum = ($activeDesk['job_desk_name'] === 'Job Desk Umum');
            $sqlTasks = $isUmum 
                ? 'SELECT * FROM maintenance_jobs WHERE job_desk_name = ? OR job_desk_name IS NULL OR job_desk_name = "" ORDER BY is_active DESC, id ASC'
                : 'SELECT * FROM maintenance_jobs WHERE job_desk_name = ? ORDER BY is_active DESC, id ASC';
            $stmtTasks = $pdo->prepare($sqlTasks);
            $stmtTasks->execute([$activeDesk['job_desk_name']]);
            $activeDeskTasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // FORM TAMBAH / EDIT JOB DESK PREVENTIVE MAINTENANCE
    $formTitle = $isEditing ? ('Edit Job Desk Preventive Maintenance #' . $editDeskId) : 'Tambah Job Desk Preventive Maintenance';
    $formAction = $isEditing ? 'edit_desk' : 'add_desk';

    echo '<section class="grid two"><div class="panel">';
    echo '<div class="split" style="align-items:center;margin-bottom:12px;">'
        . '<h1 style="margin:0;font-size:18px;">' . e($formTitle) . '</h1>'
        . ($isEditing ? '<a class="btn" href="' . route_url('jobs') . '">+ Tambah Job Desk Baru</a>' : '')
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
        . ($isEditing ? '<a class="btn" href="' . route_url('jobs') . '">Batal Edit</a>' : '')
        . '</div>'
        . '</form>'
        . '</div>';

    // TABEL DAFTAR JOB DESK PREVENTIVE MAINTENANCE
    echo '<div class="panel">'
        . '<div class="split"><h2>Daftar Job Desk Preventive Maintenance</h2><div class="actions"><a class="btn" href="' . route_url('export_excel', ['type' => 'jobs']) . '">Export Excel</a></div></div>'
        . '<p class="muted" style="margin-top:-6px;margin-bottom:12px;">Pilih Job Desk untuk mengisi atau mengelola daftar pekerjaannya (Job Tasks).</p>';

    if (!$deskRows) {
        echo '<p class="muted">Belum ada Job Desk tersimpan. Silakan tambahkan pada form di samping.</p>';
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

            $deleteBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'' . $confirmMsg . '\')">'
                . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="delete_desk">'
                . '<input type="hidden" name="desk_id" value="' . $dId . '">'
                . '<input type="hidden" name="job_desk_name" value="' . e($dName) . '">'
                . '<button class="btn danger">Hapus</button>'
                . '</form>';

            $trBg = $isActive ? ' style="background:#eff6ff;"' : '';
            echo '<tr' . $trBg . '>'
                . '<td><span class="badge" style="font-weight:700;">#' . $dId . '</span></td>'
                . '<td><strong>' . e($dName) . '</strong>' . ($isActive ? ' <span class="badge ok" style="font-size:10px;">Aktif Dikelola</span>' : '') . '</td>'
                . '<td><span style="font-weight:600;">' . e($grpLabel) . '</span><br><span class="muted" style="font-size:12px;">' . e($typLabel) . '</span></td>'
                . '<td><span class="badge">' . (int)$d['job_count'] . ' task</span><br><span class="muted" style="font-size:11px;">~' . (int)$d['total_minutes'] . ' mnt</span></td>'
                . '<td>' . $usageBadge . '</td>'
                . '<td><div class="actions" style="display:flex;gap:4px;align-items:center;">'
                . '<a class="btn ' . ($isActive ? 'primary' : '') . '" href="' . route_url('jobs', ['manage_desk_id' => $dId]) . '" title="Isi & Kelola Pekerjaan">📋 Isi Tasks</a>'
                . '<a class="btn" href="' . route_url('jobs', ['edit_id' => $dId, 'manage_desk_id' => $dId]) . '">Edit</a>'
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
        $totMinutes = 0;
        foreach ($activeDeskTasks as $t) {
            if (!empty($t['is_active'])) {
                $totMinutes += (int)$t['estimated_minutes'];
            }
        }

        echo '<div class="panel" style="margin-top:20px;border-top:3px solid #2563eb;">';
        $copyHeaderBtn = ($actName !== 'Job Desk Umum')
            ? '<form method="post" style="display:inline;"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="copy_default_tasks"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><button class="btn" style="padding:5px 10px;font-size:12px;background:#f0fdf4;border:1px solid #86efac;color:#166534;font-weight:600;" title="Salin tugas-tugas standar yang belum ada ke Job Desk ini">+ Salin Tugas Standar</button></form>'
            : '';
        echo '<div class="split" style="align-items:center;margin-bottom:14px;">'
            . '<div>'
            . '<h2 style="margin:0;color:#1e40af;">📋 Daftar Pekerjaan (Job Tasks) untuk: ' . e($actName) . ' <span class="badge" style="font-size:12px;">ID: #' . $actId . '</span></h2>'
            . '<p class="muted" style="margin:4px 0 0 0;font-size:13px;">Kelola rincian item pekerjaan yang wajib dijalankan teknisi saat preventive maintenance untuk Job Desk ini.</p>'
            . '</div>'
            . '<div style="display:flex;gap:8px;align-items:center;">'
            . $copyHeaderBtn
            . '<span class="badge ok" style="font-size:13px;padding:6px 12px;">Total: ' . count($activeDeskTasks) . ' Tasks (~' . $totMinutes . ' Menit)</span>'
            . '</div>'
            . '</div>';

        echo '<section class="grid two" style="margin-bottom:0;">';

        // Form Tambah Job Task Baru
        echo '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">'
            . '<h3 style="margin-top:0;font-size:15px;color:#0f172a;">+ Tambah Job Task Baru ke ' . e($actName) . '</h3>'
            . '<form method="post">'
            . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="add_task">'
            . '<input type="hidden" name="manage_desk_id" value="' . $actId . '">'
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

        // Tabel Daftar Job Tasks Terdaftar
        echo '<div>';
        if (!$activeDeskTasks) {
            $copyEmptyBtn = ($actName !== 'Job Desk Umum')
                ? '<form method="post" style="margin-top:12px;"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="copy_default_tasks"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><button class="btn primary" style="font-size:13px;">📥 Salin 12 Tugas Standar dari Job Desk Umum</button></form>'
                : '';
            echo '<div style="padding:28px;text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:8px;">'
                . '<p class="muted" style="margin:0 0 8px 0;">Belum ada item pekerjaan untuk Job Desk <strong>' . e($actName) . '</strong>.</p>'
                . '<p style="font-size:13px;color:#64748b;margin:0;">Silakan tambahkan pekerjaan pertama pada formulir di sebelah kiri atau salin dari tugas standar.</p>'
                . $copyEmptyBtn
                . '</div>';
        } else {
            echo '<div style="overflow-x:auto;"><table style="margin:0;"><tr><th>No</th><th>Nama Pekerjaan / Task</th><th>Estimasi</th><th>Status</th><th>Aksi</th></tr>';
            $tNo = 1;
            foreach ($activeDeskTasks as $task) {
                $tId = (int)$task['id'];
                $tTitle = (string)$task['title'];
                $tMinutes = (int)$task['estimated_minutes'];
                $tDesc = (string)($task['description'] ?? '');
                $tActive = !empty($task['is_active']);

                $toggleBtn = '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="id" value="' . $tId . '"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><button class="btn" style="padding:4px 8px;font-size:11px;">' . ($tActive ? 'Nonaktifkan' : 'Aktifkan') . '</button></form>';

                $delTaskBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus pekerjaan &quot;' . e($tTitle) . '&quot;?\')"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="' . $tId . '"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><button class="btn danger" style="padding:4px 8px;font-size:11px;">Hapus</button></form>';

                echo '<tr>'
                    . '<td style="width:36px;text-align:center;">' . $tNo++ . '</td>'
                    . '<td><strong>' . e($tTitle) . '</strong>' . ($tDesc !== '' ? ('<br><span class="muted" style="font-size:11px;">' . e($tDesc) . '</span>') : '') . '</td>'
                    . '<td><span class="badge" style="font-weight:700;">' . $tMinutes . ' mnt</span></td>'
                    . '<td>' . ($tActive ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>') . '</td>'
                    . '<td><div class="actions" style="display:flex;gap:4px;align-items:center;">' . $toggleBtn . $delTaskBtn . '</div></td>'
                    . '</tr>';
            }
            echo '</table></div>';
        }
        echo '</div>';

        echo '</section>';
        echo '</div>';
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

        typeSelect.addEventListener("change", updateGeneratedJobDeskName);
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

