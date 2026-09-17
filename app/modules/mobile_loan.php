<?php

declare(strict_types=1);

/**
 * Modul Mobile Peminjaman Aset (Mobile Asset Loans)
 * Versi mobile responsif untuk teknisi/petugas serah-terima alat kerja & unit aset.
 * Mendukung Live Camera QR Code Scanner, Pencarian Master Pengguna, & Pengembalian Cepat.
 */

function require_mobile_loan_user(): array
{
    if (!isset($_SESSION['user_id'])) {
        flash('Silakan login terlebih dahulu untuk mengakses Peminjaman Aset Mobile.', 'err');
        redirect_to('login');
    }
    return require_role(['admin', 'maintenance_admin', 'technician', 'corrective_maintenance', 'loan_officer']);
}

function render_mobile_loan_header(string $title, ?array $user): void
{
    $flash = flash();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
        <title><?= e($title) ?> - Peminjaman Aset Mobile</title>
        <style>
            * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
            body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #0f172a; color: #f8fafc; padding-bottom: 78px; }
            a { text-decoration: none; color: inherit; }
            header { background: #1e293b; border-bottom: 1px solid #334155; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
            .brand { font-weight: 700; font-size: 15px; color: #38bdf8; display: flex; align-items: center; gap: 6px; }
            .user-tag { font-size: 11px; color: #94a3b8; display: flex; align-items: center; gap: 6px; }
            .container { padding: 12px 14px; max-width: 600px; margin: 0 auto; }
            .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 14px; margin-bottom: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .card h2 { margin: 0 0 10px; font-size: 15px; color: #f1f5f9; }
            .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; border: none; border-radius: 8px; padding: 12px 16px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.2); margin-top: 6px; gap: 6px; }
            .btn-primary { background: #0284c7; color: #fff; }
            .btn-primary:active { background: #0369a1; }
            .btn-success { background: #059669; color: #fff; }
            .btn-success:active { background: #047857; }
            .btn-secondary { background: #334155; color: #f8fafc; border: 1px solid #475569; }
            .btn-danger { background: #e11d48; color: #fff; }
            .badge { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 600; }
            .badge-active { background: #0284c7; color: #fff; }
            .badge-overdue { background: #ef4444; color: #fff; }
            .badge-returned { background: #16a34a; color: #fff; }
            .flash { padding: 12px; border-radius: 8px; margin-bottom: 12px; font-size: 13px; }
            .flash-ok { background: #065f46; color: #d1fae5; border: 1px solid #047857; }
            .flash-err { background: #881337; color: #ffe4e6; border: 1px solid #be123c; }
            .flash-info { background: #1e3a8a; color: #dbeafe; border: 1px solid #3b82f6; }
            label { display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin: 10px 0 4px; }
            input, select, textarea { width: 100%; background: #0f172a; border: 1px solid #475569; color: #f8fafc; border-radius: 8px; padding: 10px 12px; font-size: 14px; outline: none; }
            input:focus, select:focus, textarea:focus { border-color: #38bdf8; }
            .nav-bottom { position: fixed; bottom: 0; left: 0; right: 0; background: #1e293b; border-top: 1px solid #334155; display: grid; grid-template-columns: repeat(4, 1fr); padding: 6px 0; z-index: 50; }
            .nav-item { text-align: center; font-size: 11px; color: #94a3b8; display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 4px 0; }
            .nav-item.active { color: #38bdf8; font-weight: 700; }
            .nav-icon { font-size: 18px; }
            .scan-box { width: 100%; max-width: 320px; margin: 10px auto; border-radius: 12px; overflow: hidden; background: #000; border: 2px dashed #38bdf8; position: relative; }
        </style>
    </head>
    <body>
        <header>
            <div class="brand">
                <span>📦</span>
                <span>PcConnect <strong>Loan</strong></span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="user-tag">
                    <span>👤 <?= e($user['name'] ?? 'User') ?></span>
                </div>
                <a href="<?= route_url('asset_loans') ?>" style="background:#334155;color:#f8fafc;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:600;" title="Buka Mode Desktop">
                    🖥️ Desktop
                </a>
            </div>
        </header>
        <div class="container">
            <?php if ($flash): ?>
                <div class="flash <?= $flash['type'] === 'err' ? 'flash-err' : ($flash['type'] === 'info' ? 'flash-info' : 'flash-ok') ?>">
                    <?= $flash['message'] ?>
                </div>
            <?php endif; ?>
    <?php
}

function render_mobile_loan_footer(string $activeTab = 'dashboard'): void
{
    ?>
        </div>
        <nav class="nav-bottom">
            <a href="<?= route_url('mobile_asset_loans') ?>" class="nav-item <?= $activeTab === 'dashboard' ? 'active' : '' ?>">
                <span class="nav-icon">🏠</span>
                <span>Pinjaman</span>
            </a>
            <a href="<?= route_url('mobile_asset_loan_create') ?>" class="nav-item <?= $activeTab === 'create' ? 'active' : '' ?>">
                <span class="nav-icon">➕</span>
                <span>Pinjam Baru</span>
            </a>
            <a href="<?= route_url('mobile_asset_loan_return') ?>" class="nav-item <?= $activeTab === 'return' ? 'active' : '' ?>">
                <span class="nav-icon">🔄</span>
                <span>Pengembalian</span>
            </a>
            <a href="<?= route_url('asset_loans') ?>" class="nav-item">
                <span class="nav-icon">🖥️</span>
                <span>Web Full</span>
            </a>
        </nav>
    </body>
    </html>
    <?php
}

/**
 * 1. Dashboard Peminjaman Mobile
 */
function handle_route_mobile_asset_loans(PDO $pdo): void
{
    $user = require_mobile_loan_user();
    $tab = (string)($_GET['tab'] ?? 'active');
    $q = trim((string)($_GET['q'] ?? ''));

    // Statistik
    $stats = [
        'active' => (int)$pdo->query("SELECT COUNT(*) FROM asset_loans WHERE status = 'active'")->fetchColumn(),
        'overdue' => (int)$pdo->query("SELECT COUNT(*) FROM asset_loans WHERE status = 'active' AND expected_return_date IS NOT NULL AND expected_return_date < CURDATE()")->fetchColumn(),
        'returned' => (int)$pdo->query("SELECT COUNT(*) FROM asset_loans WHERE status = 'returned'")->fetchColumn(),
    ];

    $where = ['1=1'];
    $params = [];

    if ($tab === 'active') {
        $where[] = "al.status = 'active'";
    } elseif ($tab === 'overdue') {
        $where[] = "al.status = 'active' AND al.expected_return_date IS NOT NULL AND al.expected_return_date < CURDATE()";
    } elseif ($tab === 'returned') {
        $where[] = "al.status = 'returned'";
    }

    if ($q !== '') {
        $where[] = "(al.loan_code LIKE ? OR al.borrower_name LIKE ? OR al.borrower_nik LIKE ? OR al.borrower_department LIKE ? OR EXISTS (
            SELECT 1 FROM asset_loan_items ali2 
            JOIN asset_items ai2 ON ai2.id = ali2.asset_item_id 
            WHERE ali2.loan_id = al.id AND (ai2.asset_code LIKE ? OR ai2.asset_name LIKE ?)
        ))";
        $w = '%' . $q . '%';
        $params = array_merge($params, [$w, $w, $w, $w, $w, $w]);
    }

    $sql = "SELECT al.*, u1.name AS officer_name,
            (SELECT COUNT(*) FROM asset_loan_items WHERE loan_id = al.id) AS total_items,
            (SELECT COUNT(*) FROM asset_loan_items WHERE loan_id = al.id AND status = 'borrowed') AS borrowed_items
            FROM asset_loans al
            LEFT JOIN users u1 ON u1.id = al.officer_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY al.id DESC LIMIT 60";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil item ringkas untuk setiap pinjaman
    $loanIds = array_column($loans, 'id');
    $itemsMap = [];
    if (!empty($loanIds)) {
        $inSql = implode(',', array_fill(0, count($loanIds), '?'));
        $itemQ = $pdo->prepare("SELECT ali.loan_id, ali.status AS item_status, ai.asset_code, ai.asset_name
                                FROM asset_loan_items ali
                                JOIN asset_items ai ON ai.id = ali.asset_item_id
                                WHERE ali.loan_id IN ($inSql)
                                ORDER BY ali.id ASC");
        $itemQ->execute($loanIds);
        foreach ($itemQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemsMap[$row['loan_id']][] = $row;
        }
    }

    render_mobile_loan_header('Peminjaman Aset', $user);
    ?>
    <!-- Aksi Cepat Mobile -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
        <a href="<?= route_url('mobile_asset_loan_create') ?>" class="btn btn-primary" style="margin:0;padding:12px 10px;font-size:13px;font-weight:700;">
            <span>➕</span> Pinjam Baru
        </a>
        <a href="<?= route_url('mobile_asset_loan_return') ?>" class="btn btn-success" style="margin:0;padding:12px 10px;font-size:13px;font-weight:700;">
            <span>🔄</span> Scan Kembali
        </a>
    </div>

    <!-- Ringkasan Status -->
    <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:6px;margin-bottom:12px;">
        <a href="<?= route_url('mobile_asset_loans', ['tab' => 'active']) ?>" style="background:<?= $tab === 'active' ? '#0369a1' : '#1e293b' ?>;border:1px solid #334155;border-radius:8px;padding:10px 6px;text-align:center;">
            <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Dipinjam</div>
            <div style="font-size:18px;font-weight:800;color:#38bdf8;margin-top:2px;"><?= $stats['active'] ?></div>
        </a>
        <a href="<?= route_url('mobile_asset_loans', ['tab' => 'overdue']) ?>" style="background:<?= $tab === 'overdue' ? '#991b1b' : ($stats['overdue'] > 0 ? '#450a0a' : '#1e293b') ?>;border:1px solid <?= $stats['overdue'] > 0 ? '#ef4444' : '#334155' ?>;border-radius:8px;padding:10px 6px;text-align:center;">
            <div style="font-size:10px;color:<?= $stats['overdue'] > 0 ? '#fca5a5' : '#94a3b8' ?>;font-weight:700;text-transform:uppercase;">Terlambat</div>
            <div style="font-size:18px;font-weight:800;color:<?= $stats['overdue'] > 0 ? '#f87171' : '#cbd5e1' ?>;margin-top:2px;"><?= $stats['overdue'] ?></div>
        </a>
        <a href="<?= route_url('mobile_asset_loans', ['tab' => 'returned']) ?>" style="background:<?= $tab === 'returned' ? '#15803d' : '#1e293b' ?>;border:1px solid #334155;border-radius:8px;padding:10px 6px;text-align:center;">
            <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Kembali</div>
            <div style="font-size:18px;font-weight:800;color:#4ade80;margin-top:2px;"><?= $stats['returned'] ?></div>
        </a>
    </div>

    <!-- Pencarian -->
    <form method="get" style="margin-bottom:12px;display:flex;gap:6px;">
        <input type="hidden" name="route" value="mobile_asset_loans">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input name="q" value="<?= e($q) ?>" placeholder="Cari peminjam / kode alat..." style="flex:1;font-size:13px;padding:8px 10px;">
        <button type="submit" class="btn btn-secondary" style="width:auto;margin:0;padding:8px 14px;font-size:13px;">Cari</button>
        <?php if ($q !== ''): ?>
            <a href="<?= route_url('mobile_asset_loans', ['tab' => $tab]) ?>" class="btn btn-secondary" style="width:auto;margin:0;padding:8px 12px;">✕</a>
        <?php endif; ?>
    </form>

    <!-- Daftar Peminjaman -->
    <?php if (empty($loans)): ?>
        <div class="card" style="text-align:center;padding:30px 16px;color:#94a3b8;">
            <div style="font-size:32px;margin-bottom:8px;">📭</div>
            <div style="font-size:14px;font-weight:600;">Tidak ada data peminjaman aset.</div>
            <div style="font-size:12px;margin-top:4px;">Gunakan tombol "Pinjam Baru" untuk mencatat serah-terima unit aset.</div>
        </div>
    <?php else: ?>
        <?php foreach ($loans as $loan): ?>
            <?php
            $isOverdue = ($loan['status'] === 'active' && !empty($loan['expected_return_date']) && $loan['expected_return_date'] < date('Y-m-d'));
            $items = $itemsMap[$loan['id']] ?? [];
            ?>
            <div class="card" style="<?= $isOverdue ? 'border-color:#ef4444;' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                    <div>
                        <div style="font-family:monospace;font-size:14px;font-weight:700;color:#38bdf8;">
                            <?= e($loan['loan_code']) ?>
                        </div>
                        <div style="font-size:15px;font-weight:700;color:#f8fafc;margin-top:2px;">
                            <?= e($loan['borrower_name']) ?>
                        </div>
                        <div style="font-size:12px;color:#94a3b8;">
                            <?= e($loan['borrower_department'] ?: '-') ?> &bull; NIK: <?= e($loan['borrower_nik'] ?: '-') ?>
                        </div>
                    </div>
                    <div>
                        <?php if ($loan['status'] === 'returned'): ?>
                            <span class="badge badge-returned">Sudah Kembali</span>
                        <?php elseif ($isOverdue): ?>
                            <span class="badge badge-overdue">Terlambat</span>
                        <?php else: ?>
                            <span class="badge badge-active">Sedang Dipinjam</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info Tanggal -->
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-top:10px;padding:8px;background:#0f172a;border-radius:6px;">
                    <div>
                        <span style="color:#94a3b8;">Dipinjam:</span>
                        <strong style="color:#cbd5e1;"><?= date('d/m/Y', strtotime($loan['loan_date'])) ?></strong>
                    </div>
                    <div>
                        <span style="color:#94a3b8;">Jatuh Tempo:</span>
                        <strong style="color:<?= $isOverdue ? '#f87171' : '#cbd5e1' ?>;">
                            <?= !empty($loan['expected_return_date']) ? date('d/m/Y', strtotime($loan['expected_return_date'])) : '-' ?>
                        </strong>
                    </div>
                </div>

                <!-- List Items Ringkas -->
                <div style="margin-top:10px;">
                    <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;margin-bottom:4px;">
                        Unit Aset (<?= count($items) ?>):
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <?php foreach ($items as $it): ?>
                            <span style="font-size:11px;padding:3px 6px;border-radius:4px;background:#334155;color:#e2e8f0;display:inline-flex;align-items:center;gap:4px;">
                                <strong style="color:#38bdf8;font-family:monospace;"><?= e($it['asset_code']) ?></strong>
                                <span><?= e($it['asset_name']) ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tombol Aksi -->
                <div style="display:flex;gap:6px;margin-top:12px;">
                    <?php if ($loan['status'] === 'active'): ?>
                        <a href="<?= route_url('mobile_asset_loan_return', ['id' => $loan['id']]) ?>" class="btn btn-success" style="flex:1;margin:0;padding:8px 10px;font-size:13px;">
                            <span>🔄</span> Proses Pengembalian
                        </a>
                    <?php endif; ?>
                    <a href="<?= route_url('mobile_asset_loan_detail', ['id' => $loan['id']]) ?>" class="btn btn-secondary" style="<?= $loan['status'] === 'active' ? 'width:auto;min-width:75px;' : 'flex:1;' ?>margin:0;padding:8px 10px;font-size:13px;">
                        <span>👁️</span> Detail
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    render_mobile_loan_footer('dashboard');
}

/**
 * 2. Form Peminjaman Baru Mobile (Scan QR Camera + Autocomplete Pengguna)
 */
function handle_route_mobile_asset_loan_create(PDO $pdo): void
{
    $user = require_mobile_loan_user();

    // Proses Simpan POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $borrowerName = trim((string)($_POST['borrower_name'] ?? ''));
        $borrowerNik = trim((string)($_POST['borrower_nik'] ?? ''));
        $borrowerDept = trim((string)($_POST['borrower_department'] ?? ''));
        $borrowerPhone = trim((string)($_POST['borrower_phone'] ?? ''));
        $loanDate = trim((string)($_POST['loan_date'] ?? date('Y-m-d')));
        $expectedReturn = trim((string)($_POST['expected_return_date'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $assetItemIds = $_POST['asset_item_ids'] ?? [];
        $conditionOut = $_POST['condition_out'] ?? [];
        $notesOut = $_POST['item_notes_out'] ?? [];

        if ($borrowerName === '') {
            flash('Nama peminjam wajib diisi atau dipilih dari Master Pengguna.', 'err');
            redirect_to('mobile_asset_loan_create');
        }

        if (empty($assetItemIds) || !is_array($assetItemIds)) {
            flash('Scan atau pilih minimal 1 unit aset yang akan dipinjam.', 'err');
            redirect_to('mobile_asset_loan_create');
        }

        try {
            $pdo->beginTransaction();

            // Generate Kode Peminjaman LN-YYYYMM-XXXX
            $prefix = 'LN-' . date('Ym') . '-';
            $stmtSeq = $pdo->prepare("SELECT COUNT(*) FROM asset_loans WHERE loan_code LIKE ?");
            $stmtSeq->execute([$prefix . '%']);
            $seq = (int)$stmtSeq->fetchColumn() + 1;
            $loanCode = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            // Double check keunikan kode
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_loans WHERE loan_code = ?");
            $chkStmt->execute([$loanCode]);
            if ((int)$chkStmt->fetchColumn() > 0) {
                $loanCode = $prefix . str_pad((string)($seq + mt_rand(1, 99)), 4, '0', STR_PAD_LEFT);
            }

            // Insert Header
            $insHeader = $pdo->prepare("INSERT INTO asset_loans 
                (loan_code, borrower_name, borrower_nik, borrower_department, borrower_phone, loan_date, expected_return_date, purpose, officer_user_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
            $insHeader->execute([
                $loanCode,
                $borrowerName,
                $borrowerNik ?: null,
                $borrowerDept ?: null,
                $borrowerPhone ?: null,
                $loanDate ?: date('Y-m-d'),
                $expectedReturn ?: null,
                $purpose ?: null,
                $user['id'] ?? null
            ]);
            $loanId = (int)$pdo->lastInsertId();

            // Status borrowed ID
            $borrowedStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'BORROWED' LIMIT 1")->fetchColumn();

            // Insert Items & Update Asset Status
            $insItem = $pdo->prepare("INSERT INTO asset_loan_items 
                (loan_id, asset_item_id, condition_out, notes_out, status, created_at)
                VALUES (?, ?, ?, ?, 'borrowed', NOW())");

            $updAsset = $pdo->prepare("UPDATE asset_items 
                SET status = 'borrowed', asset_status_id = ?, custodian_name = ?, custodian_nik = ?, updated_at = NOW() 
                WHERE id = ?");

            foreach ($assetItemIds as $aid) {
                $aid = (int)$aid;
                if ($aid <= 0) continue;

                $cond = trim((string)($conditionOut[$aid] ?? 'Normal / Baik'));
                $note = trim((string)($notesOut[$aid] ?? ''));

                $insItem->execute([$loanId, $aid, $cond ?: 'Normal / Baik', $note ?: null]);
                $updAsset->execute([$borrowedStatusId ?: null, $borrowerName, $borrowerNik ?: null, $aid]);
            }

            $pdo->commit();
            flash("Peminjaman <strong>{$loanCode}</strong> untuk <strong>{$borrowerName}</strong> berhasil disimpan!", 'ok');
            redirect_to('mobile_asset_loans');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('Gagal menyimpan peminjaman aset: ' . $e->getMessage(), 'err');
            redirect_to('mobile_asset_loan_create');
        }
    }

    render_mobile_loan_header('Pinjam Aset Baru', $user);
    ?>
    <div class="card">
        <h2 style="display:flex;align-items:center;gap:6px;">
            <span>➕</span> Catat Peminjaman Aset
        </h2>
        <form method="post" action="<?= route_url('mobile_asset_loan_create') ?>" onsubmit="return validateMobileLoanForm()">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <!-- 1. Peminjam (Autocomplete Master Pengguna) -->
            <div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px;margin-bottom:12px;">
                <div style="font-size:12px;font-weight:700;color:#38bdf8;text-transform:uppercase;margin-bottom:6px;">
                    👤 1. Identitas Peminjam (Master Pengguna)
                </div>
                <div style="position:relative;">
                    <input type="text" id="employeeSearchInput" placeholder="Ketik nama karyawan atau NIK..." autocomplete="off" style="padding-right:32px;">
                    <div id="employeeDropdownList" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:220px;overflow-y:auto;background:#1e293b;border:1px solid #475569;border-radius:8px;z-index:99;box-shadow:0 10px 25px rgba(0,0,0,0.5);margin-top:4px;"></div>
                </div>
                <div id="borrowerSourceBadge" style="display:none;margin-top:6px;font-size:11px;background:#065f46;color:#d1fae5;padding:4px 8px;border-radius:4px;font-weight:600;"></div>

                <div style="margin-top:8px;">
                    <label>Nama Peminjam <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="borrower_name" id="borrowerNameInput" required placeholder="Nama lengkap peminjam">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <label>NIK / ID Karyawan</label>
                        <input type="text" name="borrower_nik" id="borrowerNikInput" placeholder="NIK">
                    </div>
                    <div>
                        <label>Departemen</label>
                        <input type="text" name="borrower_department" id="borrowerDeptInput" placeholder="Departemen">
                    </div>
                </div>
                <div>
                    <label>No. Telepon / WhatsApp</label>
                    <input type="text" name="borrower_phone" placeholder="Contoh: 08123456789">
                </div>
            </div>

            <!-- 2. Tanggal Peminjaman -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
                <div>
                    <label>Tanggal Pinjam</label>
                    <input type="date" name="loan_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label>Batas Pengembalian</label>
                    <input type="date" name="expected_return_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                </div>
            </div>

            <!-- 3. Scanner Kamera QR Live & Barcode Input -->
            <div style="background:#0f172a;border:1px solid #0284c7;border-radius:8px;padding:12px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <div style="font-size:12px;font-weight:700;color:#38bdf8;text-transform:uppercase;">
                        📷 2. Scan QR / Masukkan Kode Unit <span style="color:#ef4444;">*</span>
                    </div>
                    <button type="button" id="btnToggleCamera" onclick="toggleCameraScanner()" style="background:#0284c7;color:#fff;border:none;border-radius:6px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;">
                        📷 Buka Kamera
                    </button>
                </div>

                <!-- Box Scanner Kamera Live -->
                <div id="cameraScannerBox" style="display:none;margin-bottom:10px;text-align:center;">
                    <div id="reader" style="width:100%;max-width:280px;margin:0 auto;border-radius:8px;overflow:hidden;background:#000;"></div>
                    <p id="camStatus" style="color:#38bdf8;font-size:11px;margin:6px 0;">Menyiapkan kamera live QR...</p>
                </div>

                <!-- Manual / Barcode input -->
                <div style="display:flex;gap:6px;">
                    <input type="text" id="barcodeAssetInput" placeholder="Ketik/scan kode unit (contoh: IT-CMP-000001)..." style="font-family:monospace;font-weight:700;font-size:13px;" onkeydown="if(event.key==='Enter'){event.preventDefault();lookupAndAddAsset(this.value);}">
                    <button type="button" class="btn btn-primary" onclick="lookupAndAddAsset(document.getElementById('barcodeAssetInput').value)" style="width:auto;margin:0;padding:8px 14px;white-space:nowrap;font-size:13px;">
                        + Tambah
                    </button>
                </div>
                <div id="scanFeedbackAlert" style="display:none;margin-top:8px;padding:8px 10px;border-radius:6px;font-size:12px;font-weight:600;"></div>
            </div>

            <!-- 4. Keranjang Unit Terpilih -->
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <div style="font-size:12px;font-weight:700;color:#cbd5e1;text-transform:uppercase;">
                        Unit Aset Terpilih
                    </div>
                    <span id="selectedCountBadge" class="badge badge-active">0 unit</span>
                </div>

                <div id="loanItemsContainer"></div>
                <div id="emptyLoanItemsMsg" style="padding:20px;text-align:center;background:#0f172a;border-radius:8px;border:1px dashed #334155;color:#94a3b8;font-size:12px;">
                    Belum ada unit aset yang ditambahkan. Silakan scan QR code pada unit fisik atau ketik kode unit di atas.
                </div>
            </div>

            <!-- 5. Keperluan Pinjam -->
            <div style="margin-bottom:16px;">
                <label>Keperluan / Catatan Pinjam</label>
                <textarea name="purpose" placeholder="Contoh: Pekerjaan maintenance server lapangan / presentasi cabang..." style="min-height:70px;"></textarea>
            </div>

            <!-- Tombol Simpan -->
            <button type="submit" class="btn btn-primary" style="padding:14px;font-size:15px;font-weight:700;">
                💾 Simpan Peminjaman Aset
            </button>
            <a href="<?= route_url('mobile_asset_loans') ?>" class="btn btn-secondary" style="margin-top:6px;">
                Batal
            </a>
        </form>
    </div>

    <script src="https://unpkg.com/html5-qrcode"></script>
    <script>
    var scannedItems = [];
    var html5QrCode = null;
    var isCameraOpen = false;

    function renderScannedContainer() {
        var container = document.getElementById("loanItemsContainer");
        var emptyMsg = document.getElementById("emptyLoanItemsMsg");
        var badge = document.getElementById("selectedCountBadge");
        container.innerHTML = "";

        if (scannedItems.length === 0) {
            emptyMsg.style.display = "block";
            badge.textContent = "0 unit";
            badge.className = "badge";
            badge.style.background = "#475569";
            return;
        }

        emptyMsg.style.display = "none";
        badge.textContent = scannedItems.length + " unit terpilih";
        badge.className = "badge badge-active";
        badge.style.background = "#0284c7";

        scannedItems.forEach(function(item, idx) {
            var card = document.createElement("div");
            card.style.background = "#0f172a";
            card.style.border = "1px solid #334155";
            card.style.borderRadius = "8px";
            card.style.padding = "10px";
            card.style.marginBottom = "8px";

            card.innerHTML = 
                "<div style='display:flex;justify-content:space-between;align-items:flex-start;gap:6px;'>"
                + "<div>"
                + "  <div style='font-family:monospace;font-weight:800;color:#38bdf8;font-size:13px;'>"
                + "    <input type='hidden' name='asset_item_ids[]' value='" + item.id + "'>"
                + "    " + item.asset_code
                + "  </div>"
                + "  <div style='font-size:13px;font-weight:700;color:#f8fafc;margin-top:2px;'>" + (item.asset_name || "-") + "</div>"
                + "  <div style='font-size:11px;color:#94a3b8;'>" + (item.brand ? (item.brand + " ") : "") + (item.model || "") + "</div>"
                + "</div>"
                + "<button type='button' onclick='removeScannedItem(" + item.id + ")' style='background:#dc2626;color:#fff;border:none;border-radius:4px;padding:4px 8px;font-size:11px;cursor:pointer;'>✕ Hapus</button>"
                + "</div>"
                + "<div style='margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:6px;'>"
                + "  <div>"
                + "    <div style='font-size:10px;color:#94a3b8;margin-bottom:2px;'>Kondisi Awal</div>"
                + "    <input name='condition_out[" + item.id + "]' value='Normal / Baik' placeholder='Kondisi' style='padding:6px;font-size:12px;'>"
                + "  </div>"
                + "  <div>"
                + "    <div style='font-size:10px;color:#94a3b8;margin-bottom:2px;'>Catatan Fisik</div>"
                + "    <input name='item_notes_out[" + item.id + "]' value='' placeholder='Kelengkapan...' style='padding:6px;font-size:12px;'>"
                + "  </div>"
                + "</div>";

            container.appendChild(card);
        });
    }

    function removeScannedItem(id) {
        scannedItems = scannedItems.filter(function(x) { return x.id !== id; });
        renderScannedContainer();
        showFeedback("Unit telah dihapus dari daftar pinjaman.", "info");
    }

    function showFeedback(msg, type) {
        var fb = document.getElementById("scanFeedbackAlert");
        if (!fb) return;
        fb.style.display = "block";
        fb.textContent = msg;
        if (type === "success") {
            fb.style.background = "#065f46";
            fb.style.color = "#d1fae5";
            fb.style.border = "1px solid #047857";
        } else if (type === "error") {
            fb.style.background = "#881337";
            fb.style.color = "#ffe4e6";
            fb.style.border = "1px solid #be123c";
        } else {
            fb.style.background = "#1e3a8a";
            fb.style.color = "#dbeafe";
            fb.style.border = "1px solid #3b82f6";
        }
    }

    function lookupAndAddAsset(code) {
        code = (code || "").trim();
        if (!code) {
            showFeedback("Silakan masukkan atau scan kode unit aset terlebih dahulu.", "error");
            return;
        }

        var already = scannedItems.some(function(item) {
            return item.asset_code.toUpperCase() === code.toUpperCase() || String(item.id) === code;
        });
        if (already) {
            showFeedback("Unit " + code + " sudah ada dalam daftar di bawah.", "error");
            document.getElementById("barcodeAssetInput").value = "";
            return;
        }

        showFeedback("Memverifikasi unit: " + code + "...", "info");
        var url = "index.php?route=api_lookup_asset_for_loan&code=" + encodeURIComponent(code);

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (!resp || !resp.ok || !resp.found) {
                    showFeedback(resp && resp.message ? resp.message : "Unit aset tidak ditemukan di database.", "error");
                    return;
                }
                if (!resp.is_available) {
                    showFeedback(resp.message || "Unit tidak dapat dipinjam saat ini.", "error");
                    return;
                }
                var item = resp.data;
                if (scannedItems.some(function(x) { return x.id === item.id; })) {
                    showFeedback("Unit " + item.asset_code + " sudah ada dalam daftar.", "error");
                    document.getElementById("barcodeAssetInput").value = "";
                    return;
                }

                scannedItems.push({
                    id: item.id,
                    asset_code: item.asset_code,
                    asset_name: item.asset_name,
                    brand: item.brand,
                    model: item.model,
                    group_name: item.group_name,
                    type_name: item.type_name
                });

                renderScannedContainer();
                showFeedback("✓ Unit " + item.asset_code + " berhasil ditambahkan!", "success");
                document.getElementById("barcodeAssetInput").value = "";
            })
            .catch(function(err) {
                showFeedback("Gagal menghubungi server: " + err.message, "error");
            });
    }

    function toggleCameraScanner() {
        var box = document.getElementById("cameraScannerBox");
        var btn = document.getElementById("btnToggleCamera");
        if (isCameraOpen) {
            if (html5QrCode) {
                html5QrCode.stop().then(function() {
                    html5QrCode.clear();
                    box.style.display = "none";
                    btn.textContent = "📷 Buka Kamera";
                    isCameraOpen = false;
                }).catch(function() {
                    box.style.display = "none";
                    btn.textContent = "📷 Buka Kamera";
                    isCameraOpen = false;
                });
            } else {
                box.style.display = "none";
                btn.textContent = "📷 Buka Kamera";
                isCameraOpen = false;
            }
        } else {
            box.style.display = "block";
            btn.textContent = "✕ Tutup Kamera";
            isCameraOpen = true;
            startCameraScanner();
        }
    }

    function startCameraScanner() {
        var camStatus = document.getElementById("camStatus");
        camStatus.textContent = "Menyalakan kamera live...";
        html5QrCode = new Html5Qrcode("reader");
        var config = { fps: 10, qrbox: { width: 220, height: 220 } };
        html5QrCode.start({ facingMode: "environment" }, config, function(decodedText) {
            camStatus.textContent = "QR Terbaca: " + decodedText;
            lookupAndAddAsset(decodedText);
        }).catch(function(err) {
            camStatus.textContent = "Kamera tidak dapat diakses (" + err + ").";
        });
    }

    // Autocomplete Master Pengguna
    (function() {
        var searchInput = document.getElementById("employeeSearchInput");
        var dropdown = document.getElementById("employeeDropdownList");
        var nikInput = document.getElementById("borrowerNikInput");
        var nameInput = document.getElementById("borrowerNameInput");
        var deptInput = document.getElementById("borrowerDeptInput");
        var badge = document.getElementById("borrowerSourceBadge");
        var timer = null;

        function hideDropdown() {
            setTimeout(function() { dropdown.style.display = "none"; }, 250);
        }

        function selectEmployee(item) {
            nameInput.value = item.name || "";
            nikInput.value = item.nik || "";
            deptInput.value = item.department || "";
            searchInput.value = (item.name || "") + " (" + (item.nik || "-") + ")";
            badge.style.display = "block";
            badge.textContent = "✓ Master Pengguna: " + (item.name || "") + " (" + (item.department || "-") + ")";
            dropdown.style.display = "none";
        }

        function searchEmployees(q) {
            fetch("index.php?route=employee_search&limit=25&q=" + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var data = (res && res.data) ? res.data : [];
                    dropdown.innerHTML = "";
                    if (data.length === 0) {
                        dropdown.innerHTML = "<div style='padding:10px;color:#94a3b8;font-size:12px;'>Tidak ada karyawan cocok di Master Pengguna.</div>";
                        dropdown.style.display = "block";
                        return;
                    }
                    data.forEach(function(row) {
                        var div = document.createElement("div");
                        div.style.padding = "8px 12px";
                        div.style.cursor = "pointer";
                        div.style.borderBottom = "1px solid #334155";
                        div.style.fontSize = "13px";
                        div.innerHTML = "<strong style='color:#f8fafc;'>" + (row.name || "-") + "</strong>"
                            + " <span style='color:#94a3b8;font-size:11px;'>(" + (row.nik || "-") + ")</span>"
                            + (row.department ? (" <span style='color:#38bdf8;font-size:11px;'>&bull; " + row.department + "</span>") : "");
                        div.onclick = function() { selectEmployee(row); };
                        dropdown.appendChild(div);
                    });
                    dropdown.style.display = "block";
                })
                .catch(function() {});
        }

        if (searchInput) {
            searchInput.addEventListener("input", function() {
                clearTimeout(timer);
                var q = searchInput.value.trim();
                if (q.length === 0) {
                    dropdown.style.display = "none";
                    return;
                }
                timer = setTimeout(function() { searchEmployees(q); }, 200);
            });
            searchInput.addEventListener("focus", function() {
                if (searchInput.value.trim()) {
                    searchEmployees(searchInput.value.trim());
                }
            });
            searchInput.addEventListener("blur", hideDropdown);
        }
    })();

    function validateMobileLoanForm() {
        var name = document.getElementById("borrowerNameInput").value.trim();
        if (!name) {
            alert("Nama peminjam wajib diisi.");
            document.getElementById("borrowerNameInput").focus();
            return false;
        }
        if (scannedItems.length === 0) {
            alert("Scan atau masukkan minimal 1 unit aset yang akan dipinjam terlebih dahulu.");
            document.getElementById("barcodeAssetInput").focus();
            return false;
        }
        return true;
    }

    renderScannedContainer();
    </script>
    <?php
    render_mobile_loan_footer('create');
}

/**
 * 3. Pengembalian Aset Mobile (Cari Cepat lewat QR Scan / Pilih Transaksi)
 */
function handle_route_mobile_asset_loan_return(PDO $pdo): void
{
    $user = require_mobile_loan_user();
    $id = (int)($_GET['id'] ?? $_POST['loan_id'] ?? 0);

    // Jika ID diberikan, proses form pengembalian
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM asset_loans WHERE id = ?");
        $stmt->execute([$id]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$loan) {
            flash('Data peminjaman tidak ditemukan.', 'err');
            redirect_to('mobile_asset_loans');
        }

        if ($loan['status'] === 'returned') {
            flash('Peminjaman nomor ' . $loan['loan_code'] . ' sudah berstatus dikembalikan.', 'info');
            redirect_to('mobile_asset_loan_detail', ['id' => $id]);
        }

        // Proses POST Pengembalian
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $actualReturnDate = trim((string)($_POST['actual_return_date'] ?? ''));
            if ($actualReturnDate === '') {
                $actualReturnDate = date('Y-m-d H:i:s');
            } else {
                $actualReturnDate = date('Y-m-d H:i:s', strtotime($actualReturnDate));
            }
            $officerNotes = trim((string)($_POST['officer_notes'] ?? ''));
            $itemStatuses = $_POST['item_status'] ?? [];
            $conditionsIn = $_POST['condition_in'] ?? [];
            $notesIn = $_POST['notes_in'] ?? [];

            $activeStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'ACTIVE' LIMIT 1")->fetchColumn();
            $repairStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'REPAIR' LIMIT 1")->fetchColumn();
            $lostStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'LOST' LIMIT 1")->fetchColumn();

            try {
                $pdo->beginTransaction();

                $updItem = $pdo->prepare("UPDATE asset_loan_items 
                    SET condition_in = ?, notes_in = ?, returned_at = ?, status = ? 
                    WHERE id = ? AND loan_id = ?");

                $updAsset = $pdo->prepare("UPDATE asset_items 
                    SET status = ?, asset_status_id = ?, custodian_name = NULL, custodian_nik = NULL, updated_at = NOW() 
                    WHERE id = ?");

                $itemQuery = $pdo->prepare("SELECT id, asset_item_id FROM asset_loan_items WHERE loan_id = ?");
                $itemQuery->execute([$id]);
                $existingItems = $itemQuery->fetchAll(PDO::FETCH_ASSOC);

                foreach ($existingItems as $it) {
                    $itemId = (int)$it['id'];
                    $assetId = (int)$it['asset_item_id'];

                    $st = in_array(($itemStatuses[$itemId] ?? ''), ['returned', 'damaged', 'lost'], true) ? $itemStatuses[$itemId] : 'returned';
                    $cond = trim((string)($conditionsIn[$itemId] ?? 'Normal / Baik'));
                    $note = trim((string)($notesIn[$itemId] ?? ''));

                    $updItem->execute([$cond ?: 'Normal / Baik', $note ?: null, $actualReturnDate, $st, $itemId, $id]);

                    if ($st === 'damaged') {
                        $updAsset->execute(['repair', $repairStatusId ?: null, $assetId]);
                    } elseif ($st === 'lost') {
                        $updAsset->execute(['lost', $lostStatusId ?: null, $assetId]);
                    } else {
                        $updAsset->execute(['active', $activeStatusId ?: null, $assetId]);
                    }
                }

                $updHeader = $pdo->prepare("UPDATE asset_loans 
                    SET status = 'returned', actual_return_date = ?, return_officer_user_id = ?, officer_notes = CONCAT(COALESCE(officer_notes,''), '\n[Pengembalian Mobile]: ', ?) 
                    WHERE id = ?");
                $updHeader->execute([$actualReturnDate, $user['id'] ?? null, $officerNotes ?: 'Semua unit dikembalikan via mobile', $id]);

                $pdo->commit();
                flash("Pengembalian unit untuk transaksi <strong>{$loan['loan_code']}</strong> berhasil dicatat!", 'ok');
                redirect_to('mobile_asset_loans');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('Gagal memproses pengembalian: ' . $e->getMessage(), 'err');
                redirect_to('mobile_asset_loan_return', ['id' => $id]);
            }
        }

        // Ambil data item
        $itemsStmt = $pdo->prepare("SELECT ali.*, ai.asset_code, ai.asset_name, ai.brand, ai.model, g.group_name, t.type_name
            FROM asset_loan_items ali
            JOIN asset_items ai ON ai.id = ali.asset_item_id
            LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
            LEFT JOIN asset_types t ON t.id = ai.asset_type_id
            WHERE ali.loan_id = ?
            ORDER BY ali.id ASC");
        $itemsStmt->execute([$id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        render_mobile_loan_header('Pengembalian Aset', $user);
        ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;font-weight:700;">Konfirmasi Pengembalian</div>
                    <div style="font-family:monospace;font-size:16px;font-weight:800;color:#38bdf8;margin-top:2px;">
                        <?= e($loan['loan_code']) ?>
                    </div>
                </div>
                <span class="badge badge-active">Sedang Dipinjam</span>
            </div>

            <div style="margin-top:10px;padding:10px;background:#0f172a;border-radius:8px;font-size:13px;">
                <div style="font-weight:700;color:#f8fafc;"><?= e($loan['borrower_name']) ?></div>
                <div style="color:#94a3b8;font-size:12px;">
                    <?= e($loan['borrower_department'] ?: '-') ?> &bull; NIK: <?= e($loan['borrower_nik'] ?: '-') ?>
                </div>
                <div style="margin-top:6px;font-size:12px;color:#cbd5e1;">
                    Dipinjam: <strong><?= date('d/m/Y', strtotime($loan['loan_date'])) ?></strong> | Batas: <strong><?= !empty($loan['expected_return_date']) ? date('d/m/Y', strtotime($loan['expected_return_date'])) : '-' ?></strong>
                </div>
            </div>

            <form method="post" action="<?= route_url('mobile_asset_loan_return', ['id' => $id]) ?>" style="margin-top:14px;">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="loan_id" value="<?= $id ?>">

                <label>Waktu Pengembalian Aktual</label>
                <input type="datetime-local" name="actual_return_date" value="<?= date('Y-m-d\TH:i') ?>" required>

                <div style="margin-top:14px;">
                    <div style="font-size:12px;font-weight:700;color:#38bdf8;text-transform:uppercase;margin-bottom:8px;">
                        Checklist Fisik Unit Dikembalikan (<?= count($items) ?>):
                    </div>

                    <?php foreach ($items as $idx => $it): ?>
                        <div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px;margin-bottom:10px;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;">
                                <div>
                                    <div style="font-family:monospace;font-weight:800;color:#38bdf8;font-size:13px;">
                                        <?= e($it['asset_code']) ?>
                                    </div>
                                    <div style="font-size:13px;font-weight:700;color:#f8fafc;">
                                        <?= e($it['asset_name']) ?>
                                    </div>
                                    <div style="font-size:11px;color:#94a3b8;">
                                        <?= e($it['brand']) ?> <?= e($it['model']) ?>
                                    </div>
                                </div>
                                <div style="font-size:11px;color:#94a3b8;text-align:right;">
                                    Awal: <em><?= e($it['condition_out'] ?: 'Normal') ?></em>
                                </div>
                            </div>

                            <div style="margin-top:8px;">
                                <label style="font-size:11px;margin:4px 0 2px;">Status Pengembalian</label>
                                <select name="item_status[<?= (int)$it['id'] ?>]" style="padding:8px;font-size:13px;">
                                    <option value="returned">✓ Kembali Normal / Baik</option>
                                    <option value="damaged">⚠️ Kembali Kondisi Rusak</option>
                                    <option value="lost">❌ Hilang / Tidak Kembali</option>
                                </select>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px;">
                                <div>
                                    <label style="font-size:11px;margin:2px 0;">Kondisi Fisik</label>
                                    <input name="condition_in[<?= (int)$it['id'] ?>]" value="Normal / Baik" placeholder="Kondisi" style="padding:6px;font-size:12px;">
                                </div>
                                <div>
                                    <label style="font-size:11px;margin:2px 0;">Catatan Unit</label>
                                    <input name="notes_in[<?= (int)$it['id'] ?>]" value="" placeholder="Keterangan..." style="padding:6px;font-size:12px;">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:10px;">
                    <label>Catatan Petugas Pemeriksa</label>
                    <textarea name="officer_notes" placeholder="Catatan kelengkapan saat pengembalian diterima..." style="min-height:70px;"></textarea>
                </div>

                <button type="submit" class="btn btn-success" style="padding:14px;font-size:15px;font-weight:700;margin-top:14px;" onclick="return confirm('Konfirmasi pencatatan pengembalian unit aset ini?')">
                    ✅ Konfirmasi Pengembalian Unit
                </button>
                <a href="<?= route_url('mobile_asset_loans') ?>" class="btn btn-secondary" style="margin-top:6px;">
                    Batal
                </a>
            </form>
        </div>
        <?php
        render_mobile_loan_footer('return');
        return;
    }

    // Jika tanpa ID: Tampilkan Scanner Kamera QR untuk cari transaksi peminjaman aktif!
    $activeLoans = $pdo->query("SELECT al.id, al.loan_code, al.borrower_name, al.borrower_department, al.loan_date, al.expected_return_date,
        (SELECT COUNT(*) FROM asset_loan_items WHERE loan_id = al.id AND status = 'borrowed') AS borrowed_items
        FROM asset_loans al
        WHERE al.status = 'active'
        ORDER BY al.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

    render_mobile_loan_header('Scan Pengembalian Aset', $user);
    ?>
    <div class="card">
        <h2 style="display:flex;align-items:center;gap:6px;">
            <span>🔄</span> Pengembalian Cepat Unit Aset
        </h2>
        <p style="font-size:12px;color:#94a3b8;margin:0 0 10px 0;">
            Scan stiker QR code pada unit alat fisik yang dikembalikan untuk langsung memproses verifikasinya.
        </p>

        <!-- Live Camera Scanner Box -->
        <div style="background:#0f172a;border:1px solid #0284c7;border-radius:8px;padding:12px;text-align:center;">
            <div id="reader" style="width:100%;max-width:280px;margin:0 auto;border-radius:8px;overflow:hidden;background:#000;"></div>
            <p id="camStatus" style="color:#38bdf8;font-size:12px;margin:8px 0 4px 0;">Kamera scanner live QR aktif</p>
            <div id="scanReturnFeedback" style="display:none;margin-top:8px;padding:8px 10px;border-radius:6px;font-size:12px;font-weight:600;text-align:left;"></div>
        </div>

        <!-- Manual input fallback -->
        <div style="display:flex;gap:6px;margin-top:10px;">
            <input type="text" id="manualCodeInput" placeholder="Atau ketik kode unit (contoh: IT-CMP-000001)..." style="font-family:monospace;font-size:13px;" onkeydown="if(event.key==='Enter'){event.preventDefault();lookupLoanForReturn(this.value);}">
            <button type="button" class="btn btn-primary" onclick="lookupLoanForReturn(document.getElementById('manualCodeInput').value)" style="width:auto;margin:0;padding:8px 14px;white-space:nowrap;font-size:13px;">
                Cari
            </button>
        </div>
    </div>

    <!-- Atau Pilih dari Transaksi Aktif -->
    <div style="margin-top:14px;">
        <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:8px;">
            Atau Pilih Transaksi Dipinjam (<?= count($activeLoans) ?>):
        </div>

        <?php if (empty($activeLoans)): ?>
            <div class="card" style="text-align:center;padding:24px 14px;color:#94a3b8;font-size:13px;">
                Tidak ada peminjaman aktif saat ini. Semua alat telah dikembalikan.
            </div>
        <?php else: ?>
            <?php foreach ($activeLoans as $al): ?>
                <div class="card" style="padding:12px;margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div style="font-family:monospace;font-weight:800;color:#38bdf8;font-size:13px;">
                                <?= e($al['loan_code']) ?>
                            </div>
                            <div style="font-size:14px;font-weight:700;color:#f8fafc;margin-top:2px;">
                                <?= e($al['borrower_name']) ?>
                            </div>
                            <div style="font-size:11px;color:#94a3b8;">
                                <?= e($al['borrower_department'] ?: '-') ?> &bull; <?= (int)$al['borrowed_items'] ?> unit
                            </div>
                        </div>
                        <a href="<?= route_url('mobile_asset_loan_return', ['id' => $al['id']]) ?>" class="btn btn-success" style="width:auto;margin:0;padding:6px 12px;font-size:12px;">
                            Kembalikan →
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/html5-qrcode"></script>
    <script>
    var html5QrCode = null;

    function showReturnFeedback(msg, type) {
        var fb = document.getElementById("scanReturnFeedback");
        if (!fb) return;
        fb.style.display = "block";
        fb.textContent = msg;
        if (type === "success") {
            fb.style.background = "#065f46";
            fb.style.color = "#d1fae5";
            fb.style.border = "1px solid #047857";
        } else if (type === "error") {
            fb.style.background = "#881337";
            fb.style.color = "#ffe4e6";
            fb.style.border = "1px solid #be123c";
        } else {
            fb.style.background = "#1e3a8a";
            fb.style.color = "#dbeafe";
            fb.style.border = "1px solid #3b82f6";
        }
    }

    function lookupLoanForReturn(code) {
        code = (code || "").trim();
        if (!code) {
            showReturnFeedback("Masukkan atau scan kode unit aset terlebih dahulu.", "error");
            return;
        }

        showReturnFeedback("Memeriksa status peminjaman unit: " + code + "...", "info");
        var url = "index.php?route=api_lookup_asset_for_loan&code=" + encodeURIComponent(code);

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (!resp || !resp.ok || !resp.found) {
                    showReturnFeedback(resp && resp.message ? resp.message : "Unit tidak ditemukan.", "error");
                    return;
                }
                var data = resp.data;
                if (data.status === "borrowed" && data.loan_info && data.loan_info.loan_id) {
                    showReturnFeedback("✓ Ditemukan! Unit " + data.asset_code + " sedang dipinjam oleh " + (data.loan_info.borrower_name || "karyawan") + ". Membuka form pengembalian...", "success");
                    setTimeout(function() {
                        window.location.href = "index.php?route=mobile_asset_loan_return&id=" + data.loan_info.loan_id;
                    }, 800);
                } else {
                    showReturnFeedback("ℹ Unit " + data.asset_code + " tidak sedang dalam status dipinjam (Status: " + data.status + ").", "error");
                }
            })
            .catch(function(err) {
                showReturnFeedback("Gagal memeriksa unit: " + err.message, "error");
            });
    }

    function startLiveReturnScanner() {
        var camStatus = document.getElementById("camStatus");
        html5QrCode = new Html5Qrcode("reader");
        var config = { fps: 10, qrbox: { width: 220, height: 220 } };
        html5QrCode.start({ facingMode: "environment" }, config, function(decodedText) {
            camStatus.textContent = "QR Terbaca: " + decodedText;
            lookupLoanForReturn(decodedText);
        }).catch(function(err) {
            camStatus.textContent = "Kamera tidak aktif (" + err + "). Silakan gunakan input kode manual di bawah.";
        });
    }

    startLiveReturnScanner();
    </script>
    <?php
    render_mobile_loan_footer('return');
}

/**
 * 4. Detail Peminjaman Mobile
 */
function handle_route_mobile_asset_loan_detail(PDO $pdo): void
{
    $user = require_mobile_loan_user();
    $id = (int)($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT al.*, u1.name AS officer_name, u2.name AS return_officer_name
        FROM asset_loans al
        LEFT JOIN users u1 ON u1.id = al.officer_user_id
        LEFT JOIN users u2 ON u2.id = al.return_officer_user_id
        WHERE al.id = ?");
    $stmt->execute([$id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        flash('Data peminjaman tidak ditemukan.', 'err');
        redirect_to('mobile_asset_loans');
    }

    $itemStmt = $pdo->prepare("SELECT ali.*, ai.asset_code, ai.asset_name, ai.brand, ai.model, g.group_name, t.type_name
        FROM asset_loan_items ali
        JOIN asset_items ai ON ai.id = ali.asset_item_id
        LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
        LEFT JOIN asset_types t ON t.id = ai.asset_type_id
        WHERE ali.loan_id = ?
        ORDER BY ali.id ASC");
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $isOverdue = ($loan['status'] === 'active' && !empty($loan['expected_return_date']) && $loan['expected_return_date'] < date('Y-m-d'));

    render_mobile_loan_header('Detail Pinjaman', $user);
    ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
                <div style="font-family:monospace;font-size:17px;font-weight:800;color:#38bdf8;">
                    <?= e($loan['loan_code']) ?>
                </div>
                <div style="font-size:16px;font-weight:700;color:#f8fafc;margin-top:2px;">
                    <?= e($loan['borrower_name']) ?>
                </div>
                <div style="font-size:12px;color:#94a3b8;">
                    <?= e($loan['borrower_department'] ?: '-') ?> &bull; NIK: <?= e($loan['borrower_nik'] ?: '-') ?>
                </div>
            </div>
            <div>
                <?php if ($loan['status'] === 'returned'): ?>
                    <span class="badge badge-returned">Sudah Kembali</span>
                <?php elseif ($isOverdue): ?>
                    <span class="badge badge-overdue">Terlambat</span>
                <?php else: ?>
                    <span class="badge badge-active">Sedang Dipinjam</span>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top:12px;padding:10px;background:#0f172a;border-radius:8px;font-size:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <div>
                <span style="color:#94a3b8;">Tanggal Pinjam:</span><br>
                <strong style="color:#cbd5e1;"><?= date('d/m/Y', strtotime($loan['loan_date'])) ?></strong>
            </div>
            <div>
                <span style="color:#94a3b8;">Batas Kembali:</span><br>
                <strong style="color:<?= $isOverdue ? '#f87171' : '#cbd5e1' ?>;"><?= !empty($loan['expected_return_date']) ? date('d/m/Y', strtotime($loan['expected_return_date'])) : '-' ?></strong>
            </div>
            <div>
                <span style="color:#94a3b8;">Petugas Serah:</span><br>
                <strong style="color:#cbd5e1;"><?= e($loan['officer_name'] ?: '-') ?></strong>
            </div>
            <div>
                <span style="color:#94a3b8;">Petugas Terima:</span><br>
                <strong style="color:#cbd5e1;"><?= e($loan['return_officer_name'] ?: '-') ?></strong>
            </div>
        </div>

        <?php if (!empty($loan['purpose'])): ?>
            <div style="margin-top:10px;font-size:12px;color:#cbd5e1;background:#0f172a;padding:8px 10px;border-radius:6px;">
                <span style="color:#94a3b8;font-weight:700;">Keperluan:</span> <?= nl2br(e($loan['purpose'])) ?>
            </div>
        <?php endif; ?>

        <!-- List Items -->
        <div style="margin-top:14px;">
            <div style="font-size:12px;font-weight:700;color:#38bdf8;text-transform:uppercase;margin-bottom:8px;">
                Daftar Unit Aset Fisik (<?= count($items) ?>):
            </div>
            <?php foreach ($items as $it): ?>
                <div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px;margin-bottom:8px;font-size:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div style="font-family:monospace;font-weight:800;color:#38bdf8;"><?= e($it['asset_code']) ?></div>
                            <div style="font-weight:700;color:#f8fafc;font-size:13px;"><?= e($it['asset_name']) ?></div>
                            <div style="color:#94a3b8;font-size:11px;"><?= e($it['brand']) ?> <?= e($it['model']) ?></div>
                        </div>
                        <div>
                            <?php if ($it['status'] === 'returned'): ?>
                                <span class="badge badge-returned">Kembali</span>
                            <?php elseif ($it['status'] === 'damaged'): ?>
                                <span class="badge" style="background:#ea580c;color:#fff;">Rusak</span>
                            <?php elseif ($it['status'] === 'lost'): ?>
                                <span class="badge" style="background:#dc2626;color:#fff;">Hilang</span>
                            <?php else: ?>
                                <span class="badge badge-active">Dipinjam</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-top:6px;font-size:11px;color:#94a3b8;">
                        Awal: <em><?= e($it['condition_out'] ?: 'Normal') ?></em>
                        <?php if (!empty($it['condition_in'])): ?>
                            &bull; Kembali: <em style="color:#f8fafc;"><?= e($it['condition_in']) ?></em>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:14px;display:flex;gap:6px;">
            <?php if ($loan['status'] === 'active'): ?>
                <a href="<?= route_url('mobile_asset_loan_return', ['id' => $loan['id']]) ?>" class="btn btn-success" style="flex:1;margin:0;padding:10px;font-size:13px;">
                    <span>🔄</span> Proses Pengembalian
                </a>
            <?php endif; ?>
            <a href="<?= route_url('asset_loan_detail', ['id' => $loan['id']]) ?>" target="_blank" class="btn btn-secondary" style="flex:1;margin:0;padding:10px;font-size:13px;">
                <span>🖨️</span> Cetak Surat
            </a>
        </div>
        <a href="<?= route_url('mobile_asset_loans') ?>" class="btn btn-secondary" style="margin-top:8px;">
            ⬅️ Kembali ke Daftar
        </a>
    </div>
    <?php
    render_mobile_loan_footer('dashboard');
}

