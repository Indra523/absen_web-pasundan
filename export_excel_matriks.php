<?php
// ============================================================
// SCRIPT EKSPOR EXCEL MATRIKS ABSENSI BULANAN (30/31 HARI)
// SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

$conn = getDB();

$bulan    = (int)($_GET['bulan'] ?? date('n'));
$tahun    = (int)($_GET['tahun'] ?? date('Y'));
$kategori = $_GET['kategori'] ?? 'semua'; // 'semua', 'karyawan', 'guru'

if ($bulan < 1 || $bulan > 12) $bulan = (int)date('n');
if ($tahun < 2020 || $tahun > 2050) $tahun = (int)date('Y');

$total_hari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);
$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Query Master Karyawan
$where_kat = "";
if ($kategori === 'karyawan') {
    $where_kat = "WHERE mk.tipe = 'karyawan'";
} elseif ($kategori === 'guru') {
    $where_kat = "WHERE mk.tipe = 'guru'";
}

$sql_master = "SELECT mk.*, 
                      GROUP_CONCAT(jg.hari ORDER BY jg.hari ASC) AS list_hari 
               FROM master_karyawan mk 
               LEFT JOIN jadwal_guru jg ON mk.pin = jg.pin 
               {$where_kat}
               GROUP BY mk.pin 
               ORDER BY CAST(mk.pin AS UNSIGNED) ASC, mk.pin ASC";
$res_master = $conn->query($sql_master);

// Ambil semua data log absensi di bulan & tahun tsb
$start_date = sprintf("%04d-%02d-01 00:00:00", $tahun, $bulan);
$end_date   = sprintf("%04d-%02d-%02d 23:59:59", $tahun, $bulan, $total_hari);

$sql_log = "SELECT pin, waktu, status, DATE(waktu) AS tgl_absen
            FROM log_absen 
            WHERE waktu >= ? AND waktu <= ?
            ORDER BY waktu ASC";
$stmt_log = $conn->prepare($sql_log);
$stmt_log->bind_param("ss", $start_date, $end_date);
$stmt_log->execute();
$res_log = $stmt_log->get_result();

$absen_detail = [];
while ($l = $res_log->fetch_assoc()) {
    $pin = $l['pin'];
    $tgl = $l['tgl_absen'];
    $st  = (string)$l['status'];

    if (!isset($absen_detail[$pin])) {
        $absen_detail[$pin] = [];
    }
    if (!isset($absen_detail[$pin][$tgl])) {
        $absen_detail[$pin][$tgl] = ['masuk' => false, 'pulang' => false];
    }
    if ($st === '0') $absen_detail[$pin][$tgl]['masuk'] = true;
    if ($st === '1') $absen_detail[$pin][$tgl]['pulang'] = true;
}

