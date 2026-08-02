<?php
// ============================================================
// HALAMAN MANAJEMEN USER — Superadmin Only
// Fitur: Bulk Generate Akun, Reset Password, Tambah/Edit/Hapus, Pagination & Real-Time Online
// ============================================================

require_once __DIR__ . '/layout.php';
require_role(['superadmin']);

$conn = getDB();
$pesan_sukses = '';
$pesan_error  = '';

// Helper Format Waktu Terakhir Aktif
function format_last_active($datetime_str) {
    if (empty($datetime_str)) {
        return "<span style='color:#94a3b8; font-style:italic;'>Belum pernah</span>";
    }
    $time = strtotime($datetime_str);
    $diff = time() - $time;

    if ($diff < 60) {
        return "<span style='color:#10b981; font-weight:700;'>Baru saja</span>";
    } elseif ($diff < 3600) {
        $m = floor($diff / 60);
        return "{$m} menit yang lalu";
    } elseif (date('Y-m-d', $time) === date('Y-m-d')) {
        return "Hari ini " . date('H:i', $time);
    } elseif (date('Y-m-d', $time) === date('Y-m-d', strtotime('-1 day'))) {
        return "Kemarin " . date('H:i', $time);
    } else {
        return date('d/m/Y H:i', $time);
    }
}

