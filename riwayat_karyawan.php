<?php
// ============================================================
// HALAMAN REKAP RIWAYAT ABSENSI INDIVIDUAL GURU & KARYAWAN
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('riwayat')) {
    header("Location: index.php?error=access_denied");
    exit;
}

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
                $pesan_sukses = "Log absen berhasil dihapus dari sistem.";
            } else {
                $pesan_error = "Gagal menghapus log absen: " . h($conn->error);
            }
        }
    }
}

// PROSES TUKAR STATUS LOG ABSEN (Superadmin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tukar_status_log') {
    csrf_verify();
    if (!is_superadmin()) {
        $pesan_error = "Akses Ditolak: Hanya Superadmin yang berhak mengubah status log absen.";
    } else {
        $id_toggle = (int)($_POST['id_log_toggle'] ?? 0);
        if ($id_toggle > 0) {
            $stmt_cur = $conn->prepare("SELECT status FROM log_absen WHERE id = ?");
            $stmt_cur->bind_param("i", $id_toggle);
            $stmt_cur->execute();
            $res_cur = $stmt_cur->get_result()->fetch_assoc();

            if ($res_cur) {
                $status_baru = ($res_cur['status'] === '0') ? '1' : '0';
                $status_label = ($status_baru === '0') ? 'Masuk' : 'Pulang';

                $stmt_upd = $conn->prepare("UPDATE log_absen SET status = ? WHERE id = ?");
                $stmt_upd->bind_param("si", $status_baru, $id_toggle);
                if ($stmt_upd->execute()) {
                    $pesan_sukses = "Status log absen berhasil ditukar menjadi {$status_label}.";
                }
            }
        }
    }
}

// Parameter PIN, Range Tanggal, Sorting, & Tab Aktif
$pin_selected = trim($_GET['pin'] ?? $_POST['pin_selected'] ?? '');
$tgl_mulai    = trim($_GET['tgl_mulai'] ?? '');
$tgl_selesai  = trim($_GET['tgl_selesai'] ?? '');
$sort_order   = $_GET['sort'] ?? 'desc';
$export       = isset($_GET['export']) && $_GET['export'] === '1';
$active_tab   = $_GET['tab'] ?? 'riwayat'; // 'riwayat' atau 'profil'

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

<style>
/* ===== PREMIUM RIWAYAT KARYAWAN REDESIGN ===== */
.riwayat-container {
    display: flex;
    flex-direction: column;
    gap: 24px;
    margin-bottom: 30px;
}

/* FILTER CARD */
.filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.04);
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
    margin-bottom: 20px;
}
.filter-grid-emp {
    grid-column: span 2;
}
@media (max-width: 768px) {
    .filter-grid-emp {
        grid-column: span 1;
    }
}

.form-label-custom {
    font-size: 12.5px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.search-input-wrap {
    position: relative;
}
.searchable-input-custom {
    width: 100%;
    padding: 11px 16px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    font-weight: 600;
    transition: all 0.2s ease;
}
.searchable-input-custom:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.searchable-dropdown-custom {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    max-height: 260px;
    overflow-y: auto;
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.15);
    z-index: 99;
    display: none;
}
.searchable-item-custom {
    padding: 11px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13.5px;
    transition: background 0.15s ease;
}
.searchable-item-custom:last-child {
    border-bottom: none;
}
.searchable-item-custom:hover {
    background: #f8fafc;
}

.input-date-custom, .select-custom {
    width: 100%;
    padding: 10.5px 14px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    font-weight: 500;
    transition: all 0.2s ease;
}
.input-date-custom:focus, .select-custom:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.filter-actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    padding-top: 18px;
    border-top: 1px solid #f1f5f9;
}
.filter-hint {
    font-size: 12.5px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* BUTTONS */
.btn-primary-custom {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 13.5px;
    padding: 10px 20px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    transition: all 0.2s ease;
}
.btn-primary-custom:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
}
.btn-excel-custom {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 13.5px;
    padding: 10px 20px;
    border-radius: 10px;
    border: none;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    transition: all 0.2s ease;
}
.btn-excel-custom:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    transform: translateY(-1px);
    color: #ffffff;
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
}
.btn-pdf-custom {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 13.5px;
    padding: 10px 20px;
    border-radius: 10px;
    border: none;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
    transition: all 0.2s ease;
}
.btn-pdf-custom:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    transform: translateY(-1px);
    color: #ffffff;
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.3);
}

