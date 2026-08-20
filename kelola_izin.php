<?php
// ============================================================
// HALAMAN MANAJEMEN CUTI / IZIN / SAKIT (GURU & KARYAWAN)
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

        // Handle Surat Dokter (Opsional untuk Admin / Superadmin / TU)
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
            $pesan_error = "PIN Guru/Karyawan, tanggal, dan jenis izin wajib diisi!";
        } elseif (!in_array($tipe_izin, ['cuti', 'izin', 'sakit'])) {
            $pesan_error = "Jenis izin tidak valid!";
        } else {
            $stmt_c = $conn->prepare("SELECT nama, tipe FROM master_karyawan WHERE pin = ?");
            $stmt_c->bind_param("s", $pin);
            $stmt_c->execute();
            $res_c = $stmt_c->get_result();

            if ($res_c->num_rows === 0) {
                $pesan_error = "PIN (<b>" . h($pin) . "</b>) tidak terdaftar di master data!";
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
/* ===== REFINED KELOLA IZIN THEME ===== */
.kelola-izin-wrapper {
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 1160px;
    margin: 0 auto 30px auto;
    width: 100%;
}

/* 4 STAT SUMMARY CARDS (COMPACT & MODERN) */
.stats-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

@media (max-width: 768px) {
    .stats-cards-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
}

.stat-card-clean {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.stat-icon-wrap {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-metric-title {
    font-size: 10.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    line-height: 1.2;
}

.stat-metric-value {
    font-size: 18px;
    font-weight: 800;
    line-height: 1.1;
    color: #0f172a;
    margin-top: 2px;
}

/* FILTER & ACTION TOOLBAR (COMPACT SINGLE ROW) */
.filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px 16px;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.03);
}

.filter-toolbar-grid {
    display: grid;
    grid-template-columns: minmax(180px, 1.8fr) minmax(130px, 1fr) minmax(120px, 1fr) auto auto;
    gap: 8px;
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

.search-input-clean {
    width: 100% !important;
    padding: 8px 10px 8px 32px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    color: #0f172a !important;
    font-weight: 600 !important;
    outline: none !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    background: #ffffff !important;
    transition: border-color 0.15s ease !important;
}

.search-input-clean:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1) !important;
}

.select-filter-clean {
    width: 100% !important;
    padding: 8px 10px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    color: #0f172a !important;
    font-weight: 700 !important;
    outline: none !important;
    background: #ffffff !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    margin: 0 !important;
}

.btn-primary-clean {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #2563eb;
    color: #ffffff !important;
    font-weight: 700;
    font-size: 12.5px;
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.2);
    transition: background 0.15s ease;
    text-decoration: none;
    margin: 0;
    white-space: nowrap;
}
.btn-primary-clean:hover {
    background: #1d4ed8;
}

.btn-green-clean {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #059669;
    color: #ffffff !important;
    font-weight: 700;
    font-size: 12.5px;
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(5, 150, 105, 0.2);
    transition: background 0.15s ease;
    text-decoration: none;
    margin: 0;
    white-space: nowrap;
}
.btn-green-clean:hover {
    background: #047857;
}

/* DATA TABLE CARD */
.table-card-clean {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}

.table-header-clean {
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.table-compact {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    min-width: 780px;
}

.table-compact thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 10.5px;
    letter-spacing: 0.5px;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    text-align: center;
}

.table-compact td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    text-align: center;
    color: #334155;
}

.table-compact tbody tr:hover {
    background: #f8fafc;
}

