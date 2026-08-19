<?php
// ============================================================
// PORTAL MANDIRI - RIWAYAT PRESENSI SAYA (ROLE USER)
// Redesain Modern, Aesthetic, & Eye-Pleasing UI/UX
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('user_riwayat')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$pin  = get_user_pin();

// Jika admin/superadmin/rnd membuka halaman ini, izinkan pilih PIN via GET
if (empty($pin) || is_superadmin() || is_rnd() || is_admin()) {
    if (isset($_GET['pin']) && !empty($_GET['pin'])) {
        $pin = trim($_GET['pin']);
    }
}

$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = 20;
$offset      = ($page - 1) * $limit;

$tgl_mulai   = trim($_GET['tgl_mulai'] ?? date('Y-m-01'));
$tgl_selesai = trim($_GET['tgl_selesai'] ?? date('Y-m-d'));

$logs          = [];
$total_records = 0;
$total_pages   = 0;
$total_masuk   = 0;
$total_pulang  = 0;
$total_hari    = 0;
$detail_user   = null;

if (!empty($pin)) {
    // 1. Detail Karyawan
    $stmt_u = $conn->prepare("SELECT * FROM master_karyawan WHERE pin = ?");
    $stmt_u->bind_param("s", $pin);
    $stmt_u->execute();
    $detail_user = $stmt_u->get_result()->fetch_assoc();

    // 2. Hitung statistik masuk & pulang pada rentang tanggal
    $stmt_stat = $conn->prepare("SELECT status, COUNT(*) as cnt FROM log_absen WHERE pin = ? AND DATE(waktu) BETWEEN ? AND ? GROUP BY status");
    $stmt_stat->bind_param("sss", $pin, $tgl_mulai, $tgl_selesai);
    $stmt_stat->execute();
    $res_st = $stmt_stat->get_result();
    while ($rs = $res_st->fetch_assoc()) {
        if ($rs['status'] == 0) $total_masuk = (int)$rs['cnt'];
        if ($rs['status'] == 1) $total_pulang = (int)$rs['cnt'];
    }
    $total_records = $total_masuk + $total_pulang;
    $total_pages   = ceil($total_records / $limit);

    // Hitung total hari kerja aktif (distinct date)
    $stmt_dh = $conn->prepare("SELECT COUNT(DISTINCT DATE(waktu)) as total_hari FROM log_absen WHERE pin = ? AND DATE(waktu) BETWEEN ? AND ?");
    $stmt_dh->bind_param("sss", $pin, $tgl_mulai, $tgl_selesai);
    $stmt_dh->execute();
    $res_dh = $stmt_dh->get_result()->fetch_assoc();
    $total_hari = (int)($res_dh['total_hari'] ?? 0);

    // 3. Data Log Absen Paginasi
    $stmt_l = $conn->prepare("SELECT la.*, (MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1) AS hari_num FROM log_absen la WHERE la.pin = ? AND DATE(la.waktu) BETWEEN ? AND ? ORDER BY la.waktu DESC LIMIT ? OFFSET ?");
    $stmt_l->bind_param("sssii", $pin, $tgl_mulai, $tgl_selesai, $limit, $offset);
    $stmt_l->execute();
    $logs = $stmt_l->get_result()->fetch_all(MYSQLI_ASSOC);
}

$nama_hari_indo = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

render_header("Riwayat Presensi Saya", "user_riwayat");
?>

<style>
/* ============================================================ */
/* AESTHETIC RIWAYAT PRESENSI THEME                             */
/* ============================================================ */
.riwayat-container {
    display: flex;
    flex-direction: column;
    gap: 22px;
    max-width: 1100px;
    margin: 0 auto 40px auto;
    width: 100%;
}

