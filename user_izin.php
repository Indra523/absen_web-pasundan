<?php
// ============================================================
// PORTAL MANDIRI - PENGAJUAN CUTI / IZIN / SAKIT (ROLE USER)
// Redesain Modern, Aesthetic, Sleek UI + Upload Surat Dokter Kondisional
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('user_izin')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$pin  = get_user_pin();
$pesan_sukses = '';
$pesan_error  = '';

if (empty($pin) || is_superadmin() || is_rnd() || is_admin()) {
    if (isset($_GET['pin'])) {
        $pin = trim($_GET['pin']);
    }
}

$detail_user = null;
if (!empty($pin)) {
    $stmt_u = $conn->prepare("SELECT * FROM master_karyawan WHERE pin = ?");
    $stmt_u->bind_param("s", $pin);
    $stmt_u->execute();
    $detail_user = $stmt_u->get_result()->fetch_assoc();
}

// PROSES POST PENGAJUAN PERIZINAN MANDIRI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'simpan_izin_mandiri') {
    csrf_verify();

    $tgl_mulai   = trim($_POST['tgl_mulai'] ?? '');
    $tgl_selesai = trim($_POST['tgl_selesai'] ?? $tgl_mulai);
    $tipe_izin   = trim($_POST['tipe_izin'] ?? '');
    $keterangan  = trim($_POST['keterangan'] ?? '');
    $username    = $_SESSION['username'] ?? 'User';

    if (empty($tgl_mulai)) $tgl_mulai = date('Y-m-d');
    if (empty($tgl_selesai)) $tgl_selesai = $tgl_mulai;

    if (strtotime($tgl_selesai) < strtotime($tgl_mulai)) {
        $tmp = $tgl_mulai;
        $tgl_mulai = $tgl_selesai;
        $tgl_selesai = $tmp;
    }

    $start_ts  = strtotime($tgl_mulai);
    $end_ts    = strtotime($tgl_selesai);
    $diff_days = ceil(($end_ts - $start_ts) / 86400) + 1;

    // Handle Upload Surat Keterangan Dokter
    $surat_dokter_path = null;
    $upload_error = null;

    if (isset($_FILES['surat_dokter']) && $_FILES['surat_dokter']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['surat_dokter'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $file_size = $file['size'];

            if (!in_array($file_ext, $allowed_ext)) {
                $upload_error = "Format file surat dokter tidak valid! Hanya format JPG, PNG, WEBP, atau PDF yang diperbolehkan.";
            } elseif ($file_size > 5 * 1024 * 1024) {
                $upload_error = "Ukuran file surat dokter terlalu besar! Maksimal 5 MB.";
            } else {
                $target_dir = __DIR__ . '/uploads/surat_dokter/';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0775, true);
                }
                $safe_pin = preg_replace('/[^a-zA-Z0-9_-]/', '', $pin);
                $new_filename = 'surat_dokter_' . $safe_pin . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                $target_file = $target_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $surat_dokter_path = 'uploads/surat_dokter/' . $new_filename;
                } else {
                    $upload_error = "Gagal mengunggah file surat dokter ke server.";
                }
            }
        } else {
            $upload_error = "Terjadi kesalahan saat mengunggah file surat dokter (Kode error: " . $file['error'] . ").";
        }
    }

    // Validasi input
    if (empty($pin)) {
        $pesan_error = "Akun Anda belum terhubung dengan PIN Karyawan. Pengajuan gagal!";
    } elseif (empty($tgl_mulai) || empty($tipe_izin)) {
        $pesan_error = "Tanggal dan jenis perizinan wajib diisi!";
    } elseif (!in_array($tipe_izin, ['cuti', 'izin', 'sakit'])) {
        $pesan_error = "Jenis perizinan tidak valid!";
    } elseif ($diff_days > 31) {
        $pesan_error = "Maksimal durasi pengajuan perizinan sekaligus adalah 31 hari!";
    } elseif (!empty($upload_error)) {
        $pesan_error = $upload_error;
    } elseif ($tipe_izin === 'sakit' && $diff_days > 2 && empty($surat_dokter_path)) {
        // ATURAN: Sakit > 2 hari WAJIB upload surat keterangan dokter
        $pesan_error = "Perhatian: Izin sakit lebih dari 2 hari ({$diff_days} Hari) <b>WAJIB</b> melampirkan foto / file Surat Keterangan Dokter!";
    } else {
        // Cek bentrokan dengan perizinan yang sudah DISETUJUI
        $stmt_check = $conn->prepare("SELECT tanggal, tgl_selesai FROM perizinan WHERE pin = ? AND ? <= COALESCE(tgl_selesai, tanggal) AND ? >= tanggal AND status_persetujuan = 'disetujui'");
        $stmt_check->bind_param("sss", $pin, $tgl_mulai, $tgl_selesai);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check->num_rows > 0) {
            $pesan_error = "Pengajuan gagal! Periode ini berbenturan dengan perizinan yang sudah <b>DISETUJUI</b> sebelumnya.";
        } else {
            // Simpan perizinan
            $stmt_ins = $conn->prepare("INSERT INTO perizinan (pin, tanggal, tgl_selesai, tipe_izin, keterangan, surat_dokter, status_persetujuan, created_by) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?) ON DUPLICATE KEY UPDATE tgl_selesai = VALUES(tgl_selesai), tipe_izin = VALUES(tipe_izin), keterangan = VALUES(keterangan), surat_dokter = COALESCE(VALUES(surat_dokter), surat_dokter), status_persetujuan = 'pending', created_by = VALUES(created_by)");
            $stmt_ins->bind_param("sssssss", $pin, $tgl_mulai, $tgl_selesai, $tipe_izin, $keterangan, $surat_dokter_path, $username);

            if ($stmt_ins->execute()) {
                $dur_fmt = ($diff_days > 1) 
                    ? "{$diff_days} Hari (" . date('d/m/Y', $start_ts) . " s.d " . date('d/m/Y', $end_ts) . ")" 
                    : date('d F Y', $start_ts);

                $pesan_sukses = "Pengajuan <b>" . strtoupper($tipe_izin) . "</b> untuk <b>" . $dur_fmt . "</b> telah berhasil dikirim.";
                log_audit("USER_INPUT_PERIZINAN", "User {$username} (PIN {$pin}) mengajukan " . strtoupper($tipe_izin) . " periode {$tgl_mulai} s.d {$tgl_selesai} ({$diff_days} hari)" . (!empty($surat_dokter_path) ? " + Surat Dokter" : ""));

                // TRIGGER 1 NOTIFIKASI REAL-TIME UNTUK ADMIN & TATA USAHA
                $nama_pemohon = $detail_user['nama'] ?? $username;
                $notif_title  = "Pengajuan " . ucfirst($tipe_izin) . " Baru (" . $diff_days . " Hari)";
                $notif_msg    = "{$nama_pemohon} (PIN {$pin}) mengajukan " . strtoupper($tipe_izin) . " periode " . date('d/m/Y', $start_ts) . ($diff_days > 1 ? " s.d " . date('d/m/Y', $end_ts) . " ({$diff_days} Hari)" : "") . (!empty($surat_dokter_path) ? " (Disertai Surat Dokter)" : "");
                $notif_link   = "kelola_izin.php";
                $applicant_uid = (int)($_SESSION['user_id'] ?? 0);

                $stmt_n = $conn->prepare("INSERT INTO notifications (user_id, target_role, title, message, type, link) VALUES (?, 'kelola_izin', ?, ?, 'perizinan', ?)");
                if ($stmt_n) {
                    $stmt_n->bind_param("isss", $applicant_uid, $notif_title, $notif_msg, $notif_link);
                    $stmt_n->execute();
                }
            } else {
                $pesan_error = "Gagal menyimpan pengajuan perizinan: " . $conn->error;
            }
        }
    }

    $_SESSION['pesan_sukses'] = $pesan_sukses;
    $_SESSION['pesan_error']  = $pesan_error;
    $redir = "user_izin.php" . (!empty($_GET['pin']) ? "?pin=" . urlencode($_GET['pin']) : "");
    header("Location: " . $redir);
    exit;
}

