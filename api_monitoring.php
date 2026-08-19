<?php
// ============================================================
// API ENDPOINT: AJAX Monitoring Data Real-Time (Dengan Filter Tanggal)
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

// Release lock file sesi segera agar request reload/F5 tidak terblokir
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$conn = getDB();

// Filter Tanggal, Pencarian, & Status
$tgl           = trim($_GET['tgl'] ?? date('Y-m-d'));
$q             = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = "";

if ($tgl !== 'all' && !empty($tgl)) {
    // Optimization: Range scan agar menggunakan index idx_waktu
    $where[] = "(log_absen.waktu >= ? AND log_absen.waktu < DATE_ADD(?, INTERVAL 1 DAY))";
    $params[] = $tgl . " 00:00:00";
    $params[] = $tgl;
    $types .= "ss";
}

if (!empty($q)) {
    $where[] = "(log_absen.pin LIKE ? OR master_karyawan.nama LIKE ? OR master_karyawan.departemen LIKE ? OR log_absen.waktu LIKE ?)";
    $param_q = "%" . $q . "%";
    $params[] = $param_q;
    $params[] = $param_q;
    $params[] = $param_q;
    $params[] = $param_q;
    $types .= "ssss";
}

if ($status_filter !== '' && in_array($status_filter, ['0', '1'])) {
    $where[] = "log_absen.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// KHUSUS ROLE TATAUSAHA: Hanya tampilkan kategori Karyawan
if (is_tatausaha()) {
    $where[] = "master_karyawan.tipe = 'karyawan'";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Query utama + LEFT JOIN ke master_karyawan dan check jadwal guru
// MOD(DAYOFWEEK(la.waktu) + 5, 7) + 1 menghitung hari: 1=Senin ... 6=Sabtu, 7=Minggu
$sql = "SELECT log_absen.*, 
               master_karyawan.nama, 
               master_karyawan.departemen, 
               master_karyawan.tipe,
               jg.id AS jg_id,
               (SELECT COUNT(*) FROM jadwal_guru WHERE pin = log_absen.pin) AS total_jadwal_guru
        FROM log_absen 
        LEFT JOIN master_karyawan ON log_absen.pin = master_karyawan.pin 
        LEFT JOIN jadwal_guru jg 
               ON jg.pin = log_absen.pin 
              AND jg.hari = (MOD(DAYOFWEEK(log_absen.waktu) + 5, 7) + 1)
        {$where_sql}
        ORDER BY log_absen.waktu DESC LIMIT 300";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

ob_start();
if ($result->num_rows > 0) {
    $no = 1;
    while ($row = $result->fetch_assoc()) {
        $status_teks = "Unknown";
        $badge_class = "badge-verif";
        if ($row['status'] == '0') {
            $status_teks = "Masuk";
            $badge_class = "badge-masuk";
        } elseif ($row['status'] == '1') {
            $status_teks = "Pulang";
            $badge_class = "badge-pulang";
        }

        $tipe_teks = "Lainnya";
        if ($row['tipe_verifikasi'] === 'SELFIE' || !empty($row['foto_selfie'])) {
            $tipe_teks = "Absen Mobile";
        } elseif ($row['tipe_verifikasi'] == '1') {
            $tipe_teks = "Sidik Jari";
        } elseif ($row['tipe_verifikasi'] == '15') {
            $tipe_teks = "Wajah";
        } elseif ($row['tipe_verifikasi'] == '0' || $row['tipe_verifikasi'] == '99') {
            $tipe_teks = "Manual Admin";
        }

        // Cek Badge Jadwal Guru
        $badge_jadwal = "";
        $is_guru = ($row['tipe'] ?? '') === 'guru';
        if ($is_guru) {
            if ($row['total_jadwal_guru'] == 0) {
                $badge_jadwal = "<span class='badge' style='background:#fef3c7; color:#92400e; border:1px solid #fde68a;' title='Jadwal ngajar belum diatur superadmin'>Belum Ada Jadwal</span>";
            } elseif (empty($row['jg_id'])) {
                $badge_jadwal = "<span class='badge' style='background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;' title='Absen di luar hari jadwal ngajar'>Di Luar Jadwal</span>";
            }
        }

        $waktu_ts   = strtotime($row['waktu']);
        $waktu_tgl  = date('Y-m-d', $waktu_ts);
        $waktu_jam  = date('H:i:s', $waktu_ts);
        $tampil_waktu = "<div style='font-size:11px; color:#64748b;'>{$waktu_tgl}</div><div style='font-size:13px; font-weight:800; color:#0f172a;'>{$waktu_jam}</div>";

        if (!empty($row['nama'])) {
            $nama_escaped = h($row['nama']);
            $dept_escaped = h($row['departemen']);
            $tipe_label   = $is_guru ? "Guru" : "Karyawan";
            $tipe_bg      = $is_guru ? "background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;" : "background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;";
            $tampil_nama = "<td style='padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;'>
                                <div style='display:flex; align-items:center; gap:8px; flex-wrap:wrap;'>
                                    <span style='font-weight:700; color:#0f172a; font-size:13px;'>{$nama_escaped}</span>
                                    <span style='font-size:10px; {$tipe_bg} padding:2px 8px; border-radius:12px; font-weight:700;'>{$tipe_label}</span>
                                </div>
                                <div style='font-size:11px; color:#64748b; margin-top:2px;'>{$dept_escaped}</div>
                            </td>";
        } else {
            $tampil_nama = "<td style='padding:12px 16px; text-align:left; color:#e11d48; font-weight:700; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;'>Belum Terdaftar di Master</td>";
        }

        $td_aksi = "";
        if (is_superadmin()) {
            $csrf_tok = csrf_token();
            $td_aksi = "<td style='padding:10px; text-align:center; border-bottom:1px solid #f1f5f9;'>
                            <form method='POST' action='index.php' style='margin:0;' onsubmit=\"return confirm('Yakin ingin menghapus data log absen ini?')\">
                                <input type='hidden' name='csrf_token' value='{$csrf_tok}'>
                                <input type='hidden' name='action' value='hapus_log_absen'>
                                <input type='hidden' name='id_log_hapus' value='{$row['id']}'>
                                <button type='submit' style='background:#fff1f2; color:#e11d48; border:1px solid #fecdd3; border-radius:8px; font-size:11px; font-weight:700; padding:5px 10px; cursor:pointer; transition:all .15s;' onmouseover=\"this.style.background='#ffe4e6'\" onmouseout=\"this.style.background='#fff1f2'\">Hapus</button>
                            </form>
                        </td>";
        }

        // Status Badge Style
        $badge_status_style = ($row['status'] == '0')
            ? "background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;"
            : "background:#fee2e2; color:#dc2626; border:1px solid #fecdd3;";

        // Verif Badge Style
        $verif_style = "background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;";
        if ($row['tipe_verifikasi'] === 'SELFIE' || !empty($row['foto_selfie'])) {
            $verif_style = "background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe;";
        } elseif ($row['tipe_verifikasi'] == '1') {
            $verif_style = "background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;";
        } elseif ($row['tipe_verifikasi'] == '15') {
            $verif_style = "background:#f3e8ff; color:#7e22ce; border:1px solid #e9d5ff;";
        } elseif ($row['tipe_verifikasi'] == '0' || $row['tipe_verifikasi'] == '99') {
            $verif_style = "background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;";
        }

        // Tampilkan Foto Selfie (jika ada)
        $foto_selfie_html = "";
        if (!empty($row['foto_selfie']) && file_exists(__DIR__ . '/' . $row['foto_selfie'])) {
            $selfie_url = h($row['foto_selfie']);
            $gps_url = (!empty($row['latitude']) && !empty($row['longitude'])) 
                ? "https://maps.google.com/?q={$row['latitude']},{$row['longitude']}" 
                : "#";
            
            $foto_selfie_html = "<div style='margin-top:4px; display:flex; flex-direction:column; align-items:center;'>
                <a href='{$selfie_url}' target='_blank' title='Klik untuk melihat foto selfie lengkap'>
                    <img src='{$selfie_url}' style='width:38px; height:38px; border-radius:8px; object-fit:cover; border:1.5px solid #2563eb; box-shadow:0 2px 6px rgba(37,99,235,0.2); transition:transform .15s;' onmouseover=\"this.style.transform='scale(1.1)'\" onmouseout=\"this.style.transform='scale(1)'\">
                </a>";
            if (!empty($row['latitude'])) {
                $foto_selfie_html .= "<a href='{$gps_url}' target='_blank' style='font-size:9.5px; color:#2563eb; font-weight:700; text-decoration:none; margin-top:2px;'>Maps GPS</a>";
            }
            $foto_selfie_html .= "</div>";
        }

        $row_bg = ($no % 2 === 0) ? '#ffffff' : '#f8fafc';
        echo "<tr style='background:{$row_bg}; transition:background .15s;' onmouseover=\"this.style.background='#eff6ff'\" onmouseout=\"this.style.background='{$row_bg}'\">
                <td style='padding:12px 10px; text-align:center; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;'><b style='color:#64748b; font-size:12px;'>{$no}</b></td>
                <td style='padding:12px 10px; text-align:center; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;'><code style='background:#1e293b; color:#38bdf8; padding:3px 9px; border-radius:6px; font-weight:800; font-size:12px; font-family:monospace;'>" . h($row['pin']) . "</code></td>
                {$tampil_nama}
                <td style='padding:12px 14px; text-align:center; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;'>{$tampil_waktu}</td>
                <td style='padding:12px 14px; text-align:center; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;'><span style='{$badge_status_style} padding:5px 14px; border-radius:20px; font-weight:800; font-size:12px; display:inline-block;'>" . h($status_teks) . "</span></td>
                <td style='padding:10px 14px; text-align:center; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;'>
                    <div style='display:flex; flex-direction:column; align-items:center; gap:4px;'>
                        <span style='{$verif_style} padding:4px 12px; border-radius:20px; font-weight:700; font-size:11px; display:inline-block;'>" . h($tipe_teks) . "</span>
                        {$foto_selfie_html}
                        {$badge_jadwal}
                    </div>
                </td>
                {$td_aksi}
              </tr>";
        $no++;
    }
} else {
    $colspan = is_superadmin() ? 7 : 6;
    echo "<tr><td colspan='{$colspan}' style='padding: 30px; color:#94a3b8;'>Data absensi tidak ditemukan untuk filter ini.</td></tr>";
}
$html = ob_get_clean();

echo json_encode([
    'success' => true,
    'count' => $result->num_rows,
    'html' => $html,
    'last_update' => date('H:i:s')
]);
exit;
