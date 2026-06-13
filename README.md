# Inquiry Transfer Bank via SIS/Assist Switching Middleware

> Script PHP single-file untuk melakukan **Inquiry Transfer Dana** dan **Inquiry BIFAST**
> melalui jalur **SIS/Assist Switching Middleware** (`assist-switching_v3_pro` v1.6.39 "Assist Pro Net").

---

## 📋 Daftar Isi

1. [Gambaran Umum](#gambaran-umum)
2. [File yang Tersedia](#file-yang-tersedia)
3. [Arsitektur & Alur Transaksi](#arsitektur--alur-transaksi)
4. [Prasyarat](#prasyarat)
5. [Cara Konfigurasi](#cara-konfigurasi)
6. [Cara Penggunaan — inquiry_transfer_permata.php](#cara-penggunaan--inquiry_transfer_permataph)
7. [Cara Penggunaan — inquiry_bifast_permata.php](#cara-penggunaan--inquiry_bifast_permataph)
8. [Format Request & Response](#format-request--response)
9. [Token Cache (CDS Pattern)](#token-cache-cds-pattern)
10. [Debug Panel](#debug-panel)
11. [Kode Referensi DE048](#kode-referensi-de048)
12. [Troubleshooting](#troubleshooting)
13. [Referensi Source Code](#referensi-source-code)

---

## Gambaran Umum

Kedua file PHP ini merupakan **standalone script** (tidak memerlukan framework) yang
mengimplementasikan komunikasi langsung dengan server **SIS/Assist digital** menggunakan:

| Komponen | Detail |
|----------|--------|
| **Autentikasi** | OAuth 2.0 (grant_type=password / client_credentials) via `myassist.sis1.net` |
| **Signing** | `hash('sha256', "cCode={json}" . timestamp)` → header `authorization` |
| **Identity** | Header `identity: {cicd_hash}` — fixed per instalasi Assist |
| **Protokol** | HTTP POST form-urlencoded ke `digital.sis1.net` |
| **Format Pesan** | ISO 8583-like JSON (`MTI` + `MSG` dengan field `DE003`…`DE103`) |
| **Token Cache** | File `.cache` pola CDS (mencegah request token berlebihan) |

---

## File yang Tersedia

```
webapp/
├── inquiry_transfer_permata.php   ← Inquiry Transfer Dana (TFDANA/LLG/RTGS)
├── inquiry_bifast_permata.php     ← Inquiry BIFAST (Bank Indonesia Fast Payment)
└── storage/
    └── cds/
        └── cache/
            ├── cds-auth-a-{KodeAgen}_{mftfi}-snap_tf_bank_permata.cache  ← token TF
            └── cds-auth-a-{KodeAgen}_{mftfi}-snap_ft_bdi.cache           ← token BIFAST
```

---

## Arsitektur & Alur Transaksi

```
Client Request (HTTP GET/POST)
        │
        ▼
┌───────────────────────────┐
│  1. Cek Token Cache       │  cds-auth-a-{agen}_{mftfi}-snap_*.cache
│     Ada & valid? → pakai  │
│     Tidak ada? → step 2   │
└───────────────────────────┘
        │
        ▼
┌───────────────────────────┐
│  2. OAuth Token Request   │  POST myassist.sis1.net/assist-auth_api
│     grant_type=password   │  /public/oauth/getaccesstoken
│     → simpan ke cache     │
└───────────────────────────┘
        │
        ▼
┌───────────────────────────┐
│  3. Bangun ISO 8583 JSON  │  MTI=010 (DIGITAL_BANK_INQUIRY)
│     MSG: DE003..DE103     │  DE048 = kode transaksi (INQTFDANA/INQBIFAST/dll)
└───────────────────────────┘
        │
        ▼
┌───────────────────────────┐
│  4. Signing & Send        │  POST digital.sis1.net
│     auth = SHA256(body+ts)│  /assist-digital.net/public/dgl
│     identity = cicd hash  │
│     datetime = SNow()     │
└───────────────────────────┘
        │
        ▼
┌───────────────────────────┐
│  5. Parse Response        │  ISO 8583 array → info rekening/nasabah
│     Tampilkan di UI       │
└───────────────────────────┘
```

---

## Prasyarat

- **PHP 8.1 atau lebih baru** (diuji pada PHP 8.4.21)
- **Ekstensi PHP**: `curl`, `json` (biasanya sudah aktif by default)
- **Akses jaringan** ke:
  - `http://myassist.sis1.net` (OAuth token)
  - `http://digital.sis1.net` (Digital server transaksi)
- **Kredensial OAuth** dari administrator SIS/Assist:
  - `client_id`, `client_secret`, `username` (UserH2H), `password`
- **Data Agen**:
  - `KODE_AGEN` — kode agen terdaftar di SIS (format `A-XXXXXX`)
  - `DE061_SIM_SERIAL` — SIMSerial/device identifier dari tabel `agen_fitur`
- **Direktori writable** untuk menyimpan token cache: `storage/cds/cache/`

---

## Cara Konfigurasi

Buka file yang ingin digunakan dan edit bagian **KONFIGURASI** di baris atas (sekitar baris 34–71):

### 1. OAuth Credentials

```php
define('OAUTH_CLIENT_ID',     'isi_client_id_anda');
define('OAUTH_CLIENT_SECRET', 'isi_client_secret_anda');
define('OAUTH_USERNAME',      'username_H2H_agen');
define('OAUTH_PASSWORD',      'password_H2H_agen');
```

> ℹ️ Credential ini diperoleh dari **administrator SIS/Assist** saat onboarding agen.

### 2. Kode Agen

```php
define('KODE_AGEN', 'A-000268');   // ganti dengan kode agen Anda
```

Format: `A-` diikuti 6 digit angka. Contoh: `A-000268`, `A-000300`.

### 3. DE061 SIM Serial

```php
define('DE061_SIM_SERIAL', '002680001234567');  // contoh
```

Nilai ini diambil dari kolom `SIMSerial` di tabel `agen_fitur` database SIS.
Digunakan oleh `MBankingFunc::GetKodeAgenMobile()` untuk resolusi kode agen.

### 4. MFTFI (Kode Mitra Transfer)

```php
define('MFTFI', '002');   // default "002" sesuai local_config.php
```

Nilai default adalah `"002"`. Ubah hanya jika agen Anda menggunakan kode mitra berbeda.
Nilai ini juga menentukan nama file cache token.

### 5. Debug Mode

```php
define('DEBUG_MODE', true);   // true = tampilkan panel debug, false = sembunyikan
```

Nonaktifkan (`false`) di environment produksi.

---

## Cara Penggunaan — `inquiry_transfer_permata.php`

### Fungsi
Melakukan inquiry rekening tujuan untuk transfer dana antar bank melalui:
- **TFDANA** — Transfer Dana Online (SKN Real-Time / Onlne)
- **LLG** — Lalu Lintas Giro (SKN Batch, biasanya < Rp 100 juta)
- **RTGS** — Real-Time Gross Settlement (biasanya > Rp 100 juta)

### Cara Akses via Browser

Buka di browser dengan parameter GET:

```
http://localhost:3000/inquiry_transfer_permata.php
```

Tanpa parameter → tampil **form input HTML** interaktif.

### Parameter Input (Form / GET / POST)

| Parameter | Wajib | Contoh | Keterangan |
|-----------|-------|--------|------------|
| `nomor_rekening` | ✅ | `1234567890` | Nomor rekening tujuan |
| `kode_bank` | ✅ | `014` | Kode bank tujuan (3 digit BCA=014, Mandiri=008, BNI=009, BRI=002) |
| `jenis_transfer` | ❌ | `TFDANA` | Jenis transfer: `TFDANA` (default), `LLG`, `RTGS` |
| `nominal` | ❌ | `1000000` | Nominal transfer (Rupiah, angka saja) |

### Contoh Request GET

```
# Transfer Dana Online (TFDANA) ke BCA
http://localhost:3000/inquiry_transfer_permata.php
  ?nomor_rekening=1234567890
  &kode_bank=014
  &jenis_transfer=TFDANA
  &nominal=500000

# LLG ke Mandiri
http://localhost:3000/inquiry_transfer_permata.php
  ?nomor_rekening=1230000456789
  &kode_bank=008
  &jenis_transfer=LLG
  &nominal=50000000

# RTGS ke BNI (nominal besar)
http://localhost:3000/inquiry_transfer_permata.php
  ?nomor_rekening=9876543210
  &kode_bank=009
  &jenis_transfer=RTGS
  &nominal=150000000
```

### Contoh Request via cURL (CLI)

```bash
# TFDANA
curl -X POST http://localhost:3000/inquiry_transfer_permata.php \
  -d "nomor_rekening=1234567890&kode_bank=014&jenis_transfer=TFDANA&nominal=500000"

# LLG
curl -X GET "http://localhost:3000/inquiry_transfer_permata.php?nomor_rekening=1230000456789&kode_bank=008&jenis_transfer=LLG"
```

### DE048 yang Dihasilkan per Jenis Transfer

| Jenis Transfer | DE048 yang Dikirim |
|---------------|-------------------|
| `TFDANA` | `0601*1001*INQTFDANA~~BLTRFAG` |
| `LLG` | `0201*1001*INQLLG~~BLTRFAG` |
| `RTGS` | `0801*1001*INQRTGS~~BLTRFAG` |

---

## Cara Penggunaan — `inquiry_bifast_permata.php`

### Fungsi
Melakukan inquiry rekening/proxy tujuan untuk transfer **BIFAST** (Bank Indonesia Fast
Payment) — sistem pembayaran cepat Bank Indonesia dengan limit **Rp 200 juta** per transaksi.

### Cara Akses via Browser

```
http://localhost:3000/inquiry_bifast_permata.php
```

Tanpa parameter → tampil form input HTML interaktif.

### Parameter Input

| Parameter | Wajib | Contoh | Keterangan |
|-----------|-------|--------|------------|
| `nomor_rekening` | ✅ | `1234567890` | Nomor rekening / proxy tujuan |
| `kode_bank` | ✅ | `014` | Kode bank / institusi tujuan |
| `proxy_type` | ❌ | `ACCOUNT_NUMBER` | Tipe identifikasi tujuan (lihat tabel di bawah) |
| `proxy_value` | ❌ | `08123456789` | Nilai proxy (jika bukan nomor rekening) |
| `nominal` | ❌ | `1000000` | Nominal transfer (maks Rp 200.000.000) |

### Tipe Proxy (`proxy_type`)

| Nilai | Keterangan | Contoh `proxy_value` |
|-------|------------|---------------------|
| `ACCOUNT_NUMBER` | Nomor rekening (default) | `1234567890` |
| `PHONE_NUMBER` | Nomor HP terdaftar di BI-FAST | `08123456789` |
| `EMAIL` | Alamat email terdaftar | `nama@email.com` |
| `VIRTUAL_ACCOUNT` | Nomor Virtual Account | `9888801234567890` |

### Contoh Request GET

```
# Inquiry by nomor rekening (paling umum)
http://localhost:3000/inquiry_bifast_permata.php
  ?nomor_rekening=1234567890
  &kode_bank=014
  &proxy_type=ACCOUNT_NUMBER
  &nominal=1000000

# Inquiry by nomor HP
http://localhost:3000/inquiry_bifast_permata.php
  ?proxy_type=PHONE_NUMBER
  &proxy_value=08123456789
  &kode_bank=014
  &nominal=500000

# Inquiry by email
http://localhost:3000/inquiry_bifast_permata.php
  ?proxy_type=EMAIL
  &proxy_value=nasabah@email.com
  &kode_bank=014
  &nominal=2000000
```

### Contoh Request via cURL

```bash
# By nomor rekening
curl -X POST http://localhost:3000/inquiry_bifast_permata.php \
  -d "nomor_rekening=1234567890&kode_bank=014&proxy_type=ACCOUNT_NUMBER&nominal=1000000"

# By nomor HP
curl -X POST http://localhost:3000/inquiry_bifast_permata.php \
  -d "proxy_type=PHONE_NUMBER&proxy_value=08123456789&kode_bank=014&nominal=500000"
```

### DE048 BIFAST

| Selalu | `0601*1001*INQBIFAST~~BLTRFAG` |
|--------|-------------------------------|

---

## Format Request & Response

### Request yang Dikirim ke Digital Server

```http
POST http://digital.sis1.net/assist-digital.net/public/dgl
Content-Type: application/x-www-form-urlencoded
authorization: {sha256_hash}
identity: db96e3cba196f76a6c31e4c9625614b3dc57619fba7e29ee534dd20c5c44855d
datetime: 2024-06-13 14:30:00
Authorization: Bearer {access_token}

cCode={"MTI":"010","MSG":{"DE003":"231041","DE004":"000001000000","DE012":"1430","DE013":"1306","DE037":"143000123456","DE044":"0","DE048":"0601*1001*INQTFDANA~~BLTRFAG","DE052":"0000000000000000000000000000000000000000000000000000000000000000","DE061":"0026800001","DE102":"1234567890","DE103":"014"}}
```

**Penjelasan Header:**

| Header | Nilai | Keterangan |
|--------|-------|------------|
| `authorization` | SHA256(`cCode={json}` + timestamp) | Signing body + waktu |
| `identity` | `db96e3cb...855d` | CICD hash tetap (dari `local_config.php`) |
| `datetime` | `2024-06-13 14:30:00` | Timestamp WIB format `Y-m-d H:i:s` (`SNow()`) |
| `Authorization` | `Bearer {token}` | OAuth token dari myassist.sis1.net |

### Format Body Request (ISO 8583-like JSON)

```json
{
  "MTI": "010",
  "MSG": {
    "DE003": "231041",
    "DE004": "000001000000",
    "DE012": "1430",
    "DE013": "1306",
    "DE037": "143000123456",
    "DE044": "0",
    "DE048": "0601*1001*INQTFDANA~~BLTRFAG",
    "DE052": "0000000000000000000000000000000000000000000000000000000000000000",
    "DE061": "0026800001",
    "DE102": "1234567890",
    "DE103": "014"
  }
}
```

**Penjelasan Field DE:**

| Field | Panjang | Isi | Keterangan |
|-------|---------|-----|------------|
| `DE003` | 6 | `231041` | Processing code (inquiry payment) |
| `DE004` | 12 | `000001000000` | Amount: 10 digit + 2 desimal (rupiah) |
| `DE012` | 4 | `1430` | Local time: HHmm |
| `DE013` | 4 | `1306` | Local date: ddMM |
| `DE037` | 12 | `143000123456` | Retrieval reference number (unik per transaksi) |
| `DE044` | 1 | `0` | Additional response data |
| `DE048` | var | `0601*1001*INQTFDANA~~BLTRFAG` | Kode transaksi Assist |
| `DE052` | 64 | `000...0` | PIN block (64 zero untuk inquiry) |
| `DE061` | var | `0026800001` | SIMSerial / device identifier agen |
| `DE102` | var | `1234567890` | Nomor rekening / proxy tujuan |
| `DE103` | var | `014` | Kode bank tujuan |

### Contoh Response Sukses

```json
{
  "MTI": "110",
  "RC": "00",
  "MSG": {
    "DE039": "00",
    "DE044": "JOHN DOE",
    "DE102": "1234567890",
    "DE103": "014"
  }
}
```

> **RC="00"** berarti inquiry berhasil. `DE044` biasanya berisi **nama pemilik rekening**.

### Response Code Umum

| RC | Keterangan |
|----|------------|
| `00` | Berhasil — rekening valid |
| `05` | Do not honor — rekening tidak aktif |
| `14` | Invalid card number — nomor rekening tidak valid |
| `51` | Insufficient funds |
| `96` | System malfunction |

---

## Token Cache (CDS Pattern)

Script menggunakan file cache untuk menyimpan OAuth token dan **menghindari request token
yang berlebihan** (sesuai pola CDS di `assist-switching_v3_pro`).

### Lokasi File Cache

```
storage/cds/cache/
├── cds-auth-a-{KodeAgen}_{mftfi}-snap_tf_bank_permata.cache   ← Transfer Dana (TF)
└── cds-auth-a-{KodeAgen}_{mftfi}-snap_ft_bdi.cache            ← BIFAST
```

**Contoh nama file nyata** (mengacu sample cache di source code):
```
cds-auth-a-000268_002-snap_tf_bank_permata.cache   (KodeAgen=A-000268, mftfi=002)
cds-auth-a-000300_002-snap_ft_bdi.cache            (KodeAgen=A-000300, mftfi=002)
```

### Format Isi File Cache

```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
  "refresh_token": "def50200...",
  "expires_in": 3600,
  "cached_at": 1718274600,
  "token_type": "Bearer"
}
```

### Logika Cache

1. **Baca cache** → jika ada dan belum expired (dengan buffer 60 detik) → gunakan langsung
2. **Jika tidak ada / expired** → request token baru ke `myassist.sis1.net`
3. **Token baru** → simpan ke file cache untuk request berikutnya
4. **Fallback** → jika `grant_type=password` gagal, coba `grant_type=client_credentials`

### Membersihkan Cache (Force Refresh Token)

```bash
# Hapus cache TF
rm storage/cds/cache/cds-auth-a-*-snap_tf_bank_permata.cache

# Hapus cache BIFAST
rm storage/cds/cache/cds-auth-a-*-snap_ft_bdi.cache

# Hapus semua cache
rm -rf storage/cds/cache/
```

---

## Debug Panel

Saat `DEBUG_MODE = true`, kedua file menampilkan **4 panel debug** di bawah form:

```
┌─────────────────────────────────────────────────────────┐
│ Panel 1: Konfigurasi Aktif                              │
│   URL Token, URL Digital, KODE_AGEN, MFTFI, CICD,      │
│   CACHE_FILE, status cache (Ada/Tidak ada)              │
├─────────────────────────────────────────────────────────┤
│ Panel 2: OAuth Token Request                            │
│   URL, status (Cache/Baru), HTTP code, response time,  │
│   token (tertrunkasi untuk keamanan)                    │
├─────────────────────────────────────────────────────────┤
│ Panel 3: Request ke Digital Server                      │
│   URL, body yang dikirim (cCode=...), headers lengkap,  │
│   SHA256 hash yang digunakan, timestamp SNow()          │
├─────────────────────────────────────────────────────────┤
│ Panel 4: Response dari Digital Server                   │
│   HTTP code, response time (ms), raw JSON response,    │
│   hasil parse ISO 8583 (RC, nama nasabah, dll)          │
└─────────────────────────────────────────────────────────┘
```

---

## Kode Referensi DE048

Format: `{part1}*{part2}*{TrxCode}~~{SubCode}`

Parsing di source code (`mbanking.controller.php`):
```php
$vaDE048  = split("*", $cRequest['DE048']);  // ["0601","1001","INQTFDANA~~BLTRFAG"]
$vaTrx    = split("~~", $vaDE048[2]);        // ["INQTFDANA","BLTRFAG"]
$cKodeMrg = $vaTrx[0];                       // "INQTFDANA"
```

| TrxCode | SubCode | Part1 | Part2 | Fungsi |
|---------|---------|-------|-------|--------|
| `INQTFDANA` | `BLTRFAG` | `0601` | `1001` | Inquiry Transfer Dana Online |
| `INQLLG` | `BLTRFAG` | `0201` | `1001` | Inquiry LLG (SKN Batch) |
| `INQRTGS` | `BLTRFAG` | `0801` | `1001` | Inquiry RTGS |
| `INQBIFAST` | `BLTRFAG` | `0601` | `1001` | Inquiry BIFAST |

---

## Troubleshooting

### ❌ cURL Error: Could not resolve host

```
Penyebab : Server myassist.sis1.net atau digital.sis1.net tidak dapat dijangkau
Solusi   : Pastikan script dijalankan di server yang memiliki akses ke jaringan SIS
           (biasanya hanya dari server produksi yang sudah whitelist IP)
```

### ❌ HTTP 401 Unauthorized dari Token Server

```
Penyebab : OAUTH_CLIENT_ID / OAUTH_CLIENT_SECRET / USERNAME / PASSWORD salah
Solusi   : Verifikasi credential dengan administrator SIS/Assist
           Cek apakah username H2H sudah aktif di myassist.sis1.net
```

### ❌ RC ≠ "00" dari Digital Server

```
Penyebab : Transaksi ditolak (rekening tidak valid, kode bank salah, dll)
Solusi   : Cek DE039 dan RC di response untuk kode error spesifik
           Pastikan DE048 sesuai dengan jenis transfer yang diminta
```

### ❌ KODE_AGEN kosong → nama cache file salah

```
Penyebab : KODE_AGEN belum diisi
Efek     : Cache file tersimpan sebagai cds-auth-a-_002-snap_*.cache (tanpa kode agen)
Solusi   : Isi KODE_AGEN dengan kode agen yang valid (contoh: 'A-000268')
```

### ❌ Permission denied saat menyimpan cache

```bash
# Buat direktori cache dan beri permission
mkdir -p storage/cds/cache
chmod 755 storage/cds/cache
```

### ❌ Nominal BIFAST melebihi batas

```
Penyebab : Nominal > Rp 200.000.000 (BIFAST_MAX_AMOUNT)
Efek     : Script otomatis memotong ke nilai maksimum
Solusi   : Gunakan RTGS untuk transfer > Rp 200 juta
```

---

## Referensi Source Code

Script ini diimplementasikan berdasarkan analisis source code:

| File | Keterangan |
|------|------------|
| `assist-switching_v3_pro/config/local_config.php` | URL endpoints, cicd hash, mftfi |
| `assist-switching_v3_pro/mvc/mbanking/mbanking.controller.php` | `ProsesInquiryPayment()`, signing pattern, DE field mapping, BIFAST routing |
| `assist-switching_v3_pro/include/func.oauth.mod.php` | OAuth token management, CekAccessToken, GetAccessToken |
| `assist-switching_v3_pro/storage/cds/cache/*.cache` | Pola penamaan file cache CDS |

**Versi referensi:** `assist-switching_v3_pro` v1.6.39 "Assist Pro Net"

---

## Deployment Info

- **GitHub**: https://github.com/pt-zenity/permatasnap
- **Platform**: PHP 8.4.21 (kompatibel PHP 8.1+)
- **Status**: ✅ Active — syntax valid, HTTP 200
- **Last Updated**: 2026-06-13
