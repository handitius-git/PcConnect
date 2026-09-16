<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

function ensure_session_started(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent($file, $line)) {
        throw new RuntimeException('Session tidak dapat dimulai karena output sudah dikirim dari ' . $file . ':' . $line . '.');
    }
    session_start();
}

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
        try {
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (Throwable $ignored) {
        }
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

function js_value(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
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

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $token)) {
        http_response_code(419);
        exit('CSRF token tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
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
    echo 'body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#172033}a{color:inherit}header{background:#182235;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}.brand{font-weight:700}.nav{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.nav a,.nav summary{color:#eaf0ff;text-decoration:none;padding:8px 10px;border-radius:6px;cursor:pointer;list-style:none;user-select:none}.nav a:hover,.nav summary:hover{background:rgba(255,255,255,.12)}.nav details{position:relative}.nav details[open]{z-index:40}.nav details[open] summary{background:rgba(255,255,255,.14)}.nav .menu{position:absolute;left:0;top:calc(100% + 4px);min-width:240px;white-space:nowrap;max-height:calc(100vh - 80px);overflow-y:auto;background:#fff;border:1px solid #d9e1ee;border-radius:8px;box-shadow:0 16px 35px rgba(15,23,42,.18);padding:6px;z-index:50}.nav details:last-of-type .menu{left:auto;right:0}.nav .menu a{display:block;color:#172033;padding:9px 12px;border-radius:6px;text-decoration:none}.nav .menu a:hover{background:#f1f5f9}main{max-width:1180px;margin:0 auto;padding:22px}.auth{max-width:420px;margin:64px auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 10px 30px rgba(16,24,40,.08)}.panel,.stat{background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:18px;margin-bottom:16px}.hero{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(260px,.8fr);gap:16px;align-items:stretch}.grid{display:grid;gap:16px}.two{grid-template-columns:repeat(2,minmax(0,1fr))}.three{grid-template-columns:repeat(3,minmax(0,1fr))}.four{grid-template-columns:repeat(4,minmax(0,1fr))}.six{grid-template-columns:repeat(6,minmax(0,1fr))}.split{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-block;border:1px solid #c7d0df;background:#fff;color:#172033;text-decoration:none;border-radius:6px;padding:9px 12px;cursor:pointer;font:inherit}.btn.primary{background:#1457d9;border-color:#1457d9;color:#fff}.btn.good{background:#0f8a5f;border-color:#0f8a5f;color:#fff}.btn.danger{background:#b91c1c;border-color:#b91c1c;color:#fff}label{display:block;font-weight:600;margin:12px 0 6px}input,select,textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:9px;font:inherit}textarea{min-height:120px}table{width:100%;border-collapse:collapse;background:#fff}th,td{border-bottom:1px solid #e2e8f0;text-align:left;padding:10px;vertical-align:top}th{background:#f8fafc}.badge{display:inline-block;border-radius:999px;background:#e8eef7;padding:4px 8px;font-size:12px}.badge.ok{background:#dcfce7;color:#166534}.badge.danger{background:#fee2e2;color:#991b1b}.muted{color:#64748b}.flash{padding:12px 14px;border-radius:6px;margin-bottom:16px;background:#e7f7ef;color:#14532d}.flash.err{background:#fee2e2;color:#991b1b}.stat strong{display:block;font-size:36px}.stat span{font-size:13px}.label-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.photo-grid img{width:100%;border-radius:6px;border:1px solid #d9e1ee}.qr-label{break-inside:avoid;background:#fff;border:1px solid #222;padding:12px;display:flex;flex-direction:column;gap:4px}.qr-box{display:grid;grid-template-columns:repeat(9,1fr);gap:2px;width:126px;height:126px;margin:8px 0}.qr-box i,.qr-box span{display:block}.qr-box i{background:#111}.qr-box span{background:#fff}@media(max-width:820px){.hero,.two,.three,.four,.six{grid-template-columns:1fr}.nav details{position:static}.nav .menu{position:static;box-shadow:none;margin-top:4px;white-space:normal}main{padding:14px}table{display:block;overflow-x:auto}}@media print{header,.no-print,.btn{display:none!important}body{background:#fff}main{max-width:none;padding:0}.qr-label{page-break-inside:avoid}}';
    echo '.qr-img{width:126px;height:126px;object-fit:contain;margin:8px 0;display:block}.nav .menu summary{display:flex;align-items:center;justify-content:space-between;color:#172033;padding:9px 12px;cursor:pointer;user-select:none;font-weight:600;border-radius:6px}.nav .menu summary:hover{background:#f1f5f9}.nav .menu summary::after{content:"▾";font-size:11px;color:#64748b;margin-left:10px;transition:transform .2s}.nav .menu details[open]>summary::after{transform:rotate(180deg)}.nav .menu details{position:static}.nav .menu details .submenu{border-left:3px solid #dbe5f3;margin:0 6px 6px 12px;padding-left:6px}.nav .menu details .submenu a{padding:8px 10px}';
    echo '</style></head><body><header><div class="brand">PcConnect</div>';
    if ($user) {
        echo '<nav class="nav">';
        if (is_full_admin($user)) {
            echo '<a href="' . route_url('dashboard') . '">Dashboard</a>';
            echo '<details name="nav_top"><summary>Maintenance</summary><div class="menu"><details><summary>Preventive Maintenance</summary><div class="submenu"><a href="' . route_url('maintenance') . '">Schedule Maintenance</a></div></details><details><summary>Corrective & Service</summary><div class="submenu"><a href="' . route_url('tickets') . '">Tiket & Troubleshooting</a><a href="' . route_url('mobile_service') . '" target="_blank">📱 Mobile Field Service (Teknisi)</a><a href="' . route_url('walkarounds') . '">Patroli / Walkaround</a></div></details></div></details>';
            echo '<details name="nav_top"><summary>Reports</summary><div class="menu"><a href="' . route_url('reports') . '">Report Preventive Maintenance</a><a href="' . route_url('maintenance_status_report') . '">Report Status PC/Printer</a><a href="' . route_url('corrective_repairs') . '">Report Corrective Maintenance</a><a href="' . route_url('asset_movements') . '">Report Mutasi Aset</a><a href="' . route_url('report_asset_loans') . '">Report Peminjaman Aset</a></div></details>';
            echo '<details name="nav_top"><summary>Manajemen Aset</summary><div class="menu"><a href="' . route_url('asset_items') . '">Unit Aset</a><a href="' . route_url('asset_loans') . '">Peminjaman Aset</a><a href="' . route_url('pcs') . '">Pendataan Khusus Computer</a><a href="' . route_url('asset_movements') . '">Mutasi / Tukar Pasang</a></div></details>';
            echo '<details name="nav_top"><summary>Setup</summary><div class="menu"><details><summary>Master</summary><div class="submenu"><details><summary>Aset</summary><div class="submenu"><a href="' . route_url('asset_groups') . '">Master Komoditas</a><a href="' . route_url('asset_types') . '">Master Kategori</a><a href="' . route_url('asset_brands') . '">Master Brand / Merk</a><a href="' . route_url('asset_master_items') . '">Master Barang (Katalog Model)</a><a href="' . route_url('asset_locations') . '">Master Lokasi</a><a href="' . route_url('asset_identifiers') . '">Identifier Aset</a><a href="' . route_url('asset_specifications') . '">Spesifikasi Aset</a></div></details><details><summary>Preventive Maintenance</summary><div class="submenu"><a href="' . route_url('jobs') . '">Job Desk Preventive Maintenance</a><a href="' . route_url('asset_maintenance_templates') . '">Template Maintenance</a></div></details><details><summary>Corrective Maintenance</summary><div class="submenu"><a href="' . route_url('corrective_job_desks') . '">Job Desk Corrective Maintenance</a></div></details><a href="' . route_url('asset_companies') . '">Company</a><a href="' . route_url('master_pengguna') . '">Master Pengguna</a><a href="' . route_url('users') . '">Users</a></div></details><a href="' . route_url('employee_source') . '">Employee & Company Source</a><a href="' . route_url('labels') . '">QR Label Unit Aset</a></div></details>';
        } elseif (can_manage_maintenance($user)) {
            echo '<a href="' . route_url('dashboard') . '">Dashboard</a>';
            echo '<details name="nav_top"><summary>Maintenance</summary><div class="menu"><details><summary>Preventive Maintenance</summary><div class="submenu"><a href="' . route_url('maintenance') . '">Schedule Maintenance</a></div></details><details><summary>Corrective & Service</summary><div class="submenu"><a href="' . route_url('tickets') . '">Tiket & Troubleshooting</a><a href="' . route_url('mobile_service') . '" target="_blank">📱 Mobile Field Service (Teknisi)</a><a href="' . route_url('walkarounds') . '">Patroli / Walkaround</a></div></details></div></details>';
            echo '<details name="nav_top"><summary>Manajemen Aset</summary><div class="menu"><a href="' . route_url('asset_items') . '">Unit Aset</a><a href="' . route_url('asset_loans') . '">Peminjaman Aset</a></div></details>';
            echo '<details name="nav_top"><summary>Reports</summary><div class="menu"><a href="' . route_url('reports') . '">Report Preventive Maintenance</a><a href="' . route_url('maintenance_status_report') . '">Report Status PC/Printer</a><a href="' . route_url('corrective_repairs') . '">Report Corrective Maintenance</a><a href="' . route_url('asset_movements') . '">Report Mutasi Aset</a><a href="' . route_url('report_asset_loans') . '">Report Peminjaman Aset</a></div></details>';
        } else {
            echo '<details name="nav_top"><summary>Maintenance</summary><div class="menu"><details><summary>Preventive Maintenance</summary><div class="submenu"><a href="' . route_url('maintenance') . '">Schedule Maintenance</a></div></details><details><summary>Corrective & Service</summary><div class="submenu"><a href="' . route_url('tickets') . '">Tiket & Troubleshooting</a><a href="' . route_url('mobile_service') . '" target="_blank">📱 Mobile Field Service (Teknisi)</a><a href="' . route_url('walkarounds') . '">Patroli / Walkaround</a></div></details></div></details>';
            echo '<details name="nav_top"><summary>Manajemen Aset</summary><div class="menu"><a href="' . route_url('asset_loans') . '">Peminjaman Aset</a></div></details>';
            echo '<details name="nav_top"><summary>Reports</summary><div class="menu"><a href="' . route_url('reports') . '">Report Preventive Maintenance</a><a href="' . route_url('maintenance_status_report') . '">Report Status PC/Printer</a><a href="' . route_url('corrective_repairs') . '">Report Corrective Maintenance</a><a href="' . route_url('asset_movements') . '">Report Mutasi Aset</a><a href="' . route_url('report_asset_loans') . '">Report Peminjaman Aset</a></div></details>';
        }
        echo '<a href="' . route_url('logout') . '">Logout</a></nav>';
        echo '<script>'
            . 'var navDetails = document.querySelectorAll(".nav>details");'
            . 'document.addEventListener("click",function(e){if(!e.target.closest(".nav details")){navDetails.forEach(function(d){d.removeAttribute("open")})}});'
            . 'navDetails.forEach(function(d){'
            . 'd.addEventListener("toggle",function(){if(d.open){navDetails.forEach(function(other){if(other!==d)other.removeAttribute("open")})}});'
            . 'd.addEventListener("mouseenter",function(){var anyOpen=Array.from(navDetails).some(function(x){return x.open});if(anyOpen&&!d.open){navDetails.forEach(function(x){x.removeAttribute("open")});d.setAttribute("open","")}});'
            . '});'
            . 'document.querySelectorAll(".nav .menu a").forEach(function(a){a.addEventListener("click",function(){var top=a.closest(".nav>details");if(top)top.removeAttribute("open")})});'
            . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){navDetails.forEach(function(d){d.removeAttribute("open")})}});'
            . '</script>';
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
            echo '<nav class="mobile-nav"><a href="' . route_url('asset_items') . '">Unit Aset</a><a href="' . route_url('maintenance') . '">Schedule</a><a href="' . route_url('reports') . '">Reports</a><a href="' . route_url('logout') . '">Logout</a></nav>';
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

