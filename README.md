# SIMLOG RS — Sistem Informasi Manajemen Logistik Rumah Sakit (Non-Medis) v2

Platform ERP logistik rumah sakit untuk siklus pengadaan, persediaan FEFO, distribusi ruangan (amprah), sirkulasi peminjaman dan pengalihan aset, pemeliharaan IPSRS, serta audit kepatuhan SPI. Terdiri dari web (Livewire) dan REST API v1 (Sanctum) untuk klien mobile Flutter (repo terpisah, lihat [docs/SECURITY.md](docs/SECURITY.md)).

## Tech Stack

| Layer | Teknologi |
|---|---|
| Backend | Laravel 13 · PHP 8.3 |
| Frontend | Livewire 3 · Flux UI · Alpine.js · Tailwind CSS 4 · Vite 6 |
| RBAC | Spatie Permission 6 (matriks halaman + aksi, lihat ADR 0001) |
| API | Laravel Sanctum (token per perangkat, abilities per peran) |
| Database | PostgreSQL 16 (pg_trgm, CHECK constraints) |
| Cache & Queue | Redis 7 |
| Push | Firebase Cloud Messaging (`kreait/laravel-firebase`) |
| Dokumen | DomPDF (cetak SPK), FastExcel, Simple QR Code (stiker aset) |
| Runtime | Docker: Nginx Alpine, PHP-FPM, PostgreSQL, Redis, worker `queue`, penjalan `scheduler` |

## Arsitektur Portal

Gateway login terpusat di `/login`. Setiap peran hanya boleh masuk ke satu portal miliknya (ADR 0006). Peran `kepala_teknisi` memakai portal `/teknisi`.

| Portal | Path | Peran |
|---|---|---|
| Super Administrator | `/super-admin` | `super_admin` |
| Admin Logistik | `/admin-logistik` | `admin_logistik` |
| Kepala Gudang | `/kepala-gudang` | `kepala_gudang` |
| Petugas Gudang | `/petugas-gudang` | `petugas_gudang` |
| Pengadaan | `/pengadaan` | `pengadaan` |
| Kepala Unit | `/kepala-unit` | `kepala_unit` |
| Petugas Unit | `/petugas-unit` | `petugas_unit` |
| Teknisi IPSRS | `/teknisi` | `teknisi`, `kepala_teknisi` |
| Manajemen Direksi | `/manajemen` | `manajemen` |
| Auditor Internal (SPI) | `/auditor` | `auditor` |

Total 11 peran. Menu sidebar tampil hanya bila pengguna punya permission halaman (`page_*`) **dan** permission lihat (`view_any_*`/`view_*`) untuk modul tersebut.

## Quick Start

```bash
# 1. Siapkan environment
cp .env.example .env

# 2. Build & jalankan container
docker compose up -d --build

# 3. Dependensi & kunci aplikasi
docker compose exec app composer install
docker compose exec app php artisan key:generate

# 4. Migrasi & seeder
docker compose exec app php artisan migrate:fresh --seed

# 5. Aset frontend
docker compose exec app npm install && docker compose exec app npm run build

# 6. Akses aplikasi
# http://localhost:8000
```

Semua perintah `artisan`, `composer`, dan `npm` dijalankan di dalam container (`docker compose exec app ...`).

### HTTPS melalui ngrok

Ngrok di host meneruskan HTTPS publik ke HTTP Nginx. `docker/nginx/default.conf`
hanya menerima `X-Forwarded-Proto: http/https` dari alamat proxy eksplisit pada blok
`geo $simlog_trusted_proxy`; selain itu memakai skema koneksi Nginx. IP klien tetap
diambil dari `$remote_addr`, bukan header `X-Forwarded-For` dari klien.

Gateway Docker saat konfigurasi ini dibuat adalah `172.22.0.1`. Jika berubah,
periksa dengan `docker inspect simrslogv2-web --format '{{json .NetworkSettings.Networks}}'`
dan sesuaikan satu alamat gateway di blok `geo`, bukan seluruh subnet.
Alamat gateway mewakili koneksi dari host (bukan identitas ngrok secara eksklusif);
hanya gunakan jika host dipercaya. Batasi akses port Nginx pada jaringan yang sesuai.
Proxy lain harus didaftarkan dengan alamat peer yang benar dan menimpa header protokol
dari klien. Jangan mengaktifkan `real_ip_header X-Forwarded-For` untuk solusi ini.

Setelah mengubah konfigurasi:

```bash
docker compose exec web nginx -t && docker compose exec web nginx -s reload
python tests/Infrastructure/nginx_proxy_check.py
```

## Akun Seeder

Kata sandi seluruh akun seeder: nilai `SEEDER_DEFAULT_PASSWORD` di `.env`, bila kosong memakai `SimlogRS@2026!` (`config/simlog.php`). **Ganti sebelum produksi.**

