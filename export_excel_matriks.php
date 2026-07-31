<?php
// ============================================================
// SCRIPT EKSPOR EXCEL MATRIKS & EVALUASI ABSENSI BULANAN (COMPREHENSIVE)
// SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

$conn = getDB();

$bulan    = (int)($_GET['bulan'] ?? date('n'));
$tahun    = (int)($_GET['tahun'] ?? date('Y'));
$kategori = $_GET['kategori'] ?? 'semua'; // 'semua', 'karyawan', 'guru'
$sort     = $_GET['sort'] ?? 'pin_asc';

if ($bulan < 1 || $bulan > 12) $bulan = (int)date('n');
if ($tahun < 2020 || $tahun > 2050) $tahun = (int)date('Y');

$total_hari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);
$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

function get_hari_kerja_karyawan($thn, $bln) {
    $total_h = cal_days_in_month(CAL_GREGORIAN, $bln, $thn);
    $minggu = 0;
    for ($d = 1; $d <= $total_h; $d++) {
        $w = (int)date('N', mktime(0, 0, 0, $bln, $d, $thn));
        if ($w === 7) $minggu++;
    }
    return $total_h - $minggu;
}

function get_target_hari_guru($conn, $pin, $thn, $bln) {
    $stmt = $conn->prepare("SELECT hari FROM jadwal_guru WHERE pin = ?");
    $stmt->bind_param("s", $pin);
    $stmt->execute();
    $res = $stmt->get_result();
    $hari_ngajar = [];
    while ($r = $res->fetch_assoc()) {
        $hari_ngajar[] = (int)$r['hari'];
    }

    if (empty($hari_ngajar)) return 0;

    $total_h = cal_days_in_month(CAL_GREGORIAN, $bln, $thn);
    $count = 0;
    for ($d = 1; $d <= $total_h; $d++) {
        $w = (int)date('N', mktime(0, 0, 0, $bln, $d, $thn));
        if (in_array($w, $hari_ngajar)) $count++;
    }
    return $count;
}

$hari_kerja_karyawan_default = get_hari_kerja_karyawan($tahun, $bulan);

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

$sql_log = "SELECT la.*, 
                   (MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1) AS hari_num,
                   DATE(la.waktu) AS tgl_absen
            FROM log_absen la 
            WHERE la.waktu >= ? AND la.waktu <= ?
            ORDER BY la.waktu ASC";
$stmt_log = $conn->prepare($sql_log);
$stmt_log->bind_param("ss", $start_date, $end_date);
$stmt_log->execute();
$res_log = $stmt_log->get_result();

$absen_data   = [];
$absen_detail = [];
while ($l = $res_log->fetch_assoc()) {
    $pin = $l['pin'];
    $tgl = $l['tgl_absen'];
    $st  = (string)$l['status'];
    $jam = date('H:i', strtotime($l['waktu']));

    if (!isset($absen_data[$pin])) $absen_data[$pin] = [];
    if (!isset($absen_data[$pin][$tgl])) $absen_data[$pin][$tgl] = (int)$l['hari_num'];

    if (!isset($absen_detail[$pin])) $absen_detail[$pin] = [];
    if (!isset($absen_detail[$pin][$tgl])) $absen_detail[$pin][$tgl] = ['masuk' => null, 'pulang' => null];

    if ($st === '0') {
        if ($absen_detail[$pin][$tgl]['masuk'] === null || $jam < $absen_detail[$pin][$tgl]['masuk']) {
            $absen_detail[$pin][$tgl]['masuk'] = $jam;
        }
    } elseif ($st === '1') {
        if ($absen_detail[$pin][$tgl]['pulang'] === null || $jam > $absen_detail[$pin][$tgl]['pulang']) {
            $absen_detail[$pin][$tgl]['pulang'] = $jam;
        }
    }
}

// Rekap Data
$rekap_data = [];
$log_diluar_jadwal_list = [];

