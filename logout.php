<?php
// ============================================================
// PROSES LOGOUT
// Menghancurkan session, mengeset status Offline & menyimpan waktu terakhir aktif
// ============================================================

require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $conn = getDB();
    // Audit Log Logout
    $username = $_SESSION['username'] ?? 'User';
    $role = $_SESSION['role'] ?? 'admin';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt_log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, details, ip_address) VALUES (?, ?, ?, 'LOGOUT', 'User keluar dari sistem', ?)");
    $stmt_log->bind_param("isss", $uid, $username, $role, $ip);
    $stmt_log->execute();

    // Set last_active menjadi 40 detik yang lalu agar status seketika menjadi Offline
    $stmt = $conn->prepare("UPDATE users SET last_active = DATE_SUB(NOW(), INTERVAL 40 SECOND) WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
}

$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
?>