if (isset($_SESSION['pesan_sukses'])) {
    $pesan_sukses = $_SESSION['pesan_sukses'];
    unset($_SESSION['pesan_sukses']);
}
if (isset($_SESSION['pesan_error'])) {
    $pesan_error = $_SESSION['pesan_error'];
    unset($_SESSION['pesan_error']);
}

$list_izin = [];
$stat_count = ['pending' => 0, 'disetujui' => 0, 'ditolak' => 0, 'total' => 0];

if (!empty($pin)) {
    $stmt_l = $conn->prepare("SELECT * FROM perizinan WHERE pin = ? ORDER BY tanggal DESC, id DESC");
    $stmt_l->bind_param("s", $pin);
    $stmt_l->execute();
    $list_izin = $stmt_l->get_result()->fetch_all(MYSQLI_ASSOC);

    $stat_count['total'] = count($list_izin);
    foreach ($list_izin as $iz) {
        $st = $iz['status_persetujuan'] ?? 'disetujui';
        if (isset($stat_count[$st])) {
            $stat_count[$st]++;
        }
    }
}

render_header("Pengajuan Perizinan &amp; Cuti", "user_izin");
?>

<style>
/* ===== MODERN USER IZIN THEME ===== */
.izin-container {
    display: flex;
    flex-direction: column;
    gap: 22px;
    max-width: 1160px;
    margin: 0 auto 40px auto;
    width: 100%;
}

