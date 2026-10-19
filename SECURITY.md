# SECURITY.md — Audit Keamanan Plugin Sekolah

Dokumen ini berisi hasil **audit keamanan statis** (code review) plugin **Sekolah v1.7.1**, kontrol yang sudah diterapkan, temuan risiko, dan rekomendasi perbaikan.

| | |
|---|---|
| **Tanggal audit** | Agustus 2026 |
| **Versi plugin** | 1.7.1 |
| **Metode** | Static analysis — review kode sumber `includes/`, `index.php`, `tools/` |
| **Lingkup** | Autentikasi portal, otorisasi, input/output, upload, CPT exposure, AJAX |
| **Status keseluruhan** | **Waspada** — fondasi keamanan baik, beberapa risiko medium–high perlu mitigasi |

---

## 1. Ringkasan Eksekutif

Plugin Sekolah menerapkan praktik keamanan WordPress dasar dengan **lapisan tambahan** di modul `includes/security.php`: session aman, rate limiting, upload whitelist, dan pembatasan akses CPT di frontend.

**Kekuatan utama:**
- Proteksi CSRF via WordPress nonces di form admin & portal
- Rate limit brute-force login siswa/guru/PSB
- Kontrol kepemilikan data (IDOR) di portal guru untuk tugas & jurnal
- Verifikasi Google ID token server-side
- Pembatasan single view CPT sensitif

**Area perhatian utama:**
- Kredensial portal guru = nomor telepon **plain text** (bukan hash)
- Fallback login siswa legacy: NIS sebagai password jika PIN belum di-set
- Tidak ada `session_regenerate_id()` setelah login sukses
- Endpoint AJAX Google login belum di-rate-limit

---

## 2. Metodologi Audit

### 2.1 Yang Diperiksa
- [x] Autentikasi & session (`security.php`, `portal-*.php`, `frontend.php`)
- [x] Otorisasi & IDOR (portal guru tugas/jurnal/gaji)
- [x] Sanitasi & escape input/output
- [x] CSRF (nonces)
- [x] Upload file (`qrg_handle_upload_safe`)
- [x] Exposure CPT di frontend
- [x] AJAX endpoints (`admin-ajax.php`)
- [x] Export/import admin
- [x] Keberadaan fungsi berbahaya (`eval`, `exec`, dll.)
- [x] Script `tools/` (dummy data)

### 2.2 Yang Tidak Diperiksa (Out of Scope)
- Penetration testing aktif / dynamic analysis
- Audit infrastruktur server (firewall, WAF, SSL config)
- Keamanan WordPress core & plugin pihak ketiga lain
- Social engineering

---

## 3. Peta Kontrol Keamanan

```
┌─────────────────────────────────────────────────────────────┐
│                    Lapisan Pertahanan                      │
├─────────────────────────────────────────────────────────────┤
│  [1] ABSPATH guard — semua file PHP                         │
│  [2] WordPress capabilities — admin (manage_options)       │
│  [3] Nonces — form POST & AJAX                             │
│  [4] Rate limit — login & PSB (transient + IP)             │
│  [5] Session cookie — HttpOnly, Secure*, SameSite=Lax      │
│  [6] template_redirect — blok CPT sensitif di frontend      │
│  [7] Ownership check — guru ↔ tugas/jurnal                 │
│  [8] Upload whitelist — MIME + block ekstensi berbahaya    │
│  [9] hash_equals / wp_check_password — perbandingan aman   │
│ [10] Token akses — tagihan PSB (random 32 hex)             │
└─────────────────────────────────────────────────────────────┘
  * Secure flag aktif hanya jika situs HTTPS (is_ssl())
```

---

## 4. Kontrol yang Sudah Baik ✅

### 4.1 Autentikasi Portal Siswa
| Kontrol | Implementasi | File |
|---------|--------------|------|
| Rate limit login | 8 percobaan / 15 menit / IP | `security.php`, `portal-sekolah.php` |
| PIN hash | `wp_check_password()` jika `_siswa_portal_pin` ada | `frontend.php` |
| Perbandingan timing-safe | `hash_equals()` untuk NIS legacy | `frontend.php` |
| Google OAuth | Verifikasi token ke Google + cek `aud` = Client ID | `google-auth.php` |
| Email matching | `hash_equals()` lowercase | `google-auth.php` |
| Session cookie | HttpOnly, SameSite=Lax, Secure (SSL) | `security.php` |

