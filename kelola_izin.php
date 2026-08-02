<?php
// ============================================================
// HALAMAN MANAJEMEN CUTI / IZIN / SAKIT (KARYAWAN & GURU)
// Akses: Superadmin & RnD / Admin
// Fitur: Catat perizinan multi-day (1 berkas), list perizinan, approval & audit log
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('kelola_izin')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$pesan_sukses = '';
$pesan_error  = '';

// --- 1. PROSES POST SIMPAN / APPROVAL / HAPUS PERIZINAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    $action = $_POST['action'];

    if ($action === 'simpan_izin') {
        $pin         = trim($_POST['pin'] ?? '');
        $tgl_mulai   = trim($_POST['tgl_mulai'] ?? $_POST['tanggal'] ?? '');
        $tgl_selesai = trim($_POST['tgl_selesai'] ?? $tgl_mulai);
        $tipe_izin   = trim($_POST['tipe_izin'] ?? '');
        $keterangan  = trim($_POST['keterangan'] ?? '');
        $created_by  = $_SESSION['username'] ?? 'Admin';

        if (empty($tgl_mulai)) $tgl_mulai = date('Y-m-d');
        if (empty($tgl_selesai)) $tgl_selesai = $tgl_mulai;

        if (strtotime($tgl_selesai) < strtotime($tgl_mulai)) {
            $tmp = $tgl_mulai;
            $tgl_mulai = $tgl_selesai;
            $tgl_selesai = $tmp;
        }

        $start_ts  = strtotime($tgl_mulai);
        $end_ts    = strtotime($tgl_selesai);
        $diff_days = ceil(($end_ts - $start_ts) / 86400) + 1;

        if (empty($pin) || empty($tgl_mulai) || empty($tipe_izin)) {
            $pesan_error = "PIN karyawan, tanggal, dan jenis izin wajib diisi!";
        } elseif (!in_array($tipe_izin, ['cuti', 'izin', 'sakit'])) {
            $pesan_error = "Jenis izin tidak valid!";
        } else {
            $stmt_c = $conn->prepare("SELECT nama FROM master_karyawan WHERE pin = ?");
            $stmt_c->bind_param("s", $pin);
            $stmt_c->execute();
            $res_c = $stmt_c->get_result();

            if ($res_c->num_rows === 0) {
                $pesan_error = "PIN karyawan (<b>" . h($pin) . "</b>) tidak terdaftar di master karyawan!";
            } else {
                $emp_data = $res_c->fetch_assoc();
                
                if (is_tatausaha() && ($emp_data['tipe'] ?? '') !== 'karyawan') {
                    $pesan_error = "⛔ <b>Akses Ditolak:</b> Role Tata Usaha hanya diizinkan mencatat perizinan untuk kategori <b>Karyawan</b>.";
                } else {
                    $nama_emp = $emp_data['nama'];
                    $approved_by = ($_SESSION['username'] ?? 'Admin') . ' (' . strtoupper($_SESSION['role'] ?? 'ADMIN') . ')';

                    $stmt = $conn->prepare("INSERT INTO perizinan (pin, tanggal, tgl_selesai, tipe_izin, keterangan, status_persetujuan, approved_by, created_by) VALUES (?, ?, ?, ?, ?, 'disetujui', ?, ?) ON DUPLICATE KEY UPDATE tgl_selesai = VALUES(tgl_selesai), tipe_izin = VALUES(tipe_izin), keterangan = VALUES(keterangan), status_persetujuan = 'disetujui', approved_by = VALUES(approved_by), created_by = VALUES(created_by)");
                    $stmt->bind_param("ssssssss", $pin, $tgl_mulai, $tgl_selesai, $tipe_izin, $keterangan, $approved_by, $created_by);

                    if ($stmt->execute()) {
                        $dur_txt = ($diff_days > 1) 
                            ? "{$diff_days} Hari (" . date('d/m/Y', $start_ts) . " s.d " . date('d/m/Y', $end_ts) . ")" 
                            : date('d/m/Y', $start_ts);

                        $pesan_sukses = "Data <b>" . strtoupper($tipe_izin) . "</b> untuk <b>" . h($nama_emp) . "</b> (PIN: " . h($pin) . ") periode <b>" . $dur_txt . "</b> berhasil disimpan.";
                        log_audit("INPUT_PERIZINAN", "Simpan " . strtoupper($tipe_izin) . " PIN {$pin} ({$nama_emp}) periode {$tgl_mulai} s.d {$tgl_selesai}");
                    } else {
                        $pesan_error = "Gagal menyimpan perizinan: " . $conn->error;
                    }
                }
            }
        }
    }

    elseif ($action === 'update_status_persetujuan') {
        $id_target   = (int)($_POST['id_target'] ?? 0);
        $status_baru = trim($_POST['status_baru'] ?? '');

        if ($id_target > 0 && in_array($status_baru, ['disetujui', 'ditolak', 'pending'])) {
            // Cek jika tatausaha memproses perizinan non-karyawan
            $stmt_chk_tu = $conn->prepare("SELECT mk.tipe FROM perizinan p JOIN master_karyawan mk ON p.pin = mk.pin WHERE p.id = ?");
            $stmt_chk_tu->bind_param("i", $id_target);
            $stmt_chk_tu->execute();
            $res_tu = $stmt_chk_tu->get_result()->fetch_assoc();

            if (is_tatausaha() && $res_tu && ($res_tu['tipe'] ?? '') !== 'karyawan') {
                $pesan_error = "⛔ <b>Akses Ditolak:</b> Anda hanya berwenang memproses persetujuan perizinan kategori <b>Karyawan</b>.";
            } else {
                $approved_by_val = ($_SESSION['username'] ?? 'Admin') . ' (' . strtoupper($_SESSION['role'] ?? 'ADMIN') . ')';

                $stmt = $conn->prepare("UPDATE perizinan SET status_persetujuan = ?, approved_by = ? WHERE id = ?");
                $stmt->bind_param("ssi", $status_baru, $approved_by_val, $id_target);
                if ($stmt->execute()) {
                    $pesan_sukses = "Status persetujuan berhasil diubah menjadi <b>" . strtoupper($status_baru) . "</b> oleh " . h($approved_by_val) . ".";
                    log_audit("UPDATE_STATUS_PERIZINAN", "Ubah status perizinan ID {$id_target} menjadi {$status_baru} oleh {$approved_by_val}");

                    // Kirim notifikasi balasan ke pemohon izin
                    $stmt_p_info = $conn->prepare("SELECT p.pin, p.tipe_izin, p.tanggal, u.id as user_id FROM perizinan p LEFT JOIN users u ON p.pin = u.pin WHERE p.id = ?");
                    $stmt_p_info->bind_param("i", $id_target);
                    $stmt_p_info->execute();
                    $p_info = $stmt_p_info->get_result()->fetch_assoc();
                    if ($p_info && !empty($p_info['user_id'])) {
                        $st_label = ($status_baru === 'disetujui') ? 'DISETUJUI' : (($status_baru === 'ditolak') ? 'DITOLAK' : 'PENDING');
                        $notif_title = "Status Perizinan " . ucfirst($p_info['tipe_izin']) . " " . $st_label;
                        $notif_msg = "Pengajuan " . strtoupper($p_info['tipe_izin']) . " Anda periode " . date('d/m/Y', strtotime($p_info['tanggal'])) . " telah {$st_label} oleh {$approved_by_val}.";
                        $stmt_u_notif = $conn->prepare("INSERT INTO notifications (user_id, target_role, title, message, type, link) VALUES (?, 'user', ?, ?, 'status_change', 'user_izin.php')");
                        $stmt_u_notif->bind_param("iss", $p_info['user_id'], $notif_title, $notif_msg);
                        $stmt_u_notif->execute();
                    }

                    $conn->query("UPDATE notifications SET is_read = 1 WHERE link = 'kelola_izin.php' AND is_read = 0");
                } else {
                    $pesan_error = "Gagal memperbarui status: " . $conn->error;
                }
            }
        }
    }

    elseif ($action === 'hapus_izin') {
        $id_hapus = (int)($_POST['id_hapus'] ?? 0);
        if ($id_hapus > 0) {
            $stmt_info = $conn->prepare("SELECT pin, tanggal, tgl_selesai, tipe_izin FROM perizinan WHERE id = ?");
            $stmt_info->bind_param("i", $id_hapus);
            $stmt_info->execute();
            $info = $stmt_info->get_result()->fetch_assoc();

            $stmt = $conn->prepare("DELETE FROM perizinan WHERE id = ?");
            $stmt->bind_param("i", $id_hapus);
            if ($stmt->execute()) {
                $pesan_sukses = "Data perizinan berhasil dihapus.";
                if ($info) {
                    log_audit("HAPUS_PERIZINAN", "Hapus " . strtoupper($info['tipe_izin']) . " PIN {$info['pin']} tanggal {$info['tanggal']}");
                }
            } else {
                $pesan_error = "Gagal menghapus perizinan: " . $conn->error;
            }
        }
    }

    $_SESSION['pesan_sukses'] = $pesan_sukses;
    $_SESSION['pesan_error']  = $pesan_error;
    $qs = !empty($_SERVER['QUERY_STRING']) ? "?" . $_SERVER['QUERY_STRING'] : "";
    header("Location: kelola_izin.php" . $qs);
    exit;
}

