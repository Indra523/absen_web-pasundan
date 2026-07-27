<?php
// ============================================================
// HALAMAN UTAMA: Live Monitoring Absensi (Sleek UI & Real-Time Sync)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';

$conn = getDB();
$pesan_manual = "";

// PROSES TAMBAH LOG ABSEN MANUAL (Hanya Superadmin - Mode Darurat / Maintenance Mesin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tambah_absen_manual') {
    csrf_verify();
    
    if (!is_superadmin()) {
        $pesan_manual = "<div style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>⛔ <b>Akses Ditolak:</b> Hanya Superadmin yang berhak menambahkan log absen manual.</div>";
    } else {
        $pin_m    = trim($_POST['pin_manual'] ?? '');
        $waktu_m  = trim($_POST['waktu_manual'] ?? '');
        $status_m = trim($_POST['status_manual'] ?? '0');

        if (!empty($pin_m) && !empty($waktu_m)) {
            $waktu_formatted = date('Y-m-d H:i:s', strtotime($waktu_m));
            $status_clean    = in_array($status_m, ['0', '1']) ? $status_m : '0';
            $tipe_verif      = '0'; // 0 = Input Manual Admin

            $stmt_m = $conn->prepare("INSERT INTO log_absen (pin, waktu, status, tipe_verifikasi) VALUES (?, ?, ?, ?)");
            $stmt_m->bind_param("ssss", $pin_m, $waktu_formatted, $status_clean, $tipe_verif);

            if ($stmt_m->execute()) {
                $pesan_manual = "<div style='background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>✅ <b>Berhasil!</b> Log absen manual untuk PIN <b>" . h($pin_m) . "</b> (Waktu: " . h($waktu_formatted) . ") berhasil ditambahkan.</div>";
            } else {
                $pesan_manual = "<div style='background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>⛔ <b>Gagal:</b> " . h($conn->error) . "</div>";
            }
        } else {
            $pesan_manual = "<div style='background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:14px; font-weight:600;'>Harap pilih karyawan dan tentukan waktu absen!</div>";
        }
    }
}

// Initial Filter Parameters (Default: Tanggal Hari Ini)
$tgl           = trim($_GET['tgl'] ?? date('Y-m-d'));
$q             = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$export_url = "export_excel.php?" . http_build_query(['tgl' => $tgl, 'q' => $q, 'status' => $status_filter]);

// Ambil semua data karyawan untuk modal input manual
$karyawan_all = [];
if (is_superadmin()) {
    $res_k = $conn->query("SELECT pin, nama, departemen, tipe FROM master_karyawan ORDER BY CAST(pin AS UNSIGNED) ASC, pin ASC");
    if ($res_k && $res_k->num_rows > 0) {
        while ($rk = $res_k->fetch_assoc()) {
            $karyawan_all[] = $rk;
        }
    }
}

render_header("Live Monitoring Absensi", "index");
?>

<?php echo $pesan_manual; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
<div style="background:#ffe4e6; color:#be123c; border:1px solid #fecdd3; padding:14px 18px; border-radius:12px; font-size:14px; font-weight:600; margin-bottom:20px;">
    ⚠️ <b>Akses Ditolak:</b> Halaman tersebut hanya dapat diakses oleh akun dengan wewenang <b>Superadmin</b>.
</div>
<?php endif; ?>

<!-- KARTU CONTROL PANEL & FILTER PENCARIAN -->
<div class="card" style="margin-bottom: 20px;">
    <form id="search-form" method="GET" action="" onsubmit="return false;">
        <!-- FILTER INPUTS: stack di mobile, grid di desktop -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 16px;">
            <div>
                <label for="tgl" style="font-weight:600; font-size:13px; color:#334155; margin-bottom:6px; display:block;">📅 Filter Tanggal:</label>
                <input type="date" id="tgl" name="tgl" value="<?php echo h($tgl === 'all' ? '' : $tgl); ?>" style="width:100%; margin-bottom:0;">
            </div>

            <div>
                <label for="q" style="font-weight:600; font-size:13px; color:#334155; margin-bottom:6px; display:block;">🔍 Pencarian:</label>
                <input type="text" id="q" name="q" value="<?php echo h($q); ?>" placeholder="Nama, PIN, departemen..." style="width:100%; margin-bottom:0;" autocomplete="off">
            </div>

            <div>
                <label for="status" style="font-weight:600; font-size:13px; color:#334155; margin-bottom:6px; display:block;">Status Absensi:</label>
                <select id="status" name="status" style="width:100%; margin-bottom:0;">
                    <option value="">-- Semua Status --</option>
                    <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>🟢 Masuk</option>
                    <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>🔴 Pulang</option>
                </select>
            </div>
        </div>

        <!-- ACTION BUTTONS: flex-wrap agar rapi di mobile -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-top:14px; border-top:1px solid #f1f5f9;">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <span style="font-size:12px; font-weight:600; color:#64748b;">Pintasan:</span>
                <button type="button" class="btn" style="background:#f1f5f9; color:#0f172a; font-size:13px; padding:8px 12px; border:1px solid #e2e8f0;" onclick="setToday()">🗓️ Hari Ini</button>
                <button type="button" class="btn" style="background:#f1f5f9; color:#0f172a; font-size:13px; padding:8px 12px; border:1px solid #e2e8f0;" onclick="setAllDates()">📑 Semua</button>
            </div>

            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <?php if (is_superadmin()): ?>
                <button type="button" class="btn" style="background:linear-gradient(135deg,#3b82f6,#6366f1); color:#fff; font-size:13px; padding:9px 14px; border:none; box-shadow:0 3px 10px rgba(59,130,246,0.3);" onclick="bukaModalManualAbsen()">
                    ✏️ Input Absen Manual
                </button>
                <?php endif; ?>

                <button type="button" id="btn-toggle-sync" class="btn" style="background:#f8fafc; color:#334155; font-size:13px; border:1px solid #cbd5e1; padding:9px 14px;" onclick="toggleAutoSync()">
                    <span id="sync-icon">🟢</span> <span id="sync-text">Auto-Sync</span>
                </button>
                <a id="btn-export" href="<?php echo h($export_url); ?>" class="btn btn-success" style="padding:9px 14px; font-size:13px;">
                    📊 Export Excel
                </a>
            </div>
        </div>
    </form>