### 4.2 Autentikasi Portal Guru
| Kontrol | Implementasi | File |
|---------|--------------|------|
| Rate limit login | 8 percobaan / 15 menit / IP | `portal-guru.php` |
| Lookup NIP | Meta query `_guru_nip` | `portal-guru.php` |
| Verifikasi telepon | `hash_equals()` setelah normalisasi | `portal-guru.php` |
| Nonce login | `qrg_guru_login` | `portal-guru.php` |

### 4.3 Otorisasi & IDOR
| Kontrol | Implementasi | File |
|---------|--------------|------|
| Tugas milik guru | `qrg_guru_portal_tugas_owned()` | `portal-guru.php` |
| Jurnal milik guru | `qrg_guru_portal_jurnal_owned()` | `portal-guru.php` |
| Tagihan siswa | Session harus match `_tagihan_siswa_id` | `frontend.php` |
| Tagihan PSB | Token `_tagihan_akses_token` + `hash_equals` | `security.php` |
| Nilai jawaban | Nonce per guru+tugas + ownership check | `portal-guru.php` |

### 4.4 Admin & AJAX
| Endpoint | Auth | Nonce |
|----------|------|-------|
| `qrg_save_dashboard_layout` | `manage_options` | ✅ |
| `qrg_gaji_hitung_kehadiran` | `edit_posts` | ✅ |
| `qrg_google_portal_login` | Publik | ✅ |
| `qrg_google_pembayaran_login` | Publik | ✅ |
| Export CSV guru/siswa | `manage_options` + referer | ✅ |

### 4.5 Upload File
```php
// includes/security.php
qrg_upload_mimes_bukti()    // gambar saja — bukti transfer
qrg_upload_mimes_dokumen()  // gambar, PDF, Office, ZIP — tugas/jawaban
qrg_handle_upload_safe()    // wp_handle_upload + block ekstensi berbahaya
```

Ekstensi diblokir: `php`, `phtml`, `phar`, `exe`, `sh`, `bat`, `cmd`, `js`, `html`, `htm`, `svg`

### 4.6 Pembatasan Frontend CPT
| CPT | Perlakuan |
|-----|-----------|
| `master_guru` | Redirect ke home |
| `master_psb` | Redirect ke home |
| `master_siswa` | Hanya endpoint `/pembayaran/` & `/pembelajaran/` |
| `master_tagihan` | Auth session siswa atau token PSB |
| Lainnya | Mayoritas `public => false` |

### 4.7 Tidak Ditemukan (Positif)
- ❌ `eval()`, `exec()`, `shell_exec()`, `system()`, `passthru()`
- ❌ SQL query raw tanpa `$wpdb->prepare()` di path kritis
- ❌ REST API publik (`register_rest_route`) — attack surface minimal

---

## 5. Temuan Audit (Findings)

Skala severity: **Kritis → Tinggi → Sedang → Rendah → Info**

### SEC-001 · Tinggi · Password Guru = Nomor Telepon (Plain)

**Lokasi:** `includes/portal-guru.php` — `qrg_guru_portal_verify()`

**Deskripsi:**  
Password portal guru adalah nomor telepon yang disimpan dan dibandingkan sebagai **plain text** (setelah normalisasi), bukan hash password.

**Risiko:**
- Siapa pun dengan akses database (SQL injection di plugin lain, backup bocor) dapat login sebagai guru
- Entropi rendah — rentan tebak jika pola nomor diketahui

**Rekomendasi:**
- Hash telepon/PIN dengan `wp_hash_password()` saat simpan
- Verifikasi dengan `wp_check_password()` saat login
- Migrasi gradual: hash saat login sukses pertama

---

### SEC-002 · Tinggi · Fallback NIS sebagai Password

**Lokasi:** `includes/frontend.php` — `qrg_pembayaran_verify_nis()`

**Deskripsi:**  
Jika `_siswa_portal_pin` kosong, sistem fallback membandingkan password dengan **NIS** (seringkali publik/dibagikan).

**Risiko:**  
Account takeover siswa jika PIN belum di-set admin.

