<?php
// ============================================================
// HALAMAN REKAP RIWAYAT ABSENSI INDIVIDUAL GURU & KARYAWAN
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// Redesain Modern, Aesthetic, Sleek UI + Navigasi Rute Rumah
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
$tgl_mulai    = trim($_GET['tgl_mulai'] ?? date('Y-m-01'));
$tgl_selesai  = trim($_GET['tgl_selesai'] ?? date('Y-m-d'));
$sort_order   = $_GET['sort'] ?? 'desc';
$export       = isset($_GET['export']) && $_GET['export'] === '1';
$active_tab   = $_GET['tab'] ?? 'riwayat'; // 'riwayat' atau 'profil'

// Ambil semua daftar karyawan untuk dropdown pencarian
$sql_all = "SELECT pin, nama, departemen, tipe, foto, latitude_rumah, longitude_rumah, no_hp FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC";
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
    $stmt_j = $conn->prepare("SELECT hari FROM jadwal_guru WHERE pin = ? ORDER BY hari ASC");
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
$total_hari   = 0;
$absen_pertama = "-";
$absen_terakhir = "-";

if (!empty($pin_selected)) {
    $where = ["la.pin = ?"];
    $params = [$pin_selected];
    $types = "s";

    if (!empty($tgl_mulai)) {
        $where[] = "DATE(la.waktu) >= ?";
        $params[] = $tgl_mulai;
        $types .= "s";
    }

    if (!empty($tgl_selesai)) {
        $where[] = "DATE(la.waktu) <= ?";
        $params[] = $tgl_selesai;
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

    $dates_seen = [];
    if ($res_l && $res_l->num_rows > 0) {
        while ($l = $res_l->fetch_assoc()) {
            if ($l['status'] == '0') $total_masuk++;
            if ($l['status'] == '1') $total_pulang++;
            $dates_seen[$l['tgl_only']] = true;
            $logs[] = $l;
        }
        $total_hari = count($dates_seen);

        // Cari waktu pertama & terakhir absen di database
        $stmt_stat = $conn->prepare("SELECT MIN(waktu) AS tgl_min, MAX(waktu) AS tgl_max FROM log_absen WHERE pin = ?");
        $stmt_stat->bind_param("s", $pin_selected);
        $stmt_stat->execute();
        $res_stat = $stmt_stat->get_result()->fetch_assoc();

        if ($res_stat) {
            $absen_pertama  = !empty($res_stat['tgl_min']) ? date('d/m/Y H:i', strtotime($res_stat['tgl_min'])) : '-';
            $absen_terakhir = !empty($res_stat['tgl_max']) ? date('d/m/Y H:i', strtotime($res_stat['tgl_max'])) : '-';
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

$nama_hari_map = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu'];

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
            <b>Hari Aktif:</b> <?php echo $total_hari; ?> Hari |
            <b>Periode:</b> <?php echo $tgl_mulai; ?> s/d <?php echo $tgl_selesai; ?>
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

                    $verif = "Sidik Jari";
                    if ($l['tipe_verifikasi'] === 'SELFIE' || !empty($l['foto_selfie'])) $verif = "Selfie Web AI";
                    elseif ($l['tipe_verifikasi'] == '15') $verif = "Scan Wajah";
                    elseif ($l['tipe_verifikasi'] == '99' || $l['tipe_verifikasi'] == '0') $verif = "Manual Admin";

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

$has_home_coords = (!empty($detail_user['latitude_rumah']) && !empty($detail_user['longitude_rumah']));
$default_map_lat = $has_home_coords ? (float)$detail_user['latitude_rumah'] : -6.90652863;
$default_map_lng = $has_home_coords ? (float)$detail_user['longitude_rumah'] : 107.57195250;

render_header("Riwayat Absensi Individual", "riwayat");
?>

<!-- LEAFLET MAPS CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
/* ===== MODERN RIWAYAT KARYAWAN THEME ===== */
.riwayat-container {
    display: flex;
    flex-direction: column;
    gap: 22px;
    max-width: 1120px;
    margin: 0 auto 40px auto;
    width: 100%;
}

/* FILTER CARD */
.filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 22px 26px;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.04);
}

.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.2fr;
    gap: 16px;
    margin-bottom: 18px;
}

@media (max-width: 960px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}
@media (max-width: 600px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
}

.form-label-custom {
    font-size: 12px;
    font-weight: 800;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.3px;
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
    padding: 10.5px 14px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    font-weight: 700;
    transition: all 0.2s ease;
    outline: none;
    box-sizing: border-box;
}

.searchable-input-custom:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3.5px rgba(37, 99, 235, 0.12);
}

.searchable-dropdown-custom {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    max-height: 280px;
    overflow-y: auto;
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.15);
    z-index: 99;
    display: none;
}

.searchable-item-custom {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
    transition: background 0.15s ease;
}

.searchable-item-custom:hover {
    background: #eff6ff;
}

.input-date-custom, .select-custom {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13px;
    color: #0f172a;
    background: #ffffff;
    font-weight: 600;
    transition: all 0.2s ease;
    outline: none;
    box-sizing: border-box;
}

