<?php
// ============================================================
// PORTAL MANDIRI - PENGAJUAN CUTI / IZIN / SAKIT (ROLE USER)
// Redesain Bersih, Elegan, Modern & Responsif Mobile (Anti-Over/Clunky)
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
                $upload_error = "Format file surat dokter tidak valid! Gunakan JPG, PNG, WEBP, atau PDF.";
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
            $upload_error = "Terjadi kesalahan saat mengunggah file surat dokter.";
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
        $pesan_error = "Maksimal durasi pengajuan perizinan adalah 31 hari!";
    } elseif (!empty($upload_error)) {
        $pesan_error = $upload_error;
    } elseif ($tipe_izin === 'sakit' && $diff_days > 2 && empty($surat_dokter_path)) {
        $pesan_error = "Perhatian: Izin sakit lebih dari 2 hari ({$diff_days} Hari) <b>WAJIB</b> melampirkan Surat Keterangan Dokter!";
    } else {
        // Cek bentrokan dengan perizinan yang sudah DISETUJUI
        $stmt_check = $conn->prepare("SELECT tanggal, tgl_selesai FROM perizinan WHERE pin = ? AND ? <= COALESCE(tgl_selesai, tanggal) AND ? >= tanggal AND status_persetujuan = 'disetujui'");
        $stmt_check->bind_param("sss", $pin, $tgl_mulai, $tgl_selesai);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check->num_rows > 0) {
            $pesan_error = "Pengajuan gagal! Periode ini berbenturan dengan perizinan yang sudah <b>DISETUJUI</b> sebelumnya.";
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO perizinan (pin, tanggal, tgl_selesai, tipe_izin, keterangan, surat_dokter, status_persetujuan, created_by) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?) ON DUPLICATE KEY UPDATE tgl_selesai = VALUES(tgl_selesai), tipe_izin = VALUES(tipe_izin), keterangan = VALUES(keterangan), surat_dokter = COALESCE(VALUES(surat_dokter), surat_dokter), status_persetujuan = 'pending', created_by = VALUES(created_by)");
            $stmt_ins->bind_param("sssssss", $pin, $tgl_mulai, $tgl_selesai, $tipe_izin, $keterangan, $surat_dokter_path, $username);

            if ($stmt_ins->execute()) {
                $dur_fmt = ($diff_days > 1) 
                    ? "{$diff_days} Hari (" . date('d/m/Y', $start_ts) . " s.d " . date('d/m/Y', $end_ts) . ")" 
                    : date('d F Y', $start_ts);

                $pesan_sukses = "Pengajuan <b>" . strtoupper($tipe_izin) . "</b> untuk <b>" . $dur_fmt . "</b> telah berhasil dikirim.";
                log_audit("USER_INPUT_PERIZINAN", "User {$username} (PIN {$pin}) mengajukan " . strtoupper($tipe_izin) . " periode {$tgl_mulai} s.d {$tgl_selesai} ({$diff_days} hari)" . (!empty($surat_dokter_path) ? " + Surat Dokter" : ""));

                // NOTIFIKASI REAL-TIME UNTUK ADMIN & TU
                $nama_pemohon = $detail_user['nama'] ?? $username;
                $notif_title  = "Pengajuan " . ucfirst($tipe_izin) . " Baru (" . $diff_days . " Hari)";
                $notif_msg    = "{$nama_pemohon} (PIN {$pin}) mengajukan " . strtoupper($tipe_izin) . " periode " . date('d/m/Y', $start_ts) . ($diff_days > 1 ? " s.d " . date('d/m/Y', $end_ts) : "") . (!empty($surat_dokter_path) ? " (Ada Surat Dokter)" : "");
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
/* ===== REFINED, SLEEK & MOBILE-FRIENDLY THEME ===== */
.izin-wrapper {
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 1120px;
    margin: 0 auto 30px auto;
    width: 100%;
}

/* 4 STAT SUMMARY CARDS (CLEAN & COMPACT) */
.stats-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

@media (max-width: 768px) {
    .stats-cards-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
}

.stat-card-clean {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.stat-icon-wrap {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-metric-title {
    font-size: 10.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    line-height: 1.2;
}

.stat-metric-value {
    font-size: 18px;
    font-weight: 800;
    line-height: 1.1;
    color: #0f172a;
    margin-top: 2px;
}

/* TWO COLUMNS LAYOUT */
.user-izin-grid {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 16px;
    align-items: start;
}

@media (max-width: 960px) {
    .user-izin-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
}

/* FORM CARD */
.form-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}

.form-header-clean {
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.form-title-text {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-body-padding {
    padding: 16px 18px;
}

/* COMPACT PROFILE CHIP */
.user-profile-chip {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    margin-bottom: 14px;
}

.user-avatar-initial {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #2563eb;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 13px;
    flex-shrink: 0;
}

/* SLEEK INPUTS */
.form-label-clean {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 4px;
    display: block;
}

.input-date-clean {
    width: 100% !important;
    padding: 8px 10px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    color: #0f172a !important;
    background: #ffffff !important;
    font-weight: 600 !important;
    outline: none !important;
    box-sizing: border-box !important;
    min-width: 0 !important;
    font-family: inherit !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}

.input-date-clean:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1) !important;
}

/* DURATION INLINE CHIP */
.duration-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    margin: 4px 0 14px 0;
}

/* SEGMENTED CONTROL (TAB PILL) UNTUK JENIS IZIN */
.type-segmented-control {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 10px;
    gap: 3px;
    margin-bottom: 14px;
}

.segmented-option {
    padding: 8px 4px;
    text-align: center;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    transition: all 0.15s ease;
    user-select: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
}

.segmented-option.active {
    background: #ffffff;
    color: #0f172a;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.segmented-option.active.card-cuti { color: #1d4ed8; }
.segmented-option.active.card-izin { color: #c2410c; }
.segmented-option.active.card-sakit { color: #7e22ce; }

.seg-title {
    font-size: 12.5px;
    font-weight: 800;
}

.seg-sub {
    font-size: 9.5px;
    opacity: 0.8;
}

/* SURAT DOKTER BOX */
.doctor-upload-box {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 14px;
}

.doctor-upload-box.required {
    background: #fffbeb;
    border-color: #f59e0b;
}

.doctor-upload-box.optional {
    background: #f0fdf4;
    border-color: #86efac;
}

/* TEXTAREA */
.textarea-clean {
    width: 100% !important;
    padding: 8px 10px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    color: #0f172a !important;
    background: #ffffff !important;
    font-family: inherit !important;
    resize: vertical !important;
    min-height: 65px !important;
    box-sizing: border-box !important;
    outline: none !important;
}

.textarea-clean:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1) !important;
}

/* SUBMIT BUTTON */
.btn-submit-clean {
    width: 100%;
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 10px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.2);
    transition: background 0.15s ease;
}
.btn-submit-clean:hover {
    background: #1d4ed8;
}

/* TABLE CARD */
.table-card-clean {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}

.table-header-clean {
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.table-compact {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    min-width: 580px;
}

.table-compact thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 10.5px;
    letter-spacing: 0.5px;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    text-align: center;
}

.table-compact td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    text-align: center;
    color: #334155;
}

