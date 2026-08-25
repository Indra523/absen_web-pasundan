<?php
// ============================================================
// FILE KONFIGURASI TERPUSAT
// Semua file cukup: require_once 'config.php'; atau require_once '../config.php';
// ============================================================

// --- Pengaturan Session & Timezone WIB (Asia/Jakarta / UTC+7) ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

// --- Konfigurasi Database & Multi-Tenant SaaS ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'ujangkedu');
define('DB_NAME', 'db_absen');
define('MASTER_DB_NAME', 'db_master_system');
define('DEFAULT_TENANT_CODE', 'pasundan2');
define('DEFAULT_TENANT_DB', 'db_absen');

// --- Konfigurasi Mesin Absensi ---
define('MESIN_IP', '172.16.0.136');
// Daftar Serial Number mesin yang diizinkan untuk PUSH data
// Tambahkan SN mesin Anda di sini. Kosongkan array untuk menerima semua SN.
define('ALLOWED_SN', [
    // 'XXXXXXXXXXXX', // Contoh: SN mesin Anda
]);

// --- Kode Verifikasi Khusus Superadmin (Master Security Code) ---
define('MASTER_SECURITY_CODE', 'akuanakyatim2025');

// --- Koneksi Database Master System (Singleton) ---
function getMasterDB() {
    static $master_conn = null;
    if ($master_conn === null) {
        $master_conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, MASTER_DB_NAME);
        if ($master_conn->connect_error) {
            return null;
        }
        $master_conn->set_charset("utf8mb4");
        $master_conn->query("SET time_zone = '+07:00'");
    }
    return $master_conn;
}

// --- Tenant Resolver: Deteksi & Ambil Konfigurasi Sekolah Aktif ---
function get_active_tenant() {
    static $active_tenant = null;
    if ($active_tenant !== null) {
        return $active_tenant;
    }

    // Default Fallback (SMK Pasundan 2)
    $default = [
        'id'              => 1,
        'tenant_code'     => DEFAULT_TENANT_CODE,
        'nama_sekolah'    => 'SMK Pasundan 2 Bandung',
        'subdomain'       => DEFAULT_TENANT_CODE,
        'custom_domain'   => 'attendance-pas2.my.id',
        'db_host'         => DB_HOST,
        'db_name'         => DEFAULT_TENANT_DB,
        'db_user'         => DB_USER,
        'db_pass'         => DB_PASS,
        'status'          => 'aktif',
        'paket_langganan' => 'Enterprise Unlimited',
        'max_karyawan'    => 1000,
        'logo'            => null,
    ];

    $master_conn = getMasterDB();
    if (!$master_conn) {
        $active_tenant = $default;
        return $active_tenant;
    }

    // 1. Cek parameter URL eksplisit (?tenant=kode_sekolah)
    if (!empty($_GET['tenant'])) {
        $query_tenant = strtolower(trim($_GET['tenant']));
        $_SESSION['active_tenant_code'] = $query_tenant;
        $stmt = $master_conn->prepare("SELECT * FROM master_tenants WHERE tenant_code = ? OR subdomain = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ss", $query_tenant, $query_tenant);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $active_tenant = $res->fetch_assoc();
                return $active_tenant;
            }
        }
    }

    // 2. Cek Subdomain atau Custom Domain dari HTTP Host (cth: sman1bdg.attendance.my.id atau absen.smk.sch.id)
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = strtolower(explode(':', $_SERVER['HTTP_HOST'])[0]);
        
        // A. Cek kecocokan exact Custom Domain (cth: attendance-pas2.my.id, absen.smk.sch.id)
        $stmt_cd = $master_conn->prepare("SELECT * FROM master_tenants WHERE custom_domain = ? LIMIT 1");
        if ($stmt_cd) {
            $stmt_cd->bind_param("s", $host);
            $stmt_cd->execute();
            $res_cd = $stmt_cd->get_result();
            if ($res_cd && $res_cd->num_rows > 0) {
                $active_tenant = $res_cd->fetch_assoc();
                return $active_tenant;
            }
        }

        // B. Cek Subdomain (cth: sman1bdg.attendance.my.id -> subdomain = sman1bdg)
        $parts = explode('.', $host);
        if (count($parts) >= 3 && $parts[0] !== 'www' && $parts[0] !== 'master') {
            $sub = $parts[0];
            $stmt_sub = $master_conn->prepare("SELECT * FROM master_tenants WHERE subdomain = ? OR tenant_code = ? LIMIT 1");
            if ($stmt_sub) {
                $stmt_sub->bind_param("ss", $sub, $sub);
                $stmt_sub->execute();
                $res_sub = $stmt_sub->get_result();
                if ($res_sub && $res_sub->num_rows > 0) {
                    $active_tenant = $res_sub->fetch_assoc();
                    return $active_tenant;
                }
            }
        }
    }

    // 3. Cek Session aktif jika ada switch tenant manual
    if (!empty($_SESSION['active_tenant_code'])) {
        $sess_code = $_SESSION['active_tenant_code'];
        $stmt_s = $master_conn->prepare("SELECT * FROM master_tenants WHERE tenant_code = ? OR subdomain = ? LIMIT 1");
        if ($stmt_s) {
            $stmt_s->bind_param("ss", $sess_code, $sess_code);
            $stmt_s->execute();
            $res_s = $stmt_s->get_result();
            if ($res_s && $res_s->num_rows > 0) {
                $active_tenant = $res_s->fetch_assoc();
                return $active_tenant;
            }
        }
    }

    // Fallback Default ke SMK Pasundan 2
    $active_tenant = $default;
    return $active_tenant;
}

