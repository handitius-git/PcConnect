<?php
declare(strict_types=1);

function generate_next_ticket_code(PDO $pdo): string
{
    $prefix = 'TKT-' . date('Ym') . '-';
    $stmt = $pdo->prepare("SELECT ticket_code FROM corrective_tickets WHERE ticket_code LIKE ? ORDER BY ticket_code DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $nextNum = 1;
    if ($last !== '') {
        $suffix = substr($last, strlen($prefix));
        $nextNum = max(1, (int)$suffix + 1);
    }
    return $prefix . str_pad((string)$nextNum, 5, '0', STR_PAD_LEFT);
}

function generate_next_walkaround_code(PDO $pdo): string
{
    $prefix = 'WKD-' . date('Ym') . '-';
    $stmt = $pdo->prepare("SELECT walkaround_code FROM asset_walkarounds WHERE walkaround_code LIKE ? ORDER BY walkaround_code DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $nextNum = 1;
    if ($last !== '') {
        $suffix = substr($last, strlen($prefix));
        $nextNum = max(1, (int)$suffix + 1);
    }
    return $prefix . str_pad((string)$nextNum, 5, '0', STR_PAD_LEFT);
}

function ticket_status_badge(string $status): string
{
    return match (strtolower(trim($status))) {
        'open' => '<span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">Open</span>',
        'assigned' => '<span class="badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd">Assigned</span>',
        'in_progress' => '<span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a">In Progress</span>',
        'pending_part' => '<span class="badge" style="background:#f3e8ff;color:#6b21a8;border:1px solid #e9d5ff">Waiting Part</span>',
        'resolved' => '<span class="badge ok">Resolved</span>',
        'closed' => '<span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1">Closed</span>',
        default => '<span class="badge">' . e($status) . '</span>',
    };
}

function ticket_priority_badge(string $priority): string
{
    return match (strtolower(trim($priority))) {
        'critical' => '<span class="badge danger" style="font-weight:700">CRITICAL</span>',
        'high' => '<span class="badge" style="background:#ffedd5;color:#c2410c;font-weight:600">High</span>',
        'medium' => '<span class="badge" style="background:#fef9c3;color:#854d0e">Medium</span>',
        'low' => '<span class="badge" style="background:#f1f5f9;color:#475569">Low</span>',
        default => '<span class="badge">' . e($priority) . '</span>',
    };
}

function ticket_category_badge(string $category): string
{
    return match (strtoupper(trim($category))) {
        'IT', 'COMPUTER' => '<span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe">💻 IT Asset</span>',
        'VEHICLE', 'KENDARAAN' => '<span class="badge" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0">🚗 Vehicle</span>',
        'FACILITY', 'FASILITAS' => '<span class="badge" style="background:#fffbeb;color:#b45309;border:1px solid #fde68a">🏢 Facility</span>',
        default => '<span class="badge">' . e($category) . '</span>',
    };
}

function get_maintenance_asset_unit(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $hasPcs = db_table_exists($pdo, 'pcs');
    $hasPrinters = db_table_exists($pdo, 'printers');
    $hasMai = db_table_exists($pdo, 'maintenance_asset_items');
    $hasGroups = db_table_exists($pdo, 'asset_groups');
    $hasTypes = db_table_exists($pdo, 'asset_types');
    $hasAssetItems = db_table_exists($pdo, 'asset_items');
    $hasJobDesks = db_table_exists($pdo, 'corrective_job_desks');

    $sql = "SELECT ma.*,
                   " . ($hasGroups ? "ag.group_code, ag.group_name," : "'' AS group_code, '' AS group_name,") . "
                   " . ($hasTypes ? "at.type_code, at.type_name," : "'' AS type_code, '' AS type_name,") . "
                   " . ($hasPcs ? "p.asset_item_id AS pc_asset_item_id," : "NULL AS pc_asset_item_id,") . "
                   " . ($hasPrinters ? "prn.asset_item_id AS prn_asset_item_id," : "NULL AS prn_asset_item_id,") . "
                   " . ($hasMai ? "mai.asset_item_id AS mai_asset_item_id," : "NULL AS mai_asset_item_id,") . "
                   " . ($hasAssetItems ? "ai.id AS direct_item_id, ai.asset_code AS item_asset_code, ai.asset_name AS item_asset_name, ai.asset_group_id AS item_group_id, ai.asset_type_id AS item_type_id, ai.asset_type AS item_asset_type, ai.asset_category AS item_asset_category, ai.custodian_name AS item_custodian_name, ai.custodian_nik AS item_custodian_nik" : "NULL AS direct_item_id, '' AS item_asset_code, '' AS item_asset_name, NULL AS item_group_id, NULL AS item_type_id, '' AS item_asset_type, '' AS item_asset_category, '' AS item_custodian_name, '' AS item_custodian_nik") . "
            FROM maintenance_assets ma
            " . ($hasGroups ? "LEFT JOIN asset_groups ag ON ag.id = ma.asset_group_id" : "") . "
            " . ($hasTypes ? "LEFT JOIN asset_types at ON at.id = ma.asset_type_id" : "") . "
            " . ($hasPcs ? "LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci" : "") . "
            " . ($hasPrinters ? "LEFT JOIN printers prn ON prn.prn_id COLLATE utf8mb4_unicode_ci = ma.printer_id COLLATE utf8mb4_unicode_ci" : "") . "
            " . ($hasMai ? "LEFT JOIN maintenance_asset_items mai ON mai.maintenance_asset_id = ma.id AND mai.detached_at IS NULL" : "") . "
            " . ($hasAssetItems ? "LEFT JOIN asset_items ai ON ai.id = COALESCE(p.asset_item_id, prn.asset_item_id, mai.asset_item_id)" : "") . "
            WHERE ma.id = ?
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $assetItemId = (int)($row['direct_item_id'] ?? $row['pc_asset_item_id'] ?? $row['prn_asset_item_id'] ?? $row['mai_asset_item_id'] ?? 0);

    // Fallback pencarian asset_items jika belum ketemu dari join
    if ($hasAssetItems && $assetItemId <= 0) {
        $aiStmt = $pdo->prepare("SELECT id, asset_code, asset_name, asset_group_id, asset_type_id, asset_type, asset_category, custodian_name, custodian_nik 
                                 FROM asset_items 
                                 WHERE asset_code = ? OR (maintenance_asset_id IS NOT NULL AND maintenance_asset_id = ?) 
                                 LIMIT 1");
        $aiStmt->execute([$row['maintenance_asset_code'], $id]);
        $aiRow = $aiStmt->fetch(PDO::FETCH_ASSOC);
        if ($aiRow) {
            $assetItemId = (int)$aiRow['id'];
            $row['item_asset_code'] = $aiRow['asset_code'];
            $row['item_asset_name'] = $aiRow['asset_name'];
            $row['item_group_id'] = $aiRow['asset_group_id'];
            $row['item_type_id'] = $aiRow['asset_type_id'];
            $row['item_asset_type'] = $aiRow['asset_type'];
            $row['item_asset_category'] = $aiRow['asset_category'];
            if (!empty($aiRow['custodian_name'])) {
                $row['item_custodian_name'] = $aiRow['custodian_name'];
            }
            if (!empty($aiRow['custodian_nik'])) {
                $row['item_custodian_nik'] = $aiRow['custodian_nik'];
            }
        }
    }

    $row['asset_item_id'] = $assetItemId > 0 ? $assetItemId : null;

    // Utamakan Group dan Type dari asset_items jika terisi
    $groupId = (int)(($row['item_group_id'] ?? 0) ?: ($row['asset_group_id'] ?? 0));
    $typeId = (int)(($row['item_type_id'] ?? 0) ?: ($row['asset_type_id'] ?? 0));

    // Jika Type ID diketahui tetapi Group ID kosong, cari Group ID dari tabel asset_types
    if ($hasTypes && $typeId > 0 && $groupId <= 0) {
        $tStmt = $pdo->prepare("SELECT asset_group_id FROM asset_types WHERE id = ? LIMIT 1");
        $tStmt->execute([$typeId]);
        $gId = (int)($tStmt->fetchColumn() ?: 0);
        if ($gId > 0) {
            $groupId = $gId;
        }
    }

    // Resolusi cerdas jika Group / Type masih belum ketemu (berdasarkan kode aset / pattern / master category)
    $assetCodeToCheck = (string)($row['item_asset_code'] ?? $row['maintenance_asset_code'] ?? '');
    if ($hasGroups && $hasTypes && ($groupId <= 0 || $typeId <= 0)) {
        if (preg_match('/^([A-Z0-9]+)-([A-Z0-9]+)-/i', $assetCodeToCheck, $m)) {
            $gCode = strtoupper($m[1]);
            $tCode = strtoupper($m[2]);
            if ($groupId <= 0) {
                $gStmt = $pdo->prepare("SELECT id FROM asset_groups WHERE UPPER(group_code) = ? LIMIT 1");
                $gStmt->execute([$gCode]);
                $groupId = (int)($gStmt->fetchColumn() ?: 0);
            }
            if ($typeId <= 0 && $groupId > 0) {
                $tStmt = $pdo->prepare("SELECT id FROM asset_types WHERE asset_group_id = ? AND UPPER(type_code) = ? LIMIT 1");
                $tStmt->execute([$groupId, $tCode]);
                $typeId = (int)($tStmt->fetchColumn() ?: 0);
            }
        }
    }

    // Resolusi fallback berdasarkan tipe perangkat (PC / Printer / Kendaraan / Fasilitas)
    $cat = 'IT';
    $grp = strtoupper((string)($row['group_code'] ?? ''));
    $grpName = strtoupper((string)($row['group_name'] ?? ''));
    $mType = strtolower((string)($row['maintenance_type'] ?? ''));
    $itemCat = (string)($row['item_asset_category'] ?? '');

    if ($grp === 'VH' || str_contains($grpName, 'VEHICLE') || str_contains($mType, 'vehicle') || strcasecmp($itemCat, 'Vehicle') === 0) {
        $cat = 'Vehicle';
        if ($groupId <= 0 && $hasGroups) {
            $groupId = (int)$pdo->query("SELECT id FROM asset_groups WHERE group_code = 'VH' OR group_name LIKE '%VEHICLE%' ORDER BY id ASC LIMIT 1")->fetchColumn();
        }
    } elseif ($grp === 'FC' || str_contains($grpName, 'FACILITY') || str_contains($mType, 'facility') || strcasecmp($itemCat, 'Facility') === 0) {
        $cat = 'Facility';
        if ($groupId <= 0 && $hasGroups) {
            $groupId = (int)$pdo->query("SELECT id FROM asset_groups WHERE group_code = 'FC' OR group_name LIKE '%FACILITY%' ORDER BY id ASC LIMIT 1")->fetchColumn();
        }
    } else {
        $cat = 'IT';
        if ($groupId <= 0 && $hasGroups) {
            $groupId = (int)$pdo->query("SELECT id FROM asset_groups WHERE group_code = 'IT' OR group_name LIKE '%IT%' ORDER BY id ASC LIMIT 1")->fetchColumn();
        }
        if ($typeId <= 0 && $hasTypes && $groupId > 0) {
            if ($mType === 'printer' || !empty($row['printer_id']) || strcasecmp($itemCat, 'Printer') === 0) {
                $typeId = (int)$pdo->query("SELECT id FROM asset_types WHERE asset_group_id = {$groupId} AND (type_code = 'PRT' OR type_name LIKE '%Printer%') ORDER BY id ASC LIMIT 1")->fetchColumn();
            } else {
                $typeId = (int)$pdo->query("SELECT id FROM asset_types WHERE asset_group_id = {$groupId} AND (type_code = 'CMP' OR type_name LIKE '%Comp%') ORDER BY id ASC LIMIT 1")->fetchColumn();
            }
        }
    }

    $row['asset_group_id'] = $groupId > 0 ? $groupId : null;
    $row['asset_type_id'] = $typeId > 0 ? $typeId : null;

    if ($groupId > 0 && $hasGroups) {
        $gRow = $pdo->query("SELECT group_code, group_name FROM asset_groups WHERE id = {$groupId}")->fetch(PDO::FETCH_ASSOC);
        if ($gRow) {
            $row['group_code'] = $gRow['group_code'];
            $row['group_name'] = $gRow['group_name'];
        }
    }
    if ($typeId > 0 && $hasTypes) {
        $tRow = $pdo->query("SELECT type_code, type_name FROM asset_types WHERE id = {$typeId}")->fetch(PDO::FETCH_ASSOC);
        if ($tRow) {
            $row['type_code'] = $tRow['type_code'];
            $row['type_name'] = $tRow['type_name'];
        }
    }

    // Temukan Job Desk Corrective Maintenance yang paling cocok
    $row['job_desk_name'] = '';
    $row['job_desk_id'] = null;
    if ($hasJobDesks && $groupId > 0) {
        $jdStmt = $pdo->prepare("SELECT id, job_desk_name FROM corrective_job_desks 
                                 WHERE asset_group_id = ? AND (asset_type_id = ? OR asset_type_id IS NULL OR asset_type_id = 0) 
                                 ORDER BY CASE WHEN asset_type_id = ? THEN 0 ELSE 1 END, id ASC LIMIT 1");
        $jdStmt->execute([$groupId, $typeId ?: 0, $typeId ?: 0]);
        $jdRow = $jdStmt->fetch(PDO::FETCH_ASSOC);
        if ($jdRow) {
            $row['job_desk_id'] = (int)$jdRow['id'];
            $row['job_desk_name'] = $jdRow['job_desk_name'];
        }
    }

    // Sinkronkan ke maintenance_assets jika perlu
    if ($groupId > 0 || $typeId > 0) {
        try {
            $upd = $pdo->prepare("UPDATE maintenance_assets SET asset_group_id = ?, asset_type_id = ? WHERE id = ? AND (asset_group_id IS NULL OR asset_type_id IS NULL OR asset_group_id <> ? OR asset_type_id <> ?)");
            $upd->execute([$groupId ?: null, $typeId ?: null, $id, $groupId ?: 0, $typeId ?: 0]);
        } catch (Throwable $ignored) {}
    }

    $row['asset_category'] = $cat;
    $row['custodian_name'] = $row['item_custodian_name'] ?: ($row['owner_name'] ?? '');
    $row['custodian_nik'] = $row['item_custodian_nik'] ?: ($row['employee_nik'] ?? '');

    return $row;
}

function resolve_maintenance_asset_id_from_raw_input(PDO $pdo, string $rawInput): ?int
{
    $raw = trim($rawInput);
    if ($raw === '') {
        return null;
    }

    // 0. Direct Integer ID check
    if (ctype_digit($raw) && (int)$raw > 0) {
        $stmt = $pdo->prepare("SELECT id FROM maintenance_assets WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$raw]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    $candidateCodes = [];
    $rawClean = urldecode($raw);
    $candidateCodes[] = $raw;
    $candidateCodes[] = $rawClean;

    // 1. Ekstrak query parameters jika raw input adalah URL atau memiliki query string
    $extractedTokens = [];
    if (preg_match('/[?&]eqr=([^&#\s]+)/i', $rawClean, $m)) {
        $extractedTokens[] = urldecode($m[1]);
    }
    if (preg_match('/[?&]code=([^&#\s]+)/i', $rawClean, $m)) {
        $candidateCodes[] = urldecode($m[1]);
    }
    if (str_contains($rawClean, '?') || str_starts_with($rawClean, 'http://') || str_starts_with($rawClean, 'https://')) {
        $parsed = parse_url($rawClean);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            if (!empty($queryParams['eqr'])) {
                $extractedTokens[] = (string)$queryParams['eqr'];
            }
            if (!empty($queryParams['code'])) {
                $candidateCodes[] = (string)$queryParams['code'];
            }
        }
    }

    // 2. Dekripsi token terenkripsi jika ditemukan (atau jika string input itu sendiri adalah token)
    if (function_exists('qr_decrypt_payload')) {
        foreach ($extractedTokens as $token) {
            $dec = qr_decrypt_payload($token);
            if (!empty($dec)) {
                $candidateCodes[] = trim($dec);
            }
        }
        // Coba juga dekripsi langsung raw string jika token terenkripsi di-scan mentah
        $dec = qr_decrypt_payload($raw);
        if (!empty($dec)) {
            $candidateCodes[] = trim($dec);
        }
        $dec2 = qr_decrypt_payload($rawClean);
        if (!empty($dec2)) {
            $candidateCodes[] = trim($dec2);
        }
    }

    // 3. Ekstrak variasi kode (strip security code, parse prefix, regex)
    $extraCodes = [];
    foreach ($candidateCodes as $c) {
        $c = trim($c);
        if ($c === '') {
            continue;
        }

        // Coba via helper bawaan find_asset_by_code
        if (function_exists('find_asset_by_code')) {
            [$assetType, $assetId, $assetInfo] = find_asset_by_code($pdo, $c);
            if (!empty($assetInfo['maintenance_asset_id'])) {
                return (int)$assetInfo['maintenance_asset_id'];
            }
            if ($assetType === 'maintenance_asset' && !empty($assetInfo['id'])) {
                return (int)$assetInfo['id'];
            }
            if ($assetId !== '') {
                $extraCodes[] = $assetId;
            }
        }

        // Parse format CODE-SECURITY_CODE
        if (function_exists('parse_asset_code')) {
            [$base, $sec] = parse_asset_code($c);
            if ($base !== '') {
                $extraCodes[] = $base;
            }
        }

        // Strip akhiran -XXXX jika ada
        if (preg_match('/^(.+?)-[A-Z0-9]{2,12}$/i', $c, $m)) {
            $extraCodes[] = $m[1];
        }

        // Regex pola MNT-XXXX
        if (preg_match_all('/MNT-[A-Z0-9_\-]+/i', $c, $matches)) {
            foreach ($matches[0] as $m) {
                $extraCodes[] = $m;
                if (preg_match('/^(.+?)-[A-Z0-9]{2,12}$/i', $m, $subm)) {
                    $extraCodes[] = $subm[1];
                }
            }
        }

        // Regex pola PCXXXX atau PRNXXXX
        if (preg_match_all('/(PC\d{4,10}|PRN\d{4,10})/i', $c, $matches)) {
            foreach ($matches[0] as $m) {
                $extraCodes[] = $m;
            }
        }
    }

    $allCandidates = array_values(array_unique(array_filter(array_merge($candidateCodes, $extraCodes))));

    // 4. Cari di database maintenance_assets untuk setiap candidate code
    $hasPcs = db_table_exists($pdo, 'pcs');
    $hasPrinters = db_table_exists($pdo, 'printers');
    $hasAssetItems = db_table_exists($pdo, 'asset_items');
    $hasMaintItems = db_table_exists($pdo, 'maintenance_asset_items');

    foreach ($allCandidates as $code) {
        $code = trim($code);
        if ($code === '' || str_starts_with($code, 'http://') || str_starts_with($code, 'https://')) {
            continue;
        }

        // a. Exact match maintenance_asset_code
        $stmt = $pdo->prepare("SELECT id FROM maintenance_assets WHERE maintenance_asset_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        // b. Exact match pc_id
        $stmt = $pdo->prepare("SELECT id FROM maintenance_assets WHERE pc_id = ? LIMIT 1");
        $stmt->execute([$code]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        // c. Exact match printer_id
        $stmt = $pdo->prepare("SELECT id FROM maintenance_assets WHERE printer_id = ? LIMIT 1");
        $stmt->execute([$code]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        // d. Match via pcs table (maintenance_asset_id)
        if ($hasPcs) {
            $stmt = $pdo->prepare("SELECT maintenance_asset_id FROM pcs WHERE pc_id = ? AND maintenance_asset_id IS NOT NULL LIMIT 1");
            $stmt->execute([$code]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        // e. Match via printers table (maintenance_asset_id)
        if ($hasPrinters) {
            $stmt = $pdo->prepare("SELECT maintenance_asset_id FROM printers WHERE prn_id = ? AND maintenance_asset_id IS NOT NULL LIMIT 1");
            $stmt->execute([$code]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        // f. Match via maintenance_asset_items & asset_items
        if ($hasMaintItems && $hasAssetItems) {
            $stmt = $pdo->prepare("SELECT mai.maintenance_asset_id FROM maintenance_asset_items mai
                                   JOIN asset_items ai ON ai.id = mai.asset_item_id
                                   WHERE ai.asset_code = ? AND mai.detached_at IS NULL LIMIT 1");
            $stmt->execute([$code]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        // g. Match via asset_items linked to pcs
        if ($hasAssetItems && $hasPcs) {
            $stmt = $pdo->prepare("SELECT ma.id FROM maintenance_assets ma
                                   JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci
                                   JOIN asset_items ai ON ai.id = p.asset_item_id
                                   WHERE ai.asset_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        // h. Match via asset_items directly (auto-link / auto-create maintenance_asset if needed)
        if ($hasAssetItems) {
            $stmt = $pdo->prepare("SELECT id, asset_code, asset_name, asset_group_id, asset_type_id, asset_category, location_label, custodian_name, custodian_nik, maintenance_asset_id FROM asset_items WHERE asset_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $aiRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($aiRow) {
                if (!empty($aiRow['maintenance_asset_id'])) {
                    return (int)$aiRow['maintenance_asset_id'];
                }
                $stmtMa = $pdo->prepare("SELECT id FROM maintenance_assets WHERE maintenance_asset_code = ? LIMIT 1");
                $stmtMa->execute([$aiRow['asset_code']]);
                $maId = (int)($stmtMa->fetchColumn() ?: 0);
                if ($maId > 0) {
                    return $maId;
                }
                // Buat maintenance_asset baru secara otomatis untuk asset_item ini
                $secCode = strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $mType = 'other';
                if (stripos((string)$aiRow['asset_category'], 'comp') !== false || stripos((string)$aiRow['asset_code'], '-CMP-') !== false || stripos((string)$aiRow['asset_code'], '-NBK-') !== false) {
                    $mType = 'pc_set';
                } elseif (stripos((string)$aiRow['asset_category'], 'print') !== false || stripos((string)$aiRow['asset_code'], '-PRT-') !== false) {
                    $mType = 'printer';
                } elseif (stripos((string)$aiRow['asset_category'], 'vehic') !== false) {
                    $mType = 'vehicle';
                } elseif (stripos((string)$aiRow['asset_category'], 'facil') !== false) {
                    $mType = 'facility';
                }
                $insMa = $pdo->prepare("INSERT INTO maintenance_assets (
                    maintenance_asset_code, security_code, maintenance_type, name, asset_group_id, asset_type_id, location_label, owner_name, employee_nik, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $insMa->execute([
                    $aiRow['asset_code'],
                    $secCode,
                    $mType,
                    $aiRow['asset_name'] ?: $aiRow['asset_code'],
                    $aiRow['asset_group_id'] ?: null,
                    $aiRow['asset_type_id'] ?: null,
                    $aiRow['location_label'] ?: null,
                    $aiRow['custodian_name'] ?: null,
                    $aiRow['custodian_nik'] ?: null,
                ]);
                $newMaId = (int)$pdo->lastInsertId();
                if ($newMaId > 0) {
                    if (db_column_exists($pdo, 'asset_items', 'maintenance_asset_id')) {
                        $pdo->prepare("UPDATE asset_items SET maintenance_asset_id = ? WHERE id = ?")->execute([$newMaId, (int)$aiRow['id']]);
                    }
                    if ($hasMaintItems) {
                        $pdo->prepare("INSERT INTO maintenance_asset_items (maintenance_asset_id, asset_item_id, role_name, attached_at) VALUES (?, ?, 'Primary Asset', CURDATE())")
                            ->execute([$newMaId, (int)$aiRow['id']]);
                    }
                    return $newMaId;
                }
            }
        }

        // i. Match prefix maintenance_asset_code LIKE 'code%'
        $stmt = $pdo->prepare("SELECT id FROM maintenance_assets WHERE maintenance_asset_code LIKE ? OR pc_id LIKE ? LIMIT 1");
        $stmt->execute([$code . '%', $code . '%']);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        // i. Fallback LIKE name
        if (strlen($code) >= 4) {
            $stmt = $pdo->prepare("SELECT id FROM maintenance_assets WHERE name LIKE ? LIMIT 1");
            $stmt->execute(['%' . $code . '%']);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }
    }

    return null;
}

// -----------------------------------------------------------------------------
// WEB INTERFACE: TICKET MANAGEMENT (DESKTOP)
// -----------------------------------------------------------------------------

function handle_route_tickets(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician', 'corrective_maintenance']);
    ensure_corrective_maintenance_schema($pdo);

    $catFilter = trim((string)($_GET['category'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

    $where = [];
    $params = [];

    if ($catFilter !== '') {
        $where[] = 't.asset_category = ?';
        $params[] = $catFilter;
    }
    if ($statusFilter !== '') {
        $where[] = 't.status = ?';
        $params[] = $statusFilter;
    }
    if ($q !== '') {
        $where[] = '(t.ticket_code LIKE ? OR t.subject LIKE ? OR t.reporter_name LIKE ? OR t.location_label LIKE ? OR ma.maintenance_asset_code LIKE ? OR ma.name LIKE ? OR t.pc_id LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT t.*, ma.maintenance_asset_code, ma.name AS maintenance_asset_name, c.company_name,
                   (SELECT COUNT(*) FROM corrective_repairs cr WHERE cr.ticket_id = t.id) AS repair_count,
                   (SELECT COALESCE(SUM(cr.repair_cost), 0) FROM corrective_repairs cr WHERE cr.ticket_id = t.id) AS total_repair_cost,
                   (SELECT COALESCE(SUM(crp.part_cost), 0) FROM corrective_repairs cr JOIN corrective_repair_parts crp ON crp.repair_id = cr.id WHERE cr.ticket_id = t.id) AS total_part_cost
            FROM corrective_tickets t
            LEFT JOIN maintenance_assets ma ON ma.id = t.maintenance_asset_id
            LEFT JOIN asset_companies c ON c.id = t.company_id
            $whereSql
            ORDER BY FIELD(t.status, 'open', 'assigned', 'in_progress', 'pending_part', 'resolved', 'closed'), t.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = $pdo->query("SELECT 
        COUNT(CASE WHEN status IN ('open', 'assigned') THEN 1 END) AS count_open,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) AS count_progress,
        COUNT(CASE WHEN status = 'pending_part' THEN 1 END) AS count_pending_part,
        COUNT(CASE WHEN status IN ('resolved', 'closed') THEN 1 END) AS count_resolved,
        COUNT(*) AS count_total
    FROM corrective_tickets")->fetch(PDO::FETCH_ASSOC);

    render_header('Corrective Maintenance & Ticketing', $user);
    ?>
    <section class="panel">
        <div class="split">
            <div>
                <h1>Tiket & Troubleshooting (Corrective Maintenance)</h1>
                <p class="muted">Kelola pelaporan masalah dan perbaikan unit berbasis <strong>Maintenance Asset ID</strong> (IT, Kendaraan, dan Fasilitas).</p>
            </div>
            <div class="actions">
                <a class="btn primary" href="<?= route_url('ticket_form') ?>">+ Buat Tiket Baru</a>
                <a class="btn good" href="<?= route_url('mobile_service') ?>" target="_blank">📱 Buka Mobile Service (Teknisi)</a>
                <a class="btn" href="<?= route_url('corrective_repairs') ?>">Riwayat Reparasi & Part</a>
                <a class="btn" href="<?= route_url('walkarounds') ?>">Patroli Walkaround</a>
            </div>
        </div>

        <div class="grid four" style="margin-top:16px;">
            <div class="stat"><strong style="color:#b91c1c"><?= (int)($stats['count_open'] ?? 0) ?></strong><span>Tiket Baru / Terbuka</span></div>
            <div class="stat"><strong style="color:#d97706"><?= (int)($stats['count_progress'] ?? 0) ?></strong><span>Sedang Dikerjakan</span></div>
            <div class="stat"><strong style="color:#7c3aed"><?= (int)($stats['count_pending_part'] ?? 0) ?></strong><span>Menunggu Sparepart</span></div>
            <div class="stat"><strong style="color:#15803d"><?= (int)($stats['count_resolved'] ?? 0) ?></strong><span>Selesai / Ditutup</span></div>
        </div>
    </section>

    <section class="panel">
        <form method="get" class="grid four" style="margin-bottom:16px;">
            <input type="hidden" name="route" value="tickets">
            <label>Cari Tiket / Maintenance Asset / Pelapor
                <input name="q" value="<?= e($q) ?>" placeholder="Kode tiket, MNT-..., nama user...">
            </label>
            <label>Kategori Aset
                <select name="category">
                    <option value="">Semua Kategori</option>
                    <option value="IT" <?= $catFilter === 'IT' ? 'selected' : '' ?>>IT Asset</option>
                    <option value="Vehicle" <?= $catFilter === 'Vehicle' ? 'selected' : '' ?>>Vehicle (Kendaraan)</option>
                    <option value="Facility" <?= $catFilter === 'Facility' ? 'selected' : '' ?>>Facility (Gedung/Fasilitas)</option>
                </select>
            </label>
            <label>Status Tiket
                <select name="status">
                    <option value="">Semua Status</option>
                    <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="pending_part" <?= $statusFilter === 'pending_part' ? 'selected' : '' ?>>Waiting Part</option>
                    <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
                </select>
            </label>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <button class="btn primary" style="height:42px;">Filter</button>
                <a class="btn" href="<?= route_url('tickets') ?>" style="height:42px;line-height:22px;">Reset</a>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Tiket & Tanggal</th>
                    <th>Kategori & Prioritas</th>
                    <th>Maintenance Asset ID</th>
                    <th>Kendala / Subjek</th>
                    <th>Pelapor & Lokasi</th>
                    <th>Status & PIC</th>
                    <th>Biaya (Jasa + Part)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tickets): ?>
                    <tr><td colspan="8" class="muted" style="text-align:center;padding:24px;">Belum ada tiket corrective maintenance.</td></tr>
                <?php else: ?>
                    <?php foreach ($tickets as $t): ?>
                        <?php
                        $assetLabel = '-';
                        if (!empty($t['maintenance_asset_code'])) {
                            $assetLabel = '<strong>' . e($t['maintenance_asset_code']) . '</strong><br><small class="muted">' . e(mb_strimwidth((string)$t['maintenance_asset_name'], 0, 40, '...')) . '</small>';
                        } elseif (!empty($t['pc_id'])) {
                            $assetLabel = '<strong>PC: ' . e($t['pc_id']) . '</strong>';
                        }
                        $totalCost = (float)$t['total_repair_cost'] + (float)$t['total_part_cost'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($t['ticket_code']) ?></strong><br>
                                <span class="muted"><?= e(date('d M Y H:i', strtotime($t['created_at']))) ?></span>
                            </td>
                            <td>
                                <?= ticket_category_badge($t['asset_category']) ?><br>
                                <div style="margin-top:4px;"><?= ticket_priority_badge($t['priority']) ?></div>
                            </td>
                            <td><?= $assetLabel ?></td>
                            <td>
                                <strong><?= e($t['subject']) ?></strong><br>
                                <span class="muted"><?= e(mb_strimwidth((string)$t['description'], 0, 70, '...')) ?></span>
                            </td>
                            <td>
                                <?= e($t['reporter_name']) ?> <?= $t['reporter_nik'] ? '<span class="muted">(' . e($t['reporter_nik']) . ')</span>' : '' ?><br>
                                <span class="muted"><?= e($t['location_label'] ?: '-') ?></span>
                            </td>
                            <td>
                                <?= ticket_status_badge($t['status']) ?><br>
                                <span class="muted">PIC: <?= e($t['assigned_technician_name'] ?: '-') ?></span>
                            </td>
                            <td>
                                <?php if ($totalCost > 0): ?>
                                    <span style="font-weight:600;color:#0f766e;">Rp <?= number_format($totalCost, 0, ',', '.') ?></span><br>
                                    <small class="muted"><?= (int)$t['repair_count'] ?> tindakan</small>
                                <?php else: ?>
                                    <span class="muted">Rp 0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn" href="<?= route_url('ticket_detail', ['id' => $t['id']]) ?>">Detail & Eksekusi</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php
    render_footer();
}

function handle_route_ticket_form(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician', 'corrective_maintenance']);
    ensure_corrective_maintenance_schema($pdo);

    $preMntId = (int)($_GET['maintenance_asset_id'] ?? 0);
    $prePcId = trim((string)($_GET['pc_id'] ?? ''));

    if ($prePcId !== '' && $preMntId <= 0) {
        $stmt = $pdo->prepare("SELECT maintenance_asset_id FROM pcs WHERE pc_id = ?");
        $stmt->execute([$prePcId]);
        $preMntId = (int)($stmt->fetchColumn() ?: 0);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $code = generate_next_ticket_code($pdo);
        $maintAssetId = (int)($_POST['maintenance_asset_id'] ?? 0) ?: null;
        $reporterName = trim((string)($_POST['reporter_name'] ?? ''));
        $reporterNik = trim((string)($_POST['reporter_nik'] ?? ''));
        $reporterContact = trim((string)($_POST['reporter_contact'] ?? ''));
        $issueCat = trim((string)($_POST['issue_category'] ?? 'hardware'));
        $priority = trim((string)($_POST['priority'] ?? 'medium'));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $odometer = (int)($_POST['vehicle_odometer_km'] ?? 0) ?: null;

        if ($subject === '' || $reporterName === '') {
            flash('Nama pelapor dan subjek kendala wajib diisi.', 'err');
            redirect_to('ticket_form');
        }

        // Ambil data detail aset dari maintenance_assets
        $assetCat = 'IT';
        $assetItemId = null;
        $pcId = null;
        $companyId = null;
        $location = trim((string)($_POST['location_label'] ?? ''));

        if ($maintAssetId) {
            $mRow = get_maintenance_asset_unit($pdo, $maintAssetId);
            if ($mRow) {
                $assetItemId = $mRow['asset_item_id'];
                $pcId = $mRow['pc_id'] ?: null;
                $companyId = (int)($mRow['company_id'] ?? 0) ?: null;
                $assetCat = $mRow['asset_category'];
                if ($location === '' && !empty($mRow['location_label'])) {
                    $location = (string)$mRow['location_label'];
                }
            }
        }

        // Upload photo before jika ada
        $photoBefore = null;
        if (!empty($_FILES['photo_before']['tmp_name']) && is_uploaded_file($_FILES['photo_before']['tmp_name'])) {
            $uploadDir = __DIR__ . '/../../public/uploads/tickets';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $ext = strtolower(pathinfo((string)$_FILES['photo_before']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $filename = 'before_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['photo_before']['tmp_name'], $uploadDir . '/' . $filename)) {
                    $photoBefore = 'uploads/tickets/' . $filename;
                }
            }
        }

        $stmt = $pdo->prepare("INSERT INTO corrective_tickets (
            ticket_code, asset_category, asset_item_id, maintenance_asset_id, pc_id, company_id, 
            location_label, vehicle_odometer_km, reporter_name, reporter_nik, reporter_contact, 
            issue_category, priority, subject, description, photo_before, status, assigned_technician_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)");

        $stmt->execute([
            $code, $assetCat, $assetItemId, $maintAssetId, $pcId, $companyId,
            $location, $odometer, $reporterName, $reporterNik, $reporterContact,
            $issueCat, $priority, $subject, $desc, $photoBefore,
            ($user['role'] === 'technician' ? $user['name'] : null)
        ]);
        $ticketId = (int)$pdo->lastInsertId();

        flash("Tiket baru {$code} berhasil dibuat.");
        redirect_to('ticket_detail', ['id' => $ticketId]);
    }

    // Ambil seluruh daftar Maintenance Assets untuk dropdown cepat
    $hasGroups = db_table_exists($pdo, 'asset_groups');
    $maintList = $pdo->query("SELECT ma.id, ma.maintenance_asset_code, ma.name, ma.maintenance_type, ma.location_label,
                                     ma.owner_name AS custodian_name, ma.employee_nik AS custodian_nik" . 
                                     ($hasGroups ? ", ag.group_code, ag.group_name" : "") . "
                              FROM maintenance_assets ma
                              " . ($hasGroups ? "LEFT JOIN asset_groups ag ON ag.id = ma.asset_group_id" : "") . "
                              ORDER BY ma.maintenance_asset_code ASC")->fetchAll(PDO::FETCH_ASSOC);

    render_header('Buat Tiket Corrective Baru', $user);
    ?>
    <section class="panel">
        <h1>Buat Tiket Kendala / Reparasi Baru</h1>
        <p class="muted">Pilih <strong>No. Maintenance Asset</strong> yang tertera pada label fisik unit.</p>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <h2>1. Identitas Maintenance Asset</h2>
            <div class="grid two">
                <label>No. Maintenance Asset ID *
                    <select name="maintenance_asset_id" id="maintAssetSelect" required onchange="onMaintAssetChange()">
                        <option value="">-- Pilih No. Maintenance Asset (MNT-...) --</option>
                        <?php foreach ($maintList as $m): ?>
                            <?php
                            $sel = ($preMntId === (int)$m['id']) ? 'selected' : '';
                            $cat = 'IT';
                            $grp = strtoupper((string)($m['group_code'] ?? ''));
                            $grpName = strtoupper((string)($m['group_name'] ?? ''));
                            $mType = strtoupper((string)($m['maintenance_type'] ?? ''));
                            if ($grp === 'VH' || str_contains($grpName, 'VEHICLE') || str_contains($mType, 'VEHICLE')) {
                                $cat = 'Vehicle';
                            } elseif ($grp === 'FC' || str_contains($grpName, 'FACILITY') || str_contains($mType, 'FACILITY')) {
                                $cat = 'Facility';
                            }
                            $desc = $m['maintenance_asset_code'] . ' - ' . $m['name'] . ($m['custodian_name'] ? ' (' . $m['custodian_name'] . ')' : '');
                            ?>
                            <option value="<?= (int)$m['id'] ?>" data-cat="<?= e($cat) ?>" data-loc="<?= e($m['location_label'] ?? '') ?>" data-user="<?= e($m['custodian_name'] ?? '') ?>" data-nik="<?= e($m['custodian_nik'] ?? '') ?>" <?= $sel ?>>
                                <?= e($desc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Lokasi / Gedung / Ruangan
                    <input name="location_label" id="locInput" placeholder="Contoh: Gedung A Lantai 2 / Ruang IT">
                </label>
            </div>

            <div id="vehicleKmWrapper" style="display:none;" class="grid two">
                <label>Kilometer / Odometer Kendaraan (KM)
                    <input type="number" name="vehicle_odometer_km" placeholder="Contoh: 45200">
                </label>
            </div>

            <h2>2. Informasi Pelapor</h2>
            <div class="grid three">
                <label>Nama Pelapor *
                    <input name="reporter_name" id="reporterName" value="<?= e($user['name'] ?? '') ?>" required placeholder="Nama pemakai / PIC">
                </label>
                <label>NIK Pelapor
                    <input name="reporter_nik" id="reporterNik" placeholder="NIK karyawan">
                </label>
                <label>No. HP / WhatsApp Pelapor
                    <input name="reporter_contact" placeholder="0812xxxxxx">
                </label>
            </div>
            <?= function_exists('employee_portal_name_picker_html') ? employee_portal_name_picker_html('ticketReporter', 'reporterName', 'reporterNik') : '' ?>

            <h2>3. Rincian Masalah & Foto Bukti</h2>
            <div class="grid three">
                <label>Kategori Masalah
                    <select name="issue_category" id="ticketIssueCat">
                        <option value="hardware">Kerusakan Hardware / Komponen</option>
                        <option value="software">Masalah Software / OS / Aplikasi</option>
                        <option value="network">Jaringan / Koneksi Internet</option>
                        <option value="printer">Printer / Scanner Error</option>
                        <option value="engine">Mesin / Ban / Rem (Kendaraan)</option>
                        <option value="electrical">Kelistrikan / Aki / Lampu</option>
                        <option value="hvac">AC / Pendingin Ruangan</option>
                        <option value="plumbing">Pipa / Sanitasi Air</option>
                        <option value="general">Lain-lain / Kerusakan Fisik</option>
                    </select>
                </label>
                <label>Tingkat Prioritas *
                    <select name="priority" required>
                        <option value="low">Low (Bisa ditunda)</option>
                        <option value="medium" selected>Medium (Standard)</option>
                        <option value="high">High (Mendesak / Mengganggu Operasional)</option>
                        <option value="critical">CRITICAL (Operasional Terhenti Total)</option>
                    </select>
                </label>
                <label>Foto Kerusakan / Bukti Error
                    <input type="file" name="photo_before" accept="image/*" capture="environment">
                </label>
            </div>

            <label>Judul / Subjek Kendala *
                <input name="subject" required placeholder="Contoh: Layar bluescreen tidak bisa masuk Windows / Ban mobil bocor / AC tidak dingin">
            </label>

            <label>Deskripsi Lengkap Gejala Kerusakan
                <textarea name="description" placeholder="Jelaskan kronologi, pesan error, atau kondisi fisik saat terjadi masalah..."></textarea>
            </label>

            <div class="actions" style="margin-top:20px;">
                <a class="btn" href="<?= route_url('tickets') ?>">Batal</a>
                <button class="btn primary">Simpan & Terbitkan Tiket</button>
            </div>
        </form>
    </section>

    <script>
    function onMaintAssetChange() {
        var sel = document.getElementById('maintAssetSelect');
        var opt = sel.options[sel.selectedIndex];
        var cat = opt ? opt.getAttribute('data-cat') : '';
        var loc = opt ? opt.getAttribute('data-loc') : '';
        var kmWrap = document.getElementById('vehicleKmWrapper');
        var locInput = document.getElementById('locInput');

        if (loc && !locInput.value) {
            locInput.value = loc;
        }
        if (cat === 'Vehicle') {
            kmWrap.style.display = 'block';
        } else {
            kmWrap.style.display = 'none';
        }
    }
    onMaintAssetChange();
    </script>
    <?php
    render_footer();
}

function handle_route_ticket_detail(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician', 'corrective_maintenance']);
    ensure_corrective_maintenance_schema($pdo);

    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT t.*, ma.maintenance_asset_code, ma.name AS maintenance_asset_name, 
                                  ma.asset_group_id AS ma_group_id, ma.asset_type_id AS ma_type_id,
                                  ai.asset_group_id AS ai_group_id, ai.asset_type_id AS ai_type_id,
                                  c.company_name
                           FROM corrective_tickets t
                           LEFT JOIN maintenance_assets ma ON ma.id = t.maintenance_asset_id
                           LEFT JOIN asset_items ai ON ai.id = t.asset_item_id
                           LEFT JOIN asset_companies c ON c.id = t.company_id
                           WHERE t.id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        flash('Tiket tidak ditemukan.', 'err');
        redirect_to('tickets');
    }

    $unit = null;
    if (!empty($ticket['maintenance_asset_id'])) {
        $unit = get_maintenance_asset_unit($pdo, (int)$ticket['maintenance_asset_id']);
    }
    $ticketGroupId = (int)($unit['asset_group_id'] ?? ($ticket['ma_group_id'] ?: ($ticket['ai_group_id'] ?? 0)));
    $ticketTypeId = (int)($unit['asset_type_id'] ?? ($ticket['ma_type_id'] ?: ($ticket['ai_type_id'] ?? 0)));

    // Ambil riwayat tindakan reparasi
    $repairsStmt = $pdo->prepare("SELECT cr.* FROM corrective_repairs cr WHERE cr.ticket_id = ? ORDER BY cr.repaired_at DESC, cr.id DESC");
    $repairsStmt->execute([$id]);
    $repairs = $repairsStmt->fetchAll(PDO::FETCH_ASSOC);

    $repairIds = array_column($repairs, 'id');
    $partsByRepair = [];
    if ($repairIds) {
        $inRepairs = implode(',', array_map('intval', $repairIds));
        $partsStmt = $pdo->query("SELECT * FROM corrective_repair_parts WHERE repair_id IN ($inRepairs) ORDER BY id ASC");
        foreach ($partsStmt->fetchAll(PDO::FETCH_ASSOC) as $part) {
            $partsByRepair[(int)$part['repair_id']][] = $part;
        }
    }

    render_header('Detail Tiket #' . $ticket['ticket_code'], $user);
    ?>
    <section class="panel">
        <div class="split">
            <div>
                <h1>Tiket #<?= e($ticket['ticket_code']) ?></h1>
                <p class="muted">Dibuat pada <?= e(date('d M Y H:i:s', strtotime($ticket['created_at']))) ?> oleh <strong><?= e($ticket['reporter_name']) ?></strong></p>
            </div>
            <div class="actions">
                <a class="btn" href="<?= route_url('tickets') ?>">← Kembali ke Daftar</a>
            </div>
        </div>

        <div class="grid two" style="margin-top:16px;">
            <div class="mini-card" style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0">
                <h3 style="margin-top:0;">Informasi Tiket & Maintenance Asset</h3>
                <table>
                    <tr><th style="width:160px;">No. Maintenance Asset</th><td><strong style="color:#0284c7;"><?= e($ticket['maintenance_asset_code'] ?: ($ticket['pc_id'] ? 'PC ' . $ticket['pc_id'] : '-')) ?></strong></td></tr>
                    <tr><th>Nama Unit</th><td><?= e($ticket['maintenance_asset_name'] ?: '-') ?></td></tr>
                    <tr><th>Kategori Aset</th><td><?= ticket_category_badge($ticket['asset_category']) ?></td></tr>
                    <tr><th>Prioritas</th><td><?= ticket_priority_badge($ticket['priority']) ?></td></tr>
                    <tr><th>Status</th><td><?= ticket_status_badge($ticket['status']) ?></td></tr>
                    <?php if ($ticket['vehicle_odometer_km']): ?>
                        <tr><th>KM Kendaraan</th><td><strong><?= number_format((int)$ticket['vehicle_odometer_km'], 0, ',', '.') ?> KM</strong></td></tr>
                    <?php endif; ?>
                    <tr><th>Company / Lokasi</th><td><?= e($ticket['company_name'] ?: '-') ?> / <?= e($ticket['location_label'] ?: '-') ?></td></tr>
                    <tr><th>Pelapor</th><td><?= e($ticket['reporter_name']) ?> <?= $ticket['reporter_nik'] ? '(' . e($ticket['reporter_nik']) . ')' : '' ?> <?= $ticket['reporter_contact'] ? ' - Telp: ' . e($ticket['reporter_contact']) : '' ?></td></tr>
                    <tr><th>Teknisi PIC</th><td><strong><?= e($ticket['assigned_technician_name'] ?: 'Belum ditentukan') ?></strong></td></tr>
                </table>
            </div>

            <div class="mini-card" style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0">
                <h3 style="margin-top:0;">Detail Keluhan & Foto</h3>
                <p><strong>Subjek:</strong> <?= e($ticket['subject']) ?></p>
                <p><strong>Deskripsi Masalah:</strong><br><?= nl2br(e($ticket['description'] ?: '-')) ?></p>
                <?php if ($ticket['photo_before']): ?>
                    <p><strong>Foto Kondisi Awal:</strong></p>
                    <a href="<?= e($ticket['photo_before']) ?>" target="_blank">
                        <img src="<?= e($ticket['photo_before']) ?>" style="max-width:240px;max-height:160px;border-radius:6px;border:1px solid #cbd5e1;display:block;">
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Form Eksekusi Teknisi -->
    <?php if ($ticket['status'] !== 'closed' && in_array($user['role'], ['admin', 'maintenance_admin', 'technician', 'corrective_maintenance'], true)): ?>
        <section class="panel">
            <h2>Input Tindakan Perbaikan & Penggantian Sparepart</h2>
            <form method="post" action="<?= route_url('ticket_action') ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">

                <div class="grid three">
                    <label>Ubah Status Tiket
                        <select name="update_status" required>
                            <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress (Sedang Dikerjakan)</option>
                            <option value="pending_part" <?= $ticket['status'] === 'pending_part' ? 'selected' : '' ?>>Waiting Part (Menunggu Sparepart)</option>
                            <option value="resolved" <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>Resolved (Perbaikan Selesai)</option>
                            <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Closed (Tutup Tiket)</option>
                        </select>
                    </label>
                    <label>Teknisi Pelaksana *
                        <input name="technician_name" value="<?= e($user['name']) ?>" required>
                    </label>
                    <label>Jenis Tindakan (Job Desk Corrective) *
                        <select name="action_type" required>
                            <?= corrective_action_type_options($pdo, null, $ticketGroupId, $ticketTypeId) ?>
                        </select>
                    </label>
                </div>

                <div class="grid two">
                    <label>Analisa Akar Penyebab Kerusakan (*Root Cause*)
                        <textarea name="root_cause_analysis" style="min-height:80px;" placeholder="Penyebab kerusakan: misal kapasitor short, bad sector pada SSD, ban robek sisi samping..."></textarea>
                    </label>
                    <label>Tindakan / Solusi yang Dilakukan *
                        <textarea name="solution_details" style="min-height:80px;" required placeholder="Langkah perbaikan yang dilakukan secara detail..."></textarea>
                    </label>
                </div>

                <h3>Vendor / Bengkel Luar & Biaya Jasa (Opsional)</h3>
                <div class="grid three">
                    <label>Nama Vendor / Bengkel
                        <input name="vendor_name" placeholder="Nama bengkel rekanan / vendor">
                    </label>
                    <label>No. Invoice / Kwitansi
                        <input name="vendor_invoice_no" placeholder="INV-xxxxxx">
                    </label>
                    <label>Biaya Jasa Servis (Rp)
                        <input type="number" name="repair_cost" value="0" placeholder="0">
                    </label>
                </div>

                <h3>Penggantian Sparepart (Jika Ada Part Baru Dipasang)</h3>
                <div id="sparepartContainer">
                    <div class="grid four" style="background:#f1f5f9;padding:12px;border-radius:6px;margin-bottom:8px;">
                        <label>Nama Part Lama yang Dilepas
                            <input name="parts[0][old_name]" placeholder="Misal: RAM 4GB DDR4 / Aki GS 45A">
                        </label>
                        <label>Nama Part Baru yang Dipasang
                            <input name="parts[0][new_name]" placeholder="Misal: RAM 8GB Kingston / Aki Yuasa 45A">
                        </label>
                        <label>Serial Number Part Baru
                            <input name="parts[0][new_serial]" placeholder="SN part baru">
                        </label>
                        <label>Harga Part Baru (Rp)
                            <input type="number" name="parts[0][cost]" value="0">
                        </label>
                    </div>
                </div>

                <label>Foto Bukti Selesai Perbaikan
                    <input type="file" name="photo_after" accept="image/*" capture="environment">
                </label>

                <div class="actions" style="margin-top:16px;">
                    <button class="btn primary">Simpan Tindakan Perbaikan</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <!-- Riwayat Tindakan Reparasi yang Pernah Dicatat -->
    <section class="panel">
        <h2>Riwayat Tindakan & Penggantian Part pada Tiket Ini</h2>
        <?php if (!$repairs): ?>
            <p class="muted">Belum ada tindakan perbaikan yang dicatat.</p>
        <?php else: ?>
            <?php foreach ($repairs as $r): ?>
                <div style="border-left:4px solid #0f766e;padding:12px 16px;background:#f8fafc;margin-bottom:16px;border-radius:0 8px 8px 0;border-top:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                    <div class="split">
                        <div>
                            <strong><?= e(date('d M Y H:i', strtotime($r['repaired_at']))) ?></strong> oleh <strong><?= e($r['technician_name']) ?></strong>
                            <span class="badge ok" style="margin-left:8px;"><?= e($r['action_type']) ?></span>
                        </div>
                        <?php if ((float)$r['repair_cost'] > 0): ?>
                            <span style="font-weight:700;color:#0f766e;">Jasa: Rp <?= number_format((float)$r['repair_cost'], 0, ',', '.') ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($r['root_cause_analysis']): ?>
                        <p style="margin:8px 0 4px;"><strong>Penyebab:</strong> <?= nl2br(e($r['root_cause_analysis'])) ?></p>
                    <?php endif; ?>
                    <p style="margin:4px 0;"><strong>Solusi:</strong> <?= nl2br(e($r['solution_details'])) ?></p>

                    <?php if (!empty($partsByRepair[(int)$r['id']])): ?>
                        <div style="margin-top:10px;background:#fff;padding:8px 12px;border-radius:6px;border:1px solid #e2e8f0;">
                            <strong>Sparepart yang Diganti:</strong>
                            <ul style="margin:4px 0 0 18px;padding:0;">
                                <?php foreach ($partsByRepair[(int)$r['id']] as $pRow): ?>
                                    <li>
                                        <strong><?= e($pRow['new_part_name']) ?></strong> (SN: <?= e($pRow['new_part_serial'] ?: '-') ?>)
                                        <?php if ($pRow['old_part_name']): ?>
                                            - Menggantikan: <em><?= e($pRow['old_part_name']) ?></em>
                                        <?php endif; ?>
                                        <?php if ((float)$pRow['part_cost'] > 0): ?>
                                            - Biaya: <strong>Rp <?= number_format((float)$pRow['part_cost'], 0, ',', '.') ?></strong>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($r['photo_after']): ?>
                        <div style="margin-top:8px;">
                            <a href="<?= e($r['photo_after']) ?>" target="_blank">
                                <img src="<?= e($r['photo_after']) ?>" style="max-height:100px;border-radius:4px;border:1px solid #cbd5e1;">
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}

function handle_route_ticket_action(PDO $pdo): void
{
    $user = require_role(['admin', 'corrective_maintenance', 'technician']);
    verify_csrf();
    ensure_corrective_maintenance_schema($pdo);

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $status = trim((string)($_POST['update_status'] ?? 'in_progress'));
    $techName = trim((string)($_POST['technician_name'] ?? $user['name']));
    $actionType = trim((string)($_POST['action_type'] ?? 'hardware_repair'));
    $rootCause = trim((string)($_POST['root_cause_analysis'] ?? ''));
    $solution = trim((string)($_POST['solution_details'] ?? ''));
    $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
    $vendorInv = trim((string)($_POST['vendor_invoice_no'] ?? ''));
    $repairCost = (float)($_POST['repair_cost'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM corrective_tickets WHERE id = ?");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        flash('Tiket tidak ditemukan.', 'err');
        redirect_to('tickets');
    }

    // Upload photo after jika ada
    $photoAfter = null;
    if (!empty($_FILES['photo_after']['tmp_name']) && is_uploaded_file($_FILES['photo_after']['tmp_name'])) {
        $uploadDir = __DIR__ . '/../../public/uploads/tickets';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        $ext = strtolower(pathinfo((string)$_FILES['photo_after']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $filename = 'after_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo_after']['tmp_name'], $uploadDir . '/' . $filename)) {
                $photoAfter = 'uploads/tickets/' . $filename;
            }
        }
    }

    if ($solution !== '') {
        $repStmt = $pdo->prepare("INSERT INTO corrective_repairs (
            ticket_id, technician_name, action_type, root_cause_analysis, solution_details, 
            vendor_name, vendor_invoice_no, repair_cost, photo_after
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $repStmt->execute([
            $ticketId, $techName, $actionType, $rootCause, $solution,
            $vendorName ?: null, $vendorInv ?: null, $repairCost, $photoAfter
        ]);
        $repairId = (int)$pdo->lastInsertId();

        // Simpan part replacements
        $parts = $_POST['parts'] ?? [];
        if (is_array($parts)) {
            $partInsert = $pdo->prepare("INSERT INTO corrective_repair_parts (
                repair_id, old_part_name, old_part_serial, new_part_name, new_part_serial, part_cost
            ) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($parts as $pRow) {
                $newName = trim((string)($pRow['new_name'] ?? ''));
                if ($newName !== '') {
                    $oldName = trim((string)($pRow['old_name'] ?? ''));
                    $newSerial = trim((string)($pRow['new_serial'] ?? ''));
                    $cost = (float)($pRow['cost'] ?? 0);
                    $partInsert->execute([$repairId, $oldName ?: null, null, $newName, $newSerial ?: null, $cost]);
                }
            }
        }
    }

    // Update status tiket
    $resolvedAt = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : $ticket['resolved_at'];
    $closedAt = ($status === 'closed') ? date('Y-m-d H:i:s') : $ticket['closed_at'];

    $pdo->prepare("UPDATE corrective_tickets SET status = ?, assigned_technician_name = ?, resolved_at = ?, closed_at = ? WHERE id = ?")
        ->execute([$status, $techName, $resolvedAt, $closedAt, $ticketId]);

    flash('Tindakan perbaikan berhasil disimpan.');
    redirect_to('ticket_detail', ['id' => $ticketId]);
}

function handle_route_corrective_repairs(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'technician', 'corrective_maintenance']);
    ensure_corrective_maintenance_schema($pdo);

    $sql = "SELECT cr.*, t.ticket_code, t.subject, t.asset_category, t.location_label,
                   ma.maintenance_asset_code, ma.name AS maintenance_asset_name
            FROM corrective_repairs cr
            JOIN corrective_tickets t ON t.id = cr.ticket_id
            LEFT JOIN maintenance_assets ma ON ma.id = t.maintenance_asset_id
            ORDER BY cr.repaired_at DESC, cr.id DESC";
    $repairs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    render_header('Riwayat Reparasi & Sparepart', $user);
    ?>
    <section class="panel">
        <div class="split">
            <div>
                <h1>Riwayat Reparasi & Penggantian Sparepart</h1>
                <p class="muted">Daftar seluruh tindakan corrective maintenance dan sparepart yang pernah diganti pada unit.</p>
            </div>
            <div class="actions">
                <a class="btn primary" href="<?= route_url('tickets') ?>">Daftar Tiket Aktif</a>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Tanggal & Tiket</th>
                    <th>Maintenance Asset</th>
                    <th>Teknisi / Vendor</th>
                    <th>Tindakan & Solusi</th>
                    <th>Sparepart Diganti</th>
                    <th>Biaya Jasa Servis</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$repairs): ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Belum ada riwayat perbaikan.</td></tr>
                <?php else: ?>
                    <?php foreach ($repairs as $r): ?>
                        <?php
                        $pStmt = $pdo->prepare("SELECT * FROM corrective_repair_parts WHERE repair_id = ?");
                        $pStmt->execute([(int)$r['id']]);
                        $parts = $pStmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e(date('d M Y H:i', strtotime($r['repaired_at']))) ?></strong><br>
                                <a href="<?= route_url('ticket_detail', ['id' => $r['ticket_id']]) ?>" style="color:#0284c7;font-weight:600;"><?= e($r['ticket_code']) ?></a>
                            </td>
                            <td>
                                <?= ticket_category_badge($r['asset_category']) ?><br>
                                <strong><?= e($r['maintenance_asset_code'] ?: '-') ?></strong><br>
                                <small class="muted"><?= e(mb_strimwidth((string)$r['maintenance_asset_name'], 0, 35, '...')) ?></small>
                            </td>
                            <td>
                                <strong><?= e($r['technician_name']) ?></strong><br>
                                <?= $r['vendor_name'] ? '<span class="muted">Vendor: ' . e($r['vendor_name']) . '</span>' : '' ?>
                            </td>
                            <td>
                                <span class="badge ok"><?= e($r['action_type']) ?></span><br>
                                <?= e(mb_strimwidth((string)$r['solution_details'], 0, 90, '...')) ?>
                            </td>
                            <td>
                                <?php if (!$parts): ?>
                                    <span class="muted">-</span>
                                <?php else: ?>
                                    <?php foreach ($parts as $p): ?>
                                        • <strong><?= e($p['new_part_name']) ?></strong> (Rp <?= number_format((float)$p['part_cost'], 0, ',', '.') ?>)<br>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>Rp <?= number_format((float)$r['repair_cost'], 0, ',', '.') ?></strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php
    render_footer();
}

function handle_route_walkarounds(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'corrective_maintenance']);
    ensure_corrective_maintenance_schema($pdo);

    $sql = "SELECT w.*, ma.maintenance_asset_code, ma.name AS maintenance_asset_name, t.ticket_code
            FROM asset_walkarounds w
            LEFT JOIN maintenance_assets ma ON ma.id = w.maintenance_asset_id
            LEFT JOIN corrective_tickets t ON t.id = w.ticket_id
            ORDER BY w.inspection_date DESC, w.id DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    render_header('Patroli & Walkaround Aset', $user);
    ?>
    <section class="panel">
        <div class="split">
            <div>
                <h1>Patroli & Walkaround Aset (Quick Inspection)</h1>
                <p class="muted">Pengecekan fisik cepat unit di lapangan. Jika ditemukan kerusakan, sistem otomatis menerbitkan tiket perbaikan.</p>
            </div>
            <div class="actions">
                <a class="btn primary" href="<?= route_url('walkaround_form') ?>">+ Mulai Walkaround Baru</a>
                <a class="btn" href="<?= route_url('tickets') ?>">Daftar Tiket</a>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Kode & Tanggal</th>
                    <th>Inspektur</th>
                    <th>Maintenance Asset</th>
                    <th>Kondisi Fisik</th>
                    <th>Catatan Temuan</th>
                    <th>Tiket Masalah</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Belum ada log walkaround.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $w): ?>
                        <tr>
                            <td>
                                <strong><?= e($w['walkaround_code']) ?></strong><br>
                                <span class="muted"><?= e($w['inspection_date']) ?></span>
                            </td>
                            <td><?= e($w['inspector_name']) ?></td>
                            <td>
                                <strong><?= e($w['maintenance_asset_code'] ?: '-') ?></strong><br>
                                <small class="muted"><?= e(mb_strimwidth((string)$w['maintenance_asset_name'], 0, 35, '...')) ?></small>
                            </td>
                            <td>
                                <?php if ($w['overall_condition'] === 'good'): ?>
                                    <span class="badge ok">✓ Normal / Bagus</span>
                                <?php elseif ($w['overall_condition'] === 'need_attention'): ?>
                                    <span class="badge" style="background:#fef3c7;color:#92400e">⚠️ Perlu Perhatian</span>
                                <?php else: ?>
                                    <span class="badge danger">✕ Rusak / Kendala</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($w['notes'] ?: '-') ?></td>
                            <td>
                                <?php if ($w['ticket_code']): ?>
                                    <a href="<?= route_url('ticket_detail', ['id' => $w['ticket_id']]) ?>" class="btn danger" style="padding:4px 8px;font-size:12px;">Buka Tiket <?= e($w['ticket_code']) ?></a>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php
    render_footer();
}

function handle_route_walkaround_form(PDO $pdo): void
{
    $user = require_role(['admin', 'maintenance_admin', 'corrective_maintenance']);
    ensure_corrective_maintenance_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $code = generate_next_walkaround_code($pdo);
        $maintAssetId = (int)($_POST['maintenance_asset_id'] ?? 0) ?: null;
        $inspectorName = trim((string)($_POST['inspector_name'] ?? $user['name']));
        $inspectorNik = trim((string)($_POST['inspector_nik'] ?? ''));
        $date = normalize_date_input((string)($_POST['inspection_date'] ?? date('Y-m-d')));
        $loc = trim((string)($_POST['location_label'] ?? ''));
        $odometer = (int)($_POST['odometer_km'] ?? 0) ?: null;
        $condition = trim((string)($_POST['overall_condition'] ?? 'good'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $ticketId = null;
        if ($condition !== 'good' && $notes !== '') {
            $tktCode = generate_next_ticket_code($pdo);
            $cat = 'IT';
            $assetItemId = null;
            $pcId = null;

            if ($maintAssetId) {
                $mRow = get_maintenance_asset_unit($pdo, $maintAssetId);
                if ($mRow) {
                    $assetItemId = $mRow['asset_item_id'];
                    $pcId = $mRow['pc_id'] ?: null;
                    $cat = $mRow['asset_category'];
                }
            }

            $stmt = $pdo->prepare("INSERT INTO corrective_tickets (
                ticket_code, asset_category, asset_item_id, maintenance_asset_id, pc_id, location_label, vehicle_odometer_km, 
                reporter_name, reporter_nik, subject, description, priority, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'high', 'open')");
            $stmt->execute([
                $tktCode, $cat, $assetItemId, $maintAssetId, $pcId, $loc, $odometer,
                $inspectorName, $inspectorNik, "Temuan Walkaround: " . mb_strimwidth($notes, 0, 50, '...'),
                $notes
            ]);
            $ticketId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("INSERT INTO asset_walkarounds (
            walkaround_code, maintenance_asset_id, inspector_name, inspector_nik, 
            inspection_date, location_label, odometer_km, overall_condition, notes, ticket_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $code, $maintAssetId, $inspectorName, $inspectorNik,
            $date, $loc, $odometer, $condition, $notes, $ticketId
        ]);

        flash('Log patroli / walkaround berhasil disimpan.' . ($ticketId ? " Tiket kerusakan otomatis diterbitkan (#$tktCode)." : ''));
        redirect_to('walkarounds');
    }

    $maintList = $pdo->query("SELECT ma.id, ma.maintenance_asset_code, ma.name, ma.location_label FROM maintenance_assets ma ORDER BY ma.maintenance_asset_code ASC")->fetchAll(PDO::FETCH_ASSOC);

    render_header('Input Walkaround / Patroli Aset', $user);
    ?>
    <section class="panel">
        <h1>Input Patroli & Walkaround Aset</h1>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <div class="grid three">
                <label>No. Maintenance Asset
                    <select name="maintenance_asset_id">
                        <option value="">-- Pilih Maintenance Asset --</option>
                        <?php foreach ($maintList as $m): ?>
                            <option value="<?= (int)$m['id'] ?>">
                                <?= e($m['maintenance_asset_code'] . ' - ' . $m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Tanggal Pemeriksaan
                    <input type="date" name="inspection_date" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>Nama Petugas / Inspektur *
                    <input name="inspector_name" value="<?= e($user['name']) ?>" required>
                </label>
            </div>

            <div class="grid two">
                <label>Lokasi / Ruangan
                    <input name="location_label" placeholder="Gedung / Lantai / Ruang">
                </label>
                <label>KM Kendaraan (Jika memeriksa Kendaraan)
                    <input type="number" name="odometer_km" placeholder="Contoh: 12500">
                </label>
            </div>

            <h2>Kondisi Fisik & Temuan</h2>
            <div class="grid two">
                <label>Status Kondisi Fisik *
                    <select name="overall_condition" required>
                        <option value="good">✓ Normal / Berfungsi Baik</option>
                        <option value="need_attention">⚠️ Perlu Perhatian / Pembersihan / Pengecekan Ulang</option>
                        <option value="damaged">✕ Rusak / Tidak Berfungsi (Otomatis Buat Tiket)</option>
                    </select>
                </label>
                <label>Catatan Temuan
                    <input name="notes" placeholder="Catatan kondisi fisik atau kerusakan jika ada...">
                </label>
            </div>

            <div class="actions" style="margin-top:16px;">
                <a class="btn" href="<?= route_url('walkarounds') ?>">Batal</a>
                <button class="btn primary">Simpan Hasil Patroli</button>
            </div>
        </form>
    </section>
    <?php
    render_footer();
}

// -----------------------------------------------------------------------------
// MASTER DATA: JOB DESK CORRECTIVE MAINTENANCE & JENIS TINDAKAN
// -----------------------------------------------------------------------------

function handle_route_corrective_action_types(PDO $pdo): void
{
    handle_route_corrective_job_desks($pdo);
}

function handle_route_corrective_job_desks(PDO $pdo): void
{
    $user = require_role(['admin']);
    ensure_corrective_maintenance_schema($pdo);

    $editDeskId = (int)($_GET['edit_id'] ?? 0);
    $editDeskName = '';
    $editGroupId = 0;
    $editTypeId = 0;
    $editDesc = '';
    $isEditing = false;

    if ($editDeskId > 0) {
        $stmtEdit = $pdo->prepare('SELECT * FROM corrective_job_desks WHERE id = ?');
        $stmtEdit->execute([$editDeskId]);
        $editRow = $stmtEdit->fetch(PDO::FETCH_ASSOC);
        if ($editRow) {
            $isEditing = true;
            $editDeskName = (string)$editRow['job_desk_name'];
            $editGroupId = (int)($editRow['asset_group_id'] ?? 0);
            $editTypeId = (int)($editRow['asset_type_id'] ?? 0);
            $editDesc = (string)($editRow['description'] ?? '');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = trim((string)($_POST['action'] ?? ''));

        // --- TAMBAH JOB DESK CORRECTIVE MAINTENANCE ---
        if ($action === 'add_desk') {
            $groupId = (int)($_POST['asset_group_id'] ?? 0);
            $typeId = (int)($_POST['asset_type_id'] ?? 0);

            $groupName = '';
            if ($groupId > 0) {
                $gStmt = $pdo->prepare('SELECT group_name FROM asset_groups WHERE id=?');
                $gStmt->execute([$groupId]);
                $groupName = (string)$gStmt->fetchColumn();
            }
            $typeName = '';
            if ($typeId > 0) {
                $tStmt = $pdo->prepare('SELECT type_name FROM asset_types WHERE id=?');
                $tStmt->execute([$typeId]);
                $typeName = (string)$tStmt->fetchColumn();
            }

            $deskName = trim((string)($_POST['job_desk_name'] ?? ''));
            if ($deskName === '') {
                $deskName = 'Job Desk Corrective ' . trim($groupName . ' ' . $typeName);
            }
            if ($deskName === 'Job Desk Corrective' || $deskName === '') {
                $deskName = 'Job Desk Corrective Umum';
            }

            $desc = trim((string)($_POST['description'] ?? ''));

            // Periksa duplikasi nama
            $chk = $pdo->prepare('SELECT id FROM corrective_job_desks WHERE job_desk_name = ? LIMIT 1');
            $chk->execute([$deskName]);
            $existId = $chk->fetchColumn();
            if ($existId) {
                flash("Job Desk Corrective dengan nama '{$deskName}' sudah ada (ID: #{$existId}).", 'err');
                redirect_to('corrective_job_desks', ['manage_desk_id' => $existId]);
            }

            $stmtIns = $pdo->prepare('INSERT INTO corrective_job_desks (job_desk_name, asset_group_id, asset_type_id, description) VALUES (?, ?, ?, ?)');
            $stmtIns->execute([
                $deskName,
                $groupId > 0 ? $groupId : null,
                $typeId > 0 ? $typeId : null,
                $desc !== '' ? $desc : null,
            ]);
            $newDeskId = (int)$pdo->lastInsertId();

            flash("Job Desk Corrective Maintenance '{$deskName}' (ID: #{$newDeskId}) berhasil dibuat. Silakan isi daftar tindakan / perbaikan di bawah.");
            redirect_to('corrective_job_desks', ['manage_desk_id' => $newDeskId]);
        }

        // --- EDIT JOB DESK CORRECTIVE MAINTENANCE ---
        if ($action === 'edit_desk') {
            $deskId = (int)($_POST['desk_id'] ?? 0);
            $origDesk = trim((string)($_POST['original_desk_name'] ?? ''));
            $newDesk = trim((string)($_POST['job_desk_name'] ?? ''));
            if ($newDesk === '') {
                $newDesk = $origDesk;
            }
            $groupId = (int)($_POST['asset_group_id'] ?? 0);
            $typeId = (int)($_POST['asset_type_id'] ?? 0);
            $desc = trim((string)($_POST['description'] ?? ''));

            if ($deskId > 0) {
                $updDesk = $pdo->prepare('UPDATE corrective_job_desks SET job_desk_name=?, asset_group_id=?, asset_type_id=?, description=? WHERE id=?');
                $updDesk->execute([
                    $newDesk,
                    $groupId > 0 ? $groupId : null,
                    $typeId > 0 ? $typeId : null,
                    $desc !== '' ? $desc : null,
                    $deskId,
                ]);

                // Sinkronkan ke corrective_action_types
                $updActions = $pdo->prepare('UPDATE corrective_action_types SET job_desk_name=?, asset_group_id=?, asset_type_id=? WHERE job_desk_name = ?');
                $updActions->execute([
                    $newDesk,
                    $groupId > 0 ? $groupId : null,
                    $typeId > 0 ? $typeId : null,
                    $origDesk,
                ]);

                flash("Job Desk Corrective Maintenance '{$newDesk}' berhasil diperbarui.");
            }
            redirect_to('corrective_job_desks', ['manage_desk_id' => $deskId]);
        }

        // --- HAPUS JOB DESK CORRECTIVE MAINTENANCE ---
        if ($action === 'delete_desk') {
            $deskId = (int)($_POST['desk_id'] ?? 0);
            $deskName = trim((string)($_POST['job_desk_name'] ?? ''));

            if ($deskId > 0 || $deskName !== '') {
                if ($deskName === '' && $deskId > 0) {
                    $stmtGet = $pdo->prepare('SELECT job_desk_name FROM corrective_job_desks WHERE id=?');
                    $stmtGet->execute([$deskId]);
                    $deskName = (string)$stmtGet->fetchColumn();
                }

                // Hapus tindakan yang belum pernah dipakai
                $pdo->prepare('DELETE FROM corrective_action_types WHERE job_desk_name = ? AND action_code NOT IN (SELECT DISTINCT action_type FROM corrective_repairs)')
                    ->execute([$deskName]);

                // Lepaskan job_desk_name dari tindakan yang sudah pernah dipakai agar riwayat masa lalu aman
                $pdo->prepare('UPDATE corrective_action_types SET job_desk_name = NULL, is_active = 0 WHERE job_desk_name = ?')
                    ->execute([$deskName]);

                if ($deskId > 0) {
                    $pdo->prepare('DELETE FROM corrective_job_desks WHERE id=?')->execute([$deskId]);
                }
                if ($deskName !== '') {
                    $pdo->prepare('DELETE FROM corrective_job_desks WHERE job_desk_name=?')->execute([$deskName]);
                }

                flash("Job Desk Corrective '{$deskName}' berhasil dihapus.");
            }
            redirect_to('corrective_job_desks');
        }

        // --- TAMBAH TINDAKAN / ACTION KE JOB DESK ---
        if ($action === 'add_action') {
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            $stmtDesk = $pdo->prepare('SELECT * FROM corrective_job_desks WHERE id=?');
            $stmtDesk->execute([$manageDeskId]);
            $curDesk = $stmtDesk->fetch(PDO::FETCH_ASSOC);

            if ($curDesk) {
                $actionName = trim((string)($_POST['action_name'] ?? ''));
                $actionCode = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($_POST['action_code'] ?? ''))));
                if ($actionCode === '') {
                    $actionCode = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_]/', '_', $actionName)));
                }
                // Unikkan kode jika sudah ada
                $chkCode = $pdo->prepare('SELECT COUNT(*) FROM corrective_action_types WHERE action_code = ?');
                $chkCode->execute([$actionCode]);
                if ((int)$chkCode->fetchColumn() > 0) {
                    $actionCode .= '_' . substr(md5(uniqid('', true)), 0, 4);
                }

                $estMinutes = max(1, (int)($_POST['estimated_minutes'] ?? 15));
                $sortOrder = (int)($_POST['sort_order'] ?? 10);
                $desc = trim((string)($_POST['description'] ?? ''));

                if ($actionName !== '') {
                    $stmtInsAction = $pdo->prepare('INSERT INTO corrective_action_types (action_code, action_name, job_desk_name, asset_group_id, asset_type_id, estimated_minutes, sort_order, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
                    $stmtInsAction->execute([
                        $actionCode,
                        $actionName,
                        $curDesk['job_desk_name'],
                        $curDesk['asset_group_id'] ?: null,
                        $curDesk['asset_type_id'] ?: null,
                        $estMinutes,
                        $sortOrder,
                        $desc !== '' ? $desc : null,
                    ]);
                    flash("Tindakan '{$actionName}' berhasil ditambahkan ke {$curDesk['job_desk_name']}.");
                } else {
                    flash('Nama tindakan perbaikan wajib diisi.', 'err');
                }
            }
            redirect_to('corrective_job_desks', ['manage_desk_id' => $manageDeskId]);
        }

        // --- UPDATE TINDAKAN / ACTION ---
        if ($action === 'update_action') {
            $actionId = (int)($_POST['action_id'] ?? 0);
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            $actionName = trim((string)($_POST['action_name'] ?? ''));
            $estMinutes = max(1, (int)($_POST['estimated_minutes'] ?? 15));
            $sortOrder = (int)($_POST['sort_order'] ?? 10);
            $desc = trim((string)($_POST['description'] ?? ''));

            if ($actionId > 0 && $actionName !== '') {
                $pdo->prepare('UPDATE corrective_action_types SET action_name=?, estimated_minutes=?, sort_order=?, description=? WHERE id=?')
                    ->execute([$actionName, $estMinutes, $sortOrder, $desc !== '' ? $desc : null, $actionId]);
                flash('Tindakan perbaikan berhasil diperbarui.');
            }
            redirect_to('corrective_job_desks', ['manage_desk_id' => $manageDeskId]);
        }

        // --- TOGGLE STATUS AKTIF TINDAKAN ---
        if ($action === 'toggle_action') {
            $actionId = (int)($_POST['id'] ?? 0);
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            if ($actionId > 0) {
                $pdo->prepare('UPDATE corrective_action_types SET is_active = 1 - is_active WHERE id = ?')->execute([$actionId]);
                flash('Status tindakan berhasil diubah.');
            }
            redirect_to('corrective_job_desks', ['manage_desk_id' => $manageDeskId]);
        }

        // --- HAPUS TINDAKAN / ACTION ---
        if ($action === 'delete_action') {
            $actionId = (int)($_POST['id'] ?? 0);
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            if ($actionId > 0) {
                $stmtGet = $pdo->prepare('SELECT action_code FROM corrective_action_types WHERE id = ?');
                $stmtGet->execute([$actionId]);
                $code = (string)$stmtGet->fetchColumn();

                $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM corrective_repairs WHERE action_type = ?');
                $checkStmt->execute([$code]);
                $usedCount = (int)$checkStmt->fetchColumn();

                if ($usedCount > 0) {
                    flash('Tindakan perbaikan tidak dapat dihapus karena sudah pernah digunakan pada ' . $usedCount . ' riwayat reparasi.', 'err');
                } else {
                    $pdo->prepare('DELETE FROM corrective_action_types WHERE id = ?')->execute([$actionId]);
                    flash('Tindakan perbaikan berhasil dihapus.');
                }
            }
            redirect_to('corrective_job_desks', ['manage_desk_id' => $manageDeskId]);
        }

        // --- SALIN TINDAKAN DARI IT COMP KE JOB DESK INI ---
        if ($action === 'copy_default_actions') {
            $manageDeskId = (int)($_POST['manage_desk_id'] ?? 0);
            $stmtDesk = $pdo->prepare('SELECT * FROM corrective_job_desks WHERE id=?');
            $stmtDesk->execute([$manageDeskId]);
            $curDesk = $stmtDesk->fetch(PDO::FETCH_ASSOC);

            if ($curDesk) {
                $targetDeskName = $curDesk['job_desk_name'];
                $defaultActions = $pdo->query("SELECT action_name, description, estimated_minutes, sort_order FROM corrective_action_types WHERE job_desk_name = 'Job Desk Corrective IT Comp' OR job_desk_name IS NULL OR job_desk_name = '' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
                $ins = $pdo->prepare("INSERT INTO corrective_action_types (action_code, action_name, job_desk_name, asset_group_id, asset_type_id, estimated_minutes, sort_order, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $copied = 0;
                foreach ($defaultActions as $da) {
                    $chkDup = $pdo->prepare("SELECT COUNT(*) FROM corrective_action_types WHERE job_desk_name = ? AND action_name = ?");
                    $chkDup->execute([$targetDeskName, $da['action_name']]);
                    if ((int)$chkDup->fetchColumn() === 0) {
                        $code = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_]/', '_', $da['action_name']))) . '_' . substr(md5(uniqid('', true)), 0, 4);
                        $ins->execute([
                            $code,
                            $da['action_name'],
                            $targetDeskName,
                            $curDesk['asset_group_id'] ?: null,
                            $curDesk['asset_type_id'] ?: null,
                            $da['estimated_minutes'] ?: 15,
                            $da['sort_order'] ?: 10,
                            $da['description'] ?: null,
                        ]);
                        $copied++;
                    }
                }
                flash("Berhasil menyalin {$copied} tindakan standar ke '{$targetDeskName}'.");
            }
            redirect_to('corrective_job_desks', ['manage_desk_id' => $manageDeskId]);
        }
    }

    render_header('Job Desk Corrective Maintenance', $user);

    // Ambil daftar job desk corrective
    $desksQuery = 'SELECT 
        d.id AS desk_id,
        d.job_desk_name,
        d.asset_group_id,
        d.asset_type_id,
        d.description,
        g.group_code,
        g.group_name,
        t.type_code,
        t.type_name,
        COUNT(a.id) AS action_count,
        COALESCE(SUM(a.estimated_minutes), 0) AS total_minutes,
        COALESCE(cr_sub.repair_count, 0) AS repair_count
    FROM corrective_job_desks d
    LEFT JOIN asset_groups g ON g.id = d.asset_group_id
    LEFT JOIN asset_types t ON t.id = d.asset_type_id
    LEFT JOIN corrective_action_types a ON a.job_desk_name COLLATE utf8mb4_unicode_ci = d.job_desk_name COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT ca.job_desk_name, COUNT(*) AS repair_count 
        FROM corrective_repairs cr 
        JOIN corrective_action_types ca ON ca.action_code = cr.action_type 
        GROUP BY ca.job_desk_name
    ) cr_sub ON cr_sub.job_desk_name COLLATE utf8mb4_unicode_ci = d.job_desk_name COLLATE utf8mb4_unicode_ci
    GROUP BY d.id, d.job_desk_name, d.asset_group_id, d.asset_type_id, d.description, g.group_code, g.group_name, t.type_code, t.type_name
    ORDER BY g.group_name, t.type_name, d.job_desk_name';

    $deskRows = $pdo->query($desksQuery)->fetchAll(PDO::FETCH_ASSOC);

    // Tentukan Job Desk yang sedang aktif dipilih untuk pengelolaan tindakan / actions
    $manageDeskId = (int)($_GET['manage_desk_id'] ?? 0);
    if ($manageDeskId === 0) {
        if ($editDeskId > 0) {
            $manageDeskId = $editDeskId;
        } elseif (!empty($deskRows)) {
            $manageDeskId = (int)$deskRows[0]['desk_id'];
        }
    }

    $activeDesk = null;
    $activeDeskActions = [];
    if ($manageDeskId > 0) {
        foreach ($deskRows as $r) {
            if ((int)$r['desk_id'] === $manageDeskId) {
                $activeDesk = $r;
                break;
            }
        }
        if ($activeDesk) {
            $isITComp = ($activeDesk['job_desk_name'] === 'Job Desk Corrective IT Comp');
            $sqlActions = $isITComp 
                ? 'SELECT * FROM corrective_action_types WHERE job_desk_name = ? OR job_desk_name IS NULL OR job_desk_name = "" ORDER BY sort_order ASC, is_active DESC, id ASC'
                : 'SELECT * FROM corrective_action_types WHERE job_desk_name = ? ORDER BY sort_order ASC, is_active DESC, id ASC';
            $stmtActions = $pdo->prepare($sqlActions);
            $stmtActions->execute([$activeDesk['job_desk_name']]);
            $activeDeskActions = $stmtActions->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // FORM TAMBAH / EDIT JOB DESK CORRECTIVE MAINTENANCE
    $formTitle = $isEditing ? ('Edit Job Desk Corrective Maintenance #' . $editDeskId) : 'Tambah Job Desk Corrective Maintenance';
    $formAction = $isEditing ? 'edit_desk' : 'add_desk';

    echo '<section class="grid two"><div class="panel">';
    echo '<div class="split" style="align-items:center;margin-bottom:12px;">'
        . '<h1 style="margin:0;font-size:18px;">' . e($formTitle) . '</h1>'
        . ($isEditing ? '<a class="btn" href="' . route_url('corrective_job_desks') . '">+ Tambah Job Desk Baru</a>' : '')
        . '</div>';

    echo '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
        . '<input type="hidden" name="action" value="' . $formAction . '">'
        . ($isEditing ? '<input type="hidden" name="desk_id" value="' . $editDeskId . '">' : '')
        . ($isEditing ? '<input type="hidden" name="original_desk_name" value="' . e($editDeskName) . '">' : '')
        . '<div class="grid two">'
        . '<label>ID Job Desk'
        . '<input type="text" readonly value="' . ($isEditing ? ('#' . $editDeskId) : '(Otomatis setelah disimpan)') . '" style="background:#f8fafc;color:#64748b;font-weight:700;">'
        . '</label>'
        . '<label>Komoditas (Asset Group) *'
        . '<select id="cJobAssetGroup" name="asset_group_id" required>'
        . '<option value="">- Pilih Komoditas -</option>'
        . asset_group_options($pdo, $editGroupId, false)
        . '</select>'
        . '</label>'
        . '</div>'
        . '<div class="grid two">'
        . '<label>Kategori (Asset Type) *'
        . '<select id="cJobAssetType" name="asset_type_id" required>'
        . '<option value="">- Pilih Kategori -</option>'
        . ($editGroupId > 0 ? asset_type_options($pdo, $editTypeId, $editGroupId) : '')
        . '</select>'
        . '</label>'
        . '<label>Deskripsi / Catatan'
        . '<input name="description" value="' . e($editDesc) . '" placeholder="Keterangan kategori corrective...">'
        . '</label>'
        . '</div>'
        . '<label>Nama Job Desk Corrective Maintenance *'
        . '<input id="cJobDeskName" name="job_desk_name" value="' . e($editDeskName) . '" required style="font-weight:700;color:#0f172a;" placeholder="Job Desk Corrective [Komoditas] [Kategori]">'
        . '</label>'
        . '<div class="actions" style="margin-top:14px;">'
        . '<button class="btn primary">' . ($isEditing ? 'Perbarui Job Desk' : 'Simpan Job Desk') . '</button>'
        . ($isEditing ? '<a class="btn" href="' . route_url('corrective_job_desks') . '">Batal Edit</a>' : '')
        . '</div>'
        . '</form>'
        . '</div>';

    // TABEL DAFTAR JOB DESK CORRECTIVE MAINTENANCE
    echo '<div class="panel">'
        . '<div class="split"><h2>Daftar Job Desk Corrective Maintenance</h2></div>'
        . '<p class="muted" style="margin-top:-6px;margin-bottom:12px;">Pilih Job Desk untuk mengisi atau mengelola daftar tindakan / pekerjaan perbaikannya.</p>';

    if (!$deskRows) {
        echo '<p class="muted">Belum ada Job Desk tersimpan. Silakan tambahkan pada form di samping.</p>';
    } else {
        echo '<div style="overflow-x:auto;"><table><tr><th>ID</th><th>Nama Job Desk</th><th>Komoditas / Kategori</th><th>Tindakan</th><th>Dipakai</th><th>Aksi</th></tr>';
        foreach ($deskRows as $d) {
            $dId = (int)$d['desk_id'];
            $dName = $d['job_desk_name'];
            $grpLabel = !empty($d['group_name']) ? ($d['group_code'] . ' - ' . $d['group_name']) : 'Umum';
            $typLabel = !empty($d['type_name']) ? ($d['type_code'] . ' - ' . $d['type_name']) : 'Semua Tipe';
            $rCount = (int)$d['repair_count'];
            $isActive = ($manageDeskId === $dId);

            $usageBadge = ($rCount > 0)
                ? '<span class="badge ok" title="Digunakan pada ' . $rCount . ' riwayat perbaikan">' . $rCount . ' reparasi</span>'
                : '<span class="muted" style="font-size:12px;">0</span>';

            $confirmMsg = ($rCount > 0)
                ? ('Job Desk Corrective &quot;' . e($dName) . '&quot; ini pernah digunakan pada ' . $rCount . ' data reparasi. Menghapusnya akan menghapus Job Desk ini dari daftar dan mengarsipkan tindakannya secara aman tanpa merusak riwayat perbaikan. Lanjutkan hapus?')
                : ('Yakin hapus Job Desk Corrective &quot;' . e($dName) . '&quot; beserta seluruh tindakannya?');

            $deleteBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'' . $confirmMsg . '\')">'
                . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
                . '<input type="hidden" name="action" value="delete_desk">'
                . '<input type="hidden" name="desk_id" value="' . $dId . '">'
                . '<input type="hidden" name="job_desk_name" value="' . e($dName) . '">'
                . '<button class="btn danger" style="padding:4px 8px;font-size:12px;">Hapus</button>'
                . '</form>';

            $trBg = $isActive ? ' style="background:#eff6ff;"' : '';
            echo '<tr' . $trBg . '>'
                . '<td><span class="badge" style="font-weight:700;">#' . $dId . '</span></td>'
                . '<td><strong>' . e($dName) . '</strong>' . ($isActive ? ' <span class="badge ok" style="font-size:10px;">Aktif Dikelola</span>' : '') . '</td>'
                . '<td><span style="font-weight:600;">' . e($grpLabel) . '</span><br><span class="muted" style="font-size:12px;">' . e($typLabel) . '</span></td>'
                . '<td><span class="badge">' . (int)$d['action_count'] . ' tindakan</span><br><span class="muted" style="font-size:11px;">~' . (int)$d['total_minutes'] . ' mnt</span></td>'
                . '<td>' . $usageBadge . '</td>'
                . '<td><div class="actions" style="display:flex;gap:4px;align-items:center;">'
                . '<a class="btn ' . ($isActive ? 'primary' : '') . '" style="padding:4px 8px;font-size:12px;" href="' . route_url('corrective_job_desks', ['manage_desk_id' => $dId]) . '" title="Isi & Kelola Tindakan">📋 Isi Tindakan</a>'
                . '<a class="btn" style="padding:4px 8px;font-size:12px;" href="' . route_url('corrective_job_desks', ['edit_id' => $dId, 'manage_desk_id' => $dId]) . '">Edit</a>'
                . $deleteBtn
                . '</div></td>'
                . '</tr>';
        }
        echo '</table></div>';
    }

    echo '</div></section>';

    // SECTION KELOLA TINDAKAN / ACTIONS DARI DAFTAR JOB DESK AKTIF
    if ($activeDesk) {
        $actId = (int)$activeDesk['desk_id'];
        $actName = (string)$activeDesk['job_desk_name'];
        $totMinutes = 0;
        foreach ($activeDeskActions as $t) {
            if (!empty($t['is_active'])) {
                $totMinutes += (int)$t['estimated_minutes'];
            }
        }

        echo '<div class="panel" style="margin-top:20px;border-top:3px solid #0284c7;">';
        $copyHeaderBtn = ($actName !== 'Job Desk Corrective IT Comp')
            ? '<form method="post" style="display:inline;"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="copy_default_actions"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><button class="btn" style="padding:5px 10px;font-size:12px;background:#f0fdf4;border:1px solid #86efac;color:#166534;font-weight:600;" title="Salin tindakan standar IT Comp yang belum ada ke Job Desk ini">+ Salin Tindakan Standar</button></form>'
            : '';

        echo '<div class="split" style="align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:14px;">'
            . '<div>'
            . '<h2 style="margin:0;font-size:18px;">📋 Daftar Tindakan / Pekerjaan Perbaikan untuk: <span style="color:#0284c7;">' . e($actName) . '</span></h2>'
            . '<p class="muted" style="margin:4px 0 0 0;font-size:13px;">Kelola opsi jenis tindakan perbaikan corrective maintenance yang akan muncul pada tiket & mobile field service untuk grup aset ini.</p>'
            . '</div>'
            . '<div class="actions" style="align-items:center;">'
            . '<span class="badge" style="background:#e0f2fe;color:#0369a1;font-weight:700;padding:6px 12px;font-size:13px;">Total ' . count($activeDeskActions) . ' Tindakan (~' . $totMinutes . ' Menit)</span>'
            . $copyHeaderBtn
            . '</div>'
            . '</div>';

        echo '<div class="grid two" style="align-items:start;">';

        // FORM TAMBAH TINDAKAN
        echo '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">'
            . '<h3 style="margin-top:0;font-size:15px;">+ Tambah Tindakan Perbaikan Baru</h3>'
            . '<form method="post">'
            . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="action" value="add_action">'
            . '<input type="hidden" name="manage_desk_id" value="' . $actId . '">'
            . '<label style="margin-top:4px;">Nama Tindakan / Solusi Perbaikan *'
            . '<input name="action_name" required placeholder="Contoh: Penggantian Power Supply, Kalibrasi Sensor, Tune Up Mesin">'
            . '</label>'
            . '<div class="grid two">'
            . '<label>Kode Sistem (Opsional)'
            . '<input name="action_code" placeholder="Otomatis jika kosong">'
            . '</label>'
            . '<label>Estimasi Menit'
            . '<input type="number" name="estimated_minutes" value="15" min="1">'
            . '</label>'
            . '</div>'
            . '<label>Urutan Tampilan'
            . '<input type="number" name="sort_order" value="10">'
            . '</label>'
            . '<label>Panduan / Keterangan Tindakan'
            . '<textarea name="description" style="min-height:70px;" placeholder="Langkah-langkah atau catatan standar pengerjaan..."></textarea>'
            . '</label>'
            . '<button class="btn primary" style="margin-top:10px;">+ Tambah Tindakan ke Job Desk</button>'
            . '</form>'
            . '</div>';

        // TABEL RINCIAN TINDAKAN
        echo '<div>';
        if (empty($activeDeskActions)) {
            $copyEmptyBtn = ($actName !== 'Job Desk Corrective IT Comp')
                ? '<form method="post" style="margin-top:12px;"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="copy_default_actions"><input type="hidden" name="manage_desk_id" value="' . $actId . '"><button class="btn primary" style="font-size:13px;">📥 Salin 6 Tindakan Standar dari IT Comp</button></form>'
                : '';
            echo '<div style="text-align:center;padding:32px 16px;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1;">'
                . '<p class="muted" style="margin:0 0 8px 0;">Belum ada tindakan perbaikan untuk Job Desk <strong>' . e($actName) . '</strong>.</p>'
                . '<p class="muted" style="font-size:12px;margin:0;">Silakan tambahkan pada form di samping' . ($copyEmptyBtn ? ' atau klik tombol di bawah untuk menyalin tindakan standar:' : '.') . '</p>'
                . $copyEmptyBtn
                . '</div>';
        } else {
            echo '<div style="overflow-x:auto;"><table>'
                . '<tr><th style="width:30px;">No</th><th>Nama Tindakan & Kode</th><th>Estimasi</th><th>Status</th><th>Aksi</th></tr>';
            $no = 1;
            foreach ($activeDeskActions as $act) {
                $tId = (int)$act['id'];
                $isAct = !empty($act['is_active']);

                $statusBadge = $isAct
                    ? '<span class="badge ok">Aktif</span>'
                    : '<span class="badge danger">Nonaktif</span>';

                echo '<tr' . (!$isAct ? ' style="opacity:0.6;background:#f8fafc;"' : '') . '>'
                    . '<td>' . $no++ . '</td>'
                    . '<td>'
                    . '<strong style="font-size:14px;">' . e($act['action_name']) . '</strong><br>'
                    . '<code style="font-size:11px;color:#64748b;">' . e($act['action_code']) . '</code>'
                    . (!empty($act['description']) ? ('<br><span class="muted" style="font-size:12px;">' . e($act['description']) . '</span>') : '')
                    . '</td>'
                    . '<td><span class="badge">' . (int)$act['estimated_minutes'] . ' mnt</span></td>'
                    . '<td>' . $statusBadge . '</td>'
                    . '<td><div class="actions" style="display:flex;gap:4px;">'
                    . '<form method="post" style="display:inline">'
                    . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="toggle_action">'
                    . '<input type="hidden" name="id" value="' . $tId . '">'
                    . '<input type="hidden" name="manage_desk_id" value="' . $actId . '">'
                    . '<button class="btn" style="padding:3px 7px;font-size:11px;" title="Aktif/Nonaktifkan">' . ($isAct ? 'Off' : 'On') . '</button>'
                    . '</form>'
                    . '<form method="post" style="display:inline" onsubmit="return confirm(\'Yakin ingin menghapus tindakan ini?\')">'
                    . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
                    . '<input type="hidden" name="action" value="delete_action">'
                    . '<input type="hidden" name="id" value="' . $tId . '">'
                    . '<input type="hidden" name="manage_desk_id" value="' . $actId . '">'
                    . '<button class="btn danger" style="padding:3px 7px;font-size:11px;">Hapus</button>'
                    . '</form>'
                    . '</div></td>'
                    . '</tr>';
            }
            echo '</table></div>';
        }
        echo '</div>';

        echo '</section>';
        echo '</div>';
    }

    echo '<script>
(function(){
    var groupSelect = document.getElementById("cJobAssetGroup");
    var typeSelect = document.getElementById("cJobAssetType");
    var nameInput = document.getElementById("cJobDeskName");
    var isEditMode = ' . ($isEditing ? 'true' : 'false') . ';

    function updateGeneratedJobDeskName() {
        if (isEditMode && nameInput.value.trim() !== "") {
            return;
        }
        var gText = groupSelect.options[groupSelect.selectedIndex] ? groupSelect.options[groupSelect.selectedIndex].text.replace(/^[A-Z0-9]+\s*-\s*/, "").trim() : "";
        var tText = typeSelect.options[typeSelect.selectedIndex] ? typeSelect.options[typeSelect.selectedIndex].text.replace(/^[A-Z0-9]+\s*-\s*/, "").trim() : "";
        if (!groupSelect.value) gText = "";
        if (!typeSelect.value) tText = "";
        
        var name = "Job Desk Corrective";
        if (gText) name += " " + gText;
        if (tText) name += " " + tText;
        nameInput.value = name;
    }

    if (groupSelect && typeSelect && nameInput) {
        groupSelect.addEventListener("change", function() {
            var gid = this.value;
            typeSelect.innerHTML = "<option value=\"\">- Memuat Kategori... -</option>";
            fetch("index.php?route=api_asset_types&group_id=" + (gid || 0))
                .then(function(r) { return r.json(); })
                .then(function(types) {
                    typeSelect.innerHTML = "<option value=\"\">- Pilih Kategori -</option>";
                    types.forEach(function(t) {
                        var opt = document.createElement("option");
                        opt.value = t.id;
                        opt.textContent = t.type_code + " - " + t.type_name;
                        typeSelect.appendChild(opt);
                    });
                    updateGeneratedJobDeskName();
                })
                .catch(function() {
                    typeSelect.innerHTML = "<option value=\"\">- Pilih Kategori -</option>";
                    updateGeneratedJobDeskName();
                });
        });

        typeSelect.addEventListener("change", function() {
            updateGeneratedJobDeskName();
        });
    }
})();
</script>';

    render_footer();
}

