<?php
// ============================================================
// API ENDPOINT: Status Online Real-Time User System (Superadmin Only)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';
require_role(['superadmin']);

// Release lock file sesi segera agar request reload/F5 tidak terblokir
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$conn = getDB();

function format_last_active_api($datetime_str) {
    if (empty($datetime_str)) {
        return "<span style='color:#94a3b8; font-style:italic;'>Belum pernah</span>";
    }
    $time = strtotime($datetime_str);
    $diff = time() - $time;

    if ($diff < 35) {
        return "<span style='color:#10b981; font-weight:700;'>Baru saja</span>";
    } elseif ($diff < 3600) {
        $m = floor($diff / 60);
        return $m > 0 ? "{$m} menit yang lalu" : "Baru saja";
    } elseif (date('Y-m-d', $time) === date('Y-m-d')) {
        return "Hari ini " . date('H:i', $time);
    } elseif (date('Y-m-d', $time) === date('Y-m-d', strtotime('-1 day'))) {
        return "Kemarin " . date('H:i', $time);
    } else {
        return date('d/m/Y H:i', $time);
    }
}

$semua_user = $conn->query("SELECT id, username, role, last_active FROM users ORDER BY id ASC");
$online_count = 0;
$users = [];

if ($semua_user && $semua_user->num_rows > 0) {
    while ($row = $semua_user->fetch_assoc()) {
        $last_act_ts = !empty($row['last_active']) ? strtotime($row['last_active']) : 0;
        $is_online = (!empty($row['last_active']) && (time() - $last_act_ts) <= 35);
        if ($is_online) $online_count++;

        $users[] = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'role' => $row['role'],
            'is_online' => $is_online,
            'last_active_html' => format_last_active_api($row['last_active']),
            'is_self' => ((int)$row['id'] === (int)($_SESSION['user_id'] ?? 0))
        ];
    }
}

echo json_encode([
    'success' => true,
    'online_count' => $online_count,
    'total_users' => count($users),
    'users' => $users,
    'last_update' => date('H:i:s')
]);
exit;
