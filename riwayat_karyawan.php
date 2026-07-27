<?php
// ============================================================
// HALAMAN REKAP RIWAYAT ABSENSI INDIVIDUAL GURU & KARYAWAN
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';

$conn = getDB();
$pesan_sukses = "";
$pesan_error  = "";

// PROSES HAPUS LOG ABSEN (Superadmin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus_log_absen') {
    csrf_verify();
    if (!is_superadmin()) {
        $pesan_error = "Akses Ditolak: Hanya Superadmin yang berhak menghapus log absen.";
    } else {
        $id_hapus = (int)($_POST['id_log_hapus'] ?? 0);
        if ($id_hapus > 0) {
            $stmt_del = $conn->prepare("DELETE FROM log_absen WHERE id = ?");
            $stmt_del->bind_param("i", $id_hapus);
            if ($stmt_del->execute()) {
                $pesan_sukses = "Log absen berhasil dihapus.";
            } else {
                $pesan_error = "Gagal menghapus log absen: " . h($conn->error);
            }
        }
    }
}

// Parameter PIN, Range Tanggal, & Sorting
$pin_selected = trim($_GET['pin'] ?? $_POST['pin_selected'] ?? '');
$tgl_mulai    = trim($_GET['tgl_mulai'] ?? '');
$tgl_selesai  = trim($_GET['tgl_selesai'] ?? '');
$sort_order   = $_GET['sort'] ?? 'desc'; // 'desc' (terbaru ke tertua) atau 'asc' (pertama ke terbaru)
$export       = isset($_GET['export']) && $_GET['export'] === '1';

// Ambil semua daftar karyawan untuk dropdown pencarian
$sql_all = "SELECT pin, nama, departemen, tipe FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC";
$res_all = $conn->query($sql_all);
$karyawan_list = [];
if ($res_all && $res_all->num_rows > 0) {
    while ($r = $res_all->fetch_assoc()) {
        $karyawan_list[] = $r;
    }
}

// Jika PIN belum dipilih tapi ada data, default pilih PIN pertama
if (empty($pin_selected) && !empty($karyawan_list)) {
    $pin_selected = $karyawan_list[0]['pin'];
}

// Ambil data detail karyawan terpilih
$detail_user = null;
if (!empty($pin_selected)) {
    $stmt_u = $conn->prepare("SELECT * FROM master_karyawan WHERE pin = ?");
    $stmt_u->bind_param("s", $pin_selected);
    $stmt_u->execute();
    $detail_user = $stmt_u->get_result()->fetch_assoc();
}

// Ambil jadwal ngajar guru ini (jika tipe = guru)
$hari_ngajar_arr = [];
if ($detail_user && $detail_user['tipe'] === 'guru') {
    $stmt_j = $conn->prepare("SELECT hari FROM jadwal_guru WHERE pin = ?");
    $stmt_j->bind_param("s", $pin_selected);
    $stmt_j->execute();
    $res_j = $stmt_j->get_result();
    while ($rj = $res_j->fetch_assoc()) {
        $hari_ngajar_arr[] = (int)$rj['hari'];
    }
}

// Query Log Absensi Karyawan Terpilih
$logs = [];
$total_masuk  = 0;
$total_pulang = 0;
$absen_pertama = "-";
$absen_terakhir = "-";

