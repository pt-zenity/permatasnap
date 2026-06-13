# Inquiry Transfer Bank via SIS/Assist Switching Middleware

> Script PHP single-file untuk melakukan **Inquiry Transfer Dana** dan **Inquiry BIFAST**
> melalui jalur **SIS/Assist Switching Middleware** (`assist-switching_v3_pro` v1.6.39 "Assist Pro Net").

---

## 📋 Daftar Isi

1. [Gambaran Umum](#gambaran-umum)
2. [File yang Tersedia](#file-yang-tersedia)
3. [Arsitektur & Alur Transaksi](#arsitektur--alur-transaksi)
4. [Prasyarat](#prasyarat)
5. [Cara Konfigurasi (Auto-Detection)](#cara-konfigurasi-auto-detection)
6. [Setup `.assist.env`](#setup-assistenv)
7. [Cara Penggunaan — inquiry_transfer_permata.php](#cara-penggunaan--inquiry_transfer_permataph)
8. [Cara Penggunaan — inquiry_bifast_permata.php](#cara-penggunaan--inquiry_bifast_permataph)
9. [Format Request & Response](#format-request--response)
10. [Token Cache (CDS Pattern)](#token-cache-cds-pattern)
11. [Debug Panel & Auto-Detection Report](#debug-panel--auto-detection-report)
12. [Kode Referensi DE048](#kode-referensi-de048)
13. [Troubleshooting](#troubleshooting)
14. [Referensi Source Code](#referensi-source-code)

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
| **Konfigurasi** | ⚡ **Auto-detection** dari `assist-switching_v3_pro` source + `.assist.env` |

---

## File yang Tersedia

```
(letakkan di dalam direktori assist-switching_v3_pro, atau direktori yang sama)
├── inquiry_transfer_permata.php   ← Inquiry Transfer Dana (TFDANA/LLG/RTGS)
├── inquiry_bifast_permata.php     ← Inquiry BIFAST (Bank Indonesia Fast Payment)
└── .assist.env                    ← OAuth credentials (wajib dibuat manual, sekali saja)
```

---

## Arsitektur & Alur Transaksi

```
Client Request (HTTP GET/POST)
        │
        ▼
┌───────────────────────────┐
│  0. Auto-Detection        │  detectAssistRoot() → cari local_config.php
│     Baca local_config.php │  parseLocalConfig() → URL, cicd, mftfi
│     Scan cache filename   │  detectFromCacheTF/BIFAST() → KodeAgen, mftfi
│     Load .assist.env      │  loadAssistEnv() → OAuth credentials
└───────────────────────────┘
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
- **`assist-switching_v3_pro`** terinstal di server yang sama (untuk auto-detection)
- **File `.assist.env`** berisi OAuth credentials (dibuat sekali, lihat seksi di bawah)

---

## Cara Konfigurasi (Auto-Detection)

> ✨ **Tidak perlu edit hardcode** — script otomatis mendeteksi konfigurasi dari instalasi Assist.

Script menggunakan **Auto-Detection Engine** dengan 4 fungsi:

### `detectAssistRoot()`
Berjalan naik direktori (max 6 level) mencari marker:
- `config/local_config.php`
- `storage/cds/cache`
- `sisproject/project.json`

Jika ≥2 marker ditemukan → itu adalah `assist_root`.

### `parseLocalConfig(string $assistRoot)`
Mem-parsing `config/local_config.php` via regex (tanpa `require`/`eval`):
```
$config['key'] = 'value'   →  key => value
$config['X'] = $config['BASE'] . '/suffix'  →  X => BASE_value/suffix
```
Mengekstrak: `_URL_GET_TOKEN_`, `_URL_MY_ASSIST_`, `s` (URL digital), `cicd`, `mftfi`, dll.

### `detectFromCacheTF()` / `detectFromCacheBIFAST()`
Scan file di `storage/cds/cache/`:
```
cds-auth-a-000268_0017-snap_tf_bank_permata.cache
              ^^^^^^  ^^^^
              KodeAgen mftfi  →  KODE_AGEN=A-000268, MFTFI=0017
```
File terbaru (filemtime) digunakan jika ada lebih dari satu.

### `loadAssistEnv()`
Mencari `.assist.env` di direktori saat ini dan 2 level di atasnya.
Format: `KEY=value` per baris.

### Nilai yang Terdeteksi Otomatis

| Konstanta | Sumber | Keterangan |
|-----------|--------|------------|
| `URL_GET_TOKEN` | `local_config.php` → `_URL_GET_TOKEN_` | URL OAuth token |
| `URL_DIGITAL` | `local_config.php` → `s` | URL digital server |
| `CICD` | `local_config.php` → `cicd` | Identity hash header |
| `MFTFI` | Nama file cache | Kode mitra transfer |
| `KODE_AGEN` | Nama file cache | `A-000268` format |
| `CACHE_DIR` | `assist_root/storage/cds/cache` | Direktori token cache |
| `OAUTH_*` | `.assist.env` | Credentials OAuth |
| `DE061_SIM_SERIAL` | `.assist.env` | SIM serial agen |

---

## Setup `.assist.env`

> 🔑 **Satu-satunya yang perlu disiapkan manual** — credentials OAuth yang ada di database SIS.

Buat file `.assist.env` di direktori yang sama dengan script PHP (atau 1–2 level di atasnya):

```env
# OAuth Credentials untuk SIS/Assist Switching Middleware
# Minta dari administrator SIS/Assist saat onboarding agen

OAUTH_CLIENT_ID=isi_client_id_anda
OAUTH_CLIENT_SECRET=isi_client_secret_anda
OAUTH_USERNAME=username_H2H_agen
OAUTH_PASSWORD=password_H2H_agen
DE061_SIM_SERIAL=nomor_sim_serial_agen
```

**Lokasi yang dicari script (urutan prioritas):**
1. `{direktori_script}/.assist.env`
2. `{direktori_script}/../.assist.env`
3. `{direktori_script}/../../.assist.env`
4. `{direktori_script}/assist.env` (tanpa titik)

**Cara mendapatkan nilai:**

| Key | Cara Mendapatkan |
|-----|-----------------|
| `OAUTH_CLIENT_ID` | Administrator SIS/Assist saat onboarding |
| `OAUTH_CLIENT_SECRET` | Administrator SIS/Assist saat onboarding |
| `OAUTH_USERNAME` | Kolom `UserH2H` di tabel `agen` database SIS |
| `OAUTH_PASSWORD` | Password H2H dari administrator |
| `DE061_SIM_SERIAL` | Kolom `SIMSerial` di tabel `agen_fitur` database SIS |

---

## Cara Penggunaan — `inquiry_transfer_permata.php`

### Fungsi
Melakukan inquiry rekening tujuan untuk transfer dana antar bank melalui:
- **TFDANA** — Transfer Dana Online (SKN Real-Time / Online)
- **LLG** — Lalu Lintas Giro (SKN Batch, biasanya < Rp 100 juta)
- **RTGS** — Real-Time Gross Settlement (biasanya > Rp 100 juta)

### Cara Akses via Browser

```
http://localhost:3000/inquiry_transfer_permata.php
```

Tanpa parameter → tampil **form input HTML** interaktif.

### Parameter Input (Form / GET / POST)

| Parameter | Wajib | Contoh | Keterangan |
|-----------|-------|--------|------------|
| `nomor_rekening` | ✅ | `1234567890` | Nomor rekening tujuan |
| `kode_bank` | ✅ | `014` | Kode bank tujuan (BCA=014, Mandiri=008, BNI=009, BRI=002) |
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
| `identity` | `db96e3cb...855d` | CICD hash (auto-detect dari `local_config.php`) |
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
{assist_root}/storage/cds/cache/
├── cds-auth-a-{KodeAgen}_{mftfi}-snap_tf_bank_permata.cache   ← Transfer Dana (TF)
└── cds-auth-a-{KodeAgen}_{mftfi}-snap_ft_bdi.cache            ← BIFAST
```

**Contoh nama file nyata:**
```
cds-auth-a-000268_0017-snap_tf_bank_permata.cache   (KodeAgen=A-000268, mftfi=0017)
cds-auth-a-000300_0024-snap_ft_bdi.cache            (KodeAgen=A-000300, mftfi=0024)
```

> ℹ️ Nama file ini juga menjadi sumber auto-detection untuk `KODE_AGEN` dan `MFTFI`.

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
rm {assist_root}/storage/cds/cache/cds-auth-a-*-snap_tf_bank_permata.cache

# Hapus cache BIFAST
rm {assist_root}/storage/cds/cache/cds-auth-a-*-snap_ft_bdi.cache
```

---

## Debug Panel & Auto-Detection Report

Saat `DEBUG_MODE = true` (default), tersedia **2 panel** di halaman:

### 🔍 Auto-Detection Report
Panel pertama menampilkan hasil deteksi otomatis:

```
Auto-Detection Report
──────────────────────────────────────────────
assist_root   : /var/www/html/assist-switching_v3_pro  ✅
local_config  : 10 keys parsed  ✅
Cache TF      : cds-auth-a-000268_0017-snap_tf_bank_permata.cache
                → KodeAgen = A-000268, mftfi = 0017  ✅
.assist.env   : Loaded from /var/www/html/.assist.env
                → 5 keys (CLIENT_ID, SECRET, USERNAME, PASSWORD, DE061)  ✅
──────────────────────────────────────────────
⚠️  Fallback values used (jika ada yang tidak terdeteksi)
```

### ⚙️ Konfigurasi Aktif (Config Grid)
Menampilkan semua nilai yang aktif digunakan:

| Item | Nilai |
|------|-------|
| URL Token | `http://myassist.sis1.net/...` |
| URL Digital | `http://digital.sis1.net/...` |
| CICD / Identity | `db96e3cb...` |
| KODE_AGEN | `A-000268` |
| MFTFI | `0017` |
| Cache File | `cds-auth-a-000268_0017-snap_tf_bank_permata.cache` |
| OAuth Credentials | `✅ Loaded from .assist.env` |

---

## Kode Referensi DE048

Format: `{part1}*{part2}*{TrxCode}~~{SubCode}`

| TrxCode | SubCode | Part1 | Part2 | Fungsi |
|---------|---------|-------|-------|--------|
| `INQTFDANA` | `BLTRFAG` | `0601` | `1001` | Inquiry Transfer Dana Online |
| `INQLLG` | `BLTRFAG` | `0201` | `1001` | Inquiry LLG (SKN Batch) |
| `INQRTGS` | `BLTRFAG` | `0801` | `1001` | Inquiry RTGS |
| `INQBIFAST` | `BLTRFAG` | `0601` | `1001` | Inquiry BIFAST |

---

## Troubleshooting

### ❌ Auto-Detection gagal — assist_root tidak ditemukan

```
Penyebab : Script tidak diletakkan di dalam atau dekat direktori assist-switching_v3_pro
Solusi   : Letakkan script PHP di:
           - Dalam direktori assist-switching_v3_pro/
           - Atau 1–5 level di atasnya (script naik max 6 level mencari marker)
           - Contoh: /var/www/html/inquiry_transfer_permata.php
             jika assist ada di /var/www/html/assist-switching_v3_pro/
```

### ❌ `.assist.env` tidak ditemukan

```
Penyebab : File .assist.env belum dibuat atau berada di lokasi yang salah
Solusi   : Buat file .assist.env di direktori script atau 1–2 level di atasnya
           Isi dengan OAuth credentials dari administrator SIS
           (lihat seksi "Setup .assist.env" di atas)
```

### ❌ KODE_AGEN kosong — file cache belum ada

```
Penyebab : storage/cds/cache/ kosong (belum pernah ada transaksi)
Efek     : KODE_AGEN dan MFTFI tidak terdeteksi dari cache
Solusi   : Set manual di .assist.env:
           KODE_AGEN=A-000268
           MFTFI=0017
           (script akan membaca dari .assist.env sebagai fallback)
```

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

### ❌ Permission denied saat menyimpan cache

```bash
# Buat direktori cache dan beri permission
mkdir -p storage/cds/cache
chmod 755 storage/cds/cache
```

---

## Referensi Source Code

Script ini diimplementasikan berdasarkan analisis source code:

| File | Keterangan |
|------|------------|
| `assist-switching_v3_pro/config/local_config.php` | URL endpoints, cicd hash, mftfi |
| `assist-switching_v3_pro/mvc/mbanking/mbanking.controller.php` | `ProsesInquiryPayment()`, signing pattern, DE field mapping, BIFAST routing |
| `assist-switching_v3_pro/include/func.oauth.mod.php` | OAuth token management, CekAccessToken, GetAccessToken |
| `assist-switching_v3_pro/storage/cds/cache/*.cache` | Pola penamaan file cache CDS → sumber auto-detection KODE_AGEN & MFTFI |

**Versi referensi:** `assist-switching_v3_pro` v1.6.39 "Assist Pro Net"

---

## Deployment Info

- **GitHub**: https://github.com/pt-zenity/permatasnap
- **Platform**: PHP 8.4.21 (kompatibel PHP 8.1+)
- **Status**: ✅ Active — syntax valid, HTTP 200
- **Last Updated**: 2026-06-13
- **Konfigurasi**: ⚡ Auto-detection (tidak perlu edit hardcode)
