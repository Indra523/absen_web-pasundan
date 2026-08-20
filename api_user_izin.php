<?php
// ============================================================
// API REALTIME STATUS PERIZINAN USER (JSON)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$conn = getDB();
$pin = get_user_pin();

if (empty($pin) || is_superadmin() || is_rnd() || is_admin()) {
    if (isset($_GET['pin']) && !empty($_GET['pin'])) {
        $pin = trim($_GET['pin']);
    }
}

if (empty($pin)) {
    echo json_encode(['status' => 'error', 'message' => 'PIN empty']);
    exit;
}

// Fetch stats & list perizinan
$stmt = $conn->prepare("SELECT * FROM perizinan WHERE pin = ? ORDER BY tanggal DESC, id DESC");
$stmt->bind_param("s", $pin);
$stmt->execute();
$list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stat = ['total' => count($list), 'pending' => 0, 'disetujui' => 0, 'ditolak' => 0];

$items = [];
foreach ($list as $row) {
    $st_p = $row['status_persetujuan'] ?? 'disetujui';
    if (isset($stat[$st_p])) {
        $stat[$st_p]++;
    }

    $t_iz = $row['tipe_izin'];
    $badge_style = 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
    $badge_text  = ucfirst($t_iz);

    if ($t_iz === 'cuti') {
        $badge_style = 'background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;';
        $badge_text  = 'Cuti';
    } elseif ($t_iz === 'izin') {
        $badge_style = 'background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;';
        $badge_text  = 'Izin';
    } elseif ($t_iz === 'sakit') {
        $badge_style = 'background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff;';
        $badge_text  = 'Sakit';
    }

    $p_badge = 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;';
    $p_text  = 'Disetujui';
    if ($st_p === 'pending') {
        $p_badge = 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;';
        $p_text  = 'Menunggu';
    } elseif ($st_p === 'ditolak') {
        $p_badge = 'background:#fee2e2; color:#be123c; border:1px solid #fca5a5;';
        $p_text  = 'Ditolak';
    }

    $tgl_m = date('d/m/Y', strtotime($row['tanggal']));
    $tgl_s = !empty($row['tgl_selesai']) ? date('d/m/Y', strtotime($row['tgl_selesai'])) : $tgl_m;
    $dur_days = (strtotime($row['tgl_selesai'] ?: $row['tanggal']) - strtotime($row['tanggal'])) / 86400 + 1;

    $periode_fmt = ($tgl_m === $tgl_s) ? $tgl_m : "{$tgl_m} s.d {$tgl_s}";
    $periode_html = ($tgl_m === $tgl_s) 
        ? "<b>{$tgl_m}</b>" 
        : "<b>{$tgl_m}</b> s.d <b>{$tgl_s}</b> <span style='font-size:11px; color:#2563eb; font-weight:700; display:block;'>({$dur_days} Hari)</span>";

    $acc_info = !empty($row['approved_by']) ? '<div style="font-size:10.5px; color:#64748b; margin-top:3px; font-weight:600;">oleh <b>' . h($row['approved_by']) . '</b></div>' : '';

    $items[] = [
        'id'                 => (int)$row['id'],
        'tanggal_fmt'        => $tgl_m,
        'periode_fmt'        => $periode_fmt,
        'periode_html'       => $periode_html,
        'tipe_izin'          => $t_iz,
        'tipe_badge_html'    => '<span class="badge" style="' . $badge_style . ' font-weight:700;">' . $badge_text . '</span>',
        'keterangan'         => h($row['keterangan'] ?: '-'),
        'surat_dokter'       => !empty($row['surat_dokter']) ? h($row['surat_dokter']) : null,
        'status_persetujuan' => $st_p,
        'approved_by'        => h($row['approved_by'] ?? ''),
        'status_badge_html'  => '<span class="badge" style="' . $p_badge . ' font-weight:700;">' . $p_text . '</span>' . $acc_info,
        'created_at_fmt'     => date('d/m/Y H:i', strtotime($row['created_at']))
    ];
}

echo json_encode([
    'status' => 'success',
    'stat'   => $stat,
    'items'  => $items
]);
