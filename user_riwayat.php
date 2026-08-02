<?php
// ============================================================
// PORTAL MANDIRI - RIWAYAT PRESENSI SAYA (ROLE USER)
// Desain Premium, Ringkas & Sleek UI
// ============================================================

require_once __DIR__ . '/layout.php';
if (!can_access_page('user_riwayat')) {
    header("Location: index.php?error=access_denied");
    exit;
}

$conn = getDB();
$pin  = get_user_pin();

// Jika admin/superadmin membuka halaman ini, izinkan pilih PIN via GET
if (empty($pin) || is_superadmin() || is_rnd()) {
    if (isset($_GET['pin'])) {
        $pin = trim($_GET['pin']);
    }
}

$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 20;
$offset     = ($page - 1) * $limit;

$tgl_mulai   = trim($_GET['tgl_mulai'] ?? date('Y-m-01'));
$tgl_selesai = trim($_GET['tgl_selesai'] ?? date('Y-m-d'));

$logs = [];
$total_records = 0;
$total_pages   = 0;
$total_masuk   = 0;
$total_pulang  = 0;
$detail_user   = null;

if (!empty($pin)) {
    // 1. Detail Karyawan
    $stmt_u = $conn->prepare("SELECT * FROM master_karyawan WHERE pin = ?");
    $stmt_u->bind_param("s", $pin);
    $stmt_u->execute();
    $detail_user = $stmt_u->get_result()->fetch_assoc();

    // 2. Hitung statistik masuk & pulang pada rentang tanggal
    $stmt_stat = $conn->prepare("SELECT status, COUNT(*) as cnt FROM log_absen WHERE pin = ? AND DATE(waktu) BETWEEN ? AND ? GROUP BY status");
    $stmt_stat->bind_param("sss", $pin, $tgl_mulai, $tgl_selesai);
    $stmt_stat->execute();
    $res_st = $stmt_stat->get_result();
    while ($rs = $res_st->fetch_assoc()) {
        if ($rs['status'] == 0) $total_masuk = (int)$rs['cnt'];
        if ($rs['status'] == 1) $total_pulang = (int)$rs['cnt'];
    }
    $total_records = $total_masuk + $total_pulang;
    $total_pages   = ceil($total_records / $limit);

    // 3. Data Log Absen Paginasi
    $stmt_l = $conn->prepare("SELECT la.*, (MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1) AS hari_num FROM log_absen la WHERE la.pin = ? AND DATE(la.waktu) BETWEEN ? AND ? ORDER BY la.waktu DESC LIMIT ? OFFSET ?");
    $stmt_l->bind_param("sssii", $pin, $tgl_mulai, $tgl_selesai, $limit, $offset);
    $stmt_l->execute();
    $logs = $stmt_l->get_result()->fetch_all(MYSQLI_ASSOC);
}

$nama_hari_indo = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

render_header("Riwayat Presensi Saya", "user_riwayat");
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

