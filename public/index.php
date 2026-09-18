<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    echo '<pre>Uncaught exception: ' . htmlspecialchars($e->getMessage()) . "\n";
    echo 'In ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo '</pre>';
    exit;
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        http_response_code(500);
        echo '<pre>Fatal error: ' . htmlspecialchars($error['message']) . "\n";
        echo 'In ' . htmlspecialchars($error['file']) . ':' . $error['line'] . '</pre>';
    }
});

if (!ob_get_level()) {
    if (extension_loaded('zlib') && !ini_get('zlib.output_compression') && !headers_sent()) {
        ob_start('ob_gzhandler');
    } else {
        ob_start();
    }
}

$baseDir = dirname(__DIR__);

// Core modules loaded on every request
require_once $baseDir . '/app/lib/bootstrap.php';
require_once $baseDir . '/app/lib/schema.php';

ensure_session_started();

$route = (string)($_GET['route'] ?? 'dashboard');

// Route-based lazy loading: only load modules required by the requested route
$modulesToLoad = [];

if (str_starts_with($route, 'api_') || $route === 'employee_search') {
    $modulesToLoad = [
        'app/modules/pc.php',
        'app/modules/asset.php',
        'app/modules/maintenance.php',
        'app/modules/corrective.php',
        'app/modules/api.php',
    ];
} elseif (in_array($route, ['login', 'logout', 'dashboard', 'users', 'technicians'], true)) {
    $modulesToLoad = [
        'app/modules/auth.php',
    ];
} elseif (in_array($route, ['pcs', 'pc_detail', 'pc_form', 'pc_location', 'pc_locations', 'pc_asset_sync', 'download_agent', 'upload_analysis'], true)) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/asset.php',
        'app/modules/pc.php',
    ];
} elseif (in_array($route, ['printers', 'printer_detail', 'printer_form', 'printer_location', 'printer_locations'], true)) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/printer.php',
    ];
} elseif (str_starts_with($route, 'asset_loan') || $route === 'report_asset_loans') {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/asset.php',
        'app/modules/asset_loan.php',
    ];
} elseif (str_starts_with($route, 'mobile_loan') || $route === 'mobile_asset_loans') {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/asset.php',
        'app/modules/mobile_loan.php',
    ];
} elseif (str_starts_with($route, 'asset_')) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/asset.php',
    ];
} elseif (in_array($route, ['maintenance', 'maintenance_cleanup', 'maintenance_categories', 'maintenance_do', 'maintenance_unlock', 'maintenance_status_report', 'schedule_form', 'report_print', 'export_excel', 'jobs', 'calendar', 'reports'], true)) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/pc.php',
        'app/modules/printer.php',
        'app/modules/asset.php',
        'app/modules/maintenance_asset.php',
        'app/modules/maintenance.php',
    ];
} elseif (in_array($route, ['maintenance_assets', 'maintenance_asset_form', 'maintenance_asset_item_action'], true)) {
    $modulesToLoad = [
        'app/modules/asset.php',
        'app/modules/maintenance_asset.php',
    ];
} elseif (in_array($route, ['tickets', 'ticket_form', 'ticket_detail', 'ticket_action', 'walkarounds', 'walkaround_form', 'walkaround_detail', 'corrective_repairs', 'corrective_action_types', 'corrective_job_desks', 'corrective_job_desk_form'], true)) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/asset.php',
        'app/modules/pc.php',
        'app/modules/corrective.php',
    ];
} elseif (str_starts_with($route, 'mobile_service')) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/asset.php',
        'app/modules/corrective.php',
        'app/modules/field_service.php',
    ];
} elseif (str_starts_with($route, 'mobile_')) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/pc.php',
        'app/modules/maintenance.php',
        'app/modules/mobile.php',
    ];
} elseif (in_array($route, ['labels', 'qr_png', 'qr_view'], true)) {
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/asset.php',
        'app/modules/labels.php',
    ];
} elseif ($route === 'setup_regulations') {
    $modulesToLoad = [
        'app/modules/regulations.php',
    ];
} elseif (in_array($route, ['master_pengguna', 'employee_source'], true)) {
    $modulesToLoad = [
        'app/modules/pengguna.php',
    ];
} else {
    // Default fallback
    $modulesToLoad = [
        'app/lib/qr.php',
        'app/modules/auth.php',
        'app/modules/pc.php',
        'app/modules/asset.php',
        'app/modules/maintenance.php',
        'app/modules/corrective.php',
    ];
}