</div>

<!-- KARTU TABEL DATA LIVE MONITORING -->
<div class="card" style="padding: 24px;">
    <div class="card-header" style="margin-bottom: 20px;">
        <div class="card-title" style="font-size: 17px;">
            <span>📡 Live Feed Data Absensi (<span id="data-count">...</span> Data)</span>
        </div>

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <span class="badge badge-verif" id="filter-date-badge" style="font-size:12px; padding: 6px 12px;">📅 Tanggal: <?php echo h($tgl === 'all' ? 'Semua Tanggal' : date('d-m-Y', strtotime($tgl))); ?></span>
            <span class="badge badge-verif" style="font-size:12px; padding: 6px 12px; background:#f8fafc;">🕒 Update: <b id="last-update-time" style="color:#0f172a; margin-left:4px;">-</b></span>
            <span class="badge badge-verif" style="font-size:12px; padding: 6px 12px; background:#f8fafc;">📟 Mesin: <b>Solution X606-S</b></span>
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>PIN / ID</th>
                    <th>Nama Guru & Karyawan</th>
                    <th>Waktu Absen</th>
                    <th>Status Absensi</th>
                    <th>Tipe Verifikasi</th>
                </tr>
            </thead>
            <tbody id="tbody-monitoring">
                <tr>
                    <td colspan="6" style="padding: 35px; color:#94a3b8; text-align:center;">Memuat data absensi...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php if (is_superadmin()): ?>