.filter-bar {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
</style>

<?php if (empty($pin) || !$detail_user): ?>
    <div class="card" style="text-align:center; padding:40px 20px;">
        <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
        <h3 style="font-size:20px; font-weight:800; color:#0f172a; margin-bottom:8px;">Akun Belum Terhubung dengan Data Karyawan</h3>
        <p style="color:#64748b; font-size:14px; max-width:500px; margin:0 auto;">
            Anda belum terhubung ke PIN Karyawan. Silakan hubungi Administrator untuk menghubungkan akun Anda.
        </p>
    </div>
<?php else: ?>

<!-- RINGKASAN CARDS STATISTIK SAYA -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
    <!-- IDENTITAS -->
    <div class="card" style="margin-bottom:0; padding:16px 20px; background:linear-gradient(135deg, #1e293b, #0f172a); color:#fff;">
        <div style="font-size:11px; color:#94a3b8; font-weight:600; text-transform:uppercase;">Pegawai / Guru</div>
        <div style="font-size:16px; font-weight:800; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo h($detail_user['nama']); ?></div>
        <div style="font-size:12px; color:#cbd5e1; margin-top:2px;">PIN: <code><?php echo h($pin); ?></code> (<?php echo h($detail_user['departemen'] ?: '-'); ?>)</div>
    </div>

    <!-- TOTAL LOG -->
    <div class="card" style="margin-bottom:0; padding:16px 20px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Total Record Absen</div>
        <div style="font-size:24px; font-weight:800; color:#0f172a; margin-top:2px;"><?php echo $total_records; ?> <span style="font-size:12px; font-weight:500; color:#64748b;">Log</span></div>
    </div>

    <!-- HADIR MASUK -->
    <div class="card" style="margin-bottom:0; padding:16px 20px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Absen Masuk</div>
        <div style="font-size:24px; font-weight:800; color:#15803d; margin-top:2px;"><?php echo $total_masuk; ?> <span style="font-size:12px; font-weight:500; color:#64748b;">Kali</span></div>
    </div>

    <!-- ABSEN PULANG -->
    <div class="card" style="margin-bottom:0; padding:16px 20px;">
        <div style="font-size:12px; color:#64748b; font-weight:600;">Absen Pulang</div>
        <div style="font-size:24px; font-weight:800; color:#be123c; margin-top:2px;"><?php echo $total_pulang; ?> <span style="font-size:12px; font-weight:500; color:#64748b;">Kali</span></div>
    </div>
</div>

<!-- BAR FILTER & CETAK PDF -->
<div class="filter-bar">
    <form method="GET" action="user_riwayat.php" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:0;">
        <div style="display:flex; align-items:center; gap:8px;">
            <label style="margin-bottom:0; font-size:12.5px; white-space:nowrap;">📅 Dari:</label>
            <input type="date" name="tgl_mulai" value="<?php echo h($tgl_mulai); ?>" style="margin-bottom:0; width:150px; padding:7px 10px; font-size:13px;">
        </div>

        <div style="display:flex; align-items:center; gap:8px;">
            <label style="margin-bottom:0; font-size:12.5px; white-space:nowrap;">Sampai:</label>
            <input type="date" name="tgl_selesai" value="<?php echo h($tgl_selesai); ?>" style="margin-bottom:0; width:150px; padding:7px 10px; font-size:13px;">
        </div>

        <button type="submit" class="btn btn-primary" style="padding:7px 16px; font-size:13px; min-height:36px;">
            🔍 Filter Tanggal
        </button>
    </form>

    <?php if (can_access_rnd()): ?>
    <a href="<?php echo 'export_pdf_riwayat.php?' . http_build_query(['pin' => $pin, 'tgl_dari' => $tgl_mulai, 'tgl_sampai' => $tgl_selesai, 'auto_print' => 1]); ?>" target="_blank" class="btn" style="background:#ef4444; color:#fff; font-size:13px; padding:7px 14px; min-height:36px; text-decoration:none;">
        📄 Export PDF Riwayat
    </a>
    <?php endif; ?>
</div>

<!-- TABEL RIWAYAT PRESENSI SAYA -->
<div class="card">
    <div class="card-title" style="margin-bottom:18px;">
        📜 Detail Riwayat Kehadiran Presensi
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width:45px;">NO</th>
                    <th style="text-align:left;">HARI & TANGGAL</th>
                    <th>JAM ABSEN (WIB)</th>
                    <th>STATUS PRESENSI</th>
                    <th>METODE VERIFIKASI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): 
                    $no = $offset + 1;
                    foreach ($logs as $l):
                        $hn     = (int)$l['hari_num'];
                        $hari   = $nama_hari_indo[$hn] ?? '';
                        $tgl_f  = date('d/m/Y', strtotime($l['waktu']));
                        $jam_f  = date('H:i:s', strtotime($l['waktu']));

                        $st_text  = $l['status'] == 0 ? 'MASUK' : 'PULANG';
                        $st_badge = $l['status'] == 0 ? 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;' : 'background:#fee2e2; color:#be123c; border:1px solid #fca5a5;';
                        
                        $ver_text = 'Sidik Jari';
                        if ($l['tipe_verifikasi'] == '15') $ver_text = 'Scan Wajah';
                        elseif ($l['tipe_verifikasi'] == '99') $ver_text = 'Manual Admin';
                ?>
                    <tr>
                        <td><b><?php echo $no++; ?></b></td>
                        <td style="text-align:left; font-weight:700; color:#0f172a;">
                            <?php echo $hari; ?>, <?php echo $tgl_f; ?>
                        </td>
                        <td>
                            <code style="font-size:13px; font-weight:bold; color:#1e293b;"><?php echo $jam_f; ?></code>
                        </td>
                        <td>
                            <span class="badge" style="<?php echo $st_badge; ?> font-weight:700;"><?php echo $st_text; ?></span>
                        </td>
                        <td style="font-size:12.5px; color:#475569;">
                            <?php echo $ver_text; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" style="padding:35px; color:#94a3b8;">Belum ada log presensi pada rentang tanggal ini.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- NAVIGASI PAGINASI -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <span style="font-size:12.5px; color:#64748b;">Menampilkan <b><?php echo count($logs); ?></b> dari <b><?php echo $total_records; ?></b> record</span>
            
            <div style="display:flex; gap:4px;">
                <?php $url_p = "tgl_mulai=" . urlencode($tgl_mulai) . "&tgl_selesai=" . urlencode($tgl_selesai); ?>

                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo ($page - 1); ?>&<?php echo $url_p; ?>" class="pagination-btn">‹ Prev</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="?page=<?php echo $p; ?>&<?php echo $url_p; ?>" class="pagination-btn <?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo ($page + 1); ?>&<?php echo $url_p; ?>" class="pagination-btn">Next ›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php
render_footer();
?>