| Email | Peran |
|---|---|
| `superadmin@simlogrs.local` | Super Administrator |
| `adminlogistik@simlogrs.local` | Admin Logistik |
| `kepalagudang@simlogrs.local` | Kepala Gudang (Gudang Sentral) |
| `petugasgudang@simlogrs.local` | Petugas Gudang |
| `pengadaan@simlogrs.local` | Pengadaan |
| `kepalaunit@simlogrs.local` | Kepala Unit (IGD) |
| `petugasunit@simlogrs.local` | Petugas Unit (IGD) |
| `teknisi@simlogrs.local` | Teknisi IPSRS |
| `manajemen@simlogrs.local` | Manajemen Direksi |
| `auditor@simlogrs.local` | Auditor Internal |
| `karu_ipsrs@simlogrs.local` | Kepala Teknisi (IPSRS) |
| `karu_icu@`, `karu_rawat1@`, `karu_farmasi@`, `karu_lab@`, `karu_logistik@simlogrs.local` | Kepala Unit per ruangan |
| `kagud_ipsrs@`, `kagud_atk@`, `kagud_b3@simlogrs.local` | Kepala Gudang per fasilitas |

`DatabaseSeeder` hanya menanam unit, gudang, peran, permission, dan akun. Seeder data transaksi (`database/seeders/Dummy/*`) dinonaktifkan; aktifkan manual di `DatabaseSeeder` bila butuh data demo.

Kartu "Akun Percobaan Cepat" di halaman login hanya muncul bila `SIMLOG_QUICK_LOGIN=true`. Jangan aktifkan di produksi.

## Registrasi Akun Pegawai

Menu Super Administrator, Akun Pengguna, Tambah:

- Kata sandi sementara dibuat otomatis (10 karakter) dan ditampilkan read-only di form.
- Akun dibuat dengan `wajib_ganti_password = true`. Saat login pertama, web mengarahkan ke `/auth/ganti-password`; API mobile memblokir seluruh endpoint kecuali `POST /api/v1/auth/ganti-password` dan logout sampai sandi diganti.
- Email berisi nama, username, email login, peran, penugasan, kata sandi sementara, dan tautan login dikirim ke email yang diinput (`App\Mail\AkunPegawaiBaruMail`).
- Bila email gagal terkirim, akun tetap tersimpan dan muncul peringatan; penyebab dicatat di `storage/logs/laravel.log`.
- Penanggung jawab Unit dan Gudang hanya bisa dipilih dari `kepala_unit`/`kepala_teknisi` (Unit) dan `kepala_gudang` (Gudang), atau pegawai yang bertugas di unit/gudang itu.

### Konfigurasi email (Mailtrap)

Isi di `.env`, lalu `docker compose exec app php artisan config:clear`:

| Mode | `MAIL_HOST` | Username & password | Catatan |
|---|---|---|---|
| Sandbox (uji coba) | `sandbox.smtp.mailtrap.io` | Dari Email Testing, Inbox, SMTP Settings | Email hanya masuk inbox Mailtrap, tidak sampai ke Gmail |
| Sending (produksi) | `live.smtp.mailtrap.io` | `smtp@mailtrap.io` atau `api`, password = API token | Email terkirim sungguhan |

Port `2525` atau `587` (STARTTLS), `MAIL_SCHEME=null`. Pada mode Sending, `MAIL_FROM_ADDRESS` wajib memakai domain yang sudah diverifikasi di Mailtrap (SPF/DKIM/DMARC). Domain seperti `simlogrs.local` ditolak dengan `550 5.7.1 Sending from domain ... is not allowed`.

## Modul

