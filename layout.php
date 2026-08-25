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
    <title><?php echo h($page_title); ?> — <?php echo h(get_tenant_school_name()); ?></title>
    <!-- Favicon & Icon Tab Browser -->
    <link rel="icon" type="image/jpeg" href="assets/logo_pasundan2.png">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
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
            max-width: 85vw;
            background: var(--sidebar-bg);
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; bottom: 0; left: 0;
            z-index: 999;
            box-shadow: 4px 0 25px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.15) transparent;
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            z-index: 998;
            backdrop-filter: blur(4px);
            animation: fadeIn 0.25s ease;
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .sidebar-brand {
            padding: 18px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
            position: relative;
        }

        .brand-logo {
            width: 40px; height: 40px; min-width: 40px;
            background: #fff;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            padding: 4px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .brand-logo img { width: 100%; height: 100%; object-fit: contain; }

        .brand-text h1 { font-size: 13.5px; font-weight: 700; color: #fff; line-height: 1.25; }
        .brand-text p  { font-size: 10.5px; color: #94a3b8; font-weight: 500; margin-top: 1px; text-transform: uppercase; letter-spacing: 0.5px; }

        .close-sidebar-btn {
            display: none;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            color: #cbd5e1;
            width: 32px; height: 32px;
            border-radius: 8px;
            cursor: pointer;
            align-items: center; justify-content: center;
            margin-left: auto;
            transition: all 0.2s;
        }
        .close-sidebar-btn:hover { background: rgba(239,68,68,0.2); color: #fca5a5; border-color: rgba(239,68,68,0.3); }

        .sidebar-nav, .sidebar-menu { padding: 14px 10px; flex: 1; display: flex; flex-direction: column; gap: 3px; }

        .menu-label {
            font-size: 10px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1.2px;
            padding: 10px 10px 4px 10px;
        }
        .menu-label:not(:first-child) {
            border-top: 1px solid rgba(255,255,255,0.06);
            margin-top: 8px;
            padding-top: 12px;
        }

        .nav-item {
            display: flex; align-items: center; gap: 11px;
            padding: 9.5px 12px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 13px; font-weight: 500;
            border-radius: 10px;
            transition: all 0.2s ease;
            min-height: 42px;
        }

        .nav-item span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-item .icon-svg {
            width: 18px; height: 18px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            color: #94a3b8;
            transition: color 0.2s ease;
        }

        .nav-item:hover { color: #fff; background: rgba(255,255,255,0.08); transform: translateX(3px); }
        .nav-item:hover .icon-svg { color: #fff; }

        .nav-item.active {
            color: #fff;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
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
            height: 56px;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 997;
            align-items: center;
            padding: 0 14px;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 4px 16px rgba(0,0,0,0.25);
        }

        .hamburger-btn {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            cursor: pointer;
            width: 38px; height: 38px;
            border-radius: 9px;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4.5px;
            transition: background 0.2s, border-color 0.2s;
            flex-shrink: 0;
        }

        .hamburger-btn span { display: block; width: 20px; height: 2px; background: #f1f5f9; border-radius: 2px; transition: all 0.2s; }
        .hamburger-btn:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.2); }

        .topbar-logo { width: 30px; height: 30px; background: #fff; border-radius: 8px; overflow: hidden; padding: 2px; flex-shrink: 0; }
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
        input[type="time"],
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

        /* MODERN PAGINATION STYLING */
        .pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            border-radius: 0 0 16px 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .pagination-info {
            font-size: 12.5px;
            color: #64748b;
            font-weight: 600;
        }
        .pagination-pill {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            color: #334155;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            transition: all 0.2s ease;
            user-select: none;
            box-sizing: border-box;
        }
        .pagination-pill:hover:not(.disabled):not(.active) {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(37,99,235,0.12);
        }
        .pagination-pill.active {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff !important;
            border-color: #2563eb;
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
            font-weight: 700;
        }
        .pagination-pill.disabled {
            color: #94a3b8;
            background: #f8fafc;
            border-color: #e2e8f0;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .pagination-dots {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 34px;
            font-size: 13px;
            font-weight: 700;
            color: #94a3b8;
        }

        /* NOTIFICATION BELL & DROPDOWN */
        .notif-dropdown-container {
            position: relative;
            display: inline-block;
        }
        .notif-btn {
            width: 40px; height: 40px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: center;
            color: #475569;
            cursor: pointer;
            position: relative;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            transition: all 0.2s;
        }
        .notif-btn:hover { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
        .notif-btn:active { transform: scale(0.96); }
        .notif-badge {
            position: absolute;
            top: -4px; right: -4px;
            background: #ef4444; color: #fff;
            font-size: 10px; font-weight: 800;
            min-width: 18px; height: 18px;
            border-radius: 99px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(239,68,68,0.4);
        }
        .notif-dropdown-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            left: auto;
            width: 350px;
            max-width: calc(100vw - 32px);
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15,23,42,0.18);
            z-index: 10000;
            display: none;
            overflow: visible;
            animation: dropdownFade 0.2s ease;
        }
        .notif-dropdown-menu::before {
            content: '';
            position: absolute;
            top: -6px;
            right: 14px;
            width: 10px;
            height: 10px;
            background: #f8fafc;
            border-left: 1px solid #cbd5e1;
            border-top: 1px solid #cbd5e1;
            transform: rotate(45deg);
            z-index: 1;
        }
        @keyframes dropdownFade { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
        
        .notif-header {
            padding: 12px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            border-radius: 15px 15px 0 0;
            font-size: 12.5px; font-weight: 700; color: #0f172a;
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px;
            position: relative;
            z-index: 2;
        }
        .notif-header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .notif-act-btn {
            background: none;
            border: none;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            padding: 3px 6px;
            border-radius: 6px;
            transition: all 0.15s ease;
        }
        .notif-act-read {
            color: #2563eb;
        }
        .notif-act-read:hover {
            background: #eff6ff;
        }
        .notif-act-del {
            color: #ef4444;
        }
        .notif-act-del:hover {
            background: #fee2e2;
        }

        .notif-list { max-height: 350px; overflow-y: auto; position: relative; z-index: 2; background: #fff; border-radius: 0 0 15px 15px; }
        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            transition: background 0.15s;
            word-break: break-word;
            position: relative;
        }
        .notif-item:hover { background: #f8fafc; }
        .notif-item.unread { background: #f0f9ff; border-left: 3.5px solid #3b82f6; }
        .notif-item-content {
            flex: 1;
            min-width: 0;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .notif-item-title { font-size: 12.5px; font-weight: 700; color: #0f172a; margin-bottom: 3px; line-height: 1.35; }
        .notif-item-msg { font-size: 11.5px; color: #475569; line-height: 1.45; word-break: break-word; }
        .notif-item-time { font-size: 10.5px; color: #94a3b8; margin-top: 4px; font-weight: 600; }
        
        .notif-del-btn {
            width: 26px; height: 26px;
            border-radius: 6px;
            border: none;
            background: transparent;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            margin-top: 1px;
            transition: all 0.15s ease;
        }
        .notif-del-btn:hover {
            background: #fee2e2;
            color: #ef4444;
        }

        .toast-notif-popup {
            position: fixed;
            top: 20px; right: 20px;
            background: #0f172a; color: #fff;
            padding: 14px 20px;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            z-index: 9999;
            display: flex; align-items: center; gap: 12px;
            max-width: 380px;
            animation: slideInRight 0.3s ease;
            border-left: 4px solid #3b82f6;
        }
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        .nama-container { text-align: left; }
        .nama-title { font-weight: 700; color: #0f172a; font-size: 13px; }
        .dept-subtitle { font-size: 11px; color: #64748b; margin-top: 2px; }
        .text-unregistered { color: #94a3b8; font-style: italic; text-align: left; font-size: 12px; }

        @media (max-width: 1024px) {
            .main-content { padding: 28px 20px; }
        }

        @media (max-width: 768px) {
            .topbar-mobile { display: flex; }
            .close-sidebar-btn { display: flex; }
            .sidebar { transform: translateX(-100%); width: min(275px, 85vw); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.open { display: block; }
            .main-content { margin-left: 0 !important; padding: 72px 14px 24px 14px; }
            .page-title h2 { font-size: 17px; }
            .page-title p  { font-size: 12px; }
            .live-badge { font-size: 11px; padding: 6px 10px; }
            .card { padding: 14px; border-radius: 12px; }
            .card-title { font-size: 14px; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 8px; }
            
            /* SEMBUNYIKAN ICON NOTIFIKASI DI PAGE HEADER PADA MOBILE (HANYA PAKAI YANG DI TOPBAR ATAS) */
            .page-header .notif-dropdown-container {
                display: none !important;
            }

            .topbar-mobile .notif-dropdown-menu {
                position: absolute !important;
                top: calc(100% + 10px) !important;
                right: 0 !important;
                left: auto !important;
                width: 330px !important;
                max-width: calc(100vw - 24px) !important;
                border-radius: 16px !important;
                box-shadow: 0 14px 35px rgba(15, 23, 42, 0.25) !important;
                z-index: 10005 !important;
            }
            .topbar-mobile .notif-dropdown-menu::before {
                right: 12px !important;
                left: auto !important;
            }
        }

        @media (max-width: 480px) {
            .main-content { padding: 68px 10px 20px 10px; }
            .card { padding: 12px; }
            .btn { padding: 8.5px 12px; font-size: 12.5px; min-height: 40px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            th { font-size: 10.5px; padding: 10px 8px; }
            td { padding: 9px 8px; font-size: 12px; }
        }
    </style>
</head>
<body>

<div class="app-layout">

    <!-- TOPBAR MOBILE (Hamburger, Logo, Title, Notif) -->
    <div class="topbar-mobile" id="topbar-mobile">
        <button class="hamburger-btn" id="hamburger-btn" onclick="toggleSidebar()" aria-label="Buka menu">
            <span></span><span></span><span></span>
        </button>
        <div class="topbar-logo">
            <img src="assets/logo_pasundan2.jpg" alt="Logo">
        </div>
        <div class="topbar-title">Monitoring Absensi</div>
        <?php if (can_access_page('notifikasi')): ?>
        <div class="notif-dropdown-container" style="margin-left:auto;">
            <button type="button" class="topbar-mobile-notif-btn" onclick="toggleNotifDropdown(event, 'topbar')" title="Notifikasi Real-time" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); width:36px; height:36px; border-radius:9px; color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; position:relative;">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span class="notif-badge" id="notifBadgeMobile" style="display:none; position:absolute; top:-3px; right:-3px; min-width:16px; height:16px; font-size:9.5px; padding:0 3px; border:2px solid #0f172a;">0</span>
            </button>
            <!-- DROPDOWN MENU TOPBAR -->
            <div class="notif-dropdown-menu" id="notifMenuMobile">
                <div class="notif-header">
                    <div style="display:flex; align-items:center; gap:6px; font-weight:800; font-size:13px; color:#0f172a;">
                        <svg width="15" height="15" fill="none" stroke="#2563eb" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <span>Notifikasi</span>
                    </div>
                    <div class="notif-header-actions">
                        <button type="button" class="notif-act-btn notif-act-read" onclick="markAllNotifRead(event)">Tandai Dibaca</button>
                        <span style="color:#cbd5e1; font-size:11px;">|</span>
                        <button type="button" class="notif-act-btn notif-act-del" onclick="deleteAllNotif(event)">Hapus Semua</button>
                    </div>
                </div>
                <div class="notif-list" id="notifListMobile">
                    <div style="padding:20px; text-align:center; color:#94a3b8; font-size:12px;">Memuat notifikasi...</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Overlay (tutup sidebar) -->
    <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo">
                <img src="assets/logo_pasundan2.jpg" alt="Logo Sekolah">
            </div>
            <div class="brand-text">
                <h1><?php echo h(get_tenant_school_name()); ?></h1>
                <p><?php echo !empty($_SESSION['active_tenant_code']) && $_SESSION['active_tenant_code'] !== 'pasundan2' ? 'Tenant ' . h(strtoupper($_SESSION['active_tenant_code'])) : 'Kota Bandung'; ?></p>
            </div>
            <button class="close-sidebar-btn" onclick="closeSidebar()" aria-label="Tutup Menu">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <?php if (is_user_role()): ?>
            <div class="menu-label">Portal Mandiri</div>

            <?php if (can_access_page('absen_mandiri') || can_access_page('user_profile')): ?>
            <a href="absen_mandiri.php" class="nav-item <?php echo $active_menu === 'absen_mandiri' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </span>
                <span>Absen Mandiri</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('user_profile')): ?>
            <a href="user_profile.php" class="nav-item <?php echo $active_menu === 'user_profile' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </span>
                <span>Profil &amp; Data Diri</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('user_izin')): ?>
            <a href="user_izin.php" class="nav-item <?php echo $active_menu === 'user_izin' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                <span>Pengajuan Cuti/Izin/Sakit</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('user_riwayat')): ?>
            <a href="user_riwayat.php" class="nav-item <?php echo $active_menu === 'user_riwayat' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <span>Riwayat Presensi Saya</span>
            </a>
            <?php endif; ?>

            <a href="ganti_password.php" class="nav-item <?php echo $active_menu === 'ganti_password' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </span>
                <span>Ganti Password Akun</span>
            </a>

            <?php else: ?>

            <?php if (!is_superadmin() && (can_access_page('absen_mandiri') || can_access_page('user_profile') || can_access_page('user_izin') || can_access_page('user_riwayat'))): ?>
            <div class="menu-label">Portal Mandiri Saya</div>

            <?php if (can_access_page('absen_mandiri') || can_access_page('user_profile')): ?>
            <a href="absen_mandiri.php" class="nav-item <?php echo $active_menu === 'absen_mandiri' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </span>
                <span>Absen Mandiri</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('user_profile')): ?>
            <a href="user_profile.php" class="nav-item <?php echo $active_menu === 'user_profile' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </span>
                <span>Profil &amp; Data Diri</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('user_izin')): ?>
            <a href="user_izin.php" class="nav-item <?php echo $active_menu === 'user_izin' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                <span>Pengajuan Cuti/Izin/Sakit</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('user_riwayat')): ?>
            <a href="user_riwayat.php" class="nav-item <?php echo $active_menu === 'user_riwayat' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <span>Riwayat Presensi Saya</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <div class="menu-label">Navigasi Utama</div>

            <?php if (can_access_page('index')): ?>
            <a href="index.php" class="nav-item <?php echo $active_menu === 'index' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </span>
                <span>Live Monitoring</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('export_bulanan')): ?>
            <a href="export_bulanan.php" class="nav-item <?php echo $active_menu === 'laporan_bulanan' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                <span>Laporan Bulanan</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('riwayat')): ?>
            <a href="riwayat_karyawan.php" class="nav-item <?php echo $active_menu === 'riwayat' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <span>Riwayat Individual</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('kelola_izin')): ?>
            <a href="kelola_izin.php" class="nav-item <?php echo $active_menu === 'kelola_izin' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                <span>Cuti, Izin &amp; Sakit</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('rnd_analytics') || can_access_page('audit_log')): ?>
            <div class="menu-label" style="margin-top: 10px;">Fitur Riset &amp; Audit</div>
            <?php endif; ?>

            <?php if (can_access_page('rnd_analytics')): ?>
            <a href="rnd_analytics.php" class="nav-item <?php echo $active_menu === 'rnd_analytics' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </span>
                <span>RnD Analytics</span>
            </a>
            <?php endif; ?>

            <?php if (can_access_page('audit_log')): ?>
            <a href="audit_log.php" class="nav-item <?php echo $active_menu === 'audit_log' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </span>
                <span>Audit Log System</span>
            </a>
            <?php endif; ?>

            <?php if (is_superadmin()): ?>
            <a href="input_karyawan.php" class="nav-item <?php echo $active_menu === 'karyawan' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 100 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </span>
                <span>Kelola Guru &amp; Karyawan</span>
            </a>

            <a href="tarik_nama.php" class="nav-item <?php echo $active_menu === 'sinkron' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </span>
                <span>Sinkronisasi Mesin</span>
            </a>
            <?php endif; ?>

            <div class="menu-label" style="margin-top: 10px;">Pengaturan</div>

            <?php if (can_access_page('jadwal_guru')): ?>
            <a href="kelola_jadwal.php" class="nav-item <?php echo $active_menu === 'jadwal_guru' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </span>
                <span>Kelola Jadwal Guru</span>
            </a>
            <?php endif; ?>

            <?php if (is_superadmin()): ?>
            <a href="kelola_user.php" class="nav-item <?php echo $active_menu === 'users' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </span>
                <span>Manajemen User</span>
            </a>

            <a href="kelola_permissions.php" class="nav-item <?php echo $active_menu === 'kelola_permissions' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </span>
                <span>Hak Akses Role</span>
            </a>

            <div class="menu-label" style="margin-top: 10px; color: #38bdf8;">Platform Multi-Tenant</div>
            <a href="master_tenants.php" class="nav-item <?php echo $active_menu === 'master_tenants' ? 'active' : ''; ?>" onclick="closeSidebar()" style="background: rgba(56, 189, 248, 0.08); border-left: 3px solid #38bdf8;">
                <span class="icon-svg" style="color: #38bdf8;">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </span>
                <span style="font-weight: 700; color: #e0f2fe;">Kelola Sekolah (SaaS)</span>
            </a>
            <?php endif; ?>

            <a href="ganti_password.php" class="nav-item <?php echo $active_menu === 'ganti_password' ? 'active' : ''; ?>" onclick="closeSidebar()">
                <span class="icon-svg">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </span>
                <span>Ganti Password Akun</span>
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
                    <div class="user-role"><?php echo is_superadmin() ? 'Superadmin' : (is_rnd() ? 'RnD Researcher' : (is_user_role() ? 'User Karyawan' : (is_tatausaha() ? 'Tata Usaha' : (is_staff() ? 'Staff' : 'Operator Admin')))); ?></div>
                    <?php 
                    $pin_connected = $_SESSION['pin'] ?? '';
                    if (!empty($pin_connected)): 
                    ?>
                        <div style="font-size:10px; color:#38bdf8; margin-top:2px; font-weight:600;">
                            🔗 PIN: <?php echo h($pin_connected); ?>
                        </div>
                    <?php endif; ?>
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
            <div style="display:flex; align-items:center; gap:12px;">
                <?php if (can_access_page('notifikasi')): ?>
                <!-- NOTIFICATION BELL DROPDOWN -->
                <div class="notif-dropdown-container" style="position:relative;">
                    <button type="button" class="notif-btn" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifikasi Real-time">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                    </button>
                    <!-- DROPDOWN MENU -->
                    <div class="notif-dropdown-menu" id="notifMenu">
                        <div class="notif-header">
                            <div style="display:flex; align-items:center; gap:6px; font-weight:800; font-size:13px; color:#0f172a;">
                                <svg width="15" height="15" fill="none" stroke="#2563eb" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                <span>Notifikasi</span>
                            </div>
                            <div class="notif-header-actions">
                                <button type="button" class="notif-act-btn notif-act-read" onclick="markAllNotifRead(event)">Tandai Dibaca</button>
                                <span style="color:#cbd5e1; font-size:11px;">|</span>
                                <button type="button" class="notif-act-btn notif-act-del" onclick="deleteAllNotif(event)">Hapus Semua</button>
                            </div>
                        </div>
                        <div class="notif-list" id="notifList">
                            <div style="padding:20px; text-align:center; color:#94a3b8; font-size:12px;">Memuat notifikasi...</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($active_menu === 'index'): ?>
                <div class="live-badge">
                    <span class="pulse-dot"></span>
                    <span>Live Refresh (5s)</span>
                </div>
                <?php endif; ?>
            </div>
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

<?php if (can_access_page('notifikasi')): ?>
// REALTIME NOTIFICATION SYSTEM JS
let lastUnreadCount = null;

function fetchNotif() {
    fetch('api_notifikasi.php')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const badge = document.getElementById('notifBadge');
                const badgeMobile = document.getElementById('notifBadgeMobile');
                const list = document.getElementById('notifList');
                const listMobile = document.getElementById('notifListMobile');
                
                const updateBadge = (el) => {
                    if (el) {
                        if (data.unread_count > 0) {
                            el.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                            el.style.display = 'flex';
                        } else {
                            el.style.display = 'none';
                        }
                    }
                };

                updateBadge(badge);
                updateBadge(badgeMobile);

                // If unread count increased, show real-time popup!
                if (lastUnreadCount !== null && data.unread_count > lastUnreadCount && data.items.length > 0) {
                    const newest = data.items[0];
                    showToastNotif(newest.title, newest.message);
                }
                lastUnreadCount = data.unread_count;

                // Render items
                const renderItems = (targetList) => {
                    if (!targetList) return;
                    if (data.items.length === 0) {
                        targetList.innerHTML = '<div style="padding:28px 16px; text-align:center; color:#94a3b8; font-size:12.5px;"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" style="margin:0 auto 6px auto; display:block; opacity:0.6;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>Tidak ada notifikasi</div>';
                    } else {
                        let html = '';
                        data.items.forEach(item => {
                            const unreadClass = item.is_read ? '' : 'unread';
                            html += `
                                <div class="notif-item ${unreadClass}" id="notif-item-${item.id}">
                                    <a href="${item.link}" class="notif-item-content" onclick="markNotifRead(${item.id})">
                                        <div class="notif-item-title">${item.title}</div>
                                        <div class="notif-item-msg">${item.message}</div>
                                        <div class="notif-item-time">${item.time_str}</div>
                                    </a>
                                    <button type="button" class="notif-del-btn" onclick="deleteSingleNotif(event, ${item.id})" title="Hapus notifikasi">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            `;
                        });
                        targetList.innerHTML = html;
                    }
                };

                renderItems(list);
                renderItems(listMobile);
            }
        })
        .catch(err => console.error('Notif error:', err));
}

function toggleNotifDropdown(event, type) {
    if (event) event.stopPropagation();
    const menuHeader = document.getElementById('notifMenu');
    const menuMobile = document.getElementById('notifMenuMobile');
    
    if (type === 'topbar') {
        if (menuHeader) menuHeader.style.display = 'none';
        if (menuMobile) {
            const isHidden = (menuMobile.style.display === 'none' || menuMobile.style.display === '');
            menuMobile.style.display = isHidden ? 'block' : 'none';
        }
    } else {
        if (menuMobile) menuMobile.style.display = 'none';
        if (menuHeader) {
            const isHidden = (menuHeader.style.display === 'none' || menuHeader.style.display === '');
            menuHeader.style.display = isHidden ? 'block' : 'none';
        }
    }
}

function markAllNotifRead(event) {
    if (event) event.stopPropagation();
    const formData = new FormData();
    formData.append('action', 'mark_all_read');
    fetch('api_notifikasi.php', { method: 'POST', body: formData })
        .then(() => fetchNotif());
}

function markNotifRead(id) {
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('id', id);
    fetch('api_notifikasi.php', { method: 'POST', body: formData });
}

function deleteSingleNotif(event, id) {
    if (event) event.stopPropagation();
    const itemElements = document.querySelectorAll('#notif-item-' + id);
    itemElements.forEach(el => {
        el.style.opacity = '0.3';
        el.style.pointerEvents = 'none';
    });
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    fetch('api_notifikasi.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(() => {
            fetchNotif();
        })
        .catch(() => {
            itemElements.forEach(el => {
                el.style.opacity = '1';
                el.style.pointerEvents = 'auto';
            });
        });
}

function deleteAllNotif(event) {
    if (event) event.stopPropagation();
    if (!confirm('Apakah Anda yakin ingin menghapus semua notifikasi?')) {
        return;
    }
    const formData = new FormData();
    formData.append('action', 'delete_all');
    fetch('api_notifikasi.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(() => {
            fetchNotif();
        });
}

function showToastNotif(title, msg) {
    const toast = document.createElement('div');
    toast.className = 'toast-notif-popup';
    toast.innerHTML = `
        <div style="font-size:18px; display:flex; align-items:center;">
            <svg width="20" height="20" fill="none" stroke="#60a5fa" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        </div>
        <div>
            <div style="font-weight:700; font-size:13px; color:#fff;">${title}</div>
            <div style="font-size:12px; color:#cbd5e1; margin-top:2px;">${msg}</div>
        </div>
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 6000);
}

document.addEventListener('click', function(e) {
    const container = e.target.closest('.notif-dropdown-container');
    if (!container) {
        const menuHeader = document.getElementById('notifMenu');
        const menuMobile = document.getElementById('notifMenuMobile');
        if (menuHeader) menuHeader.style.display = 'none';
        if (menuMobile) menuMobile.style.display = 'none';
    }
});

// Polling notifikasi setiap 8 detik secara real-time
fetchNotif();
setInterval(fetchNotif, 8000);
<?php endif; ?>
</script>

</body>
</html>
    <?php
}

// HELPER FUNCTION RENDER PAGINATION MODERN & SMART (ELLIPSIS WINDOWING)
function render_smart_pagination($current_page, $total_pages, $base_params = []) {
    if ($total_pages <= 1) return '';

    $html = '<div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">';

    $get_url = function($p) use ($base_params) {
        $params = array_merge($base_params, ['page' => $p]);
        return '?' . http_build_query($params);
    };

    // Button Prev
    if ($current_page > 1) {
        $html .= '<a href="' . $get_url($current_page - 1) . '" class="pagination-pill">‹ Prev</a>';
    } else {
        $html .= '<span class="pagination-pill disabled">‹ Prev</span>';
    }

    // Determine range of page numbers to show
    $range = 1;
    $show_pages = [];

    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i == 1 || $i == $total_pages || ($i >= $current_page - $range && $i <= $current_page + $range)) {
            $show_pages[] = $i;
        }
    }

    $prev_p = 0;
    foreach ($show_pages as $p) {
        if ($prev_p > 0 && $p - $prev_p > 1) {
            $html .= '<span class="pagination-dots">…</span>';
        }
        if ($p == $current_page) {
            $html .= '<span class="pagination-pill active">' . $p . '</span>';
        } else {
            $html .= '<a href="' . $get_url($p) . '" class="pagination-pill">' . $p . '</a>';
        }
        $prev_p = $p;
    }

    // Button Next
    if ($current_page < $total_pages) {
        $html .= '<a href="' . $get_url($current_page + 1) . '" class="pagination-pill">Next ›</a>';
    } else {
        $html .= '<span class="pagination-pill disabled">Next ›</span>';
    }

    $html .= '</div>';
    return $html;
}
?>