/* 4 STAT SUMMARY CARDS */
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
    font-size: 11px;
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

/* 2 COLUMNS LAYOUT */
.user-izin-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 22px;
    align-items: start;
}

@media (max-width: 1024px) {
    .user-izin-grid {
        grid-template-columns: 1fr;
    }
}

/* FORM CARD */
.form-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 6px 25px -4px rgba(15, 23, 42, 0.06);
}

.form-header-gradient {
    background: linear-gradient(135deg, #0b132b 0%, #1c2541 50%, #0f172a 100%);
    padding: 20px 24px;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.form-title-text {
    font-size: 15px;
    font-weight: 800;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-body-padding {
    padding: 22px;
}

.form-label-custom {
    font-size: 11.5px;
    font-weight: 800;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.input-date-custom {
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

.input-date-custom:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3.5px rgba(37, 99, 235, 0.12);
}

/* TYPE SELECTOR CARDS */
.type-selector-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 16px;
}

.type-card-option {
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    background: #ffffff;
    user-select: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
}

.type-card-option:hover {
    border-color: #3b82f6;
    background: #f8fafc;
    transform: translateY(-1px);
}

.type-card-option.active {
    border-color: #2563eb;
    background: #eff6ff;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.type-card-option.active.card-sakit {
    border-color: #7e22ce;
    background: #faf5ff;
    box-shadow: 0 0 0 3px rgba(126, 34, 206, 0.15);
}

.type-card-option.active.card-izin {
    border-color: #ea580c;
    background: #fff7ed;
    box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.15);
}

.type-title {
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
}

.type-desc {
    font-size: 10px;
    color: #64748b;
    font-weight: 600;
}

/* DURATION PILL */
.duration-info-pill {
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

/* SURAT DOKTER UPLOAD BOX */
.doctor-upload-section {
    background: #f8fafc;
    border: 1.5px dashed #cbd5e1;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 18px;
    transition: all 0.2s ease;
}

.doctor-upload-section.required {
    background: #fffbeb;
    border-color: #f59e0b;
}

.doctor-upload-section.optional {
    background: #f0f9ff;
    border-color: #bae6fd;
}

.file-dropzone-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    padding: 14px 10px;
    text-align: center;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
}

.file-dropzone-label:hover {
    border-color: #2563eb;
    background: #f8fafc;
}

/* TABLE CARD */
.table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 6px 25px -4px rgba(15, 23, 42, 0.06);
}

.table-header-bar {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}

.table-modern {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 640px;
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
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 800;
}
.status-pill-approved { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.status-pill-pending { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.status-pill-rejected { background: #fee2e2; color: #be123c; border: 1px solid #fca5a5; }

.badge-tipe {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3.5px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 800;
}
.badge-tipe-cuti { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-tipe-izin { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
.badge-tipe-sakit { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }

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
}
.photo-modal-card {
    background: #ffffff;
    border-radius: 20px;
    max-width: 540px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
    animation: scaleIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes scaleIn { from { transform: scale(0.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>

<div class="izin-container">

    <!-- TOAST NOTIFICATIONS -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:14px 18px; color:#15803d; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(22,163,74,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; border-radius:14px; padding:14px 18px; color:#991b1b; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(220,38,38,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
        </div>
    <?php endif; ?>

    <?php if (empty($pin) || !$detail_user): ?>
        <div class="form-card" style="text-align:center; padding:60px 20px;">
            <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin-bottom:8px;">Akun Belum Terhubung PIN Karyawan</h3>
            <p style="color:#64748b; font-size:13.5px; max-width:440px; margin:0 auto;">
                Akun <code><?php echo h($_SESSION['username']); ?></code> belum terhubung ke data PIN karyawan. Silakan hubungi Administrator.
            </p>
        </div>
    <?php else: ?>

    <!-- 4 KPI SUMMARY CARDS -->
    <div class="stats-cards-grid">
        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#eff6ff; color:#2563eb;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Total Berkas</div>
                <div class="stat-metric-value" id="stat_total"><?php echo $stat_count['total']; ?> <span style="font-size:12px; font-weight:700; color:#64748b;">Berkas</span></div>
                <div class="stat-metric-sub">Semua Pengajuan</div>
            </div>
        </div>

        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#f0fdf4; color:#16a34a;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Disetujui</div>
                <div class="stat-metric-value" style="color:#16a34a;" id="stat_disetujui"><?php echo $stat_count['disetujui']; ?></div>
                <div class="stat-metric-sub">Izin/Cuti Aktif</div>
            </div>
        </div>

        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#fffbeb; color:#d97706;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Menunggu Approval</div>
                <div class="stat-metric-value" style="color:#d97706;" id="stat_pending"><?php echo $stat_count['pending']; ?></div>
                <div class="stat-metric-sub">Dalam Proses Review</div>
            </div>
        </div>

        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#fff1f2; color:#e11d48;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Ditolak</div>
                <div class="stat-metric-value" style="color:#e11d48;" id="stat_ditolak"><?php echo $stat_count['ditolak']; ?></div>
                <div class="stat-metric-sub">Tidak Disetujui</div>
            </div>
        </div>
    </div>

    <!-- MAIN TWO COLUMNS GRID -->
    <div class="user-izin-grid">

        <!-- LEFT COLUMN: FORM PENGAJUAN IZIN -->
        <div class="form-card">
            <div class="form-header-gradient">
                <div class="form-title-text">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2.3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <span>Form Pengajuan Perizinan</span>
                </div>
            </div>

            <div class="form-body-padding">
                <!-- IDENTITAS PEGAWAI CHIP -->
                <div style="display:flex; align-items:center; gap:12px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; margin-bottom:18px;">
                    <div style="width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg, #3b82f6, #1d4ed8); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:16px; flex-shrink:0;">
                        <?php echo strtoupper(mb_substr($detail_user['nama'], 0, 1)); ?>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:13.5px; font-weight:800; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo h($detail_user['nama']); ?></div>
                        <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                            PIN: <code style="font-weight:800; color:#0f172a;"><?php echo h($detail_user['pin']); ?></code> &bull; <?php echo h($detail_user['departemen'] ?: 'Umum'); ?>
                        </div>
                    </div>
                </div>

                <form method="POST" action="user_izin.php<?php echo !empty($_GET['pin']) ? '?pin=' . urlencode($_GET['pin']) : ''; ?>" enctype="multipart/form-data" id="formUserIzin">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="simpan_izin_mandiri">

                    <!-- RENTANG TANGGAL -->
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                        <div>
                            <label for="tgl_mulai" class="form-label-custom">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span>Dari Tanggal</span>
                            </label>
                            <input type="date" id="tgl_mulai" name="tgl_mulai" value="<?php echo date('Y-m-d'); ?>" class="input-date-custom" onchange="handleFormDateChange()" required>
                        </div>
                        <div>
                            <label for="tgl_selesai" class="form-label-custom">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span>Sampai Tanggal</span>
                            </label>
                            <input type="date" id="tgl_selesai" name="tgl_selesai" value="<?php echo date('Y-m-d'); ?>" class="input-date-custom" onchange="handleFormDateChange()" required>
                        </div>
                    </div>

                    <!-- DURATION INFO PILL -->
                    <div id="durationPill" class="duration-info-pill" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span id="durationText">Durasi: <b>1 Hari</b> (Single Day)</span>
                    </div>

                    <!-- JENIS PERIZINAN (CARDS) -->
                    <div style="margin-bottom:16px;">
                        <label class="form-label-custom">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>Jenis Perizinan</span>
                        </label>
                        <input type="hidden" id="tipe_izin_val" name="tipe_izin" value="cuti" required>

                        <div class="type-selector-grid">
                            <div class="type-card-option active" id="card_cuti" onclick="selectIzinType('cuti')">
                                <div class="type-title">Cuti</div>
                                <div class="type-desc">Resmi Kalender</div>
                            </div>
                            <div class="type-card-option" id="card_izin" onclick="selectIzinType('izin')">
                                <div class="type-title">Izin</div>
                                <div class="type-desc">Dinas / Pribadi</div>
                            </div>
                            <div class="type-card-option" id="card_sakit" onclick="selectIzinType('sakit')">
                                <div class="type-title">Sakit</div>
                                <div class="type-desc">Surat Dokter</div>
                            </div>
                        </div>
                    </div>

                    <!-- CONDITIONAL SURAT DOKTER UPLOAD SECTION (SAKIT) -->
                    <div id="doctorUploadSection" class="doctor-upload-section optional" style="display:none;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; gap:8px;">
                            <div style="font-size:12px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:6px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7e22ce" stroke-width="2.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                                <span>Surat Keterangan Dokter</span>
                            </div>
                            <span id="doctorRequirementBadge" style="font-size:10.5px; font-weight:800; padding:2px 8px; border-radius:6px; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;">
                                OPSIONAL (1-2 HARI)
                            </span>
                        </div>

                        <!-- DYNAMIC ALERT DESCRIPTION -->
                        <div id="doctorAlertDesc" style="font-size:11.5px; line-height:1.4; color:#0369a1; margin-bottom:12px;">
                            Untuk izin sakit 1-2 hari yang hanya perlu istirahat di rumah tanpa ke dokter, upload surat dokter bersifat <b>opsional / tidak wajib</b>.
                        </div>

                        <!-- DROPZONE BOX -->
                        <label for="surat_dokter" class="file-dropzone-label">
                            <input type="file" id="surat_dokter" name="surat_dokter" accept=".jpg,.jpeg,.png,.webp,.pdf" style="display:none;" onchange="handleDoctorFileSelect(this)">
                            
                            <div id="dropzoneIcon" style="width:36px; height:36px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; color:#64748b;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            </div>
                            
                            <div id="dropzoneText" style="font-size:12px; font-weight:700; color:#334155;">
                                Klik untuk upload foto / PDF surat dokter
                            </div>
                            <div style="font-size:10.5px; color:#94a3b8;">Format: JPG, PNG, WEBP, PDF (Maks. 5 MB)</div>
                        </label>

                        <!-- PREVIEW CONTAINER -->
                        <div id="doctorFilePreview" style="display:none; margin-top:10px; padding:10px; background:#ffffff; border:1px solid #bbf7d0; border-radius:10px; align-items:center; justify-content:space-between;">
                            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <span id="doctorFileName" style="font-size:12px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">nama_file.jpg</span>
                            </div>
                            <button type="button" onclick="clearDoctorFile()" style="background:none; border:none; color:#ef4444; font-size:11px; font-weight:800; cursor:pointer; padding:2px 6px;">Batal</button>
                        </div>
                    </div>

                    <!-- KETERANGAN / ALASAN -->
                    <div style="margin-bottom:18px;">
                        <label for="keterangan" class="form-label-custom">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <span>Keterangan / Alasan</span>
                        </label>
                        <textarea id="keterangan" name="keterangan" rows="3" class="input-date-custom" placeholder="Jelaskan alasan izin secara singkat dan jelas..." style="resize:vertical;" required></textarea>
                    </div>

                    <!-- SUBMIT BUTTON -->
                    <button type="submit" id="btnSubmitIzin" style="width:100%; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border:none; padding:12px; font-size:13.5px; font-weight:800; border-radius:12px; cursor:pointer; box-shadow:0 4px 15px rgba(37,99,235,.3); display:flex; align-items:center; justify-content:center; gap:8px; transition:all 0.2s ease;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        <span>Kirim Pengajuan</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- RIGHT COLUMN: RIWAYAT PENGAJUAN SAYA -->
        <div class="table-card">
            <div class="table-header-bar">
                <div style="font-size:15px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Riwayat Pengajuan Saya</span>
                </div>
                <div style="font-size:11px; color:#166534; font-weight:700; background:#dcfce7; border:1px solid #bbf7d0; padding:4px 12px; border-radius:20px; display:flex; align-items:center; gap:6px;">
                    <span style="width:8px; height:8px; background:#22c55e; border-radius:50%; display:inline-block;"></span>
                    <span>Realtime Sync</span>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th style="width:45px;">NO</th>
                            <th style="text-align:left;">PERIODE TANGGAL</th>
                            <th>JENIS</th>
                            <th style="text-align:left;">KETERANGAN</th>
                            <th>SURAT DOKTER</th>
                            <th>STATUS APPROVAL</th>
                            <th>DIAJUKAN PADA</th>
                        </tr>
                    </thead>
                    <tbody id="user_izin_tbody">
                        <?php if (!empty($list_izin)):
                            $no = 1;
                            foreach ($list_izin as $row):
                                $t_iz = $row['tipe_izin'];
                                $badge_tipe_class = 'badge-tipe-cuti';
                                if ($t_iz === 'izin') $badge_tipe_class = 'badge-tipe-izin';
                                if ($t_iz === 'sakit') $badge_tipe_class = 'badge-tipe-sakit';

                                $st_p = $row['status_persetujuan'] ?? 'disetujui';
                                $p_class = 'status-pill-approved';
                                $p_text  = 'Disetujui';
                                if ($st_p === 'pending') {
                                    $p_class = 'status-pill-pending';
                                    $p_text  = 'Menunggu';
                                } elseif ($st_p === 'ditolak') {
                                    $p_class = 'status-pill-rejected';
                                    $p_text  = 'Ditolak';
                                }

                                $tgl_m = date('d/m/Y', strtotime($row['tanggal']));
                                $tgl_s = !empty($row['tgl_selesai']) ? date('d/m/Y', strtotime($row['tgl_selesai'])) : $tgl_m;
                                $dur_days = (strtotime($row['tgl_selesai'] ?: $row['tanggal']) - strtotime($row['tanggal'])) / 86400 + 1;
                                
                                $tgl_display = ($tgl_m === $tgl_s) 
                                    ? "<div style='font-weight:800; color:#0f172a;'>{$tgl_m}</div><div style='font-size:11px; color:#64748b;'>1 Hari</div>" 
                                    : "<div style='font-weight:800; color:#0f172a;'>{$tgl_m} &ndash; {$tgl_s}</div><div style='font-size:11px; color:#2563eb; font-weight:700;'>{$dur_days} Hari</div>";

                                // Surat Dokter Cell
                                $td_surat = '<span style="color:#cbd5e1;">-</span>';
                                if (!empty($row['surat_dokter']) && file_exists(__DIR__ . '/' . $row['surat_dokter'])) {
                                    $file_ext = strtolower(pathinfo($row['surat_dokter'], PATHINFO_EXTENSION));
                                    $file_url = h($row['surat_dokter']);
                                    if ($file_ext === 'pdf') {
                                        $td_surat = "<a href='{$file_url}' target='_blank' style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; gap:4px;'>
                                                        <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><polyline points='14 2 14 8 20 8'/></svg>
                                                        <span>Lihat PDF</span>
                                                     </a>";
                                    } else {
                                        $td_surat = "<button type='button' onclick=\"openDoctorLightbox('{$file_url}', 'Surat Keterangan Dokter &bull; {$tgl_m}')\" style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:4px;'>
                                                        <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'/><circle cx='12' cy='10' r='3'/></svg>
                                                        <span>Lihat Surat</span>
                                                     </button>";
                                    }
                                } elseif ($t_iz === 'sakit' && $dur_days <= 2) {
                                    $td_surat = "<span style='font-size:10.5px; color:#64748b; background:#f1f5f9; padding:2px 6px; border-radius:4px;'>Istirahat (1-2 Hari)</span>";
                                }
                        ?>
                            <tr id="row_izin_<?php echo $row['id']; ?>" data-status="<?php echo $st_p; ?>">
                                <td><b><?php echo $no++; ?></b></td>
                                <td style="text-align:left;"><?php echo $tgl_display; ?></td>
                                <td>
                                    <span class="badge-tipe <?php echo $badge_tipe_class; ?>"><?php echo ucfirst($t_iz); ?></span>
                                </td>
                                <td style="text-align:left; color:#334155; line-height:1.4;">
                                    <?php echo h($row['keterangan'] ?: '-'); ?>
                                </td>
                                <td><?php echo $td_surat; ?></td>
                                <td>
                                    <span class="status-pill-badge <?php echo $p_class; ?>"><?php echo $p_text; ?></span>
                                    <?php if (!empty($row['approved_by'])): ?>
                                        <div style="font-size:10.5px; color:#64748b; margin-top:3px; font-weight:600;">
                                            oleh <b><?php echo h($row['approved_by']); ?></b>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:11.5px; color:#64748b;">
                                    <?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="7" style="padding:40px; color:#94a3b8; text-align:center;">Belum ada riwayat perizinan yang diajukan.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- LIGHTBOX MODAL SURAT DOKTER -->
<div class="photo-modal-overlay" id="doctorModalOverlay" onclick="closeDoctorLightbox(event)">
    <div class="photo-modal-card" onclick="event.stopPropagation()">
        <div style="position:relative; width:100%; max-height:75vh; background:#0f172a; display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <img id="doctorModalImg" src="" alt="Surat Dokter" style="width:100%; height:auto; max-height:75vh; object-fit:contain;">
            <button type="button" onclick="closeDoctorLightbox()" style="position:absolute; top:12px; right:12px; background:rgba(15,23,42,0.75); color:#fff; border:none; border-radius:50%; width:32px; height:32px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div style="padding:16px 20px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
            <div id="doctorModalTitle" style="font-size:13px; font-weight:800; color:#0f172a;">Surat Keterangan Dokter</div>
            <a id="doctorModalDownload" href="" target="_blank" download style="font-size:12px; font-weight:800; color:#2563eb; text-decoration:none;">Download File</a>
        </div>
    </div>
</div>

<script>
let currentDiffDays = 1;

function selectIzinType(type) {
    document.getElementById('tipe_izin_val').value = type;
    document.querySelectorAll('.type-card-option').forEach(card => card.classList.remove('active'));
    
    const targetCard = document.getElementById('card_' + type);
    if (targetCard) targetCard.classList.add('active');

    handleFormDateChange();
}

function handleFormDateChange() {
    const t1 = document.getElementById('tgl_mulai').value;
    const t2 = document.getElementById('tgl_selesai').value;
    const pill = document.getElementById('durationPill');
    const textEl = document.getElementById('durationText');
    const currentType = document.getElementById('tipe_izin_val').value;

    if (!t1 || !t2 || !pill) return;

    const d1 = new Date(t1);
    const d2 = new Date(t2);

    if (d2 < d1) {
        pill.style.background = '#fff1f2';
        pill.style.borderColor = '#fca5a5';
        pill.style.color = '#dc2626';
        textEl.innerHTML = 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai!';
        currentDiffDays = 0;
        return;
    }

    const diffTime = Math.abs(d2 - d1);
    currentDiffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

    pill.style.background = '#eff6ff';
    pill.style.borderColor = '#bfdbfe';
    pill.style.color = '#1d4ed8';

    if (currentDiffDays === 1) {
        textEl.innerHTML = 'Durasi: <b>1 Hari</b> (Single Day)';
    } else {
        const fmt1 = t1.split('-').reverse().join('/');
        const fmt2 = t2.split('-').reverse().join('/');
        textEl.innerHTML = `Durasi: <b>${currentDiffDays} Hari</b> (${fmt1} s.d ${fmt2})`;
    }

    // UPDATE SURAT DOKTER REQUIREMENT
    const doctorSec = document.getElementById('doctorUploadSection');
    const reqBadge = document.getElementById('doctorRequirementBadge');
    const alertDesc = document.getElementById('doctorAlertDesc');
    const fileInput = document.getElementById('surat_dokter');

    if (currentType === 'sakit') {
        doctorSec.style.display = 'block';

        if (currentDiffDays > 2) {
            // SAKIT > 2 HARI = WAJIB
            doctorSec.className = 'doctor-upload-section required';
            reqBadge.style.background = '#fef3c7';
            reqBadge.style.borderColor = '#fde68a';
            reqBadge.style.color = '#92400e';
            reqBadge.textContent = 'WAJIB (> 2 HARI)';
            alertDesc.style.color = '#92400e';
            alertDesc.innerHTML = `Izin sakit selama <b>${currentDiffDays} hari</b> (> 2 hari) <b>WAJIB</b> melampirkan foto / file Surat Keterangan Dokter.`;
            fileInput.required = (document.getElementById('doctorFilePreview').style.display !== 'flex');
        } else {
            // SAKIT 1-2 HARI = OPSIONAL
            doctorSec.className = 'doctor-upload-section optional';
            reqBadge.style.background = '#e0f2fe';
            reqBadge.style.borderColor = '#bae6fd';
            reqBadge.style.color = '#0369a1';
            reqBadge.textContent = 'OPSIONAL (1-2 HARI)';
            alertDesc.style.color = '#0369a1';
            alertDesc.innerHTML = `Untuk izin sakit 1-2 hari yang hanya perlu istirahat di rumah tanpa ke dokter, upload surat dokter bersifat <b>opsional / tidak wajib</b>.`;
            fileInput.required = false;
        }
    } else {
        doctorSec.style.display = 'none';
        fileInput.required = false;
    }
}

function handleDoctorFileSelect(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById('doctorFileName').textContent = file.name;
        document.getElementById('doctorFilePreview').style.display = 'flex';
        input.required = false;
    }
}

function clearDoctorFile() {
    const input = document.getElementById('surat_dokter');
    input.value = '';
    document.getElementById('doctorFilePreview').style.display = 'none';
    if (document.getElementById('tipe_izin_val').value === 'sakit' && currentDiffDays > 2) {
        input.required = true;
    }
}

function openDoctorLightbox(src, title) {
    const overlay = document.getElementById('doctorModalOverlay');
    const img = document.getElementById('doctorModalImg');
    const titleEl = document.getElementById('doctorModalTitle');
    const dlEl = document.getElementById('doctorModalDownload');

    if (img) img.src = src;
    if (titleEl) titleEl.textContent = title;
    if (dlEl) dlEl.href = src;
    if (overlay) overlay.style.display = 'flex';
}

function closeDoctorLightbox(e) {
    if (e && e.target && e.target.closest && e.target.closest('.photo-modal-card') && e.target.tagName !== 'BUTTON') {
        return;
    }
    const overlay = document.getElementById('doctorModalOverlay');
    if (overlay) overlay.style.display = 'none';
}

// REALTIME LIVE AUTO-UPDATE STATUS PENGAJUAN
const targetPin = "<?php echo h($pin); ?>";
let prevStatuses = {};

document.querySelectorAll('#user_izin_tbody tr[data-status]').forEach(tr => {
    const id = tr.id.replace('row_izin_', '');
    prevStatuses[id] = tr.getAttribute('data-status');
});

function pollRealtimeStatus() {
    if (!targetPin) return;

    fetch('api_user_izin.php?pin=' + encodeURIComponent(targetPin))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('stat_total').innerHTML = data.stat.total + ' <span style="font-size:12px; font-weight:700; color:#64748b;">Berkas</span>';
                document.getElementById('stat_disetujui').textContent = data.stat.disetujui;
                document.getElementById('stat_pending').textContent = data.stat.pending;
                document.getElementById('stat_ditolak').textContent = data.stat.ditolak;
            }
        })
        .catch(err => console.error('Realtime sync error:', err));
}

setInterval(pollRealtimeStatus, 5000);
document.addEventListener('DOMContentLoaded', handleFormDateChange);
</script>

<?php render_footer(); ?>
