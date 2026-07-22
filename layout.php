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
    <!-- Polling data otomatis dilakukan via AJAX di background tanpa me-refresh halaman -->
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
            --primary-gradient: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
            --text-dark: #1e293b;
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

        /* LAYOUT CONTAINER */
        .app-layout {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* SIDEBAR STYLING */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
            box-shadow: 4px 0 25px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            padding: 24px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .brand-logo {
            width: 48px;
            height: 48px;
            background: #ffffff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            overflow: hidden;
        }

        .brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand-text h1 {
            font-size: 15px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.25;
            letter-spacing: -0.2px;
        }

        .brand-text p {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 500;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* NAVIGATION LINKS */
        .sidebar-menu {
            padding: 20px 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .menu-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 12px 6px 12px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 10px;
            transition: all 0.25s ease;
        }

        .nav-item .icon {
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            transition: transform 0.2s ease;
        }

        .nav-item:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(4px);
        }

        .nav-item.active {
            color: #ffffff;
            background: var(--primary-gradient);
            font-weight: 600;
            box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.38);
        }

        .nav-item.active .icon {
            transform: scale(1.1);
        }

        /* SIDEBAR USER FOOTER */
        .sidebar-user {
            padding: 16px 18px;
            margin: 14px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-details {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 36px;
            height: 36px;
            background: #334155;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .user-name {
            font-size: 13px;
            font-weight: 600;
            color: #f1f5f9;
        }

        .user-role {
            font-size: 11px;
            color: #94a3b8;
        }

        .btn-logout {
            color: #f43f5e;
            text-decoration: none;
            font-size: 18px;
            padding: 6px;
            border-radius: 6px;
            transition: background 0.2s;
            display: flex;
            align-items: center;
        }

        .btn-logout:hover {
            background: rgba(244, 63, 94, 0.15);
        }

        /* MAIN CONTENT AREA */
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 32px 36px;
            min-width: 0;
        }

        /* MAIN HEADER BANNER */
        .page-header {
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-title h2 {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
        }

        .page-title p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #ffffff;
            padding: 8px 16px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
        }

        .pulse-dot {
            width: 10px;
            height: 10px;
            background-color: #10b981;
            border-radius: 50%;
            position: relative;
        }

        .pulse-dot::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            background-color: #10b981;
            border-radius: 50%;
            animation: pulse 1.8s infinite ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(2.5); opacity: 0; }
        }

        /* UI CARDS */
        .card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .card-header {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* FORM CONTROLS */
        label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: #334155;
            margin-bottom: 6px;
        }

        input[type="text"], input[type="number"], input[type="password"], input[type="file"], select {
            width: 100%;
            padding: 11px 14px;
            margin-bottom: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
        }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.25s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }

        .btn-block {
            width: 100%;
        }

        /* DATA TABLES */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #ffffff;
        }

        th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
        }

        td {
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            color: #334155;
            text-align: center;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: #f8fafc;
        }

        /* STATUS BADGES */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .badge-masuk {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .badge-pulang {
            background: #ffe4e6;
            color: #be123c;
            border: 1px solid #fecdd3;
        }

        .badge-verif {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .nama-container {
            text-align: left;
        }

        .nama-title {
            font-weight: 700;
            color: #0f172a;
            font-size: 14px;
        }

        .dept-subtitle {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            margin-top: 2px;
        }

        .text-unregistered {
            color: #94a3b8;
            font-style: italic;
            text-align: left;
            font-size: 13px;
        }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
            }
            .sidebar-brand .brand-text, .sidebar-menu .menu-label, .sidebar-menu .nav-item span, .sidebar-user .user-details {
                display: none;
            }
            .sidebar-brand { justify-content: center; padding: 20px 0; }
            .nav-item { justify-content: center; padding: 14px; }
            .sidebar-user { justify-content: center; padding: 10px; margin: 10px 5px; }
            .main-content { margin-left: 70px; padding: 20px; }
        }
    </style>
</head>
<body>

<div class="app-layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
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
            
            <a href="index.php" class="nav-item <?php echo $active_menu === 'index' ? 'active' : ''; ?>">
                <span class="icon">📡</span>
                <span>Live Monitoring</span>
            </a>

            <?php if (is_superadmin()): ?>
            <a href="input_karyawan.php" class="nav-item <?php echo $active_menu === 'karyawan' ? 'active' : ''; ?>">
                <span class="icon">👥</span>
                <span>Kelola Guru & Karyawan</span>
            </a>

            <a href="tarik_nama.php" class="nav-item <?php echo $active_menu === 'sinkron' ? 'active' : ''; ?>">
                <span class="icon">🔌</span>
                <span>Sinkronisasi Mesin</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-user">
            <div class="user-details">
                <div class="avatar">👤</div>
                <div>
                    <div class="user-name"><?php echo $username; ?></div>
                    <div class="user-role"><?php echo is_superadmin() ? 'Superadmin' : 'Operator Admin'; ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout" title="Logout">🚪</a>
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

</body>
</html>
    <?php
}
?>
