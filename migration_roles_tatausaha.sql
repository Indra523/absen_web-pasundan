-- Seed initial permissions for Tatausaha role
INSERT INTO role_permissions (role, page_key, is_allowed) VALUES
('tatausaha', 'index', 1),
('tatausaha', 'export_bulanan', 1),
('tatausaha', 'riwayat', 1),
('tatausaha', 'export_excel', 1),
('tatausaha', 'notifikasi', 1),
('tatausaha', 'ganti_password', 1)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);
