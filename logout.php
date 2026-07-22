<?php
// ============================================================
// PROSES LOGOUT
// Menghancurkan session dan redirect ke halaman login
// ============================================================

require_once __DIR__ . '/config.php';

$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
?>