if (isset($_SESSION['pesan_sukses'])) {
    $pesan_sukses = $_SESSION['pesan_sukses'];
    unset($_SESSION['pesan_sukses']);
}
if (isset($_SESSION['pesan_error'])) {
    $pesan_error = $_SESSION['pesan_error'];
    unset($_SESSION['pesan_error']);
}

// --- 2. STATISTIK RINGKASAN ---
$stat = ['total' => 0, 'pending' => 0, 'disetujui' => 0, 'ditolak' => 0];

if (is_tatausaha()) {
    $res_stat = $conn->query("SELECT perizinan.status_persetujuan, COUNT(*) as cnt FROM perizinan JOIN master_karyawan ON perizinan.pin = master_karyawan.pin WHERE master_karyawan.tipe = 'karyawan' GROUP BY perizinan.status_persetujuan");
} else {
    $res_stat = $conn->query("SELECT status_persetujuan, COUNT(*) as cnt FROM perizinan GROUP BY status_persetujuan");
}

if ($res_stat) {
    while ($rs = $res_stat->fetch_assoc()) {
        $st_key = $rs['status_persetujuan'] ?? 'disetujui';
        if (isset($stat[$st_key])) {
            $stat[$st_key] += (int)$rs['cnt'];
        }
        $stat['total'] += (int)$rs['cnt'];
    }
}

