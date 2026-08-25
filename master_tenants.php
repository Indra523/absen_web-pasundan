<?php
// ============================================================
// MASTER TENANT & SCHOOL MANAGEMENT (MULTI-TENANT SAAS)
// Panel Master Admin untuk Mengelola & Menambah Database Sekolah
// ============================================================

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/tenant_provisioner.php';

// Verifikasi Akses: Hanya Superadmin atau Master Admin
if (!is_superadmin() && !is_master_admin()) {
    header("Location: index.php?error=access_denied");
    exit;
}

$master_conn = getMasterDB();
if (!$master_conn) {
    die("Master Database tidak terhubung. Periksa konfigurasi db_master_system di config.php.");
}

$pesan_sukses = '';
$pesan_error  = '';

// --- POST HANDLER: PROVISIONING / EDIT / HAPUS / TOGGLE STATUS / IMPERSONATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    $action = $_POST['action'];

    // 1. TAMBAH SEKOLAH BARU (AUTO-PROVISIONING DATABASE)
    if ($action === 'tambah_sekolah') {
        $res = TenantProvisioner::createTenant($_POST);
        if ($res['success']) {
            $pesan_sukses = $res['message'];
        } else {
            $pesan_error = $res['message'];
        }
    }

    // 2. EDIT INFORMASI SEKOLAH
    elseif ($action === 'edit_sekolah') {
        $t_id           = (int)($_POST['tenant_id'] ?? 0);
        $nama_sekolah   = trim($_POST['nama_sekolah'] ?? '');
        $custom_domain  = !empty($_POST['custom_domain']) ? trim($_POST['custom_domain']) : null;
        $paket          = trim($_POST['paket'] ?? 'Pro');
        $max_karyawan   = (int)($_POST['max_karyawan'] ?? 500);
        $tgl_kadaluarsa = !empty($_POST['tgl_kadaluarsa']) ? trim($_POST['tgl_kadaluarsa']) : null;
        $kontak_pic     = trim($_POST['kontak_pic'] ?? '');
        $no_hp_pic      = trim($_POST['no_hp_pic'] ?? '');
        $email_pic      = trim($_POST['email_pic'] ?? '');
        $alamat         = trim($_POST['alamat'] ?? '');
        $new_admin_pass = trim($_POST['new_admin_password'] ?? '');

        $stmt_get = $master_conn->prepare("SELECT * FROM master_tenants WHERE id = ?");
        $stmt_get->bind_param("i", $t_id);
        $stmt_get->execute();
        $tenant_data = $stmt_get->get_result()->fetch_assoc();

        if (!$tenant_data) {
            $pesan_error = "Data sekolah tidak ditemukan.";
        } else {
            $stmt_ed = $master_conn->prepare("UPDATE master_tenants SET nama_sekolah=?, custom_domain=?, paket_langganan=?, max_karyawan=?, tgl_kadaluarsa=?, kontak_pic=?, no_hp_pic=?, email_pic=?, alamat_sekolah=? WHERE id=?");
            $stmt_ed->bind_param("sssisssssi", $nama_sekolah, $custom_domain, $paket, $max_karyawan, $tgl_kadaluarsa, $kontak_pic, $no_hp_pic, $email_pic, $alamat, $t_id);
            
            if ($stmt_ed->execute()) {
                // Update nama sekolah di app_settings database tenant
                $t_db_name = $tenant_data['db_name'];
                $t_conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, $t_db_name);
                if ($t_conn && !$t_conn->connect_error) {
                    $t_conn->set_charset("utf8mb4");
                    $stmt_set = $t_conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('nama_sekolah', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    if ($stmt_set) {
                        $stmt_set->bind_param("s", $nama_sekolah);
                        $stmt_set->execute();
                    }

                    // Jika ada ganti password admin sekolah
                    if (!empty($new_admin_pass) && strlen($new_admin_pass) >= 6) {
                        $new_hash = password_hash($new_admin_pass, PASSWORD_BCRYPT);
                        $t_conn->query("UPDATE users SET password = '{$new_hash}' WHERE role = 'admin' LIMIT 1");
                    }
                    $t_conn->close();
                }

                $pesan_sukses = "Informasi sekolah <b>" . h($nama_sekolah) . "</b> berhasil diperbarui.";
            } else {
                $pesan_error = "Gagal memperbarui sekolah: " . $master_conn->error;
            }
        }
    }

    // 3. HAPUS SEKOLAH & DATABASE TENANT
    elseif ($action === 'hapus_sekolah') {
        $t_id     = (int)($_POST['tenant_id'] ?? 0);
        $drop_db  = !empty($_POST['drop_database']) && $_POST['drop_database'] === '1';

        $res_del = TenantProvisioner::deleteTenant($t_id, $drop_db);
        if ($res_del['success']) {
            $pesan_sukses = $res_del['message'];
        } else {
            $pesan_error = $res_del['message'];
        }
    }

    // 4. TOGGLE STATUS (AKTIF / SUSPEND / TRIAL)
    elseif ($action === 'toggle_status') {
        $t_id   = (int)($_POST['tenant_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['aktif', 'suspend', 'trial', 'nonaktif']) ? $_POST['status'] : 'aktif';
        
        $stmt_up = $master_conn->prepare("UPDATE master_tenants SET status = ? WHERE id = ?");
        $stmt_up->bind_param("si", $status, $t_id);
        if ($stmt_up->execute()) {
            $pesan_sukses = "Status sekolah berhasil diubah menjadi: <b>" . strtoupper($status) . "</b>.";
        } else {
            $pesan_error = "Gagal mengubah status: " . $master_conn->error;
        }
    }

    // 5. IMPERSONATE / MASUK KE DASHBOARD SEKOLAH TERKAIT
    elseif ($action === 'impersonate_sekolah') {
        $tenant_code = trim($_POST['tenant_code'] ?? '');
        if (!empty($tenant_code)) {
            $_SESSION['active_tenant_code'] = $tenant_code;
            $_SESSION['role'] = 'superadmin';
            header("Location: index.php?tenant_switched=1");
            exit;
        }
    }

    // 6. RESET TENANT SESSION (KEMBALI KE SEKOLAH UTAMA)
    elseif ($action === 'reset_tenant') {
        unset($_SESSION['active_tenant_code']);
        header("Location: master_tenants.php");
        exit;
    }
}

// --- AMBIL DAFTAR SEMUA SEKOLAH & STATISTIK ---
$res_tenants = $master_conn->query("SELECT * FROM master_tenants ORDER BY id ASC");
$all_tenants = [];
$total_sekolah = 0;
$total_aktif   = 0;
$total_suspend = 0;

if ($res_tenants) {
    while ($row = $res_tenants->fetch_assoc()) {
        $all_tenants[] = $row;
        $total_sekolah++;
        if ($row['status'] === 'aktif') $total_aktif++;
        if ($row['status'] === 'suspend') $total_suspend++;
    }
}

$current_active_tenant = get_active_tenant();

render_header("Kelola Sekolah (Multi-Tenant)", "master_tenants");
?>

<style>
/* MASTER SAAS STYLES */
.master-grid {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.master-hero {
    background: linear-gradient(135deg, #091e3a 0%, #1e3a8a 50%, #0f172a 100%);
    border-radius: 20px;
    padding: 26px 30px;
    color: #ffffff;
    box-shadow: 0 12px 35px rgba(15, 23, 42, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}

.master-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 350px;
    height: 350px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.25) 0%, transparent 70%);
    pointer-events: none;
}

.master-hero-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.master-hero-sub {
    font-size: 13px;
    color: #cbd5e1;
    margin-top: 5px;
    max-width: 600px;
    line-height: 1.5;
}

.master-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}

