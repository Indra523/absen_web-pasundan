<?php
// ============================================================
// PORTAL MANDIRI - DATA DIRI & PROFIL PEGAWAI
// Redesain Modern, Aesthetic + Pin Point Lokasi Rumah
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('user_profile')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$pin = get_user_pin();
$pesan_sukses = '';
$pesan_error  = '';

if (empty($pin) || is_superadmin() || is_rnd() || is_admin()) {
    if (isset($_GET['pin']) && !empty($_GET['pin'])) {
        $pin = trim($_GET['pin']);
    }
}

// --- PROSES PERBARUI PROFIL MANDIRI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profil_mandiri') {
    csrf_verify();

    $target_pin     = trim($_POST['target_pin'] ?? $pin);
    $no_hp          = trim($_POST['no_hp'] ?? '');
    $tempat_lahir   = trim($_POST['tempat_lahir'] ?? '');
    $tanggal_lahir  = trim($_POST['tanggal_lahir'] ?? '');
    $alamat         = trim($_POST['alamat'] ?? '');
    $lat_rumah      = (!empty($_POST['latitude_rumah']) && is_numeric($_POST['latitude_rumah'])) ? (float)$_POST['latitude_rumah'] : null;
    $lng_rumah      = (!empty($_POST['longitude_rumah']) && is_numeric($_POST['longitude_rumah'])) ? (float)$_POST['longitude_rumah'] : null;
    $catatan_alamat = trim($_POST['catatan_alamat'] ?? '');

    $tgl_l_val      = !empty($tanggal_lahir) ? $tanggal_lahir : null;
    $foto_path      = null;

    if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['foto_profil']['tmp_name'];
        $file_name = $_FILES['foto_profil']['name'];
        $file_size = $_FILES['foto_profil']['size'];
        $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowed)) {
            $pesan_error = "Format foto tidak didukung. Gunakan format JPG, PNG, atau WEBP.";
        } elseif ($file_size > 2 * 1024 * 1024) {
            $pesan_error = "Ukuran file foto terlalu besar. Maksimal 2MB.";
        } else {
            $target_dir = __DIR__ . '/uploads/foto_karyawan/';
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $new_filename = "foto_" . preg_replace('/[^a-zA-Z0-9]/', '', $target_pin) . "_" . time() . "." . $ext;
            if (move_uploaded_file($file_tmp, $target_dir . $new_filename)) {
                $foto_path = "uploads/foto_karyawan/" . $new_filename;
            } else {
                $pesan_error = "Gagal mengunggah foto ke server.";
            }
        }
    }

    if (empty($pesan_error)) {
        if ($foto_path !== null) {
            $stmt_upd = $conn->prepare("UPDATE master_karyawan SET no_hp=?, tempat_lahir=?, tanggal_lahir=?, alamat=?, latitude_rumah=?, longitude_rumah=?, catatan_alamat=?, foto=? WHERE pin=?");
            $stmt_upd->bind_param("ssssdddss", $no_hp, $tempat_lahir, $tgl_l_val, $alamat, $lat_rumah, $lng_rumah, $catatan_alamat, $foto_path, $target_pin);
        } else {
            $stmt_upd = $conn->prepare("UPDATE master_karyawan SET no_hp=?, tempat_lahir=?, tanggal_lahir=?, alamat=?, latitude_rumah=?, longitude_rumah=?, catatan_alamat=? WHERE pin=?");
            $stmt_upd->bind_param("ssssddds", $no_hp, $tempat_lahir, $tgl_l_val, $alamat, $lat_rumah, $lng_rumah, $catatan_alamat, $target_pin);
        }
        if ($stmt_upd->execute()) {
            $pesan_sukses = "Data profil dan pin point lokasi rumah berhasil disimpan.";
            log_audit("UPDATE_PROFIL_MANDIRI", "Update foto, data diri & koordinat rumah PIN {$target_pin}");
        } else {
            $pesan_error = "Gagal menyimpan perubahan: " . $conn->error;
        }
    }
}

$detail      = null;
$absen_today = ['masuk' => null, 'pulang' => null];
$rekap_bulan = ['hadir' => 0, 'cuti' => 0, 'izin' => 0, 'sakit' => 0];
$jadwal_hari = [];

