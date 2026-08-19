<?php
// ============================================================
// HALAMAN MANAJEMEN GURU & KARYAWAN (Dengan Real-Time Search & Foto Master)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/vendor/autoload.php';

// Proteksi Halaman: Hanya Superadmin yang boleh mengelola data guru & karyawan
require_role(['superadmin']);

use Shuchkin\SimpleXLSX;

$conn = getDB();
$pesan = "";

// Helper untuk Upload Foto Karyawan
function handle_foto_upload($pin, $file_input_name) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES[$file_input_name]['tmp_name'];
        $file_name = $_FILES[$file_input_name]['name'];
        $file_size = $_FILES[$file_input_name]['size'];
        $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowed) && $file_size <= 2 * 1024 * 1024) {
            $target_dir = __DIR__ . '/uploads/foto_karyawan/';
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0777, true);
                @chmod($target_dir, 0777);
            }
            $new_filename = "foto_" . preg_replace('/[^a-zA-Z0-9]/', '', $pin) . "_" . time() . "." . $ext;
            if (move_uploaded_file($file_tmp, $target_dir . $new_filename)) {
                @chmod($target_dir . $new_filename, 0666);
                return "uploads/foto_karyawan/" . $new_filename;
            }
        }
    }
    return null;
}

// --- 1. PROSES DOWNLOAD TEMPLATE CSV ---
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=template_guru_karyawan.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'PIN', 'Nama', 'Departemen', 'Tipe (karyawan/guru)']);
    fputcsv($output, ['1', '88', 'Drs. H. Ahmad Ridwan, M.Pd.', 'Guru Pengajar', 'guru']);
    fputcsv($output, ['2', '89', 'Budi Santoso, S.ST.', 'Staff TU / Laboratorium', 'karyawan']);
    fclose($output);
    exit;
}

// --- 2. PROSES TAMBAH SINGLE KARYAWAN ---
if (isset($_POST['submit'])) {
    csrf_verify();

    $pin        = trim($_POST['pin'] ?? '');
    $nama       = trim($_POST['nama'] ?? '');
    $departemen = trim($_POST['departemen'] ?? '');
    $tipe       = in_array($_POST['tipe'] ?? '', ['karyawan', 'guru']) ? $_POST['tipe'] : 'karyawan';
    $foto_path  = handle_foto_upload($pin, 'foto_karyawan');

    if (!empty($pin) && !empty($nama)) {
        if ($foto_path !== null) {
            $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama, departemen, tipe, foto) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama = VALUES(nama), departemen = VALUES(departemen), tipe = VALUES(tipe), foto = VALUES(foto)");
            $stmt->bind_param("sssss", $pin, $nama, $departemen, $tipe, $foto_path);
        } else {
            $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama, departemen, tipe) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama = VALUES(nama), departemen = VALUES(departemen), tipe = VALUES(tipe)");
            $stmt->bind_param("ssss", $pin, $nama, $departemen, $tipe);
        }
        
        if ($stmt->execute()) {
            $pesan = "<div style='background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px;'>
                <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='#059669' stroke-width='2.5'><polyline points='20 6 9 17 4 12'/></svg>
                <span>Data Guru/Karyawan dengan PIN <b>" . h($pin) . "</b> berhasil disimpan.</span>
            </div>";
        } else {
            $pesan = "<div style='background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px;'>Gagal: " . h($conn->error) . "</div>";
        }
    } else {
        $pesan = "<div style='background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px;'>PIN dan Nama wajib diisi!</div>";
    }
}

