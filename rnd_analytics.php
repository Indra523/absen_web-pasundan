<?php
// ============================================================
// MODUL FITUR: RnD ANALYTICS & TOLERANSI JAM KERJA
// Akses: Superadmin (Full Access/Edit), RnD (Read-Only)
// Admin: Ditolak / Blocked
// ============================================================

require_once __DIR__ . '/layout.php';

if (!can_access_page('rnd_analytics')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$pesan_sukses = '';
$pesan_error  = '';

// --- 1. PROSES SIMPAN PENGATURAN JAM KERJA & ABSEN SELFIE (SUPERADMIN ONLY) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    csrf_verify();
    
    if (!is_superadmin()) {
        $pesan_error = "Akses Ditolak: Anda berada dalam mode Read-Only (RnD). Pengubahan konfigurasi hanya dapat dilakukan oleh Superadmin.";
    } else {
        $jam_masuk            = trim($_POST['jam_masuk'] ?? '07:00');
        $jam_toleransi        = trim($_POST['jam_toleransi'] ?? '07:15');
        $jam_pulang           = trim($_POST['jam_pulang'] ?? '15:00');
        $allowed_wifi_subnets = trim($_POST['allowed_wifi_subnets'] ?? '172.16., 192.168., 127.0.0.1, ::1');
        $school_latitude      = trim($_POST['school_latitude'] ?? '-6.91750000');
        $school_longitude     = trim($_POST['school_longitude'] ?? '107.61910000');
        $gps_radius_meters    = (int)($_POST['gps_radius_meters'] ?? 100);

        $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        
        $sets = [
            'jam_masuk'            => $jam_masuk,
            'jam_toleransi'        => $jam_toleransi,
            'jam_pulang'           => $jam_pulang,
            'allowed_wifi_subnets' => $allowed_wifi_subnets,
            'school_latitude'      => $school_latitude,
            'school_longitude'     => $school_longitude,
            'gps_radius_meters'    => (string)$gps_radius_meters
        ];

        foreach ($sets as $k => $v) {
            $stmt->bind_param("ss", $k, $v);
            $stmt->execute();
        }

        $pesan_sukses = "Konfigurasi jam kerja, segmen IP Wi-Fi, dan parameter Geolocation GPS sekolah berhasil diperbarui.";
    }
}

// --- 2. AMBIL APP SETTINGS DARI DATABASE ---
$settings             = get_app_settings();
$jam_masuk            = $settings['jam_masuk'] ?? '07:00';
$jam_toleransi        = $settings['jam_toleransi'] ?? '07:15';
$jam_pulang           = $settings['jam_pulang'] ?? '15:00';
$allowed_wifi_subnets = $settings['allowed_wifi_subnets'] ?? '172.16., 192.168., 127.0.0.1, ::1';
$school_latitude      = $settings['school_latitude'] ?? '-6.91750000';
$school_longitude     = $settings['school_longitude'] ?? '107.61910000';
$gps_radius_meters    = $settings['gps_radius_meters'] ?? '100';

$today_date    = date('Y-m-d');
$today_label   = date('l, d F Y');

// --- 3. PROSES ANALISIS ABSENSI HARI INI ---
$master = [];
$res_m = $conn->query("SELECT pin, nama, departemen, tipe FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC");
if ($res_m) {
    while ($row = $res_m->fetch_assoc()) {
        $master[$row['pin']] = $row;
    }
}

// Ambil log absen pertama (Check-In) hari ini per PIN
$checkins = [];
$res_l = $conn->query("SELECT pin, MIN(waktu) as min_waktu FROM log_absen WHERE DATE(waktu) = '$today_date' GROUP BY pin");
if ($res_l) {
    while ($row = $res_l->fetch_assoc()) {
        $checkins[$row['pin']] = $row['min_waktu'];
    }
}

// Perhitungan Statistik Real-Time
$total_karyawan  = count($master);
$tepat_waktu     = 0;
$terlambat       = 0;
$belum_absen     = 0;

$dept_stats = [];
$detail_list = [];

$toleransi_timestamp = strtotime("$today_date $jam_toleransi:00");
$masuk_timestamp     = strtotime("$today_date $jam_masuk:00");

