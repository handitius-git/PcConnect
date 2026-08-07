<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';
$cfg = is_file($configPath) ? require $configPath : [];

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function try_pdo(array $cfg, string $host, int $port): array
{
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, (string)($cfg['db_user'] ?? ''), (string)($cfg['db_pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $dbName = (string)($cfg['db_name'] ?? '');
        $dbExists = false;
        if ($dbName !== '') {
            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $stmt->execute([$dbName]);
            $dbExists = (bool)$stmt->fetchColumn();
        }
        return ['ok' => true, 'message' => $dbExists ? 'Connected, database exists.' : 'Connected, database belum ada.', 'db_exists' => $dbExists];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'db_exists' => false];
    }
}

$host = (string)($cfg['db_host'] ?? '127.0.0.1');
$port = (int)($cfg['db_port'] ?? 3306);
$tests = [
    [$host, $port],
    ['127.0.0.1', 3306],
    ['127.0.0.1', 3307],
    ['localhost', 3306],
];
$unique = [];
foreach ($tests as $test) {
    $unique[$test[0] . ':' . $test[1]] = $test;
}

?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PcConnect DB Check</title>
    <style>
        body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#172033;margin:0;padding:24px}
        main{max-width:920px;margin:auto;background:#fff;border:1px solid #dfe5ee;border-radius:8px;padding:22px}
        table{width:100%;border-collapse:collapse;margin:14px 0}th,td{border-bottom:1px solid #e2e8f0;text-align:left;padding:10px;vertical-align:top}
        .ok{color:#166534;font-weight:700}.bad{color:#991b1b;font-weight:700}code,pre{background:#f1f5f9;padding:2px 5px;border-radius:4px}pre{padding:12px;overflow:auto}
    </style>
</head>
<body>
<main>
    <h1>PcConnect Database Check</h1>
    <p>File config: <code><?= h($configPath) ?></code></p>
    <table>
        <tr><th>db_host</th><td><?= h($cfg['db_host'] ?? '-') ?></td></tr>
        <tr><th>db_port</th><td><?= h($cfg['db_port'] ?? '-') ?></td></tr>
        <tr><th>db_name</th><td><?= h($cfg['db_name'] ?? '-') ?></td></tr>
        <tr><th>db_user</th><td><?= h($cfg['db_user'] ?? '-') ?></td></tr>
    </table>

    <h2>Connection Test</h2>
    <table>
        <tr><th>Host</th><th>Port</th><th>Status</th><th>Message</th></tr>
        <?php foreach ($unique as [$testHost, $testPort]): $result = try_pdo($cfg, $testHost, (int)$testPort); ?>
            <tr>
                <td><?= h($testHost) ?></td>
                <td><?= h($testPort) ?></td>
                <td class="<?= $result['ok'] ? 'ok' : 'bad' ?>"><?= $result['ok'] ? 'OK' : 'FAILED' ?></td>
                <td><?= h($result['message']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Jika semua FAILED</h2>
    <p>MySQL/MariaDB belum aktif, port salah, atau user/password belum benar. Di QNAP, aktifkan MariaDB dari App Center/Control Panel lalu cek port yang dipakai.</p>
    <h2>Contoh SQL</h2>
    <pre>CREATE DATABASE pcconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pcconnect'@'localhost' IDENTIFIED BY 'password-kuat';
GRANT ALL PRIVILEGES ON pcconnect.* TO 'pcconnect'@'localhost';
GRANT ALL PRIVILEGES ON pcconnect.* TO 'pcconnect'@'127.0.0.1';
FLUSH PRIVILEGES;</pre>
    <p>Setelah database berhasil, import <code>database/schema.sql</code>. Hapus atau rename <code>public/db-check.php</code> setelah setup selesai.</p>
</main>
</body>
</html>
