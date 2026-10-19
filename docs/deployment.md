# Panduan Deployment — Plugin Sekolah

Panduan instalasi, konfigurasi server, dan deploy plugin ke lingkungan production/staging.

---

## 1. Persyaratan Sistem

### Minimum

| Komponen | Versi |
|----------|-------|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Web server | Apache (mod_rewrite) atau Nginx |

### Disarankan (Production)

| Komponen | Versi |
|----------|-------|
| PHP | 8.1+ |
| WordPress | 6.x latest |
| HTTPS | Wajib (OAuth Google, session Secure) |
| Memory limit PHP | ≥ 256M |
| Max upload | ≥ 10M (bukti transfer, lampiran tugas) |

### Ekstensi PHP Wajib

- `json`, `mbstring`, `curl`, `session`
- `gd` atau `imagick` (opsional, untuk image upload)
- `zip` (opsional, lampiran tugas)

---

## 2. Instalasi

### 2.1 Upload Plugin

**Opsi A — Manual:**
```bash
# Upload folder ke server
scp -r sekolah/ user@server:/path/to/wp-content/plugins/

# Atau via FTP/cPanel File Manager
wp-content/plugins/sekolah/
```

**Opsi B — WP-CLI:**
```bash
wp plugin install /path/to/sekolah.zip --activate --path=/var/www/html
```

### 2.2 Aktivasi

1. Login wp-admin → **Plugins → Installed Plugins**
2. Aktifkan **Sekolah**
3. Menu **Sekolah** muncul di sidebar admin

### 2.3 Post-Install (Wajib)

```bash
# Flush rewrite rules
wp rewrite flush --path=/var/www/html
```

Atau manual: **Settings → Permalinks → Save Changes** (tanpa ubah apapun).

**Verifikasi URL:**
- `{domain}/sekolah/` → halaman portal siswa
- `{domain}/guru/` → halaman portal guru

---

## 3. Konfigururasi WordPress

### 3.1 Permalink

**Wajib:** Bukan "Plain". Disarankan **Post name** (`/%postname%/`).

### 3.2 Timezone

**Settings → General → Timezone** → set ke zona sekolah (mis. `Asia/Jakarta`).

Mempengaruhi: deadline tugas, tanggal jurnal, generate gaji.

### 3.3 File Upload

`wp-config.php` atau `.htaccess` / nginx config:
```php
define( 'WP_MEMORY_LIMIT', '256M' );
@ini_set( 'upload_max_filesize', '10M' );
@ini_set( 'post_max_size', '12M' );
```

---

## 4. Konfigururasi Plugin

### 4.1 Google Login (Opsional)

