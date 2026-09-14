<?php
declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/app/lib/bootstrap.php';

function role_upgrade_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function role_upgrade_column_type(PDO $pdo): string
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
        $row = $stmt->fetch();
        return (string)($row['Type'] ?? '-');
    } catch (Throwable $e) {
        return 'ERROR: ' . $e->getMessage();
    }
}

$pdo = Database::pdo();
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    header('Location: index.php?route=login');
    exit;
}

$message = '';
$before = role_upgrade_column_type($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','maintenance_admin','technician') NOT NULL DEFAULT 'technician'");
        $message = 'Upgrade role berhasil dijalankan.';
    } catch (Throwable $e) {
        $message = 'Upgrade role gagal: ' . $e->getMessage();
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}
$after = role_upgrade_column_type($pdo);
$ready = strpos($after, 'maintenance_admin') !== false;

?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PcConnect Role Upgrade</title>
    <style>
        body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#172033}
        main{max-width:860px;margin:40px auto;padding:0 18px}
        .panel{background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:18px;margin-bottom:16px}
        .ok{color:#166534}.err{color:#991b1b}.muted{color:#64748b}
        code,pre{background:#f1f5f9;padding:3px 6px;border-radius:5px}
        pre{padding:12px;overflow:auto}
        .btn{display:inline-block;border:1px solid #1457d9;background:#1457d9;color:#fff;text-decoration:none;border-radius:6px;padding:10px 14px;cursor:pointer;font:inherit}
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>PcConnect Role Upgrade</h1>
        <p class="muted">Build marker: role-upgrade-20260721-maintenance-admin</p>
        <?php if ($message !== ''): ?><p><strong><?= role_upgrade_e($message) ?></strong></p><?php endif; ?>
        <p>Status role: <strong class="<?= $ready ? 'ok' : 'err' ?>"><?= $ready ? 'OK, maintenance_admin sudah tersedia' : 'Belum tersedia' ?></strong></p>
    </section>

    <section class="panel">
        <h2>Database Role</h2>
        <p>Sebelum request ini:</p>
        <pre><?= role_upgrade_e($before) ?></pre>
        <p>Saat ini:</p>
        <pre><?= role_upgrade_e($after) ?></pre>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= role_upgrade_e(csrf_token()) ?>">
            <button class="btn">Jalankan Upgrade Role</button>
        </form>
    </section>

    <section class="panel">
        <h2>File Aktif</h2>
        <p>Jika file ini bisa dibuka, berarti folder upload yang benar minimal sudah menerima file baru ini.</p>
        <pre><?= role_upgrade_e(__FILE__) ?></pre>
        <p><a class="btn" href="index.php?route=users">Kembali ke Users</a></p>
    </section>
</main>
</body>
</html>
