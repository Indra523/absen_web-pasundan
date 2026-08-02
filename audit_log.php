<?php
// ============================================================
// HALAMAN AUDIT LOG SYSTEM (RIWAYAT AKTIVITAS ADMIN)
// Akses: Superadmin & RnD
// Fitur: Pencarian, Filter tanggal, Filter jenis aksi & paginasi data
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('audit_log')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();

$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = 25;
$offset      = ($page - 1) * $limit;

$search      = trim($_GET['q'] ?? '');
$filter_user = trim($_GET['username'] ?? '');
$filter_act  = trim($_GET['action_type'] ?? '');
$tgl_dari    = trim($_GET['tgl_dari'] ?? date('Y-m-01'));
$tgl_sampai  = trim($_GET['tgl_sampai'] ?? date('Y-m-d'));

$where = ["DATE(audit_logs.created_at) BETWEEN ? AND ?"];
$params = [$tgl_dari, $tgl_sampai];
$types  = 'ss';

if (!empty($search)) {
    $where[] = "(audit_logs.username LIKE ? OR audit_logs.details LIKE ? OR audit_logs.ip_address LIKE ?)";
    $s_term = "%{$search}%";
    $params[] = $s_term; $params[] = $s_term; $params[] = $s_term;
    $types .= 'sss';
}

if (!empty($filter_user)) {
    $where[] = "audit_logs.username = ?";
    $params[] = $filter_user;
    $types .= 's';
}

if (!empty($filter_act)) {
    $where[] = "audit_logs.action = ?";
    $params[] = $filter_act;
    $types .= 's';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Total data
$stmt_cnt = $conn->prepare("SELECT COUNT(*) as total FROM audit_logs {$where_sql}");
$stmt_cnt->bind_param($types, ...$params);
$stmt_cnt->execute();
$total_records = $stmt_cnt->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages   = ceil($total_records / $limit);

// Data audit logs
$query = "SELECT * FROM audit_logs {$where_sql} ORDER BY id DESC LIMIT ? OFFSET ?";
$params_data = array_merge($params, [$limit, $offset]);
$types_data  = $types . 'ii';

$stmt_data = $conn->prepare($query);
$stmt_data->bind_param($types_data, ...$params_data);
$stmt_data->execute();
$logs = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);

// Distinct usernames for filter
$res_u = $conn->query("SELECT DISTINCT username FROM audit_logs ORDER BY username ASC");
$user_options = $res_u ? $res_u->fetch_all(MYSQLI_ASSOC) : [];

// Distinct actions for filter
$res_a = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
$action_options = $res_a ? $res_a->fetch_all(MYSQLI_ASSOC) : [];

render_header("Audit Log System", "audit_log");
?>