.input-date-custom:focus, .select-custom:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.filter-actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
}

/* BUTTONS */
.btn-primary-custom {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    font-weight: 800;
    font-size: 13px;
    padding: 9px 18px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(37, 99, 235, 0.25);
    transition: all 0.2s ease;
}
.btn-primary-custom:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
}

.btn-excel-custom {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff !important;
    font-weight: 800;
    font-size: 13px;
    padding: 9px 16px;
    border-radius: 10px;
    text-decoration: none;
    box-shadow: 0 3px 10px rgba(16, 185, 129, 0.25);
    transition: all 0.2s ease;
}
.btn-excel-custom:hover {
    transform: translateY(-1px);
}

.btn-pdf-custom {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #ffffff !important;
    font-weight: 800;
    font-size: 13px;
    padding: 9px 16px;
    border-radius: 10px;
    text-decoration: none;
    box-shadow: 0 3px 10px rgba(239, 68, 68, 0.25);
    transition: all 0.2s ease;
}
.btn-pdf-custom:hover {
    transform: translateY(-1px);
}

/* HERO BANNER CARD */
.hero-card {
    position: relative;
    background: linear-gradient(135deg, #0b132b 0%, #1c2541 50%, #0f172a 100%);
    border-radius: 22px;
    padding: 28px 30px;
    color: #ffffff;
    overflow: hidden;
    box-shadow: 0 16px 40px -10px rgba(15, 23, 42, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.09);
}

.hero-card::before {
    content: '';
    position: absolute;
    top: -50px;
    right: -50px;
    width: 240px;
    height: 240px;
    background: radial-gradient(circle, rgba(56, 189, 248, 0.16) 0%, rgba(37, 99, 235, 0) 70%);
    border-radius: 50%;
    pointer-events: none;
}

.hero-main {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 22px;
    flex-wrap: wrap;
}

.hero-profile-info {
    display: flex;
    align-items: center;
    gap: 20px;
    flex: 1;
    min-width: 280px;
}

.hero-avatar-wrap {
    position: relative;
    width: 80px;
    height: 80px;
    flex-shrink: 0;
}

.hero-avatar-img, .hero-avatar-initials {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 3.5px solid rgba(255, 255, 255, 0.25);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    font-weight: 800;
    color: #ffffff;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
}

.hero-name {
    font-size: 22px;
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
    padding: 3.5px 11px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
}
.hero-tag-dept { background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.18); color: #f1f5f9; }
.hero-tag-pin { background: rgba(59, 130, 246, 0.25); border: 1px solid rgba(147, 197, 253, 0.35); color: #bfdbfe; font-family: monospace; }
.hero-tag-guru { background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(52, 211, 153, 0.35); color: #6ee7b7; }

/* ROUTE TO HOME BUTTON IN HERO */
.btn-hero-route {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #ffffff !important;
    text-decoration: none;
    padding: 10px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.15);
    transition: all 0.2s ease;
}

.btn-hero-route:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(5, 150, 105, 0.45);
}

.btn-hero-wa {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.2);
    text-decoration: none;
    padding: 10px 16px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.2s ease;
}

.btn-hero-wa:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px);
}

/* TAB NAVIGATION */
.tab-bar-custom {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0;
    margin-bottom: 10px;
}
.tab-link-custom {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 800;
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

/* 4 STAT SUMMARY CARDS */
.stats-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat-card-glass {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.04);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.stat-card-glass:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -4px rgba(15, 23, 42, 0.08);
}

.stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-metric-title {
    font-size: 11.5px;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}

.stat-metric-value {
    font-size: 24px;
    font-weight: 900;
    line-height: 1.1;
    color: #0f172a;
}

.stat-metric-sub {
    font-size: 11.5px;
    color: #94a3b8;
    font-weight: 600;
    margin-top: 2px;
}

/* DATA TABLE STYLES */
.table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 6px 25px -4px rgba(15, 23, 42, 0.06);
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
    font-size: 13px;
    min-width: 720px;
}

.table-modern thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 800;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.8px;
    padding: 13px 16px;
    border-bottom: 1.5px solid #e2e8f0;
    text-align: center;
}

.table-modern td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    text-align: center;
    color: #334155;
}

.table-modern tbody tr:hover {
    background: #f8fafc;
}

