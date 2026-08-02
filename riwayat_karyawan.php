<?php
// ============================================================
// HALAMAN REKAP RIWAYAT ABSENSI INDIVIDUAL GURU & KARYAWAN
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('riwayat')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$pesan_sukses = "";
$pesan_error  = "";

// PROSES HAPUS LOG ABSEN (Superadmin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus_log_absen') {
    csrf_verify();
    if (!is_superadmin()) {
        $pesan_error = "Akses Ditolak: Hanya Superadmin yang berhak menghapus log absen.";
    } else {
        $id_hapus = (int)($_POST['id_log_hapus'] ?? 0);
        if ($id_hapus > 0) {
            $stmt_del = $conn->prepare("DELETE FROM log_absen WHERE id = ?");
            $stmt_del->bind_param("i", $id_hapus);
            if ($stmt_del->execute()) {
                $pesan_sukses = "Log absen berhasil dihapus.";
            } else {
                $pesan_error = "Gagal menghapus log absen: " . h($conn->error);
            }
        }
    }
}

// PROSES TUKAR STATUS LOG ABSEN (Superadmin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tukar_status_log') {
    csrf_verify();
    if (!is_superadmin()) {
        $pesan_error = "Akses Ditolak: Hanya Superadmin yang berhak mengubah status log absen.";
    } else {
        $id_toggle = (int)($_POST['id_log_toggle'] ?? 0);
        if ($id_toggle > 0) {
            $stmt_cur = $conn->prepare("SELECT status FROM log_absen WHERE id = ?");
            $stmt_cur->bind_param("i", $id_toggle);
            $stmt_cur->execute();
            $res_cur = $stmt_cur->get_result()->fetch_assoc();

            if ($res_cur) {
                $status_baru = ($res_cur['status'] === '0') ? '1' : '0';
                $status_label = ($status_baru === '0') ? 'Masuk' : 'Pulang';

                $stmt_upd = $conn->prepare("UPDATE log_absen SET status = ? WHERE id = ?");
                $stmt_upd->bind_param("si", $status_baru, $id_toggle);
                if ($stmt_upd->execute()) {
                    $pesan_sukses = "Status log absen berhasil ditukar menjadi {$status_label}.";
                }
            }
        }
    }
}

// Parameter PIN, Range Tanggal, Sorting, & Tab Aktif
$pin_selected = trim($_GET['pin'] ?? $_POST['pin_selected'] ?? '');
$tgl_mulai    = trim($_GET['tgl_mulai'] ?? '');
$tgl_selesai  = trim($_GET['tgl_selesai'] ?? '');
$sort_order   = $_GET['sort'] ?? 'desc';
$export       = isset($_GET['export']) && $_GET['export'] === '1';
$active_tab   = $_GET['tab'] ?? 'riwayat'; // 'riwayat' atau 'profil'

// Ambil semua daftar karyawan untuk dropdown pencarian
$sql_all = "SELECT pin, nama, departemen, tipe FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC";
$res_all = $conn->query($sql_all);
$karyawan_list = [];
if ($res_all && $res_all->num_rows > 0) {
    while ($r = $res_all->fetch_assoc()) {
        $karyawan_list[] = $r;
    }
}

// Jika PIN belum dipilih tapi ada data, default pilih PIN pertama
if (empty($pin_selected) && !empty($karyawan_list)) {
    $pin_selected = $karyawan_list[0]['pin'];
}

// Ambil data detail karyawan terpilih
$detail_user = null;
if (!empty($pin_selected)) {
    $stmt_u = $conn->prepare("SELECT * FROM master_karyawan WHERE pin = ?");
    $stmt_u->bind_param("s", $pin_selected);
    $stmt_u->execute();
    $detail_user = $stmt_u->get_result()->fetch_assoc();
}

// Ambil jadwal ngajar guru ini (jika tipe = guru)
$hari_ngajar_arr = [];
if ($detail_user && $detail_user['tipe'] === 'guru') {
    $stmt_j = $conn->prepare("SELECT hari FROM jadwal_guru WHERE pin = ?");
    $stmt_j->bind_param("s", $pin_selected);
    $stmt_j->execute();
    $res_j = $stmt_j->get_result();
    while ($rj = $res_j->fetch_assoc()) {
        $hari_ngajar_arr[] = (int)$rj['hari'];
    }
}

