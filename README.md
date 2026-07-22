# Monitoring Absensi Guru & Karyawan — SMK Pasundan 2 Bandung

![PHP](https://img.shields.io/badge/PHP-8.1-blue?logo=php)
![Nginx](https://img.shields.io/badge/Web%20Server-Nginx-green?logo=nginx)
![MariaDB](https://img.shields.io/badge/Database-MariaDB-blue?logo=mariadb)
![ZKTeco](https://img.shields.io/badge/Mesin-ZKTeco%20Solution%20X606--S-orange)

Sistem monitoring absensi real-time untuk guru dan karyawan SMK Pasundan 2 Bandung, terintegrasi langsung dengan mesin absensi **ZKTeco Solution X606-S** via protokol **ADMS PUSH**.

---

## ✨ Fitur Utama

- 📡 **Live Monitoring Real-Time** — Data absensi tampil otomatis setiap 5 detik via AJAX (tanpa reload halaman)
- 🔍 **Pencarian Instan** — Cari berdasarkan nama, PIN, departemen, atau waktu absensi
- 📅 **Filter Tanggal** — Lihat data per hari atau semua tanggal sekaligus
- 📊 **Export ke Excel** — Ekspor data absensi lengkap dengan header detail dan timestamp
- 👥 **Multi-Role Akses** — Superadmin (akses penuh) dan Admin (monitoring saja)
- 📥 **Import Data Karyawan** — Upload data karyawan via file Excel/CSV
- 🔄 **Tarik Nama dari Mesin** — Sinkronisasi nama dari mesin absensi ZKTeco via UDP
- 🛡️ **Keamanan** — CSRF Protection, XSS Escaping, Bcrypt password hashing, RBAC
- 🕒 **Timezone WIB** — Semua waktu disesuaikan dengan Asia/Jakarta (UTC+7)

---

## 🛠️ Teknologi

| Komponen | Detail |
|---|---|
| Backend | PHP 8.1 |
| Web Server | Nginx |
| Database | MariaDB 10.6 |
| Frontend | Vanilla HTML/CSS/JS |
| Library | `0mithun/php-zkteco`, `shuchkin/simplexlsx` |
| Protokol Mesin | ZKTeco ADMS/iClock PUSH |

---

## 🚀 Cara Instalasi

### Prasyarat
- PHP 8.1+ dengan ekstensi: `mysqli`, `sockets`, `mbstring`, `gd`
- Nginx atau Apache
- MariaDB / MySQL
- Composer

### Langkah Instalasi

```bash
# 1. Clone repository
git clone https://github.com/Indra523/absen_web-pasundan.git
cd absen_web-pasundan

# 2. Install dependensi PHP
composer install

# 3. Salin dan sesuaikan konfigurasi
cp config.example.php config.php
# Edit config.php: isi DB_HOST, DB_USER, DB_PASS, DB_NAME, MESIN_IP

# 4. Import database
mysql -u root -p < database/log_absen.sql

# 5. Konfigurasikan Nginx (lihat contoh di bawah)
```

### Contoh Konfigurasi Nginx
```nginx
server {
    listen 80;
    root /path/to/absen_web;
    index index.php;
    server_name _;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/(config\.php|auth\.php)$ { deny all; }
    location ~ /\. { deny all; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## ⚙️ Konfigurasi Mesin ZKTeco

1. Buka **Menu** pada mesin Solution X606-S
2. Masuk ke **Komunikasi → ADMS / Cloud Server**
3. Isi **Server Address**: `http://[IP-SERVER]/iclock/cdata`
4. **Port**: `80`
5. Simpan dan restart mesin

---

## 📋 Akun Default

| Role | Username | Password Default |
|---|---|---|
| Superadmin | `superadmin` | `superadmin123` |
| Admin | `admin` | `admin123` |

> ⚠️ **PENTING**: Segera ganti password default setelah instalasi pertama!

---

## 📁 Struktur Folder

```
absen_web/
├── iclock/
│   └── cdata.php          # Endpoint ADMS PUSH dari mesin
├── assets/
│   └── logo_pasundan2.png # Logo sekolah
├── database/
│   └── log_absen.sql      # Schema database (tidak include data)
├── vendor/                # Composer dependencies (tidak di-commit)
├── config.example.php     # Template konfigurasi
├── index.php              # Halaman Live Monitoring
├── login.php              # Halaman login
├── api_monitoring.php     # API AJAX endpoint
├── export_excel.php       # Export data ke Excel
├── input_karyawan.php     # Import data karyawan
├── tarik_nama.php         # Sinkronisasi nama dari mesin (UDP)
├── layout.php             # Template sidebar & layout
├── auth.php               # Guard autentikasi & RBAC
└── config.php             # Konfigurasi (tidak di-commit, lihat config.example.php)
```

---

## 📄 Lisensi

Dikembangkan untuk keperluan internal **SMK Pasundan 2 Bandung**.
