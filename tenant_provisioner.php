<?php
// ============================================================
// TENANT PROVISIONING ENGINE (MULTI-TENANT SAAS)
// Otomatisasi Pembuatan Database & Skema untuk Sekolah Baru
// ============================================================

require_once __DIR__ . '/config.php';

class TenantProvisioner {

    /**
     * Membuat Sekolah / Tenant Baru Secara Otomatis
     * 1. Validasi input
     * 2. Buat Database MySQL baru
     * 3. Eksekusi skema tabel dari sql/schema_template.sql
     * 4. Buat Akun Admin Sekolah awal
     * 5. Set nama sekolah di app_settings
     * 6. Buat folder penyimpanan berkas uploads/tenants/{tenant_code}/
     * 7. Daftarkan di database master_tenants
     */
    public static function createTenant($data) {
        $master_conn = getMasterDB();
        if (!$master_conn) {
            return ['success' => false, 'message' => 'Gagal terhubung ke Database Master System.'];
        }

        // Sanitasi input
        $nama_sekolah   = trim($data['nama_sekolah'] ?? '');
        $subdomain      = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['subdomain'] ?? '')));
        $tenant_code    = !empty($data['tenant_code']) ? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['tenant_code']))) : $subdomain;
        $custom_domain  = !empty($data['custom_domain']) ? trim($data['custom_domain']) : null;
        $paket          = trim($data['paket'] ?? 'Pro');
        $max_karyawan   = (int)($data['max_karyawan'] ?? 500);
        $tgl_kadaluarsa = !empty($data['tgl_kadaluarsa']) ? trim($data['tgl_kadaluarsa']) : date('Y-m-d', strtotime('+1 year'));
        $kontak_pic     = trim($data['kontak_pic'] ?? '');
        $no_hp_pic      = trim($data['no_hp_pic'] ?? '');
        $email_pic      = trim($data['email_pic'] ?? '');
        $alamat         = trim($data['alamat'] ?? '');

        // Akun Admin Awal Sekolah
        $admin_user     = trim($data['admin_username'] ?? 'admin_' . $tenant_code);
        $admin_pass     = trim($data['admin_password'] ?? 'admin123');

        // Validasi dasar
        if (empty($nama_sekolah)) {
            return ['success' => false, 'message' => 'Nama sekolah wajib diisi.'];
        }
        if (empty($subdomain) || strlen($subdomain) < 3) {
            return ['success' => false, 'message' => 'Subdomain wajib minimal 3 karakter alfanumerik.'];
        }
        if (empty($admin_pass) || strlen($admin_pass) < 6) {
            return ['success' => false, 'message' => 'Password admin sekolah minimal 6 karakter.'];
        }

        // Cek apakah subdomain / tenant_code sudah terdaftar
        $stmt_c = $master_conn->prepare("SELECT id FROM master_tenants WHERE tenant_code = ? OR subdomain = ?");
        $stmt_c->bind_param("ss", $tenant_code, $subdomain);
        $stmt_c->execute();
        if ($stmt_c->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => "Kode sekolah atau subdomain '{$subdomain}' sudah digunakan. Silakan pilih yang lain."];
        }

        // Nama Database Tenant Baru
        $db_name = "db_tenant_" . $tenant_code;
        $db_host = DB_HOST;
        $db_user = DB_USER;
        $db_pass = DB_PASS;

        // 1. Buat Database MySQL Baru
        $create_db_sql = "CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
        if (!$master_conn->query($create_db_sql)) {
            return ['success' => false, 'message' => 'Gagal membuat database baru: ' . $master_conn->error];
        }

        // 2. Hubungkan ke Database Baru dan Eksekusi Skema Template
        $tenant_conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($tenant_conn->connect_error) {
            return ['success' => false, 'message' => 'Gagal menghubungkan ke database baru: ' . $tenant_conn->connect_error];
        }
        $tenant_conn->set_charset("utf8mb4");

        $schema_path = __DIR__ . '/sql/schema_template.sql';
        if (!file_exists($schema_path)) {
            $schema_path = __DIR__ . '/schema_template.sql';
        }
        if (!file_exists($schema_path) && file_exists('/var/www/absen_web/sql/schema_template.sql')) {
            $schema_path = '/var/www/absen_web/sql/schema_template.sql';
        }
        if (!file_exists($schema_path) && file_exists('/home/setia/absen_web/schema_template.sql')) {
            $schema_path = '/home/setia/absen_web/schema_template.sql';
        }
        if (!file_exists($schema_path)) {
            return ['success' => false, 'message' => 'Berkas skema database (schema_template.sql) tidak ditemukan di server.'];
        }

        $schema_sql = file_get_contents($schema_path);
        if ($tenant_conn->multi_query($schema_sql)) {
            do {
                if ($res = $tenant_conn->store_result()) {
                    $res->free();
                }
            } while ($tenant_conn->more_results() && $tenant_conn->next_result());
        }

        if ($tenant_conn->error) {
            return ['success' => false, 'message' => 'Error saat migrasi skema tabel: ' . $tenant_conn->error];
        }

        // 3. Buat Akun Admin Sekolah Utama di Database Tenant Baru
        $admin_hash = password_hash($admin_pass, PASSWORD_BCRYPT);
        $stmt_adm = $tenant_conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin') ON DUPLICATE KEY UPDATE password = VALUES(password)");
        if ($stmt_adm) {
            $stmt_adm->bind_param("ss", $admin_user, $admin_hash);
            $stmt_adm->execute();
        }

        // 4. Update nama sekolah di app_settings database tenant
        $stmt_set = $tenant_conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('nama_sekolah', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt_set) {
            $stmt_set->bind_param("s", $nama_sekolah);
            $stmt_set->execute();
        }

        // 5. Buat Struktur Folder Uploads Khusus Tenant
        $tenant_upload_base = __DIR__ . "/uploads/tenants/{$tenant_code}/";
        $folders = ['selfie', 'foto_karyawan', 'surat_dokter'];
        foreach ($folders as $f) {
            $path = $tenant_upload_base . $f;
            if (!is_dir($path)) {
                @mkdir($path, 0777, true);
                @chmod($path, 0777);
            }
        }

        // 6. Daftarkan Tenant ke Tabel master_tenants di db_master_system
        $stmt_ins = $master_conn->prepare("INSERT INTO master_tenants 
            (tenant_code, nama_sekolah, subdomain, custom_domain, db_host, db_name, db_user, db_pass, status, paket_langganan, max_karyawan, alamat_sekolah, kontak_pic, no_hp_pic, email_pic, tgl_kadaluarsa) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aktif', ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt_ins->bind_param(
            "ssssssssissssss",
            $tenant_code,
            $nama_sekolah,
            $subdomain,
            $custom_domain,
            $db_host,
            $db_name,
            $db_user,
            $db_pass,
            $paket,
            $max_karyawan,
            $alamat,
            $kontak_pic,
            $no_hp_pic,
            $email_pic,
            $tgl_kadaluarsa
        );

        if ($stmt_ins->execute()) {
            $tenant_id = $stmt_ins->insert_id;
            
            // Catat log master
            $admin_name = $_SESSION['master_username'] ?? 'Master Admin';
            $stmt_log = $master_conn->prepare("INSERT INTO master_audit_logs (admin_username, tenant_code, action, details, ip_address) VALUES (?, ?, 'PROVISION_TENANT', ?, ?)");
            $details = "Membuat sekolah baru '{$nama_sekolah}' (DB: {$db_name}, Subdomain: {$subdomain})";
            $ip = get_client_real_ip();
            $stmt_log->bind_param("ssss", $admin_name, $tenant_code, $details, $ip);
            $stmt_log->execute();

            return [
                'success'        => true,
                'tenant_id'      => $tenant_id,
                'tenant_code'    => $tenant_code,
                'nama_sekolah'   => $nama_sekolah,
                'subdomain'      => $subdomain,
                'db_name'        => $db_name,
                'admin_username' => $admin_user,
                'admin_password' => $admin_pass,
                'message'        => "Sekolah <b>{$nama_sekolah}</b> berhasil dibuat dengan database mandiri <code>{$db_name}</code>!"
            ];
        } else {
            return ['success' => false, 'message' => 'Gagal mendaftarkan tenant ke master: ' . $master_conn->error];
        }
    }

    /**
     * Backup Database Tenant ke File .sql
     */
    public static function backupTenantDB($tenant_code) {
        $master_conn = getMasterDB();
        $stmt = $master_conn->prepare("SELECT * FROM master_tenants WHERE tenant_code = ?");
        $stmt->bind_param("s", $tenant_code);
        $stmt->execute();
        $tenant = $stmt->get_result()->fetch_assoc();

        if (!$tenant) {
            return ['success' => false, 'message' => 'Tenant tidak ditemukan.'];
        }

        $db_name = $tenant['db_name'];
        $backup_dir = __DIR__ . '/backups/';
        if (!is_dir($backup_dir)) {
            @mkdir($backup_dir, 0777, true);
        }

        $filename = "backup_{$tenant_code}_" . date('Ymd_His') . ".sql";
        $filepath = $backup_dir . $filename;

        $cmd = "mysqldump -u " . DB_USER . " -p" . DB_PASS . " " . escapeshellarg($db_name) . " > " . escapeshellarg($filepath);
        exec($cmd, $output, $return_var);

        if ($return_var === 0 && file_exists($filepath)) {
            return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
        } else {
            return ['success' => false, 'message' => 'Gagal mengeksekusi mysqldump.'];
        }
    }

    /**
     * Hapus Sekolah & Database Tenant Secara Permanen
     */
    public static function deleteTenant($tenant_id, $drop_database = true) {
        $master_conn = getMasterDB();
        if (!$master_conn) {
            return ['success' => false, 'message' => 'Gagal terhubung ke Database Master System.'];
        }

        $stmt = $master_conn->prepare("SELECT * FROM master_tenants WHERE id = ?");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $tenant = $stmt->get_result()->fetch_assoc();

        if (!$tenant) {
            return ['success' => false, 'message' => 'Data sekolah tidak ditemukan.'];
        }

        if ($tenant['tenant_code'] === 'pasundan2') {
            return ['success' => false, 'message' => 'Sekolah utama (SMK Pasundan 2) tidak dapat dihapus.'];
        }

        $db_name = $tenant['db_name'];
        $tenant_code = $tenant['tenant_code'];
        $nama_sekolah = $tenant['nama_sekolah'];

        // 1. Drop Database jika dipilih
        if ($drop_database && !empty($db_name) && strpos($db_name, 'db_tenant_') === 0) {
            $master_conn->query("DROP DATABASE IF EXISTS `{$db_name}`");
        }

        // 2. Hapus direktori uploads tenant
        $upload_dir = __DIR__ . "/uploads/tenants/{$tenant_code}/";
        if (is_dir($upload_dir)) {
            self::deleteDirRecursively($upload_dir);
        }

        // 3. Hapus dari master_tenants
        $stmt_del = $master_conn->prepare("DELETE FROM master_tenants WHERE id = ?");
        $stmt_del->bind_param("i", $tenant_id);
        if ($stmt_del->execute()) {
            // Catat log audit
            $admin_name = $_SESSION['master_username'] ?? 'Master Admin';
            $stmt_log = $master_conn->prepare("INSERT INTO master_audit_logs (admin_username, tenant_code, action, details, ip_address) VALUES (?, ?, 'DELETE_TENANT', ?, ?)");
            $details = "Menghapus sekolah '{$nama_sekolah}' (DB: {$db_name})";
            $ip = get_client_real_ip();
            $stmt_log->bind_param("ssss", $admin_name, $tenant_code, $details, $ip);
            $stmt_log->execute();

            return ['success' => true, 'message' => "Sekolah <b>{$nama_sekolah}</b> dan database <code>{$db_name}</code> berhasil dihapus secara permanen."];
        } else {
            return ['success' => false, 'message' => 'Gagal menghapus data sekolah dari master: ' . $master_conn->error];
        }
    }

    private static function deleteDirRecursively($dir) {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) return unlink($dir);
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!self::deleteDirRecursively($dir . DIRECTORY_SEPARATOR . $item)) return false;
        }
        return rmdir($dir);
    }
}
