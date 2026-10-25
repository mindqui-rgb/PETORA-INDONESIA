# Pet Shop dan Klinik Hewan

Sistem operasional terpadu untuk pet shop dan klinik hewan dengan dukungan banyak cabang. Satu aplikasi mencakup kasir (POS), inventori bersama, toko online dengan pembayaran manual, klinik dan rekam medis, rawat inap, grooming dan penitipan, tagihan layanan, keuangan, dan portal pelanggan.

> **Status:** Versi 1.0. Dokumen ini menjelaskan cara menjalankan dan mengelola proyek. Aturan bisnis lengkap ada di [`docs/spesifikasi-petshop-klinik-v1.md`](docs/spesifikasi-petshop-klinik-v1.md).

---

## Fitur Utama

- **Multi-cabang:** satu basis data untuk seluruh cabang, dengan kasir, shift, zona waktu, dan rekening pembayaran per cabang.
- **Point of Sale:** shift kasir, pembayaran terpisah per metode, diskon bertingkat, retur, dan invoice bernomor unik.
- **Inventori:** saldo per lokasi dan batch, FEFO untuk obat, biaya rata-rata tertimbang, penyesuaian, stok opname, dan transfer antarcabang.
- **Toko online:** katalog, keranjang, pembayaran manual dengan bukti transfer, pemeriksaan stok setelah pembayaran, dan fulfillment pickup atau delivery.
- **Klinik:** jadwal dokter, janji temu tanpa tumpang tindih, rekam medis dengan tanda tangan, amendemen, resep, dan pengeluaran obat.
- **Rawat inap dan penitipan:** unit rawat dengan kapasitas, observasi, tugas, dan update untuk pemilik hewan.
- **Tagihan dan keuangan:** invoice layanan dengan pembayaran parsial, piutang, refund dengan pemisahan tugas, buku kas, pengeluaran dengan persetujuan, dan penutupan periode.
- **Portal pelanggan:** hewan, janji temu, pesanan, invoice, dan update rawat inap.
- **Keamanan:** izin berbasis peran dengan pemeriksaan terpusat, MFA untuk operasi sensitif, jejak audit yang tidak dapat diubah, dan idempotensi untuk setiap operasi tulis penting.

---

## Teknologi

| Lapisan | Teknologi |
|---------|-----------|
| Backend | Laravel 12, PHP 8.4 |
| Panel staf | Filament 4, Livewire 3 |
| Portal pelanggan | Blade, Tailwind CSS 4, Vite |
| Basis data | PostgreSQL 16 (`btree_gist`, `pg_trgm`) |
| Autentikasi | Laravel Fortify, Filament Breezy (TOTP MFA) |
| Angka uang | `brick/math` |
| Email | Resend |
| PDF | dompdf |
| Pemantauan error | Sentry |
| Cadangan | spatie/laravel-backup |
| Pengujian | Pest, Laravel Dusk, pengujian basis data dan konkurensi |
| Analisis statis | Laravel Pint, Larastan |

---

## Prasyarat

- PHP 8.4 dengan ekstensi `pdo_pgsql`, `intl`, `gd`, `fileinfo`, `mbstring`, `bcmath`
- Composer 2
- Node.js 22 LTS dan npm
- PostgreSQL 16 (lokal melalui Docker atau instalasi native)

---

## Instalasi Lokal

```bash
# 1. Clone repository
git clone git@github.com:{organisasi}/petshop-klinik.git
cd petshop-klinik

# 2. Pasang dependensi
composer install
npm ci

# 3. Siapkan environment
cp .env.example .env
php artisan key:generate

# 4. Isi koneksi basis data di .env
#    DB_CONNECTION=pgsql
#    DB_HOST=127.0.0.1
#    DB_PORT=5432
#    DB_DATABASE=petshop_klinik
#    DB_USERNAME=...
#    DB_PASSWORD=...

# 5. Jalankan migration dan data demo sintetis
php artisan migrate
php artisan db:seed

# 6. Buat storage link dan buat akun pemilik pertama
php artisan storage:link
php artisan app:create-owner

# 7. Jalankan aplikasi dan asset
composer run dev
```

Setelah berjalan:

| Area | URL lokal |
|------|-----------|
| Situs publik dan portal pelanggan | `http://petshop.test` atau `http://localhost:8000` |
| Panel staf | `http://localhost:8000/admin` |
| Pemeriksaan kesehatan | `http://localhost:8000/health` |

> `app:create-owner` hanya dapat dijalankan sekali per lingkungan. Perintah ini dicatat di audit.

Data demo dibuat melalui seeder dan hanya berisi data sintetis. Seeder demo tidak berjalan di lingkungan `production`.

