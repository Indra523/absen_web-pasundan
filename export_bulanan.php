<?php
// ============================================================
// HALAMAN LAPORAN EVALUASI ABSENSI BULANAN (Dengan Auto-Sync Libur Nasional API)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('export_bulanan')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();

// Auto Create Table hari_libur jika belum ada
$conn->query("CREATE TABLE IF NOT EXISTS hari_libur (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tanggal DATE NOT NULL UNIQUE,
    keterangan VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pesan_sukses = '';
$pesan_error  = '';

// -------------------------------------------------------
// HELPER: SINKRONISASI OTOMATIS HARI LIBUR NASIONAL (API + FALLBACK)
// -------------------------------------------------------
function sync_hari_libur_nasional($tahun, $bulan, $conn) {
    $year_str  = sprintf("%04d", $tahun);
    $month_str = sprintf("%02d", $bulan);
    
    // 1. Primary API: https://api-hari-libur.vercel.app/api?year=YYYY&month=MM
    $url = "https://api-hari-libur.vercel.app/api?year={$tahun}&month={$bulan}";
    
    $added_count = 0;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3-second timeout untuk proteksi performa
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($res && $http_code === 200) {
        $data = json_decode($res, true);
        if (is_array($data)) {
            foreach ($data as $item) {
                $tgl = $item['holiday_date'] ?? $item['tanggal'] ?? '';
                $ket = $item['holiday_name'] ?? $item['keterangan'] ?? '';
                $is_nat = $item['is_national_holiday'] ?? true;
                
                if (!empty($tgl) && !empty($ket) && $is_nat) {
                    $stmt = $conn->prepare("INSERT INTO hari_libur (tanggal, keterangan) VALUES (?, ?) ON DUPLICATE KEY UPDATE keterangan = VALUES(keterangan)");
                    $stmt->bind_param("ss", $tgl, $ket);
                    if ($stmt->execute()) {
                        $added_count++;
                    }
                }
            }
        }
    }
    
    // 2. Offline Fallback for Fixed Annual Indonesian Holidays (1 Jan, 1 Mei, 1 Jun, 17 Ags, 25 Des)
    $fixed_holidays = [
        "{$year_str}-01-01" => "Tahun Baru Masehi",
        "{$year_str}-05-01" => "Hari Buruh Internasional",
        "{$year_str}-06-01" => "Hari Lahir Pancasila",
        "{$year_str}-08-17" => "Hari Kemerdekaan RI",
        "{$year_str}-12-25" => "Hari Raya Natal",
    ];
    
    foreach ($fixed_holidays as $f_tgl => $f_ket) {
        if ((int)date('n', strtotime($f_tgl)) === (int)$bulan) {
            $stmt_f = $conn->prepare("INSERT IGNORE INTO hari_libur (tanggal, keterangan) VALUES (?, ?)");
            $stmt_f->bind_param("ss", $f_tgl, $f_ket);
            $stmt_f->execute();
        }
    }
    
    return $added_count;
}

// -------------------------------------------------------
// POST HANDLER KELOLA HARI LIBUR (SUPERADMIN ONLY)
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_superadmin()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah_hari_libur') {
        $tgl_libur = trim($_POST['tgl_libur'] ?? '');
        $ket_libur = trim($_POST['ket_libur'] ?? '');

        if (empty($tgl_libur) || empty($ket_libur)) {
            $pesan_error = 'Tanggal dan Keterangan Libur tidak boleh kosong.';
        } else {
            $stmt_hl = $conn->prepare("INSERT INTO hari_libur (tanggal, keterangan) VALUES (?, ?) ON DUPLICATE KEY UPDATE keterangan = VALUES(keterangan)");
            $stmt_hl->bind_param("ss", $tgl_libur, $ket_libur);
            if ($stmt_hl->execute()) {
                $pesan_sukses = "✅ Hari libur <b>" . h($tgl_libur) . " (" . h($ket_libur) . ")</b> berhasil disimpan.";
            } else {
                $pesan_error = "Gagal menyimpan hari libur: " . $conn->error;
            }
        }
    } elseif ($action === 'hapus_hari_libur') {
        $id_hl = (int)($_POST['id_hl'] ?? 0);
        if ($id_hl > 0) {
            $stmt_del = $conn->prepare("DELETE FROM hari_libur WHERE id = ?");
            $stmt_del->bind_param("i", $id_hl);
            if ($stmt_del->execute()) {
                $pesan_sukses = "✅ Hari libur berhasil dihapus.";
            } else {
                $pesan_error = "Gagal menghapus hari libur: " . $conn->error;
            }
        }
    } elseif ($action === 'sync_nasional') {
        $b_sync = (int)($_POST['bulan'] ?? date('n'));
        $t_sync = (int)($_POST['tahun'] ?? date('Y'));
        sync_hari_libur_nasional($t_sync, $b_sync, $conn);
        $pesan_sukses = "✅ Sinkronisasi Hari Libur Nasional Kalender Indonesia berhasil diperbarui!";
    }
}

// Input Parameter Bulan & Tahun (Default: Bulan & Tahun Saat Ini)
$bulan    = (int)($_GET['bulan'] ?? date('n'));
$tahun    = (int)($_GET['tahun'] ?? date('Y'));
$kategori = $_GET['kategori'] ?? 'semua'; // 'semua', 'karyawan', 'guru'
$sort     = $_GET['sort'] ?? 'pin_asc';

// KHUSUS ROLE TATAUSAHA: Hanya tampilkan kategori Karyawan saja
if (is_tatausaha()) {
    $kategori = 'karyawan';
}

if ($bulan < 1 || $bulan > 12) $bulan = (int)date('n');
if ($tahun < 2020 || $tahun > 2050) $tahun = (int)date('Y');

// Auto-Sync Hari Libur Nasional Kalender Indonesia Saat Halaman Di-Load
sync_hari_libur_nasional($tahun, $bulan, $conn);

$total_hari_bulan = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);

// Ambil Daftar Hari Libur Kalender di Bulan & Tahun ini
$start_hl = sprintf("%04d-%02d-01", $tahun, $bulan);
$end_hl   = sprintf("%04d-%02d-%02d", $tahun, $bulan, $total_hari_bulan);

$hari_libur_map = [];
$sql_hl = "SELECT * FROM hari_libur WHERE tanggal >= ? AND tanggal <= ? ORDER BY tanggal ASC";
$stmt_hl = $conn->prepare($sql_hl);
if ($stmt_hl) {
    $stmt_hl->bind_param("ss", $start_hl, $end_hl);
    $stmt_hl->execute();
    $res_hl = $stmt_hl->get_result();
    while ($hl = $res_hl->fetch_assoc()) {
        $hari_libur_map[$hl['tanggal']] = $hl;
    }
}