// Query Log Absensi Karyawan Terpilih
$logs = [];
$total_masuk  = 0;
$total_pulang = 0;
$absen_pertama = "-";
$absen_terakhir = "-";

if (!empty($pin_selected)) {
    $where = ["la.pin = ?"];
    $params = [$pin_selected];
    $types = "s";

    if (!empty($tgl_mulai)) {
        $where[] = "la.waktu >= ?";
        $params[] = $tgl_mulai . " 00:00:00";
        $types .= "s";
    }

    if (!empty($tgl_selesai)) {
        $where[] = "la.waktu <= ?";
        $params[] = $tgl_selesai . " 23:59:59";
        $types .= "s";
    }

    $where_sql = "WHERE " . implode(" AND ", $where);
    $order_sql = ($sort_order === 'asc') ? "ASC" : "DESC";

    $sql_log = "SELECT la.*, 
                       (MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1) AS hari_num,
                       DATE(la.waktu) AS tgl_only,
                       TIME(la.waktu) AS jam_only
                FROM log_absen la
                {$where_sql}
                ORDER BY la.waktu {$order_sql}";

    $stmt_l = $conn->prepare($sql_log);
    $stmt_l->bind_param($types, ...$params);
    $stmt_l->execute();
    $res_l = $stmt_l->get_result();

    if ($res_l && $res_l->num_rows > 0) {
        while ($l = $res_l->fetch_assoc()) {
            if ($l['status'] == '0') $total_masuk++;
            if ($l['status'] == '1') $total_pulang++;
            $logs[] = $l;
        }

        // Cari waktu pertama & terakhir absen di database
        $stmt_stat = $conn->prepare("SELECT MIN(waktu) AS tgl_min, MAX(waktu) AS tgl_max FROM log_absen WHERE pin = ?");
        $stmt_stat->bind_param("s", $pin_selected);
        $stmt_stat->execute();
        $res_stat = $stmt_stat->get_result()->fetch_assoc();

        if ($res_stat) {
            $absen_pertama  = !empty($res_stat['tgl_min']) ? date('d/m/Y H:i:s', strtotime($res_stat['tgl_min'])) : '-';
            $absen_terakhir = !empty($res_stat['tgl_max']) ? date('d/m/Y H:i:s', strtotime($res_stat['tgl_max'])) : '-';
        }
    }
}

$nama_hari_indo = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu'
];

