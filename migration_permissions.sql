CREATE TABLE IF NOT EXISTS role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role VARCHAR(20) NOT NULL,
  page_key VARCHAR(50) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY role_page (role, page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO role_permissions (role, page_key, is_allowed) VALUES
('admin','index',1),('admin','export_bulanan',1),('admin','riwayat',1),
('admin','kelola_izin',0),('admin','rnd_analytics',0),('admin','audit_log',0),
('admin','jadwal_guru',1),('admin','export_pdf',0),('admin','export_excel',1),
('rnd','index',1),('rnd','export_bulanan',1),('rnd','riwayat',1),
('rnd','kelola_izin',1),('rnd','rnd_analytics',1),('rnd','audit_log',1),
('rnd','jadwal_guru',1),('rnd','export_pdf',1),('rnd','export_excel',1),
('user','user_profile',1),('user','user_izin',1),('user','user_riwayat',1);