/* STATUS PILLS */
.status-pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.3px;
}
.status-pill-masuk { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
.status-pill-pulang { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }

.status-dot-inner {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}
.status-pill-masuk .status-dot-inner { background: #10b981; }
.status-pill-pulang .status-dot-inner { background: #f43f5e; }

.badge-jadwal {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11.5px;
    font-weight: 700;
}
.badge-jadwal-ok { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-jadwal-warn { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
.badge-jadwal-neutral { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

.method-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 4px 10px;
    border-radius: 8px;
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
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-action-toggle:hover { background: #e2e8f0; border-color: #94a3b8; }

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
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-action-delete:hover { background: #fee2e2; border-color: #f87171; }

.selfie-thumb-btn {
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.selfie-thumb-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

/* LIGHTBOX MODAL */
.photo-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(6px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.2s ease;
}

.photo-modal-card {
    background: #ffffff;
    border-radius: 20px;
    max-width: 420px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
    animation: scaleIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes scaleIn { from { transform: scale(0.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>

<div class="riwayat-container">

    <!-- TOAST NOTIFICATIONS -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:14px 18px; color:#15803d; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(22,163,74,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo h($pesan_sukses); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; border-radius:14px; padding:14px 18px; color:#991b1b; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(220,38,38,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo h($pesan_error); ?></div>
        </div>
    <?php endif; ?>

    <!-- PANEL SEARCH & FILTER CARD -->
    <div class="filter-card">
        <form method="GET" action="riwayat_karyawan.php" style="margin:0;">
            <div class="filter-grid">
                <!-- SEARCH / SELECT GURU & KARYAWAN -->
                <div>
                    <label for="input-search-emp" class="form-label-custom">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span>Pilih Guru / Karyawan:</span>
                    </label>
                    <div class="search-input-wrap">
                        <input type="hidden" name="pin" id="selected-pin" value="<?php echo h($pin_selected); ?>">
                        <input type="text" id="input-search-emp" class="searchable-input-custom" 
                               value="<?php 
                               if ($detail_user) {
                                   echo "[" . h($detail_user['pin']) . "] " . h($detail_user['nama']) . ($detail_user['departemen'] ? " — " . h($detail_user['departemen']) : "");
                               }
                               ?>" 
                               placeholder="Cari Nama / PIN / Dept..." autocomplete="off">
                        
                        <div class="searchable-dropdown-custom" id="dropdown-emp-list">
                            <?php foreach ($karyawan_list as $k): 
                                $label_k = "[" . h($k['pin']) . "] " . h($k['nama']) . ($k['departemen'] ? " — " . h($k['departemen']) : "");
                            ?>
                                <div class="searchable-item-custom" 
                                     data-pin="<?php echo h($k['pin']); ?>" 
                                     data-text="<?php echo h(strtolower($k['pin'] . ' ' . $k['nama'] . ' ' . $k['departemen'])); ?>"
                                     onclick="selectEmployee('<?php echo h($k['pin']); ?>', '<?php echo h($label_k); ?>')">
                                    <div style="font-weight:800; color:#0f172a;">[<?php echo h($k['pin']); ?>] <?php echo h($k['nama']); ?></div>
                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px; display:flex; gap:8px;">
                                        <span>Dept: <?php echo h($k['departemen'] ?: 'Umum'); ?></span>
                                        <span>&bull;</span>
                                        <span style="text-transform:capitalize;"><?php echo h($k['tipe']); ?></span>
                                        <?php if (!empty($k['latitude_rumah'])): ?>
                                            <span>&bull;</span>
                                            <span style="color:#059669; font-weight:700;">📍 Ada Lokasi Rumah</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- FILTER DATE RANGE -->
                <div>
                    <label for="tgl_mulai" class="form-label-custom">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span>Dari:</span>
                    </label>
                    <input type="date" id="tgl_mulai" name="tgl_mulai" class="input-date-custom" value="<?php echo h($tgl_mulai); ?>">
                </div>

                <div>
                    <label for="tgl_selesai" class="form-label-custom">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span>Sampai:</span>
                    </label>
                    <input type="date" id="tgl_selesai" name="tgl_selesai" class="input-date-custom" value="<?php echo h($tgl_selesai); ?>">
                </div>

                <!-- SORT ORDER -->
                <div>
                    <label for="sort" class="form-label-custom">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="4"/><polyline points="6 10 12 4 18 10"/></svg>
                        <span>Urutan:</span>
                    </label>
                    <select name="sort" id="sort" class="select-custom">
                        <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Terbaru Dulu (Desc)</option>
                        <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Terlama Dulu (Asc)</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions-bar">
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <span style="font-size:11.5px; font-weight:800; color:#64748b; text-transform:uppercase;">Preset:</span>
                    <button type="button" onclick="applyPreset('today')" style="background:#f8fafc; border:1px solid #e2e8f0; color:#475569; padding:5px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer;">Hari Ini</button>
                    <button type="button" onclick="applyPreset('week')" style="background:#f8fafc; border:1px solid #e2e8f0; color:#475569; padding:5px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer;">7 Hari</button>
                    <button type="button" onclick="applyPreset('month')" style="background:#f8fafc; border:1px solid #e2e8f0; color:#475569; padding:5px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer;">Bulan Ini</button>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn-primary-custom">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span>Filter Data</span>
                    </button>
                    <?php if ($detail_user): ?>
                        <?php if (can_access_page('export_pdf')): ?>
                        <a href="<?php echo 'export_pdf_riwayat.php?' . http_build_query(['pin' => $pin_selected, 'tgl_dari' => $tgl_mulai, 'tgl_sampai' => $tgl_selesai, 'auto_print' => 1]); ?>" target="_blank" class="btn-pdf-custom">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <span>PDF</span>
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo 'riwayat_karyawan.php?' . http_build_query(['pin' => $pin_selected, 'tgl_mulai' => $tgl_mulai, 'tgl_selesai' => $tgl_selesai, 'sort' => $sort_order, 'export' => 1]); ?>" class="btn-excel-custom">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                            <span>Excel</span>
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
                </div>

                <div>
                    <h2 class="hero-name"><?php echo h($detail_profil['nama']); ?></h2>
                    <div class="hero-tags">
                        <span class="hero-tag hero-tag-pin">PIN: <?php echo h($pin_selected); ?></span>
                        <span class="hero-tag <?php echo $detail_user['tipe'] === 'guru' ? 'hero-tag-guru' : 'hero-tag-dept'; ?>">
                            <?php echo $detail_user['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
                        </span>
                        <span class="hero-tag hero-tag-dept">
                            <?php echo h($detail_profil['departemen'] ?: 'Umum'); ?>
                        </span>
                        <?php if ($usia_str): ?>
                        <span class="hero-tag hero-tag-dept" style="color:#e9d5ff;">
                            <?php echo $usia_str; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ACTION BUTTONS IN HERO: DIRECT GMAPS NAVIGATION & WA -->
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <?php if ($has_home_coords && can_access_route_maps()): ?>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $detail_user['latitude_rumah'] . ',' . $detail_user['longitude_rumah']; ?>" target="_blank" class="btn-hero-route" title="Buka Rute Navigasi Google Maps untuk Menjenguk Pegawai">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                        <span>Rute ke Rumah (Google Maps)</span>
                    </a>
                <?php elseif (!$has_home_coords && can_access_route_maps()): ?>
                    <a href="user_profile.php?pin=<?php echo urlencode($pin_selected); ?>" target="_blank" class="btn-hero-wa" style="border-color:#fde68a; color:#fef08a;" title="Titik koordinat rumah belum diset">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span>Set Titik Rumah</span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($detail_profil['no_hp'])): ?>
                    <a href="https://wa.me/<?php echo preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $detail_profil['no_hp'])); ?>" target="_blank" class="btn-hero-wa" title="Hubungi via WhatsApp">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <span>Hubungi WA</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TAB NAVIGATION BAR -->
    <?php $tab_url_base = 'riwayat_karyawan.php?' . http_build_query(['pin' => $pin_selected, 'tgl_mulai' => $tgl_mulai, 'tgl_selesai' => $tgl_selesai, 'sort' => $sort_order]); ?>
    <div class="tab-bar-custom">
        <a href="<?php echo $tab_url_base . '&tab=riwayat'; ?>" class="tab-link-custom <?php echo $active_tab !== 'profil' ? 'active' : 'inactive'; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span>Riwayat Presensi</span>
        </a>
        <a href="<?php echo $tab_url_base . '&tab=profil'; ?>" class="tab-link-custom <?php echo $active_tab === 'profil' ? 'active' : 'inactive'; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span>Profil &amp; Lokasi Rumah</span>
        </a>
    </div>

    <?php if ($active_tab === 'profil'): ?>
        <!-- TAB PROFIL & LOKASI RUMAH (2 COLUMNS) -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:22px; align-items:start;">
            
            <!-- LEFT READONLY INFO & MAP CARD -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:20px; overflow:hidden; box-shadow:0 4px 20px -2px rgba(15,23,42,0.04);">
                <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:18px 24px; font-size:15px; font-weight:800; color:#0f172a; display:flex; align-items:center; justify-content:space-between;">
                    <div style="display:flex; align-items:center; gap:9px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Informasi Guru / Karyawan</span>
                    </div>
                    <span style="font-size:11px; font-weight:800; color:#15803d; background:#dcfce7; padding:3px 10px; border-radius:20px;">AKTIF</span>
                </div>
                
                <div style="padding:20px 24px;">
                    <?php
                    $rows_profil = [
                        'PIN' => '<span style="background:#f1f5f9; border:1px solid #e2e8f0; padding:2px 8px; border-radius:6px; font-family:monospace; font-weight:800; color:#0f172a;">' . h($detail_profil['pin']) . '</span>',
                        'Nama Lengkap' => h($detail_profil['nama']),
                        'Departemen'   => h($detail_profil['departemen'] ?: '-'),
                        'Jabatan'      => $detail_profil['tipe'] === 'guru' ? '<span style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">Guru / Pendidik</span>' : '<span style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">Tenaga Kependidikan</span>',
                        'No. Telepon'  => !empty($detail_profil['no_hp']) ? '<a href="https://wa.me/' . preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $detail_profil['no_hp'])) . '" target="_blank" style="color:#059669; font-weight:800; text-decoration:none;">' . h($detail_profil['no_hp']) . ' (WA)</a>' : '-',
                        'TTL & Usia'   => h((!empty($detail_profil['tempat_lahir']) ? $detail_profil['tempat_lahir'] . ', ' : '') . (!empty($detail_profil['tanggal_lahir']) ? date('d F Y', strtotime($detail_profil['tanggal_lahir'])) : (!empty($detail_profil['tempat_lahir']) ? '' : '-'))) . ($usia_str ? " ({$usia_str})" : ''),
                        'Alamat Rumah' => h($detail_profil['alamat'] ?: '-'),
                    ];
                    foreach ($rows_profil as $lbl => $val):
                    ?>
                    <div style="display:flex; justify-content:space-between; padding:11px 0; border-bottom:1px solid #f1f5f9; font-size:13px; gap:12px;">
                        <span style="color:#64748b; font-weight:700; flex-shrink:0;"><?php echo $lbl; ?></span>
                        <span style="color:#0f172a; font-weight:700; text-align:right; word-break:break-word;"><?php echo $val; ?></span>
                    </div>
                    <?php endforeach; ?>

                    <!-- PIN POINT LOKASI RUMAH & NAVIGASI KUNJUNGAN -->
                    <div style="margin-top:20px; padding:16px; background:<?php echo $has_home_coords ? '#f0fdf4' : '#fffbeb'; ?>; border:1px solid <?php echo $has_home_coords ? '#bbf7d0' : '#fde68a'; ?>; border-radius:14px;">
                        <div style="font-size:13px; font-weight:800; color:<?php echo $has_home_coords ? '#166534' : '#92400e'; ?>; display:flex; align-items:center; gap:6px; margin-bottom:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span>Titik Lokasi &amp; Navigasi Rumah</span>
                        </div>
                        <?php if ($has_home_coords): ?>
                            <div style="font-size:11.5px; font-family:monospace; font-weight:700; color:#15803d; margin-bottom:6px;">
                                Koordinat: <code><?php echo number_format($detail_profil['latitude_rumah'], 6) . ', ' . number_format($detail_profil['longitude_rumah'], 6); ?></code>
                            </div>
                            <?php if (!empty($detail_profil['catatan_alamat'])): ?>
                                <div style="font-size:12px; color:#334155; margin-bottom:12px; background:rgba(255,255,255,0.75); padding:6px 10px; border-radius:8px; border:1px solid #bbf7d0;">
                                    <strong>Patokan:</strong> <?php echo h($detail_profil['catatan_alamat']); ?>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                                <?php if (can_access_route_maps()): ?>
                                <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $detail_profil['latitude_rumah'] . ',' . $detail_profil['longitude_rumah']; ?>" target="_blank" style="background:linear-gradient(135deg, #059669, #047857); color:#fff; font-size:12px; font-weight:800; text-decoration:none; padding:8px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 3px 10px rgba(5,150,105,0.25);">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                                    <span>Buka Rute di Google Maps</span>
                                </a>
                                <?php endif; ?>
                                <a href="https://www.google.com/maps?q=<?php echo $detail_profil['latitude_rumah'] . ',' . $detail_profil['longitude_rumah']; ?>" target="_blank" style="background:#ffffff; color:#334155; border:1px solid #cbd5e1; font-size:12px; font-weight:700; text-decoration:none; padding:8px 12px; border-radius:8px; display:inline-flex; align-items:center; gap:5px;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4-7 4z"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                                    <span>Lihat Peta</span>
                                </a>
                            </div>
                        <?php else: ?>
                            <div style="font-size:12px; color:#92400e; line-height:1.5;">
                                Titik koordinat GPS rumah belum ditentukan. Gunakan form di sebelah kanan untuk menetapkan pin point lokasi rumah.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT EDIT FORM & MAP PICKER -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:20px; overflow:hidden; box-shadow:0 4px 20px -2px rgba(15,23,42,0.04);">
                <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:18px 24px; font-size:15px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:9px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <span>Perbarui Data &amp; Lokasi Rumah</span>
                </div>

                <form method="POST" action="user_profile.php?pin=<?php echo urlencode($pin_selected); ?>" enctype="multipart/form-data" style="padding:24px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profil_mandiri">
                    <input type="hidden" name="target_pin" value="<?php echo h($pin_selected); ?>">

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">No. HP / WhatsApp</label>
                            <input type="text" name="no_hp" value="<?php echo h($detail_profil['no_hp'] ?? ''); ?>" placeholder="08xxxxxxxxxx" class="input-date-custom" style="margin-top:4px;">
                        </div>
                        <div>
                            <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Tempat Lahir</label>
                            <input type="text" name="tempat_lahir" value="<?php echo h($detail_profil['tempat_lahir'] ?? ''); ?>" placeholder="Kota kelahiran" class="input-date-custom" style="margin-top:4px;">
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Tanggal Lahir</label>
                        <input type="date" name="tanggal_lahir" value="<?php echo h($detail_profil['tanggal_lahir'] ?? ''); ?>" class="input-date-custom" style="margin-top:4px;">
                    </div>

                    <div style="margin-bottom:18px;">
                        <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Alamat Domisili Lengkap</label>
                        <textarea name="alamat" rows="2" class="input-date-custom" style="margin-top:4px; resize:vertical; line-height:1.5;" placeholder="Jl. ... No. ..."><?php echo h($detail_profil['alamat'] ?? ''); ?></textarea>
                    </div>

                    <div style="height:1px; background:#e2e8f0; margin:16px 0;"></div>

                    <!-- PIN POINT MAP PICKER -->
                    <div style="margin-bottom:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:8px;">
                            <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">
                                <span>Peta Pin Point Lokasi Rumah</span>
                            </label>
                            <button type="button" onclick="getCurrentHomeGPS()" id="btnGetHomeGps" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:11px; font-weight:800; padding:4px 10px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg>
                                <span>GPS Saat Ini</span>
                            </button>
                        </div>

                        <!-- LEAFLET MAP CONTAINER -->
                        <div id="homeMapPickerAdmin" style="width:100%; height:200px; border-radius:12px; border:1.5px solid #cbd5e1; overflow:hidden; margin-bottom:10px;"></div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                            <div>
                                <label style="font-size:10.5px; font-weight:700; color:#64748b;">Latitude</label>
                                <input type="text" id="input_latitude_rumah" name="latitude_rumah" value="<?php echo !empty($detail_profil['latitude_rumah']) ? h($detail_profil['latitude_rumah']) : ''; ?>" placeholder="-6.xxxxxx" readonly class="input-date-custom" style="background:#f8fafc; font-family:monospace; font-size:12px;">
                            </div>
                            <div>
                                <label style="font-size:10.5px; font-weight:700; color:#64748b;">Longitude</label>
                                <input type="text" id="input_longitude_rumah" name="longitude_rumah" value="<?php echo !empty($detail_profil['longitude_rumah']) ? h($detail_profil['longitude_rumah']) : ''; ?>" placeholder="107.xxxxxx" readonly class="input-date-custom" style="background:#f8fafc; font-family:monospace; font-size:12px;">
                            </div>
                        </div>

                        <div>
                            <label style="font-size:10.5px; font-weight:700; color:#64748b;">Patokan / Petunjuk Rumah</label>
                            <input type="text" name="catatan_alamat" value="<?php echo h($detail_profil['catatan_alamat'] ?? ''); ?>" placeholder="Contoh: Pagar hijau no. 12 depan masjid" class="input-date-custom">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f1f5f9; padding-top:16px;">
                        <button type="submit" class="btn-primary-custom">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <span>Simpan Perubahan</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- TAB RIWAYAT: 4 SUMMARY CARDS -->
        <div class="stats-cards-grid">
            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#eff6ff; color:#2563eb;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Total Log Presensi</div>
                    <div class="stat-metric-value"><?php echo count($logs); ?> <span style="font-size:12px; font-weight:700; color:#64748b;">Record</span></div>
                    <div class="stat-metric-sub">Periode Terpilih</div>
                </div>
            </div>

            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#f0fdf4; color:#16a34a;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Hari Hadir Aktif</div>
                    <div class="stat-metric-value" style="color:#16a34a;"><?php echo $total_hari; ?> <span style="font-size:12px; font-weight:700; color:#64748b;">Hari</span></div>
                    <div class="stat-metric-sub">Kehadiran Fisik/Web</div>
                </div>
            </div>

            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#ecfdf5; color:#059669;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Absen Masuk</div>
                    <div class="stat-metric-value" style="color:#059669;"><?php echo $total_masuk; ?> <span style="font-size:12px; font-weight:700; color:#64748b;">Kali</span></div>
                    <div class="stat-metric-sub">Awal: <?php echo $absen_pertama; ?></div>
                </div>
            </div>

            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#fff1f2; color:#e11d48;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Absen Pulang</div>
                    <div class="stat-metric-value" style="color:#e11d48;"><?php echo $total_pulang; ?> <span style="font-size:12px; font-weight:700; color:#64748b;">Kali</span></div>
                    <div class="stat-metric-sub">Akhir: <?php echo $absen_terakhir; ?></div>
                </div>
            </div>
        </div>

        <!-- RIWAYAT ABSENSI DATA TABLE -->
        <div class="table-card">
            <div class="table-header-bar">
                <div class="table-header-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Rekaman Presensi Guru / Karyawan</span>
                </div>
                <div style="font-size:12px; font-weight:700; color:#64748b;">
                    Total: <b style="color:#0f172a;"><?php echo count($logs); ?></b> Data &bull; <?php echo $sort_order === 'desc' ? 'Waktu Terbaru ➔ Terlama' : 'Waktu Terlama ➔ Terbaru'; ?>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th style="width:50px;">NO</th>
                            <th style="text-align:left;">HARI &amp; TANGGAL</th>
                            <th>JAM PRESENSI</th>
                            <th>STATUS</th>
                            <th>KETERANGAN JADWAL</th>
                            <th style="text-align:left;">METODE VERIFIKASI</th>
                            <th>FOTO BUKTI</th>
                            <?php if (is_superadmin()): ?>
                            <th>AKSI ADMIN</th>
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
                                $tgl_f = date('d F Y', strtotime($l['waktu']));
                                $jam_f = date('H:i:s', strtotime($l['waktu']));
                                $is_masuk = ($l['status'] == '0');

                                $st_badge = $is_masuk
                                    ? "<span class='status-pill-badge status-pill-masuk'><span class='status-dot-inner'></span> MASUK</span>"
                                    : "<span class='status-pill-badge status-pill-pulang'><span class='status-dot-inner'></span> PULANG</span>";

                                $ket_badge = "";
                                if ($detail_user['tipe'] === 'guru') {
                                    if (empty($hari_ngajar_arr)) {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-warn'>Belum Ada Jadwal</span>";
                                    } elseif (in_array($hn, $hari_ngajar_arr)) {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-ok'>Sesuai Jadwal</span>";
                                    } else {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-warn'>Di Luar Jadwal</span>";
                                    }
                                } else {
                                    if ($hn == 7) {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-warn'>Minggu (Libur)</span>";
                                    } else {
                                        $ket_badge = "<span class='badge-jadwal badge-jadwal-neutral'>Hari Kerja</span>";
                                    }
                                }

                                $ver_text = "Sidik Jari";
                                $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M12 1a8 8 0 0 0-8 8v4a8 8 0 0 0 16 0V9a8 8 0 0 0-8-8z"/><path d="M9 9a3 3 0 0 1 6 0v4a3 3 0 0 1-6 0V9z"/></svg>';

                                if ($l['tipe_verifikasi'] === 'SELFIE' || !empty($l['foto_selfie'])) {
                                    $ver_text = "Selfie AI (Web)";
                                    $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>';
                                } elseif ($l['tipe_verifikasi'] == '15') {
                                    $ver_text = "Scan Wajah";
                                    $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>';
                                } elseif ($l['tipe_verifikasi'] == '0' || $l['tipe_verifikasi'] == '99') {
                                    $ver_text = "Manual Admin";
                                    $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
                                }

                                $td_foto = '<span style="color:#cbd5e1;">-</span>';
                                if (!empty($l['foto_selfie']) && file_exists(__DIR__ . '/' . $l['foto_selfie'])) {
                                    $selfie_src = h($l['foto_selfie']);
                                    $td_foto = "<img src='{$selfie_src}' class='selfie-thumb-btn' onclick=\"openPhotoLightbox('{$selfie_src}', '{$h_nama}, {$tgl_f} ({$jam_f} WIB)', '" . ($is_masuk ? 'Absen Masuk' : 'Absen Pulang') . "')\" style='width:36px; height:36px; border-radius:8px; object-fit:cover; border:2px solid #bfdbfe;'>";
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
                                                                <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M21.5 2v6h-6'/><path d='M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67'/></svg>
                                                                Tukar
                                                            </button>
                                                        </form>
                                                        <form method='POST' action='" . h($action_url) . "' style='margin:0;' onsubmit=\"return confirm('Yakin ingin menghapus data log absen ini?')\">
                                                            " . csrf_field() . "
                                                            <input type='hidden' name='action' value='hapus_log_absen'>
                                                            <input type='hidden' name='id_log_hapus' value='{$l['id']}'>
                                                            <input type='hidden' name='pin_selected' value='{$pin_selected}'>
                                                            <button type='submit' class='btn-action-delete' title='Hapus Record'>
                                                                <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><polyline points='3 6 5 6 21 6'/><path d='M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'/></svg>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>";
                                }

                                echo "<tr>
                                        <td><b>{$no}</b></td>
                                        <td style='text-align:left;'>
                                            <div style='font-weight:800; color:#0f172a;'>{$h_nama}, {$tgl_f}</div>
                                            <div style='font-size:11px; color:#94a3b8;'>{$l['tgl_only']}</div>
                                        </td>
                                        <td><code style='font-size:13.5px; font-weight:800; color:#0f172a; background:#f1f5f9; padding:3px 8px; border-radius:6px;'>{$jam_f}</code></td>
                                        <td>{$st_badge}</td>
                                        <td>{$ket_badge}</td>
                                        <td style='text-align:left;'>
                                            <div class='method-tag'>{$ver_icon} <span>{$ver_text}</span></div>
                                            " . (!empty($l['ip_address']) ? "<div style='font-size:10.5px; color:#94a3b8; font-family:monospace; margin-top:3px;'>IP: " . h($l['ip_address']) . "</div>" : "") . "
                                        </td>
                                        <td>{$td_foto}</td>
                                        {$td_aksi}
                                      </tr>";
                                $no++;
                            }
                        } else {
                            $colspan = is_superadmin() ? 8 : 7;
                            echo "<tr><td colspan='{$colspan}' style='padding:50px 20px; color:#94a3b8; font-size:14px; text-align:center;'>Belum ada rekaman presensi pada filter yang dipilih.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; // end tab riwayat ?>
    <?php endif; // end active_tab check ?>

</div>

<!-- LIGHTBOX MODAL FOTO SELFIE -->
<div class="photo-modal-overlay" id="photoModalOverlay" onclick="closePhotoLightbox(event)">
    <div class="photo-modal-card" onclick="event.stopPropagation()">
        <div style="position:relative; width:100%; aspect-ratio:4/3; background:#0f172a; display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <img id="lightboxImg" src="" alt="Bukti Foto" style="width:100%; height:100%; object-fit:cover;">
            <button type="button" onclick="closePhotoLightbox()" style="position:absolute; top:12px; right:12px; background:rgba(15,23,42,0.7); color:#fff; border:none; border-radius:50%; width:32px; height:32px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div style="padding:18px 22px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px;">
                <span id="lightboxStatusBadge" class="status-pill-badge status-pill-masuk">Absen Masuk</span>
                <span style="font-size:11.5px; color:#10b981; font-weight:700; background:#dcfce7; padding:3px 8px; border-radius:6px;">Selfie Terverifikasi</span>
            </div>
            <div id="lightboxTimeText" style="font-size:13.5px; font-weight:800; color:#0f172a; margin-top:4px;">-</div>
            <div style="font-size:12px; color:#64748b; margin-top:2px;">Bukti absensi mandiri karyawan</div>
        </div>
    </div>
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
    inputEmp.form.submit();
}

function applyPreset(type) {
    const now = new Date();
    const tglMulai = document.getElementById('tgl_mulai');
    const tglSelesai = document.getElementById('tgl_selesai');

    const pad = (n) => n < 10 ? '0' + n : n;
    const formatYMD = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());

    tglSelesai.value = formatYMD(now);

    if (type === 'today') {
        tglMulai.value = formatYMD(now);
    } else if (type === 'week') {
        const weekAgo = new Date();
        weekAgo.setDate(now.getDate() - 6);
        tglMulai.value = formatYMD(weekAgo);
    } else if (type === 'month') {
        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        tglMulai.value = formatYMD(firstDay);
    }

    tglMulai.form.submit();
}

function openPhotoLightbox(src, timeTxt, statusTxt) {
    const overlay = document.getElementById('photoModalOverlay');
    const img = document.getElementById('lightboxImg');
    const timeEl = document.getElementById('lightboxTimeText');
    const statusBadge = document.getElementById('lightboxStatusBadge');

    if (img) img.src = src;
    if (timeEl) timeEl.textContent = timeTxt;
    if (statusBadge) {
        statusBadge.textContent = statusTxt;
        if (statusTxt.toLowerCase().includes('pulang')) {
            statusBadge.className = 'status-pill-badge status-pill-pulang';
        } else {
            statusBadge.className = 'status-pill-badge status-pill-masuk';
        }
    }
    if (overlay) overlay.style.display = 'flex';
}

function closePhotoLightbox(e) {
    if (e && e.target && e.target.closest && e.target.closest('.photo-modal-card') && e.target.tagName !== 'BUTTON') {
        return;
    }
    const overlay = document.getElementById('photoModalOverlay');
    if (overlay) overlay.style.display = 'none';
}

// Leaflet Map Picker Initialization if on Tab Profil
let homeMapAdmin = null;
let homeMarkerAdmin = null;

const initialLat = <?php echo $default_map_lat; ?>;
const initialLng = <?php echo $default_map_lng; ?>;
const hasInitialCoords = <?php echo $has_home_coords ? 'true' : 'false'; ?>;

function initAdminHomeMap() {
    const mapEl = document.getElementById('homeMapPickerAdmin');
    if (!mapEl || typeof L === 'undefined') return;

    homeMapAdmin = L.map('homeMapPickerAdmin').setView([initialLat, initialLng], hasInitialCoords ? 16 : 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(homeMapAdmin);

    homeMarkerAdmin = L.marker([initialLat, initialLng], {
        draggable: true,
        title: 'Geser ke lokasi rumah'
    }).addTo(homeMapAdmin);

    homeMarkerAdmin.bindPopup("<b>Lokasi Rumah Pegawai</b><br>Geser pin tepat di atap rumah.").openPopup();

    homeMarkerAdmin.on('dragend', function(e) {
        const pos = homeMarkerAdmin.getLatLng();
        document.getElementById('input_latitude_rumah').value = pos.lat.toFixed(7);
        document.getElementById('input_longitude_rumah').value = pos.lng.toFixed(7);
    });

    homeMapAdmin.on('click', function(e) {
        homeMarkerAdmin.setLatLng(e.latlng);
        document.getElementById('input_latitude_rumah').value = e.latlng.lat.toFixed(7);
        document.getElementById('input_longitude_rumah').value = e.latlng.lng.toFixed(7);
    });

    setTimeout(() => {
        homeMapAdmin.invalidateSize();
    }, 300);
}

function getCurrentHomeGPS() {
    const btn = document.getElementById('btnGetHomeGps');
    if (!navigator.geolocation) {
        alert('Browser tidak mendukung Geolocation GPS.');
        return;
    }

    if (btn) btn.innerHTML = '<span>Mencari GPS...</span>';

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            document.getElementById('input_latitude_rumah').value = lat.toFixed(7);
            document.getElementById('input_longitude_rumah').value = lng.toFixed(7);

            if (homeMapAdmin && homeMarkerAdmin) {
                homeMapAdmin.setView([lat, lng], 17);
                homeMarkerAdmin.setLatLng([lat, lng]);
                homeMarkerAdmin.bindPopup("<b>Lokasi GPS Terkunci!</b>").openPopup();
            }
            if (btn) btn.innerHTML = '<span style="color:#16a34a;">GPS Terkunci!</span>';
        },
        function(err) {
            alert('Gagal membaca GPS: ' + err.message);
            if (btn) btn.innerHTML = '<span>GPS Saat Ini</span>';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

document.addEventListener('DOMContentLoaded', function() {
    initAdminHomeMap();
});
</script>

<?php render_footer(); ?>
