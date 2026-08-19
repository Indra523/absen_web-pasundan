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
function require_role($allowed_roles = ['superadmin', 'admin', 'rnd', 'tatausaha', 'staff', 'user']) {
    $user_role = $_SESSION['role'] ?? 'user';
    
    if (!in_array($user_role, $allowed_roles)) {
        header("Location: index.php?error=access_denied");
        exit;
    }
}

// Function helper untuk cek apakah user adalah superadmin
function is_superadmin() {
    return ($_SESSION['role'] ?? '') === 'superadmin';
}

// Function helper untuk cek apakah user adalah admin
function is_admin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

// Function helper untuk cek apakah user adalah RnD
function is_rnd() {
    return ($_SESSION['role'] ?? '') === 'rnd';
}

// Function helper untuk cek apakah user berhak mengakses fitur RnD (superadmin & rnd)
function can_access_rnd() {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, ['superadmin', 'rnd']);
}

// Function helper untuk cek apakah user adalah role Tatausaha
function is_tatausaha() {
    return ($_SESSION['role'] ?? '') === 'tatausaha';
}

// Function helper untuk cek apakah user adalah role Staff
function is_staff() {
    return ($_SESSION['role'] ?? '') === 'staff';
}

// Function helper untuk cek apakah user adalah role user (Karyawan/Guru)
function is_user_role() {
    return ($_SESSION['role'] ?? '') === 'user';
}

// Function helper untuk mengambil PIN terhubung dari session
function get_user_pin() {
    return $_SESSION['pin'] ?? null;
}



// ============================================================
// DYNAMIC ROLE PERMISSIONS (DB-based RBAC)
// ============================================================

// Ambil semua izin page_key untuk role saat ini dari DB (di-cache ke session dengan TTL 10s)
function get_role_permissions() {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'superadmin') return 'all';

    $cache_key = 'perm_cache_' . $role;
    $time_key  = 'perm_cache_time_' . $role;

    if (isset($_SESSION[$cache_key]) && is_array($_SESSION[$cache_key]) && isset($_SESSION[$time_key]) && (time() - $_SESSION[$time_key]) < 10) {
        return $_SESSION[$cache_key];
    }

    $conn = getDB();
    $perms = [];

    $stmt = $conn->prepare("SELECT page_key, is_allowed FROM role_permissions WHERE role = ?");
    if ($stmt) {
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $perms[$r['page_key']] = (bool)$r['is_allowed'];
        }
    }

    $_SESSION[$cache_key] = $perms;
    $_SESSION[$time_key]  = time();
    return $perms;
}

// Cek apakah role saat ini boleh mengakses page_key tertentu
function can_access_page($page_key) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'superadmin') return true;
    
    // Ganti Password adalah portal mandiri yang selalu diizinkan untuk semua role login
    if ($page_key === 'ganti_password') return true;

    $perms = get_role_permissions();
    if ($perms === 'all') return true;

    // Jika key tidak ada di table role_permissions untuk role ini, fallback default
    if (!isset($perms[$page_key])) {
        // Admin, RnD, Tatausaha default true untuk monitoring dasar jika belum diset
        if (in_array($role, ['admin', 'rnd', 'tatausaha']) && in_array($page_key, ['index', 'export_bulanan', 'riwayat', 'export_excel', 'notifikasi'])) {
            return true;
        }
        return false;
    }

    return !empty($perms[$page_key]);
}

// Invalidate permission cache (dipanggil setelah superadmin simpan perubahan)
function invalidate_perm_cache() {
    foreach (['admin', 'rnd', 'tatausaha', 'staff', 'user'] as $r) {
        unset($_SESSION['perm_cache_' . $r]);
        unset($_SESSION['perm_cache_time_' . $r]);
    }
}
?>
