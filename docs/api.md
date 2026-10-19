# Dokumentasi API — Plugin Sekolah

Plugin ini **tidak** menyediakan REST API publik penuh. Integrasi dilakukan via:

1. **WordPress AJAX** (`admin-ajax.php`)
2. **Fungsi PHP helper** (`qrg_*`) untuk dipanggil dari tema/plugin lain
3. **URL rewrite** portal frontend
4. **Admin GET actions** (export CSV dengan nonce)

---

## 1. WordPress AJAX Endpoints

Base URL: `{site_url}/wp-admin/admin-ajax.php`

### 1.1 Simpan Layout Dashboard

| | |
|---|---|
| **Action** | `qrg_save_dashboard_layout` |
| **Auth** | Wajib login wp-admin |
| **Capability** | `manage_options` (implicit via admin) |
| **Method** | POST |

**Parameter POST:**
| Field | Tipe | Keterangan |
|-------|------|------------|
| `action` | string | `qrg_save_dashboard_layout` |
| `nonce` | string | WordPress AJAX nonce |
| `summary` | JSON array | Urutan kartu ringkasan |
| `widgets` | JSON array | Urutan widget |
| `widget_cols` | JSON object | Kolom widget |
| `hidden_summary` | JSON array | Kartu disembunyikan |
| `hidden_widgets` | JSON array | Widget disembunyikan |
| `sizes_summary` | JSON object | Ukuran kartu (`25`,`33`,`50`,`75`,`100`) |
| `sizes_widgets` | JSON object | Ukuran widget |

**Response sukses:**
```json
{
  "success": true,
  "data": { "message": "Layout dashboard disimpan." }
}
```

---

### 1.2 Login Google — Portal Siswa

| | |
|---|---|
| **Action** | `qrg_google_portal_login` |
| **Hook** | `wp_ajax_` + `wp_ajax_nopriv_` |
| **Auth** | Publik (dengan rate limit di pemanggil) |
| **Method** | POST |

**Parameter POST:**
| Field | Tipe | Keterangan |
|-------|------|------------|
| `action` | string | `qrg_google_portal_login` |
| `id_token` | string | Google ID token dari GIS |
| `nonce` | string | Nonce halaman portal |

**Response sukses:** redirect URL atau JSON `{ success: true }` (tergantung implementasi handler)

**Prasyarat:** Option `qrg_google_client_id` terisi.

---

### 1.3 Login Google — Pembayaran (Legacy)

| | |
|---|---|
| **Action** | `qrg_google_pembayaran_login` |
| **Hook** | `wp_ajax_` + `wp_ajax_nopriv_` |
| **Catatan** | Digunakan shortcode `[qr_checkout]` legacy; portal utama memakai `qrg_google_portal_login` |

---

### 1.4 Hitung Kehadiran Gaji

| | |
|---|---|
| **Action** | `qrg_gaji_hitung_kehadiran` |
| **Auth** | Wajib login wp-admin |
| **Method** | POST |

**Parameter POST:**
| Field | Tipe | Keterangan |
|-------|------|------------|
| `action` | string | `qrg_gaji_hitung_kehadiran` |
| `guru_id` | int | ID post `master_guru` |
| `bulan` | string | Format `YYYY-MM` |
| `nonce` | string | Nonce admin gaji |

**Response:** JSON jumlah hari hadir / data kehadiran untuk kalkulasi slip gaji.

---

## 2. URL Frontend (Rewrite API)

Semua portal memakai **GET** HTML — bukan JSON API.

### 2.1 Portal Siswa

| URL | Query var | Fungsi |
|-----|-----------|--------|
| `/sekolah/` | `qrg_sekolah_portal=hub` | Menu utama |
| `/sekolah/pembayaran/` | `=pembayaran` | Tagihan siswa |
| `/sekolah/pembelajaran/` | `=pembelajaran` | Tugas siswa |
| `/sekolah/bayar/{id}/` | `=bayar`, `qrg_tagihan_id` | Detail bayar tagihan |

