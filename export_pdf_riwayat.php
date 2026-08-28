<?php
// ============================================================
// EXPORT RIWAYAT INDIVIDUAL KE FORMAT PDF / CETAK RESMI
// Kop Surat Resmi SMK Pasundan 2 Bandung + Rekap Presensi Individual
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
if (!can_access_page('export_pdf') && !is_superadmin()) {
    header("Location: index.php?error=access_denied");
    exit;
}

$pin        = trim($_GET['pin'] ?? '');
$tgl_dari   = trim($_GET['tgl_dari'] ?? date('Y-m-01'));
$tgl_sampai = trim($_GET['tgl_sampai'] ?? date('Y-m-d'));

if (empty($pin)) {
    die("Error: PIN Karyawan wajib dipilih!");
}

$conn = getDB();
$app_settings = get_app_settings();

// Fetch Data Karyawan
$stmt_emp = $conn->prepare("SELECT pin, nama, departemen, tipe FROM master_karyawan WHERE pin = ?");
$stmt_emp->bind_param("s", $pin);
$stmt_emp->execute();
$emp = $stmt_emp->get_result()->fetch_assoc();

if (!$emp) {
    die("Error: Data Karyawan dengan PIN {$pin} tidak ditemukan!");
}

// Fetch Log Absen Individual
$stmt_log = $conn->prepare("SELECT waktu, status, tipe_verifikasi FROM log_absen WHERE pin = ? AND DATE(waktu) BETWEEN ? AND ? ORDER BY waktu ASC");
$stmt_log->bind_param("sss", $pin, $tgl_dari, $tgl_sampai);
$stmt_log->execute();
$logs = $stmt_log->get_result()->fetch_all(MYSQLI_ASSOC);

