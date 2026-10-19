# Troubleshooting & Maintenance — Plugin Sekolah

Panduan diagnosa error umum, perbaikan cepat, dan maintenance rutin.

---

## 1. Portal 404 (Not Found)

### Gejala
- `/sekolah/` atau `/guru/` menampilkan 404 WordPress

### Penyebab
Rewrite rules belum ter-flush setelah aktivasi/update plugin.

### Solusi
1. **Settings → Permalinks → Save Changes**
2. Atau WP-CLI:
   ```bash
   wp rewrite flush
   ```
3. Jika masih gagal, hapus option flush dan reload admin:
   ```bash
   wp option delete qrg_sekolah_portal_rewrite_flushed_v2
   wp option delete qrg_guru_portal_rewrite_flushed_v1
   wp rewrite flush
   ```

### Nginx
Pastikan `try_files` mengarah ke `index.php` (lihat [deployment.md](deployment.md)).

---

## 2. Login Portal Gagal

### 2.1 Guru — "NIP atau password salah"

**Cek:**
- Field **NIP** guru di Data Guru terisi benar
- **No. Telepon** sebagai password (bukan PIN lama)
- Format telepon dinormalisasi (`0812…` = `62812…`)

**Debug:**
```php
// WP-CLI eval (staging)
$guru_id = qrg_find_guru_by_nip( '123456' );
$telp = get_post_meta( $guru_id, '_guru_telp', true );
echo qrg_guru_normalize_telp( $telp );
```

### 2.2 Siswa — "Terlalu banyak percobaan"

Rate limit aktif: 8 percobaan / 15 menit per IP.

**Solusi:**
- Tunggu 15 menit, atau
- Clear transient (admin/staging):
  ```bash
  wp transient delete --all
  ```
  *(Hati-hati: menghapus semua transient situs)*

**Alternatif targeted:** hapus transient `qrg_rl_*` dari tabel `wp_options`.

### 2.3 Google Login Gagal

| Error | Penyebab | Solusi |
|-------|----------|--------|
| Konfigurasi belum lengkap | Client ID kosong | Isi di **Sekolah → Login Google** |
| Email tidak ditemukan | Email Google ≠ email siswa | Samakan email di Data Siswa |
| origin_mismatch | Domain OAuth salah | Tambahkan domain di Google Console |
| invalid_token | Token expired | Refresh halaman, coba lagi |

---

## 3. Fatal Error PHP

### 3.1 Nested Ternary (PHP 8+)

**Gejala:** `Fatal error: Unparenthesized ternary…`

**Penyebab:** Ekspresi `? :` + `?:` tanpa kurung.

**Solusi:** Ganti ke `if/elseif/else`. Contoh perbaikan sudah diterapkan di `portal-guru.php`.

### 3.2 Memory Limit

**Gejala:** `Allowed memory size exhausted`

**Solusi:**
```php
define( 'WP_MEMORY_LIMIT', '256M' );
```

Terjadi saat query `-1` posts dengan data besar — laporkan ke developer.

---

## 4. Upload Gagal

### Bukti Transfer / Lampiran Tugas

**Cek:**
- `upload_max_filesize` & `post_max_size` di PHP
- MIME file di whitelist (`qrg_upload_mimes_bukti`, `qrg_upload_mimes_dokumen`)
- Folder `wp-content/uploads` writable

**Ekstensi diblokir:** php, exe, js, html, svg, dll.

**Log:**
```bash
tail -f wp-content/debug.log
```

---

## 5. Saldo Keuangan Tidak Sesuai

### Cara Hitung Saldo
```
Saldo = Tagihan Lunas (siswa)
      − Gaji Dibayar
      − Pembelian Lunas
```

**Tidak termasuk:**
- Tagihan belum lunas (piutang)
- Gaji belum dibayar
- Pembelian belum lunas

### Cek Data
1. **Keuangan → Dashboard** — bandingkan angka per komponen
2. **Tabel Pembukuan** — filter tahun
3. Pastikan status meta benar:
   - Tagihan: `_tagihan_status = Lunas`
   - Gaji: status **Dibayar**
   - Pembelian: `_pembelian_status = Lunas`

---

## 6. Jadwal Guru Kosong di Portal