// --- 3. QUERY & PAGINASI TABLE PERIZINAN ---
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = 15;
$offset      = ($page - 1) * $limit;
$search      = trim($_GET['q'] ?? '');
$filter_tipe = trim($_GET['tipe'] ?? 'semua');
$filter_st   = trim($_GET['status'] ?? 'semua');

$where_clauses = [];
$params        = [];
$types         = '';

if (is_tatausaha()) {
    $where_clauses[] = "master_karyawan.tipe = 'karyawan'";
}

if (!empty($search)) {
    $where_clauses[] = "(perizinan.pin LIKE ? OR master_karyawan.nama LIKE ? OR perizinan.keterangan LIKE ?)";
    $s_term = "%{$search}%";
    $params[] = $s_term; $params[] = $s_term; $params[] = $s_term;
    $types .= 'sss';
}

if (in_array($filter_tipe, ['cuti', 'izin', 'sakit'])) {
    $where_clauses[] = "perizinan.tipe_izin = ?";
    $params[] = $filter_tipe;
    $types .= 's';
}

if (in_array($filter_st, ['pending', 'disetujui', 'ditolak'])) {
    $where_clauses[] = "perizinan.status_persetujuan = ?";
    $params[] = $filter_st;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$count_query = "SELECT COUNT(*) as total FROM perizinan LEFT JOIN master_karyawan ON perizinan.pin = master_karyawan.pin {$where_sql}";
$stmt_cnt = $conn->prepare($count_query);
if (!empty($types)) {
    $stmt_cnt->bind_param($types, ...$params);
}
$stmt_cnt->execute();
$total_records = $stmt_cnt->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages   = max(1, ceil($total_records / $limit));

$data_query = "SELECT perizinan.*, master_karyawan.nama, master_karyawan.departemen, master_karyawan.tipe 
               FROM perizinan 
               LEFT JOIN master_karyawan ON perizinan.pin = master_karyawan.pin 
               {$where_sql} 
               ORDER BY perizinan.tanggal DESC, perizinan.id DESC 
               LIMIT ? OFFSET ?";
$params_data = array_merge($params, [$limit, $offset]);
$types_data  = $types . 'ii';

$stmt_data = $conn->prepare($data_query);
$stmt_data->bind_param($types_data, ...$params_data);
$stmt_data->execute();
$list_izin = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);

