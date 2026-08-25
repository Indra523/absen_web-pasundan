<?php
// ============================================================
// HALAMAN UTAMA: Live Monitoring Absensi (Sleek UI & Real-Time Sync)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';

if (is_user_role()) {
    header("Location: user_profile.php");
    exit;
}

$conn = getDB();
$pesan_manual = "";

// PROSES TAMBAH LOG ABSEN MANUAL (Hanya Superadmin - Mode Darurat / Maintenance Mesin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tambah_absen_manual') {
    csrf_verify();
    
    if (!is_superadmin()) {
        $pesan_manual = "<div style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>⛔ <b>Akses Ditolak:</b> Hanya Superadmin yang berhak menambahkan log absen manual.</div>";
    } else {
        $pin_m        = trim($_POST['pin_manual'] ?? '');
        $tgl_m        = trim($_POST['tgl_manual'] ?? '');
        $jam_m        = trim($_POST['jam_manual'] ?? '');
        $status_m     = trim($_POST['status_manual'] ?? '0');
        $tipe_verif_m = trim($_POST['tipe_verifikasi_manual'] ?? '0');

        if (!empty($pin_m) && (!empty($tgl_m) && !empty($jam_m))) {
            if (strlen($jam_m) === 5) $jam_m .= ':00';
            $waktu_formatted = date('Y-m-d H:i:s', strtotime($tgl_m . ' ' . $jam_m));
            $status_clean    = in_array($status_m, ['0', '1']) ? $status_m : '0';
            $tipe_verif      = in_array($tipe_verif_m, ['0', '1', '15']) ? $tipe_verif_m : '0';

            $stmt_m = $conn->prepare("INSERT INTO log_absen (pin, waktu, status, tipe_verifikasi) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), tipe_verifikasi = VALUES(tipe_verifikasi)");
            $stmt_m->bind_param("ssss", $pin_m, $waktu_formatted, $status_clean, $tipe_verif);

            if ($stmt_m->execute()) {
                $tgl = $tgl_m; // Otomatis sesuaikan filter tanggal ke tanggal data yang diinput
                $tgl_fmt_id = date('d-m-Y H:i:s', strtotime($waktu_formatted));
                $pesan_manual = "<div style='background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;'>
                    <div>
                        ✅ <b>Berhasil Disimpan!</b> Log absen manual untuk PIN <b>" . h($pin_m) . "</b> (Waktu: <b>" . h($tgl_fmt_id) . "</b>) berhasil masuk ke database.<br>
                        <span style='font-size:12px; font-weight:normal; opacity:0.9;'>💡 <b>Informasi:</b> Live Monitoring diurutkan dari jam terbaru ke tertua. Data jam sebelumnya tersusun sesuai kronologi urutan jamnya.</span>
                    </div>
                    <button type='button' class='btn' style='background:#155724; color:#fff; font-size:12px; padding:6px 12px; border:none;' onclick=\"findNewlyAddedLog('" . h($pin_m) . "', '" . h($tgl_m) . "')\">
                        🔍 Sorot / Filter Data Ini
                    </button>
                </div>";
            } else {
                $pesan_manual = "<div style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>⛔ <b>Gagal:</b> " . h($conn->error) . "</div>";
            }
        } else {
            $pesan_manual = "<div style='background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>Harap pilih karyawan dan tentukan waktu absen!</div>";
        }
    }
}

