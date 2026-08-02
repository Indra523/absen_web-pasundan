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

$conn = getDB();
$role = $_SESSION['role'] ?? 'admin';
$user_id = (int)$_SESSION['user_id'];

// PROSES POST: MARK AS READ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_all_read') {
        if ($role === 'superadmin') {
            $conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND (target_role = ? OR target_role = 'all' OR user_id = ?)");
            $stmt->bind_param("si", $role, $user_id);
            $stmt->execute();
        }
        echo json_encode(['status' => 'success', 'message' => 'Semua notifikasi ditandai dibaca']);
        exit;
    }

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
}

// PROSES GET: FETCH UNREAD COUNT & LIST
$where_clause = "WHERE 1=1";
$params = [];
$types = "";

if ($role !== 'superadmin') {
    $where_clause .= " AND (notifications.target_role = ? OR notifications.target_role = 'all' OR notifications.user_id = ?)";
    $params = [$role, $user_id];
    $types = "si";
}

// KHUSUS ROLE TATAUSAHA: Hanya terima notifikasi pengajuan dari Karyawan (bukan Guru)
if ($role === 'tatausaha') {
    $where_clause .= " AND (notifications.user_id IS NULL OR NOT EXISTS (SELECT 1 FROM users u JOIN master_karyawan mk ON u.pin = mk.pin WHERE u.id = notifications.user_id AND mk.tipe = 'guru'))";
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
