<?php
// ============================================================
// HALAMAN LAPORAN EVALUASI ABSENSI BULANAN
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';

$conn = getDB();

// Input Parameter Bulan & Tahun (Default: Bulan & Tahun Saat Ini)
$bulan    = (int)($_GET['bulan'] ?? date('n'));
$tahun    = (int)($_GET['tahun'] ?? date('Y'));
$kategori = $_GET['kategori'] ?? 'semua'; // 'semua', 'karyawan', 'guru'
$export   = isset($_GET['export']) && $_GET['export'] === '1';

if ($bulan < 1 || $bulan > 12) $bulan = (int)date('n');
if ($tahun < 2020 || $tahun > 2050) $tahun = (int)date('Y');

// Helper: Hitung total hari kerja karyawan di bulan tsb (semua hari kecuali Minggu)
function get_hari_kerja_karyawan($thn, $bln) {
    $total_hari = cal_days_in_month(CAL_GREGORIAN, $bln, $thn);
    $minggu = 0;
    for ($d = 1; $d <= $total_hari; $d++) {
        $w = (int)date('N', mktime(0, 0, 0, $bln, $d, $thn)); // 1=Senin...7=Minggu
        if ($w === 7) $minggu++;
    }
    return $total_hari - $minggu;
}

// Helper: Hitung target hari ngajar guru di bulan tsb sesuai jadwal
function get_target_hari_guru($conn, $pin, $thn, $bln) {
    // Ambil daftar hari ngajar guru dari DB (1=Senin...6=Sabtu)
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
        $w = (int)date('N', mktime(0, 0, 0, $bln, $d, $thn)); // 1=Senin...6=Sabtu, 7=Minggu
        if (in_array($w, $hari_ngajar)) {
            $count++;
        }
    }
    return $count;
}

$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$hari_kerja_karyawan_default = get_hari_kerja_karyawan($tahun, $bulan);

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
               ORDER BY mk.tipe DESC, mk.nama ASC";
$res_master = $conn->query($sql_master);

// Ambil semua data log absensi di bulan & tahun tsb
$start_date = sprintf("%04d-%02d-01 00:00:00", $tahun, $bulan);
$end_date   = date("Y-m-t 23:59:59", strtotime($start_date));

$sql_log = "SELECT la.*, 
                   (MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1) AS hari_num,
                   DATE(la.waktu) AS tgl_absen
            FROM log_absen la 
            WHERE la.waktu >= ? AND la.waktu <= ?";
$stmt_log = $conn->prepare($sql_log);
$stmt_log->bind_param("ss", $start_date, $end_date);
$stmt_log->execute();
$res_log = $stmt_log->get_result();

// Organisasikan log absensi per PIN dan per Tanggal
// $absen_data[pin][tgl_absen] = ['hari_num' => x, 'status' => y]
$absen_data = [];
$absen_diluar_jadwal = []; // Log guru di luar jadwal

while ($l = $res_log->fetch_assoc()) {
    $pin = $l['pin'];
    $tgl = $l['tgl_absen'];
    if (!isset($absen_data[$pin])) {
        $absen_data[$pin] = [];
    }
    // Simpan unique per hari per pin
    if (!isset($absen_data[$pin][$tgl])) {
        $absen_data[$pin][$tgl] = (int)$l['hari_num'];
    }
}

// Olah Rekap
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
                    // Belum ada jadwal
                    $total_hadir_diluar++;
                } elseif (in_array($hari_num, $hari_arr)) {
                    // Sesuai jadwal
                    $total_hadir_sesuai++;
                } else {
                    // Di luar jadwal
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
                // Karyawan: hitung semua hari kecuali Minggu (7)
                if ($hari_num !== 7) {
                    $total_hadir_sesuai++;
                }
            }
        }

        // Persentase
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

