<?php
// ============================================================
// API REAL-TIME NOTIFIKASI SYSTEM (JSON)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Cek permission notifikasi
if (!can_access_page('notifikasi')) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
    exit;
}

$conn    = getDB();
$role    = $_SESSION['role'] ?? 'user';
$user_id = (int)$_SESSION['user_id'];
$can_manage_izin = can_access_page('kelola_izin');

// Konstruksi WHERE clause sesuai hak akses & role:
// 1. Role Pengelola (can_access_page('kelola_izin') = true):
//    - Menerima notifikasi pengajuan perizinan baru dari user (target_role IN ('kelola_izin','admin','all') atau type = 'perizinan')
//    - Menerima notifikasi personal milik dirinya sendiri (user_id = $user_id)
//    - Khusus Tata Usaha: Hanya menerima notifikasi pengajuan dari Karyawan (bukan Guru)
// 2. Role User Biasa (can_access_page('kelola_izin') = false):
//    - HANYA menerima notifikasi personal milik dirinya sendiri (user_id = $user_id AND (target_role = 'user' OR type = 'status_change'))
//    - Tidak akan melihat notifikasi pengajuan perizinan user lain

$where_clause = "WHERE 1=1";
$params = [];
$types  = "";

if ($can_manage_izin) {
    if ($role === 'tatausaha') {
        $where_clause .= " AND (
            (notifications.user_id = ? AND (notifications.target_role = 'user' OR notifications.type = 'status_change')) 
            OR 
            ((notifications.type = 'perizinan' OR notifications.target_role IN ('admin', 'kelola_izin', 'all')) 
             AND (notifications.user_id IS NULL OR NOT EXISTS (SELECT 1 FROM users u JOIN master_karyawan mk ON u.pin = mk.pin WHERE u.id = notifications.user_id AND mk.tipe = 'guru')))
        )";
        $params = [$user_id];
        $types  = "i";
    } else {
        $where_clause .= " AND (
            (notifications.user_id = ? AND (notifications.target_role = 'user' OR notifications.type = 'status_change')) 
            OR 
            (notifications.type = 'perizinan' OR notifications.target_role IN ('admin', 'kelola_izin', 'all'))
        )";
        $params = [$user_id];
        $types  = "i";
    }
} else {
    $where_clause .= " AND notifications.user_id = ? AND (notifications.target_role = 'user' OR notifications.type = 'status_change')";
    $params = [$user_id];
    $types  = "i";
}

// PROSES POST: MARK AS READ & DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // TANDAI SEMUA DIBACA
    if ($action === 'mark_all_read') {
        $sql_mark = "UPDATE notifications SET is_read = 1 {$where_clause} AND is_read = 0";
        $stmt_m = $conn->prepare($sql_mark);
        if (!empty($params)) {
            $stmt_m->bind_param($types, ...$params);
        }
        $stmt_m->execute();
        echo json_encode(['status' => 'success', 'message' => 'Semua notifikasi ditandai dibaca']);
        exit;
    }

    // TANDAI SATU DIBACA
    if ($action === 'mark_read') {
        $notif_id = (int)($_POST['id'] ?? 0);
        if ($notif_id > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $stmt->bind_param("i", $notif_id);
            $stmt->execute();
            echo json_encode(['status' => 'success', 'message' => 'Notifikasi ditandai dibaca']);
            exit;
        }
    }

    // HAPUS SATU NOTIFIKASI
    if ($action === 'delete') {
        $notif_id = (int)($_POST['id'] ?? 0);
        if ($notif_id > 0) {
            $sql_del = "DELETE FROM notifications {$where_clause} AND notifications.id = ?";
            $del_params = array_merge($params, [$notif_id]);
            $del_types  = $types . "i";
            $stmt_del   = $conn->prepare($sql_del);
            $stmt_del->bind_param($del_types, ...$del_params);
            $stmt_del->execute();
            echo json_encode(['status' => 'success', 'message' => 'Notifikasi berhasil dihapus']);
            exit;
        }
    }

    // HAPUS SEMUA NOTIFIKASI
    if ($action === 'delete_all') {
        $sql_del_all = "DELETE FROM notifications {$where_clause}";
        $stmt_da = $conn->prepare($sql_del_all);
        if (!empty($params)) {
            $stmt_da->bind_param($types, ...$params);
        }
        $stmt_da->execute();
        echo json_encode(['status' => 'success', 'message' => 'Semua notifikasi berhasil dihapus']);
        exit;
    }
}

// Hitung Unread
$sql_count = "SELECT COUNT(*) as unread FROM notifications {$where_clause} AND is_read = 0";
$stmt_c = $conn->prepare($sql_count);
if (!empty($params)) {
    $stmt_c->bind_param($types, ...$params);
}
$stmt_c->execute();
$unread_count = (int)($stmt_c->get_result()->fetch_assoc()['unread'] ?? 0);

// Fetch List 10 Terkini
$sql_list = "SELECT * FROM notifications {$where_clause} ORDER BY created_at DESC LIMIT 10";
$stmt_l = $conn->prepare($sql_list);
if (!empty($params)) {
    $stmt_l->bind_param($types, ...$params);
}
$stmt_l->execute();
$res_l = $stmt_l->get_result();

$items = [];
while ($r = $res_l->fetch_assoc()) {
    // Time ago
    $created_time = strtotime($r['created_at']);
    $diff = time() - $created_time;
    if ($diff < 60) $time_str = "Baru saja";
    elseif ($diff < 3600) $time_str = floor($diff / 60) . " mnt lalu";
    elseif ($diff < 86400) $time_str = floor($diff / 3600) . " jam lalu";
    else $time_str = date('d/m H:i', $created_time);

    $items[] = [
        'id'         => (int)$r['id'],
        'title'      => h($r['title']),
        'message'    => h($r['message']),
        'type'       => h($r['type']),
        'link'       => h($r['link']),
        'is_read'    => (int)$r['is_read'],
        'time_str'   => $time_str,
        'created_at' => $r['created_at']
    ];
}

echo json_encode([
    'status'       => 'success',
    'unread_count' => $unread_count,
    'items'        => $items
]);
