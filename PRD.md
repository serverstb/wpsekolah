# PRD — Product Requirements Document
## Plugin Sekolah (Sistem Informasi Sekolah)

**Versi dokumen:** 1.0  
**Versi plugin:** 1.7.1  
**Terakhir diperbarui:** Agustus 2026

---

## 1. Ringkasan Produk

### 1.1 Visi
Menyediakan sistem informasi sekolah terintegrasi di WordPress yang dapat dioperasikan oleh admin sekolah tanpa aplikasi terpisah, dengan portal self-service untuk siswa/wali dan guru.

### 1.2 Masalah yang Diselesaikan
- Data sekolah tersebar (Excel, kertas, banyak sistem)
- Sulit melacak tagihan, gaji, dan realisasi anggaran
- Guru tidak punya akses mudah ke jadwal, tugas, dan slip gaji
- Siswa/wali kesulitan cek tagihan dan mengumpulkan tugas online

### 1.3 Target Pengguna

| Persona | Peran | Akses |
|---------|-------|-------|
| Admin Sekolah | Mengelola seluruh data & keuangan | wp-admin (`manage_options`) |
| Guru | Jadwal, tugas, jurnal, gaji, presensi | Portal `/guru/` |
| Siswa/Wali | Pembayaran & tugas | Portal `/sekolah/` |
| Calon Siswa | Pendaftaran PSB | Halaman publik (shortcode) |

---

## 2. Ruang Lingkup Fitur

### 2.1 Modul Admin (wp-admin)

#### Dashboard
- Kartu ringkasan: civitas, PSB, kelas, keuangan
- Widget: inventaris, siswa per kelas, keuangan, PSB
- Layout drag-and-drop, ukuran kolom, sembunyikan widget (per user)

#### Data Master
| Modul | CPT / Halaman | Fungsi |
|-------|---------------|--------|
| Guru | `master_guru` | Biodata, NIP, telepon, jabatan, mapel |
| Siswa | `master_siswa` | NIS, kelas, email, PIN portal |
| Kelas | `master_kelas` | Tingkat, jurusan, wali kelas |
| Mata Pelajaran | `master_mapel` | Mapel, guru pengampu, tugas |
| Jadwal | `master_jadwal` | Hari, jam, mapel, kelas, ruangan, guru |
| Ruangan | `master_ruangan` | Data ruang belajar |
| Jabatan | `master_jabatan` | Struktur jabatan guru |
| Biaya | `master_biaya` | Komponen biaya sekolah |
| Inventaris | `master_inventaris` | Aset sekolah |
| PSB | `master_psb` | Pendaftar baru |
| Jalur PSB | `master_jalur` | Jalur pendaftaran |

#### Keuangan
- **Tagihan Siswa** — buat, generate massal, status Lunas/Belum
- **Gaji Guru** — slip, rumus, generate bulanan, komponen kehadiran
- **Pembelanjaan** — pengeluaran operasional
- **RKAS** — rencana kegiatan anggaran sekolah
- **Dashboard Keuangan** — saldo kas, DSS, jurnal pembukuan, aksi cepat

#### Pembelajaran
- **Tugas** (`master_tugas`) — esai/CBT, deadline, poin
- **Jawaban** (`master_jawaban`) — teks, file, nilai
- **Jurnal** (`master_jurnal`) — materi, kegiatan, catatan per pertemuan

#### Presensi
- Presensi guru (`master_presensi_guru`)
- Integrasi perhitungan gaji berdasarkan kehadiran

#### Utilitas
- Export/Import CSV guru & siswa
- Login Google (OAuth Client ID)
- Setting Form PSB
- Bantuan & dokumentasi shortcode

### 2.2 Portal Siswa (`/sekolah/`)

**Autentikasi:**
- Google Sign-In (email harus cocok dengan email siswa)
- NIS + PIN/password (fallback legacy: NIS sebagai password)