// Helper: Hitung total hari kerja karyawan di bulan tsb (semua hari kecuali Minggu & Hari Libur Kalender)
function get_hari_kerja_karyawan($thn, $bln, $hari_libur_map = []) {
    $total_hari = cal_days_in_month(CAL_GREGORIAN, $bln, $thn);
    $count = 0;
    for ($d = 1; $d <= $total_hari; $d++) {
        $tgl_str = sprintf("%04d-%02d-%02d", $thn, $bln, $d);
        $w = (int)date('N', mktime(0, 0, 0, $bln, $d, $thn)); // 1=Senin...7=Minggu
        if ($w !== 7 && !isset($hari_libur_map[$tgl_str])) {
            $count++;
        }
    }
    return $count;
}

// Helper: Hitung target hari ngajar guru di bulan tsb sesuai jadwal & dikurangi Hari Libur Kalender
function get_target_hari_guru($conn, $pin, $thn, $bln, $hari_libur_map = []) {
    $stmt = $conn->prepare("SELECT hari FROM jadwal_guru WHERE pin = ?");
    $stmt->bind_param("s", $pin);
    $stmt->execute();
    $res = $stmt->get_result();
    $hari_ngajar = [];
    while ($r = $res->fetch_assoc()) {
        $hari_ngajar[] = (int)$r['hari'];
    }

    if (empty($hari_ngajar)) return 0; // Belum ada jadwal

    $total_hari = cal_days_in_month(CAL_GREGORIAN, $bln, $thn);
    $count = 0;
    for ($d = 1; $d <= $total_hari; $d++) {
        $tgl_str = sprintf("%04d-%02d-%02d", $thn, $bln, $d);
        $w = (int)date('N', mktime(0, 0, 0, $bln, $d, $thn)); // 1=Senin...6=Sabtu, 7=Minggu
        if (in_array($w, $hari_ngajar) && !isset($hari_libur_map[$tgl_str])) {
            $count++;
        }
    }
    return $count;
}

$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$hari_kerja_karyawan_default = get_hari_kerja_karyawan($tahun, $bulan, $hari_libur_map);

// Query Data Master Karyawan
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
$end_date   = sprintf("%04d-%02d-%02d 23:59:59", $tahun, $bulan, $total_hari_bulan);

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

    if (!isset($absen_data[$pin])) {
        $absen_data[$pin] = [];
    }
    if (!isset($absen_data[$pin][$tgl])) {
        $absen_data[$pin][$tgl] = (int)$l['hari_num'];
    }

    if (!isset($absen_detail[$pin])) {
        $absen_detail[$pin] = [];
    }
    if (!isset($absen_detail[$pin][$tgl])) {
        $absen_detail[$pin][$tgl] = ['masuk' => null, 'pulang' => null];
    }

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

// Ambil semua data perizinan yang disetujui (Cuti, Izin, Sakit) di bulan & tahun ini
$start_p = sprintf("%04d-%02d-01", $tahun, $bulan);
$end_p   = sprintf("%04d-%02d-%02d", $tahun, $bulan, $total_hari_bulan);

$izin_map = [];
$sql_p = "SELECT pin, tanggal, COALESCE(tgl_selesai, tanggal) AS tgl_selesai, tipe_izin, keterangan FROM perizinan WHERE tanggal <= ? AND COALESCE(tgl_selesai, tanggal) >= ? AND status_persetujuan = 'disetujui'";
$stmt_p = $conn->prepare($sql_p);
if ($stmt_p) {
    $stmt_p->bind_param("ss", $end_p, $start_p);
    $stmt_p->execute();
    $res_p = $stmt_p->get_result();
    while ($rp = $res_p->fetch_assoc()) {
        $p_pin  = $rp['pin'];
        $p_from = max($start_p, $rp['tanggal']);
        $p_to   = min($end_p, $rp['tgl_selesai']);

        $cur = strtotime($p_from);
        $end = strtotime($p_to);
        while ($cur <= $end) {
            $tgl_str = date('Y-m-d', $cur);
            $izin_map[$p_pin][$tgl_str] = [
                'tipe' => $rp['tipe_izin'],
                'ket'  => $rp['keterangan']
            ];
            $cur += 86400;
        }
    }
}

// Rekap Kehadiran
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
            ? get_target_hari_guru($conn, $pin, $tahun, $bulan, $hari_libur_map) 
            : $hari_kerja_karyawan_default;

        $tgl_logs = $absen_data[$pin] ?? [];

        foreach ($tgl_logs as $tgl_str => $hari_num) {
            $is_custom_hol = isset($hari_libur_map[$tgl_str]);
            if ($is_guru) {
                if (empty($hari_arr)) {
                    $total_hadir_diluar++;
                } elseif (in_array($hari_num, $hari_arr) && !$is_custom_hol) {
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
                if ($hari_num !== 7 && !$is_custom_hol) {
                    $total_hadir_sesuai++;
                } else {
                    $total_hadir_diluar++;
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
        case 'pin_asc': default: return ((int)$a['pin'] <=> (int)$b['pin']) ?: strcmp($a['pin'], $b['pin']);
    }
});

render_header("Laporan Bulanan", "laporan_bulanan");
?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- NOTIFIKASI SUKSES / ERROR -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- NOTIFIKASI SUKSES / ERROR -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<?php if (!empty($pesan_sukses)): ?>
<div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5); border-left:4px solid #10b981; border-radius:0 12px 12px 0; padding:14px 20px; margin-bottom:20px; color:#065f46; font-size:14px; font-weight:600; display:flex; align-items:center; gap:10px; box-shadow:0 2px 8px rgba(16,185,129,.12);">
    <span style="font-size:12px; font-weight:900; background:#10b981; color:#fff; padding:2px 8px; border-radius:4px;">SUKSES</span>
    <span><?php echo $pesan_sukses; ?></span>
