<?php
// ============================================================
// PORTAL MANDIRI - DATA DIRI & PROFIL PEGAWAI
// Akses: Role User (Profil Sendiri) & Superadmin/RnD/Admin
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profil_mandiri') {
    csrf_verify();

    $target_pin    = trim($_POST['target_pin'] ?? $pin);
    $no_hp         = trim($_POST['no_hp'] ?? '');
    $tempat_lahir  = trim($_POST['tempat_lahir'] ?? '');
    $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '');
    $alamat        = trim($_POST['alamat'] ?? '');
    $tgl_l_val     = !empty($tanggal_lahir) ? $tanggal_lahir : null;
    $foto_path     = null;

    if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['foto_profil']['tmp_name'];
        $file_name = $_FILES['foto_profil']['name'];
        $file_size = $_FILES['foto_profil']['size'];
        $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowed)) {
            $pesan_error = "Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.";
        } elseif ($file_size > 2 * 1024 * 1024) {
            $pesan_error = "Ukuran file terlalu besar. Maksimal 2MB.";
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
            $stmt_upd = $conn->prepare("UPDATE master_karyawan SET no_hp=?, tempat_lahir=?, tanggal_lahir=?, alamat=?, foto=? WHERE pin=?");
            $stmt_upd->bind_param("ssssss", $no_hp, $tempat_lahir, $tgl_l_val, $alamat, $foto_path, $target_pin);
        } else {
            $stmt_upd = $conn->prepare("UPDATE master_karyawan SET no_hp=?, tempat_lahir=?, tanggal_lahir=?, alamat=? WHERE pin=?");
            $stmt_upd->bind_param("sssss", $no_hp, $tempat_lahir, $tgl_l_val, $alamat, $target_pin);
        }
        if ($stmt_upd->execute()) {
            $pesan_sukses = "Data profil berhasil diperbarui.";
            log_audit("UPDATE_PROFIL_MANDIRI", "Update foto & data diri PIN {$target_pin}");
        } else {
            $pesan_error = "Gagal menyimpan perubahan: " . $conn->error;
        }
    }
}

$detail      = null;
$absen_today = ['masuk' => null, 'pulang' => null];
$rekap_bulan = ['hadir' => 0, 'cuti' => 0, 'izin' => 0, 'sakit' => 0];

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

render_header("Profil & Data Diri", "user_profile");
?>

<style>
/* ===== REFINED USER PROFILE & MOBILE SELFIE ATTENDANCE ===== */
.profile-wrapper {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-bottom: 30px;
}

/* AI FACE DETECTION GUIDE OVERLAY */
.face-guide-wrapper {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 10;
}
.face-guide-oval {
    width: 190px;
    height: 240px;
    border-radius: 50% / 50%;
    border: 3px dashed #f59e0b;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.4);
    position: relative;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: center;
}
.face-guide-oval.valid {
    border: 3.5px solid #10b981 !important;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.4), 0 0 24px rgba(16, 185, 129, 0.6) !important;
}
.face-guide-oval.invalid {
    border: 3px solid #ef4444 !important;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.4), 0 0 20px rgba(239, 68, 68, 0.45) !important;
}
.face-guide-oval.warning {
    border: 3px solid #f97316 !important;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.4), 0 0 16px rgba(249, 115, 22, 0.4) !important;
}

.face-status-pill {
    position: absolute;
    bottom: 10px;
    background: rgba(15, 23, 42, 0.88);
    color: #ffffff;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 7px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(6px);
    white-space: nowrap;
    max-width: 90%;
    text-align: center;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
    transition: all 0.25s ease;
}
.face-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    background: #f59e0b;
}
.face-status-dot.valid {
    background: #10b981;
    box-shadow: 0 0 8px #10b981;
}
.face-status-dot.invalid {
    background: #ef4444;
    box-shadow: 0 0 8px #ef4444;
}
.face-status-dot.warning {
    background: #f97316;
    box-shadow: 0 0 8px #f97316;
}

/* AI SCANNING & PROCESSING OVERLAY */
.camera-scanning-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.84);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 25;
    padding: 20px;
    text-align: center;
    color: #ffffff;
    animation: fadeInOverlay 0.2s ease-out;
}
.scanning-scanner-line {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, transparent, #38bdf8, #60a5fa, transparent);
    box-shadow: 0 0 15px #38bdf8, 0 0 25px #60a5fa;
    animation: scanLaser 1.6s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}