// -------------------------------------------------------
// PROSES POST ACTION
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---- 1. BULK CREATE AKUN KARYAWAN ----
    if ($action === 'bulk_create_users') {
        $sql = "SELECT mk.pin, mk.nama, mk.tipe 
                FROM master_karyawan mk 
                LEFT JOIN users u ON (u.pin = mk.pin OR u.username = mk.pin) 
                WHERE u.id IS NULL";
        $res = $conn->query($sql);
        $unregistered = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

        if (empty($unregistered)) {
            $pesan_sukses = "Semua guru & karyawan terdaftar sudah memiliki akun user!";
        } else {
            $created_count = 0;
            $failed_count  = 0;
            $stmt_ins = $conn->prepare("INSERT INTO users (username, password, role, pin) VALUES (?, ?, 'user', ?)");

            foreach ($unregistered as $emp) {
                $emp_pin = trim($emp['pin']);
                if (empty($emp_pin)) continue;

                $username     = "user" . $emp_pin; // Contoh: PIN 88 -> username user88
                $default_pass = "smk" . $emp_pin;  // Contoh: PIN 88 -> password smk88
                $hash         = password_hash($default_pass, PASSWORD_BCRYPT);

                $stmt_ins->bind_param("sss", $username, $hash, $emp_pin);
                if ($stmt_ins->execute()) {
                    $created_count++;
                } else {
                    $failed_count++;
                }
            }

            if ($created_count > 0) {
                $pesan_sukses = "⚡ Berhasil membuat <b>{$created_count}</b> akun user baru untuk guru & karyawan! Username: <code>user[PIN]</code>, Password Default: <code>smk[PIN]</code> (contoh PIN 88 ➔ Username: <code>user88</code>, Password: <code>smk88</code>).";
                log_audit("BULK_CREATE_USERS", "Bulk generate {$created_count} akun user karyawan baru");
            } else {
                $pesan_error = "Gagal membuat akun bulk: " . $conn->error;
            }
        }
    }

    // ---- 2. RESET PASSWORD USER BY SUPERADMIN ----
    elseif ($action === 'reset_password_user') {
        $id_target = (int)($_POST['id_target'] ?? 0);
        $pass_baru = trim($_POST['pass_baru'] ?? '');

        if ($id_target <= 0) {
            $pesan_error = "ID user tidak valid.";
        } else {
            $stmt_u = $conn->prepare("SELECT username, pin FROM users WHERE id = ?");
            $stmt_u->bind_param("i", $id_target);
            $stmt_u->execute();
            $usr = $stmt_u->get_result()->fetch_assoc();

            if (!$usr) {
                $pesan_error = "User tidak ditemukan.";
            } else {
                if (empty($pass_baru)) {
                    $pass_baru = !empty($usr['pin']) ? "smk" . $usr['pin'] : "smk12345";
                }

                if (strlen($pass_baru) < 6) {
                    $pesan_error = "Password minimal 6 karakter.";
                } else {
                    $hash = password_hash($pass_baru, PASSWORD_BCRYPT);
                    $stmt_upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt_upd->bind_param("si", $hash, $id_target);
                    if ($stmt_upd->execute()) {
                        $pesan_sukses = "🔑 Password user <b>" . h($usr['username']) . "</b> berhasil di-reset menjadi: <code>" . h($pass_baru) . "</code>";
                        log_audit("RESET_PASSWORD_USER", "Reset password user {$usr['username']} (ID: {$id_target})");
                    } else {
                        $pesan_error = "Gagal reset password: " . $conn->error;
                    }
                }
            }
        }
    }

    // ---- 3. TAMBAH USER BARU MANUAL ----
    elseif ($action === 'tambah') {
        $username_baru = trim($_POST['username_baru'] ?? '');
        $password_baru = $_POST['password_baru'] ?? '';
        $role_baru     = $_POST['role_baru'] ?? 'admin';
        $kode_khusus   = trim($_POST['kode_verifikasi_khusus'] ?? '');

        if ($kode_khusus !== MASTER_SECURITY_CODE) {
            $pesan_error = "⛔ <b>Akses Ditolak:</b> Kode Verifikasi Khusus Salah! Pembuatan user baru dibatalkan.";
        } elseif (empty($username_baru) || empty($password_baru)) {
            $pesan_error = 'Username dan password tidak boleh kosong.';
        } elseif (!in_array($role_baru, ['superadmin', 'admin', 'rnd', 'tatausaha', 'staff', 'user'])) {
            $pesan_error = 'Role tidak valid.';
        } elseif (strlen($password_baru) < 6) {
            $pesan_error = 'Password minimal 6 karakter.';
        } else {
            $cek = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $cek->bind_param("s", $username_baru);
            $cek->execute();
            $cek->store_result();

            if ($cek->num_rows > 0) {
                $pesan_error = "Username <b>" . h($username_baru) . "</b> sudah digunakan, pilih username lain.";
            } else {
                $pin_baru = in_array($role_baru, ['user', 'tatausaha', 'staff']) ? trim($_POST['pin_baru'] ?? '') : null;
                if (empty($pin_baru)) $pin_baru = null;

                $hash = password_hash($password_baru, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, role, pin) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username_baru, $hash, $role_baru, $pin_baru);
                if ($stmt->execute()) {
                    $pesan_sukses = "✅ User <b>" . h($username_baru) . "</b> berhasil ditambahkan sebagai <b>" . h($role_baru) . "</b>.";
                    log_audit("TAMBAH_USER", "Tambah user {$username_baru} (role: {$role_baru}, PIN: " . ($pin_baru ?: '-') . ")");
                } else {
                    $pesan_error = "Gagal menambahkan user: " . $conn->error;
                }
            }
        }
    }

    // ---- 4. UPDATE USER ----
    elseif ($action === 'update') {
        $id_target        = (int)($_POST['id_target'] ?? 0);
        $username_update  = trim($_POST['username_update'] ?? '');
        $password_update  = $_POST['password_update'] ?? '';
        $role_update      = $_POST['role_update'] ?? '';

        if ($id_target <= 0 || empty($username_update)) {
            $pesan_error = 'Data tidak valid untuk diperbarui.';
        } elseif (!in_array($role_update, ['superadmin', 'admin', 'rnd', 'tatausaha', 'staff', 'user'])) {
            $pesan_error = 'Role tidak valid.';
        } else {
            $cek = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $cek->bind_param("si", $username_update, $id_target);
            $cek->execute();
            $cek->store_result();

            if ($cek->num_rows > 0) {
                $pesan_error = "Username <b>" . h($username_update) . "</b> sudah digunakan oleh user lain.";
            } else {
                $pin_update = in_array($role_update, ['user', 'tatausaha', 'staff']) ? trim($_POST['pin_update'] ?? '') : null;
                if (empty($pin_update)) $pin_update = null;

                if (!empty($password_update)) {
                    if (strlen($password_update) < 6) {
                        $pesan_error = 'Password baru minimal 6 karakter.';
                    } else {
                        $hash = password_hash($password_update, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare("UPDATE users SET username=?, password=?, role=?, pin=? WHERE id=?");
                        $stmt->bind_param("ssssi", $username_update, $hash, $role_update, $pin_update, $id_target);
                        if ($stmt->execute()) {
                            if ($id_target == ($_SESSION['user_id'] ?? 0)) {
                                $_SESSION['username'] = $username_update;
                                $_SESSION['pin'] = $pin_update;
                            }
                            $pesan_sukses = "✅ User <b>" . h($username_update) . "</b> berhasil diperbarui.";
                            log_audit("UPDATE_USER", "Update user ID {$id_target} ({$username_update})");
                        } else {
                            $pesan_error = "Gagal memperbarui user: " . $conn->error;
                        }
                    }
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username=?, role=?, pin=? WHERE id=?");
                    $stmt->bind_param("sssi", $username_update, $role_update, $pin_update, $id_target);
                    if ($stmt->execute()) {
                        if ($id_target == ($_SESSION['user_id'] ?? 0)) {
                            $_SESSION['username'] = $username_update;
                            $_SESSION['pin'] = $pin_update;
                        }
                        $pesan_sukses = "✅ Username dan role user berhasil diperbarui. Password tidak diubah.";
                        log_audit("UPDATE_USER", "Update user ID {$id_target} ({$username_update})");
                    } else {
                        $pesan_error = "Gagal memperbarui user: " . $conn->error;
                    }
                }
            }
        }
    }

    // ---- 5. HAPUS USER ----
    elseif ($action === 'hapus') {
        $id_hapus = (int)($_POST['id_hapus'] ?? 0);

        if ($id_hapus <= 0) {
            $pesan_error = 'ID user tidak valid.';
        } elseif ($id_hapus == ($_SESSION['user_id'] ?? 0)) {
            $pesan_error = '⛔ Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif.';
        } else {
            $cek_sa = $conn->prepare("SELECT id FROM users WHERE role='superadmin' AND id != ?");
            $cek_sa->bind_param("i", $id_hapus);
            $cek_sa->execute();
            $cek_sa->store_result();

            $user_dihapus = $conn->prepare("SELECT username, role FROM users WHERE id=?");
            $user_dihapus->bind_param("i", $id_hapus);
            $user_dihapus->execute();
            $res_dihapus = $user_dihapus->get_result()->fetch_assoc();

            if ($res_dihapus && $res_dihapus['role'] === 'superadmin' && $cek_sa->num_rows < 1) {
                $pesan_error = '⛔ Tidak bisa menghapus superadmin terakhir. Minimal harus ada 1 superadmin aktif.';
            } else {
                $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
                $stmt->bind_param("i", $id_hapus);
                if ($stmt->execute()) {
                    $pesan_sukses = "✅ User <b>" . h($res_dihapus['username'] ?? '') . "</b> berhasil dihapus.";
                } else {
                    $pesan_error = "Gagal menghapus user: " . $conn->error;
                }
            }
        }
    }
}