if (!empty($pin)) {
    $stmt = $conn->prepare("SELECT * FROM master_karyawan WHERE pin = ?");
    $stmt->bind_param("s", $pin);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();

    if ($detail) {
        $tgl_today = date('Y-m-d');
        $stmt_td = $conn->prepare("SELECT waktu, status FROM log_absen WHERE pin = ? AND DATE(waktu) = ? ORDER BY waktu ASC");
        $stmt_td->bind_param("ss", $pin, $tgl_today);
        $stmt_td->execute();
        $res_td = $stmt_td->get_result();
        while ($rtd = $res_td->fetch_assoc()) {
            if ($rtd['status'] == 0 && !$absen_today['masuk'])  $absen_today['masuk']  = date('H:i', strtotime($rtd['waktu']));
            if ($rtd['status'] == 1) $absen_today['pulang'] = date('H:i', strtotime($rtd['waktu']));
        }

        $bln = (int)date('m'); $thn = (int)date('Y');
        $res_h = $conn->query("SELECT COUNT(DISTINCT DATE(waktu)) as total FROM log_absen WHERE pin='{$pin}' AND MONTH(waktu)={$bln} AND YEAR(waktu)={$thn}");
        if ($res_h) $rekap_bulan['hadir'] = (int)($res_h->fetch_assoc()['total'] ?? 0);
        $res_i = $conn->query("SELECT tipe_izin, COUNT(*) as total FROM perizinan WHERE pin='{$pin}' AND MONTH(tanggal)={$bln} AND YEAR(tanggal)={$thn} AND (status_persetujuan='disetujui' OR status_persetujuan IS NULL) GROUP BY tipe_izin");
        if ($res_i) {
            while ($ri = $res_i->fetch_assoc()) {
                if (isset($rekap_bulan[$ri['tipe_izin']])) $rekap_bulan[$ri['tipe_izin']] = (int)$ri['total'];
            }
        }

        // Ambil jadwal mengajar jika guru
        if ($detail['tipe'] === 'guru') {
            $stmt_j = $conn->prepare("SELECT hari FROM jadwal_guru WHERE pin = ? ORDER BY hari ASC");
            $stmt_j->bind_param("s", $pin);
            $stmt_j->execute();
            $res_j = $stmt_j->get_result();
            while ($rj = $res_j->fetch_assoc()) {
                $jadwal_hari[] = (int)$rj['hari'];
            }
        }
    }
}

// Hitung usia jika ada tanggal lahir
$usia_str = '';
if (!empty($detail['tanggal_lahir'])) {
    $dob  = new DateTime($detail['tanggal_lahir']);
    $now  = new DateTime();
    $usia = $dob->diff($now)->y;
    $usia_str = $usia . ' Tahun';
}

$nama_hari_map = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu'];

// Default titik tengah peta Bandung jika belum ada koordinat rumah
$default_map_lat = !empty($detail['latitude_rumah']) ? (float)$detail['latitude_rumah'] : -6.90652863;
$default_map_lng = !empty($detail['longitude_rumah']) ? (float)$detail['longitude_rumah'] : 107.57195250;
$has_home_coords = (!empty($detail['latitude_rumah']) && !empty($detail['longitude_rumah']));

render_header("Profil & Data Diri", "user_profile");
?>

<!-- LEAFLET MAPS CSS & JS (OFFLINE/CDN COMPATIBLE) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
/* ============================================================ */
/* AESTHETIC & MODERN USER PROFILE THEME                         */
/* ============================================================ */
.profile-container {
    display: flex;
    flex-direction: column;
    gap: 24px;
    max-width: 1120px;
    margin: 0 auto 40px auto;
    width: 100%;
}

/* HERO DASHBOARD CARD */
.profile-hero {
    position: relative;
    background: linear-gradient(135deg, #0b132b 0%, #1c2541 50%, #0f172a 100%);
    border-radius: 22px;
    padding: 30px 32px;
    color: #ffffff;
    box-shadow: 0 16px 40px -10px rgba(15, 23, 42, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.09);
    overflow: hidden;
}

.profile-hero::before {
    content: '';
    position: absolute;
    top: -60px;
    right: -60px;
    width: 260px;
    height: 260px;
    background: radial-gradient(circle, rgba(56, 189, 248, 0.18) 0%, rgba(37, 99, 235, 0) 70%);
    border-radius: 50%;
    pointer-events: none;
}

.profile-hero-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}

.profile-identity-group {
    display: flex;
    align-items: center;
    gap: 20px;
}

.profile-avatar-large {
    position: relative;
    width: 88px;
    height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 800;
    color: #ffffff;
    box-shadow: 0 8px 24px rgba(37, 99, 235, 0.4);
    border: 3.5px solid rgba(255, 255, 255, 0.25);
    overflow: hidden;
    flex-shrink: 0;
}

.profile-avatar-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-meta-info {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.profile-name-title {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.3px;
    line-height: 1.25;
}

.profile-tags-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.tag-pin-chip {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.18);
    padding: 3px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-family: monospace;
    font-weight: 700;
    color: #38bdf8;
}

