<?php
// ============================================================
// PORTAL MANDIRI - ABSEN SELFIE MANDIRI (AI WAJAH + GPS + WI-FI)
// Akses: Role User (Karyawan/Guru) & Superadmin/RnD/Admin/Staff/Tatausaha
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('absen_mandiri') && !can_access_page('user_profile')) {
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

// --- PROSES ABSEN SELFIE MANDIRI USER (KAMERA + GPS + WI-FI) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_absen_selfie') {
    csrf_verify();

    $user_pin = get_user_pin();
    if (empty($user_pin) && (is_superadmin() || is_rnd() || is_admin())) {
        $user_pin = trim($_POST['target_pin'] ?? $pin);
    }

    if (empty($user_pin)) {
        $pesan_error = "Akun Anda tidak terhubung dengan PIN Karyawan. Absen gagal.";
    } else {
        // 1. Cek Wi-Fi Sekolah (IP Check)
        $client_ip = get_client_real_ip();

        if (!is_school_wifi($client_ip)) {
            $pesan_error = "<b>Absen Gagal (Wi-Fi Tidak Sesuai):</b> Anda harus terhubung ke jaringan Wi-Fi lokal sekolah. (IP Anda: <code>" . h($client_ip) . "</code>)";
        } else {
            // 2. Cek Geolocation GPS Radius
            $settings   = get_app_settings();
            $school_lat = (float)($settings['school_latitude'] ?? -6.90652863);
            $school_lng = (float)($settings['school_longitude'] ?? 107.57195250);
            $max_radius = (float)($settings['gps_radius_meters'] ?? 4000);

            $user_lat = (float)($_POST['latitude'] ?? 0);
            $user_lng = (float)($_POST['longitude'] ?? 0);

            // Jika diakses via HTTP di mana Chrome memblokir Geolocation API, gunakan koordinat sekolah karena IP Wi-Fi sudah valid
            if ($user_lat == 0 || $user_lng == 0) {
                $user_lat = $school_lat;
                $user_lng = $school_lng;
            }

            $dist_meters = haversine_distance($school_lat, $school_lng, $user_lat, $user_lng);

            if ($dist_meters > $max_radius) {
                $pesan_error = "<b>Absen Gagal (Di Luar Radius):</b> Jarak Anda <b>{$dist_meters} Meter</b> dari area sekolah (Batas Maksimal: <b>{$max_radius} Meter</b>).";
            } else {
                // 3. Simpan Foto Selfie Base64
                $selfie_b64 = $_POST['selfie_image'] ?? '';
                if (empty($selfie_b64) || strpos($selfie_b64, 'data:image') !== 0) {
                    $pesan_error = "<b>Absen Gagal:</b> Foto selfie belum diambil atau tidak valid.";
                } else {
                    list($type_str, $data_str) = explode(';', $selfie_b64);
                    list(, $data_str)          = explode(',', $data_str);
                    $image_data                = base64_decode($data_str);

                    if ($image_data === false) {
                        $pesan_error = "<b>Absen Gagal:</b> Gagal memproses gambar selfie.";
                    } else {
                        $dir_month  = date('Y-m');
                        $base_selfie_dir = __DIR__ . "/uploads/selfie/";
                        if (!is_dir($base_selfie_dir)) {
                            @mkdir($base_selfie_dir, 0777, true);
                            @chmod($base_selfie_dir, 0777);
                        }

                        $upload_dir = $base_selfie_dir . "{$dir_month}/";
                        if (!is_dir($upload_dir)) {
                            @mkdir($upload_dir, 0777, true);
                            @chmod($upload_dir, 0777);
                        }

                        $filename    = "selfie_" . preg_replace('/[^a-zA-Z0-9]/', '', $user_pin) . "_" . date('Ymd_His') . ".jpg";
                        $save_path   = $upload_dir . $filename;
                        $db_rel_path = "uploads/selfie/{$dir_month}/" . $filename;

                        $saved = @file_put_contents($save_path, $image_data);
                        if ($saved === false) {
                            $save_path_fallback = $base_selfie_dir . $filename;
                            $saved_fallback = @file_put_contents($save_path_fallback, $image_data);
                            if ($saved_fallback !== false) {
                                $db_rel_path = "uploads/selfie/" . $filename;
                                @chmod($save_path_fallback, 0666);
                            } else {
                                $pesan_error = "Gagal menyimpan foto selfie di server. Periksa izin folder uploads.";
                            }
                        } else {
                            @chmod($save_path, 0666);
                        }

                        if (empty($pesan_error)) {
                            // 4. Tentukan Status Absen (0 = Masuk, 1 = Pulang)
                            $tgl_today = date('Y-m-d');
                            $stmt_c = $conn->prepare("SELECT status FROM log_absen WHERE pin = ? AND DATE(waktu) = ? ORDER BY waktu ASC");
                            $stmt_c->bind_param("ss", $user_pin, $tgl_today);
                            $stmt_c->execute();
                            $res_c = $stmt_c->get_result();

                            $status_absen = 0; // Default Masuk
                            if ($res_c->num_rows > 0) {
                                $status_absen = 1; // Jika sudah pernah absen hari ini, maka Pulang
                            }

                            $stmt_ins = $conn->prepare("INSERT INTO log_absen (pin, waktu, status, tipe_verifikasi, foto_selfie, latitude, longitude, ip_address) VALUES (?, NOW(), ?, 'SELFIE', ?, ?, ?, ?)");
                            $stmt_ins->bind_param("sisdds", $user_pin, $status_absen, $db_rel_path, $user_lat, $user_lng, $client_ip);

                            if ($stmt_ins->execute()) {
                                $st_label = ($status_absen === 0) ? 'MASUK' : 'PULANG';
                                $pesan_sukses = "<b>Absen {$st_label} Berhasil!</b> Foto selfie &amp; koordinat GPS terverifikasi (Jarak: <b>{$dist_meters}m</b> | IP Wi-Fi: <code>" . h($client_ip) . "</code>).";
                                log_audit("ABSEN_SELFIE_USER", "Absen {$st_label} PIN {$user_pin} via Selfie Web (Jarak: {$dist_meters}m, Lat: {$user_lat}, Lng: {$user_lng}, IP: {$client_ip})");
                            } else {
                                $pesan_error = "Gagal menyimpan data absensi: " . $conn->error;
                            }
                        }
                    }
                }
            }
        }
    }
}

$detail      = null;
$absen_today = ['masuk' => null, 'pulang' => null];

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
    }
}

// Konfigurasi Absensi Selfie Mandiri & Verifikasi Lokasi
$app_settings       = get_app_settings();
$school_lat         = (float)($app_settings['school_latitude'] ?? -6.90652863);
$school_lng         = (float)($app_settings['school_longitude'] ?? 107.57195250);
$max_radius         = (float)($app_settings['gps_radius_meters'] ?? 4000);
$client_ip          = get_client_real_ip();
$is_wifi_valid      = is_school_wifi($client_ip);
$next_absen_status  = ($absen_today['masuk'] === null) ? 'Masuk' : 'Pulang';

render_header("Absen Mandiri", "absen_mandiri");
?>

<!-- MULTI-ENGINE OFFLINE AI: TENSORFLOW.JS BLAZEFACE & PICO.JS -->
<script src="assets/js/tf.min.js"></script>
<script src="assets/js/blazeface.min.js"></script>
<script src="assets/js/pico_face.js"></script>

<style>
/* ===== ABSEN MANDIRI STYLES ===== */
.mandiri-wrapper {
    display: flex;
    flex-direction: column;
    gap: 20px;
    max-width: 900px;
    margin: 0 auto;
    width: 100%;
}

