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
            $status          = isset($kolom[2]) ? trim($kolom[2]) : '';
            $tipe_verifikasi = isset($kolom[3]) ? trim($kolom[3]) : '';
            
            if ($pin != '' && $waktu != '') {
                // INSERT IGNORE: mencegah duplikasi berkat UNIQUE KEY (pin, waktu)
                $stmt = $conn->prepare("INSERT IGNORE INTO log_absen (pin, waktu, status, tipe_verifikasi) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $pin, $waktu, $status, $tipe_verifikasi);
                $stmt->execute();
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