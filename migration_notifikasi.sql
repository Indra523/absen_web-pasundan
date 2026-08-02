CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  target_role VARCHAR(20) DEFAULT 'admin',
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  type VARCHAR(50) DEFAULT 'perizinan',
  link VARCHAR(255) DEFAULT 'kelola_izin.php',
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO role_permissions (role, page_key, is_allowed) VALUES
('admin', 'notifikasi', 1),
('rnd', 'notifikasi', 1),
('user', 'notifikasi', 0);
