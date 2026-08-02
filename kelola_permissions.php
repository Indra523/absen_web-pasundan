<?php
// ============================================================
// KELOLA HAK AKSES ROLE (RBAC MANAGEMENT) - Superadmin Only
// ============================================================

require_once __DIR__ . '/layout.php';
require_role(['superadmin']);

$conn = getDB();
$pesan_sukses = '';
$pesan_error  = '';

// Definisi semua halaman yang bisa diatur aksesnya
// [page_key => [label, category, deskripsi, roles yang bisa diatur]]
$pages_config = [
    // --- MONITORING & LAPORAN ---
    'index'          => ['label' => 'Live Monitoring', 'cat' => 'Monitoring & Laporan', 'desc' => 'Dashboard absensi real-time mesin fingerprint', 'roles' => ['admin','rnd','tatausaha','staff']],
    'export_bulanan' => ['label' => 'Laporan Bulanan', 'cat' => 'Monitoring & Laporan', 'desc' => 'Matriks & rekap kehadiran bulanan guru/karyawan', 'roles' => ['admin','rnd','tatausaha','staff']],
    'riwayat'        => ['label' => 'Riwayat Individual', 'cat' => 'Monitoring & Laporan', 'desc' => 'Log absensi detail per individu + profil karyawan', 'roles' => ['admin','rnd','tatausaha','staff']],
    'export_excel'   => ['label' => 'Export Excel', 'cat' => 'Monitoring & Laporan', 'desc' => 'Export laporan matriks ke format Excel (.xls)', 'roles' => ['admin','rnd','tatausaha','staff']],
    'notifikasi'     => ['label' => 'Pusat Notifikasi Real-time', 'cat' => 'Monitoring & Laporan', 'desc' => 'Terima notifikasi pengajuan izin & alert sistem secara real-time', 'roles' => ['admin','rnd','tatausaha','staff']],
    // --- PERIZINAN ---
    'kelola_izin'    => ['label' => 'Kelola Cuti/Izin/Sakit', 'cat' => 'Perizinan', 'desc' => 'Persetujuan dan pengelolaan pengajuan izin karyawan', 'roles' => ['admin','rnd','tatausaha','staff']],
    // --- FITUR RnD & AUDIT ---
    'rnd_analytics'  => ['label' => 'RnD Analytics', 'cat' => 'Fitur Riset & Audit', 'desc' => 'Analitik mendalam, tren, dan statistik kehadiran', 'roles' => ['admin','rnd','tatausaha','staff']],
    'audit_log'      => ['label' => 'Audit Log System', 'cat' => 'Fitur Riset & Audit', 'desc' => 'Rekaman semua aktivitas dan perubahan sistem', 'roles' => ['admin','rnd','tatausaha','staff']],
    'export_pdf'     => ['label' => 'Export PDF Official', 'cat' => 'Fitur Riset & Audit', 'desc' => 'Cetak laporan resmi berkop surat sekolah (PDF)', 'roles' => ['admin','rnd','tatausaha','staff']],
    // --- PENGATURAN ---
    'jadwal_guru'    => ['label' => 'Kelola Jadwal Guru', 'cat' => 'Pengaturan', 'desc' => 'Atur hari mengajar per guru untuk kalkulasi kehadiran', 'roles' => ['admin','rnd','tatausaha','staff']],
    // --- PORTAL USER ---
    'user_profile'   => ['label' => 'Portal: Profil & Data Diri', 'cat' => 'Portal Mandiri (User)', 'desc' => 'Edit foto profil, no HP, TTL, dan alamat mandiri', 'roles' => ['admin','rnd','tatausaha','staff','user']],
    'user_izin'      => ['label' => 'Portal: Pengajuan Cuti/Izin/Sakit', 'cat' => 'Portal Mandiri (User)', 'desc' => 'Submit pengajuan cuti, izin, dan sakit secara mandiri', 'roles' => ['admin','rnd','tatausaha','staff','user']],
    'user_riwayat'   => ['label' => 'Portal: Riwayat Presensi Saya', 'cat' => 'Portal Mandiri (User)', 'desc' => 'Lihat riwayat absen dan ringkasan kehadiran pribadi', 'roles' => ['admin','rnd','tatausaha','staff','user']],
    'ganti_password' => ['label' => 'Ganti Password Akun', 'cat' => 'Portal Mandiri (User)', 'desc' => 'Ubah password akun mandiri untuk semua role', 'roles' => ['admin','rnd','tatausaha','staff','user']],
];

