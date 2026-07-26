<?php
// ============================================================
// TEMPLATE LAYOUT & SIDEBAR PREMUM
// Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung
// ============================================================

require_once __DIR__ . '/auth.php';

function render_header($page_title = "Monitoring Absensi", $active_menu = "index") {
    $username = h($_SESSION['username'] ?? 'Admin');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?> — SMK Pasundan 2 Bandung</title>
    <!-- Favicon & Icon Tab Browser -->
    <link rel="icon" type="image/jpeg" href="assets/logo_pasundan2.png">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 280px;
            --bg-main: #f8fafc;
            --sidebar-bg: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            --primary: #3b82f6;
            --primary-gradient: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --card-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 2px 6px -1px rgba(0, 0, 0, 0.03);
            --border-color: #e2e8f0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
        }

        .app-layout { display: flex; width: 100%; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; bottom: 0; left: 0;
            z-index: 200;
            box-shadow: 4px 0 25px rgba(0, 0, 0, 0.15);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 199;
            backdrop-filter: blur(2px);
            animation: fadeIn 0.25s ease;
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .sidebar-brand {
            padding: 20px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
        }

        .brand-logo {
            width: 42px; height: 42px; min-width: 42px;
            background: #fff;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            padding: 4px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .brand-logo img { width: 100%; height: 100%; object-fit: contain; }

        .brand-text h1 { font-size: 14px; font-weight: 700; color: #fff; line-height: 1.25; }
        .brand-text p  { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px; }

        .sidebar-menu { padding: 16px 12px; flex: 1; display: flex; flex-direction: column; gap: 4px; }

        .menu-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; padding: 10px 12px 5px 12px; }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 13.5px; font-weight: 500;
            border-radius: 10px;
            transition: all 0.2s ease;
            min-height: 44px;
        }

        .nav-item .icon-svg {
            width: 20px; height: 20px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            color: #94a3b8;
            transition: color 0.2s ease;
        }

        .nav-item:hover { color: #fff; background: rgba(255,255,255,0.06); transform: translateX(3px); }
        .nav-item:hover .icon-svg { color: #fff; }

        .nav-item.active {
            color: #fff;
            background: var(--primary-gradient);
            font-weight: 600;
            box-shadow: 0 4px 14px 0 rgba(37,99,235,0.35);
        }

        .nav-item.active .icon-svg { color: #fff; }

        .sidebar-user {
            padding: 14px 16px; margin: 12px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }

        .user-details { display: flex; align-items: center; gap: 10px; min-width: 0; }

        .avatar {
            width: 34px; height: 34px; min-width: 34px;
            background: #334155; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8;
            border: 1.5px solid rgba(255,255,255,0.12);
        }

        .user-name { font-size: 13px; font-weight: 600; color: #f1f5f9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 11px; color: #94a3b8; }

        .btn-logout {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 12px; font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 6px;
            flex-shrink: 0;
        }
        .btn-logout:hover { background: rgba(239,68,68,0.2); color: #fca5a5; border-color: rgba(239,68,68,0.3); }

        /* TOPBAR MOBILE */
        .topbar-mobile {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 58px;
            background: #0f172a;
            z-index: 198;
            align-items: center;
            padding: 0 16px;
            gap: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3);
        }

        .hamburger-btn {
            background: none; border: none; cursor: pointer;
            padding: 8px; border-radius: 8px;
            display: flex; flex-direction: column; gap: 5px;
            transition: background 0.2s;
        }

        .hamburger-btn span { display: block; width: 22px; height: 2px; background: #f1f5f9; border-radius: 2px; }
        .hamburger-btn:hover { background: rgba(255,255,255,0.08); }

        .topbar-logo { width: 32px; height: 32px; background: #fff; border-radius: 8px; overflow: hidden; padding: 3px; flex-shrink: 0; }
        .topbar-logo img { width: 100%; height: 100%; object-fit: contain; }

        .topbar-title { font-size: 13px; font-weight: 700; color: #f1f5f9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* MAIN CONTENT */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 32px 36px; min-width: 0; }

        .page-header { margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }

        .page-title h2 { font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.4px; line-height: 1.3; }
        .page-title p  { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

        .live-badge { display: inline-flex; align-items: center; gap: 8px; background: #fff; padding: 7px 14px; border-radius: 9999px; font-size: 12.5px; font-weight: 600; color: #0f172a; box-shadow: var(--card-shadow); border: 1px solid var(--border-color); white-space: nowrap; }

        .pulse-dot { width: 8px; height: 8px; background: #10b981; border-radius: 50%; position: relative; flex-shrink: 0; }
        .pulse-dot::after { content: ''; position: absolute; inset: 0; background: #10b981; border-radius: 50%; animation: pulse 1.8s infinite ease-in-out; }

        @keyframes pulse { 0% { transform: scale(1); opacity: 0.8; } 100% { transform: scale(2.5); opacity: 0; } }

        /* CARDS */
        .card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: var(--card-shadow); border: 1px solid var(--border-color); margin-bottom: 20px; }

        .card-header { margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }

        .card-title { font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; letter-spacing: -0.2px; }

        /* FORM */
        label { display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px; }

        input[type="text"],
        input[type="number"],
        input[type="password"],
        input[type="date"],
        input[type="file"],
        select {
            width: 100%; padding: 11px 14px; margin-bottom: 18px;
            border: 1.5px solid #cbd5e1; border-radius: 10px;
            font-size: 14px; font-family: inherit; color: #0f172a; background: #fff;
            transition: all 0.2s ease;
            -webkit-appearance: none; appearance: none;
        }

        input:focus, select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

        /* BUTTONS */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            padding: 10px 18px; border-radius: 9px;
            font-weight: 600; font-size: 13.5px; cursor: pointer; border: none;
            transition: all 0.2s ease; text-decoration: none;
            min-height: 42px; white-space: nowrap;
        }

        .btn-primary { background: var(--primary-gradient); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,0.25); }
        .btn-primary:hover { opacity: 0.94; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(37,99,235,0.35); }

        .btn-success { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 4px 12px rgba(16,185,129,0.25); }
        .btn-success:hover { opacity: 0.94; transform: translateY(-1px); }

        .btn-block { width: 100%; }

        /* TABLE */
        .table-responsive { width: 100%; overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color); -webkit-overflow-scrolling: touch; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; min-width: 540px; }

        th { background: #f8fafc; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 13px 14px; border-bottom: 1px solid var(--border-color); text-align: center; white-space: nowrap; }

        td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; text-align: center; vertical-align: middle; }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: #f8fafc; }

        /* BADGES */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 9999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge-masuk  { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-pulang { background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; }
        .badge-verif  { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

        .nama-container { text-align: left; }
        .nama-title { font-weight: 700; color: #0f172a; font-size: 13px; }
        .dept-subtitle { font-size: 11px; color: #64748b; margin-top: 2px; }
        .text-unregistered { color: #94a3b8; font-style: italic; text-align: left; font-size: 12px; }

        @media (max-width: 1024px) {
            .main-content { padding: 28px 22px; }
        }

        @media (max-width: 768px) {
            .topbar-mobile { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.open { display: block; }
            .main-content { margin-left: 0; padding: 74px 14px 24px 14px; }
            .page-title h2 { font-size: 17px; }
            .page-title p  { font-size: 12px; }
            .live-badge { font-size: 11px; padding: 6px 10px; }
            .card { padding: 14px; border-radius: 12px; }
            .card-title { font-size: 14px; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 8px; }
        }

        @media (max-width: 480px) {
            .main-content { padding: 70px 10px 20px 10px; }
            .card { padding: 12px; }
            .btn { padding: 9px 12px; font-size: 13px; min-height: 40px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            th { font-size: 10px; padding: 11px 10px; }
            td { padding: 10px 10px; font-size: 12px; }
        }
    </style>
</head>
<body>

<div class="app-layout">

    <!-- TOPBAR MOBILE (Hamburger) -->
    <div class="topbar-mobile" id="topbar-mobile">
        <button class="hamburger-btn" id="hamburger-btn" onclick="toggleSidebar()" aria-label="Buka menu">
            <span></span><span></span><span></span>
        </button>
        <div class="topbar-logo">
            <img src="assets/logo_pasundan2.jpg" alt="Logo">
        </div>
        <div class="topbar-title">Monitoring Absensi — SMK Pasundan 2</div>
    </div>

    <!-- Overlay (tutup sidebar) -->
    <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo">
                <img src="assets/logo_pasundan2.jpg" alt="Logo SMK Pasundan 2 Bandung">
            </div>
            <div class="brand-text">
                <h1>SMK Pasundan 2</h1>
                <p>Kota Bandung</p>
            </div>
        </div>

        <nav class="sidebar-menu">
            <div class="menu-label">Navigasi Utama</div>

            <a href="index.php" class="nav-item <?php echo $active_menu === 'index' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </span>
                <span>Live Monitoring</span>
            </a>

            <a href="export_bulanan.php" class="nav-item <?php echo $active_menu === 'laporan_bulanan' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                <span>Laporan Bulanan</span>
            </a>

            <a href="riwayat_karyawan.php" class="nav-item <?php echo $active_menu === 'riwayat' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <span>Riwayat Individual</span>
            </a>

            <?php if (is_superadmin()): ?>
            <a href="input_karyawan.php" class="nav-item <?php echo $active_menu === 'karyawan' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 100 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </span>
                <span>Kelola Guru & Karyawan</span>
            </a>

            <a href="tarik_nama.php" class="nav-item <?php echo $active_menu === 'sinkron' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </span>
                <span>Sinkronisasi Mesin</span>
            </a>

            <div class="menu-label" style="margin-top: 10px;">Pengaturan</div>
            <a href="kelola_jadwal.php" class="nav-item <?php echo $active_menu === 'jadwal_guru' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </span>
                <span>Kelola Jadwal Guru</span>
            </a>

            <a href="kelola_user.php" class="nav-item <?php echo $active_menu === 'users' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </span>
                <span>Manajemen User</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-user">
            <div class="user-details">
                <div class="avatar">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <div>
                    <div class="user-name"><?php echo $username; ?></div>
                    <div class="user-role"><?php echo is_superadmin() ? 'Superadmin' : 'Operator Admin'; ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout" title="Logout">Logout</a>
        </div>

        <!-- FOOTER CREDIT -->
        <div style="padding: 10px 16px 14px 16px; text-align: center;">
            <span style="font-size: 11px; color: #475569; letter-spacing: 0.2px;">
                Build by <span style="font-weight: 600; color: #94a3b8;">Indra Setia</span>
            </span>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <!-- PAGE HEADER -->
        <header class="page-header">
            <div class="page-title">
                <h2>Monitoring Absensi Guru & Karyawan SMK Pasundan 2 Bandung</h2>
                <p>Sistem Pemantauan Absensi Real-Time Mesin Solution X606-S</p>
            </div>
            <?php if ($active_menu === 'index'): ?>
            <div class="live-badge">
                <span class="pulse-dot"></span>
                <span>Live Refresh (5s)</span>
            </div>
            <?php endif; ?>
        </header>
    <?php
}

function render_footer() {
    ?>
    </main>
</div>

<script>
function toggleSidebar() {
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebar-overlay');
    if (sidebar.classList.contains('open')) {
        closeSidebar();
    } else {
        sidebar.classList.add('open');
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-overlay').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSidebar();
});
</script>

</body>
</html>
    <?php
}
?>
