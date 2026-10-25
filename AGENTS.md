# AGENTS.md – PETORA-INDONESIA

Dokumen ini mengatur perilaku AI/LLM saat mengerjakan proyek **PETORA-INDONESIA**. Tujuannya adalah memastikan setiap perubahan implementasi bersifat *end-to-end*, konsisten dengan spesifikasi, patuh terhadap struktur repository yang ada, dan benar-benar siap untuk *deployment*.

## 1. Tujuan

AI wajib melakukan pengembangan penuh hingga siap *deploy* dengan aturan:

- **Berbasis Spesifikasi**: Spesifikasi adalah sumber kebenaran mutlak.
- **Konsisten**: Implementasi, struktur file, penamaan, alur bisnis, dan aturan akses harus selaras antara kode, migrasi, route, test, dan spesifikasi.
- **Repository-Aware**: Tidak membuat struktur baru sembarangan. Wajib menyesuaikan dengan pola yang sudah ada di proyek ini.
- **Siap Deployment**: Setiap pekerjaan dianggap selesai hanya jika memenuhi *Definition of Done* dan *Deployment Readiness Checklist* di bawah ini.

## 2. Sumber Kebenaran (Source of Truth)

Urutan prioritas yang wajib dipatuhi:

1. `docs/spesifikasi-petshop-klinik-v1.md` – **WAJIB** dijadikan acuan utama untuk kebutuhan fungsional, modul, entitas, aturan bisnis, role/permission, alur transaksi, dan kriteria penerimaan.
2. **Kode yang sudah ada** (`app/`, `database/migrations/`, `routes/`, `tests/`, `config/`, `app/Services/`, `app/Models/`, `app/Support/`) – Untuk mengikuti pola arsitektur, konvensi penamaan, cara penggunaan `Service`, `Model`, `Support`, dan pola testing yang berlaku.
3. `composer.json`, `package.json`, `phpunit.xml`, `.env.example` – Untuk menyesuaikan dengan stack proyek (Laravel, Vite, testing).
4. File konfigurasi lain yang relevan.

**Jangan menambah fitur di luar cakupan spesifikasi kecuali secara eksplisit diminta oleh user.**

## 3. Prinsip Kerja

- **Spec-First**: Sebelum menulis kode, baca dan pahami seluruh bagian relevan dari `docs/spesifikasi-petshop-klinik-v1.md`.
- **Structure-First**: Pahami struktur repository terlebih dahulu. Cocokkan lokasi file dengan pola yang sudah ada, bukan sebaliknya.
- **Traceable**: Setiap perubahan harus bisa ditelusuri ke entitas/modul/aturan yang ada di spesifikasi.
- **Minimal & Konsisten**: Lakukan perubahan seminimal mungkin namun cukup untuk memenuhi kebutuhan spesifikasi dan tetap konsisten dengan kode eksisting.
- **Transaction-Safe**: Untuk operasi bisnis kritikal (POS, Pembelian, Transfer Stok, Penyesuaian Stok, Rekam Medis, dll) wajib mempertimbangkan transaksi database, konsistensi stok, dan integritas data.
- **Idempotent-Aware**: Perhatikan mekanisme *idempotency key* yang sudah ada (lihat `tests/Feature/ExpireIdempotencyKeysTest.php` dan implementasi terkait).
- **Test-Aware**: Perhatikan pola test yang ada di `tests/Feature/` dan `tests/Unit/`. Usahakan perubahan tidak merusak test yang relevan dan tambahkan/verifikasi cakupan bila diperlukan.
- **No Guessing**: Jika ada ambiguitas antara spesifikasi dan kode eksisting, prioritaskan **keselarasan antara keduanya** dengan cara memetakan gap secara objektif, lalu selesaikan gap tersebut dengan tetap mengikuti pola proyek.

## 4. Struktur Repository yang Wajib Dipatuhi

Patuhilah struktur berikut. Jangan memindahkan file secara besar-besaran tanpa justifikasi yang sangat kuat dan konsisten dengan pola yang ada.

