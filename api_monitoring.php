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
    $where[] = "DATE(log_absen.waktu) = ?";
    $params[] = $tgl;
    $types .= "s";
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

$sql = "SELECT log_absen.*, master_karyawan.nama, master_karyawan.departemen 
        FROM log_absen 
        LEFT JOIN master_karyawan ON log_absen.pin = master_karyawan.pin 
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

        if (!empty($row['nama'])) {
            $nama_escaped = h($row['nama']);
            $dept_escaped = h($row['departemen']);
            $tampil_nama = "<td class='nama-container'>
                                <div class='nama-title'>{$nama_escaped}</div>
                                <div class='dept-subtitle'>{$dept_escaped}</div>
                            </td>";
        } else {
            $tampil_nama = "<td class='text-unregistered'>⚠️ Belum Terdaftar di Master</td>";
        }

        echo "<tr>
                <td><b>{$no}</b></td>
                <td><code style='background:#f1f5f9; padding:3px 8px; border-radius:6px; font-weight:700; color:#0f172a;'>" . h($row['pin']) . "</code></td>
                {$tampil_nama}
                <td><b>" . h($row['waktu']) . "</b></td>
                <td><span class='badge {$badge_class}'>" . h($status_teks) . "</span></td>
                <td><span class='badge badge-verif'>" . h($tipe_teks) . "</span></td>
              </tr>";
        $no++;
    }
} else {
    echo "<tr><td colspan='6' style='padding: 30px; color:#94a3b8;'>Data absensi tidak ditemukan untuk filter ini.</td></tr>";
}
$html = ob_get_clean();

echo json_encode([
    'success' => true,
    'count' => $result->num_rows,
    'html' => $html,
    'last_update' => date('H:i:s')
]);
exit;
