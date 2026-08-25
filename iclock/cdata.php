<?php
// ============================================================
// ENDPOINT PUSH MESIN ABSENSI (Protokol iClock/ADMS)
// File ini menerima data dari mesin ZKTeco secara otomatis.
// 
// PENTING: File ini TIDAK menggunakan auth.php karena
// mesin absensi yang mengirim data, bukan user browser.
// Validasi dilakukan via Serial Number mesin.
// ============================================================

require_once __DIR__ . '/../config.php';

$conn = getDB();
$sn = isset($_GET['SN']) ? $_GET['SN'] : '';

// Validasi Serial Number mesin
if (!validate_sn($sn)) {
    http_response_code(403);
    echo "FORBIDDEN";
    exit;
}

// Catat aktivitas mesin ke tabel mesin_absensi
$pushver = $_GET['pushver'] ?? '2.4.0';
$ip_client = $_SERVER['REMOTE_ADDR'] ?? '172.16.0.136';
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
$stmt_ms = $conn->prepare("INSERT INTO mesin_absensi (sn, nama_mesin, ip_mesin, push_version, status_online, last_seen)
    VALUES (?, 'Mesin Solution Utama', ?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE ip_mesin = VALUES(ip_mesin), push_version = VALUES(push_version), status_online = 1, last_seen = NOW()");
if ($stmt_ms) {
    $stmt_ms->bind_param("sss", $sn, $ip_client, $pushver);
    $stmt_ms->execute();
}

// 1. HANDSHAKE (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "GET OPTION FROM: " . $sn . "\n";
    echo "Stamp=9999\n";
    echo "OpStamp=9999\n";
    echo "ErrorDelay=60\n";
    echo "Delay=10\n";
    echo "TransTimes=00:00;14:00\n";
    echo "TransInterval=1\n";
    echo "TransFlag=1111000000\n";
    
    // Perintah ADMS standar menyuruh mesin mengunggah seluruh data User & Fingerprint
    echo "C:1:DATA UPDATE USERINFO\n";
    echo "C:2:DATA UPDATE FINGERTEMPLATE\n";
    echo "GET USER DATA\n"; 
    echo "GET FINGERPRINT DATA\n";
    exit;
}

// 2. MENERIMA DATA DARI MESIN (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data_mentah = file_get_contents("php://input");
    $jenis_data = isset($_GET['table']) ? $_GET['table'] : 'Unknown';
    
    // A. JIKA YANG DIKIRIM ADALAH DATA ABSENSI
    if ($jenis_data === 'ATTLOG') {
        $baris_data = explode("\n", trim($data_mentah));
        foreach ($baris_data as $baris) {
            if (empty(trim($baris))) continue;
            $kolom = explode("\t", $baris);
            
            $pin             = isset($kolom[0]) ? trim($kolom[0]) : '';
            $waktu           = isset($kolom[1]) ? trim($kolom[1]) : '';
            $status          = isset($kolom[2]) ? trim($kolom[2]) : '0';
            $tipe_verifikasi = isset($kolom[3]) ? trim($kolom[3]) : '';
            
            if ($pin !== '' && $waktu !== '') {
                $tgl_log  = date('Y-m-d', strtotime($waktu));
                $waktu_ts = strtotime($waktu);

                // CEK LOG HARI INI UNTUK KARYAWAN/GURU INI
                $stmt_hist = $conn->prepare("SELECT id, status, waktu FROM log_absen WHERE pin = ? AND DATE(waktu) = ? ORDER BY waktu ASC");
                $stmt_hist->bind_param("ss", $pin, $tgl_log);
                $stmt_hist->execute();
                $res_hist = $stmt_hist->get_result();

                $waktu_masuk_ts  = null;
                $waktu_pulang_ts = null;
                $id_pulang_last  = null;

                while ($h = $res_hist->fetch_assoc()) {
                    $ts = strtotime($h['waktu']);
                    if ($h['status'] === '0') {
                        if ($waktu_masuk_ts === null || $ts < $waktu_masuk_ts) {
                            $waktu_masuk_ts = $ts;
                        }
                    } elseif ($h['status'] === '1') {
                        if ($waktu_pulang_ts === null || $ts > $waktu_pulang_ts) {
                            $waktu_pulang_ts = $ts;
                            $id_pulang_last = (int)$h['id'];
                        }
                    }
                }

                // --- ATURAN OTOMATISASI ABSENSI BARU ---

                // 1. ATURAN 1: Tap Pertama Hari Ini = MASUK (Mau Jam Berapa Pun)
                if ($waktu_masuk_ts === null) {
                    $stmt_ins = $conn->prepare("INSERT IGNORE INTO log_absen (pin, waktu, status, tipe_verifikasi) VALUES (?, ?, '0', ?)");
                    $stmt_ins->bind_param("sss", $pin, $waktu, $tipe_verifikasi);
                    $stmt_ins->execute();
                }
                // 2. ATURAN 2 & 3: Tap Kedua & Seterusnya (Setelah Ada Log Masuk)
                else {
                    $diff_masuk = $waktu_ts - $waktu_masuk_ts;

                    // A. Jika tap terjadi dalam rentang 30 MENIT setelah Masuk (< 1800 detik):
                    //    Abaikan/skip sebagai double tap agar tidak tidak sengaja masuk sebagai jam Pulang.
                    if ($diff_masuk >= 0 && $diff_masuk < 1800) {
                        // SKIPPED (Protected by 30-minute cooldown after Masuk)
                        continue;
                    }

                    // B. Jika tap terjadi setelah > 30 MENIT dari Masuk (>= 1800 detik):
                    if ($diff_masuk >= 1800) {
                        // B1. Belum ada log Pulang hari ini -> Simpan sebagai PULANG (status 1)
                        if ($waktu_pulang_ts === null) {
                            $stmt_ins = $conn->prepare("INSERT IGNORE INTO log_absen (pin, waktu, status, tipe_verifikasi) VALUES (?, ?, '1', ?)");
                            $stmt_ins->bind_param("sss", $pin, $waktu, $tipe_verifikasi);
                            $stmt_ins->execute();
                        }
                        // B2. Sudah ada log Pulang hari ini
                        else {
                            $diff_pulang = $waktu_ts - $waktu_pulang_ts;

                            // Jika tap terjadi dalam rentang 30 MENIT setelah Pulang terakhir -> Skip (Double tap)
                            if ($diff_pulang >= 0 && $diff_pulang < 1800) {
                                // SKIPPED (Double tap Pulang)
                                continue;
                            }
                            // Jika tap terjadi > 30 MENIT setelah Pulang terakhir -> Perbarui jam Pulang ke jam terkini
                            elseif ($diff_pulang >= 1800 && $id_pulang_last !== null) {
                                $stmt_upd = $conn->prepare("UPDATE log_absen SET waktu = ?, tipe_verifikasi = ? WHERE id = ?");
                                $stmt_upd->bind_param("ssi", $waktu, $tipe_verifikasi, $id_pulang_last);
                                $stmt_upd->execute();
                            }
                        }
                    }
                }
            }
        }
    }
    
    // B. JIKA YANG DIKIRIM ADALAH DATA KARYAWAN/USER DARI MESIN
    elseif ($jenis_data === 'USERINFO') {
        $baris_data = explode("\n", trim($data_mentah));
        foreach ($baris_data as $baris) {
            if (empty(trim($baris))) continue;
            
            // Format data user dari mesin dipisahkan oleh Tab (\t)
            // Kita parse menjadi key-value pairs
            parse_str(str_replace("\t", "&", $baris), $data_user);
            
            $pin  = isset($data_user['PIN']) ? trim($data_user['PIN']) : '';
            $nama = isset($data_user['Name']) ? trim($data_user['Name']) : '';
            
            // Jika mesin mengirim data PIN dan Nama, simpan/perbarui ke tabel master_karyawan
            if ($pin != '' && $nama != '') {
                $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama) VALUES (?, ?) ON DUPLICATE KEY UPDATE nama = ?");
                $stmt->bind_param("sss", $pin, $nama, $nama);
                $stmt->execute();
            }
        }
    }
    
    echo "OK";
    exit;
}
?>