if (is_tatausaha()) {
    $res_emp = $conn->query("SELECT pin, nama, departemen, tipe FROM master_karyawan WHERE tipe = 'karyawan' ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC");
} else {
    $res_emp = $conn->query("SELECT pin, nama, departemen, tipe FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC");
}
$master_employees = $res_emp->fetch_all(MYSQLI_ASSOC);

render_header("Kelola Cuti, Izin & Sakit", "kelola_izin");
?>

<style>
/* CSS RESPONSIVE & OVERFLOW FIXES */
.kelola-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 1024px) {
    .kelola-grid {
        grid-template-columns: 1fr;
    }
}
.stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 20px;
    box-shadow: 0 2px 10px rgba(15,23,42,0.03);
}

.searchable-select-wrapper { position: relative; width: 100%; }
.searchable-input {
    width: 100%;
    box-sizing: border-box;
    min-width: 0;
    padding: 9px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13px;
    background: #fff;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.searchable-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    outline: none;
}
.searchable-dropdown-list {
    position: absolute;
    top: calc(100% + 4px); left: 0; right: 0;
    max-height: 220px;
    overflow-y: auto;
    background: #fff;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    z-index: 99;
    display: none;
}
.searchable-item {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
    transition: background 0.15s;
}
.searchable-item:hover { background: #eff6ff; color: #2563eb; }

/* FIX DATE RANGE GRID IN 340PX CARD */
.date-range-admin-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 14px;
}
.date-range-admin-grid > div {
    min-width: 0;
}

.action-btn-sm {
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    white-space: nowrap;
}
</style>

<!-- NOTIFIKASI SUKSES / ERROR -->
<?php if (!empty($pesan_sukses)): ?>
    <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5); color:#065f46; border-left:4px solid #10b981; padding:14px 20px; border-radius:0 12px 12px 0; margin-bottom:20px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:10px; box-shadow:0 2px 8px rgba(16,185,129,.12);">
        <span style="font-size:11px; font-weight:900; background:#10b981; color:#fff; padding:2px 8px; border-radius:4px;">SUKSES</span>
        <span><?php echo $pesan_sukses; ?></span>
    </div>
<?php endif; ?>
<?php if (!empty($pesan_error)): ?>
    <div style="background:linear-gradient(135deg,#fff1f2,#fee2e2); color:#991b1b; border-left:4px solid #ef4444; padding:14px 20px; border-radius:0 12px 12px 0; margin-bottom:20px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:10px; box-shadow:0 2px 8px rgba(239,68,68,.12);">
        <span style="font-size:11px; font-weight:900; background:#ef4444; color:#fff; padding:2px 8px; border-radius:4px;">ERROR</span>
        <span><?php echo $pesan_error; ?></span>
    </div>
<?php endif; ?>

<?php if (is_tatausaha()): ?>
    <div style="background:#fff7ed; border:1px solid #fed7aa; border-left:4px solid #f97316; color:#c2410c; padding:12px 18px; border-radius:0 12px 12px 0; margin-bottom:20px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:10px;">
        <span style="font-size:11px; font-weight:900; background:#ea580c; color:#fff; padding:2px 8px; border-radius:4px;">TATA USAHA</span>
        <span><b>Akses Tata Usaha:</b> Data perizinan dan rekap di bawah ini khusus menampilkan pengajuan kategori <b>Karyawan</b> saja.</span>
    </div>
<?php endif; ?>