/* HERO BANNER CARD */
.hero-card {
    position: relative;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #1e3a8a 100%);
    border-radius: 20px;
    padding: 30px 32px;
    color: #ffffff;
    overflow: hidden;
    box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.22);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.hero-main {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}
.hero-profile-info {
    display: flex;
    align-items: center;
    gap: 22px;
    flex: 1;
    min-width: 280px;
}
.hero-avatar-wrap {
    position: relative;
    width: 90px;
    height: 90px;
    flex-shrink: 0;
}
.hero-avatar-img {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    object-fit: cover;
    border: 3.5px solid rgba(255, 255, 255, 0.85);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}
.hero-avatar-initials {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border: 3.5px solid rgba(255, 255, 255, 0.85);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 34px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -1px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}
.hero-status-ping {
    position: absolute;
    bottom: 3px;
    right: 3px;
    width: 16px;
    height: 16px;
    background: #10b981;
    border: 2.5px solid #0f172a;
    border-radius: 50%;
    box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
}
.hero-name {
    font-size: 24px;
    font-weight: 800;
    color: #ffffff;
    margin: 2px 0 6px 0;
    line-height: 1.25;
}
.hero-tags {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.hero-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3.5px 12px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 600;
}
.hero-tag-dept { background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.2); color: #f1f5f9; }
.hero-tag-pin { background: rgba(59, 130, 246, 0.25); border: 1px solid rgba(147, 197, 253, 0.35); color: #bfdbfe; font-family: monospace; }
.hero-tag-age { background: rgba(168, 85, 247, 0.25); border: 1px solid rgba(216, 180, 254, 0.35); color: #e9d5ff; }

/* TAB NAVIGATION */
.tab-bar-custom {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0;
    margin-bottom: 20px;
}
.tab-link-custom {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    border-radius: 12px 12px 0 0;
    transition: all 0.2s ease;
    margin-bottom: -2px;
}
.tab-link-custom.active {
    background: #ffffff;
    color: #2563eb;
    border: 2px solid #e2e8f0;
    border-bottom: 2px solid #ffffff;
}
.tab-link-custom.inactive {
    background: transparent;
    color: #64748b;
    border: 2px solid transparent;
}
.tab-link-custom.inactive:hover {
    color: #0f172a;
    background: rgba(241, 245, 249, 0.6);
}

/* STATS SUMMARY GRID */
.stats-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.summary-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03);
    transition: all 0.2s ease;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}
