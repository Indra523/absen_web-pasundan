<?php
// ============================================================
// HALAMAN MANAJEMEN GURU & KARYAWAN
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/vendor/autoload.php';

// Proteksi Halaman: Hanya Superadmin yang boleh mengelola data guru & karyawan
require_role(['superadmin']);

use Shuchkin\SimpleXLSX;

$conn = getDB();
$pesan = "";

// --- 1. PROSES DOWNLOAD TEMPLATE CSV ---
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=template_guru_karyawan.csv');
    $output = fopen('php://output', 'w');
    // Header CSV
    fputcsv($output, ['No', 'PIN', 'Nama', 'Departemen', 'Tipe (karyawan/guru)']);
    // Contoh Data
    fputcsv($output, ['1', '88', 'Drs. H. Ahmad Ridwan, M.Pd.', 'Guru Pengajar', 'guru']);
    fputcsv($output, ['2', '89', 'Budi Santoso, S.ST.', 'Staff TU / Laboratorium', 'karyawan']);
    fputcsv($output, ['3', '90', 'Citra Dewi, S.Pd.', 'Guru Pengajar', 'guru']);
    fclose($output);
    exit;
}

// --- 2. PROSES TAMBAH / UPDATE SINGLE KARYAWAN ---
if (isset($_POST['submit'])) {
    csrf_verify();

    $pin        = trim($_POST['pin'] ?? '');
    $nama       = trim($_POST['nama'] ?? '');
    $departemen = trim($_POST['departemen'] ?? '');
    $tipe       = in_array($_POST['tipe'] ?? '', ['karyawan', 'guru']) ? $_POST['tipe'] : 'karyawan';

    if (!empty($pin) && !empty($nama)) {
        $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama, departemen, tipe) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama = ?, departemen = ?, tipe = ?");
        $stmt->bind_param("sssssss", $pin, $nama, $departemen, $tipe, $nama, $departemen, $tipe);
        
        if ($stmt->execute()) {
            $pesan = "<p style='color: #155724; background: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 8px; margin-bottom: 15px;'><b>✅ Berhasil!</b> Data Guru/Karyawan berhasil disimpan.</p>";
        } else {
            $pesan = "<p style='color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; border-radius: 8px; margin-bottom: 15px;'><b>Gagal:</b> " . h($conn->error) . "</p>";
        }
    } else {
        $pesan = "<p style='color: #856404; background: #fff3cd; border: 1px solid #ffeeba; padding: 12px; border-radius: 8px; margin-bottom: 15px;'>PIN dan Nama wajib diisi!</p>";
    }
}

