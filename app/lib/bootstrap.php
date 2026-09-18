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

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
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

if (!function_exists('enforce_idle_logout')) {
    function enforce_idle_logout(string $route): void
    {
        $route = (string)$route;
        if (substr($route, 0, 4) === 'api_' || in_array($route, ['login', 'logout', 'mobile_service_login', 'mobile_service_logout'], true) || empty($_SESSION['user_id'])) {
            return;
        }
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

function app_name(): string
{
    return (string)config_value('app_name', 'AsetConnect');
}

function is_full_admin(?array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function can_manage_maintenance(?array $user): bool
{
    return in_array(($user['role'] ?? ''), ['admin', 'maintenance_admin'], true);
}

function get_regulated_menus(): array
{
    return [
        'Transaksi Aset' => [
            'asset_items' => 'Unit Aset',
            'pcs' => 'Pendataan PC',
            'asset_loans' => 'Peminjaman Aset',
            'asset_movements' => 'Mutasi / Tukar Pasang',
        ],
        'Maintenance Aset' => [
            'maintenance' => 'Schedule PM',
            'tickets' => 'Tiket Corrective',
            'walkarounds' => 'Patroli Walkaround',
            'jobs' => 'Job Desk PM',
            'corrective_job_desks' => 'Job Desk Corrective',
            'mobile_service' => 'Mobile Service',
        ],
        'Reports & QR_Label' => [
            'labels' => 'QR Label Unit',
        ],
        'Data Master' => [
            'asset_groups' => 'Master Komoditas',
            'asset_types' => 'Master Kategori',
            'asset_brands' => 'Master Brand / Merk',
            'asset_master_items' => 'Master Barang (Katalog Model)',
            'asset_identifiers' => 'Identifier Aset',
            'asset_specifications' => 'Spesifikasi Aset',
            'asset_locations' => 'Master Lokasi',
            'asset_companies' => 'Company',
            'master_pengguna' => 'Master Pengguna',
        ],
        'Pengaturan & Setup' => [
            'users' => 'Akses Users',
        ]
    ];
}

function get_user_regulations(?string $role): array
{
    static $cache = [];
    if (!$role) {
        return [];
    }
    if (isset($cache[$role])) {
        return $cache[$role];
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['cached_role_regulations'][$role])) {
        $cache[$role] = $_SESSION['cached_role_regulations'][$role];
        return $cache[$role];
    }
    try {
        $pdo = Database::pdo();
        if (function_exists('db_table_exists') && db_table_exists($pdo, 'role_regulations')) {
            $stmt = $pdo->prepare('SELECT menu_key, can_view, can_create, can_edit, can_delete FROM role_regulations WHERE role = ?');
            $stmt->execute([$role]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $regs = [];
            foreach ($rows as $r) {
                $regs[$r['menu_key']] = [
                    'view' => (bool)$r['can_view'],
                    'create' => (bool)$r['can_create'],
                    'edit' => (bool)$r['can_edit'],
                    'delete' => (bool)$r['can_delete'],
                ];
            }
            $cache[$role] = $regs;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['cached_role_regulations'][$role] = $regs;
            }
            return $regs;
        }
    } catch (Throwable $e) {
    }
    return [];
}

function clear_role_regulations_cache(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['cached_role_regulations'])) {
        unset($_SESSION['cached_role_regulations']);
    }
}

function has_regulation(string $menuKey, string $action = 'view', ?array $user = null): bool
{
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) {
        return false;
    }
    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }
    $regs = get_user_regulations($role);
    if (!isset($regs[$menuKey])) {
        if ($role === 'maintenance_admin') {
            if (in_array($menuKey, ['maintenance', 'asset_items', 'tickets', 'walkarounds', 'asset_loans', 'asset_movements', 'jobs', 'corrective_job_desks', 'mobile_service'], true)) {
                return true;
            }
        } elseif ($role === 'technician') {
            if (in_array($menuKey, ['maintenance', 'tickets', 'jobs', 'corrective_job_desks', 'mobile_service'], true) && in_array($action, ['view', 'edit'], true)) {
                return true;
            }
        } elseif ($role === 'corrective_maintenance') {
            if (in_array($menuKey, ['tickets', 'walkarounds', 'corrective_job_desks', 'mobile_service'], true)) {
                return true;
            }
        } elseif ($role === 'loan_officer') {
            if ($menuKey === 'asset_loans') {
                return true;
            }
        }
        return false;
    }
    return !empty($regs[$menuKey][$action]);
}

function require_regulation(string $menuKey, string $action = 'view'): array
{
    $user = require_login();
    if (!has_regulation($menuKey, $action, $user)) {
        http_response_code(403);
        $actionName = match ($action) {
            'create' => 'menambah data',
            'edit' => 'mengubah data',
            'delete' => 'menghapus data',
            default => 'mengakses menu ini',
        };
        exit('Akses ditolak. Role Anda tidak memiliki izin untuk ' . e($actionName) . ' pada modul ini.');
    }
    return $user;
}

