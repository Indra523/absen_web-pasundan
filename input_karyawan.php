<?php
// ============================================================
// HALAMAN MANAJEMEN GURU & KARYAWAN (Dengan Sorting & Search)
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
    fputcsv($output, ['No', 'PIN', 'Nama', 'Departemen', 'Tipe (karyawan/guru)']);
    fputcsv($output, ['1', '88', 'Drs. H. Ahmad Ridwan, M.Pd.', 'Guru Pengajar', 'guru']);
    fputcsv($output, ['2', '89', 'Budi Santoso, S.ST.', 'Staff TU / Laboratorium', 'karyawan']);
    fputcsv($output, ['3', '90', 'Citra Dewi, S.Pd.', 'Guru Pengajar', 'guru']);
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

    if (!empty($pin) && !empty($nama)) {
        $stmt = $conn->prepare("INSERT INTO master_karyawan (pin, nama, departemen, tipe) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama = ?, departemen = ?, tipe = ?");
        $stmt->bind_param("sssssss", $pin, $nama, $departemen, $tipe, $nama, $departemen, $tipe);
        
        if ($stmt->execute()) {
            $pesan = "<div style='background:#d4edda; color:#155724; padding:12px 16px; border-radius:10px; border:1px solid #c3e6cb; margin-bottom:15px;'><b>✅ Berhasil!</b> Data Guru/Karyawan dengan PIN <b>" . h($pin) . "</b> berhasil disimpan.</div>";
        } else {
            $pesan = "<div style='background:#f8d7da; color:#721c24; padding:12px 16px; border-radius:10px; border:1px solid #f5c6cb; margin-bottom:15px;'><b>Gagal:</b> " . h($conn->error) . "</div>";
        }
    } else {
        $pesan = "<div style='background:#fff3cd; color:#856404; padding:12px 16px; border-radius:10px; border:1px solid #ffeeba; margin-bottom:15px;'>PIN dan Nama wajib diisi!</div>";
    }
}

