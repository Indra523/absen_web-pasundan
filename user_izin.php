<?php
// ============================================================
// PORTAL MANDIRI - PENGAJUAN CUTI / IZIN / SAKIT (ROLE USER)
// Fitur: Form pengajuan multi-day (1 berkas), list riwayat, real-time sync
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

if (empty($pin) || is_superadmin() || is_rnd()) {
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

// PROSES POST PENGAJUAN PERIZINAN MANDIRI (SINGLE / MULTI-DAY IN 1 ROW)
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

    $start_ts = strtotime($tgl_mulai);
    $end_ts   = strtotime($tgl_selesai);
    $diff_days = ceil(($end_ts - $start_ts) / 86400) + 1;

    if (empty($pin)) {
        $pesan_error = "Akun Anda belum terhubung dengan PIN Karyawan. Pengajuan gagal!";
    } elseif (empty($tgl_mulai) || empty($tipe_izin)) {
        $pesan_error = "Tanggal dan jenis perizinan wajib diisi!";
    } elseif (!in_array($tipe_izin, ['cuti', 'izin', 'sakit'])) {
        $pesan_error = "Jenis perizinan tidak valid!";
    } elseif ($diff_days > 31) {
        $pesan_error = "Maksimal durasi pengajuan perizinan sekaligus adalah 31 hari!";
    } else {
        // Cek bentrokan dengan perizinan yang sudah DISETUJUI
        $stmt_check = $conn->prepare("SELECT tanggal, tgl_selesai FROM perizinan WHERE pin = ? AND ? <= COALESCE(tgl_selesai, tanggal) AND ? >= tanggal AND status_persetujuan = 'disetujui'");
        $stmt_check->bind_param("sss", $pin, $tgl_mulai, $tgl_selesai);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check->num_rows > 0) {
            $pesan_error = "Pengajuan gagal! Periode ini berbenturan dengan perizinan yang sudah <b>DISETUJUI</b> sebelumnya.";
        } else {
            // Simpan 1 ROW perizinan untuk rentang tanggal tersebut
            $stmt_ins = $conn->prepare("INSERT INTO perizinan (pin, tanggal, tgl_selesai, tipe_izin, keterangan, status_persetujuan, created_by) VALUES (?, ?, ?, ?, ?, 'pending', ?) ON DUPLICATE KEY UPDATE tgl_selesai = VALUES(tgl_selesai), tipe_izin = VALUES(tipe_izin), keterangan = VALUES(keterangan), status_persetujuan = 'pending', created_by = VALUES(created_by)");
            $stmt_ins->bind_param("ssssss", $pin, $tgl_mulai, $tgl_selesai, $tipe_izin, $keterangan, $username);

            if ($stmt_ins->execute()) {
                $dur_fmt = ($diff_days > 1) 
                    ? "{$diff_days} Hari (" . date('d/m/Y', $start_ts) . " s.d " . date('d/m/Y', $end_ts) . ")" 
                    : date('d F Y', $start_ts);

                $pesan_sukses = "Pengajuan " . strtoupper($tipe_izin) . " untuk <b>" . $dur_fmt . "</b> telah berhasil dikirim.";
                log_audit("USER_INPUT_PERIZINAN", "User {$username} (PIN {$pin}) mengajukan " . strtoupper($tipe_izin) . " periode {$tgl_mulai} s.d {$tgl_selesai} ({$diff_days} hari)");

                // TRIGGER 1 NOTIFIKASI REAL-TIME UNTUK ADMIN
                $nama_pemohon = $detail_user['nama'] ?? $username;
                $notif_title  = "Pengajuan " . ucfirst($tipe_izin) . " Baru (" . $diff_days . " Hari)";
                $notif_msg    = "{$nama_pemohon} (PIN {$pin}) mengajukan " . strtoupper($tipe_izin) . " periode " . date('d/m/Y', $start_ts) . ($diff_days > 1 ? " s.d " . date('d/m/Y', $end_ts) . " ({$diff_days} Hari)" : "");
                $notif_link   = "kelola_izin.php";
                $applicant_uid = (int)($_SESSION['user_id'] ?? 0);

                $stmt_n = $conn->prepare("INSERT INTO notifications (user_id, target_role, title, message, type, link) VALUES (?, 'all', ?, ?, 'perizinan', ?)");
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

render_header("Pengajuan Perizinan", "user_izin");
?>

<style>
/* CSS FIX RESPONSIVE & OVERFLOW DATES */
.user-izin-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 1024px) {
    .user-izin-grid {
        grid-template-columns: 1fr;
    }
}

.izin-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}
.izin-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

/* FIX DATE INPUT OVERFLOW */
.date-range-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 12px;
}
.date-range-grid > div {
    min-width: 0;
}