---

## Konfigurasi Environment

Semua variabel didokumentasikan di `.env.example`. Ringkasan variabel penting:

| Variabel | Keterangan |
|----------|------------|
| `APP_KEY` | Kunci enkripsi aplikasi. Dibuat dengan `php artisan key:generate`. |
| `DB_*` | Koneksi PostgreSQL. `DB_SSLMODE=require` untuk staging dan production. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | Diatur ke `database`. Redis tidak digunakan pada Versi 1. |
| `FILESYSTEM_DISK` | Diatur ke `private`. Berkas medis dan bukti pembayaran tidak boleh berada di disk publik. |
| `RESEND_KEY` | Kunci API Resend untuk email. |
| `SENTRY_LARAVEL_DSN` | Pemantauan error. Kosongkan di lokal bila tidak diperlukan. |
| `BACKUP_S3_*` | Tujuan cadangan di luar server. |

Aturan keamanan konfigurasi:

- Jangan pernah meng-commit file `.env`.
- Jangan memberi awalan `VITE_` atau `NEXT_PUBLIC_` pada rahasia apa pun.
- `APP_DEBUG` harus `false` di staging dan production.
- Rahasia hanya disimpan di environment hosting. Kunci yang pernah terekspos wajib dirotasi dalam 24 jam.

---

## Perintah Pengembangan

| Perintah | Fungsi |
|----------|--------|
| `composer run dev` | Menjalankan server, antrean, dan Vite bersamaan |
| `php artisan test` | Menjalankan seluruh tes Pest |
| `php artisan test --testsuite=Isolation` | Tes isolasi cabang dan pelanggan |
| `php artisan test --testsuite=Concurrency` | Tes konkurensi stok, pembayaran, dan kas |
| `php artisan dusk` | Tes E2E di desktop, tablet, dan ponsel |
| `vendor/bin/pint` | Format kode |
| `vendor/bin/phpstan analyse` | Analisis statis Larastan |
| `npm run build` | Build asset produksi |
| `php artisan reconcile:stock` | Rekonsiliasi `on_hand` dengan ledger mutasi |
| `php artisan reconcile:cash` | Rekonsiliasi kas per shift |
| `php artisan idempotency:expire` | Menghapus kunci idempotensi yang sudah kedaluwarsa |
| `php artisan schedule:list` | Melihat daftar job terjadwal |

