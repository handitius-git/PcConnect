<?php
declare(strict_types=1);

final class Database
{
    public static function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $cfg = app_config();
        $dsn = 'mysql:host=' . $cfg['db_host'];
        if (!empty($cfg['db_port']) && $cfg['db_host'] !== 'localhost') {
            $dsn .= ';port=' . (int)$cfg['db_port'];
        }
        $dsn .= ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }
}

function app_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $path = dirname(__DIR__, 2) . '/config/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('File config/config.php tidak ditemukan.');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('File config/config.php harus mengembalikan array.');
    }
    return $config;
}

function config_value(string $key, mixed $default = null): mixed
{
    $config = app_config();
    return $config[$key] ?? $default;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function route_url(string $route, array $params = []): string
{
    $params = array_merge(['route' => $route], $params);
    return 'index.php?' . http_build_query($params);
}

function ends_with_text(string $value, string $suffix): bool
{
    if ($suffix === '') {
        return true;
    }
    return substr($value, -strlen($suffix)) === $suffix;
}

function absolute_route_url(string $route, array $params = []): string
{
    $base = rtrim((string)config_value('base_url'), '/');
    $query = http_build_query(array_merge(['route' => $route], $params));
    if (ends_with_text($base, 'index.php')) {
        return $base . '?' . $query;
    }
    return $base . '/index.php?' . $query;
}

function mobile_asset_url(string $assetCode): string
{
    return (string)config_value('mobile_base_url') . rawurlencode($assetCode);
}

function redirect_to(string $route, array $params = []): never
{
    header('Location: ' . route_url($route, $params));
    exit;
}

function ensure_session_started(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function csrf_token(): string
{
    ensure_session_started();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    ensure_session_started();
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $token)) {
        http_response_code(419);
        exit('CSRF token tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    ensure_session_started();
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function current_user(): ?array
{
    ensure_session_started();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect_to('login');
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    return $user;
}

function require_technician(): array
{
    return require_role(['technician']);
}

function is_full_admin(?array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function can_manage_maintenance(?array $user): bool
{
    return in_array(($user['role'] ?? ''), ['admin', 'maintenance_admin'], true);
}

function render_header(string $title, ?array $user = null): void
{
    $flash = flash();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' - PcConnect</title><style>';
    echo 'body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#172033}a{color:inherit}header{background:#182235;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}.brand{font-weight:700}.nav{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.nav a,.nav summary{color:#eaf0ff;text-decoration:none;padding:8px 10px;border-radius:6px;cursor:pointer;list-style:none}.nav a:hover,.nav summary:hover{background:rgba(255,255,255,.12)}.nav details{position:relative}.nav details[open] summary{background:rgba(255,255,255,.14)}.nav .menu{position:absolute;right:0;top:38px;min-width:230px;background:#fff;border:1px solid #d9e1ee;border-radius:8px;box-shadow:0 16px 35px rgba(15,23,42,.18);padding:6px;z-index:10}.nav .menu a{display:block;color:#172033;padding:10px 12px}.nav .menu a:hover{background:#f1f5f9}main{max-width:1180px;margin:0 auto;padding:22px}.auth{max-width:420px;margin:64px auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 10px 30px rgba(16,24,40,.08)}.panel,.stat{background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:18px;margin-bottom:16px}.hero{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(260px,.8fr);gap:16px;align-items:stretch}.grid{display:grid;gap:16px}.two{grid-template-columns:repeat(2,minmax(0,1fr))}.three{grid-template-columns:repeat(3,minmax(0,1fr))}.four{grid-template-columns:repeat(4,minmax(0,1fr))}.split{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-block;border:1px solid #c7d0df;background:#fff;color:#172033;text-decoration:none;border-radius:6px;padding:9px 12px;cursor:pointer;font:inherit}.btn.primary{background:#1457d9;border-color:#1457d9;color:#fff}.btn.good{background:#0f8a5f;border-color:#0f8a5f;color:#fff}.btn.danger{background:#b91c1c;border-color:#b91c1c;color:#fff}label{display:block;font-weight:600;margin:12px 0 6px}input,select,textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:9px;font:inherit}textarea{min-height:120px}table{width:100%;border-collapse:collapse;background:#fff}th,td{border-bottom:1px solid #e2e8f0;text-align:left;padding:10px;vertical-align:top}th{background:#f8fafc}.badge{display:inline-block;border-radius:999px;background:#e8eef7;padding:4px 8px;font-size:12px}.badge.ok{background:#dcfce7;color:#166534}.badge.danger{background:#fee2e2;color:#991b1b}.muted{color:#64748b}.flash{padding:12px 14px;border-radius:6px;margin-bottom:16px;background:#e7f7ef;color:#14532d}.flash.err{background:#fee2e2;color:#991b1b}.stat strong{display:block;font-size:36px}.label-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.photo-grid img{width:100%;border-radius:6px;border:1px solid #d9e1ee}.qr-label{break-inside:avoid;background:#fff;border:1px solid #222;padding:12px;display:flex;flex-direction:column;gap:4px}.qr-box{display:grid;grid-template-columns:repeat(9,1fr);gap:2px;width:126px;height:126px;margin:8px 0}.qr-box i,.qr-box span{display:block}.qr-box i{background:#111}.qr-box span{background:#fff}@media(max-width:820px){.hero,.two,.three,.four{grid-template-columns:1fr}.nav details{position:static}.nav .menu{position:static;box-shadow:none;margin-top:4px}main{padding:14px}table{display:block;overflow-x:auto}}@media print{header,.no-print,.btn{display:none!important}body{background:#fff}main{max-width:none;padding:0}.qr-label{page-break-inside:avoid}}';
    echo '.qr-img{width:126px;height:126px;object-fit:contain;margin:8px 0;display:block}';
    echo '</style></head><body><header><div class="brand">PcConnect</div>';
    if ($user) {
        echo '<nav class="nav">';
        if (is_full_admin($user)) {
            echo '<a href="' . route_url('dashboard') . '">Dashboard</a><a href="' . route_url('pcs') . '">PC</a><a href="' . route_url('printers') . '">Printer</a>';
            echo '<details><summary>Asset Management</summary><div class="menu"><a href="' . route_url('asset_dashboard') . '">Overview</a><a href="' . route_url('asset_companies') . '">Company</a><a href="' . route_url('asset_items') . '">Asset Items</a><a href="' . route_url('maintenance_assets') . '">Maintenance Assets / QR</a><a href="' . route_url('asset_repairs') . '">Repair History</a><a href="' . route_url('asset_movements') . '">Mutasi / Tukar Pasang</a><a href="' . route_url('asset_bundles') . '">Bundle Lama</a></div></details>';
        }
        if (can_manage_maintenance($user)) {
            echo '<details><summary>Preventive Maintenance</summary><div class="menu"><a href="' . route_url('maintenance') . '">Maintenance</a><a href="' . route_url('maintenance_status_report') . '">Status PC/Printer</a><a href="' . route_url('jobs') . '">Job Desk</a><a href="' . route_url('technicians') . '">Teknisi</a><a href="' . route_url('maintenance_cleanup') . '">Hapus Data Maintenance</a><a href="' . route_url('reports') . '">Reports</a></div></details>';
            if (is_full_admin($user)) {
                echo '<details><summary>Setup</summary><div class="menu"><a href="' . route_url('employee_source') . '">Employee & Company Source</a><a href="' . route_url('labels') . '">QR Label</a><a href="' . route_url('users') . '">Users</a></div></details>';
            }
        } else {
            echo '<details><summary>Preventive Maintenance</summary><div class="menu"><a href="' . route_url('maintenance') . '">Maintenance</a><a href="' . route_url('maintenance_status_report') . '">Status PC/Printer</a><a href="' . route_url('reports') . '">Reports</a></div></details>';
        }
        echo '<a href="' . route_url('logout') . '">Logout</a></nav>';
    }
    echo '</header><main>';
    if ($flash) {
        echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function render_mobile_header(string $title, ?array $user = null): void
{
    $flash = flash();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' - PcConnect Mobile</title><style>';
    echo 'body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f3f6fb;color:#172033}main{max-width:560px;margin:0 auto;padding:14px 14px 82px}.mobile-top{position:sticky;top:0;z-index:2;background:#172033;color:#fff;padding:14px 16px;font-weight:700}.mobile-nav{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #d9e1ee;display:grid;grid-template-columns:repeat(4,1fr);z-index:3}.mobile-nav a{text-align:center;text-decoration:none;color:#334155;padding:10px 4px;font-size:13px}.panel,.stat{background:#fff;border:1px solid #dde5f0;border-radius:8px;padding:16px;margin-bottom:12px}.grid{display:grid;gap:12px}.two{grid-template-columns:repeat(2,minmax(0,1fr))}.split{display:flex;justify-content:space-between;gap:10px;align-items:center}.btn{display:inline-block;border:1px solid #c7d0df;background:#fff;color:#172033;text-decoration:none;border-radius:6px;padding:10px 12px;cursor:pointer;font:inherit}.btn.primary{background:#1457d9;border-color:#1457d9;color:#fff}.btn.good{background:#0f8a5f;border-color:#0f8a5f;color:#fff}.btn.danger{background:#b91c1c;border-color:#b91c1c;color:#fff}label{display:block;font-weight:600;margin:10px 0 6px}input,select,textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:10px;font:inherit}textarea{min-height:110px}.badge{display:inline-block;border-radius:999px;background:#e8eef7;padding:4px 8px;font-size:12px}.ok{background:#dcfce7;color:#166534}.danger-text{color:#991b1b}.muted{color:#64748b}.flash{padding:12px;border-radius:6px;margin-bottom:12px;background:#e7f7ef;color:#14532d}.flash.err{background:#fee2e2;color:#991b1b}.readonly{opacity:.72}.check-row{display:grid;grid-template-columns:28px minmax(0,1fr);gap:10px;align-items:start;border-bottom:1px solid #e2e8f0;padding:12px 0}.check-row input[type=checkbox]{width:22px;height:22px;margin:2px 0 0}.check-title{display:block;font-weight:700;line-height:1.35}.check-note-label{font-size:13px;color:#64748b;margin-top:8px}.check-note-label input{margin-top:4px}.scan-video{display:none;width:100%;border-radius:8px;background:#111;margin-bottom:10px}.camera-note{font-size:13px;color:#64748b;margin:8px 0}.photo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.photo-grid img{width:100%;border-radius:6px;border:1px solid #d9e1ee}.signature-pad{width:100%;height:160px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;touch-action:none}@media(max-width:420px){.two,.photo-grid{grid-template-columns:1fr}}';
    echo '</style></head><body><div class="mobile-top">PcConnect Mobile</div><main>';
    if ($flash) {
        echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
    if ($user) {
        if (can_manage_maintenance($user)) {
            echo '<nav class="mobile-nav"><a href="' . route_url('maintenance') . '">Maintenance</a><a href="' . route_url('schedule_form') . '">Schedule</a><a href="' . route_url('reports') . '">Reports</a><a href="' . route_url('logout') . '">Logout</a></nav>';
        } else {
            echo '<nav class="mobile-nav"><a href="' . route_url('mobile_dashboard') . '">Dashboard</a><a href="' . route_url('mobile_schedule') . '">Schedule</a><a href="' . route_url('mobile_scan') . '">Scan</a><a href="' . route_url('mobile_history') . '">History</a></nav>';
        }
    }
}

function render_footer(): void
{
    echo '</main></body></html>';
}

function render_mobile_footer(): void
{
    render_footer();
}

function ensure_security_code(PDO $pdo, string $pcId): string
{
    $stmt = $pdo->prepare('SELECT security_code FROM pcs WHERE pc_id = ?');
    $stmt->execute([$pcId]);
    $code = (string)($stmt->fetchColumn() ?: '');
    if ($code !== '') {
        return $code;
    }
    $code = strtoupper(chr(65 + (crc32($pcId) % 26)) . (crc32($pcId . '-pcconnect') % 10));
    $pdo->prepare('UPDATE pcs SET security_code = ? WHERE pc_id = ?')->execute([$code, $pcId]);
    return $code;
}

function asset_code(array $pc): string
{
    $maintenanceCode = trim((string)($pc['maintenance_asset_code'] ?? ''));
    if ($maintenanceCode !== '') {
        return $maintenanceCode . '-' . (string)$pc['security_code'];
    }
    return (string)$pc['pc_id'] . '-' . (string)$pc['security_code'];
}

function parse_asset_code(string $code): array
{
    $code = strtoupper(trim($code));
    if (preg_match('/^([A-Z0-9_-]+)-([A-Z0-9]{2,12})$/', $code, $m)) {
        return [$m[1], $m[2]];
    }
    return ['', ''];
}

function add_timeline(PDO $pdo, int $scheduleId, string $eventType, string $note = '', ?int $actorUserId = null): void
{
    $stmt = $pdo->prepare('INSERT INTO maintenance_timeline (schedule_id, event_type, event_note, actor_user_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$scheduleId, $eventType, $note, $actorUserId]);
}

function upload_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/uploads/maintenance';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function compress_image_to_limit(string $path, int $maxBytes = 1048576): bool
{
    if (!is_file($path) || filesize($path) <= $maxBytes) {
        return true;
    }
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $info = @getimagesize($path);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return false;
    }
    [$width, $height, $type] = $info;
    $loader = match ($type) {
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        default => null,
    };
    if (!$loader || !function_exists($loader)) {
        return false;
    }
    $source = @$loader($path);
    if (!$source) {
        return false;
    }
    $maxSide = 1600;
    $scale = min(1, $maxSide / max($width, $height));
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));
    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    for ($quality = 82; $quality >= 45; $quality -= 8) {
        imagejpeg($canvas, $path, $quality);
        clearstatcache(true, $path);
        if (filesize($path) <= $maxBytes) {
            imagedestroy($source);
            imagedestroy($canvas);
            return true;
        }
    }
    imagedestroy($source);
    imagedestroy($canvas);
    return filesize($path) <= $maxBytes;
}

function save_uploaded_photos(string $field, int $scheduleId, ?array &$metadata = null): array
{
    if (empty($_FILES[$field]['name'])) {
        return [];
    }
    if (!is_array($_FILES[$field]['name'])) {
        $_FILES[$field] = [
            'name' => [$_FILES[$field]['name']],
            'type' => [$_FILES[$field]['type'] ?? ''],
            'tmp_name' => [$_FILES[$field]['tmp_name'] ?? ''],
            'error' => [$_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$_FILES[$field]['size'] ?? 0],
        ];
    }
    $saved = [];
    $dir = upload_dir();
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = $_FILES[$field]['tmp_name'][$i] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $file = 'schedule-' . $scheduleId . '-' . $field . '-' . bin2hex(random_bytes(6)) . '.jpg';
        $target = $dir . '/' . $file;
        if (move_uploaded_file($tmp, $target)) {
            if (!compress_image_to_limit($target)) {
                @unlink($target);
                continue;
            }
            $url = '../uploads/maintenance/' . $file;
            $saved[] = $url;
            if (is_array($metadata)) {
                $metadata[] = [
                    'field' => $field,
                    'url' => $url,
                    'original_name' => (string)($_FILES[$field]['name'][$i] ?? ''),
                    'original_type' => (string)($_FILES[$field]['type'][$i] ?? ''),
                    'original_size' => (int)($_FILES[$field]['size'][$i] ?? 0),
                    'server_saved_at' => date('c'),
                ];
            }
        }
    }
    return $saved;
}

function save_signature_image(string $dataUrl, int $scheduleId): ?string
{
    if (!preg_match('/^data:image\/png;base64,(.+)$/', $dataUrl, $m)) {
        return null;
    }
    $raw = base64_decode($m[1], true);
    if ($raw === false || strlen($raw) < 100) {
        return null;
    }
    $file = 'schedule-' . $scheduleId . '-signature-' . bin2hex(random_bytes(6)) . '.png';
    file_put_contents(upload_dir() . '/' . $file, $raw);
    return '../uploads/maintenance/' . $file;
}

function report_hash(array $data): string
{
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function analytical_summary(array $payload): array
{
    $hardware = $payload['hardware'] ?? [];
    $software = $payload['software'] ?? [];
    $devices = $payload['devices'] ?? [];
    $benchmark = $payload['benchmark'] ?? [];
    $startup = $payload['startup'] ?? [];

    $hardwareScore = 100;
    if ((float)($hardware['ram_gb'] ?? 0) < 8) {
        $hardwareScore -= 25;
    }
    if (stripos((string)($hardware['processor'] ?? ''), 'celeron') !== false || stripos((string)($hardware['processor'] ?? ''), 'atom') !== false) {
        $hardwareScore -= 15;
    }

    $softwareScore = 100;
    if (empty($software['antivirus'])) {
        $softwareScore -= 20;
    }
    if (empty($software['office_apps'])) {
        $softwareScore -= 10;
    }

    $driverWarnings = normalize_count($devices['driver_warnings'] ?? []);
    $driverRisk = normalize_count($devices['driver_risk'] ?? []);
    $highDriverRisk = risk_level_count($devices['driver_risk'] ?? [], 'High');
    $mediumDriverRisk = risk_level_count($devices['driver_risk'] ?? [], 'Medium');
    $deviceScore = 100 - ($driverWarnings * 15) - ($highDriverRisk * 30) - ($mediumDriverRisk * 12);

    $benchmarkScore = 100;
    if (isset($benchmark['storage_write_mbps']) && (float)$benchmark['storage_write_mbps'] < 80) {
        $benchmarkScore -= 25;
    }
    if (isset($benchmark['ram_copy_mbps']) && (float)$benchmark['ram_copy_mbps'] < 1500) {
        $benchmarkScore -= 20;
    }
    if (isset($benchmark['cpu_quick_ms']) && (float)$benchmark['cpu_quick_ms'] > 2500) {
        $benchmarkScore -= 20;
    }

    $startupScore = 100;
    $highStartupRisk = risk_level_count($startup['startup_intelligence'] ?? [], 'High');
    $mediumStartupRisk = risk_level_count($startup['startup_intelligence'] ?? [], 'Medium');
    $startupScore -= min(60, ($highStartupRisk * 30) + ($mediumStartupRisk * 12));
    $startupScore -= min(25, normalize_count($startup['suspicious_background_processes'] ?? []) * 12);
    $startupScore -= min(20, normalize_count($startup['auto_services_not_running'] ?? []) * 3);

    $notes = [];
    $critical = [];
    if ($hardwareScore < 80) {
        $notes[] = 'Hardware: tinjau RAM/prosesor terhadap beban kerja user; upgrade RAM/SSD lebih prioritas daripada reinstall OS bila bottleneck fisik jelas.';
    }
    if ($softwareScore < 90) {
        $notes[] = 'Software: validasi antivirus, Windows Update, dan aplikasi produktivitas. Jangan abaikan endpoint yang tidak terbaca proteksinya.';
    }
    if ($highDriverRisk > 0) {
        $critical[] = 'Driver high risk ' . $highDriverRisk . ' item';
        $notes[] = 'Driver: prioritas high risk. Update/reinstall driver resmi vendor, restart, lalu cek ulang Device Manager sebelum menyimpulkan hardware rusak.';
    } elseif ($driverWarnings > 0 || $mediumDriverRisk > 0) {
        $notes[] = 'Driver: ada item medium/warning. Cocokkan dengan keluhan user seperti USB, display, LAN/Wi-Fi, audio, atau storage.';
    }
    if ($benchmarkScore < 85) {
        $notes[] = 'Performance: benchmark menunjukkan bottleneck. Cek storage health/ruang kosong, RAM, suhu, power plan, dan proses background sebelum maintenance dianggap selesai.';
    }
    if ($highStartupRisk > 0) {
        $critical[] = 'Startup high risk ' . $highStartupRisk . ' item';
        $notes[] = 'Startup/security: review high risk dahulu, terutama Temp/AppData, PowerShell encoded/download, scheduled task script, mshta/wscript/cscript, atau publisher tidak jelas.';
    } elseif ($startupScore < 85) {
        $notes[] = 'Startup: review item medium risk dan service auto yang tidak berjalan. Disable hanya setelah dipastikan bukan aplikasi kerja user.';
    }
    if (!$notes) {
        $notes[] = 'Kondisi analisa terlihat wajar. Lanjutkan maintenance berkala, bandingkan skor antar PC sejenis, dan fokus pada kebersihan fisik serta update rutin.';
    }
    $overallScore = (int)round(($hardwareScore + $softwareScore + max(0, $deviceScore) + $benchmarkScore + max(0, $startupScore)) / 5);
    $overallStatus = $overallScore >= 85 ? 'Sehat' : ($overallScore >= 70 ? 'Perlu review' : 'Prioritas maintenance');
    $prefix = 'AI Overall: ' . $overallStatus . ' (' . $overallScore . '/100). ';
    if ($critical) {
        $prefix .= 'Temuan kritis: ' . implode(', ', $critical) . '. ';
    }

    return [
        'hardware_score' => max(0, $hardwareScore),
        'software_score' => max(0, $softwareScore),
        'device_score' => max(0, $deviceScore),
        'benchmark_score' => max(0, $benchmarkScore),
        'startup_score' => max(0, $startupScore),
        'recommendation' => $prefix . implode(' ', $notes),
    ];
}

function normalize_count(mixed $value): int
{
    if (!is_array($value)) {
        return 0;
    }
    if ($value === []) {
        return 0;
    }
    return array_keys($value) === range(0, count($value) - 1) ? count($value) : 1;
}

function risk_level_count(mixed $value, string $level): int
{
    if (!is_array($value)) {
        return 0;
    }
    $items = array_keys($value) === range(0, count($value) - 1) ? $value : [$value];
    $count = 0;
    foreach ($items as $item) {
        if (is_array($item) && strcasecmp((string)($item['RiskLevel'] ?? ''), $level) === 0) {
            $count++;
        }
    }
    return $count;
}