log_audit("EXPORT_PDF_RIWAYAT", "Export PDF Riwayat Individual PIN {$pin} ({$emp['nama']}) periode {$tgl_dari} s/d {$tgl_sampai}");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Riwayat_Absensi_<?php echo h($pin); ?>_<?php echo h(str_replace(' ', '_', $emp['nama'])); ?></title>
    <style>
        @page { size: A4 portrait; margin: 12mm 15mm 15mm 15mm; }
        body { font-family: 'Times New Roman', Times, serif; color: #000; background: #fff; margin: 0; padding: 0; font-size: 11px; }
        
        /* KOP SURAT OFISIAL */
        .header-kop { display: flex; align-items: center; justify-content: center; gap: 15px; padding-bottom: 4px; margin-bottom: 2px; }
        .logo-kop { width: 85px; height: 85px; flex-shrink: 0; }
        .logo-kop img { width: 100%; height: 100%; object-fit: contain; }
        .text-kop { text-align: center; flex: 1; }
        .text-kop .line-1 { font-size: 11pt; font-weight: bold; color: #000; letter-spacing: 0.2px; margin: 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-2 { font-size: 14pt; font-weight: 800; color: #1e3a8a; letter-spacing: 0.5px; margin: 2px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-3 { font-size: 9pt; font-weight: bold; color: #000; margin: 2px 0 1px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-jurusan { font-size: 8.5pt; font-weight: bold; color: #991b1b; line-height: 1.2; margin: 1px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-alamat { font-size: 9pt; font-weight: bold; color: #000; margin-top: 3px; font-family: 'Arial', sans-serif; }
        .text-kop .line-web { font-size: 8.5pt; color: #000; margin-top: 1px; font-family: 'Arial', sans-serif; }
        .double-line { border: none; border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-bottom: 14px; }

        .title-report { text-align: center; margin-bottom: 15px; }
        .title-report h4 { margin: 0; font-size: 12pt; text-transform: uppercase; text-decoration: underline; font-family: 'Arial', sans-serif; }

        .profile-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; display: flex; justify-content: space-between; font-family: 'Arial', sans-serif; font-size: 9.5pt; }
        .profile-col { line-height: 1.6; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10pt; }
        th, td { border: 1px solid #000; padding: 5px 6px; text-align: center; vertical-align: middle; }
        th { background: #e2e8f0; font-weight: bold; font-family: 'Arial', sans-serif; }

        .ttd-box { margin-top: 25px; display: flex; justify-content: space-between; page-break-inside: avoid; font-family: 'Arial', sans-serif; }
        .ttd-col { text-align: center; width: 220px; }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print" style="background:#f1f5f9; padding:10px 20px; border-bottom:1px solid #cbd5e1; display:flex; justify-content:space-between; align-items:center;">
    <div><b>📄 Preview Laporan PDF Official Individual</b> (Kop Surat Resmi SMK Pasundan 2 Bandung)</div>
    <button onclick="window.print()" style="background:#2563eb; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">🖨️ Cetak / Simpan PDF</button>
</div>

<div style="padding: 10px 15px;">
    <!-- KOP SURAT SEKOLAH OFISIAL -->
    <?php echo render_kop_surat_html($app_settings); ?>

    <!-- JUDUL -->
    <div class="title-report">
        <h4>LAPORAN RIWAYAT PRESENSI INDIVIDUAL GURU & KARYAWAN</h4>
    </div>

    <!-- BIODATA KARYAWAN -->
    <div class="profile-box">
        <div class="profile-col">
            <b>PIN:</b> <?php echo h($emp['pin']); ?><br>
            <b>Nama Lengkap:</b> <?php echo h($emp['nama']); ?>
        </div>
        <div class="profile-col">
            <b>Departemen:</b> <?php echo h($emp['departemen'] ?: '-'); ?><br>
            <b>Kategori:</b> <?php echo ucfirst($emp['tipe']); ?>
        </div>
        <div class="profile-col">
            <b>Periode:</b> <?php echo date('d/m/Y', strtotime($tgl_dari)); ?> s/d <?php echo date('d/m/Y', strtotime($tgl_sampai)); ?><br>
            <b>Total Log:</b> <?php echo count($logs); ?> Record
        </div>
    </div>

    <!-- TABEL LOG LOG ABSEN -->
    <table>
        <thead>
            <tr>
                <th style="width:30px;">NO</th>
                <th>TANGGAL & WAKTU (WIB)</th>
                <th>STATUS PRESENSI</th>
                <th>METODE VERIFIKASI</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($logs)): 
                $no = 1;
                foreach ($logs as $l):
                    $st_text = $l['status'] == 0 ? 'MASUK' : 'PULANG';
                    $ver_text = 'Sidik Jari';
                    if ($l['tipe_verifikasi'] == '15') $ver_text = 'Wajah';
                    elseif ($l['tipe_verifikasi'] == '99') $ver_text = 'Manual Admin';
            ?>
                <tr>
                    <td><?php echo $no++; ?></td>
                    <td style="font-weight:bold;"><?php echo date('d/m/Y H:i:s', strtotime($l['waktu'])); ?></td>
                    <td style="font-weight:bold; color:<?php echo $l['status'] == 0 ? '#15803d' : '#be123c'; ?>;"><?php echo $st_text; ?></td>
                    <td><?php echo $ver_text; ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td colspan="4" style="padding:20px; color:#64748b;">Belum ada log presensi untuk periode tanggal ini.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- TANDA TANGAN -->
    <div class="ttd-box">
        <div class="ttd-col">
            <p>Mengetahui,<br><b><?php echo h($app_settings['jabatan_kepsek'] ?? 'Kepala Sekolah'); ?> <?php echo h($app_settings['nama_sekolah'] ?? ''); ?></b></p>
            <br><br><br>
            <p><b><u><?php echo h($app_settings['nama_kepsek'] ?? 'Umar Khatob, S.Pd, M.Si.'); ?></u></b><br><?php echo (!empty($app_settings['nip_kepsek']) && $app_settings['nip_kepsek'] !== '-') ? 'NIP. ' . h($app_settings['nip_kepsek']) : ''; ?></p>
        </div>
        <div class="ttd-col">
            <p><?php echo h($app_settings['kota_surat'] ?? 'Bandung'); ?>, <?php echo date('d') . ' ' . (['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][(int)date('n')]) . ' ' . date('Y'); ?><br><b>Yang Bersangkutan,</b></p>
            <br><br><br>
            <p><b><u><?php echo h($emp['nama']); ?></u></b><br>PIN: <?php echo h($emp['pin']); ?></p>
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