<!-- RINGKASAN STATISTIK -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:22px;">
    <div style="background:linear-gradient(135deg,#0f172a,#1e293b); border-radius:16px; padding:20px 22px; color:#fff; box-shadow:0 4px 16px rgba(15,23,42,.15);">
        <div style="font-size:11px; color:#94a3b8; font-weight:800; text-transform:uppercase; letter-spacing:1.5px;">TOTAL BERKAS</div>
        <div style="font-size:26px; font-weight:900; color:#38bdf8; margin-top:4px;"><?php echo $stat['total']; ?> <span style="font-size:13px; font-weight:600; color:#94a3b8;">Berkas</span></div>
    </div>
    <div style="background:linear-gradient(135deg,#78350f,#d97706); border-radius:16px; padding:20px 22px; color:#fff; box-shadow:0 4px 16px rgba(217,119,6,.2);">
        <div style="font-size:11px; color:#fde68a; font-weight:800; text-transform:uppercase; letter-spacing:1.5px;">MENUNGGU APPROVAL</div>
        <div style="font-size:26px; font-weight:900; color:#fff; margin-top:4px;"><?php echo $stat['pending']; ?></div>
    </div>
    <div style="background:linear-gradient(135deg,#065f46,#059669); border-radius:16px; padding:20px 22px; color:#fff; box-shadow:0 4px 16px rgba(5,150,105,.2);">
        <div style="font-size:11px; color:#a7f3d0; font-weight:800; text-transform:uppercase; letter-spacing:1.5px;">DISETUJUI</div>
        <div style="font-size:26px; font-weight:900; color:#fff; margin-top:4px;"><?php echo $stat['disetujui']; ?></div>
    </div>
    <div style="background:linear-gradient(135deg,#881337,#e11d48); border-radius:16px; padding:20px 22px; color:#fff; box-shadow:0 4px 16px rgba(225,29,72,.2);">
        <div style="font-size:11px; color:#fecdd3; font-weight:800; text-transform:uppercase; letter-spacing:1.5px;">DITOLAK</div>
        <div style="font-size:26px; font-weight:900; color:#fff; margin-top:4px;"><?php echo $stat['ditolak']; ?></div>
    </div>
</div>