// --- 3. PROSES EDIT SINGLE KARYAWAN ---
if (isset($_POST['update_karyawan'])) {
    csrf_verify();

    $pin        = trim($_POST['edit_pin'] ?? '');
    $nama       = trim($_POST['edit_nama'] ?? '');
    $departemen = trim($_POST['edit_departemen'] ?? '');
    $tipe       = in_array($_POST['edit_tipe'] ?? '', ['karyawan', 'guru']) ? $_POST['edit_tipe'] : 'karyawan';

    if (!empty($pin) && !empty($nama)) {
        $stmt = $conn->prepare("UPDATE master_karyawan SET nama = ?, departemen = ?, tipe = ? WHERE pin = ?");
        $stmt->bind_param("ssss", $nama, $departemen, $tipe, $pin);
        
        if ($stmt->execute()) {
            $pesan = "<div style='background:#d4edda; color:#155724; padding:12px 16px; border-radius:10px; border:1px solid #c3e6cb; margin-bottom:15px;'><b>✅ Berhasil memperbarui!</b> Data Guru/Karyawan (PIN: <b>" . h($pin) . "</b>) telah diperbarui.</div>";
        } else {
            $pesan = "<div style='background:#f8d7da; color:#721c24; padding:12px 16px; border-radius:10px; border:1px solid #f5c6cb; margin-bottom:15px;'><b>Gagal update:</b> " . h($conn->error) . "</div>";
        }
    } else {
        $pesan = "<div style='background:#fff3cd; color:#856404; padding:12px 16px; border-radius:10px; border:1px solid #ffeeba; margin-bottom:15px;'>Nama tidak boleh kosong!</div>";
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
        $pesan = "<div style='background:#d4edda; color:#155724; padding:12px 16px; border-radius:10px; border:1px solid #c3e6cb; margin-bottom:15px;'><b>✅ Hapus Massal Berhasil!</b> <b>{$total_deleted}</b> data guru & karyawan telah dihapus.</div>";
    } else {
        $pesan = "<div style='background:#fff3cd; color:#856404; padding:12px 16px; border-radius:10px; border:1px solid #ffeeba; margin-bottom:15px;'>Pilih minimal satu data yang ingin dihapus!</div>";
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
                $pesan = "<p style='color: red;'><b>Gagal membaca Excel:</b> " . h(SimpleXLSX::parseError()) . "</p>";
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
            $pesan = "<p style='color: red;'>Format file tidak didukung! Harap unggah file <b>.xlsx</b> atau <b>.csv</b>.</p>";
        }

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

// --- 6. PROSES HAPUS SINGLE KARYAWAN ---
if (isset($_GET['hapus'])) {
    $pin_hapus = $_GET['hapus'];
    $stmt = $conn->prepare("DELETE FROM master_karyawan WHERE pin = ?");
    $stmt->bind_param("s", $pin_hapus);
    if ($stmt->execute()) {
        $pesan = "<div style='background:#d4edda; color:#155724; padding:12px 16px; border-radius:10px; border:1px solid #c3e6cb; margin-bottom:15px;'><b>Berhasil!</b> Data dengan PIN <b>" . h($pin_hapus) . "</b> telah dihapus.</div>";
    }
}

// --- 7. SORTING & SERCHING MASTER KARYAWAN ---
$sort = $_GET['sort'] ?? 'pin_asc';
$q_master = trim($_GET['q_master'] ?? '');

$order_by = "CAST(pin AS UNSIGNED) ASC, pin ASC"; // Default: Sorting Numeric PIN 1, 2, 3...
switch ($sort) {
    case 'pin_desc':
        $order_by = "CAST(pin AS UNSIGNED) DESC, pin DESC";
        break;
    case 'nama_asc':
        $order_by = "nama ASC";
        break;
    case 'nama_desc':
        $order_by = "nama DESC";
        break;
    case 'dept_asc':
        $order_by = "departemen ASC, CAST(pin AS UNSIGNED) ASC";
        break;
    case 'tipe_asc':
        $order_by = "tipe ASC, CAST(pin AS UNSIGNED) ASC";
        break;
    case 'tipe_desc':
        $order_by = "tipe DESC, CAST(pin AS UNSIGNED) ASC";
        break;
    default:
        $sort = 'pin_asc';
        $order_by = "CAST(pin AS UNSIGNED) ASC, pin ASC";
        break;
}

$where_master = "";
$params_master = [];
$types_master = "";

if (!empty($q_master)) {
    $where_master = "WHERE (pin LIKE ? OR nama LIKE ? OR departemen LIKE ?)";
    $param_q = "%" . $q_master . "%";
    $params_master = [$param_q, $param_q, $param_q];
    $types_master = "sss";
}

$sql_master = "SELECT * FROM master_karyawan {$where_master} ORDER BY {$order_by}";

if (!empty($params_master)) {
    $stmt_m = $conn->prepare($sql_master);
    $stmt_m->bind_param($types_master, ...$params_master);
    $stmt_m->execute();
    $result = $stmt_m->get_result();
} else {
    $result = $conn->query($sql_master);
}

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

<!-- CARD 3: TABEL DAFTAR KARYAWAN + SORTING + BULK DELETE -->
<div class="card">
    <!-- PANEL FILTER & SORTING -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #f1f5f9;">
        <div class="card-title" style="margin-bottom:0;">
            <span>📋 Master Data Guru & Karyawan Terdaftar</span>
        </div>

        <form method="GET" action="input_karyawan.php" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;">
            <!-- Input Pencarian -->
            <input type="text" name="q_master" value="<?php echo h($q_master); ?>" placeholder="🔍 Cari PIN, Nama, Dept..." style="margin-bottom:0; height:38px; width:210px; font-size:13px;" autocomplete="off">
            
            <!-- Dropdown Sorting -->
            <select name="sort" onchange="this.form.submit()" style="margin-bottom:0; height:38px; font-size:13px; padding:6px 12px; width:auto; cursor:pointer;">
                <option value="pin_asc" <?php echo $sort === 'pin_asc' ? 'selected' : ''; ?>>🔢 Urut PIN (1 ➔ 99)</option>
                <option value="pin_desc" <?php echo $sort === 'pin_desc' ? 'selected' : ''; ?>>🔢 Urut PIN (99 ➔ 1)</option>
                <option value="nama_asc" <?php echo $sort === 'nama_asc' ? 'selected' : ''; ?>>🔤 Nama (A ➔ Z)</option>
                <option value="nama_desc" <?php echo $sort === 'nama_desc' ? 'selected' : ''; ?>>🔤 Nama (Z ➔ A)</option>
                <option value="tipe_desc" <?php echo $sort === 'tipe_desc' ? 'selected' : ''; ?>>👨‍🏫 Tipe (Guru Dulu)</option>
                <option value="tipe_asc" <?php echo $sort === 'tipe_asc' ? 'selected' : ''; ?>>👔 Tipe (Karyawan Dulu)</option>
                <option value="dept_asc" <?php echo $sort === 'dept_asc' ? 'selected' : ''; ?>>🏢 Departemen</option>
            </select>

            <?php if (!empty($q_master) || $sort !== 'pin_asc'): ?>
            <a href="input_karyawan.php" style="font-size:12px; color:#ef4444; font-weight:600; text-decoration:none; padding:6px;">✕ Reset Filter</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ACTION HEADER: TOTAL BADGE & BULK DELETE BUTTON -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
        <div style="font-size:13px; color:#64748b;">
            Menampilkan <b><?php echo $result->num_rows; ?></b> data guru & karyawan
        </div>

        <form method="POST" action="input_karyawan.php" id="form-bulk" style="margin:0;">
            <?php echo csrf_field(); ?>
            <button type="submit" name="bulk_delete" id="btn-bulk-delete" class="btn" 
                    style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; font-size:13px; padding:7px 14px; display:none;"
                    onclick="return confirm('Yakin ingin menghapus semua data yang dicentang?')">
                🗑️ Hapus Terpilih (<span id="count-selected">0</span>)
            </button>
    </div>

    <?php
    // Helper URL untuk Header Sorting Clickable
    function sort_url($col_name, $current_sort) {
        $next_sort = ($current_sort === "{$col_name}_asc") ? "{$col_name}_desc" : "{$col_name}_asc";
        $q = isset($_GET['q_master']) ? '&q_master=' . urlencode($_GET['q_master']) : '';
        return "input_karyawan.php?sort={$next_sort}{$q}";
    }

    function sort_icon($col_name, $current_sort) {
        if ($current_sort === "{$col_name}_asc") return " 🔼";
        if ($current_sort === "{$col_name}_desc") return " 🔽";
        return " <span style='color:#cbd5e1;'>↕</span>";
    }
    ?>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width:40px; text-align:center;">
                        <input type="checkbox" id="check-all" onclick="toggleSelectAll(this)" style="width:18px; height:18px; cursor:pointer;" title="Pilih Semua">
                    </th>
                    <th>
                        <a href="<?php echo sort_url('pin', $sort); ?>" style="color:inherit; text-decoration:none;" title="Klik untuk mengurutkan PIN">
                            PIN / ID<?php echo sort_icon('pin', $sort); ?>
                        </a>
                    </th>
                    <th style="text-align:left;">
                        <a href="<?php echo sort_url('nama', $sort); ?>" style="color:inherit; text-decoration:none;" title="Klik untuk mengurutkan Nama">
                            Nama Lengkap<?php echo sort_icon('nama', $sort); ?>
                        </a>
                    </th>
                    <th style="text-align:left;">
                        <a href="<?php echo sort_url('dept', $sort); ?>" style="color:inherit; text-decoration:none;" title="Klik untuk mengurutkan Departemen">
                            Departemen / Jabatan<?php echo sort_icon('dept', $sort); ?>
                        </a>
                    </th>
                    <th>
                        <a href="<?php echo sort_url('tipe', $sort); ?>" style="color:inherit; text-decoration:none;" title="Klik untuk mengurutkan Tipe Kategori">
                            Tipe Kategori<?php echo sort_icon('tipe', $sort); ?>
                        </a>
                    </th>
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

                        $pin_attr  = h($row['pin']);
                        $nama_attr = h($row['nama']);
                        $dept_attr = h($row['departemen']);
                        $tipe_attr = h($row['tipe']);

                        echo "<tr>
                                <td style='text-align:center;'>
                                    <input type='checkbox' name='pin_selected[]' value='{$pin_attr}' form='form-bulk' class='chk-item' onchange='updateBulkState()' style='width:18px; height:18px; cursor:pointer;'>
                                </td>
                                <td><code style='background:#f1f5f9; padding:4px 10px; border-radius:6px; font-weight:700; color:#0f172a;'>{$pin_attr}</code></td>
                                <td style='text-align:left;'><b>{$nama_attr}</b></td>
                                <td style='text-align:left; color:#64748b;'>{$dept_attr}</td>
                                <td>{$badge_tipe}</td>
                                <td>
                                    <div style='display:flex; gap:6px; justify-content:center;'>
                                        <button type='button' class='btn' style='background:#f1f5f9; color:#334155; font-size:12px; padding:6px 12px; border:1px solid #cbd5e1;'
                                                onclick='bukaModalEditKaryawan(\"{$pin_attr}\", \"{$nama_attr}\", \"{$dept_attr}\", \"{$tipe_attr}\")'>
                                            ✏️ Edit
                                        </button>
                                        <a class='btn' style='background:#fee2e2; color:#dc2626; font-size:12px; padding:6px 12px; border:1px solid #fca5a5; text-decoration:none;' 
                                           href='input_karyawan.php?hapus=" . urlencode($row['pin']) . "' 
                                           onclick=\"return confirm('Yakin ingin menghapus data {$nama_attr}?')\">
                                            🗑️ Hapus
                                        </a>
                                    </div>
                                </td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' style='padding: 30px; color:#94a3b8;'>Data tidak ditemukan" . (!empty($q_master) ? " untuk kata kunci '" . h($q_master) . "'" : "") . ".</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    </form>
</div>

<!-- ================= MODAL EDIT KARYAWAN ================= -->
<div id="modal-edit-karyawan" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:20px; padding:32px; width:100%; max-width:460px; box-shadow:0 25px 60px rgba(0,0,0,0.25); animation:slideUp 0.25s ease;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="font-size:18px; font-weight:800; color:#0f172a;">✏️ Edit Data Guru & Karyawan</h3>
            <button type="button" onclick="tutupModalEditKaryawan()" style="background:#f1f5f9; border:none; border-radius:8px; padding:8px 12px; cursor:pointer; font-size:16px; color:#64748b;">✕</button>
        </div>

        <form method="POST" action="input_karyawan.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="update_karyawan" value="1">
            <input type="hidden" name="edit_pin" id="edit_pin">

            <div style="background:#f8fafc; border-radius:12px; padding:12px 16px; margin-bottom:18px; border:1px solid #e2e8f0;">
                <div style="font-size:12px; color:#64748b; font-weight:600;">PIN / User ID Mesin:</div>
                <div style="font-size:16px; font-weight:800; color:#0f172a; margin-top:2px;" id="edit_pin_display">-</div>
            </div>

            <label for="edit_nama">Nama Lengkap (Gelar):</label>
            <input type="text" id="edit_nama" name="edit_nama" placeholder="Nama lengkap..." required>

            <label for="edit_departemen">Departemen / Jabatan:</label>
            <input type="text" id="edit_departemen" name="edit_departemen" placeholder="Departemen...">

            <label for="edit_tipe">Tipe Kategori:</label>
            <select id="edit_tipe" name="edit_tipe" style="margin-bottom:24px;">
                <option value="karyawan">👔 Karyawan / Staff (Hari kerja kalender)</option>
                <option value="guru">👨‍🏫 Guru Pengajar (Sesuai jadwal ngajar)</option>
            </select>

            <div style="display:flex; gap:12px;">
                <button type="button" onclick="tutupModalEditKaryawan()" class="btn" style="flex:1; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0;">Batal</button>
                <button type="submit" class="btn btn-primary" style="flex:2;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<script>
// --- MODAL EDIT ---
function bukaModalEditKaryawan(pin, nama, dept, tipe) {
    document.getElementById('edit_pin').value = pin;
    document.getElementById('edit_pin_display').textContent = pin;
    document.getElementById('edit_nama').value = nama;
    document.getElementById('edit_departemen').value = dept;
    document.getElementById('edit_tipe').value = tipe;

    const modal = document.getElementById('modal-edit-karyawan');
    modal.style.display = 'flex';
    setTimeout(() => document.getElementById('edit_nama').focus(), 100);
}

function tutupModalEditKaryawan() {
    document.getElementById('modal-edit-karyawan').style.display = 'none';
}

document.getElementById('modal-edit-karyawan').addEventListener('click', function(e) {
    if (e.target === this) tutupModalEditKaryawan();
});

// --- BULK SELECTION (CHECKBOX) ---
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.chk-item');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateBulkState();
}

function updateBulkState() {
    const checkboxes = document.querySelectorAll('.chk-item:checked');
    const count = checkboxes.length;
    const btnBulk = document.getElementById('btn-bulk-delete');
    const countSpan = document.getElementById('count-selected');
    const master = document.getElementById('check-all');
    const totalItems = document.querySelectorAll('.chk-item').length;

    countSpan.textContent = count;

    if (count > 0) {
        btnBulk.style.display = 'inline-flex';
    } else {
        btnBulk.style.display = 'none';
    }

    if (master) {
        master.checked = (count === totalItems && totalItems > 0);
    }
}
</script>

<?php render_footer(); ?>