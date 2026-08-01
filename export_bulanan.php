<?php
// ============================================================
// HALAMAN LAPORAN EVALUASI ABSENSI BULANAN (Dengan Kelola Hari Libur)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';

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
    }
}

// Input Parameter Bulan & Tahun (Default: Bulan & Tahun Saat Ini)
$bulan    = (int)($_GET['bulan'] ?? date('n'));
$tahun    = (int)($_GET['tahun'] ?? date('Y'));
$kategori = $_GET['kategori'] ?? 'semua'; // 'semua', 'karyawan', 'guru'
$sort     = $_GET['sort'] ?? 'pin_asc';

if ($bulan < 1 || $bulan > 12) $bulan = (int)date('n');
if ($tahun < 2020 || $tahun > 2050) $tahun = (int)date('Y');

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
        case 'pin_asc': default: return ((int)$a['pin'] <=> (int)$a['pin']) ?: strcmp($a['pin'], $b['pin']);
    }
});

render_header("Laporan Bulanan", "rekap");
?>

<!-- NOTIFIKASI SUKSES / ERROR -->
<?php if (!empty($pesan_sukses)): ?>
<div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; color: #065f46; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 20px;">✅</span>
    <span><?php echo $pesan_sukses; ?></span>
</div>
<?php endif; ?>

<?php if (!empty($pesan_error)): ?>
<div style="background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #f87171; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; color: #991b1b; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 20px;">⛔</span>
    <span><?php echo $pesan_error; ?></span>
</div>
<?php endif; ?>

<!-- KARTU FILTER LAPORAN -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" action="export_bulanan.php" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
        <input type="hidden" name="sort" value="<?php echo h($sort); ?>">

        <!-- INPUT FILTERS -->
        <div style="display:flex; gap:14px; flex-wrap:wrap; flex:1; min-width:280px;">
            <div style="flex:1; min-width:140px;">
                <label for="bulan">📅 Pilih Bulan:</label>
                <select name="bulan" id="bulan" style="margin-bottom:0;">
                    <?php foreach ($nama_bulan as $num => $nama_b): ?>
                    <option value="<?php echo $num; ?>" <?php echo $bulan === $num ? 'selected' : ''; ?>><?php echo $nama_b; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex:1; min-width:120px;">
                <label for="tahun">📆 Pilih Tahun:</label>
                <select name="tahun" id="tahun" style="margin-bottom:0;">
                    <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $tahun === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div style="flex:1.5; min-width:180px;">
                <label for="kategori">👥 Kategori:</label>
                <select name="kategori" id="kategori" style="margin-bottom:0;">
                    <option value="semua" <?php echo $kategori === 'semua' ? 'selected' : ''; ?>>Semua (Guru & Karyawan)</option>
                    <option value="guru" <?php echo $kategori === 'guru' ? 'selected' : ''; ?>>👨‍🏫 Guru Pengajar Only</option>
                    <option value="karyawan" <?php echo $kategori === 'karyawan' ? 'selected' : ''; ?>>👔 Karyawan / Staff Only</option>
                </select>
            </div>
        </div>

        <!-- ACTION BUTTONS -->
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">🔍 Tampilkan Laporan</button>
            <?php if (is_superadmin()): ?>
            <button type="button" class="btn" style="background:#8b5cf6; color:#fff;" onclick="bukaModalHariLibur()">🌴 Kelola Hari Libur Kalender</button>
            <?php endif; ?>
            <a href="<?php echo 'export_excel_matriks.php?' . http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $sort]); ?>" class="btn btn-success">📊 Export ke Excel</a>
        </div>
    </form>
</div>

<!-- INFO HEADER RINGKASAN -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
    <div class="card" style="margin-bottom:0; padding:16px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Periode Evaluasi</div>
        <div style="font-size:18px; font-weight:800; color:#0f172a; margin-top:2px;"><?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?></div>
    </div>
    <div class="card" style="margin-bottom:0; padding:16px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Hari Kerja Karyawan</div>
        <div style="font-size:18px; font-weight:800; color:#3b82f6; margin-top:2px;"><?php echo $hari_kerja_karyawan_default; ?> Hari <span style="font-size:11px; font-weight:500; color:#64748b;">(Excl. Minggu & Libur)</span></div>
    </div>
    <div class="card" style="margin-bottom:0; padding:16px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Hari Libur Kalender Bulan Ini</div>
        <div style="font-size:18px; font-weight:800; color:#8b5cf6; margin-top:2px;"><?php echo count($hari_libur_map); ?> Hari Libur</div>
    </div>
    <div class="card" style="margin-bottom:0; padding:16px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Total Master Evaluasi</div>
        <div style="font-size:18px; font-weight:800; color:#10b981; margin-top:2px;"><?php echo count($rekap_data); ?> Orang</div>
    </div>