/* ACTION BUTTONS */
.btn-approve {
    background: #10b981;
    color: #ffffff;
    border: none;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.btn-approve:hover { background: #059669; }

.btn-reject {
    background: #ef4444;
    color: #ffffff;
    border: none;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.btn-reject:hover { background: #dc2626; }

.btn-route-sick {
    background: #059669;
    color: #ffffff !important;
    text-decoration: none;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

/* SEGMENTED CONTROL IN MODAL */
.type-segmented-control {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 10px;
    gap: 3px;
    margin-bottom: 12px;
}

.segmented-option {
    padding: 8px 4px;
    text-align: center;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    transition: all 0.15s ease;
    user-select: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
}

.segmented-option.active {
    background: #ffffff;
    color: #0f172a;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}
.segmented-option.active.card-cuti { color: #1d4ed8; }
.segmented-option.active.card-izin { color: #c2410c; }
.segmented-option.active.card-sakit { color: #7e22ce; }

.duration-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    margin: 2px 0 10px 0;
}

.doctor-upload-box {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 12px;
}

.input-date-clean {
    width: 100% !important;
    padding: 7px 9px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 12px !important;
    color: #0f172a !important;
    background: #ffffff !important;
    font-weight: 600 !important;
    outline: none !important;
    box-sizing: border-box !important;
    font-family: inherit !important;
}

.textarea-clean {
    width: 100% !important;
    padding: 7px 9px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 12px !important;
    color: #0f172a !important;
    background: #ffffff !important;
    font-family: inherit !important;
    resize: vertical !important;
    min-height: 60px !important;
    box-sizing: border-box !important;
    outline: none !important;
}

/* LIGHTBOX MODAL */
.photo-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(4px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.photo-modal-card {
    background: #ffffff;
    border-radius: 14px;
    max-width: 480px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}
</style>

<div class="kelola-izin-wrapper">

    <!-- TOAST NOTIFICATIONS -->
    <?php if (!empty($pesan_sukses)): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 16px; color:#15803d; font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo $pesan_sukses; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pesan_error)): ?>
        <div style="background:#fff1f2; border:1px solid #fca5a5; border-radius:10px; padding:12px 16px; color:#991b1b; font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><?php echo $pesan_error; ?></div>
        </div>
    <?php endif; ?>

    <!-- 4 KPI STAT CARDS -->
    <div class="stats-cards-grid">
        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#eff6ff; color:#2563eb;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Total Pengajuan</div>
                <div class="stat-metric-value"><?php echo $stat['total']; ?></div>
            </div>
        </div>

        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#fffbeb; color:#d97706;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Menunggu Review</div>
                <div class="stat-metric-value" style="color:#d97706;"><?php echo $stat['pending']; ?></div>
            </div>
        </div>

        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#f0fdf4; color:#16a34a;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Disetujui</div>
                <div class="stat-metric-value" style="color:#16a34a;"><?php echo $stat['disetujui']; ?></div>
            </div>
        </div>

        <div class="stat-card-clean">
            <div class="stat-icon-wrap" style="background:#fff1f2; color:#e11d48;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </div>
            <div>
                <div class="stat-metric-title">Ditolak</div>
                <div class="stat-metric-value" style="color:#e11d48;"><?php echo $stat['ditolak']; ?></div>
            </div>
        </div>
    </div>

    <!-- FILTER & ACTION TOOLBAR -->
    <div class="filter-card">
        <form method="GET" action="kelola_izin.php" style="margin:0;">
            <div class="filter-toolbar-grid">
                <!-- SEARCH INPUT WITH ICON -->
                <div style="position:relative; width:100%;">
                    <svg style="position:absolute; left:10px; top:50%; transform:translateY(-50%); pointer-events:none;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Cari Nama / PIN / Alasan..." class="search-input-clean">
                </div>

                <!-- FILTER STATUS -->
                <div>
                    <select name="status" class="select-filter-clean" onchange="this.form.submit()">
                        <option value="semua" <?php echo $filter_st === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                        <option value="pending" <?php echo $filter_st === 'pending' ? 'selected' : ''; ?>>Menunggu (<?php echo $stat['pending']; ?>)</option>
                        <option value="disetujui" <?php echo $filter_st === 'disetujui' ? 'selected' : ''; ?>>Disetujui (<?php echo $stat['disetujui']; ?>)</option>
                        <option value="ditolak" <?php echo $filter_st === 'ditolak' ? 'selected' : ''; ?>>Ditolak (<?php echo $stat['ditolak']; ?>)</option>
                    </select>
                </div>

                <!-- FILTER TIPE IZIN -->
                <div>
                    <select name="tipe" class="select-filter-clean" onchange="this.form.submit()">
                        <option value="semua" <?php echo $filter_tipe === 'semua' ? 'selected' : ''; ?>>Semua Jenis</option>
                        <option value="cuti" <?php echo $filter_tipe === 'cuti' ? 'selected' : ''; ?>>Cuti</option>
                        <option value="izin" <?php echo $filter_tipe === 'izin' ? 'selected' : ''; ?>>Izin</option>
                        <option value="sakit" <?php echo $filter_tipe === 'sakit' ? 'selected' : ''; ?>>Sakit</option>
                    </select>
                </div>

                <!-- FILTER & RESET BUTTON -->
                <div style="display:flex; gap:6px; align-items:center;">
                    <button type="submit" class="btn-primary-clean">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span>Filter</span>
                    </button>
                    <?php if (!empty($search) || $filter_st !== 'semua' || $filter_tipe !== 'semua'): ?>
                        <a href="kelola_izin.php" title="Reset Filter" style="padding:8px 9px; background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- CATAT IZIN BUTTON (GURU / KARYAWAN) -->
                <div style="display:flex; justify-content:flex-end;">
                    <button type="button" onclick="toggleInputIzinModal()" class="btn-green-clean">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Catat Izin Guru / Karyawan</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- TABLE REKAP DATA PERIZINAN -->
    <div class="table-card-clean">
        <div class="table-header-clean">
            <div style="font-size:13.5px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Daftar Pengajuan Cuti, Izin &amp; Sakit Guru / Karyawan</span>
            </div>
            <div style="font-size:11.5px; font-weight:700; color:#64748b;">
                Total: <b style="color:#0f172a;"><?php echo $total_records; ?></b> Berkas
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="table-compact">
                <thead>
                    <tr>
                        <th style="width:35px;">NO</th>
                        <th style="text-align:left;">GURU / KARYAWAN</th>
                        <th style="text-align:left;">PERIODE</th>
                        <th>JENIS</th>
                        <th style="text-align:left;">KETERANGAN</th>
                        <th>SURAT DOKTER</th>
                        <th>STATUS</th>
                        <th>TINDAKAN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($list_izin)):
                        $no = $offset + 1;
                        foreach ($list_izin as $row):
                            $t_iz = $row['tipe_izin'];
                            $t_bg = '#eff6ff'; $t_col = '#1d4ed8'; $t_brd = '#bfdbfe';
                            if ($t_iz === 'izin') { $t_bg = '#fff7ed'; $t_col = '#c2410c'; $t_brd = '#ffedd5'; }
                            if ($t_iz === 'sakit') { $t_bg = '#faf5ff'; $t_col = '#7e22ce'; $t_brd = '#e9d5ff'; }

                            $st_p = $row['status_persetujuan'] ?? 'disetujui';
                            $p_bg = '#dcfce7'; $p_col = '#15803d'; $p_lbl = 'Disetujui';
                            if ($st_p === 'pending') { $p_bg = '#fef3c7'; $p_col = '#92400e'; $p_lbl = 'Menunggu'; }
                            if ($st_p === 'ditolak') { $p_bg = '#fee2e2'; $p_col = '#be123c'; $p_lbl = 'Ditolak'; }

                            $tgl_m = date('d/m/Y', strtotime($row['tanggal']));
                            $tgl_s = !empty($row['tgl_selesai']) ? date('d/m/Y', strtotime($row['tgl_selesai'])) : $tgl_m;
                            $dur_days = (strtotime($row['tgl_selesai'] ?: $row['tanggal']) - strtotime($row['tanggal'])) / 86400 + 1;

                            $tgl_display = ($tgl_m === $tgl_s) 
                                ? "<div style='font-weight:700; color:#0f172a;'>{$tgl_m}</div><div style='font-size:10px; color:#64748b;'>1 Hari</div>" 
                                : "<div style='font-weight:700; color:#0f172a;'>{$tgl_m} - {$tgl_s}</div><div style='font-size:10px; color:#2563eb; font-weight:700;'>{$dur_days} Hari</div>";

                            // Surat Dokter
                            $td_surat = '<span style="color:#cbd5e1;">-</span>';
                            if (!empty($row['surat_dokter']) && file_exists(__DIR__ . '/' . $row['surat_dokter'])) {
                                $file_ext = strtolower(pathinfo($row['surat_dokter'], PATHINFO_EXTENSION));
                                $file_url = h($row['surat_dokter']);
                                if ($file_ext === 'pdf') {
                                    $td_surat = "<a href='{$file_url}' target='_blank' style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:2px 6px; border-radius:4px; font-size:10.5px; font-weight:700; text-decoration:none;'>Lihat PDF</a>";
                                } else {
                                    $td_surat = "<button type='button' onclick=\"openDoctorLightbox('{$file_url}', 'Surat Keterangan Dokter &bull; " . h($row['nama']) . "')\" style='background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; padding:2px 6px; border-radius:4px; font-size:10.5px; font-weight:700; cursor:pointer;'>Lihat Surat</button>";
                                }
                            } elseif ($t_iz === 'sakit' && $dur_days <= 2) {
                                $td_surat = "<span style='font-size:10px; color:#64748b;'>Istirahat</span>";
                            }

                            $has_coords = (!empty($row['latitude_rumah']) && !empty($row['longitude_rumah']));
                    ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td style="text-align:left;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div style="width:30px; height:30px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:12px; flex-shrink:0;">
                                        <?php echo strtoupper(mb_substr($row['nama'] ?? 'G', 0, 1)); ?>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-weight:800; color:#0f172a; font-size:12.5px;"><?php echo h($row['nama']); ?></div>
                                        <div style="font-size:10.5px; color:#64748b;">
                                            PIN: <b><?php echo h($row['pin']); ?></b> &bull; <?php echo h($row['departemen'] ?: 'Umum'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td style="text-align:left;"><?php echo $tgl_display; ?></td>
                            <td>
                                <span style="background:<?php echo $t_bg; ?>; color:<?php echo $t_col; ?>; border:1px solid <?php echo $t_brd; ?>; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700;">
                                    <?php echo ucfirst($t_iz); ?>
                                </span>
                            </td>
                            <td style="text-align:left; color:#334155; line-height:1.35; max-width:200px; font-size:12px;">
                                <?php echo h($row['keterangan'] ?: '-'); ?>
                            </td>
                            <td><?php echo $td_surat; ?></td>
                            <td>
                                <span style="background:<?php echo $p_bg; ?>; color:<?php echo $p_col; ?>; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700;">
                                    <?php echo $p_lbl; ?>
                                </span>
                                <?php if (!empty($row['approved_by'])): ?>
                                    <div style="font-size:9.5px; color:#64748b; margin-top:2px;">
                                        oleh <b><?php echo h($row['approved_by']); ?></b>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap; align-items:center;">
                                    <?php if ($st_p === 'pending'): ?>
                                        <form method="POST" action="kelola_izin.php" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_status_persetujuan">
                                            <input type="hidden" name="id_target" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="status_baru" value="disetujui">
                                            <button type="submit" class="btn-approve" title="Setujui Pengajuan">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Setujui</span>
                                            </button>
                                        </form>
                                        <form method="POST" action="kelola_izin.php" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_status_persetujuan">
                                            <input type="hidden" name="id_target" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="status_baru" value="ditolak">
                                            <button type="submit" class="btn-reject" title="Tolak Pengajuan">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Tolak</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- TOMBOL RUTE KE RUMAH JIKA SAKIT -->
                                    <?php if ($t_iz === 'sakit' && $has_coords && can_access_route_maps()): ?>
                                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $row['latitude_rumah'] . ',' . $row['longitude_rumah']; ?>" target="_blank" class="btn-route-sick" title="Rute ke Rumah Guru / Karyawan">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                                            <span>Rute Rumah</span>
                                        </a>
                                    <?php endif; ?>

                                    <!-- TOMBOL HUBUNGI WA -->
                                    <?php if (!empty($row['no_hp'])): ?>
                                        <a href="https://wa.me/<?php echo preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $row['no_hp'])); ?>" target="_blank" style="background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; padding:3px 6px; border-radius:6px; font-size:10.5px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:2px;" title="Hubungi WhatsApp">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                            <span>WA</span>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (is_superadmin()): ?>
                                        <form method="POST" action="kelola_izin.php" style="margin:0;" onsubmit="return confirm('Hapus data perizinan ini?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="hapus_izin">
                                            <input type="hidden" name="id_hapus" value="<?php echo $row['id']; ?>">
                                            <button type="submit" style="background:#fff1f2; color:#dc2626; border:1px solid #fca5a5; padding:3px 6px; border-radius:6px; font-size:10.5px; font-weight:700; cursor:pointer;" title="Hapus">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="8" style="padding:40px 20px; color:#94a3b8; font-size:13px; text-align:center;">Belum ada data perizinan pada kriteria filter yang dipilih.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <div style="padding:12px 18px; border-top:1px solid #f1f5f9; display:flex; justify-content:center; gap:4px;">
                <?php for ($i = 1; $i <= $total_pages; $i++): 
                    $active_pg = ($i === $page);
                    $pg_url = 'kelola_izin.php?' . http_build_query(array_merge($_GET, ['page' => $i]));
                ?>
                    <a href="<?php echo $pg_url; ?>" style="padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:700; text-decoration:none; <?php echo $active_pg ? 'background:#2563eb; color:#fff;' : 'background:#f8fafc; color:#334155; border:1px solid #e2e8f0;'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- REFINED MODAL INPUT IZIN GURU / KARYAWAN (ADMIN) -->
<div class="photo-modal-overlay" id="modalInputIzin" onclick="closeInputIzinModal(event)">
    <div class="photo-modal-card" style="max-width:440px;" onclick="event.stopPropagation()">
        <!-- CLEAN MODAL HEADER -->
        <div style="background:#ffffff; border-bottom:1px solid #f1f5f9; padding:14px 18px; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:14px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <span>Catat Izin Guru / Karyawan</span>
            </div>
            <button type="button" onclick="closeInputIzinModal()" style="background:none; border:none; color:#94a3b8; font-size:20px; line-height:1; cursor:pointer; padding:0 4px;">&times;</button>
        </div>

        <form method="POST" action="kelola_izin.php" enctype="multipart/form-data" style="padding:16px 18px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="simpan_izin">

            <!-- PILIH GURU / KARYAWAN -->
            <div style="margin-bottom:10px;">
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px; display:block;">Pilih Guru / Karyawan</label>
                <select name="pin" required class="input-date-clean" style="font-size:12.5px; padding:8px 10px;">
                    <option value="">-- Pilih Guru / Karyawan --</option>
                    <?php foreach ($master_employees as $emp): ?>
                        <option value="<?php echo h($emp['pin']); ?>">[<?php echo h($emp['pin']); ?>] <?php echo h($emp['nama']); ?> (<?php echo h($emp['departemen'] ?: 'Umum'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- RENTANG TANGGAL -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:4px;">
                <div style="min-width:0;">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px; display:block;">Dari Tanggal</label>
                    <input type="date" id="admin_tgl_mulai" name="tgl_mulai" value="<?php echo date('Y-m-d'); ?>" required class="input-date-clean" onchange="handleAdminDateChange()">
                </div>
                <div style="min-width:0;">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px; display:block;">Sampai Tanggal</label>
                    <input type="date" id="admin_tgl_selesai" name="tgl_selesai" value="<?php echo date('Y-m-d'); ?>" required class="input-date-clean" onchange="handleAdminDateChange()">
                </div>
            </div>

            <!-- DURATION CHIP -->
            <div>
                <div id="adminDurationPill" class="duration-chip">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span id="adminDurationText">Durasi: 1 Hari</span>
                </div>
            </div>

            <!-- JENIS PERIZINAN (SEGMENTED CONTROL) -->
            <div style="margin-bottom:10px;">
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px; display:block;">Jenis Perizinan</label>
                <input type="hidden" id="admin_tipe_izin_val" name="tipe_izin" value="cuti" required>

                <div class="type-segmented-control">
                    <div class="segmented-option active card-cuti" id="adm_card_cuti" onclick="setAdminIzinType('cuti')">
                        <span class="seg-title">Cuti</span>
                        <span class="seg-sub">Resmi</span>
                    </div>
                    <div class="segmented-option" id="adm_card_izin" onclick="setAdminIzinType('izin')">
                        <span class="seg-title">Izin</span>
                        <span class="seg-sub">Pribadi/Dinas</span>
                    </div>
                    <div class="segmented-option" id="adm_card_sakit" onclick="setAdminIzinType('sakit')">
                        <span class="seg-title">Sakit</span>
                        <span class="seg-sub">Surat Dokter</span>
                    </div>
                </div>
            </div>

            <!-- SURAT DOKTER (OPSIONAL UNTUK ADMIN) -->
            <div id="adminDoctorSection" class="doctor-upload-box optional" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <span style="font-size:11.5px; font-weight:800; color:#0f172a;">Surat Dokter</span>
                    <span style="font-size:9.5px; font-weight:800; padding:2px 6px; border-radius:4px; background:#dcfce7; color:#15803d;">OPSIONAL</span>
                </div>
                <div style="font-size:11px; color:#15803d; margin-bottom:6px;">Upload surat dokter bersifat opsional bila dicatat langsung oleh Admin/Tata Usaha.</div>
                <input type="file" name="surat_dokter" accept=".jpg,.jpeg,.png,.webp,.pdf" class="input-date-clean" style="font-size:11.5px; padding:5px 8px;">
            </div>

            <!-- KETERANGAN / ALASAN -->
            <div style="margin-bottom:14px;">
                <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px; display:block;">Keterangan / Alasan</label>
                <textarea name="keterangan" rows="2" placeholder="Tulis alasan izin..." class="textarea-clean"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:6px;">
                <button type="button" onclick="closeInputIzinModal()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:7px 12px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">Batal</button>
                <button type="submit" class="btn-primary-clean">Simpan &amp; Setujui</button>
            </div>
        </form>
    </div>
</div>

<!-- LIGHTBOX MODAL SURAT DOKTER -->
<div class="photo-modal-overlay" id="doctorModalOverlay" onclick="closeDoctorLightbox(event)">
    <div class="photo-modal-card" onclick="event.stopPropagation()">
        <div style="position:relative; width:100%; max-height:75vh; background:#0f172a; display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <img id="doctorModalImg" src="" alt="Surat Dokter" style="width:100%; height:auto; max-height:75vh; object-fit:contain;">
            <button type="button" onclick="closeDoctorLightbox()" style="position:absolute; top:8px; right:8px; background:rgba(15,23,42,0.75); color:#fff; border:none; border-radius:50%; width:28px; height:28px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                &times;
            </button>
        </div>
        <div style="padding:12px 16px; display:flex; align-items:center; justify-content:space-between;">
            <div id="doctorModalTitle" style="font-size:12px; font-weight:700; color:#0f172a;">Surat Keterangan Dokter</div>
            <a id="doctorModalDownload" href="" target="_blank" download style="font-size:11.5px; font-weight:700; color:#2563eb; text-decoration:none;">Download File</a>
        </div>
    </div>
</div>

<script>
function toggleInputIzinModal() {
    const modal = document.getElementById('modalInputIzin');
    modal.style.display = 'flex';
    handleAdminDateChange();
}

function closeInputIzinModal(e) {
    if (e && e.target && e.target.closest && e.target.closest('.photo-modal-card') && e.target.tagName !== 'BUTTON') {
        return;
    }
    const modal = document.getElementById('modalInputIzin');
    if (modal) modal.style.display = 'none';
}

function setAdminIzinType(type) {
    document.getElementById('admin_tipe_izin_val').value = type;
    document.querySelectorAll('#modalInputIzin .segmented-option').forEach(el => {
        el.classList.remove('active', 'card-cuti', 'card-izin', 'card-sakit');
    });
    
    const activeEl = document.getElementById('adm_card_' + type);
    if (activeEl) {
        activeEl.classList.add('active', 'card-' + type);
    }

    const docSec = document.getElementById('adminDoctorSection');
    if (docSec) {
        docSec.style.display = (type === 'sakit') ? 'block' : 'none';
    }
}

function handleAdminDateChange() {
    const t1 = document.getElementById('admin_tgl_mulai').value;
    const t2 = document.getElementById('admin_tgl_selesai').value;
    const pill = document.getElementById('adminDurationPill');
    const textEl = document.getElementById('adminDurationText');

    if (!t1 || !t2 || !pill) return;

    const d1 = new Date(t1);
    const d2 = new Date(d2);

    if (d2 < d1) {
        textEl.textContent = 'Tanggal tidak valid';
        return;
    }

    const diffTime = Math.abs(new Date(t2) - new Date(t1));
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

    textEl.textContent = (diffDays === 1) ? 'Durasi: 1 Hari' : `Durasi: ${diffDays} Hari`;
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