$all_roles_configurable = ['admin', 'rnd', 'tatausaha', 'staff', 'user'];
$role_labels = [
    'admin'     => 'Admin',
    'rnd'       => 'RnD',
    'tatausaha' => 'Tata Usaha',
    'staff'     => 'Staff',
    'user'      => 'User (Karyawan)',
];
$role_colors = [
    'admin'     => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'border' => '#bfdbfe'],
    'rnd'       => ['bg' => '#fdf4ff', 'text' => '#7e22ce', 'border' => '#e9d5ff'],
    'tatausaha' => ['bg' => '#fff7ed', 'text' => '#c2410c', 'border' => '#ffedd5'],
    'staff'     => ['bg' => '#f1f5f9', 'text' => '#475569', 'border' => '#cbd5e1'],
    'user'      => ['bg' => '#f0fdf4', 'text' => '#15803d', 'border' => '#bbf7d0'],
];

// PROSES SIMPAN PERUBAHAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_permissions') {
    csrf_verify();

    $updated = 0;
    foreach ($pages_config as $page_key => $page) {
        foreach ($page['roles'] as $role) {
            $checkbox_name = "perm_{$role}_{$page_key}";
            $is_allowed = isset($_POST[$checkbox_name]) ? 1 : 0;

            $stmt = $conn->prepare("INSERT INTO role_permissions (role, page_key, is_allowed)
                                    VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE is_allowed = ?");
            $stmt->bind_param("ssii", $role, $page_key, $is_allowed, $is_allowed);
            if ($stmt->execute()) $updated++;
        }
    }

    // Invalidate semua session cache permission user yang sedang login
    invalidate_perm_cache();
    log_audit("MANAGE_PERMISSIONS", "Superadmin memperbarui {$updated} entri hak akses role");
    $pesan_sukses = "Hak akses berhasil diperbarui ({$updated} pengaturan disimpan).";
}

// FETCH DATA PERMISSIONS SAAT INI
$current_perms = [];
$res_perms = $conn->query("SELECT role, page_key, is_allowed FROM role_permissions");
if ($res_perms) {
    while ($rp = $res_perms->fetch_assoc()) {
        $current_perms[$rp['role']][$rp['page_key']] = (bool)$rp['is_allowed'];
    }
}

// Helper: cek apakah role+page diizinkan
function is_perm_on($perms, $role, $page_key) {
    return !empty($perms[$role][$page_key]);
}

// Kelompokkan pages per kategori
$categories = [];
foreach ($pages_config as $key => $page) {
    $categories[$page['cat']][$key] = $page;
}

render_header("Kelola Hak Akses Role", "kelola_permissions");
?>