1. [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Buat **OAuth Client ID** → Web application
3. **Authorized JavaScript origins:** `https://domain-sekolah.com`
4. Salin Client ID → **Sekolah → Login Google**
5. Pastikan setiap siswa punya **Email** yang cocok di Data Siswa

### 4.2 Form PSB

**Sekolah → Setting Form PSB** — atur field dan tampilan form pendaftaran.

Buat halaman publik dengan shortcode:
```
[form_pendaftaran_psb]
```

### 4.3 Portal Siswa & Guru

Buat halaman opsional (atau akses langsung URL):

| Halaman | Shortcode / URL |
|---------|-----------------|
| Portal siswa | `/sekolah/` |
| Pembayaran | `[qr_checkout]` atau `/sekolah/pembayaran/` |
| Pembelajaran | `[portal_pembelajaran]` atau `/sekolah/pembelajaran/` |
| Portal guru | `/guru/` |

### 4.4 Data Awal

Urutan setup disarankan:
1. Kelas → Ruangan → Jabatan
2. Guru (NIP + telepon wajib untuk portal)
3. Siswa (NIS + email + kelas)
4. Mata pelajaran → Jadwal
5. Biaya → Generate tagihan
6. Rumus gaji → Generate gaji

---

## 5. Konfigurasi Server

### 5.1 Apache (.htaccess)

WordPress permalink standard sudah cukup. Pastikan `AllowOverride All` untuk `.htaccess`.

### 5.2 Nginx

Tambahkan rewrite WordPress standard + pastikan request `/sekolah/` dan `/guru/` diarahkan ke `index.php`:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

### 5.3 PHP Session

Portal memakai native PHP session. Pastikan:
- `session.save_path` writable
- Tidak ada plugin cache yang cache halaman `/sekolah/` atau `/guru/` untuk user login

**LiteSpeed / LSCache:** exclude URI:
```
/sekolah/
/guru/
```

### 5.4 HTTPS

Wajib untuk:
- Google OAuth
- Cookie session `Secure`
- Upload bukti transfer

Certbot (Let's Encrypt):
```bash
certbot --nginx -d sekolah.example.com
```

---

## 6. Deploy ke Production

### 6.1 Checklist Pre-Deploy

- [ ] Backup database & `wp-content/uploads`
- [ ] Uji di staging dengan data mirroring
- [ ] `php -l includes/*.php` — no syntax error
- [ ] Flush rewrite setelah deploy
- [ ] Cek `/sekolah/` dan `/guru/` tidak 404
- [ ] Cek login guru (NIP + telp) dan siswa (NIS/Google)

### 6.2 Zero-Downtime Update

```bash
# 1. Maintenance mode (opsional)
wp maintenance-mode activate

# 2. Backup
wp db export backup-$(date +%Y%m%d).sql

# 3. Deploy file baru (rsync/git pull)
rsync -av --exclude='.git' sekolah/ /var/www/html/wp-content/plugins/sekolah/

# 4. Flush rewrite
wp rewrite flush

# 5. Deactivate maintenance
wp maintenance-mode deactivate
```

### 6.3 Rollback

```bash
# Restore plugin folder dari backup
# Restore DB jika ada migrasi data
wp rewrite flush
```

---

## 7. Environment Variables

Plugin tidak memakai `.env`. Konfigurasi disimpan di:

| Lokasi | Contoh |
|--------|--------|
| `wp_options` | `qrg_google_client_id` |
| `wp_postmeta` | Data bisnis |
| `wp_usermeta` | Layout dashboard per admin |

**Jangan commit** credential ke repository.

---

## 8. Cron & Scheduled Tasks

Plugin saat ini **tidak** mendaftarkan WP-Cron khusus.  
Generate tagihan/gaji dijalankan manual via admin.

Jika menambah cron di masa depan:
```php
wp_schedule_event( time(), 'daily', 'qrg_daily_task' );
```

---

## 9. Backup Rutin

Disarankan backup:
- Database WordPress (harian)
- `wp-content/uploads` (harian/mingguan)
- Folder plugin (setiap deploy)

Plugin WP populer: UpdraftPlus, Duplicator, atau `wp db export` via cron.

---

## 10. Monitoring

Pantau:
- **Error log PHP** — fatal error di portal
- **Disk space** — upload bukti & lampiran tugas
- **SSL expiry** — OAuth Google
- **Login rate limit** — transient `qrg_rl_*` (normal jika ada brute force)

Log path umum:
- Apache: `/var/log/apache2/error.log`
- Nginx + PHP-FPM: `/var/log/php8.1-fpm.log`
- WordPress: `WP_DEBUG_LOG` di `wp-content/debug.log`

```php
// wp-config.php (staging only)
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## 11. Multi-Site

Plugin **belum diuji** untuk WordPress Multisite.  
Deploy di single-site WordPress per sekolah.

---

## 12. Performa

Tips shared hosting:
- Aktifkan object cache (Redis) jika tersedia
- Batasi `posts_per_page => -1` query di modul custom (sudah dioptimasi sebagian)
- Jangan jalankan script `tools/generate-*-dummy.php` di production