foreach ($modulesToLoad as $file) {
    require_once $baseDir . '/' . $file;
}

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    $cfg = [];
    try {
        $cfg = app_config();
    } catch (Throwable $ignored) {
    }
    render_header('Setup Database');
    echo '<section class="auth" style="max-width:720px"><h1>PcConnect</h1><p>Koneksi database belum siap.</p><pre>' . e($e->getMessage()) . '</pre>';
    echo '<h2>Yang perlu dicek</h2><ol><li>Pastikan MySQL/MariaDB aktif di server web.</li><li>Buat database <code>' . e($cfg['db_name'] ?? 'pcconnect') . '</code>.</li><li>Import file <code>database/schema.sql</code>.</li><li>Edit <code>config/config.php</code> sesuai user/password database server.</li></ol>';
    echo '<h2>Config saat ini</h2><table><tr><th>Host</th><td>' . e($cfg['db_host'] ?? '-') . '</td></tr><tr><th>Port</th><td>' . e($cfg['db_port'] ?? '-') . '</td></tr><tr><th>Database</th><td>' . e($cfg['db_name'] ?? '-') . '</td></tr><tr><th>User</th><td>' . e($cfg['db_user'] ?? '-') . '</td></tr></table>';
    echo '<p class="muted">Setelah itu ubah <code>db_pass</code> di <code>config/config.php</code>, lalu import <code>database/schema.sql</code>.</p></section>';
    render_footer();
    exit;
}

ensure_app_schema($pdo);

enforce_idle_logout($route);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && substr((string)$route, 0, 4) !== 'api_') {
    verify_csrf();
}

