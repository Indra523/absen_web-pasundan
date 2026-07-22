<?php
// ============================================================
// HALAMAN MANAJEMEN USER — Superadmin Only
// Fitur: Tambah User, Edit Username/Password, Hapus User
// ============================================================

require_once __DIR__ . '/layout.php';
require_role(['superadmin']);

$conn = getDB();
$pesan_sukses = '';
$pesan_error  = '';

// -------------------------------------------------------
// PROSES POST ACTION
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---- TAMBAH USER BARU ----
    if ($action === 'tambah') {
        $username_baru = trim($_POST['username_baru'] ?? '');
        $password_baru = $_POST['password_baru'] ?? '';
        $role_baru     = $_POST['role_baru'] ?? 'admin';

        if (empty($username_baru) || empty($password_baru)) {
            $pesan_error = 'Username dan password tidak boleh kosong.';
        } elseif (!in_array($role_baru, ['superadmin', 'admin'])) {
            $pesan_error = 'Role tidak valid.';
        } elseif (strlen($password_baru) < 6) {
            $pesan_error = 'Password minimal 6 karakter.';
        } else {
            // Cek duplikat username
            $cek = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $cek->bind_param("s", $username_baru);
            $cek->execute();
            $cek->store_result();

            if ($cek->num_rows > 0) {
                $pesan_error = "Username <b>" . h($username_baru) . "</b> sudah digunakan, pilih username lain.";
            } else {
                $hash = password_hash($password_baru, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $username_baru, $hash, $role_baru);
                if ($stmt->execute()) {
                    $pesan_sukses = "✅ User <b>" . h($username_baru) . "</b> berhasil ditambahkan sebagai <b>" . h($role_baru) . "</b>.";
                } else {
                    $pesan_error = "Gagal menambahkan user: " . $conn->error;
                }
            }
        }
    }

    // ---- UPDATE USER (USERNAME / PASSWORD / ROLE) ----
    elseif ($action === 'update') {
        $id_target        = (int)($_POST['id_target'] ?? 0);
        $username_update  = trim($_POST['username_update'] ?? '');
        $password_update  = $_POST['password_update'] ?? '';
        $role_update      = $_POST['role_update'] ?? '';

        if ($id_target <= 0 || empty($username_update)) {
            $pesan_error = 'Data tidak valid untuk diperbarui.';
        } elseif (!in_array($role_update, ['superadmin', 'admin'])) {
            $pesan_error = 'Role tidak valid.';
        } else {
            // Cek duplikat username (kecuali diri sendiri)
            $cek = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $cek->bind_param("si", $username_update, $id_target);
            $cek->execute();
            $cek->store_result();

            if ($cek->num_rows > 0) {
                $pesan_error = "Username <b>" . h($username_update) . "</b> sudah digunakan oleh user lain.";
            } else {
                if (!empty($password_update)) {
                    // Update username + password + role
                    if (strlen($password_update) < 6) {
                        $pesan_error = 'Password baru minimal 6 karakter.';
                    } else {
                        $hash = password_hash($password_update, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare("UPDATE users SET username=?, password=?, role=? WHERE id=?");
                        $stmt->bind_param("sssi", $username_update, $hash, $role_update, $id_target);
                        if ($stmt->execute()) {
                            // Update session jika user update dirinya sendiri
                            if ($id_target == ($_SESSION['user_id'] ?? 0)) {
                                $_SESSION['username'] = $username_update;
                            }
                            $pesan_sukses = "✅ User <b>" . h($username_update) . "</b> berhasil diperbarui (username + password + role).";
                        } else {
                            $pesan_error = "Gagal memperbarui user: " . $conn->error;
                        }
                    }
                } else {
                    // Update username + role saja (tanpa ubah password)
                    $stmt = $conn->prepare("UPDATE users SET username=?, role=? WHERE id=?");
                    $stmt->bind_param("ssi", $username_update, $role_update, $id_target);
                    if ($stmt->execute()) {
                        if ($id_target == ($_SESSION['user_id'] ?? 0)) {
                            $_SESSION['username'] = $username_update;
                        }
                        $pesan_sukses = "✅ Username dan role user berhasil diperbarui. Password tidak diubah.";
                    } else {
                        $pesan_error = "Gagal memperbarui user: " . $conn->error;
                    }
                }
            }
        }
    }

    // ---- HAPUS USER ----
    elseif ($action === 'hapus') {
        $id_hapus = (int)($_POST['id_hapus'] ?? 0);

        // Tidak boleh hapus diri sendiri
        if ($id_hapus <= 0) {
            $pesan_error = 'ID user tidak valid.';
        } elseif ($id_hapus == ($_SESSION['user_id'] ?? 0)) {
            $pesan_error = '⛔ Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif.';
        } else {
            // Cek minimal 1 superadmin harus tersisa
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
// AMBIL SEMUA DATA USER
// -------------------------------------------------------
$semua_user = $conn->query("SELECT id, username, role, created_at FROM users ORDER BY id ASC");

render_header("Manajemen User", "users");
?>

<!-- NOTIFIKASI -->
<?php if ($pesan_sukses): ?>
<div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; color: #065f46; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 20px;">✅</span>
    <span><?php echo $pesan_sukses; ?></span>
</div>
<?php endif; ?>

<?php if ($pesan_error): ?>
<div style="background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #f87171; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; color: #991b1b; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 20px;">⛔</span>
    <span><?php echo $pesan_error; ?></span>
</div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 360px 1fr; gap: 24px; align-items: start;">

    <!-- PANEL KIRI: FORM TAMBAH USER -->
    <div>
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header" style="margin-bottom: 20px;">
                <div class="card-title">➕ Tambah User Baru</div>
            </div>

            <form method="POST" action="kelola_user.php" id="form-tambah">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="tambah">

                <label for="username_baru">Username</label>
                <input type="text" id="username_baru" name="username_baru" placeholder="Contoh: operator1" autocomplete="off" required>

                <label for="password_baru">Password</label>
                <div style="position: relative; margin-bottom: 18px;">
                    <input type="password" id="password_baru" name="password_baru" placeholder="Minimal 6 karakter" style="margin-bottom: 0; padding-right: 44px;" autocomplete="new-password">
                    <button type="button" onclick="togglePass('password_baru', 'eye-tambah')" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:18px; color:#64748b;" id="eye-tambah">👁️</button>
                </div>

                <label for="role_baru">Role / Hak Akses</label>
                <select id="role_baru" name="role_baru" style="margin-bottom: 22px;">
                    <option value="admin">👤 Admin — Hanya Live Monitoring</option>
                    <option value="superadmin">👑 Superadmin — Akses Penuh</option>
                </select>

                <button type="submit" class="btn btn-primary btn-block">
                    ➕ Tambah User
                </button>
            </form>
        </div>
    </div>

    <!-- PANEL KANAN: DAFTAR USER -->
    <div>
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div class="card-title">👥 Daftar User Aktif (<?php echo $semua_user->num_rows; ?> User)</div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th style="text-align:left;">Username</th>
                            <th>Role</th>
                            <th>Dibuat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no = 1;
                        $semua_user->data_seek(0);
                        while ($u = $semua_user->fetch_assoc()):
                            $is_self = ($u['id'] == ($_SESSION['user_id'] ?? 0));
                            $role_badge_color = $u['role'] === 'superadmin'
                                ? 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;'
                                : 'background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;';
                        ?>
                        <tr>
                            <td><b><?php echo $no++; ?></b></td>
                            <td style="text-align:left;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span style="font-size:16px;"><?php echo $u['role'] === 'superadmin' ? '👑' : '👤'; ?></span>
                                    <div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo h($u['username']); ?></div>
                                        <?php if ($is_self): ?>
                                        <div style="font-size:11px; color:#10b981; font-weight:600;">● Akun Anda</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="<?php echo $role_badge_color; ?>">
                                    <?php echo $u['role'] === 'superadmin' ? '👑 Superadmin' : '👤 Admin'; ?>
                                </span>
                            </td>
                            <td style="color:#64748b; font-size:13px;">
                                <?php echo $u['created_at'] ? date('d/m/Y', strtotime($u['created_at'])) : '-'; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:8px; justify-content:center;">
                                    <button
                                        class="btn"
                                        style="background:#f1f5f9; color:#334155; font-size:12px; padding:7px 14px; border:1px solid #e2e8f0;"
                                        onclick="bukaModalEdit(<?php echo $u['id']; ?>, '<?php echo h($u['username']); ?>', '<?php echo $u['role']; ?>')"
                                    >✏️ Edit</button>

                                    <?php if (!$is_self): ?>
                                    <form method="POST" action="kelola_user.php" style="margin:0;" onsubmit="return confirm('Yakin ingin menghapus user \'<?php echo h($u['username']); ?>\'?\n\nAksi ini tidak bisa dibatalkan!');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id_hapus" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn" style="background:#fee2e2; color:#dc2626; font-size:12px; padding:7px 14px; border:1px solid #fca5a5;">🗑️ Hapus</button>
                                    </form>
                                    <?php else: ?>
                                    <span style="font-size:12px; color:#94a3b8; padding:7px 0;">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- INFO ROLE -->
        <div class="card" style="margin-bottom:0; background: linear-gradient(135deg, #f8fafc, #f1f5f9);">
            <div class="card-title" style="font-size:14px; margin-bottom:14px;">ℹ️ Keterangan Role</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div style="background:#fff; border-radius:10px; padding:14px 16px; border:1px solid #e2e8f0;">
                    <div style="font-weight:700; color:#0f172a; font-size:13px; margin-bottom:6px;">👑 Superadmin</div>
                    <ul style="font-size:13px; color:#64748b; padding-left:16px; line-height:1.8;">
                        <li>Live Monitoring Absensi</li>
                        <li>Kelola Data Guru & Karyawan</li>
                        <li>Sinkronisasi Mesin</li>
                        <li>Manajemen User</li>
                        <li>Export & Import Data</li>
                    </ul>
                </div>
                <div style="background:#fff; border-radius:10px; padding:14px 16px; border:1px solid #e2e8f0;">
                    <div style="font-weight:700; color:#0f172a; font-size:13px; margin-bottom:6px;">👤 Admin</div>
                    <ul style="font-size:13px; color:#64748b; padding-left:16px; line-height:1.8;">
                        <li>Live Monitoring Absensi</li>
                        <li>Filter Tanggal & Pencarian</li>
                        <li>Export ke Excel</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========== MODAL EDIT USER =========== -->
<div id="modal-edit" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:20px; padding:32px; width:100%; max-width:440px; box-shadow:0 25px 60px rgba(0,0,0,0.25); animation:slideUp 0.25s ease;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
            <h3 style="font-size:18px; font-weight:800; color:#0f172a;">✏️ Edit User</h3>
            <button onclick="tutupModalEdit()" style="background:#f1f5f9; border:none; border-radius:8px; padding:8px 12px; cursor:pointer; font-size:16px; color:#64748b;">✕</button>
        </div>

        <form method="POST" action="kelola_user.php" id="form-edit">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id_target" id="edit_id">

            <label for="username_update">Username</label>
            <input type="text" id="username_update" name="username_update" placeholder="Username baru" autocomplete="off" required>

            <label for="password_update">Password Baru</label>
            <div style="position: relative; margin-bottom: 18px;">
                <input type="password" id="password_update" name="password_update" placeholder="Kosongkan jika tidak ingin diubah" style="margin-bottom:0; padding-right: 44px;" autocomplete="new-password">
                <button type="button" onclick="togglePass('password_update', 'eye-edit')" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:18px; color:#64748b;" id="eye-edit">👁️</button>
            </div>
            <div style="font-size:12px; color:#94a3b8; margin-top:-14px; margin-bottom:18px;">💡 Biarkan kosong jika tidak ingin mengubah password.</div>

            <label for="role_update">Role / Hak Akses</label>
            <select id="role_update" name="role_update" style="margin-bottom:24px;">
                <option value="admin">👤 Admin — Hanya Live Monitoring</option>
                <option value="superadmin">👑 Superadmin — Akses Penuh</option>
            </select>

            <div style="display:flex; gap:12px;">
                <button type="button" onclick="tutupModalEdit()" class="btn" style="flex:1; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0;">Batal</button>
                <button type="submit" class="btn btn-primary" style="flex:2;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<script>
function bukaModalEdit(id, username, role) {
    document.getElementById('edit_id').value = id;
    document.getElementById('username_update').value = username;
    document.getElementById('password_update').value = '';
    document.getElementById('role_update').value = role;
    const modal = document.getElementById('modal-edit');
    modal.style.display = 'flex';
    setTimeout(() => document.getElementById('username_update').focus(), 100);
}

function tutupModalEdit() {
    document.getElementById('modal-edit').style.display = 'none';
}

// Tutup modal saat klik di luar kotak
document.getElementById('modal-edit').addEventListener('click', function(e) {
    if (e.target === this) tutupModalEdit();
});

// Toggle show/hide password
function togglePass(inputId, btnId) {
    const input = document.getElementById(inputId);
    const btn   = document.getElementById(btnId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
    } else {
        input.type = 'password';
        btn.textContent = '👁️';
    }
}
</script>

<?php render_footer(); ?>