.master-kpi-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 20px 22px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.master-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
}

.master-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.master-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}

.master-kpi-lbl {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    margin-top: 4px;
}

.tenant-table-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}

.tenant-table-header {
    padding: 20px 24px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.btn-add-school {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 18px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
    transition: all 0.2s ease;
}

.btn-add-school:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.4);
}

.badge-tenant-status {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.badge-status-aktif { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.badge-status-suspend { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.badge-status-trial { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

/* ACTION BUTTONS */
.btn-action-sm {
    font-size: 11.5px;
    font-weight: 700;
    padding: 6px 10px;
    border-radius: 7px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
    text-decoration: none;
    border: none;
}

.btn-action-enter {
    background: #eff6ff;
    color: #2563eb;
    border: 1px solid #bfdbfe;
}
.btn-action-enter:hover {
    background: #2563eb;
    color: #ffffff;
}

.btn-action-edit {
    background: #f8fafc;
    color: #475569;
    border: 1px solid #cbd5e1;
}
.btn-action-edit:hover {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
}

.btn-action-delete {
    background: #fff1f2;
    color: #e11d48;
    border: 1px solid #fecdd3;
}
.btn-action-delete:hover {
    background: #e11d48;
    color: #ffffff;
    border-color: #e11d48;
}

/* ANIMATED MODAL STYLES */
.modal-saas-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.7);
    backdrop-filter: blur(8px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeInModal 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.modal-saas-card {
    background: #ffffff;
    border-radius: 24px;
    max-width: 580px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 28px;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
    border: 1px solid #e2e8f0;
    transform: scale(0.92);
    animation: popInModal 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
}

@keyframes fadeInModal {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes popInModal {
    from { opacity: 0; transform: scale(0.92) translateY(15px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

/* ANIMATED TOAST NOTIFICATION */
.toast-animated {
    animation: slideDownToast 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.08);
}

@keyframes slideDownToast {
    from { opacity: 0; transform: translateY(-15px); }
    to { opacity: 1; transform: translateY(0); }
}

/* DANGER MODAL ICON ANIMATION */
.anim-danger-pulse {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #fee2e2;
    color: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px auto;
    position: relative;
    animation: pulseAura 1.8s infinite;
}

@keyframes pulseAura {
    0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    70% { box-shadow: 0 0 0 18px rgba(239, 68, 68, 0); }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}
</style>

<div class="master-grid">

    <!-- ALERT PESAN ANIMASI -->
    <?php if (!empty($pesan_sukses)): ?>
        <div class="toast-animated" style="background:#f0fdf4; border:1px solid #86efac; color:#15803d;">
            <div style="display:flex; align-items:center; gap:12px; font-size:13.5px;">
                <div style="width:32px; height:32px; border-radius:50%; background:#dcfce7; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.8"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div><?php echo $pesan_sukses; ?></div>
            </div>
            <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#16a34a; font-size:18px; cursor:pointer;">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div class="toast-animated" style="background:#fff1f2; border:1px solid #fca5a5; color:#be123c;">
            <div style="display:flex; align-items:center; gap:12px; font-size:13.5px;">
                <div style="width:32px; height:32px; border-radius:50%; background:#fee2e2; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div><?php echo $pesan_error; ?></div>
            </div>
            <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#be123c; font-size:18px; cursor:pointer;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- HERO HEADER -->
    <div class="master-hero">
        <div>
            <div class="master-hero-title">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                <span>Master Multi-Tenant Platform</span>
            </div>
            <div class="master-hero-sub">
                Pusat kontrol distribusi presensi sekolah. Tambah sekolah baru, isolasi database mandiri, dan kelola lisensi dengan mudah.
            </div>
            <?php if (!empty($_SESSION['active_tenant_code']) && $_SESSION['active_tenant_code'] !== 'pasundan2'): ?>
                <div style="margin-top:14px; display:inline-flex; align-items:center; gap:10px; background:rgba(234,179,8,0.2); border:1px solid rgba(234,179,8,0.5); padding:8px 14px; border-radius:10px; font-size:12.5px; color:#fef08a;">
                    <span>Sedang Masuk Sebagai: <strong><?php echo h($current_active_tenant['nama_sekolah']); ?></strong></span>
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reset_tenant">
                        <button type="submit" style="background:#eab308; color:#000; border:none; padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:800; cursor:pointer;">Kembali ke Sekolah Utama</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <button type="button" class="btn-add-school" onclick="openAddModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Tambah Sekolah Baru</span>
        </button>
    </div>

    <!-- KPI STATS -->
    <div class="master-kpi-grid">
        <div class="master-kpi-card">
            <div class="master-kpi-icon" style="background:#eff6ff; color:#2563eb;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
            <div>
                <div class="master-kpi-val"><?php echo $total_sekolah; ?></div>
                <div class="master-kpi-lbl">Total Sekolah Terdaftar</div>
            </div>
        </div>

        <div class="master-kpi-card">
            <div class="master-kpi-icon" style="background:#f0fdf4; color:#16a34a;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="master-kpi-val"><?php echo $total_aktif; ?></div>
                <div class="master-kpi-lbl">Sekolah Aktif</div>
            </div>
        </div>

        <div class="master-kpi-card">
            <div class="master-kpi-icon" style="background:#fef2f2; color:#dc2626;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div>
                <div class="master-kpi-val"><?php echo $total_suspend; ?></div>
                <div class="master-kpi-lbl">Sekolah Ditangguhkan</div>
            </div>
        </div>

        <div class="master-kpi-card">
            <div class="master-kpi-icon" style="background:#faf5ff; color:#9333ea;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            </div>
            <div>
                <div class="master-kpi-val"><?php echo $total_sekolah; ?> DB</div>
                <div class="master-kpi-lbl">Database Terisolasi</div>
            </div>
        </div>
    </div>

    <!-- TABLE SEKOLAH -->
    <div class="tenant-table-card">
        <div class="tenant-table-header">
            <div>
                <h3 style="font-size:16px; font-weight:800; color:#0f172a;">Daftar Sekolah &amp; Database Tenant</h3>
                <p style="font-size:12px; color:#64748b; margin-top:2px;">Setiap sekolah memiliki database MySQL dan berkas terisolasi mandiri</p>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th style="text-align:left;">Nama Sekolah</th>
                        <th>Subdomain</th>
                        <th>Database MySQL</th>
                        <th>Paket</th>
                        <th>Status</th>
                        <th>Kadaluarsa</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_tenants)): ?>
                        <tr>
                            <td colspan="8" style="padding:30px; color:#94a3b8;">Belum ada sekolah terdaftar.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($all_tenants as $i => $t): ?>
                            <tr>
                                <td style="font-weight:700; color:#64748b;"><?php echo $i + 1; ?></td>
                                <td style="text-align:left;">
                                    <div style="font-weight:800; color:#0f172a; font-size:14px;"><?php echo h($t['nama_sekolah']); ?></div>
                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                        <?php if (!empty($t['kontak_pic'])): ?>
                                            PIC: <?php echo h($t['kontak_pic']); ?> (<?php echo h($t['no_hp_pic'] ?? '-'); ?>)
                                        <?php else: ?>
                                            Kota Bandung
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <a href="https://<?php echo h($t['subdomain']); ?>.attendance-pas2.my.id" target="_blank" style="text-decoration:none;">
                                        <span style="background:#f1f5f9; color:#2563eb; font-family:monospace; font-weight:700; padding:4px 8px; border-radius:6px; font-size:12px; border:1px solid #e2e8f0; display:inline-flex; align-items:center; gap:4px;">
                                            <span><?php echo h($t['subdomain']); ?></span>
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    <code style="background:#eff6ff; color:#1d4ed8; padding:3px 8px; border-radius:6px; font-size:12px; font-weight:700;">
                                        <?php echo h($t['db_name']); ?>
                                    </code>
                                </td>
                                <td>
                                    <span style="font-weight:700; color:#475569; font-size:12px;">
                                        <?php echo h($t['paket_langganan']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-tenant-status badge-status-<?php echo $t['status']; ?>">
                                        <?php echo h($t['status']); ?>
                                    </span>
                                </td>
                                <td style="font-size:12px; color:#64748b;">
                                    <?php echo !empty($t['tgl_kadaluarsa']) ? date('d M Y', strtotime($t['tgl_kadaluarsa'])) : 'Selamanya'; ?>
                                </td>
                                <td>
                                    <div style="display:inline-flex; align-items:center; gap:5px;">
                                        <!-- MASUK / IMPERSONATE -->
                                        <form method="POST" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="impersonate_sekolah">
                                            <input type="hidden" name="tenant_code" value="<?php echo h($t['tenant_code']); ?>">
                                            <button type="submit" class="btn-action-sm btn-action-enter" title="Masuk ke dashboard sekolah ini">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                                                <span>Masuk</span>
                                            </button>
                                        </form>

                                        <!-- EDIT SEKOLAH -->
                                        <button type="button" class="btn-action-sm btn-action-edit" title="Edit Informasi Sekolah" onclick='openEditModal(<?php echo json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            <span>Edit</span>
                                        </button>

                                        <!-- HAPUS SEKOLAH (MODERN MODAL) -->
                                        <?php if ($t['tenant_code'] !== 'pasundan2'): ?>
                                            <button type="button" class="btn-action-sm btn-action-delete" title="Hapus Sekolah &amp; Database" onclick="openDeleteModal(<?php echo $t['id']; ?>, '<?php echo addslashes(h($t['nama_sekolah'])); ?>', '<?php echo addslashes(h($t['db_name'])); ?>')">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                <span>Hapus</span>
                                            </button>
                                        <?php else: ?>
                                            <span style="font-size:10px; color:#94a3b8; background:#f1f5f9; padding:4px 8px; border-radius:6px; font-weight:700;">Utama</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL TAMBAH SEKOLAH BARU (1-KLIK PROVISIONING)              -->
<!-- ============================================================ -->
<div class="modal-saas-overlay" id="modalAddSchool" onclick="closeAddModal(event)">
    <div class="modal-saas-card" onclick="event.stopPropagation()">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:14px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16.5px; font-weight:800; color:#0f172a;">Tambah Sekolah Baru</h3>
                    <p style="font-size:12px; color:#64748b;">Sistem otomatis membuat database MySQL &amp; akun admin baru</p>
                </div>
            </div>
            <button type="button" onclick="closeAddModal()" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:22px;">&times;</button>
        </div>

        <form method="POST" action="master_tenants.php" id="formAddSchool" onsubmit="handleProvisioningSubmit(this)">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="tambah_sekolah">

            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Nama Lengkap Sekolah *</label>
                    <input type="text" name="nama_sekolah" required placeholder="Contoh: SMA Negeri 1 Bandung" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;" onkeyup="autoGenerateCode(this.value)">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Subdomain Sekolah *</label>
                        <input type="text" name="subdomain" id="inputSubdomain" required placeholder="sman1bdg" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-family:monospace;" onkeyup="updateDbPreview(this.value)">
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Nama Database Otomatis</label>
                        <input type="text" id="inputDbPreview" readonly value="db_tenant_..." style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; background:#f8fafc; color:#2563eb; font-weight:700; border-radius:10px; font-size:13px; font-family:monospace;">
                    </div>
                </div>

                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:14px 16px;">
                    <div style="font-size:12.5px; font-weight:800; color:#1e40af; margin-bottom:10px;">Akun Admin Sekolah (Login Awal)</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label style="font-size:11.5px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Username Admin</label>
                            <input type="text" name="admin_username" id="inputAdminUser" required placeholder="admin_sekolah" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                        </div>
                        <div>
                            <label style="font-size:11.5px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Password Admin</label>
                            <input type="text" name="admin_password" required value="admin123" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Paket Langganan</label>
                        <select name="paket" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                            <option value="Standard">Standard (Max 100 Guru/Karyawan)</option>
                            <option value="Pro" selected>Pro (Max 500 Guru/Karyawan)</option>
                            <option value="Enterprise Unlimited">Enterprise Unlimited</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Masa Aktif Hingga</label>
                        <input type="date" name="tgl_kadaluarsa" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Nama PIC Sekolah</label>
                        <input type="text" name="kontak_pic" placeholder="Bpk. Rahmat, S.Pd" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Nomor WhatsApp PIC</label>
                        <input type="text" name="no_hp_pic" placeholder="08123456789" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                    </div>
                </div>

                <div style="margin-top:10px; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeAddModal()" style="padding:10px 18px; border:1px solid #cbd5e1; background:#f8fafc; border-radius:10px; font-weight:700; cursor:pointer;">Batal</button>
                    <button type="submit" id="btnSubmitProvision" style="padding:10px 22px; border:none; background:linear-gradient(135deg, #2563eb, #1d4ed8); color:#fff; border-radius:10px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                        <span>Proses &amp; Buat Database</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL EDIT SEKOLAH (MODERN POPUP)                           -->
<!-- ============================================================ -->
<div class="modal-saas-overlay" id="modalEditSchool" onclick="closeEditModal(event)">
    <div class="modal-saas-card" onclick="event.stopPropagation()">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:14px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:#f1f5f9; color:#0f172a; display:flex; align-items:center; justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16.5px; font-weight:800; color:#0f172a;">Edit Informasi Sekolah</h3>
                    <p style="font-size:12px; color:#64748b;">Perbarui profil, paket langganan, atau reset password admin</p>
                </div>
            </div>
            <button type="button" onclick="closeEditModal()" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:22px;">&times;</button>
        </div>

        <form method="POST" action="master_tenants.php" id="formEditSchool">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_sekolah">
            <input type="hidden" name="tenant_id" id="editTenantId">

            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Nama Sekolah *</label>
                    <input type="text" name="nama_sekolah" id="editNamaSekolah" required style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Subdomain (Readonly)</label>
                        <input type="text" id="editSubdomain" readonly style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; background:#f8fafc; color:#64748b; font-family:monospace; border-radius:10px; font-size:13px;">
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Database MySQL</label>
                        <input type="text" id="editDbName" readonly style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; background:#f8fafc; color:#2563eb; font-weight:700; font-family:monospace; border-radius:10px; font-size:13px;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Paket Langganan</label>
                        <select name="paket" id="editPaket" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                            <option value="Standard">Standard (Max 100 Guru/Karyawan)</option>
                            <option value="Pro">Pro (Max 500 Guru/Karyawan)</option>
                            <option value="Enterprise Unlimited">Enterprise Unlimited</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Masa Berlaku Hingga</label>
                        <input type="date" name="tgl_kadaluarsa" id="editTglKadaluarsa" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Nama PIC</label>
                        <input type="text" name="kontak_pic" id="editKontakPic" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">No. WhatsApp PIC</label>
                        <input type="text" name="no_hp_pic" id="editNoHpPic" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px;">
                    </div>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px;">
                    <label style="font-size:12px; font-weight:700; color:#0f172a; display:block; margin-bottom:4px;">Reset Password Admin Sekolah (Opsional)</label>
                    <p style="font-size:11.5px; color:#64748b; margin-bottom:8px;">Kosongkan jika tidak ingin mengubah password akun admin sekolah ini.</p>
                    <input type="password" name="new_admin_password" placeholder="Masukkan password baru jika ingin reset" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                </div>

                <div style="margin-top:10px; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeEditModal()" style="padding:10px 18px; border:1px solid #cbd5e1; background:#f8fafc; border-radius:10px; font-weight:700; cursor:pointer;">Batal</button>
                    <button type="submit" style="padding:10px 22px; border:none; background:linear-gradient(135deg, #0f172a, #1e293b); color:#fff; border-radius:10px; font-weight:800; cursor:pointer;">
                        Simpan Perubahan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL KONFIRMASI HAPUS MODERN DENGAN ANIMASI                -->
<!-- ============================================================ -->
<div class="modal-saas-overlay" id="modalDeleteSchool" onclick="closeDeleteModal(event)">
    <div class="modal-saas-card" style="max-width:440px; text-align:center; padding:32px 28px;" onclick="event.stopPropagation()">
        <div class="anim-danger-pulse">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        </div>
        
        <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin-bottom:8px;">Hapus Sekolah Ini?</h3>
        <p style="font-size:13px; color:#64748b; line-height:1.5; margin-bottom:18px;">
            Anda akan menghapus sekolah <b id="delSchoolNameText" style="color:#0f172a;">-</b>. Tindakan ini tidak dapat dibatalkan.
        </p>

        <form method="POST" action="master_tenants.php" id="formDeleteSchool">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="hapus_sekolah">
            <input type="hidden" name="tenant_id" id="delTenantId">

            <div style="background:#fff1f2; border:1px solid #fecdd3; border-radius:12px; padding:12px 14px; margin-bottom:20px; text-align:left;">
                <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-size:12.5px; color:#9f1239; font-weight:600;">
                    <input type="checkbox" name="drop_database" value="1" checked style="margin-top:2.5px; accent-color:#e11d48;">
                    <span>Hapus juga database MySQL (<code id="delDbNameText" style="font-weight:700;">db_tenant_...</code>) dan seluruh data presensi secara permanen.</span>
                </label>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <button type="button" onclick="closeDeleteModal()" style="padding:11px; border:1px solid #cbd5e1; background:#f8fafc; border-radius:10px; font-weight:700; color:#475569; cursor:pointer;">
                    Batal
                </button>
                <button type="submit" style="padding:11px; border:none; background:linear-gradient(135deg, #ef4444, #dc2626); color:#fff; border-radius:10px; font-weight:800; cursor:pointer; box-shadow:0 4px 12px rgba(239,68,68,0.3);">
                    Ya, Hapus
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// --- MODAL TAMBAH SEKOLAH ---
function openAddModal() {
    document.getElementById('modalAddSchool').style.display = 'flex';
}
function closeAddModal(e) {
    if (!e || e.target === document.getElementById('modalAddSchool')) {
        document.getElementById('modalAddSchool').style.display = 'none';
    }
}
function autoGenerateCode(name) {
    const code = name.toLowerCase()
                     .replace(/[^a-z0-9]/g, '')
                     .substring(0, 15);
    const subInput = document.getElementById('inputSubdomain');
    if (subInput && (!subInput.value || subInput.dataset.manual !== '1')) {
        subInput.value = code;
        updateDbPreview(code);
        document.getElementById('inputAdminUser').value = 'admin_' + code;
    }
}
function updateDbPreview(code) {
    const clean = code.toLowerCase().replace(/[^a-z0-9_]/g, '');
    document.getElementById('inputDbPreview').value = clean ? 'db_tenant_' + clean : 'db_tenant_...';
    document.getElementById('inputAdminUser').value = 'admin_' + (clean || 'sekolah');
}
function handleProvisioningSubmit(form) {
    const btn = document.getElementById('btnSubmitProvision');
    btn.disabled = true;
    btn.style.opacity = '0.75';
    btn.innerHTML = '<span style="display:inline-block; width:14px; height:14px; border:2px solid #fff; border-top-color:transparent; border-radius:50%; animation:spin 0.6s linear infinite;"></span> Membuat Database &amp; Skema...';
}

// --- MODAL EDIT SEKOLAH ---
function openEditModal(tenant) {
    document.getElementById('editTenantId').value       = tenant.id;
    document.getElementById('editNamaSekolah').value    = tenant.nama_sekolah || '';
    document.getElementById('editSubdomain').value      = tenant.subdomain || '';
    document.getElementById('editDbName').value         = tenant.db_name || '';
    document.getElementById('editPaket').value          = tenant.paket_langganan || 'Pro';
    document.getElementById('editTglKadaluarsa').value  = tenant.tgl_kadaluarsa || '';
    document.getElementById('editKontakPic').value      = tenant.kontak_pic || '';
    document.getElementById('editNoHpPic').value        = tenant.no_hp_pic || '';
    
    document.getElementById('modalEditSchool').style.display = 'flex';
}
function closeEditModal(e) {
    if (!e || e.target === document.getElementById('modalEditSchool')) {
        document.getElementById('modalEditSchool').style.display = 'none';
    }
}

// --- MODAL HAPUS SEKOLAH (MODERN CONFIRMATION) ---
function openDeleteModal(tenantId, schoolName, dbName) {
    document.getElementById('delTenantId').value              = tenantId;
    document.getElementById('delSchoolNameText').textContent  = schoolName;
    document.getElementById('delDbNameText').textContent      = dbName;
    document.getElementById('modalDeleteSchool').style.display = 'flex';
}
function closeDeleteModal(e) {
    if (!e || e.target === document.getElementById('modalDeleteSchool')) {
        document.getElementById('modalDeleteSchool').style.display = 'none';
    }
}
</script>

<?php render_footer(); ?>
