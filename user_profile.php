<?php
// ============================================================
// PORTAL MANDIRI - DATA DIRI & PROFIL
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
            $pesan_error = "Format foto tidak didukung! Gunakan JPG, PNG, atau WEBP.";
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
            $pesan_sukses = "Profil berhasil diperbarui.";
            log_audit("UPDATE_PROFIL_MANDIRI", "Update foto & data diri PIN {$target_pin}");
        } else {
            $pesan_error = "Gagal menyimpan: " . $conn->error;
        }
    }
}

$master_employees = [];
if (!is_user_role()) {
    $res_emp = $conn->query("SELECT pin, nama, departemen, tipe FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC");
    if ($res_emp) {
        $master_employees = $res_emp->fetch_all(MYSQLI_ASSOC);
        if (empty($pin) && !empty($master_employees)) $pin = $master_employees[0]['pin'];
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
        if ($res_h) $rekap_bulan['hadir'] = $res_h->fetch_assoc()['total'] ?? 0;
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
/* ===== PROFILE PAGE STYLES ===== */
.profile-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #1e293b 100%);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
    color: #fff;
}
.profile-hero::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 240px; height: 240px;
    background: rgba(59,130,246,0.12);
    border-radius: 50%;
}
.profile-hero::after {
    content: '';
    position: absolute;
    bottom: -40px; left: 30%;
    width: 180px; height: 180px;
    background: rgba(99,102,241,0.08);
    border-radius: 50%;
}
.profile-avatar-wrap {
    position: relative;
    width: 96px;
    height: 96px;
    flex-shrink: 0;
}
.profile-avatar {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,0.3);
    background: rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -1px;
}
.profile-avatar-badge {
    position: absolute;
    bottom: 2px; right: 2px;
    width: 22px; height: 22px;
    background: #22c55e;
    border-radius: 50%;
    border: 2px solid #0f172a;
}
.stat-pill {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    padding: 12px 18px;
    min-width: 110px;
    text-align: center;
    backdrop-filter: blur(4px);
}
.info-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(15,23,42,0.05);
}
.info-section-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 24px;
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-row {
    display: flex;
    padding: 13px 24px;
    border-bottom: 1px solid #f1f5f9;
    align-items: flex-start;
    gap: 12px;
    font-size: 13.5px;
    transition: background 0.15s;
}
.info-row:hover { background: #fafafa; }
.info-row:last-child { border-bottom: none; }
.info-label {
    width: 140px;
    flex-shrink: 0;
    color: #64748b;
    font-weight: 500;
    padding-top: 1px;
}
.info-value {
    color: #0f172a;
    font-weight: 600;
    flex: 1;
    line-height: 1.5;
}
.form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-field label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0;
}
.form-field input,
.form-field textarea,
.form-field select {
    padding: 10px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #fff;
    margin-bottom: 0;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-field input:focus,
.form-field textarea:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    outline: none;
}
.profile-grid {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 20px;
    align-items: start;
}
.form-field-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}
@media (max-width: 992px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
}
.upload-area {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 18px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: #f8fafc;
}
.upload-area:hover { border-color: #3b82f6; background: #eff6ff; }

.toast-success {
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
    box-shadow: 0 2px 8px rgba(21,128,61,0.1);
}
.toast-error {
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
    box-shadow: 0 2px 8px rgba(190,18,60,0.1);
}
</style>

<?php if (!empty($pesan_sukses)): ?>
    <div class="toast-success"><span style="font-size:18px;">✓</span> <?php echo $pesan_sukses; ?></div>
<?php endif; ?>
<?php if (!empty($pesan_error)): ?>
    <div class="toast-error"><span style="font-size:18px;">✕</span> <?php echo $pesan_error; ?></div>
<?php endif; ?>

<?php if (empty($pin) || !$detail): ?>
    <div class="info-section" style="text-align:center; padding:60px 20px;">
        <div style="font-size:48px; margin-bottom:16px; opacity:0.3;">👤</div>
        <h3 style="font-size:18px; font-weight:700; color:#0f172a; margin-bottom:8px;">Akun Belum Terhubung</h3>
        <p style="color:#64748b; font-size:14px; max-width:400px; margin:0 auto;">
            Akun <code><?php echo h($_SESSION['username']); ?></code> belum terhubung ke data karyawan. Hubungi Administrator.
        </p>
    </div>
<?php else: ?>

<!-- PROFILE HERO BANNER -->
<div class="profile-hero">
    <div style="position:relative; z-index:1; display:flex; gap:24px; align-items:center; flex-wrap:wrap;">

        <!-- Avatar -->
        <div class="profile-avatar-wrap">
            <?php if (!empty($detail['foto']) && file_exists(__DIR__ . '/' . $detail['foto'])): ?>
                <img src="<?php echo h($detail['foto']); ?>?v=<?php echo time(); ?>" alt="Foto Profil" class="profile-avatar" style="display:block;">
            <?php else: ?>
                <div class="profile-avatar">
                    <?php echo strtoupper(mb_substr($detail['nama'], 0, 1)); ?>
                </div>
            <?php endif; ?>
            <div class="profile-avatar-badge" title="Akun Aktif"></div>
        </div>

        <!-- Identity -->
        <div style="flex:1; min-width:200px;">
            <div style="font-size:11px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px;">
                <?php echo $detail['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
            </div>
            <h2 style="font-size:22px; font-weight:800; color:#fff; margin-bottom:6px; line-height:1.2;"><?php echo h($detail['nama']); ?></h2>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <span style="background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2); color:#e2e8f0; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600;">
                    <?php echo h($detail['departemen'] ?: 'Umum'); ?>
                </span>
                <span style="background:rgba(59,130,246,0.2); border:1px solid rgba(59,130,246,0.3); color:#93c5fd; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600;">
                    PIN: <?php echo h($pin); ?>
                </span>
                <?php if ($usia_str): ?>
                <span style="background:rgba(168,85,247,0.2); border:1px solid rgba(168,85,247,0.3); color:#c4b5fd; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600;">
                    <?php echo $usia_str; ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats -->
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <div class="stat-pill">
                <div style="font-size:22px; font-weight:800; color:#4ade80;"><?php echo $rekap_bulan['hadir']; ?></div>
                <div style="font-size:11px; color:#94a3b8; margin-top:2px;">Hadir</div>
            </div>
            <div class="stat-pill">
                <div style="font-size:22px; font-weight:800; color:#fbbf24;"><?php echo $rekap_bulan['izin']; ?></div>
                <div style="font-size:11px; color:#94a3b8; margin-top:2px;">Izin</div>
            </div>
            <div class="stat-pill">
                <div style="font-size:22px; font-weight:800; color:#f87171;"><?php echo $rekap_bulan['sakit']; ?></div>
                <div style="font-size:11px; color:#94a3b8; margin-top:2px;">Sakit</div>
            </div>
            <div class="stat-pill">
                <div style="font-size:22px; font-weight:800; color:#60a5fa;"><?php echo $rekap_bulan['cuti']; ?></div>
                <div style="font-size:11px; color:#94a3b8; margin-top:2px;">Cuti</div>
            </div>
        </div>
    </div>

    <!-- Presensi hari ini ribbon -->
    <div style="position:relative; z-index:1; display:flex; gap:24px; margin-top:20px; padding-top:20px; border-top:1px solid rgba(255,255,255,0.1); flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:8px; height:8px; border-radius:50%; background: <?php echo $absen_today['masuk'] ? '#4ade80' : '#f87171'; ?>; box-shadow: 0 0 6px <?php echo $absen_today['masuk'] ? '#4ade80' : '#f87171'; ?>;"></div>
            <span style="font-size:13px; color:#e2e8f0;">
                <span style="color:#94a3b8;">Absen Masuk:</span>
                <b style="color:#fff; margin-left:6px;"><?php echo $absen_today['masuk'] ?: 'Belum absen'; ?></b>
            </span>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:8px; height:8px; border-radius:50%; background: <?php echo $absen_today['pulang'] ? '#4ade80' : '#f87171'; ?>; box-shadow: 0 0 6px <?php echo $absen_today['pulang'] ? '#4ade80' : '#f87171'; ?>;"></div>
            <span style="font-size:13px; color:#e2e8f0;">
                <span style="color:#94a3b8;">Absen Pulang:</span>
                <b style="color:#fff; margin-left:6px;"><?php echo $absen_today['pulang'] ?: 'Belum absen'; ?></b>
            </span>
        </div>
        <div style="margin-left:auto; font-size:11.5px; color:#64748b;">
            <?php echo date('l, d F Y'); ?>
        </div>
    </div>
</div>

<!-- MAIN CONTENT GRID -->
<div class="profile-grid">

    <!-- LEFT: INFO PANEL -->
    <div>
        <div class="info-section">
            <div class="info-section-header">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                Informasi Pegawai
            </div>
            <div class="info-row">
                <div class="info-label">PIN</div>
                <div class="info-value"><code style="background:#f1f5f9; padding:2px 8px; border-radius:6px; font-weight:700;"><?php echo h($detail['pin']); ?></code></div>
            </div>
            <div class="info-row">
                <div class="info-label">Nama Lengkap</div>
                <div class="info-value"><?php echo h($detail['nama']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Departemen</div>
                <div class="info-value"><?php echo h($detail['departemen'] ?: '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Jabatan</div>
                <div class="info-value">
                    <span style="background:<?php echo $detail['tipe'] === 'guru' ? '#eff6ff' : '#f1f5f9'; ?>; color:<?php echo $detail['tipe'] === 'guru' ? '#1d4ed8' : '#475569'; ?>; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:700; border:1px solid <?php echo $detail['tipe'] === 'guru' ? '#bfdbfe' : '#e2e8f0'; ?>;">
                        <?php echo $detail['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">No. Telepon</div>
                <div class="info-value"><?php echo h($detail['no_hp'] ?: '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">TTL</div>
                <div class="info-value">
                    <?php
                    $ttl = [];
                    if (!empty($detail['tempat_lahir'])) $ttl[] = $detail['tempat_lahir'];
                    if (!empty($detail['tanggal_lahir'])) $ttl[] = date('d F Y', strtotime($detail['tanggal_lahir']));
                    echo !empty($ttl) ? h(implode(', ', $ttl)) : '-';
                    ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Alamat</div>
                <div class="info-value" style="line-height:1.6;"><?php echo h($detail['alamat'] ?: '-'); ?></div>
            </div>
        </div>
    </div>

    <!-- RIGHT: EDIT FORM -->
    <div>
        <div class="info-section">
            <div class="info-section-header">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Perbarui Data Diri <?php echo !is_user_role() ? '<span style="font-size:11px; font-weight:500; color:#3b82f6; margin-left:6px;">— Superadmin Access</span>' : ''; ?>
            </div>

            <form method="POST" action="user_profile.php<?php echo !is_user_role() ? '?pin=' . urlencode($pin) : ''; ?>" enctype="multipart/form-data" style="padding:24px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_profil_mandiri">
                <input type="hidden" name="target_pin" value="<?php echo h($pin); ?>">

                <!-- FOTO UPLOAD AREA -->
                <div style="margin-bottom:24px;">
                    <div style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">Foto Profil</div>
                    <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                        <!-- Preview avatar kecil -->
                        <div style="width:64px; height:64px; border-radius:50%; overflow:hidden; border:2px solid #e2e8f0; background:#f8fafc; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; color:#94a3b8;">
                            <?php if (!empty($detail['foto']) && file_exists(__DIR__ . '/' . $detail['foto'])): ?>
                                <img src="<?php echo h($detail['foto']); ?>" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <?php echo strtoupper(mb_substr($detail['nama'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;">
                            <input type="file" id="foto_profil" name="foto_profil" accept="image/jpeg,image/png,image/webp"
                                style="width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; background:#fff; cursor:pointer; margin-bottom:0;">
                            <div style="font-size:11.5px; color:#94a3b8; margin-top:5px;">Format JPG, PNG, atau WEBP. Ukuran maksimal 2MB.</div>
                        </div>
                    </div>
                </div>

                <div style="height:1px; background:#f1f5f9; margin-bottom:20px;"></div>

                <!-- FORM FIELDS GRID -->
                <div class="form-field-grid">
                    <div class="form-field">
                        <label for="no_hp">Nomor Telepon / WhatsApp</label>
                        <input type="text" id="no_hp" name="no_hp" value="<?php echo h($detail['no_hp'] ?? ''); ?>" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="form-field">
                        <label for="tempat_lahir">Tempat Lahir</label>
                        <input type="text" id="tempat_lahir" name="tempat_lahir" value="<?php echo h($detail['tempat_lahir'] ?? ''); ?>" placeholder="Kota kelahiran">
                    </div>
                    <div class="form-field">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir" value="<?php echo h($detail['tanggal_lahir'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-field" style="margin-bottom:24px;">
                    <label for="alamat">Alamat Tempat Tinggal</label>
                    <textarea id="alamat" name="alamat" rows="3" placeholder="Jl. ... No. ... RT/RW ... Kel. ... Kec. ... Kota ..."
                        style="resize:vertical; line-height:1.6;"><?php echo h($detail['alamat'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; padding-top:16px; border-top:1px solid #f1f5f9;">
                    <button type="reset" class="btn" style="background:#f8fafc; color:#475569; border:1px solid #e2e8f0; padding:9px 18px; font-size:13.5px;">
                        Reset
                    </button>
                    <button type="submit" class="btn btn-primary" style="padding:9px 22px; font-size:13.5px; font-weight:700; min-height:40px;">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<?php endif; ?>

<?php render_footer(); ?>
