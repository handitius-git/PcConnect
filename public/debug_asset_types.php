<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/app/lib/bootstrap.php';
require_once dirname(__DIR__) . '/app/lib/schema.php';

$pdo = Database::pdo();
$user = current_user() ?? ['id' => 1, 'name' => 'Admin', 'role' => 'admin'];

$actionMsg = '';
if (isset($_GET['action']) && $_GET['action'] === 'repair') {
    repair_asset_type_groups($pdo);
    $actionMsg = '<div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:12px;border-radius:6px;margin:12px 0;"><strong>Berhasil:</strong> Fungsi repair_asset_type_groups() telah dijalankan di database server!</div>';
}

$groups = $pdo->query('SELECT * FROM asset_groups ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query('SELECT t.*, g.group_code, g.group_name FROM asset_types t LEFT JOIN asset_groups g ON g.id = t.asset_group_id ORDER BY t.id ASC')->fetchAll(PDO::FETCH_ASSOC);

$assetPhpPath = dirname(__DIR__) . '/app/modules/asset.php';
$assetPhpSize = file_exists($assetPhpPath) ? filesize($assetPhpPath) : 0;
$assetPhpMd5 = file_exists($assetPhpPath) ? md5_file($assetPhpPath) : 'NOT FOUND';
$assetPhpMod = file_exists($assetPhpPath) ? date('Y-m-d H:i:s', filemtime($assetPhpPath)) : '-';
$assetPhpContainsAllTypes = file_exists($assetPhpPath) && str_contains(file_get_contents($assetPhpPath), 'window.allAssetTypes');

render_header('Diagnostik Kategori & Komoditas', $user);
?>
<section class="panel">
    <h2>Status Berkas asset.php di Server NAS:</h2>
    <ul>
        <li><strong>Path:</strong> <?= htmlspecialchars($assetPhpPath) ?></li>
        <li><strong>Ukuran File:</strong> <?= $assetPhpSize ?> bytes</li>
        <li><strong>Terakhir Dimodifikasi:</strong> <?= $assetPhpMod ?></li>
        <li><strong>MD5 Hash:</strong> <?= $assetPhpMd5 ?></li>
        <li><strong>Berisi window.allAssetTypes?</strong> <?= $assetPhpContainsAllTypes ? '<span class="badge ok">YA (Versi Baru)</span>' : '<span class="badge danger">TIDAK (MASIH VERSI LAMA!)</span>' ?></li>
    </ul>
</section>

<section class="panel">
    <h2>4. Live Render Form Tambah Unit Aset:</h2>
    <?php
    require_once dirname(__DIR__) . '/app/modules/asset.php';
    echo asset_master_item_form_html($pdo, asset_master_item_defaults(), false);
    ?>
</section>


<section class="panel">
    <h2>1. Pengujian Interaktif Pemilihan Komoditas -> Kategori</h2>
    <div class="grid two">
        <label>Pilih Komoditas:
            <select id="testGroup" onchange="runTestFilter()" style="font-size:15px;padding:8px;">
                <option value="">- Pilih Komoditas -</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['group_code'] . ' - ' . $g['group_name'] . ' (ID: ' . $g['id'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Hasil Kategori yang Muncul:
            <select id="testType" style="font-size:15px;padding:8px;">
                <option value="">- Pilih Komoditas Terlebih Dahulu -</option>
            </select>
        </label>
    </div>
    <div id="testLog" style="background:#0f172a;color:#38bdf8;padding:12px;border-radius:6px;font-family:Consolas,monospace;font-size:13px;margin-top:12px;min-height:40px;">
        Pilih salah satu Komoditas di atas untuk melihat log filter.
    </div>
</section>

<section class="panel">
    <h2>2. Data Tabel <code>asset_groups</code> Saat Ini (Total: <?= count($groups) ?>)</h2>
    <table>
        <thead><tr><th>ID</th><th>Kode Group</th><th>Nama Komoditas</th><th>Status Aktif</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <tr>
                <td><strong><?= (int)$g['id'] ?></strong></td>
                <td><span class="badge ok"><?= htmlspecialchars($g['group_code']) ?></span></td>
                <td><?= htmlspecialchars($g['group_name']) ?></td>
                <td><?= (int)($g['is_active'] ?? 1) ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>3. Data Tabel <code>asset_types</code> Saat Ini (Total: <?= count($types) ?>)</h2>
    <table>
        <thead><tr><th>ID</th><th>Asset Group ID</th><th>Relasi Komoditas</th><th>Kode Kategori</th><th>Nama Kategori</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($types as $t): ?>
            <tr>
                <td><strong><?= (int)$t['id'] ?></strong></td>
                <td><?= (int)$t['asset_group_id'] ?></td>
                <td><?= htmlspecialchars(($t['group_code'] ?? '-') . ' - ' . ($t['group_name'] ?? '-')) ?></td>
                <td><span class="badge"><?= htmlspecialchars($t['type_code']) ?></span></td>
                <td><?= htmlspecialchars($t['type_name']) ?></td>
                <td><?= (int)($t['is_active'] ?? 1) ? '<span class="badge ok">Aktif</span>' : '<span class="badge danger">Nonaktif</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<script>
var DB_GROUPS = <?= json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var DB_TYPES = <?= json_encode($types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function runTestFilter(){
    var selG = document.getElementById("testGroup");
    var selT = document.getElementById("testType");
    var log = document.getElementById("testLog");
    var gVal = parseInt(selG.value, 10) || 0;

    if(gVal === 0){
        selT.innerHTML = '<option value="">- Pilih Komoditas Terlebih Dahulu -</option>';
        log.textContent = "Komoditas belum dipilih (gVal = 0)";
        return;
    }

    var grp = DB_GROUPS.find(function(g){ return parseInt(g.id, 10) === gVal; });
    var gCode = grp ? grp.group_code.toUpperCase() : "";

    var matching = DB_TYPES.filter(function(t){
        var tGId = parseInt(t.asset_group_id, 10) || 0;
        var tGCode = (t.group_code || "").toUpperCase();
        if (tGId === gVal) return true;
        if (gCode && tGCode === gCode) return true;
        return false;
    });

    var html = '<option value="">- Pilih Kategori (' + matching.length + ' Ditemukan) -</option>';
    matching.forEach(function(m){
        html += '<option value="' + m.id + '">' + m.type_code + ' - ' + m.type_name + ' (GroupID: ' + m.asset_group_id + ')</option>';
    });
    selT.innerHTML = html;

    log.innerHTML = "Dipilih: Group ID " + gVal + " (" + gCode + ") &rarr; Ditemukan " + matching.length + " Kategori:<br>" +
        JSON.stringify(matching.map(function(m){ return { id: m.id, code: m.type_code, name: m.type_name, group_id: m.asset_group_id }; }), null, 2);
}
</script>
<?php
render_footer();
