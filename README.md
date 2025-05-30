# DuitkuXFossbilling
Payment gateway Indonesia Fossbilling x Duitku 
# Dokumentasi Payment Adapter Duitku untuk FOSSBilling

**Versi:** 1.0.0
**Tanggal Rilis:** 25 Mei 2025

---

## 1. Ringkasan

Dokumentasi ini menjelaskan instalasi, konfigurasi, dan penggunaan **Payment Adapter Duitku POP** (QRIS, VA, dll) yang terintegrasi dengan FOSSBilling. Modul ini memungkinkan merchant menerima pembayaran melalui Duitku dengan proses IPN otomatis.

## 2. Persyaratan

* FOSSBilling 1.x atau kompatibel
* PHP 8.0+ dengan ekstensi cURL dan JSON
* Library **Duitku PHP SDK** (`duitku/duitku-php`)
* Pimple Container (sudah ada di FOSSBilling)

## 3. Instalasi

1. Copy direktori `library/Payment/Adapter/Duitku/` ke `YOUR_FOSSBILLING/library/Payment/Adapter/Duitku/`.
2. Pastikan file `Duitku.php` memiliki namespace dan class seperti di repo.
3. Update composer (jika diperlukan):

   ```bash
   composer require duitku/duitku-php
   ```
4. Bersihkan cache FOSSBilling:

   ```bash
   php fossbilling cache:clear
   ```

## 4. Konfigurasi

1. Masuk ke *Admin → Settings → Payment Gateways*.
2. Pilih **Duitku**. Isi:

   * **Merchant Code**: kode merchant Anda dari dashboard Duitku.
   * **API Key**: kunci API sandbox/production.
   * **Enable Sandbox**: Yes (sandbox) / No (production).
3. Salin **IPN Callback URL** yang muncul dan tempel ke *Merchant Portal* Duitku sebagai callback.
4. Simpan.

## 5. Cara Kerja

### 5.1. `getHtml()`

* Membuat invoice di Duitku via `Pop::createInvoice()`.
* Parameter wajib: `paymentAmount`, `merchantOrderId`, `productDetails`, `customerDetail`, `callbackUrl`,`returnUrl`, `expiryPeriod`.
* Menampilkan `<iframe>` untuk checkout dan memanggil `checkout.process(reference)`.

### 5.2. `processTransaction()`

* Dipanggil FOSSBilling melalui IPN router.
* Memverifikasi callback dari Duitku via `Pop::callback()`.
* Jika `resultCode == '00'`:

  1. Cari model **Invoice** berdasarkan `merchantOrderId` (atau Service jika order berbasis layanan).
  2. Tambah dana pelanggan (`Client::addFunds`).
  3. Tandai invoice lunas (`Invoice::markAsPaid`).
  4. Catat atau perbarui record tabel `Transaction`:

     * `invoice_id`, `gateway_id`
     * `type`: `Payment`
     * `status`: `Complete` atau `Pending validation`
     * `txn_id`, `amount`, `currency`, `updated_at`
* Kembalikan `['status'=>'ok']` ke FOSSBilling.

## 6. Logging & Debugging

* Semua *params* dan *responses* dicatat ke `error_log` dengan prefix `[Duitku POP]`.
* Jika IPN tidak memproses, periksa log FOSSBilling (`storage/logs/`) untuk:

  * Payload raw
  * Invoice lookup
  * Mark as paid / store transaction

## 7. Catatan Versi (Changelog)

* **v1.0.0** (25 Mei 2025):

  * Rilis awal: `getHtml()`, `processTransaction()`, otomatis mark-paid, record transaction.
  * Penanganan sandbox/production via `Config::setSandboxMode()`.
  * Fallback lookup dan upsert `Transaction`.

---

*Document generated by pengembangan tim IT.*