<style>
.perm-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow-x:auto; box-shadow:0 2px 12px rgba(15,23,42,0.04); margin-bottom:24px; }
.perm-cat-header { padding:14px 24px; font-size:13px; font-weight:700; color:#0f172a; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:8px; sticky:top; }
.perm-row { display:grid; align-items:center; padding:14px 20px; border-bottom:1px solid #f1f5f9; gap:12px; transition:background 0.12s; min-width:860px; }
.perm-row:hover { background:#fafafa; }
.perm-row:last-child { border-bottom:none; }
.perm-page-info { min-width:200px; }
.perm-page-label { font-size:13.5px; font-weight:700; color:#0f172a; margin-bottom:2px; }
.perm-page-desc { font-size:11.5px; color:#64748b; line-height:1.4; }

/* Toggle Switch */
.toggle-wrap { display:flex; flex-direction:column; align-items:center; gap:4px; }
.toggle-label-sm { font-size:10px; color:#94a3b8; font-weight:600; text-align:center; white-space:nowrap; }
.toggle-switch { position:relative; width:44px; height:24px; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#e2e8f0; border-radius:24px; transition:0.2s; }
.toggle-slider:before { position:absolute; content:""; height:18px; width:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:0.2s; box-shadow:0 1px 3px rgba(0,0,0,0.2); }
input:checked + .toggle-slider { background:#22c55e; }
input:checked + .toggle-slider:before { transform:translateX(20px); }
input:disabled + .toggle-slider { background:#f1f5f9; cursor:not-allowed; opacity:0.5; }

.role-header-badge { display:flex; flex-direction:column; align-items:center; gap:2px; }
.locked-badge { background:#f1f5f9; border:1px dashed #cbd5e1; color:#94a3b8; padding:4px 10px; border-radius:8px; font-size:10px; font-weight:600; }
</style>

<?php if (!empty($pesan_sukses)): ?>
<div style="background:linear-gradient(135deg,#dcfce7,#f0fdf4); border:1px solid #86efac; color:#15803d; padding:14px 20px; border-radius:12px; margin-bottom:20px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:10px; box-shadow:0 2px 8px rgba(21,128,61,0.1);">
    <span style="font-size:18px;">✓</span> <?php echo $pesan_sukses; ?>
</div>
<?php endif; ?>

<!-- PAGE HEADER -->
<div style="background:linear-gradient(135deg,#0f172a,#1e3a5f); border-radius:20px; padding:28px 32px; margin-bottom:24px; color:#fff; position:relative; overflow:hidden;">
    <div style="position:absolute;top:-50px;right:-50px;width:200px;height:200px;background:rgba(99,102,241,0.1);border-radius:50%;"></div>
    <div style="position:relative;z-index:1;">
        <div style="font-size:11px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Superadmin Panel</div>
        <h2 style="font-size:22px; font-weight:800; color:#fff; margin-bottom:6px;">Manajemen Hak Akses Role</h2>
        <p style="color:#94a3b8; font-size:13.5px; margin-bottom:16px;">Atur halaman dan fitur apa saja yang boleh diakses oleh setiap role pengguna sistem.</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <?php foreach ($all_roles_configurable as $r): ?>
            <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:8px 16px; text-align:center;">
                <div style="font-size:14px; font-weight:800; color:#fff;"><?php echo $role_labels[$r]; ?></div>
                <div style="font-size:10px; color:#94a3b8; margin-top:2px;">
                    <?php
                    $cnt = 0;
                    foreach ($pages_config as $pk => $pc) {
                        if (in_array($r, $pc['roles']) && is_perm_on($current_perms, $r, $pk)) $cnt++;
                    }
                    $total = count(array_filter($pages_config, fn($p) => in_array($r, $p['roles'])));
                    echo "{$cnt} / {$total} Halaman Aktif";
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); border-radius:10px; padding:8px 16px; text-align:center;">
                <div style="font-size:14px; font-weight:800; color:#fca5a5;">Superadmin</div>
                <div style="font-size:10px; color:#94a3b8; margin-top:2px;">Full Access (Locked)</div>
            </div>
        </div>
    </div>
</div>

<!-- INFO NOTE -->
<div style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:14px 18px; margin-bottom:24px; display:flex; gap:12px; align-items:flex-start;">
    <span style="font-size:18px; flex-shrink:0;">💡</span>
    <div style="font-size:13px; color:#92400e;">
        <b>Catatan:</b> Role <b>Superadmin</b> selalu memiliki akses penuh ke semua halaman dan tidak dapat dibatasi.
        Halaman yang bertanda <b>🔒 Terkunci</b> adalah halaman khusus Superadmin yang tidak dapat diberikan ke role lain.
        Perubahan yang disimpan akan langsung berlaku untuk pengguna yang sedang login.
    </div>
</div>

<form method="POST" action="kelola_permissions.php">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save_permissions">

    <?php foreach ($categories as $cat_name => $cat_pages): ?>
    <div class="perm-card">
        <div class="perm-cat-header">
            <?php
            $cat_icons = [
                'Monitoring & Laporan' => '📊',
                'Perizinan' => '📝',
                'Fitur Riset & Audit' => '🔬',
                'Pengaturan' => '⚙️',
                'Portal Mandiri (User)' => '👤',
            ];
            echo $cat_icons[$cat_name] ?? '📄';
            ?>
            &nbsp;<?php echo $cat_name; ?>
            <span style="margin-left:auto; font-size:11px; color:#94a3b8; font-weight:500;"><?php echo count($cat_pages); ?> Halaman</span>
        </div>

        <?php 
        $num_role_cols = count($all_roles_configurable) + 1; // +1 untuk Superadmin
        $grid_cols_style = "grid-template-columns: minmax(220px, 1fr) " . str_repeat("minmax(95px, 110px) ", $num_role_cols) . ";";
        ?>

        <!-- HEADER ROW: role columns -->
        <div class="perm-row" style="background:#f8fafc; border-bottom:2px solid #e2e8f0; <?php echo $grid_cols_style; ?> padding:12px 20px;">
            <div style="font-size:11.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Halaman / Fitur</div>
            <?php foreach ($all_roles_configurable as $role): ?>
            <div class="role-header-badge" style="text-align:center;">
                <span style="background:<?php echo $role_colors[$role]['bg']; ?>; color:<?php echo $role_colors[$role]['text']; ?>; border:1px solid <?php echo $role_colors[$role]['border']; ?>; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; display:inline-block;">
                    <?php echo $role_labels[$role]; ?>
                </span>
            </div>
            <?php endforeach; ?>
            <div class="role-header-badge" style="text-align:center;">
                <span style="background:#fee2e2; color:#be123c; border:1px solid #fca5a5; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; display:inline-block;">
                    Superadmin
                </span>
            </div>
        </div>

        <?php foreach ($cat_pages as $page_key => $page): ?>
        <div class="perm-row" style="<?php echo $grid_cols_style; ?>">
            <!-- Page Info -->
            <div class="perm-page-info">
                <div class="perm-page-label"><?php echo $page['label']; ?></div>
                <div class="perm-page-desc"><?php echo $page['desc']; ?></div>
            </div>

            <!-- Toggle per role -->
            <?php foreach ($all_roles_configurable as $role): ?>
            <div class="toggle-wrap">
                <?php if (in_array($role, $page['roles'])): ?>
                    <?php $checked = is_perm_on($current_perms, $role, $page_key); ?>
                    <label class="toggle-switch" title="<?php echo $role_labels[$role]; ?>: <?php echo $checked ? 'Diizinkan' : 'Dilarang'; ?>">
                        <input type="checkbox"
                               name="perm_<?php echo $role; ?>_<?php echo $page_key; ?>"
                               <?php echo $checked ? 'checked' : ''; ?>
                               onchange="updateStatus(this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-label-sm" id="lbl_<?php echo $role; ?>_<?php echo $page_key; ?>" style="color:<?php echo $checked ? '#22c55e' : '#ef4444'; ?>;">
                        <?php echo $checked ? 'Boleh' : 'Tidak'; ?>
                    </span>
                <?php else: ?>
                    <span class="locked-badge">🔒</span>
                    <span class="toggle-label-sm">–</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- Superadmin: selalu full -->
            <div class="toggle-wrap">
                <label class="toggle-switch">
                    <input type="checkbox" checked disabled>
                    <span class="toggle-slider"></span>
                </label>
                <span class="toggle-label-sm" style="color:#22c55e;">Full</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- SAVE BUTTON -->
    <div style="display:flex; justify-content:flex-end; gap:12px; padding:8px 0 24px;">
        <a href="index.php" style="padding:10px 20px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; color:#475569; font-size:13.5px; font-weight:600; text-decoration:none;">
            Batal
        </a>
        <button type="submit" class="btn btn-primary" style="padding:10px 28px; font-size:13.5px; font-weight:700; min-height:42px; border-radius:10px;">
            Simpan Semua Perubahan
        </button>
    </div>
</form>

<script>
function updateStatus(checkbox) {
    const parts = checkbox.name.split('_'); // perm_{role}_{page_key}
    const role = parts[1];
    const pageKey = parts.slice(2).join('_');
    const lblEl = document.getElementById('lbl_' + role + '_' + pageKey);
    if (lblEl) {
        lblEl.textContent = checkbox.checked ? 'Boleh' : 'Tidak';
        lblEl.style.color = checkbox.checked ? '#22c55e' : '#ef4444';
    }
}
</script>

<?php render_footer(); ?>