/* HERO SECTION */
.riwayat-hero {
    position: relative;
    background: linear-gradient(135deg, #0b132b 0%, #1c2541 50%, #0f172a 100%);
    border-radius: 22px;
    padding: 28px 30px;
    color: #ffffff;
    box-shadow: 0 16px 40px -10px rgba(15, 23, 42, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.09);
    overflow: hidden;
}

.riwayat-hero::before {
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

.riwayat-hero-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.hero-identity {
    display: flex;
    align-items: center;
    gap: 18px;
}

.hero-avatar {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 800;
    color: #ffffff;
    overflow: hidden;
    flex-shrink: 0;
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35);
    border: 3px solid rgba(255, 255, 255, 0.2);
}

.hero-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.hero-text-name {
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
    margin-bottom: 4px;
}

.hero-chips-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.hero-pin-badge {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.18);
    padding: 3px 9px;
    border-radius: 7px;
    font-size: 11.5px;
    font-family: monospace;
    font-weight: 700;
    color: #38bdf8;
}

.hero-role-badge {
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
}

.hero-role-guru {
    background: rgba(59, 130, 246, 0.22);
    border: 1px solid rgba(96, 165, 250, 0.4);
    color: #93c5fd;
}

.hero-role-staff {
    background: rgba(148, 163, 184, 0.2);
    border: 1px solid rgba(148, 163, 184, 0.35);
    color: #cbd5e1;
}

/* STAT CARDS STRIP */
.stats-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
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
    border-color: #cbd5e1;
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

/* FILTER & ACTION TOOLBAR */
.filter-toolbar-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 16px 22px;
    box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.04);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}

.preset-filters-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.preset-filter-btn {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}

.preset-filter-btn:hover {
    background: #eff6ff;
    color: #2563eb;
    border-color: #bfdbfe;
}

.date-input-custom {
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 600;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    outline: none;
    background: #ffffff;
    color: #0f172a;
    transition: all 0.2s ease;
}

.date-input-custom:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.btn-filter-submit {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    border: none;
    padding: 8px 18px;
    font-size: 12.5px;
    font-weight: 800;
    border-radius: 10px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 3px 12px rgba(37, 99, 235, 0.25);
    transition: all 0.2s ease;
}

.btn-filter-submit:hover {
    background: linear-gradient(135deg, #1d4ed8, #1e40af);
    transform: translateY(-1px);
}

.btn-pdf-export {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #ffffff !important;
    text-decoration: none;
    padding: 8px 16px;
    font-size: 12.5px;
    font-weight: 800;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    box-shadow: 0 3px 12px rgba(239, 68, 68, 0.25);
    transition: all 0.2s ease;
}

.btn-pdf-export:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    transform: translateY(-1px);
}

/* DATA LOG TABLE CARD */
.log-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 6px 25px -4px rgba(15, 23, 42, 0.06);
}

.log-table-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.log-table-title {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 9px;
}

.log-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 680px;
}

.log-table thead th {
    background: #f8fafc;
    color: #475569;
    padding: 13px 16px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    border-bottom: 1.5px solid #e2e8f0;
}

.log-table tbody tr {
    transition: background 0.15s ease;
}

.log-table tbody tr:hover {
    background: #f8fafc;
}

.log-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}

/* STATUS BADGES */
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

.status-pill-masuk {
    background: #ecfdf5;
    color: #059669;
    border: 1px solid #a7f3d0;
}

.status-pill-pulang {
    background: #fff1f2;
    color: #e11d48;
    border: 1px solid #fecdd3;
}

.status-dot-inner {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}

.status-pill-masuk .status-dot-inner {
    background: #10b981;
}

.status-pill-pulang .status-dot-inner {
    background: #f43f5e;
}