**Rekomendasi:**
- Wajibkan set PIN saat create/import siswa
- Hapus fallback NIS di versi major berikutnya
- Tampilkan peringatan admin untuk siswa tanpa PIN

---

### SEC-003 · Sedang · Session Fixation

**Lokasi:** `portal-sekolah.php`, `portal-guru.php`, `frontend.php`

**Deskripsi:**  
Setelah login sukses, **tidak ada** `session_regenerate_id(true)`.

**Risiko:**  
Attacker yang memperoleh session ID sebelum login dapat hijack session setelah korban login.

**Rekomendasi:**
```php
// Setelah autentikasi sukses, sebelum set auth_id:
session_regenerate_id( true );
qrg_pembayaran_set_auth_siswa_id( $siswa_id );
```

---

### SEC-004 · Sedang · Google AJAX Login Tanpa Rate Limit

**Lokasi:** `includes/google-auth.php` — `qrg_ajax_google_portal_login`, `qrg_ajax_google_pembayaran_login`

**Deskripsi:**  
Endpoint AJAX Google login memverifikasi nonce & token, tetapi **tidak** memanggil `qrg_rate_limit_check()`.

**Risiko:**  
Abuse volume request / enumerasi email terdaftar (meski Google membatasi token).

**Rekomendasi:**
- Tambahkan `qrg_rate_limit_check( 'google_login', 10, 900 )` sebelum verifikasi

---

### SEC-005 · Sedang · Trust HTTP_X_FORWARDED_FOR

**Lokasi:** `includes/security.php` — `qrg_get_client_ip()`

**Deskripsi:**  
Rate limit memakai IP dari header `X-Forwarded-For` tanpa validasi proxy tepercaya.

**Risiko:**  
Bypass rate limit jika attacker spoof header (bergantung konfigurasi reverse proxy).

**Rekomendasi:**
- Gunakan `REMOTE_ADDR` jika tidak di belakang proxy terpercaya
- Atau whitelist proxy IP sebelum percaya XFF

---

### SEC-006 · Sedang · CPT Tagihan Publik (`/spp/`)

**Lokasi:** `includes/tagihan.php` — `'public' => true`, slug `spp`

**Deskripsi:**  
Tagihan dapat di-query via permalink WordPress. Akses dibatasi di `template_redirect`, bukan di level CPT.

**Risiko:**  
Defense-in-depth lemah — bug di hook redirect bisa expose invoice.

**Rekomendasi:**
- Set `'public' => false`, `'publicly_queryable' => false`
- Pertahankan akses hanya via intercept custom + portal

---

### SEC-007 · Rendah · Upload ZIP Diizinkan

**Lokasi:** `includes/security.php` — `qrg_upload_mimes_dokumen()`

**Deskripsi:**  
File ZIP diizinkan untuk lampiran tugas. File disimpan, tidak diekstrak.

**Risiko:**  
Malware archive, zip bomb (DoS storage), social engineering download.

**Rekomendasi:**
- Batasi ukuran file (server + validasi PHP)
- Pertimbangkan hapus ZIP dari whitelist jika tidak diperlukan
- Scan antivirus di level server (ClamAV)

---

### SEC-008 · Rendah · Script `tools/` di Production

**Lokasi:** `tools/generate-*-dummy.php`

**Deskripsi:**  
Script dummy data memerlukan `ABSPATH` (via WP-CLI), tetapi file tetap ada di folder web jika plugin di `wp-content/plugins/`.

**Risiko:**  
Eksposur informasi struktur kode; risiko jika dijalankan di production.

**Rekomendasi:**
- Jangan deploy folder `tools/` ke production, atau
- Blokir akses web:
  ```apache
  # tools/.htaccess
  Require all denied
  ```

---

### SEC-009 · Rendah · Portal Tanpa Security Headers

**Lokasi:** `portal-sekolah.php`, `portal-guru.php`

**Deskripsi:**  
Halaman portal standalone tidak mengirim CSP, X-Frame-Options, X-Content-Type-Options.

**Rekomendasi:**
```php
header( 'X-Frame-Options: SAMEORIGIN' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Referrer-Policy: strict-origin-when-cross-origin' );
// CSP disesuaikan jika pakai Google GIS
```

