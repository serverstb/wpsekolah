# Changelog

Semua perubahan penting plugin **Sekolah** didokumentasikan di file ini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.0.0/),  
dan proyek ini memakai [Semantic Versioning](https://semver.org/lang/id/).

---

## [1.7.1] — 2026

### Ditambahkan
- Modul **Jurnal Mengajar** (`master_jurnal`) di admin
- Portal guru: **Jurnal** per slot jadwal + form tulis jurnal
- Portal guru: UI **Jadwal & Tugas** — hero, navigasi hari sticky, kartu slot, fade-in scroll
- Portal guru: monitoring tugas & penilaian jawaban siswa dari jadwal
- Portal guru: slip gaji dengan deskripsi komponen (`qrg_guru_portal_gaji_komponen_deskripsi`)
- Dashboard Keuangan: **Aksi Cepat** dengan kartu pintasan berikon & terkelompok
- Dashboard Keuangan: **DSS** (Decision Support System) — skor kesehatan, indikator, peringatan, rekomendasi
- Dashboard Keuangan: banner RKAS, jurnal pembukuan sekolah
- Portal siswa terpadu `/sekolah/` (pembayaran + pembelajaran)
- Login Google OAuth untuk portal siswa
- Export/Import CSV guru & siswa
- Rate limiting & session aman (`includes/security.php`)
- Layout dashboard dapat disesuaikan per user (drag, resize, hide)

### Diubah
- Portal guru: login **NIP + No. Telepon** (PIN portal dihapus dari admin)
- Portal guru: `/guru/tugas/` digabung ke `/guru/jadwal/`
- Portal guru: statistik hero (slot/minggu, hari ini, hari aktif) dihapus atas permintaan
- Kartu ringkasan dashboard: `status` → `keuangan`
- URL legacy pembayaran/pembelajaran dialihkan ke `/sekolah/`

### Diperbaiki
- Fatal error PHP 8 pada ternary nested di halaman jadwal guru
- Scroll navigasi hari jadwal — target section vs nav link
- Rewrite rule flush otomatis setelah update keamanan CPT

---

## [1.6.x] — 2025

### Ditambahkan
- Portal guru awal (`/guru/`) — jadwal, gaji, presensi
- Modul presensi guru & integrasi gaji
- Modul pembelajaran — tugas esai/CBT, jawaban siswa
- Modul akuntansi — tagihan, gaji, pembelanjaan
- PSB dengan shortcode & setting form dinamis
- Inventaris sekolah

### Diubah
- Struktur modular: setiap fitur di `includes/*.php`
- Menu admin **Sekolah** sebagai parent menu

---

## [1.0.0] — Rilis Awal

### Ditambahkan
- Data master: guru, siswa, kelas, mapel, jadwal, ruangan
- Dashboard ringkasan sekolah
- Shortcode pembayaran siswa (`[qr_checkout]`)
- Generate tagihan per kelas

---

## Catatan Migrasi

### Setelah update ke 1.7.x
1. Buka **Settings → Permalinks → Save** jika `/sekolah/` atau `/guru/` menampilkan 404
2. Pastikan guru memiliki **NIP** dan **No. Telepon** untuk login portal
3. Isi **Google Client ID** di **Sekolah → Login Google** bila ingin login Google siswa
4. Flush rewrite otomatis via option `qrg_*_rewrite_flushed_*` — hapus option tersebut hanya jika perlu force re-flush

### Kompatibilitas PHP
- **PHP 8+** disarankan; hindari nested ternary dengan elvis operator tanpa kurung
- **PHP 7.4** minimum untuk session cookie `SameSite`
