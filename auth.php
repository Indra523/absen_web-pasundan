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