.tag-role-chip {
    padding: 3px 12px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.2px;
}

.tag-role-guru {
    background: rgba(59, 130, 246, 0.2);
    border: 1px solid rgba(96, 165, 250, 0.4);
    color: #93c5fd;
}

.tag-role-staff {
    background: rgba(148, 163, 184, 0.18);
    border: 1px solid rgba(148, 163, 184, 0.35);
    color: #cbd5e1;
}

.tag-dept-chip {
    font-size: 12px;
    color: #94a3b8;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
}

/* HERO ACTION BUTTONS */
.hero-cta-btn {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff !important;
    text-decoration: none;
    padding: 11px 22px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.hero-cta-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.45);
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
}

/* STATS ROW IN HERO */
.profile-stats-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 12px;
    margin-top: 24px;
    padding-top: 22px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.glass-stat-item {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    padding: 14px 16px;
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.2s ease;
}

.glass-stat-item:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.15);
    transform: translateY(-2px);
}

.glass-stat-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.glass-stat-num {
    font-size: 20px;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 3px;
}

.glass-stat-lbl {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

/* TODAY'S ATTENDANCE STATUS BAR */
.today-live-bar {
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 14px;
    padding: 12px 18px;
    margin-top: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    font-size: 12.5px;
}

.live-status-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
}

.live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.live-dot.on {
    background: #10b981;
    box-shadow: 0 0 8px #10b981;
}

.live-dot.off {
    background: #64748b;
}

/* GRID TWO-COLUMN */
.profile-grid-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
}

@media (max-width: 920px) {
    .profile-grid-layout {
        grid-template-columns: 1fr;
    }
}

/* MODERN CONTENT CARD */
.modern-card {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
    overflow: hidden;
    transition: all 0.2s ease;
}

.modern-card:hover {
    box-shadow: 0 10px 25px -4px rgba(15, 23, 42, 0.08);
}

.card-header-gradient {
    padding: 18px 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.card-title-text {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 9px;
}

.card-body-padding {
    padding: 24px;
}

/* INFO ITEMS LIST */
.info-row-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.info-row-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 11px 14px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #f1f5f9;
    transition: background 0.15s ease;
}

.info-row-item:hover {
    background: #f1f5f9;
}

.info-row-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    font-weight: 700;
    color: #64748b;
    flex-shrink: 0;
}

.info-row-val {
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    text-align: right;
    word-break: break-word;
}

/* PIN POINT HOME CARD */
.home-location-card {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 14px;
    padding: 16px;
    margin-top: 14px;
}

.home-location-card.empty {
    background: #fffbeb;
    border: 1px solid #fde68a;
}

.btn-gmaps-route {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #ffffff !important;
    font-size: 12.5px;
    font-weight: 800;
    text-decoration: none;
    padding: 9px 16px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
    transition: all 0.2s ease;
}

.btn-gmaps-route:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35);
}

.btn-gmaps-view {
    background: #ffffff;
    color: #1e293b !important;
    border: 1px solid #cbd5e1;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    padding: 9px 14px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}

.btn-gmaps-view:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}

/* MAP PICKER IN FORM */
.map-picker-stage {
    position: relative;
    width: 100%;
    height: 230px;
    border-radius: 14px;
    border: 1.5px solid #cbd5e1;
    overflow: hidden;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    z-index: 1;
}

/* JADWAL MENGAJAR BADGES */
.jadwal-badge-group {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 14px;
}

.jadwal-day-badge {
    padding: 6px 12px;
    border-radius: 10px;
    font-size: 11.5px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 5px;
}

.jadwal-day-badge.active {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.jadwal-day-badge.inactive {
    background: #f8fafc;
    color: #94a3b8;
    border: 1px solid #e2e8f0;
}

/* AVATAR INTERACTIVE UPLOADER */
.avatar-edit-stage {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 16px;
    background: #f8fafc;
    border-radius: 16px;
    border: 1.5px dashed #cbd5e1;
    margin-bottom: 20px;
    transition: border-color 0.2s ease;
}

.avatar-edit-stage:hover {
    border-color: #3b82f6;
}

.avatar-edit-thumb {
    position: relative;
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    font-weight: 800;
    color: #fff;
    overflow: hidden;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25);
    border: 3px solid #fff;
}

.avatar-edit-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* FORM ELEMENTS */
.form-input-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
}

.form-label-custom {
    font-size: 12px;
    font-weight: 800;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.input-box-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.input-box-wrapper input,
.input-box-wrapper textarea {
    width: 100%;
    padding: 11px 14px;
    border-radius: 12px;
    border: 1.5px solid #cbd5e1;
    font-size: 13.5px;
    font-weight: 600;
    color: #0f172a;
    background: #ffffff;
    transition: all 0.2s ease;
    outline: none;
    box-sizing: border-box;
}

.input-box-wrapper input:focus,
.input-box-wrapper textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    background: #ffffff;
}