.mandiri-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
    border-radius: 18px;
    padding: 24px 28px;
    color: #ffffff;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.mandiri-hero-title {
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.mandiri-hero-sub {
    font-size: 12.5px;
    color: #94a3b8;
    line-height: 1.5;
}

.mandiri-user-chip {
    background: rgba(255, 255, 255, 0.07);
    border: 1px solid rgba(255, 255, 255, 0.12);
    padding: 10px 16px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.mandiri-chip-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #2563eb;
    color: #fff;
    font-weight: 800;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.2);
}

.mandiri-chip-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.mandiri-chip-name {
    font-size: 13.5px;
    font-weight: 700;
    color: #f8fafc;
}

.mandiri-chip-pin {
    font-size: 11px;
    color: #38bdf8;
    font-family: monospace;
    font-weight: 600;
}

/* STATUS TODAY CARD */
.today-status-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}

.today-stat-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 18px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
    display: flex;
    align-items: center;
    gap: 14px;
}

.today-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.today-stat-label {
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.today-stat-val {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    margin-top: 2px;
}

/* MAIN CAMERA CARD */
.camera-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
    overflow: hidden;
}

.camera-card-header {
    background: #f8fafc;
    padding: 16px 22px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.camera-card-title {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

.selfie-indicators-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
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
    max-width: 380px;
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

.face-guide-wrapper {
    position: absolute;
    inset: 0;
    pointer-events: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

.face-guide-oval {
    width: 62%;
    height: 78%;
    border-radius: 50% / 50%;
    border: 3.5px dashed rgba(255, 255, 255, 0.7);
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.55);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.face-guide-oval.valid {
    border-color: #10b981;
    border-style: solid;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.35), 0 0 20px rgba(16, 185, 129, 0.7);
}

.face-guide-oval.warning {
    border-color: #f59e0b;
    border-style: dashed;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.5), 0 0 15px rgba(245, 158, 11, 0.5);
}

.face-guide-oval.invalid {
    border-color: #ef4444;
    border-style: dashed;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.55), 0 0 15px rgba(239, 68, 68, 0.5);
}

.face-status-pill {
    position: absolute;
    bottom: 12px;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(6px);
    color: #ffffff;
    font-size: 11.5px;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    display: flex;
    align-items: center;
    gap: 8px;
    text-align: center;
    max-width: 90%;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.face-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
    flex-shrink: 0;
}

.face-status-dot.valid { background: #10b981; box-shadow: 0 0 8px #10b981; }
.face-status-dot.warning { background: #f59e0b; box-shadow: 0 0 8px #f59e0b; }
.face-status-dot.invalid { background: #ef4444; box-shadow: 0 0 8px #ef4444; }

.camera-scanning-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(4px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 20;
    color: #ffffff;
    text-align: center;
    padding: 20px;
}

.scanning-spinner {
    width: 44px;
    height: 44px;
    border: 4px solid rgba(255, 255, 255, 0.2);
    border-top-color: #38bdf8;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 12px;
}

.camera-btn-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
}

.btn-cam-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 20px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    min-height: 44px;
    transition: all 0.2s ease;
}

.btn-submit-attendance {
    width: 100%;
    padding: 14px;
    font-size: 14px;
    font-weight: 800;
    letter-spacing: 0.5px;
    border: none;
    border-radius: 12px;
    cursor: not-allowed;
    background: #cbd5e1;
    color: #475569;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* ============================================================ */
/* MODAL POPUP PENJELASAN ABSEN MANDIRI (ANIMASI TANDA SERU !)   */
/* ============================================================ */
.mandiri-notice-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.7);
    backdrop-filter: blur(5px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: noticeFadeIn 0.25s ease-out;
}

@keyframes noticeFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.mandiri-notice-card {
    background: #ffffff;
    border-radius: 20px;
    max-width: 440px;
    width: 100%;
    padding: 30px 26px 26px 26px;
    text-align: center;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);
    border: 1px solid #e2e8f0;
    position: relative;
    animation: noticeCardIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes noticeCardIn {
    0% { transform: scale(0.9) translateY(18px); opacity: 0; }
    100% { transform: scale(1) translateY(0); opacity: 1; }
}

.notice-exclamation-wrap {
    position: relative;
    width: 76px;
    height: 76px;
    margin: 0 auto 20px auto;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notice-pulse-ring {
    position: absolute;
    inset: -6px;
    border-radius: 50%;
    background: rgba(37, 99, 235, 0.15);
    animation: noticePulseRing 2s infinite cubic-bezier(0.4, 0, 0.6, 1);
}

@keyframes noticePulseRing {
    0% { transform: scale(0.92); opacity: 0.8; }
    50% { transform: scale(1.15); opacity: 0.3; }
    100% { transform: scale(1.28); opacity: 0; }
}

.notice-circle-icon {
    position: relative;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
}

.notice-title {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 12px;
}

.notice-desc {
    font-size: 13.5px;
    color: #475569;
    line-height: 1.6;
    margin-bottom: 24px;
}

.btn-notice-confirm {
    width: 100%;
    padding: 13px;
    font-size: 13.5px;
    font-weight: 800;
    color: #ffffff;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border: none;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
    transition: all 0.2s ease;
}

.btn-notice-confirm:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.4);
}

/* REJECT MODAL */
.reject-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(4px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: noticeFadeIn 0.25s ease-out;
}

.reject-modal-card {
    background: #ffffff;
    border-radius: 20px;
    max-width: 440px;
    width: 100%;
    padding: 28px 24px;
    text-align: center;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.3);
    animation: noticeCardIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.reject-icon-wrapper {
    position: relative;
    width: 68px;
    height: 68px;
    margin: 0 auto 16px auto;
    display: flex;
    align-items: center;
    justify-content: center;
}

.reject-icon-circle {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: #fee2e2;
    color: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 18px rgba(239, 68, 68, 0.25);
}

.reject-svg-cross {
    width: 32px;
    height: 32px;
    stroke: #ef4444;
    stroke-width: 3;
    stroke-linecap: round;
}

.reject-modal-title {
    font-size: 18px;
    font-weight: 800;
    color: #991b1b;
    margin-bottom: 8px;
}

.reject-modal-reason {
    font-size: 13px;
    color: #475569;
    line-height: 1.5;
    margin-bottom: 18px;
}

.reject-tips-list {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
    font-size: 12px;
    color: #334155;
}

.btn-reject-retry {
    width: 100%;
    padding: 12px;
    background: #0f172a;
    color: #ffffff;
    font-size: 13px;
    font-weight: 800;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }
</style>

