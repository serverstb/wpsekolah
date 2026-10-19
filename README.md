# Sekolah — Sistem Informasi Sekolah (WordPress Plugin)

Plugin WordPress untuk mengelola operasional sekolah: data civitas, PSB, jadwal, pembelajaran, keuangan, inventaris, dan portal mandiri untuk siswa serta guru.

| | |
|---|---|
| **Versi** | 1.7.1 |
| **Author** | Ryan Dewantoro |
| **Lisensi** | GPL-2.0-or-later |
| **Platform** | WordPress 5.x+, PHP 7.4+ (disarankan PHP 8.x) |

---

## Fitur Utama

- **Dashboard Sekolah** — ringkasan civitas, PSB, kelas, keuangan, inventaris (layout dapat disesuaikan per user)
- **Data Master** — guru, siswa, kelas, mata pelajaran, jadwal, ruangan, jabatan, biaya
- **PSB** — formulir pendaftaran siswa baru via shortcode
- **Keuangan** — tagihan siswa, gaji guru, pembelanjaan, RKAS, jurnal pembukuan, DSS (Decision Support System)
- **Pembelajaran** — tugas, jawaban siswa, monitoring & penilaian
- **Jurnal Mengajar** — pencatatan kegiatan pembelajaran per mapel/kelas
- **Presensi Guru** — absensi dan integrasi perhitungan gaji
- **Portal Siswa** (`/sekolah/`) — pembayaran & pembelajaran (login Google atau NIS)
- **Portal Guru** (`/sekolah/guru/`) — jadwal & tugas, slip gaji, presensi (login NIP + telepon)
- **Export/Import** — data guru & siswa (CSV)
- **Keamanan** — rate limiting, session aman, blokir CPT sensitif di frontend

---

## Instalasi Cepat

1. Salin folder `sekolah` ke `wp-content/plugins/`
2. Aktifkan plugin di **Plugins → Installed Plugins**
3. Buka menu **Sekolah → Dashboard** di wp-admin
4. Atur **Setting Form PSB** dan **Login Google** (opsional) bila diperlukan
5. Setelah aktivasi, rewrite rule otomatis di-flush — jika URL portal 404, buka **Settings → Permalinks** lalu klik **Save Changes**

---

## URL Penting

| URL | Fungsi |
|-----|--------|
| `/sekolah/` | Portal siswa (hub) |
| `/sekolah/pembayaran/` | Tagihan & pembayaran siswa |
| `/sekolah/pembelajaran/` | Tugas & pembelajaran siswa |
| `/sekolah/guru/` | Portal guru |
| `/sekolah/guru/jadwal/` | Jadwal mengajar, tugas & jurnal |
| `/sekolah/guru/gaji/` | Slip gaji guru |
| `/sekolah/guru/presensi/` | Presensi guru |
| `/sekolah/buku/` | Perpustakaan digital (PDF) — **wajib login** portal `/sekolah/` |

---

## Shortcode

| Shortcode | Keterangan |
|-----------|------------|
| `[form_pendaftaran_psb]` | Form pendaftaran siswa baru |
| `[qr_checkout]` | Halaman login & pembayaran siswa |
| `[portal_pembelajaran]` | Portal tugas siswa |
| `[perpustakaan]` | Daftar ringkas dokumen PDF |

Detail lengkap: **Sekolah → Bantuan** di wp-admin.

---

## Struktur Folder

```
sekolah/
├── index.php                 # Bootstrap plugin
├── includes/                 # Modul fitur (CPT, admin, portal)
│   ├── akuntansi.php         # Keuangan, RKAS, pembelian, DSS
│   ├── portal-sekolah.php    # Portal siswa /sekolah/
│   ├── portal-guru.php       # Portal guru /sekolah/guru/
│   ├── pembelajaran.php      # Tugas & jawaban
│   ├── security.php          # Helper keamanan bersama
│   └── ...
├── tools/                    # Script dummy data (dev)
├── SECURITY.md               # Audit keamanan
└── docs/                     # Dokumentasi teknis
```

---

## Dokumentasi Lengkap

| Dokumen | Isi |
|---------|-----|
| [PRD.md](PRD.md) | Spesifikasi kebutuhan produk |
| [CHANGELOG.md](CHANGELOG.md) | Catatan rilis versi |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Panduan kontribusi & koding |
| [SECURITY.md](SECURITY.md) | Audit keamanan & pelaporan kerentanan |
| [docs/architecture.md](docs/architecture.md) | Arsitektur sistem |
| [docs/api.md](docs/api.md) | Endpoint, AJAX, helper publik |
| [docs/deployment.md](docs/deployment.md) | Deploy & konfigurasi server |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Error umum & maintenance |

---

## Persyaratan Server

- WordPress dengan permalink **bukan** "Plain"
- PHP dengan ekstensi: `session`, `json`, `mbstring`, `curl` (untuk Google OAuth)
- MySQL/MariaDB (via WordPress)
- HTTPS disarankan (session cookie `Secure`, OAuth Google)

---

## Kontak & Lisensi

Plugin ini dirilis di bawah [GPL-2.0-or-later](LICENSE).  
© Ryan Dewantoro. Lihat file [LICENSE](LICENSE) untuk detail hak cipta.
# wpsekolah