**Helper URL:**
```php
qrg_get_sekolah_portal_url( 'pembayaran', [ 'foo' => 'bar' ] );
```

### 2.2 Portal Guru

| URL | Query var | Fungsi |
|-----|-----------|--------|
| `/guru/` | `qrg_guru_portal=hub` | Menu guru |
| `/guru/jadwal/` | `=jadwal` | Jadwal & tugas |
| `/guru/gaji/` | `=gaji` | Slip gaji |
| `/guru/presensi/` | `=presensi` | Presensi |
| `/guru/tugas/` | `=tugas` | Redirect → jadwal |

**Query params jadwal:**
| Param | Fungsi |
|-------|--------|
| `baru=1` | Form buat tugas |
| `jurnal_baru=1` | Form buat jurnal |
| `kelas_id`, `mapel_id` | Pre-fill dari slot jadwal |
| `tugas={id}` | Monitoring pengumpulan |
| `jurnal={id}` | Detail jurnal |
| `created=1` | Flash sukses tugas |
| `jurnal_created=1` | Flash sukses jurnal |
| `graded=1` | Flash sukses nilai |

**Helper URL:**
```php
qrg_get_guru_portal_url( 'jadwal', [ 'baru' => 1, 'kelas_id' => 5 ] );
```

### 2.3 Legacy Redirect

| URL lama | Redirect ke |
|----------|-------------|
| `/master_siswa/{slug}/pembayaran/` | `/sekolah/pembayaran/` |
| `/master_siswa/{slug}/pembelajaran/` | `/sekolah/pembelajaran/` |

---

## 3. Admin GET Actions (Export)

| URL | Nonce action | Output |
|-----|--------------|--------|
| `admin.php?page=qrg-export-import&qrg_action=export_guru` | `qrg_export_guru` | CSV guru |
| `admin.php?page=qrg-export-import&qrg_action=export_siswa` | `qrg_export_siswa` | CSV siswa |

Import via POST form dengan `qrg_import_type=guru|siswa` + nonce terkait.

---

## 4. Fungsi PHP Publik (Helper API)

Fungsi berikut dapat dipanggil dari tema/plugin lain setelah plugin aktif:

### Autentikasi & Session

```php
qrg_pembayaran_get_auth_siswa_id();      // int — ID siswa login portal
qrg_guru_portal_get_auth_id();           // int — ID guru login portal
qrg_find_siswa_by_nis( $nis );           // int — post ID siswa
qrg_find_guru_by_nip( $nip );            // int — post ID guru
qrg_pembayaran_verify_nis( $id, $pass ); // bool
qrg_guru_portal_verify( $id, $pass );    // bool
```

### Jadwal & Data

```php
qrg_get_jadwal_by_guru( $guru_id );      // array by hari
qrg_get_daftar_kelas();                  // WP_Post[]
qrg_get_daftar_mapel( $only_active );    // WP_Post[]
qrg_get_kelas_label( $kelas_id );        // string
qrg_get_mapel_label( $mapel_id );        // string
```

### Keuangan

```php
qrg_akuntansi_get_jurnal_pembukuan( $tahun );  // array jurnal
qrg_akuntansi_build_dss( $ctx );               // array DSS
qrg_rkas_get_summary_stats();                  // anggaran, realisasi
qrg_gaji_get_dashboard_stats( $tahun );        // stat gaji
```

### Keamanan

```php
qrg_rate_limit_check( $action, $max, $window ); // true|WP_Error
qrg_rate_limit_hit( $action, $window );
qrg_rate_limit_clear( $action );
qrg_handle_upload_safe( $file, $mimes );
qrg_safe_redirect_url( $url, $default );
```

### Google Auth

```php
qrg_get_google_client_id();              // string
qrg_google_auth_enabled();               // bool
qrg_verify_google_id_token( $token );    // array { ok, email?, error? }
```

---

## 5. WordPress Options