if (!empty($pin_selected)) {
    $where = ["la.pin = ?"];
    $params = [$pin_selected];
    $types = "s";

    if (!empty($tgl_mulai)) {
        $where[] = "la.waktu >= ?";
        $params[] = $tgl_mulai . " 00:00:00";
        $types .= "s";
    }

    if (!empty($tgl_selesai)) {
        $where[] = "la.waktu <= ?";
        $params[] = $tgl_selesai . " 23:59:59";
        $types .= "s";
    }

    $where_sql = "WHERE " . implode(" AND ", $where);
    $order_sql = ($sort_order === 'asc') ? "ASC" : "DESC";

    $sql_log = "SELECT la.*, 
                       (MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1) AS hari_num,
                       DATE(la.waktu) AS tgl_only,
                       TIME(la.waktu) AS jam_only
                FROM log_absen la
                {$where_sql}
                ORDER BY la.waktu {$order_sql}";

    $stmt_l = $conn->prepare($sql_log);
    $stmt_l->bind_param($types, ...$params);
    $stmt_l->execute();
    $res_l = $stmt_l->get_result();

    if ($res_l && $res_l->num_rows > 0) {
        while ($l = $res_l->fetch_assoc()) {
            if ($l['status'] == '0') $total_masuk++;
            if ($l['status'] == '1') $total_pulang++;
            $logs[] = $l;
        }

        // Cari waktu pertama & terakhir absen di database
        $stmt_stat = $conn->prepare("SELECT MIN(waktu) AS tgl_min, MAX(waktu) AS tgl_max FROM log_absen WHERE pin = ?");
        $stmt_stat->bind_param("s", $pin_selected);
        $stmt_stat->execute();
        $res_stat = $stmt_stat->get_result()->fetch_assoc();

        if ($res_stat) {
            $absen_pertama  = !empty($res_stat['tgl_min']) ? date('d/m/Y H:i:s', strtotime($res_stat['tgl_min'])) : '-';
            $absen_terakhir = !empty($res_stat['tgl_max']) ? date('d/m/Y H:i:s', strtotime($res_stat['tgl_max'])) : '-';
        }
    }
}

$nama_hari_indo = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu'
];