// --- Koneksi Database Sekolah Aktif (Dynamic Multi-Tenant Singleton) ---
function getDB() {
    static $connections = [];
    
    $tenant = get_active_tenant();
    $db_name = $tenant['db_name'] ?? DEFAULT_TENANT_DB;
    $db_host = $tenant['db_host'] ?? DB_HOST;
    $db_user = $tenant['db_user'] ?? DB_USER;
    $db_pass = $tenant['db_pass'] ?? DB_PASS;

    if (!isset($connections[$db_name])) {
        $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            if ($db_name !== DEFAULT_TENANT_DB) {
                $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DEFAULT_TENANT_DB);
            }
            if ($conn->connect_error) {
                die("<div style='font-family:sans-serif; padding:30px; text-align:center;'><h2>Database Sekolah Tidak Dapat Dihubungi</h2><p>" . htmlspecialchars($conn->connect_error) . "</p></div>");
            }
        }
        $conn->set_charset("utf8mb4");
        $conn->query("SET time_zone = '+07:00'");
        $connections[$db_name] = $conn;
    }

    return $connections[$db_name];
}

// Helper: Ambil nama sekolah aktif
function get_tenant_school_name() {
    $tenant = get_active_tenant();
    return $tenant['nama_sekolah'] ?? 'Sistem Presensi Sekolah';
}

// Helper: Cek apakah user adalah Master Platform Admin
function is_master_admin() {
    return (!empty($_SESSION['is_master_admin']) && $_SESSION['is_master_admin'] === true);
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
    // Jika daftar ALLOWED_SN kosong, izinkan semua mesin
    if (empty(ALLOWED_SN)) {
        return true;
    }
    return in_array($sn, ALLOWED_SN);
}

// --- Helper: Ambil App Settings dari DB ---
function get_app_settings() {
    static $settings = null;
    if ($settings === null) {
        $settings = [
            'jam_masuk'     => '06:30',
            'jam_toleransi' => '07:15',
            'jam_pulang'    => '17:00',
        ];
        $conn = getDB();
        $res = $conn->query("SELECT setting_key, setting_value FROM app_settings");
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
    return $settings;
}

// --- Helper: Audit Log Aktivitas System ---
function log_audit($action, $details = '') {
    $conn = getDB();
    $user_id  = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'System/Guest';
    $role     = !empty($_SESSION['role']) ? $_SESSION['role'] : 'guest';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 500);

    // Auto-migrate: pastikan kolom user_agent sudah ada di tabel audit_logs
    static $col_checked = false;
    if (!$col_checked && $conn) {
        $col_check = $conn->query("SHOW COLUMNS FROM audit_logs LIKE 'user_agent'");
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE audit_logs ADD COLUMN user_agent VARCHAR(500) NULL AFTER ip_address");
        }
        $col_checked = true;
    }

    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("issssss", $user_id, $username, $role, $action, $details, $ip, $ua);
        $stmt->execute();
    }
}

// --- Helper: Hitung Jarak GPS (Haversine Formula dalam Meter) ---
function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // Radius Bumi dalam meter
    $dLat = deg2rad((float)$lat2 - (float)$lat1);
    $dLon = deg2rad((float)$lon2 - (float)$lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earth_radius * $c, 2);
}

// --- Helper: Dapatkan IP Asli Klien (Mendukung Cloudflare, Proxy, & Direct) ---
function get_client_real_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// --- Helper: Cek IP Wi-Fi Lokal / Publik Sekolah ---
function is_school_wifi($client_ip = null) {
    if ($client_ip === null) {
        $client_ip = get_client_real_ip();
    }
    $settings = get_app_settings();
    $raw_subnets = $settings['allowed_wifi_subnets'] ?? '172.16., 192.168., 103.110., 114.10., 127.0.0.1, ::1';
    $subnets = array_map('trim', explode(',', $raw_subnets));

    foreach ($subnets as $subnet) {
        if (empty($subnet)) continue;
        if (strpos($client_ip, $subnet) === 0 || $client_ip === $subnet) {
            return true;
        }
    }
    return false;
}
?>