// --- 3. PROSES EDIT SINGLE KARYAWAN ---
if (isset($_POST['update_karyawan'])) {
    csrf_verify();

    $pin        = trim($_POST['edit_pin'] ?? '');
    $nama       = trim($_POST['edit_nama'] ?? '');
    $departemen = trim($_POST['edit_departemen'] ?? '');
    $tipe       = in_array($_POST['edit_tipe'] ?? '', ['karyawan', 'guru']) ? $_POST['edit_tipe'] : 'karyawan';
    $foto_path  = handle_foto_upload($pin, 'edit_foto_karyawan');

    if (!empty($pin) && !empty($nama)) {
        if ($foto_path !== null) {
            $stmt = $conn->prepare("UPDATE master_karyawan SET nama = ?, departemen = ?, tipe = ?, foto = ? WHERE pin = ?");
            $stmt->bind_param("sssss", $nama, $departemen, $tipe, $foto_path, $pin);
        } else {
            $stmt = $conn->prepare("UPDATE master_karyawan SET nama = ?, departemen = ?, tipe = ? WHERE pin = ?");
            $stmt->bind_param("ssss", $nama, $departemen, $tipe, $pin);
        }
        
        if ($stmt->execute()) {
            $pesan = "<div style='background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px;'>
                <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='#059669' stroke-width='2.5'><polyline points='20 6 9 17 4 12'/></svg>
                <span>Data Guru/Karyawan (PIN: <b>" . h($pin) . "</b>) berhasil diperbarui.</span>
            </div>";
        } else {
            $pesan = "<div style='background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px;'>Gagal update: " . h($conn->error) . "</div>";
        }
    } else {
        $pesan = "<div style='background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px;'>Nama tidak boleh kosong!</div>";
    }
}

// --- 4. PROSES HAPUS MASSAL (BULK DELETE) ---
if (isset($_POST['bulk_delete'])) {
    csrf_verify();

    $pins_selected = $_POST['pin_selected'] ?? [];
    if (!empty($pins_selected) && is_array($pins_selected)) {
        $total_deleted = 0;
        $stmt = $conn->prepare("DELETE FROM master_karyawan WHERE pin = ?");
        
        foreach ($pins_selected as $p) {
            $p_clean = trim($p);
            if (!empty($p_clean)) {
                $stmt->bind_param("s", $p_clean);
                if ($stmt->execute()) {
                    $total_deleted++;
                }
            }
        }
        $pesan = "<div style='background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px;'>
            <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='#059669' stroke-width='2.5'><polyline points='20 6 9 17 4 12'/></svg>
            <span>Hapus Massal Berhasil: <b>{$total_deleted}</b> data guru & karyawan telah dihapus.</span>
        </div>";
    } else {
        $pesan = "<div style='background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px;'>Pilih minimal satu data yang ingin dihapus!</div>";
    }
}

// --- 5. PROSES IMPORT FILE EXCEL / CSV ---
if (isset($_POST['import_excel'])) {
    csrf_verify();

    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmp  = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $rows = [];

        if ($ext === 'xlsx' || $ext === 'xls') {
            if ($xlsx = SimpleXLSX::parse($fileTmp)) {
                $rows = $xlsx->rows();
            } else {
                $pesan = "<div style='background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px;'>Gagal membaca Excel: " . h(SimpleXLSX::parseError()) . "</div>";
            }
        }
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
            $pesan = "<div style='background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px;'>Format file tidak didukung! Unggah file <b>.xlsx</b> atau <b>.csv</b>.</div>";
        }

        if (!empty($rows)) {
            $imported = 0;
            $updated  = 0;
            $skipped  = 0;

            $colPin  = 1;
            $colNama = 2;
            $colDept = 3;
            $colTipe = 4;

            $startRow = 0;
            if (isset($rows[0][0]) && strtolower($rows[0][0]) == 'no') {
                $startRow = 1;
            }

            $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama, departemen, tipe) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama = VALUES(nama), departemen = VALUES(departemen), tipe = VALUES(tipe)");

            for ($i = $startRow; $i < count($rows); $i++) {
                $row = $rows[$i];
                $pin  = isset($row[$colPin]) ? trim(strval($row[$colPin])) : '';
                $nama = isset($row[$colNama]) ? trim(strval($row[$colNama])) : '';
                $dept = isset($row[$colDept]) ? trim(strval($row[$colDept])) : '';
                $tipe_val = (isset($row[$colTipe])) ? strtolower(trim(strval($row[$colTipe]))) : 'karyawan';
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

            $pesan = "<div style='background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px;'>
                <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='#059669' stroke-width='2.5'><polyline points='20 6 9 17 4 12'/></svg>
                <span>Import Selesai: <b>{$imported}</b> data baru ditambahkan, <b>{$updated}</b> diperbarui, <b>{$skipped}</b> dilewati.</span>
            </div>";
        }
    }
}

