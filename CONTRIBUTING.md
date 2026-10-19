# Panduan Kontribusi

Terima kasih atas minat untuk berkontribusi pada plugin **Sekolah**. Dokumen ini menjelaskan standar koding, alur kerja, dan aturan Pull Request (PR).

---

## Prasyarat

- WordPress lokal atau staging (disarankan tidak langsung di production)
- PHP 7.4+ (uji juga di PHP 8.x)
- Pemahaman dasar WordPress: CPT, hooks, nonces, capabilities
- Git & GitHub (untuk PR)

---

## Setup Lingkungan Dev

```bash
# Clone / salin plugin ke wp-content/plugins/
cd wp-content/plugins/sekolah

# Aktifkan plugin via WP-CLI (opsional)
wp plugin activate sekolah --path=/path/to/wordpress
```

**Setelah clone baru:**
1. Aktifkan plugin
2. Buka **Settings → Permalinks → Save** (flush rewrite)
3. Gunakan script dummy di `tools/` hanya di lingkungan dev

---

## Struktur Kode

```
includes/
  {modul}.php    # Satu modul = satu domain fitur
index.php        # Bootstrap, dashboard, require modul
```

**Aturan modul:**
- Setiap file diawali `if ( ! defined( 'ABSPATH' ) ) exit;`
- Fungsi publik prefix `qrg_`
- Konstanta prefix `QRG_`
- CPT prefix `master_` (contoh: `master_guru`)
- Option/transient prefix `qrg_`

**Urutan require** di `index.php` penting — modul dependensi harus dimuat lebih dulu (`security.php` di awal).

---

## Konvensi Koding

### PHP
- Ikuti [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Sanitasi input: `sanitize_text_field()`, `absint()`, `wp_unslash()`
- Escape output: `esc_html()`, `esc_attr()`, `esc_url()`
- Query DB: gunakan `$wpdb->prepare()` atau WP API (`get_posts`, `get_post_meta`)
- **PHP 8:** hindari nested ternary + elvis tanpa kurung — gunakan `if/elseif/else`

### Keamanan (wajib)
- Form POST: `wp_nonce_field()` + `wp_verify_nonce()` / `check_admin_referer()`
- Admin: `current_user_can( 'manage_options' )` atau capability sesuai konteks
- Upload: gunakan `qrg_handle_upload_safe()` dengan MIME whitelist
- Redirect: `wp_safe_redirect()` / `qrg_safe_redirect_url()`
- Portal login: `qrg_rate_limit_check()` + `qrg_rate_limit_hit()`

### JavaScript (inline admin/portal)
- Enqueue atau inline minimal; hindari dependency berat
- AJAX: nonce WordPress, handle error response
- Portal: mobile-first, `touch-action: manipulation` untuk tombol

### CSS
- Scope class dengan prefix `.qrg-` untuk hindari konflik tema
- Portal: self-contained di `portal-*.php` (tidak bergantung tema WP)

---

## Menambah Fitur Baru

1. **Buat/baca PRD** — pastikan fitur masuk ruang lingkup ([PRD.md](PRD.md))
2. **Modul baru** → file di `includes/`, require di `index.php`
3. **CPT baru** → `register_post_type` + meta boxes + save handler
4. **URL frontend** → `add_rewrite_rule` + `query_vars` + `template_redirect` + flush once option
5. **Update dokumentasi** — README, CHANGELOG, `docs/` jika ada perubahan arsitektur/API
6. **Update Bantuan** — `includes/bantuan.php` jika ada shortcode/URL baru

---

## Alur Pull Request

### 1. Fork & Branch
```bash
git checkout -b fitur/nama-singkat
# atau
git checkout -b perbaikan/deskripsi-bug
```

### 2. Commit
- Satu commit = satu perubahan logis
- **Pesan commit dalam Bahasa Indonesia**
- Format disarankan:
  ```
  tambah: fitur X untuk modul Y
  perbaiki: error Z di halaman portal guru
  ubah: UI aksi cepat keuangan
  docs: perbarui panduan deployment
  ```

### 3. Sebelum PR
- [ ] `php -l includes/*.php` — tidak ada syntax error
- [ ] Uji halaman yang diubah (admin + portal bila relevan)
- [ ] Tidak ada secret/API key hardcoded
- [ ] CHANGELOG.md diperbarui (section `[Unreleased]` atau versi baru)
- [ ] Diff fokus — hindari refactor tidak terkait

### 4. Buat PR
Judul PR (Bahasa Indonesia):
```
Tambah fitur jurnal mengajar di portal guru
```

Body PR template:
```markdown
## Ringkasan
- ...

## Perubahan
- ...

## Cara uji
1. ...
2. ...

## Screenshot (jika UI)
```

### 5. Review
- PR minimal 1 review sebelum merge
- Perbaiki komentar reviewer di branch yang sama
- Squash merge disarankan untuk PR kecil; merge commit untuk PR besar

---

## Yang Tidak Diterima

- Perubahan breaking tanpa catatan migrasi di CHANGELOG
- Menonaktifkan nonce/capability check
- Commit file `.env`, credential, atau data produksi
- Menghapus flush rewrite / security hook tanpa alasan
- Style-only mass refactor bercampur fitur (pisahkan PR)

---

## Script Dummy (`tools/`)

Hanya untuk **development**:
- `generate-guru-dummy.php`
- `generate-jadwal-dummy.php`
- `generate-gaji-guru-dummy.php`
- dll.

**Jangan jalankan di production** tanpa backup database.

---

## Pelaporan Bug

Sertakan:
1. Versi plugin & WordPress
2. Versi PHP
3. Langkah reproduksi
4. Expected vs actual
5. Screenshot / log error (tanpa data sensitif)

---

## Lisensi

Dengan berkontribusi, Anda setuju bahwa kontribusi dilisensikan di bawah [GPL-2.0-or-later](LICENSE), sama dengan plugin ini.