if ($res_master->num_rows > 0) {
    while ($m = $res_master->fetch_assoc()) {
        $pin       = $m['pin'];
        $nama      = $m['nama'];
        $dept      = $m['departemen'];
        $tipe      = $m['tipe'];
        $hari_arr  = !empty($m['list_hari']) ? array_map('intval', explode(',', $m['list_hari'])) : [];
        $is_guru   = ($tipe === 'guru');

        $total_hadir_sesuai = 0;
        $total_hadir_diluar = 0;

        $target_hari = $is_guru 
            ? get_target_hari_guru($conn, $pin, $tahun, $bulan) 
            : $hari_kerja_karyawan_default;

        $tgl_logs = $absen_data[$pin] ?? [];

        foreach ($tgl_logs as $tgl_str => $hari_num) {
            if ($is_guru) {
                if (empty($hari_arr)) {
                    $total_hadir_diluar++;
                } elseif (in_array($hari_num, $hari_arr)) {
                    $total_hadir_sesuai++;
                } else {
                    $total_hadir_diluar++;
                    $log_diluar_jadwal_list[] = [
                        'pin' => $pin,
                        'nama' => $nama,
                        'dept' => $dept,
                        'waktu' => $tgl_str,
                        'hari_nama' => ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][$hari_num-1] ?? ''
                    ];
                }
            } else {
                if ($hari_num !== 7) {
                    $total_hadir_sesuai++;
                }
            }
        }

        $persen = ($target_hari > 0) ? round(($total_hadir_sesuai / $target_hari) * 100, 1) : 0;

        $rekap_data[] = [
            'pin' => $pin,
            'nama' => $nama,
            'dept' => $dept,
            'tipe' => $tipe,
            'target_hari' => $target_hari,
            'hadir_sesuai' => $total_hadir_sesuai,
            'hadir_diluar' => $total_hadir_diluar,
            'persen' => $persen,
            'list_hari' => $hari_arr
        ];
    }
}

// Sorting
usort($rekap_data, function($a, $b) use ($sort) {
    switch ($sort) {
        case 'pin_desc': return ((int)$b['pin'] <=> (int)$a['pin']) ?: strcmp($b['pin'], $a['pin']);
        case 'nama_asc': return strcasecmp($a['nama'], $b['nama']);
        case 'nama_desc': return strcasecmp($b['nama'], $a['nama']);
        case 'persen_desc': return ($b['persen'] <=> $a['persen']) ?: ((int)$a['pin'] <=> (int)$b['pin']);
        case 'persen_asc': return ($a['persen'] <=> $b['persen']) ?: ((int)$a['pin'] <=> (int)$b['pin']);
        case 'target_desc': return ($b['target_hari'] <=> $a['target_hari']) ?: ((int)$a['pin'] <=> (int)$b['pin']);
        case 'hadir_desc': return ($b['hadir_sesuai'] <=> $a['hadir_sesuai']) ?: ((int)$a['pin'] <=> (int)$b['pin']);
        case 'tipe_desc': return strcmp($b['tipe'], $a['tipe']) ?: ((int)$a['pin'] <=> (int)$b['pin']);
        case 'tipe_asc': return strcmp($a['tipe'], $b['tipe']) ?: ((int)$a['pin'] <=> (int)$b['pin']);
        case 'pin_asc': default: return ((int)$a['pin'] <=> (int)$a['pin']) ?: strcmp($a['pin'], $b['pin']);
    }
});