</div>

<!-- CARD KETERANGAN WARNA (LEGEND) -->
<div class="card" style="margin-bottom:20px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0;">
    <div style="font-size:13px; font-weight:700; color:#334155; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
        💡 <b>Keterangan Warna Matriks Kehadiran (Periode: <?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?>):</b>
    </div>
    <div style="display:flex; flex-wrap:wrap; gap:12px; font-size:12px; font-weight:600;">
        <div style="display:flex; align-items:center; gap:6px; background:#dcfce7; color:#166534; padding:6px 12px; border-radius:8px; border:1px solid #bbf7d0;">
            <span>🟢 Hadir Lengkap</span> <span style="font-weight:normal; opacity:0.85;">(Masuk & Pulang)</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px; background:#fef9c3; color:#854d0e; padding:6px 12px; border-radius:8px; border:1px solid #fef08a;">
            <span>🟡 Hadir Parsial</span> <span style="font-weight:normal; opacity:0.85;">(Cuma Masuk / Pulang)</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px; background:#fee2e2; color:#991b1b; padding:6px 12px; border-radius:8px; border:1px solid #fecdd3;">
            <span>🔴 Tidak Hadir / Alpa</span> <span style="font-weight:normal; opacity:0.85;">(Ada Jadwal, 0 Log)</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px; background:#f1f5f9; color:#475569; padding:6px 12px; border-radius:8px; border:1px solid #cbd5e1;">
            <span>🌴 Libur Kalender</span> <span style="font-weight:normal; opacity:0.85;">(Bebas Alpa / Potong Target)</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px; background:#f1f5f9; color:#64748b; padding:6px 12px; border-radius:8px; border:1px solid #e2e8f0;">
            <span>⚪ Libur Rutin / Tanpa Jadwal</span> <span style="font-weight:normal; opacity:0.85;">(Tidak Dihitung Alpa)</span>
        </div>
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