---

### SEC-010 · Info · Session Terpisah dari WordPress Users

**Deskripsi:**  
Portal siswa/guru **tidak** membuat `WP_User`. Auth via `$_SESSION` native PHP.

**Implikasi:**
- Tidak inherit 2FA / password policy WordPress
- Session management manual — perlu regenerate & timeout
- Logout tidak affect WP admin session (positif — isolasi)

---

### SEC-011 · Info · Tidak Ada REST API

Plugin tidak mendaftarkan REST routes — mengurangi attack surface untuk integrasi eksternal.

---

## 6. Matriks Risiko

| ID | Severity | Kemungkinan | Dampak | Status |
|----|----------|-------------|--------|--------|
| SEC-001 | Tinggi | Sedang | Tinggi | Open |
| SEC-002 | Tinggi | Sedang | Tinggi | Open |
| SEC-003 | Sedang | Rendah | Sedang | Open |
| SEC-004 | Sedang | Rendah | Sedang | Open |
| SEC-005 | Sedang | Rendah | Sedang | Open |
| SEC-006 | Sedang | Rendah | Sedang | Open |
| SEC-007 | Rendah | Rendah | Rendah | Open |
| SEC-008 | Rendah | Rendah | Sedang | Open |
| SEC-009 | Rendah | Rendah | Rendah | Open |

---

## 7. Rekomendasi Prioritas

### Segera (Sprint berikutnya)
1. **SEC-002** — Wajibkan PIN portal siswa; deprecate NIS fallback
2. **SEC-001** — Hash kredensial portal guru
3. **SEC-003** — `session_regenerate_id()` setelah login

### Jangka Pendek
4. **SEC-004** — Rate limit Google AJAX
5. **SEC-006** — `public => false` untuk `master_tagihan`
6. **SEC-008** — Blokir/hapus `tools/` di production

### Jangka Panjang
7. **SEC-009** — Security headers di portal
8. **SEC-005** — Hardening IP detection di belakang proxy
9. Audit ulang setelah perubahan major (target: tiap release minor)

---

## 8. Checklist Keamanan Deployment

Gunakan sebelum go-live:

- [ ] **HTTPS** aktif di seluruh situs
- [ ] Permalink **bukan** Plain
- [ ] Semua siswa punya **PIN portal** (bukan NIS saja)
- [ ] Semua guru punya **NIP + telepon** unik
- [ ] **Google Client ID** hanya origin production
- [ ] Folder `tools/` **tidak web-accessible**
- [ ] `WP_DEBUG` **false** di production
- [ ] `WP_DEBUG_DISPLAY` **false**
- [ ] File permission: plugin `755`, file `644`
- [ ] Backup DB terenkripsi / akses terbatas
- [ ] Page cache **exclude** `/sekolah/` dan `/guru/`
- [ ] `upload_max_filesize` & `post_max_size` dibatasi wajar
- [ ] Admin WordPress memakai password kuat + 2FA (plugin terpisah)

---

## 9. Pelaporan Kerentanan (Responsible Disclosure)

Jika Anda menemukan kerentanan keamanan:

1. **Jangan** publikasikan detail sebelum perbaikan tersedia
2. Kirim laporan ke maintainer plugin dengan:
   - Versi plugin & WordPress
   - Langkah reproduksi
   - Dampak potensial
   - Proof of concept (jika ada) — **tanpa data siswa/guru real**
3. Beri waktu reasonable (≥ 90 hari) untuk patch sebelum disclosure publik

**Kontak:** Ryan Dewantoro (author plugin)

---

## 10. Riwayat Audit

| Tanggal | Versi | Auditor | Catatan |
|---------|-------|---------|---------|
| Agustus 2026 | 1.7.1 | Static code review (internal) | Audit awal — dokumen ini dibuat |

---

## 11. Referensi

- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [WordPress Coding Standards — Security](https://developer.wordpress.org/apis/security/)
- Dokumen terkait: [docs/architecture.md](docs/architecture.md), [docs/troubleshooting.md](docs/troubleshooting.md)

---

> **Disclaimer:** Audit ini berbasis review kode statis pada versi 1.7.1. Hasil dapat berubah setelah modifikasi kode. Audit ulang disarankan setiap release signifikan.