@keyframes scanLaser {
    0% { top: 0%; opacity: 0.8; }
    50% { top: 96%; opacity: 1; }
    100% { top: 0%; opacity: 0.8; }
}
.scanning-loader-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    max-width: 280px;
}
.scanning-spinner {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 3.5px solid rgba(56, 189, 248, 0.2);
    border-top-color: #38bdf8;
    animation: spinLoader 0.75s linear infinite;
    box-shadow: 0 0 16px rgba(56, 189, 248, 0.4);
}
.btn-spinner {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #ffffff;
    animation: spinLoader 0.65s linear infinite;
    display: inline-block;
    vertical-align: middle;
}
@keyframes spinLoader {
    100% { transform: rotate(360deg); }
}
.scanning-text-title {
    font-size: 14.5px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.2px;
}
.scanning-text-sub {
    font-size: 11.5px;
    color: #94a3b8;
    line-height: 1.45;
}

/* MODAL ANIMASI FOTO DITOLAK */
.reject-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.78);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10050;
    padding: 16px;
    animation: fadeInOverlay 0.25s ease-out;
}
@keyframes fadeInOverlay {
    from { opacity: 0; }
    to { opacity: 1; }
}

.reject-modal-card {
    background: #ffffff;
    width: 100%;
    max-width: 400px;
    border-radius: 24px;
    padding: 28px 22px 22px 22px;
    text-align: center;
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.45);
    border: 1.5px solid rgba(239, 68, 68, 0.3);
    position: relative;
    animation: shakePopup 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
}

@keyframes shakePopup {
    0% { transform: scale(0.85) translateY(20px); opacity: 0; }
    40% { transform: scale(1.02) translateX(-6px); opacity: 1; }
    60% { transform: scale(0.99) translateX(5px); }
    80% { transform: scale(1.01) translateX(-3px); }
    100% { transform: scale(1) translateX(0); opacity: 1; }
}

.reject-icon-wrapper {
    width: 80px;
    height: 80px;
    margin: 0 auto 14px auto;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.reject-icon-circle {
    width: 76px;
    height: 76px;
    border-radius: 50%;
    background: #fee2e2;
    border: 3px solid #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 25px rgba(239, 68, 68, 0.35);
    animation: pulseRedRing 1.8s infinite;
}

@keyframes pulseRedRing {
    0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
    70% { box-shadow: 0 0 0 16px rgba(239, 68, 68, 0); }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}

.reject-svg-cross {
    width: 38px;
    height: 38px;
}

.reject-svg-cross path {
    stroke: #dc2626;
    stroke-width: 4.5;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 48;
    stroke-dashoffset: 48;
    animation: drawCrossLine 0.4s 0.15s cubic-bezier(0.65, 0, 0.45, 1) forwards;
}

.reject-svg-cross path:nth-child(2) {
    animation-delay: 0.3s;
}

@keyframes drawCrossLine {
    100% { stroke-dashoffset: 0; }
}

.reject-modal-title {
    font-size: 18px;
    font-weight: 800;
    color: #991b1b;
    margin-bottom: 4px;
    letter-spacing: -0.2px;
}

.reject-modal-reason {
    font-size: 13px;
    font-weight: 600;
    color: #7f1d1d;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    padding: 9px 12px;
    border-radius: 12px;
    margin: 10px 0 16px 0;
    line-height: 1.45;
}

.reject-tips-list {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px 14px;
    text-align: left;
    margin-bottom: 18px;
}

.reject-tip-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 11.5px;
    color: #334155;
    margin-bottom: 6px;
    line-height: 1.4;
}
.reject-tip-item:last-child { margin-bottom: 0; }
.reject-tip-icon {
    flex-shrink: 0;
    margin-top: 1px;
}

.btn-reject-retry {
    width: 100%;
    padding: 12px 18px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #ffffff;
    font-weight: 800;
    font-size: 13.5px;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(239, 68, 68, 0.35);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.btn-reject-retry:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    transform: translateY(-1px);
    box-shadow: 0 8px 22px rgba(239, 68, 68, 0.45);
}
.btn-reject-retry:active {
    transform: scale(0.98);
}

