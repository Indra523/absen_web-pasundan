<?php
// ============================================================
// PENGATURAN SEKOLAH & KOP DOKUMEN / LAPORAN
// Mengubah Identitas, Logo, Favicon, & Pejabat Penandatangan Laporan (Excel & PDF)
// ============================================================

require_once __DIR__ . '/layout.php';

// Akses hanya untuk Superadmin dan Admin
if (!is_superadmin() && !is_admin()) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$tenant = get_active_tenant();
$tenant_code = $tenant['tenant_code'] ?? 'pasundan2';

$pesan_sukses = '';
$pesan_error  = '';

// --- POST HANDLER: SIMPAN PENGATURAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    csrf_verify();

    $settings_to_update = [
        'nama_sekolah'        => trim($_POST['nama_sekolah'] ?? ''),
        'nama_yayasan'        => trim($_POST['nama_yayasan'] ?? ''),
        'sub_header_kop'      => trim($_POST['sub_header_kop'] ?? ''),
        'alamat_sekolah'      => trim($_POST['alamat_sekolah'] ?? ''),
        'telepon_sekolah'     => trim($_POST['telepon_sekolah'] ?? ''),
        'email_sekolah'       => trim($_POST['email_sekolah'] ?? ''),
        'kota_surat'          => trim($_POST['kota_surat'] ?? 'Bandung'),
        'nama_kepsek'         => trim($_POST['nama_kepsek'] ?? ''),
        'nip_kepsek'          => trim($_POST['nip_kepsek'] ?? '-'),
        'jabatan_kepsek'      => trim($_POST['jabatan_kepsek'] ?? 'Kepala Sekolah'),
        'nama_admin_rekap'    => trim($_POST['nama_admin_rekap'] ?? ''),
        'nip_admin_rekap'     => trim($_POST['nip_admin_rekap'] ?? '-'),
        'jabatan_admin_rekap' => trim($_POST['jabatan_admin_rekap'] ?? 'Administrator System'),
        'jam_masuk'           => trim($_POST['jam_masuk'] ?? '06:30'),
        'jam_toleransi'       => trim($_POST['jam_toleransi'] ?? '07:15'),
        'jam_pulang'          => trim($_POST['jam_pulang'] ?? '17:00'),
    ];

    // Direktori upload logo tenant
    $upload_dir = __DIR__ . "/uploads/tenants/{$tenant_code}/";
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
        @chmod($upload_dir, 0777);
    }

    // 1. Handle Upload Logo Sekolah
    if (!empty($_FILES['logo_sekolah']['name']) && $_FILES['logo_sekolah']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['logo_sekolah']['tmp_name'];
        $file_ext  = strtolower(pathinfo($_FILES['logo_sekolah']['name'], PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
        
        if (in_array($file_ext, $allowed)) {
            $logo_filename = "logo_" . time() . "." . $file_ext;
            $dest_path = $upload_dir . $logo_filename;
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $settings_to_update['logo_sekolah'] = "uploads/tenants/{$tenant_code}/" . $logo_filename;
            }
        } else {
            $pesan_error = "Format file logo tidak didukung (gunakan JPG, PNG, atau WEBP).";
        }
    }

    // 2. Handle Upload Favicon
    if (!empty($_FILES['favicon_sekolah']['name']) && $_FILES['favicon_sekolah']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['favicon_sekolah']['tmp_name'];
        $file_ext  = strtolower(pathinfo($_FILES['favicon_sekolah']['name'], PATHINFO_EXTENSION));
        $allowed   = ['ico', 'png', 'jpg', 'jpeg', 'svg'];
        
        if (in_array($file_ext, $allowed)) {
            $fav_filename = "favicon_" . time() . "." . $file_ext;
            $dest_path = $upload_dir . $fav_filename;
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $settings_to_update['favicon_sekolah'] = "uploads/tenants/{$tenant_code}/" . $fav_filename;
            }
        } else {
            $pesan_error = "Format file favicon tidak didukung (gunakan ICO atau PNG).";
        }
    }

    // Simpan semua settings ke database tenant
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($stmt) {
        foreach ($settings_to_update as $k => $v) {
            $stmt->bind_param("ss", $k, $v);
            $stmt->execute();
        }
    }

    // Sinkronkan nama sekolah ke master_tenants jika ada koneksi master
    if (!empty($settings_to_update['nama_sekolah'])) {
        $master_conn = getMasterDB();
        if ($master_conn) {
            $stmt_m = $master_conn->prepare("UPDATE master_tenants SET nama_sekolah = ?, alamat_sekolah = ?, kontak_pic = ? WHERE tenant_code = ?");
            if ($stmt_m) {
                $stmt_m->bind_param("ssss", $settings_to_update['nama_sekolah'], $settings_to_update['alamat_sekolah'], $settings_to_update['nama_kepsek'], $tenant_code);
                $stmt_m->execute();
            }
        }
    }

    log_audit("UPDATE_PENGATURAN_SEKOLAH", "Memperbarui identitas sekolah, logo, dan penandatangan laporan");
    $pesan_sukses = "Pengaturan sekolah dan format laporan berhasil disimpan!";
}