// --- 6. PROSES HAPUS SINGLE KARYAWAN ---
if (isset($_POST['hapus_single'])) {
    csrf_verify();
    $pin_hapus = trim($_POST['hapus_single']);
    if (!empty($pin_hapus)) {
        $stmt = $conn->prepare("DELETE FROM master_karyawan WHERE pin = ?");
        $stmt->bind_param("s", $pin_hapus);
        if ($stmt->execute()) {
            $pesan = "<div style='background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:12px 16px; border-radius:12px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px;'>
                <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='#059669' stroke-width='2.5'><polyline points='20 6 9 17 4 12'/></svg>
                <span>Data Guru/Karyawan PIN <b>" . h($pin_hapus) . "</b> berhasil dihapus.</span>
            </div>";
        }
    }
}

// --- 7. AMBIL DATA DARI DATABASE DENGAN SORTING ---
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'pin_asc';
$q_master = isset($_GET['q_master']) ? trim($_GET['q_master']) : '';

$orderBy = "CAST(pin AS UNSIGNED) ASC, pin ASC";
switch ($sort) {
    case 'pin_desc':
        $orderBy = "CAST(pin AS UNSIGNED) DESC, pin DESC";
        break;
    case 'nama_asc':
        $orderBy = "nama ASC";
        break;
    case 'nama_desc':
        $orderBy = "nama DESC";
        break;
    case 'tipe_desc':
        $orderBy = "tipe DESC, nama ASC";
        break;
    case 'tipe_asc':
        $orderBy = "tipe ASC, nama ASC";
        break;
    case 'dept_asc':
        $orderBy = "departemen ASC, nama ASC";
        break;
    case 'pin_asc':
    default:
        $orderBy = "CAST(pin AS UNSIGNED) ASC, pin ASC";
        break;
}

$sql = "SELECT pin, nama, departemen, tipe, foto FROM master_karyawan ORDER BY " . $orderBy;
$result = $conn->query($sql);
$total_master = $result->num_rows;

render_header("Kelola Guru & Karyawan", "input_karyawan");
?>

<style>
/* ===== MODERN STYLES INPUT KARYAWAN ===== */
.master-wrapper {
    display: flex;
    flex-direction: column;
    gap: 22px;
    margin-bottom: 35px;
}

.avatar-table-thumb {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid #e2e8f0;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
    background: #f1f5f9;
}
.avatar-table-initials {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.25);
    flex-shrink: 0;
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.btn-action-icon {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s ease;
}