$filename = "Laporan_Matriks_Evaluasi_Absensi_{$nama_bulan[$bulan]}_{$tahun}.xls";
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
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th { background-color: #1e293b; color: #ffffff; font-weight: bold; border: 1px solid #0f172a; padding: 6px; text-align: center; font-size: 9pt; }
        td { border: 1px solid #cbd5e1; padding: 5px; font-size: 8.5pt; text-align: center; }
        .text-left { text-align: left; }
        
        /* CELL COLORS */
        .cell-green { background-color: #dcfce7; color: #166534; font-weight: bold; }
        .cell-yellow { background-color: #fef9c3; color: #854d0e; font-weight: bold; }
        .cell-red { background-color: #fee2e2; color: #991b1b; font-weight: bold; }
        .cell-gray { background-color: #f1f5f9; color: #94a3b8; }
        .badge-green { color: #15803d; font-weight: bold; }
        .badge-yellow { color: #b45309; font-weight: bold; }
        .badge-red { color: #be123c; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header-title">LAPORAN EVALUASI & MATRIKS KEHADIRAN BULANAN</div>
    <div class="header-school">SMK PASUNDAN 2 BANDUNG</div>
    <div style="text-align:center; font-size:10pt; margin-bottom:12px;">
        <b>Periode:</b> <?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?> | 
        <b>Kategori:</b> <?php echo ucfirst($kategori); ?> | 
        <b>Hari Kerja Karyawan:</b> <?php echo $hari_kerja_karyawan_default; ?> Hari
    </div>

    <!-- LEGEND KETERANGAN WARNA MATRIKS -->
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
                <th style="background-color:#0f172a;">Target Hari</th>
                <th style="background-color:#166534;">Hadir Sesuai</th>
                <th style="background-color:#c2410c;">Hadir di Luar</th>
                <th style="background-color:#1d4ed8;">% Kehadiran</th>
                <th style="background-color:#475569;">Status Evaluasi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (!empty($rekap_data)) {
                foreach ($rekap_data as $r) {
                    $pin      = $r['pin'];
                    $nama     = $r['nama'];
                    $dept     = $r['dept'];
                    $tipe     = $r['tipe'];
                    $hari_arr = $r['list_hari'];
                    $is_guru  = ($tipe === 'guru');

                    $eval_text = "🟢 Baik (≥90%)";
                    $eval_class = "badge-green";
                    if ($r['target_hari'] == 0) {
                        $eval_text = "❓ Belum Ada Jadwal";
                        $eval_class = "";
                    } elseif ($r['persen'] < 80) {
                        $eval_text = "🔴 Perlu Perhatian (<80%)";
                        $eval_class = "badge-red";
                    } elseif ($r['persen'] < 90) {
                        $eval_text = "🟡 Cukup (80-89%)";
                        $eval_class = "badge-yellow";
                    }

                    echo "<tr>";
                    echo "<td>'" . h($pin) . "</td>";
                    echo "<td class='text-left'>" . h($nama) . "</td>";
                    echo "<td class='text-left'>" . h($dept) . "</td>";
                    echo "<td>" . ucfirst($tipe) . "</td>";

                    for ($d = 1; $d <= $total_hari; $d++) {
                        $tgl_key  = sprintf("%04d-%02d-%02d", $tahun, $bulan, $d);
                        $day_num  = (int)date('N', strtotime($tgl_key));

                        $has_sch = $is_guru ? in_array($day_num, $hari_arr) : ($day_num !== 7);
                        $log_today = $absen_detail[$pin][$tgl_key] ?? null;

                        if ($log_today !== null) {
                            if ($log_today['masuk'] && $log_today['pulang']) {
                                echo "<td class='cell-green'>✔️</td>";
                            } else {
                                echo "<td class='cell-yellow'>⚠️</td>";
                            }
                        } else {
                            if ($has_sch) {
                                echo "<td class='cell-red'>❌</td>";
                            } else {
                                echo "<td class='cell-gray'>-</td>";
                            }
                        }
                    }

                    echo "<td>{$r['target_hari']} hr</td>";
                    echo "<td><b>{$r['hadir_sesuai']} hr</b></td>";
                    echo "<td>" . ($r['hadir_diluar'] > 0 ? "{$r['hadir_diluar']} hr" : "-") . "</td>";
                    echo "<td><b>{$r['persen']}%</b></td>";
                    echo "<td class='{$eval_class}'>{$eval_text}</td>";
                    echo "</tr>";
                }
            }
            ?>
        </tbody>
    </table>

    <?php if (!empty($log_diluar_jadwal_list)): ?>
    <br>
    <div style="font-size:11pt; font-weight:bold; color:#c2410c; margin-bottom:8px;">⚠️ RINCIAN GURU ABSEN DI LUAR HARI JADWAL NGAJAR</div>
    <table>
        <thead>
            <tr style="background:#ffedd5; color:#9a3412;">
                <th>PIN</th>
                <th>Nama Guru</th>
                <th>Departemen</th>
                <th>Tanggal Absen</th>
                <th>Hari</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($log_diluar_jadwal_list as $ld) {
                echo "<tr>
                        <td>'" . h($ld['pin']) . "</td>
                        <td class='text-left'>" . h($ld['nama']) . "</td>
                        <td class='text-left'>" . h($ld['dept']) . "</td>
                        <td>" . h($ld['waktu']) . "</td>
                        <td>" . h($ld['hari_nama']) . " (Di luar jadwal)</td>
                      </tr>";
            }
            ?>
        </tbody>
    </table>
    <?php endif; ?>
</body>
</html>
