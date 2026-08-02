<?php
// ============================================================
// EXPORT LAPORAN ABSENSI KE EXCEL (.xls)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

$conn = getDB();

// Filter Tanggal, Pencarian, & Status
$tgl           = trim($_GET['tgl'] ?? date('Y-m-d'));
$q             = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = "";

if ($tgl !== 'all' && !empty($tgl)) {
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

if (is_tatausaha()) {
    $where[] = "master_karyawan.tipe = 'karyawan'";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$sql = "SELECT log_absen.*, master_karyawan.nama, master_karyawan.departemen 
        FROM log_absen 
        LEFT JOIN master_karyawan ON log_absen.pin = master_karyawan.pin 
        {$where_sql}
        ORDER BY log_absen.waktu DESC LIMIT 1000";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Format Nama File & Timestamp Export
$waktu_export = date('d-m-Y H:i:s') . ' WIB';
$label_tanggal = ($tgl === 'all' || empty($tgl)) ? "Semua Tanggal" : date('d-m-Y', strtotime($tgl));
$file_timestamp = date('Y-m-d_H-i-s');
$filename = "Laporan_Absensi_" . ($tgl === 'all' ? 'Semua' : $tgl) . "_" . $file_timestamp . ".xls";

// Set Header Download Excel
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; }
        .header-title { font-size: 16pt; font-weight: bold; color: #0f172a; text-align: center; }
        .header-school { font-size: 14pt; font-weight: bold; color: #3b82f6; text-align: center; margin-bottom: 10px; }
        .meta-table { margin-bottom: 15px; font-size: 10pt; }
        .meta-label { font-weight: bold; width: 160px; }
        table.data-table { border-collapse: collapse; width: 100%; }
        table.data-table th { background-color: #3b82f6; color: #ffffff; font-weight: bold; border: 1px solid #1d4ed8; padding: 8px; text-align: center; }
        table.data-table td { border: 1px solid #cbd5e1; padding: 6px 8px; font-size: 10pt; }
        table.data-table tr:nth-child(even) { background-color: #f8fafc; }
        .status-masuk { color: #15803d; font-weight: bold; text-align: center; }
        .status-pulang { color: #be123c; font-weight: bold; text-align: center; }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
    </style>
</head>
<body>

    <div class="header-title">LAPORAN ABSENSI GURU & KARYAWAN</div>
    <div class="header-school">SMK PASUNDAN 2 BANDUNG</div>
    <br>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Filter Tanggal:</td>
            <td><b><?php echo $label_tanggal; ?></b></td>
        </tr>
        <tr>
            <td class="meta-label">Waktu Export:</td>
            <td><b><?php echo $waktu_export; ?></b></td>
        </tr>
        <tr>
            <td class="meta-label">Di-export Oleh:</td>
            <td><?php echo h($_SESSION['username']); ?> (<?php echo h($_SESSION['role'] ?? 'admin'); ?>)</td>
        </tr>
        <tr>
            <td class="meta-label">Total Data Ditampilkan:</td>
            <td><?php echo $result->num_rows; ?> baris</td>
        </tr>
        <?php if (!empty($q)): ?>
        <tr>
            <td class="meta-label">Kata Kunci Pencarian:</td>
            <td>"<?php echo h($q); ?>"</td>
        </tr>
        <?php endif; ?>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th>No</th>
                <th>PIN / User ID</th>
                <th>Nama Guru & Karyawan</th>
                <th>Departemen / Jabatan</th>
                <th>Waktu Absen</th>
                <th>Status Absensi</th>
                <th>Tipe Verifikasi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result->num_rows > 0) {
                $no = 1;
                while ($row = $result->fetch_assoc()) {
                    $status_teks = "Unknown";
                    $status_class = "";
                    if ($row['status'] == '0') {
                        $status_teks = "Masuk";
                        $status_class = "status-masuk";
                    } elseif ($row['status'] == '1') {
                        $status_teks = "Pulang";
                        $status_class = "status-pulang";
                    }

                    $tipe_teks = "Unknown";
                    if ($row['tipe_verifikasi'] == '1') $tipe_teks = "Sidik Jari";
                    elseif ($row['tipe_verifikasi'] == '15') $tipe_teks = "Wajah";

                    $nama = !empty($row['nama']) ? $row['nama'] : 'Belum Terdaftar';
                    $dept = !empty($row['departemen']) ? $row['departemen'] : '-';

                    echo "<tr>
                            <td class='text-center'>{$no}</td>
                            <td class='text-center'>" . h($row['pin']) . "</td>
                            <td class='text-left'>" . h($nama) . "</td>
                            <td class='text-left'>" . h($dept) . "</td>
                            <td class='text-center'>" . h($row['waktu']) . "</td>
                            <td class='{$status_class}'>" . h($status_teks) . "</td>
                            <td class='text-center'>" . h($tipe_teks) . "</td>
                          </tr>";
                    $no++;
                }
            } else {
                echo "<tr><td colspan='7' class='text-center'>Tidak ada data absensi untuk filter ini.</td></tr>";
            }
            ?>
        </tbody>
    </table>

</body>
</html>