// --- 3. PROSES IMPORT FILE EXCEL / CSV ---
if (isset($_POST['import_excel'])) {
    csrf_verify();

    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmp  = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $rows = [];

        // Baca File XLSX / XLS menggunakan SimpleXLSX
        if ($ext === 'xlsx' || $ext === 'xls') {
            if ($xlsx = SimpleXLSX::parse($fileTmp)) {
                $rows = $xlsx->rows();
            } else {
                $pesan = "<p style='color: red;'><b>Gagal membaca Excel:</b> " . h(SimpleXLSX::parseError()) . "</p>";
            }
        }
        // Baca File CSV
        elseif ($ext === 'csv') {
            if (($handle = fopen($fileTmp, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) === 1 && strpos($data[0], ';') !== false) {
                        $data = explode(';', $data[0]);
                    }
                    $rows[] = $data;
                }
                fclose($handle);
            }
        } else {
            $pesan = "<p style='color: red;'>Format file tidak didukung! Harap unggah file <b>.xlsx</b> atau <b>.csv</b>.</p>";
        }

        // Jalankan Import jika data baris berhasil dibaca
        if (!empty($rows)) {
            $imported = 0;
            $updated  = 0;
            $skipped  = 0;

            $colPin  = -1;
            $colNama = -1;
            $colDept = -1;
            $colTipe = -1;

            $headerRow = $rows[0];
            foreach ($headerRow as $idx => $val) {
                $v = strtolower(trim(strval($val)));
                if (in_array($v, ['pin', 'user id', 'userid', 'id', 'no pin', 'pin/user id', 'no/pin/user id'])) {
                    $colPin = $idx;
                } elseif (in_array($v, ['nama', 'name', 'nama lengkap', 'nama karyawan', 'nama guru'])) {
                    $colNama = $idx;
                } elseif (in_array($v, ['departemen', 'dept', 'bagian', 'jabatan', 'unit'])) {
                    $colDept = $idx;
                } elseif (in_array($v, ['tipe', 'type', 'kategori', 'tipe (karyawan/guru)'])) {
                    $colTipe = $idx;
                }
            }

            $startRow = 0;
            if ($colPin !== -1 && $colNama !== -1) {
                $startRow = 1;
            } else {
                $firstRow = $rows[0];
                if (count($firstRow) >= 3 && is_numeric(trim($firstRow[0]))) {
                    $colPin  = 1;
                    $colNama = 2;
                    $colDept = 3;
                    $colTipe = 4;
                    $startRow = 0;
                } else {
                    $colPin  = (count($firstRow) >= 3) ? 1 : 0;
                    $colNama = (count($firstRow) >= 3) ? 2 : 1;
                    $colDept = (count($firstRow) >= 4) ? 3 : 2;
                    $colTipe = (count($firstRow) >= 5) ? 4 : -1;
                    $startRow = 1;
                }
            }

            $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama, departemen, tipe) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama = VALUES(nama), departemen = VALUES(departemen), tipe = VALUES(tipe)");

            for ($i = $startRow; $i < count($rows); $i++) {
                $row = $rows[$i];
                $pin  = isset($row[$colPin]) ? trim(strval($row[$colPin])) : '';
                $nama = isset($row[$colNama]) ? trim(strval($row[$colNama])) : '';
                $dept = isset($row[$colDept]) ? trim(strval($row[$colDept])) : '';
                $tipe_val = ($colTipe !== -1 && isset($row[$colTipe])) ? strtolower(trim(strval($row[$colTipe]))) : 'karyawan';
                $tipe = in_array($tipe_val, ['guru', 'karyawan']) ? $tipe_val : 'karyawan';

                if (empty($pin) || empty($nama)) {
                    $skipped++;
                    continue;
                }

                $stmt->bind_param("ssss", $pin, $nama, $dept, $tipe);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows === 1) {
                        $imported++;
                    } else {
                        $updated++;
                    }
                }
            }

            $pesan = "<div style='background:#d4edda; color:#155724; padding:14px 18px; border-radius:10px; border:1px solid #c3e6cb; margin-bottom:20px;'>";
            $pesan .= "<b>✅ Import Selesai!</b><br>";
            $pesan .= "• <b>{$imported}</b> data baru berhasil dimasukkan.<br>";
            $pesan .= "• <b>{$updated}</b> data diperbarui.<br>";
            if ($skipped > 0) {
                $pesan .= "• <b>{$skipped}</b> baris dilewati (kosong/header).";
            }
            $pesan .= "</div>";
        }
    } else {
        $pesan = "<p style='color: red;'>Harap pilih file Excel / CSV terlebih dahulu!</p>";
    }
}

// --- 4. PROSES HAPUS KARYAWAN ---
if (isset($_GET['hapus'])) {
    $pin_hapus = $_GET['hapus'];
    $stmt = $conn->prepare("DELETE FROM master_karyawan WHERE pin = ?");
    $stmt->bind_param("s", $pin_hapus);
    if ($stmt->execute()) {
        $pesan = "<p style='color: #155724; background: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 8px; margin-bottom: 15px;'><b>Berhasil!</b> Data dengan PIN " . h($pin_hapus) . " telah dihapus.</p>";
    }
}

// Ambil daftar karyawan yang sudah terdaftar
$result = $conn->query("SELECT * FROM master_karyawan ORDER BY pin ASC");

render_header("Kelola Guru & Karyawan", "karyawan");
?>

