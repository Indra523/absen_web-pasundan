<?php
// ============================================================
// HALAMAN MANAJEMEN CUTI / IZIN / SAKIT (KARYAWAN & GURU)
// Akses: Superadmin, Admin, RnD, Tata Usaha, Staff
// Redesain Modern, Aesthetic, Sleek UI + Preview Surat Dokter & Rute Rumah
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

        // Handle Surat Dokter (Optional in Admin form)
        $surat_dokter_path = null;
        if (isset($_FILES['surat_dokter']) && $_FILES['surat_dokter']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['surat_dokter'];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf']) && $file['size'] <= 5 * 1024 * 1024) {
                $target_dir = __DIR__ . '/uploads/surat_dokter/';
                if (!is_dir($target_dir)) @mkdir($target_dir, 0775, true);
                $safe_pin = preg_replace('/[^a-zA-Z0-9_-]/', '', $pin);
                $new_filename = 'surat_dokter_' . $safe_pin . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                if (move_uploaded_file($file['tmp_name'], $target_dir . $new_filename)) {
                    $surat_dokter_path = 'uploads/surat_dokter/' . $new_filename;
                }
            }
        }

        if (empty($pin) || empty($tgl_mulai) || empty($tipe_izin)) {
            $pesan_error = "PIN karyawan, tanggal, dan jenis izin wajib diisi!";
        } elseif (!in_array($tipe_izin, ['cuti', 'izin', 'sakit'])) {
            $pesan_error = "Jenis izin tidak valid!";
        } else {
            $stmt_c = $conn->prepare("SELECT nama, tipe FROM master_karyawan WHERE pin = ?");
            $stmt_c->bind_param("s", $pin);
            $stmt_c->execute();
            $res_c = $stmt_c->get_result();

            if ($res_c->num_rows === 0) {
                $pesan_error = "PIN karyawan (<b>" . h($pin) . "</b>) tidak terdaftar di master karyawan!";
            } else {
                $emp_data = $res_c->fetch_assoc();
                
                if (is_tatausaha() && ($emp_data['tipe'] ?? '') !== 'karyawan') {
                    $pesan_error = "Akses Ditolak: Role Tata Usaha hanya diizinkan mencatat perizinan untuk kategori Karyawan.";
                } else {
                    $nama_emp           = $emp_data['nama'];
                    $status_persetujuan = 'disetujui';
                    $approved_by        = ($_SESSION['username'] ?? 'Admin') . ' (' . strtoupper($_SESSION['role'] ?? 'ADMIN') . ')';

                    $stmt = $conn->prepare("INSERT INTO perizinan (pin, tanggal, tgl_selesai, tipe_izin, keterangan, surat_dokter, status_persetujuan, approved_by, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE tgl_selesai = VALUES(tgl_selesai), tipe_izin = VALUES(tipe_izin), keterangan = VALUES(keterangan), surat_dokter = COALESCE(VALUES(surat_dokter), surat_dokter), status_persetujuan = VALUES(status_persetujuan), approved_by = VALUES(approved_by), created_by = VALUES(created_by)");
                    $stmt->bind_param("sssssssss", $pin, $tgl_mulai, $tgl_selesai, $tipe_izin, $keterangan, $surat_dokter_path, $status_persetujuan, $approved_by, $created_by);

                    if ($stmt->execute()) {
                        $dur_txt = ($diff_days > 1) 
                            ? "{$diff_days} Hari (" . date('d/m/Y', $start_ts) . " s.d " . date('d/m/Y', $end_ts) . ")" 
                            : date('d/m/Y', $start_ts);

                        $pesan_sukses = "Data <b>" . strtoupper($tipe_izin) . "</b> untuk <b>" . h($nama_emp) . "</b> (PIN: " . h($pin) . ") periode <b>" . $dur_txt . "</b> berhasil disimpan dan langsung disetujui.";
                        log_audit("INPUT_PERIZINAN", "Simpan & Setujui " . strtoupper($tipe_izin) . " PIN {$pin} ({$nama_emp}) periode {$tgl_mulai} s.d {$tgl_selesai} oleh {$approved_by}");
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
            $stmt_chk_tu = $conn->prepare("SELECT mk.tipe FROM perizinan p JOIN master_karyawan mk ON p.pin = mk.pin WHERE p.id = ?");
            $stmt_chk_tu->bind_param("i", $id_target);
            $stmt_chk_tu->execute();
            $res_tu = $stmt_chk_tu->get_result()->fetch_assoc();

            if (is_tatausaha() && $res_tu && ($res_tu['tipe'] ?? '') !== 'karyawan') {
                $pesan_error = "Akses Ditolak: Anda hanya berwenang memproses persetujuan perizinan kategori Karyawan.";
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

$data_query = "SELECT perizinan.*, master_karyawan.nama, master_karyawan.departemen, master_karyawan.tipe, master_karyawan.foto, master_karyawan.no_hp, master_karyawan.latitude_rumah, master_karyawan.longitude_rumah, master_karyawan.catatan_alamat 
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

render_header("Kelola Cuti, Izin &amp; Sakit", "kelola_izin");
?>

<style>
/* ===== MODERN KELOLA IZIN THEME ===== */
.kelola-izin-container {
    display: flex;
    flex-direction: column;
    gap: 22px;
    max-width: 1200px;
    margin: 0 auto 40px auto;
    width: 100%;
}

/* 4 STAT SUMMARY CARDS */
.stats-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}

.stat-card-glass {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.04);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.stat-card-glass:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -4px rgba(15, 23, 42, 0.08);
}

.stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-metric-title {
    font-size: 11px;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}

.stat-metric-value {
    font-size: 24px;
    font-weight: 900;
    line-height: 1.1;
    color: #0f172a;
}

.stat-metric-sub {
    font-size: 11.5px;
    color: #94a3b8;
    font-weight: 600;
    margin-top: 2px;
}

/* FILTER & SEARCH CARD COMPACT */
.filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 14px 18px;
    box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.04);
}

.filter-toolbar-grid {
    display: grid;
    grid-template-columns: minmax(180px, 1.8fr) minmax(140px, 1.1fr) minmax(130px, 1fr) auto auto;
    gap: 10px;
    align-items: center;
}

@media (max-width: 960px) {
    .filter-toolbar-grid {
        grid-template-columns: 1fr 1fr;
    }
}
@media (max-width: 580px) {
    .filter-toolbar-grid {
        grid-template-columns: 1fr;
    }
}

.search-input-custom {
    width: 100% !important;
    padding: 9.5px 12px 9.5px 34px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 10px !important;
    font-size: 13px !important;
    color: #0f172a !important;
    font-weight: 600 !important;
    outline: none !important;
    transition: all 0.2s ease !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    background: #ffffff !important;
}

.search-input-custom:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12) !important;
}