Sebelum membuka pull request, jalankan minimal:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
npm run build
```

---

## Pengujian

Pengujian dibagi menurut risikonya:

- **Unit:** perhitungan uang, diskon, pembulatan, biaya rata-rata, FEFO, dan transisi status.
- **Fitur dan basis data:** constraint, trigger immutability, RPC logic di service, dan idempotensi pada PostgreSQL nyata.
- **Isolasi:** setiap route dan policy diuji dengan akun dari setiap peran dan cabang, termasuk percobaan mengganti ID milik pelanggan lain.
- **Konkurensi:** dua proses menjalankan operasi bersamaan pada stok, pembayaran, dan kas yang sama.
- **E2E:** alur POS, pesanan online, rekam medis, rawat inap, dan portal pada viewport 1280 px, 768 px, dan 390 px.

Target cakupan minimum: 85% untuk `app/Services` dan `app/Support`, serta 100% untuk setiap method yang mengubah stok, kas, atau status pembayaran.

Kriteria penerimaan lengkap (AC-01 sampai AC-20) dan skenario wajib ada di spesifikasi Bagian 24.

---

## Struktur Proyek Saat Ini

```text
app/
├── Domain/                 Enum status, konstanta izin, dan kode alasan
├── Services/
│   ├── Clinic/             AppointmentService, MedicalRecordService
│   ├── Inventory/          ProductCatalogService, InventoryAdjustmentService, InventoryTransferService
│   ├── Pos/                CashShiftService, PosSalesService
│   ├── Purchasing/         PurchaseReceivingService
│   └── Shared/             Transactions, IdempotencyGuard, DocumentNumberService, StockWriteGuard, AuditLogger, CustomerPetService
├── Support/                Money, AccessControl
├── Http/Controllers/        HealthCheckController
├── Console/Commands/        ExpireIdempotencyKeys
└── Models/                  Model untuk modul yang sudah dimulai
database/
├── migrations/              Skema dan constraint modul yang sudah dimulai
└── seeders/                 PermissionSeeder, PaymentMethodSeeder
resources/views/             View Laravel
tests/
├── Unit/
└── Feature/
docs/                         Spesifikasi dan dokumentasi proyek
```

Panel Filament, komponen Livewire, middleware keamanan, dan command operasional lainnya belum diimplementasikan. Folder Online, Hospitalization, Grooming, Billing, dan Finance dibuat saat modulnya dimulai.

Aturan arsitektur utama:

- Model Eloquent tidak mengubah stok, kas, atau status secara langsung. Perubahan dilakukan melalui service dalam transaksi basis data.
- Komponen Filament, Livewire, dan Blade memanggil service, tidak menulis ke model secara langsung.
- Total transaksi selalu dihitung ulang di server.
- Kode di `app/Services` tidak bergantung pada `request()` atau komponen tampilan.

---

## Deployment

Deployment menggunakan **Laravel Forge** di VPS Ubuntu 24.04 region Singapura, dengan PostgreSQL 16 terkelola yang memiliki point-in-time recovery.

| Lingkungan | Pemicu | Domain |
|------------|--------|--------|
| Staging | Merge ke `main` (otomatis) | `staging.{domain}` |
| Production | Branch `production`, dideploy manual dari Forge | `app.{domain}` |

Langkah ringkas:

1. Buat pull request dari branch fitur ke `main`. Pastikan CI lulus dan satu reviewer menyetujui.
2. Merge ke `main`. Forge men-deploy ke staging dan menjalankan migration.
3. Lakukan smoke test di staging.
4. Setelah UAT disetujui pemilik, fast-forward `main` ke `production` dan jalankan **Deploy** di Forge.

Skrip deploy dan penyiapan Forge lengkap ada di `docs/deployment.md`.

Aturan migration production:

- Migration destruktif (drop kolom, tabel, constraint, atau pengubahan tipe) memerlukan persetujuan pemilik yang tercatat di pull request.
- Perubahan berisiko memakai pola expand and contract.
- Migration tidak dijalankan manual di production kecuali melalui prosedur insiden.

---

## Operasional

- **Cadangan:** PITR basis data (RPO 15 menit) ditambah dump harian ke penyimpanan S3-compatible di luar server, retensi 35 hari.
- **Pemulihan:** target RTO 4 jam. Uji pemulihan dilakukan setiap bulan ke staging terpisah.
- **Pemantauan:** Sentry untuk error, endpoint `/health`, dan tabel `job_runs` untuk status job terjadwal.
- **Insiden:** ikuti prosedur di `docs/runbook.md`. Jangan mengubah stok atau kas langsung di basis data produksi.

Prosedur lengkap, termasuk pemulihan dan daftar kontak penanggung jawab, ada di `docs/runbook.md`.

---

## Dokumentasi

| Dokumen | Isi |
|---------|-----|
| [`docs/spesifikasi-petshop-klinik-v1.md`](docs/spesifikasi-petshop-klinik-v1.md) | Spesifikasi lengkap dan keputusan terkunci (T1–T22, B1–B34) |
| [`docs/permissions.md`](docs/permissions.md) | Matriks izin per peran, sinkron dengan seeder |
| [`docs/deployment.md`](docs/deployment.md) | Penyiapan Forge, environment, dan skrip deploy |
| [`docs/runbook.md`](docs/runbook.md) | Cadangan, pemulihan, dan prosedur insiden |

Jika ada perbedaan antara kode dan spesifikasi, spesifikasi yang berlaku. Perubahan keputusan dilakukan dengan merevisi spesifikasi dan menaikkan nomor versinya.

---

## Keamanan

Untuk melaporkan kerentanan, jangan membuka issue publik. Hubungi developer lead atau pemilik proyek secara langsung dengan rincian yang diperlukan untuk mereproduksi masalah.

Data klinis, bukti pembayaran, dan data pribadi pelanggan tidak boleh muncul di issue, pull request, log, tangkapan layar, atau tiket. Gunakan data sintetis untuk reproduksi.

---

## Kontribusi

1. Buat branch dari `main` dengan awalan `feat/`, `fix/`, `db/`, atau `chore/`.
2. Gunakan Conventional Commits dalam bahasa Inggris, misalnya `feat(pos): add complete_sale rpc`.
3. Setiap perubahan pada otorisasi, stok, kas, pembayaran, atau rekam medis wajib disertai tes.
4. Setiap perubahan skema dilakukan melalui migration baru. Migration yang sudah di-merge tidak diedit.
5. Pull request memerlukan minimal satu reviewer lain dan CI yang lulus.

Konvensi kode, aturan agen, dan kriteria selesai ada di `AGENTS.md`.

---

## Lisensi

Hak cipta ©2026 PETORA INDONESIA. Seluruh hak dilindungi. Perangkat lunak ini bersifat proprietary dan tidak untuk didistribusikan tanpa izin tertulis dari pemilik.