foreach ($master as $pin => $emp) {
    $dept = !empty($emp['departemen']) ? $emp['departemen'] : 'Umum / Lainnya';
    if (!isset($dept_stats[$dept])) {
        $dept_stats[$dept] = ['total' => 0, 'tepat' => 0, 'terlambat' => 0, 'belum' => 0];
    }
    $dept_stats[$dept]['total']++;

    if (isset($checkins[$pin])) {
        $waktu_absen = $checkins[$pin];
        $time_absen_ts = strtotime($waktu_absen);
        $jam_absen_str = date('H:i:s', $time_absen_ts);

        if ($time_absen_ts <= $toleransi_timestamp) {
            $status_code = 'tepat';
            $status_label = 'Tepat Waktu';
            $status_badge = "<span class='status-pill status-tepat'><svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><polyline points='20 6 9 17 4 12'/></svg> Tepat Waktu</span>";
            $selisih_text = "Hadir {$jam_absen_str} (Sebelum {$jam_toleransi})";
            $tepat_waktu++;
            $dept_stats[$dept]['tepat']++;
        } else {
            $status_code = 'terlambat';
            $diff_minutes = ceil(($time_absen_ts - $toleransi_timestamp) / 60);
            $status_label = "Terlambat {$diff_minutes}m";
            $status_badge = "<span class='status-pill status-terlambat'><svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><circle cx='12' cy='12' r='10'/><polyline points='12 6 12 12 16 14'/></svg> Terlambat {$diff_minutes}m</span>";
            $selisih_text = "Lewat {$diff_minutes} menit dari batas {$jam_toleransi}";
            $terlambat++;
            $dept_stats[$dept]['terlambat']++;
        }
    } else {
        $status_code = 'belum';
        $status_label = 'Belum Absen';
        $status_badge = "<span class='status-pill status-belum'><svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><circle cx='12' cy='12' r='10'/><line x1='15' y1='9' x2='9' y2='15'/><line x1='9' y1='9' x2='15' y2='15'/></svg> Belum Absen</span>";
        $selisih_text = "Belum ada record scan masuk hari ini";
        $waktu_absen = '-';
        $jam_absen_str = '-';
        $belum_absen++;
        $dept_stats[$dept]['belum']++;
    }

    $detail_list[] = [
        'pin'            => $emp['pin'],
        'nama'           => $emp['nama'],
        'departemen'     => $dept,
        'tipe'           => $emp['tipe'],
        'waktu_absen'    => $waktu_absen,
        'jam_absen'      => $jam_absen_str,
        'status_code'    => $status_code,
        'status_label'   => $status_label,
        'status_badge'   => $status_badge,
        'selisih_text'   => $selisih_text
    ];
}

$persen_tepat = $total_karyawan > 0 ? round(($tepat_waktu / $total_karyawan) * 100, 1) : 0;
$persen_terlambat = $total_karyawan > 0 ? round(($terlambat / $total_karyawan) * 100, 1) : 0;
$persen_belum = $total_karyawan > 0 ? round(($belum_absen / $total_karyawan) * 100, 1) : 0;

render_header("RnD Analytics & Toleransi Jam Kerja", "rnd_analytics");
?>

<style>
/* ===== MODERN RND ANALYTICS STYLES ===== */
.analytics-wrapper {
    display: flex;
    flex-direction: column;
    gap: 22px;
    margin-bottom: 35px;
}

/* HERO BANNER */
.analytics-hero-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0369a1 100%);
    border-radius: 20px;
    padding: 24px 28px;
    color: #ffffff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.25);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.analytics-hero-main {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.analytics-hero-title {
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
    margin-bottom: 4px;
    letter-spacing: -0.3px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.analytics-hero-desc {
    font-size: 12.5px;
    color: #94a3b8;
    max-width: 600px;
    line-height: 1.5;
}
.analytics-hero-badge {
    padding: 6px 14px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* KPI STATS GRID */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
}
.kpi-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 20px 22px;
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
}
.kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.kpi-label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.kpi-value {
    font-size: 32px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
    margin-bottom: 6px;
    font-feature-settings: "tnum";
}
.kpi-sub {
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}
.kpi-progress-bg {
    width: 100%;
    height: 6px;
    background: #f1f5f9;
    border-radius: 99px;
    overflow: hidden;
    margin-top: 14px;
}
.kpi-progress-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.4s ease;
}