// -------------------------------------------------------
// PAGINASI, SEARCH & FILTER ROLE USER
// -------------------------------------------------------
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = 10;
$offset      = ($page - 1) * $limit;
$search      = trim($_GET['q'] ?? '');
$filter_role = trim($_GET['role'] ?? 'semua');

$where = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(u.username LIKE ? OR u.pin LIKE ? OR mk.nama LIKE ?)";
    $st = "%{$search}%";
    $params[] = $st; $params[] = $st; $params[] = $st;
    $types .= 'sss';
}

if (in_array($filter_role, ['superadmin', 'admin', 'rnd', 'tatausaha', 'staff', 'user'])) {
    $where[] = "u.role = ?";
    $params[] = $filter_role;
    $types .= 's';
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Count Query
$count_sql = "SELECT COUNT(*) as total FROM users u LEFT JOIN master_karyawan mk ON u.pin = mk.pin {$where_sql}";
$stmt_c = $conn->prepare($count_sql);
if (!empty($types)) $stmt_c->bind_param($types, ...$params);
$stmt_c->execute();
$total_records = $stmt_c->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages   = max(1, ceil($total_records / $limit));

// Data Query
$data_sql = "SELECT u.id, u.username, u.role, u.pin, u.created_at, u.last_active, mk.nama as nama_karyawan, mk.departemen 
             FROM users u 
             LEFT JOIN master_karyawan mk ON u.pin = mk.pin 
             {$where_sql} 
             ORDER BY u.id ASC 
             LIMIT ? OFFSET ?";

$params_data = array_merge($params, [$limit, $offset]);
$types_data  = $types . 'ii';

$stmt_d = $conn->prepare($data_sql);
$stmt_d->bind_param($types_data, ...$params_data);
$stmt_d->execute();
$res_users = $stmt_d->get_result()->fetch_all(MYSQLI_ASSOC);

// Hitung berapa karyawan di master_karyawan yang belum punya akun
$sql_unreg = "SELECT COUNT(*) as unreg FROM master_karyawan mk LEFT JOIN users u ON (u.pin = mk.pin OR u.username = mk.pin) WHERE u.id IS NULL";
$res_unreg = $conn->query($sql_unreg);
$unregistered_count = $res_unreg ? ($res_unreg->fetch_assoc()['unreg'] ?? 0) : 0;

$online_count = 0;
$user_list = [];
foreach ($res_users as $row) {
    $last_act_ts = !empty($row['last_active']) ? strtotime($row['last_active']) : 0;
    $is_online = (!empty($row['last_active']) && (time() - $last_act_ts) <= 35);
    if ($is_online) $online_count++;
    $row['is_online'] = $is_online;
    $user_list[] = $row;
}

render_header("Manajemen User", "users");
?>

<style>
/* MODAL RESET PASSWORD STYLING */
.modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: none;
    align-items: center; justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: #fff; border-radius: 16px;
    width: 90%; max-width: 440px;
    padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    animation: modalPop 0.25s ease;
}
@keyframes modalPop {
    from { transform: scale(0.92); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

.action-btn-sm {
    padding: 5px 10px;
    font-size: 11.5px;
    font-weight: 700;
    border-radius: 8px;
    border: 1px solid transparent;
    cursor: pointer;
    white-space: nowrap;
}
</style>

<!-- NOTIFIKASI SUKSES / ERROR -->
<?php if (!empty($pesan_sukses)): ?>
<div style="background: linear-gradient(135deg, #dcfce7, #f0fdf4); color: #15803d; border: 1px solid #86efac; padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 18px;">✓</span>
    <span><?php echo $pesan_sukses; ?></span>
</div>
<?php endif; ?>

<?php if (!empty($pesan_error)): ?>
<div style="background: linear-gradient(135deg, #fee2e2, #fef2f2); color: #be123c; border: 1px solid #fca5a5; padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 18px;">✕</span>
    <span><?php echo $pesan_error; ?></span>
</div>
<?php endif; ?>

<!-- KARTU BULK GENERATE AKUN KARYAWAN -->
<?php if ($unregistered_count > 0): ?>
<div class="card" style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1px solid #bfdbfe; margin-bottom:20px; padding:18px 22px;">
    <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:14px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:42px; height:42px; border-radius:12px; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px;">
                ⚡
            </div>
            <div>
                <div style="font-size:14px; font-weight:800; color:#1e40af;">Bulk Generate Akun Guru &amp; Karyawan</div>
                <div style="font-size:12px; color:#3b82f6; margin-top:2px;">
                    Ditemukan <b><?php echo $unregistered_count; ?> karyawan</b> di master data yang belum memiliki akun user login.
                </div>
            </div>
        </div>
        <form method="POST" action="kelola_user.php" style="margin:0;" onsubmit="return confirm('Buat akun user otomatis untuk <?php echo $unregistered_count; ?> karyawan ini?')">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_create_users">
            <button type="submit" class="btn btn-primary" style="padding:10px 18px; font-weight:700; font-size:13px; border-radius:10px; background:#2563eb;">
                ⚡ Generate Akun Semua Karyawan (<?php echo $unregistered_count; ?>)
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 320px 1fr; gap: 20px; align-items: start;">

    <!-- FORM TAMBAH USER BARU MANUAL -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="border-bottom:1px solid #f1f5f9; padding-bottom:12px; margin-bottom:16px;">
            <div class="card-title" style="margin-bottom:0;">Tambah User Manual</div>
        </div>

        <form method="POST" action="kelola_user.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="tambah">

            <div style="margin-bottom:14px;">
                <label for="username_baru" style="font-size:11.5px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">Username</label>
                <input type="text" id="username_baru" name="username_baru" class="form-control" placeholder="Contoh: admin_pasundan" required style="width:100%; box-sizing:border-box;">
            </div>

            <div style="margin-bottom:14px;">
                <label for="password_baru" style="font-size:11.5px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">Password Initial</label>
                <input type="password" id="password_baru" name="password_baru" class="form-control" placeholder="Minimal 6 karakter" required style="width:100%; box-sizing:border-box;">
            </div>

            <div style="margin-bottom:14px;">
                <label for="role_baru" style="font-size:11.5px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">Role / Hak Akses</label>
                <select id="role_baru" name="role_baru" class="form-control" style="width:100%; box-sizing:border-box;" onchange="togglePinField('baru')">
                    <option value="admin">Operator Admin</option>
                    <option value="tatausaha">Tata Usaha</option>
                    <option value="staff">Staff</option>
                    <option value="user">User Karyawan (Self Service)</option>
                    <option value="rnd">RnD Researcher</option>
                    <option value="superadmin">Superadmin</option>
                </select>
            </div>

            <div style="margin-bottom:14px; display:none;" id="field_pin_baru">
                <label for="pin_baru" style="font-size:11.5px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">PIN Karyawan (Sambungkan Akun)</label>
                <input type="text" id="pin_baru" name="pin_baru" class="form-control" placeholder="Masukkan PIN Karyawan (cth: 88)" style="width:100%; box-sizing:border-box;">
                <div style="font-size:11px; color:#64748b; margin-top:4px;">* Sambungkan PIN karyawan agar diketahui pemegang role ini.</div>
            </div>

            <div style="margin-bottom:18px;">
                <label for="kode_verifikasi_khusus" style="font-size:11.5px; font-weight:700; color:#dc2626; text-transform:uppercase; display:block; margin-bottom:6px;">🔒 Kode Verifikasi Khusus</label>
                <input type="password" id="kode_verifikasi_khusus" name="kode_verifikasi_khusus" class="form-control" placeholder="Kode Khusus Superadmin" required style="width:100%; box-sizing:border-box; border-color:#fca5a5;">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; padding:10px; font-weight:700; font-size:13px; border-radius:10px;">
                Simpan User Baru
            </button>
        </form>
    </div>

    <!-- TABEL DAFTAR USER & AKSI RESET PASSWORD -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="flex-wrap:wrap; gap:12px; align-items:center;">
            <div class="card-title" style="margin-bottom:0;">
                Daftar User (Total: <?php echo $total_records; ?>)
            </div>

            <!-- SEARCH & FILTER -->
            <form method="GET" action="kelola_user.php" style="margin:0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="🔍 Cari username / PIN..." style="width:170px; margin-bottom:0; padding:7px 12px; font-size:12.5px; border:1.5px solid #cbd5e1; border-radius:8px;">
                <select name="role" style="width:130px; margin-bottom:0; padding:7px 10px; font-size:12.5px; border:1.5px solid #cbd5e1; border-radius:8px;" onchange="this.form.submit()">
                    <option value="semua" <?php echo $filter_role === 'semua' ? 'selected' : ''; ?>>Semua Role</option>
                    <option value="superadmin" <?php echo $filter_role === 'superadmin' ? 'selected' : ''; ?>>Superadmin</option>
                    <option value="admin" <?php echo $filter_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="tatausaha" <?php echo $filter_role === 'tatausaha' ? 'selected' : ''; ?>>Tata Usaha</option>
                    <option value="staff" <?php echo $filter_role === 'staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="rnd" <?php echo $filter_role === 'rnd' ? 'selected' : ''; ?>>RnD</option>
                    <option value="user" <?php echo $filter_role === 'user' ? 'selected' : ''; ?>>User</option>
                </select>
                <button type="submit" class="btn" style="background:#f1f5f9; color:#334155; padding:7px 12px; font-size:12.5px; border:1px solid #cbd5e1;">Cari</button>
                <a href="export_pdf_users.php?q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($filter_role); ?>&auto_print=1" target="_blank" class="btn" style="background:#dc2626; color:#fff; padding:7px 14px; font-size:12.5px; font-weight:700; border-radius:8px; display:inline-flex; align-items:center; gap:6px; text-decoration:none;" title="Cetak / Export PDF Dokumen Kredensial User">
                    📄 Export PDF Akun
                </a>
            </form>
        </div>

        <div class="table-responsive" style="overflow-x:auto;">
            <table style="min-width:740px; font-size:13px;">
                <thead>
                    <tr>
                        <th style="width:40px;">No</th>
                        <th style="text-align:left;">Username / PIN</th>
                        <th>Nama Karyawan</th>
                        <th>Role</th>
                        <th>Status Login</th>
                        <th>Terakhir Aktif</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($user_list)): 
                        $no_counter = $offset + 1;
                        foreach ($user_list as $u):
                        $role_badge = "background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;";
                        if ($u['role'] === 'superadmin')     $role_badge = "background:#fdf2f8; color:#be185d; border:1px solid #fbcfe8;";
                        elseif ($u['role'] === 'admin')     $role_badge = "background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;";
                        elseif ($u['role'] === 'tatausaha') $role_badge = "background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;";
                        elseif ($u['role'] === 'staff')     $role_badge = "background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;";
                        elseif ($u['role'] === 'rnd')       $role_badge = "background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe;";
                        elseif ($u['role'] === 'user')      $role_badge = "background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0;";

                        $st_online = $u['is_online'] 
                            ? "<span class='badge' style='background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700;'>🟢 Online</span>"
                            : "<span class='badge' style='background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;'>⚪ Offline</span>";
                    ?>
                        <tr>
                            <td><b><?php echo $no_counter++; ?></b></td>
                            <td style="text-align:left; font-weight:700; color:#0f172a;">
                                <?php echo h($u['username']); ?>
                                <?php if (!empty($u['pin'])): ?>
                                    <code style="font-size:11px; background:#f1f5f9; padding:2px 6px; border-radius:4px; margin-left:4px;">PIN: <?php echo h($u['pin']); ?></code>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php if (!empty($u['nama_karyawan'])): ?>
                                    <div style="font-weight:700; color:#0f172a;"><?php echo h($u['nama_karyawan']); ?></div>
                                    <div style="font-size:11px; color:#64748b;"><?php echo h($u['departemen'] ?: '-'); ?></div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="<?php echo $role_badge; ?> font-weight:700;"><?php echo strtoupper($u['role']); ?></span></td>
                            <td><?php echo $st_online; ?></td>
                            <td style="font-size:12px; color:#64748b;"><?php echo format_last_active($u['last_active']); ?></td>
                            <td>
                                <div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">
                                    <!-- TOMBOL RESET PASSWORD -->
                                    <button type="button" class="action-btn-sm" style="background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe;" onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo h($u['username']); ?>', '<?php echo h($u['pin']); ?>')" title="Reset Password User">
                                        🔑 Reset Pass
                                    </button>

                                    <!-- TOMBOL HAPUS -->
                                    <?php if ($u['id'] != ($_SESSION['user_id'] ?? 0)): ?>
                                    <form method="POST" action="kelola_user.php" style="margin:0;" onsubmit="return confirm('Hapus user <?php echo h($u['username']); ?>?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id_hapus" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="action-btn-sm" style="background:#fee2e2; color:#be123c; border-color:#fca5a5;">Hapus</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="7" style="padding:40px; color:#94a3b8; text-align:center;">User tidak ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION NAV MODERN -->
        <?php if ($total_pages > 1): 
            $start_num = $offset + 1;
            $end_num   = min($offset + $limit, $total_records);
        ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Menampilkan <b><?php echo $start_num; ?> - <?php echo $end_num; ?></b> dari <b><?php echo $total_records; ?></b> user
                </div>
                <?php echo render_smart_pagination($page, $total_pages, ['q' => $search, 'role' => $filter_role]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL RESET PASSWORD SUPERADMIN -->
<div class="modal-overlay" id="resetModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;" id="resetModalTitle">🔑 Reset Password User</h3>
            <button type="button" onclick="closeResetModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <form method="POST" action="kelola_user.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reset_password_user">
            <input type="hidden" name="id_target" id="reset_id_target">

            <div style="margin-bottom:14px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:12.5px; color:#334155;">
                User Target: <b id="reset_username_txt" style="color:#0f172a;">-</b>
            </div>

            <div style="margin-bottom:16px;">
                <label for="pass_baru" style="font-size:11.5px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">Password Baru</label>
                <input type="text" id="reset_pass_baru" name="pass_baru" class="form-control" placeholder="Biarkan kosong untuk password default (smk[PIN])" style="width:100%; box-sizing:border-box;">
                <div style="font-size:11px; color:#64748b; margin-top:4px;">* Jika dikosongkan, password akan di-reset menjadi <code>smk[PIN]</code> (misal: <code>smk88</code>).</div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                <button type="button" class="btn" style="background:#f1f5f9; color:#475569;" onclick="closeResetModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 16px; font-weight:700;">Simpan Password Baru</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePinField(prefix) {
    const roleSelect = document.getElementById('role_' + prefix);
    const pinField   = document.getElementById('field_pin_' + prefix);
    if (roleSelect && pinField) {
        if (['user', 'tatausaha', 'staff'].includes(roleSelect.value)) {
            pinField.style.display = 'block';
        } else {
            pinField.style.display = 'none';
        }
    }
}

function openResetModal(id, username, pin) {
    document.getElementById('reset_id_target').value = id;
    document.getElementById('reset_username_txt').textContent = username + (pin ? ' (PIN: ' + pin + ')' : '');
    document.getElementById('reset_pass_baru').value = pin ? 'smk' + pin : 'smk12345';
    document.getElementById('resetModal').classList.add('active');
}

function closeResetModal() {
    document.getElementById('resetModal').classList.remove('active');
}
</script>

<?php render_footer(); ?>