.select-filter-custom {
    width: 100% !important;
    padding: 9.5px 12px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 10px !important;
    font-size: 13px !important;
    color: #0f172a !important;
    font-weight: 700 !important;
    outline: none !important;
    background: #ffffff !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    transition: all 0.2s ease !important;
}

.select-filter-custom:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12) !important;
}

.btn-primary-custom {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff !important;
    font-weight: 800;
    font-size: 13px;
    padding: 9.5px 18px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(37, 99, 235, 0.25);
    transition: all 0.2s ease;
    text-decoration: none;
    margin: 0;
}
.btn-primary-custom:hover {
    transform: translateY(-1px);
}

/* DATA TABLE CARD */
.table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 6px 25px -4px rgba(15, 23, 42, 0.06);
}

.table-header-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}

.table-modern {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 820px;
}

.table-modern thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 800;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.8px;
    padding: 13px 16px;
    border-bottom: 1.5px solid #e2e8f0;
    text-align: center;
}

.table-modern td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    text-align: center;
    color: #334155;
}

.table-modern tbody tr:hover {
    background: #f8fafc;
}

/* STATUS & TIPE PILLS */
.status-pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 800;
}
.status-pill-approved { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.status-pill-pending { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.status-pill-rejected { background: #fee2e2; color: #be123c; border: 1px solid #fca5a5; }

.badge-tipe {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3.5px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 800;
}
.badge-tipe-cuti { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-tipe-izin { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
.badge-tipe-sakit { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }

/* ACTION BUTTONS */
.btn-approve {
    background: #10b981;
    color: #ffffff;
    border: none;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.btn-approve:hover { background: #059669; transform: scale(1.02); }

.btn-reject {
    background: #ef4444;
    color: #ffffff;
    border: none;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.btn-reject:hover { background: #dc2626; transform: scale(1.02); }

.btn-route-sick {
    background: linear-gradient(135deg, #059669, #047857);
    color: #ffffff !important;
    text-decoration: none;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 8px rgba(5,150,105,0.25);
    transition: all 0.15s ease;
}
.btn-route-sick:hover { transform: translateY(-1px); }

/* LIGHTBOX MODAL */
.photo-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(6px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.photo-modal-card {
    background: #ffffff;
    border-radius: 20px;
    max-width: 560px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
    animation: scaleIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes scaleIn { from { transform: scale(0.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>

<div class="kelola-izin-container">

    <!-- TOAST NOTIFICATIONS -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:14px 18px; color:#15803d; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(22,163,74,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; border-radius:14px; padding:14px 18px; color:#991b1b; font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(220,38,38,0.08);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
        </div>
    <?php endif; ?>

    <!-- 4 KPI STAT CARDS -->
    <div class="stats-cards-grid">
        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#eff6ff; color:#2563eb;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Total Pengajuan</div>
                <div class="stat-metric-value"><?php echo $stat['total']; ?> <span style="font-size:12px; font-weight:700; color:#64748b;">Berkas</span></div>
                <div class="stat-metric-sub">Keseluruhan Record</div>
            </div>
        </div>

        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#fffbeb; color:#d97706;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Menunggu Review</div>
                <div class="stat-metric-value" style="color:#d97706;"><?php echo $stat['pending']; ?> <span style="font-size:12px; font-weight:700; color:#64748b;">Antrean</span></div>
                <div class="stat-metric-sub">Perlu Tindakan</div>
            </div>
        </div>

        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#f0fdf4; color:#16a34a;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Disetujui</div>
                <div class="stat-metric-value" style="color:#16a34a;"><?php echo $stat['disetujui']; ?></div>
                <div class="stat-metric-sub">Izin/Cuti Aktif</div>
            </div>
        </div>

        <div class="stat-card-glass">
            <div class="stat-icon-wrapper" style="background:#fff1f2; color:#e11d48;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Ditolak</div>
                <div class="stat-metric-value" style="color:#e11d48;"><?php echo $stat['ditolak']; ?></div>
                <div class="stat-metric-sub">Tidak Disetujui</div>
            </div>
        </div>
    </div>

    <!-- FILTER & ACTION TOOLBAR COMPACT SINGLE ROW -->
    <div class="filter-card">
        <form method="GET" action="kelola_izin.php" style="margin:0;">
            <div class="filter-toolbar-grid">
                <!-- SEARCH INPUT WITH ICON -->
                <div style="position:relative; width:100%;">
                    <svg style="position:absolute; left:11px; top:50%; transform:translateY(-50%); pointer-events:none;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Cari Nama / PIN / Alasan..." class="search-input-custom">
                </div>

                <!-- FILTER STATUS -->
                <div>
                    <select name="status" class="select-filter-custom" onchange="this.form.submit()">
                        <option value="semua" <?php echo $filter_st === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                        <option value="pending" <?php echo $filter_st === 'pending' ? 'selected' : ''; ?>>Menunggu (<?php echo $stat['pending']; ?>)</option>
                        <option value="disetujui" <?php echo $filter_st === 'disetujui' ? 'selected' : ''; ?>>Disetujui (<?php echo $stat['disetujui']; ?>)</option>
                        <option value="ditolak" <?php echo $filter_st === 'ditolak' ? 'selected' : ''; ?>>Ditolak (<?php echo $stat['ditolak']; ?>)</option>
                    </select>
                </div>

                <!-- FILTER TIPE IZIN -->
                <div>
                    <select name="tipe" class="select-filter-custom" onchange="this.form.submit()">
                        <option value="semua" <?php echo $filter_tipe === 'semua' ? 'selected' : ''; ?>>Semua Jenis</option>
                        <option value="cuti" <?php echo $filter_tipe === 'cuti' ? 'selected' : ''; ?>>Cuti</option>
                        <option value="izin" <?php echo $filter_tipe === 'izin' ? 'selected' : ''; ?>>Izin</option>
                        <option value="sakit" <?php echo $filter_tipe === 'sakit' ? 'selected' : ''; ?>>Sakit</option>
                    </select>
                </div>

                <!-- FILTER & RESET BUTTON -->
                <div style="display:flex; gap:6px; align-items:center;">
                    <button type="submit" class="btn-primary-custom" style="padding:9.5px 14px; white-space:nowrap;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span>Filter</span>
                    </button>
                    <?php if (!empty($search) || $filter_st !== 'semua' || $filter_tipe !== 'semua'): ?>
                        <a href="kelola_izin.php" title="Reset Filter" style="padding:9px 10px; background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- CATAT IZIN BUTTON (RIGHT) -->
                <div style="display:flex; justify-content:flex-end;">
                    <button type="button" onclick="toggleInputIzinModal()" class="btn-primary-custom" style="background:linear-gradient(135deg, #059669, #047857); box-shadow:0 3px 10px rgba(5,150,105,0.25); white-space:nowrap;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Catat Izin Pegawai</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- TABLE REKAP DATA PERIZINAN -->
    <div class="table-card">
        <div class="table-header-bar">
            <div style="font-size:15px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Daftar Pengajuan Cuti, Izin &amp; Sakit</span>
            </div>
            <div style="font-size:12px; font-weight:700; color:#64748b;">
                Total: <b style="color:#0f172a;"><?php echo $total_records; ?></b> Berkas &bull; Halaman <?php echo $page; ?> dari <?php echo $total_pages; ?>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:45px;">NO</th>
                        <th style="text-align:left;">PEGAWAI</th>
                        <th style="text-align:left;">PERIODE &amp; DURASI</th>
                        <th>JENIS</th>
                        <th style="text-align:left;">KETERANGAN</th>
                        <th>SURAT DOKTER</th>
                        <th>STATUS</th>
                        <th>TINDAKAN / AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($list_izin)):
                        $no = $offset + 1;
                        foreach ($list_izin as $row):
                            $t_iz = $row['tipe_izin'];
                            $badge_tipe_class = 'badge-tipe-cuti';
                            if ($t_iz === 'izin') $badge_tipe_class = 'badge-tipe-izin';
                            if ($t_iz === 'sakit') $badge_tipe_class = 'badge-tipe-sakit';

                            $st_p = $row['status_persetujuan'] ?? 'disetujui';
                            $p_class = 'status-pill-approved';
                            $p_text  = 'Disetujui';
                            if ($st_p === 'pending') {
                                $p_class = 'status-pill-pending';
                                $p_text  = 'Menunggu';
                            } elseif ($st_p === 'ditolak') {
                                $p_class = 'status-pill-rejected';
                                $p_text  = 'Ditolak';
                            }

                            $tgl_m = date('d/m/Y', strtotime($row['tanggal']));
                            $tgl_s = !empty($row['tgl_selesai']) ? date('d/m/Y', strtotime($row['tgl_selesai'])) : $tgl_m;
                            $dur_days = (strtotime($row['tgl_selesai'] ?: $row['tanggal']) - strtotime($row['tanggal'])) / 86400 + 1;

                            $tgl_display = ($tgl_m === $tgl_s) 
                                ? "<div style='font-weight:800; color:#0f172a;'>{$tgl_m}</div><div style='font-size:11px; color:#64748b;'>1 Hari (Single Day)</div>" 
                                : "<div style='font-weight:800; color:#0f172a;'>{$tgl_m} &ndash; {$tgl_s}</div><div style='font-size:11px; color:#2563eb; font-weight:700;'>{$dur_days} Hari</div>";

                            // Surat Dokter
                            $td_surat = '<span style="color:#cbd5e1;">-</span>';
                            if (!empty($row['surat_dokter']) && file_exists(__DIR__ . '/' . $row['surat_dokter'])) {
                                $file_ext = strtolower(pathinfo($row['surat_dokter'], PATHINFO_EXTENSION));
                                $file_url = h($row['surat_dokter']);
                                if ($file_ext === 'pdf') {
                                    $td_surat = "<a href='{$file_url}' target='_blank' style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; gap:4px;'>
                                                    <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><polyline points='14 2 14 8 20 8'/></svg>
                                                    <span>Lihat PDF</span>
                                                 </a>";
                                } else {
                                    $td_surat = "<button type='button' onclick=\"openDoctorLightbox('{$file_url}', 'Surat Keterangan Dokter &bull; " . h($row['nama']) . "')\" style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:4px;'>
                                                    <svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><path d='M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'/><circle cx='12' cy='10' r='3'/></svg>
                                                    <span>Lihat Surat</span>
                                                 </button>";
                                }
                            } elseif ($t_iz === 'sakit' && $dur_days <= 2) {
                                $td_surat = "<span style='font-size:10.5px; color:#64748b; background:#f1f5f9; padding:2px 6px; border-radius:4px;'>Istirahat (1-2 Hari)</span>";
                            }

                            // Cek rute ke rumah (jika sakit dan ada koordinat)
                            $has_coords = (!empty($row['latitude_rumah']) && !empty($row['longitude_rumah']));
                    ?>
                        <tr>
                            <td><b><?php echo $no++; ?></b></td>
                            <td style="text-align:left;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div style="width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; flex-shrink:0;">
                                        <?php echo strtoupper(mb_substr($row['nama'] ?? 'P', 0, 1)); ?>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-weight:800; color:#0f172a;"><?php echo h($row['nama']); ?></div>
                                        <div style="font-size:11px; color:#64748b;">
                                            PIN: <code style="font-weight:700; color:#0f172a;"><?php echo h($row['pin']); ?></code> &bull; <?php echo h($row['departemen'] ?: 'Umum'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td style="text-align:left;"><?php echo $tgl_display; ?></td>
                            <td>
                                <span class="badge-tipe <?php echo $badge_tipe_class; ?>"><?php echo ucfirst($t_iz); ?></span>
                            </td>
                            <td style="text-align:left; color:#334155; line-height:1.4; max-width:200px;">
                                <?php echo h($row['keterangan'] ?: '-'); ?>
                            </td>
                            <td><?php echo $td_surat; ?></td>
                            <td>
                                <span class="status-pill-badge <?php echo $p_class; ?>"><?php echo $p_text; ?></span>
                                <?php if (!empty($row['approved_by'])): ?>
                                    <div style="font-size:10px; color:#64748b; margin-top:2px; font-weight:600;">
                                        oleh <b><?php echo h($row['approved_by']); ?></b>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap; align-items:center;">
                                    <?php if ($st_p === 'pending'): ?>
                                        <form method="POST" action="kelola_izin.php" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_status_persetujuan">
                                            <input type="hidden" name="id_target" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="status_baru" value="disetujui">
                                            <button type="submit" class="btn-approve" title="Setujui Pengajuan">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Setujui</span>
                                            </button>
                                        </form>
                                        <form method="POST" action="kelola_izin.php" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_status_persetujuan">
                                            <input type="hidden" name="id_target" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="status_baru" value="ditolak">
                                            <button type="submit" class="btn-reject" title="Tolak Pengajuan">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Tolak</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- TOMBOL RUTE KE RUMAH JIKA PEGAWAI SAKIT (RBAC PROTECTED) -->
                                    <?php if ($t_iz === 'sakit' && $has_coords && can_access_route_maps()): ?>
                                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $row['latitude_rumah'] . ',' . $row['longitude_rumah']; ?>" target="_blank" class="btn-route-sick" title="Buka Rute Navigasi Google Maps untuk Menjenguk">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                                            <span>Rute Rumah</span>
                                        </a>
                                    <?php endif; ?>

                                    <!-- TOMBOL HUBUNGI WA -->
                                    <?php if (!empty($row['no_hp'])): ?>
                                        <a href="https://wa.me/<?php echo preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $row['no_hp'])); ?>" target="_blank" style="background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; padding:4px 8px; border-radius:6px; font-size:11px; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" title="Hubungi WA Pegawai">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                            <span>WA</span>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (is_superadmin()): ?>
                                        <form method="POST" action="kelola_izin.php" style="margin:0;" onsubmit="return confirm('Hapus data perizinan ini?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="hapus_izin">
                                            <input type="hidden" name="id_hapus" value="<?php echo $row['id']; ?>">
                                            <button type="submit" style="background:#fff1f2; color:#dc2626; border:1px solid #fca5a5; padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer;" title="Hapus">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="8" style="padding:50px 20px; color:#94a3b8; font-size:14px; text-align:center;">Belum ada data perizinan pada kriteria filter yang dipilih.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <div style="padding:16px 24px; border-top:1px solid #f1f5f9; display:flex; justify-content:center; gap:6px;">
                <?php for ($i = 1; $i <= $total_pages; $i++): 
                    $active_pg = ($i === $page);
                    $pg_url = 'kelola_izin.php?' . http_build_query(array_merge($_GET, ['page' => $i]));
                ?>
                    <a href="<?php echo $pg_url; ?>" style="padding:6px 12px; border-radius:8px; font-size:12px; font-weight:800; text-decoration:none; <?php echo $active_pg ? 'background:#2563eb; color:#fff;' : 'background:#f8fafc; color:#334155; border:1px solid #e2e8f0;'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- MODAL INPUT IZIN PEGAWAI (ADMIN) -->
<div class="photo-modal-overlay" id="modalInputIzin" onclick="closeInputIzinModal(event)">
    <div class="photo-modal-card" style="max-width:480px;" onclick="event.stopPropagation()">
        <div style="background:linear-gradient(135deg, #0b132b, #1c2541); padding:18px 22px; color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:15px; font-weight:800;">Catat Izin Pegawai (Admin)</div>
            <button type="button" onclick="closeInputIzinModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" action="kelola_izin.php" enctype="multipart/form-data" style="padding:22px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="simpan_izin">

            <div style="margin-bottom:14px;">
                <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Pilih Pegawai</label>
                <select name="pin" required class="select-filter-custom" style="width:100%; margin-top:4px;">
                    <option value="">-- Pilih Pegawai --</option>
                    <?php foreach ($master_employees as $emp): ?>
                        <option value="<?php echo h($emp['pin']); ?>">[<?php echo h($emp['pin']); ?>] <?php echo h($emp['nama']); ?> (<?php echo h($emp['departemen'] ?: 'Umum'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
                <div>
                    <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Dari Tanggal</label>
                    <input type="date" name="tgl_mulai" value="<?php echo date('Y-m-d'); ?>" required class="input-date-custom" style="margin-top:4px;">
                </div>
                <div>
                    <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Sampai Tanggal</label>
                    <input type="date" name="tgl_selesai" value="<?php echo date('Y-m-d'); ?>" required class="input-date-custom" style="margin-top:4px;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Jenis Perizinan</label>
                <select name="tipe_izin" required class="select-filter-custom" style="width:100%; margin-top:4px;">
                    <option value="cuti">Cuti</option>
                    <option value="izin">Izin</option>
                    <option value="sakit">Sakit</option>
                </select>
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Surat Dokter (Opsional)</label>
                <input type="file" name="surat_dokter" accept=".jpg,.jpeg,.png,.webp,.pdf" class="input-date-custom" style="margin-top:4px; font-size:12px;">
            </div>

            <div style="margin-bottom:18px;">
                <label style="font-size:11.5px; font-weight:800; color:#334155; text-transform:uppercase;">Keterangan / Alasan</label>
                <textarea name="keterangan" rows="2" placeholder="Tulis alasan..." class="input-date-custom" style="margin-top:4px; resize:vertical;"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="closeInputIzinModal()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:9px 16px; border-radius:10px; font-weight:700; cursor:pointer;">Batal</button>
                <button type="submit" class="btn-primary-custom">Simpan &amp; Setujui</button>
            </div>
        </form>
    </div>
</div>

<!-- LIGHTBOX MODAL SURAT DOKTER -->
<div class="photo-modal-overlay" id="doctorModalOverlay" onclick="closeDoctorLightbox(event)">
    <div class="photo-modal-card" onclick="event.stopPropagation()">
        <div style="position:relative; width:100%; max-height:75vh; background:#0f172a; display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <img id="doctorModalImg" src="" alt="Surat Dokter" style="width:100%; height:auto; max-height:75vh; object-fit:contain;">
            <button type="button" onclick="closeDoctorLightbox()" style="position:absolute; top:12px; right:12px; background:rgba(15,23,42,0.75); color:#fff; border:none; border-radius:50%; width:32px; height:32px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div style="padding:16px 20px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
            <div id="doctorModalTitle" style="font-size:13px; font-weight:800; color:#0f172a;">Surat Keterangan Dokter</div>
            <a id="doctorModalDownload" href="" target="_blank" download style="font-size:12px; font-weight:800; color:#2563eb; text-decoration:none;">Download File</a>
        </div>
    </div>
</div>

<script>
function toggleInputIzinModal() {
    const modal = document.getElementById('modalInputIzin');
    modal.style.display = 'flex';
}

function closeInputIzinModal(e) {
    if (e && e.target && e.target.closest && e.target.closest('.photo-modal-card') && e.target.tagName !== 'BUTTON') {
        return;
    }
    const modal = document.getElementById('modalInputIzin');
    if (modal) modal.style.display = 'none';
}

function openDoctorLightbox(src, title) {
    const overlay = document.getElementById('doctorModalOverlay');
    const img = document.getElementById('doctorModalImg');
    const titleEl = document.getElementById('doctorModalTitle');
    const dlEl = document.getElementById('doctorModalDownload');

    if (img) img.src = src;
    if (titleEl) titleEl.textContent = title;
    if (dlEl) dlEl.href = src;
    if (overlay) overlay.style.display = 'flex';
}

function closeDoctorLightbox(e) {
    if (e && e.target && e.target.closest && e.target.closest('.photo-modal-card') && e.target.tagName !== 'BUTTON') {
        return;
    }
    const overlay = document.getElementById('doctorModalOverlay');
    if (overlay) overlay.style.display = 'none';
}
</script>

<?php render_footer(); ?>
