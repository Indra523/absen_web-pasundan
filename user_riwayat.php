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
    <div class="card" style="text-align:center; padding:40px 20px; border-radius:16px;">
        <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin-bottom:8px;">Akun Belum Terhubung dengan Data Karyawan</h3>
        <p style="color:#64748b; font-size:13.5px; max-width:500px; margin:0 auto; line-height:1.6;">
            Akun Anda belum terhubung ke PIN Karyawan. Silakan hubungi Administrator untuk menghubungkan akun Anda.
        </p>
    </div>
<?php else: ?>

<!-- RINGKASAN CARDS STATISTIK SAYA -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:20px;">
    <!-- IDENTITAS -->
    <div style="background:linear-gradient(135deg, #0f172a, #1e293b); border-radius:16px; padding:18px 20px; color:#fff; box-shadow:0 4px 16px rgba(15,23,42,.15);">
        <div style="font-size:11px; color:#94a3b8; font-weight:800; text-transform:uppercase; letter-spacing:1px;">PEGAWAI / GURU</div>
        <div style="font-size:16px; font-weight:800; margin-top:3px; color:#38bdf8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo h($detail_user['nama']); ?></div>
        <div style="font-size:11.5px; color:#cbd5e1; margin-top:2px;">PIN: <code style="font-weight:700; color:#fff;"><?php echo h($pin); ?></code> &bull; <?php echo h($detail_user['departemen'] ?: '-'); ?></div>
    </div>

    <!-- TOTAL LOG -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:18px 20px; box-shadow:0 2px 10px rgba(15,23,42,0.03);">
        <div style="font-size:11px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:1px;">TOTAL LOG PRESENSI</div>
        <div style="font-size:24px; font-weight:900; color:#0f172a; margin-top:3px;"><?php echo $total_records; ?> <span style="font-size:12px; font-weight:600; color:#64748b;">Record</span></div>
    </div>

    <!-- HADIR MASUK -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:18px 20px; box-shadow:0 2px 10px rgba(15,23,42,0.03);">
        <div style="font-size:11px; color:#15803d; font-weight:800; text-transform:uppercase; letter-spacing:1px;">ABSEN MASUK</div>
        <div style="font-size:24px; font-weight:900; color:#15803d; margin-top:3px;"><?php echo $total_masuk; ?> <span style="font-size:12px; font-weight:600; color:#166534;">Kali</span></div>
    </div>

    <!-- ABSEN PULANG -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:18px 20px; box-shadow:0 2px 10px rgba(15,23,42,0.03);">
        <div style="font-size:11px; color:#be123c; font-weight:800; text-transform:uppercase; letter-spacing:1px;">ABSEN PULANG</div>
        <div style="font-size:24px; font-weight:900; color:#be123c; margin-top:3px;"><?php echo $total_pulang; ?> <span style="font-size:12px; font-weight:600; color:#991b1b;">Kali</span></div>
    </div>
</div>

<!-- BAR FILTER & CETAK PDF -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:14px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; box-shadow:0 2px 10px rgba(15,23,42,0.03);">
    <form method="GET" action="user_riwayat.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
        <div style="display:flex; align-items:center; gap:6px;">
            <label style="margin-bottom:0; font-size:12px; font-weight:700; color:#475569; text-transform:uppercase;">Dari:</label>
            <input type="date" name="tgl_mulai" value="<?php echo h($tgl_mulai); ?>" style="margin-bottom:0; width:140px; padding:7px 10px; font-size:12.5px; border:1px solid #cbd5e1; border-radius:8px; outline:none;">
        </div>

        <div style="display:flex; align-items:center; gap:6px;">
            <label style="margin-bottom:0; font-size:12px; font-weight:700; color:#475569; text-transform:uppercase;">Sampai:</label>
            <input type="date" name="tgl_selesai" value="<?php echo h($tgl_selesai); ?>" style="margin-bottom:0; width:140px; padding:7px 10px; font-size:12.5px; border:1px solid #cbd5e1; border-radius:8px; outline:none;">
        </div>

        <button type="submit" style="background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; font-weight:700; border:none; padding:7px 16px; font-size:12.5px; border-radius:8px; cursor:pointer; box-shadow:0 3px 10px rgba(37,99,235,0.25);">
            Filter Tanggal
        </button>
    </form>

    <?php if (can_access_page('export_pdf')): ?>
    <a href="<?php echo 'export_pdf_riwayat.php?' . http_build_query(['pin' => $pin, 'tgl_dari' => $tgl_mulai, 'tgl_sampai' => $tgl_selesai, 'auto_print' => 1]); ?>" target="_blank" style="background:#ef4444; color:#fff; font-size:12.5px; font-weight:700; padding:8px 16px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; box-shadow:0 3px 10px rgba(239,68,68,0.25);">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span>Export PDF Riwayat</span>
    </a>
    <?php endif; ?>