| Path | Kegunaan | Aturan |
| --- | --- | --- |
| `app/Models/` | Eloquent Models | Ikuti pola model yang ada (relasi, fillable/guarded, casts). Sesuaikan dengan migrasi. |
| `app/Services/` | Business Logic (terutama domain) | Lihat `app/Services/Pos/PosSalesService.php` sebagai acuan. Pisahkan logika transaksi kompleks dari Controller. |
| `app/Support/` | Helper/Utility lintas domain | Lihat `app/Support/Money.php` dan `app/Support/AccessControl.php`. Gunakan untuk logika reusable (uang, akses, validasi umum). |
| `app/Support/Errors/` | Domain Exception | Gunakan `DomainException` untuk error bisnis, bukan hanya `Exception` generik. |
| `app/Http/Controllers/` | HTTP Layer | Tipis. Validasi request, delegasi ke Service/Model, return response sesuai pola. |
| `app/Policies/` / aturan akses | Otorisasi | Ikuti pola `AccessControl` dan role/permission sesuai spesifikasi. |
| `app/Console/Commands/` | Artisan Commands | Lihat `app/Console/Commands/ExpireIdempotencyKeys.php` sebagai acuan. |
| `database/migrations/` | Skema DB | Ikuti pola penamaan dan urutan migrasi yang sudah ada. Jangan mengubah migrasi yang sudah *committed* kecuali benar-benar tidak terhindarkan dan aman. Prioritaskan menambah migrasi baru. |
| `routes/` | Routing | Tambahkan route dengan konsisten terhadap grup, middleware, dan pola yang berlaku. |
| `resources/views/` | Blade (jika dipakai) | Sesuaikan gaya yang ada. |
| `resources/js/`, `resources/css/` | Frontend asset | Ikuti setup Vite yang ada. |
| `tests/Feature/` & `tests/Unit/` | Pengujian | Patuhi gaya `Pest.php`/`TestCase`. Perhatikan test yang sudah ada untuk menghindari regresi. |
| `config/` | Konfigurasi | Hanya ubah jika benar-benar dibutuhkan oleh fitur yang sesuai spesifikasi. |
| `docs/` | Dokumentasi | Jangan menghapus/mengubah `spesifikasi-petshop-klinik-v1.md`. Hanya tambahkan jika diminta secara eksplisit. |

## 5. Alur Kerja Wajib (Spec-Driven Implementation)

Lakukan langkah-langkah ini secara berurutan untuk setiap tugas.

### Langkah 1: Analisis Spesifikasi
- Baca `docs/spesifikasi-petshop-klinik-v1.md` secara menyeluruh untuk modul yang relevan dengan permintaan user.
- Ekstrak: entitas, field kunci, relasi, status, role/permission, aturan stok/harga/biaya, alur transaksi, validasi, dan *acceptance criteria*.
- Catat poin-poin wajib yang harus ada di implementasi.

### Langkah 2: Pemetaan ke Repository
- Identifikasi file terkait yang sudah ada (`Model`, `Migration`, `Service`, `Controller`, `Routes`, `Tests`, `Support`).
- Bandingkan antara spesifikasi vs implementasi saat ini.
- Buat **gap analysis** singkat (apa yang hilang, apa yang belum konsisten, apa yang perlu disesuaikan).

### Langkah 3: Perencanaan Implementasi
- Rencanakan perubahan per layer: `Migration` -> `Model` -> `Support/Service` -> `Controller/Routes` -> `Policy/AccessControl` -> `Tests/Validasi`.
- Utamakan konsistensi dengan pola yang ada. Gunakan nama class, method, variabel, dan namespace yang sama dengan konvensi proyek.
- Pertimbangkan dampak ke modul lain (Inventori, POS, Pembelian, Transfer, Pelanggan/Hewan, Janji Temu, Rekam Medis, Akses).

### Langkah 4: Implementasi Bertahap
- **Database**: Sesuai kebutuhan spesifikasi. Jika perlu penambahan kolom/tabel, buat migrasi terpisah dengan pola penamaan yang konsisten. Jangan menghapus/merevisi migrasi lama secara sembunyi.
- **Model**: Tambahkan relasi, `$fillable`/`$guarded`, `casts`, dan accessor/mutator hanya jika diperlukan dan sesuai pola.
- **Domain Logic**: Letakkan di `app/Services/` untuk transaksi kompleks. Gunakan `DB::transaction()` bila melibatkan beberapa tabel/perubahan stok.
- **Support**: Manfaatkan `app/Support/Money.php` untuk perhitungan nilai uang. Manfaatkan `app/Support/AccessControl.php` untuk pengecekan role/permission.
- **HTTP/Route**: Jaga endpoint tetap terstruktur, gunakan middleware yang sesuai, dan pastikan terdaftar dengan benar.
- **Error Handling**: Gunakan `app/Support/Errors/DomainException.php` untuk pelanggaran aturan bisnis agar penanganan error konsisten.
- **Keamanan & Akses**: Terapkan pembatasan akses sesuai role/permission di spesifikasi. Jangan membuka akses tanpa alasan yang jelas dan sesuai aturan.