<div class="kelola-grid">

    <!-- FORM INPUT CUTI / IZIN / SAKIT -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,.06);">
        <div style="background:linear-gradient(135deg,#0f172a,#1e293b); padding:16px 20px; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:15px; font-weight:800; color:#fff;">Catat Perizinan Pegawai</div>
        </div>

        <form method="POST" action="kelola_izin.php" id="form-izin" style="padding:20px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="simpan_izin">

            <!-- SEARCHABLE AUTOCOMPLETE DROPDOWN KARYAWAN -->
            <div style="margin-bottom:14px;">
                <label for="input-search-emp" style="font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:6px;"><?php echo is_tatausaha() ? 'Pilih Karyawan' : 'Pilih Guru / Karyawan'; ?></label>
                <div class="searchable-select-wrapper">
                    <input type="hidden" name="pin" id="selected-pin" required>
                    <input type="text" id="input-search-emp" class="searchable-input" placeholder="Ketik PIN atau Nama..." autocomplete="off" required>
                    
                    <div class="searchable-dropdown-list" id="dropdown-emp-list">
                        <?php foreach ($master_employees as $e): 
                            $label_emp = "[" . h($e['pin']) . "] " . h($e['nama']) . " — " . h($e['departemen'] ?: 'Karyawan');
                        ?>
                            <div class="searchable-item" 
                                 data-pin="<?php echo h($e['pin']); ?>" 
                                 data-text="<?php echo h(strtolower($e['pin'] . ' ' . $e['nama'] . ' ' . $e['departemen'])); ?>"
                                 onclick="selectEmployee('<?php echo h($e['pin']); ?>', '<?php echo h($label_emp); ?>')">
                                <b>[<?php echo h($e['pin']); ?>]</b> <?php echo h($e['nama']); ?>
                                <span style="font-size:11px; color:#64748b; display:block;"><?php echo h($e['departemen'] ?: '-'); ?> (<?php echo ucfirst($e['tipe']); ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- RENTANG TANGGAL -->
            <div class="date-range-admin-grid">
                <div>
                    <label for="tgl_mulai" style="font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:6px;">Dari Tanggal</label>
                    <input type="date" id="tgl_mulai" name="tgl_mulai" value="<?php echo date('Y-m-d'); ?>" class="searchable-input" required>
                </div>
                <div>
                    <label for="tgl_selesai" style="font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:6px;">Sampai Tanggal</label>
                    <input type="date" id="tgl_selesai" name="tgl_selesai" value="<?php echo date('Y-m-d'); ?>" class="searchable-input" required>
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label for="tipe_izin" style="font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:6px;">Jenis Perizinan</label>
                <select id="tipe_izin" name="tipe_izin" class="searchable-input" style="font-weight:600;" required>
                    <option value="cuti">Cuti Resmi Kalender / Tahunan</option>
                    <option value="izin">Izin Keperluan Pribadi / Tugas</option>
                    <option value="sakit">Sakit (Dengan / Tanpa Surat)</option>
                </select>
            </div>

            <div style="margin-bottom:20px;">
                <label for="keterangan" style="font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:6px;">Keterangan / Alasan</label>
                <textarea id="keterangan" name="keterangan" rows="3" placeholder="Contoh: Sakit demam, Izin dinas luar..." class="searchable-input" style="resize:vertical; line-height:1.5;"></textarea>
            </div>

            <button type="submit" style="width:100%; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border:none; padding:12px; font-size:13px; font-weight:800; border-radius:10px; cursor:pointer; box-shadow:0 4px 14px rgba(37,99,235,.35);">
                Simpan Data Perizinan
            </button>
        </form>
    </div>

    <!-- TABEL DAFTAR PERIZINAN & PAGINASI -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,.06);">
        <div style="background:#ffffff; border-bottom:1px solid #e2e8f0; padding:18px 22px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;">
            <div style="font-size:16px; font-weight:800; color:#0f172a;">Riwayat &amp; Data Perizinan</div>

            <!-- FILTER & SEARCH BAR -->
            <form method="GET" action="kelola_izin.php" style="margin:0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Cari PIN / Nama / Alasan..." style="width:180px; margin-bottom:0; padding:8px 12px; font-size:12px; border:1px solid #cbd5e1; border-radius:8px; outline:none;">
                <select name="tipe" style="width:120px; margin-bottom:0; padding:8px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:8px; font-weight:600; outline:none;" onchange="this.form.submit()">
                    <option value="semua" <?php echo $filter_tipe === 'semua' ? 'selected' : ''; ?>>Semua Tipe</option>
                    <option value="cuti" <?php echo $filter_tipe === 'cuti' ? 'selected' : ''; ?>>Cuti</option>
                    <option value="izin" <?php echo $filter_tipe === 'izin' ? 'selected' : ''; ?>>Izin</option>
                    <option value="sakit" <?php echo $filter_tipe === 'sakit' ? 'selected' : ''; ?>>Sakit</option>
                </select>
                <select name="status" style="width:130px; margin-bottom:0; padding:8px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:8px; font-weight:600; outline:none;" onchange="this.form.submit()">
                    <option value="semua" <?php echo $filter_st === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                    <option value="pending" <?php echo $filter_st === 'pending' ? 'selected' : ''; ?>>Menunggu</option>
                    <option value="disetujui" <?php echo $filter_st === 'disetujui' ? 'selected' : ''; ?>>Disetujui</option>
                    <option value="ditolak" <?php echo $filter_st === 'ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                </select>
                <button type="submit" style="background:#f1f5f9; color:#334155; padding:8px 14px; font-size:12px; font-weight:700; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer;">Cari</button>
            </form>
        </div>

        <div class="table-responsive" style="max-height:700px; overflow:auto;">
            <table style="min-width:760px; font-size:13px; width:100%; border-collapse:collapse;">
                <thead style="position:sticky; top:0; z-index:10; background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                    <tr>
                        <th style="width:45px; background:#f8fafc; color:#475569; padding:12px 8px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">No</th>
                        <th style="background:#f8fafc; color:#475569; padding:12px 14px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">Periode Tanggal</th>
                        <th style="width:80px; background:#f8fafc; color:#475569; padding:12px 8px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">PIN</th>
                        <th style="background:#f8fafc; color:#475569; padding:12px 16px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:left; border-right:1px solid #e2e8f0;">Nama Karyawan</th>
                        <th style="width:90px; background:#f8fafc; color:#475569; padding:12px 10px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">Jenis</th>
                        <th style="background:#f8fafc; color:#475569; padding:12px 16px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:left; border-right:1px solid #e2e8f0;">Keterangan</th>
                        <th style="width:120px; background:#f8fafc; color:#475569; padding:12px 10px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">Status</th>
                        <th style="width:140px; background:#f8fafc; color:#475569; padding:12px 10px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($list_izin)): 
                        $no_counter = $offset + 1;
                        foreach ($list_izin as $row):
                            $badge_style = 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
                            $badge_text  = ucfirst($row['tipe_izin']);

                            if ($row['tipe_izin'] === 'cuti') {
                                $badge_style = 'background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;';
                                $badge_text  = 'Cuti';
                            } elseif ($row['tipe_izin'] === 'izin') {
                                $badge_style = 'background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;';
                                $badge_text  = 'Izin';
                            } elseif ($row['tipe_izin'] === 'sakit') {
                                $badge_style = 'background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff;';
                                $badge_text  = 'Sakit';
                            }

                            $st_p = $row['status_persetujuan'] ?? 'disetujui';
                            $p_badge = 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;';
                            $p_text  = 'Disetujui';
                            if ($st_p === 'pending') {
                                $p_badge = 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;';
                                $p_text  = 'Menunggu';
                            } elseif ($st_p === 'ditolak') {
                                $p_badge = 'background:#fee2e2; color:#be123c; border:1px solid #fca5a5;';
                                $p_text  = 'Ditolak';
                            }

                            // Format Tgl Range
                            $tgl_m = date('d/m/Y', strtotime($row['tanggal']));
                            $tgl_s = !empty($row['tgl_selesai']) ? date('d/m/Y', strtotime($row['tgl_selesai'])) : $tgl_m;
                            $dur_days = (strtotime($row['tgl_selesai'] ?: $row['tanggal']) - strtotime($row['tanggal'])) / 86400 + 1;

                            $tgl_display = ($tgl_m === $tgl_s) 
                                ? "<b>{$tgl_m}</b>" 
                                : "<b>{$tgl_m}</b> s.d <b>{$tgl_s}</b> <span style='font-size:11px; color:#2563eb; font-weight:700; display:block;'>({$dur_days} Hari)</span>";
                    ?>
                        <tr>
                            <td><b><?php echo $no_counter++; ?></b></td>
                            <td><?php echo $tgl_display; ?></td>
                            <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:700;"><?php echo h($row['pin']); ?></code></td>
                            <td style="text-align:left; font-weight:700; color:#0f172a; white-space:nowrap;">
                                <?php echo h($row['nama'] ?: 'Belum Terdaftar'); ?>
                                <span style="font-size:11px; color:#64748b; display:block; font-weight:normal;"><?php echo h($row['departemen'] ?: '-'); ?></span>
                            </td>
                            <td>
                                <span class="badge" style="<?php echo $badge_style; ?> font-weight:700;"><?php echo $badge_text; ?></span>
                            </td>
                            <td style="text-align:left; font-size:12.5px; color:#334155; line-height:1.4;">
                                <?php echo h($row['keterangan'] ?: '-'); ?>
                            </td>
                            <td>
                                <span class="badge" style="<?php echo $p_badge; ?> font-weight:700;"><?php echo $p_text; ?></span>
                                <?php if (!empty($row['approved_by'])): ?>
                                    <div style="font-size:10.5px; color:#64748b; margin-top:3px; font-weight:600;">
                                        oleh <b><?php echo h($row['approved_by']); ?></b>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">
                                    <?php if ($st_p !== 'disetujui'): ?>
                                    <form method="POST" action="kelola_izin.php" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_status_persetujuan">
                                        <input type="hidden" name="id_target" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="status_baru" value="disetujui">
                                        <button type="submit" class="action-btn-sm" style="background:#dcfce7; color:#15803d; border-color:#bbf7d0;" title="Setujui Izin (Seluruh Periode)">Setujui</button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($st_p !== 'ditolak'): ?>
                                    <form method="POST" action="kelola_izin.php" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_status_persetujuan">
                                        <input type="hidden" name="id_target" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="status_baru" value="ditolak">
                                        <button type="submit" class="action-btn-sm" style="background:#fff7ed; color:#c2410c; border-color:#ffedd5;" title="Tolak Izin">Tolak</button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="POST" action="kelola_izin.php" style="margin:0;" onsubmit="return confirm('Hapus berkas perizinan ini?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="hapus_izin">
                                        <input type="hidden" name="id_hapus" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="action-btn-sm" style="background:#fee2e2; color:#be123c; border-color:#fca5a5;" title="Hapus Berkas">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="8" style="padding:40px; color:#94a3b8; text-align:center;">Belum ada data perizinan / cuti / sakit.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- NAVIGASI PAGINASI MODERN -->
        <?php if ($total_pages > 1): 
            $start_num = $offset + 1;
            $end_num   = min($offset + $limit, $total_records);
        ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Menampilkan <b><?php echo $start_num; ?> - <?php echo $end_num; ?></b> dari <b><?php echo $total_records; ?></b> berkas
                </div>
                <?php echo render_smart_pagination($page, $total_pages, ['q' => $search, 'tipe' => $filter_tipe, 'status' => $filter_st]); ?>
            </div>
        <?php endif; ?>
    </div>
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
        if (!e.target.closest('.searchable-select-wrapper')) {
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

<?php render_footer(); ?>
