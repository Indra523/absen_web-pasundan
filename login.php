<?php
// ============================================================
// HALAMAN LOGIN
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/config.php';

// Jika sudah login, langsung ke dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $conn = getDB();
        $stmt = $conn->prepare("SELECT id, username, password, role, pin FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                // Fix Bug #5: Regenerasi session ID setelah login sukses (mencegah session fixation)
                session_regenerate_id(true);
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = !empty($user['role']) ? $user['role'] : 'user';
                $_SESSION['pin']      = $user['pin'] ?? null;

                // Audit Log Login (termasuk pencatatan User Agent / Browser)
                log_audit('LOGIN', 'Login berhasil ke sistem');

                header("Location: index.php");
                exit;
            } else {
                // Fix Bug #4: Pesan generik — tidak membedakan username vs password salah
                $error = "Username atau password yang Anda masukkan salah!";
            }
        } else {
            // Fix Bug #4: Pesan sama agar attacker tidak bisa enumerate username valid
            $error = "Username atau password yang Anda masukkan salah!";
        }
    } else {
        $error = "Username dan password wajib diisi!";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Monitoring Absensi SMK Pasundan 2 Bandung</title>
    <!-- Favicon & Icon Tab Browser -->
    <link rel="icon" type="image/jpeg" href="assets/logo_pasundan2.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            padding: 44px 38px;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            width: 100%;
            max-width: 420px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .school-badge {
            width: 72px;
            height: 72px;
            background: #ffffff;
            border-radius: 16px;
            margin: 0 auto 20px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 6px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        .school-badge img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .login-card h2 {
            text-align: center;
            color: #0f172a;
            font-size: 20px;
            font-weight: 800;
            line-height: 1.3;
            letter-spacing: -0.3px;
        }
        .login-card .school-name {
            text-align: center;
            color: #3b82f6;
            font-size: 14px;
            font-weight: 700;
            margin-top: 4px;
            margin-bottom: 24px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-weight: 600;
            font-size: 13px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            margin-bottom: 20px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s;
        }
        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }
        button {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);
            transition: transform 0.2s, opacity 0.2s;
        }
        button:hover { opacity: 0.95; transform: translateY(-1px); }
        .error {
            background: #ffe4e6;
            color: #be123c;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #fecdd3;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="school-badge">
            <img src="assets/logo_pasundan2.jpg" alt="Logo SMK Pasundan 2 Bandung">
        </div>
        <h2>Monitoring Absensi Guru & Karyawan</h2>
        <div class="school-name">SMK Pasundan 2 Bandung</div>

        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <label for="username">Username Admin</label>
            <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Masukkan password" required>

            <button type="submit">🔑 Masuk ke Sistem</button>
        </form>
    </div>

</body>
</html>