switch ($route) {
    // ----------------------------------------------------
    // Auth & Users
    // ----------------------------------------------------
    case 'login':
        handle_route_login($pdo);
        break;
    case 'logout':
        handle_route_logout();
        break;
    case 'users':
        handle_route_users($pdo);
        break;
    case 'setup_regulations':
        handle_route_setup_regulations($pdo);
        break;
    case 'master_pengguna':
        handle_route_master_pengguna($pdo);
        break;
    case 'technicians':
        handle_route_technicians($pdo);
        break;

    // ----------------------------------------------------
    // Dashboard & PCs
    // ----------------------------------------------------
    case 'dashboard':
        handle_route_dashboard($pdo);
        break;
    case 'pcs':
        handle_route_pcs($pdo);
        break;
    case 'pc_detail':
        handle_route_pc_detail($pdo);
        break;
    case 'pc_form':
        handle_route_pc_form($pdo);
        break;
    case 'pc_location':
        handle_route_pc_location($pdo);
        break;
    case 'pc_locations':
        handle_route_pc_locations($pdo);
        break;
    case 'pc_asset_sync':
        handle_route_pc_asset_sync($pdo);
        break;
    case 'download_agent':
        handle_route_download_agent();
        break;
    case 'upload_analysis':
        handle_route_upload_analysis($pdo);
        break;

    // ----------------------------------------------------
    // Printers
    // ----------------------------------------------------
    case 'printers':
        handle_route_printers($pdo);
        break;
    case 'printer_detail':
        handle_route_printer_detail($pdo);
        break;
    case 'printer_form':
        handle_route_printer_form($pdo);
        break;
    case 'printer_location':
        handle_route_printer_location($pdo);
        break;
    case 'printer_locations':
        handle_route_printer_locations($pdo);
        break;

    // ----------------------------------------------------
    // Asset Management
    // ----------------------------------------------------
    case 'asset_dashboard':
        handle_route_asset_dashboard($pdo);
        break;
    case 'asset_companies':
        handle_route_asset_companies($pdo);
        break;
    case 'asset_categories':
        handle_route_asset_categories($pdo);
        break;
    case 'asset_items':
        handle_route_asset_items($pdo);
        break;
    case 'asset_item_form':
        handle_route_asset_item_form($pdo);
        break;
    case 'asset_item_member_action':
        handle_route_asset_item_member_action($pdo);
        break;
    case 'asset_bundles':
        handle_route_asset_bundles($pdo);
        break;
    case 'asset_bundle_form':
        handle_route_asset_bundle_form($pdo);
        break;
    case 'asset_bundle_member_action':
        handle_route_asset_bundle_member_action($pdo);
        break;
    case 'asset_repairs':
        handle_route_asset_repairs($pdo);
        break;
    case 'asset_repair_form':
        handle_route_asset_repair_form($pdo);
        break;
    case 'asset_movements':
        handle_route_asset_movements($pdo);
        break;
    case 'asset_loans':
        handle_route_asset_loans($pdo);
        break;
    case 'asset_loan_form':
    case 'asset_loan_edit':
        handle_route_asset_loan_form($pdo);
        break;
    case 'asset_loan_detail':
        handle_route_asset_loan_detail($pdo);
        break;
    case 'asset_loan_return':
        handle_route_asset_loan_return($pdo);
        break;
    case 'mobile_asset_loans':
        handle_route_mobile_asset_loans($pdo);
        break;
    case 'mobile_asset_loan_create':
        handle_route_mobile_asset_loan_create($pdo);
        break;
    case 'mobile_asset_loan_return':
        handle_route_mobile_asset_loan_return($pdo);
        break;
    case 'mobile_asset_loan_detail':
        handle_route_mobile_asset_loan_detail($pdo);
        break;
    case 'report_asset_loans':
        handle_route_report_asset_loans($pdo);
        break;
    case 'asset_management_cleanup':
        handle_route_asset_management_cleanup($pdo);
        break;
    case 'employee_source':
        handle_route_employee_source($pdo);
        break;
    case 'asset_groups':
    case 'asset_types':
    case 'asset_brands':
    case 'asset_locations':
    case 'asset_identifiers':
    case 'asset_specifications':
    case 'asset_maintenance_templates':
        $user = require_regulation($route, 'view');
        asset_master_page($pdo, $route, $user);
        break;
    case 'asset_statuses':
        $user = require_role(['admin']);
        asset_master_page($pdo, $route, $user);
        break;
    case 'asset_master_items':
        handle_route_asset_master_items($pdo);
        break;

    // ----------------------------------------------------
    // Maintenance Assets (Dialihkan langsung ke Unit Aset)
    // ----------------------------------------------------
    case 'maintenance_assets':
    case 'maintenance_asset_form':
    case 'maintenance_asset_item_action':
        flash('Entitas Maintenance Asset telah ditiadakan dan diseragamkan langsung ke Unit Aset.', 'ok');
        redirect_to('asset_items');
        break;

    // ----------------------------------------------------
    // Maintenance Schedules & Jobs
    // ----------------------------------------------------
    case 'maintenance':
        handle_route_maintenance($pdo);
        break;
    case 'maintenance_cleanup':
        handle_route_maintenance_cleanup($pdo);
        break;
    case 'schedule_form':
        handle_route_schedule_form($pdo);
        break;
    case 'maintenance_do':
        handle_route_maintenance_do($pdo);
        break;
    case 'maintenance_unlock':
        handle_route_maintenance_unlock($pdo);
        break;
    case 'jobs':
        handle_route_jobs($pdo);
        break;
    case 'maintenance_categories':
        handle_route_maintenance_categories($pdo);
        break;
    case 'reports':
        handle_route_reports($pdo);
        break;
    case 'maintenance_status_report':
        handle_route_maintenance_status_report($pdo);
        break;
    case 'report_print':
        report_print($pdo);
        break;
    case 'export_excel':
        export_excel($pdo, (string)($_GET['type'] ?? 'pcs'));
        break;

    // ----------------------------------------------------
    // Corrective Maintenance, Ticketing & Walkarounds
    // ----------------------------------------------------
    case 'tickets':
        handle_route_tickets($pdo);
        break;
    case 'ticket_form':
        handle_route_ticket_form($pdo);
        break;
    case 'ticket_detail':
        handle_route_ticket_detail($pdo);
        break;
    case 'ticket_action':
        handle_route_ticket_action($pdo);
        break;
    case 'asset_repairs':
    case 'corrective_repairs':
        handle_route_corrective_repairs($pdo);
        break;
    case 'walkarounds':
        handle_route_walkarounds($pdo);
        break;
    case 'walkaround_form':
        handle_route_walkaround_form($pdo);
        break;
    case 'corrective_action_types':
    case 'corrective_job_desks':
        handle_route_corrective_job_desks($pdo);
        break;

    // ----------------------------------------------------
    // Dedicated Mobile Field Service (Corrective & Repair)
    // ----------------------------------------------------
    case 'mobile_service_login':
        handle_route_mobile_service_login($pdo);
        break;
    case 'mobile_service_logout':
        handle_route_mobile_service_logout();
        break;
    case 'mobile_service':
    case 'mobile_repair':
        handle_route_mobile_service($pdo);
        break;
    case 'mobile_service_scan':
        handle_route_mobile_service_scan($pdo);
        break;
    case 'mobile_service_direct':
        handle_route_mobile_service_direct($pdo);
        break;
    case 'mobile_service_tasks':
        handle_route_mobile_service_tasks($pdo);
        break;
    case 'mobile_service_ticket':
        handle_route_mobile_service_ticket($pdo);
        break;

    // ----------------------------------------------------
    // Mobile PWA
    // ----------------------------------------------------
    case 'mobile_dashboard':
        mobile_dashboard($pdo);
        break;
    case 'mobile_schedule':
        mobile_schedule($pdo);
        break;
    case 'mobile_scan':
        mobile_scan($pdo);
        break;
    case 'mobile_job':
        mobile_job($pdo);
        break;
    case 'mobile_history':
        mobile_history($pdo);
        break;

    // ----------------------------------------------------
    // Labels & QR Codes
    // ----------------------------------------------------
    case 'labels':
        handle_route_labels($pdo);
        break;
    case 'qr_png':
        handle_route_qr_png($pdo);
        break;

    // ----------------------------------------------------
    // APIs
    // ----------------------------------------------------
    case 'api_ingest':
        handle_route_api_ingest($pdo);
        break;
    case 'api_technician_login':
        api_technician_login($pdo);
        break;
    case 'api_technician_me':
        api_technician_me($pdo);
        break;
    case 'api_technician_schedules':
        api_technician_schedules($pdo);
        break;
    case 'api_technician_schedule':
        api_technician_schedule($pdo);
        break;
    case 'api_technician_scan':
        api_technician_scan($pdo);
        break;
    case 'api_technician_submit':
        api_technician_submit($pdo);
        break;
    case 'api_technician_history':
        api_technician_history($pdo);
        break;
    case 'api_technician_logout':
        api_technician_logout($pdo);
        break;
    case 'employee_search':
        handle_route_employee_search($pdo);
        break;
    case 'api_asset_types':
        handle_route_api_asset_types($pdo);
        break;
    case 'api_asset_type_config':
        handle_route_api_asset_type_config($pdo);
        break;
    case 'api_master_items':
        handle_route_api_master_items($pdo);
        break;
    case 'api_asset_brands':
        handle_route_api_asset_brands($pdo);
        break;
    case 'api_job_desks':
        handle_route_api_job_desks($pdo);
        break;
    case 'api_trace_identifier':
        handle_route_api_trace_identifier($pdo);
        break;
    case 'api_pengguna_assets':
        handle_route_api_pengguna_assets($pdo);
        break;
    case 'api_pc_import':
        handle_route_api_pc_import($pdo);
        break;
    case 'api_ai_resolve_model':
        handle_route_api_ai_resolve_model($pdo);
        break;
    case 'api_eligible_maintenance_asset_items':
        handle_route_api_eligible_maintenance_asset_items($pdo);
        break;
    case 'api_lookup_asset_for_loan':
        handle_route_api_lookup_asset_for_loan($pdo);
        break;

    default:
        http_response_code(404);
        echo 'Halaman atau endpoint tidak ditemukan.';
}