// EXPORT EXCEL INDIVIDUAL
if ($export && $detail_user) {
    $filename = "Riwayat_Absen_" . preg_replace('/[^a-zA-Z0-9]/', '_', $detail_user['nama']) . "_{$pin_selected}.xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename={$filename}");
    header("Pragma: no-cache");
    header("Expires: 0");
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: Arial, sans-serif; font-size: 10pt; }
            .header-title { font-size: 14pt; font-weight: bold; text-align: center; margin-bottom: 4px; }
            .header-sub { font-size: 11pt; text-align: center; margin-bottom: 15px; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
            th { background-color: #0f172a; color: #ffffff; font-weight: bold; padding: 8px; border: 1px solid #000; text-align: center; }
            td { padding: 6px 8px; border: 1px solid #ccc; text-align: center; vertical-align: middle; }
            .text-left { text-align: left; }
        </style>
    </head>
    <body>
        <div class="header-title">REKAP RIWAYAT ABSENSI INDIVIDUAL</div>
        <div class="header-sub">
            <b>PIN:</b> <?php echo h($detail_user['pin']); ?> | 
            <b>Nama:</b> <?php echo h($detail_user['nama']); ?> | 
            <b>Departemen:</b> <?php echo h($detail_user['departemen']); ?> | 
            <b>Tipe:</b> <?php echo ucfirst($detail_user['tipe']); ?>
        </div>
        <div style="margin-bottom:12px; font-size:10pt;">
            <b>Total Log:</b> <?php echo count($logs); ?> Record (Masuk: <?php echo $total_masuk; ?>, Pulang: <?php echo $total_pulang; ?>) | 
            <b>Absen Pertama:</b> <?php echo $absen_pertama; ?> | 
            <b>Absen Terakhir:</b> <?php echo $absen_terakhir; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal & Hari</th>
                    <th>Waktu Absen</th>
                    <th>Status Absensi</th>
                    <th>Keterangan Jadwal</th>
                    <th>Tipe Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                foreach ($logs as $l) {
                    $hn = (int)$l['hari_num'];
                    $h_nama = $nama_hari_indo[$hn] ?? '';
                    $tgl_fmt = $h_nama . ", " . date('d/m/Y', strtotime($l['waktu']));
                    $waktu_fmt = date('H:i:s', strtotime($l['waktu']));

                    $st_text = ($l['status'] == '0') ? "Masuk" : (($l['status'] == '1') ? "Pulang" : "Unknown");

                    $ket_jadwal = "";
                    if ($detail_user['tipe'] === 'guru') {
                        if (empty($hari_ngajar_arr)) {
                            $ket_jadwal = "Belum Ada Jadwal";
                        } elseif (in_array($hn, $hari_ngajar_arr)) {
                            $ket_jadwal = "Sesuai Jadwal";
                        } else {
                            $ket_jadwal = "Di Luar Jadwal";
                        }
                    } else {
                        $ket_jadwal = ($hn == 7) ? "Di Luar Hari Kerja" : "Hari Kerja Kalender";
                    }

                    $verif = "Lainnya";
                    if ($l['tipe_verifikasi'] == '1') $verif = "Sidik Jari";
                    elseif ($l['tipe_verifikasi'] == '15') $verif = "Wajah";

                    echo "<tr>
                            <td>{$no}</td>
                            <td>{$tgl_fmt}</td>
                            <td><b>{$waktu_fmt}</b></td>
                            <td>{$st_text}</td>
                            <td>{$ket_jadwal}</td>
                            <td>{$verif}</td>
                          </tr>";
                    $no++;
                }
                ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

render_header("Riwayat Absensi Individual", "riwayat");
?>

<?php if ($pesan_sukses): ?>
<div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; color: #065f46; font-size: 14px; font-weight: 500;">
    <?php echo $pesan_sukses; ?>
</div>
<?php endif; ?>

<?php if ($pesan_error): ?>
<div style="background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #f87171; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; color: #991b1b; font-size: 14px; font-weight: 500;">
    <?php echo $pesan_error; ?>
</div>
<?php endif; ?>

<!-- PANEL SEARCH & FILTER KARYAWAN -->
<div class="card" style="margin-bottom:20px; padding:20px;">
    <form method="GET" action="riwayat_karyawan.php" style="margin:0;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:16px;">
            <!-- SELECT / SEARCH GURU & KARYAWAN -->
            <div style="grid-column: span 2;">
                <label for="pin" style="font-weight:700; color:#0f172a;">🔍 Pilih / Cari Guru & Karyawan:</label>
                <select name="pin" id="pin" onchange="this.form.submit()" style="margin-bottom:0; font-size:14px; font-weight:600; cursor:pointer;">
                    <?php if (empty($karyawan_list)): ?>
                        <option value="">-- Tidak ada data karyawan --</option>
                    <?php else: ?>
                        <?php foreach ($karyawan_list as $k): 
                            $dept_label = !empty($k['departemen']) ? " — " . h($k['departemen']) : "";
                        ?>
                            <option value="<?php echo h($k['pin']); ?>" <?php echo $pin_selected === $k['pin'] ? 'selected' : ''; ?>>
                                <?php echo h($k['nama']) . $dept_label; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- FILTER DATE RANGE -->
            <div>
                <label for="tgl_mulai">Dari Tanggal:</label>
                <input type="date" id="tgl_mulai" name="tgl_mulai" value="<?php echo h($tgl_mulai); ?>" style="margin-bottom:0;">
            </div>

            <div>
                <label for="tgl_selesai">Sampai Tanggal:</label>
                <input type="date" id="tgl_selesai" name="tgl_selesai" value="<?php echo h($tgl_selesai); ?>" style="margin-bottom:0;">
            </div>

            <!-- SORT ORDER -->
            <div>
                <label for="sort">Urutkan Waktu:</label>
                <select name="sort" id="sort" style="margin-bottom:0;">
                    <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>⏰ Terbaru ➔ Pertamakan (Terbaru Dulu)</option>
                    <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>⏳ Pertama ➔ Terkini (Awal Terlebih Dulu)</option>
                </select>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-top:14px; border-top:1px solid #f1f5f9;">
            <div style="font-size:12px; color:#64748b;">
                💡 <b>Petunjuk:</b> Pilih nama guru/karyawan untuk melihat riwayat absensi lengkap dari awal hingga akhir.
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">🔍 Tampilkan Riwayat</button>
                <?php if ($detail_user): ?>
                <a href="<?php echo 'riwayat_karyawan.php?' . http_build_query(['pin' => $pin_selected, 'tgl_mulai' => $tgl_mulai, 'tgl_selesai' => $tgl_selesai, 'sort' => $sort_order, 'export' => 1]); ?>" class="btn btn-success">
                    📊 Export Riwayat ke Excel
                </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php if ($detail_user): ?>
<!-- STATISTIK & PROFILE HEADER CARD -->
<div class="card" style="margin-bottom:20px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #e2e8f0;">
        <div style="display:flex; align-items:center; gap:14px;">
            <div style="width:52px; height:52px; background:#eff6ff; border:2px solid #bfdbfe; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:24px;">
                <?php echo $detail_user['tipe'] === 'guru' ? '👨‍🏫' : '👔'; ?>
            </div>
            <div>
                <h3 style="font-size:20px; font-weight:800; color:#0f172a; margin-bottom:2px;"><?php echo h($detail_user['nama']); ?></h3>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:13px; color:#64748b;">
                    <span>PIN: <code style="background:#f1f5f9; padding:2px 8px; border-radius:6px; font-weight:700; color:#0f172a;"><?php echo h($detail_user['pin']); ?></code></span>
                    <span>•</span>
                    <span>Departemen: <b><?php echo h($detail_user['departemen'] ?: '-'); ?></b></span>
                    <span>•</span>
                    <?php if ($detail_user['tipe'] === 'guru'): ?>
                        <span class="badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;">👨‍🏫 Guru Pengajar</span>
                    <?php else: ?>
                        <span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;">👔 Karyawan / Staff</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (is_superadmin()): ?>
        <a href="input_karyawan.php" class="btn" style="background:#f1f5f9; color:#334155; font-size:12px; padding:6px 12px; border:1px solid #cbd5e1;">✏️ Edit Profil Karyawan</a>
        <?php endif; ?>
    </div>

    <!-- 4 SUMMARY CARDS -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap:14px;">
        <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
            <div style="font-size:12px; color:#64748b; font-weight:600;">Total Record Absensi</div>
            <div style="font-size:22px; font-weight:800; color:#0f172a; margin-top:4px;"><?php echo count($logs); ?> <span style="font-size:12px; font-weight:500; color:#64748b;">Log</span></div>
            <div style="font-size:11px; color:#10b981; margin-top:4px; font-weight:600;">🟢 <?php echo $total_masuk; ?> Masuk · 🔴 <?php echo $total_pulang; ?> Pulang</div>
        </div>

        <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
            <div style="font-size:12px; color:#64748b; font-weight:600;">Absen Pertama Kali</div>
            <div style="font-size:14px; font-weight:700; color:#2563eb; margin-top:6px;"><?php echo $absen_pertama; ?></div>
            <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Awal rekaman absensi</div>
        </div>

        <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
            <div style="font-size:12px; color:#64748b; font-weight:600;">Absen Terakhir Kali</div>
            <div style="font-size:14px; font-weight:700; color:#059669; margin-top:6px;"><?php echo $absen_terakhir; ?></div>
            <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Rekaman absensi terkini</div>
        </div>

        <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
            <div style="font-size:12px; color:#64748b; font-weight:600;">Pengaturan Jadwal</div>
            <div style="font-size:13px; font-weight:700; color:#0f172a; margin-top:6px;">
                <?php
                if ($detail_user['tipe'] === 'guru') {
                    echo empty($hari_ngajar_arr) ? "<span style='color:#d97706;'>❓ Belum Ada Jadwal</span>" : "<span style='color:#1d4ed8;'>" . count($hari_ngajar_arr) . " Hari Ngajar / Wk</span>";
                } else {
                    echo "<span style='color:#475569;'>Kalender Kerja (Senin–Sabtu)</span>";
                }
                ?>
            </div>
            <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Status skema jadwal</div>
        </div>
    </div>
</div>

<!-- TABEL DETAIL RIWAYAT ABSENSI -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>📜 Daftar Riwayat Lengkap Aktivitas Absensi (<?php echo count($logs); ?> Record)</span>
        </div>

        <div style="font-size:12px; color:#64748b;">
            Diurutkan berdasarkan <b><?php echo $sort_order === 'desc' ? 'Waktu Terbaru ➔ Tertua' : 'Waktu Tertua ➔ Terbaru'; ?></b>
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th style="text-align:left;">Tanggal & Hari</th>
                    <th>Waktu Absen</th>
                    <th>Status Absensi</th>
                    <th>Keterangan Jadwal</th>
                    <th>Tipe Verifikasi</th>
                    <?php if (is_superadmin()): ?>
                    <th>Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($logs)) {
                    $no = 1;
                    foreach ($logs as $l) {
                        $hn = (int)$l['hari_num'];
                        $h_nama = $nama_hari_indo[$hn] ?? '';
                        $tgl_fmt = "<b>{$h_nama}</b>, " . date('d/m/Y', strtotime($l['waktu']));
                        $jam_fmt = date('H:i:s', strtotime($l['waktu']));

                        $st_badge = ($l['status'] == '0')
                            ? "<span class='badge badge-masuk'>🟢 Masuk</span>"
                            : (($l['status'] == '1')
                                ? "<span class='badge badge-pulang'>🔴 Pulang</span>"
                                : "<span class='badge badge-verif'>Unknown</span>");

                        $ket_badge = "";
                        if ($detail_user['tipe'] === 'guru') {
                            if (empty($hari_ngajar_arr)) {
                                $ket_badge = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;'>❓ Belum Ada Jadwal</span>";
                            } elseif (in_array($hn, $hari_ngajar_arr)) {
                                $ket_badge = "<span class='badge' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;'>👨‍🏫 Sesuai Jadwal Ngajar</span>";
                            } else {
                                $ket_badge = "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;'>⚠️ Di Luar Jadwal</span>";
                            }
                        } else {
                            if ($hn == 7) {
                                $ket_badge = "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;'>⚠️ Di Luar Hari Kerja (Minggu)</span>";
                            } else {
                                $ket_badge = "<span class='badge' style='background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'>👔 Hari Kerja Kalender</span>";
                            }
                        }

                        $verif_badge = "<span class='badge badge-verif'>";
                        if ($l['tipe_verifikasi'] == '1') $verif_badge .= "Sidik Jari 👆";
                        elseif ($l['tipe_verifikasi'] == '15') $verif_badge .= "Wajah 👤";
                        elseif ($l['tipe_verifikasi'] == '0' || $l['tipe_verifikasi'] == '99') $verif_badge .= "Manual Admin ✏️";
                        else $verif_badge .= "Password / PIN";
                        $verif_badge .= "</span>";

                        $td_aksi = "";
                        if (is_superadmin()) {
                            $csrf_tok = csrf_token();
                            $td_aksi = "<td>
                                            <form method='POST' action='riwayat_karyawan.php?pin=" . urlencode($pin_selected) . "&tgl_mulai=" . urlencode($tgl_mulai) . "&tgl_selesai=" . urlencode($tgl_selesai) . "&sort=" . urlencode($sort_order) . "' style='margin:0;' onsubmit=\"return confirm('Yakin ingin menghapus data log absen ini?')\">
                                                " . csrf_field() . "
                                                <input type='hidden' name='action' value='hapus_log_absen'>
                                                <input type='hidden' name='id_log_hapus' value='{$l['id']}'>
                                                <input type='hidden' name='pin_selected' value='{$pin_selected}'>
                                                <button type='submit' class='btn' style='background:#fee2e2; color:#dc2626; font-size:11px; padding:4px 8px; border:1px solid #fca5a5;'>🗑️ Hapus</button>
                                            </form>
                                        </td>";
                        }

                        echo "<tr>
                                <td><b>{$no}</b></td>
                                <td style='text-align:left;'>{$tgl_fmt}</td>
                                <td><b style='color:#0f172a; font-size:14px;'>{$jam_fmt}</b></td>
                                <td>{$st_badge}</td>
                                <td>{$ket_badge}</td>
                                <td>{$verif_badge}</td>
                                {$td_aksi}
                              </tr>";
                        $no++;
                    }
                } else {
                    $colspan = is_superadmin() ? 7 : 6;
                    echo "<tr><td colspan='{$colspan}' style='padding:35px; color:#94a3b8;'>Belum ada data riwayat absensi untuk karyawan ini.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>