| Option key | Isi |
|------------|-----|
| `qrg_google_client_id` | Google OAuth Client ID |
| `qrg_payment_api_enabled` | `1` jika REST pembayaran aktif |
| `qrg_payment_api_key` | API key pembayaran |
| `qrg_payment_api_secret` | Secret HMAC webhook |
| `qrg_payment_api_ip_whitelist` | Daftar IP (opsional) |
| `qrg_dashboard_layout` | *(user meta, bukan option)* |
| `qrg_*_rewrite_flushed_*` | Flag flush rewrite (internal) |

---

## 6. WordPress Transients (Rate Limit)

Key pattern: `qrg_rl_{md5(action|ip)}`

| Action key | Konteks |
|------------|---------|
| `login_nis` | Login portal siswa |
| `login_guru` | Login portal guru |
| `psb_form` | Submit form PSB |

Default: **8 percobaan / 900 detik (15 menit)** per IP.

---

## 7. Post Meta Reference (Ringkas)

### Siswa (`master_siswa`)
| Meta | Tipe |
|------|------|
| `_siswa_nis` | string |
| `_siswa_kelas_id` | int |
| `_siswa_portal_pin` | string (hash) |

### Guru (`master_guru`)
| Meta | Tipe |
|------|------|
| `_guru_nip` | string |
| `_guru_telp` | string |

### Jadwal (`master_jadwal`)
| Meta | Tipe |
|------|------|
| `_jadwal_hari` | string (Senin…Minggu) |
| `_jadwal_guru_id` | int |
| `_jadwal_mapel_id` | int |
| `_jadwal_kelas_id` | int |
| `_jadwal_jam_mulai` | string HH:MM |
| `_jadwal_jam_selesai` | string HH:MM |

### Tagihan (`master_tagihan`)
| Meta | Tipe |
|------|------|
| `_tagihan_siswa_id` | int |
| `_tagihan_status` | `Lunas` \| lainnya |
| `_tagihan_nominal` | int |

### Tugas (`master_tugas`)
| Meta | Tipe |
|------|------|
| `_tugas_guru_id` | int |
| `_tugas_kelas_id` | int |
| `_tugas_mapel_id` | int |
| `_tugas_deadline` | datetime |
| `_tugas_tipe` | `esai` \| `cbt` |

---

## 8. Shortcode API

| Shortcode | Callback | Output |
|-----------|----------|--------|
| `[form_pendaftaran_psb]` | psb.php | HTML form PSB |
| `[qr_checkout]` | frontend.php | HTML login pembayaran |
| `[portal_pembelajaran]` | pembelajaran.php | HTML portal tugas |

---

## 9. REST API Pembayaran

Aktifkan di **Sekolah → Setting**. Autentikasi: header `Authorization: Bearer {api_key}` atau `X-API-Key`.

Base: `{site_url}/wp-json/sekolah/v1/pembayaran`

| Metode | Path | Fungsi |
|--------|------|--------|
| GET | `/status` | Cek API aktif |
| GET | `/tagihan` | Daftar tagihan (`status`, `nis`, `page`, `per_page`) |
| GET | `/tagihan/{id}` | Detail tagihan |
| POST | `/webhook` | Update status (`Lunas` / `Sedang Ditinjau` / `Belum Dibayar`) |

Webhook wajib header `X-Sekolah-Signature` = HMAC-SHA256 body JSON dengan API Secret (jika secret terisi).

---

## 10. Contoh Integrasi

### Cek siswa login di tema
```php
if ( function_exists( 'qrg_pembayaran_get_auth_siswa_id' ) ) {
    $siswa_id = qrg_pembayaran_get_auth_siswa_id();
    if ( $siswa_id ) {
        $nis = get_post_meta( $siswa_id, '_siswa_nis', true );
    }
}
```

### Ambil jadwal guru
```php
$jadwal = qrg_get_jadwal_by_guru( $guru_id );
foreach ( $jadwal as $hari => $slots ) {
    foreach ( $slots as $slot ) {
        // $slot['mapel'], $slot['kelas'], $slot['jam_mulai']
    }
}
```