/* HERO BANNER CARD */
.hero-card {
    position: relative;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #1e3a8a 100%);
    border-radius: 20px;
    padding: 28px 32px;
    color: #ffffff;
    overflow: hidden;
    box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.25);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.hero-card::before {
    content: '';
    position: absolute;
    top: -80px;
    right: -80px;
    width: 280px;
    height: 280px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.25) 0%, rgba(59, 130, 246, 0) 70%);
    border-radius: 50%;
    pointer-events: none;
}
.hero-card::after {
    content: '';
    position: absolute;
    bottom: -60px;
    left: 15%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.18) 0%, rgba(99, 102, 241, 0) 70%);
    border-radius: 50%;
    pointer-events: none;
}

.hero-main {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.hero-profile-info {
    display: flex;
    align-items: center;
    gap: 20px;
    flex: 1;
    min-width: 260px;
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
    border: 3px solid rgba(255, 255, 255, 0.9);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}
.hero-avatar-initials {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border: 3px solid rgba(255, 255, 255, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 34px;
    font-weight: 800;
    color: #ffffff;
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

.hero-details {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    flex: 1;
}
.hero-subtitle {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #93c5fd;
    display: flex;
    align-items: center;
    gap: 6px;
}
.hero-name {
    font-size: clamp(20px, 3.5vw, 24px);
    font-weight: 800;
    color: #ffffff;
    margin: 0;
    line-height: 1.25;
    word-break: break-word;
}
.hero-tags {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 2px;
}
.hero-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 600;
    backdrop-filter: blur(8px);
}
.hero-tag-dept {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #f1f5f9;
}
.hero-tag-pin {
    background: rgba(59, 130, 246, 0.25);
    border: 1px solid rgba(147, 197, 253, 0.35);
    color: #bfdbfe;
    font-family: monospace;
    font-size: 12px;
}
.hero-tag-age {
    background: rgba(168, 85, 247, 0.25);
    border: 1px solid rgba(216, 180, 254, 0.35);
    color: #e9d5ff;
}

/* MONTHLY REKAP STATS GRID */
.hero-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    min-width: 280px;
}
.stat-card {
    background: rgba(255, 255, 255, 0.07);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    padding: 12px 14px;
    text-align: center;
    transition: all 0.25s ease;
    backdrop-filter: blur(10px);
}
.stat-card:hover {
    background: rgba(255, 255, 255, 0.12);
    transform: translateY(-2px);
    border-color: rgba(255, 255, 255, 0.25);
}
.stat-card-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 7px;
    margin-bottom: 4px;
}
.stat-number {
    font-size: 20px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 3px;
}
.stat-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
}

/* PRESENSI HARI INI RIBBON */
.hero-attendance-bar {
    position: relative;
    z-index: 2;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid rgba(255, 255, 255, 0.12);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}
.attendance-pill-group {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.attendance-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(15, 23, 42, 0.45);
    padding: 7px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    font-size: 12.5px;
}
.attendance-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.attendance-dot.active {
    background: #10b981;
    box-shadow: 0 0 8px #10b981;
}
.attendance-dot.inactive {
    background: #ef4444;
    box-shadow: 0 0 8px #ef4444;
}
.attendance-date {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #94a3b8;
    font-weight: 600;
}

/* SELFIE ATTENDANCE CARD STYLES */
.selfie-card {
    background: #ffffff;
    border: 1.5px solid #3b82f6;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(37, 99, 235, 0.08);
}
.selfie-card-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.selfie-card-title {
    font-size: 14.5px;
    font-weight: 800;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 8px;
}
.selfie-badge-tag {
    font-size: 10.5px;
    font-weight: 800;
    background: rgba(56, 189, 248, 0.18);
    color: #38bdf8;
    padding: 3px 10px;
    border-radius: 20px;
    border: 1px solid rgba(56, 189, 248, 0.3);
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.selfie-indicators-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.indicator-box {
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.indicator-tag {
    font-weight: 800;
    font-size: 10.5px;
    padding: 3px 8px;
    border-radius: 6px;
    white-space: nowrap;
}

.camera-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
    width: 100%;
}
.camera-viewport {
    position: relative;
    width: 100%;
    max-width: 360px;
    aspect-ratio: 4 / 3;
    background: #0f172a;
    border-radius: 16px;
    overflow: hidden;
    border: 2px solid #cbd5e1;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
}
.camera-btn-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
}
.btn-cam-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    min-height: 42px;
    transition: all 0.2s ease;
}