**Cek:**
1. Guru punya entri di **Jadwal** dengan `_jadwal_guru_id` benar
2. Field `_jadwal_hari` terisi (Senin–Minggu)
3. Login portal dengan NIP guru yang sama

**Debug:**
```php
$by = qrg_get_jadwal_by_guru( $guru_id );
print_r( array_map( 'count', $by ) );
```

---

## 7. Tugas Tidak Muncul di Portal Siswa

**Cek:**
1. Tugas status **publish**
2. `_tugas_kelas_id` = kelas siswa
3. Siswa login dengan akun yang benar
4. Deadline belum lewat (jika ada filter)

---

## 8. Session / Logout Tiba-tiba

**Penyebab umum:**
- Cache plugin cache halaman portal
- Session save path tidak writable
- Cookie `Secure` di HTTP (non-SSL)
- Banyak tab logout bersamaan

**Solusi:**
- Aktifkan HTTPS
- Exclude `/sekolah/` dan `/guru/` dari page cache
- Cek `session.save_path` permissions

---

## 9. Export/Import CSV Error

| Masalah | Solusi |
|---------|--------|
| Akses ditolak | Login sebagai admin `manage_options` |
| Import 0 row | Cek format CSV & header kolom |
| Duplikat NIS/NIP | Import skip duplikat — cek log `skipped` |
| Nonce invalid | Refresh halaman, coba lagi |

---

## 10. Dashboard Layout Reset

Layout disimpan per user admin di `user_meta: qrg_dashboard_layout`.

**Reset layout satu user:**
```bash
wp user meta delete {user_id} qrg_dashboard_layout
```

Reload dashboard → layout kembali ke default.

---

## 11. Maintenance Rutin

### Harian
- [ ] Cek error log PHP
- [ ] Verifikasi backup DB jalan

### Mingguan
- [ ] Review tagihan belum lunas
- [ ] Review gaji belum dibayar
- [ ] Cek upload disk space

### Bulanan
- [ ] Generate tagihan & gaji
- [ ] Review DSS Keuangan
- [ ] Update WordPress core & plugin lain
- [ ] Tes login portal siswa & guru

### Setelah Update Plugin
- [ ] Flush rewrite
- [ ] Tes `/sekolah/`, `/guru/`
- [ ] Tes fitur yang diubah
- [ ] Baca [CHANGELOG.md](../CHANGELOG.md)

---

## 12. Force Flush Semua Rewrite Plugin

Jika banyak URL rusak sekaligus (staging):

```bash
wp option delete qrg_security_rewrite_flushed_v1
wp option delete qrg_sekolah_portal_rewrite_flushed_v2
wp option delete qrg_guru_portal_rewrite_flushed_v1
wp option delete qrg_pembayaran_rewrite_flushed_v1
wp option delete qrg_pembelajaran_rewrite_flushed_v1
wp rewrite flush
```

Kunjungi wp-admin sekali agar hook `admin_init` re-flush otomatis.

---

## 13. Debug Mode Aman

**Hanya staging:**

```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', 0 );
```

**Jangan aktifkan `WP_DEBUG_DISPLAY` di production** — bisa bocorkan path server.

---

## 14. Kontak Eskalasi

Sebelum melaporkan bug, kumpulkan:
1. Versi plugin (header `index.php` → 1.7.1)
2. Versi WordPress & PHP (`Site Health`)
3. Langkah reproduksi
4. Cuplikan `debug.log` (tanpa data siswa/guru sensitif)
5. Screenshot

Laporkan via issue tracker repositori atau maintainer plugin.

---

## 15. FAQ Cepat

**Q: Apakah plugin butuh database terpisah?**  
A: Tidak. Memakai database WordPress standar.

**Q: Bisa dipakai tanpa Google Login?**  
A: Ya. Login NIS + PIN/password tetap tersedia.

**Q: Guru bisa akses wp-admin?**  
A: Tidak otomatis. Guru pakai portal `/guru/` unless diberi user WP terpisah.

**Q: Hapus plugin = hapus data?**  
A: CPT & meta tetap di DB unless uninstall hook diimplementasikan. Backup dulu sebelum hapus.

**Q: Script tools/ aman di production?**  
A: **Tidak.** Hanya untuk development/staging.
