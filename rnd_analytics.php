<?php
// ============================================================
// MODUL FITUR BARU: RnD ANALYTICS & TOLERANSI JAM KERJA
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

// --- 1. PROSES SIMPAN PENGATURAN JAM KERJA (SUPERADMIN ONLY) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    csrf_verify();
    
    if (!is_superadmin()) {
        $pesan_error = "⛔ <b>Akses Ditolak:</b> Anda berada dalam mode Read-Only (RnD). Pengubahan konfigurasi hanya dapat dilakukan oleh Superadmin.";
    } else {
        $jam_masuk     = trim($_POST['jam_masuk'] ?? '07:00');
        $jam_toleransi = trim($_POST['jam_toleransi'] ?? '07:15');
        $jam_pulang    = trim($_POST['jam_pulang'] ?? '15:00');

        // Validasi format jam HH:MM
        if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $jam_masuk) &&
            preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $jam_toleransi) &&
            preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $jam_pulang)) {

            $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            
            $k1 = 'jam_masuk';     $stmt->bind_param("ss", $k1, $jam_masuk);     $stmt->execute();
            $k2 = 'jam_toleransi'; $stmt->bind_param("ss", $k2, $jam_toleransi); $stmt->execute();
            $k3 = 'jam_pulang';    $stmt->bind_param("ss", $k3, $jam_pulang);    $stmt->execute();

            $pesan_sukses = "✅ <b>Berhasil!</b> Konfigurasi toleransi jam kerja telah diperbarui oleh Superadmin.";
        } else {
            $pesan_error = "Format jam tidak valid. Harap gunakan format HH:MM (contoh: 07:15).";
        }
    }
}

// --- 2. AMBIL APP SETTINGS DARI DATABASE ---
$settings      = get_app_settings();
$jam_masuk     = $settings['jam_masuk'] ?? '07:00';
$jam_toleransi = $settings['jam_toleransi'] ?? '07:15';
$jam_pulang    = $settings['jam_pulang'] ?? '15:00';

$today_date    = date('Y-m-d');
$today_label   = date('d F Y');