.btn-submit-attendance {
    width: 100%;
    padding: 13px;
    font-size: 13.5px;
    font-weight: 800;
    border-radius: 12px;
    border: none;
    cursor: not-allowed;
    background: #cbd5e1;
    color: #475569;
    letter-spacing: 0.3px;
    min-height: 46px;
    transition: all 0.25s ease;
    word-break: break-word;
}

/* MAIN CONTENT GRID */
.profile-content-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 20px;
    align-items: start;
}

/* CONTENT CARD */
.content-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
}
.card-header-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.card-header-title {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* DATA DIRI LIST */
.info-list {
    display: flex;
    flex-direction: column;
}
.info-item {
    display: flex;
    padding: 13px 20px;
    border-bottom: 1px solid #f1f5f9;
    align-items: flex-start;
    gap: 12px;
    transition: background 0.15s ease;
}
.info-item:last-child {
    border-bottom: none;
}
.info-item:hover {
    background: #f8fafc;
}
.info-key {
    width: 120px;
    flex-shrink: 0;
    font-size: 12.5px;
    font-weight: 700;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 6px;
}
.info-val {
    flex: 1;
    font-size: 13px;
    font-weight: 600;
    color: #0f172a;
    word-break: break-word;
    line-height: 1.5;
}

/* FORM EDIT STYLES */
.form-container {
    padding: 20px;
}
.form-section-title {
    font-size: 11.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #475569;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.avatar-upload-box {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: #f8fafc;
    border: 1.5px dashed #cbd5e1;
    border-radius: 12px;
    transition: all 0.2s ease;
    margin-bottom: 20px;
}
.avatar-upload-box:hover {
    border-color: #2563eb;
    background: #eff6ff;
}
.avatar-preview-thumb {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #ffffff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.1);
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 800;
    color: #64748b;
    flex-shrink: 0;
    overflow: hidden;
}

.file-input-custom {
    font-size: 12.5px;
    color: #334155;
    flex: 1;
    min-width: 0;
}
.file-input-custom input[type=file] {
    display: block;
    width: 100%;
    font-size: 12px;
    color: #475569;
}
.file-input-custom input[type=file]::file-selector-button {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    margin-right: 10px;
    transition: all 0.2s ease;
}
.file-input-custom input[type=file]::file-selector-button:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 14px;
}
.form-group-custom {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}
.form-group-custom label {
    font-size: 12px;
    font-weight: 700;
    color: #334155;
}
.input-custom {
    padding: 9px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 9px;
    font-size: 13px;
    color: #0f172a;
    background: #ffffff;
    transition: all 0.2s ease;
    font-family: inherit;
    width: 100%;
    box-sizing: border-box;
}
.input-custom:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

/* BUTTONS */
.btn-submit-custom {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    padding: 9px 20px;
    border-radius: 9px;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    transition: all 0.2s ease;
}
.btn-submit-custom:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
}
.btn-reset-custom {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f1f5f9;
    color: #475569;
    font-weight: 700;
    font-size: 13px;
    padding: 9px 16px;
    border-radius: 9px;
    border: 1px solid #cbd5e1;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-reset-custom:hover {
    background: #e2e8f0;
    color: #1e293b;
}

/* TOAST ALERTS */
.toast-alert {
    padding: 12px 18px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
}
.toast-alert-success {
    background: #f0fdf4;
    border-left: 4px solid #10b981;
    color: #166534;
}
.toast-alert-error {
    background: #fef2f2;
    border-left: 4px solid #ef4444;
    color: #991b1b;
}