$filename = "Matriks_Absensi_31Hari_{$nama_bulan[$bulan]}_{$tahun}.xls";
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
        .header-title { font-size: 14pt; font-weight: bold; text-align: center; }
        .header-school { font-size: 12pt; font-weight: bold; color: #1d4ed8; text-align: center; margin-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #1e293b; color: #ffffff; font-weight: bold; border: 1px solid #0f172a; padding: 6px; text-align: center; font-size: 9pt; }
        td { border: 1px solid #cbd5e1; padding: 5px; font-size: 8.5pt; text-align: center; }
        .text-left { text-align: left; }
        
        /* CELL COLORS */
        .cell-green { background-color: #dcfce7; color: #166534; font-weight: bold; }
        .cell-yellow { background-color: #fef9c3; color: #854d0e; font-weight: bold; }
        .cell-red { background-color: #fee2e2; color: #991b1b; font-weight: bold; }
        .cell-gray { background-color: #f1f5f9; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="header-title">MATRIKS REKAPITULASI ABSENSI BULANAN (1–<?php echo $total_hari; ?> HARI)</div>
    <div class="header-school">SMK PASUNDAN 2 BANDUNG</div>
    <div style="text-align:center; font-size:10pt; margin-bottom:12px;">
        <b>Periode:</b> <?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?> | 
        <b>Kategori:</b> <?php echo ucfirst($kategori); ?>
    </div>

    <!-- LEGEND KETERANGAN WARNA -->
    <table style="width: auto; margin: 0 auto 12px auto;">
        <tr>
            <td class="cell-green" style="padding:4px 10px;">🟢 HADIR LENGKAP (Masuk & Pulang)</td>
            <td class="cell-yellow" style="padding:4px 10px;">🟡 HADIR PARSIAL (Cuma Masuk / Pulang)</td>
            <td class="cell-red" style="padding:4px 10px;">🔴 ALPA / TIDAK HADIR (Ada Jadwal 0 Log)</td>
            <td class="cell-gray" style="padding:4px 10px;">⚪ TANPA JADWAL / LIBUR (Tidak Dihitung Alpa)</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>PIN</th>
                <th>Nama Lengkap</th>
                <th>Departemen</th>
                <th>Tipe</th>
                <?php for ($d = 1; $d <= $total_hari; $d++): 
                    $tgl_sub = sprintf("%04d-%02d-%02d", $tahun, $bulan, $d);
                    $h_nama  = ['Sen','Sel','Rab','Kam','Jum','Sab','Min'][(int)date('N', strtotime($tgl_sub)) - 1];
                ?>
                    <th><?php echo $d; ?><br><span style="font-size:7.5pt; font-weight:normal;"><?php echo $h_nama; ?></span></th>
                <?php endfor; ?>
                <th style="background-color:#166534;">🟢 Hadir</th>
                <th style="background-color:#854d0e;">🟡 Parsial</th>
                <th style="background-color:#991b1b;">🔴 Alpa</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($res_master->num_rows > 0) {
                while ($m = $res_master->fetch_assoc()) {
                    $pin      = $m['pin'];
                    $nama     = $m['nama'];
                    $dept     = $m['departemen'];
                    $tipe     = $m['tipe'];
                    $hari_arr = !empty($m['list_hari']) ? array_map('intval', explode(',', $m['list_hari'])) : [];
                    $is_guru  = ($tipe === 'guru');

                    $tot_green  = 0;
                    $tot_yellow = 0;
                    $tot_red    = 0;

                    echo "<tr>";
                    echo "<td>'" . h($pin) . "</td>";
                    echo "<td class='text-left'>" . h($nama) . "</td>";
                    echo "<td class='text-left'>" . h($dept) . "</td>";
                    echo "<td>" . ucfirst($tipe) . "</td>";

                    for ($d = 1; $d <= $total_hari; $d++) {
                        $tgl_key  = sprintf("%04d-%02d-%02d", $tahun, $bulan, $d);
                        $day_num  = (int)date('N', strtotime($tgl_key));

                        // Cek Jadwal
                        $has_sch = $is_guru ? in_array($day_num, $hari_arr) : ($day_num !== 7);
                        $log_today = $absen_detail[$pin][$tgl_key] ?? null;

                        if ($log_today !== null) {
                            if ($log_today['masuk'] && $log_today['pulang']) {
                                echo "<td class='cell-green'>✔️</td>";
                                $tot_green++;
                            } else {
                                echo "<td class='cell-yellow'>⚠️</td>";
                                $tot_yellow++;
                            }
                        } else {
                            if ($has_sch) {
                                echo "<td class='cell-red'>❌</td>";
                                $tot_red++;
                            } else {
                                echo "<td class='cell-gray'>-</td>";
                            }
                        }
                    }

                    echo "<td class='cell-green'>{$tot_green}</td>";
                    echo "<td class='cell-yellow'>{$tot_yellow}</td>";
                    echo "<td class='cell-red'>{$tot_red}</td>";
                    echo "</tr>";
                }
            }
            ?>
        </tbody>
    </table>
</body>
</html>