| Domain | Halaman Livewire | Service / API v1 | Tes fitur |
|---|---|---|---|
| Master data (barang, aset, unit, gudang, rak, kategori, satuan, merek, pemasok) | `Master/*` (9 modul) | `MasterApiController`, `AsetKatalogApiController` | `Master/*`, `Ipsrs/IpsrsWebAuthorizationTest` |
| Pengadaan (PR, PO, penerimaan PO, anggaran, rekanan) | `Pengadaan/*` | `PengajuanPrService`, `PenerimaanPoService`, `AnggaranRealtimeService`, `Pengadaan*ApiController` | `Pengadaan/*`, `Integrity/*` |
| Distribusi ruangan (amprah, approval 2 meja, picking FEFO, SPPB, stok ruangan) | `Distribusi/*` | `DistribusiService`, `PickingScope`, `Distribusi*ApiController` | `Distribusi/*` |
| Sirkulasi aset (peminjaman, otorisasi, pengembalian) | `Aset/Peminjaman*`, `ApprovalPeminjamanIndex`, `PengembalianPeminjamanIndex` | `PeminjamanAsetService`, `AsetPeminjaman*ApiController` | `Aset/*` |
| Pengalihan aset dan barang | `Aset/PengalihanAsetIndex`, `Persediaan/PengalihanBarangIndex` | `PengalihanAsetService`, `PengalihanBarangService` | `Aset/PengalihanAset*`, `Persediaan/PengalihanBarang*` |
| Persediaan (monitoring FEFO dan stok minimum, buku mutasi, stock opname, otorisasi penyesuaian) | `Persediaan/*` | `Persediaan*ApiController` | `Persediaan/*` |
| Pemeliharaan IPSRS (lapor servis, SPK, lembar kerja, jadwal preventif, rekam medis aset) | `Ipsrs/*` | `Maintenance*ApiController`, `Ipsrs*ApiController` | `Ipsrs/*`, `Maintenance/*` |
| Audit SPI (audit trail, kepatuhan pengadaan, selisih opname, temuan dan CAPA) | `Audit/*` | `Audit*ApiController` | `Audit/*` |
| Notifikasi dan push FCM | `Notifikasi/*`, `System/SchedulerNotifikasiIndex` | `NotifikasiService`, `PenerimaResolver`, observer di `app/Observers` | `Notifikasi/*` |
| RBAC dan akun | `User/RoleManager`, `User/PenggunaIndex` | `PagePermissionRegistry`, `PenggunaService` | `Security/*`, `User/*` |
| Dashboard per portal | `Dashboard/*` (10 halaman) | `DashboardApiController`, `Services/Dashboard/*` | akses portal di `Security/*` |

Detail aset (modal di Register Aset) memuat tab Informasi Umum, Rekam Medis & Servis, Riwayat Peminjaman, dan Riwayat Pengalihan.

## Notifikasi, Scheduler & Push (FCM)

Notifikasi ditulis ke tabel `notifikasi` (lonceng web dan inbox app), lalu dikirim sebagai push FCM oleh worker antrean. Worker (`queue`) dan penjalan cron (`scheduler`) adalah service docker yang hidup otomatis:

```bash
docker compose up -d queue scheduler
docker compose ps
```

Jadwal harian (zona waktu `Asia/Jakarta`), didefinisikan di `routes/console.php` dan dapat dijalankan manual dari menu Task Scheduler (Super Administrator):

| Waktu | Perintah | Fungsi |
|---|---|---|
| 00:01 | `simlog:sweep-expired-batches` | Kunci batch kedaluwarsa, tandai batch di bawah 90 hari |
| 01:00 | `simlog:generate-preventive-maintenance` | Terbitkan tiket dan SPK preventif dari jadwal berkala |
| 06:00 | `simlog:alert-overdue-loans` | Peringatan peminjaman aset terlambat |
| 06:30 | `simlog:alert-stok-minimum` | Notifikasi komoditas dengan total stok di bawah `stok_minimum` (dedup harian per barang) |
| 07:00 | `simlog:alert-due-soon-loans` | Pengingat peminjaman jatuh tempo besok |
| 07:30 | `simlog:alert-capa-jatuh-tempo` | Pengingat CAPA jatuh tempo atau terlambat |

Kontrak API dan payload: [docs/NOTIFICATION_CONTRACT.md](docs/NOTIFICATION_CONTRACT.md). Kredensial Firebase, pengecekan worker, dan pemecahan masalah "muncul di web tapi tidak di HP": [docs/FIREBASE_SETUP.md](docs/FIREBASE_SETUP.md).

## Pengujian

Tes dijalankan di dalam container dan memakai database PostgreSQL yang sama dengan aplikasi (`DatabaseTransactions`, tanpa `RefreshDatabase`):

```bash
docker compose exec -T app php artisan test --compact
# atau sebagian:
docker compose exec -T app php artisan test --compact tests/Feature/Persediaan
```

Regresi pencarian katalog mobile (`cari`, nama/kode, filter tipe, hasil kosong), dijalankan pada database terisolasi `simlog_rs_test`:

```bash
docker compose exec -T app sh .phptest.sh php artisan test --compact tests/Feature/Master/MasterBarangSearchApiTest.php
```

Endpoint `/api/v1/master/barang-lengkap` mencari pada kolom `nama` dan `kode`; `spesifikasi` bukan kolom tabel `barang`.

Hasil terakhir (22 Oktober 2026): **539 lulus, 0 gagal** (3788 assertion).

Catatan penulisan tes: karena memakai database yang sama dengan aplikasi, tes tidak boleh bergantung pada id tetap atau baris pertama hasil seeder. Buat data referensi sendiri di `setUp()`, pakai akun seeder berdasarkan `username`, dan batasi assertion ke dokumen uji (mis. filter `referensi_id`), bukan ke seluruh tabel atau seluruh HTML halaman. Livewire 3.8 tidak menyediakan `assertSeeLivewire`; periksa snapshot komponen di HTML atau gunakan `Livewire::test()`.