<div style="margin-bottom: 15px;"><?php echo $pesan; ?></div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px; margin-bottom: 24px;">
    <!-- CARD 1: FORM INPUT SINGLE -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-title">
            <span>✏️ Tambah / Edit Manual</span>
        </div>
        <form method="POST" action="" style="margin-top: 15px;">
            <?php echo csrf_field(); ?>

            <label>PIN / User ID di Mesin:</label>
            <input type="text" name="pin" placeholder="Contoh: 88 atau 89" required>

            <label>Nama Lengkap (Gelar):</label>
            <input type="text" name="nama" placeholder="Contoh: Drs. H. Ahmad, M.Pd." required>

            <label>Departemen / Jabatan:</label>
            <input type="text" name="departemen" placeholder="Contoh: Guru Pengajar / Staff TU">

            <label>Tipe Kategori:</label>
            <select name="tipe" style="margin-bottom:18px;">
                <option value="karyawan">👔 Karyawan / Staff (Hari kerja kalender)</option>
                <option value="guru">👨‍🏫 Guru Pengajar (Sesuai jadwal ngajar)</option>
            </select>

            <button type="submit" name="submit" class="btn btn-success btn-block">💾 Simpan Data Karyawan</button>
        </form>
    </div>

    <!-- CARD 2: FORM IMPORT EXCEL / CSV -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-title">
            <span>📊 Import Massal dari Excel / CSV</span>
        </div>
        <form method="POST" action="" enctype="multipart/form-data" style="margin-top: 15px;">
            <?php echo csrf_field(); ?>

            <label>Pilih File Excel (.xlsx) atau CSV (.csv):</label>
            <input type="file" name="excel_file" accept=".xlsx, .xls, .csv" required>

            <div style="font-size: 12px; color: #64748b; margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; line-height: 1.5;">
                <strong>Format Kolom Excel:</strong><br>
                • Kolom 1: <code>No</code> (opsional)<br>
                • Kolom 2: <code>PIN / User ID</code> (Wajib)<br>
                • Kolom 3: <code>Nama Lengkap</code> (Wajib)<br>
                • Kolom 4: <code>Departemen / Jabatan</code> (Opsional)<br>
                • Kolom 5: <code>Tipe</code>: <code>karyawan</code> atau <code>guru</code> (Opsional)
            </div>

            <button type="submit" name="import_excel" class="btn btn-primary btn-block">📥 Unggah & Import Excel</button>
        </form>

        <a href="input_karyawan.php?download_template=1" style="display:inline-block; margin-top:14px; font-size:13px; color:#3b82f6; text-decoration:none; font-weight:600;">📄 Download Contoh Template CSV/Excel</a>
    </div>
</div>

<!-- CARD 3: TABEL DAFTAR KARYAWAN -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>📋 Master Data Guru & Karyawan Terdaftar</span>
        </div>
        <span class="badge badge-verif" style="font-size:13px; font-weight:700;">Total: <?php echo $result->num_rows; ?> Orang</span>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>PIN / ID</th>
                    <th>Nama Lengkap</th>
                    <th>Departemen / Jabatan</th>
                    <th>Tipe Kategori</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $is_guru = ($row['tipe'] === 'guru');
                        $badge_tipe = $is_guru 
                            ? "<span class='badge' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;'>👨‍🏫 Guru</span>"
                            : "<span class='badge' style='background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'>👔 Karyawan</span>";

                        echo "<tr>
                                <td><code style='background:#f1f5f9; padding:4px 10px; border-radius:6px; font-weight:700; color:#0f172a;'>" . h($row['pin']) . "</code></td>
                                <td style='text-align:left;'><b>" . h($row['nama']) . "</b></td>
                                <td style='text-align:left; color:#64748b;'>" . h($row['departemen']) . "</td>
                                <td>{$badge_tipe}</td>
                                <td><a class='btn-hapus' style='color:#dc2626; font-size:13px; font-weight:600; text-decoration:none;' href='input_karyawan.php?hapus=" . urlencode($row['pin']) . "' onclick=\"return confirm('Yakin ingin menghapus data " . h($row['nama']) . "?')\">🗑️ Hapus</a></td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' style='padding: 30px; color:#94a3b8;'>Belum ada data guru & karyawan. Silakan tambah manual atau import dari Excel.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>