// PROSES EXPORT EXCEL
if ($export) {
    $filename = "Laporan_Evaluasi_Kehadiran_{$nama_bulan[$bulan]}_{$tahun}.xls";
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
            .header-title { font-size: 16pt; font-weight: bold; text-align: center; }
            .header-school { font-size: 14pt; font-weight: bold; color: #3b82f6; text-align: center; margin-bottom: 10px; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 25px; }
            th { background-color: #3b82f6; color: white; font-weight: bold; border: 1px solid #1d4ed8; padding: 8px; text-align: center; }
            td { border: 1px solid #cbd5e1; padding: 6px 8px; font-size: 10pt; text-align: center; }
            .text-left { text-align: left; }
            .badge-green { color: #15803d; font-weight: bold; }
            .badge-yellow { color: #b45309; font-weight: bold; }
            .badge-red { color: #be123c; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="header-title">LAPORAN EVALUASI KEHADIRAN BULANAN</div>
        <div class="header-school">SMK PASUNDAN 2 BANDUNG</div>
        <div style="text-align:center; font-size:11pt; margin-bottom:15px;">
            <b>Bulan:</b> <?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?> | 
            <b>Kategori:</b> <?php echo ucfirst($kategori); ?> | 
            <b>Hari Kerja Karyawan:</b> <?php echo $hari_kerja_karyawan_default; ?> Hari
        </div>

        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>PIN</th>
                    <th>Nama Lengkap</th>
                    <th>Departemen / Jabatan</th>
                    <th>Tipe</th>
                    <th>Target Hari</th>
                    <th>Hadir Sesuai Jadwal</th>
                    <th>Hadir di Luar Jadwal</th>
                    <th>% Kehadiran</th>
                    <th>Evaluasi Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                foreach ($rekap_data as $r) {
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

                    echo "<tr>
                            <td>{$no}</td>
                            <td>'" . h($r['pin']) . "</td>
                            <td class='text-left'>" . h($r['nama']) . "</td>
                            <td class='text-left'>" . h($r['dept']) . "</td>
                            <td>" . ucfirst($r['tipe']) . "</td>
                            <td>{$r['target_hari']} hr</td>
                            <td><b>{$r['hadir_sesuai']} hr</b></td>
                            <td>" . ($r['hadir_diluar'] > 0 ? "{$r['hadir_diluar']} hr" : "-") . "</td>
                            <td><b>{$r['persen']}%</b></td>
                            <td class='{$eval_class}'>{$eval_text}</td>
                          </tr>";
                    $no++;
                }
                ?>
            </tbody>
        </table>

        <?php if (!empty($log_diluar_jadwal_list)): ?>
        <br>
        <div style="font-size:12pt; font-weight:bold; color:#c2410c; margin-bottom:8px;">⚠️ CATATAN: RINCIAN GURU ABSEN DI LUAR HARI JADWAL NGAJAR</div>
        <table>
            <thead>
                <tr style="background:#ffedd5; color:#9a3412;">
                    <th>No</th>
                    <th>PIN</th>
                    <th>Nama Guru</th>
                    <th>Departemen</th>
                    <th>Tanggal Absen</th>
                    <th>Hari</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no2 = 1;
                foreach ($log_diluar_jadwal_list as $ld) {
                    echo "<tr>
                            <td>{$no2}</td>
                            <td>'" . h($ld['pin']) . "</td>
                            <td class='text-left'>" . h($ld['nama']) . "</td>
                            <td class='text-left'>" . h($ld['dept']) . "</td>
                            <td>" . h($ld['waktu']) . "</td>
                            <td>" . h($ld['hari_nama']) . " (Di luar jadwal)</td>
                          </tr>";
                    $no2++;
                }
                ?>
            </tbody>
        </table>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

render_header("Laporan Evaluasi Bulanan", "laporan_bulanan");
?>

<!-- FILTER CONTROL CARD -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" action="export_bulanan.php" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; align-items:end;">
        <div>
            <label for="bulan">📅 Pilih Bulan:</label>
            <select name="bulan" id="bulan" style="margin-bottom:0;">
                <?php foreach ($nama_bulan as $num => $nama_b): ?>
                <option value="<?php echo $num; ?>" <?php echo $bulan === $num ? 'selected' : ''; ?>><?php echo $nama_b; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="tahun">📆 Pilih Tahun:</label>
            <select name="tahun" id="tahun" style="margin-bottom:0;">
                <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $tahun === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div>
            <label for="kategori">👥 Kategori:</label>
            <select name="kategori" id="kategori" style="margin-bottom:0;">
                <option value="semua" <?php echo $kategori === 'semua' ? 'selected' : ''; ?>>Semua (Guru & Karyawan)</option>
                <option value="guru" <?php echo $kategori === 'guru' ? 'selected' : ''; ?>>👨‍🏫 Guru Pengajar Only</option>
                <option value="karyawan" <?php echo $kategori === 'karyawan' ? 'selected' : ''; ?>>👔 Karyawan / Staff Only</option>
            </select>
        </div>

        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary" style="flex:1;">🔍 Tampilkan</button>
            <a href="<?php echo 'export_bulanan.php?' . http_build_query(['bulan' => $bulan, 'tahun' => $tahun, 'kategori' => $kategori, 'export' => 1]); ?>" class="btn btn-success">📊 Export Excel</a>
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
        <div style="font-size:18px; font-weight:800; color:#3b82f6; margin-top:2px;"><?php echo $hari_kerja_karyawan_default; ?> Hari <span style="font-size:11px; font-weight:500; color:#64748b;">(Excl. Minggu)</span></div>
    </div>
    <div class="card" style="margin-bottom:0; padding:16px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Total Master Evaluasi</div>
        <div style="font-size:18px; font-weight:800; color:#10b981; margin-top:2px;"><?php echo count($rekap_data); ?> Orang</div>
    </div>
</div>

<!-- TABEL REKAP EVALUASI -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>📋 Rekapitulasi Kehadiran & Evaluasi Kinerja</span>
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>PIN</th>
                    <th style="text-align:left;">Nama Guru & Karyawan</th>
                    <th>Tipe</th>
                    <th>Target Hari</th>
                    <th>Hadir Sesuai</th>
                    <th>Hadir Di Luar Jadwal</th>
                    <th>% Kehadiran</th>
                    <th>Status Evaluasi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($rekap_data)) {
                    $no = 1;
                    foreach ($rekap_data as $r) {
                        $is_guru = ($r['tipe'] === 'guru');
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
                            ? "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;'>⚠️ {$r['hadir_diluar']} Hari</span>"
                            : "<span style='color:#94a3b8;'>-</span>";

                        echo "<tr>
                                <td><b>{$no}</b></td>
                                <td><code style='background:#f1f5f9; padding:4px 8px; border-radius:6px; font-weight:700; color:#0f172a;'>" . h($r['pin']) . "</code></td>
                                <td style='text-align:left;'>
                                    <div style='font-weight:700; color:#0f172a;'>" . h($r['nama']) . "</div>
                                    <div style='font-size:11px; color:#64748b;'>" . h($r['dept']) . "</div>
                                </td>
                                <td>{$badge_tipe}</td>
                                <td><b>{$r['target_hari']} Hari</b></td>
                                <td><b style='color:#10b981;'>{$r['hadir_sesuai']} Hari</b></td>
                                <td>{$diluar_text}</td>
                                <td><b style='font-size:15px; color:#0f172a;'>{$r['persen']}%</b></td>
                                <td>{$status_eval}</td>
                              </tr>";
                        $no++;
                    }
                } else {
                    echo "<tr><td colspan='9' style='padding:30px; color:#94a3b8;'>Data tidak ditemukan untuk periode ini.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($log_diluar_jadwal_list)): ?>
<!-- TABEL LOG GURU ABSEN DI LUAR JADWAL NGAJAR -->
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
                    <th style="color:#9a3412;">No</th>
                    <th style="color:#9a3412;">PIN</th>
                    <th style="color:#9a3412; text-align:left;">Nama Guru</th>
                    <th style="color:#9a3412;">Tanggal Absen</th>
                    <th style="color:#9a3412;">Hari</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no2 = 1;
                foreach ($log_diluar_jadwal_list as $ld) {
                    echo "<tr>
                            <td><b>{$no2}</b></td>
                            <td><code style='background:#fff; padding:3px 8px; border-radius:6px; font-weight:700; color:#0f172a;'>" . h($ld['pin']) . "</code></td>
                            <td style='text-align:left;'>
                                <div style='font-weight:700; color:#0f172a;'>" . h($ld['nama']) . "</div>
                                <div style='font-size:11px; color:#64748b;'>" . h($ld['dept']) . "</div>
                            </td>
                            <td><b>" . h($ld['waktu']) . "</b></td>
                            <td><span class='badge' style='background:#ffedd5; color:#9a3412; border:1px solid #fed7aa;'>{$ld['hari_nama']} (Di Luar Jadwal)</span></td>
                          </tr>";
                    $no2++;
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>