.grid-top-forms {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
@media (max-width: 900px) {
    .grid-top-forms {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="master-wrapper">

    <?php if (!empty($pesan)) echo $pesan; ?>

    <!-- TOP SECTION: FORM TAMBAH & IMPORT EXCEL -->
    <div class="grid-top-forms">
        <!-- CARD 1: FORM TAMBAH SINGLE KARYAWAN -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    <span>Tambah Guru / Karyawan Baru</span>
                </div>
            </div>

            <form method="POST" action="input_karyawan.php" enctype="multipart/form-data" style="margin-top: 14px;">
                <?php echo csrf_field(); ?>

                <div style="display:grid; grid-template-columns: 1fr 2fr; gap:12px;">
                    <div>
                        <label for="input_pin">PIN / User ID Mesin:</label>
                        <input type="text" id="input_pin" name="pin" placeholder="Contoh: 88" required autocomplete="off">
                    </div>
                    <div>
                        <label for="input_nama">Nama Lengkap &amp; Gelar:</label>
                        <input type="text" id="input_nama" name="nama" placeholder="Contoh: Drs. H. Ahmad Ridwan, M.Pd." required autocomplete="off">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px;">
                    <div>
                        <label for="input_departemen">Departemen / Bidang:</label>
                        <input type="text" id="input_departemen" name="departemen" placeholder="Contoh: Guru Pengajar / Staff TU">
                    </div>
                    <div>
                        <label for="input_tipe">Kategori Tipe:</label>
                        <select id="input_tipe" name="tipe">
                            <option value="karyawan">Tenaga Kependidikan</option>
                            <option value="guru">Guru / Pendidik</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="foto_karyawan">Foto Profil Master (Opsional):</label>
                    <input type="file" id="foto_karyawan" name="foto_karyawan" accept="image/*" style="font-size:12.5px;">
                    <div style="font-size:11px; color:#64748b; margin-top:-10px; margin-bottom:16px;">Format: JPG, PNG, WEBP. Maks 2MB.</div>
                </div>

                <button type="submit" name="submit" class="btn btn-primary" style="width:100%; font-weight:800; padding:10px; box-shadow:0 4px 12px rgba(37,99,235,0.25);">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Simpan Data Pegawai</span>
                </button>
            </form>
        </div>

        <!-- CARD 2: FORM IMPORT EXCEL / CSV -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-header">
                <div class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    <span>Import Massal dari Excel / CSV</span>
                </div>
            </div>

            <form method="POST" action="input_karyawan.php" enctype="multipart/form-data" style="margin-top: 14px;">
                <?php echo csrf_field(); ?>

                <label for="excel_file">Pilih File Spreadsheet (.xlsx / .csv):</label>
                <input type="file" id="excel_file" name="excel_file" accept=".xlsx, .xls, .csv" required style="font-size:12.5px;">

                <div style="font-size: 11.5px; color: #64748b; margin-bottom: 14px; background: #f8fafc; padding: 10px 14px; border-radius: 10px; border: 1px solid #e2e8f0; line-height: 1.45;">
                    <strong>Urutan Kolom File:</strong><br>
                    1. <code>PIN / User ID</code> (Wajib) &nbsp;|&nbsp; 2. <code>Nama Lengkap</code> (Wajib)<br>
                    3. <code>Departemen</code> &nbsp;|&nbsp; 4. <code>Tipe</code> (<code>guru</code> / <code>karyawan</code>)
                </div>

                <button type="submit" name="import_excel" class="btn" style="width:100%; background:#059669; color:#fff; font-weight:800; padding:10px; box-shadow:0 4px 12px rgba(5,150,105,0.25);">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span>Unggah &amp; Import Data Pegawai</span>
                </button>
            </form>

            <div style="margin-top:12px; text-align:right;">
                <a href="input_karyawan.php?download_template=1" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:5px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Unduh Format Template CSV</span>
                </a>
            </div>
        </div>
    </div>

    <!-- CARD 3: TABEL DAFTAR KARYAWAN + REAL-TIME SEARCH + SORTING + BULK DELETE -->
    <div class="card">
        <!-- PANEL FILTER & SORTING -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:18px; padding-bottom:16px; border-bottom:1px solid #f1f5f9;">
            <div class="card-title" style="margin-bottom:0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Daftar Guru &amp; Tenaga Kependidikan</span>
                <span class="badge" id="count-visible-badge" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-size:11.5px;"><?php echo $total_master; ?> Pegawai</span>
            </div>

            <form method="GET" action="input_karyawan.php" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;" onsubmit="return false;">
                <!-- Input Pencarian Real-Time -->
                <div style="position:relative;">
                    <input type="text" id="q_master" name="q_master" value="<?php echo h($q_master); ?>" placeholder="Cari nama / PIN / dept..." style="margin-bottom:0; height:38px; width:230px; font-size:12.5px; padding-left:32px; border-radius:10px;" autocomplete="off">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>
                
                <!-- Dropdown Sorting -->
                <select name="sort" onchange="location.href='input_karyawan.php?sort=' + this.value + (document.getElementById('q_master').value ? '&q_master=' + encodeURIComponent(document.getElementById('q_master').value) : '')" style="margin-bottom:0; height:38px; font-size:12.5px; padding:6px 12px; width:auto; border-radius:10px; cursor:pointer;">
                    <option value="pin_asc" <?php echo $sort === 'pin_asc' ? 'selected' : ''; ?>>Urut PIN (Kecil ke Besar)</option>
                    <option value="pin_desc" <?php echo $sort === 'pin_desc' ? 'selected' : ''; ?>>Urut PIN (Besar ke Kecil)</option>
                    <option value="nama_asc" <?php echo $sort === 'nama_asc' ? 'selected' : ''; ?>>Nama (A ke Z)</option>
                    <option value="nama_desc" <?php echo $sort === 'nama_desc' ? 'selected' : ''; ?>>Nama (Z ke A)</option>
                    <option value="tipe_desc" <?php echo $sort === 'tipe_desc' ? 'selected' : ''; ?>>Tipe (Guru Terlebih Dahulu)</option>
                    <option value="tipe_asc" <?php echo $sort === 'tipe_asc' ? 'selected' : ''; ?>>Tipe (Karyawan Terlebih Dahulu)</option>
                    <option value="dept_asc" <?php echo $sort === 'dept_asc' ? 'selected' : ''; ?>>Departemen</option>
                </select>
            </form>
        </div>

        <!-- ACTION HEADER: TOTAL BADGE & BULK DELETE BUTTON -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
            <div style="font-size:12.5px; color:#64748b; font-weight:600;">
                Menampilkan <b id="count-visible"><?php echo $total_master; ?></b> dari <b><?php echo $total_master; ?></b> data terdaftar
            </div>

            <form method="POST" action="input_karyawan.php" id="form-bulk" style="margin:0;">
                <?php echo csrf_field(); ?>
                <button type="submit" name="bulk_delete" id="btn-bulk-delete" class="btn" 
                        style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; font-size:12px; padding:6px 14px; display:none; font-weight:700;"
                        onclick="return confirm('Yakin ingin menghapus semua data yang dicentang?')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <span>Hapus Terpilih (<span id="count-selected">0</span>)</span>
                </button>
        </div>

        <div class="table-responsive">
            <table id="tbl-master">
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;">
                            <input type="checkbox" id="check-all" onclick="toggleSelectAll(this)" style="width:16px; height:16px; cursor:pointer;" title="Pilih Semua">
                        </th>
                        <th style="width:70px;">PIN</th>
                        <th style="text-align:left;">Pegawai (Foto &amp; Nama)</th>
                        <th style="text-align:left;">Departemen / Jabatan</th>
                        <th style="width:120px;">Tipe Kategori</th>
                        <th style="width:170px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tbody-master">
                    <?php
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $is_guru = ($row['tipe'] === 'guru');
                            $badge_tipe = $is_guru 
                                ? "<span class='badge' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:11px; font-weight:700;'>Guru</span>"
                                : "<span class='badge' style='background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-size:11px; font-weight:700;'>Tendik</span>";

                            $pin_attr   = h($row['pin']);
                            $nama_attr  = h($row['nama']);
                            $dept_attr  = h($row['departemen']);
                            $tipe_attr  = h($row['tipe']);
                            $foto_db    = $row['foto'] ?? '';
                            $has_foto   = (!empty($foto_db) && file_exists(__DIR__ . '/' . $foto_db));
                            $foto_url   = $has_foto ? h($foto_db) . '?v=' . time() : '';

                            echo "<tr class='master-row' data-pin='{$pin_attr}' data-nama='" . strtolower($nama_attr) . "' data-dept='" . strtolower($dept_attr) . "'>
                                    <td style='text-align:center;'>
                                        <input type='checkbox' name='pin_selected[]' value='{$pin_attr}' form='form-bulk' class='chk-item' onchange='updateBulkState()' style='width:16px; height:16px; cursor:pointer;'>
                                    </td>
                                    <td><code style='background:#f1f5f9; padding:4px 8px; border-radius:6px; font-weight:700; color:#0f172a; font-size:12px;'>{$pin_attr}</code></td>
                                    <td style='text-align:left;'>
                                        <div class='user-cell'>
                                            " . ($has_foto 
                                                ? "<img src='{$foto_url}' class='avatar-table-thumb' alt='{$nama_attr}'>" 
                                                : "<div class='avatar-table-initials'>" . strtoupper(mb_substr($row['nama'], 0, 1)) . "</div>"
                                            ) . "
                                            <div>
                                                <div style='font-weight:800; color:#0f172a; font-size:13px;'>{$nama_attr}</div>
                                                <div style='font-size:11px; color:#64748b;'>PIN: {$pin_attr}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style='text-align:left; font-size:12.5px; color:#475569; font-weight:600;'>" . ($dept_attr ?: '-') . "</td>
                                    <td>{$badge_tipe}</td>
                                    <td>
                                        <div style='display:flex; gap:5px; justify-content:center; flex-wrap:wrap;'>
                                            <a class='btn-action-icon' style='background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0;' 
                                               href='user_profile.php?pin=" . urlencode($row['pin']) . "' title='Lihat Profil & Titik Peta Rumah' target='_blank'>
                                                <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'/><circle cx='12' cy='10' r='3'/></svg>
                                                <span>Peta/Profil</span>
                                            </a>
                                            <a class='btn-action-icon' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;' 
                                               href='riwayat_karyawan.php?pin=" . urlencode($row['pin']) . "' title='Lihat Riwayat Presensi'>
                                                <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><circle cx='12' cy='12' r='10'/><polyline points='12 6 12 12 16 14'/></svg>
                                                <span>Riwayat</span>
                                            </a>
                                            <button type='button' class='btn-action-icon' style='background:#f8fafc; color:#334155; border:1px solid #cbd5e1;'
                                                    onclick='bukaModalEditKaryawan(\"{$pin_attr}\", \"{$nama_attr}\", \"{$dept_attr}\", \"{$tipe_attr}\", \"{$foto_url}\")' title='Edit Data'>
                                                <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'/><path d='M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z'/></svg>
                                                <span>Edit</span>
                                            </button>
                                            <form method='POST' action='input_karyawan.php' style='display:inline;' onsubmit=\"return confirm('Yakin ingin menghapus data {$nama_attr}?')\">
                                                <input type='hidden' name='hapus_single' value='" . h($row['pin']) . "'>
                                                <input type='hidden' name='csrf_token' value='" . h($_SESSION['csrf_token'] ?? csrf_token()) . "'>
                                                <button type='submit' class='btn-action-icon' style='background:#fef2f2; color:#dc2626; border:1px solid #fecaca; cursor:pointer;' title='Hapus'>
                                                    <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><polyline points='3 6 5 6 21 6'/><path d='M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                  </tr>";
                        }
                    } else {
                        echo "<tr id='row-empty'><td colspan='6' style='padding: 30px; color:#94a3b8;'>Belum ada data guru & karyawan. Silakan tambah data baru di atas.</td></tr>";
                    }
                    ?>
                    <tr id="row-no-match" style="display:none;">
                        <td colspan="6" style="padding: 30px; color:#94a3b8; text-align:center;">Data tidak ditemukan untuk kata kunci pencarian tersebut.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        </form>
    </div>

