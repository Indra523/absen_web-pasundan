<?php
// ============================================================
// FILE KONFIGURASI TERPUSAT
// PETUNJUK: Salin file ini menjadi config.php
//           lalu isi dengan kredensial yang sesuai.
//   cp config.example.php config.php
// ============================================================

// --- Pengaturan Session & Timezone WIB (Asia/Jakarta / UTC+7) ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

// --- Konfigurasi Database ---
define('DB_HOST', 'localhost');
define('DB_USER', 'nama_user_database');       // Ganti dengan user DB Anda
define('DB_PASS', 'password_database');        // Ganti dengan password DB Anda
define('DB_NAME', 'db_absen');

// --- Konfigurasi Mesin Absensi ---
define('MESIN_IP', '192.168.x.x');             // Ganti dengan IP mesin ZKTeco Anda
// Daftar Serial Number mesin yang diizinkan untuk PUSH data
// Kosongkan array [] untuk menerima semua mesin
define('ALLOWED_SN', [
    // 'XXXXXXXXXXXX', // Contoh SN mesin
]);

// --- Koneksi Database (Singleton) ---
function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die("Koneksi database gagal: " . $conn->connect_error);
        }
        $conn->set_charset("utf8mb4");
        $conn->query("SET time_zone = '+07:00'");
    }
    return $conn;
}

// --- Helper: Escape HTML untuk mencegah XSS ---
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// --- Helper: Generate & Validasi CSRF Token ---
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die("Error: CSRF token tidak valid. Silakan refresh halaman dan coba lagi.");
    }
}

// --- Helper: Validasi Serial Number Mesin ---
function validate_sn($sn) {
    if (empty(ALLOWED_SN)) {
        return true;
    }
    return in_array($sn, ALLOWED_SN);
}
?>