/* SETTINGS CARD */
.settings-section-title {
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f1f5f9;
}
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}
.form-help-text {
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.35;
}

/* DEPT BREAKDOWN CARDS */
.dept-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}
.dept-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.dept-name {
    font-size: 13.5px;
    font-weight: 800;
    color: #0f172a;
}
.dept-count {
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 6px;
}
.dept-stacked-bar {
    display: flex;
    height: 8px;
    border-radius: 99px;
    overflow: hidden;
    background: #e2e8f0;
    margin-bottom: 10px;
}
.dept-meta-grid {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11.5px;
    font-weight: 700;
}

/* STATUS PILLS */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
}
.status-tepat {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}
.status-terlambat {
    background: #fff7ed;
    color: #c2410c;
    border: 1px solid #fed7aa;
}
.status-belum {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

/* FILTER PILLS TABS */
.filter-tabs {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.filter-tab-btn {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 6px 14px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.filter-tab-btn:hover {
    background: #eff6ff;
    color: #2563eb;
    border-color: #bfdbfe;
}
.filter-tab-btn.active {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.2);
}
</style>

<div class="analytics-wrapper">

    <!-- NOTIFIKASI PESAN SUKSES / ERROR -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:14px 18px; border-radius:12px; font-weight:700; font-size:13px; display:flex; align-items:center; gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?php echo $pesan_sukses; ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:14px 18px; border-radius:12px; font-weight:700; font-size:13px; display:flex; align-items:center; gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <span><?php echo $pesan_error; ?></span>
        </div>
    <?php endif; ?>

    <!-- HERO BANNER -->
    <div class="analytics-hero-card">
        <div class="analytics-hero-main">
            <div>
                <div class="analytics-hero-title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2.3"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    <span>RnD Analytics &amp; Toleransi Jam Kerja</span>
                </div>
                <div class="analytics-hero-desc">
                    Pemantauan analitik ketepatan waktu presensi real-time, toleransi keterlambatan, dan konfigurasi verifikasi absensi mobile SMK Pasundan 2 Bandung.
                </div>
            </div>
            <div>
                <?php if (is_rnd()): ?>
                    <span class="analytics-hero-badge" style="background:rgba(56, 189, 248, 0.18); color:#38bdf8; border:1px solid rgba(56, 189, 248, 0.35);">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        RND READ-ONLY
                    </span>
                <?php else: ?>
                    <span class="analytics-hero-badge" style="background:rgba(168, 85, 247, 0.2); color:#c084fc; border:1px solid rgba(168, 85, 247, 0.35);">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        SUPERADMIN FULL CONTROL
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 1. EXECUTIVE KPI CARDS -->
    <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div style="font-size:14px; font-weight:800; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px;">
                Statistik Presensi Hari Ini
            </div>
            <div style="font-size:12px; color:#64748b; font-weight:700;">
                <?php echo $today_label; ?>
            </div>
        </div>

        <div class="kpi-grid">
            <!-- Card 1: Total Terdaftar -->
            <div class="kpi-card" style="border-top: 4px solid #0284c7;">
                <div class="kpi-header">
                    <span class="kpi-label">Total Terdaftar</span>
                    <div class="kpi-icon" style="background:#e0f2fe; color:#0284c7;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                </div>
                <div class="kpi-value"><?php echo $total_karyawan; ?></div>
                <div class="kpi-sub">Guru &amp; Tenaga Kependidikan</div>
                <div class="kpi-progress-bg">
                    <div class="kpi-progress-fill" style="width:100%; background:#0284c7;"></div>
                </div>
            </div>

            <!-- Card 2: Tepat Waktu -->
            <div class="kpi-card" style="border-top: 4px solid #10b981;">
                <div class="kpi-header">
                    <span class="kpi-label" style="color:#047857;">Tepat Waktu</span>
                    <div class="kpi-icon" style="background:#ecfdf5; color:#059669;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                </div>
                <div class="kpi-value" style="color:#047857;"><?php echo $tepat_waktu; ?></div>
                <div class="kpi-sub"><?php echo $persen_tepat; ?>% dari total pegawai</div>
                <div class="kpi-progress-bg">
                    <div class="kpi-progress-fill" style="width:<?php echo $persen_tepat; ?>%; background:#10b981;"></div>
                </div>
            </div>

            <!-- Card 3: Terlambat -->
            <div class="kpi-card" style="border-top: 4px solid #f97316;">
                <div class="kpi-header">
                    <span class="kpi-label" style="color:#c2410c;">Hadir Terlambat</span>
                    <div class="kpi-icon" style="background:#fff7ed; color:#ea580c;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>
                <div class="kpi-value" style="color:#c2410c;"><?php echo $terlambat; ?></div>
                <div class="kpi-sub"><?php echo $persen_terlambat; ?>% dari total pegawai</div>
                <div class="kpi-progress-bg">
                    <div class="kpi-progress-fill" style="width:<?php echo $persen_terlambat; ?>%; background:#f97316;"></div>
                </div>
            </div>

            <!-- Card 4: Belum Absen -->
            <div class="kpi-card" style="border-top: 4px solid #ef4444;">
                <div class="kpi-header">
                    <span class="kpi-label" style="color:#b91c1c;">Belum Absen Masuk</span>
                    <div class="kpi-icon" style="background:#fef2f2; color:#dc2626;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                </div>
                <div class="kpi-value" style="color:#b91c1c;"><?php echo $belum_absen; ?></div>
                <div class="kpi-sub"><?php echo $persen_belum; ?>% dari total pegawai</div>
                <div class="kpi-progress-bg">
                    <div class="kpi-progress-fill" style="width:<?php echo $persen_belum; ?>%; background:#ef4444;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. PENGATURAN SYSTEM JAM KERJA & GEOLOCATION (CARD) -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Pengaturan Toleransi Jam Kerja &amp; Geolocation</span>
            </div>
            <div>
                <?php if (is_rnd()): ?>
                    <span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:11px;">Mode Lihat Saja</span>
                <?php else: ?>
                    <span class="badge" style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:11px;">Dapat Diubah</span>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="rnd_analytics.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="save_settings" value="1">

            <!-- SUB-SECTION 1: JAM KERJA -->
            <div class="settings-section-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span>Parameter Waktu Kerja Harian</span>
            </div>

            <div class="settings-grid" style="margin-bottom:24px;">
                <div>
                    <label for="jam_masuk">Jam Masuk Standar</label>
                    <input type="time" id="jam_masuk" name="jam_masuk" value="<?php echo h($jam_masuk); ?>" <?php echo is_rnd() ? 'disabled' : ''; ?> required>
                    <div class="form-help-text">Waktu resmi mulainya kehadiran tepat waktu.</div>
                </div>

                <div>
                    <label for="jam_toleransi">Batas Akhir Toleransi</label>
                    <input type="time" id="jam_toleransi" name="jam_toleransi" value="<?php echo h($jam_toleransi); ?>" <?php echo is_rnd() ? 'disabled' : ''; ?> required>
                    <div class="form-help-text">Presensi setelah waktu ini dicatat sebagai terlambat.</div>
                </div>

                <div>
                    <label for="jam_pulang">Jam Pulang Standar</label>
                    <input type="time" id="jam_pulang" name="jam_pulang" value="<?php echo h($jam_pulang); ?>" <?php echo is_rnd() ? 'disabled' : ''; ?> required>
                    <div class="form-help-text">Batas minimal scan pulang pegawai.</div>
                </div>
            </div>

            <!-- SUB-SECTION 2: GEOLOCATION & WI-FI -->
            <div class="settings-section-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span>Parameter Absen Selfie, Wi-Fi Sekolah &amp; Geolocation GPS</span>
            </div>

            <div class="settings-grid">
                <div style="grid-column: span 2;">
                    <label for="allowed_wifi_subnets">Segmen IP Wi-Fi Lokal Sekolah (Pisahkan Koma)</label>
                    <input type="text" id="allowed_wifi_subnets" name="allowed_wifi_subnets" value="<?php echo h($allowed_wifi_subnets); ?>" placeholder="Contoh: 172.16., 192.168.1., 127.0.0.1" <?php echo is_rnd() ? 'disabled' : ''; ?> required style="width:100%;">
                    <div class="form-help-text">Awalan IP yang diizinkan untuk verifikasi Wi-Fi (contoh: <code>172.16.</code> mencakup semua <code>172.16.x.x</code>).</div>
                </div>

                <div>
                    <label for="school_latitude">Latitude GPS Sekolah</label>
                    <input type="text" id="school_latitude" name="school_latitude" value="<?php echo h($school_latitude); ?>" placeholder="-6.91750000" <?php echo is_rnd() ? 'disabled' : ''; ?> required style="width:100%;">
                    <div class="form-help-text">Koordinat Latitude titik pusat sekolah.</div>
                </div>

                <div>
                    <label for="school_longitude">Longitude GPS Sekolah</label>
                    <input type="text" id="school_longitude" name="school_longitude" value="<?php echo h($school_longitude); ?>" placeholder="107.61910000" <?php echo is_rnd() ? 'disabled' : ''; ?> required style="width:100%;">
                    <div class="form-help-text">Koordinat Longitude titik pusat sekolah.</div>
                </div>

                <div>
                    <label for="gps_radius_meters">Radius Toleransi GPS (Meter)</label>
                    <input type="number" id="gps_radius_meters" name="gps_radius_meters" value="<?php echo h($gps_radius_meters); ?>" placeholder="100" min="10" max="5000" <?php echo is_rnd() ? 'disabled' : ''; ?> required style="width:100%;">
                    <div class="form-help-text">Jarak maksimal kehadiran dari titik pusat sekolah.</div>
                </div>
            </div>

            <?php if (is_superadmin()): ?>
                <div style="margin-top:24px; padding-top:16px; border-top:1px solid #f1f5f9; text-align:right;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 22px; font-weight:800; box-shadow:0 4px 12px rgba(37,99,235,0.25);">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <span>Simpan Konfigurasi Presensi</span>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- 3. DEPARTEMEN BREAKDOWN -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                <span>Analisis Ketepatan Waktu per Departemen / Unit</span>
            </div>
            <span style="font-size:12px; color:#64748b; font-weight:700;"><?php echo count($dept_stats); ?> Unit Terdaftar</span>
        </div>
        
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px;">
            <?php foreach ($dept_stats as $d_name => $d_data): 
                $d_tot = $d_data['total'];
                $d_tepat_p = $d_tot > 0 ? round(($d_data['tepat'] / $d_tot) * 100) : 0;
                $d_terlambat_p = $d_tot > 0 ? round(($d_data['terlambat'] / $d_tot) * 100) : 0;
                $d_belum_p = $d_tot > 0 ? round(($d_data['belum'] / $d_tot) * 100) : 0;
            ?>
                <div class="dept-card">
                    <div class="dept-header">
                        <span class="dept-name"><?php echo h($d_name); ?></span>
                        <span class="dept-count"><?php echo $d_tot; ?> Pegawai</span>
                    </div>
                    
                    <div class="dept-stacked-bar">
                        <div style="width:<?php echo $d_tepat_p; ?>%; background:#10b981;" title="Tepat Waktu: <?php echo $d_data['tepat']; ?> (<?php echo $d_tepat_p; ?>%)"></div>
                        <div style="width:<?php echo $d_terlambat_p; ?>%; background:#f97316;" title="Terlambat: <?php echo $d_data['terlambat']; ?> (<?php echo $d_terlambat_p; ?>%)"></div>
                        <div style="width:<?php echo $d_belum_p; ?>%; background:#ef4444;" title="Belum Absen: <?php echo $d_data['belum']; ?> (<?php echo $d_belum_p; ?>%)"></div>
                    </div>

                    <div class="dept-meta-grid">
                        <span style="color:#047857;">Tepat: <strong><?php echo $d_data['tepat']; ?></strong></span>
                        <span style="color:#c2410c;">Terlambat: <strong><?php echo $d_data['terlambat']; ?></strong></span>
                        <span style="color:#b91c1c;">Belum: <strong><?php echo $d_data['belum']; ?></strong></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 4. TABEL DETAIL ANALISIS KETERLAMBATAN REAL-TIME -->
    <div class="card">
        <div class="card-header" style="flex-wrap:wrap; gap:14px;">
            <div class="card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Tabel Detail Presensi &amp; Keterlambatan Real-Time</span>
                <span class="badge" id="badgeTotalFiltered" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-size:11.5px;"><?php echo count($detail_list); ?> Data</span>
            </div>
            
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <button type="button" class="filter-tab-btn active" data-filter="semua" onclick="setFilterStatus('semua')">Semua (<?php echo $total_karyawan; ?>)</button>
                    <button type="button" class="filter-tab-btn" data-filter="tepat" onclick="setFilterStatus('tepat')" style="color:#047857;">Tepat (<?php echo $tepat_waktu; ?>)</button>
                    <button type="button" class="filter-tab-btn" data-filter="terlambat" onclick="setFilterStatus('terlambat')" style="color:#c2410c;">Terlambat (<?php echo $terlambat; ?>)</button>
                    <button type="button" class="filter-tab-btn" data-filter="belum" onclick="setFilterStatus('belum')" style="color:#b91c1c;">Belum (<?php echo $belum_absen; ?>)</button>
                </div>

                <!-- Search Input -->
                <div style="position:relative;">
                    <input type="text" id="search-table" placeholder="Cari nama / PIN / dept..." style="width:200px; margin-bottom:0; padding:7px 12px 7px 30px; font-size:12.5px; border-radius:10px;" onkeyup="filterTable()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table id="table-detail">
                <thead>
                    <tr>
                        <th style="width:50px;">No</th>
                        <th style="width:80px;">PIN</th>
                        <th style="text-align:left;">Nama Guru &amp; Karyawan</th>
                        <th style="text-align:left;">Departemen</th>
                        <th style="width:100px;">Tipe</th>
                        <th style="width:130px;">Jam Absen Masuk</th>
                        <th style="width:140px;">Status Presensi</th>
                        <th style="text-align:left;">Analisis Selisih Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = 1;
                    foreach ($detail_list as $row):
                    ?>
                    <tr data-status="<?php echo $row['status_code']; ?>" data-text="<?php echo h(strtolower($row['pin'] . ' ' . $row['nama'] . ' ' . $row['departemen'])); ?>">
                        <td style="font-weight:700; color:#64748b;"><?php echo $no++; ?></td>
                        <td><code><?php echo h($row['pin']); ?></code></td>
                        <td style="text-align:left;">
                            <div style="font-weight:800; color:#0f172a; font-size:13px;"><?php echo h($row['nama']); ?></div>
                        </td>
                        <td style="text-align:left; font-size:12.5px; color:#475569; font-weight:600;">
                            <?php echo h($row['departemen'] ?: '-'); ?>
                        </td>
                        <td>
                            <span class="badge" style="<?php echo $row['tipe'] === 'guru' ? 'background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe;' : 'background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'; ?> font-size:11px; font-weight:700;">
                                <?php echo ucfirst($row['tipe']); ?>
                            </span>
                        </td>
                        <td>
                            <strong style="font-family:monospace; font-size:13px; color:#0f172a;"><?php echo $row['jam_absen']; ?></strong>
                        </td>
                        <td><?php echo $row['status_badge']; ?></td>
                        <td style="text-align:left; font-size:12px; color:#475569; font-weight:600;">
                            <?php echo h($row['selisih_text']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
let currentStatusFilter = 'semua';

function setFilterStatus(status) {
    currentStatusFilter = status;
    document.querySelectorAll('.filter-tab-btn').forEach(btn => {
        if (btn.getAttribute('data-filter') === status) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    filterTable();
}

function filterTable() {
    const query = document.getElementById('search-table').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#table-detail tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        const text = row.getAttribute('data-text') || '';
        const status = row.getAttribute('data-status') || '';

        const matchQuery = text.includes(query);
        const matchStatus = (currentStatusFilter === 'semua' || status === currentStatusFilter);

        if (matchQuery && matchStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const badgeTotal = document.getElementById('badgeTotalFiltered');
    if (badgeTotal) {
        badgeTotal.textContent = visibleCount + ' Data';
    }
}
</script>

<?php
render_footer();
?>