</div>

<!-- ================= MODAL EDIT KARYAWAN ================= -->
<div id="modal-edit-karyawan" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:20px; padding:28px 24px; width:100%; max-width:480px; box-shadow:0 25px 60px rgba(0,0,0,0.3); animation:slideUp 0.22s ease-out;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <div style="font-size:16px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <span>Edit Data Guru &amp; Karyawan</span>
            </div>
            <button type="button" onclick="tutupModalEditKaryawan()" style="background:#f1f5f9; border:none; border-radius:8px; padding:6px 10px; cursor:pointer; font-size:14px; color:#64748b; font-weight:700;">✕</button>
        </div>

        <form method="POST" action="input_karyawan.php" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="update_karyawan" value="1">
            <input type="hidden" name="edit_pin" id="edit_pin">

            <div style="background:#f8fafc; border-radius:12px; padding:12px 16px; margin-bottom:16px; border:1px solid #e2e8f0; display:flex; align-items:center; gap:14px;">
                <img id="edit_foto_preview" src="" style="width:48px; height:48px; border-radius:50%; object-fit:cover; display:none; border:2px solid #fff; box-shadow:0 2px 6px rgba(0,0,0,0.1);">
                <div id="edit_foto_initials" class="avatar-table-initials" style="width:48px; height:48px; font-size:16px;">-</div>
                <div>
                    <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">PIN / User ID Mesin</div>
                    <div style="font-size:16px; font-weight:800; color:#0f172a;" id="edit_pin_display">-</div>
                </div>
            </div>

            <label for="edit_nama">Nama Lengkap &amp; Gelar:</label>
            <input type="text" id="edit_nama" name="edit_nama" placeholder="Nama lengkap..." required>

            <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px;">
                <div>
                    <label for="edit_departemen">Departemen / Bidang:</label>
                    <input type="text" id="edit_departemen" name="edit_departemen" placeholder="Departemen...">
                </div>
                <div>
                    <label for="edit_tipe">Kategori Tipe:</label>
                    <select id="edit_tipe" name="edit_tipe">
                        <option value="karyawan">Tenaga Kependidikan</option>
                        <option value="guru">Guru / Pendidik</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="edit_foto_karyawan">Ganti Foto Profil Master:</label>
                <input type="file" id="edit_foto_karyawan" name="edit_foto_karyawan" accept="image/*" style="font-size:12px; margin-bottom:4px;">
                <div style="font-size:11px; color:#64748b; margin-bottom:20px;">Kosongkan jika tidak ingin mengubah foto.</div>
            </div>

            <div style="display:flex; gap:10px; margin-top:8px;">
                <button type="button" onclick="tutupModalEditKaryawan()" class="btn" style="flex:1; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; font-weight:700;">Batal</button>
                <button type="submit" class="btn btn-primary" style="flex:2; font-weight:800; box-shadow:0 4px 12px rgba(37,99,235,0.25);">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes slideUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<script>