.form-actions-bar {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 18px;
    border-top: 1px solid #f1f5f9;
}

.btn-save-custom {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    border: none;
    padding: 11px 22px;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
    transition: all 0.2s ease;
}

.btn-save-custom:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.4);
}

.btn-reset-custom {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    padding: 11px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}

.btn-reset-custom:hover {
    background: #e2e8f0;
    color: #0f172a;
}
</style>

<div class="profile-container">

    <!-- ALERT NOTIFIKASI -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:14px 18px; color:#15803d; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(22,163,74,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; border-radius:14px; padding:14px 18px; color:#991b1b; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(220,38,38,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
        </div>
    <?php endif; ?>

    <?php if (empty($pin) || !$detail): ?>
        <div class="modern-card" style="text-align:center; padding:50px 24px;">
            <div style="width:64px; height:64px; border-radius:50%; background:#fee2e2; color:#ef4444; margin:0 auto 16px auto; display:flex; align-items:center; justify-content:center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin-bottom:8px;">Data Pegawai Tidak Ditemukan</h3>
            <p style="color:#64748b; font-size:13.5px; max-width:460px; margin:0 auto; line-height:1.6;">
                Akun Anda belum terhubung dengan PIN Karyawan. Silakan hubungi Administrator sekolah untuk menghubungkan akun Anda.
            </p>
        </div>
    <?php else: ?>

        <!-- HERO PROFILE DASHBOARD -->
        <div class="profile-hero">
            <div class="profile-hero-top">
                <div class="profile-identity-group">
                    <div class="profile-avatar-large">
                        <?php if (!empty($detail['foto']) && file_exists(__DIR__ . '/' . $detail['foto'])): ?>
                            <img src="<?php echo h($detail['foto']); ?>" alt="<?php echo h($detail['nama']); ?>">
                        <?php else: ?>
                            <?php echo strtoupper(mb_substr($detail['nama'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-meta-info">
                        <div class="profile-name-title"><?php echo h($detail['nama']); ?></div>
                        <div class="profile-tags-row">
                            <span class="tag-pin-chip">PIN: <?php echo h($detail['pin']); ?></span>
                            <span class="tag-role-chip <?php echo $detail['tipe'] === 'guru' ? 'tag-role-guru' : 'tag-role-staff'; ?>">
                                <?php echo $detail['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
                            </span>
                            <span class="tag-dept-chip">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1M9 12h1M9 16h1M14 8h1M14 12h1M14 16h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                                <span><?php echo h($detail['departemen'] ?: 'Umum'); ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <a href="absen_mandiri.php<?php echo !is_user_role() ? '?pin=' . urlencode($pin) : ''; ?>" class="hero-cta-btn">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span>Buka Absen Mandiri</span>
                </a>
            </div>

            <!-- RINGKASAN KEHADIRAN BULAN INI -->
            <div class="profile-stats-strip">
                <div class="glass-stat-item">
                    <div class="glass-stat-icon" style="background:rgba(16,185,129,0.18); color:#34d399;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <div>
                        <div class="glass-stat-num" style="color:#34d399;"><?php echo $rekap_bulan['hadir']; ?></div>
                        <div class="glass-stat-lbl">Hadir (Bln Ini)</div>
                    </div>
                </div>

                <div class="glass-stat-item">
                    <div class="glass-stat-icon" style="background:rgba(245,158,11,0.18); color:#fbbf24;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div>
                        <div class="glass-stat-num" style="color:#fbbf24;"><?php echo $rekap_bulan['izin']; ?></div>
                        <div class="glass-stat-lbl">Izin</div>
                    </div>
                </div>

                <div class="glass-stat-item">
                    <div class="glass-stat-icon" style="background:rgba(239,68,68,0.18); color:#f87171;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    </div>
                    <div>
                        <div class="glass-stat-num" style="color:#f87171;"><?php echo $rekap_bulan['sakit']; ?></div>
                        <div class="glass-stat-lbl">Sakit</div>
                    </div>
                </div>

                <div class="glass-stat-item">
                    <div class="glass-stat-icon" style="background:rgba(56,189,248,0.18); color:#38bdf8;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div>
                        <div class="glass-stat-num" style="color:#38bdf8;"><?php echo $rekap_bulan['cuti']; ?></div>
                        <div class="glass-stat-lbl">Cuti</div>
                    </div>
                </div>
            </div>

            <!-- LIVE BAR ABSEN HARI INI -->
            <div class="today-live-bar">
                <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div class="live-status-pill">
                        <span class="live-dot <?php echo $absen_today['masuk'] ? 'on' : 'off'; ?>"></span>
                        <span style="color:#94a3b8;">Masuk:</span>
                        <span style="color:#ffffff; font-weight:800;"><?php echo $absen_today['masuk'] ? h($absen_today['masuk']) . ' WIB' : 'Belum Absen'; ?></span>
                    </div>
                    <div class="live-status-pill">
                        <span class="live-dot <?php echo $absen_today['pulang'] ? 'on' : 'off'; ?>"></span>
                        <span style="color:#94a3b8;">Pulang:</span>
                        <span style="color:#ffffff; font-weight:800;"><?php echo $absen_today['pulang'] ? h($absen_today['pulang']) . ' WIB' : 'Belum Absen'; ?></span>
                    </div>
                </div>
                <div style="color:#94a3b8; font-size:12px; font-weight:600; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span><?php echo date('l, d F Y'); ?></span>
                </div>
            </div>
        </div>

        <!-- MAIN TWO-COLUMN CONTENT SECTION -->
        <div class="profile-grid-layout">

            <!-- LEFT COLUMN: READONLY PROFILE INFORMATION -->
            <div class="modern-card">
                <div class="card-header-gradient">
                    <div class="card-title-text">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Informasi Guru / Karyawan</span>
                    </div>
                    <span style="font-size:11px; font-weight:700; color:#10b981; background:#dcfce7; padding:3px 10px; border-radius:20px; border:1px solid #bbf7d0;">
                        TERDAFTAR
                    </span>
                </div>

                <div class="card-body-padding">
                    <div class="info-row-list">
                        <div class="info-row-item">
                            <div class="info-row-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span>PIN</span>
                            </div>
                            <div class="info-row-val" style="display:flex; align-items:center; gap:6px;">
                                <span id="pinText" style="background:#e2e8f0; border:1px solid #cbd5e1; padding:2px 8px; border-radius:6px; font-family:monospace; font-weight:800; color:#0f172a;">
                                    <?php echo h($detail['pin']); ?>
                                </span>
                                <button type="button" onclick="copyPIN('<?php echo h($detail['pin']); ?>')" title="Salin PIN" style="background:none; border:none; cursor:pointer; color:#64748b; padding:2px; display:inline-flex;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="info-row-item">
                            <div class="info-row-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <span>Nama Lengkap</span>
                            </div>
                            <div class="info-row-val"><?php echo h($detail['nama']); ?></div>
                        </div>

                        <div class="info-row-item">
                            <div class="info-row-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1M9 12h1M9 16h1M14 8h1M14 12h1M14 16h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                                <span>Departemen</span>
                            </div>
                            <div class="info-row-val"><?php echo h($detail['departemen'] ?: '-'); ?></div>
                        </div>

                        <div class="info-row-item">
                            <div class="info-row-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                                <span>Status / Tipe</span>
                            </div>
                            <div class="info-row-val">
                                <span style="background:<?php echo $detail['tipe'] === 'guru' ? '#eff6ff' : '#f8fafc'; ?>; color:<?php echo $detail['tipe'] === 'guru' ? '#1d4ed8' : '#334155'; ?>; border:1px solid <?php echo $detail['tipe'] === 'guru' ? '#bfdbfe' : '#cbd5e1'; ?>; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">
                                    <?php echo $detail['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="info-row-item">
                            <div class="info-row-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                <span>No. WhatsApp</span>
                            </div>
                            <div class="info-row-val">
                                <?php if (!empty($detail['no_hp'])): ?>
                                    <a href="https://wa.me/<?php echo preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $detail['no_hp'])); ?>" target="_blank" style="color:#059669; text-decoration:none; font-weight:800; display:inline-flex; align-items:center; gap:4px;">
                                        <span><?php echo h($detail['no_hp']); ?></span>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">-</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-row-item">
                            <div class="info-row-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span>TTL &amp; Usia</span>
                            </div>
                            <div class="info-row-val">
                                <?php
                                $ttl = [];
                                if (!empty($detail['tempat_lahir'])) $ttl[] = $detail['tempat_lahir'];
                                if (!empty($detail['tanggal_lahir'])) $ttl[] = date('d F Y', strtotime($detail['tanggal_lahir']));
                                if (!empty($ttl)) {
                                    echo h(implode(', ', $ttl));
                                    if ($usia_str) echo " <span style='font-size:11px; background:#eff6ff; color:#2563eb; padding:1px 6px; border-radius:6px; font-weight:700;'>({$usia_str})</span>";
                                } else {
                                    echo '-';
                                }
                                ?>
                            </div>
                        </div>

                        <div class="info-row-item">
                            <div class="info-row-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                <span>Alamat Domisili</span>
                            </div>
                            <div class="info-row-val" style="font-size:12.5px; line-height:1.4; color:#334155;">
                                <?php echo h($detail['alamat'] ?: '-'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- CARD PIN POINT LOKASI RUMAH & NAVIGASI (UNTUK JENGUK SAKIT / KUNJUNGAN STAFF) -->
                    <div class="home-location-card <?php echo !$has_home_coords ? 'empty' : ''; ?>">
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:10px;">
                            <div style="font-size:12.5px; font-weight:800; color:<?php echo $has_home_coords ? '#166534' : '#92400e'; ?>; display:flex; align-items:center; gap:7px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <span>Titik Koordinat / Pin Point Rumah</span>
                            </div>
                            <?php if ($has_home_coords): ?>
                                <span style="background:#dcfce7; color:#15803d; font-size:10.5px; font-weight:800; padding:2px 8px; border-radius:6px; border:1px solid #86efac;">
                                    TERVERIFIKASI
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($has_home_coords): ?>
                            <div style="font-size:11.5px; font-family:monospace; font-weight:700; color:#15803d; margin-bottom:6px;">
                                Koordinat: <code><?php echo number_format($detail['latitude_rumah'], 6) . ', ' . number_format($detail['longitude_rumah'], 6); ?></code>
                            </div>
                            <?php if (!empty($detail['catatan_alamat'])): ?>
                                <div style="font-size:12px; color:#334155; margin-bottom:12px; background:rgba(255,255,255,0.7); padding:6px 10px; border-radius:8px; border:1px solid #bbf7d0;">
                                    <strong>Patokan:</strong> <?php echo h($detail['catatan_alamat']); ?>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                                <?php if (can_access_route_maps()): ?>
                                <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $detail['latitude_rumah'] . ',' . $detail['longitude_rumah']; ?>" target="_blank" class="btn-gmaps-route" title="Buka Petunjuk Arah Langsung di Google Maps">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                                    <span>Buka Rute di Google Maps</span>
                                </a>
                                <?php endif; ?>
                                <a href="https://www.google.com/maps?q=<?php echo $detail['latitude_rumah'] . ',' . $detail['longitude_rumah']; ?>" target="_blank" class="btn-gmaps-view" title="Lihat Titik Peta Google Maps">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4-7 4z"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                                    <span>Lihat Peta</span>
                                </a>
                            </div>
                        <?php else: ?>
                            <div style="font-size:12px; color:#92400e; line-height:1.5;">
                                Titik koordinat GPS rumah belum ditentukan. Silakan tentukan titik rumah Anda pada formulir di samping agar memudahkan staff berkunjung/menjenguk saat dibutuhkan.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- HARI MENGAJAR JIKA GURU -->
                    <?php if ($detail['tipe'] === 'guru'): ?>
                        <div style="margin-top:20px; padding-top:16px; border-top:1px solid #f1f5f9;">
                            <div style="font-size:12px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:0.4px; display:flex; align-items:center; gap:6px; margin-bottom:8px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                <span>Jadwal Hari Mengajar</span>
                            </div>
                            <div class="jadwal-badge-group">
                                <?php foreach ($nama_hari_map as $h_num => $h_nama): 
                                    $is_active = in_array($h_num, $jadwal_hari);
                                ?>
                                    <span class="jadwal-day-badge <?php echo $is_active ? 'active' : 'inactive'; ?>">
                                        <?php if ($is_active): ?>
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                        <?php endif; ?>
                                        <span><?php echo $h_nama; ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT COLUMN: FORM EDIT PROFIL MANDIRI + PIN POINT MAP PICKER -->
            <div class="modern-card">
                <div class="card-header-gradient">
                    <div class="card-title-text">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <span>Perbarui Data &amp; Lokasi Rumah</span>
                    </div>
                </div>

                <div class="card-body-padding">
                    <form method="POST" action="user_profile.php<?php echo !is_user_role() ? '?pin=' . urlencode($pin) : ''; ?>" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_profil_mandiri">
                        <input type="hidden" name="target_pin" value="<?php echo h($pin); ?>">

                        <!-- INTERACTIVE AVATAR UPLOAD -->
                        <div class="form-label-custom" style="margin-bottom:8px;">Foto Profil</div>
                        <div class="avatar-edit-stage">
                            <div class="avatar-edit-thumb" id="avatarPreviewContainer">
                                <?php if (!empty($detail['foto']) && file_exists(__DIR__ . '/' . $detail['foto'])): ?>
                                    <img src="<?php echo h($detail['foto']); ?>" id="avatarPreviewImg" alt="Avatar">
                                <?php else: ?>
                                    <span id="avatarPreviewInitials"><?php echo strtoupper(mb_substr($detail['nama'], 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1;">
                                <label for="foto_profil" style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1.5px solid #cbd5e1; padding:7px 14px; border-radius:10px; font-size:12.5px; font-weight:700; color:#334155; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.04);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                    <span>Pilih Foto Baru</span>
                                </label>
                                <input type="file" id="foto_profil" name="foto_profil" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewSelectedImage(this)">
                                <div style="font-size:11.5px; color:#64748b; margin-top:6px; line-height:1.4;">
                                    Format JPG, PNG, WEBP (Maksimal 2MB).
                                </div>
                            </div>
                        </div>

                        <!-- INPUT NO HP -->
                        <div class="form-input-group">
                            <label for="no_hp" class="form-label-custom">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                <span>Nomor Telepon / WhatsApp</span>
                            </label>
                            <div class="input-box-wrapper">
                                <input type="text" id="no_hp" name="no_hp" value="<?php echo h($detail['no_hp'] ?? ''); ?>" placeholder="Contoh: 08123456789">
                            </div>
                        </div>

                        <!-- 2 COLUMNS TTL -->
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div class="form-input-group">
                                <label for="tempat_lahir" class="form-label-custom">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                    <span>Tempat Lahir</span>
                                </label>
                                <div class="input-box-wrapper">
                                    <input type="text" id="tempat_lahir" name="tempat_lahir" value="<?php echo h($detail['tempat_lahir'] ?? ''); ?>" placeholder="Kota kelahiran">
                                </div>
                            </div>

                            <div class="form-input-group">
                                <label for="tanggal_lahir" class="form-label-custom">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <span>Tanggal Lahir</span>
                                </label>
                                <div class="input-box-wrapper">
                                    <input type="date" id="tanggal_lahir" name="tanggal_lahir" value="<?php echo h($detail['tanggal_lahir'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- INPUT ALAMAT -->
                        <div class="form-input-group">
                            <label for="alamat" class="form-label-custom">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                <span>Alamat Domisili Lengkap</span>
                            </label>
                            <div class="input-box-wrapper">
                                <textarea id="alamat" name="alamat" rows="2" style="resize:vertical; line-height:1.5;" placeholder="Jl. ... No. ... RT/RW ... Kel. ... Kec. ... Kota/Kab. ..."><?php echo h($detail['alamat'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div style="height:1px; background:#e2e8f0; margin:20px 0;"></div>

                        <!-- SECTION PIN POINT PETA RUMAH -->
                        <div style="margin-bottom:18px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; flex-wrap:wrap;">
                                <label class="form-label-custom" style="margin-bottom:0;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                    <span>Pin Point Lokasi Rumah (Peta Interaktif)</span>
                                </label>
                                <button type="button" onclick="getCurrentHomeGPS()" id="btnGetHomeGps" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:11.5px; font-weight:800; padding:5px 12px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg>
                                    <span>Ambil Lokasi Saya Sekarang (GPS HP)</span>
                                </button>
                            </div>

                            <div style="font-size:11.5px; color:#64748b; margin-bottom:10px; line-height:1.4;">
                                Klik atau geser pin merah di peta tepat di lokasi rumah Anda untuk memudahkan staff menjenguk jika Anda sakit.
                            </div>

                            <!-- LEAFLET MAP CONTAINER -->
                            <div class="map-picker-stage" id="homeMapPicker"></div>

                            <!-- KOORDINAT INPUTS -->
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                <div class="form-input-group" style="margin-bottom:10px;">
                                    <label class="form-label-custom" style="font-size:11px;">Latitude</label>
                                    <div class="input-box-wrapper">
                                        <input type="text" id="input_latitude_rumah" name="latitude_rumah" value="<?php echo !empty($detail['latitude_rumah']) ? h($detail['latitude_rumah']) : ''; ?>" placeholder="-6.xxxxxx" readonly style="background:#f8fafc; font-family:monospace; font-size:12.5px;">
                                    </div>
                                </div>
                                <div class="form-input-group" style="margin-bottom:10px;">
                                    <label class="form-label-custom" style="font-size:11px;">Longitude</label>
                                    <div class="input-box-wrapper">
                                        <input type="text" id="input_longitude_rumah" name="longitude_rumah" value="<?php echo !empty($detail['longitude_rumah']) ? h($detail['longitude_rumah']) : ''; ?>" placeholder="107.xxxxxx" readonly style="background:#f8fafc; font-family:monospace; font-size:12.5px;">
                                    </div>
                                </div>
                            </div>

                            <div class="form-input-group" style="margin-bottom:0;">
                                <label for="catatan_alamat" class="form-label-custom" style="font-size:11px;">
                                    <span>Patokan / Petunjuk Lokasi Rumah</span>
                                </label>
                                <div class="input-box-wrapper">
                                    <input type="text" id="catatan_alamat" name="catatan_alamat" value="<?php echo h($detail['catatan_alamat'] ?? ''); ?>" placeholder="Contoh: Pagar hitam no. 15, depan Masjid Al-Ikhlas, masuk gang pos ronda">
                                </div>
                            </div>
                        </div>

                        <!-- ACTION BUTTONS -->
                        <div class="form-actions-bar">
                            <button type="reset" class="btn-reset-custom">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 2v6h6"/><path d="M2.5 13a9 9 0 1 0 3-7.7L2.5 8"/></svg>
                                <span>Reset</span>
                            </button>
                            <button type="submit" class="btn-save-custom">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                <span>Simpan Profil &amp; Lokasi</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

    <?php endif; ?>

</div>

<script>
let homeMap = null;
let homeMarker = null;

const initialLat = <?php echo $default_map_lat; ?>;
const initialLng = <?php echo $default_map_lng; ?>;
const hasInitialCoords = <?php echo $has_home_coords ? 'true' : 'false'; ?>;

function initHomeMapPicker() {
    const mapEl = document.getElementById('homeMapPicker');
    if (!mapEl || typeof L === 'undefined') return;

    homeMap = L.map('homeMapPicker').setView([initialLat, initialLng], hasInitialCoords ? 16 : 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(homeMap);

    // Marker
    homeMarker = L.marker([initialLat, initialLng], {
        draggable: true,
        title: 'Geser ke lokasi rumah Anda'
    }).addTo(homeMap);

    homeMarker.bindPopup("<b>Lokasi Rumah Anda</b><br>Geser pin merah tepat di atap rumah Anda.").openPopup();

    // Event ketika pin digeser
    homeMarker.on('dragend', function(e) {
        const pos = homeMarker.getLatLng();
        updateCoordInputs(pos.lat, pos.lng);
    });

    // Event ketika peta diklik
    homeMap.on('click', function(e) {
        homeMarker.setLatLng(e.latlng);
        updateCoordInputs(e.latlng.lat, e.latlng.lng);
    });

    // Fix map sizing on render
    setTimeout(() => {
        homeMap.invalidateSize();
    }, 300);
}

function updateCoordInputs(lat, lng) {
    document.getElementById('input_latitude_rumah').value = lat.toFixed(7);
    document.getElementById('input_longitude_rumah').value = lng.toFixed(7);
}

function getCurrentHomeGPS() {
    const btn = document.getElementById('btnGetHomeGps');
    if (!navigator.geolocation) {
        alert('Browser tidak mendukung Geolocation GPS.');
        return;
    }

    if (btn) {
        btn.innerHTML = '<span class="btn-spinner" style="display:inline-block; width:12px; height:12px; border:2px solid #2563eb; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span> <span>Membaca GPS Satelit...</span>';
    }

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            const accuracy = Math.round(pos.coords.accuracy || 0);

            updateCoordInputs(lat, lng);

            if (homeMap && homeMarker) {
                homeMap.setView([lat, lng], 17);
                homeMarker.setLatLng([lat, lng]);
                homeMarker.bindPopup("<b>Lokasi GPS Terkunci!</b><br>Akurasi: &plusmn;" + accuracy + " meter").openPopup();
            }

            if (btn) {
                btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> <span style="color:#16a34a;">Lokasi Terkunci (&plusmn;' + accuracy + 'm)</span>';
                setTimeout(() => {
                    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg> <span>Ambil Lokasi Saya Sekarang (GPS HP)</span>';
                }, 3500);
            }
        },
        function(err) {
            if (btn) {
                btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg> <span>Ambil Lokasi Saya Sekarang (GPS HP)</span>';
            }
            alert('Gagal mengambil koordinat GPS: ' + err.message + '. Silakan klik langsung pada peta untuk menentukan titik rumah.');
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
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

function copyPIN(pin) {
    navigator.clipboard.writeText(pin).then(() => {
        const pinEl = document.getElementById('pinText');
        const originalBg = pinEl.style.background;
        pinEl.style.background = '#bbf7d0';
        pinEl.style.color = '#15803d';
        setTimeout(() => {
            pinEl.style.background = originalBg;
            pinEl.style.color = '#0f172a';
        }, 1200);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initHomeMapPicker();
});
</script>

<?php render_footer(); ?>