Keputusan arsitektur yang menjadi acuan tes:

- [ADR 0001 — Kebijakan otorisasi web](docs/adr/0001-kebijakan-otorisasi-web.md)
- [ADR 0002 — Jaminan pencatatan audit dan transisi status](docs/adr/0002-jaminan-pencatatan-audit-dan-transisi-status.md)
- [ADR 0003 — Konfigurasi operasional dan kontrak route](docs/adr/0003-konfigurasi-operasional-dan-kontrak-route.md)
- [ADR 0004 — Audit dead code dan penyederhanaan](docs/adr/0004-audit-dead-code-dan-penyederhanaan.md)
- [ADR 0005 — Struktur modul dan batas ukuran file](docs/adr/0005-struktur-modul-dan-batas-ukuran-file.md)
- [ADR 0006 — Isolasi portal terikat peran](docs/adr/0006-isolasi-portal-terikat-peran.md)

Dokumen pendukung: [UAT](docs/UAT.md) dan [panduan keamanan dan rilis](docs/SECURITY.md).

## Komponen UI Kustom

Komponen Blade berada di `resources/views/components`.

| Komponen | Fungsi |
|---|---|
| `<x-ui.number-input>` | Input angka dengan format titik ribuan, prefix Rp opsional |
| `<x-ui.rupiah>` | Tampilan mata uang Rupiah |
| `<x-ui.status-badge>` | Badge status berwarna |
| `<x-ui.urgency-badge>` | Badge urgensi REGULER/CITO |
| `<x-ui.context-banner>` | Banner konteks emulasi gudang/unit |
| `<x-ui.table-pagination>` | Pagination dengan pemilih jumlah per halaman |
| `<x-ui.empty-state>` | State kosong untuk tabel |
| `<x-ui.searchable-select>` | Select dengan kotak pencarian (terikat `wire:model`) |
| `<x-ui.confirm-modal>` | Modal konfirmasi aksi |
| `<x-tabs.*>` | Sub-navigasi hub modul (peminjaman, distribusi, pengadaan, IPSRS, persediaan, audit) |

## Konvensi Kode

- **Otorisasi (ADR 0001)**: lihat halaman dan data lewat permission `view_any_*`/`view_*`; aksi (buat, ubah, hapus, eksekusi) wajib permission aksi tersebut, dan peran saja tidak cukup. Tahap workflow terikat jabatan (approval kepala unit/gudang, penerbitan SPK oleh `kepala_teknisi`) memakai `hasRole()` **dan** permission aksi. Data non-global dibatasi ke `unit_id`/`gudang_id` pengguna; bypass scope global hanya untuk `super_admin` dan `admin_logistik`. Permission `page_*Dashboard` hanya membuka portal.
- **Konfigurasi operasional**: nilai dibaca lewat `config('simlog.*')` (`config/simlog.php`), bukan `env()` langsung (ADR 0003).
- **Audit**: mutasi penting menulis `log_audit` dalam transaksi yang sama; gagal audit membatalkan mutasi (ADR 0002).
- **Kode otomatis**: kode entitas dibuat `CodeGenerator` (`BRG-YYYY-XXX`, `AST-YYYY-XXX`, `PR-`, `PO-`, `MNT-`, `SPK-`, dan seterusnya). Input kode di form dinonaktifkan.
- **Toast**: `Flux::toast()` dengan variant `success`, `warning`, `danger`, `info`.
- **Docker-first**: semua perintah `artisan`, `composer`, `npm` lewat `docker compose exec app <perintah>`.

## Struktur Direktori Utama

```
app/
├── Http/Controllers/Api/V1/   # REST API mobile per domain
├── Http/Middleware/           # PortalAccessMiddleware, EnsureUserIsActive
├── Livewire/                  # Halaman per domain (Master, Pengadaan, Distribusi, Aset, Persediaan, Ipsrs, Audit, User, Dashboard, ...)
├── Mail/                      # AkunPegawaiBaruMail
├── Models/                    # Model Eloquent
├── Observers/                 # Pemicu notifikasi
├── Services/                  # Logika domain (Distribusi, Pengadaan, Aset, Persediaan, Notifikasi, Dashboard, User)
└── Support/                   # PagePermissionRegistry, PortalResolver, CodeGenerator
database/seeders/              # Master, Rbac, Dummy (dinonaktifkan)
docs/                          # ADR, kontrak notifikasi, UAT, keamanan
resources/views/
├── components/                # ui/* dan tabs/*
├── layouts/                   # portal.blade.php (sidebar Flux)
├── livewire/                  # View per modul
└── mail/                      # Templat email
routes/                        # web.php, api.php, console.php (scheduler)
tests/                         # Feature dan Unit
```

## License

Proprietary — Hak cipta dilindungi.