// --- MODAL EDIT ---
function bukaModalEditKaryawan(pin, nama, dept, tipe, fotoUrl) {
    document.getElementById('edit_pin').value = pin;
    document.getElementById('edit_pin_display').textContent = pin;
    document.getElementById('edit_nama').value = nama;
    document.getElementById('edit_departemen').value = dept;
    document.getElementById('edit_tipe').value = tipe;

    const imgPreview = document.getElementById('edit_foto_preview');
    const initPreview = document.getElementById('edit_foto_initials');
    if (fotoUrl) {
        imgPreview.src = fotoUrl;
        imgPreview.style.display = 'block';
        initPreview.style.display = 'none';
    } else {
        imgPreview.style.display = 'none';
        initPreview.style.display = 'flex';
        initPreview.textContent = (nama.charAt(0) || '-').toUpperCase();
    }

    const modal = document.getElementById('modal-edit-karyawan');
    modal.style.display = 'flex';
    setTimeout(() => document.getElementById('edit_nama').focus(), 100);
}

function tutupModalEditKaryawan() {
    document.getElementById('modal-edit-karyawan').style.display = 'none';
}

// --- BULK SELECTION (CHECKBOX) ---
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.chk-item');
    checkboxes.forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') {
            cb.checked = master.checked;
        }
    });
    updateBulkState();
}

