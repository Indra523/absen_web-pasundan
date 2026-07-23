<?php
// ============================================================
// HALAMAN KELOLA JADWAL NGAJAR GURU (Dengan Real-Time Search)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/layout.php';
require_role(['superadmin']);

$conn = getDB();
$pesan_sukses = "";
$pesan_error  = "";

// Nama Hari Map
$nama_hari_map = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu'
];

// --- PROSES SIMPAN JADWAL GURU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'simpan_jadwal') {
    csrf_verify();

    $pin       = trim($_POST['pin'] ?? '');
    $hari_list = $_POST['hari'] ?? []; // Array hari yang dicentang [1, 2, 3...]

    if (empty($pin)) {
        $pesan_error = "PIN Guru tidak valid!";
    } else {
        // Mulai Transaksi
        $conn->begin_transaction();
        try {
            // Hapus semua jadwal lama guru ini
            $del = $conn->prepare("DELETE FROM jadwal_guru WHERE pin = ?");
            $del->bind_param("s", $pin);
            $del->execute();

            // Insert hari-hari baru
            if (!empty($hari_list) && is_array($hari_list)) {
                $ins = $conn->prepare("INSERT INTO jadwal_guru (pin, hari) VALUES (?, ?)");
                foreach ($hari_list as $h) {
                    $hari_num = (int)$h;
                    if ($hari_num >= 1 && $hari_num <= 6) {
                        $ins->bind_param("si", $pin, $hari_num);
                        $ins->execute();
                    }
                }
            }

            // Otomatis ubah tipe karyawan menjadi 'guru' jika belum
            $upd = $conn->prepare("UPDATE master_karyawan SET tipe = 'guru' WHERE pin = ?");
            $upd->bind_param("s", $pin);
            $upd->execute();

            $conn->commit();
            $pesan_sukses = "✅ Jadwal ngajar berhasil diperbarui.";
        } catch (Exception $e) {
            $conn->rollback();
            $pesan_error = "Gagal menyimpan jadwal: " . h($e->getMessage());
        }
    }
}

// Ambil semua daftar guru (dan karyawan yang mungkin ingin diubah ke guru)
$sql_guru = "SELECT mk.*, 
                    GROUP_CONCAT(jg.hari ORDER BY jg.hari ASC) AS list_hari 
             FROM master_karyawan mk 
             LEFT JOIN jadwal_guru jg ON mk.pin = jg.pin 
             GROUP BY mk.pin 
             ORDER BY mk.tipe DESC, CAST(mk.pin AS UNSIGNED) ASC, mk.pin ASC";
$result_guru = $conn->query($sql_guru);
$total_guru  = $result_guru->num_rows;

render_header("Kelola Jadwal Ngajar Guru", "jadwal_guru");
?>

<?php if ($pesan_sukses): ?>
<div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; color: #065f46; font-size: 14px; font-weight: 500;">
    <?php echo $pesan_sukses; ?>
</div>
<?php endif; ?>

<?php if ($pesan_error): ?>
<div style="background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #f87171; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; color: #991b1b; font-size: 14px; font-weight: 500;">
    ⛔ <?php echo $pesan_error; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="flex-wrap:wrap; gap:12px; align-items:center;">
        <div class="card-title" style="margin-bottom:0;">
            <span>⚙️ Pengaturan Jadwal Ngajar Guru</span>
        </div>

        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <!-- Input Pencarian Real-Time (Tanpa Tekan Enter) -->
            <input type="text" id="q_jadwal" placeholder="🔍 Cari Real-Time (Nama, PIN, Dept)..." style="margin-bottom:0; height:38px; width:260px; font-size:13px;" autocomplete="off">
            
            <span class="badge badge-verif" style="font-size:12px; font-weight:700;">
                Menampilkan <b id="count-visible"><?php echo $total_guru; ?></b> dari <?php echo $total_guru; ?> Orang
            </span>
        </div>
    </div>

    <div style="margin-bottom: 16px; font-size:13px; color:#64748b; background:#f8fafc; padding:12px 16px; border-radius:10px; border:1px solid #e2e8f0; line-height:1.5;">
        💡 <b>Petunjuk:</b> Centang hari ngajar untuk masing-masing guru. Data absensi guru yang masuk di luar hari jadwal ngajar tetap tersimpan di database, tetapi akan ditandai <span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;'>⚠️ Di Luar Jadwal</span> dan dikecualikan dari rekap evaluasi bulanan.
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>PIN / ID</th>
                    <th style="text-align:left;">Nama Guru & Karyawan</th>
                    <th>Kategori</th>
                    <th style="text-align:left;">Jadwal Ngajar Mingguan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="tbody-jadwal">
                <?php
                if ($result_guru->num_rows > 0) {
                    $no = 1;
                    while ($g = $result_guru->fetch_assoc()) {
                        $is_guru = ($g['tipe'] === 'guru');
                        $badge_kategori = $is_guru
                            ? "<span class='badge' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;'>👨‍🏫 Guru</span>"
                            : "<span class='badge' style='background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;'>👔 Karyawan</span>";

                        // Parse list hari
                        $hari_arr = !empty($g['list_hari']) ? explode(',', $g['list_hari']) : [];
                        $tampil_jadwal = "";
                        if (!$is_guru) {
                            $tampil_jadwal = "<span style='color:#94a3b8; font-size:12px; font-style:italic;'>Absensi mengikuti hari kerja kalender (Senin–Sabtu)</span>";
                        } elseif (empty($hari_arr)) {
                            $tampil_jadwal = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;'>❓ Belum Ada Jadwal</span>";
                        } else {
                            $badge_hari = [];
                            foreach ($hari_arr as $hn) {
                                $nama_h = $nama_hari_map[(int)$hn] ?? '';
                                if ($nama_h) {
                                    $badge_hari[] = "<span style='background:#f1f5f9; color:#0f172a; font-weight:700; font-size:11px; padding:3px 8px; border-radius:6px; border:1px solid #cbd5e1;'>{$nama_h}</span>";
                                }
                            }
                            $tampil_jadwal = implode(' ', $badge_hari);
                        }

                        $hari_json = json_encode(array_map('intval', $hari_arr));
                        $nama_attr = h($g['nama']);
                        $pin_attr  = h($g['pin']);
                        $dept_attr = h($g['departemen']);

                        echo "<tr class='jadwal-row' data-pin='{$pin_attr}' data-nama='" . strtolower($nama_attr) . "' data-dept='" . strtolower($dept_attr) . "'>
                                <td><b>{$no}</b></td>
                                <td><code style='background:#f1f5f9; padding:4px 8px; border-radius:6px; font-weight:700; color:#0f172a;'>{$pin_attr}</code></td>
                                <td style='text-align:left;'>
                                    <div style='font-weight:700; color:#0f172a;'>{$nama_attr}</div>
                                    <div style='font-size:11px; color:#64748b;'>{$dept_attr}</div>
                                </td>
                                <td>{$badge_kategori}</td>
                                <td style='text-align:left;'>{$tampil_jadwal}</td>
                                <td>
                                    <button type='button' class='btn' style='background:#f1f5f9; color:#334155; font-size:12px; padding:6px 12px; border:1px solid #cbd5e1;' 
                                            onclick='bukaModalJadwal(\"{$pin_attr}\", \"{$nama_attr}\", {$hari_json})'>
                                        ✏️ Atur Jadwal
                                    </button>
                                </td>
                              </tr>";
                        $no++;
                    }
                } else {
                    echo "<tr id='row-empty'><td colspan='6' style='padding:30px; color:#94a3b8;'>Belum ada data guru/karyawan.</td></tr>";
                }
                ?>
                <tr id="row-no-match" style="display:none;">
                    <td colspan="6" style="padding: 30px; color:#94a3b8; text-align:center;">🔍 Data tidak ditemukan untuk kata kunci pencarian tersebut.</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= MODAL ATUR JADWAL GURU ================= -->