.type-selector-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 6px;
    margin-bottom: 16px;
}
@media (max-width: 480px) {
    .type-selector-grid {
        grid-template-columns: 1fr;
    }
}
.type-card-option {
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 10px 4px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #fff;
    user-select: none;
    min-width: 0;
}
.type-card-option:hover {
    border-color: #3b82f6;
    background: #eff6ff;
}
.type-card-option.active {
    border-color: #2563eb;
    background: #eff6ff;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
}
.type-title {
    font-size: 12.5px;
    font-weight: 700;
    color: #0f172a;
    margin-top: 2px;
}
.type-desc {
    font-size: 10px;
    color: #64748b;
    margin-top: 2px;
}
.form-input-custom {
    width: 100%;
    box-sizing: border-box;
    min-width: 0;
    padding: 9px 10px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 12.5px;
    color: #0f172a;
    background: #fff;
    margin-bottom: 14px;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-input-custom:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    outline: none;
}
.form-label-custom {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 5px;
    display: block;
}
.toast-notice-success {
    background: linear-gradient(135deg, #dcfce7, #f0fdf4);
    border: 1px solid #86efac;
    color: #15803d;
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-weight: 600;
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.toast-notice-error {
    background: linear-gradient(135deg, #fee2e2, #fef2f2);
    border: 1px solid #fca5a5;
    color: #be123c;
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-weight: 600;
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.pulse-live-dot {
    width: 8px; height: 8px;
    background: #22c55e;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
    animation: pulseDot 2s infinite;
}
@keyframes pulseDot {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}
</style>

<?php if (!empty($pesan_sukses)): ?>
    <div class="toast-notice-success"><span style="font-size:18px;">✓</span> <?php echo $pesan_sukses; ?></div>
<?php endif; ?>

<?php if (!empty($pesan_error)): ?>
    <div class="toast-notice-error"><span style="font-size:18px;">✕</span> <?php echo $pesan_error; ?></div>
<?php endif; ?>

<?php if (empty($pin) || !$detail_user): ?>
    <div class="izin-card" style="text-align:center; padding:60px 20px;">
        <div style="font-size:40px; color:#cbd5e1; margin-bottom:12px;">👤</div>
        <h3 style="font-size:18px; font-weight:700; color:#0f172a; margin-bottom:8px;">Akun Belum Terhubung Karyawan</h3>
        <p style="color:#64748b; font-size:13.5px; max-width:440px; margin:0 auto;">
            Akun <code><?php echo h($_SESSION['username']); ?></code> belum terhubung ke data PIN karyawan. Hubungi Administrator.
        </p>
    </div>
<?php else: ?>

<!-- RINGKASAN STATISTIK IZIN SAYA -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:20px;">
    <div class="izin-card" style="padding:16px 20px;">
        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Total Berkas</div>
        <div style="font-size:22px; font-weight:800; color:#0f172a; margin-top:2px;" id="stat_total"><?php echo $stat_count['total']; ?> <span style="font-size:12px; font-weight:500; color:#64748b;">Berkas</span></div>
    </div>
    <div class="izin-card" style="padding:16px 20px;">
        <div style="font-size:11px; color:#15803d; font-weight:700; text-transform:uppercase;">Disetujui</div>
        <div style="font-size:22px; font-weight:800; color:#15803d; margin-top:2px;" id="stat_disetujui"><?php echo $stat_count['disetujui']; ?></div>
    </div>
    <div class="izin-card" style="padding:16px 20px;">
        <div style="font-size:11px; color:#b45309; font-weight:700; text-transform:uppercase;">Menunggu Approval</div>
        <div style="font-size:22px; font-weight:800; color:#d97706; margin-top:2px;" id="stat_pending"><?php echo $stat_count['pending']; ?></div>
    </div>
    <div class="izin-card" style="padding:16px 20px;">
        <div style="font-size:11px; color:#be123c; font-weight:700; text-transform:uppercase;">Ditolak</div>
        <div style="font-size:22px; font-weight:800; color:#be123c; margin-top:2px;" id="stat_ditolak"><?php echo $stat_count['ditolak']; ?></div>
    </div>
</div>

<div class="user-izin-grid">

    <!-- FORM PENGAJUAN IZIN SAYA -->
    <div class="izin-card">
        <div class="izin-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                Form Pengajuan Izin / Cuti
            </div>
        </div>

        <div style="padding: 18px;">
            <!-- PROFIL PEGAWAI SIMPEL -->
            <div style="display:flex; align-items:center; gap:10px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:16px;">
                <div style="width:36px; height:36px; border-radius:50%; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:15px; flex-shrink:0;">
                    <?php echo strtoupper(mb_substr($detail_user['nama'], 0, 1)); ?>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:12.5px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo h($detail_user['nama']); ?></div>
                    <div style="font-size:11px; color:#64748b;">PIN: <code style="font-weight:700; color:#0f172a;"><?php echo h($detail_user['pin']); ?></code> &bull; <?php echo h($detail_user['departemen'] ?: 'Umum'); ?></div>
                </div>
            </div>

            <form method="POST" action="user_izin.php<?php echo !empty($_GET['pin']) ? '?pin=' . urlencode($_GET['pin']) : ''; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="simpan_izin_mandiri">

                <!-- RENTANG TANGGAL PERIZINAN (PAS DI CARD) -->
                <div class="date-range-grid">
                    <div>
                        <label for="tgl_mulai" class="form-label-custom">Dari Tanggal</label>
                        <input type="date" id="tgl_mulai" name="tgl_mulai" value="<?php echo date('Y-m-d'); ?>" class="form-input-custom" style="margin-bottom:0;" onchange="updateDurasiInfo()" required>
                    </div>
                    <div>
                        <label for="tgl_selesai" class="form-label-custom">Sampai Tanggal</label>
                        <input type="date" id="tgl_selesai" name="tgl_selesai" value="<?php echo date('Y-m-d'); ?>" class="form-input-custom" style="margin-bottom:0;" onchange="updateDurasiInfo()" required>
                    </div>
                </div>

                <!-- INFO DURASI HARI -->
                <div id="durasi_badge" style="font-size:11.5px; font-weight:700; color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; padding:7px 10px; border-radius:8px; margin-bottom:14px; display:flex; align-items:center; gap:6px;">
                    📅 Durasi: <b>1 Hari</b> (Single Day)
                </div>

                <!-- JENIS PERIZINAN (RADIO CARDS) -->
                <div style="margin-bottom:14px;">
                    <label class="form-label-custom">Jenis Perizinan</label>
                    <input type="hidden" id="tipe_izin_val" name="tipe_izin" value="cuti" required>

                    <div class="type-selector-grid">
                        <div class="type-card-option active" id="card_cuti" onclick="selectType('cuti')">
                            <div style="font-size:16px;">🏖️</div>
                            <div class="type-title">Cuti</div>
                            <div class="type-desc">Resmi</div>
                        </div>

                        <div class="type-card-option" id="card_izin" onclick="selectType('izin')">
                            <div style="font-size:16px;">📋</div>
                            <div class="type-title">Izin</div>
                            <div class="type-desc">Dinas</div>
                        </div>

                        <div class="type-card-option" id="card_sakit" onclick="selectType('sakit')">
                            <div style="font-size:16px;">💊</div>
                            <div class="type-title">Sakit</div>
                            <div class="type-desc">Surat</div>
                        </div>
                    </div>
                </div>

                <!-- KETERANGAN / ALASAN -->
                <div style="margin-bottom:18px;">
                    <label for="keterangan" class="form-label-custom">Keterangan / Alasan</label>
                    <textarea id="keterangan" name="keterangan" rows="3" class="form-input-custom" placeholder="Jelaskan alasan pengajuan izin secara singkat dan jelas..." style="resize:vertical; margin-bottom:0;" required></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:10px; font-size:13px; font-weight:700; border-radius:10px; min-height:40px;">
                    Kirim Pengajuan
                </button>
            </form>
        </div>
    </div>

    <!-- TABEL RIWAYAT PENGAJUAN SAYA -->
    <div class="izin-card">
        <div class="izin-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Riwayat Pengajuan Saya
            </div>
            <div style="font-size:11px; color:#22c55e; font-weight:600; display:flex; align-items:center; gap:6px;">
                <span class="pulse-live-dot"></span> Realtime Sync (5s)
            </div>
        </div>

        <div class="table-responsive" style="overflow-x:auto;">
            <table style="font-size:13px; min-width:560px;">
                <thead>
                    <tr>
                        <th style="width:40px;">No</th>
                        <th>Periode Tanggal</th>
                        <th>Jenis</th>
                        <th style="text-align:left;">Keterangan / Alasan</th>
                        <th>Status Approval</th>
                        <th>Diajukan Pada</th>
                    </tr>
                </thead>
                <tbody id="user_izin_tbody">
                    <?php if (!empty($list_izin)):
                        $no = 1;
                        foreach ($list_izin as $row):
                            $badge_style = 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
                            $badge_text  = ucfirst($row['tipe_izin']);

                            if ($row['tipe_izin'] === 'cuti') {
                                $badge_style = 'background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;';
                                $badge_text  = 'Cuti';
                            } elseif ($row['tipe_izin'] === 'izin') {
                                $badge_style = 'background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;';
                                $badge_text  = 'Izin';
                            } elseif ($row['tipe_izin'] === 'sakit') {
                                $badge_style = 'background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff;';
                                $badge_text  = 'Sakit';
                            }

                            $st_p = $row['status_persetujuan'] ?? 'disetujui';
                            $p_badge = 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;';
                            $p_text  = 'Disetujui';
                            if ($st_p === 'pending') {
                                $p_badge = 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;';
                                $p_text  = 'Menunggu';
                            } elseif ($st_p === 'ditolak') {
                                $p_badge = 'background:#fee2e2; color:#be123c; border:1px solid #fca5a5;';
                                $p_text  = 'Ditolak';
                            }

                            // Format tanggal range
                            $tgl_m = date('d/m/Y', strtotime($row['tanggal']));
                            $tgl_s = !empty($row['tgl_selesai']) ? date('d/m/Y', strtotime($row['tgl_selesai'])) : $tgl_m;
                            
                            $dur_days = (strtotime($row['tgl_selesai'] ?: $row['tanggal']) - strtotime($row['tanggal'])) / 86400 + 1;
                            $tgl_display = ($tgl_m === $tgl_s) 
                                ? "<b>{$tgl_m}</b>" 
                                : "<b>{$tgl_m}</b> s.d <b>{$tgl_s}</b> <span style='font-size:11px; color:#2563eb; font-weight:700; display:block;'>({$dur_days} Hari)</span>";
                    ?>
                        <tr id="row_izin_<?php echo $row['id']; ?>" data-status="<?php echo $st_p; ?>">
                            <td><b><?php echo $no++; ?></b></td>
                            <td><?php echo $tgl_display; ?></td>
                            <td>
                                <span class="badge" style="<?php echo $badge_style; ?> font-weight:700;"><?php echo $badge_text; ?></span>
                            </td>
                            <td style="text-align:left; color:#334155; line-height:1.4;">
                                <?php echo h($row['keterangan'] ?: '-'); ?>
                            </td>
                            <td>
                                <span class="badge" style="<?php echo $p_badge; ?> font-weight:700;"><?php echo $p_text; ?></span>
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
                            <td colspan="6" style="padding:40px; color:#94a3b8; text-align:center;">Belum ada riwayat perizinan yang Anda ajukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function selectType(type) {
    document.getElementById('tipe_izin_val').value = type;
    document.querySelectorAll('.type-card-option').forEach(card => card.classList.remove('active'));
    document.getElementById('card_' + type).classList.add('active');
}

function updateDurasiInfo() {
    const t1 = document.getElementById('tgl_mulai').value;
    const t2 = document.getElementById('tgl_selesai').value;
    const badge = document.getElementById('durasi_badge');
    if (!t1 || !t2 || !badge) return;

    const d1 = new Date(t1);
    const d2 = new Date(t2);

    if (d2 < d1) {
        badge.style.background = '#fef2f2';
        badge.style.borderColor = '#fca5a5';
        badge.style.color = '#dc2626';
        badge.innerHTML = '⚠️ Tanggal selesai tidak boleh lebih awal dari tanggal mulai';
        return;
    }

    const diffTime = Math.abs(d2 - d1);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

    badge.style.background = '#eff6ff';
    badge.style.borderColor = '#bfdbfe';
    badge.style.color = '#1d4ed8';

    if (diffDays === 1) {
        badge.innerHTML = '📅 Durasi: <b>1 Hari</b> (Single Day)';
    } else {
        const fmt1 = t1.split('-').reverse().join('/');
        const fmt2 = t2.split('-').reverse().join('/');
        badge.innerHTML = `📅 Durasi: <b>${diffDays} Hari</b> (${fmt1} s.d ${fmt2})`;
    }
}

// REALTIME LIVE AUTO-UPDATE STATUS PENGAJUAN (EVERY 5 SECONDS)
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
                document.getElementById('stat_total').innerHTML = data.stat.total + ' <span style="font-size:12px; font-weight:500; color:#64748b;">Berkas</span>';
                document.getElementById('stat_disetujui').textContent = data.stat.disetujui;
                document.getElementById('stat_pending').textContent = data.stat.pending;
                document.getElementById('stat_ditolak').textContent = data.stat.ditolak;

                const tbody = document.getElementById('user_izin_tbody');
                if (data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="padding:40px; color:#94a3b8; text-align:center;">Belum ada riwayat perizinan yang Anda ajukan.</td></tr>';
                } else {
                    let html = '';
                    let no = 1;
                    data.items.forEach(item => {
                        const prev = prevStatuses[item.id];
                        const curr = item.status_persetujuan;

                        if (prev && prev !== curr) {
                            const statusLabel = curr === 'disetujui' ? 'DISETUJUI' : (curr === 'ditolak' ? 'DITOLAK' : 'MENUNGGU');
                            showLiveToast(`Status Pengajuan Update!`, `Pengajuan ${item.tipe_izin.toUpperCase()} periode ${item.periode_fmt} telah ${statusLabel}`);
                        }
                        prevStatuses[item.id] = curr;

                        html += `
                            <tr id="row_izin_${item.id}" data-status="${curr}">
                                <td><b>${no++}</b></td>
                                <td>${item.periode_html}</td>
                                <td>${item.tipe_badge_html}</td>
                                <td style="text-align:left; color:#334155; line-height:1.4;">${item.keterangan}</td>
                                <td>${item.status_badge_html}</td>
                                <td style="font-size:11.5px; color:#64748b;">${item.created_at_fmt}</td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;
                }
            }
        })
        .catch(err => console.error('Realtime sync error:', err));
}

function showLiveToast(title, msg) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed; top:20px; right:20px; background:#0f172a; color:#fff; padding:14px 20px; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,0.25); z-index:9999; display:flex; align-items:center; gap:12px; max-width:380px; animation:slideInRight 0.3s ease; border-left:4px solid #22c55e;';
    toast.innerHTML = `
        <div style="font-size:22px;">✅</div>
        <div>
            <div style="font-weight:700; font-size:13px; color:#fff;">${title}</div>
            <div style="font-size:12.5px; color:#e2e8f0; margin-top:2px;">${msg}</div>
        </div>
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 6000);
}

setInterval(pollRealtimeStatus, 5000);
</script>

<?php endif; ?>

<?php render_footer(); ?>
