<?php
// ============================================================
// ENDPOINT ADMS GETREQUEST (iClock Protocol)
// Menerima detak jantung (Heartbeat), info spesifikasi, dan mengirim antrean perintah ke mesin.
// ============================================================

require_once __DIR__ . '/../config.php';

$conn = getDB();
$sn   = isset($_GET['SN']) ? trim($_GET['SN']) : '';
$info = isset($_GET['INFO']) ? trim($_GET['INFO']) : '';

if (empty($sn)) {
    echo "OK";
    exit;
}

// 1. Catat detak jantung (Heartbeat) & spesifikasi mesin ke database
if ($conn) {
    // Buat tabel mesin_absensi jika belum ada
    $conn->query("CREATE TABLE IF NOT EXISTS mesin_absensi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sn VARCHAR(50) NOT NULL UNIQUE,
        nama_mesin VARCHAR(100) NOT NULL DEFAULT 'Mesin Absensi Solution',
        ip_mesin VARCHAR(50) NULL DEFAULT '172.16.0.136',
        port_mesin INT NOT NULL DEFAULT 4370,
        tipe_koneksi VARCHAR(20) NOT NULL DEFAULT 'ADMS_CLOUD',
        firmware_version VARCHAR(100) NULL,
        push_version VARCHAR(20) NULL,
        user_count INT DEFAULT 0,
        fp_count INT DEFAULT 0,
        face_count INT DEFAULT 0,
        log_count INT DEFAULT 0,
        status_online TINYINT(1) DEFAULT 1,
        last_seen DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $ip_client = $_SERVER['REMOTE_ADDR'] ?? '172.16.0.136';
    $fw_ver = '';
    $user_count = 0;
    $fp_count = 0;
    $log_count = 0;

    // Parse string INFO jika ada: cth: "Ver 8.0.4.2-20180713,98,98,2259,172.16.0.136,10,7,12,94,11100"
    if (!empty($info)) {
        $parts = explode(',', $info);
        $fw_ver     = isset($parts[0]) ? trim($parts[0]) : '';
        $user_count = isset($parts[1]) ? (int)$parts[1] : 0;
        $fp_count   = isset($parts[2]) ? (int)$parts[2] : 0;
        $log_count  = isset($parts[3]) ? (int)$parts[3] : 0;
        if (isset($parts[4]) && !empty(trim($parts[4]))) {
            $ip_client = trim($parts[4]);
        }
    }

    $stmt = $conn->prepare("INSERT INTO mesin_absensi (sn, nama_mesin, ip_mesin, firmware_version, user_count, fp_count, log_count, status_online, last_seen)
        VALUES (?, 'Mesin Solution Utama', ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            ip_mesin = VALUES(ip_mesin),
            firmware_version = IF(VALUES(firmware_version) != '', VALUES(firmware_version), firmware_version),
            user_count = IF(VALUES(user_count) > 0, VALUES(user_count), user_count),
            fp_count = IF(VALUES(fp_count) > 0, VALUES(fp_count), fp_count),
            log_count = IF(VALUES(log_count) > 0, VALUES(log_count), log_count),
            status_online = 1,
            last_seen = NOW()");
    
    if ($stmt) {
        $stmt->bind_param("sssiii", $sn, $ip_client, $fw_ver, $user_count, $fp_count, $log_count);
        $stmt->execute();
    }
}

// 2. Respon standar ADMS (Kirim "OK" jika tidak ada perintah tertunda)
echo "OK";
