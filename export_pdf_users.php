<?php
// ============================================================
// EXPORT PDF / CETAK DOKUMEN RESMI KREDENSIAL AKUN USER
// Kop Surat Resmi SMK Pasundan 2 Bandung + Daftar Username & Password Default
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_role(['superadmin']);

$conn = getDB();
$app_settings = get_app_settings();

$search      = trim($_GET['q'] ?? '');
$filter_role = trim($_GET['role'] ?? 'semua');

$where = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(u.username LIKE ? OR u.pin LIKE ? OR mk.nama LIKE ?)";
    $st = "%{$search}%";
    $params[] = $st; $params[] = $st; $params[] = $st;
    $types .= 'sss';
}

if (in_array($filter_role, ['superadmin', 'admin', 'rnd', 'tatausaha', 'staff', 'user'])) {
    $where[] = "u.role = ?";
    $params[] = $filter_role;
    $types .= 's';
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$data_sql = "SELECT u.id, u.username, u.role, u.pin, u.created_at, u.last_active, mk.nama as nama_karyawan, mk.departemen 
             FROM users u 
             LEFT JOIN master_karyawan mk ON u.pin = mk.pin 
             {$where_sql} 
             ORDER BY u.role ASC, CAST(u.pin AS UNSIGNED) ASC, u.id ASC";

$stmt_d = $conn->prepare($data_sql);
if (!empty($types)) {
    $stmt_d->bind_param($types, ...$params);
}
$stmt_d->execute();
$list_users = $stmt_d->get_result()->fetch_all(MYSQLI_ASSOC);

$total_user = count($list_users);
$str_role   = $filter_role === 'semua' ? 'Semua Role' : strtoupper($filter_role);

log_audit("EXPORT_PDF_USERS", "Export PDF Kredensial Akun User ({$total_user} user, Filter: {$str_role})");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar_Kredensial_Akun_User_SMK_Pasundan_2_Bandung</title>
    <style>
        @page { size: A4 portrait; margin: 10mm 12mm 12mm 12mm; }
        body { font-family: 'Times New Roman', Times, serif; color: #000; background: #fff; margin: 0; padding: 0; font-size: 11px; }
        
        /* KOP SURAT SEKOLAH OFISIAL */
        .header-kop { display: flex; align-items: center; justify-content: center; gap: 15px; padding-bottom: 4px; margin-bottom: 2px; }
        .logo-kop { width: 85px; height: 85px; flex-shrink: 0; }
        .logo-kop img { width: 100%; height: 100%; object-fit: contain; }
        .text-kop { text-align: center; flex: 1; }
        .text-kop .line-1 { font-size: 12pt; font-weight: bold; color: #000; letter-spacing: 0.2px; margin: 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-2 { font-size: 15pt; font-weight: 800; color: #1e3a8a; letter-spacing: 0.5px; margin: 2px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-3 { font-size: 9.5pt; font-weight: bold; color: #000; margin: 2px 0 1px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-jurusan { font-size: 9pt; font-weight: bold; color: #991b1b; line-height: 1.2; margin: 1px 0; font-family: 'Arial', sans-serif; }
        .text-kop .line-alamat { font-size: 9.5pt; font-weight: bold; color: #000; margin-top: 3px; font-family: 'Arial', sans-serif; }
        .text-kop .line-web { font-size: 9pt; color: #000; margin-top: 1px; font-family: 'Arial', sans-serif; }
        .double-line { border: none; border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-bottom: 14px; }

        .title-report { text-align: center; margin-bottom: 14px; font-family: 'Arial', sans-serif; }
        .title-report h4 { margin: 0; font-size: 13pt; text-transform: uppercase; text-decoration: underline; }
        .title-report p { margin: 3px 0 0 0; font-size: 10pt; font-weight: bold; }

        /* METADATA */
        .meta-table {
            width: 100%;
            margin-bottom: 12px;
            font-size: 9.5pt;
            font-family: 'Arial', sans-serif;
        }
        .meta-table td {
            padding: 3px 0;
        }

        /* TABEL DATA */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 9.5pt;
            font-family: 'Arial', sans-serif;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            text-align: center;
            vertical-align: middle;
        }
        table.data-table th {
            background-color: #e2e8f0;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 8.5pt;
        }
        table.data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .text-left { text-align: left !important; }
        .code-box {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
        }

        /* PETUNJUK & TTD */
        .info-box {
            border: 1px solid #000;
            background: #f8fafc;
            padding: 10px 14px;
            font-size: 9pt;
            line-height: 1.5;
            margin-bottom: 20px;
            border-radius: 6px;
            font-family: 'Arial', sans-serif;
        }

        .ttd-box {
            margin-top: 18px;
            display: flex;
            justify-content: space-between;
            page-break-inside: avoid;
            font-family: 'Arial', sans-serif;
            font-size: 9.5pt;
        }
        .ttd-col {
            text-align: center;
            width: 240px;
        }

        /* PRINT MEDIA CONTROL */
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
        }
    </style>
</head>
<body>

<div class="no-print" style="background:#f1f5f9; padding:10px 20px; border-bottom:1px solid #cbd5e1; display:flex; justify-content:space-between; align-items:center; font-family:'Arial', sans-serif;">
    <div><b>📄 Preview Kredensial Akun User PDF Official</b> (Kop Surat Resmi SMK Pasundan 2 Bandung)</div>
    <button onclick="window.print()" style="background:#2563eb; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">🖨️ Cetak / Simpan PDF</button>
</div>

<div style="padding: 10px 15px;">

    <!-- KOP SURAT SEKOLAH OFISIAL -->
    <div class="header-kop">
        <div class="logo-kop">
            <img src="<?php echo h($app_settings['logo_sekolah'] ?? 'assets/logo_pasundan2.png'); ?>" alt="Logo Sekolah">
        </div>
        <div class="text-kop">
            <div class="line-1"><?php echo h(strtoupper($app_settings['nama_yayasan'] ?? 'YAYASAN PENDIDIKAN')); ?></div>
            <div class="line-2"><?php echo h(strtoupper($app_settings['nama_sekolah'] ?? 'SMK PASUNDAN 2 BANDUNG')); ?></div>
            <?php if (!empty($app_settings['sub_header_kop'])): ?>
            <div class="line-jurusan"><?php echo h($app_settings['sub_header_kop']); ?></div>
            <?php endif; ?>
            <div class="line-alamat"><?php echo h($app_settings['alamat_sekolah'] ?? ''); ?> <?php echo !empty($app_settings['telepon_sekolah']) ? 'Telp. ' . h($app_settings['telepon_sekolah']) : ''; ?></div>
            <?php if (!empty($app_settings['email_sekolah'])): ?>
            <div class="line-web">e-mail : <?php echo h($app_settings['email_sekolah']); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="double-line"></div>

    <!-- JUDUL DOKUMEN -->
    <div class="title-report">
        <h4>DAFTAR KREDENSIAL AKUN USER &amp; HAK AKSES PORTAL MANDIRI</h4>
        <p>Sistem Pemantauan Absensi Guru &amp; Karyawan &bull; SMK Pasundan 2 Bandung</p>
    </div>

    <!-- METADATA -->
    <table class="meta-table">
        <tr>
            <td style="width:140px;"><b>Tanggal Cetak</b></td>
            <td>: <?php echo date('d F Y (H:i') . ' WIB)'; ?></td>
            <td style="width:120px;"><b>Filter Role</b></td>
            <td>: <b><?php echo h($str_role); ?></b></td>
        </tr>
        <tr>
            <td><b>Dicetak Oleh</b></td>
            <td>: <?php echo h($_SESSION['username'] ?? 'Superadmin'); ?> (Superadmin)</td>
            <td><b>Total Akun</b></td>
            <td>: <b><?php echo $total_user; ?> Akun User</b></td>
        </tr>
    </table>

    <!-- TABEL DATA KREDENSIAL USER -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:30px;">No</th>
                <th style="width:50px;">PIN</th>
                <th class="text-left">Nama Karyawan &amp; Departemen</th>
                <th style="width:120px;">Username</th>
                <th style="width:120px;">Password Default</th>
                <th style="width:80px;">Role</th>
                <th style="width:100px;">Terakhir Aktif</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($list_users)): 
                $no = 1;
                foreach ($list_users as $u):
                    $user_name_show = !empty($u['username']) ? $u['username'] : (!empty($u['pin']) ? "user" . $u['pin'] : "-");
                    $pass_default   = !empty($u['pin']) ? "smk" . $u['pin'] : "smk12345";
                    $role_lbl       = strtoupper($u['role']);
                    $nama_display   = !empty($u['nama_karyawan']) ? h($u['nama_karyawan']) : "<i style='color:#64748b;'>Administrator System</i>";
                    $dept_display   = !empty($u['departemen']) ? h($u['departemen']) : "-";
                    $last_act       = !empty($u['last_active']) ? date('d/m/Y H:i', strtotime($u['last_active'])) : "Belum Pernah";
            ?>
                <tr>
                    <td><b><?php echo $no++; ?></b></td>
                    <td><code><?php echo h($u['pin'] ?: '-'); ?></code></td>
                    <td class="text-left">
                        <b><?php echo $nama_display; ?></b>
                        <div style="font-size:8pt; color:#475569;"><?php echo $dept_display; ?></div>
                    </td>
                    <td><span class="code-box"><?php echo h($user_name_show); ?></span></td>
                    <td><span class="code-box"><?php echo h($pass_default); ?></span></td>
                    <td><b><?php echo $role_lbl; ?></b></td>
                    <td style="font-size:8pt;"><?php echo $last_act; ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td colspan="7" style="padding:20px; color:#64748b;">Tidak ada data user.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- PETUNJUK PENGGUNAAN -->
    <div class="info-box">
        <b>💡 PETUNJUK LOGIN &amp; KEAMANAN AKUN KARYAWAN:</b><br>
        1. Akses halaman portal absensi di: <b><code>http://172.16.0.61</code></b> melalui browser HP atau Komputer.<br>
        2. Masukkan <b>Username</b> (format: <code>user[PIN]</code>, contoh PIN 88 ➔ Username: <code>user88</code>) dan <b>Password Default</b> (<code>smk[PIN]</code>, contoh: <code>smk88</code>).<br>
        3. Demi keamanan akun, setiap pengguna disarankan <b>segera mengganti password default</b> setelah login pertama kali melalui menu <b>"🔑 Ganti Password Akun"</b>.
    </div>

    <!-- BLOK TANDA TANGAN RESMI -->
    <div class="ttd-box">
        <div class="ttd-col">
            <p>Mengetahui,<br><b><?php echo h($app_settings['jabatan_kepsek'] ?? 'Kepala Sekolah'); ?> <?php echo h($app_settings['nama_sekolah'] ?? ''); ?></b></p>
            <br><br><br><br>
            <p><b><u><?php echo h($app_settings['nama_kepsek'] ?? 'Umar Khatob, S.Pd, M.Si.'); ?></u></b><br><?php echo (!empty($app_settings['nip_kepsek']) && $app_settings['nip_kepsek'] !== '-') ? 'NIP. ' . h($app_settings['nip_kepsek']) : ''; ?></p>
        </div>
        <div class="ttd-col">
            <p><?php echo h($app_settings['kota_surat'] ?? 'Bandung'); ?>, <?php echo date('d') . ' ' . (['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][(int)date('n')]) . ' ' . date('Y'); ?><br><b><?php echo h($app_settings['jabatan_admin_rekap'] ?? 'Administrator System'); ?></b></p>
            <br><br><br><br>
            <p><b><u><?php echo h($app_settings['nama_admin_rekap'] ?? 'Indra Setia Budi'); ?></u></b><br><?php echo (!empty($app_settings['nip_admin_rekap']) && $app_settings['nip_admin_rekap'] !== '-') ? 'NIP. ' . h($app_settings['nip_admin_rekap']) : ''; ?></p>
        </div>
    </div>

</div>

<script>
window.onload = function() {
    if (window.location.search.includes('auto_print=1')) {
        setTimeout(() => window.print(), 500);
    }
};
</script>

</body>
</html>