<!-- TABEL MATRIKS & EVALUASI TERPADU -->
<div class="card">
    <div class="card-header" style="flex-wrap:wrap; gap:12px; align-items:center;">
        <div class="card-title" style="margin-bottom:0;">
            <span>📋 Matriks & Evaluasi Kehadiran Bulanan (Tanggal 1 ➔ <?php echo $total_hari_bulan; ?>)</span>
        </div>

        <form method="GET" action="export_bulanan.php" style="margin:0; display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="bulan" value="<?php echo $bulan; ?>">
            <input type="hidden" name="tahun" value="<?php echo $tahun; ?>">
            <input type="hidden" name="kategori" value="<?php echo h($kategori); ?>">
            
            <label for="sort_m" style="font-size:12px; color:#64748b; font-weight:600; margin-bottom:0; white-space:nowrap;">🔀 Urutkan:</label>
            <select name="sort" id="sort_m" onchange="this.form.submit()" style="margin-bottom:0; height:38px; font-size:13px; padding:6px 12px; width:auto; cursor:pointer;">
                <option value="pin_asc" <?php echo $sort === 'pin_asc' ? 'selected' : ''; ?>>🔢 PIN (1 ➔ 99)</option>
                <option value="pin_desc" <?php echo $sort === 'pin_desc' ? 'selected' : ''; ?>>🔢 PIN (99 ➔ 1)</option>
                <option value="persen_desc" <?php echo $sort === 'persen_desc' ? 'selected' : ''; ?>>📊 % Kehadiran (Tertinggi)</option>
                <option value="persen_asc" <?php echo $sort === 'persen_asc' ? 'selected' : ''; ?>>📊 % Kehadiran (Terendah)</option>
                <option value="nama_asc" <?php echo $sort === 'nama_asc' ? 'selected' : ''; ?>>🔤 Nama (A ➔ Z)</option>
                <option value="nama_desc" <?php echo $sort === 'nama_desc' ? 'selected' : ''; ?>>🔤 Nama (Z ➔ A)</option>
                <option value="hadir_desc" <?php echo $sort === 'hadir_desc' ? 'selected' : ''; ?>>🟢 Total Hadir (Banyak)</option>
                <option value="target_desc" <?php echo $sort === 'target_desc' ? 'selected' : ''; ?>>🎯 Target Hari (Banyak)</option>
                <option value="tipe_desc" <?php echo $sort === 'tipe_desc' ? 'selected' : ''; ?>>👨‍🏫 Tipe (Guru Dulu)</option>
                <option value="tipe_asc" <?php echo $sort === 'tipe_asc' ? 'selected' : ''; ?>>👔 Tipe (Karyawan Dulu)</option>
            </select>
        </form>
    </div>

    <div class="table-responsive" style="max-height:750px; overflow:auto;">
        <table style="font-size:12px; min-width:1400px;">
            <thead style="position:sticky; top:0; z-index:10; background:#1e293b; color:#fff;">
                <tr>
                    <th style="background:#0f172a; color:#fff; width:60px; min-width:60px;">
                        <a href="<?php echo b_sort_url('pin', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">PIN<?php echo b_sort_icon('pin', $sort); ?></a>
                    </th>
                    <th style="background:#0f172a; color:#fff; text-align:left; min-width:180px;">
                        <a href="<?php echo b_sort_url('nama', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Nama Guru & Karyawan<?php echo b_sort_icon('nama', $sort); ?></a>
                    </th>
                    <th style="background:#0f172a; color:#fff;">
                        <a href="<?php echo b_sort_url('tipe', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Tipe<?php echo b_sort_icon('tipe', $sort); ?></a>
                    </th>
                    <?php for ($d = 1; $d <= $total_hari_bulan; $d++): 
                        $tgl_sub  = sprintf("%04d-%02d-%02d", $tahun, $bulan, $d);
                        $day_n    = (int)date('N', strtotime($tgl_sub));
                        $h_singkat = ['Sen','Sel','Rab','Kam','Jum','Sab','Min'][$day_n - 1];
                        
                        $is_custom_hol = isset($hari_libur_map[$tgl_sub]);
                        $bg_th = 'background:#1e293b; color:#fff;';
                        if ($is_custom_hol) {
                            $bg_th = 'background:#8b5cf6; color:#fff; cursor:pointer;';
                        } elseif ($day_n === 7) {
                            $bg_th = 'background:#ef4444; color:#fff;';
                        }
                        
                        $th_title = $is_custom_hol ? "Hari Libur: " . $hari_libur_map[$tgl_sub]['keterangan'] : "Tgl {$d} {$nama_bulan[$bulan]}";
                        if (is_superadmin()) $th_title .= " (Klik untuk kelola libur)";
                    ?>
                        <th style="<?php echo $bg_th; ?> padding:6px 4px; font-size:11px; text-align:center; min-width:32px;" title="<?php echo h($th_title); ?>" <?php if (is_superadmin()): ?>onclick="setModalLiburTanggal('<?php echo $tgl_sub; ?>')"<?php endif; ?>>
                            <?php echo $d; ?><br><span style="font-size:9px; font-weight:normal; opacity:0.9;"><?php echo $is_custom_hol ? '🌴' : $h_singkat; ?></span>
                        </th>
                    <?php endfor; ?>
                    <th style="background:#0f172a; color:#fff;">
                        <a href="<?php echo b_sort_url('target', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Target Hari<?php echo b_sort_icon('target', $sort); ?></a>
                    </th>
                    <th style="background:#166534; color:#fff;">
                        <a href="<?php echo b_sort_url('hadir', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">Hadir Sesuai<?php echo b_sort_icon('hadir', $sort); ?></a>
                    </th>
                    <th style="background:#c2410c; color:#fff;">Hadir Di Luar</th>
                    <th style="background:#1d4ed8; color:#fff;">
                        <a href="<?php echo b_sort_url('persen', $sort, $bulan, $tahun, $kategori); ?>" style="color:inherit; text-decoration:none;">% Kehadiran<?php echo b_sort_icon('persen', $sort); ?></a>
                    </th>
                    <th style="background:#475569; color:#fff;">Status Evaluasi</th>
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

                        $badge_tipe = $is_guru 
                            ? "<span class='badge' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;'>👨‍🏫 Guru</span>"
                            : "<span class='badge' style='background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'>👔 Karyawan</span>";

                        $status_eval = "";
                        if ($r['target_hari'] == 0) {
                            $status_eval = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;'>❓ Belum Ada Jadwal</span>";
                        } elseif ($r['persen'] >= 90) {
                            $status_eval = "<span class='badge' style='background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;'>🟢 Baik (≥90%)</span>";
                        } elseif ($r['persen'] >= 80) {
                            $status_eval = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;'>🟡 Cukup (80-89%)</span>";
                        } else {
                            $status_eval = "<span class='badge' style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3;'>🔴 Perlu Perhatian (<80%)</span>";
                        }

                        $diluar_text = ($r['hadir_diluar'] > 0)
                            ? "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;'>⚠️ {$r['hadir_diluar']} hr</span>"
                            : "<span style='color:#94a3b8;'>-</span>";

                        echo "<tr>";
                        echo "<td><code style='background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:700; color:#0f172a;'>" . h($pin) . "</code></td>";
                        echo "<td style='text-align:left; white-space:nowrap;'>
                                <a href='riwayat_karyawan.php?pin=" . urlencode($pin) . "' style='color:#0f172a; text-decoration:none; font-weight:700;' title='Klik untuk melihat riwayat absensi lengkap'>
                                    " . h($nama) . " 📜
                                </a>
                                <div style='font-size:10px; color:#64748b;'>" . h($dept) . "</div>
                              </td>";
                        echo "<td>{$badge_tipe}</td>";

                        for ($d = 1; $d <= $total_hari_bulan; $d++) {
                            $tgl_key  = sprintf("%04d-%02d-%02d", $tahun, $bulan, $d);
                            $day_num  = (int)date('N', strtotime($tgl_key));

                            $is_custom_hol = isset($hari_libur_map[$tgl_key]);
                            $ket_custom = $is_custom_hol ? $hari_libur_map[$tgl_key]['keterangan'] : '';

                            $has_sch = $is_guru ? in_array($day_num, $hari_arr) : ($day_num !== 7);
                            $log_today = $absen_detail[$pin][$tgl_key] ?? null;

                            if ($log_today !== null) {
                                $m_jam = $log_today['masuk'] ?? '-';
                                $p_jam = $log_today['pulang'] ?? '-';

                                if ($log_today['masuk'] && $log_today['pulang']) {
                                    $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Hadir Lengkap (Masuk: {$m_jam}, Pulang: {$p_jam})" . ($is_custom_hol ? " [Libur: {$ket_custom}]" : "");
                                    echo "<td style='background:#dcfce7; color:#166534; font-weight:700; font-size:11px; text-align:center; padding:4px 0;' title='" . h($tooltip) . "'>✔️</td>";
                                } else {
                                    $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Hadir Parsial (Masuk: {$m_jam}, Pulang: {$p_jam})" . ($is_custom_hol ? " [Libur: {$ket_custom}]" : "");
                                    echo "<td style='background:#fef9c3; color:#854d0e; font-weight:700; font-size:11px; text-align:center; padding:4px 0;' title='" . h($tooltip) . "'>⚠️</td>";
                                }
                            } else {
                                if ($is_custom_hol) {
                                    $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Libur Kalender Sekolah (" . $ket_custom . ")";
                                    echo "<td style='background:#f1f5f9; color:#475569; font-weight:700; font-size:11px; text-align:center; padding:4px 0; border:1px solid #cbd5e1;' title='" . h($tooltip) . "'>🌴</td>";
                                } else {
                                    if ($has_sch) {
                                        $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Alpa / Tidak Hadir (Ada Jadwal 0 Log)";
                                        echo "<td style='background:#fee2e2; color:#991b1b; font-weight:700; font-size:11px; text-align:center; padding:4px 0;' title='" . h($tooltip) . "'>❌</td>";
                                    } else {
                                        $tooltip = "Tgl {$d} " . $nama_bulan[$bulan] . ": Libur / Tanpa Jadwal Mengajar";
                                        echo "<td style='background:#f1f5f9; color:#94a3b8; font-size:11px; text-align:center; padding:4px 0;' title='" . h($tooltip) . "'>-</td>";
                                    }
                                }
                            }
                        }

                        echo "<td><b>{$r['target_hari']} hr</b></td>";
                        echo "<td><b style='color:#10b981;'>{$r['hadir_sesuai']} hr</b></td>";
                        echo "<td>{$diluar_text}</td>";
                        echo "<td><b style='font-size:14px; color:#0f172a;'>{$r['persen']}%</b></td>";
                        echo "<td>{$status_eval}</td>";
                        echo "</tr>";
                    }
                } else {
                    $cols = $total_hari_bulan + 8;
                    echo "<tr><td colspan='{$cols}' style='padding:30px; color:#94a3b8;'>Data tidak ditemukan untuk periode ini.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($log_diluar_jadwal_list)): ?>
<!-- TABEL LOG GURU ABSEN DI LUAR JADWAL NGAJAR (Tanpa Kolom NO) -->
<div class="card" style="border: 1px solid #ffedd5; background: #fffcf8;">
    <div class="card-header">
        <div class="card-title" style="color: #c2410c;">
            <span>⚠️ Rincian Guru Absen di Luar Hari Jadwal Ngajar (<?php echo count($log_diluar_jadwal_list); ?> Record)</span>
        </div>
    </div>
    <div style="margin-bottom:14px; font-size:12px; color:#9a3412;">
        Data ini mencatat kehadiran guru pada hari yang bukan jadwal ngajarnya (misal rapat/piket). Data tetap tersimpan tetapi tidak dihitung sebagai penambah % kehadiran target.
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr style="background:#ffedd5;">
                    <th style="color:#9a3412;">PIN</th>
                    <th style="color:#9a3412; text-align:left;">Nama Guru</th>
                    <th style="color:#9a3412;">Tanggal Absen</th>
                    <th style="color:#9a3412;">Hari</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($log_diluar_jadwal_list as $ld) {
                    echo "<tr>
                            <td><code style='background:#fff; padding:3px 8px; border-radius:6px; font-weight:700; color:#0f172a;'>" . h($ld['pin']) . "</code></td>
                            <td style='text-align:left;'>
                                <div style='font-weight:700; color:#0f172a;'>" . h($ld['nama']) . "</div>
                                <div style='font-size:11px; color:#64748b;'>" . h($ld['dept']) . "</div>
                            </td>
                            <td><b>" . h($ld['waktu']) . "</b></td>
                            <td><span class='badge' style='background:#ffedd5; color:#9a3412; border:1px solid #fed7aa;'>{$ld['hari_nama']} (Di Luar Jadwal)</span></td>
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
<div id="modal-hari-libur" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div class="card" style="width:100%; max-width:550px; margin:20px; background:#fff; border-radius:16px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div class="card-header" style="border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
            <div class="card-title" style="margin-bottom:0; font-size:16px; color:#0f172a;">🌴 Kelola Hari Libur Kalender</div>
            <button type="button" onclick="tutupModalHariLibur()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>

        <!-- FORM TAMBAH / UPDATE HARI LIBUR -->
        <form method="POST" action="export_bulanan.php?<?php echo http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $sort]); ?>" style="margin-bottom:20px; background:#f8fafc; padding:14px; border-radius:12px; border:1px solid #e2e8f0;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="tambah_hari_libur">

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                <div style="flex:1; min-width:140px;">
                    <label for="tgl_libur" style="font-size:12px; font-weight:700; color:#334155;">📆 Tanggal Libur:</label>
                    <input type="date" id="tgl_libur" name="tgl_libur" required style="margin-bottom:0; font-size:13px;">
                </div>
                <div style="flex:2; min-width:180px;">
                    <label for="ket_libur" style="font-size:12px; font-weight:700; color:#334155;">📝 Keterangan Libur:</label>
                    <input type="text" id="ket_libur" name="ket_libur" placeholder="Contoh: HUT RI / Libur Semester" required style="margin-bottom:0; font-size:13px;" autocomplete="off">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="background:#8b5cf6; font-size:13px; font-weight:700;">
                ➕ Simpan Hari Libur
            </button>
        </form>

        <!-- DAFTAR HARI LIBUR BULAN INI -->
        <div style="font-size:13px; font-weight:700; color:#334155; margin-bottom:10px;">
            📋 Daftar Hari Libur Terdaftar (<?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?>):
        </div>

        <div class="table-responsive">
            <table style="font-size:12px;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th>Tanggal</th>
                        <th>Keterangan</th>
                        <th style="width:70px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($hari_libur_map)): ?>
                        <?php foreach ($hari_libur_map as $hl): ?>
                            <tr>
                                <td><b><?php echo h(date('d/m/Y', strtotime($hl['tanggal']))); ?></b></td>
                                <td style="text-align:left;"><span class="badge" style="background:#f3e8ff; color:#6b21a8; border:1px solid #e9d5ff;">🌴 <?php echo h($hl['keterangan']); ?></span></td>
                                <td>
                                    <form method="POST" action="export_bulanan.php?<?php echo http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'sort' => $sort]); ?>" style="margin:0;" onsubmit="return confirm('Hapus hari libur ini?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="hapus_hari_libur">
                                        <input type="hidden" name="id_hl" value="<?php echo $hl['id']; ?>">
                                        <button type="submit" class="btn" style="background:#fee2e2; color:#dc2626; padding:3px 8px; font-size:11px;">🗑️ Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" style="color:#94a3b8; padding:16px;">Belum ada hari libur kalender yang didaftarkan bulan ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px; text-align:right;">
            <button type="button" class="btn" style="background:#f1f5f9; color:#475569;" onclick="tutupModalHariLibur()">Tutup</button>
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