<!-- ================= MODAL INPUT ABSEN MANUAL (SUPERADMIN ONLY) ================= -->
<div id="modal-manual-absen" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:20px; padding:32px; width:100%; max-width:480px; box-shadow:0 25px 60px rgba(0,0,0,0.25); animation:slideUp 0.25s ease;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="font-size:18px; font-weight:800; color:#0f172a;">✏️ Input Log Absen Manual</h3>
            <button type="button" onclick="tutupModalManualAbsen()" style="background:#f1f5f9; border:none; border-radius:8px; padding:8px 12px; cursor:pointer; font-size:16px; color:#64748b;">✕</button>
        </div>

        <div style="margin-bottom:16px; font-size:12px; color:#64748b; background:#f8fafc; padding:12px 14px; border-radius:10px; border:1px solid #e2e8f0; line-height:1.5;">
            💡 <b>Mode Darurat / Backup:</b> Gunakan fitur ini jika mesin absensi rusak atau sedang maintenance. Data akan otomatis muncul di Live Monitoring, Laporan Bulanan, dan Riwayat Karyawan.
        </div>

        <form method="POST" action="index.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="tambah_absen_manual">

            <label for="pin_manual" style="font-weight:700;">Pilih Guru / Karyawan:</label>
            <select name="pin_manual" id="pin_manual" required style="margin-bottom:18px; font-size:14px; font-weight:600;">
                <?php if (empty($karyawan_all)): ?>
                    <option value="">-- Belum ada data guru & karyawan --</option>
                <?php else: ?>
                    <?php foreach ($karyawan_all as $ka): 
                        $is_g = ($ka['tipe'] === 'guru');
                        $t_label = $is_g ? "👨‍🏫 Guru" : "👔 Karyawan";
                    ?>
                        <option value="<?php echo h($ka['pin']); ?>">
                            [PIN: <?php echo h($ka['pin']); ?>] <?php echo h($ka['nama']); ?> (<?php echo h($ka['departemen'] ?: 'Staff'); ?> - <?php echo $t_label; ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <label for="waktu_manual" style="font-weight:700;">Waktu Absen (Tanggal & Jam):</label>
            <input type="datetime-local" id="waktu_manual" name="waktu_manual" value="<?php echo date('Y-m-d\TH:i'); ?>" required style="margin-bottom:18px;">

            <label for="status_manual" style="font-weight:700;">Status Absensi:</label>
            <select id="status_manual" name="status_manual" style="margin-bottom:24px;">
                <option value="0">🟢 Masuk</option>
                <option value="1">🔴 Pulang</option>
            </select>

            <div style="display:flex; gap:12px;">
                <button type="button" onclick="tutupModalManualAbsen()" class="btn" style="flex:1; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0;">Batal</button>
                <button type="submit" class="btn btn-primary" style="flex:2;">💾 Simpan Absen Manual</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
<?php endif; ?>

<!-- JAVASCRIPT AJAX REAL-TIME POLLING & DATE FILTER -->
<script>
let autoSyncActive = true;
let syncInterval = null;
let searchDebounce = null;
let currentTglMode = "<?php echo h($tgl); ?>";

const inputTgl = document.getElementById('tgl');
const inputQ = document.getElementById('q');
const selectStatus = document.getElementById('status');
const tbody = document.getElementById('tbody-monitoring');
const dataCount = document.getElementById('data-count');
const lastUpdateTime = document.getElementById('last-update-time');
const filterDateBadge = document.getElementById('filter-date-badge');
const btnExport = document.getElementById('btn-export');
const syncIcon = document.getElementById('sync-icon');
const syncText = document.getElementById('sync-text');
const btnToggleSync = document.getElementById('btn-toggle-sync');

// Fungsi Modal Input Manual
function bukaModalManualAbsen() {
    const modal = document.getElementById('modal-manual-absen');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function tutupModalManualAbsen() {
    const modal = document.getElementById('modal-manual-absen');
    if (modal) {
        modal.style.display = 'none';
    }
}

const modalManual = document.getElementById('modal-manual-absen');
if (modalManual) {
    modalManual.addEventListener('click', function(e) {
        if (e.target === this) tutupModalManualAbsen();
    });
}

// Fungsi utama mengambil data via AJAX (Fetch API)
function fetchMonitoringData() {
    let tglVal = currentTglMode;
    if (currentTglMode !== 'all') {
        tglVal = inputTgl.value;
    }

    const qVal = inputQ.value.trim();
    const statusVal = selectStatus.value;

    // Update Label Filter Tanggal
    if (currentTglMode === 'all') {
        filterDateBadge.textContent = '📅 Tanggal: Semua Tanggal';
    } else if (tglVal) {
        const parts = tglVal.split('-');
        if (parts.length === 3) {
            filterDateBadge.textContent = '📅 Tanggal: ' + parts[2] + '-' + parts[1] + '-' + parts[0];
        }
    }

    // Update URL export secara dinamis
    const params = new URLSearchParams({ tgl: tglVal, q: qVal, status: statusVal });
    btnExport.href = 'export_excel.php?' + params.toString();

    fetch('api_monitoring.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                tbody.innerHTML = data.html;
                dataCount.textContent = data.count;
                lastUpdateTime.textContent = data.last_update;
            }
        })
        .catch(err => {
            console.error('AJAX polling error:', err);
        });
}

// Shortcut Filter Tanggal
function setToday() {
    const today = new Date().toISOString().split('T')[0];
    inputTgl.value = today;
    currentTglMode = today;
    fetchMonitoringData();
}

function setAllDates() {
    inputTgl.value = '';
    currentTglMode = 'all';
    fetchMonitoringData();
}

// Toggle Auto Sync (Pause / Resume)
function toggleAutoSync() {
    autoSyncActive = !autoSyncActive;
    if (autoSyncActive) {
        syncIcon.textContent = '🟢';
        syncText.textContent = 'Auto-Sync (5s)';
        btnToggleSync.style.background = '#f8fafc';
        startPolling();
    } else {
        syncIcon.textContent = '⏸️';
        syncText.textContent = 'Sync Paused';
        btnToggleSync.style.background = '#fef3c7';
        stopPolling();
    }
}

function startPolling() {
    stopPolling();
    syncInterval = setInterval(() => {
        if (autoSyncActive) {
            fetchMonitoringData();
        }
    }, 5000);
}

function stopPolling() {
    if (syncInterval) {
        clearInterval(syncInterval);
        syncInterval = null;
    }
}

// Event Listeners
inputTgl.addEventListener('change', () => {
    currentTglMode = inputTgl.value;
    fetchMonitoringData();
});

inputQ.addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        fetchMonitoringData();
    }, 300);
});

selectStatus.addEventListener('change', () => {
    fetchMonitoringData();
});

// Init
document.addEventListener('DOMContentLoaded', () => {
    fetchMonitoringData();
    startPolling();
});
</script>

<?php render_footer(); ?>