// Ambil settings terbaru
$app_settings = get_app_settings();

render_header("Pengaturan Sekolah", "pengaturan_sekolah");
?>

<style>
.settings-container {
    max-width: 980px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.settings-card {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}

.settings-card-header {
    padding: 22px 26px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 14px;
}

.settings-card-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.settings-card-body {
    padding: 26px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 16px;
}

.form-group label {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
}

.form-group input, .form-group textarea, .form-group select {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    font-family: inherit;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input:focus, .form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.form-help {
    font-size: 11.5px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.4;
}

.logo-preview-box {
    display: flex;
    align-items: center;
    gap: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 20px;
}

.logo-img-wrapper {
    width: 80px;
    height: 80px;
    border-radius: 14px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    overflow: hidden;
}

.logo-img-wrapper img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.btn-save-settings {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    font-weight: 800;
    font-size: 14px;
    padding: 14px 28px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.35);
    transition: all 0.2s ease;
}

.btn-save-settings:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(37, 99, 235, 0.45);
}

@media (max-width: 768px) {
    .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
    .logo-preview-box { flex-direction: column; text-align: center; }
}
</style>

<div class="settings-container">

    <!-- ALERT PESAN -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #86efac; color:#15803d; border-radius:12px; padding:16px 20px; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 4px 12px rgba(22,163,74,0.08);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; color:#be123c; border-radius:12px; padding:16px 20px; font-size:13.5px; display:flex; align-items:center; gap:12px;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="pengaturan_sekolah.php" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">

        <!-- KARTU 1: LOGO & IDENTITAS SEKOLAH -->
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="settings-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16.5px; font-weight:800; color:#0f172a;">Identitas &amp; Logo Sekolah</h3>
                    <p style="font-size:12px; color:#64748b;">Informasi nama sekolah, logo, favicon, dan alamat resmi</p>
                </div>
            </div>

            <div class="settings-card-body">
                <!-- LOGO & FAVICON UPLOAD -->
                <div class="form-grid-2">
                    <div class="logo-preview-box">
                        <div class="logo-img-wrapper">
                            <img src="<?php echo h($app_settings['logo_sekolah'] ?? 'assets/logo_pasundan2.jpg'); ?>" id="previewLogoImg" alt="Logo">
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:12.5px; font-weight:700; color:#0f172a; display:block; margin-bottom:4px;">Logo Sekolah</label>
                            <div class="form-help" style="margin-bottom:8px;">Tampil di Kop Surat, Halaman Login, dan Sidebar</div>
                            <input type="file" name="logo_sekolah" accept="image/*" onchange="previewFile(this, 'previewLogoImg')" style="font-size:12px;">
                        </div>
                    </div>

                    <div class="logo-preview-box">
                        <div class="logo-img-wrapper" style="width:54px; height:54px; border-radius:10px;">
                            <img src="<?php echo h($app_settings['favicon_sekolah'] ?? 'assets/logo_pasundan2.png'); ?>" id="previewFaviconImg" alt="Favicon">
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:12.5px; font-weight:700; color:#0f172a; display:block; margin-bottom:4px;">Favicon Tab Browser</label>
                            <div class="form-help" style="margin-bottom:8px;">Ikon kecil di tab browser (.ico atau .png)</div>
                            <input type="file" name="favicon_sekolah" accept="image/*" onchange="previewFile(this, 'previewFaviconImg')" style="font-size:12px;">
                        </div>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Nama Lengkap Sekolah *</label>
                        <input type="text" name="nama_sekolah" value="<?php echo h($app_settings['nama_sekolah'] ?? ''); ?>" required placeholder="SMK Pasundan 2 Bandung">
                        <div class="form-help">Nama ini tampil di seluruh judul, login, dan header sistem.</div>
                    </div>
                    <div class="form-group">
                        <label>Nama Yayasan / Instansi Induk (Kop Surat)</label>
                        <input type="text" name="nama_yayasan" value="<?php echo h($app_settings['nama_yayasan'] ?? ''); ?>" placeholder="YAYASAN PENDIDIKAN DASAR DAN MENENGAH PASUNDAN">
                    </div>
                </div>

                <div class="form-group">
                    <label>Sub Header Kop / Program Keahlian (Kop Surat Resmi)</label>
                    <input type="text" name="sub_header_kop" value="<?php echo h($app_settings['sub_header_kop'] ?? ''); ?>" placeholder="KOMPETENSI KEAHLIAN : REKAYASA PERANGKAT LUNAK, TEKNIK KOMPUTER JARINGAN">
                </div>

                <div class="form-group">
                    <label>Alamat Lengkap Sekolah</label>
                    <textarea name="alamat_sekolah" rows="2" placeholder="Jl. Citarum No. 12, Kota Bandung, Jawa Barat"><?php echo h($app_settings['alamat_sekolah'] ?? ''); ?></textarea>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label>No. Telepon / WhatsApp Sekolah</label>
                        <input type="text" name="telepon_sekolah" value="<?php echo h($app_settings['telepon_sekolah'] ?? ''); ?>" placeholder="022-1234567 / 08123456789">
                    </div>
                    <div class="form-group">
                        <label>Email Resmi Sekolah</label>
                        <input type="email" name="email_sekolah" value="<?php echo h($app_settings['email_sekolah'] ?? ''); ?>" placeholder="info@sekolah.sch.id">
                    </div>
                </div>
            </div>
        </div>

        <!-- KARTU 2: PEJABAT PENANDATANGAN LAPORAN (EXCEL & PDF) -->
        <div class="settings-card" style="margin-top:20px;">
            <div class="settings-card-header">
                <div class="settings-card-icon" style="background:#fef3c7; color:#b45309;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16.5px; font-weight:800; color:#0f172a;">Pejabat Penandatangan Laporan (Excel &amp; PDF)</h3>
                    <p style="font-size:12px; color:#64748b;">Nama Kepala Sekolah dan Administrator yang tercetak di dokumen ekspor</p>
                </div>
            </div>

            <div class="settings-card-body">
                <div class="form-group" style="max-width:300px;">
                    <label>Kota Penandatanganan Dokumen</label>
                    <input type="text" name="kota_surat" value="<?php echo h($app_settings['kota_surat'] ?? 'Bandung'); ?>" placeholder="Bandung">
                    <div class="form-help">Contoh: "Bandung, 25 Agustus 2026"</div>
                </div>

                <!-- KEPALA SEKOLAH -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:18px; display:flex; flex-direction:column; gap:14px;">
                    <div style="font-size:13.5px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                        <span>👔 Pejabat 1: Kepala Sekolah (Mengetahui)</span>
                    </div>
                    <div class="form-grid-3">
                        <div class="form-group">
                            <label>Nama Lengkap &amp; Gelar *</label>
                            <input type="text" name="nama_kepsek" value="<?php echo h($app_settings['nama_kepsek'] ?? ''); ?>" required placeholder="Umar Khatob, S.Pd, M.Si.">
                        </div>
                        <div class="form-group">
                            <label>NIP / NUPTK</label>
                            <input type="text" name="nip_kepsek" value="<?php echo h($app_settings['nip_kepsek'] ?? '-'); ?>" placeholder="19720815...">
                        </div>
                        <div class="form-group">
                            <label>Jabatan di Dokumen</label>
                            <input type="text" name="jabatan_kepsek" value="<?php echo h($app_settings['jabatan_kepsek'] ?? 'Kepala Sekolah'); ?>" placeholder="Kepala Sekolah">
                        </div>
                    </div>
                </div>

                <!-- ADMINISTRATOR SISTEM -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:18px; display:flex; flex-direction:column; gap:14px;">
                    <div style="font-size:13.5px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                        <span>💻 Pejabat 2: Administrator / Petugas Rekap Absensi</span>
                    </div>
                    <div class="form-grid-3">
                        <div class="form-group">
                            <label>Nama Lengkap &amp; Gelar *</label>
                            <input type="text" name="nama_admin_rekap" value="<?php echo h($app_settings['nama_admin_rekap'] ?? ''); ?>" required placeholder="Indra Setia Budi">
                        </div>
                        <div class="form-group">
                            <label>NIP / NUPTK</label>
                            <input type="text" name="nip_admin_rekap" value="<?php echo h($app_settings['nip_admin_rekap'] ?? '-'); ?>" placeholder="-">
                        </div>
                        <div class="form-group">
                            <label>Jabatan di Dokumen</label>
                            <input type="text" name="jabatan_admin_rekap" value="<?php echo h($app_settings['jabatan_admin_rekap'] ?? 'Administrator System'); ?>" placeholder="Administrator System">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- KARTU 3: JADWAL & TOLERANSI -->
        <div class="settings-card" style="margin-top:20px;">
            <div class="settings-card-header">
                <div class="settings-card-icon" style="background:#f0fdf4; color:#16a34a;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16.5px; font-weight:800; color:#0f172a;">Waktu Jam Masuk &amp; Pulang Default</h3>
                    <p style="font-size:12px; color:#64748b;">Aturan jam absensi harian sekolah</p>
                </div>
            </div>

            <div class="settings-card-body">
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Jam Masuk Normal</label>
                        <input type="time" name="jam_masuk" value="<?php echo h($app_settings['jam_masuk'] ?? '06:30'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Batas Jam Toleransi Terlambat</label>
                        <input type="time" name="jam_toleransi" value="<?php echo h($app_settings['jam_toleransi'] ?? '07:15'); ?>" required>
                        <div class="form-help">Lewat jam ini dihitung Terlambat</div>
                    </div>
                    <div class="form-group">
                        <label>Jam Pulang Normal</label>
                        <input type="time" name="jam_pulang" value="<?php echo h($app_settings['jam_pulang'] ?? '17:00'); ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- TOMBOL SIMPAN -->
        <div style="margin-top:24px; display:flex; justify-content:flex-end;">
            <button type="submit" class="btn-save-settings">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span>Simpan Semua Pengaturan</span>
            </button>
        </div>
    </form>

</div>

<script>
function previewFile(input, imgId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById(imgId).src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php render_footer(); ?>