</div>
<?php endif; ?>
<?php if (!empty($pesan_error)): ?>
<div style="background:linear-gradient(135deg,#fff1f2,#fee2e2); border-left:4px solid #ef4444; border-radius:0 12px 12px 0; padding:14px 20px; margin-bottom:20px; color:#991b1b; font-size:14px; font-weight:600; display:flex; align-items:center; gap:10px; box-shadow:0 2px 8px rgba(239,68,68,.12);">
    <span style="font-size:12px; font-weight:900; background:#ef4444; color:#fff; padding:2px 8px; border-radius:4px;">ERROR</span>
    <span><?php echo $pesan_error; ?></span>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- PANEL FILTER & AKSI -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#1e3a5f 100%); border-radius:18px; padding:24px 28px 22px; margin-bottom:22px; box-shadow:0 8px 32px rgba(15,23,42,.35); position:relative; overflow:hidden;">
    <!-- decorative circles -->
    <div style="position:absolute; top:-40px; right:-40px; width:160px; height:160px; border-radius:50%; background:rgba(99,102,241,.15); pointer-events:none;"></div>
    <div style="position:absolute; bottom:-30px; left:60px; width:100px; height:100px; border-radius:50%; background:rgba(16,185,129,.1); pointer-events:none;"></div>

    <div style="margin-bottom:18px; position:relative; z-index:1;">
        <div style="font-size:11px; font-weight:700; letter-spacing:2px; color:#94a3b8; text-transform:uppercase; margin-bottom:4px;">LAPORAN EVALUASI ABSENSI BULANAN</div>
        <div style="font-size:20px; font-weight:800; color:#f1f5f9;">Monitoring Kehadiran — <span style="color:#38bdf8;"><?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?></span></div>
    </div>

    <form method="GET" action="export_bulanan.php" style="position:relative; z-index:1;">
        <input type="hidden" name="sort" value="<?php echo h($sort); ?>">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:16px;">
            <!-- Bulan -->
            <div>
                <label style="display:block; font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">Bulan</label>
                <select name="bulan" id="bulan" style="margin:0; background:#1e293b; color:#f1f5f9; border:1px solid #334155; border-radius:10px; padding:9px 12px; font-size:13px; font-weight:600; width:100%; outline:none; cursor:pointer;">
                    <?php foreach ($nama_bulan as $num => $nama_b): ?>
                    <option value="<?php echo $num; ?>" <?php echo $bulan === $num ? 'selected' : ''; ?> style="background:#1e293b;"><?php echo $nama_b; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Tahun -->
            <div>
                <label style="display:block; font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">Tahun</label>
                <select name="tahun" id="tahun" style="margin:0; background:#1e293b; color:#f1f5f9; border:1px solid #334155; border-radius:10px; padding:9px 12px; font-size:13px; font-weight:600; width:100%; outline:none; cursor:pointer;">
                    <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $tahun === $y ? 'selected' : ''; ?> style="background:#1e293b;"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <!-- Kategori -->
            <div style="grid-column: span 2;">
                <label style="display:block; font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">Kategori</label>
                <?php if (is_tatausaha()): ?>
                <select name="kategori_disabled" style="margin:0; background:#0f172a; color:#64748b; border:1px solid #1e293b; border-radius:10px; padding:9px 12px; font-size:13px; width:100%; cursor:not-allowed;" disabled>
                    <option>Karyawan / Staff Only (Khusus Tata Usaha)</option>
                </select>
                <input type="hidden" name="kategori" value="karyawan">
                <?php else: ?>
                <select name="kategori" id="kategori" style="margin:0; background:#1e293b; color:#f1f5f9; border:1px solid #334155; border-radius:10px; padding:9px 12px; font-size:13px; font-weight:600; width:100%; outline:none; cursor:pointer;">
                    <option value="semua" <?php echo $kategori === 'semua' ? 'selected' : ''; ?> style="background:#1e293b;">Semua (Guru & Karyawan)</option>
                    <option value="guru" <?php echo $kategori === 'guru' ? 'selected' : ''; ?> style="background:#1e293b;">Guru Pengajar Only</option>
                    <option value="karyawan" <?php echo $kategori === 'karyawan' ? 'selected' : ''; ?> style="background:#1e293b;">Karyawan / Staff Only</option>
                </select>
                <?php endif; ?>
            </div>
        </div>

        <!-- ACTION BUTTONS -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button type="submit" style="display:flex; align-items:center; gap:8px; background:linear-gradient(135deg,#3b82f6,#2563eb); color:#fff; border:none; border-radius:10px; padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer; box-shadow:0 4px 14px rgba(59,130,246,.4); transition:all .2s; white-space:nowrap;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                Tampilkan Laporan
            </button>
            <?php if (is_superadmin()): ?>
            <button type="button" onclick="bukaModalHariLibur()" style="display:flex; align-items:center; gap:8px; background:linear-gradient(135deg,#7c3aed,#6d28d9); color:#fff; border:none; border-radius:10px; padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer; box-shadow:0 4px 14px rgba(124,58,237,.4); white-space:nowrap;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Kelola Hari Libur
            </button>
            <?php endif; ?>
            <?php if (can_access_rnd()): ?>
            <a href="<?php echo 'export_pdf_bulanan.php?' . http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'auto_print' => 1]); ?>" target="_blank" style="display:flex; align-items:center; gap:8px; background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; border-radius:10px; padding:10px 20px; font-size:13px; font-weight:700; text-decoration:none; box-shadow:0 4px 14px rgba(239,68,68,.4); white-space:nowrap;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Export PDF Official
            </a>
            <?php endif; ?>
            <a href="<?php echo 'export_excel_matriks.php?' . http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $sort]); ?>" style="display:flex; align-items:center; gap:8px; background:linear-gradient(135deg,#10b981,#059669); color:#fff; border-radius:10px; padding:10px 20px; font-size:13px; font-weight:700; text-decoration:none; box-shadow:0 4px 14px rgba(16,185,129,.4); white-space:nowrap;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                Export ke Excel
            </a>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- RINGKASAN STATISTIK (4 CARDS) -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:14px; margin-bottom:22px;">

    <!-- Card 1: Periode -->
    <div style="background:linear-gradient(135deg,#1e3a5f,#1e40af); border-radius:16px; padding:22px 24px; position:relative; overflow:hidden; box-shadow:0 4px 20px rgba(30,64,175,.3);">
        <div style="position:absolute; top:-20px; right:-20px; width:90px; height:90px; border-radius:50%; background:rgba(255,255,255,.07);"></div>
        <div style="font-size:11px; font-weight:800; color:#93c5fd; letter-spacing:1.8px; text-transform:uppercase; margin-bottom:8px;">PERIODE EVALUASI</div>
        <div style="font-size:22px; font-weight:900; color:#fff; line-height:1.2;"><?php echo $nama_bulan[$bulan]; ?> <span style="font-size:16px; color:#93c5fd; font-weight:700;"><?php echo $tahun; ?></span></div>
    </div>

    <!-- Card 2: Hari Kerja -->
    <div style="background:linear-gradient(135deg,#065f46,#059669); border-radius:16px; padding:22px 24px; position:relative; overflow:hidden; box-shadow:0 4px 20px rgba(5,150,105,.3);">
        <div style="position:absolute; top:-20px; right:-20px; width:90px; height:90px; border-radius:50%; background:rgba(255,255,255,.07);"></div>
        <div style="font-size:11px; font-weight:800; color:#6ee7b7; letter-spacing:1.8px; text-transform:uppercase; margin-bottom:8px;">HARI KERJA KARYAWAN</div>
        <div style="font-size:30px; font-weight:900; color:#fff; line-height:1;"><?php echo $hari_kerja_karyawan_default; ?> <span style="font-size:14px; font-weight:700; color:#a7f3d0;">Hari</span></div>
        <div style="font-size:10px; color:#6ee7b7; margin-top:6px; font-weight:600;">Excl. Minggu & Hari Libur</div>
    </div>

    <!-- Card 3: Hari Libur -->
    <div style="background:linear-gradient(135deg,#4c1d95,#7c3aed); border-radius:16px; padding:22px 24px; position:relative; overflow:hidden; box-shadow:0 4px 20px rgba(124,58,237,.3);">
        <div style="position:absolute; top:-20px; right:-20px; width:90px; height:90px; border-radius:50%; background:rgba(255,255,255,.07);"></div>
        <div style="font-size:11px; font-weight:800; color:#c4b5fd; letter-spacing:1.8px; text-transform:uppercase; margin-bottom:8px;">HARI LIBUR KALENDER</div>
        <div style="font-size:30px; font-weight:900; color:#fff; line-height:1;"><?php echo count($hari_libur_map); ?> <span style="font-size:14px; font-weight:700; color:#ddd6fe;">Hari Libur</span></div>
        <div style="font-size:10px; color:#c4b5fd; margin-top:6px; font-weight:600;">Bulan <?php echo $nama_bulan[$bulan]; ?></div>
    </div>

    <!-- Card 4: Total Evaluasi -->
    <div style="background:linear-gradient(135deg,#7f1d1d,#dc2626); border-radius:16px; padding:22px 24px; position:relative; overflow:hidden; box-shadow:0 4px 20px rgba(220,38,38,.3);">
        <div style="position:absolute; top:-20px; right:-20px; width:90px; height:90px; border-radius:50%; background:rgba(255,255,255,.07);"></div>
        <div style="font-size:11px; font-weight:800; color:#fca5a5; letter-spacing:1.8px; text-transform:uppercase; margin-bottom:8px;">TOTAL MASTER EVALUASI</div>
        <div style="font-size:30px; font-weight:900; color:#fff; line-height:1;"><?php echo count($rekap_data); ?> <span style="font-size:14px; font-weight:700; color:#fecaca;">Orang</span></div>
        <div style="font-size:10px; color:#fca5a5; margin-top:6px; font-weight:600;"><?php echo ucfirst($kategori); ?> <?php echo $kategori === 'semua' ? '(Guru & Karyawan)' : 'Only'; ?></div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- LEGEND KETERANGAN WARNA -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(15,23,42,.06);">
    <div style="font-size:11px; font-weight:800; color:#475569; letter-spacing:1.5px; text-transform:uppercase; margin-bottom:12px;">
        KETERANGAN WARNA MATRIKS — <span style="color:#2563eb;"><?php echo strtoupper($nama_bulan[$bulan] . ' ' . $tahun); ?></span>
    </div>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <span style="display:inline-flex; align-items:center; background:#dcfce7; color:#15803d; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #bbf7d0;">Hadir <span style="font-weight:400; opacity:.85; margin-left:4px;">(Sudah Absen)</span></span>
        <span style="display:inline-flex; align-items:center; background:#eff6ff; color:#1d4ed8; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #bfdbfe;">Cuti Disetujui</span>
        <span style="display:inline-flex; align-items:center; background:#fff7ed; color:#c2410c; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #ffedd5;">Izin Disetujui</span>
        <span style="display:inline-flex; align-items:center; gap:5px; background:#fdf4ff; color:#7e22ce; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #e9d5ff;">Sakit Disetujui</span>
        <span style="display:inline-flex; align-items:center; background:#fee2e2; color:#991b1b; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #fecdd3;">Alpa / Tidak Hadir <span style="font-weight:400; opacity:.85; margin-left:4px;">(Ada Jadwal, 0 Log)</span></span>
        <span style="display:inline-flex; align-items:center; background:rgb(190,10,10); color:#fff; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700;">Hari Libur Kalender <span style="font-weight:400; opacity:.85; margin-left:4px;">(Kolom Merah Vertikal)</span></span>
        <span style="display:inline-flex; align-items:center; background:#f1f5f9; color:#64748b; padding:5px 12px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #e2e8f0;">Libur Rutin / Tanpa Jadwal</span>
    </div>