// PROSES HAPUS LOG ABSEN (Hanya Superadmin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus_log_absen') {
    csrf_verify();
    
    if (!is_superadmin()) {
        $pesan_manual = "<div style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>⛔ <b>Akses Ditolak:</b> Hanya Superadmin yang berhak menghapus log absen.</div>";
    } else {
        $id_hapus = (int)($_POST['id_log_hapus'] ?? 0);
        if ($id_hapus > 0) {
            $stmt_del = $conn->prepare("DELETE FROM log_absen WHERE id = ?");
            $stmt_del->bind_param("i", $id_hapus);
            if ($stmt_del->execute()) {
                $pesan_manual = "<div style='background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>✅ <b>Berhasil!</b> Data log absen telah berhasil dihapus.</div>";
            } else {
                $pesan_manual = "<div style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>⛔ <b>Gagal menghapus:</b> " . h($conn->error) . "</div>";
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tukar_status_log') {
    csrf_verify();
    
    if (!is_superadmin()) {
        $pesan_manual = "<div style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>⛔ <b>Akses Ditolak:</b> Hanya Superadmin yang berhak mengubah status log absen.</div>";
    } else {
        $id_toggle = (int)($_POST['id_log_toggle'] ?? 0);
        if ($id_toggle > 0) {
            $stmt_cur = $conn->prepare("SELECT status FROM log_absen WHERE id = ?");
            $stmt_cur->bind_param("i", $id_toggle);
            $stmt_cur->execute();
            $res_cur = $stmt_cur->get_result()->fetch_assoc();

            if ($res_cur) {
                $status_baru = ($res_cur['status'] === '0') ? '1' : '0';
                $status_label = ($status_baru === '0') ? '🟢 Masuk' : '🔴 Pulang';

                $stmt_upd = $conn->prepare("UPDATE log_absen SET status = ? WHERE id = ?");
                $stmt_upd->bind_param("si", $status_baru, $id_toggle);
                if ($stmt_upd->execute()) {
                    $pesan_manual = "<div style='background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>🔄 <b>Status Diperbarui!</b> Status log absen berhasil diubah menjadi <b>{$status_label}</b>.</div>";
                }
            }
        }
    }
}

// Initial Filter Parameters (Default: Tanggal Hari Ini)
$tgl           = trim($_GET['tgl'] ?? date('Y-m-d'));
$q             = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$export_url = "export_excel.php?" . http_build_query(['tgl' => $tgl, 'q' => $q, 'status' => $status_filter]);

// Ambil semua data karyawan untuk modal input manual
$karyawan_all = [];
if (is_superadmin()) {
    $res_k = $conn->query("SELECT pin, nama, departemen, tipe FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC");
    if ($res_k && $res_k->num_rows > 0) {
        while ($rk = $res_k->fetch_assoc()) {
            $karyawan_all[] = $rk;
        }
    }
}

render_header("Live Monitoring Absensi", "index");
?>

<?php echo $pesan_manual; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
<div style="background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; font-size:14px; font-weight:600; margin-bottom:20px;">
    ⚠️ <b>Akses Ditolak:</b> Halaman tersebut hanya dapat diakses oleh akun dengan wewenang <b>Superadmin</b>.
</div>
<?php endif; ?>

<?php if (is_tatausaha()): ?>
<div style="background:#fff7ed; color:#c2410c; border:1px solid #ffedd5; padding:12px 16px; border-radius:12px; font-size:13px; font-weight:600; margin-bottom:20px; display:flex; align-items:center; gap:8px;">
    <span>💼</span>
    <span><b>Akses Tata Usaha:</b> Live Monitoring ini khusus menampilkan presensi kategori <b>Karyawan</b> saja.</span>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- PANEL FILTER & KONTROL LIVE MONITORING -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#1e3a5f 100%); border-radius:18px; padding:24px 28px 22px; margin-bottom:22px; box-shadow:0 8px 32px rgba(15,23,42,.35); position:relative; overflow:hidden;">
    <!-- decorative circles -->
    <div style="position:absolute; top:-40px; right:-40px; width:160px; height:160px; border-radius:50%; background:rgba(99,102,241,.15); pointer-events:none;"></div>
    <div style="position:absolute; bottom:-30px; left:60px; width:100px; height:100px; border-radius:50%; background:rgba(16,185,129,.1); pointer-events:none;"></div>

    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:18px; position:relative; z-index:1;">
        <div>
            <div style="font-size:11px; font-weight:700; letter-spacing:2px; color:#94a3b8; text-transform:uppercase; margin-bottom:4px;">📡 REAL-TIME ABSENSI FEED</div>
            <div style="font-size:22px; font-weight:800; color:#f1f5f9; display:flex; align-items:center; gap:10px;">
                Live Monitoring Absensi
                <span id="pulse-dot" style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#10b981; box-shadow:0 0 0 4px rgba(16,185,129,.25); animation:pulse 2s infinite;"></span>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span style="background:rgba(255,255,255,.08); color:#cbd5e1; font-size:12px; font-weight:600; padding:6px 12px; border-radius:20px; border:1px solid rgba(255,255,255,.12); display:flex; align-items:center; gap:6px;">
                📟 Mesin: <b style="color:#fff;">Solution X606-S</b>
            </span>
            <span style="background:rgba(255,255,255,.08); color:#cbd5e1; font-size:12px; font-weight:600; padding:6px 12px; border-radius:20px; border:1px solid rgba(255,255,255,.12); display:flex; align-items:center; gap:6px;">
                🕒 Live: <b id="last-update-time" style="color:#38bdf8;">-</b>
            </span>
        </div>
    </div>

    <form id="search-form" method="GET" action="" onsubmit="return false;" style="position:relative; z-index:1;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:16px;">
            <!-- Filter Tanggal -->
            <div>
                <label for="tgl" style="display:block; font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">📅 Tanggal</label>
                <input type="date" id="tgl" name="tgl" value="<?php echo h($tgl === 'all' ? '' : $tgl); ?>" style="margin:0; background:#1e293b; color:#f1f5f9; border:1px solid #334155; border-radius:10px; padding:9px 12px; font-size:13px; font-weight:600; width:100%; outline:none;">
            </div>

            <!-- Pencarian -->
            <div style="grid-column: span 2;">
                <label for="q" style="display:block; font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">🔍 Cari Nama / PIN / Dept</label>
                <input type="text" id="q" name="q" value="<?php echo h($q); ?>" placeholder="Ketik nama pegawai, PIN, atau departemen..." style="margin:0; background:#1e293b; color:#f1f5f9; border:1px solid #334155; border-radius:10px; padding:9px 14px; font-size:13px; width:100%; outline:none;" autocomplete="off">
            </div>

            <!-- Filter Status -->
            <div>
                <label for="status" style="display:block; font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">Status Absensi</label>
                <select id="status" name="status" style="margin:0; background:#1e293b; color:#f1f5f9; border:1px solid #334155; border-radius:10px; padding:9px 12px; font-size:13px; font-weight:600; width:100%; outline:none; cursor:pointer;">
                    <option value="" style="background:#1e293b;">-- Semua Status --</option>
                    <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?> style="background:#1e293b;">🟢 Masuk</option>
                    <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?> style="background:#1e293b;">🔴 Pulang</option>
                </select>
            </div>
        </div>

        <!-- BAR PINTASAN & AKSI -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-top:14px; border-top:1px solid rgba(255,255,255,.1);">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <span style="font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:1px; text-transform:uppercase;">Pintasan:</span>
                <button type="button" onclick="setToday()" style="background:rgba(255,255,255,.08); color:#f1f5f9; border:1px solid rgba(255,255,255,.15); border-radius:8px; padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s;" onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">🗓️ Hari Ini</button>
                <button type="button" onclick="setAllDates()" style="background:rgba(255,255,255,.08); color:#f1f5f9; border:1px solid rgba(255,255,255,.15); border-radius:8px; padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s;" onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">📑 Semua Tanggal</button>
            </div>

            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <?php if (is_superadmin()): ?>
                <button type="button" onclick="bukaModalManualAbsen()" style="display:flex; align-items:center; gap:6px; background:linear-gradient(135deg,#6366f1,#4f46e5); color:#fff; border:none; border-radius:9px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; box-shadow:0 4px 14px rgba(99,102,241,.4);">
                    ✏️ Input Absen Manual
                </button>
                <?php endif; ?>

                <button type="button" id="btn-toggle-sync" onclick="toggleAutoSync()" style="display:flex; align-items:center; gap:6px; background:rgba(255,255,255,.08); color:#f1f5f9; border:1px solid rgba(255,255,255,.15); border-radius:9px; padding:9px 14px; font-size:13px; font-weight:700; cursor:pointer;">
                    <span id="sync-icon">🟢</span> <span id="sync-text">Auto-Sync (5s)</span>
                </button>
                <a id="btn-export" href="<?php echo h($export_url); ?>" style="display:flex; align-items:center; gap:6px; background:linear-gradient(135deg,#10b981,#059669); color:#fff; border-radius:9px; padding:9px 16px; font-size:13px; font-weight:700; text-decoration:none; box-shadow:0 4px 14px rgba(16,185,129,.4);">
                    📊 Export Excel
                </a>
            </div>
        </div>
    </form>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16,185,129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129, 0); }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- TABEL DATA LIVE FEED ABSENSI -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,.06); margin-bottom:24px;">
    <!-- Card Header Light & Clean -->
    <div style="background:#ffffff; border-bottom:1px solid #e2e8f0; padding:18px 24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <div style="font-size:16px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                📋 Log Presensi Kehadiran
            </div>
            <span style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:12px; font-weight:800; padding:3px 12px; border-radius:20px;">
                <span id="data-count">...</span> Record
            </span>
        </div>
        <div>
            <span id="filter-date-badge" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; font-size:12px; font-weight:700; padding:6px 14px; border-radius:20px;">
                📅 Tanggal: <?php echo h($tgl === 'all' ? 'Semua Tanggal' : date('d-m-Y', strtotime($tgl))); ?>
            </span>
        </div>
    </div>

    <div class="table-responsive" style="max-height:750px; overflow:auto;">
        <table style="font-size:13px; width:100%; border-collapse:collapse;">
            <thead style="position:sticky; top:0; z-index:10; background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                <tr>
                    <th style="background:#f8fafc; color:#475569; width:55px; padding:12px 10px; font-size:11px; font-weight:800; letter-spacing:0.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">NO</th>
                    <th style="background:#f8fafc; color:#475569; width:95px; padding:12px 10px; font-size:11px; font-weight:800; letter-spacing:0.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">PIN / ID</th>
                    <th style="background:#f8fafc; color:#475569; padding:12px 16px; font-size:11px; font-weight:800; letter-spacing:0.8px; text-transform:uppercase; text-align:left; border-right:1px solid #e2e8f0;">Nama Pegawai</th>
                    <th style="background:#f8fafc; color:#475569; width:180px; padding:12px 14px; font-size:11px; font-weight:800; letter-spacing:0.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">Waktu Absen</th>
                    <th style="background:#f8fafc; color:#475569; width:140px; padding:12px 14px; font-size:11px; font-weight:800; letter-spacing:0.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">Status Absensi</th>
                    <th style="background:#f8fafc; color:#475569; width:160px; padding:12px 14px; font-size:11px; font-weight:800; letter-spacing:0.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">Verifikasi</th>
                    <?php if (is_superadmin()): ?>
                    <th style="background:#f8fafc; color:#475569; width:90px; padding:12px 10px; font-size:11px; font-weight:800; letter-spacing:0.8px; text-transform:uppercase; text-align:center;">Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="tbody-monitoring">
                <tr>
                    <td colspan="<?php echo is_superadmin() ? '7' : '6'; ?>" style="padding:48px 30px; text-align:center; color:#94a3b8;">
                        <div style="font-size:32px; margin-bottom:10px;">⏳</div>
                        <div style="font-size:14px; font-weight:700; color:#64748b;">Memuat data absensi real-time...</div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= LIGHTBOX MODAL FOTO SELFIE MOBILE ================= -->
