<?php
// ============================================================
// PROSES LOGOUT
// Menghancurkan session, mengeset status Offline & menyimpan waktu terakhir aktif
// ============================================================

require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $conn = getDB();
    // Set last_active menjadi 40 detik yang lalu agar status seketika menjadi Offline, 
    // namun waktu terakhir aktifnya tetap tercatat secara akurat
    $stmt = $conn->prepare("UPDATE users SET last_active = DATE_SUB(NOW(), INTERVAL 40 SECOND) WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
}

$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
?>