.table-compact tbody tr:hover {
    background: #f8fafc;
}

/* LIGHTBOX MODAL */
.photo-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(4px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.photo-modal-card {
    background: #ffffff;
    border-radius: 14px;
    max-width: 480px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}
</style>

<div class="izin-wrapper">

    <!-- TOAST NOTIFICATIONS -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 16px; color:#15803d; font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; border-radius:10px; padding:12px 16px; color:#991b1b; font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
        </div>
    <?php endif; ?>

    <?php if (empty($pin) || !$detail_user): ?>
        <div class="form-card" style="text-align:center; padding:40px 20px;">
            <div style="font-size:16px; font-weight:800; color:#0f172a; margin-bottom:6px;">Akun Belum Terhubung PIN Karyawan</div>
            <p style="color:#64748b; font-size:13px; max-width:380px; margin:0 auto;">
                Akun Anda belum terhubung ke data master karyawan. Silakan hubungi Administrator.
            </p>
        </div>
    <?php else: ?>

    <!-- 4 KPI SUMMARY CARDS -->
    <div class="stats-cards-grid">
        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#eff6ff; color:#2563eb;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Total Berkas</div>
                <div class="stat-metric-value" id="stat_total"><?php echo $stat_count['total']; ?></div>
            </div>
        </div>

        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#f0fdf4; color:#16a34a;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Disetujui</div>
                <div class="stat-metric-value" style="color:#16a34a;" id="stat_disetujui"><?php echo $stat_count['disetujui']; ?></div>
            </div>
        </div>

        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#fffbeb; color:#d97706;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Menunggu</div>
                <div class="stat-metric-value" style="color:#d97706;" id="stat_pending"><?php echo $stat_count['pending']; ?></div>
            </div>
        </div>

        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#fff1f2; color:#e11d48;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Ditolak</div>
                <div class="stat-metric-value" style="color:#e11d48;" id="stat_ditolak"><?php echo $stat_count['ditolak']; ?></div>
            </div>
        </div>
    </div>

    <!-- MAIN GRID LAYOUT -->
    <div class="user-izin-grid">

        <!-- LEFT: FORM PENGAJUAN IZIN -->
        <div class="form-card">
            <div class="form-header-clean">
                <div class="form-title-text">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <span>Form Pengajuan Izin</span>
                </div>
            </div>

            <div class="form-body-padding">
                <!-- COMPACT PROFILE CHIP -->
                <div class="user-profile-chip">
                    <div class="user-avatar-initial">
                        <?php echo strtoupper(mb_substr($detail_user['nama'], 0, 1)); ?>
                    </div>
                    <div style="min-width:0; flex:1;">
                        <div style="font-size:12.5px; font-weight:800; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo h($detail_user['nama']); ?></div>
                        <div style="font-size:11px; color:#64748b;">PIN: <b style="color:#0f172a;"><?php echo h($detail_user['pin']); ?></b> &bull; <?php echo h($detail_user['departemen'] ?: 'Umum'); ?></div>
                    </div>
                </div>

                <form method="POST" action="user_izin.php<?php echo !empty($_GET['pin']) ? '?pin=' . urlencode($_GET['pin']) : ''; ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="simpan_izin_mandiri">

                    <!-- RENTANG TANGGAL (2 KOLOM RAPI TANPA OVERFLOW) -->
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:6px;">
                        <div style="min-width:0;">
                            <label for="tgl_mulai" class="form-label-clean">Dari Tanggal</label>
                            <input type="date" id="tgl_mulai" name="tgl_mulai" value="<?php echo date('Y-m-d'); ?>" class="input-date-clean" onchange="handleDateChange()" required>
                        </div>
                        <div style="min-width:0;">
                            <label for="tgl_selesai" class="form-label-clean">Sampai Tanggal</label>
                            <input type="date" id="tgl_selesai" name="tgl_selesai" value="<?php echo date('Y-m-d'); ?>" class="input-date-clean" onchange="handleDateChange()" required>
                        </div>
                    </div>

                    <!-- DURATION INLINE CHIP -->
                    <div>
                        <div id="durationPill" class="duration-chip">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span id="durationText">Durasi: 1 Hari</span>
                        </div>
                    </div>

                    <!-- JENIS PERIZINAN (SEGMENTED PILL CONTROL) -->
                    <div style="margin-bottom:12px;">
                        <label class="form-label-clean">Jenis Perizinan</label>
                        <input type="hidden" id="tipe_izin_val" name="tipe_izin" value="cuti" required>

                        <div class="type-segmented-control">
                            <div class="segmented-option active card-cuti" id="card_cuti" onclick="setIzinType('cuti')">
                                <span class="seg-title">Cuti</span>
                                <span class="seg-sub">Resmi</span>
                            </div>
                            <div class="segmented-option" id="card_izin" onclick="setIzinType('izin')">
                                <span class="seg-title">Izin</span>
                                <span class="seg-sub">Pribadi/Dinas</span>
                            </div>
                            <div class="segmented-option" id="card_sakit" onclick="setIzinType('sakit')">
                                <span class="seg-title">Sakit</span>
                                <span class="seg-sub">Surat Dokter</span>
                            </div>
                        </div>
                    </div>

                    <!-- CONDITIONAL SURAT DOKTER UPLOAD -->
                    <div id="doctorUploadSection" class="doctor-upload-box optional" style="display:none;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                            <span style="font-size:11.5px; font-weight:800; color:#0f172a;">Surat Keterangan Dokter</span>
                            <span id="doctorReqBadge" style="font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px; background:#dcfce7; color:#15803d;">
                                OPSIONAL (1-2 HARI)
                            </span>
                        </div>

                        <div id="doctorAlertDesc" style="font-size:11px; line-height:1.35; color:#0369a1; margin-bottom:8px;">
                            Izin sakit 1-2 hari (istirahat ringan). Upload surat dokter bersifat opsional.
                        </div>

                        <input type="file" id="surat_dokter" name="surat_dokter" accept=".jpg,.jpeg,.png,.webp,.pdf" class="input-date-clean" style="font-size:11.5px; padding:6px 8px;" onchange="handleFileChange(this)">
                    </div>

                    <!-- KETERANGAN / ALASAN -->
                    <div style="margin-bottom:14px;">
                        <label for="keterangan" class="form-label-clean">Keterangan / Alasan</label>
                        <textarea id="keterangan" name="keterangan" rows="2" class="textarea-clean" placeholder="Jelaskan alasan izin secara singkat..." required></textarea>
                    </div>

                    <!-- SUBMIT BUTTON -->
                    <button type="submit" class="btn-submit-clean">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        <span>Kirim Pengajuan</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- RIGHT: RIWAYAT PENGAJUAN SAYA -->
        <div class="table-card-clean">
            <div class="table-header-clean">
                <div style="font-size:13.5px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:6px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Riwayat Pengajuan Saya</span>
                </div>
                <div style="font-size:10.5px; color:#166534; font-weight:700; background:#dcfce7; padding:2px 8px; border-radius:12px;">
                    Realtime Sync
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="table-compact">
                    <thead>
                        <tr>
                            <th style="width:35px;">NO</th>
                            <th style="text-align:left;">PERIODE</th>
                            <th>JENIS</th>
                            <th style="text-align:left;">KETERANGAN</th>
                            <th>SURAT DOKTER</th>
                            <th>STATUS</th>
                            <th>WAKTU</th>
                        </tr>
                    </thead>
                    <tbody id="user_izin_tbody">
                        <?php if (!empty($list_izin)):
                            $no = 1;
                            foreach ($list_izin as $row):
                                $t_iz = $row['tipe_izin'];
                                $t_bg = '#eff6ff'; $t_col = '#1d4ed8'; $t_brd = '#bfdbfe';
                                if ($t_iz === 'izin') { $t_bg = '#fff7ed'; $t_col = '#c2410c'; $t_brd = '#ffedd5'; }
                                if ($t_iz === 'sakit') { $t_bg = '#faf5ff'; $t_col = '#7e22ce'; $t_brd = '#e9d5ff'; }

                                $st_p = $row['status_persetujuan'] ?? 'disetujui';
                                $p_bg = '#dcfce7'; $p_col = '#15803d'; $p_lbl = 'Disetujui';
                                if ($st_p === 'pending') { $p_bg = '#fef3c7'; $p_col = '#92400e'; $p_lbl = 'Menunggu'; }
                                if ($st_p === 'ditolak') { $p_bg = '#fee2e2'; $p_col = '#be123c'; $p_lbl = 'Ditolak'; }

                                $tgl_m = date('d/m/Y', strtotime($row['tanggal']));
                                $tgl_s = !empty($row['tgl_selesai']) ? date('d/m/Y', strtotime($row['tgl_selesai'])) : $tgl_m;
                                $dur_days = (strtotime($row['tgl_selesai'] ?: $row['tanggal']) - strtotime($row['tanggal'])) / 86400 + 1;
                                
                                $tgl_txt = ($tgl_m === $tgl_s) 
                                    ? "<div style='font-weight:700; color:#0f172a;'>{$tgl_m}</div><div style='font-size:10px; color:#64748b;'>1 Hari</div>" 
                                    : "<div style='font-weight:700; color:#0f172a;'>{$tgl_m} - {$tgl_s}</div><div style='font-size:10px; color:#2563eb; font-weight:700;'>{$dur_days} Hari</div>";

                                $td_surat = '<span style="color:#cbd5e1;">-</span>';
                                if (!empty($row['surat_dokter']) && file_exists(__DIR__ . '/' . $row['surat_dokter'])) {
                                    $file_ext = strtolower(pathinfo($row['surat_dokter'], PATHINFO_EXTENSION));
                                    $file_url = h($row['surat_dokter']);
                                    if ($file_ext === 'pdf') {
                                        $td_surat = "<a href='{$file_url}' target='_blank' style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:2px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-decoration:none;'>Lihat PDF</a>";
                                    } else {
                                        $td_surat = "<button type='button' onclick=\"openDoctorLightbox('{$file_url}', 'Surat Keterangan Dokter')\" style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:2px 6px; border-radius:4px; font-size:10.5px; font-weight:700; cursor:pointer;'>Lihat Foto</button>";
                                    }
                                } elseif ($t_iz === 'sakit' && $dur_days <= 2) {
                                    $td_surat = "<span style='font-size:10px; color:#64748b;'>Istirahat</span>";
                                }
                        ?>
                            <tr id="row_izin_<?php echo $row['id']; ?>" data-status="<?php echo $st_p; ?>">
                                <td><?php echo $no++; ?></td>
                                <td style="text-align:left;"><?php echo $tgl_txt; ?></td>
                                <td>
                                    <span style="background:<?php echo $t_bg; ?>; color:<?php echo $t_col; ?>; border:1px solid <?php echo $t_brd; ?>; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700;">
                                        <?php echo ucfirst($t_iz); ?>
                                    </span>
                                </td>
                                <td style="text-align:left; color:#334155; line-height:1.35; font-size:12px;">
                                    <?php echo h($row['keterangan'] ?: '-'); ?>
                                </td>
                                <td><?php echo $td_surat; ?></td>
                                <td>
                                    <span style="background:<?php echo $p_bg; ?>; color:<?php echo $p_col; ?>; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700;">
                                        <?php echo $p_lbl; ?>
                                    </span>
                                </td>
                                <td style="font-size:10.5px; color:#64748b;">
                                    <?php echo date('d/m H:i', strtotime($row['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="7" style="padding:30px; color:#94a3b8; text-align:center; font-size:12px;">Belum ada riwayat perizinan yang diajukan.</td>
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
            <button type="button" onclick="closeDoctorLightbox()" style="position:absolute; top:8px; right:8px; background:rgba(15,23,42,0.75); color:#fff; border:none; border-radius:50%; width:28px; height:28px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                &times;
            </button>
        </div>
        <div style="padding:12px 16px; display:flex; align-items:center; justify-content:space-between;">
            <div id="doctorModalTitle" style="font-size:12px; font-weight:700; color:#0f172a;">Surat Keterangan Dokter</div>
            <a id="doctorModalDownload" href="" target="_blank" download style="font-size:11.5px; font-weight:700; color:#2563eb; text-decoration:none;">Download File</a>
        </div>
    </div>
</div>

<script>
let currentDiffDays = 1;

function setIzinType(type) {
    document.getElementById('tipe_izin_val').value = type;
    document.querySelectorAll('.segmented-option').forEach(el => {
        el.classList.remove('active', 'card-cuti', 'card-izin', 'card-sakit');
    });
    
    const activeEl = document.getElementById('card_' + type);
    if (activeEl) {
        activeEl.classList.add('active', 'card-' + type);
    }

    handleDateChange();
}

function handleDateChange() {
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
        textEl.textContent = 'Tanggal selesai tidak valid';
        currentDiffDays = 0;
        return;
    }

    const diffTime = Math.abs(d2 - d1);
    currentDiffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

    pill.style.background = '#eff6ff';
    pill.style.borderColor = '#bfdbfe';
    pill.style.color = '#1d4ed8';

    if (currentDiffDays === 1) {
        textEl.textContent = 'Durasi: 1 Hari';
    } else {
        textEl.textContent = `Durasi: ${currentDiffDays} Hari`;
    }

    const doctorSec = document.getElementById('doctorUploadSection');
    const reqBadge = document.getElementById('doctorReqBadge');
    const alertDesc = document.getElementById('doctorAlertDesc');
    const fileInput = document.getElementById('surat_dokter');

    if (currentType === 'sakit') {
        doctorSec.style.display = 'block';

        if (currentDiffDays > 2) {
            doctorSec.className = 'doctor-upload-box required';
            reqBadge.style.background = '#fef3c7';
            reqBadge.style.color = '#92400e';
            reqBadge.textContent = 'WAJIB (> 2 HARI)';
            alertDesc.style.color = '#92400e';
            alertDesc.innerHTML = `Izin sakit selama <b>${currentDiffDays} hari</b> wajib melampirkan Surat Dokter.`;
            fileInput.required = (fileInput.value === '');
        } else {
            doctorSec.className = 'doctor-upload-box optional';
            reqBadge.style.background = '#dcfce7';
            reqBadge.style.color = '#15803d';
            reqBadge.textContent = 'OPSIONAL (1-2 HARI)';
            alertDesc.style.color = '#15803d';
            alertDesc.innerHTML = 'Izin sakit 1-2 hari (istirahat ringan). Upload surat dokter bersifat opsional.';
            fileInput.required = false;
        }
    } else {
        doctorSec.style.display = 'none';
        fileInput.required = false;
    }
}

function handleFileChange(input) {
    if (input.files && input.files[0]) {
        input.required = false;
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

// REALTIME POLLING
const targetPin = "<?php echo h($pin); ?>";
function pollRealtimeStatus() {
    if (!targetPin) return;

    fetch('api_user_izin.php?pin=' + encodeURIComponent(targetPin))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('stat_total').textContent = data.stat.total;
                document.getElementById('stat_disetujui').textContent = data.stat.disetujui;
                document.getElementById('stat_pending').textContent = data.stat.pending;
                document.getElementById('stat_ditolak').textContent = data.stat.ditolak;
            }
        })
        .catch(err => console.error('Sync error:', err));
}

setInterval(pollRealtimeStatus, 5000);
document.addEventListener('DOMContentLoaded', handleDateChange);
</script>

<?php render_footer(); ?>