/* RESPONSIVE MOBILE FIXES */
@media (max-width: 992px) {
    .profile-content-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .profile-wrapper { gap: 16px; margin-bottom: 20px; }
    .hero-card { padding: 20px 16px; border-radius: 16px; }
    .hero-main { gap: 16px; }
    .hero-profile-info { gap: 14px; min-width: 0; width: 100%; }
    .hero-avatar-wrap { width: 76px; height: 76px; }
    .hero-avatar-img, .hero-avatar-initials { width: 76px; height: 76px; font-size: 28px; }
    .hero-stats-grid { width: 100%; min-width: 0; grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .stat-card { padding: 10px 12px; }
    .stat-number { font-size: 18px; }
    .hero-attendance-bar { flex-direction: column; align-items: stretch; gap: 10px; margin-top: 16px; padding-top: 14px; }
    .attendance-pill-group { flex-direction: column; align-items: stretch; gap: 6px; }
    .attendance-pill { justify-content: space-between; width: 100%; }
    .attendance-date { justify-content: center; }
    .card-header-bar { padding: 14px 16px; }
    .info-item { padding: 10px 14px; }
    .info-key { width: 100px; font-size: 11.5px; }
    .info-val { font-size: 12.5px; }
    .form-container { padding: 16px; }
    .avatar-upload-box { flex-direction: column; text-align: center; padding: 12px; }
}
@media (max-width: 480px) {
    .hero-profile-info { flex-direction: column; text-align: center; }
    .hero-details { align-items: center; text-align: center; }
    .hero-tags { justify-content: center; }
    .selfie-indicators-grid { grid-template-columns: 1fr; }
    .camera-btn-group { flex-direction: column; width: 100%; }
    .btn-cam-action { width: 100%; }
    .info-item { flex-direction: column; gap: 3px; }
    .info-key { width: 100%; }
}
</style>

<div class="profile-wrapper">

    <!-- TOAST NOTIFICATIONS -->
    <?php if (!empty($pesan_sukses)): ?>
        <div class="toast-alert toast-alert-success">
            <span style="font-size:10.5px; font-weight:900; background:#10b981; color:#fff; padding:2px 6px; border-radius:4px;">SUKSES</span>
            <span><?php echo $pesan_sukses; ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div class="toast-alert toast-alert-error">
            <span style="font-size:10.5px; font-weight:900; background:#ef4444; color:#fff; padding:2px 6px; border-radius:4px;">ERROR</span>
            <span><?php echo $pesan_error; ?></span>
        </div>
    <?php endif; ?>

    <?php if (empty($pin) || !$detail): ?>
        <!-- EMPTY / UNLINKED ACCOUNT STATE -->
        <div class="content-card" style="text-align:center; padding:50px 20px;">
            <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin-bottom:8px;">Data Profil Tidak Ditemukan</h3>
            <p style="color:#64748b; font-size:13.5px; max-width:440px; margin:0 auto; line-height:1.6;">
                Akun <code><?php echo h($_SESSION['username'] ?? 'User'); ?></code> belum terhubung ke data master karyawan. Silakan hubungi Administrator untuk penautan PIN Karyawan.
            </p>
        </div>
    <?php else: ?>

        <!-- HERO BANNER CARD -->
        <div class="hero-card">
            <div class="hero-main">

                <!-- Left Avatar & Bio -->
                <div class="hero-profile-info">
                    <div class="hero-avatar-wrap">
                        <?php if (!empty($detail['foto']) && file_exists(__DIR__ . '/' . $detail['foto'])): ?>
                            <img src="<?php echo h($detail['foto']); ?>?v=<?php echo time(); ?>" id="heroAvatarImg" alt="Foto Profil" class="hero-avatar-img">
                        <?php else: ?>
                            <div class="hero-avatar-initials" id="heroAvatarInitials">
                                <?php echo strtoupper(mb_substr($detail['nama'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div class="hero-status-ping" title="Status Akun Aktif"></div>
                    </div>

                    <div class="hero-details">
                        <div class="hero-subtitle">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                            <span><?php echo $detail['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?></span>
                        </div>
                        <h1 class="hero-name"><?php echo h($detail['nama']); ?></h1>
                        <div class="hero-tags">
                            <span class="hero-tag hero-tag-dept">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                                <?php echo h($detail['departemen'] ?: 'Umum'); ?>
                            </span>
                            <span class="hero-tag hero-tag-pin">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                PIN: <?php echo h($pin); ?>
                            </span>
                            <?php if ($usia_str): ?>
                            <span class="hero-tag hero-tag-age">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <?php echo $usia_str; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Monthly Stats Grid -->
                <div class="hero-stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(16,185,129,0.2); color:#4ade80;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div class="stat-number" style="color:#4ade80;"><?php echo $rekap_bulan['hadir']; ?></div>
                        <div class="stat-label">Hadir</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(245,158,11,0.2); color:#fbbf24;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="stat-number" style="color:#fbbf24;"><?php echo $rekap_bulan['izin']; ?></div>
                        <div class="stat-label">Izin</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(244,63,94,0.2); color:#f87171;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        </div>
                        <div class="stat-number" style="color:#f87171;"><?php echo $rekap_bulan['sakit']; ?></div>
                        <div class="stat-label">Sakit</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(14,165,233,0.2); color:#38bdf8;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <div class="stat-number" style="color:#38bdf8;"><?php echo $rekap_bulan['cuti']; ?></div>
                        <div class="stat-label">Cuti</div>
                    </div>
                </div>

            </div>

            <!-- Attendance Ribbon for Today -->
            <div class="hero-attendance-bar">
                <div class="attendance-pill-group">
                    <div class="attendance-pill">
                        <div class="attendance-dot <?php echo $absen_today['masuk'] ? 'active' : 'inactive'; ?>"></div>
                        <span style="color:#94a3b8;">Absen Masuk:</span>
                        <strong style="color:#ffffff; font-weight:700;"><?php echo $absen_today['masuk'] ?: 'Belum Absen'; ?></strong>
                    </div>
                    <div class="attendance-pill">
                        <div class="attendance-dot <?php echo $absen_today['pulang'] ? 'active' : 'inactive'; ?>"></div>
                        <span style="color:#94a3b8;">Absen Pulang:</span>
                        <strong style="color:#ffffff; font-weight:700;"><?php echo $absen_today['pulang'] ?: 'Belum Absen'; ?></strong>
                    </div>
                </div>
                <div class="attendance-date">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span><?php echo date('l, d F Y'); ?></span>
                </div>
            </div>
        </div>

        <!-- BANNER PINTASAN ABSEN MANDIRI -->
        <?php if (!empty($pin)): ?>
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-radius: 16px; padding: 20px 24px; color: #fff; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; box-shadow: 0 4px 18px rgba(15, 23, 42, 0.12); border: 1px solid rgba(255, 255, 255, 0.08); margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.3); color: #38bdf8; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </div>
                <div>
                    <div style="font-size: 14.5px; font-weight: 800; color: #ffffff;">Fitur Absen Mandiri via Selfie Web</div>
                    <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">Lupa absen di mesin fisik? Silakan gunakan menu Absen Mandiri dengan verifikasi AI &amp; GPS.</div>
                </div>
            </div>
            <a href="absen_mandiri.php<?php echo !is_user_role() ? '?pin=' . urlencode($pin) : ''; ?>" style="display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; padding: 10px 18px; border-radius: 10px; box-shadow: 0 4px 12px rgba(37,99,235,0.3); transition: all 0.2s ease;">
                <span>Buka Absen Mandiri</span>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
            </a>
        </div>
        <?php endif; ?>

        <!-- MAIN TWO-COLUMN SECTION -->
        <div class="profile-content-grid">

            <!-- LEFT COLUMN: READONLY PROFILE INFORMATION -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-header-title">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Informasi Pegawai</span>
                    </div>
                </div>

                <div class="info-list">
                    <div class="info-item">
                        <div class="info-key">PIN Pegawai</div>
                        <div class="info-val">
                            <span style="background:#f1f5f9; border:1px solid #e2e8f0; padding:2px 8px; border-radius:6px; font-family:monospace; font-weight:700; color:#0f172a;">
                                <?php echo h($detail['pin']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-key">Nama Lengkap</div>
                        <div class="info-val"><?php echo h($detail['nama']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-key">Departemen</div>
                        <div class="info-val"><?php echo h($detail['departemen'] ?: '-'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-key">Status Pegawai</div>
                        <div class="info-val">
                            <span style="background:<?php echo $detail['tipe'] === 'guru' ? '#eff6ff' : '#f8fafc'; ?>; color:<?php echo $detail['tipe'] === 'guru' ? '#1d4ed8' : '#334155'; ?>; border:1px solid <?php echo $detail['tipe'] === 'guru' ? '#bfdbfe' : '#cbd5e1'; ?>; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">
                                <?php echo $detail['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-key">No. Telepon / WA</div>
                        <div class="info-val"><?php echo h($detail['no_hp'] ?: '-'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-key">TTL</div>
                        <div class="info-val">
                            <?php
                            $ttl = [];
                            if (!empty($detail['tempat_lahir'])) $ttl[] = $detail['tempat_lahir'];
                            if (!empty($detail['tanggal_lahir'])) $ttl[] = date('d F Y', strtotime($detail['tanggal_lahir']));
                            echo !empty($ttl) ? h(implode(', ', $ttl)) : '-';
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-key">Alamat Rumah</div>
                        <div class="info-val"><?php echo h($detail['alamat'] ?: '-'); ?></div>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: FORM EDIT PROFIL MANDIRI -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-header-title">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <span>Perbarui Data Diri</span>
                    </div>
                </div>

                <form method="POST" action="user_profile.php<?php echo !is_user_role() ? '?pin=' . urlencode($pin) : ''; ?>" enctype="multipart/form-data" class="form-container">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profil_mandiri">
                    <input type="hidden" name="target_pin" value="<?php echo h($pin); ?>">

                    <!-- UPLOAD FOTO PROFIL AREA -->
                    <div class="form-section-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        <span>Foto Profil</span>
                    </div>
                    <div class="avatar-upload-box">
                        <div class="avatar-preview-thumb" id="avatarPreviewContainer">
                            <?php if (!empty($detail['foto']) && file_exists(__DIR__ . '/' . $detail['foto'])): ?>
                                <img src="<?php echo h($detail['foto']); ?>" id="avatarPreviewImg" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <span id="avatarPreviewInitials"><?php echo strtoupper(mb_substr($detail['nama'], 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="file-input-custom">
                            <input type="file" id="foto_profil" name="foto_profil" accept="image/jpeg,image/png,image/webp" onchange="previewSelectedImage(this)">
                            <div style="font-size:11.5px; color:#64748b; margin-top:5px; line-height:1.4;">
                                Format JPG, PNG, WEBP (Maks 2MB).
                            </div>
                        </div>
                    </div>

                    <div style="height:1px; background:#f1f5f9; margin-bottom:18px;"></div>

                    <!-- FORM INPUTS GRID -->
                    <div class="form-section-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Kontak &amp; Data Diri</span>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group-custom">
                            <label for="no_hp">Nomor Telepon / WhatsApp</label>
                            <input type="text" id="no_hp" name="no_hp" class="input-custom" value="<?php echo h($detail['no_hp'] ?? ''); ?>" placeholder="Contoh: 08123456789">
                        </div>
                        <div class="form-group-custom">
                            <label for="tempat_lahir">Tempat Lahir</label>
                            <input type="text" id="tempat_lahir" name="tempat_lahir" class="input-custom" value="<?php echo h($detail['tempat_lahir'] ?? ''); ?>" placeholder="Kota kelahiran">
                        </div>
                        <div class="form-group-custom">
                            <label for="tanggal_lahir">Tanggal Lahir</label>
                            <input type="date" id="tanggal_lahir" name="tanggal_lahir" class="input-custom" value="<?php echo h($detail['tanggal_lahir'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group-custom" style="margin-bottom:20px;">
                        <label for="alamat">Alamat Tempat Tinggal</label>
                        <textarea id="alamat" name="alamat" rows="3" class="input-custom" style="resize:vertical; line-height:1.5;" placeholder="Jl. ... No. ... RT/RW ... Kel. ... Kec. ... Kota/Kab. ..."><?php echo h($detail['alamat'] ?? ''); ?></textarea>
                    </div>

                    <!-- FORM FOOTER BUTTONS -->
                    <div style="display:flex; justify-content:flex-end; gap:10px; padding-top:14px; border-top:1px solid #f1f5f9;">
                        <button type="reset" class="btn-reset-custom">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 2v6h6"/><path d="M2.5 13a9 9 0 1 0 3-7.7L2.5 8"/></svg>
                            <span>Reset</span>
                        </button>
                        <button type="submit" class="btn-submit-custom">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <span>Simpan Perubahan</span>
                        </button>
                    </div>
                </form>
            </div>

        </div>

    <?php endif; ?>

</div>

<script>
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