<style>
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 16px;
    flex-wrap: wrap;
    gap: 10px;
}
.pagination-btn {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
}
.pagination-btn:hover { background: #f1f5f9; }
.pagination-btn.active { background: var(--primary-gradient); color: #fff; border-color: transparent; }
</style>

<div class="card">
    <div class="card-header" style="flex-wrap:wrap; gap:12px;">
        <div class="card-title">
            <span>📜 Catatan Aktivitas Sistem (Audit Log Admin)</span>
            <span class="badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:11px;">Total: <?php echo $total_records; ?> Event</span>
        </div>

        <div style="font-size:12.5px; color:#64748b;">
            Monitoring keamanan & jejak histori operasi admin
        </div>
    </div>

    <!-- FILTER PANEL -->
    <form method="GET" action="audit_log.php" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:20px; display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px; align-items:end;">
        <div>
            <label style="font-size:12px; margin-bottom:4px;">📅 Dari Tanggal</label>
            <input type="date" name="tgl_dari" value="<?php echo h($tgl_dari); ?>" style="margin-bottom:0; padding:8px 10px; font-size:13px;">
        </div>

        <div>
            <label style="font-size:12px; margin-bottom:4px;">📅 Sampai Tanggal</label>
            <input type="date" name="tgl_sampai" value="<?php echo h($tgl_sampai); ?>" style="margin-bottom:0; padding:8px 10px; font-size:13px;">
        </div>

        <div>
            <label style="font-size:12px; margin-bottom:4px;">👤 User Admin</label>
            <select name="username" style="margin-bottom:0; padding:8px 10px; font-size:13px;">
                <option value="">Semua User</option>
                <?php foreach ($user_options as $uo): ?>
                    <option value="<?php echo h($uo['username']); ?>" <?php echo $filter_user === $uo['username'] ? 'selected' : ''; ?>><?php echo h($uo['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="font-size:12px; margin-bottom:4px;">⚡ Jenis Aksi</label>
            <select name="action_type" style="margin-bottom:0; padding:8px 10px; font-size:13px;">
                <option value="">Semua Aksi</option>
                <?php foreach ($action_options as $ao): ?>
                    <option value="<?php echo h($ao['action']); ?>" <?php echo $filter_act === $ao['action'] ? 'selected' : ''; ?>><?php echo h($ao['action']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="font-size:12px; margin-bottom:4px;">🔍 Kata Kunci</label>
            <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="IP / Detail..." style="margin-bottom:0; padding:8px 10px; font-size:13px;">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="width:100%; min-height:38px; padding:8px 14px; font-size:13px;">
                🔍 Filter Log
            </button>
        </div>
    </form>

    <!-- TABEL AUDIT LOG -->
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Waktu (WIB)</th>
                    <th>User Admin</th>
                    <th>Role</th>
                    <th>Jenis Aksi</th>
                    <th style="text-align:left;">Detail Aktivitas</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): 
                    $no = $offset + 1;
                    foreach ($logs as $l):
                        $action_badge = "background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;";
                        if (str_contains($l['action'], 'LOGIN')) {
                            $action_badge = "background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;";
                        } elseif (str_contains($l['action'], 'LOGOUT')) {
                            $action_badge = "background:#fee2e2; color:#be123c; border:1px solid #fca5a5;";
                        } elseif (str_contains($l['action'], 'HAPUS') || str_contains($l['action'], 'DELETE')) {
                            $action_badge = "background:#ffedd5; color:#c2410c; border:1px solid #fed7aa;";
                        } elseif (str_contains($l['action'], 'INPUT') || str_contains($l['action'], 'SIMPAN')) {
                            $action_badge = "background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;";
                        }
                ?>
                    <tr>
                        <td><b><?php echo $no++; ?></b></td>
                        <td><b><?php echo date('d/m/Y H:i:s', strtotime($l['created_at'])); ?></b></td>
                        <td style="font-weight:700; color:#0f172a;"><?php echo h($l['username']); ?></td>
                        <td>
                            <span class="badge" style="<?php echo $l['role'] === 'superadmin' ? 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;' : 'background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'; ?>">
                                <?php echo ucfirst($l['role']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge" style="<?php echo $action_badge; ?>"><?php echo h($l['action']); ?></span>
                        </td>
                        <td style="text-align:left; font-size:12.5px; color:#334155;">
                            <?php echo h($l['details'] ?: '-'); ?>
                        </td>
                        <td><code><?php echo h($l['ip_address'] ?: '-'); ?></code></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" style="padding:30px; color:#94a3b8;">Belum ada log aktivitas yang sesuai dengan filter.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINASI -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <span style="font-size:12.5px; color:#64748b;">Menampilkan halaman <b><?php echo $page; ?></b> dari <b><?php echo $total_pages; ?></b> (Total <?php echo $total_records; ?> log)</span>
            
            <div style="display:flex; gap:4px;">
                <?php
                $url_params = "tgl_dari=" . urlencode($tgl_dari) . "&tgl_sampai=" . urlencode($tgl_sampai) . "&username=" . urlencode($filter_user) . "&action_type=" . urlencode($filter_act) . "&q=" . urlencode($search);
                ?>

                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo ($page - 1); ?>&<?php echo $url_params; ?>" class="pagination-btn">‹ Prev</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="?page=<?php echo $p; ?>&<?php echo $url_params; ?>" class="pagination-btn <?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo ($page + 1); ?>&<?php echo $url_params; ?>" class="pagination-btn">Next ›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
render_footer();
?>