<div class="mandiri-wrapper">

    <!-- ALERT PESAN SUKSES / ERROR -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:14px 18px; color:#15803d; font-size:13px; font-weight:600; display:flex; align-items:center; gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; border-radius:12px; padding:14px 18px; color:#991b1b; font-size:13px; font-weight:600; display:flex; align-items:center; gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
        </div>
    <?php endif; ?>

    <!-- HERO HEADER CARD -->
    <div class="mandiri-hero">
        <div>
            <div class="mandiri-hero-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <span>Portal Absen Mandiri</span>
            </div>
            <div class="mandiri-hero-sub">
                Layanan presensi mandiri berbasis verifikasi foto wajah AI, koordinat GPS sekolah, dan koneksi Wi-Fi resmi.
            </div>
        </div>

        <?php if ($detail): ?>
        <div class="mandiri-user-chip">
            <div class="mandiri-chip-avatar">
                <?php if (!empty($detail['foto']) && file_exists(__DIR__ . '/' . $detail['foto'])): ?>
                    <img src="<?php echo h($detail['foto']); ?>" alt="Foto">
                <?php else: ?>
                    <?php echo strtoupper(mb_substr($detail['nama'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="mandiri-chip-name"><?php echo h($detail['nama']); ?></div>
                <div class="mandiri-chip-pin">PIN: <?php echo h($detail['pin']); ?> &bull; <?php echo h($detail['tipe'] === 'guru' ? 'Guru' : 'Karyawan'); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- STRIP STATUS ABSEN HARI INI -->
    <div class="today-status-strip">
        <div class="today-stat-box">
            <div class="today-stat-icon" style="background:#eff6ff; color:#2563eb;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            </div>
            <div>
                <div class="today-stat-label">Absen Masuk Hari Ini</div>
                <div class="today-stat-val"><?php echo $absen_today['masuk'] ? h($absen_today['masuk']) . ' WIB' : '<span style="color:#94a3b8; font-weight:600; font-size:14px;">Belum Absen</span>'; ?></div>
            </div>
        </div>

        <div class="today-stat-box">
            <div class="today-stat-icon" style="background:#fef2f2; color:#dc2626;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </div>
            <div>
                <div class="today-stat-label">Absen Pulang Hari Ini</div>
                <div class="today-stat-val"><?php echo $absen_today['pulang'] ? h($absen_today['pulang']) . ' WIB' : '<span style="color:#94a3b8; font-weight:600; font-size:14px;">Belum Absen</span>'; ?></div>
            </div>
        </div>

        <div class="today-stat-box">
            <div class="today-stat-icon" style="background:#f0fdf4; color:#16a34a;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="today-stat-label">Target Absen Berikutnya</div>
                <div class="today-stat-val" style="color:<?php echo $next_absen_status === 'Masuk' ? '#2563eb' : '#dc2626'; ?>;">
                    Absen <?php echo $next_absen_status; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN CAMERA CARD -->
    <div class="camera-card">
        <div class="camera-card-header">
            <div class="camera-card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <span>Pemindai Wajah &amp; Verifikasi Presensi</span>
            </div>
            <button type="button" onclick="openMandiriNoticeModal()" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:11.5px; font-weight:700; padding:4px 12px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>Petunjuk Absen</span>
            </button>
        </div>

        <div style="padding: 20px;">
            <!-- NOTIFIKASI HTTPS UNTUK KAMERA LIVE WEBRTC -->
            <div id="https-banner-notice" style="display:none; background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:10px 14px; margin-bottom:14px; align-items:center; justify-content:space-between; gap:10px; font-size:12px; color:#1e40af; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>Gunakan <strong>HTTPS</strong> untuk mengaktifkan <strong>Kamera Live AI + Panduan Oval</strong> di browser HP.</span>
                </div>
                <a href="https://<?php echo $_SERVER['HTTP_HOST'] ?? 'attendance-pas2.my.id'; ?>/absen_mandiri.php" style="background:#2563eb; color:#fff; font-weight:700; text-decoration:none; padding:5px 12px; border-radius:8px; white-space:nowrap; font-size:11px;">Buka Versi HTTPS</a>
            </div>
            <script>
            if (window.location.protocol === 'http:') {
                document.addEventListener('DOMContentLoaded', function() {
                    var banner = document.getElementById('https-banner-notice');
                    if (banner) banner.style.display = 'flex';
                });
            }
            </script>

            <!-- BADGE INDIKATOR WI-FI, GPS, & AI WAJAH -->
            <div class="selfie-indicators-grid">
                <!-- STATUS WI-FI -->
                <div class="indicator-box" style="<?php echo $is_wifi_valid ? 'background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d;' : 'background:#fff1f2; border:1px solid #fca5a5; color:#991b1b;'; ?>">
                    <span class="indicator-tag" style="background:<?php echo $is_wifi_valid ? '#dcfce7; color:#166534;' : '#fee2e2; color:#991b1b;'; ?>">
                        <?php echo $is_wifi_valid ? 'TERHUBUNG' : 'DITOLAK'; ?>
                    </span>
                    <div style="min-width:0;">
                        <div>Wi-Fi Sekolah</div>
                        <div style="font-size:11px; font-weight:600; opacity:0.85; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?php echo $is_wifi_valid ? 'IP: ' . h($client_ip) : 'Bukan Wi-Fi (IP: ' . h($client_ip) . ')'; ?>
                        </div>
                    </div>
                </div>

                <!-- STATUS GPS -->
                <div id="gps-status-box" class="indicator-box" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8;">
                    <span id="gps-badge-tag" class="indicator-tag" style="background:#dbeafe; color:#1e40af;">
                        GPS
                    </span>
                    <div style="min-width:0;">
                        <div id="gps-title-txt">Mendeteksi GPS...</div>
                        <div style="font-size:11px; font-weight:600; opacity:0.85; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="gps-sub-txt">Buka kamera untuk cek lokasi</div>
                    </div>
                </div>

                <!-- STATUS AI FACE DETECTION -->
                <div id="ai-status-box" class="indicator-box" style="background:#fffbeb; border:1px solid #fde68a; color:#b45309;">
                    <span id="ai-badge-tag" class="indicator-tag" style="background:#fef3c7; color:#92400e;">
                        AI WAJAH
                    </span>
                    <div style="min-width:0;">
                        <div id="ai-title-txt">Deteksi Wajah AI</div>
                        <div style="font-size:11px; font-weight:600; opacity:0.85; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="ai-sub-txt">Buka kamera untuk memindai</div>
                    </div>
                </div>
            </div>

            <!-- FORM ABSEN SELFIE -->
            <form method="POST" action="absen_mandiri.php" id="form-selfie-absen">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="submit_absen_selfie">
                <input type="hidden" name="target_pin" value="<?php echo h($pin); ?>">
                <input type="hidden" name="latitude" id="input_latitude" value="0">
                <input type="hidden" name="longitude" id="input_longitude" value="0">
                <input type="hidden" name="selfie_image" id="input_selfie_image" value="">

                <!-- FALLBACK NATIVE HP CAMERA INPUT -->
                <input type="file" id="fileCameraInput" accept="image/*" capture="user" style="display:none;" onchange="handleNativeCameraFile(this)">

                <div class="camera-stage">
                    <!-- CAMERA / PREVIEW CONTAINER -->
                    <div class="camera-viewport">
                        <video id="selfieVideo" autoplay playsinline style="width:100%; height:100%; object-fit:cover; transform: scaleX(-1); display:none;"></video>
                        <canvas id="selfieCanvas" style="display:none;"></canvas>
                        <img id="selfiePreview" style="width:100%; height:100%; object-fit:cover; display:none;">

                        <!-- FACE GUIDE OVAL OVERLAY -->
                        <div class="face-guide-wrapper" id="faceGuideWrapper" style="display:none;">
                            <div class="face-guide-oval" id="faceGuideOval"></div>
                            <div class="face-status-pill" id="faceStatusPill">
                                <span class="face-status-dot" id="faceStatusDot"></span>
                                <span id="faceStatusText">Mencari Wajah...</span>
                            </div>
                        </div>

                        <!-- AI SCANNING & PROCESSING OVERLAY -->
                        <div class="camera-scanning-overlay" id="cameraScanningOverlay" style="display:none;">
                            <div class="scanning-spinner"></div>
                            <div style="font-size:14px; font-weight:800; margin-bottom:4px;" id="scanningTitle">Memindai Wajah AI...</div>
                            <div style="font-size:12px; color:#cbd5e1;" id="scanningSub">Mohon tunggu, AI sedang menganalisis foto wajah...</div>
                        </div>

                        <div id="cameraPlaceholder" style="text-align:center; color:#94a3b8; padding:20px;">
                            <div style="font-size:14px; font-weight:800; color:#e2e8f0; margin-bottom:4px;">Kamera Belum Aktif</div>
                            <div style="font-size:11.5px; color:#94a3b8;">Klik tombol di bawah untuk membuka kamera</div>
                        </div>
                    </div>

                    <!-- CONTROLS & SNAP BUTTONS -->
                    <div class="camera-btn-group">
                        <button type="button" id="btnOpenCam" class="btn-cam-action" onclick="startSelfieCamera()" style="background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; box-shadow:0 4px 12px rgba(37,99,235,0.25);">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            <span>Buka Kamera &amp; Deteksi Lokasi</span>
                        </button>
                        <button type="button" id="btnSnapPhoto" class="btn-cam-action" onclick="takeSelfieSnap()" style="background:linear-gradient(135deg,#059669,#047857); color:#fff; display:none; opacity:0.4; pointer-events:none; box-shadow:0 4px 12px rgba(5,150,105,0.25);">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                            <span>Ambil Foto Selfie</span>
                        </button>
                        <button type="button" id="btnRetakePhoto" class="btn-cam-action" onclick="retakeSelfiePhoto()" style="background:#475569; color:#fff; display:none;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M2.5 2v6h6"/><path d="M2.5 13a9 9 0 1 0 3-7.7L2.5 8"/></svg>
                            <span>Foto Ulang</span>
                        </button>
                    </div>
                </div>

                <!-- TOMBOL KIRIM ABSEN -->
                <button type="submit" id="btnSubmitAbsen" class="btn-submit-attendance" disabled>
                    Lengkapi Wi-Fi, GPS &amp; Foto Selfie Wajah
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL POPUP INFORMASI ABSEN MANDIRI (ANIMASI TANDA SERU !)   -->
<!-- ============================================================ -->
<div class="mandiri-notice-modal-overlay" id="mandiriNoticeModal">
    <div class="mandiri-notice-card" onclick="event.stopPropagation()">
        <!-- ANIMATED EXCLAMATION ICON (NO EMOJI) -->
        <div class="notice-exclamation-wrap">
            <div class="notice-pulse-ring"></div>
            <div class="notice-circle-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="7" x2="12" y2="13"></line>
                    <circle cx="12" cy="17" r="1.2" fill="#ffffff"></circle>
                </svg>
            </div>
        </div>

        <h3 class="notice-title">Informasi Absen Mandiri</h3>
        <p class="notice-desc">
            Ini adalah menu Absen mandiri. Apabila bapak/ibu lupa absen di mesin, silahkan absen disini
        </p>

        <button type="button" class="btn-notice-confirm" onclick="closeMandiriNoticeModal()">
            OK
        </button>
    </div>
</div>

<!-- MODAL ANIMASI FOTO DITOLAK (REJECTION POPUP) -->
<div class="reject-modal-overlay" id="rejectModalOverlay" onclick="closeRejectModal(event)">
    <div class="reject-modal-card" onclick="event.stopPropagation()">
        <div class="reject-icon-wrapper">
            <div class="reject-icon-circle">
                <svg class="reject-svg-cross" viewBox="0 0 24 24" fill="none">
                    <path d="M18 6L6 18"></path>
                    <path d="M6 6l12 12"></path>
                </svg>
            </div>
        </div>

        <div class="reject-modal-title">Foto Selfie Ditolak!</div>
        <div class="reject-modal-reason" id="rejectModalReason">
            Wajah tidak terdeteksi di dalam frame kamera.
        </div>

        <div class="reject-tips-list">
            <div>&bull; <strong>Posisikan Wajah:</strong> Arahkan seluruh wajah tepat di dalam bingkai oval hingga berwarna hijau.</div>
            <div>&bull; <strong>Pencahayaan:</strong> Pastikan ruangan cukup terang dan wajah tidak tertutup masker/benda.</div>
            <div>&bull; <strong>Foto Mandiri:</strong> Hanya boleh 1 orang dalam foto (dilarang foto bersama).</div>
        </div>

        <button type="button" class="btn-reject-retry" onclick="closeRejectModal()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M2.5 2v6h6"/><path d="M2.5 13a9 9 0 1 0 3-7.7L2.5 8"/></svg>
            <span>Coba Ambil Foto Ulang</span>
        </button>
    </div>
</div>

<script>
const SCHOOL_LAT = <?php echo $school_lat; ?>;
const SCHOOL_LNG = <?php echo $school_lng; ?>;
const MAX_RADIUS = <?php echo $max_radius; ?>;
const IS_WIFI_OK = <?php echo $is_wifi_valid ? 'true' : 'false'; ?>;
const NEXT_STATUS = "<?php echo strtoupper($next_absen_status); ?>";

let videoStream = null;
let blazefaceModel = null;
let isBlazeFaceLoading = false;
let isFaceDetectionRunning = false;
let lastFaceValid = false;
let consecutiveValidFaceFrames = 0;
let faceDetectorInstance = null;

function openMandiriNoticeModal() {
    const modal = document.getElementById('mandiriNoticeModal');
    if (modal) modal.style.display = 'flex';
}

function closeMandiriNoticeModal() {
    const modal = document.getElementById('mandiriNoticeModal');
    if (modal) modal.style.display = 'none';
    try {
        sessionStorage.setItem('absen_mandiri_notice_seen', '1');
    } catch (e) {}
}

if (navigator.mediaDevices === undefined) {
    navigator.mediaDevices = {};
}
if (navigator.mediaDevices.getUserMedia === undefined) {
    navigator.mediaDevices.getUserMedia = function(constraints) {
        const getUserMedia = navigator.webkitGetUserMedia || navigator.mozGetUserMedia || navigator.msGetUserMedia || navigator.getUserMedia;
        if (!getUserMedia) {
            return Promise.reject(new Error('GETUSERMEDIA_NOT_SUPPORTED'));
        }
        return new Promise(function(resolve, reject) {
            getUserMedia.call(navigator, constraints, resolve, reject);
        });
    }
}

function initFaceDetectors() {
    // 1. Native Android/Chrome Shape Detection
    if ('FaceDetector' in window) {
        try {
            faceDetectorInstance = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 3 });
        } catch (e) {
            console.warn('Native FaceDetector error:', e);
        }
    }

    // 2. BlazeFace Deep Neural Network Offline Model
    loadBlazeFace();
}

async function loadBlazeFace() {
    if (blazefaceModel) return blazefaceModel;
    if (isBlazeFaceLoading) return null;
    isBlazeFaceLoading = true;
    try {
        if (typeof blazeface !== 'undefined') {
            blazefaceModel = await blazeface.load({
                modelUrl: 'assets/models/blazeface/model.json'
            });
            console.log('BlazeFace AI offline neural network ready.');
        }
    } catch (err) {
        console.warn('BlazeFace load error:', err);
    }
    isBlazeFaceLoading = false;
    return blazefaceModel;
}

async function detectFacesOnSource(source, customMinScore) {
    let faces = [];

    // 1. Primary: BlazeFace Deep Neural Network (Google ML)
    if (blazefaceModel) {
        try {
            const predictions = await blazefaceModel.estimateFaces(source, false);
            if (predictions && predictions.length > 0) {
                return predictions.map(p => {
                    const pScore = p.probability ? (Array.isArray(p.probability) ? p.probability[0] : p.probability) : 0.99;
                    return {
                        topLeft: p.topLeft,
                        bottomRight: p.bottomRight,
                        width: p.bottomRight[0] - p.topLeft[0],
                        height: p.bottomRight[1] - p.topLeft[1],
                        landmarks: p.landmarks,
                        score: pScore * 100
                    };
                });
            }
        } catch (e) {
            // Ignore frame prediction error
        }
    }

    // 2. Secondary: Native Chrome / Android Hardware FaceDetector API
    if (faceDetectorInstance) {
        try {
            const detected = await faceDetectorInstance.detect(source);
            if (detected && detected.length > 0) {
                return detected.map(f => ({
                    topLeft: [f.boundingBox.x, f.boundingBox.y],
                    bottomRight: [f.boundingBox.x + f.boundingBox.width, f.boundingBox.y + f.boundingBox.height],
                    width: f.boundingBox.width,
                    height: f.boundingBox.height,
                    score: 95.0
                }));
            }
        } catch (e) {
            // Ignore
        }
    }

    // 3. Fallback: Pico.js Instant Cascade
    if (window.PicoFaceDetector && window.PicoFaceDetector.isReady()) {
        try {
            const detected = window.PicoFaceDetector.detect(source, {
                shiftfactor: 0.1,
                minsize: 35,
                maxsize: 800,
                scalefactor: 1.1,
                minScore: (typeof customMinScore === 'number') ? customMinScore : 4.0
            });
            if (detected && detected.length > 0) {
                return detected.map(f => ({
                    topLeft: [f.box.x, f.box.y],
                    bottomRight: [f.box.x + f.box.width, f.box.y + f.box.height],
                    width: f.box.width,
                    height: f.box.height,
                    score: f.score
                }));
            }
        } catch (e) {
            console.warn('Pico detection error:', e);
        }
    }

    return faces;
}

function validateFullFaceCriteria(face, vW, vH) {
    if (!face) return { valid: false, reason: 'Wajah tidak terdeteksi (Arahkan kamera ke wajah Anda)' };

    const x1 = face.topLeft[0];
    const y1 = face.topLeft[1];
    const x2 = face.bottomRight[0];
    const y2 = face.bottomRight[1];
    const fW = x2 - x1;
    const fH = y2 - y1;
    const fcX = (x1 + x2) / 2;
    const fcY = (y1 + y2) / 2;

    const cX = vW / 2;
    const cY = vH / 2;

    // 1. Cek ukuran proporsi wajah di frame (radius diperketat agar pas dengan bingkai oval)
    const fRatioW = fW / vW;
    const fRatioH = fH / vH;
    if (fRatioW < 0.24 || fRatioH < 0.26) {
        return { valid: false, reason: 'Wajah terlalu jauh. Posisikan wajah pas di dalam oval.' };
    }
    if (fRatioW > 0.72 || fRatioH > 0.78) {
        return { valid: false, reason: 'Wajah terlalu dekat. Mundurkan sedikit agar full wajah masuk.' };
    }

    // 2. Cek posisi pusat wajah harus berada di area tengah oval
    if (Math.abs(fcX - cX) > vW * 0.16 || Math.abs(fcY - cY) > vH * 0.18) {
        return { valid: false, reason: 'Posisikan wajah tepat di tengah-tengah lingkaran oval.' };
    }

    // 3. Cek apakah wajah terpotong di tepi kamera
    if (x1 < 0 || y1 < 0 || x2 > vW || y2 > vH) {
        return { valid: false, reason: 'Wajah terpotong di tepi kamera. Posisikan tepat di tengah oval.' };
    }

    // 4. Validasi Landmark Biometrik Wajib (2 mata, hidung, bibir)
    if (face.landmarks && face.landmarks.length >= 4) {
        const rightEye = face.landmarks[0];
        const leftEye  = face.landmarks[1];
        const nose     = face.landmarks[2];
        const mouth    = face.landmarks[3];

        const rx = vW * 0.32; // radius horizontal oval
        const ry = vH * 0.40; // radius vertikal oval

        // A. Pastikan semua titik (2 mata, hidung, bibir) berada DI DALAM OVAL PANDUAN
        const keyPoints = [
            { name: 'Mata Kanan', pt: rightEye },
            { name: 'Mata Kiri',  pt: leftEye },
            { name: 'Hidung',     pt: nose },
            { name: 'Bibir',      pt: mouth }
        ];
        for (let i = 0; i < keyPoints.length; i++) {
            const kp = keyPoints[i];
            const dx = (kp.pt[0] - cX) / rx;
            const dy = (kp.pt[1] - cY) / ry;
            if (dx * dx + dy * dy > 1.0) {
                return { valid: false, reason: kp.name + ' berada di luar oval. Posisikan full wajah di dalam oval.' };
            }
        }

        // B. Pastikan kedua mata terpisah dan sejajar
        const eyeDist = Math.hypot(leftEye[0] - rightEye[0], leftEye[1] - rightEye[1]);
        if (eyeDist < fW * 0.20) {
            return { valid: false, reason: 'Posisikan kedua mata terlihat jelas dan menghadap lurus ke kamera.' };
        }

        // C. Pastikan mata berada di bagian atas oval (di atas hidung)
        const avgEyeY = (rightEye[1] + leftEye[1]) / 2;
        if (nose[1] <= avgEyeY + fH * 0.08) {
            return { valid: false, reason: 'Hidung tidak terdeteksi di bawah mata. Posisikan wajah tegak.' };
        }

        // D. Pastikan bibir/mulut berada di bagian bawah hidung
        if (mouth[1] <= nose[1] + fH * 0.08) {
            return { valid: false, reason: 'Bibir/mulut tidak terlihat. Pastikan wajah bagian bawah masuk oval.' };
        }

        // E. Jarak dari mata ke mulut harus proporsional (minimal 24% tinggi wajah)
        const eyeToMouthDist = mouth[1] - avgEyeY;
        if (eyeToMouthDist < fH * 0.24) {
            return { valid: false, reason: 'Full wajah (mata, hidung, bibir) harus masuk lengkap ke dalam oval.' };
        }
    } else {
        const aspect = fH / fW;
        if (aspect < 1.05 || aspect > 1.6) {
            return { valid: false, reason: 'Posisikan seluruh wajah tegak di dalam oval.' };
        }
    }

    return { valid: true, reason: 'Wajah Lengkap Terverifikasi' };
}

async function runFaceDetectionLoop() {
    if (!isFaceDetectionRunning) return;
    const video = document.getElementById('selfieVideo');
    if (!video || video.paused || video.ended || video.readyState < 2) {
        requestAnimationFrame(runFaceDetectionLoop);
        return;
    }

    const oval = document.getElementById('faceGuideOval');
    const dot = document.getElementById('faceStatusDot');
    const txt = document.getElementById('faceStatusText');
    const btnSnap = document.getElementById('btnSnapPhoto');

    const aiBox = document.getElementById('ai-status-box');
    const aiBadge = document.getElementById('ai-badge-tag');
    const aiTitle = document.getElementById('ai-title-txt');
    const aiSub = document.getElementById('ai-sub-txt');

    const faces = await detectFacesOnSource(video, 4.0);

    if (faces.length === 0) {
        consecutiveValidFaceFrames = 0;
        lastFaceValid = false;
        if (oval) oval.className = 'face-guide-oval invalid';
        if (dot) dot.className = 'face-status-dot invalid';
        if (txt) txt.textContent = 'Wajah tidak terdeteksi (Arahkan ke wajah)';
        if (btnSnap) {
            btnSnap.style.opacity = '0.4';
            btnSnap.style.pointerEvents = 'none';
        }
        if (aiBox) {
            aiBox.style.background = '#fff1f2';
            aiBox.style.borderColor = '#fca5a5';
            aiBox.style.color = '#991b1b';
            aiBadge.style.background = '#fee2e2';
            aiBadge.style.color = '#991b1b';
            aiBadge.textContent = 'TIDAK VALID';
            aiTitle.textContent = 'Wajah Tidak Ditemukan';
            aiSub.textContent = 'Arahkan kamera ke wajah Anda';
        }
    } else if (faces.length > 1) {
        consecutiveValidFaceFrames = 0;
        lastFaceValid = false;
        if (oval) oval.className = 'face-guide-oval invalid';
        if (dot) dot.className = 'face-status-dot invalid';
        if (txt) txt.textContent = 'Terdeteksi lebih dari 1 orang! (Absen mandiri)';
        if (btnSnap) {
            btnSnap.style.opacity = '0.4';
            btnSnap.style.pointerEvents = 'none';
        }
        if (aiBox) {
            aiBox.style.background = '#fff1f2';
            aiBox.style.borderColor = '#fca5a5';
            aiBox.style.color = '#991b1b';
            aiBadge.style.background = '#fee2e2';
            aiBadge.style.color = '#991b1b';
            aiBadge.textContent = 'DITOLAK';
            aiTitle.textContent = 'Lebih Dari 1 Wajah';
            aiSub.textContent = 'Hanya boleh 1 orang dalam foto';
        }
    } else {
        const face = faces[0];
        const vW = video.videoWidth || 640;
        const vH = video.videoHeight || 480;

        const check = validateFullFaceCriteria(face, vW, vH);

        if (!check.valid) {
            consecutiveValidFaceFrames = 0;
            lastFaceValid = false;
            if (oval) oval.className = 'face-guide-oval warning';
            if (dot) dot.className = 'face-status-dot warning';
            if (txt) txt.textContent = check.reason;
            if (btnSnap) {
                btnSnap.style.opacity = '0.4';
                btnSnap.style.pointerEvents = 'none';
            }
            if (aiBox) {
                aiBox.style.background = '#fffbeb';
                aiBox.style.borderColor = '#fde68a';
                aiBox.style.color = '#b45309';
                aiBadge.style.background = '#fef3c7';
                aiBadge.style.color = '#92400e';
                aiBadge.textContent = 'BELUM LENGKAP';
                aiTitle.textContent = 'Posisikan Full Wajah';
                aiSub.textContent = check.reason;
            }
        } else {
            consecutiveValidFaceFrames++;
            if (consecutiveValidFaceFrames >= 1) {
                lastFaceValid = true;
                if (oval) oval.className = 'face-guide-oval valid';
                if (dot) dot.className = 'face-status-dot valid';
                if (txt) txt.textContent = 'Wajah Lengkap Terverifikasi! (2 Mata, Hidung, Bibir Masuk)';
                if (btnSnap) {
                    btnSnap.style.opacity = '1';
                    btnSnap.style.pointerEvents = 'auto';
                }
                if (aiBox) {
                    aiBox.style.background = '#f0fdf4';
                    aiBox.style.borderColor = '#bbf7d0';
                    aiBox.style.color = '#15803d';
                    aiBadge.style.background = '#dcfce7';
                    aiBadge.style.color = '#166534';
                    aiBadge.textContent = 'VALID';
                    aiTitle.textContent = 'Wajah Lengkap Terverifikasi';
                    aiSub.textContent = '2 mata, hidung & bibir terdeteksi sempurna';
                }
            }
        }
    }

    if (isFaceDetectionRunning) {
        setTimeout(() => requestAnimationFrame(runFaceDetectionLoop), 100);
    }
}

async function verifyFaceOnCanvas(canvas) {
    const faces = await detectFacesOnSource(canvas, 3.5);
    if (faces && faces.length === 1) {
        const check = validateFullFaceCriteria(faces[0], canvas.width, canvas.height);
        return check.valid;
    }
    return false;
}

function calcHaversine(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return Math.round(R * c);
}

let gpsWatchId = null;

function applyGPSPosition(position) {
    const box = document.getElementById('gps-status-box');
    const badge = document.getElementById('gps-badge-tag');
    const title = document.getElementById('gps-title-txt');
    const sub = document.getElementById('gps-sub-txt');

    const lat = position.coords.latitude;
    const lng = position.coords.longitude;
    const accuracy = Math.round(position.coords.accuracy || 0);

    document.getElementById('input_latitude').value = lat;
    document.getElementById('input_longitude').value = lng;

    const dist = calcHaversine(SCHOOL_LAT, SCHOOL_LNG, lat, lng);

    if (dist <= MAX_RADIUS) {
        box.style.background = '#f0fdf4';
        box.style.borderColor = '#bbf7d0';
        box.style.color = '#15803d';
        badge.style.background = '#dcfce7';
        badge.style.color = '#166534';
        badge.textContent = 'AKURAT (±' + accuracy + 'm)';
        title.textContent = 'GPS Valid (' + dist + 'm dari sekolah)';
        sub.textContent = 'Akurasi satelit ±' + accuracy + 'm &bull; Radius maks: ' + MAX_RADIUS + 'm';
    } else {
        box.style.background = '#fff1f2';
        box.style.borderColor = '#fca5a5';
        box.style.color = '#991b1b';
        badge.style.background = '#fee2e2';
        badge.style.color = '#991b1b';
        badge.textContent = 'DITOLAK (' + dist + 'm)';
        title.textContent = 'Di Luar Radius Sekolah (' + dist + 'm)';
        sub.textContent = 'Akurasi satelit ±' + accuracy + 'm &bull; Batas radius: ' + MAX_RADIUS + 'm';
    }
    checkSubmitStatus();
}

function handleGPSError(error, isSecondAttempt) {
    const box = document.getElementById('gps-status-box');
    const badge = document.getElementById('gps-badge-tag');
    const title = document.getElementById('gps-title-txt');
    const sub = document.getElementById('gps-sub-txt');

    if (IS_WIFI_OK && (error.code === 1 || !window.isSecureContext)) {
        document.getElementById('input_latitude').value = SCHOOL_LAT;
        document.getElementById('input_longitude').value = SCHOOL_LNG;

        box.style.background = '#f0fdf4';
        box.style.borderColor = '#bbf7d0';
        box.style.color = '#15803d';
        badge.style.background = '#dcfce7';
        badge.style.color = '#166534';
        badge.textContent = 'WI-FI VALID';
        title.textContent = 'Terverifikasi Wi-Fi Sekolah';
        sub.textContent = 'Lokasi valid via IP Wi-Fi lokal';
        checkSubmitStatus();
        return;
    }

    box.style.background = '#fff1f2';
    box.style.borderColor = '#fca5a5';
    box.style.color = '#991b1b';
    badge.style.background = '#fee2e2';
    badge.style.color = '#991b1b';
    badge.textContent = 'ERROR';

    if (error.code === 1) {
        title.textContent = 'Izin Lokasi Ditolak';
        sub.textContent = 'Aktifkan izin GPS di pengaturan browser';
    } else if (!isSecondAttempt) {
        title.textContent = 'Mengunci Satelit GPS...';
        sub.textContent = 'Mengambil sinyal GPS alternatif...';
        navigator.geolocation.getCurrentPosition(
            position => applyGPSPosition(position),
            err2 => handleGPSError(err2, true),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
        return;
    } else if (error.code === 3) {
        title.textContent = 'GPS Timeout';
        sub.textContent = 'Pastikan GPS HP menyala di luar/dekat jendela';
    } else {
        title.textContent = 'GPS Tidak Terbaca';
        sub.textContent = 'Pastikan GPS perangkat aktif';
    }
    checkSubmitStatus();
}

function startGPSDetection() {
    const box = document.getElementById('gps-status-box');
    const title = document.getElementById('gps-title-txt');
    const sub = document.getElementById('gps-sub-txt');

    if (!navigator.geolocation) {
        box.style.background = '#fff1f2';
        box.style.borderColor = '#fca5a5';
        box.style.color = '#991b1b';
        title.textContent = 'GPS Tidak Didukung';
        sub.textContent = 'Gunakan Chrome / Safari / Edge';
        return;
    }

    title.textContent = 'Mengunci Posisi Satelit...';
    sub.textContent = 'Mengukur jarak ke sekolah secara presisi';

    // Bersihkan listener watchPosition sebelumnya jika ada
    if (gpsWatchId !== null) {
        navigator.geolocation.clearWatch(gpsWatchId);
        gpsWatchId = null;
    }

    // Gunakan watchPosition untuk akurasi satelit bertahap yang semakin presisi
    gpsWatchId = navigator.geolocation.watchPosition(
        position => applyGPSPosition(position),
        error => handleGPSError(error, false),
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

function showScanningOverlay(title, sub) {
    const overlay = document.getElementById('cameraScanningOverlay');
    const tEl = document.getElementById('scanningTitle');
    const sEl = document.getElementById('scanningSub');
    if (tEl && title) tEl.textContent = title;
    if (sEl && sub) sEl.textContent = sub;
    if (overlay) overlay.style.display = 'flex';
}

function hideScanningOverlay() {
    const overlay = document.getElementById('cameraScanningOverlay');
    if (overlay) overlay.style.display = 'none';
}

async function startSelfieCamera() {
    const btnOpen = document.getElementById('btnOpenCam');
    if (btnOpen) {
        btnOpen.disabled = true;
        btnOpen.style.pointerEvents = 'none';
        btnOpen.style.opacity = '0.75';
        btnOpen.innerHTML = '<span class="btn-spinner"></span> <span>Memuat AI &amp; Membuka Kamera...</span>';
    }

    startGPSDetection();

    const video = document.getElementById('selfieVideo');
    const placeholder = document.getElementById('cameraPlaceholder');
    const guide = document.getElementById('faceGuideWrapper');
    const btnSnap = document.getElementById('btnSnapPhoto');
    const fileInput = document.getElementById('fileCameraInput');

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        if (btnOpen) {
            btnOpen.disabled = false;
            btnOpen.style.pointerEvents = 'auto';
            btnOpen.style.opacity = '1';
            btnOpen.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> <span>Buka Kamera &amp; Deteksi Lokasi</span>';
        }
        if (window.location.protocol === 'http:') {
            if (confirm('Untuk mengaktifkan Kamera Live In-Browser dengan Panduan Oval Biometrik AI, browser HP mewajibkan koneksi HTTPS yang aman.\n\nKlik OK untuk beralih ke versi HTTPS sekarang? (Pilih Cancel jika ingin memakai kamera HP biasa)')) {
                window.location.href = 'https://' + window.location.host + window.location.pathname + window.location.search;
                return;
            }
        }
        if (fileInput) {
            fileInput.click();
        } else {
            alert('Akses kamera tidak didukung di browser ini.');
        }
        return;
    }

    try {
        videoStream = await navigator.mediaDevices.getUserMedia({
            video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' }
        });
        video.srcObject = videoStream;
        video.style.display = 'block';
        if (guide) guide.style.display = 'flex';
        placeholder.style.display = 'none';
        if (btnOpen) btnOpen.style.display = 'none';
        btnSnap.style.display = 'inline-flex';

        initFaceDetectors();
        isFaceDetectionRunning = true;
        runFaceDetectionLoop();
    } catch (err) {
        console.warn('WebRTC getUserMedia error, falling back to native camera input:', err);
        if (btnOpen) {
            btnOpen.disabled = false;
            btnOpen.style.pointerEvents = 'auto';
            btnOpen.style.opacity = '1';
            btnOpen.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> <span>Buka Kamera &amp; Deteksi Lokasi</span>';
        }
        if (window.location.protocol === 'http:') {
            if (confirm('Kamera Live WebRTC diblokir oleh browser HP pada HTTP biasa.\n\nBuka versi HTTPS (https://' + window.location.host + ') untuk mengaktifkan Kamera Live Oval AI?')) {
                window.location.href = 'https://' + window.location.host + window.location.pathname + window.location.search;
                return;
            }
        }
        if (fileInput) {
            fileInput.click();
        } else {
            alert('Gagal membuka kamera: ' + err.message);
        }
    }
}

function showRejectModal(reason) {
    const overlay = document.getElementById('rejectModalOverlay');
    const reasonEl = document.getElementById('rejectModalReason');
    if (reasonEl) {
        reasonEl.textContent = reason || 'Wajah tidak terdeteksi di dalam frame kamera.';
    }
    if (overlay) {
        overlay.style.display = 'flex';
    }
}

function closeRejectModal(e) {
    if (e && e.target && e.target.closest && e.target.closest('.reject-modal-card') && e.target.tagName !== 'BUTTON') {
        return;
    }
    const overlay = document.getElementById('rejectModalOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
    retakeSelfiePhoto();
}

async function detectFaceMultiOrientation(canvas) {
    let faces = await detectFacesOnSource(canvas, 3.5);
    if (faces && faces.length === 1) {
        const check = validateFullFaceCriteria(faces[0], canvas.width, canvas.height);
        if (check.valid) {
            return { valid: true, faces: faces, angle: 0 };
        }
    }

    const angles = [90, 270, 180];
    for (let i = 0; i < angles.length; i++) {
        const angle = angles[i];
        const rotCanvas = document.createElement('canvas');
        const rotCtx = rotCanvas.getContext('2d');
        if (angle === 90 || angle === 270) {
            rotCanvas.width = canvas.height;
            rotCanvas.height = canvas.width;
        } else {
            rotCanvas.width = canvas.width;
            rotCanvas.height = canvas.height;
        }

        rotCtx.translate(rotCanvas.width / 2, rotCanvas.height / 2);
        rotCtx.rotate((angle * Math.PI) / 180);
        rotCtx.drawImage(canvas, -canvas.width / 2, -canvas.height / 2);

        faces = await detectFacesOnSource(rotCanvas, 3.5);
        if (faces && faces.length === 1) {
            const check = validateFullFaceCriteria(faces[0], rotCanvas.width, rotCanvas.height);
            if (check.valid) {
                canvas.width = rotCanvas.width;
                canvas.height = rotCanvas.height;
                const mainCtx = canvas.getContext('2d');
                mainCtx.drawImage(rotCanvas, 0, 0);
                return { valid: true, faces: faces, angle: angle };
            }
        }
    }

    return { valid: false, faces: [] };
}

async function handleNativeCameraFile(input) {
    if (input.files && input.files[0]) {
        showScanningOverlay('Memeriksa Foto...', 'AI sedang membaca biometrik wajah...');
        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = async function() {
                const canvas = document.getElementById('selfieCanvas');
                const maxDim = 640;
                let w = img.width;
                let h = img.height;

                if (w > maxDim || h > maxDim) {
                    if (w > h) {
                        h = Math.round((h * maxDim) / w);
                        w = maxDim;
                    } else {
                        w = Math.round((w * maxDim) / h);
                        h = maxDim;
                    }
                }

                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, w, h);

                initFaceDetectors();
                const result = await detectFaceMultiOrientation(canvas);
                hideScanningOverlay();

                if (!result.valid) {
                    showRejectModal('Wajah tidak terdeteksi dalam foto yang diambil. Harap posisikan kamera tepat di depan wajah Anda.');
                    input.value = '';
                    return;
                }

                const b64 = canvas.toDataURL('image/jpeg', 0.85);
                
                document.getElementById('input_selfie_image').value = b64;
                const preview = document.getElementById('selfiePreview');
                preview.src = b64;

                document.getElementById('selfieVideo').style.display = 'none';
                document.getElementById('cameraPlaceholder').style.display = 'none';
                preview.style.display = 'block';

                document.getElementById('btnOpenCam').style.display = 'none';
                document.getElementById('btnSnapPhoto').style.display = 'none';
                document.getElementById('btnRetakePhoto').style.display = 'inline-flex';

                const aiBox = document.getElementById('ai-status-box');
                const aiBadge = document.getElementById('ai-badge-tag');
                const aiTitle = document.getElementById('ai-title-txt');
                const aiSub = document.getElementById('ai-sub-txt');
                if (aiBox) {
                    aiBox.style.background = '#f0fdf4';
                    aiBox.style.borderColor = '#bbf7d0';
                    aiBox.style.color = '#15803d';
                    aiBadge.style.background = '#dcfce7';
                    aiBadge.style.color = '#166534';
                    aiBadge.textContent = 'VALID';
                    aiTitle.textContent = 'Wajah Terverifikasi';
                    aiSub.textContent = 'Foto selfie biometrik valid';
                }

                checkSubmitStatus();
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
}

async function takeSelfieSnap() {
    const btnSnap = document.getElementById('btnSnapPhoto');
    if (!lastFaceValid) {
        showRejectModal('Wajah belum terdeteksi sempurna di dalam lingkaran oval. Arahkan wajah ke tengah hingga garis oval berwarna hijau.');
        return;
    }

    if (btnSnap) {
        btnSnap.disabled = true;
        btnSnap.style.pointerEvents = 'none';
        btnSnap.innerHTML = '<span class="btn-spinner"></span> <span>Memproses Biometrik...</span>';
    }
    showScanningOverlay('Memverifikasi Biometrik Wajah...', 'Mohon tunggu, AI sedang menganalisis foto wajah...');

    const video = document.getElementById('selfieVideo');
    const canvas = document.getElementById('selfieCanvas');
    const preview = document.getElementById('selfiePreview');
    const btnRetake = document.getElementById('btnRetakePhoto');
    const inputImg = document.getElementById('input_selfie_image');
    const guide = document.getElementById('faceGuideWrapper');

    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    const ctx = canvas.getContext('2d');
    
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    await new Promise(resolve => setTimeout(resolve, 450));

    const isValidSnapshot = await verifyFaceOnCanvas(canvas);
    hideScanningOverlay();

    if (btnSnap) {
        btnSnap.disabled = false;
        btnSnap.style.pointerEvents = 'auto';
        btnSnap.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg> <span>Ambil Foto Selfie</span>';
    }

    if (!isValidSnapshot) {
        showRejectModal('Wajah tidak terdeteksi dalam jepretan foto. Silakan posisikan wajah Anda kembali di depan kamera.');
        return;
    }

    const b64 = canvas.toDataURL('image/jpeg', 0.85);
    inputImg.value = b64;
    preview.src = b64;

    isFaceDetectionRunning = false;
    video.style.display = 'none';
    if (guide) guide.style.display = 'none';
    preview.style.display = 'block';
    if (btnSnap) btnSnap.style.display = 'none';
    btnRetake.style.display = 'inline-flex';

    checkSubmitStatus();
}

function retakeSelfiePhoto() {
    const video = document.getElementById('selfieVideo');
    const preview = document.getElementById('selfiePreview');
    const btnSnap = document.getElementById('btnSnapPhoto');
    const btnRetake = document.getElementById('btnRetakePhoto');
    const inputImg = document.getElementById('input_selfie_image');
    const guide = document.getElementById('faceGuideWrapper');

    inputImg.value = '';
    preview.src = '';
    preview.style.display = 'none';
    lastFaceValid = false;
    consecutiveValidFaceFrames = 0;

    if (btnSnap) {
        btnSnap.style.opacity = '0.4';
        btnSnap.style.pointerEvents = 'none';
    }

    if (videoStream === null) {
        document.getElementById('cameraPlaceholder').style.display = 'block';
        document.getElementById('btnOpenCam').style.display = 'inline-flex';
        document.getElementById('btnRetakePhoto').style.display = 'none';
    } else {
        video.style.display = 'block';
        if (guide) guide.style.display = 'flex';
        btnSnap.style.display = 'inline-flex';
        btnRetake.style.display = 'none';

        isFaceDetectionRunning = true;
        runFaceDetectionLoop();
    }

    checkSubmitStatus();
}

function checkSubmitStatus() {
    const lat = parseFloat(document.getElementById('input_latitude').value || 0);
    const lng = parseFloat(document.getElementById('input_longitude').value || 0);
    const b64 = document.getElementById('input_selfie_image').value;
    const btnSubmit = document.getElementById('btnSubmitAbsen');

    const dist = calcHaversine(SCHOOL_LAT, SCHOOL_LNG, lat, lng);
    const isGpsOk = (lat !== 0 && lng !== 0 && dist <= MAX_RADIUS);
    const isSelfieOk = (b64 && b64.startsWith('data:image'));

    if (IS_WIFI_OK && isGpsOk && isSelfieOk) {
        btnSubmit.disabled = false;
        btnSubmit.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        btnSubmit.style.color = '#ffffff';
        btnSubmit.style.cursor = 'pointer';
        btnSubmit.style.boxShadow = '0 4px 14px rgba(16, 185, 129, 0.35)';
        btnSubmit.innerHTML = 'KIRIM ABSEN ' + NEXT_STATUS + ' SEKARANG';
    } else {
        btnSubmit.disabled = true;
        btnSubmit.style.background = '#cbd5e1';
        btnSubmit.style.color = '#475569';
        btnSubmit.style.cursor = 'not-allowed';
        btnSubmit.style.boxShadow = 'none';
        
        let missing = [];
        if (!IS_WIFI_OK) missing.push('Wi-Fi');
        if (!isGpsOk) missing.push('GPS');
        if (!isSelfieOk) missing.push('Foto Wajah');
        btnSubmit.innerHTML = 'Lengkapi: ' + missing.join(', ');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initFaceDetectors();

    // Cek apakah baru saja submit absen atau merefresh halaman
    const isPostSubmit = <?php echo ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($pesan_sukses) || !empty($pesan_error)) ? 'true' : 'false'; ?>;
    let hasSeenNotice = false;
    try {
        hasSeenNotice = (sessionStorage.getItem('absen_mandiri_notice_seen') === '1');
    } catch (e) {}

    // Popup HANYA muncul saat pertama kali masuk ke menu Absen Mandiri (bukan saat refresh atau setelah submit absen)
    if (!isPostSubmit && !hasSeenNotice) {
        setTimeout(openMandiriNoticeModal, 150);
    }
});

// Bila user mengklik menu/link lain di navbar/sidebar, hapus flag agar jika masuk kembali ke menu Absen Mandiri pop-up muncul lagi
document.addEventListener('click', function(e) {
    const link = e.target.closest('a');
    if (link && link.href) {
        try {
            const targetUrl = new URL(link.href, window.location.origin);
            if (!targetUrl.pathname.endsWith('absen_mandiri.php')) {
                sessionStorage.removeItem('absen_mandiri_notice_seen');
            }
        } catch (err) {}
    }
});
</script>

<?php render_footer(); ?>
