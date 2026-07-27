<?php
// ============================================================
// API ENDPOINT: AJAX Monitoring Data Real-Time (Dengan Filter Tanggal)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

// Release lock file sesi segera agar request reload/F5 tidak terblokir
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$conn = getDB();

// Filter Tanggal, Pencarian, & Status
$tgl           = trim($_GET['tgl'] ?? date('Y-m-d'));
$q             = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = "";

if ($tgl !== 'all' && !empty($tgl)) {
    // Optimization: Range scan agar menggunakan index idx_waktu
    $where[] = "(log_absen.waktu >= ? AND log_absen.waktu < DATE_ADD(?, INTERVAL 1 DAY))";
    $params[] = $tgl . " 00:00:00";
    $params[] = $tgl;
    $types .= "ss";
}

if (!empty($q)) {
    $where[] = "(log_absen.pin LIKE ? OR master_karyawan.nama LIKE ? OR master_karyawan.departemen LIKE ? OR log_absen.waktu LIKE ?)";
    $param_q = "%" . $q . "%";
    $params[] = $param_q;
    $params[] = $param_q;
    $params[] = $param_q;
    $params[] = $param_q;
    $types .= "ssss";
}

if ($status_filter !== '' && in_array($status_filter, ['0', '1'])) {
    $where[] = "log_absen.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Query utama + LEFT JOIN ke master_karyawan dan check jadwal guru
// MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1 menghitung hari: 1=Senin ... 6=Sabtu, 7=Minggu
$sql = "SELECT log_absen.*, 
               master_karyawan.nama, 
               master_karyawan.departemen, 
               master_karyawan.tipe,
               jg.id AS jg_id,
               (SELECT COUNT(*) FROM jadwal_guru WHERE pin = log_absen.pin) AS total_jadwal_guru
        FROM log_absen 
        LEFT JOIN master_karyawan ON log_absen.pin = master_karyawan.pin 
        LEFT JOIN jadwal_guru jg 
               ON jg.pin = log_absen.pin 
              AND jg.hari = (MOD(DAYOFWEEK(log_absen.waktu) + 5, 7) + 1)
        {$where_sql}
        ORDER BY log_absen.waktu DESC LIMIT 300";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

ob_start();
if ($result->num_rows > 0) {
    $no = 1;
    while ($row = $result->fetch_assoc()) {
        $status_teks = "Unknown";
        $badge_class = "badge-verif";
        if ($row['status'] == '0') {
            $status_teks = "Masuk";
            $badge_class = "badge-masuk";
        } elseif ($row['status'] == '1') {
            $status_teks = "Pulang";
            $badge_class = "badge-pulang";
        }

        $tipe_teks = "Unknown";
        if ($row['tipe_verifikasi'] == '1') $tipe_teks = "Sidik Jari 👆";
        elseif ($row['tipe_verifikasi'] == '15') $tipe_teks = "Wajah 👤";
        elseif ($row['tipe_verifikasi'] == '0' || $row['tipe_verifikasi'] == '99') $tipe_teks = "Manual Admin ✏️";

        // Cek Badge Jadwal Guru
        $badge_jadwal = "";
        $is_guru = ($row['tipe'] ?? '') === 'guru';
        if ($is_guru) {
            if ($row['total_jadwal_guru'] == 0) {
                $badge_jadwal = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;' title='Jadwal ngajar belum diatur superadmin'>❓ Belum Ada Jadwal</span>";
            } elseif (empty($row['jg_id'])) {
                $badge_jadwal = "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;' title='Absen di luar hari jadwal ngajar'>⚠️ Di Luar Jadwal</span>";
            }
        }

        if (!empty($row['nama'])) {
            $nama_escaped = h($row['nama']);
            $dept_escaped = h($row['departemen']);
            $tipe_label   = $is_guru ? "👨‍🏫 Guru" : "👔 Karyawan";
            $tampil_nama = "<td class='nama-container'>
                                <div style='display:flex; align-items:center; gap:6px;'>
                                    <div class='nama-title'>{$nama_escaped}</div>
                                    <span style='font-size:10px; background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-weight:600;'>{$tipe_label}</span>
                                </div>
                                <div class='dept-subtitle'>{$dept_escaped}</div>
                            </td>";
        } else {
            $tampil_nama = "<td class='text-unregistered'>⚠️ Belum Terdaftar di Master</td>";
        }

        $td_aksi = "";
        if (is_superadmin()) {
            $csrf_tok = csrf_token();
            $target_status_label = ($row['status'] === '0') ? "Pulang" : "Masuk";
            $td_aksi = "<td>
                            <div style='display:flex; gap:4px; justify-content:center;'>
                                <form method='POST' action='index.php' style='margin:0;'>
                                    <input type='hidden' name='csrf_token' value='{$csrf_tok}'>
                                    <input type='hidden' name='action' value='tukar_status_log'>
                                    <input type='hidden' name='id_log_toggle' value='{$row['id']}'>
                                    <button type='submit' class='btn' style='background:#f1f5f9; color:#0f172a; font-size:11px; padding:4px 8px; border:1px solid #cbd5e1;' title='Tukar status ke {$target_status_label}'>🔄 Tukar Status</button>
                                </form>
                                <form method='POST' action='index.php' style='margin:0;' onsubmit=\"return confirm('Yakin ingin menghapus data log absen ini?')\">
                                    <input type='hidden' name='csrf_token' value='{$csrf_tok}'>
                                    <input type='hidden' name='action' value='hapus_log_absen'>
                                    <input type='hidden' name='id_log_hapus' value='{$row['id']}'>
                                    <button type='submit' class='btn' style='background:#fee2e2; color:#dc2626; font-size:11px; padding:4px 8px; border:1px solid #fca5a5;'>🗑️ Hapus</button>
                                </form>
                            </div>
                        </td>";
        }

        echo "<tr>
                <td><b>{$no}</b></td>
                <td><code style='background:#f1f5f9; padding:3px 8px; border-radius:6px; font-weight:700; color:#0f172a;'>" . h($row['pin']) . "</code></td>
                {$tampil_nama}
                <td><b>" . h($row['waktu']) . "</b></td>
                <td><span class='badge {$badge_class}'>" . h($status_teks) . "</span></td>
                <td>
                    <div style='display:flex; flex-direction:column; align-items:center; gap:4px;'>
                        <span class='badge badge-verif'>" . h($tipe_teks) . "</span>
                        {$badge_jadwal}
                    </div>
                </td>
                {$td_aksi}
              </tr>";
        $no++;
    }
} else {
    $colspan = is_superadmin() ? 7 : 6;
    echo "<tr><td colspan='{$colspan}' style='padding: 30px; color:#94a3b8;'>Data absensi tidak ditemukan untuk filter ini.</td></tr>";
}
$html = ob_get_clean();

echo json_encode([
    'success' => true,
    'count' => $result->num_rows,
    'html' => $html,
    'last_update' => date('H:i:s')
]);
exit;
