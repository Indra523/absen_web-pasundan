<?php
// ============================================================
// SINKRONISASI NAMA KARYAWAN DARI MESIN ABSENSI
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/vendor/autoload.php';

// Proteksi Halaman: Hanya Superadmin yang boleh melakukan sinkronisasi mesin
require_role(['superadmin']);

use Mithun\PhpZkteco\Libs\ZKTeco;

$conn = getDB();
$pesan = "";
$detail = [];
$comm_key = $_POST['comm_key'] ?? '0';

// Proses tarik data jika tombol ditekan
if (isset($_POST['tarik'])) {
    csrf_verify();

    $comm_key_int = intval($comm_key);

    // Koneksi ke Mesin Absensi via Socket UDP (Port 4370)
    $zk = new ZKTeco(MESIN_IP, 4370, false, 25, $comm_key_int);
    $terhubung = $zk->connect();

    if ($terhubung) {
        $daftar_user = $zk->getUsers();
        
        if (empty($daftar_user)) {
            $serial = $zk->serialNumber();
            $device = $zk->deviceName();
            
            $pesan = "<div style='background:#fff3cd; padding:18px; border-radius:12px; border-left:5px solid #f59e0b; margin-bottom:20px;'>";
            $pesan .= "<p style='color:#b45309; margin:0 0 10px; font-size:15px;'><b>Data user tidak terbaca dari Socket Direct.</b></p>";
            $pesan .= "<p style='color:#b45309; margin:0 0 8px;'>Mesin Solution X606-S saat ini berada dalam mode <b>ADMS / PUSH Protocol</b>.</p>";
            $pesan .= "<p style='color:#b45309; margin:0; font-size:13px; line-height:1.6;'>";
            $pesan .= "<b>Cara Otomatis Sinkronisasi Nama Guru & Karyawan:</b><br>";
            $pesan .= "1. Mesin secara otomatis mem-PUSH data user (USERINFO) setiap kali terhubung / melakukan handshake ke server.<br>";
            $pesan .= "2. Perintah handshake PUSH (<code>C:1:DATA UPDATE USERINFO</code>) telah aktif di <code>iclock/cdata.php</code>.<br>";
            $pesan .= "3. Jika mesin mempunyai <b>Comm Key / Password Komunikasi</b> di menu mesin (misal: 123456), masukkan di kolom Comm Key dan coba lagi.";
            $pesan .= "</p></div>";

        } else {
            $sukses = 0;
            $dilewati = 0;
            foreach ($daftar_user as $user) {
                $pin  = isset($user['user_id']) ? trim(strval($user['user_id'])) : '';
                $nama = isset($user['name']) ? trim($user['name']) : '';
                
                if ($pin === '' && isset($user['uid'])) {
                    $pin = trim(strval($user['uid']));
                }
                
                if ($nama === '' || $nama === $pin) {
                    $nama = 'User ' . $pin;
                }
                
                if ($pin !== '') {
                    $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama) VALUES (?, ?) ON DUPLICATE KEY UPDATE nama = ?");
                    $stmt->bind_param("sss", $pin, $nama, $nama);
                    $stmt->execute();
                    $sukses++;
                    $detail[] = ['pin' => $pin, 'nama' => $nama];
                } else {
                    $dilewati++;
                }
            }
            
            $total = count($daftar_user);
            $pesan = "<div style='background:#d4edda; color:#155724; padding:14px 18px; border-radius:10px; border:1px solid #c3e6cb; margin-bottom:20px;'>";
            $pesan .= "<b>Berhasil!</b> Sebanyak <b>{$sukses}</b> dari <b>{$total}</b> data karyawan berhasil disinkronisasi ke database.";
            if ($dilewati > 0) {
                $pesan .= " (<b>{$dilewati}</b> data dilewati karena PIN kosong.)";
            }
            $pesan .= "</div>";
        }
        
        $zk->disconnect();

    } else {
        $pesan = "<p style='color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;'><b>Gagal terhubung ke mesin absensi!</b> Pastikan IP (<code>" . h(MESIN_IP) . "</code>) benar dan server berada di satu jaringan dengan mesin.</p>";
    }
}

render_header("Sinkronisasi Mesin", "sinkron");
?>

<div class="card">
    <div class="card-title" style="margin-bottom: 15px;">
        <span>Info & Konfigurasi Sinkronisasi Mesin</span>
    </div>

    <div style="background: #eff6ff; border-left: 5px solid #3b82f6; padding: 16px 20px; border-radius: 10px; margin-bottom: 24px; font-size: 14px; color: #1e40af; line-height: 1.6;">
        <strong>Dua Cara Sinkronisasi Data Guru & Karyawan:</strong>
        <ul style="margin-left:20px; margin-top:6px;">
            <li><b>1. Otomatis via ADMS PUSH:</b> Mesin Solution X606-S otomatis mengirim data user & absensi ke server web secara berkala.</li>
            <li><b>2. Manual via Socket UDP:</b> Gunakan form di bawah untuk menarik nama via socket UDP port 4370.</li>
        </ul>
    </div>

    <?php echo $pesan; ?>

    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        
        <div style="max-width: 400px;">
            <label for="comm_key">Comm Key / Password Mesin (Default: 0):</label>
            <input type="number" id="comm_key" name="comm_key" value="<?php echo h($comm_key); ?>" placeholder="0">

            <button type="submit" name="tarik" class="btn btn-primary btn-block">Tarik Data dari Mesin Sekarang</button>
        </div>
    </form>
</div>

<?php if (!empty($detail)): ?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>Detail Data Disinkronisasi</span>
        </div>
        <span class="badge badge-masuk">Total: <?php echo count($detail); ?></span>
    </div>
    
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>PIN / ID</th>
                    <th>Nama Guru & Karyawan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detail as $i => $d): ?>
                <tr>
                    <td><b><?php echo $i + 1; ?></b></td>
                    <td><code style="background:#f1f5f9; padding:4px 10px; border-radius:6px; font-weight:700; color:#0f172a;"><?php echo h($d['pin']); ?></code></td>
                    <td style="text-align:left;"><b><?php echo h($d['nama']); ?></b></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>