.time-badge-mono {
    font-family: monospace;
    font-size: 13.5px;
    font-weight: 800;
    color: #0f172a;
    background: #f1f5f9;
    padding: 4px 10px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

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

.selfie-thumb-btn {
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.selfie-thumb-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

/* PAGINATION */
.pagination-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    flex-wrap: wrap;
    gap: 12px;
}

.page-btn {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.15s ease;
}

.page-btn:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.page-btn.active {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    border-color: transparent;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
}

/* LIGHTBOX MODAL FOR SELFIE PHOTO */
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

    <?php if (empty($pin) || !$detail_user): ?>
        <div class="log-table-card" style="text-align:center; padding:50px 24px;">
            <div style="width:64px; height:64px; border-radius:50%; background:#fee2e2; color:#ef4444; margin:0 auto 16px auto; display:flex; align-items:center; justify-content:center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin-bottom:8px;">Data Karyawan Tidak Ditemukan</h3>
            <p style="color:#64748b; font-size:13.5px; max-width:460px; margin:0 auto; line-height:1.6;">
                Akun Anda belum terhubung dengan PIN Karyawan. Silakan hubungi Administrator sekolah.
            </p>
        </div>
    <?php else: ?>

        <!-- HERO BANNER -->
        <div class="riwayat-hero">
            <div class="riwayat-hero-top">
                <div class="hero-identity">
                    <div class="hero-avatar">
                        <?php if (!empty($detail_user['foto']) && file_exists(__DIR__ . '/' . $detail_user['foto'])): ?>
                            <img src="<?php echo h($detail_user['foto']); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo strtoupper(mb_substr($detail_user['nama'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="hero-text-name"><?php echo h($detail_user['nama']); ?></div>
                        <div class="hero-chips-wrap">
                            <span class="hero-pin-badge">PIN: <?php echo h($pin); ?></span>
                            <span class="hero-role-badge <?php echo $detail_user['tipe'] === 'guru' ? 'hero-role-guru' : 'hero-role-staff'; ?>">
                                <?php echo $detail_user['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
                            </span>
                            <span style="font-size:12px; color:#94a3b8; font-weight:600;">
                                &bull; <?php echo h($detail_user['departemen'] ?: 'Umum'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <a href="absen_mandiri.php<?php echo !is_user_role() ? '?pin=' . urlencode($pin) : ''; ?>" style="background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; text-decoration:none; padding:10px 20px; border-radius:12px; font-size:13px; font-weight:800; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 16px rgba(37,99,235,0.35); border:1px solid rgba(255,255,255,0.15);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span>Absen Mandiri Sekarang</span>
                </a>
            </div>
        </div>

        <!-- 4 STAT METRICS CARDS -->
        <div class="stats-cards-grid">
            <!-- TOTAL LOG -->
            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#eff6ff; color:#2563eb;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Total Log Presensi</div>
                    <div class="stat-metric-value"><?php echo $total_records; ?></div>
                    <div class="stat-metric-sub">Periode Terpilih</div>
                </div>
            </div>

            <!-- HADIR AKTIF (HARI) -->
            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#f0fdf4; color:#16a34a;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Hari Hadir Aktif</div>
                    <div class="stat-metric-value" style="color:#16a34a;"><?php echo $total_hari; ?> <span style="font-size:13px; font-weight:700; color:#64748b;">Hari</span></div>
                    <div class="stat-metric-sub">Kehadiran Fisik/Web</div>
                </div>
            </div>

            <!-- TOTAL ABSEN MASUK -->
            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#ecfdf5; color:#059669;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Absen Masuk</div>
                    <div class="stat-metric-value" style="color:#059669;"><?php echo $total_masuk; ?> <span style="font-size:13px; font-weight:700; color:#64748b;">Kali</span></div>
                    <div class="stat-metric-sub">Terekam di Sistem</div>
                </div>
            </div>

            <!-- TOTAL ABSEN PULANG -->
            <div class="stat-card-glass">
                <div class="stat-icon-wrapper" style="background:#fff1f2; color:#e11d48;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </div>
                <div>
                    <div class="stat-metric-title">Absen Pulang</div>
                    <div class="stat-metric-value" style="color:#e11d48;"><?php echo $total_pulang; ?> <span style="font-size:13px; font-weight:700; color:#64748b;">Kali</span></div>
                    <div class="stat-metric-sub">Terekam di Sistem</div>
                </div>
            </div>
        </div>

        <!-- FILTER TOOLBAR -->
        <div class="filter-toolbar-card">
            <form method="GET" action="user_riwayat.php" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;">
                <?php if (!empty($pin) && (is_superadmin() || is_rnd() || is_admin())): ?>
                    <input type="hidden" name="pin" value="<?php echo h($pin); ?>">
                <?php endif; ?>

                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:11.5px; font-weight:800; color:#64748b; text-transform:uppercase;">DARI:</span>
                    <input type="date" name="tgl_mulai" id="filter_tgl_mulai" value="<?php echo h($tgl_mulai); ?>" class="date-input-custom">
                </div>

                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:11.5px; font-weight:800; color:#64748b; text-transform:uppercase;">SAMPAI:</span>
                    <input type="date" name="tgl_selesai" id="filter_tgl_selesai" value="<?php echo h($tgl_selesai); ?>" class="date-input-custom">
                </div>

                <button type="submit" class="btn-filter-submit">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span>Filter Data</span>
                </button>
            </form>

            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <div class="preset-filters-row">
                    <button type="button" class="preset-filter-btn" onclick="applyPreset('today')">Hari Ini</button>
                    <button type="button" class="preset-filter-btn" onclick="applyPreset('week')">7 Hari</button>
                    <button type="button" class="preset-filter-btn" onclick="applyPreset('month')">Bulan Ini</button>
                </div>

                <?php if (can_access_page('export_pdf')): ?>
                    <a href="<?php echo 'export_pdf_riwayat.php?' . http_build_query(['pin' => $pin, 'tgl_dari' => $tgl_mulai, 'tgl_sampai' => $tgl_selesai, 'auto_print' => 1]); ?>" target="_blank" class="btn-pdf-export">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>Export PDF</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- TABEL LOG PRESENSI -->
        <div class="log-table-card">
            <div class="log-table-header">
                <div class="log-table-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Rekaman Presensi Pegawai</span>
                </div>
                <div style="font-size:12px; font-weight:700; color:#64748b;">
                    Total: <b style="color:#0f172a;"><?php echo $total_records; ?></b> Rekord
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th style="width:50px; text-align:center;">NO</th>
                            <th style="text-align:left;">HARI &amp; TANGGAL</th>
                            <th style="text-align:center;">JAM PRESENSI</th>
                            <th style="text-align:center;">STATUS</th>
                            <th style="text-align:left;">METODE VERIFIKASI</th>
                            <th style="text-align:center;">FOTO / BUKTI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): 
                            $no = $offset + 1;
                            foreach ($logs as $l):
                                $hn     = (int)$l['hari_num'];
                                $hari   = $nama_hari_indo[$hn] ?? '';
                                $tgl_f  = date('d F Y', strtotime($l['waktu']));
                                $jam_f  = date('H:i:s', strtotime($l['waktu']));
                                $is_masuk = ($l['status'] == 0);

                                $ver_text = 'Sidik Jari (Mesin)';
                                $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M12 1a8 8 0 0 0-8 8v4a8 8 0 0 0 16 0V9a8 8 0 0 0-8-8z"/><path d="M9 9a3 3 0 0 1 6 0v4a3 3 0 0 1-6 0V9z"/></svg>';

                                if ($l['tipe_verifikasi'] === 'SELFIE' || !empty($l['foto_selfie'])) {
                                    $ver_text = 'Selfie AI (Web)';
                                    $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>';
                                } elseif ($l['tipe_verifikasi'] == '15') {
                                    $ver_text = 'Scan Wajah (Mesin)';
                                    $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>';
                                } elseif ($l['tipe_verifikasi'] == '99') {
                                    $ver_text = 'Manual Admin';
                                    $ver_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
                                }
                        ?>
                            <tr>
                                <td style="text-align:center; font-weight:800; color:#64748b;"><?php echo $no++; ?></td>
                                <td style="text-align:left;">
                                    <div style="font-weight:800; color:#0f172a; font-size:13.5px;"><?php echo $hari; ?>, <?php echo $tgl_f; ?></div>
                                    <div style="font-size:11px; color:#94a3b8; margin-top:2px;">Tgl Record: <?php echo date('Y-m-d', strtotime($l['waktu'])); ?></div>
                                </td>
                                <td style="text-align:center;">
                                    <span class="time-badge-mono"><?php echo $jam_f; ?> <span style="font-size:10.5px; color:#64748b;">WIB</span></span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="status-pill-badge <?php echo $is_masuk ? 'status-pill-masuk' : 'status-pill-pulang'; ?>">
                                        <span class="status-dot-inner"></span>
                                        <span><?php echo $is_masuk ? 'MASUK' : 'PULANG'; ?></span>
                                    </span>
                                </td>
                                <td style="text-align:left;">
                                    <div class="method-tag">
                                        <?php echo $ver_icon; ?>
                                        <span><?php echo $ver_text; ?></span>
                                    </div>
                                    <?php if (!empty($l['ip_address'])): ?>
                                        <div style="font-size:10.5px; color:#94a3b8; font-family:monospace; margin-top:4px;">IP: <?php echo h($l['ip_address']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if (!empty($l['foto_selfie']) && file_exists(__DIR__ . '/' . $l['foto_selfie'])): 
                                        $selfie_src = h($l['foto_selfie']);
                                    ?>
                                        <img src="<?php echo $selfie_src; ?>" alt="Selfie" class="selfie-thumb-btn" onclick="openPhotoLightbox('<?php echo $selfie_src; ?>', '<?php echo $hari . ', ' . $tgl_f . ' (' . $jam_f . ' WIB)'; ?>', '<?php echo $is_masuk ? 'Absen Masuk' : 'Absen Pulang'; ?>')" style="width:36px; height:36px; border-radius:8px; object-fit:cover; border:2px solid #bfdbfe;">
                                    <?php else: ?>
                                        <span style="font-size:11.5px; color:#cbd5e1; font-weight:600;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="6" style="padding:60px 20px; text-align:center;">
                                    <div style="width:52px; height:52px; border-radius:50%; background:#f1f5f9; color:#94a3b8; margin:0 auto 12px auto; display:flex; align-items:center; justify-content:center;">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    </div>
                                    <div style="font-size:14px; font-weight:800; color:#475569;">Belum Ada Riwayat Presensi</div>
                                    <div style="font-size:12px; color:#94a3b8; margin-top:3px;">Tidak ditemukan data presensi pada rentang tanggal yang dipilih.</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINASI -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-wrap">
                    <span style="font-size:12px; color:#64748b; font-weight:600;">
                        Menampilkan <b><?php echo count($logs); ?></b> dari <b><?php echo $total_records; ?></b> data
                    </span>
                    
                    <div style="display:flex; gap:5px; align-items:center;">
                        <?php $url_p = "tgl_mulai=" . urlencode($tgl_mulai) . "&tgl_selesai=" . urlencode($tgl_selesai) . (!empty($pin) ? "&pin=" . urlencode($pin) : ""); ?>

                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo ($page - 1); ?>&<?php echo $url_p; ?>" class="page-btn">‹ Prev</a>
                        <?php endif; ?>

                        <?php 
                        $start_p = max(1, $page - 2);
                        $end_p   = min($total_pages, $page + 2);
                        for ($p = $start_p; $p <= $end_p; $p++): 
                        ?>
                            <a href="?page=<?php echo $p; ?>&<?php echo $url_p; ?>" class="page-btn <?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo ($page + 1); ?>&<?php echo $url_p; ?>" class="page-btn">Next ›</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

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
            <div style="font-size:12px; color:#64748b; margin-top:2px;">Foto diambil mandiri melalui Web AI Face Scanner</div>
        </div>
    </div>
</div>

<script>
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

function applyPreset(type) {
    const now = new Date();
    const tglMulai = document.getElementById('filter_tgl_mulai');
    const tglSelesai = document.getElementById('filter_tgl_selesai');

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
</script>

<?php
render_footer();
?>