.summary-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.summary-title {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.summary-icon-box {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.summary-val {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}
.summary-sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
    font-weight: 500;
}

/* DATA TABLE STYLES */
.table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.04);
}
.table-header-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.table-header-title {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-modern {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.table-modern th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11.5px;
    letter-spacing: 0.5px;
    padding: 14px 20px;
    border-bottom: 1.5px solid #e2e8f0;
    text-align: center;
}
.table-modern td {
    padding: 15px 20px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    text-align: center;
    color: #1e293b;
}
.table-modern tbody tr:last-child td {
    border-bottom: none;
}
.table-modern tbody tr:hover {
    background: #f8fafc;
}

/* BADGES */
.badge-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.badge-masuk {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.badge-pulang {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.badge-jadwal {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.badge-jadwal-ok { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-jadwal-warn { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
.badge-jadwal-neutral { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

.badge-verif-custom {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    color: #334155;
    border: 1px solid #cbd5e1;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.btn-action-toggle {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #f1f5f9;
    color: #1e293b;
    border: 1px solid #cbd5e1;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-action-toggle:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
}

.btn-action-delete {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fca5a5;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-action-delete:hover {
    background: #fee2e2;
    border-color: #f87171;
}

/* TOAST ALERTS */
.toast-alert {
    padding: 14px 20px;
    border-radius: 14px;
    font-weight: 600;
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
}
.toast-alert-success { background: #f0fdf4; border: 1.5px solid #86efac; color: #166534; }
.toast-alert-error { background: #fef2f2; border: 1.5px solid #fca5a5; color: #991b1b; }
</style>

<div class="riwayat-container">

    <!-- TOAST NOTIFICATIONS -->
    <?php if (!empty($pesan_sukses)): ?>
        <div class="toast-alert toast-alert-success">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?php echo h($pesan_sukses); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div class="toast-alert toast-alert-error">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?php echo h($pesan_error); ?></span>
        </div>
    <?php endif; ?>

    <!-- PANEL SEARCH & FILTER CARD -->
    <div class="filter-card">
        <form method="GET" action="riwayat_karyawan.php" style="margin:0;">
            <div class="filter-grid">
                <!-- SEARCH / SELECT GURU & KARYAWAN -->
                <div class="filter-grid-emp">
                    <label for="input-search-emp" class="form-label-custom">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span>Pilih Pegawai:</span>
                    </label>
                    <div class="search-input-wrap">
                        <input type="hidden" name="pin" id="selected-pin" value="<?php echo h($pin_selected); ?>">
                        <input type="text" id="input-search-emp" class="searchable-input-custom" 
                               value="<?php 
                               if ($detail_user) {
                                   echo "[" . h($detail_user['pin']) . "] " . h($detail_user['nama']) . ($detail_user['departemen'] ? " — " . h($detail_user['departemen']) : "");
                               }
                               ?>" 
                               placeholder="Ketik PIN, Nama, atau Departemen..." autocomplete="off">
                        
                        <div class="searchable-dropdown-custom" id="dropdown-emp-list">
                            <?php foreach ($karyawan_list as $k): 
                                $label_k = "[" . h($k['pin']) . "] " . h($k['nama']) . ($k['departemen'] ? " — " . h($k['departemen']) : "");
                            ?>
                                <div class="searchable-item-custom" 
                                     data-pin="<?php echo h($k['pin']); ?>" 
                                     data-text="<?php echo h(strtolower($k['pin'] . ' ' . $k['nama'] . ' ' . $k['departemen'])); ?>"
                                     onclick="selectEmployee('<?php echo h($k['pin']); ?>', '<?php echo h($label_k); ?>')">
                                    <div style="font-weight:700; color:#0f172a;">[<?php echo h($k['pin']); ?>] <?php echo h($k['nama']); ?></div>
                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px; display:flex; gap:8px;">
                                        <span>Dept: <?php echo h($k['departemen'] ?: '-'); ?></span>
                                        <span>•</span>
                                        <span style="text-transform:capitalize;"><?php echo h($k['tipe']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- FILTER DATE RANGE -->
                <div>
                    <label for="tgl_mulai" class="form-label-custom">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span>Dari Tanggal:</span>
                    </label>
                    <input type="date" id="tgl_mulai" name="tgl_mulai" class="input-date-custom" value="<?php echo h($tgl_mulai); ?>">
                </div>

                <div>
                    <label for="tgl_selesai" class="form-label-custom">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span>Sampai Tanggal:</span>
                    </label>
                    <input type="date" id="tgl_selesai" name="tgl_selesai" class="input-date-custom" value="<?php echo h($tgl_selesai); ?>">
                </div>

                <!-- SORT ORDER -->
                <div>
                    <label for="sort" class="form-label-custom">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="4"/><polyline points="6 10 12 4 18 10"/></svg>
                        <span>Urutan Waktu:</span>
                    </label>
                    <select name="sort" id="sort" class="select-custom">
                        <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Terbaru Dulu (Descending)</option>
                        <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Terlama Dulu (Ascending)</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions-bar">
                <div class="filter-hint">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span>Ketik nama atau PIN untuk mencari data riwayat absensi pegawai.</span>
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="btn-primary-custom">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span>Tampilkan Riwayat</span>
                    </button>
                    <?php if ($detail_user): ?>
                        <?php if (can_access_page('export_pdf')): ?>
                        <a href="<?php echo 'export_pdf_riwayat.php?' . http_build_query(['pin' => $pin_selected, 'tgl_dari' => $tgl_mulai, 'tgl_sampai' => $tgl_selesai, 'auto_print' => 1]); ?>" target="_blank" class="btn-pdf-custom">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            <span>Export PDF Official</span>
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo 'riwayat_karyawan.php?' . http_build_query(['pin' => $pin_selected, 'tgl_mulai' => $tgl_mulai, 'tgl_selesai' => $tgl_selesai, 'sort' => $sort_order, 'export' => 1]); ?>" class="btn-excel-custom">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8"/><path d="M8 17h8"/></svg>
                            <span>Export Excel</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php if ($detail_user): ?>

    <?php
    $detail_profil = $detail_user;
    $usia_str = '';
    if (!empty($detail_profil['tanggal_lahir'])) {
        $dob = new DateTime($detail_profil['tanggal_lahir']);
        $usia_str = $dob->diff(new DateTime())->y . ' Tahun';
    }
    ?>

    <!-- HERO HEADER CARD -->
    <div class="hero-card">
        <div class="hero-main">
            <div class="hero-profile-info">
                <div class="hero-avatar-wrap">
                    <?php if (!empty($detail_profil['foto']) && file_exists(__DIR__ . '/' . $detail_profil['foto'])): ?>
                        <img src="<?php echo h($detail_profil['foto']); ?>" alt="Foto" class="hero-avatar-img">
                    <?php else: ?>
                        <div class="hero-avatar-initials">
                            <?php echo strtoupper(mb_substr($detail_profil['nama'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="hero-status-ping" title="Status Akun Aktif"></div>
                </div>

                <div>
                    <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:#93c5fd; display:flex; align-items:center; gap:6px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                        <?php echo $detail_user['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
                    </div>
                    <h2 class="hero-name"><?php echo h($detail_profil['nama']); ?></h2>
                    <div class="hero-tags">
                        <span class="hero-tag hero-tag-dept">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            <?php echo h($detail_profil['departemen'] ?: 'Umum'); ?>
                        </span>
                        <span class="hero-tag hero-tag-pin">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            PIN: <?php echo h($pin_selected); ?>
                        </span>
                        <?php if ($usia_str): ?>
                        <span class="hero-tag hero-tag-age">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?php echo $usia_str; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Counter Pills -->
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:10px 18px; text-align:center; min-width:90px;">
                    <div style="font-size:20px; font-weight:800; color:#ffffff;"><?php echo count($logs); ?></div>
                    <div style="font-size:10.5px; color:#94a3b8; font-weight:600;">Total Record</div>
                </div>
                <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:10px 18px; text-align:center; min-width:90px;">
                    <div style="font-size:20px; font-weight:800; color:#4ade80;"><?php echo $total_masuk; ?></div>
                    <div style="font-size:10.5px; color:#94a3b8; font-weight:600;">Absen Masuk</div>
                </div>
                <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:10px 18px; text-align:center; min-width:90px;">
                    <div style="font-size:20px; font-weight:800; color:#f87171;"><?php echo $total_pulang; ?></div>
                    <div style="font-size:10.5px; color:#94a3b8; font-weight:600;">Absen Pulang</div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB NAVIGATION BAR -->
    <?php $tab_url_base = 'riwayat_karyawan.php?' . http_build_query(['pin' => $pin_selected, 'tgl_mulai' => $tgl_mulai, 'tgl_selesai' => $tgl_selesai, 'sort' => $sort_order]); ?>
    <div class="tab-bar-custom">
        <a href="<?php echo $tab_url_base . '&tab=riwayat'; ?>" class="tab-link-custom <?php echo $active_tab !== 'profil' ? 'active' : 'inactive'; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span>Riwayat Absensi</span>
        </a>
        <a href="<?php echo $tab_url_base . '&tab=profil'; ?>" class="tab-link-custom <?php echo $active_tab === 'profil' ? 'active' : 'inactive'; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Profil Karyawan</span>
        </a>
    </div>

    <?php if ($active_tab === 'profil'): ?>
        <!-- TAB PROFIL (2 COLUMNS) -->
        <div style="display:grid; grid-template-columns: 360px 1fr; gap:24px; align-items:start;">
            <!-- LEFT READONLY INFO CARD -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:18px; overflow:hidden; box-shadow:0 4px 20px -2px rgba(15,23,42,0.04);">
                <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:18px 24px; font-size:14.5px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Informasi Pegawai</span>
                </div>
                <?php
                $rows_profil = [
                    'PIN Pegawai' => '<span style="background:#f1f5f9; border:1px solid #e2e8f0; padding:2px 8px; border-radius:6px; font-family:monospace; font-weight:700; color:#0f172a;">' . h($detail_profil['pin']) . '</span>',
                    'Nama Lengkap' => h($detail_profil['nama']),
                    'Departemen'   => h($detail_profil['departemen'] ?: '-'),
                    'Jabatan'      => $detail_profil['tipe'] === 'guru' ? '<span style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">Guru / Pendidik</span>' : '<span style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">Tenaga Kependidikan</span>',
                    'No. Telepon'  => h($detail_profil['no_hp'] ?: '-'),
                    'TTL'          => h((!empty($detail_profil['tempat_lahir']) ? $detail_profil['tempat_lahir'] . ', ' : '') . (!empty($detail_profil['tanggal_lahir']) ? date('d F Y', strtotime($detail_profil['tanggal_lahir'])) : (!empty($detail_profil['tempat_lahir']) ? '' : '-'))),
                    'Alamat'       => h($detail_profil['alamat'] ?: '-'),
                ];
                foreach ($rows_profil as $lbl => $val):
                ?>
                <div style="display:flex; padding:15px 24px; border-bottom:1px solid #f1f5f9; font-size:13.5px; gap:12px;">
                    <span style="width:120px; flex-shrink:0; color:#64748b; font-weight:600;"><?php echo $lbl; ?></span>
                    <span style="color:#0f172a; font-weight:600; flex:1; line-height:1.5; word-break:break-word;"><?php echo $val; ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- RIGHT EDIT FORM CARD -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:18px; overflow:hidden; box-shadow:0 4px 20px -2px rgba(15,23,42,0.04);">
                <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:18px 24px; font-size:14.5px; font-weight:700; color:#0f172a; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <span>Perbarui Data Diri Pegawai</span>
                    </div>
                </div>

                <form method="POST" action="user_profile.php?pin=<?php echo urlencode($pin_selected); ?>&tab_redirect=riwayat_karyawan" enctype="multipart/form-data" style="padding:24px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profil_mandiri">
                    <input type="hidden" name="target_pin" value="<?php echo h($pin_selected); ?>">

                    <div style="display:flex; align-items:center; gap:20px; padding:18px; background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:14px; margin-bottom:24px;">
                        <div id="avatarPreviewContainer" style="width:72px; height:72px; border-radius:50%; overflow:hidden; border:2px solid #ffffff; background:#e2e8f0; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:800; color:#64748b; box-shadow:0 4px 12px rgba(15,23,42,0.1);">
                            <?php if (!empty($detail_profil['foto']) && file_exists(__DIR__ . '/' . $detail_profil['foto'])): ?>
                                <img src="<?php echo h($detail_profil['foto']); ?>" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <?php echo strtoupper(mb_substr($detail_profil['nama'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">Foto Profil</label>
                            <input type="file" name="foto_profil" accept="image/jpeg,image/png,image/webp" onchange="previewSelectedImage(this)" style="width:100%; font-size:13px; color:#475569;">
                            <div style="font-size:11.5px; color:#64748b; margin-top:6px;">Format JPG, PNG, atau WEBP (Maksimal 2MB).</div>
                        </div>
                    </div>

                    <div style="height:1px; background:#f1f5f9; margin-bottom:20px;"></div>

                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:18px; margin-bottom:18px;">
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <label style="font-size:12.5px; font-weight:700; color:#334155;">No. Telepon / WhatsApp</label>
                            <input type="text" name="no_hp" value="<?php echo h($detail_profil['no_hp'] ?? ''); ?>" placeholder="08xxxxxxxxxx" class="input-date-custom">
                        </div>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <label style="font-size:12.5px; font-weight:700; color:#334155;">Tempat Lahir</label>
                            <input type="text" name="tempat_lahir" value="<?php echo h($detail_profil['tempat_lahir'] ?? ''); ?>" placeholder="Kota kelahiran" class="input-date-custom">
                        </div>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <label style="font-size:12.5px; font-weight:700; color:#334155;">Tanggal Lahir</label>
                            <input type="date" name="tanggal_lahir" value="<?php echo h($detail_profil['tanggal_lahir'] ?? ''); ?>" class="input-date-custom">
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:24px;">
                        <label style="font-size:12.5px; font-weight:700; color:#334155;">Alamat Lengkap</label>
                        <textarea name="alamat" rows="3" class="input-date-custom" style="resize:vertical; line-height:1.6;" placeholder="Jl. ... No. ..."><?php echo h($detail_profil['alamat'] ?? ''); ?></textarea>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="reset" style="padding:10px 18px; border:1px solid #cbd5e1; border-radius:10px; background:#f1f5f9; color:#475569; font-weight:600; font-size:13.5px; cursor:pointer;">Reset</button>
                        <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- TAB RIWAYAT: 4 SUMMARY CARDS -->
        <div class="stats-summary-grid">
            <div class="summary-card">
                <div class="summary-card-header">
                    <span class="summary-title">Total Log Absen</span>
                    <div class="summary-icon-box" style="background:#eff6ff; color:#2563eb;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                </div>
                <div class="summary-val"><?php echo count($logs); ?> <span style="font-size:13px; font-weight:600; color:#64748b;">Record</span></div>
                <div class="summary-sub" style="display:flex; gap:12px; margin-top:6px;">
                    <span style="color:#166534; font-weight:700;">Masuk: <?php echo $total_masuk; ?></span>
                    <span style="color:#991b1b; font-weight:700;">Pulang: <?php echo $total_pulang; ?></span>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-card-header">
                    <span class="summary-title">Absen Pertama</span>
                    <div class="summary-icon-box" style="background:#f0fdf4; color:#166534;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>
                <div class="summary-val" style="font-size:15px; font-weight:700; color:#2563eb; font-family:monospace;"><?php echo $absen_pertama; ?></div>
                <div class="summary-sub">Awal rekaman di sistem</div>
            </div>

            <div class="summary-card">
                <div class="summary-card-header">
                    <span class="summary-title">Absen Terakhir</span>
                    <div class="summary-icon-box" style="background:#ecfdf5; color:#059669;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                </div>
                <div class="summary-val" style="font-size:15px; font-weight:700; color:#059669; font-family:monospace;"><?php echo $absen_terakhir; ?></div>
                <div class="summary-sub">Rekaman presensi terkini</div>
            </div>

            <div class="summary-card">
                <div class="summary-card-header">
                    <span class="summary-title">Skema Jadwal</span>
                    <div class="summary-icon-box" style="background:#fff7ed; color:#c2410c;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                </div>
                <div class="summary-val" style="font-size:14px; font-weight:700;">
                    <?php
                    if ($detail_user['tipe'] === 'guru') {
                        echo empty($hari_ngajar_arr) ? "<span style='color:#c2410c;'>Belum Ada Jadwal</span>" : "<span style='color:#1d4ed8;'>" . count($hari_ngajar_arr) . " Hari Ngajar / Minggu</span>";
                    } else {
                        echo "<span style='color:#334155;'>Hari Kerja Kalender</span>";
                    }
                    ?>
                </div>
                <div class="summary-sub">Status acuan presensi</div>
            </div>
        </div>

        <!-- RIWAYAT ABSENSI DATA TABLE -->
        <div class="table-card">
            <div class="table-header-bar">
                <div class="table-header-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                    <span>Daftar Riwayat Log Absensi (<?php echo count($logs); ?> Record)</span>
                </div>
                <div style="font-size:12.5px; color:#64748b; font-weight:500;">
                    Diurutkan: <b><?php echo $sort_order === 'desc' ? 'Waktu Terbaru ➔ Terlama' : 'Waktu Terlama ➔ Terbaru'; ?></b>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th style="width:60px;">No</th>
                            <th style="text-align:left;">Tanggal & Hari</th>
                            <th>Waktu Absen</th>
                            <th>Status Absensi</th>
                            <th>Keterangan Jadwal</th>
                            <th>Tipe Verifikasi</th>
                            <?php if (is_superadmin()): ?>
                            <th>Aksi Admin</th>
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
                                $tgl_fmt = "<strong style='color:#0f172a;'>{$h_nama}</strong>, " . date('d/m/Y', strtotime($l['waktu']));
                                $jam_fmt = date('H:i:s', strtotime($l['waktu']));

                                $st_badge = ($l['status'] == '0')
                                    ? "<span class='badge-status badge-masuk'><span style='width:7px; height:7px; border-radius:50%; background:#166534;'></span> Masuk</span>"
                                    : (($l['status'] == '1')
                                        ? "<span class='badge-status badge-pulang'><span style='width:7px; height:7px; border-radius:50%; background:#991b1b;'></span> Pulang</span>"
                                        : "<span class='badge-status' style='background:#f1f5f9; color:#475569;'>Lainnya</span>");

                                $ket_badge = "";
                                if ($detail_user['tipe'] === 'guru') {
                                    if (empty($hari_ngajar_arr)) {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-warn'>Belum Ada Jadwal</span>";
                                    } elseif (in_array($hn, $hari_ngajar_arr)) {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-ok'>Sesuai Jadwal Ngajar</span>";
                                    } else {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-warn'>Di Luar Jadwal</span>";
                                    }
                                } else {
                                    if ($hn == 7) {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-warn'>Di Luar Hari Kerja (Minggu)</span>";
                                    } else {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-neutral'>Hari Kerja Kalender</span>";
                                    }
                                }

                                $verif_text = "Lainnya";
                                if ($l['tipe_verifikasi'] === 'SELFIE' || !empty($l['foto_selfie'])) {
                                    $verif_text = "Selfie Web";
                                } elseif ($l['tipe_verifikasi'] == '1') {
                                    $verif_text = "Sidik Jari";
                                } elseif ($l['tipe_verifikasi'] == '15') {
                                    $verif_text = "Wajah";
                                } elseif ($l['tipe_verifikasi'] == '0' || $l['tipe_verifikasi'] == '99') {
                                    $verif_text = "Manual Admin";
                                } else {
                                    $verif_text = "PIN / Password";
                                }

                                $verif_badge = "<span class='badge-verif-custom'>{$verif_text}</span>";

                                if (!empty($l['foto_selfie']) && file_exists(__DIR__ . '/' . $l['foto_selfie'])) {
                                    $selfie_url = h($l['foto_selfie']);
                                    $gps_link   = (!empty($l['latitude']) && !empty($l['longitude'])) 
                                        ? "https://maps.google.com/?q={$l['latitude']},{$l['longitude']}" 
                                        : "#";

                                    $verif_badge .= "<div style='margin-top:4px;'>
                                        <a href='{$selfie_url}' target='_blank' style='display:inline-block;' title='Lihat Foto Selfie'>
                                            <img src='{$selfie_url}' style='width:36px; height:36px; border-radius:6px; object-fit:cover; border:1.5px solid #bfdbfe; box-shadow:0 2px 6px rgba(0,0,0,0.1);'>
                                        </a>";
                                    if (!empty($l['latitude'])) {
                                        $verif_badge .= "<a href='{$gps_link}' target='_blank' style='font-size:10px; color:#2563eb; display:block; font-weight:700; margin-top:2px; text-decoration:none;' title='Buka Lokasi Google Maps'>Lokasi GPS (Maps)</a>";
                                    }
                                    $verif_badge .= "</div>";
                                }

                                $td_aksi = "";
                                if (is_superadmin()) {
                                    $target_status_label = ($l['status'] === '0') ? "Pulang" : "Masuk";
                                    $action_url = "riwayat_karyawan.php?pin=" . urlencode($pin_selected) . "&tgl_mulai=" . urlencode($tgl_mulai) . "&tgl_selesai=" . urlencode($tgl_selesai) . "&sort=" . urlencode($sort_order);
                                    $td_aksi = "<td>
                                                    <div style='display:flex; gap:6px; justify-content:center;'>
                                                        <form method='POST' action='" . h($action_url) . "' style='margin:0;'>
                                                            " . csrf_field() . "
                                                            <input type='hidden' name='action' value='tukar_status_log'>
                                                            <input type='hidden' name='id_log_toggle' value='{$l['id']}'>
                                                            <input type='hidden' name='pin_selected' value='{$pin_selected}'>
                                                            <button type='submit' class='btn-action-toggle' title='Tukar status ke {$target_status_label}'>
                                                                <svg width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M21.5 2v6h-6'/><path d='M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67'/></svg>
                                                                Tukar Status
                                                            </button>
                                                        </form>
                                                        <form method='POST' action='" . h($action_url) . "' style='margin:0;' onsubmit=\"return confirm('Yakin ingin menghapus data log absen ini?')\">
                                                            " . csrf_field() . "
                                                            <input type='hidden' name='action' value='hapus_log_absen'>
                                                            <input type='hidden' name='id_log_hapus' value='{$l['id']}'>
                                                            <input type='hidden' name='pin_selected' value='{$pin_selected}'>
                                                            <button type='submit' class='btn-action-delete'>
                                                                <svg width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><polyline points='3 6 5 6 21 6'/><path d='M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'/></svg>
                                                                Hapus
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>";
                                }

                                echo "<tr>
                                        <td><b>{$no}</b></td>
                                        <td style='text-align:left;'>{$tgl_fmt}</td>
                                        <td><b style='color:#0f172a; font-size:14.5px; font-family:monospace;'>{$jam_fmt}</b></td>
                                        <td>{$st_badge}</td>
                                        <td>{$ket_badge}</td>
                                        <td>{$verif_badge}</td>
                                        {$td_aksi}
                                      </tr>";
                                $no++;
                            }
                        } else {
                            $colspan = is_superadmin() ? 7 : 6;
                            echo "<tr><td colspan='{$colspan}' style='padding:45px 20px; color:#94a3b8; font-size:14px;'>Belum ada data riwayat absensi untuk kriteria pencarian ini.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; // end tab riwayat ?>
    <?php endif; // end active_tab check ?>

</div>

<script>
const inputEmp = document.getElementById('input-search-emp');
const dropdownEmp = document.getElementById('dropdown-emp-list');
const selectedPin = document.getElementById('selected-pin');
const itemsEmp = document.querySelectorAll('.searchable-item-custom');

if (inputEmp && dropdownEmp) {
    inputEmp.addEventListener('focus', () => { dropdownEmp.style.display = 'block'; });

    inputEmp.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        dropdownEmp.style.display = 'block';
        
        itemsEmp.forEach(item => {
            const text = item.getAttribute('data-text');
            if (text.includes(q)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#input-search-emp') && !e.target.closest('#dropdown-emp-list')) {
            dropdownEmp.style.display = 'none';
        }
    });
}

function selectEmployee(pin, label) {
    selectedPin.value = pin;
    inputEmp.value = label;
    dropdownEmp.style.display = 'none';
}

function previewSelectedImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const container = document.getElementById('avatarPreviewContainer');
            if (container) {
                container.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover;">`;
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php render_footer(); ?>
