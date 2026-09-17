<?php

declare(strict_types=1);

/**
 * Modul Setup Regulasi Hak Akses Per Role
 * Mengontrol izin Lihat (View), Tambah (Create), Edit, dan Hapus (Remove)
 * untuk seluruh menu Master dan Transaksi.
 */
function handle_route_setup_regulations(PDO $pdo): void
{
    $user = require_role(['admin']);
    ensure_role_regulations_schema($pdo);

    $roles = [
        'admin' => 'Administrator Full',
        'maintenance_admin' => 'Admin Maintenance',
        'technician' => 'Teknisi Preventive',
        'corrective_maintenance' => 'Corrective Maintenance',
        'loan_officer' => 'Petugas Peminjaman Aset',
    ];

    $selectedRole = (string)($_GET['role'] ?? $_POST['role'] ?? 'maintenance_admin');
    if (!isset($roles[$selectedRole])) {
        $selectedRole = 'maintenance_admin';
    }

    $menus = get_regulated_menus();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'save');

        if ($action === 'reset_default') {
            try {
                // Delete current regulations for this role and re-seed default
                $pdo->prepare('DELETE FROM role_regulations WHERE role = ?')->execute([$selectedRole]);
                ensure_role_regulations_schema($pdo);
                flash('Regulasi hak akses untuk role ' . e($roles[$selectedRole]) . ' berhasil direset ke pengaturan bawaan.');
            } catch (Throwable $e) {
                flash('Gagal mereset regulasi: ' . $e->getMessage(), 'err');
            }
            redirect_to('setup_regulations', ['role' => $selectedRole]);
        }

        if ($action === 'save') {
            $submitted = (array)($_POST['reg'] ?? []);
            $pdo->beginTransaction();
            try {
                $stmtDel = $pdo->prepare('DELETE FROM role_regulations WHERE role = ?');
                $stmtDel->execute([$selectedRole]);

                $stmtIns = $pdo->prepare('INSERT INTO role_regulations (role, menu_key, can_view, can_create, can_edit, can_delete, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');

                foreach ($menus as $group => $items) {
                    foreach ($items as $mKey => $mLabel) {
                        $v = !empty($submitted[$mKey]['view']) ? 1 : 0;
                        $c = !empty($submitted[$mKey]['create']) ? 1 : 0;
                        $e = !empty($submitted[$mKey]['edit']) ? 1 : 0;
                        $d = !empty($submitted[$mKey]['delete']) ? 1 : 0;

                        // Jika create/edit/delete aktif, pastikan view juga aktif
                        if ($c || $e || $d) {
                            $v = 1;
                        }

                        // Jika admin, selalu 1
                        if ($selectedRole === 'admin') {
                            $v = $c = $e = $d = 1;
                        }

                        $stmtIns->execute([$selectedRole, $mKey, $v, $c, $e, $d]);
                    }
                }
                $pdo->commit();
                flash('Regulasi hak akses role ' . e($roles[$selectedRole]) . ' berhasil disimpan dan langsung aktif.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('Gagal menyimpan regulasi: ' . $e->getMessage(), 'err');
            }
            redirect_to('setup_regulations', ['role' => $selectedRole]);
        }
    }

    // Load current regulations for selected role
    $stmtCur = $pdo->prepare('SELECT menu_key, can_view, can_create, can_edit, can_delete FROM role_regulations WHERE role = ?');
    $stmtCur->execute([$selectedRole]);
    $curData = [];
    while ($r = $stmtCur->fetch(PDO::FETCH_ASSOC)) {
        $curData[$r['menu_key']] = $r;
    }

    render_header('Regulasi Hak Akses', $user);
    ?>

    <section class="panel">
        <div class="split">
            <div>
                <h1 style="display:flex;align-items:center;gap:10px;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
                    Regulasi Hak Akses Berdasarkan Role
                </h1>
                <p class="muted">Atur izin <strong>Lihat</strong>, <strong>Tambah</strong>, <strong>Edit</strong>, dan <strong>Remove (Hapus)</strong> untuk setiap menu Master dan Transaksi per role user.</p>
            </div>
            <div class="actions">
                <a class="btn" href="<?= route_url('users') ?>">Kelola Pengguna</a>
            </div>
        </div>

        <!-- Tab Role Selector -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:20px;border-bottom:1px solid #e2e8f0;padding-bottom:12px;">
            <?php foreach ($roles as $rKey => $rLabel): ?>
                <?php $isSelected = $rKey === $selectedRole; ?>
                <a href="<?= route_url('setup_regulations', ['role' => $rKey]) ?>" class="btn <?= $isSelected ? 'primary' : '' ?>" style="padding:8px 16px;font-weight:600;font-size:13px;border-radius:8px;">
                    <?= e($rLabel) ?>
                    <?php if ($rKey === 'admin'): ?>
                        <span style="font-size:10px;background:rgba(255,255,255,.25);padding:1px 6px;border-radius:999px;margin-left:4px;">Super</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <form method="post" id="formRegulasi">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="role" value="<?= e($selectedRole) ?>">
        <input type="hidden" name="action" value="save" id="formAction">

        <section class="panel">
            <div class="split" style="margin-bottom:16px;">
                <div>
                    <h2 style="margin:0;">Matriks Izin Role: <span style="color:#2563eb;"><?= e($roles[$selectedRole]) ?></span></h2>
                    <?php if ($selectedRole === 'admin'): ?>
                        <p class="muted" style="margin:4px 0 0 0;">Role Administrator memiliki hak akses penuh otomatis ke seluruh menu dan fungsi.</p>
                    <?php else: ?>
                        <p class="muted" style="margin:4px 0 0 0;">Centang fitur yang diizinkan untuk diakses, ditambah, diubah, atau dihapus oleh role ini.</p>
                    <?php endif; ?>
                </div>
                <?php if ($selectedRole !== 'admin'): ?>
                    <div class="actions">
                        <button type="button" class="btn" onclick="checkAll(true)">Pilih Semua</button>
                        <button type="button" class="btn" onclick="checkAll(false)">Batalkan Semua</button>
                        <button type="button" class="btn" onclick="checkColumn('create', true)">+ Centang Semua Tambah</button>
                        <button type="button" class="btn" onclick="checkColumn('edit', true)">✏️ Centang Semua Edit</button>
                        <button type="button" class="btn" onclick="checkColumn('delete', true)">🗑️ Centang Semua Hapus</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php foreach ($menus as $groupName => $groupMenus): ?>
                <div style="margin-top:24px;margin-bottom:12px;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#475569;background:#f1f5f9;padding:4px 12px;border-radius:6px;">
                        <?= e($groupName) ?>
                    </span>
                    <span class="muted" style="font-size:12px;">(<?= count($groupMenus) ?> Menu)</span>
                </div>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:40%;">Nama Menu / Modul</th>
                                <th style="width:15%;text-align:center;">Lihat (View)</th>
                                <th style="width:15%;text-align:center;">Tambah (Create)</th>
                                <th style="width:15%;text-align:center;">Ubah (Edit)</th>
                                <th style="width:15%;text-align:center;">Hapus (Remove)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupMenus as $mKey => $mLabel): ?>
                                <?php
                                $rRow = $curData[$mKey] ?? null;
                                $canView = $selectedRole === 'admin' ? 1 : (!empty($rRow['can_view']) ? 1 : 0);
                                $canCreate = $selectedRole === 'admin' ? 1 : (!empty($rRow['can_create']) ? 1 : 0);
                                $canEdit = $selectedRole === 'admin' ? 1 : (!empty($rRow['can_edit']) ? 1 : 0);
                                $canDelete = $selectedRole === 'admin' ? 1 : (!empty($rRow['can_delete']) ? 1 : 0);
                                $isDisabled = $selectedRole === 'admin' ? 'disabled' : '';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($mLabel) ?></strong>
                                        <div style="font-size:11px;color:#64748b;font-family:Consolas,monospace;">key: <?= e($mKey) ?></div>
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="reg[<?= e($mKey) ?>][view]" value="1" <?= $canView ? 'checked' : '' ?> <?= $isDisabled ?> class="chk-col-view chk-row-<?= e($mKey) ?>">
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="reg[<?= e($mKey) ?>][create]" value="1" <?= $canCreate ? 'checked' : '' ?> <?= $isDisabled ?> class="chk-col-create chk-row-<?= e($mKey) ?>" onchange="onActionTick('<?= e($mKey) ?>')">
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="reg[<?= e($mKey) ?>][edit]" value="1" <?= $canEdit ? 'checked' : '' ?> <?= $isDisabled ?> class="chk-col-edit chk-row-<?= e($mKey) ?>" onchange="onActionTick('<?= e($mKey) ?>')">
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="reg[<?= e($mKey) ?>][delete]" value="1" <?= $canDelete ? 'checked' : '' ?> <?= $isDisabled ?> class="chk-col-delete chk-row-<?= e($mKey) ?>" onchange="onActionTick('<?= e($mKey) ?>')">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <div class="split" style="margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;">
                <div>
                    <?php if ($selectedRole !== 'admin'): ?>
                        <button type="submit" class="btn primary" style="padding:10px 24px;font-size:14px;">💾 Simpan Regulasi Hak Akses</button>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($selectedRole !== 'admin'): ?>
                        <button type="button" class="btn danger" onclick="confirmReset()" style="font-size:12px;">↺ Reset Regulasi ke Default</button>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </form>

    <script>
    function checkAll(status) {
        document.querySelectorAll('#formRegulasi input[type=checkbox]').forEach(function(cb) {
            if (!cb.disabled) cb.checked = status;
        });
    }

    function checkColumn(col, status) {
        document.querySelectorAll('.chk-col-' + col).forEach(function(cb) {
            if (!cb.disabled) {
                cb.checked = status;
                if (status) {
                    var mKey = cb.className.match(/chk-row-([^\s]+)/);
                    if (mKey && mKey[1]) {
                        var vBox = document.querySelector('.chk-col-view.chk-row-' + mKey[1]);
                        if (vBox && !vBox.disabled) vBox.checked = true;
                    }
                }
            }
        });
    }

    function onActionTick(mKey) {
        var vBox = document.querySelector('.chk-col-view.chk-row-' + mKey);
        var cBox = document.querySelector('.chk-col-create.chk-row-' + mKey);
        var eBox = document.querySelector('.chk-col-edit.chk-row-' + mKey);
        var dBox = document.querySelector('.chk-col-delete.chk-row-' + mKey);
        if (vBox && ((cBox && cBox.checked) || (eBox && eBox.checked) || (dBox && dBox.checked))) {
            vBox.checked = true;
        }
    }

    function confirmReset() {
        if (confirm('Yakin ingin mereset seluruh regulasi untuk role ini kembali ke pengaturan awal bawaan sistem?')) {
            document.getElementById('formAction').value = 'reset_default';
            document.getElementById('formRegulasi').submit();
        }
    }
    </script>

    <?php
    render_footer();
}
