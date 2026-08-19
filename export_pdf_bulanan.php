<?php
// ============================================================
// EXPORT LAPORAN BULANAN KE FORMAT PDF / CETAK RESMI
// Kop Surat Resmi SMK Pasundan 2 Bandung + Matriks Evaluasi Kehadiran
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
if (!can_access_page('export_pdf') && !is_superadmin()) {
    header("Location: index.php?error=access_denied");
    exit;
}

$bulan    = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$tahun    = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$kategori = isset($_GET['kategori']) ? $_GET['kategori'] : 'semua';

if (is_tatausaha()) {
    $kategori = 'karyawan';
}

$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$str_bulan = $nama_bulan[$bulan] ?? '';
$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);

$conn = getDB();

// Fetch Hari Libur Kalender
$hari_libur = [];
$res_hl = $conn->query("SELECT DAY(tanggal) as tgl, keterangan FROM hari_libur WHERE MONTH(tanggal) = $bulan AND YEAR(tanggal) = $tahun");
if ($res_hl) {
    while ($row = $res_hl->fetch_assoc()) {
        $hari_libur[(int)$row['tgl']] = $row['keterangan'];
    }
}

// Fetch Perizinan (Cuti / Izin / Sakit) - Hanya yang telah DISETUJUI
$perizinan = [];
$res_iz = $conn->query("SELECT pin, DAY(tanggal) as tgl, tipe_izin, keterangan FROM perizinan WHERE MONTH(tanggal) = $bulan AND YEAR(tanggal) = $tahun AND (status_persetujuan = 'disetujui' OR status_persetujuan IS NULL)");
if ($res_iz) {
    while ($row = $res_iz->fetch_assoc()) {
        $perizinan[$row['pin']][(int)$row['tgl']] = $row;
    }
}

// Fetch Master Karyawan
$where_kat = "";
if ($kategori === 'guru') $where_kat = "WHERE tipe = 'guru'";
elseif ($kategori === 'karyawan') $where_kat = "WHERE tipe = 'karyawan'";

$master = [];
$res_m = $conn->query("SELECT pin, nama, departemen, tipe FROM master_karyawan $where_kat ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC");
if ($res_m) {
    while ($row = $res_m->fetch_assoc()) {
        $master[$row['pin']] = $row;
    }
}

// Fetch Log Absen
$log_absen = [];
$res_l = $conn->query("SELECT pin, DAY(waktu) as tgl, status FROM log_absen WHERE MONTH(waktu) = $bulan AND YEAR(waktu) = $tahun");
if ($res_l) {
    while ($row = $res_l->fetch_assoc()) {
        $p = $row['pin'];
        $d = (int)$row['tgl'];
        $st = (int)$row['status'];
        if (!isset($log_absen[$p][$d])) {
            $log_absen[$p][$d] = ['masuk' => false, 'pulang' => false];
        }
        if ($st === 0) $log_absen[$p][$d]['masuk'] = true;
        if ($st === 1) $log_absen[$p][$d]['pulang'] = true;
    }
}