function updateBulkState() {
    const checkboxes = document.querySelectorAll('.chk-item:checked');
    const count = checkboxes.length;
    const btnBulk = document.getElementById('btn-bulk-delete');
    const countSpan = document.getElementById('count-selected');
    const master = document.getElementById('check-all');
    const visibleCheckboxes = document.querySelectorAll('.master-row:not([style*="display: none"]) .chk-item');

    countSpan.textContent = count;
    btnBulk.style.display = (count > 0) ? 'inline-flex' : 'none';

    if (master && visibleCheckboxes.length > 0) {
        let allVisibleChecked = true;
        visibleCheckboxes.forEach(cb => {
            if (!cb.checked) allVisibleChecked = false;
        });
        master.checked = allVisibleChecked;
    }
}

// --- REAL-TIME INSTANT SEARCH ---
const inputQMaster = document.getElementById('q_master');
const countVisibleSpan = document.getElementById('count-visible');
const countVisibleBadge = document.getElementById('count-visible-badge');
const rowNoMatch = document.getElementById('row-no-match');

function filterMasterTable() {
    const val = inputQMaster.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.master-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const pin  = row.getAttribute('data-pin') || '';
        const nama = row.getAttribute('data-nama') || '';
        const dept = row.getAttribute('data-dept') || '';

        if (!val || pin.includes(val) || nama.includes(val) || dept.includes(val)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
            const chk = row.querySelector('.chk-item');
            if (chk) chk.checked = false;
        }
    });

    if (countVisibleSpan) countVisibleSpan.textContent = visibleCount;
    if (countVisibleBadge) countVisibleBadge.textContent = visibleCount + ' Pegawai';
    if (rowNoMatch) {
        rowNoMatch.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
    }
    updateBulkState();
}

inputQMaster.addEventListener('input', filterMasterTable);

// Filter awal jika ada query di URL
if (inputQMaster.value) {
    filterMasterTable();
}
</script>

<?php render_footer(); ?>