</div>

<?php
// Helper URL untuk Header Sorting Clickable di Laporan Bulanan
function b_sort_url($col_name, $current_sort, $bulan, $tahun, $kategori) {
    $next_sort = ($current_sort === "{$col_name}_asc") ? "{$col_name}_desc" : "{$col_name}_asc";
    if ($col_name === 'persen') {
        $next_sort = ($current_sort === 'persen_desc') ? 'persen_asc' : 'persen_desc';
    }
    return "export_bulanan.php?" . http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $next_sort]);
}

function b_sort_icon($col_name, $current_sort) {
    if ($current_sort === "{$col_name}_asc") return " 🔼";
    if ($current_sort === "{$col_name}_desc") return " 🔽";
    return " <span style='color:#cbd5e1;'>↕</span>";
}
?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- TABEL MATRIKS & EVALUASI TERPADU -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(15,23,42,.08); margin-bottom:20px;">
    <!-- Card Header -->
    <div style="background:linear-gradient(135deg,#0f172a,#1e293b); padding:18px 24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; letter-spacing:2px; text-transform:uppercase; margin-bottom:3px;">MATRIKS KEHADIRAN</div>
            <div style="font-size:16px; font-weight:800; color:#f1f5f9;">Evaluasi Bulanan — Tgl 1 s/d <?php echo $total_hari_bulan; ?> <?php echo $nama_bulan[$bulan]; ?> <?php echo $tahun; ?></div>
        </div>
        <form method="GET" action="export_bulanan.php" style="margin:0; display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="bulan" value="<?php echo $bulan; ?>">
            <input type="hidden" name="tahun" value="<?php echo $tahun; ?>">
            <input type="hidden" name="kategori" value="<?php echo h($kategori); ?>">
            <label for="sort_m" style="font-size:11px; color:#94a3b8; font-weight:700; margin-bottom:0; white-space:nowrap; letter-spacing:1px; text-transform:uppercase;">Urutkan:</label>
            <select name="sort" id="sort_m" onchange="this.form.submit()" style="margin:0; background:#1e293b; color:#f1f5f9; border:1px solid #334155; border-radius:8px; padding:7px 10px; font-size:12px; font-weight:600; cursor:pointer; outline:none;">
                <option value="pin_asc" <?php echo $sort === 'pin_asc' ? 'selected' : ''; ?> style="background:#1e293b;">PIN (1 ➔ 99)</option>
                <option value="pin_desc" <?php echo $sort === 'pin_desc' ? 'selected' : ''; ?> style="background:#1e293b;">PIN (99 ➔ 1)</option>
                <option value="persen_desc" <?php echo $sort === 'persen_desc' ? 'selected' : ''; ?> style="background:#1e293b;">% Kehadiran (Tertinggi)</option>
                <option value="persen_asc" <?php echo $sort === 'persen_asc' ? 'selected' : ''; ?> style="background:#1e293b;">% Kehadiran (Terendah)</option>
                <option value="nama_asc" <?php echo $sort === 'nama_asc' ? 'selected' : ''; ?> style="background:#1e293b;">Nama (A ➔ Z)</option>
                <option value="nama_desc" <?php echo $sort === 'nama_desc' ? 'selected' : ''; ?> style="background:#1e293b;">Nama (Z ➔ A)</option>
                <option value="hadir_desc" <?php echo $sort === 'hadir_desc' ? 'selected' : ''; ?> style="background:#1e293b;">Total Hadir (Banyak)</option>
                <option value="target_desc" <?php echo $sort === 'target_desc' ? 'selected' : ''; ?> style="background:#1e293b;">Target Hari (Banyak)</option>
                <option value="tipe_desc" <?php echo $sort === 'tipe_desc' ? 'selected' : ''; ?> style="background:#1e293b;">Tipe (Guru Dulu)</option>
                <option value="tipe_asc" <?php echo $sort === 'tipe_asc' ? 'selected' : ''; ?> style="background:#1e293b;">Tipe (Karyawan Dulu)</option>
            </select>
        </form>
    </div>

    <div style="max-height:750px; overflow:auto;">
        <table style="font-size:12px; min-width:1400px; border-collapse:collapse; width:100%;">
            <thead style="position:sticky; top:0; z-index:10;">
                <tr>
                    <th style="background:#0f172a; color:#94a3b8; width:60px; min-width:60px; padding:10px 8px; font-size:10px; letter-spacing:1px; text-transform:uppercase; border-right:1px solid #1e293b;">
                        <a href="<?php echo b_sort_url('pin', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">PIN<?php echo b_sort_icon('pin', $sort); ?></a>
                    </th>
                    <th style="background:#0f172a; color:#94a3b8; text-align:left; min-width:200px; padding:10px 12px; font-size:10px; letter-spacing:1px; text-transform:uppercase; border-right:1px solid #1e293b;">
                        <a href="<?php echo b_sort_url('nama', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Nama Pegawai<?php echo b_sort_icon('nama', $sort); ?></a>
                    </th>
                    <th style="background:#0f172a; color:#94a3b8; padding:10px 8px; font-size:10px; letter-spacing:1px; text-transform:uppercase; border-right:1px solid #1e293b;">
                        <a href="<?php echo b_sort_url('tipe', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Tipe<?php echo b_sort_icon('tipe', $sort); ?></a>
                    </th>
                    <?php for ($d = 1; $d <= $total_hari_bulan; $d++): 
                        $tgl_sub  = sprintf("%04d-%02d-%02d", $tahun, $bulan, $d);
                        $day_n    = (int)date('N', strtotime($tgl_sub));
                        $h_singkat = ['Sen','Sel','Rab','Kam','Jum','Sab','Min'][$day_n - 1];
                        
                        $is_custom_hol = isset($hari_libur_map[$tgl_sub]);
                        $bg_th = 'background:#1e293b; color:#fff;';
                        $lbl_sub = $h_singkat;
                        if ($is_custom_hol) {
                            $bg_th = 'background:rgb(246,10,10); color:#fff; border-left:1px solid #b91c1c; border-right:1px solid #b91c1c; cursor:pointer;';
                            $lbl_sub = 'LIBUR';
                        } elseif ($day_n === 7) {
                            $bg_th = 'background:#ef4444; color:#fff;';
                        }
                        
                        $th_title = $is_custom_hol ? "Hari Libur Kalender: " . $hari_libur_map[$tgl_sub]['keterangan'] : "Tgl {$d} {$nama_bulan[$bulan]}";
                        if (is_superadmin()) $th_title .= " (Klik untuk kelola libur)";
                    ?>
                        <th style="<?php echo $bg_th; ?> padding:7px 2px; font-size:11px; text-align:center; min-width:36px; border-right:1px solid rgba(255,255,255,.08);" title="<?php echo h($th_title); ?>" <?php if (is_superadmin()): ?>onclick="setModalLiburTanggal('<?php echo $tgl_sub; ?>')" style="cursor:pointer;"<?php endif; ?>>
                            <div style="font-weight:800; font-size:12px; line-height:1;"><?php echo $d; ?></div>
                            <div style="font-size:8px; font-weight:700; opacity:0.85; margin-top:3px;"><?php echo $lbl_sub; ?></div>
                        </th>
                    <?php endfor; ?>
                    <th style="background:#0f172a; color:#94a3b8; padding:10px 8px; font-size:10px; letter-spacing:1px; text-transform:uppercase; border-left:2px solid #1e3a5f; white-space:nowrap;">
                        <a href="<?php echo b_sort_url('target', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Target<?php echo b_sort_icon('target', $sort); ?></a>
                    </th>
                    <th style="background:#052e16; color:#6ee7b7; padding:10px 8px; font-size:10px; letter-spacing:1px; text-transform:uppercase; white-space:nowrap;">
                        <a href="<?php echo b_sort_url('hadir', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Hadir ✓<?php echo b_sort_icon('hadir', $sort); ?></a>
                    </th>
                    <th style="background:#431407; color:#fb923c; padding:10px 8px; font-size:10px; letter-spacing:1px; text-transform:uppercase; white-space:nowrap;">Di Luar</th>
                    <th style="background:#1e1b4b; color:#a5b4fc; padding:10px 8px; font-size:10px; letter-spacing:1px; text-transform:uppercase; white-space:nowrap;">
                        <a href="<?php echo b_sort_url('persen', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">% Hadir<?php echo b_sort_icon('persen', $sort); ?></a>
                    </th>
                    <th style="background:#0f172a; color:#94a3b8; padding:10px 8px; font-size:10px; letter-spacing:1px; text-transform:uppercase; white-space:nowrap;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($rekap_data)) {
                    $total_rows = count($rekap_data);
                    $row_idx = 0;
                    foreach ($rekap_data as $r) {
                        $pin      = $r['pin'];
                        $nama     = $r['nama'];
                        $dept     = $r['dept'];
                        $tipe     = $r['tipe'];
                        $hari_arr = $r['list_hari'];
                        $is_guru  = ($tipe === 'guru');

                        $badge_tipe = $is_guru 
                            ? "<span class='badge' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-weight:700;'>Guru</span>"
                            : "<span class='badge' style='background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-weight:700;'>Karyawan</span>";

                        $status_eval = "";
                        if ($r['target_hari'] == 0) {
                            $status_eval = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-weight:700;'>Belum Ada Jadwal</span>";
                        } elseif ($r['persen'] >= 90) {
                            $status_eval = "<span class='badge' style='background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700;'>Baik (≥90%)</span>";
                        } elseif ($r['persen'] >= 80) {
                            $status_eval = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-weight:700;'>Cukup (80-89%)</span>";
                        } else {
                            $status_eval = "<span class='badge' style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; font-weight:700;'>Perlu Perhatian (<80%)</span>";
                        }

                        $diluar_text = ($r['hadir_diluar'] > 0)
                            ? "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5; font-weight:700;'>{$r['hadir_diluar']} hr</span>"
                            : "<span style='color:#94a3b8;'>-</span>";

                        $row_bg = ($row_idx % 2 === 0) ? '#ffffff' : '#f8fafc';
                        echo "<tr style='background:{$row_bg}; transition:background .15s;' onmouseover=\"this.style.background='#eff6ff'\" onmouseout=\"this.style.background='{$row_bg}'\">"; 
                        echo "<td style='text-align:center; padding:8px 6px; border-bottom:1px solid #f1f5f9;'><code style='background:#1e293b; color:#93c5fd; padding:3px 8px; border-radius:6px; font-weight:800; font-size:12px;'>" . h($pin) . "</code></td>";
                        echo "<td style='text-align:left; padding:8px 12px; border-bottom:1px solid #f1f5f9; white-space:nowrap;'>
                                <a href='riwayat_karyawan.php?pin=" . urlencode($pin) . "' style='color:#1e293b; text-decoration:none; font-weight:700; font-size:13px;' title='Klik untuk melihat riwayat absensi lengkap'>
                                    " . h($nama) . "
                                </a>
                                <div style='font-size:10px; color:#94a3b8; margin-top:1px;'>" . h($dept) . "</div>
                              </td>";
                        echo "<td style='text-align:center; padding:6px; border-bottom:1px solid #f1f5f9;'>{$badge_tipe}</td>";

                        for ($d = 1; $d <= $total_hari_bulan; $d++) {
                            $tgl_key  = sprintf("%04d-%02d-%02d", $tahun, $bulan, $d);
                            $day_num  = (int)date('N', strtotime($tgl_key));

                            $is_custom_hol = isset($hari_libur_map[$tgl_key]);
                            $ket_custom = $is_custom_hol ? $hari_libur_map[$tgl_key]['keterangan'] : '';

                            $has_sch = $is_guru ? in_array($day_num, $hari_arr) : ($day_num !== 7);
                            $log_today = $absen_detail[$pin][$tgl_key] ?? null;

                            if ($is_custom_hol) {
                                if ($row_idx === 0) {
                                    $label_libur = "Libur " . h($ket_custom);
                                    echo "<td rowspan='{$total_rows}' style='background:linear-gradient(180deg,rgb(190,10,10),rgb(220,20,20)); color:#ffffff; font-weight:800; font-style:italic; font-size:10px; text-align:center; vertical-align:middle; padding:10px 2px; border:1px solid #b91c1c; text-transform:uppercase; letter-spacing:1.5px; white-space:nowrap; writing-mode:vertical-rl; transform:rotate(180deg);' title='" . h($ket_custom) . "'>
                                            {$label_libur}
                                          </td>";
                                }
                                // Row_idx > 0 skipped due to rowspan
                            } else {
                                if ($log_today !== null) {
                                    $m_jam = $log_today['masuk'] ?? '-';
                                    $p_jam = $log_today['pulang'] ?? '-';
                                    $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Hadir (Masuk: {$m_jam}, Pulang: {$p_jam})";
                                    echo "<td style='background:#dcfce7; color:#15803d; font-weight:800; font-size:12px; text-align:center; padding:6px 2px; border-bottom:1px solid #f0fdf4;' title='" . h($tooltip) . "'>✓</td>";
                                } elseif (isset($izin_map[$pin][$tgl_key])) {
                                    $iz_info = $izin_map[$pin][$tgl_key];
                                    $iz_tipe = $iz_info['tipe'];
                                    $iz_ket  = $iz_info['ket'];

                                    $b_bg = '#dbeafe'; $b_fg = '#1d4ed8'; $b_lbl = 'C';
                                    if ($iz_tipe === 'izin') {
                                        $b_bg = '#ffedd5'; $b_fg = '#c2410c'; $b_lbl = 'I';
                                    } elseif ($iz_tipe === 'sakit') {
                                        $b_bg = '#f3e8ff'; $b_fg = '#7e22ce'; $b_lbl = 'S';
                                    }

                                    $iz_full_label = ['cuti'=>'Cuti','izin'=>'Izin','sakit'=>'Sakit'][$iz_tipe] ?? ucfirst($iz_tipe);
                                    $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": " . $iz_full_label . " Disetujui" . ($iz_ket ? " (" . h($iz_ket) . ")" : "");
                                    echo "<td style='background:{$b_bg}; color:{$b_fg}; font-weight:900; font-size:11px; text-align:center; padding:6px 2px; border-bottom:1px solid rgba(0,0,0,.04);' title='" . h($tooltip) . "'>{$b_lbl}</td>";
                                } else {
                                    if ($has_sch) {
                                        $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Alpa / Tidak Hadir (Ada Jadwal 0 Log)";
                                        echo "<td style='background:#fee2e2; color:#dc2626; font-weight:800; font-size:12px; text-align:center; padding:6px 2px; border-bottom:1px solid #fecaca;' title='" . h($tooltip) . "'>✗</td>";
                                    } else {
                                        $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Libur / Tanpa Jadwal Mengajar";
                                        echo "<td style='background:#f8fafc; color:#cbd5e1; font-size:11px; text-align:center; padding:6px 2px; border-bottom:1px solid #f1f5f9;' title='" . h($tooltip) . "'>·</td>";
                                    }
                                }
                            }
                        }

                        echo "<td style='text-align:center; padding:6px 8px; border-bottom:1px solid #f1f5f9; border-left:2px solid #e2e8f0;'><b style='font-size:12px; color:#1e293b;'>{$r['target_hari']}h</b></td>";
                        echo "<td style='text-align:center; padding:6px 8px; border-bottom:1px solid #f1f5f9; background:#f0fdf4;'><b style='font-size:13px; color:#15803d;'>{$r['hadir_sesuai']}h</b></td>";
                        echo "<td style='text-align:center; padding:6px 8px; border-bottom:1px solid #f1f5f9;'>{$diluar_text}</td>";

                        $persen_color = $r['persen'] >= 90 ? '#15803d' : ($r['persen'] >= 80 ? '#b45309' : '#dc2626');
                        $persen_bg    = $r['persen'] >= 90 ? '#dcfce7' : ($r['persen'] >= 80 ? '#fef3c7' : '#fee2e2');
                        echo "<td style='text-align:center; padding:6px 8px; border-bottom:1px solid #f1f5f9; background:{$persen_bg};'><b style='font-size:14px; color:{$persen_color};'>{$r['persen']}%</b></td>";
                        echo "<td style='text-align:center; padding:6px 8px; border-bottom:1px solid #f1f5f9;'>{$status_eval}</td>";
                        echo "</tr>";

                        $row_idx++;
                    }
                } else {
                    $cols = $total_hari_bulan + 8;
                    echo "<tr><td colspan='{$cols}' style='padding:48px 30px; text-align:center; color:#94a3b8;'><div style='font-size:40px; margin-bottom:12px;'>📭</div><div style='font-size:15px; font-weight:700; color:#64748b;'>Tidak ada data untuk periode ini</div><div style='font-size:12px; color:#94a3b8; margin-top:6px;'>Pastikan filter bulan, tahun, dan kategori sudah benar</div></td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($log_diluar_jadwal_list)): ?>
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- LOG GURU ABSEN DI LUAR JADWAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div style="background:#fffbf5; border:1px solid #fed7aa; border-left:4px solid #f97316; border-radius:14px; overflow:hidden; box-shadow:0 2px 12px rgba(249,115,22,.12); margin-bottom:20px;">
    <div style="background:linear-gradient(135deg,#431407,#7c2d12); padding:16px 22px; display:flex; align-items:center; gap:12px;">
        <span style="font-size:11px; font-weight:900; background:#f97316; color:#fff; padding:3px 8px; border-radius:4px;">INFO</span>
        <div>
            <div style="font-size:14px; font-weight:800; color:#fed7aa;">Rincian Guru Absen di Luar Hari Jadwal Ngajar</div>
            <div style="font-size:11px; color:#fb923c; margin-top:2px;"><?php echo count($log_diluar_jadwal_list); ?> Record ditemukan — Tidak dihitung ke % Kehadiran Target</div>
        </div>
    </div>
    <div style="padding:14px 20px 0; font-size:12px; color:#9a3412; background:#fff7ed; border-bottom:1px solid #fed7aa;">
        Data ini mencatat kehadiran guru pada hari yang bukan jadwal ngajarnya (misal rapat/piket). Data tetap tersimpan tetapi <b>tidak dihitung</b> sebagai penambah % kehadiran target.
    </div>
    <div style="overflow-x:auto;">
        <table style="font-size:12px; width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#fff7ed;">
                    <th style="padding:10px 14px; color:#9a3412; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:800; text-align:center; border-bottom:1px solid #fed7aa;">PIN</th>
                    <th style="padding:10px 14px; color:#9a3412; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:800; text-align:left; border-bottom:1px solid #fed7aa;">Nama Guru</th>
                    <th style="padding:10px 14px; color:#9a3412; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:800; text-align:center; border-bottom:1px solid #fed7aa;">Tanggal Absen</th>
                    <th style="padding:10px 14px; color:#9a3412; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:800; text-align:center; border-bottom:1px solid #fed7aa;">Hari</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($log_diluar_jadwal_list as $idx_ld => $ld) {
                    $ld_bg = $idx_ld % 2 === 0 ? '#ffffff' : '#fffbf5';
                    echo "<tr style='background:{$ld_bg};'>
                            <td style='padding:10px 14px; text-align:center; border-bottom:1px solid #fff7ed;'><code style='background:#1e293b; color:#93c5fd; padding:3px 9px; border-radius:6px; font-weight:800;'>" . h($ld['pin']) . "</code></td>
                            <td style='padding:10px 14px; border-bottom:1px solid #fff7ed;'>
                                <div style='font-weight:700; color:#1e293b;'>" . h($ld['nama']) . "</div>
                                <div style='font-size:10px; color:#94a3b8;'>" . h($ld['dept']) . "</div>
                            </td>
                            <td style='padding:10px 14px; text-align:center; border-bottom:1px solid #fff7ed;'><b style='color:#92400e;'>" . h($ld['waktu']) . "</b></td>
                            <td style='padding:10px 14px; text-align:center; border-bottom:1px solid #fff7ed;'><span style='background:#ffedd5; color:#9a3412; border:1px solid #fed7aa; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:700;'>{$ld['hari_nama']} — Di Luar Jadwal</span></td>
                          </tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- MODAL KELOLA HARI LIBUR KALENDER (SUPERADMIN ONLY) -->
<!-- ============================================================ -->
<?php if (is_superadmin()): ?>
<div id="modal-hari-libur" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; backdrop-filter:blur(6px); align-items:center; justify-content:center;">
    <div style="width:100%; max-width:600px; margin:20px auto; background:#fff; border-radius:20px; max-height:92vh; overflow-y:auto; box-shadow:0 30px 60px -12px rgba(0,0,0,.4); position:relative;">

        <!-- Modal Header -->
        <div style="background:linear-gradient(135deg,#4c1d95,#7c3aed); padding:20px 24px; border-radius:20px 20px 0 0; display:flex; align-items:center; justify-content:space-between;">
            <div>
                <div style="font-size:11px; font-weight:700; color:#c4b5fd; letter-spacing:2px; text-transform:uppercase; margin-bottom:3px;">SUPERADMIN</div>
                <div style="font-size:17px; font-weight:800; color:#fff;">Kelola Hari Libur Kalender</div>
                <div style="font-size:11px; color:#ddd6fe; margin-top:2px;"><?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?></div>
            </div>
            <button type="button" onclick="tutupModalHariLibur()" style="background:rgba(255,255,255,.15); border:none; width:36px; height:36px; border-radius:50%; font-size:18px; cursor:pointer; color:#fff; display:flex; align-items:center; justify-content:center; transition:background .2s;" onmouseover="this.style.background='rgba(255,255,255,.25)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">✕</button>
        </div>

        <div style="padding:20px 24px;">

            <!-- SINKRONISASI LIBUR NASIONAL -->
            <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe); padding:14px 16px; border-radius:12px; border:1px solid #bfdbfe; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                <div>
                    <div style="font-size:13px; font-weight:800; color:#1e40af;">Sinkronisasi Libur Nasional API</div>
                    <div style="font-size:11px; color:#3b82f6; margin-top:2px;">Ambil otomatis tanggal merah resmi bulan <?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?></div>
                </div>
                <form method="POST" action="export_bulanan.php?<?php echo http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $sort]); ?>" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="sync_nasional">
                    <input type="hidden" name="bulan" value="<?php echo $bulan; ?>">
                    <input type="hidden" name="tahun" value="<?php echo $tahun; ?>">
                    <button type="submit" style="background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border:none; border-radius:9px; padding:8px 16px; font-size:12px; font-weight:700; cursor:pointer; box-shadow:0 3px 10px rgba(37,99,235,.3);">
                        Sync Libur Nasional
                    </button>
                </form>
            </div>

            <!-- FORM TAMBAH LIBUR MANUAL -->
            <form method="POST" action="export_bulanan.php?<?php echo http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $sort]); ?>" style="margin-bottom:20px; background:#faf5ff; padding:16px; border-radius:12px; border:1px solid #ddd6fe;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="tambah_hari_libur">
                <div style="font-size:12px; font-weight:800; color:#5b21b6; margin-bottom:12px;">Tambah / Update Libur Manual</div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                    <div style="flex:1; min-width:140px;">
                        <label for="tgl_libur" style="font-size:11px; font-weight:700; color:#7c3aed; display:block; margin-bottom:5px; text-transform:uppercase; letter-spacing:1px;">Tanggal</label>
                        <input type="date" id="tgl_libur" name="tgl_libur" required style="width:100%; padding:9px 12px; border:1px solid #c4b5fd; border-radius:9px; font-size:13px; font-weight:600; outline:none; box-sizing:border-box;">
                    </div>
                    <div style="flex:2; min-width:180px;">
                        <label for="ket_libur" style="font-size:11px; font-weight:700; color:#7c3aed; display:block; margin-bottom:5px; text-transform:uppercase; letter-spacing:1px;">Keterangan</label>
                        <input type="text" id="ket_libur" name="ket_libur" placeholder="Contoh: Libur Semester / Classmeeting" required style="width:100%; padding:9px 12px; border:1px solid #c4b5fd; border-radius:9px; font-size:13px; outline:none; box-sizing:border-box;" autocomplete="off">
                    </div>
                </div>
                <button type="submit" style="width:100%; background:linear-gradient(135deg,#7c3aed,#6d28d9); color:#fff; border:none; border-radius:10px; padding:10px; font-size:13px; font-weight:800; cursor:pointer; box-shadow:0 4px 14px rgba(124,58,237,.3);">
                    Simpan Libur Manual
                </button>
            </form>

            <!-- DAFTAR HARI LIBUR -->
            <div style="font-size:12px; font-weight:800; color:#334155; margin-bottom:10px; text-transform:uppercase; letter-spacing:1px;">Daftar Hari Libur — <?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?></div>
            <div style="overflow-x:auto; border-radius:10px; border:1px solid #e2e8f0; overflow:hidden;">
                <table style="font-size:12px; width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:linear-gradient(135deg,#6d28d9,#7c3aed);">
                            <th style="color:#ddd6fe; padding:10px 14px; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:800;">Tanggal</th>
                            <th style="color:#ddd6fe; padding:10px 14px; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:800; text-align:left;">Keterangan</th>
                            <th style="color:#ddd6fe; padding:10px 14px; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:800; width:70px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($hari_libur_map)): ?>
                            <?php foreach ($hari_libur_map as $idx_hl => $hl): ?>
                                <tr style="background:<?php echo $idx_hl % 2 === 0 ? '#fff' : '#faf5ff'; ?>">
                                    <td style="padding:10px 14px; font-weight:800; color:#4c1d95; border-bottom:1px solid #f1f5f9; white-space:nowrap;"><?php echo h(date('d/m/Y', strtotime($hl['tanggal']))); ?></td>
                                    <td style="padding:10px 14px; text-align:left; border-bottom:1px solid #f1f5f9;">
                                        <span style="background:#ede9fe; color:#5b21b6; border:1px solid #ddd6fe; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:700;"><?php echo h($hl['keterangan']); ?></span>
                                    </td>
                                    <td style="padding:10px 14px; text-align:center; border-bottom:1px solid #f1f5f9;">
                                        <form method="POST" action="export_bulanan.php?<?php echo http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $sort]); ?>" style="margin:0;" onsubmit="return confirm('Hapus hari libur ini?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="hapus_hari_libur">
                                            <input type="hidden" name="id_hl" value="<?php echo $hl['id']; ?>">
                                            <button type="submit" style="background:#fee2e2; color:#dc2626; border:1px solid #fecaca; border-radius:7px; padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer;">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="padding:24px; text-align:center; color:#94a3b8; font-size:13px;">Belum ada hari libur kalender yang didaftarkan bulan ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:18px; text-align:right;">
                <button type="button" onclick="tutupModalHariLibur()" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:9px; padding:9px 20px; font-size:13px; font-weight:700; cursor:pointer;">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
function bukaModalHariLibur() {
    document.getElementById('modal-hari-libur').style.display = 'flex';
}
function tutupModalHariLibur() {
    document.getElementById('modal-hari-libur').style.display = 'none';
}
function setModalLiburTanggal(tglStr) {
    document.getElementById('tgl_libur').value = tglStr;
    bukaModalHariLibur();
}
</script>
<?php endif; ?>

<?php render_footer(); ?>
