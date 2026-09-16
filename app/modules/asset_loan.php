<?php

declare(strict_types=1);

/**
 * Modul Peminjaman Aset (Asset Loan Management)
 * Terintegrasi dengan Unit Aset (asset_items), Master Karyawan (employee_directory), dan Status Aset.
 */

function handle_route_asset_loans(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician', 'corrective_maintenance']);
    $tab = trim((string)($_GET['tab'] ?? 'active'));
    if (!in_array($tab, ['active', 'overdue', 'returned', 'all'], true)) {
        $tab = 'active';
    }
    $q = trim((string)($_GET['q'] ?? ''));

    // Hitung statistik ringkasan
    $stats = [
        'active' => 0,
        'overdue' => 0,
        'returned' => 0,
        'total_items_out' => 0,
    ];
    try {
        $stats['active'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_loans WHERE status = 'active'")->fetchColumn();
        $stats['overdue'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_loans WHERE status = 'active' AND expected_return_date IS NOT NULL AND expected_return_date < CURDATE()")->fetchColumn();
        $stats['returned'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_loans WHERE status = 'returned'")->fetchColumn();
        $stats['total_items_out'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_loan_items ali JOIN asset_loans al ON al.id = ali.loan_id WHERE al.status = 'active' AND ali.status = 'borrowed'")->fetchColumn();
    } catch (Throwable $e) {
    }

    // Build Query data
    $where = ["1=1"];
    $params = [];

    if ($tab === 'active') {
        $where[] = "al.status = 'active'";
    } elseif ($tab === 'overdue') {
        $where[] = "al.status = 'active' AND al.expected_return_date IS NOT NULL AND al.expected_return_date < CURDATE()";
    } elseif ($tab === 'returned') {
        $where[] = "al.status = 'returned'";
    }

    if ($q !== '') {
        $where[] = "(al.loan_code LIKE ? OR al.borrower_name LIKE ? OR al.borrower_nik LIKE ? OR al.borrower_department LIKE ? OR al.purpose LIKE ? OR EXISTS (
            SELECT 1 FROM asset_loan_items ali2 
            JOIN asset_items ai2 ON ai2.id = ali2.asset_item_id 
            WHERE ali2.loan_id = al.id AND (ai2.asset_code LIKE ? OR ai2.asset_name LIKE ? OR ai2.model LIKE ?)
        ))";
        $w = '%' . $q . '%';
        $params = array_merge($params, [$w, $w, $w, $w, $w, $w, $w, $w]);
    }

    $sql = "SELECT al.*, u1.name AS officer_name, u2.name AS return_officer_name,
            (SELECT COUNT(*) FROM asset_loan_items WHERE loan_id = al.id) AS total_items,
            (SELECT COUNT(*) FROM asset_loan_items WHERE loan_id = al.id AND status = 'borrowed') AS borrowed_items
            FROM asset_loans al
            LEFT JOIN users u1 ON u1.id = al.officer_user_id
            LEFT JOIN users u2 ON u2.id = al.return_officer_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY al.id DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil detail unit aset untuk setiap peminjaman
    $loanIds = array_column($loans, 'id');
    $loanItems = [];
    if (!empty($loanIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($loanIds), '?'));
        $itemStmt = $pdo->prepare("SELECT ali.*, ai.asset_code, ai.asset_name, ai.brand, ai.model, g.group_name, t.type_name
            FROM asset_loan_items ali
            JOIN asset_items ai ON ai.id = ali.asset_item_id
            LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
            LEFT JOIN asset_types t ON t.id = ai.asset_type_id
            WHERE ali.loan_id IN ($inPlaceholders)
            ORDER BY ali.id ASC");
        $itemStmt->execute($loanIds);
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $loanItems[$it['loan_id']][] = $it;
        }
    }

    render_header('Peminjaman Aset', $user);

    // Navigasi & Statistik
    echo '<section class="panel">'
        . '<div class="split" style="align-items:center;margin-bottom:16px;">'
        . '  <div>'
        . '    <h1 style="margin:0;display:flex;align-items:center;gap:8px;">📦 Peminjaman Aset</h1>'
        . '    <p class="muted" style="margin:4px 0 0 0;">Pengelolaan serah-terima peminjaman perkakas, alat kerja, dan unit aset operasional.</p>'
        . '  </div>'
        . '  <div class="actions">'
        . '    <a class="btn primary" href="' . route_url('asset_loan_form') . '" style="display:inline-flex;align-items:center;gap:6px;font-weight:bold;">'
        . '      <span>➕</span> Catat Peminjaman Aset'
        . '    </a>'
        . '    <a class="btn" href="' . route_url('report_asset_loans') . '">📊 Laporan Peminjaman</a>'
        . '  </div>'
        . '</div>'

        // Kartu Ringkasan
        . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;">'
        . '  <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;">'
        . '    <div style="font-size:12px;color:#0369a1;font-weight:600;text-transform:uppercase;">Sedang Dipinjam</div>'
        . '    <div style="font-size:24px;font-weight:700;color:#0284c7;margin-top:4px;">' . $stats['active'] . ' <span style="font-size:13px;font-weight:normal;color:#64748b;">transaksi</span></div>'
        . '  </div>'
        . '  <div style="background:' . ($stats['overdue'] > 0 ? '#fef2f2' : '#f8fafc') . ';border:1px solid ' . ($stats['overdue'] > 0 ? '#fecaca' : '#e2e8f0') . ';border-radius:8px;padding:12px 16px;">'
        . '    <div style="font-size:12px;color:' . ($stats['overdue'] > 0 ? '#b91c1c' : '#64748b') . ';font-weight:600;text-transform:uppercase;">Jatuh Tempo (Overdue)</div>'
        . '    <div style="font-size:24px;font-weight:700;color:' . ($stats['overdue'] > 0 ? '#ef4444' : '#64748b') . ';margin-top:4px;">' . $stats['overdue'] . ' <span style="font-size:13px;font-weight:normal;color:#64748b;">transaksi</span></div>'
        . '  </div>'
        . '  <div style="background:#fdf4ff;border:1px solid #f5d0fe;border-radius:8px;padding:12px 16px;">'
        . '    <div style="font-size:12px;color:#86198f;font-weight:600;text-transform:uppercase;">Total Unit di Luar</div>'
        . '    <div style="font-size:24px;font-weight:700;color:#a21caf;margin-top:4px;">' . $stats['total_items_out'] . ' <span style="font-size:13px;font-weight:normal;color:#64748b;">unit fisik</span></div>'
        . '  </div>'
        . '  <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;">'
        . '    <div style="font-size:12px;color:#166534;font-weight:600;text-transform:uppercase;">Sudah Kembali</div>'
        . '    <div style="font-size:24px;font-weight:700;color:#16a34a;margin-top:4px;">' . $stats['returned'] . ' <span style="font-size:13px;font-weight:normal;color:#64748b;">transaksi</span></div>'
        . '  </div>'
        . '</div>'

        // Filter Tabs & Search
        . '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px;">'
        . '  <div style="display:flex;gap:6px;flex-wrap:wrap;">'
        . '    <a href="' . route_url('asset_loans', ['tab' => 'active', 'q' => $q]) . '" class="btn ' . ($tab === 'active' ? 'primary' : '') . '" style="padding:6px 14px;border-radius:20px;font-size:13px;">Sedang Dipinjam (' . $stats['active'] . ')</a>'
        . '    <a href="' . route_url('asset_loans', ['tab' => 'overdue', 'q' => $q]) . '" class="btn ' . ($tab === 'overdue' ? 'danger' : '') . '" style="padding:6px 14px;border-radius:20px;font-size:13px;' . ($tab !== 'overdue' && $stats['overdue'] > 0 ? 'border-color:#fca5a5;color:#dc2626;' : '') . '">Terlambat (' . $stats['overdue'] . ')</a>'
        . '    <a href="' . route_url('asset_loans', ['tab' => 'returned', 'q' => $q]) . '" class="btn ' . ($tab === 'returned' ? 'primary' : '') . '" style="padding:6px 14px;border-radius:20px;font-size:13px;">Sudah Kembali (' . $stats['returned'] . ')</a>'
        . '    <a href="' . route_url('asset_loans', ['tab' => 'all', 'q' => $q]) . '" class="btn ' . ($tab === 'all' ? 'primary' : '') . '" style="padding:6px 14px;border-radius:20px;font-size:13px;">Semua Transaksi</a>'
        . '  </div>'
        . '  <form method="get" style="margin:0;display:flex;gap:6px;">'
        . '    <input type="hidden" name="route" value="asset_loans">'
        . '    <input type="hidden" name="tab" value="' . e($tab) . '">'
        . '    <input name="q" value="' . e($q) . '" placeholder="Cari no pinjam / peminjam / alat..." style="padding:6px 12px;font-size:13px;width:240px;">'
        . '    <button class="btn" style="padding:6px 12px;">Cari</button>'
        . ($q !== '' ? '    <a class="btn" href="' . route_url('asset_loans', ['tab' => $tab]) . '" style="padding:6px 10px;">✕</a>' : '')
        . '  </form>'
        . '</div>';

    // Tabel Daftar Peminjaman
    if (empty($loans)) {
        echo '<div style="text-align:center;padding:48px 16px;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1;color:#64748b;">'
            . '<div style="font-size:36px;margin-bottom:8px;">📦</div>'
            . '<div style="font-size:16px;font-weight:600;color:#334155;">Tidak ada data peminjaman ditemukan</div>'
            . '<p style="margin:4px 0 16px 0;">' . ($q !== '' ? 'Tidak ada hasil untuk kata kunci "' . e($q) . '".' : 'Belum ada transaksi peminjaman untuk kategori ini.') . '</p>'
            . '<a class="btn primary" href="' . route_url('asset_loan_form') . '">+ Catat Peminjaman Baru</a>'
            . '</div>';
    } else {
        echo '<div style="overflow-x:auto;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            . '<thead><tr style="background:#f1f5f9;border-bottom:2px solid #cbd5e1;text-align:left;">'
            . '  <th style="padding:10px 12px;">No. Pinjam</th>'
            . '  <th style="padding:10px 12px;">Peminjam (NIK / Dept)</th>'
            . '  <th style="padding:10px 12px;">Unit Aset Dipinjam</th>'
            . '  <th style="padding:10px 12px;">Tgl Pinjam & Estimasi</th>'
            . '  <th style="padding:10px 12px;">Keperluan</th>'
            . '  <th style="padding:10px 12px;text-align:center;">Status</th>'
            . '  <th style="padding:10px 12px;text-align:center;">Aksi</th>'
            . '</tr></thead>'
            . '<tbody>';

        $today = date('Y-m-d');
        foreach ($loans as $l) {
            $isOverdue = ($l['status'] === 'active' && !empty($l['expected_return_date']) && $l['expected_return_date'] < $today);
            $items = $loanItems[$l['id']] ?? [];

            // Badge Status
            $badge = '';
            if ($l['status'] === 'returned') {
                $badge = '<span class="badge ok" style="font-weight:600;">✓ Dikembalikan</span>';
            } elseif ($isOverdue) {
                $badge = '<span class="badge danger" style="font-weight:600;">⚠️ Terlambat</span>';
            } elseif ($l['status'] === 'active') {
                $badge = '<span class="badge warning" style="font-weight:600;">Sedang Dipinjam</span>';
            } else {
                $badge = '<span class="badge muted">' . e(ucfirst($l['status'])) . '</span>';
            }

            // HTML daftar item
            $itemsHtml = '<div style="display:flex;flex-direction:column;gap:4px;">';
            foreach ($items as $it) {
                $codeBadge = '<strong style="color:#0369a1;font-family:monospace;">' . e($it['asset_code']) . '</strong>';
                $nameDesc = e($it['asset_name'] ?: ($it['brand'] . ' ' . $it['model']));
                $itemStatus = '';
                if ($it['status'] === 'returned') {
                    $itemStatus = ' <span style="font-size:10px;color:#166534;background:#dcfce7;padding:1px 5px;border-radius:4px;">Kembali</span>';
                } elseif ($it['status'] === 'damaged') {
                    $itemStatus = ' <span style="font-size:10px;color:#991b1b;background:#fee2e2;padding:1px 5px;border-radius:4px;">Rusak</span>';
                }
                $itemsHtml .= '<div style="font-size:12px;line-height:1.3;">' . $codeBadge . ' &bull; ' . $nameDesc . $itemStatus . '</div>';
            }
            $itemsHtml .= '</div>';

            echo '<tr style="border-bottom:1px solid #e2e8f0;background:' . ($isOverdue ? '#fff7ed' : '#fff') . ';">'
                . '  <td style="padding:10px 12px;vertical-align:top;">'
                . '    <a href="' . route_url('asset_loan_detail', ['id' => $l['id']]) . '" style="font-weight:700;color:#0284c7;text-decoration:none;">' . e($l['loan_code']) . '</a>'
                . '    <div style="font-size:11px;color:#64748b;margin-top:2px;">Petugas: ' . e($l['officer_name'] ?? 'System') . '</div>'
                . '  </td>'
                . '  <td style="padding:10px 12px;vertical-align:top;">'
                . '    <div style="font-weight:600;color:#1e293b;">' . e($l['borrower_name']) . '</div>'
                . '    <div style="font-size:11px;color:#64748b;">'
                . (!empty($l['borrower_nik']) ? 'NIK: ' . e($l['borrower_nik']) : '')
                . (!empty($l['borrower_department']) ? ' &bull; ' . e($l['borrower_department']) : '')
                . '    </div>'
                . '  </td>'
                . '  <td style="padding:10px 12px;vertical-align:top;">' . $itemsHtml . '</td>'
                . '  <td style="padding:10px 12px;vertical-align:top;">'
                . '    <div>📅 Pinjam: ' . date('d/m/Y H:i', strtotime($l['loan_date'])) . '</div>'
                . '    <div style="margin-top:2px;' . ($isOverdue ? 'color:#dc2626;font-weight:600;' : 'color:#64748b;') . '">'
                . '      🎯 Target: ' . (!empty($l['expected_return_date']) ? date('d/m/Y', strtotime($l['expected_return_date'])) : '-')
                . ($isOverdue ? ' (Lewat!)' : '')
                . '    </div>'
                . ($l['status'] === 'returned' && !empty($l['actual_return_date']) ? '<div style="color:#166534;font-size:11px;margin-top:2px;">✓ Kembali: ' . date('d/m/Y H:i', strtotime($l['actual_return_date'])) . '</div>' : '')
                . '  </td>'
                . '  <td style="padding:10px 12px;vertical-align:top;max-width:200px;">'
                . '    <div style="color:#334155;word-break:break-word;">' . e($l['purpose'] ?: '-') . '</div>'
                . (!empty($l['location_note']) ? '<div style="font-size:11px;color:#64748b;margin-top:2px;">📍 ' . e($l['location_note']) . '</div>' : '')
                . '  </td>'
                . '  <td style="padding:10px 12px;vertical-align:top;text-align:center;">' . $badge . '</td>'
                . '  <td style="padding:10px 12px;vertical-align:top;text-align:center;white-space:nowrap;">'
                . '    <div style="display:inline-flex;gap:4px;flex-wrap:wrap;justify-content:center;">'
                . '      <a class="btn" href="' . route_url('asset_loan_detail', ['id' => $l['id']]) . '" style="padding:4px 8px;font-size:12px;" title="Lihat Detail & Bukti Pinjam">📄 Detail</a>'
                . ($l['status'] === 'active' ? '      <a class="btn primary" href="' . route_url('asset_loan_return', ['id' => $l['id']]) . '" style="padding:4px 8px;font-size:12px;background:#16a34a;border-color:#16a34a;" title="Proses Pengembalian">📥 Kembalikan</a>' : '')
                . '    </div>'
                . '  </td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</section>';
    render_footer();
}

/**
 * Form Peminjaman Aset Baru
 */
function handle_route_asset_loan_form(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician']);

    // Proses Simpan Transaksi Peminjaman
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $borrowerName = trim((string)($_POST['borrower_name'] ?? ''));
        $borrowerNik = trim((string)($_POST['borrower_nik'] ?? ''));
        $borrowerDept = trim((string)($_POST['borrower_department'] ?? ''));
        $borrowerPhone = trim((string)($_POST['borrower_phone'] ?? ''));
        $loanDate = trim((string)($_POST['loan_date'] ?? ''));
        $expectedReturnDate = trim((string)($_POST['expected_return_date'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $locationNote = trim((string)($_POST['location_note'] ?? ''));
        $officerNotes = trim((string)($_POST['officer_notes'] ?? ''));
        $selectedAssetIds = $_POST['asset_item_ids'] ?? [];

        if (!is_array($selectedAssetIds)) {
            $selectedAssetIds = [];
        }
        $selectedAssetIds = array_map('intval', array_filter($selectedAssetIds));

        if ($borrowerName === '') {
            flash('Nama peminjam wajib diisi.', 'err');
            redirect_to('asset_loan_form');
        }

        if (empty($selectedAssetIds)) {
            flash('Pilih minimal 1 unit aset yang akan dipinjam.', 'err');
            redirect_to('asset_loan_form');
        }

        if ($loanDate === '') {
            $loanDate = date('Y-m-d H:i:s');
        } else {
            $loanDate = date('Y-m-d H:i:s', strtotime($loanDate));
        }

        $expectedReturn = !empty($expectedReturnDate) ? date('Y-m-d', strtotime($expectedReturnDate)) : null;

        // Ambil ID status BORROWED
        $borrowedStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'BORROWED' LIMIT 1")->fetchColumn();
        if ($borrowedStatusId <= 0) {
            $pdo->exec("INSERT IGNORE INTO asset_statuses (status_code, status_name, is_active) VALUES ('BORROWED', 'Dipinjam', 1)");
            $borrowedStatusId = (int)$pdo->lastInsertId();
        }

        try {
            $pdo->beginTransaction();

            // Generate nomor peminjaman: LN-YYYYMM-XXXX
            $prefix = 'LN-' . date('Ym') . '-';
            $stmtSeq = $pdo->prepare("SELECT COUNT(*) FROM asset_loans WHERE loan_code LIKE ?");
            $stmtSeq->execute([$prefix . '%']);
            $nextSeq = (int)$stmtSeq->fetchColumn() + 1;
            $loanCode = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);

            // Double check keunikan loan_code
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_loans WHERE loan_code = ?");
            $chkStmt->execute([$loanCode]);
            while ((int)$chkStmt->fetchColumn() > 0) {
                $nextSeq++;
                $loanCode = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);
                $chkStmt->execute([$loanCode]);
            }

            // Insert Header
            $insHeader = $pdo->prepare("INSERT INTO asset_loans 
                (loan_code, borrower_nik, borrower_name, borrower_department, borrower_phone, loan_date, expected_return_date, purpose, location_note, status, officer_user_id, officer_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)");
            $insHeader->execute([
                $loanCode,
                $borrowerNik ?: null,
                $borrowerName,
                $borrowerDept ?: null,
                $borrowerPhone ?: null,
                $loanDate,
                $expectedReturn,
                $purpose ?: null,
                $locationNote ?: null,
                $user['id'] ?? null,
                $officerNotes ?: null
            ]);
            $loanId = (int)$pdo->lastInsertId();

            // Insert Detail Items & Update Unit Aset
            $insItem = $pdo->prepare("INSERT INTO asset_loan_items (loan_id, asset_item_id, condition_out, notes_out, status) VALUES (?, ?, ?, ?, 'borrowed')");
            $updAsset = $pdo->prepare("UPDATE asset_items SET status = 'borrowed', asset_status_id = ?, custodian_name = ?, custodian_nik = ? WHERE id = ?");

            $conditionsOut = $_POST['condition_out'] ?? [];
            $itemNotesOut = $_POST['item_notes_out'] ?? [];

            foreach ($selectedAssetIds as $aid) {
                $cOut = trim((string)($conditionsOut[$aid] ?? 'Normal / Baik'));
                $nOut = trim((string)($itemNotesOut[$aid] ?? ''));

                $insItem->execute([$loanId, $aid, $cOut ?: 'Normal / Baik', $nOut ?: null]);
                $updAsset->execute([$borrowedStatusId, $borrowerName, $borrowerNik ?: null, $aid]);
            }

            $pdo->commit();
            flash("Peminjaman aset berhasil disimpan dengan nomor: <strong>{$loanCode}</strong>.", 'ok');
            redirect_to('asset_loans');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('Gagal menyimpan peminjaman aset: ' . $e->getMessage(), 'err');
        }
    }

    // Ambil daftar unit aset yang tersedia (Active & Not Borrowed)
    $availableAssets = [];
    try {
        $availStmt = $pdo->query("SELECT ai.id, ai.asset_code, ai.asset_name, ai.brand, ai.model, ai.serial_number,
            g.group_name, g.group_code, t.type_name, t.type_code, loc.location_name
            FROM asset_items ai
            LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
            LEFT JOIN asset_types t ON t.id = ai.asset_type_id
            LEFT JOIN asset_locations loc ON loc.id = ai.location_id
            LEFT JOIN asset_statuses st ON st.id = ai.asset_status_id
            WHERE (ai.status = 'active' OR (st.status_code = 'ACTIVE' OR st.status_code IS NULL))
              AND ai.status <> 'borrowed'
            ORDER BY g.group_name ASC, t.type_name ASC, ai.asset_name ASC, ai.asset_code ASC");
        $availableAssets = $availStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }

    render_header('Catat Peminjaman Aset Baru', $user);

    echo '<section class="panel">'
        . '<div class="split" style="align-items:center;margin-bottom:16px;">'
        . '  <div>'
        . '    <h1 style="margin:0;">📝 Catat Peminjaman Aset</h1>'
        . '    <p class="muted" style="margin:4px 0 0 0;">Lengkapi formulir serah-terima peminjaman aset fisik kepada karyawan / divisi.</p>'
        . '  </div>'
        . '  <div class="actions">'
        . '    <a class="btn" href="' . route_url('asset_loans') . '">⬅️ Kembali ke Daftar</a>'
        . '  </div>'
        . '</div>'

        . '<form method="post" id="loanForm" onsubmit="return validateLoanForm();">'
        . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'

        // Bagian 1: Identitas Peminjam
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">'
        . '  <h2 style="margin:0 0 12px 0;font-size:16px;color:#0f172a;display:flex;align-items:center;gap:6px;">👤 1. Identitas Peminjam (Karyawan)</h2>'
        . '  <div class="grid three">'
        . '    <label>NIK Peminjam'
        . '      <input id="borrowerNikInput" name="borrower_nik" placeholder="NIK Karyawan (opsional)">'
        . '    </label>'
        . '    <label>Nama Peminjam *'
        . '      <input id="borrowerNameInput" name="borrower_name" required placeholder="Nama Lengkap Karyawan">'
        . '    </label>'
        . '    <label>Departemen / Divisi'
        . '      <input id="borrowerDeptInput" name="borrower_department" placeholder="Contoh: IT, Produksi, GA, Logistik">'
        . '    </label>'
        . '  </div>'
        . '  <div class="grid two" style="margin-top:8px;">'
        . '    <label>No. HP / WhatsApp (Peminjam)'
        . '      <input name="borrower_phone" placeholder="Contoh: 08123456789">'
        . '    </label>'
        . '    <label>Lokasi / Ruang Penggunaan'
        . '      <input name="location_note" placeholder="Contoh: Area Pabrik Line 2, Gedung B Lantai 2, Ruang Rapat">'
        . '    </label>'
        . '  </div>'
        . (function_exists('employee_portal_name_picker_html') ? employee_portal_name_picker_html('borrowerPicker', 'borrowerNameInput', 'borrowerNikInput') : '')
        . '</div>'

        // Bagian 2: Waktu & Keperluan
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">'
        . '  <h2 style="margin:0 0 12px 0;font-size:16px;color:#0f172a;display:flex;align-items:center;gap:6px;">⏰ 2. Jadwal Peminjaman & Keperluan</h2>'
        . '  <div class="grid two">'
        . '    <label>Tanggal & Waktu Pinjam *'
        . '      <input type="datetime-local" name="loan_date" value="' . date('Y-m-d\TH:i') . '" required>'
        . '    </label>'
        . '    <label>Estimasi Tanggal Kembali *'
        . '      <input type="date" name="expected_return_date" value="' . date('Y-m-d', strtotime('+1 day')) . '" required>'
        . '      <small class="muted">Batas waktu pengembalian sebelum ditandai Overdue.</small>'
        . '    </label>'
        . '  </div>'
        . '  <label style="margin-top:8px;">Keperluan Peminjaman / Nama Proyek'
        . '    <textarea name="purpose" style="min-height:50px;" placeholder="Contoh: Penarikan kabel LAN ruang meeting, instalasi CCTV, perbaikan instalasi lampu plafon..."></textarea>'
        . '  </label>'
        . '  <label style="margin-top:8px;">Catatan Tambahan Petugas'
        . '    <input name="officer_notes" placeholder="Catatan internal serah terima...">'
        . '  </label>'
        . '</div>'

        // Bagian 3: Pilih Unit Aset
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">'
        . '  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">'
        . '    <div>'
        . '      <h2 style="margin:0;font-size:16px;color:#0f172a;display:flex;align-items:center;gap:6px;">🔧 3. Pilih Unit Aset yang Dipinjam <span style="color:#ef4444;">*</span></h2>'
        . '      <p class="muted" style="margin:2px 0 0 0;font-size:12px;">Pilih satu atau lebih perkakas/alat yang sedang tersedia (Active).</p>'
        . '    </div>'
        . '    <div style="display:flex;gap:6px;align-items:center;">'
        . '      <input type="text" id="assetSearchBox" oninput="filterAssetList()" placeholder="🔍 Cari kode, nama alat, model, kategori..." style="padding:6px 12px;font-size:13px;width:280px;background:#fff;">'
        . '      <span id="selectedCountBadge" style="background:#0284c7;color:#fff;font-size:12px;font-weight:bold;padding:6px 12px;border-radius:20px;">0 unit terpilih</span>'
        . '    </div>'
        . '  </div>';

    if (empty($availableAssets)) {
        echo '<div style="background:#fffbeb;border:1px solid #fde68a;padding:12px;border-radius:6px;color:#92400e;font-size:13px;">'
            . '⚠️ Saat ini belum ada Unit Aset yang berstatus aktif atau semua unit sedang dipinjam. Silakan periksa di menu <strong>Manajemen Aset ➡️ Unit Aset</strong>.'
            . '</div>';
    } else {
        echo '<div style="max-height:360px;overflow-y:auto;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">'
            . '<table id="assetTable" style="width:100%;border-collapse:collapse;font-size:13px;">'
            . '<thead><tr style="background:#f1f5f9;position:sticky;top:0;z-index:2;border-bottom:1px solid #cbd5e1;">'
            . '  <th style="padding:8px 12px;width:36px;text-align:center;">Pilih</th>'
            . '  <th style="padding:8px 12px;text-align:left;">Kode Aset</th>'
            . '  <th style="padding:8px 12px;text-align:left;">Komoditas / Kategori</th>'
            . '  <th style="padding:8px 12px;text-align:left;">Nama Unit & Model</th>'
            . '  <th style="padding:8px 12px;text-align:left;">Kondisi Awal</th>'
            . '  <th style="padding:8px 12px;text-align:left;">Catatan Kelengkapan</th>'
            . '</tr></thead>'
            . '<tbody>';

        foreach ($availableAssets as $ast) {
            $aid = (int)$ast['id'];
            $fullDesc = trim(($ast['brand'] ?? '') . ' ' . ($ast['model'] ?? ''));
            $searchText = strtolower($ast['asset_code'] . ' ' . $ast['asset_name'] . ' ' . $fullDesc . ' ' . ($ast['group_name'] ?? '') . ' ' . ($ast['type_name'] ?? ''));

            echo '<tr class="asset-row" data-search="' . e($searchText) . '" style="border-bottom:1px solid #f1f5f9;">'
                . '  <td style="padding:8px 12px;text-align:center;">'
                . '    <input type="checkbox" name="asset_item_ids[]" value="' . $aid . '" id="chk_' . $aid . '" onchange="updateSelectedCount()" style="width:18px;height:18px;cursor:pointer;">'
                . '  </td>'
                . '  <td style="padding:8px 12px;font-family:monospace;font-weight:700;color:#0284c7;">'
                . '    <label for="chk_' . $aid . '" style="cursor:pointer;margin:0;">' . e($ast['asset_code']) . '</label>'
                . '  </td>'
                . '  <td style="padding:8px 12px;color:#475569;">'
                . '    <div>' . e($ast['group_name'] ?: 'IT') . ' &bull; <strong>' . e($ast['type_name'] ?: 'General') . '</strong></div>'
                . '  </td>'
                . '  <td style="padding:8px 12px;">'
                . '    <div style="font-weight:600;color:#1e293b;">' . e($ast['asset_name']) . '</div>'
                . (!empty($fullDesc) ? '<div style="font-size:11px;color:#64748b;">' . e($fullDesc) . '</div>' : '')
                . '  </td>'
                . '  <td style="padding:8px 12px;min-width:130px;">'
                . '    <input name="condition_out[' . $aid . ']" value="Normal / Baik" placeholder="Kondisi awal" style="padding:4px 8px;font-size:12px;width:100%;">'
                . '  </td>'
                . '  <td style="padding:8px 12px;min-width:150px;">'
                . '    <input name="item_notes_out[' . $aid . ']" placeholder="Contoh: Unit lengkap kabel" style="padding:4px 8px;font-size:12px;width:100%;">'
                . '  </td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</div>'

        // Tombol Aksi
        . '<div class="actions" style="margin-top:20px;">'
        . '  <button type="submit" class="btn primary" style="padding:10px 24px;font-size:14px;font-weight:bold;">💾 Simpan Peminjaman Aset</button>'
        . '  <a class="btn" href="' . route_url('asset_loans') . '" style="padding:10px 18px;">Batal</a>'
        . '</div>'
        . '</form>'

        // JavaScript Filter & Validasi
        . '<script>
        function filterAssetList() {
            var q = (document.getElementById("assetSearchBox").value || "").toLowerCase().trim();
            var rows = document.querySelectorAll("#assetTable tbody tr.asset-row");
            rows.forEach(function(r) {
                var txt = r.getAttribute("data-search") || "";
                if (!q || txt.indexOf(q) !== -1) {
                    r.style.display = "";
                } else {
                    r.style.display = "none";
                }
            });
        }

        function updateSelectedCount() {
            var chks = document.querySelectorAll("#assetTable input[name=\'asset_item_ids[]\']:checked");
            var badge = document.getElementById("selectedCountBadge");
            if (badge) {
                badge.textContent = chks.length + " unit terpilih";
                badge.style.background = chks.length > 0 ? "#16a34a" : "#0284c7";
            }
        }

        function validateLoanForm() {
            var name = document.getElementById("borrowerNameInput").value.trim();
            if (!name) {
                alert("Nama peminjam wajib diisi.");
                return false;
            }
            var chks = document.querySelectorAll("#assetTable input[name=\'asset_item_ids[]\']:checked");
            if (chks.length === 0) {
                alert("Silakan pilih minimal 1 unit aset yang akan dipinjam dengan mencentang checkbox-nya.");
                return false;
            }
            return true;
        }
        </script>'
        . '</section>';

    render_footer();
}

/**
 * Detail Peminjaman & Tanda Terima Siap Cetak
 */
function handle_route_asset_loan_detail(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician', 'corrective_maintenance']);
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
        redirect_to('asset_loans');
    }

    $itemStmt = $pdo->prepare("SELECT ali.*, ai.asset_code, ai.asset_name, ai.brand, ai.model, ai.serial_number,
        g.group_name, t.type_name, loc.location_name
        FROM asset_loan_items ali
        JOIN asset_items ai ON ai.id = ali.asset_item_id
        LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
        LEFT JOIN asset_types t ON t.id = ai.asset_type_id
        LEFT JOIN asset_locations loc ON loc.id = ai.location_id
        WHERE ali.loan_id = ?
        ORDER BY ali.id ASC");
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    render_header('Detail Peminjaman ' . $loan['loan_code'], $user);

    $companyName = config_value('company_name') ?: 'PT. SOA Group';
    $isOverdue = ($loan['status'] === 'active' && !empty($loan['expected_return_date']) && $loan['expected_return_date'] < date('Y-m-d'));

    echo '<style>
    @media print {
        body { background:#fff !important; color:#000 !important; }
        .nav, .actions, .noprint, .btn { display:none !important; }
        .panel { border:none !important; box-shadow:none !important; padding:0 !important; }
        .print-receipt { border:1px solid #333 !important; padding:20px !important; }
    }
    </style>';

    echo '<section class="panel">'
        . '<div class="split actions noprint" style="align-items:center;margin-bottom:16px;">'
        . '  <div>'
        . '    <a class="btn" href="' . route_url('asset_loans') . '">⬅️ Kembali ke Daftar</a>'
        . '  </div>'
        . '  <div style="display:flex;gap:8px;">'
        . '    <button type="button" class="btn" onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;">🖨️ Cetak Bukti Pinjam</button>'
        . ($loan['status'] === 'active' ? '    <a class="btn primary" href="' . route_url('asset_loan_return', ['id' => $loan['id']]) . '" style="background:#16a34a;border-color:#16a34a;font-weight:bold;">📥 Proses Pengembalian</a>' : '')
        . '  </div>'
        . '</div>'

        // Lembar Tanda Terima
        . '<div class="print-receipt" style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:24px;max-width:850px;margin:0 auto;box-shadow:0 4px 12px rgba(0,0,0,0.05);">'
        
        // Header Surat
        . '  <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #0f172a;padding-bottom:12px;margin-bottom:16px;">'
        . '    <div>'
        . '      <div style="font-size:18px;font-weight:800;color:#0f172a;text-transform:uppercase;">' . e($companyName) . '</div>'
        . '      <div style="font-size:13px;color:#475569;">Sistem Manajemen Aset & Operasional PcConnect</div>'
        . '    </div>'
        . '    <div style="text-align:right;">'
        . '      <div style="font-size:16px;font-weight:700;color:#0284c7;font-family:monospace;">' . e($loan['loan_code']) . '</div>'
        . '      <div style="font-size:12px;color:#64748b;margin-top:2px;">Tgl Pinjam: ' . date('d F Y H:i', strtotime($loan['loan_date'])) . '</div>'
        . '    </div>'
        . '  </div>'

        . '  <h2 style="text-align:center;margin:0 0 16px 0;font-size:17px;letter-spacing:1px;text-transform:uppercase;color:#1e293b;">SURAT BUKTI SERAH-TERIMA PEMINJAMAN ASET</h2>'

        // Status Banner
        . '  <div style="margin-bottom:16px;padding:10px 14px;border-radius:6px;background:' . ($loan['status'] === 'returned' ? '#f0fdf4;border:1px solid #bbf7d0;color:#166534' : ($isOverdue ? '#fef2f2;border:1px solid #fecaca;color:#991b1b' : '#f0f9ff;border:1px solid #bae6fd;color:#0369a1')) . ';font-size:13px;display:flex;justify-content:space-between;align-items:center;">'
        . '    <div><strong>Status:</strong> ' . ($loan['status'] === 'returned' ? 'SUDAH DIKEMBALIKAN' : ($isOverdue ? 'JATUH TEMPO / OVERDUE' : 'AKTIF SEDANG DIPINJAM')) . '</div>'
        . '    <div><strong>Target Kembali:</strong> ' . (!empty($loan['expected_return_date']) ? date('d/m/Y', strtotime($loan['expected_return_date'])) : '-') . '</div>'
        . '  </div>'

        // Detail Peminjam & Keperluan
        . '  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;font-size:13px;">'
        . '    <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #e2e8f0;">'
        . '      <div style="font-weight:700;color:#334155;margin-bottom:6px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;">IDENTITAS PEMINJAM:</div>'
        . '      <div><strong>Nama:</strong> ' . e($loan['borrower_name']) . '</div>'
        . '      <div><strong>NIK:</strong> ' . e($loan['borrower_nik'] ?: '-') . '</div>'
        . '      <div><strong>Departemen:</strong> ' . e($loan['borrower_department'] ?: '-') . '</div>'
        . '      <div><strong>Kontak:</strong> ' . e($loan['borrower_phone'] ?: '-') . '</div>'
        . '    </div>'
        . '    <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #e2e8f0;">'
        . '      <div style="font-weight:700;color:#334155;margin-bottom:6px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;">KEPERLUAN & LOKASI:</div>'
        . '      <div><strong>Keperluan:</strong> ' . e($loan['purpose'] ?: '-') . '</div>'
        . '      <div><strong>Lokasi Pakai:</strong> ' . e($loan['location_note'] ?: '-') . '</div>'
        . '      <div><strong>Petugas Penyerah:</strong> ' . e($loan['officer_name'] ?: 'Admin') . '</div>'
        . ($loan['status'] === 'returned' ? '      <div><strong>Petugas Penerima:</strong> ' . e($loan['return_officer_name'] ?: 'Admin') . ' (' . date('d/m/Y H:i', strtotime($loan['actual_return_date'])) . ')</div>' : '')
        . '    </div>'
        . '  </div>'

        // Tabel Rincian Unit Aset
        . '  <div style="margin-bottom:24px;">'
        . '    <div style="font-weight:700;color:#0f172a;margin-bottom:8px;font-size:14px;">DAFTAR UNIT ASET FISIK:</div>'
        . '    <table style="width:100%;border-collapse:collapse;font-size:12px;border:1px solid #cbd5e1;">'
        . '      <thead><tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1;text-align:left;">'
        . '        <th style="padding:8px 10px;border-right:1px solid #cbd5e1;width:30px;text-align:center;">No</th>'
        . '        <th style="padding:8px 10px;border-right:1px solid #cbd5e1;">Kode Aset</th>'
        . '        <th style="padding:8px 10px;border-right:1px solid #cbd5e1;">Kategori / Nama Alat</th>'
        . '        <th style="padding:8px 10px;border-right:1px solid #cbd5e1;">Kondisi Saat Keluar</th>'
        . '        <th style="padding:8px 10px;">Status / Kondisi Kembali</th>'
        . '      </tr></thead>'
        . '      <tbody>';

    $no = 1;
    foreach ($items as $it) {
        $nameInfo = e($it['asset_name'] ?: ($it['brand'] . ' ' . $it['model']));
        $catInfo = e(($it['group_name'] ?? '') . ' - ' . ($it['type_name'] ?? ''));
        echo '<tr style="border-bottom:1px solid #e2e8f0;">'
            . '  <td style="padding:8px 10px;text-align:center;border-right:1px solid #cbd5e1;">' . $no++ . '</td>'
            . '  <td style="padding:8px 10px;font-family:monospace;font-weight:bold;color:#0369a1;border-right:1px solid #cbd5e1;">' . e($it['asset_code']) . '</td>'
            . '  <td style="padding:8px 10px;border-right:1px solid #cbd5e1;">'
            . '    <div style="font-weight:600;">' . $nameInfo . '</div>'
            . '    <div style="font-size:11px;color:#64748b;">' . $catInfo . (!empty($it['serial_number']) ? ' &bull; SN: ' . e($it['serial_number']) : '') . '</div>'
            . '  </td>'
            . '  <td style="padding:8px 10px;border-right:1px solid #cbd5e1;">'
            . '    <div>' . e($it['condition_out']) . '</div>'
            . (!empty($it['notes_out']) ? '<div style="font-size:11px;color:#64748b;">' . e($it['notes_out']) . '</div>' : '')
            . '  </td>'
            . '  <td style="padding:8px 10px;">'
            . ($it['status'] === 'returned'
                ? '<div style="color:#166534;font-weight:600;">✓ Kembali: ' . e($it['condition_in'] ?: 'Normal') . '</div>' . (!empty($it['notes_in']) ? '<div style="font-size:11px;color:#64748b;">' . e($it['notes_in']) . '</div>' : '')
                : ($it['status'] === 'damaged'
                    ? '<div style="color:#991b1b;font-weight:600;">⚠️ Rusak: ' . e($it['condition_in']) . '</div>'
                    : '<span style="color:#b45309;">Sedang Dipinjam</span>'))
            . '  </td>'
            . '</tr>';
    }

    echo '      </tbody>'
        . '    </table>'
        . '  </div>'

        // Kolom Tanda Tangan
        . '  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;text-align:center;font-size:12px;margin-top:30px;padding-top:10px;">'
        . '    <div>'
        . '      <div style="margin-bottom:60px;">Peminjam / Pemakai,</div>'
        . '      <div style="font-weight:700;text-decoration:underline;">' . e($loan['borrower_name']) . '</div>'
        . '      <div style="color:#64748b;">NIK: ' . e($loan['borrower_nik'] ?: '.....................') . '</div>'
        . '    </div>'
        . '    <div>'
        . '      <div style="margin-bottom:60px;">Petugas Penyerah,</div>'
        . '      <div style="font-weight:700;text-decoration:underline;">' . e($loan['officer_name'] ?: 'Petugas IT / GA') . '</div>'
        . '      <div style="color:#64748b;">Tgl: ' . date('d/m/Y', strtotime($loan['loan_date'])) . '</div>'
        . '    </div>'
        . '    <div>'
        . '      <div style="margin-bottom:60px;">Petugas Penerima Kembali,</div>'
        . '      <div style="font-weight:700;text-decoration:underline;">' . e($loan['return_officer_name'] ?: '....................................') . '</div>'
        . '      <div style="color:#64748b;">Tgl: ' . (!empty($loan['actual_return_date']) ? date('d/m/Y', strtotime($loan['actual_return_date'])) : '..... / ..... / 20.....') . '</div>'
        . '    </div>'
        . '  </div>'

        . '</div>'
        . '</section>';

    render_footer();
}

/**
 * Form / Aksi Pengembalian Aset (Check-In)
 */
function handle_route_asset_loan_return(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician']);
    $id = (int)($_GET['id'] ?? $_POST['loan_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM asset_loans WHERE id = ?");
    $stmt->execute([$id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        flash('Data peminjaman tidak ditemukan.', 'err');
        redirect_to('asset_loans');
    }

    if ($loan['status'] === 'returned') {
        flash('Peminjaman nomor ' . $loan['loan_code'] . ' sudah berstatus dikembalikan sebelumnya.', 'info');
        redirect_to('asset_loan_detail', ['id' => $id]);
    }

    // Eksekusi POST Pengembalian
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

        // Ambil ID status ACTIVE dan REPAIR
        $activeStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'ACTIVE' LIMIT 1")->fetchColumn();
        $repairStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'REPAIR' LIMIT 1")->fetchColumn();
        $lostStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'LOST' LIMIT 1")->fetchColumn();

        try {
            $pdo->beginTransaction();

            // Update item-item peminjaman
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

                // Update status di asset_items
                if ($st === 'damaged') {
                    $updAsset->execute(['repair', $repairStatusId ?: null, $assetId]);
                } elseif ($st === 'lost') {
                    $updAsset->execute(['lost', $lostStatusId ?: null, $assetId]);
                } else {
                    $updAsset->execute(['active', $activeStatusId ?: null, $assetId]);
                }
            }

            // Update header peminjaman
            $updHeader = $pdo->prepare("UPDATE asset_loans 
                SET status = 'returned', actual_return_date = ?, return_officer_user_id = ?, officer_notes = CONCAT(COALESCE(officer_notes,''), '\n[Pengembalian]: ', ?) 
                WHERE id = ?");
            $updHeader->execute([$actualReturnDate, $user['id'] ?? null, $officerNotes ?: 'Semua item dikembalikan', $id]);

            $pdo->commit();
            flash("Pengembalian aset untuk nomor <strong>{$loan['loan_code']}</strong> berhasil dicatat. Status unit aset telah diperbarui.", 'ok');
            redirect_to('asset_loans');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('Gagal memproses pengembalian: ' . $e->getMessage(), 'err');
        }
    }

    // Ambil item yang dipinjam
    $itemStmt = $pdo->prepare("SELECT ali.*, ai.asset_code, ai.asset_name, ai.brand, ai.model, g.group_name, t.type_name
        FROM asset_loan_items ali
        JOIN asset_items ai ON ai.id = ali.asset_item_id
        LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
        LEFT JOIN asset_types t ON t.id = ai.asset_type_id
        WHERE ali.loan_id = ?
        ORDER BY ali.id ASC");
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    render_header('Proses Pengembalian Aset ' . $loan['loan_code'], $user);

    echo '<section class="panel">'
        . '<div class="split" style="align-items:center;margin-bottom:16px;">'
        . '  <div>'
        . '    <h1 style="margin:0;">📥 Konfirmasi Pengembalian Aset</h1>'
        . '    <p class="muted" style="margin:4px 0 0 0;">Periksa kondisi fisik unit aset yang dikembalikan dan selesaikan transaksi peminjaman.</p>'
        . '  </div>'
        . '  <div class="actions">'
        . '    <a class="btn" href="' . route_url('asset_loans') . '">⬅️ Batal</a>'
        . '  </div>'
        . '</div>'

        . '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
        . '<input type="hidden" name="loan_id" value="' . $loan['id'] . '">'

        // Info Peminjam
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;font-size:13px;">'
        . '  <div><strong>Nomor Pinjam:</strong> <span style="font-family:monospace;color:#0284c7;font-weight:bold;">' . e($loan['loan_code']) . '</span></div>'
        . '  <div><strong>Peminjam:</strong> ' . e($loan['borrower_name']) . (!empty($loan['borrower_department']) ? ' (' . e($loan['borrower_department']) . ')' : '') . '</div>'
        . '  <div><strong>Tanggal Pinjam:</strong> ' . date('d/m/Y H:i', strtotime($loan['loan_date'])) . '</div>'
        . '  <div><strong>Estimasi Kembali:</strong> ' . (!empty($loan['expected_return_date']) ? date('d/m/Y', strtotime($loan['expected_return_date'])) : '-') . '</div>'
        . '</div>'

        // Tabel Checklist Kondisi Kembali
        . '<div style="margin-bottom:20px;">'
        . '  <h2 style="font-size:15px;margin:0 0 10px 0;color:#0f172a;">Inspeksi Kondisi Fisik Unit Aset</h2>'
        . '  <table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #cbd5e1;">'
        . '    <thead><tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1;text-align:left;">'
        . '      <th style="padding:10px 12px;">Unit Aset</th>'
        . '      <th style="padding:10px 12px;">Kondisi Saat Keluar</th>'
        . '      <th style="padding:10px 12px;width:170px;">Status Pengembalian</th>'
        . '      <th style="padding:10px 12px;">Kondisi Fisik Saat Masuk</th>'
        . '      <th style="padding:10px 12px;">Catatan Tambahan</th>'
        . '    </tr></thead>'
        . '    <tbody>';

    foreach ($items as $it) {
        $iid = (int)$it['id'];
        $desc = e($it['asset_name'] ?: ($it['brand'] . ' ' . $it['model']));
        echo '<tr style="border-bottom:1px solid #e2e8f0;background:#fff;">'
            . '  <td style="padding:10px 12px;">'
            . '    <strong style="color:#0369a1;font-family:monospace;">' . e($it['asset_code']) . '</strong>'
            . '    <div style="font-weight:600;color:#1e293b;">' . $desc . '</div>'
            . '    <div style="font-size:11px;color:#64748b;">' . e(($it['group_name'] ?? '') . ' - ' . ($it['type_name'] ?? '')) . '</div>'
            . '  </td>'
            . '  <td style="padding:10px 12px;color:#475569;">'
            . '    <div>' . e($it['condition_out']) . '</div>'
            . (!empty($it['notes_out']) ? '<div style="font-size:11px;color:#64748b;">' . e($it['notes_out']) . '</div>' : '')
            . '  </td>'
            . '  <td style="padding:10px 12px;">'
            . '    <select name="item_status[' . $iid . ']" style="width:100%;padding:6px;font-size:13px;border-radius:4px;border:1px solid #cbd5e1;">'
            . '      <option value="returned" selected>✓ Normal / Baik</option>'
            . '      <option value="damaged">⚠️ Rusak (Perlu Perbaikan)</option>'
            . '      <option value="lost">❌ Hilang / Tidak Kembali</option>'
            . '    </select>'
            . '  </td>'
            . '  <td style="padding:10px 12px;">'
            . '    <input name="condition_in[' . $iid . ']" value="Normal / Baik" placeholder="Kondisi fisik..." style="width:100%;padding:6px;font-size:13px;">'
            . '  </td>'
            . '  <td style="padding:10px 12px;">'
            . '    <input name="notes_in[' . $iid . ']" placeholder="Keterangan kelengkapan..." style="width:100%;padding:6px;font-size:13px;">'
            . '  </td>'
            . '</tr>';
    }

    echo '    </tbody>'
        . '  </table>'
        . '</div>'

        // Detail Waktu Kembali
        . '<div class="grid two" style="margin-bottom:16px;">'
        . '  <label>Tanggal & Waktu Realisasi Kembali *'
        . '    <input type="datetime-local" name="actual_return_date" value="' . date('Y-m-d\TH:i') . '" required>'
        . '  </label>'
        . '  <label>Catatan Pengembalian Petugas'
        . '    <input name="officer_notes" placeholder="Contoh: Tangga kembali bersih, kunci berfungsi baik...">'
        . '  </label>'
        . '</div>'

        . '<div class="actions">'
        . '  <button type="submit" class="btn primary" style="background:#16a34a;border-color:#16a34a;padding:10px 24px;font-size:14px;font-weight:bold;">✅ Konfirmasi Pengembalian Selesai</button>'
        . '  <a class="btn" href="' . route_url('asset_loans') . '" style="padding:10px 18px;">Batal</a>'
        . '</div>'
        . '</form>'
        . '</section>';

    render_footer();
}

/**
 * Report Peminjaman Aset (di menu Reports)
 */
function handle_route_report_asset_loans(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician']);
    $startDate = trim((string)($_GET['start_date'] ?? ''));
    $endDate = trim((string)($_GET['end_date'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

    $where = ["1=1"];
    $params = [];

    if ($startDate !== '') {
        $where[] = "DATE(al.loan_date) >= ?";
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $where[] = "DATE(al.loan_date) <= ?";
        $params[] = $endDate;
    }
    if ($statusFilter !== '') {
        $where[] = "al.status = ?";
        $params[] = $statusFilter;
    }
    if ($q !== '') {
        $where[] = "(al.loan_code LIKE ? OR al.borrower_name LIKE ? OR al.borrower_nik LIKE ? OR al.borrower_department LIKE ?)";
        $w = '%' . $q . '%';
        $params = array_merge($params, [$w, $w, $w, $w]);
    }

    $sql = "SELECT al.*, u1.name AS officer_name, u2.name AS return_officer_name,
            (SELECT COUNT(*) FROM asset_loan_items WHERE loan_id = al.id) AS total_items,
            (SELECT GROUP_CONCAT(CONCAT(ai.asset_code, ' (', COALESCE(NULLIF(ai.asset_name,''), ai.model), ')') SEPARATOR ', ') 
             FROM asset_loan_items ali2 
             JOIN asset_items ai ON ai.id = ali2.asset_item_id 
             WHERE ali2.loan_id = al.id) AS asset_items_summary
            FROM asset_loans al
            LEFT JOIN users u1 ON u1.id = al.officer_user_id
            LEFT JOIN users u2 ON u2.id = al.return_officer_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY al.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    render_header('Report Peminjaman Aset', $user);

    $exportParams = ['type' => 'asset_loans'];
    if ($startDate !== '') $exportParams['start_date'] = $startDate;
    if ($endDate !== '') $exportParams['end_date'] = $endDate;
    if ($statusFilter !== '') $exportParams['status'] = $statusFilter;
    if ($q !== '') $exportParams['q'] = $q;

    echo '<section class="panel">'
        . '<div class="split" style="align-items:center;">'
        . '  <div>'
        . '    <h1 style="margin:0;">📊 Report Peminjaman Aset</h1>'
        . '    <p class="muted" style="margin:4px 0 0 0;">Laporan riwayat peminjaman dan pengembalian unit aset, perkakas, dan alat kerja.</p>'
        . '  </div>'
        . '  <div class="actions">'
        . '    <a class="btn" href="' . route_url('export_excel', $exportParams) . '">📥 Export Excel</a>'
        . '  </div>'
        . '</div>'

        // Form Filter
        . '<form method="get" class="actions" style="margin:16px 0 20px 0;align-items:flex-end;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">'
        . '  <input type="hidden" name="route" value="report_asset_loans">'
        . '  <label style="margin:0;">Tgl Pinjam Dari<input type="date" name="start_date" value="' . e($startDate) . '"></label>'
        . '  <label style="margin:0;">Tgl Pinjam Sampai<input type="date" name="end_date" value="' . e($endDate) . '"></label>'
        . '  <label style="margin:0;">Status'
        . '    <select name="status">'
        . '      <option value="">-- Semua Status --</option>'
        . '      <option value="active"' . ($statusFilter === 'active' ? ' selected' : '') . '>Sedang Dipinjam</option>'
        . '      <option value="returned"' . ($statusFilter === 'returned' ? ' selected' : '') . '>Sudah Dikembalikan</option>'
        . '    </select>'
        . '  </label>'
        . '  <label style="margin:0;flex:1;min-width:180px;">Cari (No. Pinjam / Peminjam)<input name="q" value="' . e($q) . '" placeholder="Ketik kata kunci..."></label>'
        . '  <button class="btn primary" style="height:38px;align-self:flex-end;">Filter</button>'
        . ($startDate !== '' || $endDate !== '' || $statusFilter !== '' || $q !== '' ? '  <a class="btn" href="' . route_url('report_asset_loans') . '" style="height:38px;align-self:flex-end;">Reset</a>' : '')
        . '</form>';

    if (empty($rows)) {
        echo '<div style="text-align:center;padding:36px;color:#64748b;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1;">Tidak ada data peminjaman yang cocok dengan filter.</div>';
    } else {
        echo '<div style="overflow-x:auto;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
            . '<thead><tr style="background:#f1f5f9;border-bottom:2px solid #cbd5e1;text-align:left;">'
            . '  <th style="padding:8px 10px;">No</th>'
            . '  <th style="padding:8px 10px;">No. Pinjam</th>'
            . '  <th style="padding:8px 10px;">Peminjam</th>'
            . '  <th style="padding:8px 10px;">Departemen</th>'
            . '  <th style="padding:8px 10px;">Aset Dipinjam</th>'
            . '  <th style="padding:8px 10px;">Tgl Pinjam</th>'
            . '  <th style="padding:8px 10px;">Target Kembali</th>'
            . '  <th style="padding:8px 10px;">Realisasi Kembali</th>'
            . '  <th style="padding:8px 10px;">Keperluan</th>'
            . '  <th style="padding:8px 10px;text-align:center;">Status</th>'
            . '</tr></thead>'
            . '<tbody>';

        $num = 1;
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $isOver = ($r['status'] === 'active' && !empty($r['expected_return_date']) && $r['expected_return_date'] < $today);
            echo '<tr style="border-bottom:1px solid #e2e8f0;">'
                . '  <td style="padding:8px 10px;text-align:center;">' . $num++ . '</td>'
                . '  <td style="padding:8px 10px;"><a href="' . route_url('asset_loan_detail', ['id' => $r['id']]) . '" style="font-weight:700;color:#0284c7;font-family:monospace;">' . e($r['loan_code']) . '</a></td>'
                . '  <td style="padding:8px 10px;font-weight:600;">' . e($r['borrower_name']) . (!empty($r['borrower_nik']) ? ' <span style="color:#64748b;font-weight:normal;">(' . e($r['borrower_nik']) . ')</span>' : '') . '</td>'
                . '  <td style="padding:8px 10px;">' . e($r['borrower_department'] ?: '-') . '</td>'
                . '  <td style="padding:8px 10px;max-width:220px;word-break:break-word;">' . e($r['asset_items_summary'] ?: '-') . '</td>'
                . '  <td style="padding:8px 10px;">' . date('d/m/Y H:i', strtotime($r['loan_date'])) . '</td>'
                . '  <td style="padding:8px 10px;' . ($isOver ? 'color:#dc2626;font-weight:bold;' : '') . '">' . (!empty($r['expected_return_date']) ? date('d/m/Y', strtotime($r['expected_return_date'])) : '-') . '</td>'
                . '  <td style="padding:8px 10px;">' . (!empty($r['actual_return_date']) ? date('d/m/Y H:i', strtotime($r['actual_return_date'])) : '-') . '</td>'
                . '  <td style="padding:8px 10px;max-width:180px;">' . e($r['purpose'] ?: '-') . '</td>'
                . '  <td style="padding:8px 10px;text-align:center;">'
                . ($r['status'] === 'returned'
                    ? '<span class="badge ok">Dikembalikan</span>'
                    : ($isOver
                        ? '<span class="badge danger">Terlambat</span>'
                        : '<span class="badge warning">Dipinjam</span>'))
                . '  </td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</section>';
    render_footer();
}

