-- ============================================================
-- MASTER SYSTEM DATABASE (MULTI-TENANT SAAS)
-- Menyimpan data seluruh sekolah, lisensi, dan master admin
-- ============================================================

CREATE DATABASE IF NOT EXISTS `db_master_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `db_master_system`;

-- 1. Tabel Master Tenants (Sekolah-sekolah)
CREATE TABLE IF NOT EXISTS `master_tenants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_code` VARCHAR(64) NOT NULL UNIQUE,
    `nama_sekolah` VARCHAR(255) NOT NULL,
    `subdomain` VARCHAR(100) NOT NULL UNIQUE,
    `custom_domain` VARCHAR(255) NULL UNIQUE,
    `db_host` VARCHAR(100) NOT NULL DEFAULT 'localhost',
    `db_name` VARCHAR(100) NOT NULL,
    `db_user` VARCHAR(100) NOT NULL DEFAULT 'root',
    `db_pass` VARCHAR(100) NOT NULL DEFAULT 'ujangkedu',
    `status` ENUM('aktif','suspend','trial','nonaktif') NOT NULL DEFAULT 'aktif',
    `paket_langganan` VARCHAR(50) NOT NULL DEFAULT 'Pro',
    `max_karyawan` INT NOT NULL DEFAULT 500,
    `logo` VARCHAR(255) NULL,
    `alamat_sekolah` TEXT NULL,
    `kontak_pic` VARCHAR(100) NULL,
    `no_hp_pic` VARCHAR(50) NULL,
    `email_pic` VARCHAR(100) NULL,
    `tgl_dibuat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `tgl_kadaluarsa` DATE NULL,
    `catatan` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel Master Admins (Owner / Platform Superadmin)
CREATE TABLE IF NOT EXISTS `master_admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(64) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `nama` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NULL,
    `role` VARCHAR(50) NOT NULL DEFAULT 'super_platform_admin',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel Master Audit Logs
CREATE TABLE IF NOT EXISTS `master_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NULL,
    `admin_username` VARCHAR(64) NOT NULL,
    `tenant_code` VARCHAR(64) NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Pendaftaran Tenant Pertama: SMK Pasundan 2 Bandung (Memetakan ke db_absen)
INSERT INTO `master_tenants` (
    `tenant_code`, `nama_sekolah`, `subdomain`, `custom_domain`, `db_host`, `db_name`, `db_user`, `db_pass`, `status`, `paket_langganan`, `max_karyawan`, `tgl_kadaluarsa`
) VALUES (
    'pasundan2', 'SMK Pasundan 2 Bandung', 'pasundan2', 'attendance-pas2.my.id', 'localhost', 'db_absen', 'root', 'ujangkedu', 'aktif', 'Enterprise Unlimited', 1000, '2099-12-31'
) ON DUPLICATE KEY UPDATE 
    `nama_sekolah` = VALUES(`nama_sekolah`),
    `custom_domain` = VALUES(`custom_domain`),
    `db_name` = VALUES(`db_name`),
    `status` = 'aktif';

-- 5. Pendaftaran Master Admin Akun Utama (Password default: master123 / masteradmin)
-- Hash untuk password 'master123'
INSERT INTO `master_admins` (`username`, `password_hash`, `nama`, `email`, `role`)
VALUES (
    'masteradmin',
    '$2y$10$tZ8vBfZiWjWn4U34g/uC7uM90dDkV67C2Fq7GfU5r8gP9g6y8EWe.',
    'Master Platform Administrator',
    'admin@attendance.id',
    'super_platform_admin'
) ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`);