### Langkah 5: Verifikasi Terhadap Spesifikasi
- Cocokkan tiap poin penting di spesifikasi dengan kode hasil implementasi.
- Pastikan nama entitas, field, status, relasi, dan alur persis konsisten (atau setara tanpa mengubah makna).
- Pastikan tidak ada deviasi tanpa justifikasi yang terdokumentasi dalam pikiran/penjelasan hasil kerja.

### Langkah 6: Integrasi & Siap Deployment
- Jalankan verifikasi yang relevan (lihat bagian 7).
- Perbaiki issue yang ditemukan.
- Pastikan proyek dalam kondisi bersih dan siap di-deploy sesuai checklist di bagian 8.

## 6. Aturan Konsistensi Khusus PETORA

| Aspek | Aturan |
| --- | --- |
| **Uang (Money)** | Selalu gunakan `App\Support\Money`. Hindari perhitungan manual dengan float mentah untuk nilai moneter. Konsisten dengan cara pakai yang ada. |
| **Akses & Role** | Ikuti `App\Support\AccessControl`. Terapkan pembatasan berdasarkan role/permission sesuai spesifikasi. Jangan hardcode bypass tanpa justifikasi. |
| **Inventori & Stok** | Perubahan stok wajib terjaga konsistensinya. Perhatikan `InventoryMovement`, `StockBalance`, `InventoryLot`. Untuk transfer/adjustment/penerimaan/pembelian harus akurat dan teraudit. |
| **POS** | Ikuti pola `App\Services\Pos\PosSalesService`. Perhatikan transaksi, item, pembayaran, shift/kasir, dan potensi idempotensi. |
| **Pembelian (PO/Receiving)** | Jaga relasi PO -> penerimaan, biaya, lot, dan efek ke stok. |
| **Transfer Antar Lokasi/Gudang** | Pastikan alur keluar-masuk stok konsisten dan terdokumentasi lewat movement. |
| **Pelanggan, Hewan & Klinik** | Perhatikan relasi `Customer`, `Pet`, `Appointment`, `Visit`, `MedicalRecord`. Jaga integritas data klinis. |
| **Rekam Medis** | Perlakukan sebagai data sensitif. Ikuti aturan yang ada di spesifikasi. Perhatikan `MedicalRecordAmendment` jika berkaitan dengan revisi/amandemen. |
| **Idempotency** | Hormati pola yang ada terkait *idempotency keys*. Jangan merusak mekanisme `ExpireIdempotencyKeys`. |
| **Transaksi DB** | Gunakan `DB::beginTransaction()`, `commit()`, `rollBack()` atau `DB::transaction()` untuk operasi multi-tabel yang saling berkaitan. |
| **Error Bisnis** | Lempar `DomainException` dengan pesan yang jelas dan bermakna untuk aturan bisnis yang dilanggar. |

## 7. Pengujian & Verifikasi

Sebelum menyatakan pekerjaan selesai, lakukan verifikasi berikut sesuai konteks perubahan.

| Perintah | Kapan Digunakan | Tujuan |
| --- | --- | --- |
| `vendor/bin/pest` atau `php artisan test` | Setelah perubahan logika bisnis, service, route, model, atau akses. | Menjalankan seluruh test untuk mendeteksi regresi. |
| `vendor/bin/pest tests/Feature/NamaTestTerkait.php` | Setelah mengubah modul spesifik (POS, Inventory, Purchase, Transfer, Appointment, MedicalRecord, dll). | Verifikasi cepat area yang terkena dampak. |
| `vendor/bin/pint` / pengecekan format | Jika ada perubahan kode PHP signifikan. | Menjaga konsistensi gaya kode (sesuai gaya proyek). |
| `npm run build` | Jika mengubah `resources/js/` atau `resources/css/`. | Memastikan aset berhasil di-build tanpa error. |
| `php artisan migrate --pretend` | Jika menambah/mengubah migrasi. | Validasi skema tanpa eksekusi langsung yang tidak perlu. |

**Aturan:** Jika perintah verifikasi relevan tersedia dan bisa dijalankan di lingkungan, jalankan. Jika tidak bisa (keterbatasan lingkungan), sebutkan dengan jelas perintah apa yang perlu dijalankan user untuk memverifikasinya.

## 8. Deployment Readiness Checklist

Pekerjaan **BARU DIANGGAP SELESAI** jika semua poin berikut terpenuhi.