**Fitur setelah login:**
- **Pembayaran** — daftar tagihan, upload bukti transfer
- **Pembelajaran** — daftar tugas, submit jawaban, lihat nilai

**Keamanan:**
- Rate limit login (8 percobaan / 15 menit per IP)
- Session PHP dengan cookie `HttpOnly`, `SameSite=Lax`
- Single CPT siswa/guru/PSB diblokir di frontend

### 2.3 Portal Guru (`/guru/`)

**Autentikasi:**
- Username: **NIP**
- Password: **No. Telepon** (dinormalisasi)

**Menu hub:**
| Section | URL | Fungsi |
|---------|-----|--------|
| Jadwal & Tugas | `/guru/jadwal/` | Jadwal mingguan, buat tugas/jurnal per slot |
| Slip Gaji | `/guru/gaji/` | Detail komponen gaji |
| Presensi | `/guru/presensi/` | Riwayat absensi |

**Jadwal & Tugas:**
- Navigasi per hari, auto-scroll ke hari ini
- Chip tugas/jurnal existing per slot mapel
- Form buat tugas & jurnal dengan mapel/kelas terisi otomatis
- Monitoring pengumpulan & penilaian jawaban siswa

### 2.4 Frontend Publik
- Shortcode PSB, pembayaran, pembelajaran
- Redirect URL legacy ke portal terpadu `/sekolah/`

---

## 3. Persyaratan Non-Fungsional

| Aspek | Target |
|-------|--------|
| **Kompatibilitas** | WordPress 5.x+, PHP 7.4+ |
| **Responsif** | Portal siswa & guru mobile-first |
| **Keamanan** | Nonce, capability check, rate limit, MIME whitelist upload |
| **Performa** | Query CPT dengan `fields => ids` where possible |
| **Bahasa UI** | Indonesia |
| **Data** | Disimpan sebagai WordPress CPT + post meta |

---

## 4. Alur Bisnis Kunci

### 4.1 Siklus Tagihan
1. Admin definisikan biaya (`master_biaya`)
2. Generate tagihan per kelas
3. Siswa login portal → lihat tagihan
4. Upload bukti → admin verifikasi → status **Lunas**
5. Pemasukan masuk perhitungan saldo kas

### 4.2 Siklus Gaji
1. Admin set rumus gaji & komponen
2. Generate slip gaji bulanan (inc. kehadiran)
3. Status **Dibayar** → masuk pengeluaran kas
4. Guru lihat slip di portal `/guru/gaji/`

### 4.3 Siklus Tugas
1. Guru buat tugas dari slot jadwal atau admin dari mapel
2. Siswa kerjakan via `/sekolah/pembelajaran/`
3. Guru monitor & nilai via portal atau admin
4. Nilai tampil di portal siswa

---

## 5. Di Luar Ruang Lingkup (v1.x)

- REST API publik penuh (CRUD eksternal)
- Multi-sekolah / multi-tenant
- Payment gateway otomatis (Midtrans, dll.)
- Aplikasi mobile native
- SSO LDAP/Active Directory

---

## 6. Metrik Keberhasilan

- Admin dapat melihat saldo kas & DSS dalam < 3 klik
- Guru dapat buat tugas dari jadwal tanpa isi ulang mapel/kelas
- Siswa dapat login & lihat tagihan dalam < 1 menit
- Zero fatal error PHP 8 pada halaman portal utama
- Rewrite portal tidak 404 setelah aktivasi plugin

---

## 7. Glosarium

| Istilah | Arti |
|---------|------|
| CPT | Custom Post Type (WordPress) |
| DSS | Decision Support System — analisis kesehatan keuangan otomatis |
| RKAS | Rencana Kegiatan dan Anggaran Sekolah |
| PSB | Penerimaan Siswa Baru |
| NIS | Nomor Induk Siswa |
| NIP | Nomor Induk Pegawai (guru) |
