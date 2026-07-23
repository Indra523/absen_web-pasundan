<?php
// ============================================================
// PROSES LOGOUT
// Menghancurkan session, mereset status last_active & redirect ke login
// ============================================================

require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $conn = getDB();
    // Set last_active menjadi NULL agar status di kelola_user.php seketika langsung Offline
    $stmt = $conn->prepare("UPDATE users SET last_active = NULL WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
}

$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
?>