if (!function_exists('db_table_exists')) {
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
}

function find_asset_by_code(PDO $pdo, string $code): array
{
    $clean = strtoupper(trim($code));
    if ($clean === '') {
        return ['', '', []];
    }
    [$assetId, $security] = parse_asset_code($clean);
    if ($assetId === '') {
        $assetId = $clean;
        $security = '';
    }

    // 1. PRIORITAS UTAMA: Cari langsung di tabel asset_items (Unit Aset)
    if (db_table_exists($pdo, 'asset_items')) {
        $stmtAi = $pdo->prepare('SELECT ai.*, ag.group_name, ag.group_code, at.type_name, at.type_code, c.company_name, loc.location_name
                                 FROM asset_items ai
                                 LEFT JOIN asset_groups ag ON ag.id = ai.asset_group_id
                                 LEFT JOIN asset_types at ON at.id = ai.asset_type_id
                                 LEFT JOIN asset_companies c ON c.id = ai.company_id
                                 LEFT JOIN asset_locations loc ON loc.id = ai.location_id
                                 WHERE (ai.asset_code = ? OR ai.asset_code = ?) AND ai.status <> "inactive"
                                 LIMIT 1');
        $stmtAi->execute([$clean, $assetId]);
        $ai = $stmtAi->fetch();

        if ($ai) {
            // Jika unit merupakan Parent Bundle ('group'), muat anggota anak bundlingnya
            if (($ai['asset_mode'] ?? '') === 'group') {
                $stmtC = $pdo->prepare('SELECT aim.*, c.id child_id, c.asset_code child_asset_code, c.asset_name child_asset_name, c.brand child_brand, c.model child_model
                                        FROM asset_item_members aim
                                        JOIN asset_items c ON c.id = aim.child_asset_item_id
                                        WHERE aim.parent_asset_item_id = ? AND aim.detached_at IS NULL');
                $stmtC->execute([(int)$ai['id']]);
                $ai['bundle_children'] = $stmtC->fetchAll();
            } elseif (($ai['asset_mode'] ?? '') === 'child') {
                // Jika unit merupakan anak anggota bundle, muat info induknya
                $stmtP = $pdo->prepare('SELECT aim.*, p.id parent_id, p.asset_code parent_asset_code, p.asset_name parent_asset_name
                                        FROM asset_item_members aim
                                        JOIN asset_items p ON p.id = aim.parent_asset_item_id
                                        WHERE aim.child_asset_item_id = ? AND aim.detached_at IS NULL LIMIT 1');
                $stmtP->execute([(int)$ai['id']]);
                $ai['parent_bundle'] = $stmtP->fetch() ?: null;
            }

            // Hubungkan relasi PC / Printer jika ada untuk kompatibilitas
            if (db_table_exists($pdo, 'pcs')) {
                $stP = $pdo->prepare('SELECT pc_id FROM pcs WHERE asset_item_id = ? LIMIT 1');
                $stP->execute([(int)$ai['id']]);
                $pcIdFound = $stP->fetchColumn();
                if ($pcIdFound) {
                    $ai['pc_id'] = (string)$pcIdFound;
                }
            }
            if (db_table_exists($pdo, 'printers')) {
                $stPr = $pdo->prepare('SELECT prn_id FROM printers WHERE asset_item_id = ? LIMIT 1');
                $stPr->execute([(int)$ai['id']]);
                $prnIdFound = $stPr->fetchColumn();
                if ($prnIdFound) {
                    $ai['printer_id'] = (string)$prnIdFound;
                }
            }

            return ['asset_item', (string)$ai['id'], $ai];
        }
    }

    // 2. Fallback: Legacy maintenance_assets jika masih ada kode MNT lama
    if (db_table_exists($pdo, 'maintenance_assets')) {
        $mntCode = str_starts_with($assetId, 'MNT-') ? $assetId : 'MNT-' . $assetId;
        $stmt = $pdo->prepare('SELECT ma.* FROM maintenance_assets ma 
                               WHERE (ma.maintenance_asset_code=? OR ma.maintenance_asset_code=?) 
                               AND ma.status<>"inactive" LIMIT 1');
        $stmt->execute([$assetId, $mntCode]);
        $maintenanceAsset = $stmt->fetch();

        if (!$maintenanceAsset) {
            $mntCodeClean = str_starts_with($clean, 'MNT-') ? $clean : 'MNT-' . $clean;
            $stmt = $pdo->prepare('SELECT ma.* FROM maintenance_assets ma 
                                   WHERE (ma.maintenance_asset_code=? OR ma.maintenance_asset_code=?) 
                                   AND ma.status<>"inactive" LIMIT 1');
            $stmt->execute([$clean, $mntCodeClean]);
            $maintenanceAsset = $stmt->fetch();
        }

        if ($maintenanceAsset) {
            if (!empty($maintenanceAsset['asset_item_id']) && db_table_exists($pdo, 'asset_items')) {
                $stAi = $pdo->prepare('SELECT * FROM asset_items WHERE id = ? LIMIT 1');
                $stAi->execute([(int)$maintenanceAsset['asset_item_id']]);
                $ai = $stAi->fetch();
                if ($ai) {
                    return ['asset_item', (string)$ai['id'], $ai];
                }
            }
            return ['maintenance_asset', (string)$maintenanceAsset['id'], $maintenanceAsset];
        }
    }

    // 3. Fallback: Printer & PC lama
    if (substr($assetId, 0, 3) === 'PRN' && db_table_exists($pdo, 'printers')) {
        $stmt = $pdo->prepare('SELECT * FROM printers WHERE prn_id=?');
        $stmt->execute([$assetId]);
        $printer = $stmt->fetch();
        if ($printer && ($security === '' || hash_equals((string)$printer['security_code'], $security))) {
            return ['printer', $assetId, $printer];
        }
        return ['', '', []];
    }

    if (db_table_exists($pdo, 'pcs')) {
        $stmt = $pdo->prepare('SELECT * FROM pcs WHERE pc_id=?');
        $stmt->execute([$assetId]);
        $pc = $stmt->fetch();
        if ($pc && ($security === '' || hash_equals((string)$pc['security_code'], $security))) {
            return ['pc', $assetId, $pc];
        }
    }

    return ['', '', []];
}

function validate_asset_item_scan_location(PDO $pdo, int $assetItemId, ?float $scanLat, ?float $scanLng): ?string
{
    if (!db_table_exists($pdo, 'asset_items')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT latitude, longitude, location_radius_m, location_label, asset_name, asset_code FROM asset_items WHERE id=?');
    $stmt->execute([$assetItemId]);
    $ai = $stmt->fetch();
    if (!$ai || $ai['latitude'] === null || $ai['longitude'] === null || $ai['latitude'] === '' || $ai['longitude'] === '') {
        return null;
    }
    if ($scanLat === null || $scanLng === null) {
        return 'GPS teknisi belum terbaca. Aktifkan izin Location/GPS di browser lalu scan ulang.';
    }
    $radius = max(1, (int)($ai['location_radius_m'] ?? 5));
    if (!function_exists('geo_distance_m')) {
        return null;
    }
    $distance = geo_distance_m((float)$ai['latitude'], (float)$ai['longitude'], $scanLat, $scanLng);
    if ($distance > $radius) {
        return 'Scan ditolak. Jarak dari titik aset ' . round($distance, 1) . ' meter, maksimal ' . $radius . ' meter' . ($ai['location_label'] ? ' (' . $ai['location_label'] . ')' : '') . '.';
    }
    return null;
}

function validate_maintenance_asset_scan_location(PDO $pdo, int $maintenanceAssetId, ?float $scanLat, ?float $scanLng): ?string
{
    if (!db_table_exists($pdo, 'maintenance_assets')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT latitude, longitude, location_radius_m, location_label, name FROM maintenance_assets WHERE id=?');
    $stmt->execute([$maintenanceAssetId]);
    $ma = $stmt->fetch();
    if (!$ma || $ma['latitude'] === null || $ma['longitude'] === null || $ma['latitude'] === '' || $ma['longitude'] === '') {
        return null;
    }
    if ($scanLat === null || $scanLng === null) {
        return 'GPS teknisi belum terbaca. Aktifkan izin Location/GPS di browser lalu scan ulang.';
    }
    $radius = max(1, (int)($ma['location_radius_m'] ?? 5));
    if (!function_exists('geo_distance_m')) {
        return null;
    }
    $distance = geo_distance_m((float)$ma['latitude'], (float)$ma['longitude'], $scanLat, $scanLng);
    if ($distance > $radius) {
        return 'Scan ditolak. Jarak dari titik aset ' . round($distance, 1) . ' meter, maksimal ' . $radius . ' meter' . ($ma['location_label'] ? ' (' . $ma['location_label'] . ')' : '') . '.';
    }
    return null;
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

function save_uploaded_photos(string $field, int $scheduleId, string $stage = '', ?array &$metadata = null): array
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
    $slotIndex = 0;
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
                    'group' => $stage !== '' ? $stage : $field,
                    'slot' => (string)$slotIndex,
                    'field' => $field,
                    'url' => $url,
                    'original_name' => (string)($_FILES[$field]['name'][$i] ?? ''),
                    'original_type' => (string)($_FILES[$field]['type'][$i] ?? ''),
                    'original_size' => (int)($_FILES[$field]['size'][$i] ?? 0),
                    'server_saved_at' => date('c'),
                ];
            }
            $slotIndex++;
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

function save_signature_data(string $dataUrl, int $scheduleId): ?string
{
    return save_signature_image($dataUrl, $scheduleId);
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

function company_options(PDO $pdo, ?int $selected = null): string
{
    $html = '<option value="">- Tanpa company -</option>';
    if (!db_table_exists($pdo, 'asset_companies')) {
        return $html;
    }
    foreach ($pdo->query('SELECT id, company_code, company_name FROM asset_companies WHERE is_active=1 ORDER BY company_name') as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['company_code'] . ' - ' . $row['company_name']) . '</option>';
    }
    return $html;
}

function asset_item_options(PDO $pdo, ?int $selected = null, ?string $onlyUnsyncedForPcId = null, bool $allowChildAssets = false): string
{
    $html = '<option value="">-- Buat Baru Otomatis / Pilih Aset --</option>';
    if (!db_table_exists($pdo, 'asset_items')) {
        return $html;
    }

    $where = [];
    $params = [];

    // Filter agar asset dengan Mode 'Bundle (Child Asset)' tidak muncul untuk sinkronisasi PC/Printer/Maintenance
    if (!$allowChildAssets) {
        $where[] = '((ai.asset_mode IS NULL OR ai.asset_mode <> "child") AND NOT EXISTS (SELECT 1 FROM asset_item_members aim WHERE aim.child_asset_item_id = ai.id AND aim.detached_at IS NULL) OR ai.id = ?)';
        $params[] = (int)$selected;
    }

    if ($onlyUnsyncedForPcId !== null && db_table_exists($pdo, 'pcs') && db_column_exists($pdo, 'pcs', 'asset_item_id')) {
        if ($onlyUnsyncedForPcId !== '') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM pcs p WHERE p.asset_item_id = ai.id AND p.asset_item_id IS NOT NULL AND p.pc_id <> ?)';
            $params[] = $onlyUnsyncedForPcId;
        } else {
            $where[] = 'NOT EXISTS (SELECT 1 FROM pcs p WHERE p.asset_item_id = ai.id AND p.asset_item_id IS NOT NULL)';
        }
    }

    $sql = 'SELECT ai.id, ai.asset_code, ai.asset_name, ai.asset_type, ai.asset_category, ai.asset_mode FROM asset_items ai';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ai.asset_code';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $sel = (int)$row['id'] === (int)$selected ? ' selected' : '';
        $rawMode = (string)($row['asset_mode'] ?? 'standalone');
        $modeLabel = $rawMode === 'group' ? 'Bundle (Parent)' : ($rawMode === 'child' ? 'Bundle (Child)' : 'Single');
        $html .= '<option value="' . e($row['id']) . '"' . $sel . '>' . e($row['asset_code'] . ' - ' . $row['asset_name'] . ' (' . ($row['asset_category'] ?? '-') . ' / ' . $row['asset_type'] . ' / ' . $modeLabel . ')') . '</option>';
    }
    return $html;
}

function asset_item_categories(?PDO $pdo = null): array
{
    $fixed = ['Computer', 'Printer', 'Kendaraan', 'Lain-lain'];
    try {
        $pdo = $pdo ?? Database::pdo();
        if (!db_table_exists($pdo, 'asset_categories')) {
            return $fixed;
        }
        $rows = $pdo->query('SELECT category_name FROM asset_categories WHERE is_active=1 ORDER BY is_system DESC, category_name')->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) {
            return $fixed;
        }
        return array_values(array_unique(array_merge($fixed, array_map('strval', $rows))));
    } catch (Throwable $ignored) {
        return $fixed;
    }
}

function asset_category_options(PDO $pdo, ?string $selected = null): string
{
    $html = '<option value="">- Pilih Kategori Asset -</option>';
    foreach (asset_item_categories($pdo) as $cat) {
        $sel = ((string)$selected === $cat) ? ' selected' : '';
        $html .= '<option value="' . e($cat) . '"' . $sel . '>' . e($cat) . '</option>';
    }
    return $html;
}

function asset_item_row(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !db_table_exists($pdo, 'asset_items')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM asset_items WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function asset_item_member_row(PDO $pdo, int $memberId): ?array
{
    if ($memberId <= 0 || !db_table_exists($pdo, 'asset_item_members')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT aim.*, p.asset_code parent_asset_code, c.asset_code child_asset_code FROM asset_item_members aim JOIN asset_items p ON p.id=aim.parent_asset_item_id JOIN asset_items c ON c.id=aim.child_asset_item_id WHERE aim.id=?');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function active_parent_asset_item(PDO $pdo, int $childAssetItemId): ?array
{
    if ($childAssetItemId <= 0 || !db_table_exists($pdo, 'asset_item_members')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT aim.*, p.asset_code parent_asset_code, p.asset_name parent_asset_name FROM asset_item_members aim JOIN asset_items p ON p.id=aim.parent_asset_item_id WHERE aim.child_asset_item_id=? AND aim.detached_at IS NULL LIMIT 1');
    $stmt->execute([$childAssetItemId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function asset_item_contains_child(PDO $pdo, int $parentCandidateId, int $targetChildId): bool
{
    if ($parentCandidateId <= 0 || $targetChildId <= 0 || !db_table_exists($pdo, 'asset_item_members')) {
        return false;
    }
    $visited = [];
    $queue = [$parentCandidateId];
    while ($queue) {
        $current = array_shift($queue);
        if ($current === $targetChildId) {
            return true;
        }
        if (isset($visited[$current])) {
            continue;
        }
        $visited[$current] = true;
        $stmt = $pdo->prepare('SELECT child_asset_item_id FROM asset_item_members WHERE parent_asset_item_id=? AND detached_at IS NULL');
        $stmt->execute([$current]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $next) {
            $next = (int)$next;
            if (!isset($visited[$next])) {
                $queue[] = $next;
            }
        }
    }
    return false;
}

function asset_bundle_row(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !db_table_exists($pdo, 'asset_bundles')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM asset_bundles WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function asset_bundle_member_row(PDO $pdo, int $memberId): ?array
{
    if ($memberId <= 0 || !db_table_exists($pdo, 'asset_bundle_members')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT m.*, b.company_id, b.maintenance_asset_code FROM asset_bundle_members m JOIN asset_bundles b ON b.id=m.bundle_id WHERE m.id=?');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    return $row ?: null;
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

function detail_block(string $title, ?string $json): void
{
    $data = $json ? json_decode($json, true) : null;
    echo '<div class="panel"><h2>' . e($title) . '</h2>';
    echo $data ? render_analysis_section($title, $data) : '<p>Belum ada data.</p>';
    echo '</div>';
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
        return $html;
    }
    if ($title === 'Startup Analisa') {
        $intel = array_slice(normalize_list($data['startup_intelligence'] ?? []), 0, 40);
        $suspicious = array_slice(normalize_list($data['suspicious_background_processes'] ?? []), 0, 30);
        $html = ai_insight_box('AI Startup Insight', startup_ai_insights($data));
        $html .= '<h3>AI Startup Risk Priority</h3>' . ($intel ? risk_cards($intel, 'startup') : '<p>Belum ada startup intelligence.</p>');
        $html .= '<h3>AI Background Risk Review</h3>' . ($suspicious ? risk_cards($suspicious, 'background') : '<p>Tidak ada proses mencurigakan.</p>');
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
        $lines[] = 'RAM di bawah 8 GB: prioritas upgrade jika membuka aplikasi berat.';
    } elseif ($ram >= 8 && $ram < 16) {
        $lines[] = 'RAM cukup untuk pekerjaan standar.';
    } elseif ($ram >= 16) {
        $lines[] = 'RAM sudah optimal.';
    }
    if (preg_match('/celeron|atom|pentium/i', $processor)) {
        $lines[] = 'Processor kelas entry-level.';
    }
    $storageText = summarize_items($data['storage'] ?? []);
    if (preg_match('/HDD|Hard/i', $storageText)) {
        $lines[] = 'Storage terindikasi HDD: upgrade SSD akan meningkatkan performa secara signifikan.';
    }
    return $lines ?: ['Data hardware wajar.'];
}

function software_ai_insights(array $data): array
{
    $lines = [];
    if (empty($data['antivirus'])) {
        $lines[] = 'Antivirus tidak terbaca: pastikan proteksi aktif.';
    }
    return $lines ?: ['Software terlihat wajar.'];
}

function device_ai_insights(array $data): array
{
    $warnings = normalize_list($data['driver_warnings'] ?? []);
    $lines = [];
    if ($warnings) {
        $lines[] = 'Device Manager memberi warning pada ' . count($warnings) . ' item.';
    }
    return $lines ?: ['Tidak ada warning perangkat.'];
}

function benchmark_ai_insights(array $data): array
{
    $lines = [];
    $write = isset($data['storage_write_mbps']) ? (float)$data['storage_write_mbps'] : null;
    if ($write !== null && $write < 80) {
        $lines[] = 'Storage write rendah, periksa kondisi drive.';
    }
    return $lines ?: ['Benchmark dalam batas normal.'];
}

function startup_ai_insights(array $data): array
{
    $intel = normalize_list($data['startup_intelligence'] ?? []);
    $high = 0;
    foreach ($intel as $row) {
        if (is_array($row) && strcasecmp((string)($row['RiskLevel'] ?? ''), 'High') === 0) {
            $high++;
        }
    }
    $lines = [];
    if ($high > 0) {
        $lines[] = 'Ditemukan ' . $high . ' startup high risk.';
    }
    return $lines ?: ['Startup normal.'];
}

function risk_cards(mixed $rows, string $type): string
{
    $items = normalize_list($rows);
    if (!$items) {
        return '<p>Belum ada data.</p>';
    }
    $items = array_slice($items, 0, 20);
    $html = '<div class="risk-list">';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $level = (string)($item['RiskLevel'] ?? 'Low');
        $levelClass = strtolower($level) === 'high' ? 'risk-high' : (strtolower($level) === 'medium' ? 'risk-medium' : 'risk-low');
        $title = (string)($item['DeviceName'] ?? ($item['Name'] ?? 'Item'));
        $path = (string)($item['Path'] ?? ($item['Command'] ?? '-'));
        $html .= '<article class="risk-card"><div class="risk-head"><div><strong>' . e($title) . '</strong></div><div><span class="risk-pill ' . $levelClass . '">' . e($level) . '</span></div></div>';
        if ($path !== '') {
            $html .= '<div class="risk-path">' . e($path) . '</div>';
        }
        $html .= '</article>';
    }
    return $html . '</div>';
}

function legacy_storage_write(array $data): string
{
    return isset($data['storage_write_8mb_ms']) ? round((float)$data['storage_write_8mb_ms'], 2) . ' ms legacy' : '-';
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

function normalize_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    if ($value === []) {
        return [];
    }
    return array_keys($value) === range(0, count($value) - 1) ? $value : [$value];
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
        $parts[] = trim(($item['Model'] ?? 'Storage') . $size);
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
        $parts[] = trim((string)($item['Name'] ?? 'GPU'));
    }
    return $parts ? implode("\n", $parts) : '-';
}

if (!function_exists('asset_group_options')) {
    function asset_group_options(PDO $pdo, int $selected = 0, bool $includeEmpty = false, string $emptyLabel = '- Pilih Grup Aset -'): string
    {
        $h = $includeEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
        if (!db_table_exists($pdo, 'asset_groups')) {
            return $h;
        }
        foreach ($pdo->query('SELECT * FROM asset_groups WHERE is_active=1 ORDER BY group_name') as $r) {
            $sel = (int)$r['id'] === $selected ? ' selected' : '';
            $h .= '<option value="' . (int)$r['id'] . '"' . $sel . '>' . e($r['group_code'] . ' - ' . $r['group_name']) . '</option>';
        }
        return $h;
    }
}

if (!function_exists('maintenance_asset_code_seed')) {
    function maintenance_asset_code_seed(string $prefix, string $assetId): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', $assetId));
        return trim($prefix, '-') . '-' . $clean;
    }
}

if (!function_exists('unique_maintenance_asset_code')) {
    function unique_maintenance_asset_code(PDO $pdo, string $base, ?int $ignoreId = null): string
    {
        $code = strtoupper(trim($base));
        if ($code === '') {
            $code = 'MNT-' . date('YmdHis');
        }
        $try = $code;
        $n = 1;
        do {
            $sql = 'SELECT id FROM maintenance_assets WHERE maintenance_asset_code = ?';
            $params = [$try];
            if ($ignoreId !== null && $ignoreId > 0) {
                $sql .= ' AND id <> ?';
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
}

if (!function_exists('corrective_action_type_options')) {
    function corrective_action_type_options(PDO $pdo, ?string $selected = null, ?int $assetGroupId = null, ?int $assetTypeId = null, ?string $jobDeskName = null): string
    {
        ensure_corrective_maintenance_schema($pdo);
        $options = '';
        try {
            $hasJobDesks = db_table_exists($pdo, 'corrective_job_desks');

            $params = [];
            $where = ['cat.is_active = 1'];

            if ($jobDeskName !== null && $jobDeskName !== '') {
                $where[] = "(cat.job_desk_name = ?" . ($hasJobDesks ? " OR cjd.job_desk_name = ?" : "") . ")";
                $params[] = $jobDeskName;
                if ($hasJobDesks) {
                    $params[] = $jobDeskName;
                }
            } elseif ($assetGroupId !== null && $assetGroupId > 0) {
                if ($assetTypeId !== null && $assetTypeId > 0) {
                    // Cari yang cocok dengan Group & Type, atau Group dengan Type universal (NULL/0)
                    $where[] = "((cat.asset_group_id = ? AND (cat.asset_type_id = ? OR cat.asset_type_id IS NULL OR cat.asset_type_id = 0))" 
                             . ($hasJobDesks ? " OR (cjd.asset_group_id = ? AND (cjd.asset_type_id = ? OR cjd.asset_type_id IS NULL OR cjd.asset_type_id = 0))" : "") . ")";
                    $params[] = $assetGroupId;
                    $params[] = $assetTypeId;
                    if ($hasJobDesks) {
                        $params[] = $assetGroupId;
                        $params[] = $assetTypeId;
                    }
                } else {
                    // Cari yang cocok dengan Group
                    $where[] = "((cat.asset_group_id = ? OR cat.asset_group_id IS NULL OR cat.asset_group_id = 0)" 
                             . ($hasJobDesks ? " OR (cjd.asset_group_id = ? OR cjd.asset_group_id IS NULL OR cjd.asset_group_id = 0)" : "") . ")";
                    $params[] = $assetGroupId;
                    if ($hasJobDesks) {
                        $params[] = $assetGroupId;
                    }
                }
            }

            $sql = "SELECT cat.action_code, cat.action_name, 
                           COALESCE(cat.job_desk_name, " . ($hasJobDesks ? "cjd.job_desk_name, " : "") . "'Tindakan Umum') AS desk_name,
                           cat.asset_group_id, cat.asset_type_id, cat.sort_order
                    FROM corrective_action_types cat
                    " . ($hasJobDesks ? "LEFT JOIN corrective_job_desks cjd ON cjd.job_desk_name COLLATE utf8mb4_unicode_ci = cat.job_desk_name COLLATE utf8mb4_unicode_ci" : "") . "
                    WHERE " . implode(' AND ', $where) . " 
                    ORDER BY " . ($assetTypeId ? "CASE WHEN COALESCE(cat.asset_type_id, " . ($hasJobDesks ? "cjd.asset_type_id, " : "") . "0) = " . (int)$assetTypeId . " THEN 0 ELSE 1 END, " : "") . "
                             cat.sort_order ASC, cat.action_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Jika hasil kosong karena filter spesifik, fallback ke semua yang aktif
            if (empty($rows)) {
                $sqlAll = "SELECT cat.action_code, cat.action_name, 
                                  COALESCE(cat.job_desk_name, " . ($hasJobDesks ? "cjd.job_desk_name, " : "") . "'Tindakan Umum') AS desk_name,
                                  cat.sort_order
                           FROM corrective_action_types cat
                           " . ($hasJobDesks ? "LEFT JOIN corrective_job_desks cjd ON cjd.job_desk_name COLLATE utf8mb4_unicode_ci = cat.job_desk_name COLLATE utf8mb4_unicode_ci" : "") . "
                           WHERE cat.is_active = 1 
                           ORDER BY cat.sort_order ASC, cat.action_name ASC";
                $rows = $pdo->query($sqlAll)->fetchAll(PDO::FETCH_ASSOC);
            }

            // Pastikan nilai yang sudah dipilih sebelumnya tetap ada di list
            if ($selected !== null && $selected !== '') {
                $foundSelected = false;
                foreach ($rows as $r) {
                    if ($r['action_code'] === $selected) {
                        $foundSelected = true;
                        break;
                    }
                }
                if (!$foundSelected) {
                    $stmtSel = $pdo->prepare("SELECT action_code, action_name, COALESCE(job_desk_name, 'Tindakan Lainnya') AS desk_name FROM corrective_action_types WHERE action_code = ? LIMIT 1");
                    $stmtSel->execute([$selected]);
                    $selRow = $stmtSel->fetch(PDO::FETCH_ASSOC);
                    if ($selRow) {
                        $rows[] = $selRow;
                    }
                }
            }

            // Cek grouping desk_name
            $desks = [];
            foreach ($rows as $r) {
                $desks[$r['desk_name']][] = $r;
            }

            if (count($desks) <= 1) {
                foreach ($rows as $r) {
                    $sel = ($selected !== null && $selected === $r['action_code']) ? ' selected' : '';
                    $options .= '<option value="' . e($r['action_code']) . '"' . $sel . '>' . e($r['action_name']) . '</option>';
                }
            } else {
                foreach ($desks as $dName => $items) {
                    $options .= '<optgroup label="' . e($dName) . '">';
                    foreach ($items as $r) {
                        $sel = ($selected !== null && $selected === $r['action_code']) ? ' selected' : '';
                        $options .= '<option value="' . e($r['action_code']) . '"' . $sel . '>' . e($r['action_name']) . '</option>';
                    }
                    $options .= '</optgroup>';
                }
            }
        } catch (Throwable $e) {
            // Fallback default
            $fallback = [
                'hardware_repair' => 'Reparasi / Service Hardware',
                'part_replacement' => 'Penggantian Sparepart / Komponen',
                'software_fix' => 'Troubleshooting Software / OS / Driver',
                'vendor_service' => 'Service Bengkel / Vendor Pihak Ketiga',
                'general_fix' => 'Pembersihan / Penyetelan / Lainnya',
            ];
            foreach ($fallback as $k => $v) {
                $sel = ($selected !== null && $selected === $k) ? ' selected' : '';
                $options .= '<option value="' . e($k) . '"' . $sel . '>' . e($v) . '</option>';
            }
        }
        return $options;
    }
}

if (!function_exists('corrective_action_type_label')) {
    function corrective_action_type_label(PDO $pdo, string $code): string
    {
        ensure_corrective_maintenance_schema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT action_name FROM corrective_action_types WHERE action_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $name = $stmt->fetchColumn();
            if ($name) {
                return (string)$name;
            }
        } catch (Throwable $e) {
        }
        return ucwords(str_replace('_', ' ', $code));
    }
}