<div id="modal-jadwal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:20px; padding:32px; width:100%; max-width:460px; box-shadow:0 25px 60px rgba(0,0,0,0.25); animation:slideUp 0.25s ease;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="font-size:18px; font-weight:800; color:#0f172a;">📅 Atur Jadwal Ngajar Guru</h3>
            <button type="button" onclick="tutupModalJadwal()" style="background:#f1f5f9; border:none; border-radius:8px; padding:8px 12px; cursor:pointer; font-size:16px; color:#64748b;">✕</button>
        </div>

        <form method="POST" action="kelola_jadwal.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="simpan_jadwal">
            <input type="hidden" name="pin" id="modal_pin">

            <div style="background:#f8fafc; border-radius:12px; padding:14px 16px; margin-bottom:20px; border:1px solid #e2e8f0;">
                <div style="font-size:12px; color:#64748b; font-weight:600;">Guru:</div>
                <div style="font-size:16px; font-weight:800; color:#0f172a; margin-top:2px;" id="modal_nama">-</div>
            </div>

            <label style="font-weight:700; color:#334155; margin-bottom:12px; display:block;">Pilih Hari Ngajar Mingguan:</label>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:24px;">
                <?php foreach ($nama_hari_map as $num => $nama_h): ?>
                <label style="display:flex; align-items:center; gap:10px; padding:10px 14px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer; font-weight:600; font-size:14px; margin-bottom:0;">
                    <input type="checkbox" name="hari[]" value="<?php echo $num; ?>" id="chk_hari_<?php echo $num; ?>" style="width:18px; height:18px; cursor:pointer;">
                    <span><?php echo $nama_h; ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:12px;">
                <button type="button" onclick="tutupModalJadwal()" class="btn" style="flex:1; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0;">Batal</button>
                <button type="submit" class="btn btn-primary" style="flex:2;">💾 Simpan Jadwal</button>
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

<script>
// --- MODAL JADWAL ---
function bukaModalJadwal(pin, nama, hariArr) {
    document.getElementById('modal_pin').value = pin;
    document.getElementById('modal_nama').textContent = nama;

    for (let i = 1; i <= 6; i++) {
        document.getElementById('chk_hari_' + i).checked = false;
    }

    if (Array.isArray(hariArr)) {
        hariArr.forEach(h => {
            const chk = document.getElementById('chk_hari_' + h);
            if (chk) chk.checked = true;
        });
    }

    const modal = document.getElementById('modal-jadwal');
    modal.style.display = 'flex';
}

function tutupModalJadwal() {
    document.getElementById('modal-jadwal').style.display = 'none';
}

document.getElementById('modal-jadwal').addEventListener('click', function(e) {
    if (e.target === this) tutupModalJadwal();
});

// --- REAL-TIME INSTANT SEARCH (SEARCH AS YOU TYPE) ---
const inputQJadwal = document.getElementById('q_jadwal');
const countVisibleSpan = document.getElementById('count-visible');
const rowNoMatch = document.getElementById('row-no-match');

function filterJadwalTable() {
    const val = inputQJadwal.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.jadwal-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const pin  = row.getAttribute('data-pin') || '';
        const nama = row.getAttribute('data-nama') || '';
        const dept = row.getAttribute('data-dept') || '';

        if (!val || pin.includes(val) || nama.includes(val) || dept.includes(val)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    if (countVisibleSpan) countVisibleSpan.textContent = visibleCount;
    if (rowNoMatch) {
        rowNoMatch.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
    }
}

inputQJadwal.addEventListener('input', filterJadwalTable);
</script>

<?php render_footer(); ?>