log_audit("EXPORT_PDF_BULANAN", "Export PDF Laporan Bulanan {$str_bulan} {$tahun} (Kategori: {$kategori})");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan_Absensi_Bulanan_<?php echo $str_bulan; ?>_<?php echo $tahun; ?></title>
    <style>
        @page { size: A4 landscape; margin: 10mm 12mm 12mm 12mm; }
        body { font-family: 'Times New Roman', Times, serif; color: #000; background: #fff; margin: 0; padding: 0; font-size: 11px; }
        
        /* KOP SURAT OFISIAL */
        .header-kop { display: flex; align-items: center; justify-content: center; gap: 15px; padding-bottom: 4px; margin-bottom: 2px; }
        .logo-kop { width: 85px; height: 85px; flex-shrink: 0; }
        .logo-kop img { width: 100%; height: 100%; object-fit: contain; }
        .text-kop { text-align: center; flex: 1; }
        .text-kop .line-1 { font-size: 12pt; font-weight: bold; color: #000; letter-spacing: 0.2px; margin: 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-2 { font-size: 15pt; font-weight: 800; color: #1e3a8a; letter-spacing: 0.5px; margin: 2px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-3 { font-size: 9.5pt; font-weight: bold; color: #000; margin: 2px 0 1px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-jurusan { font-size: 9pt; font-weight: bold; color: #991b1b; line-height: 1.2; margin: 1px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-alamat { font-size: 9.5pt; font-weight: bold; color: #000; margin-top: 3px; font-family: 'Arial', sans-serif; }
        .text-kop .line-web { font-size: 9pt; color: #000; margin-top: 1px; font-family: 'Arial', sans-serif; }
        .double-line { border: none; border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-bottom: 14px; }

        .title-report { text-align: center; margin-bottom: 12px; }
        .title-report h4 { margin: 0; font-size: 13pt; text-transform: uppercase; text-decoration: underline; font-family: 'Arial', sans-serif; }
        .title-report p { margin: 3px 0 0 0; font-size: 11pt; font-weight: bold; font-family: 'Arial', sans-serif; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 9.5pt; }
        th, td { border: 1px solid #000; padding: 4px 3px; text-align: center; vertical-align: middle; }
        th { background: #e2e8f0; font-weight: bold; font-family: 'Arial', sans-serif; }
        .bg-hol { background: #fca5a5 !important; color: #7f1d1d; }
        .bg-cuti { background: #bae6fd !important; color: #0369a1; }
        .bg-izin { background: #fde68a !important; color: #92400e; }
        .bg-sakit { background: #e9d5ff !important; color: #6b21a8; }
        .bg-alpa { background: #fecdd3 !important; color: #9f1239; }

        .ttd-box { margin-top: 18px; display: flex; justify-content: space-between; page-break-inside: avoid; font-family: 'Arial', sans-serif; }
        .ttd-col { text-align: center; width: 230px; }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print" style="background:#f1f5f9; padding:10px 20px; border-bottom:1px solid #cbd5e1; display:flex; justify-content:space-between; align-items:center;">
    <div><b>📄 Preview Laporan PDF Official</b> (Kop Surat Resmi SMK Pasundan 2 Bandung)</div>
    <button onclick="window.print()" style="background:#2563eb; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">🖨️ Cetak / Simpan PDF</button>
</div>

<div style="padding: 10px 15px;">
    <!-- KOP SURAT SEKOLAH OFISIAL -->
    <div class="header-kop">
        <div class="logo-kop">
            <img src="assets/logo_pasundan2.png" alt="Logo SMK Pasundan 2 Bandung">
        </div>
        <div class="text-kop">
            <div class="line-1">YAYASAN PENDIDIKAN DASAR DAN MENENGAH PASUNDAN</div>
            <div class="line-2">SEKOLAH MENENGAH KEJURUAN PASUNDAN 2 BANDUNG</div>
            <div class="line-3">KOMPETENSI KEAHLIAN :</div>
            <div class="line-jurusan">TEKNIK PEMESINAN (TERAKREDITASI A) &nbsp;&nbsp;&nbsp;&nbsp; TEKNIK KENDARAAN RINGAN (TERAKREDITASI A)</div>
            <div class="line-jurusan">TEKNIK AUDIO VIDEO (TERAKREDITASI A) &nbsp;&nbsp;&nbsp;&nbsp; TEKNIK KOMPUTER JARINGAN (TERAKREDITASI A)</div>
            <div class="line-jurusan">TEKNIK SEPEDA MOTOR (TERAKREDITASI A)</div>
            <div class="line-alamat">Jl. Pelita Karya I No. 2 Telp/Fax. (022) 6034059 Maleber Barat - Bandung 40184</div>
            <div class="line-web">Web Site : http://www.smkpasundan2bdg.org &nbsp;&nbsp;&nbsp;&nbsp; e-mail : smkpas2bdg@yahoo.com</div>
        </div>
    </div>
    <div class="double-line"></div>

    <!-- JUDUL LAPORAN -->
    <div class="title-report">
        <h4>REKAPITULASI EVALUASI KEHADIRAN PRESENSI BULANAN</h4>
        <p>PERIODE: <?php echo strtoupper($str_bulan); ?> <?php echo $tahun; ?></p>
    </div>

    <!-- MATRIKS TABEL -->
    <table>
        <thead>
            <tr>
                <th rowspan="2" style="width:25px;">NO</th>
                <th rowspan="2" style="width:40px;">PIN</th>
                <th rowspan="2" style="text-align:left; min-width:130px;">NAMA GURU / KARYAWAN</th>
                <th rowspan="2">DEPT</th>
                <th colspan="<?php echo $jumlah_hari; ?>">TANGGAL PERIODE BULAN <?php echo strtoupper($str_bulan); ?></th>
                <th colspan="4">REKAPITULASI</th>
            </tr>
            <tr>
                <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                    <th style="width:16px; font-size:8.5pt;"><?php echo $d; ?></th>
                <?php endfor; ?>
                <th>HADIR</th>
                <th>CUTI</th>
                <th>IZIN</th>
                <th>SAKIT</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($master as $pin => $emp):
                $hadir_cnt = 0; $cuti_cnt = 0; $izin_cnt = 0; $sakit_cnt = 0;
            ?>
            <tr>
                <td><?php echo $no++; ?></td>
                <td><code><?php echo h($pin); ?></code></td>
                <td style="text-align:left; font-weight:bold;"><?php echo h($emp['nama']); ?></td>
                <td><?php echo h($emp['departemen'] ?: '-'); ?></td>

                <?php for ($d = 1; $d <= $jumlah_hari; $d++): 
                    $tgl_str = sprintf('%04d-%02d-%02d', $tahun, $bulan, $d);
                    $day_of_week = date('N', strtotime($tgl_str));
                    $is_holiday = isset($hari_libur[$d]) || $day_of_week == 7;

                    $cell_text = 'A';
                    $cell_class = '';

                    if (isset($perizinan[$pin][$d])) {
                        $iz_t = $perizinan[$pin][$d]['tipe_izin'];
                        if ($iz_t === 'cuti') { $cell_text = 'C'; $cell_class = 'bg-cuti'; $cuti_cnt++; }
                        elseif ($iz_t === 'izin') { $cell_text = 'I'; $cell_class = 'bg-izin'; $izin_cnt++; }
                        elseif ($iz_t === 'sakit') { $cell_text = 'S'; $cell_class = 'bg-sakit'; $sakit_cnt++; }
                    } elseif (isset($log_absen[$pin][$d])) {
                        $cell_text = 'H';
                        $cell_class = '';
                        $hadir_cnt++;
                    } elseif ($is_holiday) {
                        $cell_text = 'L';
                        $cell_class = 'bg-hol';
                    } else {
                        $cell_class = 'bg-alpa';
                    }
                ?>
                    <td class="<?php echo $cell_class; ?>" style="font-weight:bold;"><?php echo $cell_text; ?></td>
                <?php endfor; ?>

                <td style="font-weight:bold; color:#15803d;"><?php echo $hadir_cnt; ?></td>
                <td style="font-weight:bold; color:#0369a1;"><?php echo $cuti_cnt; ?></td>
                <td style="font-weight:bold; color:#92400e;"><?php echo $izin_cnt; ?></td>
                <td style="font-weight:bold; color:#6b21a8;"><?php echo $sakit_cnt; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- KETERANGAN KODE -->
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div style="font-size:9.5pt; line-height:1.6; font-family:'Arial', sans-serif;">
            <b>Keterangan Kode:</b><br>
            • <b>H</b> = Hadir &nbsp;&nbsp; • <b>C</b> = Cuti &nbsp;&nbsp; • <b>I</b> = Izin &nbsp;&nbsp; • <b>S</b> = Sakit &nbsp;&nbsp; • <b>L</b> = Libur &nbsp;&nbsp; • <b>A</b> = Alpa / Belum Absen
        </div>
    </div>
    <br>
    <br>
    <br>
    <!-- BLOK TANDA TANGAN -->
    <div class="ttd-box">
        <div class="ttd-col">
            <p style="margin:0; line-height:1.4;">Mengetahui,<br><b>Kepala SMK Pasundan 2 Bandung</b></p>
            <div style="height:50px;"></div>
            <p style="margin:0; line-height:1.4;"><b><u>Umar Khatob, S.Pd, M.Si.</u></b><br>NIP. -</p>
        </div>
        <div class="ttd-col" style="margin-left:50px;">
            <p style="margin:0; line-height:1.4;">Bandung, <?php echo date('d') . ' ' . $str_bulan . ' ' . $tahun; ?><br><b>Administrator System</b></p>
            <div style="height:50px;"></div>
            <p style="margin:0; line-height:1.4;"><b><u>Indra Setia Budi</u></b><br></p>
        </div>
    </div>
</div>

<script>
window.onload = function() {
    if (window.location.search.includes('auto_print=1')) {
        setTimeout(() => window.print(), 500);
    }
};
</script>

</body>
</html>
