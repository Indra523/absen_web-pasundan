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

// --- POST HANDLER: PROVISIONING / EDIT / TOGGLE STATUS / IMPERSONATE ---
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

    // 2. TOGGLE STATUS (AKTIF / SUSPEND / TRIAL)
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

    // 3. EDIT INFORMASI SEKOLAH
    elseif ($action === 'edit_sekolah') {
        $t_id           = (int)($_POST['tenant_id'] ?? 0);
        $nama_sekolah   = trim($_POST['nama_sekolah'] ?? '');
        $custom_domain  = !empty($_POST['custom_domain']) ? trim($_POST['custom_domain']) : null;
        $paket          = trim($_POST['paket'] ?? 'Pro');
        $max_karyawan   = (int)($_POST['max_karyawan'] ?? 500);
        $tgl_kadaluarsa = !empty($_POST['tgl_kadaluarsa']) ? trim($_POST['tgl_kadaluarsa']) : null;
        $kontak_pic     = trim($_POST['kontak_pic'] ?? '');
        $no_hp_pic      = trim($_POST['no_hp_pic'] ?? '');
        $alamat         = trim($_POST['alamat'] ?? '');

        $stmt_ed = $master_conn->prepare("UPDATE master_tenants SET nama_sekolah=?, custom_domain=?, paket_langganan=?, max_karyawan=?, tgl_kadaluarsa=?, kontak_pic=?, no_hp_pic=?, alamat_sekolah=? WHERE id=?");
        $stmt_ed->bind_param("sssissssi", $nama_sekolah, $custom_domain, $paket, $max_karyawan, $tgl_kadaluarsa, $kontak_pic, $no_hp_pic, $alamat, $t_id);
        if ($stmt_ed->execute()) {
            $pesan_sukses = "Informasi sekolah <b>" . h($nama_sekolah) . "</b> berhasil diperbarui.";
        } else {
            $pesan_error = "Gagal memperbarui sekolah: " . $master_conn->error;
        }
    }

    // 4. IMPERSONATE / MASUK KE DASHBOARD SEKOLAH TERKAIT
    elseif ($action === 'impersonate_sekolah') {
        $tenant_code = trim($_POST['tenant_code'] ?? '');
        if (!empty($tenant_code)) {
            $_SESSION['active_tenant_code'] = $tenant_code;
            $_SESSION['role'] = 'superadmin'; // Berikan hak akses penuh superadmin pada tenant tersebut
            header("Location: index.php?tenant_switched=1");
            exit;
        }
    }

    // 5. RESET TENANT SESSION (KEMBALI KE SEKOLAH UTAMA)
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

/* MODAL STYLES */
.modal-saas-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(6px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-saas-card {
    background: #ffffff;
    border-radius: 20px;
    max-width: 580px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 28px;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
    border: 1px solid #e2e8f0;
}
</style>

<div class="master-grid">

    <!-- ALERT PESAN -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #86efac; color:#15803d; border-radius:12px; padding:14px 18px; font-size:13.5px; display:flex; align-items:center; gap:10px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; color:#be123c; border-radius:12px; padding:14px 18px; font-size:13.5px; display:flex; align-items:center; gap:10px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
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
                Pusat kontrol distribusi presensi sekolah. Tambah sekolah baru, isolasi database mandiri, dan kelola lisensi.
            </div>
            <?php if (!empty($_SESSION['active_tenant_code']) && $_SESSION['active_tenant_code'] !== 'pasundan2'): ?>
                <div style="margin-top:12px; display:inline-flex; align-items:center; gap:8px; background:rgba(234,179,8,0.2); border:1px solid rgba(234,179,8,0.5); padding:6px 12px; border-radius:8px; font-size:12px; color:#fef08a;">
                    <span>Sedang Masuk Sebagai: <strong><?php echo h($current_active_tenant['nama_sekolah']); ?></strong></span>
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reset_tenant">
                        <button type="submit" style="background:#eab308; color:#000; border:none; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:800; cursor:pointer;">Kembali ke Sekolah Utama</button>
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
                <p style="font-size:12px; color:#64748b; margin-top:2px;">Semua sekolah memiliki database dan penyimpanan mandiri</p>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th style="text-align:left;">Nama Sekolah</th>
                        <th>Kode / Subdomain</th>
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
                                    <span style="background:#f1f5f9; color:#1e293b; font-family:monospace; font-weight:700; padding:3px 8px; border-radius:6px; font-size:12px;">
                                        <?php echo h($t['subdomain']); ?>
                                    </span>
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
                                    <div style="display:inline-flex; align-items:center; gap:6px;">
                                        <!-- IMPERSONATE BUTTON -->
                                        <form method="POST" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="impersonate_sekolah">
                                            <input type="hidden" name="tenant_code" value="<?php echo h($t['tenant_code']); ?>">
                                            <button type="submit" title="Masuk ke dashboard sekolah ini" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:11.5px; font-weight:700; padding:5px 10px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                                                <span>Masuk</span>
                                            </button>
                                        </form>

                                        <!-- TOGGLE SUSPEND / AKTIF -->
                                        <?php if ($t['tenant_code'] !== 'pasundan2'): ?>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Ubah status sekolah <?php echo addslashes($t['nama_sekolah']); ?>?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $t['status'] === 'aktif' ? 'suspend' : 'aktif'; ?>">
                                                <button type="submit" title="<?php echo $t['status'] === 'aktif' ? 'Tangguhkan Sekolah' : 'Aktifkan Sekolah'; ?>" style="background:<?php echo $t['status'] === 'aktif' ? '#fee2e2' : '#dcfce7'; ?>; color:<?php echo $t['status'] === 'aktif' ? '#b91c1c' : '#15803d'; ?>; border:1px solid <?php echo $t['status'] === 'aktif' ? '#fca5a5' : '#86efac'; ?>; font-size:11px; font-weight:700; padding:5px 8px; border-radius:6px; cursor:pointer;">
                                                    <?php echo $t['status'] === 'aktif' ? 'Suspend' : 'Aktifkan'; ?>
                                                </button>
                                            </form>
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
                <div style="width:36px; height:36px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16px; font-weight:800; color:#0f172a;">Tambah Sekolah Baru</h3>
                    <p style="font-size:12px; color:#64748b;">Sistem akan membuatkan database MySQL &amp; akun admin baru secara otomatis</p>
                </div>
            </div>
            <button type="button" onclick="closeAddModal()" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:20px;">&times;</button>
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
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Subdomain / Kode Sekolah *</label>
                        <input type="text" name="subdomain" id="inputSubdomain" required placeholder="sman1bdg" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-family:monospace;" onkeyup="updateDbPreview(this.value)">
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:5px;">Nama Database Otomatis</label>
                        <input type="text" id="inputDbPreview" readonly value="db_tenant_..." style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; background:#f8fafc; color:#2563eb; font-weight:700; border-radius:10px; font-size:13px; font-family:monospace;">
                    </div>
                </div>

                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:12px 16px;">
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

<script>
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
</script>

<?php render_footer(); ?>