<style>
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
    animation: fadeInModal 0.2s ease;
}

.photo-modal-card {
    background: #ffffff;
    border-radius: 24px;
    max-width: 420px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
    animation: scaleInModal 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.status-pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
}
.status-pill-masuk {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}
.status-pill-pulang {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fca5a5;
}

@keyframes fadeInModal { from { opacity: 0; } to { opacity: 1; } }
@keyframes scaleInModal { from { transform: scale(0.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>

<div class="photo-modal-overlay" id="photoModalOverlay" onclick="closePhotoLightbox(event)">
    <div class="photo-modal-card" onclick="event.stopPropagation()">
        <div style="position:relative; width:100%; aspect-ratio:4/3; background:#0f172a; display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <img id="lightboxImg" src="" alt="Bukti Foto Selfie" style="width:100%; height:100%; object-fit:cover;">
            <button type="button" onclick="closePhotoLightbox()" style="position:absolute; top:12px; right:12px; background:rgba(15,23,42,0.7); color:#fff; border:none; border-radius:50%; width:34px; height:34px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .15s;" onmouseover="this.style.background='rgba(15,23,42,0.9)'" onmouseout="this.style.background='rgba(15,23,42,0.7)'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div style="padding:18px 22px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px;">
                <span id="lightboxStatusBadge" class="status-pill-badge status-pill-masuk">Absen Masuk</span>
                <span style="font-size:11.5px; color:#10b981; font-weight:700; background:#dcfce7; padding:4px 10px; border-radius:8px;">Selfie Terverifikasi</span>
            </div>
            <div id="lightboxTimeText" style="font-size:13.5px; font-weight:800; color:#0f172a; margin-top:4px;">-</div>
            <div style="font-size:12px; color:#64748b; margin-top:2px;">Bukti absensi mandiri karyawan</div>
        </div>
    </div>
</div>

<?php if (is_superadmin()): ?>
<!-- ================= MODAL INPUT ABSEN MANUAL (SUPERADMIN ONLY) ================= -->
<div id="modal-manual-absen" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.7); backdrop-filter:blur(6px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:20px; width:100%; max-width:480px; margin:20px; box-shadow:0 30px 60px -12px rgba(0,0,0,0.35); overflow:hidden;">
        <!-- Modal Header -->
        <div style="background:linear-gradient(135deg,#1e293b,#0f172a); padding:20px 24px; display:flex; align-items:center; justify-content:space-between;">
            <div>
                <div style="font-size:11px; font-weight:700; color:#6366f1; letter-spacing:2px; text-transform:uppercase; margin-bottom:2px;">SUPERADMIN ACTION</div>
                <div style="font-size:17px; font-weight:800; color:#fff;">✏️ Input Log Absen Manual</div>
            </div>
            <button type="button" onclick="tutupModalManualAbsen()" style="background:rgba(255,255,255,.1); border:none; width:34px; height:34px; border-radius:50%; font-size:16px; cursor:pointer; color:#fff; display:flex; align-items:center; justify-content:center;">✕</button>
        </div>

        <div style="padding:24px;">
            <div style="margin-bottom:18px; font-size:12px; color:#475569; background:#eff6ff; padding:12px 14px; border-radius:12px; border:1px solid #bfdbfe; line-height:1.5;">
                💡 <b>Mode Darurat / Maintenance:</b> Gunakan fitur ini jika mesin rusak/piket. Data akan tersinkron otomatis ke Laporan & Riwayat Karyawan.
            </div>

            <form method="POST" action="index.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="tambah_absen_manual">

                <div style="margin-bottom:16px;">
                    <label for="pin_manual" style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:6px; text-transform:uppercase; letter-spacing:1px;">Pilih Pegawai:</label>
                    <select name="pin_manual" id="pin_manual" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-weight:600; outline:none;">
                        <?php if (empty($karyawan_all)): ?>
                            <option value="">-- Belum ada data pegawai --</option>
                        <?php else: ?>
                            <?php foreach ($karyawan_all as $ka): 
                                $dept_label = !empty($ka['departemen']) ? " — " . h($ka['departemen']) : "";
                                $tipe_badge = ($ka['tipe'] === 'guru') ? " [Guru]" : " [Karyawan]";
                            ?>
                                <option value="<?php echo h($ka['pin']); ?>">
                                    <?php echo h($ka['nama']) . $dept_label . $tipe_badge; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                    <div>
                        <label for="tgl_manual" style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:6px; text-transform:uppercase; letter-spacing:1px;">📅 Tanggal</label>
                        <input type="date" id="tgl_manual" name="tgl_manual" value="<?php echo date('Y-m-d'); ?>" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-weight:600; outline:none; box-sizing:border-box;">
                    </div>
                    <div>
                        <label for="jam_manual" style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:6px; text-transform:uppercase; letter-spacing:1px;">⏰ Jam Absen</label>
                        <input type="time" id="jam_manual" name="jam_manual" value="<?php echo date('H:i:s'); ?>" step="1" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-weight:600; outline:none; box-sizing:border-box;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:22px;">
                    <div>
                        <label for="status_manual" style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:6px; text-transform:uppercase; letter-spacing:1px;">Status Absen</label>
                        <select id="status_manual" name="status_manual" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-weight:600; outline:none;">
                            <option value="0">🟢 Masuk</option>
                            <option value="1">🔴 Pulang</option>
                        </select>
                    </div>
                    <div>
                        <label for="tipe_verifikasi_manual" style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:6px; text-transform:uppercase; letter-spacing:1px;">Verifikasi</label>
                        <select id="tipe_verifikasi_manual" name="tipe_verifikasi_manual" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-weight:600; outline:none;">
                            <option value="0">✏️ Manual Admin</option>
                            <option value="1">👆 Sidik Jari</option>
                            <option value="15">👤 Wajah</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="tutupModalManualAbsen()" style="flex:1; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:10px; padding:10px; font-size:13px; font-weight:700; cursor:pointer;">Batal</button>
                    <button type="submit" style="flex:2; background:linear-gradient(135deg,#3b82f6,#2563eb); color:#fff; border:none; border-radius:10px; padding:10px; font-size:13px; font-weight:800; cursor:pointer; box-shadow:0 4px 14px rgba(59,130,246,.3);">💾 Simpan Absen Manual</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- JAVASCRIPT AJAX REAL-TIME POLLING & DATE FILTER -->
<script>
let autoSyncActive = true;
let syncInterval = null;
let searchDebounce = null;
let currentTglMode = "<?php echo h($tgl); ?>";

const inputTgl = document.getElementById('tgl');
const inputQ = document.getElementById('q');
const selectStatus = document.getElementById('status');
const tbody = document.getElementById('tbody-monitoring');
const dataCount = document.getElementById('data-count');
const lastUpdateTime = document.getElementById('last-update-time');
const filterDateBadge = document.getElementById('filter-date-badge');
const btnExport = document.getElementById('btn-export');
const syncIcon = document.getElementById('sync-icon');
const syncText = document.getElementById('sync-text');
const btnToggleSync = document.getElementById('btn-toggle-sync');

// Fungsi Modal Input Manual
function bukaModalManualAbsen() {
    const modal = document.getElementById('modal-manual-absen');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function tutupModalManualAbsen() {
    const modal = document.getElementById('modal-manual-absen');
    if (modal) {
        modal.style.display = 'none';
    }
}

const modalManual = document.getElementById('modal-manual-absen');
if (modalManual) {
    modalManual.addEventListener('click', function(e) {
        if (e.target === this) tutupModalManualAbsen();
    });
}

// Fungsi utama mengambil data via AJAX (Fetch API)
function fetchMonitoringData() {
    let tglVal = currentTglMode;
    if (currentTglMode !== 'all') {
        tglVal = inputTgl.value;
    }

    const qVal = inputQ.value.trim();
    const statusVal = selectStatus.value;

    // Update Label Filter Tanggal
    if (currentTglMode === 'all') {
        filterDateBadge.textContent = '📅 Tanggal: Semua Tanggal';
    } else if (tglVal) {
        const parts = tglVal.split('-');
        if (parts.length === 3) {
            filterDateBadge.textContent = '📅 Tanggal: ' + parts[2] + '-' + parts[1] + '-' + parts[0];
        }
    }

    // Update URL export secara dinamis
    const params = new URLSearchParams({ tgl: tglVal, q: qVal, status: statusVal });
    btnExport.href = 'export_excel.php?' + params.toString();

    fetch('api_monitoring.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                tbody.innerHTML = data.html;
                dataCount.textContent = data.count;
                lastUpdateTime.textContent = data.last_update;
            }
        })
        .catch(err => {
            console.error('AJAX polling error:', err);
        });
}

// Fungsi Sorot / Filter Data Baru
function findNewlyAddedLog(pin, tgl) {
    if (tgl) {
        inputTgl.value = tgl;
        currentTglMode = tgl;
    }
    inputQ.value = pin;
    fetchMonitoringData();
}

// Shortcut Filter Tanggal
function setToday() {
    const today = new Date().toISOString().split('T')[0];
    inputTgl.value = today;
    currentTglMode = today;
    fetchMonitoringData();
}

function setAllDates() {
    inputTgl.value = '';
    currentTglMode = 'all';
    fetchMonitoringData();
}

// Toggle Auto Sync (Pause / Resume)
function toggleAutoSync() {
    autoSyncActive = !autoSyncActive;
    const pulseDot = document.getElementById('pulse-dot');
    if (autoSyncActive) {
        syncIcon.textContent = '🟢';
        syncText.textContent = 'Auto-Sync (5s)';
        btnToggleSync.style.background = 'rgba(255,255,255,.08)';
        btnToggleSync.style.borderColor = 'rgba(255,255,255,.15)';
        if (pulseDot) pulseDot.style.background = '#10b981';
        startPolling();
    } else {
        syncIcon.textContent = '⏸️';
        syncText.textContent = 'Sync Paused';
        btnToggleSync.style.background = '#fef3c7';
        btnToggleSync.style.color = '#92400e';
        btnToggleSync.style.borderColor = '#fde68a';
        if (pulseDot) pulseDot.style.background = '#f59e0b';
        stopPolling();
    }
}

function startPolling() {
    stopPolling();
    syncInterval = setInterval(() => {
        if (autoSyncActive) {
            fetchMonitoringData();
        }
    }, 5000);
}

function stopPolling() {
    if (syncInterval) {
        clearInterval(syncInterval);
        syncInterval = null;
    }
}

// Event Listeners
inputTgl.addEventListener('change', () => {
    currentTglMode = inputTgl.value;
    fetchMonitoringData();
});

inputQ.addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        fetchMonitoringData();
    }, 300);
});

selectStatus.addEventListener('change', () => {
    fetchMonitoringData();
});

// Lightbox Modal Foto Selfie Mobile
function openPhotoLightbox(src, timeTxt, statusTxt, empName, gpsUrl, ipAddress) {
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

// Init
document.addEventListener('DOMContentLoaded', () => {
    fetchMonitoringData();
    startPolling();
});
</script>

<?php render_footer(); ?>