function render_header(string $title, ?array $user = null): void
{
    $flash = flash();
    $appName = app_name();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' - ' . e($appName) . '</title><style>';
    echo ':root{--sidebar-w:260px;--sidebar-collapsed-w:68px;--primary:#1457d9;--primary-hover:#0f46b3;--bg:#f5f7fb;--text:#1e293b;--sidebar-bg:#0f172a;--sidebar-hover:#1e293b;--sidebar-active:#1d4ed8;--sidebar-text:#94a3b8;--sidebar-text-active:#f8fafc;--border:#e2e8f0}';
    echo '*{box-sizing:border-box}body{margin:0;font-family:Segoe UI,-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5}a{color:inherit;text-decoration:none}';
    echo '.app-layout{display:flex;min-height:100vh;width:100%}';
    // Sidebar styles
    echo '.sidebar{width:var(--sidebar-w);background:var(--sidebar-bg);color:var(--sidebar-text);flex-shrink:0;position:fixed;top:0;bottom:0;left:0;z-index:100;display:flex;flex-direction:column;transition:width .22s cubic-bezier(0.4,0,0.2,1);box-shadow:2px 0 10px rgba(0,0,0,.15);user-select:none;overflow-x:hidden}';
    echo '.sidebar-resizer{position:absolute;top:0;right:0;width:7px;height:100%;cursor:ew-resize;z-index:120;background:transparent;transition:background .15s}';
    echo '.sidebar-resizer:hover,.app-layout.resizing .sidebar-resizer{background:rgba(56,189,248,.35)}';
    echo '.sidebar-resizer::after{content:"";position:absolute;top:50%;right:2px;transform:translateY(-50%);width:3px;height:36px;border-radius:2px;background:rgba(255,255,255,.2);transition:background .15s}';
    echo '.sidebar-resizer:hover::after,.app-layout.resizing .sidebar-resizer::after{background:#38bdf8}';
    echo '.app-layout.resizing .sidebar,.app-layout.resizing .main-wrapper{transition:none!important}';
    echo '.sidebar-header{height:64px;display:flex;align-items:center;justify-content:space-between;padding:0 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0}';
    echo '.brand-link{display:flex;align-items:center;gap:12px;font-weight:700;font-size:17px;color:#fff;overflow:hidden;white-space:nowrap}';
    echo '.brand-logo{width:36px;height:36px;background:linear-gradient(135deg,#2563eb,#38bdf8);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(37,99,235,.35)}';
    echo '.brand-text{transition:opacity .2s,width .2s}';
    echo '.collapse-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#94a3b8;width:28px;height:28px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;transition:all .15s;padding:0}';
    echo '.collapse-btn:hover{background:rgba(255,255,255,.15);color:#fff}';
    echo '.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:12px 10px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.15) transparent}';
    echo '.sidebar-nav::-webkit-scrollbar{width:4px}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:4px}';
    echo '.nav-group-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;padding:10px 10px 4px 10px;margin-top:6px;white-space:nowrap}';
    echo '.nav-item{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:8px;color:#cbd5e1;font-size:13px;font-weight:500;transition:all .15s;margin-bottom:2px;cursor:pointer;white-space:nowrap;position:relative}';
    echo '.nav-item:hover{background:var(--sidebar-hover);color:#fff}';
    echo '.nav-item.active{background:var(--sidebar-active);color:#fff;font-weight:600}';
    echo '.nav-icon{width:20px;height:20px;flex-shrink:0;display:flex;align-items:center;justify-content:center}';
    echo '.nav-icon svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}';
    echo '.nav-label{flex:1;overflow:hidden;text-overflow:ellipsis}';
    echo '.nav-chevron{width:16px;height:16px;transition:transform .2s;display:flex;align-items:center;justify-content:center;font-size:10px;color:#64748b}';
    echo '.nav-accordion{margin-bottom:2px}';
    echo '.nav-accordion[open]>.nav-item .nav-chevron{transform:rotate(90deg)}';
    echo '.nav-submenu{padding:2px 0 2px 28px;display:flex;flex-direction:column;gap:1px}';
    echo '.nav-submenu .nav-item{padding:7px 10px;font-size:12.5px;color:#94a3b8}';
    echo '.nav-submenu .nav-item:hover{color:#fff}';
    echo '.nav-badge{background:rgba(37,99,235,.2);color:#60a5fa;border:1px solid rgba(37,99,235,.4);padding:1px 6px;border-radius:999px;font-size:10px;font-weight:700}';
    echo '.nav-subaccordion{margin:3px 0 3px 6px;border-left:2px solid rgba(255,255,255,.12);border-radius:0 6px 6px 0;transition:border-color .15s}';
    echo '.nav-subaccordion[open]{border-left-color:#38bdf8}';
    echo '.nav-subaccordion summary{padding:6px 8px;font-size:12px;font-weight:600;color:#94a3b8;cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;border-radius:4px;transition:all .15s}';
    echo '.nav-subaccordion summary::-webkit-details-marker{display:none}';
    echo '.nav-subaccordion summary:hover{color:#fff;background:rgba(255,255,255,.06)}';
    echo '.nav-subaccordion[open]>summary .nav-chevron{transform:rotate(90deg)}';
    echo '.nav-subaccordion .nav-item{padding:6px 10px;font-size:12px}';
    echo '.brand-link.active .brand-logo{box-shadow:0 0 0 2px #fff,0 4px 14px rgba(37,99,235,.6)}';
    // User profile footer in sidebar
    echo '.sidebar-user{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.2);flex-shrink:0;overflow:hidden}';
    echo '.user-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#475569,#334155);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;border:1px solid rgba(255,255,255,.15)}';
    echo '.user-info{flex:1;min-width:0;overflow:hidden}';
    echo '.user-name{font-size:13px;font-weight:600;color:#f8fafc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
    echo '.user-role{font-size:11px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
    echo '.logout-btn{background:transparent;border:none;color:#94a3b8;cursor:pointer;padding:6px;border-radius:6px;display:flex;align-items:center;justify-content:center;transition:all .15s}';
    echo '.logout-btn:hover{background:rgba(239,68,68,.15);color:#ef4444}';
    // Collapsed state behavior
    echo '.app-layout.collapsed .sidebar{width:var(--sidebar-collapsed-w)!important}';
    echo '.app-layout.collapsed .brand-text,.app-layout.collapsed .nav-label,.app-layout.collapsed .nav-chevron,.app-layout.collapsed .nav-group-label,.app-layout.collapsed .user-info,.app-layout.collapsed .nav-submenu,.app-layout.collapsed .nav-subaccordion{display:none!important}';
    echo '.app-layout.collapsed .sidebar-header{padding:0 10px;justify-content:center;position:relative}';
    echo '.app-layout.collapsed .collapse-btn{display:flex!important;position:absolute;right:-10px;top:20px;background:#1d4ed8;color:#fff;border-radius:50%;width:20px;height:20px;font-size:10px;box-shadow:0 2px 6px rgba(0,0,0,.3);z-index:125;border:1px solid rgba(255,255,255,.4)}';
    echo '.app-layout.collapsed .collapse-btn:hover{background:#2563eb;transform:scale(1.1)}';
    echo '.app-layout.collapsed .nav-item{justify-content:center;padding:10px 0}';
    echo '.app-layout.collapsed .sidebar-user{padding:10px 0;justify-content:center}';
    // Main wrapper styles
    echo '.main-wrapper{flex:1;margin-left:var(--sidebar-w);transition:margin-left .22s cubic-bezier(0.4,0,0.2,1);min-width:0;display:flex;flex-direction:column;min-height:100vh}';
    echo '.app-layout.collapsed .main-wrapper{margin-left:var(--sidebar-collapsed-w)!important}';
    echo '.topbar{height:60px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:90;box-shadow:0 1px 3px rgba(0,0,0,.02)}';
    echo '.topbar-left{display:flex;align-items:center;gap:14px}';
    echo '.topbar-toggle{background:#f1f5f9;border:1px solid #cbd5e1;color:#334155;border-radius:6px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;transition:all .15s}';
    echo '.topbar-toggle:hover{background:#e2e8f0;color:#0f172a}';
    echo '.topbar-title{font-size:17px;font-weight:700;color:#0f172a;margin:0}';
    echo '.topbar-right{display:flex;align-items:center;gap:10px}';
    echo 'main{flex:1;max-width:1320px;width:100%;margin:0 auto;padding:24px}';
    // Reusable components
    echo '.panel,.stat{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04)}';
    echo '.hero{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(260px,.8fr);gap:16px;align-items:stretch}';
    echo '.grid{display:grid;gap:16px}';
    echo '.two{grid-template-columns:repeat(2,minmax(0,1fr))}.three{grid-template-columns:repeat(3,minmax(0,1fr))}.four{grid-template-columns:repeat(4,minmax(0,1fr))}.six{grid-template-columns:repeat(6,minmax(0,1fr))}';
    echo '.split{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}';
    echo '.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}';
    echo '.btn{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;background:#fff;color:#1e293b;text-decoration:none;border-radius:7px;padding:8px 14px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;transition:all .15s}';
    echo '.btn:hover{background:#f8fafc;border-color:#94a3b8}';
    echo '.btn.primary{background:#1457d9;border-color:#1457d9;color:#fff}.btn.primary:hover{background:#0f46b3}';
    echo '.btn.good{background:#0f8a5f;border-color:#0f8a5f;color:#fff}.btn.good:hover{background:#0b6b4a}';
    echo '.btn.danger{background:#dc2626;border-color:#dc2626;color:#fff}.btn.danger:hover{background:#b91c1c}';
    echo '.btn-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;transition:all .15s ease-in-out;padding:0;text-decoration:none;box-sizing:border-box}';
    echo '.btn-icon:hover{transform:translateY(-1px);box-shadow:0 2px 5px rgba(0,0,0,.08)}';
    echo '.btn-icon.edit{color:#2563eb;border-color:#bfdbfe;background:#eff6ff}.btn-icon.edit:hover{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd}';
    echo '.btn-icon.repair{color:#d97706;border-color:#fde68a;background:#fffbeb}.btn-icon.repair:hover{background:#fef3c7;color:#b45309;border-color:#fcd34d}';
    echo '.btn-icon.delete{color:#dc2626;border-color:#fecaca;background:#fef2f2}.btn-icon.delete:hover{background:#fee2e2;color:#b91c1c;border-color:#fca5a5}';
    echo 'label{display:block;font-weight:600;margin:12px 0 6px;font-size:13px;color:#334155}';
    echo 'input,select,textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:9px 12px;font:inherit;font-size:13px;background:#fff;transition:border-color .15s,box-shadow .15s}';
    echo 'input:focus,select:focus,textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}';
    echo 'textarea{min-height:120px}';
    echo 'table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden}';
    echo 'th,td{border-bottom:1px solid #e2e8f0;text-align:left;padding:11px 14px;vertical-align:middle;font-size:13px}';
    echo 'th{background:#f8fafc;font-weight:600;color:#475569;text-transform:uppercase;font-size:11.5px;letter-spacing:.04em}';
    echo 'tr:hover td{background:#fbfcfe}';
    echo '.badge{display:inline-flex;align-items:center;border-radius:999px;background:#e2e8f0;color:#475569;padding:3px 10px;font-size:11.5px;font-weight:600}';
    echo '.badge.ok{background:#dcfce7;color:#15803d}';
    echo '.badge.danger{background:#fee2e2;color:#b91c1c}';
    echo '.muted{color:#64748b}';
    echo '.flash{padding:12px 16px;border-radius:8px;margin-bottom:18px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:500;display:flex;align-items:center;gap:10px}';
    echo '.flash.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}';
    echo '.stat strong{display:block;font-size:32px;font-weight:800;color:#0f172a;line-height:1.2}';
    echo '.stat span{font-size:12.5px;color:#64748b;font-weight:600}';
    echo '.label-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}';
    echo '.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}';
    echo '.photo-grid img{width:100%;border-radius:8px;border:1px solid #e2e8f0}';
    echo '.qr-label{break-inside:avoid;background:#fff;border:1px solid #222;padding:12px;display:flex;flex-direction:column;gap:4px}';
    echo '.qr-box{display:grid;grid-template-columns:repeat(9,1fr);gap:2px;width:126px;height:126px;margin:8px 0}.qr-box i{background:#111}.qr-box span{background:#fff}';
    echo '.qr-img{width:126px;height:126px;object-fit:contain;margin:8px 0;display:block}';
    // Mobile responsive
    echo '@media(max-width:900px){.sidebar{transform:translateX(-100%);transition:transform .25s ease}.sidebar.mobile-open{transform:translateX(0)}.sidebar-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:99}.sidebar-overlay.active{display:block}.main-wrapper,.app-layout.collapsed .main-wrapper{margin-left:0;width:100%}.hero,.two,.three,.four,.six{grid-template-columns:1fr}main{padding:14px}table{display:block;overflow-x:auto}}';
    echo '@media print{.sidebar,.topbar,.no-print,.btn{display:none!important}body{background:#fff}.main-wrapper{margin-left:0!important}main{max-width:none;padding:0}.qr-label{page-break-inside:avoid}}';
    echo '</style></head><body>';

    if ($user) {
        $curRoute = (string)($_GET['route'] ?? 'dashboard');
        $isAdmin = is_full_admin($user);
        $role = (string)($user['role'] ?? '');

        // SVG Icon Helpers
        $svgBox = '<svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>';
        $svgDash = '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>';
        $svgLayers = '<svg viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>';
        $svgPc = '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>';
        $svgPrn = '<svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>';
        $svgLoan = '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>';
        $svgTool = '<svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>';
        $svgTicket = '<svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>';
        $svgPatrol = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>';
        $svgMove = '<svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>';
        $svgChart = '<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>';
        $svgUsers = '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
        $svgShield = '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>';
        $svgCog = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>';
        $svgQr = '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><line x1="14" y1="14" x2="14" y2="14.01"></line><line x1="18" y1="14" x2="18" y2="18"></line><line x1="14" y1="18" x2="18" y2="18"></line></svg>';
        $svgMobile = '<svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>';
        $svgLogout = '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>';

        echo '<div class="app-layout" id="appLayout">';
        echo '<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>';
        echo '<aside class="sidebar" id="appSidebar">';
        echo '<div class="sidebar-resizer" id="sidebarResizer" title="Geser ke kiri untuk mode icon, atau geser ke kanan untuk melebarkan menu"></div>';
        echo '<div class="sidebar-header">';
        echo '  <a class="brand-link' . ($curRoute === 'dashboard' ? ' active' : '') . '" href="' . route_url('dashboard') . '" title="AsetConnect Dashboard">';
        echo '    <div class="brand-logo"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></div>';
        echo '    <div class="brand-text" style="display:flex;flex-direction:column;line-height:1.2;">';
        echo '      <span style="font-size:16px;font-weight:800;color:#fff;">' . e($appName) . '</span>';
        echo '      <span style="font-size:11px;color:#94a3b8;font-weight:500;">Dashboard</span>';
        echo '    </div>';
        echo '  </a>';
        echo '  <button class="collapse-btn" id="sidebarCollapseBtn" onclick="toggleSidebarCollapse()" title="Collapse / Expand Sidebar">«</button>';
        echo '</div>';

        echo '<nav class="sidebar-nav">';

        // 1. Transaksi Aset Accordion (Unit Aset, Pendataan PC, Pinjam, Mutasi)
        $transRoutes = ['asset_items', 'pcs', 'asset_loans', 'mobile_asset_loans', 'asset_movements'];
        $isTransOpen = in_array($curRoute, $transRoutes, true);
        echo '<details class="nav-accordion"' . ($isTransOpen ? ' open' : '') . '>';
        echo '  <summary class="nav-item" title="Transaksi Aset"><span class="nav-icon">' . $svgBox . '</span><span class="nav-label">Transaksi Aset</span><span class="nav-chevron">▶</span></summary>';
        echo '  <div class="nav-submenu">';
        if ($isAdmin || has_regulation('asset_items', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_items' ? ' active' : '') . '" href="' . route_url('asset_items') . '"><span class="nav-icon">' . $svgBox . '</span><span class="nav-label">Unit Aset</span></a>';
        }
        if ($isAdmin || has_regulation('pcs', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'pcs' ? ' active' : '') . '" href="' . route_url('pcs') . '"><span class="nav-icon">' . $svgPc . '</span><span class="nav-label">Pendataan PC</span></a>';
        }
        if ($isAdmin || has_regulation('asset_loans', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_loans' ? ' active' : '') . '" href="' . route_url('asset_loans') . '"><span class="nav-icon">' . $svgLoan . '</span><span class="nav-label">Peminjaman Aset</span></a>';
            echo '<a class="nav-item" href="' . route_url('mobile_asset_loans') . '" target="_blank"><span class="nav-icon">' . $svgMobile . '</span><span class="nav-label">📱 Mobile Pinjam</span><span class="nav-badge">PWA</span></a>';
        }
        if ($isAdmin || has_regulation('asset_movements', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_movements' ? ' active' : '') . '" href="' . route_url('asset_movements') . '"><span class="nav-icon">' . $svgMove . '</span><span class="nav-label">Mutasi / Tukar Pasang</span></a>';
        }
        echo '  </div>';
        echo '</details>';

        // 2. Maintenance Aset Accordion (Schedule PM, Tiket, Patroli, Submenu Job Desk, Submenu Mobile)
        $maintRoutes = ['maintenance', 'tickets', 'walkarounds', 'jobs', 'corrective_job_desks', 'mobile_service'];
        $isMaintOpen = in_array($curRoute, $maintRoutes, true);
        echo '<details class="nav-accordion"' . ($isMaintOpen ? ' open' : '') . '>';
        echo '  <summary class="nav-item" title="Maintenance Aset"><span class="nav-icon">' . $svgTool . '</span><span class="nav-label">Maintenance Aset</span><span class="nav-chevron">▶</span></summary>';
        echo '  <div class="nav-submenu">';
        if ($isAdmin || has_regulation('maintenance', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'maintenance' ? ' active' : '') . '" href="' . route_url('maintenance') . '"><span class="nav-icon">' . $svgTool . '</span><span class="nav-label">Schedule PM</span></a>';
        }
        if ($isAdmin || has_regulation('tickets', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'tickets' ? ' active' : '') . '" href="' . route_url('tickets') . '"><span class="nav-icon">' . $svgTicket . '</span><span class="nav-label">Tiket Corrective</span></a>';
        }
        if ($isAdmin || has_regulation('walkarounds', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'walkarounds' ? ' active' : '') . '" href="' . route_url('walkarounds') . '"><span class="nav-icon">' . $svgPatrol . '</span><span class="nav-label">Patroli Walkaround</span></a>';
        }

        // Sub menu Job Desk
        $jobDeskRoutes = ['jobs', 'corrective_job_desks'];
        $isJobDeskOpen = in_array($curRoute, $jobDeskRoutes, true);
        echo '<details class="nav-subaccordion"' . ($isJobDeskOpen ? ' open' : '') . '>';
        echo '  <summary title="Job Desk Maintenance"><span>📋 Job Desk</span><span class="nav-chevron">▶</span></summary>';
        echo '  <div style="display:flex;flex-direction:column;gap:1px;padding-top:2px;">';
        if ($isAdmin || has_regulation('jobs', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'jobs' ? ' active' : '') . '" href="' . route_url('jobs') . '"><span class="nav-icon">' . $svgTool . '</span><span class="nav-label">Job Desk PM</span></a>';
        }
        if ($isAdmin || has_regulation('corrective_job_desks', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'corrective_job_desks' ? ' active' : '') . '" href="' . route_url('corrective_job_desks') . '"><span class="nav-icon">' . $svgTicket . '</span><span class="nav-label">Job Desk Corrective</span></a>';
        }
        echo '  </div>';
        echo '</details>';

        // Sub menu Mobile
        if ($isAdmin || has_regulation('mobile_service', 'view', $user)) {
            echo '<details class="nav-subaccordion"' . ($curRoute === 'mobile_service' ? ' open' : '') . '>';
            echo '  <summary title="Mobile Field Service"><span>📱 Mobile</span><span class="nav-chevron">▶</span></summary>';
            echo '  <div style="display:flex;flex-direction:column;gap:1px;padding-top:2px;">';
            echo '<a class="nav-item' . ($curRoute === 'mobile_service' ? ' active' : '') . '" href="' . route_url('mobile_service') . '" target="_blank"><span class="nav-icon">' . $svgMobile . '</span><span class="nav-label">📱 Mobile Service</span><span class="nav-badge">PWA</span></a>';
            echo '  </div>';
            echo '</details>';
        }

        echo '  </div>';
        echo '</details>';

        // 3. Reports & QR_Label Accordion
        $reportRoutes = ['labels', 'reports', 'maintenance_status_report', 'corrective_repairs', 'report_asset_loans'];
        $isReportOpen = in_array($curRoute, $reportRoutes, true);
        echo '<details class="nav-accordion"' . ($isReportOpen ? ' open' : '') . '>';
        echo '  <summary class="nav-item" title="Reports & QR_Label"><span class="nav-icon">' . $svgChart . '</span><span class="nav-label">Reports & QR_Label</span><span class="nav-chevron">▶</span></summary>';
        echo '  <div class="nav-submenu">';
        if ($isAdmin || has_regulation('labels', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'labels' ? ' active' : '') . '" href="' . route_url('labels') . '"><span class="nav-icon">' . $svgQr . '</span><span class="nav-label">QR Label Unit</span></a>';
        }
        echo '<a class="nav-item' . ($curRoute === 'reports' ? ' active' : '') . '" href="' . route_url('reports') . '"><span class="nav-icon">' . $svgChart . '</span><span class="nav-label">Report PM</span></a>';
        echo '<a class="nav-item' . ($curRoute === 'maintenance_status_report' ? ' active' : '') . '" href="' . route_url('maintenance_status_report') . '"><span class="nav-icon">' . $svgChart . '</span><span class="nav-label">Report Status PC/PRN</span></a>';
        echo '<a class="nav-item' . ($curRoute === 'corrective_repairs' ? ' active' : '') . '" href="' . route_url('corrective_repairs') . '"><span class="nav-icon">' . $svgChart . '</span><span class="nav-label">Report Corrective</span></a>';
        echo '<a class="nav-item' . ($curRoute === 'report_asset_loans' ? ' active' : '') . '" href="' . route_url('report_asset_loans') . '"><span class="nav-icon">' . $svgChart . '</span><span class="nav-label">Report Peminjaman</span></a>';
        echo '  </div>';
        echo '</details>';

        // 4. Data Master Accordion (Posisinya di bawah Reports & QR_Label)
        $masterRoutes = ['asset_groups', 'asset_types', 'asset_brands', 'asset_master_items', 'asset_identifiers', 'asset_specifications', 'asset_locations', 'asset_companies', 'master_pengguna'];
        $isMasterOpen = in_array($curRoute, $masterRoutes, true);
        echo '<details class="nav-accordion"' . ($isMasterOpen ? ' open' : '') . '>';
        echo '  <summary class="nav-item" title="Data Master"><span class="nav-icon">' . $svgLayers . '</span><span class="nav-label">Data Master</span><span class="nav-chevron">▶</span></summary>';
        echo '  <div class="nav-submenu">';
        if ($isAdmin || has_regulation('asset_groups', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_groups' ? ' active' : '') . '" href="' . route_url('asset_groups') . '"><span class="nav-icon">' . $svgLayers . '</span><span class="nav-label">Master Komoditas</span></a>';
        }
        if ($isAdmin || has_regulation('asset_types', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_types' ? ' active' : '') . '" href="' . route_url('asset_types') . '"><span class="nav-icon">' . $svgLayers . '</span><span class="nav-label">Master Kategori</span></a>';
        }
        if ($isAdmin || has_regulation('asset_brands', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_brands' ? ' active' : '') . '" href="' . route_url('asset_brands') . '"><span class="nav-icon">' . $svgBox . '</span><span class="nav-label">Master Brand / Merk</span></a>';
        }
        if ($isAdmin || has_regulation('asset_master_items', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_master_items' ? ' active' : '') . '" href="' . route_url('asset_master_items') . '"><span class="nav-icon">' . $svgBox . '</span><span class="nav-label">Master Barang</span></a>';
        }
        if ($isAdmin || has_regulation('asset_identifiers', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_identifiers' ? ' active' : '') . '" href="' . route_url('asset_identifiers') . '"><span class="nav-icon">' . $svgQr . '</span><span class="nav-label">Identifier Aset</span></a>';
        }
        if ($isAdmin || has_regulation('asset_specifications', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_specifications' ? ' active' : '') . '" href="' . route_url('asset_specifications') . '"><span class="nav-icon">' . $svgTool . '</span><span class="nav-label">Spesifikasi Aset</span></a>';
        }
        if ($isAdmin || has_regulation('asset_locations', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_locations' ? ' active' : '') . '" href="' . route_url('asset_locations') . '"><span class="nav-icon">' . $svgPatrol . '</span><span class="nav-label">Master Lokasi</span></a>';
        }
        if ($isAdmin || has_regulation('asset_companies', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'asset_companies' ? ' active' : '') . '" href="' . route_url('asset_companies') . '"><span class="nav-icon">' . $svgShield . '</span><span class="nav-label">Company</span></a>';
        }
        if ($isAdmin || has_regulation('master_pengguna', 'view', $user)) {
            echo '<a class="nav-item' . ($curRoute === 'master_pengguna' ? ' active' : '') . '" href="' . route_url('master_pengguna') . '"><span class="nav-icon">' . $svgUsers . '</span><span class="nav-label">Master Pengguna</span></a>';
        }
        echo '  </div>';
        echo '</details>';

        // 5. Setup Accordion
        if ($isAdmin || has_regulation('users', 'view', $user)) {
            $setupRoutes = ['users', 'setup_regulations', 'employee_source'];
            $isSetupOpen = in_array($curRoute, $setupRoutes, true);
            echo '<details class="nav-accordion"' . ($isSetupOpen ? ' open' : '') . '>';
            echo '  <summary class="nav-item" title="Pengaturan & Setup"><span class="nav-icon">' . $svgCog . '</span><span class="nav-label">Pengaturan & Setup</span><span class="nav-chevron">▶</span></summary>';
            echo '  <div class="nav-submenu">';
            if ($isAdmin || has_regulation('users', 'view', $user)) {
                echo '<a class="nav-item' . ($curRoute === 'users' ? ' active' : '') . '" href="' . route_url('users') . '"><span class="nav-icon">' . $svgUsers . '</span><span class="nav-label">Akses Users</span></a>';
            }
            if ($isAdmin) {
                echo '<a class="nav-item' . ($curRoute === 'setup_regulations' ? ' active' : '') . '" href="' . route_url('setup_regulations') . '"><span class="nav-icon">' . $svgShield . '</span><span class="nav-label">Regulasi Hak Akses</span></a>';
                echo '<a class="nav-item' . ($curRoute === 'employee_source' ? ' active' : '') . '" href="' . route_url('employee_source') . '"><span class="nav-icon">' . $svgCog . '</span><span class="nav-label">Employee Source</span></a>';
            }
            echo '  </div>';
            echo '</details>';
        }

        echo '</nav>'; // End sidebar-nav

        // Sidebar user profile footer (Kiri Bawah dengan Sign In / Sign Out)
        $initial = strtoupper(substr((string)($user['name'] ?? 'U'), 0, 1));
        $roleLabel = match ($role) {
            'admin' => 'Administrator',
            'maintenance_admin' => 'Admin Maintenance',
            'technician' => 'Teknisi PM',
            'corrective_maintenance' => 'Corrective Maint.',
            'loan_officer' => 'Petugas Peminjaman',
            default => $role,
        };
        echo '<div class="sidebar-user">';
        echo '  <div class="user-avatar">' . e($initial) . '</div>';
        echo '  <div class="user-info">';
        echo '    <div class="user-name">' . e($user['name'] ?? 'User') . '</div>';
        echo '    <div class="user-role">' . e($roleLabel) . '</div>';
        echo '  </div>';
        echo '  <div style="display:flex;gap:4px;align-items:center;">';
        echo '    <a class="logout-btn" href="' . route_url('login') . '" title="Sign In / Ganti User" style="color:#60a5fa;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg></a>';
        echo '    <a class="logout-btn" href="' . route_url('logout') . '" title="Sign Out / Logout"><span style="width:17px;height:17px;">' . $svgLogout . '</span></a>';
        echo '  </div>';
        echo '</div>';

        echo '</aside>'; // End sidebar

        // Main content wrapper (Topbar Kanan Atas dengan Sign In / Sign Out)
        echo '<div class="main-wrapper">';
        echo '<header class="topbar">';
        echo '  <div class="topbar-left">';
        echo '    <button class="topbar-toggle" onclick="toggleSidebarSlide()" title="Slide / Buka-Tutup Menu"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg></button>';
        echo '    <h1 class="topbar-title">' . e($title) . '</h1>';
        echo '  </div>';
        echo '  <div class="topbar-right">';
        echo '    <span class="badge ok">' . e($roleLabel) . '</span>';
        echo '    <span style="font-size:13px;font-weight:600;color:#334155;">' . e($user['name'] ?? '') . '</span>';
        echo '    <a href="' . route_url('login') . '" class="btn" style="padding:4px 9px;font-size:12px;display:inline-flex;align-items:center;gap:4px;" title="Sign In Akun Lain / Ganti User"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg> Sign In</a>';
        echo '    <a href="' . route_url('logout') . '" class="btn danger" style="padding:4px 9px;font-size:12px;display:inline-flex;align-items:center;gap:4px;" title="Sign Out"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg> Sign Out</a>';
        echo '  </div>';
        echo '</header>';
        echo '<main>';
    } else {
        echo '<header class="topbar" style="justify-content:space-between;padding:0 24px;">';
        echo '  <div class="topbar-left"><a href="' . route_url('login') . '" style="display:flex;align-items:center;gap:10px;font-weight:800;font-size:16px;color:#0f172a;"><div style="width:30px;height:30px;background:linear-gradient(135deg,#2563eb,#38bdf8);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div> ' . e($appName) . '</a></div>';
        echo '  <div class="topbar-right"><a href="' . route_url('login') . '" class="btn primary" style="padding:6px 14px;font-size:13px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg> Sign In</a></div>';
        echo '</header>';
        echo '<main style="max-width:100%;padding:0;">';
    }

    if ($flash) {
        echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function render_footer(): void
{
    echo '</main>';
    $user = current_user();
    if ($user) {
        echo '</div></div>'; // End main-wrapper and app-layout
        echo '<script>
        var _isSidebarDragging = false;
        var _savedSidebarWidth = 260;

        function applySidebarState() {
            var isCollapsed = localStorage.getItem("asetconnect_sidebar_collapsed") === "1";
            var savedW = parseInt(localStorage.getItem("asetconnect_sidebar_width"), 10);
            var layout = document.getElementById("appLayout");
            var btn = document.getElementById("sidebarCollapseBtn");
            if (layout) {
                if (savedW && savedW >= 180 && savedW <= 450) {
                    _savedSidebarWidth = savedW;
                    layout.style.setProperty("--sidebar-w", savedW + "px");
                }
                if (isCollapsed) {
                    layout.classList.add("collapsed");
                    if (btn) btn.innerHTML = "»";
                } else {
                    layout.classList.remove("collapsed");
                    if (btn) btn.innerHTML = "«";
                }
            }
        }

        function toggleSidebarCollapse() {
            var layout = document.getElementById("appLayout");
            var btn = document.getElementById("sidebarCollapseBtn");
            if (!layout) return;
            var isCollapsed = layout.classList.toggle("collapsed");
            localStorage.setItem("asetconnect_sidebar_collapsed", isCollapsed ? "1" : "0");
            if (btn) btn.innerHTML = isCollapsed ? "»" : "«";
            if (!isCollapsed) {
                var w = _savedSidebarWidth || 260;
                layout.style.setProperty("--sidebar-w", w + "px");
            }
        }

        function toggleSidebarSlide() {
            if (window.innerWidth <= 900) {
                toggleMobileSidebar();
            } else {
                toggleSidebarCollapse();
            }
        }

        function toggleMobileSidebar() {
            var sidebar = document.getElementById("appSidebar");
            var overlay = document.getElementById("sidebarOverlay");
            if (sidebar) sidebar.classList.toggle("mobile-open");
            if (overlay) overlay.classList.toggle("active");
        }

        function initSidebarResizer() {
            var resizer = document.getElementById("sidebarResizer");
            var layout = document.getElementById("appLayout");
            var btn = document.getElementById("sidebarCollapseBtn");
            if (!resizer || !layout) return;

            function handleMove(clientX) {
                if (!_isSidebarDragging) return;
                if (clientX <= 130) {
                    // Slide ke paling kiri: otomatis snap ke tinggal icon
                    if (!layout.classList.contains("collapsed")) {
                        layout.classList.add("collapsed");
                    }
                    layout.style.setProperty("--sidebar-w", "68px");
                    if (btn) btn.innerHTML = "»";
                } else {
                    // Slide ke kanan: munculkan kembali teks menu
                    if (layout.classList.contains("collapsed")) {
                        layout.classList.remove("collapsed");
                    }
                    var clamped = Math.max(180, Math.min(450, clientX));
                    _savedSidebarWidth = clamped;
                    layout.style.setProperty("--sidebar-w", clamped + "px");
                    if (btn) btn.innerHTML = "«";
                }
            }

            function handleEnd() {
                if (!_isSidebarDragging) return;
                _isSidebarDragging = false;
                layout.classList.remove("resizing");
                document.body.style.cursor = "";
                document.body.style.userSelect = "";

                var isCol = layout.classList.contains("collapsed");
                localStorage.setItem("asetconnect_sidebar_collapsed", isCol ? "1" : "0");
                if (!isCol && _savedSidebarWidth >= 180) {
                    localStorage.setItem("asetconnect_sidebar_width", _savedSidebarWidth);
                }
            }

            resizer.addEventListener("mousedown", function(e) {
                _isSidebarDragging = true;
                layout.classList.add("resizing");
                document.body.style.cursor = "ew-resize";
                document.body.style.userSelect = "none";
                e.preventDefault();
            });

            window.addEventListener("mousemove", function(e) {
                handleMove(e.clientX);
            });

            window.addEventListener("mouseup", handleEnd);

            // Touch support
            resizer.addEventListener("touchstart", function(e) {
                if (e.touches && e.touches.length === 1) {
                    _isSidebarDragging = true;
                    layout.classList.add("resizing");
                }
            }, {passive: true});

            window.addEventListener("touchmove", function(e) {
                if (_isSidebarDragging && e.touches && e.touches.length === 1) {
                    handleMove(e.touches[0].clientX);
                }
            }, {passive: true});

            window.addEventListener("touchend", handleEnd);
        }

        applySidebarState();
        initSidebarResizer();
        </script>';
    }
    echo '</body></html>';
}

function render_mobile_header(string $title, ?array $user = null): void
{
    $flash = flash();
    $appName = app_name();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' - ' . e($appName) . ' Mobile</title><style>';
    echo 'body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f3f6fb;color:#172033}main{max-width:560px;margin:0 auto;padding:14px 14px 82px}.mobile-top{position:sticky;top:0;z-index:2;background:#172033;color:#fff;padding:14px 16px;font-weight:700}.mobile-nav{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #d9e1ee;display:grid;grid-template-columns:repeat(4,1fr);z-index:3}.mobile-nav a{text-align:center;text-decoration:none;color:#334155;padding:10px 4px;font-size:13px}.panel,.stat{background:#fff;border:1px solid #dde5f0;border-radius:8px;padding:16px;margin-bottom:12px}.grid{display:grid;gap:12px}.two{grid-template-columns:repeat(2,minmax(0,1fr))}.split{display:flex;justify-content:space-between;gap:10px;align-items:center}.btn{display:inline-block;border:1px solid #c7d0df;background:#fff;color:#172033;text-decoration:none;border-radius:6px;padding:10px 12px;cursor:pointer;font:inherit}.btn.primary{background:#1457d9;border-color:#1457d9;color:#fff}.btn.good{background:#0f8a5f;border-color:#0f8a5f;color:#fff}.btn.danger{background:#b91c1c;border-color:#b91c1c;color:#fff}label{display:block;font-weight:600;margin:10px 0 6px}input,select,textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:10px;font:inherit}textarea{min-height:110px}.badge{display:inline-block;border-radius:999px;background:#e8eef7;padding:4px 8px;font-size:12px}.ok{background:#dcfce7;color:#166534}.danger-text{color:#991b1b}.muted{color:#64748b}.flash{padding:12px;border-radius:6px;margin-bottom:12px;background:#e7f7ef;color:#14532d}.flash.err{background:#fee2e2;color:#991b1b}.readonly{opacity:.72}.check-row{display:grid;grid-template-columns:28px minmax(0,1fr);gap:10px;align-items:start;border-bottom:1px solid #e2e8f0;padding:12px 0}.check-row input[type=checkbox]{width:22px;height:22px;margin:2px 0 0}.check-title{display:block;font-weight:700;line-height:1.35}.check-note-label{font-size:13px;color:#64748b;margin-top:8px}.check-note-label input{margin-top:4px}.scan-video{display:none;width:100%;border-radius:8px;background:#111;margin-bottom:10px}.camera-note{font-size:13px;color:#64748b;margin:8px 0}.photo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.photo-grid img{width:100%;border-radius:6px;border:1px solid #d9e1ee}.signature-pad{width:100%;height:160px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;touch-action:none}@media(max-width:420px){.two,.photo-grid{grid-template-columns:1fr}}';
    echo '</style></head><body><div class="mobile-top">' . e($appName) . ' Mobile</div><main>';
    if ($flash) {
        echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
    if ($user) {
        if (can_manage_maintenance($user)) {
            echo '<nav class="mobile-nav"><a href="' . route_url('asset_items') . '">Unit Aset</a><a href="' . route_url('maintenance') . '">Schedule</a><a href="' . route_url('reports') . '">Reports</a><a href="' . route_url('logout') . '">Logout</a></nav>';
        } elseif (($user['role'] ?? '') === 'loan_officer') {
            echo '<nav class="mobile-nav"><a href="' . route_url('mobile_asset_loans') . '">Pinjaman</a><a href="' . route_url('mobile_asset_loan_create') . '">Pinjam Baru</a><a href="' . route_url('mobile_asset_loan_return') . '">Pengembalian</a><a href="' . route_url('logout') . '">Logout</a></nav>';
        } else {
            echo '<nav class="mobile-nav"><a href="' . route_url('mobile_dashboard') . '">Dashboard</a><a href="' . route_url('mobile_schedule') . '">Schedule</a><a href="' . route_url('mobile_scan') . '">Scan</a><a href="' . route_url('mobile_history') . '">History</a></nav>';
        }
    }
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
        static $tables = null;
        if ($tables === null) {
            try {
                $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                $tables = [];
                foreach ($rows as $t) {
                    $tables[strtolower((string)$t)] = true;
                }
            } catch (Throwable $e) {
                $tables = [];
            }
        }
        return isset($tables[strtolower($table)]);
    }
}

if (!function_exists('db_column_exists')) {
    function db_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $columns = [];
        $tableLower = strtolower($table);
        $colLower = strtolower($column);
        if (!isset($columns[$tableLower])) {
            if (!db_table_exists($pdo, $table)) {
                $columns[$tableLower] = [];
                return false;
            }
            try {
                $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
                $cols = [];
                foreach ($rows as $c) {
                    $cols[strtolower((string)$c)] = true;
                }
                $columns[$tableLower] = $cols;
            } catch (Throwable $e) {
                $columns[$tableLower] = [];
            }
        }
        return isset($columns[$tableLower][$colLower]);
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
                    " . ($hasJobDesks ? "LEFT JOIN corrective_job_desks cjd ON cjd.job_desk_name = cat.job_desk_name" : "") . "
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
                           " . ($hasJobDesks ? "LEFT JOIN corrective_job_desks cjd ON cjd.job_desk_name = cat.job_desk_name" : "") . "
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