// --- 3. PROSES ANALISIS ABSENSI HARI INI ---
// Ambil semua master karyawan
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
    $dept = !empty($emp['departemen']) ? $emp['departemen'] : 'Lainnya';
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
            $status_badge = "<span class='badge' style='background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;'>🟢 Tepat Waktu</span>";
            $selisih_text = "Hadir jam {$jam_absen_str}";
            $tepat_waktu++;
            $dept_stats[$dept]['tepat']++;
        } else {
            $status_code = 'terlambat';
            $diff_minutes = ceil(($time_absen_ts - $toleransi_timestamp) / 60);
            $status_label = "Terlambat {$diff_minutes} M";
            $status_badge = "<span class='badge' style='background:#ffedd5; color:#c2410c; border:1px solid #fed7aa;'>⚠️ Terlambat {$diff_minutes} m</span>";
            $selisih_text = "Lewat {$diff_minutes} menit dari batas {$jam_toleransi}";
            $terlambat++;
            $dept_stats[$dept]['terlambat']++;
        }
    } else {
        $status_code = 'belum';
        $status_label = 'Belum Absen';
        $status_badge = "<span class='badge' style='background:#fee2e2; color:#dc2626; border:1px solid #fca5a5;'>🔴 Belum Absen</span>";
        $selisih_text = "Belum ada record absen masuk hari ini";
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
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.stat-box {
    background: #ffffff;
    border-radius: 16px;
    padding: 20px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08);
}
.stat-box .title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 8px;
}
.stat-box .value {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}
.stat-box .subtitle {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 6px;
    font-weight: 500;
}
.stat-box .corner-icon {
    position: absolute;
    right: 16px;
    top: 16px;
    font-size: 28px;
    opacity: 0.8;
}
.progress-bar-bg {
    width: 100%;
    height: 8px;
    background: #e2e8f0;
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 10px;
}
.progress-bar-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.4s ease;
}
.role-notice-banner {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>

<!-- NOTIFIKASI PESAN SUKSES / ERROR -->
<?php if (!empty($pesan_sukses)): ?>
    <div style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600;">
        <?php echo $pesan_sukses; ?>
    </div>
<?php endif; ?>

<?php if (!empty($pesan_error)): ?>
    <div style="background:#fee2e2; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600;">
        <?php echo $pesan_error; ?>
    </div>
<?php endif; ?>

<!-- BANNER HAK AKSES ROLE -->
<?php if (is_rnd()): ?>
    <div class="role-notice-banner">
        <span style="font-size:24px;">🔬</span>
        <div>
            <div style="font-weight:800; color:#1e40af; font-size:14px;">Mode Riset & Analisis (RnD — Read Only)</div>
            <div style="font-size:12.5px; color:#3b82f6; margin-top:2px;">
                Anda saat ini login sebagai role <b>RnD (Research & Development)</b>. Anda dapat memantau analisis keterlambatan dan statistik absensi real-time. Pengubahan konfigurasi jam kerja khusus dilakukan oleh Superadmin.
            </div>
        </div>
    </div>
<?php else: ?>
    <div style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border:1px solid #e9d5ff; border-radius:14px; padding:16px 20px; margin-bottom:24px; display:flex; align-items:center; gap:14px;">
        <span style="font-size:24px;">👑</span>
        <div>
            <div style="font-weight:800; color:#6b21a8; font-size:14px;">Kontrol Penuh Superadmin & Modul Analytics</div>
            <div style="font-size:12.5px; color:#7c3aed; margin-top:2px;">
                Sebagai <b>Superadmin</b>, Anda memiliki hak akses penuh untuk mengatur jam masuk standar, jam batas toleransi, dan jam pulang. Hasil analisis dapat dipantau oleh tim RnD.
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- 1. PENGATURAN SYSTEM JAM KERJA & TOLERANSI -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <div class="card-title">
            <span>⚙️ Pengaturan Toleransi Jam Kerja System</span>
            <?php if (is_rnd()): ?>
                <span class="badge" style="background:#f3e8ff; color:#6b21a8; border:1px solid #e9d5ff; font-size:11px;">🔒 Read-Only (RnD)</span>
            <?php else: ?>
                <span class="badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:11px;">✏️ Superadmin Editable</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="rnd_analytics.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="save_settings" value="1">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px;">
            <div>
                <label for="jam_masuk">🕒 Jam Masuk Standar</label>
                <input type="time" id="jam_masuk" name="jam_masuk" value="<?php echo h($jam_masuk); ?>" <?php echo is_rnd() ? 'disabled' : ''; ?> required>
                <div style="font-size:11px; color:#64748b; margin-top:-12px;">Waktu awal presensi dianggap tepat waktu</div>
            </div>

            <div>
                <label for="jam_toleransi">⚠️ Batas Toleransi Terlambat</label>
                <input type="time" id="jam_toleransi" name="jam_toleransi" value="<?php echo h($jam_toleransi); ?>" <?php echo is_rnd() ? 'disabled' : ''; ?> required>
                <div style="font-size:11px; color:#64748b; margin-top:-12px;">Absen sesudah jam ini dicatat Terlambat</div>
            </div>

            <div>
                <label for="jam_pulang">🌆 Jam Pulang Standar</label>
                <input type="time" id="jam_pulang" name="jam_pulang" value="<?php echo h($jam_pulang); ?>" <?php echo is_rnd() ? 'disabled' : ''; ?> required>
                <div style="font-size:11px; color:#64748b; margin-top:-12px;">Acuan batas jam pulang karyawan</div>
            </div>
        </div>

        <?php if (is_superadmin()): ?>
            <div style="margin-top: 14px; text-align: right;">
                <button type="submit" class="btn btn-primary">
                    💾 Simpan Konfigurasi Jam Kerja
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- 2. WIDGET EXECUTIVE ANALYTICS (HARI INI) -->
<div style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
    <h3 style="font-size:17px; font-weight:800; color:#0f172a;">📊 Executive Analytics Absensi Hari Ini</h3>
    <span style="font-size:13px; color:#64748b; font-weight:600;">📅 <?php echo $today_label; ?></span>
</div>

<div class="analytics-grid">
    <!-- Card Total -->
    <div class="stat-box">
        <div class="corner-icon">👥</div>
        <div class="title">Total Terdaftar</div>
        <div class="value"><?php echo $total_karyawan; ?></div>
        <div class="subtitle">Guru & Karyawan Aktif</div>
    </div>

    <!-- Card Tepat Waktu -->
    <div class="stat-box" style="border-left: 4px solid #10b981;">
        <div class="corner-icon">🟢</div>
        <div class="title" style="color:#047857;">Tepat Waktu</div>
        <div class="value" style="color:#047857;"><?php echo $tepat_waktu; ?></div>
        <div class="subtitle"><?php echo $persen_tepat; ?>% dari total karyawan</div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" style="width: <?php echo $persen_tepat; ?>%; background: #10b981;"></div>
        </div>
    </div>

    <!-- Card Terlambat -->
    <div class="stat-box" style="border-left: 4px solid #f97316;">
        <div class="corner-icon">⚠️</div>
        <div class="title" style="color:#c2410c;">Hadir Terlambat</div>
        <div class="value" style="color:#c2410c;"><?php echo $terlambat; ?></div>
        <div class="subtitle"><?php echo $persen_terlambat; ?>% dari total karyawan</div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" style="width: <?php echo $persen_terlambat; ?>%; background: #f97316;"></div>
        </div>
    </div>

    <!-- Card Belum Absen -->
    <div class="stat-box" style="border-left: 4px solid #ef4444;">
        <div class="corner-icon">🔴</div>
        <div class="title" style="color:#b91c1c;">Belum Absen Masuk</div>
        <div class="value" style="color:#b91c1c;"><?php echo $belum_absen; ?></div>
        <div class="subtitle"><?php echo $persen_belum; ?>% dari total karyawan</div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" style="width: <?php echo $persen_belum; ?>%; background: #ef4444;"></div>
        </div>
    </div>
</div>

<!-- 3. DEPARTEMEN BREAKDOWN -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <div class="card-title">🏢 Analisis Ketepatan Waktu per Departemen / Divisi</div>
    </div>
    
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
        <?php foreach ($dept_stats as $d_name => $d_data): 
            $d_tot = $d_data['total'];
            $d_tepat_p = $d_tot > 0 ? round(($d_data['tepat'] / $d_tot) * 100) : 0;
            $d_terlambat_p = $d_tot > 0 ? round(($d_data['terlambat'] / $d_tot) * 100) : 0;
            $d_belum_p = $d_tot > 0 ? round(($d_data['belum'] / $d_tot) * 100) : 0;
        ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <span style="font-weight:700; color:#0f172a; font-size:14px;"><?php echo h($d_name); ?></span>
                    <span style="font-size:12px; color:#64748b; font-weight:600;"><?php echo $d_tot; ?> Orang</span>
                </div>
                
                <div style="display:flex; height:10px; border-radius:9999px; overflow:hidden; background:#cbd5e1; margin-bottom:10px;">
                    <div style="width:<?php echo $d_tepat_p; ?>%; background:#10b981;" title="Tepat Waktu: <?php echo $d_data['tepat']; ?>"></div>
                    <div style="width:<?php echo $d_terlambat_p; ?>%; background:#f97316;" title="Terlambat: <?php echo $d_data['terlambat']; ?>"></div>
                    <div style="width:<?php echo $d_belum_p; ?>%; background:#ef4444;" title="Belum Absen: <?php echo $d_data['belum']; ?>"></div>
                </div>

                <div style="display:flex; justify-content:space-between; font-size:11.5px; color:#475569; font-weight:600;">
                    <span style="color:#047857;">🟢 Tepat: <?php echo $d_data['tepat']; ?></span>
                    <span style="color:#c2410c;">⚠️ Terlambat: <?php echo $d_data['terlambat']; ?></span>
                    <span style="color:#b91c1c;">🔴 Belum: <?php echo $d_data['belum']; ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 4. TABEL DETAIL ANALISIS KETERLAMBATAN REAL-TIME -->
<div class="card">
    <div class="card-header" style="flex-wrap:wrap; gap:12px;">
        <div class="card-title">📋 Tabel Detail Presensi & Analisis Keterlambatan Real-Time</div>
        
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="text" id="search-table" placeholder="🔍 Cari nama / PIN / dept..." style="width:220px; margin-bottom:0; padding:8px 12px; font-size:13px;" onkeyup="filterTable()">
            <select id="filter-status" style="width:160px; margin-bottom:0; padding:8px 12px; font-size:13px;" onchange="filterTable()">
                <option value="semua">Semua Status</option>
                <option value="tepat">🟢 Tepat Waktu</option>
                <option value="terlambat">⚠️ Terlambat</option>
                <option value="belum">🔴 Belum Absen</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table id="table-detail">
            <thead>
                <tr>
                    <th>No</th>
                    <th>PIN</th>
                    <th style="text-align:left;">Nama Guru / Karyawan</th>
                    <th style="text-align:left;">Departemen</th>
                    <th>Tipe</th>
                    <th>Jam Absen Masuk</th>
                    <th>Status Presensi</th>
                    <th style="text-align:left;">Analisis Selisih Waktu</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                foreach ($detail_list as $row):
                ?>
                <tr data-status="<?php echo $row['status_code']; ?>" data-text="<?php echo h(strtolower($row['pin'] . ' ' . $row['nama'] . ' ' . $row['departemen'])); ?>">
                    <td><b><?php echo $no++; ?></b></td>
                    <td><code><?php echo h($row['pin']); ?></code></td>
                    <td style="text-align:left; font-weight:700; color:#0f172a;"><?php echo h($row['nama']); ?></td>
                    <td style="text-align:left;"><?php echo h($row['departemen'] ?: '-'); ?></td>
                    <td>
                        <span class="badge" style="<?php echo $row['tipe'] === 'guru' ? 'background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe;' : 'background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'; ?>">
                            <?php echo ucfirst($row['tipe']); ?>
                        </span>
                    </td>
                    <td><b><?php echo $row['jam_absen']; ?></b></td>
                    <td><?php echo $row['status_badge']; ?></td>
                    <td style="text-align:left; font-size:12.5px; color:#475569; font-weight:500;">
                        <?php echo h($row['selisih_text']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterTable() {
    const query = document.getElementById('search-table').value.toLowerCase().trim();
    const statusFilter = document.getElementById('filter-status').value;
    const rows = document.querySelectorAll('#table-detail tbody tr');

    rows.forEach(row => {
        const text = row.getAttribute('data-text');
        const status = row.getAttribute('data-status');

        const matchQuery = text.includes(query);
        const matchStatus = (statusFilter === 'semua' || status === statusFilter);

        if (matchQuery && matchStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php
render_footer();
?>
