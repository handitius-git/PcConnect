<?php

declare(strict_types=1);

/**
 * Modul Peminjaman Aset (Asset Loan Management)
 * Terintegrasi dengan Unit Aset (asset_items), Master Karyawan (employee_directory), dan Status Aset.
/**
 * Temukan unit aset berdasarkan kode aset atau hasil scan QR (plain / URL / terenkripsi)
 */
function find_asset_item_for_loan(PDO $pdo, string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $code = $raw;
    // Cek jika raw berupa URL yang mengandung query parameter eqr atau code
    if (str_contains($raw, 'eqr=')) {
        $parts = parse_url($raw);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['eqr']) && function_exists('qr_decrypt_payload')) {
                $decrypted = qr_decrypt_payload((string)$q['eqr']);
                if ($decrypted) {
                    $code = $decrypted;
                }
            }
        } elseif (function_exists('qr_decrypt_payload')) {
            $eqrVal = substr($raw, strpos($raw, 'eqr=') + 4);
            $eqrVal = explode('&', $eqrVal)[0];
            $decrypted = qr_decrypt_payload(urldecode($eqrVal));
            if ($decrypted) {
                $code = $decrypted;
            }
        }
    } elseif (str_contains($raw, 'code=')) {
        $parts = parse_url($raw);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['code'])) {
                $code = trim((string)$q['code']);
            }
        } else {
            $cVal = substr($raw, strpos($raw, 'code=') + 5);
            $code = trim(urldecode(explode('&', $cVal)[0]));
        }
    } else {
        // Cek apakah raw adalah payload terenkripsi
        if (function_exists('qr_decrypt_payload')) {
            $decrypted = qr_decrypt_payload($raw);
            if ($decrypted) {
                $code = $decrypted;
            }
        }
    }

    $code = trim($code);
    if ($code === '') {
        return null;
    }

    // 1. Cari exact match asset_code
    $stmt = $pdo->prepare("SELECT ai.*, g.group_name, t.type_name, loc.location_name
                           FROM asset_items ai
                           LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
                           LEFT JOIN asset_types t ON t.id = ai.asset_type_id
                           LEFT JOIN asset_locations loc ON loc.id = ai.location_id
                           WHERE UPPER(TRIM(ai.asset_code)) = UPPER(?)
                           LIMIT 1");
    $stmt->execute([$code]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Cari serial_number
    if (!$item) {
        $stmt2 = $pdo->prepare("SELECT ai.*, g.group_name, t.type_name, loc.location_name
                                FROM asset_items ai
                                LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
                                LEFT JOIN asset_types t ON t.id = ai.asset_type_id
                                LEFT JOIN asset_locations loc ON loc.id = ai.location_id
                                WHERE UPPER(TRIM(ai.serial_number)) = UPPER(?)
                                LIMIT 1");
        $stmt2->execute([$code]);
        $item = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    // 3. Cari id jika format #123 atau angka murni
    if (!$item && preg_match('/^#?(\d+)$/', $code, $m)) {
        $stmt3 = $pdo->prepare("SELECT ai.*, g.group_name, t.type_name, loc.location_name
                                FROM asset_items ai
                                LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
                                LEFT JOIN asset_types t ON t.id = ai.asset_type_id
                                LEFT JOIN asset_locations loc ON loc.id = ai.location_id
                                WHERE ai.id = ?
                                LIMIT 1");
        $stmt3->execute([(int)$m[1]]);
        $item = $stmt3->fetch(PDO::FETCH_ASSOC);
    }

    return $item ?: null;
}

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
        . '  <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;">'
        . '    <a class="btn" href="' . route_url('mobile_asset_loans') . '" target="_blank" style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border-color:#86efac;color:#166534;font-weight:600;">'
        . '      <span>📱</span> Versi Mobile'
        . '    </a>'
        . '    <a class="btn primary" href="' . route_url('asset_loan_form') . '" style="display:inline-flex;align-items:center;gap:6px;font-weight:bold;">'
        . '      <span>➕</span> Catat Peminjaman Aset'
        . '    </a>'
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
                . ($l['status'] === 'active' ? '      <a class="btn" href="' . route_url('asset_loan_form', ['id' => $l['id']]) . '" style="padding:4px 8px;font-size:12px;background:#f59e0b;border-color:#f59e0b;color:#fff;" title="Edit Data Peminjaman">✏️ Edit</a>' : '')
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
/**
 * Form Peminjaman Aset (Tambah Baru & Edit)
 */
function handle_route_asset_loan_form(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician']);
    $id = (int)($_GET['id'] ?? $_POST['loan_id'] ?? 0);
    $loan = null;
    $existingItems = [];

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM asset_loans WHERE id = ?");
        $stmt->execute([$id]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$loan) {
            flash('Data peminjaman tidak ditemukan.', 'err');
            redirect_to('asset_loans');
        }
        $itemStmt = $pdo->prepare("SELECT ali.*, ai.asset_code, ai.asset_name, ai.brand, ai.model, g.group_name, t.type_name, loc.location_name
                                   FROM asset_loan_items ali
                                   JOIN asset_items ai ON ai.id = ali.asset_item_id
                                   LEFT JOIN asset_groups g ON g.id = ai.asset_group_id
                                   LEFT JOIN asset_types t ON t.id = ai.asset_type_id
                                   LEFT JOIN asset_locations loc ON loc.id = ai.location_id
                                   WHERE ali.loan_id = ?
                                   ORDER BY ali.id ASC");
        $itemStmt->execute([$id]);
        $existingItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Proses Simpan Transaksi Peminjaman (Tambah Baru / Edit)
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
        $selectedAssetIds = array_values(array_unique(array_map('intval', array_filter($selectedAssetIds))));

        if ($borrowerName === '') {
            flash('Nama peminjam wajib diisi (pilih dari database atau ketik nama).', 'err');
            redirect_to('asset_loan_form', $id > 0 ? ['id' => $id] : []);
        }

        if (empty($selectedAssetIds)) {
            flash('Pilih/scan minimal 1 unit aset yang akan dipinjam.', 'err');
            redirect_to('asset_loan_form', $id > 0 ? ['id' => $id] : []);
        }

        if ($loanDate === '') {
            $loanDate = date('Y-m-d H:i:s');
        } else {
            $loanDate = date('Y-m-d H:i:s', strtotime($loanDate));
        }

        $expectedReturn = !empty($expectedReturnDate) ? date('Y-m-d', strtotime($expectedReturnDate)) : null;

        // Ambil ID status BORROWED & ACTIVE
        $borrowedStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'BORROWED' LIMIT 1")->fetchColumn();
        if ($borrowedStatusId <= 0) {
            $pdo->exec("INSERT IGNORE INTO asset_statuses (status_code, status_name, is_active) VALUES ('BORROWED', 'Dipinjam', 1)");
            $borrowedStatusId = (int)$pdo->lastInsertId();
        }
        $activeStatusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'ACTIVE' LIMIT 1")->fetchColumn();

        try {
            $pdo->beginTransaction();

            $conditionsOut = $_POST['condition_out'] ?? [];
            $itemNotesOut = $_POST['item_notes_out'] ?? [];

            if ($id > 0) {
                // UPDATE Transaksi Peminjaman (Edit Mode)
                $updHeader = $pdo->prepare("UPDATE asset_loans 
                    SET borrower_nik = ?, borrower_name = ?, borrower_department = ?, borrower_phone = ?,
                        loan_date = ?, expected_return_date = ?, purpose = ?, location_note = ?, officer_notes = ?, updated_at = NOW()
                    WHERE id = ?");
                $updHeader->execute([
                    $borrowerNik ?: null,
                    $borrowerName,
                    $borrowerDept ?: null,
                    $borrowerPhone ?: null,
                    $loanDate,
                    $expectedReturn,
                    $purpose ?: null,
                    $locationNote ?: null,
                    $officerNotes ?: null,
                    $id
                ]);

                // Ambil ID aset yang lama di transaksi ini
                $oldItemStmt = $pdo->prepare("SELECT asset_item_id FROM asset_loan_items WHERE loan_id = ?");
                $oldItemStmt->execute([$id]);
                $oldAssetIds = array_map('intval', $oldItemStmt->fetchAll(PDO::FETCH_COLUMN));

                // 1. Item yang dihapus dari transaksi (kembalikan ke active)
                $deletedAssetIds = array_diff($oldAssetIds, $selectedAssetIds);
                if (!empty($deletedAssetIds)) {
                    $delStmt = $pdo->prepare("DELETE FROM asset_loan_items WHERE loan_id = ? AND asset_item_id = ?");
                    $relAsset = $pdo->prepare("UPDATE asset_items SET status = 'active', asset_status_id = ?, custodian_name = NULL, custodian_nik = NULL WHERE id = ?");
                    foreach ($deletedAssetIds as $delAid) {
                        $delStmt->execute([$id, $delAid]);
                        $relAsset->execute([$activeStatusId ?: null, $delAid]);
                    }
                }

                // 2. Item yang baru ditambahkan
                $newAssetIds = array_diff($selectedAssetIds, $oldAssetIds);
                $insItem = $pdo->prepare("INSERT INTO asset_loan_items (loan_id, asset_item_id, condition_out, notes_out, status) VALUES (?, ?, ?, ?, 'borrowed')");
                $updNewAsset = $pdo->prepare("UPDATE asset_items SET status = 'borrowed', asset_status_id = ?, custodian_name = ?, custodian_nik = ? WHERE id = ?");
                foreach ($newAssetIds as $newAid) {
                    $cOut = trim((string)($conditionsOut[$newAid] ?? 'Normal / Baik'));
                    $nOut = trim((string)($itemNotesOut[$newAid] ?? ''));
                    $insItem->execute([$id, $newAid, $cOut ?: 'Normal / Baik', $nOut ?: null]);
                    $updNewAsset->execute([$borrowedStatusId, $borrowerName, $borrowerNik ?: null, $newAid]);
                }

                // 3. Item yang tetap ada: update condition_out & notes_out
                $keptAssetIds = array_intersect($selectedAssetIds, $oldAssetIds);
                $updKept = $pdo->prepare("UPDATE asset_loan_items SET condition_out = ?, notes_out = ? WHERE loan_id = ? AND asset_item_id = ?");
                foreach ($keptAssetIds as $keptAid) {
                    $cOut = trim((string)($conditionsOut[$keptAid] ?? 'Normal / Baik'));
                    $nOut = trim((string)($itemNotesOut[$keptAid] ?? ''));
                    $updKept->execute([$cOut ?: 'Normal / Baik', $nOut ?: null, $id, $keptAid]);
                }

                $pdo->commit();
                flash("Perubahan peminjaman aset <strong>{$loan['loan_code']}</strong> berhasil disimpan.", 'ok');
                redirect_to('asset_loans');
            } else {
                // INSERT Transaksi Baru
                $prefix = 'LN-' . date('Ym') . '-';
                $stmtSeq = $pdo->prepare("SELECT COUNT(*) FROM asset_loans WHERE loan_code LIKE ?");
                $stmtSeq->execute([$prefix . '%']);
                $nextSeq = (int)$stmtSeq->fetchColumn() + 1;
                $loanCode = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);

                $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_loans WHERE loan_code = ?");
                $chkStmt->execute([$loanCode]);
                while ((int)$chkStmt->fetchColumn() > 0) {
                    $nextSeq++;
                    $loanCode = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);
                    $chkStmt->execute([$loanCode]);
                }

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

                $insItem = $pdo->prepare("INSERT INTO asset_loan_items (loan_id, asset_item_id, condition_out, notes_out, status) VALUES (?, ?, ?, ?, 'borrowed')");
                $updAsset = $pdo->prepare("UPDATE asset_items SET status = 'borrowed', asset_status_id = ?, custodian_name = ?, custodian_nik = ? WHERE id = ?");

                foreach ($selectedAssetIds as $aid) {
                    $cOut = trim((string)($conditionsOut[$aid] ?? 'Normal / Baik'));
                    $nOut = trim((string)($itemNotesOut[$aid] ?? ''));

                    $insItem->execute([$loanId, $aid, $cOut ?: 'Normal / Baik', $nOut ?: null]);
                    $updAsset->execute([$borrowedStatusId, $borrowerName, $borrowerNik ?: null, $aid]);
                }

                $pdo->commit();
                flash("Peminjaman aset berhasil disimpan dengan nomor: <strong>{$loanCode}</strong>.", 'ok');
                redirect_to('asset_loans');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('Gagal menyimpan peminjaman aset: ' . $e->getMessage(), 'err');
        }
    }

    $pageTitle = $id > 0 ? ('Edit Peminjaman Aset #' . ($loan['loan_code'] ?? '')) : 'Catat Peminjaman Aset Baru';
    render_header($pageTitle, $user);

    $borrowerNameVal = $loan['borrower_name'] ?? '';
    $borrowerNikVal = $loan['borrower_nik'] ?? '';
    $borrowerDeptVal = $loan['borrower_department'] ?? '';
    $borrowerPhoneVal = $loan['borrower_phone'] ?? '';
    $loanDateVal = !empty($loan['loan_date']) ? date('Y-m-d\TH:i', strtotime($loan['loan_date'])) : date('Y-m-d\TH:i');
    $expReturnVal = !empty($loan['expected_return_date']) ? date('Y-m-d', strtotime($loan['expected_return_date'])) : date('Y-m-d', strtotime('+1 day'));
    $purposeVal = $loan['purpose'] ?? '';
    $locationNoteVal = $loan['location_note'] ?? '';
    $officerNotesVal = $loan['officer_notes'] ?? '';

    // Data awal unit terverifikasi jika edit
    $initialItemsJs = [];
    foreach ($existingItems as $it) {
        $initialItemsJs[] = [
            'id' => (int)$it['asset_item_id'],
            'asset_code' => (string)$it['asset_code'],
            'asset_name' => (string)$it['asset_name'],
            'brand' => (string)($it['brand'] ?? ''),
            'model' => (string)($it['model'] ?? ''),
            'group_name' => (string)($it['group_name'] ?? 'IT'),
            'type_name' => (string)($it['type_name'] ?? 'General'),
            'location_name' => (string)($it['location_name'] ?? '-'),
            'condition_out' => (string)($it['condition_out'] ?: 'Normal / Baik'),
            'notes_out' => (string)($it['notes_out'] ?? ''),
        ];
    }

    echo '<section class="panel">'
        . '<div class="split" style="align-items:center;margin-bottom:16px;">'
        . '  <div>'
        . '    <h1 style="margin:0;">' . e($pageTitle) . '</h1>'
        . '    <p class="muted" style="margin:4px 0 0 0;">Lengkapi identitas peminjam dari Master Pengguna dan scan QR code unit aset fisik yang diserahterimakan.</p>'
        . '  </div>'
        . '  <div class="actions">'
        . '    <a class="btn" href="' . route_url('asset_loans') . '">⬅️ Kembali ke Daftar</a>'
        . '  </div>'
        . '</div>'

        . '<form method="post" id="loanForm" onsubmit="return validateLoanForm();">'
        . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
        . ($id > 0 ? '<input type="hidden" name="loan_id" value="' . $id . '">' : '')

        // Bagian 1: Identitas Peminjam
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">'
        . '  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">'
        . '    <h2 style="margin:0;font-size:16px;color:#0f172a;display:flex;align-items:center;gap:6px;">👤 1. Identitas Peminjam (Dari Master Pengguna)</h2>'
        . '    <span id="borrowerSourceBadge" style="font-size:12px;background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-weight:600;' . ($borrowerNameVal !== '' ? '' : 'display:none;') . '">✓ Terpilih dari Database</span>'
        . '  </div>'

        // Search Autocomplete Master Pengguna
        . '  <div style="position:relative;margin-bottom:12px;">'
        . '    <label style="font-weight:600;font-size:13px;color:#334155;margin-bottom:4px;display:block;">🔍 Cari & Pilih Karyawan dari Database Master Pengguna:'
        . '      <input type="text" id="employeeSearchInput" placeholder="Ketik nama atau NIK karyawan (contoh: Budi, Agus, 00123)..." autocomplete="off" style="width:100%;box-sizing:border-box;padding:10px 14px;background:#fff;border:2px solid #0284c7;border-radius:6px;font-size:14px;">'
        . '    </label>'
        . '    <div id="employeeDropdownList" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);max-height:260px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 12px 30px rgba(0,0,0,0.18);z-index:99999;"></div>'
        . '  </div>'

        . '  <div class="grid three">'
        . '    <label>NIK Peminjam'
        . '      <input id="borrowerNikInput" name="borrower_nik" value="' . e($borrowerNikVal) . '" placeholder="NIK Karyawan">'
        . '    </label>'
        . '    <label>Nama Peminjam *'
        . '      <input id="borrowerNameInput" name="borrower_name" value="' . e($borrowerNameVal) . '" required placeholder="Nama Lengkap Karyawan">'
        . '    </label>'
        . '    <label>Departemen / Divisi'
        . '      <input id="borrowerDeptInput" name="borrower_department" value="' . e($borrowerDeptVal) . '" placeholder="Contoh: IT, Produksi, GA, Logistik">'
        . '    </label>'
        . '  </div>'
        . '  <div class="grid two" style="margin-top:8px;">'
        . '    <label>No. HP / WhatsApp (Peminjam)'
        . '      <input name="borrower_phone" value="' . e($borrowerPhoneVal) . '" placeholder="Contoh: 08123456789">'
        . '    </label>'
        . '    <label>Lokasi / Ruang Penggunaan'
        . '      <input name="location_note" value="' . e($locationNoteVal) . '" placeholder="Contoh: Area Pabrik Line 2, Gedung B Lantai 2, Ruang Rapat">'
        . '    </label>'
        . '  </div>'
        . '</div>'

        // Bagian 2: Waktu & Keperluan
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">'
        . '  <h2 style="margin:0 0 12px 0;font-size:16px;color:#0f172a;display:flex;align-items:center;gap:6px;">⏰ 2. Jadwal Peminjaman & Keperluan</h2>'
        . '  <div class="grid two">'
        . '    <label>Tanggal & Waktu Pinjam *'
        . '      <input type="datetime-local" name="loan_date" value="' . e($loanDateVal) . '" required>'
        . '    </label>'
        . '    <label>Estimasi Tanggal Kembali *'
        . '      <input type="date" name="expected_return_date" value="' . e($expReturnVal) . '" required>'
        . '      <small class="muted">Batas waktu pengembalian sebelum ditandai Overdue.</small>'
        . '    </label>'
        . '  </div>'
        . '  <label style="margin-top:8px;">Keperluan Peminjaman / Nama Proyek'
        . '    <textarea name="purpose" style="min-height:50px;" placeholder="Contoh: Penarikan kabel LAN ruang meeting, instalasi CCTV, perbaikan instalasi lampu plafon...">' . e($purposeVal) . '</textarea>'
        . '  </label>'
        . '  <label style="margin-top:8px;">Catatan Tambahan Petugas'
        . '    <input name="officer_notes" value="' . e($officerNotesVal) . '" placeholder="Catatan internal serah terima...">'
        . '  </label>'
        . '</div>'

        // Bagian 3: Scanner Kode Unit / QR Code
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">'
        . '  <div style="background:#f0f9ff;border:1px solid #0284c7;border-radius:8px;padding:16px;margin-bottom:16px;">'
        . '    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">'
        . '      <div>'
        . '        <h3 style="margin:0;color:#0369a1;font-size:16px;display:flex;align-items:center;gap:8px;">📷 3. Scan QR Code atau Masukkan Kode Unit Aset <span style="color:#ef4444;">*</span></h3>'
        . '        <p style="margin:4px 0 0 0;font-size:13px;color:#0369a1;">Wajib scan QR code pada stiker fisik atau ketik kode unit aset (contoh: <code>IT-CMP-000001</code> / <code>TL-IT-000001</code>) untuk validasi unit.</p>'
        . '      </div>'
        . '      <button type="button" class="btn" id="btnToggleCamera" onclick="toggleCameraScanner()" style="background:#0284c7;color:#fff;font-weight:600;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;">'
        . '        📷 <span id="cameraBtnText">Buka Live Scan Kamera QR</span>'
        . '      </button>'
        . '    </div>'

        // Kotak Live Scanner Kamera
        . '    <div id="cameraScannerBox" style="display:none;margin-top:14px;background:#0f172a;border-radius:8px;padding:14px;text-align:center;">'
        . '      <div id="reader" style="width:100%;max-width:340px;margin:0 auto;border-radius:8px;overflow:hidden;background:#000;"></div>'
        . '      <p id="camStatus" style="color:#38bdf8;font-size:13px;margin:10px 0 4px 0;">Menyiapkan kamera live scan QR...</p>'
        . '      <button type="button" class="btn" onclick="toggleCameraScanner()" style="margin-top:8px;background:#334155;color:#fff;padding:6px 14px;font-size:12px;">✕ Tutup Kamera</button>'
        . '    </div>'

        // Input Barcode / Manual
        . '    <div style="display:flex;gap:8px;margin-top:12px;">'
        . '      <input type="text" id="barcodeAssetInput" placeholder="Ketik Kode Unit Aset / scan barcode fisik (contoh: IT-CMP-000001) lalu tekan Enter..." style="flex:1;font-size:14px;padding:10px 14px;font-family:monospace;font-weight:600;background:#fff;border:1px solid #cbd5e1;border-radius:6px;" onkeydown="if(event.key===\'Enter\'){event.preventDefault();lookupAndAddAsset(this.value);}">'
        . '      <button type="button" class="btn primary" onclick="lookupAndAddAsset(document.getElementById(\'barcodeAssetInput\').value)" style="padding:10px 20px;font-weight:700;white-space:nowrap;">'
        . '        + Tambah Unit'
        . '      </button>'
        . '    </div>'
        . '    <div id="scanFeedbackAlert" style="display:none;margin-top:10px;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;"></div>'
        . '  </div>'

        // Tabel Keranjang Unit Terverifikasi
        . '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'
        . '    <h3 style="margin:0;font-size:15px;color:#1e293b;">Daftar Unit Aset Terverifikasi yang Dipinjam</h3>'
        . '    <span id="selectedCountBadge" style="background:#0284c7;color:#fff;font-size:12px;font-weight:bold;padding:4px 12px;border-radius:20px;">0 unit</span>'
        . '  </div>'
        . '  <div style="overflow-x:auto;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">'
        . '    <table id="loanItemsTable" style="width:100%;border-collapse:collapse;font-size:13px;">'
        . '      <thead>'
        . '        <tr style="background:#f1f5f9;border-bottom:2px solid #cbd5e1;text-align:left;">'
        . '          <th style="padding:8px 10px;width:36px;text-align:center;">No</th>'
        . '          <th style="padding:8px 10px;">Kode Unit</th>'
        . '          <th style="padding:8px 10px;">Nama & Model Barang</th>'
        . '          <th style="padding:8px 10px;">Komoditas / Kategori</th>'
        . '          <th style="padding:8px 10px;width:150px;">Kondisi Awal</th>'
        . '          <th style="padding:8px 10px;width:200px;">Catatan Kelengkapan</th>'
        . '          <th style="padding:8px 10px;width:50px;text-align:center;">Aksi</th>'
        . '        </tr>'
        . '      </thead>'
        . '      <tbody id="loanItemsBody">'
        . '      </tbody>'
        . '    </table>'
        . '    <div id="emptyLoanItemsMsg" style="padding:28px 16px;text-align:center;color:#64748b;font-size:13px;background:#fafafa;">'
        . '      Belum ada unit aset yang di-scan atau dimasukkan. Silakan scan stiker QR atau ketik kode unit aset di atas.'
        . '    </div>'
        . '  </div>'
        . '</div>'

        // Tombol Aksi
        . '<div class="actions" style="margin-top:20px;">'
        . '  <button type="submit" class="btn primary" style="padding:10px 26px;font-size:14px;font-weight:bold;">💾 Simpan Peminjaman Aset</button>'
        . '  <a class="btn" href="' . route_url('asset_loans') . '" style="padding:10px 18px;">Batal</a>'
        . '</div>'
        . '</form>'

        // Skrip JS Lengkap (Lookup Autocomplete Master Pengguna, Live Scanner QR, Dynamic Table)
        . '<script src="https://unpkg.com/html5-qrcode"></script>'
        . '<script>
        var scannedItems = ' . json_encode($initialItemsJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';
        var html5QrCode = null;
        var isCameraOpen = false;

        function renderScannedTable() {
            var tbody = document.getElementById("loanItemsBody");
            var emptyMsg = document.getElementById("emptyLoanItemsMsg");
            var badge = document.getElementById("selectedCountBadge");
            tbody.innerHTML = "";

            if (scannedItems.length === 0) {
                emptyMsg.style.display = "block";
                badge.textContent = "0 unit";
                badge.style.background = "#64748b";
                return;
            }

            emptyMsg.style.display = "none";
            badge.textContent = scannedItems.length + " unit terpilih";
            badge.style.background = "#16a34a";

            scannedItems.forEach(function(item, idx) {
                var tr = document.createElement("tr");
                tr.style.borderBottom = "1px solid #e2e8f0";
                tr.innerHTML = "<td style=\'padding:8px 10px;text-align:center;\'>" + (idx + 1) + "</td>"
                    + "<td style=\'padding:8px 10px;font-family:monospace;font-weight:700;color:#0284c7;\'>"
                    + "<input type=\'hidden\' name=\'asset_item_ids[]\' value=\'" + item.id + "\'>"
                    + item.asset_code
                    + "</td>"
                    + "<td style=\'padding:8px 10px;\'>"
                    + "<div style=\'font-weight:600;color:#1e293b;\'>" + (item.asset_name || "-") + "</div>"
                    + "<div style=\'font-size:11px;color:#64748b;\'>" + (item.brand ? (item.brand + " ") : "") + (item.model || "") + "</div>"
                    + "</td>"
                    + "<td style=\'padding:8px 10px;font-size:12px;color:#475569;\'>"
                    + (item.group_name || "IT") + " &bull; " + (item.type_name || "General")
                    + "</td>"
                    + "<td style=\'padding:8px 10px;\'>"
                    + "<input name=\'condition_out[" + item.id + "]\' value=\'" + (item.condition_out || "Normal / Baik") + "\' placeholder=\'Kondisi awal\' style=\'width:100%;box-sizing:border-box;padding:4px 8px;font-size:12px;\'>"
                    + "</td>"
                    + "<td style=\'padding:8px 10px;\'>"
                    + "<input name=\'item_notes_out[" + item.id + "]\' value=\'" + (item.notes_out || "") + "\' placeholder=\'Keterangan kelengkapan...\' style=\'width:100%;box-sizing:border-box;padding:4px 8px;font-size:12px;\'>"
                    + "</td>"
                    + "<td style=\'padding:8px 10px;text-align:center;\'>"
                    + "<button type=\'button\' class=\'btn danger\' onclick=\'removeScannedItem(" + item.id + ")\' style=\'padding:3px 8px;font-size:12px;\' title=\'Hapus dari daftar\'>✕</button>"
                    + "</td>";
                tbody.appendChild(tr);
            });
        }

        function removeScannedItem(id) {
            scannedItems = scannedItems.filter(function(x) { return x.id !== id; });
            renderScannedTable();
            showFeedback("Unit telah dihapus dari daftar pinjaman.", "info");
        }

        function showFeedback(msg, type) {
            var fb = document.getElementById("scanFeedbackAlert");
            if (!fb) return;
            fb.style.display = "block";
            fb.textContent = msg;
            if (type === "success") {
                fb.style.background = "#dcfce7";
                fb.style.color = "#166534";
                fb.style.border = "1px solid #86efac";
            } else if (type === "error") {
                fb.style.background = "#fee2e2";
                fb.style.color = "#991b1b";
                fb.style.border = "1px solid #fca5a5";
            } else {
                fb.style.background = "#f1f5f9";
                fb.style.color = "#334155";
                fb.style.border = "1px solid #cbd5e1";
            }
        }

        function lookupAndAddAsset(code) {
            code = (code || "").trim();
            if (!code) {
                showFeedback("Silakan masukkan atau scan kode unit aset terlebih dahulu.", "error");
                return;
            }

            // Cek apakah sudah ada di keranjang
            var already = scannedItems.some(function(item) {
                return item.asset_code.toUpperCase() === code.toUpperCase() || String(item.id) === code;
            });
            if (already) {
                showFeedback("Unit " + code + " sudah ada dalam daftar peminjaman di bawah.", "error");
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
                    // Cek duplikasi ID
                    if (scannedItems.some(function(x) { return x.id === item.id; })) {
                        showFeedback("Unit " + item.asset_code + " sudah ada dalam daftar di bawah.", "error");
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
                        type_name: item.type_name,
                        location_name: item.location_name,
                        condition_out: "Normal / Baik",
                        notes_out: ""
                    });

                    renderScannedTable();
                    showFeedback("✓ Berhasil! Unit " + item.asset_code + " (" + item.asset_name + ") ditambahkan ke daftar.", "success");
                    document.getElementById("barcodeAssetInput").value = "";
                    document.getElementById("barcodeAssetInput").focus();
                })
                .catch(function(err) {
                    showFeedback("Gagal menghubungi server: " + err.message, "error");
                });
        }

        // Live Camera Scanner Toggle
        function toggleCameraScanner() {
            var box = document.getElementById("cameraScannerBox");
            var btnText = document.getElementById("cameraBtnText");
            if (isCameraOpen) {
                if (html5QrCode) {
                    html5QrCode.stop().then(function() {
                        html5QrCode.clear();
                        box.style.display = "none";
                        btnText.textContent = "Buka Live Scan Kamera QR";
                        isCameraOpen = false;
                    }).catch(function() {
                        box.style.display = "none";
                        btnText.textContent = "Buka Live Scan Kamera QR";
                        isCameraOpen = false;
                    });
                } else {
                    box.style.display = "none";
                    btnText.textContent = "Buka Live Scan Kamera QR";
                    isCameraOpen = false;
                }
            } else {
                box.style.display = "block";
                btnText.textContent = "Tutup Kamera";
                isCameraOpen = true;
                startCameraScanner();
            }
        }

        function startCameraScanner() {
            var camStatus = document.getElementById("camStatus");
            camStatus.textContent = "Menyalakan kamera...";
            html5QrCode = new Html5Qrcode("reader");
            var config = { fps: 10, qrbox: { width: 250, height: 250 } };
            html5QrCode.start({ facingMode: "environment" }, config, function(decodedText) {
                camStatus.textContent = "QR Terbaca: " + decodedText;
                lookupAndAddAsset(decodedText);
            }).catch(function(err) {
                camStatus.textContent = "Kamera tidak dapat diakses atau diblokir (" + err + ").";
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
                setTimeout(function() { dropdown.style.display = "none"; }, 200);
            }

            function selectEmployee(item) {
                nameInput.value = item.name || "";
                nikInput.value = item.nik || "";
                deptInput.value = item.department || "";
                searchInput.value = (item.name || "") + " (" + (item.nik || "-") + ")";
                badge.style.display = "inline-block";
                badge.textContent = "✓ Terpilih: " + (item.name || "");
                dropdown.style.display = "none";
            }

            function searchEmployees(q) {
                fetch("index.php?route=employee_search&limit=30&q=" + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        var data = (res && res.data) ? res.data : [];
                        dropdown.innerHTML = "";
                        if (data.length === 0) {
                            dropdown.innerHTML = "<div style=\'padding:10px 14px;color:#94a3b8;font-size:13px;\'>Tidak ada karyawan cocok di Master Pengguna.</div>";
                            dropdown.style.display = "block";
                            return;
                        }
                        data.forEach(function(row) {
                            var div = document.createElement("div");
                            div.style.padding = "10px 14px";
                            div.style.cursor = "pointer";
                            div.style.borderBottom = "1px solid #f1f5f9";
                            div.style.fontSize = "13px";
                            div.innerHTML = "<strong style=\'color:#0f172a;\'>" + (row.name || "-") + "</strong>"
                                + " <span style=\'color:#64748b;\'>(" + (row.nik || "-") + ")</span>"
                                + (row.department ? (" &bull; <span style=\'color:#0284c7;\'>" + row.department + "</span>") : "");
                            div.onmouseenter = function() { div.style.background = "#f0f9ff"; };
                            div.onmouseleave = function() { div.style.background = "#fff"; };
                            div.onmousedown = function() { selectEmployee(row); };
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
                    timer = setTimeout(function() { searchEmployees(q); }, 250);
                });
                searchInput.addEventListener("focus", function() {
                    if (searchInput.value.trim()) {
                        searchEmployees(searchInput.value.trim());
                    }
                });
                searchInput.addEventListener("blur", hideDropdown);
            }
        })();

        function validateLoanForm() {
            var name = document.getElementById("borrowerNameInput").value.trim();
            if (!name) {
                alert("Nama peminjam wajib diisi.");
                document.getElementById("borrowerNameInput").focus();
                return false;
            }
            if (scannedItems.length === 0) {
                alert("Pilih / scan minimal 1 unit aset fisik yang akan dipinjam terlebih dahulu.");
                document.getElementById("barcodeAssetInput").focus();
                return false;
            }
            return true;
        }

        renderScannedTable();
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