- [ ] **Kesesuaian Spesifikasi**: Seluruh poin relevan di `docs/spesifikasi-petshop-klinik-v1.md` telah dipenuhi dan konsisten.
- [ ] **Struktur Konsisten**: File ditempatkan sesuai pola repository yang ada. Tidak ada duplikasi logika yang seharusnya ada di `Service/Support`.
- [ ] **Migrasi Valid**: Migrasi baru aman, mengikuti pola, tidak merusak migrasi lama, dan relasi skema sesuai spesifikasi.
- [ ] **Model & Relasi Benar**: Relasi Eloquent konsisten dengan skema dan kebutuhan bisnis.
- [ ] **Logika Bisnis Aman**: Transaksi DB dipakai bila perlu. Perhitungan stok/harga menggunakan pola yang benar. `DomainException` dipakai untuk aturan bisnis.
- [ ] **Akses Terkontrol**: Role/permission sesuai spesifikasi melalui pola `AccessControl`.
- [ ] **Route Terdaftar**: Endpoint baru terdaftar, middleware sesuai, tidak bentrok dengan route yang ada.
- [ ] **Tidak Merusak yang Ada**: Perubahan kompatibel dengan test yang relevan. Jika ada test terkait yang gagal akibat perubahan yang benar & sesuai spesifikasi, jelaskan alasannya dan solusi perbaikannya.
- [ ] **Konfigurasi**: `/.env.example` diperbarui **hanya** jika benar-benar ada variabel baru yang wajib untuk fitur tersebut.
- [ ] **Aset**: Jika ada perubahan frontend, `npm run build` berhasil atau penjelasan perbaikan disediakan.
- [ ] **Bersih**: Tidak ada kode debug (`dd()`, `dump()`, `var_dump()`, `console.log` berlebihan), tidak ada komentar yang tidak perlu, tidak ada file sampah/temp.
- [ ] **Siap Deploy**: Sudah mempertimbangkan migrasi, cache, queue/scheduler (jika terkait `app/Console/Commands/`), storage, dan permission dasar untuk environment production-like.

## 9. Larangan Keras

- **Jangan abaikan spesifikasi**: Tidak boleh mengimplementasi berdasarkan asumsi pribadi jika bertentangan dengan `docs/spesifikasi-petshop-klinik-v1.md`.
- **Jangan merombak struktur tanpa alasan**: Jangan memindahkan namespace/path besar-besaran hanya untuk "merapikan" tanpa kebutuhan fungsional.
- **Jangan mengedit migrasi lama**: Hindari mengubah file migrasi yang sudah ada. Buat migrasi baru.
- **Jangan bypass akses**: Tidak boleh menonaktifkan pengecekan role/permission tanpa persetujuan eksplisit.
- **Jangan hitung uang dengan float**: Wajib lewat `App\Support\Money`.
- **Jangan biarkan stok tidak konsisten**: Setiap mutasi stok harus tercatat/terkonsiliasi sesuai pola inventori proyek.
- **Jangan tambahkan dependency baru**: Tanpa justifikasi kuat, terkait kebutuhan spesifikasi, dan kompatibel dengan `composer.json`/`package.json` yang ada.
- **Jangan tinggalkan kode rusak**: Hindari menaruh kode setengah jadi. Selesaikan sampai konsisten dengan alur spesifikasi.
- **Jangan mengarang skema/entitas**: Ikuti entitas yang terdefinisi di spesifikasi. Jika perlu menambah entitas, pastikan benar-benar ada di cakupan dan konsisten dengan relasi yang ada.

## 10. Pedoman Respons Saat Mengerjakan

Saat melaporkan hasil pekerjaan, ikuti pola ini:

1. **Ringkasan**: Jelaskan secara singkat apa yang dikerjakan dan kaitannya dengan modul di spesifikasi.
2. **Perubahan**: Sebutkan file yang ditambah/diubah/dihapus dengan path yang jelas.
3. **Kesesuaian Spesifikasi**: Sebutkan poin spesifikasi kunci yang terpenuhi (bisa merujuk ke bagian/konsep di `spesifikasi-petshop-klinik-v1.md`).
4. **Verifikasi**: Sebutkan perintah yang sudah dijalankan + hasilnya, atau perintah yang disarankan jika belum bisa dijalankan.
5. **Risiko/Notes**: Sebutkan dampak potensial ke modul lain (jika ada).
6. **Status**: Nyatakan apakah sudah **Ready for Review/Deployment** berdasarkan *Deployment Readiness Checklist* di atas.

## 11. Aturan Final

- **Spesifikasi > Asumsi**. Jika ragu, kembali ke `docs/spesifikasi-petshop-klinik-v1.md` dan cocokkan dengan kode yang ada.
- **Struktur adalah kontrak**. Jaga konsistensi struktur repository di atas semua.
- **Konsistensi adalah wajib**. Implementasi harus selaras end-to-end antara skema, model, service, route, akses, dan test.
- **Siap deploy atau belum selesai**. Jangan klaim "selesai" sebelum melewati seluruh poin di *Deployment Readiness Checklist*.