</div>

<!-- TABEL RIWAYAT PRESENSI SAYA -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,.06);">
    <div style="background:#ffffff; border-bottom:1px solid #e2e8f0; padding:16px 20px;">
        <div style="font-size:15px; font-weight:800; color:#0f172a;">Detail Riwayat Presensi</div>
    </div>

    <div class="table-responsive" style="max-height:650px; overflow:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:13px; min-width:600px;">
            <thead style="position:sticky; top:0; z-index:10; background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                <tr>
                    <th style="width:45px; background:#f8fafc; color:#475569; padding:11px 8px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">NO</th>
                    <th style="background:#f8fafc; color:#475569; padding:11px 16px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:left; border-right:1px solid #e2e8f0;">HARI &amp; TANGGAL</th>
                    <th style="background:#f8fafc; color:#475569; padding:11px 14px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">JAM PRESENSI</th>
                    <th style="background:#f8fafc; color:#475569; padding:11px 14px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center; border-right:1px solid #e2e8f0;">STATUS</th>
                    <th style="background:#f8fafc; color:#475569; padding:11px 14px; font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; text-align:center;">METODE VERIFIKASI</th>
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
                        if ($l['tipe_verifikasi'] === 'SELFIE' || !empty($l['foto_selfie'])) {
                            $ver_text = 'Selfie Web';
                        } elseif ($l['tipe_verifikasi'] == '15') {
                            $ver_text = 'Scan Wajah';
                        } elseif ($l['tipe_verifikasi'] == '99') {
                            $ver_text = 'Manual Admin';
                        }

                        if (!empty($l['foto_selfie']) && file_exists(__DIR__ . '/' . $l['foto_selfie'])) {
                            $selfie_url = h($l['foto_selfie']);
                            $ver_text .= "<div style='margin-top:4px;'><a href='{$selfie_url}' target='_blank' title='Lihat Foto Selfie'><img src='{$selfie_url}' style='width:32px; height:32px; border-radius:6px; object-fit:cover; border:1.5px solid #bfdbfe;'></a></div>";
                        }
                ?>
                    <tr>
                        <td style="text-align:center; border-bottom:1px solid #f1f5f9; padding:10px 8px;"><b><?php echo $no++; ?></b></td>
                        <td style="text-align:left; font-weight:700; color:#0f172a; border-bottom:1px solid #f1f5f9; padding:10px 16px;">
                            <?php echo $hari; ?>, <?php echo $tgl_f; ?>
                        </td>
                        <td style="text-align:center; border-bottom:1px solid #f1f5f9; padding:10px 14px;">
                            <code style="font-size:13px; font-weight:800; color:#0f172a; background:#f1f5f9; padding:3px 8px; border-radius:6px;"><?php echo $jam_f; ?></code>
                        </td>
                        <td style="text-align:center; border-bottom:1px solid #f1f5f9; padding:10px 14px;">
                            <span class="badge" style="<?php echo $st_badge; ?> font-weight:800; font-size:11.5px;"><?php echo $st_text; ?></span>
                        </td>
                        <td style="text-align:center; font-size:12.5px; color:#475569; border-bottom:1px solid #f1f5f9; padding:10px 14px;">
                            <?php echo $ver_text; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" style="padding:40px; color:#94a3b8; text-align:center;">Belum ada log presensi pada rentang tanggal ini.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- NAVIGASI PAGINASI -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination-container" style="padding:14px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:600;">Menampilkan <b><?php echo count($logs); ?></b> dari <b><?php echo $total_records; ?></b> log</span>
            
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