// EXPORT EXCEL INDIVIDUAL
if ($export && $detail_user) {
    $filename = "Riwayat_Absen_" . preg_replace('/[^a-zA-Z0-9]/', '_', $detail_user['nama']) . "_{$pin_selected}.xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename={$filename}");
    header("Pragma: no-cache");
    header("Expires: 0");
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: Arial, sans-serif; font-size: 10pt; }
            .header-title { font-size: 14pt; font-weight: bold; text-align: center; margin-bottom: 4px; }
            .header-sub { font-size: 11pt; text-align: center; margin-bottom: 15px; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
            th { background-color: #0f172a; color: #ffffff; font-weight: bold; padding: 8px; border: 1px solid #000; text-align: center; }
            td { padding: 6px 8px; border: 1px solid #ccc; text-align: center; vertical-align: middle; }
            .text-left { text-align: left; }
        </style>
    </head>
    <body>
        <div class="header-title">REKAP RIWAYAT ABSENSI INDIVIDUAL</div>
        <div class="header-sub">
            <b>PIN:</b> <?php echo h($detail_user['pin']); ?> | 
            <b>Nama:</b> <?php echo h($detail_user['nama']); ?> | 
            <b>Departemen:</b> <?php echo h($detail_user['departemen']); ?> | 
            <b>Tipe:</b> <?php echo ucfirst($detail_user['tipe']); ?>
        </div>
        <div style="margin-bottom:12px; font-size:10pt;">
            <b>Total Log:</b> <?php echo count($logs); ?> Record (Masuk: <?php echo $total_masuk; ?>, Pulang: <?php echo $total_pulang; ?>) | 
            <b>Absen Pertama:</b> <?php echo $absen_pertama; ?> | 
            <b>Absen Terakhir:</b> <?php echo $absen_terakhir; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal & Hari</th>
                    <th>Waktu Absen</th>
                    <th>Status Absensi</th>
                    <th>Keterangan Jadwal</th>
                    <th>Tipe Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                foreach ($logs as $l) {
                    $hn = (int)$l['hari_num'];
                    $h_nama = $nama_hari_indo[$hn] ?? '';
                    $tgl_fmt = $h_nama . ", " . date('d/m/Y', strtotime($l['waktu']));
                    $waktu_fmt = date('H:i:s', strtotime($l['waktu']));

                    $st_text = ($l['status'] == '0') ? "Masuk" : (($l['status'] == '1') ? "Pulang" : "Unknown");

                    $ket_jadwal = "";
                    if ($detail_user['tipe'] === 'guru') {
                        if (empty($hari_ngajar_arr)) {
                            $ket_jadwal = "Belum Ada Jadwal";
                        } elseif (in_array($hn, $hari_ngajar_arr)) {
                            $ket_jadwal = "Sesuai Jadwal";
                        } else {
                            $ket_jadwal = "Di Luar Jadwal";
                        }
                    } else {
                        $ket_jadwal = ($hn == 7) ? "Di Luar Hari Kerja" : "Hari Kerja Kalender";
                    }

                    $verif = "Lainnya";
                    if ($l['tipe_verifikasi'] == '1') $verif = "Sidik Jari";
                    elseif ($l['tipe_verifikasi'] == '15') $verif = "Wajah";

                    echo "<tr>
                            <td>{$no}</td>
                            <td>{$tgl_fmt}</td>
                            <td><b>{$waktu_fmt}</b></td>
                            <td>{$st_text}</td>
                            <td>{$ket_jadwal}</td>
                            <td>{$verif}</td>
                          </tr>";
                    $no++;
                }
                ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

render_header("Riwayat Absensi Individual", "riwayat");
?>

<?php if ($pesan_sukses): ?>
<div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; color: #065f46; font-size: 14px; font-weight: 500;">
    <?php echo $pesan_sukses; ?>
</div>
<?php endif; ?>

<?php if ($pesan_error): ?>
<div style="background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #f87171; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; color: #991b1b; font-size: 14px; font-weight: 500;">
    <?php echo $pesan_error; ?>
</div>
<?php endif; ?>

<!-- PANEL SEARCH & FILTER KARYAWAN -->
<div class="card" style="margin-bottom:20px; padding:20px;">
    <form method="GET" action="riwayat_karyawan.php" style="margin:0;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:16px;">
            <!-- SELECT / SEARCH GURU & KARYAWAN AUTOCOMPLETE -->
            <div style="grid-column: span 2;">
                <label for="input-search-emp" style="font-weight:700; color:#0f172a;">🔍 <?php echo is_tatausaha() ? 'Pilih / Ketik Nama Karyawan:' : 'Pilih / Ketik Nama Guru & Karyawan:'; ?></label>
                <div style="position:relative;">
                    <input type="hidden" name="pin" id="selected-pin" value="<?php echo h($pin_selected); ?>">
                    <input type="text" id="input-search-emp" class="searchable-input" 
                           value="<?php 
                           if ($detail_user) {
                               echo "[" . h($detail_user['pin']) . "] " . h($detail_user['nama']) . ($detail_user['departemen'] ? " — " . h($detail_user['departemen']) : "");
                           }
                           ?>" 
                           placeholder="🔍 Ketik PIN, Nama, atau Departemen..." autocomplete="off">
                    
                    <div class="searchable-dropdown-list" id="dropdown-emp-list" style="position:absolute; top:100%; left:0; right:0; max-height:240px; overflow-y:auto; background:#fff; border:1.5px solid #cbd5e1; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.15); z-index:99; display:none;">
                        <?php foreach ($karyawan_list as $k): 
                            $label_k = "[" . h($k['pin']) . "] " . h($k['nama']) . ($k['departemen'] ? " — " . h($k['departemen']) : "");
                        ?>
                            <div class="searchable-item" 
                                 style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:13.5px;"
                                 data-pin="<?php echo h($k['pin']); ?>" 
                                 data-text="<?php echo h(strtolower($k['pin'] . ' ' . $k['nama'] . ' ' . $k['departemen'])); ?>"
                                 onclick="selectEmployee('<?php echo h($k['pin']); ?>', '<?php echo h($label_k); ?>')">
                                <b>[<?php echo h($k['pin']); ?>]</b> <?php echo h($k['nama']); ?>
                                <span style="font-size:11.5px; color:#64748b; display:block;"><?php echo h($k['departemen'] ?: '-'); ?> (<?php echo ucfirst($k['tipe']); ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- FILTER DATE RANGE -->
            <div>
                <label for="tgl_mulai">Dari Tanggal:</label>
                <input type="date" id="tgl_mulai" name="tgl_mulai" value="<?php echo h($tgl_mulai); ?>" style="margin-bottom:0;">
            </div>

            <div>
                <label for="tgl_selesai">Sampai Tanggal:</label>
                <input type="date" id="tgl_selesai" name="tgl_selesai" value="<?php echo h($tgl_selesai); ?>" style="margin-bottom:0;">
            </div>

            <!-- SORT ORDER -->
            <div>
                <label for="sort">Urutkan Waktu:</label>
                <select name="sort" id="sort" style="margin-bottom:0;">
                    <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>⏰ Terbaru ➔ Pertamakan (Terbaru Dulu)</option>
                    <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>⏳ Pertama ➔ Terkini (Awal Terlebih Dulu)</option>
                </select>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-top:14px; border-top:1px solid #f1f5f9;">
            <div style="font-size:12px; color:#64748b;">
                💡 <b>Petunjuk:</b> Ketik nama/PIN untuk mencari karyawan. Pilih rentang tanggal jika ingin memfilter data.
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">🔍 Tampilkan Riwayat</button>
                <?php if ($detail_user): ?>
                <?php if (can_access_rnd()): ?>
                <a href="<?php echo 'export_pdf_riwayat.php?' . http_build_query(['pin' => $pin_selected, 'tgl_dari' => $tgl_mulai, 'tgl_sampai' => $tgl_selesai, 'auto_print' => 1]); ?>" target="_blank" class="btn" style="background:#ef4444; color:#fff; font-weight:600; text-decoration:none;">
                    📄 Export PDF Official
                </a>
                <?php endif; ?>
                <a href="<?php echo 'riwayat_karyawan.php?' . http_build_query(['pin' => $pin_selected, 'tgl_mulai' => $tgl_mulai, 'tgl_selesai' => $tgl_selesai, 'sort' => $sort_order, 'export' => 1]); ?>" class="btn btn-success">
                    📊 Export ke Excel
                </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script>
const inputEmp = document.getElementById('input-search-emp');
const dropdownEmp = document.getElementById('dropdown-emp-list');
const selectedPin = document.getElementById('selected-pin');
const itemsEmp = document.querySelectorAll('.searchable-item');

if (inputEmp && dropdownEmp) {
    inputEmp.addEventListener('focus', () => { dropdownEmp.style.display = 'block'; });

    inputEmp.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        dropdownEmp.style.display = 'block';
        
        itemsEmp.forEach(item => {
            const text = item.getAttribute('data-text');
            if (text.includes(q)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#input-search-emp') && !e.target.closest('#dropdown-emp-list')) {
            dropdownEmp.style.display = 'none';
        }
    });
}

function selectEmployee(pin, label) {
    selectedPin.value = pin;
    inputEmp.value = label;
    dropdownEmp.style.display = 'none';
}
</script>

<?php if ($detail_user): ?>

<?php
// Fetch data profil tambahan (foto, no_hp, ttl, dll)
$detail_profil = $detail_user; // sudah include semua kolom dari SELECT *
// Hitung usia
$usia_str = '';
if (!empty($detail_profil['tanggal_lahir'])) {
    $dob = new DateTime($detail_profil['tanggal_lahir']);
    $usia_str = $dob->diff(new DateTime())->y . ' Tahun';
}
?>

<!-- HERO HEADER CARD KARYAWAN -->
<div style="background:linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #1e293b 100%); border-radius:20px; padding:28px 32px; margin-bottom:20px; position:relative; overflow:hidden; color:#fff;">
    <div style="position:absolute; top:-50px; right:-50px; width:200px; height:200px; background:rgba(59,130,246,0.1); border-radius:50%;"></div>
    <div style="position:absolute; bottom:-30px; left:25%; width:150px; height:150px; background:rgba(99,102,241,0.07); border-radius:50%;"></div>

    <div style="position:relative; z-index:1; display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
        <!-- Avatar -->
        <div style="position:relative; width:80px; height:80px; flex-shrink:0;">
            <?php if (!empty($detail_profil['foto']) && file_exists(__DIR__ . '/' . $detail_profil['foto'])): ?>
                <img src="<?php echo h($detail_profil['foto']); ?>" alt="Foto" style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid rgba(255,255,255,0.25);">
            <?php else: ?>
                <div style="width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,0.1); border:3px solid rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center; font-size:28px; font-weight:800; color:#fff;">
                    <?php echo strtoupper(mb_substr($detail_profil['nama'], 0, 1)); ?>
                </div>
            <?php endif; ?>
            <div style="position:absolute; bottom:2px; right:2px; width:18px; height:18px; background:#22c55e; border-radius:50%; border:2px solid #0f172a;"></div>
        </div>

        <!-- Identity -->
        <div style="flex:1; min-width:180px;">
            <div style="font-size:10px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px;">
                <?php echo $detail_user['tipe'] === 'guru' ? 'Guru / Pendidik' : 'Tenaga Kependidikan'; ?>
            </div>
            <h3 style="font-size:20px; font-weight:800; color:#fff; margin-bottom:6px; line-height:1.2;"><?php echo h($detail_profil['nama']); ?></h3>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <span style="background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.18); color:#e2e8f0; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;"><?php echo h($detail_profil['departemen'] ?: 'Umum'); ?></span>
                <span style="background:rgba(59,130,246,0.2); border:1px solid rgba(59,130,246,0.3); color:#93c5fd; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;">PIN: <?php echo h($pin_selected); ?></span>
                <?php if ($usia_str): ?>
                <span style="background:rgba(168,85,247,0.2); border:1px solid rgba(168,85,247,0.3); color:#c4b5fd; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600;"><?php echo $usia_str; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats -->
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:10px 16px; text-align:center;">
                <div style="font-size:20px; font-weight:800; color:#4ade80;"><?php echo count($logs); ?></div>
                <div style="font-size:10px; color:#94a3b8;">Total Log</div>
            </div>
            <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:10px 16px; text-align:center;">
                <div style="font-size:20px; font-weight:800; color:#60a5fa;"><?php echo $total_masuk; ?></div>
                <div style="font-size:10px; color:#94a3b8;">Masuk</div>
            </div>
            <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:10px 16px; text-align:center;">
                <div style="font-size:20px; font-weight:800; color:#f87171;"><?php echo $total_pulang; ?></div>
                <div style="font-size:10px; color:#94a3b8;">Pulang</div>
            </div>
        </div>
    </div>
</div>

<!-- TAB NAVIGATION -->
<?php $tab_url_base = 'riwayat_karyawan.php?' . http_build_query(['pin' => $pin_selected, 'tgl_mulai' => $tgl_mulai, 'tgl_selesai' => $tgl_selesai, 'sort' => $sort_order]); ?>
<div style="display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid #e2e8f0; padding-bottom:0;">
    <a href="<?php echo $tab_url_base . '&tab=riwayat'; ?>" style="padding:10px 20px; font-size:13.5px; font-weight:700; text-decoration:none; border-radius:10px 10px 0 0; border:1px solid <?php echo $active_tab !== 'profil' ? '#e2e8f0' : 'transparent'; ?>; border-bottom:none; background:<?php echo $active_tab !== 'profil' ? '#fff' : 'transparent'; ?>; color:<?php echo $active_tab !== 'profil' ? '#2563eb' : '#64748b'; ?>; margin-bottom:-2px;">
        Riwayat Absensi
    </a>
    <a href="<?php echo $tab_url_base . '&tab=profil'; ?>" style="padding:10px 20px; font-size:13.5px; font-weight:700; text-decoration:none; border-radius:10px 10px 0 0; border:1px solid <?php echo $active_tab === 'profil' ? '#e2e8f0' : 'transparent'; ?>; border-bottom:none; background:<?php echo $active_tab === 'profil' ? '#fff' : 'transparent'; ?>; color:<?php echo $active_tab === 'profil' ? '#2563eb' : '#64748b'; ?>; margin-bottom:-2px;">
        Profil Karyawan
    </a>
</div>

<?php if ($active_tab === 'profil'): ?>
<!-- TAB PROFIL -->
<div style="display:grid; grid-template-columns: 300px 1fr; gap:20px; align-items:start;">

    <!-- Info Panel -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(15,23,42,0.04);">
        <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:14px 20px; font-size:13px; font-weight:700; color:#0f172a;">Informasi Pegawai</div>
        <?php
        $rows_profil = [
            'PIN'          => '<code style="background:#f1f5f9; padding:2px 8px; border-radius:6px; font-weight:700;">' . h($detail_profil['pin']) . '</code>',
            'Nama Lengkap' => h($detail_profil['nama']),
            'Departemen'   => h($detail_profil['departemen'] ?: '-'),
            'Jabatan'      => $detail_profil['tipe'] === 'guru' ? '<span style="background:#eff6ff; color:#1d4ed8; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:700; border:1px solid #bfdbfe;">Guru / Pendidik</span>' : '<span style="background:#f1f5f9; color:#475569; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:700; border:1px solid #e2e8f0;">Tenaga Kependidikan</span>',
            'No. Telepon'  => h($detail_profil['no_hp'] ?: '-'),
            'TTL'          => h((!empty($detail_profil['tempat_lahir']) ? $detail_profil['tempat_lahir'] . ', ' : '') . (!empty($detail_profil['tanggal_lahir']) ? date('d F Y', strtotime($detail_profil['tanggal_lahir'])) : (!empty($detail_profil['tempat_lahir']) ? '' : '-'))),
            'Alamat'       => h($detail_profil['alamat'] ?: '-'),
        ];
        foreach ($rows_profil as $lbl => $val):
        ?>
        <div style="display:flex; padding:12px 20px; border-bottom:1px solid #f1f5f9; font-size:13px; gap:12px;">
            <span style="width:110px; flex-shrink:0; color:#64748b; font-weight:500;"><?php echo $lbl; ?></span>
            <span style="color:#0f172a; font-weight:600; flex:1; line-height:1.5;"><?php echo $val; ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Form Edit Profil (Superadmin bisa edit, user bisa di user_profile.php) -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(15,23,42,0.04);">
        <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:14px 20px; font-size:13px; font-weight:700; color:#0f172a; display:flex; justify-content:space-between; align-items:center;">
            <span>Edit Data Diri</span>
            <?php if (is_superadmin() || is_rnd()): ?>
            <span style="font-size:11px; color:#3b82f6; font-weight:500;">Superadmin Access</span>
            <?php endif; ?>
        </div>
        <form method="POST" action="user_profile.php?pin=<?php echo urlencode($pin_selected); ?>&tab_redirect=riwayat_karyawan" enctype="multipart/form-data" style="padding:24px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_profil_mandiri">
            <input type="hidden" name="target_pin" value="<?php echo h($pin_selected); ?>">

            <!-- Preview & Upload Foto -->
            <div style="display:flex; gap:16px; align-items:center; margin-bottom:20px;">
                <div style="width:56px; height:56px; border-radius:50%; overflow:hidden; border:2px solid #e2e8f0; background:#f8fafc; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:800; color:#94a3b8;">
                    <?php if (!empty($detail_profil['foto']) && file_exists(__DIR__ . '/' . $detail_profil['foto'])): ?>
                        <img src="<?php echo h($detail_profil['foto']); ?>" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <?php echo strtoupper(mb_substr($detail_profil['nama'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">Foto Profil (JPG/PNG, Maks 2MB)</label>
                    <input type="file" name="foto_profil" accept="image/jpeg,image/png,image/webp" style="width:100%; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; background:#fff; margin-bottom:0;">
                </div>
            </div>

            <div style="height:1px; background:#f1f5f9; margin-bottom:18px;"></div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase;">No. Telepon / WhatsApp</label>
                    <input type="text" name="no_hp" value="<?php echo h($detail_profil['no_hp'] ?? ''); ?>" placeholder="08xxxxxxxxxx" style="padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13.5px; margin-bottom:0;">
                </div>
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase;">Tempat Lahir</label>
                    <input type="text" name="tempat_lahir" value="<?php echo h($detail_profil['tempat_lahir'] ?? ''); ?>" placeholder="Kota kelahiran" style="padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13.5px; margin-bottom:0;">
                </div>
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase;">Tanggal Lahir</label>
                    <input type="date" name="tanggal_lahir" value="<?php echo h($detail_profil['tanggal_lahir'] ?? ''); ?>" style="padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13.5px; margin-bottom:0;">
                </div>
            </div>
            <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:20px;">
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase;">Alamat Lengkap</label>
                <textarea name="alamat" rows="3" style="padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13.5px; resize:vertical; line-height:1.6;"><?php echo h($detail_profil['alamat'] ?? ''); ?></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f1f5f9; padding-top:16px;">
                <button type="reset" style="padding:8px 16px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#475569; font-size:13px; cursor:pointer;">Reset</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 22px; font-size:13.5px; font-weight:700;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- TAB RIWAYAT: 4 SUMMARY CARDS -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap:14px; margin-bottom:20px;">
    <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Total Record Absensi</div>
        <div style="font-size:22px; font-weight:800; color:#0f172a; margin-top:4px;"><?php echo count($logs); ?> <span style="font-size:12px; font-weight:500; color:#64748b;">Log</span></div>
        <div style="font-size:11px; color:#10b981; margin-top:4px; font-weight:600;">🟢 <?php echo $total_masuk; ?> Masuk · 🔴 <?php echo $total_pulang; ?> Pulang</div>
    </div>
    <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Absen Pertama Kali</div>
        <div style="font-size:14px; font-weight:700; color:#2563eb; margin-top:6px;"><?php echo $absen_pertama; ?></div>
        <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Awal rekaman absensi</div>
    </div>
    <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Absen Terakhir Kali</div>
        <div style="font-size:14px; font-weight:700; color:#059669; margin-top:6px;"><?php echo $absen_terakhir; ?></div>
        <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Rekaman absensi terkini</div>
    </div>
    <div style="background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Pengaturan Jadwal</div>
        <div style="font-size:13px; font-weight:700; color:#0f172a; margin-top:6px;">
            <?php
            if ($detail_user['tipe'] === 'guru') {
                echo empty($hari_ngajar_arr) ? "<span style='color:#d97706;'>❓ Belum Ada Jadwal</span>" : "<span style='color:#1d4ed8;'>" . count($hari_ngajar_arr) . " Hari Ngajar / Wk</span>";
            } else {
                echo "<span style='color:#475569;'>Kalender Kerja (Senin–Sabtu)</span>";
            }
            ?>
        </div>
        <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Status skema jadwal</div>
    </div>
</div>

<!-- TABEL DETAIL RIWAYAT ABSENSI (hanya tampil di tab riwayat) -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>📜 Daftar Riwayat Lengkap Aktivitas Absensi (<?php echo count($logs); ?> Record)</span>
        </div>

        <div style="font-size:12px; color:#64748b;">
            Diurutkan berdasarkan <b><?php echo $sort_order === 'desc' ? 'Waktu Terbaru ➔ Tertua' : 'Waktu Tertua ➔ Terbaru'; ?></b>
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th style="text-align:left;">Tanggal & Hari</th>
                    <th>Waktu Absen</th>
                    <th>Status Absensi</th>
                    <th>Keterangan Jadwal</th>
                    <th>Tipe Verifikasi</th>
                    <?php if (is_superadmin()): ?>
                    <th>Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($logs)) {
                    $no = 1;
                    foreach ($logs as $l) {
                        $hn = (int)$l['hari_num'];
                        $h_nama = $nama_hari_indo[$hn] ?? '';
                        $tgl_fmt = "<b>{$h_nama}</b>, " . date('d/m/Y', strtotime($l['waktu']));
                        $jam_fmt = date('H:i:s', strtotime($l['waktu']));

                        $st_badge = ($l['status'] == '0')
                            ? "<span class='badge badge-masuk'>🟢 Masuk</span>"
                            : (($l['status'] == '1')
                                ? "<span class='badge badge-pulang'>🔴 Pulang</span>"
                                : "<span class='badge badge-verif'>Unknown</span>");

                        $ket_badge = "";
                        if ($detail_user['tipe'] === 'guru') {
                            if (empty($hari_ngajar_arr)) {
                                $ket_badge = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;'>❓ Belum Ada Jadwal</span>";
                            } elseif (in_array($hn, $hari_ngajar_arr)) {
                                $ket_badge = "<span class='badge' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;'>👨‍🏫 Sesuai Jadwal Ngajar</span>";
                            } else {
                                $ket_badge = "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;'>⚠️ Di Luar Jadwal</span>";
                            }
                        } else {
                            if ($hn == 7) {
                                $ket_badge = "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;'>⚠️ Di Luar Hari Kerja (Minggu)</span>";
                            } else {
                                $ket_badge = "<span class='badge' style='background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'>👔 Hari Kerja Kalender</span>";
                            }
                        }

                        $verif_badge = "<span class='badge badge-verif'>";
                        if ($l['tipe_verifikasi'] == '1') $verif_badge .= "Sidik Jari 👆";
                        elseif ($l['tipe_verifikasi'] == '15') $verif_badge .= "Wajah 👤";
                        elseif ($l['tipe_verifikasi'] == '0' || $l['tipe_verifikasi'] == '99') $verif_badge .= "Manual Admin ✏️";
                        else $verif_badge .= "Password / PIN";
                        $verif_badge .= "</span>";

                        $td_aksi = "";
                        if (is_superadmin()) {
                            $target_status_label = ($l['status'] === '0') ? "Pulang" : "Masuk";
                            $action_url = "riwayat_karyawan.php?pin=" . urlencode($pin_selected) . "&tgl_mulai=" . urlencode($tgl_mulai) . "&tgl_selesai=" . urlencode($tgl_selesai) . "&sort=" . urlencode($sort_order);
                            $td_aksi = "<td>
                                            <div style='display:flex; gap:4px; justify-content:center;'>
                                                <form method='POST' action='{$action_url}' style='margin:0;'>
                                                    " . csrf_field() . "
                                                    <input type='hidden' name='action' value='tukar_status_log'>
                                                    <input type='hidden' name='id_log_toggle' value='{$l['id']}'>
                                                    <input type='hidden' name='pin_selected' value='{$pin_selected}'>
                                                    <button type='submit' class='btn' style='background:#f1f5f9; color:#0f172a; font-size:11px; padding:4px 8px; border:1px solid #cbd5e1;' title='Tukar status ke {$target_status_label}'>🔄 Tukar Status</button>
                                                </form>
                                                <form method='POST' action='{$action_url}' style='margin:0;' onsubmit=\"return confirm('Yakin ingin menghapus data log absen ini?')\">
                                                    " . csrf_field() . "
                                                    <input type='hidden' name='action' value='hapus_log_absen'>
                                                    <input type='hidden' name='id_log_hapus' value='{$l['id']}'>
                                                    <input type='hidden' name='pin_selected' value='{$pin_selected}'>
                                                    <button type='submit' class='btn' style='background:#fee2e2; color:#dc2626; font-size:11px; padding:4px 8px; border:1px solid #fca5a5;'>🗑️ Hapus</button>
                                                </form>
                                            </div>
                                        </td>";
                        }

                        echo "<tr>
                                <td><b>{$no}</b></td>
                                <td style='text-align:left;'>{$tgl_fmt}</td>
                                <td><b style='color:#0f172a; font-size:14px;'>{$jam_fmt}</b></td>
                                <td>{$st_badge}</td>
                                <td>{$ket_badge}</td>
                                <td>{$verif_badge}</td>
                                {$td_aksi}
                              </tr>";
                        $no++;
                    }
                } else {
                    $colspan = is_superadmin() ? 7 : 6;
                    echo "<tr><td colspan='{$colspan}' style='padding:35px; color:#94a3b8;'>Belum ada data riwayat absensi untuk karyawan ini.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; // end tab riwayat ?>
<?php endif; // end active_tab check ?>

<?php render_footer(); ?>
