<?php
// ============================================================
// API ENDPOINT: AJAX Monitoring Data Real-Time (Dengan Filter Tanggal)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

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

$rows_out = [];
if ($result && $result->num_rows > 0) {
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

        $tipe_teks = "Lainnya";
        if ($row['tipe_verifikasi'] == '1') $tipe_teks = "Sidik Jari";
        elseif ($row['tipe_verifikasi'] == '15') $tipe_teks = "Wajah";
        elseif ($row['tipe_verifikasi'] == '0') $tipe_teks = "Password / PIN";

        // Cek Badge Jadwal Guru
        $badge_jadwal = "";
        $is_guru = ($row['tipe'] ?? '') === 'guru';
        if ($is_guru) {
            if ($row['total_jadwal_guru'] == 0) {
                $badge_jadwal = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;' title='Jadwal ngajar belum diatur superadmin'>Belum Ada Jadwal</span>";
            } elseif (empty($row['jg_id'])) {
                $badge_jadwal = "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;' title='Absen di luar hari jadwal ngajar'>Di Luar Jadwal</span>";
            }
        }

        if (!empty($row['nama'])) {
            $nama_escaped = h($row['nama']);
            $dept_escaped = h($row['departemen']);
            $tipe_label   = $is_guru ? "Guru" : "Karyawan";
            $tampil_nama = "<td class='nama-container'>
                                <div style='display:flex; align-items:center; gap:6px;'>
                                    <div class='nama-title'>{$nama_escaped}</div>
                                    <span style='font-size:10px; background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-weight:600;'>{$tipe_label}</span>
                                </div>
                                <div class='dept-subtitle'>{$dept_escaped}</div>
                            </td>";
        } else {
            $tampil_nama = "<td class='text-unregistered'>Belum Terdaftar di Master</td>";
        }

        $status_badge = "<span class='badge {$badge_class}'>" . h($status_teks) . "</span>";

        $rows_out[] = [
            'no' => $no++,
            'pin' => h($row['pin']),
            'nama_html' => $tampil_nama,
            'waktu' => h($row['waktu']),
            'status_badge' => $status_badge,
            'verifikasi' => $tipe_teks,
            'badge_jadwal' => $badge_jadwal
        ];
    }
}

echo json_encode([
    'success' => true,
    'total' => count($rows_out),
    'rows' => $rows_out,
    'last_update' => date('H:i:s')
]);
exit;
