<?php
// ============================================================
// AUTH GUARD & ROLE-BASED ACCESS CONTROL (RBAC)
// ============================================================

require_once __DIR__ . '/config.php';

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Update timestamp last_active user di database (di-throttle 10 detik sekali per sesi agar real-time dan presisi)
if (isset($_SESSION['user_id'])) {
    if (!isset($_SESSION['last_activity_update']) || (time() - $_SESSION['last_activity_update']) > 10) {
        $uid_track = (int)$_SESSION['user_id'];
        $db_track = getDB();
        $stmt_track = $db_track->prepare("UPDATE users SET last_active = NOW() WHERE id = ?");
        $stmt_track->bind_param("i", $uid_track);
        $stmt_track->execute();
        $_SESSION['last_activity_update'] = time();
    }
}

// Function helper untuk membatasi akses halaman berdasarkan role
function require_role($allowed_roles = ['superadmin', 'admin']) {
    $user_role = $_SESSION['role'] ?? 'admin';
    
    if (!in_array($user_role, $allowed_roles)) {
        header("Location: index.php?error=access_denied");
        exit;
    }
}

// Function helper untuk cek apakah user adalah superadmin
function is_superadmin() {
    return ($_SESSION['role'] ?? '') === 'superadmin';
}
?>
