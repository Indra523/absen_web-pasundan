<?php
// ============================================================
// HALAMAN GANTI PASSWORD AKUN (ALL ROLES)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
require_role();

$conn = getDB();
$user_id  = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'User';

$pesan_sukses = '';
$pesan_error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ganti_password_mandiri') {
    csrf_verify();

    $pass_lama  = $_POST['pass_lama'] ?? '';
    $pass_baru  = $_POST['pass_baru'] ?? '';
    $konfirmasi = $_POST['konfirmasi_pass'] ?? '';

    if (empty($pass_lama) || empty($pass_baru) || empty($konfirmasi)) {
        $pesan_error = "Semua bidang (Password Lama, Password Baru, Konfirmasi) wajib diisi!";
    } elseif ($pass_baru !== $konfirmasi) {
        $pesan_error = "Konfirmasi password baru tidak cocok!";
    } elseif (strlen($pass_baru) < 6) {
        $pesan_error = "Password baru minimal 6 karakter!";
    } else {
        // Ambil hash password saat ini
        $stmt_u = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt_u->bind_param("i", $user_id);
        $stmt_u->execute();
        $res_u = $stmt_u->get_result()->fetch_assoc();

        if (!$res_u || !password_verify($pass_lama, $res_u['password'])) {
            $pesan_error = "Password lama yang Anda masukkan salah!";
        } else {
            $new_hash = password_hash($pass_baru, PASSWORD_BCRYPT);
            $stmt_upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_upd->bind_param("si", $new_hash, $user_id);

            if ($stmt_upd->execute()) {
                $pesan_sukses = "🔑 Password Anda berhasil diperbarui! Gunakan password baru Anda untuk login berikutnya.";
                log_audit("GANTI_PASSWORD_MANDIRI", "User {$username} memperbarui password mandiri");
            } else {
                $pesan_error = "Gagal memperbarui password: " . $conn->error;
            }
        }
    }
}

render_header("Ganti Password Akun", "ganti_password");
?>

<style>
.pass-card {
    max-width: 500px;
    margin: 20px auto;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(15,23,42,0.06);
    overflow: hidden;
}
.pass-header {
    background: linear-gradient(135deg, #1e293b, #0f172a);
    color: #fff;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.pass-body {
    padding: 24px;
}
.form-input-custom {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 14px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #fff;
    margin-bottom: 16px;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-input-custom:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    outline: none;
}
.form-label-custom {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 6px;
    display: block;
}
</style>

<!-- NOTIFIKASI SUKSES / ERROR -->
<?php if (!empty($pesan_sukses)): ?>
    <div style="max-width:500px; margin:0 auto 16px; background:linear-gradient(135deg,#dcfce7,#f0fdf4); color:#15803d; border:1px solid #86efac; padding:14px 20px; border-radius:12px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:10px;">
        <span style="font-size:18px;">✓</span> <?php echo $pesan_sukses; ?>
    </div>
<?php endif; ?>

<?php if (!empty($pesan_error)): ?>
    <div style="max-width:500px; margin:0 auto 16px; background:linear-gradient(135deg,#fee2e2,#fef2f2); color:#be123c; border:1px solid #fca5a5; padding:14px 20px; border-radius:12px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:10px;">
        <span style="font-size:18px;">✕</span> <?php echo $pesan_error; ?>
    </div>
<?php endif; ?>

<div class="pass-card">
    <div class="pass-header">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(255,255,255,0.1); display:flex; align-items:center; justify-content:center; font-size:20px;">
            🔑
        </div>
        <div>
            <h3 style="margin:0; font-size:16px; font-weight:700;">Ganti Password Akun</h3>
            <p style="margin:2px 0 0; font-size:12px; color:#94a3b8;">Ubah password demi keamanan akun Anda</p>
        </div>
    </div>

    <div class="pass-body">
        <div style="margin-bottom:20px; padding:12px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; font-size:12.5px; color:#1e40af; display:flex; align-items:center; gap:10px;">
            <span style="font-size:18px;">💡</span>
            <div>
                Login sebagai <b><?php echo h($username); ?></b> (Role: <?php echo h(strtoupper($_SESSION['role'])); ?>).
            </div>
        </div>

        <form method="POST" action="ganti_password.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="ganti_password_mandiri">

            <div style="margin-bottom:16px;">
                <label for="pass_lama" class="form-label-custom">Password saat ini / Password lama</label>
                <input type="password" id="pass_lama" name="pass_lama" class="form-input-custom" placeholder="Masukkan password saat ini..." required>
            </div>

            <div style="margin-bottom:16px;">
                <label for="pass_baru" class="form-label-custom">Password Baru</label>
                <input type="password" id="pass_baru" name="pass_baru" class="form-input-custom" placeholder="Minimal 6 karakter..." required>
            </div>

            <div style="margin-bottom:24px;">
                <label for="konfirmasi_pass" class="form-label-custom">Konfirmasi Password Baru</label>
                <input type="password" id="konfirmasi_pass" name="konfirmasi_pass" class="form-input-custom" placeholder="Ketik ulang password baru..." required>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; font-size:14px; font-weight:700; border-radius:10px;">
                🔑 Simpan Password Baru
            </button>
        </form>
    </div>
</div>

<?php render_footer(); ?>
