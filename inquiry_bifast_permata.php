<?php
/**
 * Inquiry Transfer Bank BIFAST via Permata (SIS/Assist Switching Middleware)
 * ===========================================================================
 * Script single-file PHP untuk melakukan inquiry BIFAST (Bank Indonesia Fast
 * Payment) melalui jalur SIS/Assist switching middleware (assist-switching_v3_pro).
 *
 * AUTO-DETECTION dari source code assist-switching_v3_pro & assist-bpr.net:
 *   ✅ URL_GET_TOKEN, URL_DIGITAL, CICD, MFTFI → dari config/local_config.php
 *   ✅ KODE_AGEN, MFTFI BIFAST → dari nama file storage/cds/cache/*snap_ft_bdi.cache
 *   ✅ OAuth credentials (auto-detect, urutan prioritas):
 *      1. File .assist.env (format KEY=VALUE):
 *           OAUTH_CLIENT_ID=xxxx
 *           OAUTH_CLIENT_SECRET=xxxx
 *           OAUTH_USERNAME=xxxx       ← UserH2H di tabel agen
 *           OAUTH_PASSWORD=xxxx
 *           DE061_SIM_SERIAL=xxxx     ← opsional
 *      2. assist-bpr.net/env/{kode}_.env (format key = value, spasi sekitar =):
 *           auth_client_id = xxxx     → OAUTH_CLIENT_ID
 *           auth_client_secret = xxxx → OAUTH_CLIENT_SECRET
 *           username = Assist         → OAUTH_USERNAME (default H2H)
 *           password = Irac           → OAUTH_PASSWORD (default H2H)
 *           auth_server_uri = https://one.myassist.id
 *
 * Alur transaksi (sesuai source code mbanking.controller.php):
 *   1. Ambil OAuth access token dari myassist.sis1.net
 *      Cache file: cds-auth-a-{KodeAgen}_{mftfi}-snap_ft_bdi.cache
 *   2. Bangun pesan ISO 8583-like JSON (MTI="010", MSG dengan DE fields)
 *      DE048 berisi kode INQBIFAST (strpos(json_encode($cRequest),"BIFAST") > 0)
 *   3. Kirim POST ke digital.sis1.net/assist-digital.net/public/dgl
 *      Header: authorization=SHA256("cCode={json}".timestamp), identity=cicd
 *   4. Response di-parse dari ISO 8583 array → MBankingFunc::ISO2Array
 *
 * Referensi: assist-switching_v3_pro v1.6.39 "Assist Pro Net"
 *   - config/local_config.php            → URL, cicd, mftfi
 *   - mvc/mbanking/mbanking.controller.php → ProsesInquiryPayment() + INQBIFAST
 *   - storage/cds/cache/*snap_ft_bdi.cache → token cache pattern + KODE_AGEN
 *   - include/func.oauth.mod.php          → OAuth token management
 *
 * PHP 8.1+
 */

date_default_timezone_set('Asia/Jakarta');

// ============================================================
// AUTO-DETECTION ENGINE
// ============================================================

/**
 * Cari direktori root assist-switching_v3_pro secara otomatis.
 */
function detectAssistRoot(): string {
    $markers = ['config/local_config.php', 'storage/cds/cache', 'sisproject/project.json'];
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $found = 0;
        foreach ($markers as $m) {
            if (file_exists($dir . '/' . $m)) $found++;
        }
        if ($found >= 2) return $dir;
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return '';
}

/**
 * Parse local_config.php menggunakan regex (tanpa require/eval).
 */
function parseLocalConfig(string $assistRoot): array {
    $cfgFile = $assistRoot . '/config/local_config.php';
    if (!file_exists($cfgFile)) return [];

    $src  = file_get_contents($cfgFile);
    $vals = [];

    preg_match_all(
        '/\$config\[[\'"]([\w_]+)[\'"]\]\s*=\s*["\']([^"\']*)["\']/',
        $src, $matches, PREG_SET_ORDER
    );
    foreach ($matches as $m) {
        $vals[$m[1]] = $m[2];
    }

    // Resolve concatenation: $config['X'] = $config['Y'] . "/suffix"
    preg_match_all(
        '/\$config\[[\'"]([\w_]+)[\'"]\]\s*=\s*\$config\[[\'"]([\w_]+)[\'"]\]\s*\.\s*["\']([^"\']*)["\']/',
        $src, $concatMatches, PREG_SET_ORDER
    );
    foreach ($concatMatches as $m) {
        $base = $vals[$m[2]] ?? '';
        $vals[$m[1]] = $base . $m[3];
    }

    return $vals;
}

/**
 * Deteksi KODE_AGEN dan MFTFI dari nama file cache snap_ft_bdi (BIFAST).
 * Pattern: cds-auth-a-{KodeAgen}_{mftfi}-snap_ft_bdi.cache
 * Contoh: cds-auth-a-000300_0024-snap_ft_bdi.cache
 *   → KodeAgen = "A-000300", mftfi = "0024"
 */
function detectFromCacheBIFAST(string $assistRoot): array {
    $cacheDir = $assistRoot . '/storage/cds/cache';
    if (!is_dir($cacheDir)) return ['kode_agen' => '', 'mftfi' => ''];

    $files = glob($cacheDir . '/cds-auth-a-*-snap_ft_bdi.cache');
    if (empty($files)) return ['kode_agen' => '', 'mftfi' => ''];

    // Ambil file terbaru
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $fname = basename($files[0]);

    if (preg_match('/^cds-auth-a-(\d+)_(\w+)-snap_ft_bdi\.cache$/', $fname, $m)) {
        return [
            'kode_agen' => 'A-' . $m[1],
            'mftfi'     => $m[2],
            'raw_kode'  => $m[1],
        ];
    }

    return ['kode_agen' => '', 'mftfi' => ''];
}

/**
 * Baca OAuth credentials dari file .assist.env.
 */
function loadAssistEnv(): array {
    $candidates = [
        __DIR__ . '/.assist.env',
        dirname(__DIR__) . '/.assist.env',
        dirname(dirname(__DIR__)) . '/.assist.env',
        __DIR__ . '/assist.env',
        dirname(__DIR__) . '/assist.env',
    ];

    foreach ($candidates as $path) {
        if (!file_exists($path)) continue;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env   = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $env[trim($key)] = trim($val, " \t\"'");
        }
        if (!empty($env)) return array_merge($env, ['_env_file' => $path]);
    }
    return [];
}

/**
 * Cari direktori root assist-bpr.net secara otomatis.
 * Markers: direktori env/ berisi file *.env + bin/connect.php
 * Pencarian dari __DIR__ ke atas, maks 8 level.
 */
function detectAssistBprRoot(): string {
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_dir($dir . '/env') && file_exists($dir . '/bin/connect.php')) {
            $envFiles = glob($dir . '/env/*.env') ?: [];
            if (!empty($envFiles)) return $dir;
        }
        // Cek juga subdirektori bernama assist-bpr.net atau assist-bpr
        foreach (['assist-bpr.net', 'assist-bpr', 'assistbpr'] as $sub) {
            $candidate = $dir . '/' . $sub;
            if (is_dir($candidate . '/env') && file_exists($candidate . '/bin/connect.php')) {
                $envFiles = glob($candidate . '/env/*.env') ?: [];
                if (!empty($envFiles)) return $candidate;
            }
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return '';
}

/**
 * Baca OAuth credentials dari assist-bpr.net/env/{kode}_.env
 *
 * Format file: key = value  (dengan spasi di sekitar tanda =)
 * Contoh:
 *   auth_client_id = 000143
 *   auth_client_secret = 53f2cb55aa3a3ea5f7f523a2a22cc36...
 *   username = Assist
 *   password = Irac
 *   auth_server_uri = https://one.myassist.id/
 *   base_url_auth = https://auth.myassist.id/
 *
 * Mapping ke konstanta standar:
 *   auth_client_id     → OAUTH_CLIENT_ID
 *   auth_client_secret → OAUTH_CLIENT_SECRET
 *   username           → OAUTH_USERNAME
 *   password           → OAUTH_PASSWORD
 *
 * @param string $assistBprRoot  Path root assist-bpr.net (dari detectAssistBprRoot())
 * @param string $rawKode        Kode numerik agen, mis. "000268" (dari cache filename)
 */
function detectFromAssistBprEnv(string $assistBprRoot, string $rawKode = ''): array {
    $envDir = $assistBprRoot . '/env';
    if (!is_dir($envDir)) return [];

    // Daftar kandidat file: kode spesifik dulu, lalu semua file numerik
    $candidates = [];
    if (!empty($rawKode)) {
        $candidates[] = $envDir . '/' . $rawKode . '_.env';
        $noLeading = ltrim($rawKode, '0');
        if ($noLeading !== $rawKode && $noLeading !== '') {
            $candidates[] = $envDir . '/' . $noLeading . '_.env';
        }
    }
    // Fallback: semua file env numerik, diurutkan (terbaru dulu)
    $allNumericEnv = glob($envDir . '/[0-9]*.env') ?: [];
    usort($allNumericEnv, fn($a, $b) => filemtime($b) - filemtime($a));
    $candidates = array_merge($candidates, $allNumericEnv);
    $candidates = array_unique($candidates);

    foreach ($candidates as $path) {
        if (!file_exists($path)) continue;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) continue;
        $raw = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $raw[trim($k)] = trim($v);
        }
        // File valid harus punya auth_client_id atau OAUTH_CLIENT_ID
        if (empty($raw['auth_client_id']) && empty($raw['OAUTH_CLIENT_ID'])) continue;

        $oauthServerUri = rtrim(
            $raw['auth_server_uri'] ?? $raw['base_url_auth'] ?? '',
            '/'
        );

        return [
            'OAUTH_CLIENT_ID'     => $raw['auth_client_id']    ?? $raw['OAUTH_CLIENT_ID'] ?? '',
            'OAUTH_CLIENT_SECRET' => $raw['auth_client_secret'] ?? '',
            'OAUTH_USERNAME'      => $raw['username']           ?? '',
            'OAUTH_PASSWORD'      => $raw['password']           ?? '',
            'OAUTH_SERVER_URI'    => $oauthServerUri,
            '_env_file'           => $path,
            '_source'             => 'assist-bpr.net/env',
        ];
    }
    return [];
}

// ────────────────────────────────────────────────────────────
// Jalankan auto-detection
// ────────────────────────────────────────────────────────────
$_ASSIST_ROOT   = detectAssistRoot();
$_LOCAL_CONFIG  = !empty($_ASSIST_ROOT) ? parseLocalConfig($_ASSIST_ROOT) : [];
$_CACHE_BF      = !empty($_ASSIST_ROOT) ? detectFromCacheBIFAST($_ASSIST_ROOT) : [];
$_ASSIST_ENV    = loadAssistEnv();

// Auto-detect assist-bpr.net (sumber OAuth tambahan)
$_ASSIST_BPR_ROOT = detectAssistBprRoot();
$_RAW_KODE_BF     = $_CACHE_BF['raw_kode'] ?? '';
$_BPR_ENV         = !empty($_ASSIST_BPR_ROOT)
    ? detectFromAssistBprEnv($_ASSIST_BPR_ROOT, $_RAW_KODE_BF)
    : [];

$_DETECTION_LOG = [];

// ── URLs ─────────────────────────────────────────────────────
$_myAssistBase  = $_LOCAL_CONFIG['_URL_MY_ASSIST_'] ?? 'http://myassist.sis1.net/assist-auth_api/public';
$_urlGetToken   = $_LOCAL_CONFIG['_URL_GET_TOKEN_'] ?? ($_myAssistBase . '/oauth/getaccesstoken');
$_urlCekToken   = $_LOCAL_CONFIG['_URL_CEK_TOKEN_'] ?? ($_myAssistBase . '/oauth/cekaccesstoken');
$_urlRfzToken   = $_LOCAL_CONFIG['_URL_RFZ_TOKEN_'] ?? ($_myAssistBase . '/oauth/getaccesstokenfromrefresh');
$_urlDigital    = $_LOCAL_CONFIG['s']               ?? 'http://digital.sis1.net/assist-digital.net/public/dgl';
$_urlDigitalSS  = $_LOCAL_CONFIG['ss']              ?? 'http://digital.sis1.net/assist-digital.net/public/rgol';
$_cicd          = $_LOCAL_CONFIG['cicd']            ?? 'db96e3cba196f76a6c31e4c9625614b3dc57619fba7e29ee534dd20c5c44855d';
$_mftfi         = $_CACHE_BF['mftfi']               ?? ($_LOCAL_CONFIG['mftfi'] ?? '002');

$_DETECTION_LOG['assist_root']    = $_ASSIST_ROOT ?: '— tidak ditemukan (fallback ke default)';
$_DETECTION_LOG['local_config']   = !empty($_LOCAL_CONFIG) ? '✅ Terbaca (' . count($_LOCAL_CONFIG) . ' keys)' : '⚠️ Tidak ditemukan';
$_DETECTION_LOG['url_get_token']  = empty($_LOCAL_CONFIG['_URL_GET_TOKEN_']) ? '⚠️ fallback default' : '✅ dari local_config.php';
$_DETECTION_LOG['url_digital']    = empty($_LOCAL_CONFIG['s'])               ? '⚠️ fallback default' : '✅ dari local_config.php';
$_DETECTION_LOG['cicd']           = empty($_LOCAL_CONFIG['cicd'])            ? '⚠️ fallback default' : '✅ dari local_config.php';

// ── KODE_AGEN + MFTFI BIFAST (dari cache file snap_ft_bdi) ───
$_kodeAgen       = $_CACHE_BF['kode_agen'] ?? '';
$_mftfiFromCache = !empty($_CACHE_BF['mftfi']);

$_DETECTION_LOG['kode_agen_bifast'] = !empty($_kodeAgen) ? '✅ auto dari cache: ' . $_kodeAgen : '⚠️ tidak ditemukan (snap_ft_bdi.cache)';
$_DETECTION_LOG['mftfi_bifast']     = $_mftfiFromCache    ? '✅ auto dari cache: ' . $_mftfi   : '⚠️ fallback dari local_config/default';

// ── OAuth Credentials (prioritas: .assist.env > assist-bpr.net/env/) ──────
// Sumber 1: .assist.env (format KEY=VALUE)
$_oauthClientId     = $_ASSIST_ENV['OAUTH_CLIENT_ID']     ?? '';
$_oauthClientSecret = $_ASSIST_ENV['OAUTH_CLIENT_SECRET'] ?? '';
$_oauthUsername     = $_ASSIST_ENV['OAUTH_USERNAME']      ?? '';
$_oauthPassword     = $_ASSIST_ENV['OAUTH_PASSWORD']      ?? '';
$_de061             = $_ASSIST_ENV['DE061_SIM_SERIAL']     ?? '';
$_oauthSource       = !empty($_oauthClientId) ? '.assist.env' : '';

// Sumber 2: assist-bpr.net/env/{kode}_.env — fallback jika .assist.env kosong
if (empty($_oauthClientId) && !empty($_BPR_ENV['OAUTH_CLIENT_ID'])) {
    $_oauthClientId     = $_BPR_ENV['OAUTH_CLIENT_ID'];
    $_oauthClientSecret = $_BPR_ENV['OAUTH_CLIENT_SECRET'] ?? '';
    $_oauthUsername     = $_BPR_ENV['OAUTH_USERNAME']      ?? '';
    $_oauthPassword     = $_BPR_ENV['OAUTH_PASSWORD']      ?? '';
    $_oauthSource       = 'assist-bpr.net/env';
} elseif (empty($_oauthClientSecret) && !empty($_BPR_ENV['OAUTH_CLIENT_SECRET'])) {
    // Partial fill: client_id ada di .assist.env tapi secret kosong → ambil dari bpr
    $_oauthClientSecret = $_BPR_ENV['OAUTH_CLIENT_SECRET'];
    if (empty($_oauthUsername)) $_oauthUsername = $_BPR_ENV['OAUTH_USERNAME'] ?? '';
    if (empty($_oauthPassword)) $_oauthPassword = $_BPR_ENV['OAUTH_PASSWORD'] ?? '';
    $_oauthSource .= '+assist-bpr.net/env';
}

$_envFile = !empty($_oauthSource) && str_contains($_oauthSource, 'bpr')
    ? ($_BPR_ENV['_env_file'] ?? ($_ASSIST_ENV['_env_file'] ?? ''))
    : ($_ASSIST_ENV['_env_file'] ?? '');

$_DETECTION_LOG['assist_bpr_root']  = !empty($_ASSIST_BPR_ROOT) ? '✅ ' . $_ASSIST_BPR_ROOT : '— tidak ditemukan';
$_DETECTION_LOG['oauth_env_file']   = !empty($_envFile)         ? '✅ ' . $_envFile          : '⚠️ env tidak ditemukan';
$_DETECTION_LOG['oauth_source']     = !empty($_oauthSource)     ? '✅ ' . $_oauthSource      : '❌ tidak ada sumber OAuth';
$_DETECTION_LOG['oauth_client_id']  = !empty($_oauthClientId)   ? '✅ terisi (' . $_oauthSource . ')' : '❌ belum diisi';
$_DETECTION_LOG['oauth_username']   = !empty($_oauthUsername)   ? '✅ terisi (' . $_oauthSource . ')' : '❌ belum diisi';
$_DETECTION_LOG['de061_sim_serial'] = !empty($_de061)           ? '✅ terisi dari .assist.env' : '⚠️ kosong';

// ============================================================
// DEFINISIKAN KONSTANTA (dari hasil auto-detection)
// ============================================================

define('URL_GET_TOKEN',  $_urlGetToken);
define('URL_CEK_TOKEN',  $_urlCekToken);
define('URL_RFZ_TOKEN',  $_urlRfzToken);
define('URL_DIGITAL',    $_urlDigital);
define('URL_DIGITAL_SS', $_urlDigitalSS);
define('CICD',           $_cicd);
define('MFTFI',          $_mftfi);
define('KODE_AGEN',      $_kodeAgen);
define('OAUTH_CLIENT_ID',     $_oauthClientId);
define('OAUTH_CLIENT_SECRET', $_oauthClientSecret);
define('OAUTH_USERNAME',      $_oauthUsername);
define('OAUTH_PASSWORD',      $_oauthPassword);
define('DE061_SIM_SERIAL',    $_de061);

// ── Token Cache BIFAST (CDS pattern) ─────────────────────────
$_cacheDir = !empty($_ASSIST_ROOT)
    ? $_ASSIST_ROOT . '/storage/cds/cache'
    : __DIR__ . '/storage/cds/cache';

$_rawKode = $_CACHE_BF['raw_kode'] ?? str_replace('A-', '', KODE_AGEN);

define('CACHE_DIR',         $_cacheDir);
define('CACHE_FILE_BIFAST', CACHE_DIR . '/cds-auth-a-' . $_rawKode . '_' . MFTFI . '-snap_ft_bdi.cache');

// ── Opsi ────────────────────────────────────────────────────
define('CURL_TIMEOUT', 30);
define('DEBUG_MODE',   true);

// Maksimum nominal BIFAST: Rp 200 juta per transaksi
define('BIFAST_MAX_AMOUNT', 200000000);

// Bersihkan variabel sementara
unset($_myAssistBase, $_urlGetToken, $_urlCekToken, $_urlRfzToken,
      $_urlDigital, $_urlDigitalSS, $_cicd, $_mftfi, $_mftfiFromCache,
      $_kodeAgen, $_rawKode, $_cacheDir, $_envFile, $_oauthSource,
      $_oauthClientId, $_oauthClientSecret, $_oauthUsername, $_oauthPassword, $_de061,
      $_ASSIST_BPR_ROOT, $_RAW_KODE_BF, $_BPR_ENV);

// ============================================================
// FUNGSI HELPER
// ============================================================

/**
 * SNow() — timestamp fungsi sesuai source code Assist
 */
function SNow(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Buat direktori cache jika belum ada
 */
function ensureCacheDir(): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
}

/**
 * Simpan token ke cache file BIFAST (CDS pattern)
 * Cache file: cds-auth-a-{KodeAgen}_{mftfi}-snap_ft_bdi.cache
 */
function saveTokenCache(array $tokenData): void {
    ensureCacheDir();
    $cache = [
        'access_token'  => $tokenData['access_token']  ?? '',
        'refresh_token' => $tokenData['refresh_token'] ?? '',
        'expires_in'    => $tokenData['expires_in']    ?? 3600,
        'cached_at'     => time(),
        'token_type'    => $tokenData['token_type']    ?? 'Bearer',
    ];
    @file_put_contents(CACHE_FILE_BIFAST, json_encode($cache));
}

/**
 * Baca token dari cache file BIFAST
 * Return null jika tidak ada / expired
 */
function readTokenCache(): ?string {
    if (!file_exists(CACHE_FILE_BIFAST)) return null;
    $raw   = @file_get_contents(CACHE_FILE_BIFAST);
    if (!$raw) return null;
    $cache = json_decode($raw, true);
    if (empty($cache['access_token'])) return null;
    $expiresAt = ($cache['cached_at'] ?? 0) + ($cache['expires_in'] ?? 3600) - 60;
    if (time() > $expiresAt) return null;
    return $cache['access_token'];
}

/**
 * HTTP POST menggunakan cURL (form-urlencoded atau JSON)
 */
function sendHttpPost(string $url, string $body, array $headers): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $t0   = microtime(true);
    $raw  = curl_exec($ch);
    $ms   = round((microtime(true) - $t0) * 1000);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http_code'  => $code,
        'raw'        => $raw ?? '',
        'data'       => json_decode($raw ?? '', true) ?? [],
        'curl_error' => $err,
        'elapsed_ms' => $ms,
        'url'        => $url,
        'body_sent'  => $body,
        'headers'    => $headers,
    ];
}

/**
 * Ambil OAuth Access Token dari myassist.sis1.net
 * Implementasi sesuai func.oauth.mod.php
 */
function getAccessToken(): array {
    // 1. Coba dari cache
    $cached = readTokenCache();
    if ($cached) {
        return ['success' => true, 'token' => $cached, 'from_cache' => true];
    }

    // 2. Request token baru (grant_type=password)
    $body = http_build_query([
        'grant_type'    => 'password',
        'username'      => OAUTH_USERNAME,
        'password'      => OAUTH_PASSWORD,
        'client_id'     => OAUTH_CLIENT_ID,
        'client_secret' => OAUTH_CLIENT_SECRET,
    ]);

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ];

    $result = sendHttpPost(URL_GET_TOKEN, $body, $headers);

    if (!empty($result['curl_error'])) {
        return ['success' => false, 'error' => 'cURL: ' . $result['curl_error'], 'raw' => $result];
    }

    $data = $result['data'];

    if (!empty($data['access_token'])) {
        saveTokenCache($data);
        return ['success' => true, 'token' => $data['access_token'], 'from_cache' => false, 'raw' => $result];
    }

    // Fallback: client_credentials
    if ($result['http_code'] !== 200) {
        $body2 = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => OAUTH_CLIENT_ID,
            'client_secret' => OAUTH_CLIENT_SECRET,
        ]);
        $result2 = sendHttpPost(URL_GET_TOKEN, $body2, $headers);
        $data2   = $result2['data'];
        if (!empty($data2['access_token'])) {
            saveTokenCache($data2);
            return ['success' => true, 'token' => $data2['access_token'], 'from_cache' => false, 'raw' => $result2];
        }
    }

    $errMsg = $data['error_description'] ?? $data['message'] ?? ('HTTP ' . $result['http_code']);
    return ['success' => false, 'error' => $errMsg, 'raw' => $result];
}

/**
 * Bangun DE048 untuk BIFAST
 *
 * Dari source code (mbanking.controller.php line 916-917):
 *   $allowedTransactions = ["INQTFDANA", "INQBIFAST"];
 *   strpos(json_encode($cRequest),"BIFAST") > 0
 *
 * Format DE048 sesuai pola Assist: "{part1}*{part2}*{TrxCode}~~{SubCode}"
 *   INQBIFAST: "0601*1001*INQBIFAST~~BLTRFAG"
 *
 * DE048 ini digunakan untuk routing oleh Assist digital server:
 *   - Jika mengandung "BIFAST" → masuk ke PermataSNAP::GetTFINQ atau SNAPDanamon
 *   - Kemudian route berdasarkan setting DanamonSNAPTF / PermataSNAPTF agen
 */
function buildDE048BIFAST(): string {
    return '0601*1001*INQBIFAST~~BLTRFAG';
}

/**
 * Bangun request ISO 8583-like JSON untuk BIFAST inquiry
 *
 * Field mapping sesuai source code (mbanking.controller.php):
 *   DE003  = "231041"   processing code inquiry
 *   DE004  = nominal 12 digit
 *   DE012  = local time HHmm
 *   DE013  = local date ddMM
 *   DE037  = retrieval reference number 12 digit
 *   DE044  = "0"
 *   DE048  = "0601*1001*INQBIFAST~~BLTRFAG"
 *   DE052  = 64 zero (PIN block untuk inquiry)
 *   DE061  = SIMSerial / device identifier agen
 *   DE102  = nomor rekening / proxy tujuan
 *   DE103  = kode bank tujuan (kode numerik atau BIC)
 *
 * MTI = "010" (DIGITAL_BANK_INQUIRY)
 */
function buildISO8583RequestBIFAST(array $params): array {
    $nomorTujuan = preg_replace('/\D/', '', $params['nomor_rekening'] ?? '');
    $proxyType   = $params['proxy_type'] ?? 'ACCOUNT_NUMBER';
    $proxyValue  = $params['proxy_value'] ?? $nomorTujuan;
    $kodeBank    = $params['kode_bank']   ?? '';
    $nominal     = (int)preg_replace('/\D/', '', $params['nominal'] ?? '0');

    // Batasi nominal BIFAST
    if ($nominal > BIFAST_MAX_AMOUNT) {
        $nominal = BIFAST_MAX_AMOUNT;
    }

    // DE004: nominal 12 digit (10 digit + 2 desimal)
    $de004 = str_pad($nominal, 10, '0', STR_PAD_LEFT) . '00';

    // DE012: HHmm
    $de012 = date('Hi');

    // DE013: ddMM
    $de013 = date('dm');

    // DE037: retrieval reference number
    $de037 = date('His') . str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

    // DE048: kode transaksi BIFAST
    $de048 = buildDE048BIFAST();

    // DE052: PIN block (64 zero)
    $de052 = str_repeat('0', 64);

    // DE061: SIM Serial agen
    $de061 = DE061_SIM_SERIAL;

    // DE102: nomor rekening atau proxy (HP/email/VA sesuai proxy_type)
    // Untuk BIFAST bisa berupa nomor rekening, nomor HP, email, atau VA
    $de102 = !empty($proxyValue) ? $proxyValue : $nomorTujuan;

    // DE103: kode bank tujuan
    $de103 = $kodeBank;

    $msg = [
        'DE003' => '231041',
        'DE004' => $de004,
        'DE012' => $de012,
        'DE013' => $de013,
        'DE037' => $de037,
        'DE044' => '0',
        'DE048' => $de048,
        'DE052' => $de052,
        'DE061' => $de061,
        'DE102' => $de102,
        'DE103' => $de103,
    ];

    // Tambahkan informasi proxy type jika bukan ACCOUNT_NUMBER
    // Ini mungkin digunakan oleh PermataSNAP::GetTFINQ untuk routing proxy BIFAST
    if ($proxyType !== 'ACCOUNT_NUMBER') {
        $msg['ProxyType']  = $proxyType;
        $msg['ProxyValue'] = $proxyValue;
    }

    return [
        'MTI' => '010',     // DIGITAL_BANK_INQUIRY
        'MSG' => $msg,
    ];
}

/**
 * Kirim request ISO 8583 ke digital.sis1.net
 *
 * Signing sesuai source code:
 *   $cB  = json_encode($isoRequest);
 *   $cM  = "cCode=" . $cB;
 *   auth = hash('sha256', $cM . SNow())
 *
 * PENTING: SHA256 dihitung atas seluruh body string ("cCode=...") + timestamp,
 *          bukan hanya JSON payload saja.
 */
function sendBIFASTInquiryRequest(array $isoRequest, string $accessToken): array {
    $cB  = json_encode($isoRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $cM  = 'cCode=' . $cB;
    $cU  = URL_DIGITAL;
    $dNs = SNow();

    // Authorization = SHA256(full_body_string . datetime)
    // Sesuai: hash('sha256', $cM . SNow())
    $authorization = hash('sha256', $cM . $dNs);

    $headers = [
        'authorization: ' . $authorization,
        'identity: '      . CICD,
        'datetime: '      . $dNs,
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Bearer ' . $accessToken,
    ];

    $result = sendHttpPost($cU, $cM, $headers);
    $result['iso_request'] = $isoRequest;
    $result['body_signed'] = $cM;
    $result['datetime']    = $dNs;
    $result['sha256_auth'] = $authorization;

    return $result;
}

/**
 * Parse response dari digital server (ISO2Array)
 *
 * Sesuai source:
 *   $vaResponse = json_decode($cResponse, 1);
 *   $vaResponse = json_decode($vaResponse["data"], 1);  // unwrap envelope
 *   $vaResponse = MBankingFunc::ISO2Array($cResponse);
 *
 * Response berhasil (RC=00):
 *   MSG mengandung tagihan (string pipe-separated atau array)
 *   Tagihan format: "NamaRekening|NomorRekening|Bank|..." atau string nama saja
 */
function parseISO8583Response(array $httpResult): array {
    $raw  = $httpResult['raw'] ?? '';
    $data = $httpResult['data'] ?? [];

    // Unwrap envelope jika ada
    if (!empty($data['data']) && is_string($data['data'])) {
        $inner = json_decode($data['data'], true);
        if (is_array($inner)) {
            $data = $inner;
        }
    }

    if (isset($data['MTI'])) {
        return $data;
    }

    return $data;
}

/**
 * Fungsi utama: Inquiry BIFAST
 */
function inquiryBIFAST(array $params): array {
    $debug = [];

    // ── Step 1: Ambil Token ────────────────────────────────────
    $tokenResult = getAccessToken();
    $debug['step1_get_token'] = $tokenResult;

    if (!$tokenResult['success']) {
        return [
            'success' => false,
            'step'    => 'get_token',
            'rc'      => 'XT',
            'message' => 'Gagal mendapatkan token: ' . ($tokenResult['error'] ?? 'Unknown'),
            'debug'   => $debug,
        ];
    }

    $accessToken = $tokenResult['token'];

    // ── Step 2: Bangun ISO 8583 Request BIFAST ─────────────────
    $isoRequest = buildISO8583RequestBIFAST($params);
    $debug['step2_iso_request'] = $isoRequest;

    // ── Step 3: Kirim ke Digital Server ───────────────────────
    $httpResult = sendBIFASTInquiryRequest($isoRequest, $accessToken);
    $debug['step3_http_result'] = $httpResult;

    // ── Step 4: Parse Response ─────────────────────────────────
    $isoResponse = parseISO8583Response($httpResult);
    $debug['step4_iso_response'] = $isoResponse;

    // Ambil RC dan MSG
    $rc      = $isoResponse['RC']  ?? $isoResponse['rc'] ?? ($httpResult['http_code'] == 200 ? '00' : 'XT');
    $msgData = $isoResponse['MSG'] ?? $isoResponse;

    // Parse tagihan / nama rekening dari MSG
    $tagihan      = '';
    $namaRekening = '-';
    $nomorKonfirm = $params['nomor_rekening'] ?? '';
    $biayaAdmin   = 0;

    if (is_string($msgData)) {
        $tagihan = $msgData;
    } elseif (is_array($msgData)) {
        $tagihan      = $msgData['DE048'] ?? $msgData['tagihan'] ?? $msgData['message'] ?? '';
        $namaRekening = $msgData['NamaRekening'] ?? '';
        $de004Raw     = $msgData['DE004'] ?? '000000000000';
        $biayaAdmin   = (int)substr($de004Raw, 0, -2);
        $nomorKonfirm = $msgData['DE102'] ?? $nomorKonfirm;
    }

    $success = in_array($rc, ['00', '19'], true);

    // Parse nama dari tagihan (pipe-separated)
    if (!empty($tagihan) && empty($namaRekening)) {
        $parts = explode('|', $tagihan);
        if (count($parts) >= 2) {
            $namaRekening = $parts[1] ?? '-';
            $nomorKonfirm = $parts[0] ?? $nomorKonfirm;
        } elseif (strlen($tagihan) > 5 && strpos($tagihan, ' ') !== false) {
            $namaRekening = $tagihan;
        }
    }

    return [
        'success'          => $success,
        'step'             => 'inquiry_bifast',
        'rc'               => $rc,
        'message'          => $tagihan ?: ($success ? 'Inquiry BIFAST berhasil' : 'Inquiry BIFAST gagal'),
        'beneficiary'      => [
            'account_no'   => $nomorKonfirm,
            'account_name' => $namaRekening ?: '-',
            'bank_code'    => $params['kode_bank']  ?? '',
            'bank_name'    => $params['nama_bank']  ?? '',
        ],
        'proxy_info'       => [
            'type'         => $params['proxy_type']  ?? 'ACCOUNT_NUMBER',
            'value'        => $params['proxy_value'] ?? $params['nomor_rekening'] ?? '',
        ],
        'biaya_admin'      => $biayaAdmin,
        'de048'            => buildDE048BIFAST(),
        'cache_file'       => basename(CACHE_FILE_BIFAST),
        'token_from_cache' => $tokenResult['from_cache'] ?? false,
        'debug'            => $debug,
    ];
}

// ============================================================
// HANDLE POST REQUEST
// ============================================================
$result       = null;
$errorMessage = '';
$formData     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inquiry_bifast') {

    $formData = [
        'nomor_rekening' => trim($_POST['nomor_rekening']  ?? ''),
        'proxy_type'     => trim($_POST['proxy_type']      ?? 'ACCOUNT_NUMBER'),
        'proxy_value'    => trim($_POST['proxy_value']     ?? ''),
        'kode_bank'      => trim($_POST['kode_bank']       ?? ''),
        'kode_bank_manual'=> trim($_POST['kode_bank_manual'] ?? ''),
        'nama_bank'      => trim($_POST['nama_bank']       ?? ''),
        'nominal'        => trim($_POST['nominal']         ?? '0'),
    ];

    // Gunakan kode bank manual jika diisi
    if (!empty($formData['kode_bank_manual'])) {
        $formData['kode_bank'] = $formData['kode_bank_manual'];
    }

    // Proxy value default ke nomor rekening jika kosong
    if (empty($formData['proxy_value'])) {
        $formData['proxy_value'] = $formData['nomor_rekening'];
    }

    // Validasi
    $pType = $formData['proxy_type'];
    if ($pType === 'ACCOUNT_NUMBER' && empty($formData['nomor_rekening'])) {
        $errorMessage = 'Nomor rekening tujuan wajib diisi!';
    } elseif ($pType === 'PHONE_NUMBER' && empty($formData['proxy_value'])) {
        $errorMessage = 'Nomor HP tujuan wajib diisi!';
    } elseif ($pType === 'EMAIL' && empty($formData['proxy_value'])) {
        $errorMessage = 'Alamat email tujuan wajib diisi!';
    } elseif (empty($formData['kode_bank'])) {
        $errorMessage = 'Kode bank tujuan wajib diisi!';
    } elseif (KODE_AGEN === '') {
        $errorMessage = '⚠️ KODE_AGEN tidak terdeteksi! Pastikan ada file cache snap_ft_bdi.cache di storage/cds/cache/ dalam direktori assist-switching_v3_pro.';
    } elseif (OAUTH_CLIENT_ID === '' && OAUTH_USERNAME === '') {
        $errorMessage = '⚠️ Kredensial OAuth belum dikonfigurasi! Buat file .assist.env dengan isi: OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, OAUTH_USERNAME, OAUTH_PASSWORD.';
    } else {
        // Cek batas nominal BIFAST
        $nominal = (int)preg_replace('/\D/', '', $formData['nominal'] ?? '0');
        if ($nominal > BIFAST_MAX_AMOUNT) {
            $errorMessage = 'Nominal melebihi batas BIFAST (Rp 200.000.000). Nilai akan disesuaikan otomatis.';
        }
        $result = inquiryBIFAST($formData);
    }
}

// ============================================================
// DATA REFERENSI
// ============================================================
// Kode bank numerik (untuk DE103)
$daftarBankNumerik = [
    ''      => '-- Pilih Bank --',
    '008'   => 'Bank Mandiri',
    '009'   => 'BNI',
    '002'   => 'BRI',
    '014'   => 'BCA',
    '013'   => 'Bank Permata',
    '022'   => 'CIMB Niaga',
    '016'   => 'Maybank',
    '011'   => 'Danamon',
    '028'   => 'OCBC NISP',
    '200'   => 'BTN',
    '019'   => 'Panin Bank',
    '023'   => 'UOB',
    '110'   => 'BJB',
    '111'   => 'Bank DKI',
    '112'   => 'BPD Jateng',
    '114'   => 'BPD Jatim',
    '116'   => 'BPD Sumut',
    '422'   => 'BSI',
    '503'   => 'Bank Jago',
    '506'   => 'SeaBank',
    '553'   => 'Bank DBS',
    '688'   => 'HSBC Indonesia',
];

// Kode bank BIC/SWIFT (jika Permata SNAP memerlukan untuk BIFAST)
$daftarBankBIC = [
    ''           => '-- Pilih Bank BIC --',
    'BMRIIDJA'   => 'Bank Mandiri',
    'BNINIDJA'   => 'BNI',
    'BRINIDJA'   => 'BRI',
    'CENAIDJA'   => 'BCA',
    'BBBAIDJA'   => 'Bank Permata',
    'BIADIDJA'   => 'CIMB Niaga',
    'MBBEIDJA'   => 'Maybank',
    'BDMNIDJA'   => 'Danamon',
    'NIBIIDJA'   => 'OCBC NISP',
    'BBTNIDJA'   => 'BTN',
    'BSYSIDJX'   => 'BSI',
    'IBBKIDJA'   => 'Bank Jago',
    'SEUBIDJX'   => 'SeaBank',
    'HANAIDIDK'  => 'Bank KEB Hana',
];

$isConfigured = (KODE_AGEN !== '' && OAUTH_CLIENT_ID !== '' && OAUTH_USERNAME !== '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inquiry BIFAST — SIS/Assist Middleware</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #064e3b 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 960px; margin: 0 auto; }

        /* ── Header ── */
        .page-header { text-align: center; color: #fff; margin-bottom: 24px; }
        .header-badge {
            display: inline-flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,.11);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 50px;
            padding: 9px 22px;
            margin-bottom: 12px;
        }
        .badge-bifast {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff; border-radius: 8px; padding: 3px 9px;
            font-size: .72rem; font-weight: 800; letter-spacing: 1px;
        }
        .badge-permata {
            background: #003d82; color: #fff;
            border-radius: 8px; padding: 3px 9px;
            font-size: .72rem; font-weight: 800;
        }
        .badge-assist {
            background: #f59e0b; color: #1c1917;
            border-radius: 8px; padding: 3px 9px;
            font-size: .72rem; font-weight: 800;
        }
        .badge-sep { color: rgba(255,255,255,.4); }
        .page-header h1 { font-size: 1.85rem; font-weight: 800; }
        .page-header p  { font-size: .93rem; opacity: .8; margin-top: 5px; max-width: 640px; margin-left: auto; margin-right: auto; }

        /* ── Feature cards ── */
        .feature-row {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
            margin-bottom: 18px;
        }
        @media (max-width: 640px) { .feature-row { grid-template-columns: 1fr; } }
        .fc {
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 12px; padding: 14px 16px;
            color: #fff; text-align: center;
        }
        .fc .fc-i  { font-size: 24px; margin-bottom: 5px; }
        .fc .fc-t  { font-size: .85rem; font-weight: 700; color: #6ee7b7; }
        .fc .fc-d  { font-size: .75rem; opacity: .65; margin-top: 3px; }

        /* ── Card ── */
        .card {
            background: #fff; border-radius: 16px;
            box-shadow: 0 8px 36px rgba(0,0,0,.22);
            overflow: hidden; margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(90deg, #065f46, #047857);
            color: #fff; padding: 15px 22px;
            display: flex; align-items: center; gap: 10px;
            font-size: .97rem; font-weight: 700;
        }
        .card-header .ch-icon {
            width: 30px; height: 30px;
            background: rgba(255,255,255,.18);
            border-radius: 7px;
            display: flex; align-items: center; justify-content: center; font-size: 17px;
        }
        .card-header.blue { background: linear-gradient(90deg, #003d82, #0057c2); }
        .card-header .ch-badge {
            margin-left: auto; background: rgba(255,255,255,.18);
            border-radius: 6px; padding: 2px 9px; font-size: .72rem; letter-spacing: .4px;
        }
        .card-body { padding: 24px; }

        /* ── Config Grid ── */
        .config-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
            margin-bottom: 16px;
        }
        @media (max-width: 640px) { .config-grid { grid-template-columns: 1fr 1fr; } }
        .cfg { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; }
        .cfg .cg-l { font-size: .71rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .cfg .cg-v { font-size: .87rem; font-weight: 600; color: #1e293b; margin-top: 3px; font-family: monospace; }
        .cfg .cg-v.ok   { color: #059669; }
        .cfg .cg-v.warn { color: #d97706; }
        .cfg .cg-v.err  { color: #dc2626; }

        /* ── Alert ── */
        .alert {
            border-radius: 10px; padding: 13px 17px;
            margin-bottom: 16px; font-size: .9rem;
            display: flex; align-items: flex-start; gap: 10px;
        }
        .alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }
        .alert-warning { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }
        .alert-info    { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .alert .ai { font-size: 17px; flex-shrink: 0; }

        /* ── Form ── */
        .section-title {
            font-size: .75rem; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            display: flex; align-items: center; gap: 8px;
            margin: 18px 0 12px;
        }
        .section-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 580px) { .form-grid { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            font-size: .82rem; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: .4px;
        }
        .form-group label .req { color: #ef4444; }
        .form-group label .opt { color: #9ca3af; font-weight: 400; font-size: .77rem; text-transform: none; }
        .form-group input,
        .form-group select {
            border: 1.5px solid #d1d5db; border-radius: 8px;
            padding: 9px 13px; font-size: .92rem; color: #111827;
            outline: none; transition: border-color .2s, box-shadow .2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,.13);
        }
        .field-hint { font-size: .74rem; color: #6b7280; }

        /* ── Proxy Type Tabs ── */
        .proxy-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        .proxy-tab {
            flex: 1; min-width: 100px; padding: 10px 8px;
            border: 2px solid #e5e7eb; border-radius: 10px;
            cursor: pointer; background: #f9fafb;
            text-align: center; transition: all .2s;
        }
        .proxy-tab input { display: none; }
        .proxy-tab .pt-i { font-size: 20px; display: block; }
        .proxy-tab .pt-l { font-size: .78rem; font-weight: 700; color: #374151; display: block; }
        .proxy-tab .pt-d { font-size: .69rem; color: #6b7280; display: block; }
        .proxy-tab:has(input:checked), .proxy-tab.active {
            border-color: #10b981; background: #ecfdf5;
        }
        .proxy-tab:has(input:checked) .pt-l, .proxy-tab.active .pt-l { color: #065f46; }

        /* ── Submit ── */
        .btn-submit {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff; border: none; border-radius: 10px;
            font-size: .97rem; font-weight: 700; cursor: pointer;
            transition: box-shadow .2s; margin-top: 6px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { box-shadow: 0 4px 20px rgba(5,150,105,.4); }
        .btn-submit:disabled { opacity: .65; cursor: not-allowed; }

        /* ── Result ── */
        .result-wrap { margin-top: 20px; }
        .result-header {
            border-radius: 12px 12px 0 0; padding: 14px 20px;
            display: flex; align-items: center; gap: 10px;
            font-weight: 800; color: #fff;
        }
        .result-header.ok   { background: linear-gradient(90deg, #047857, #10b981); }
        .result-header.fail { background: linear-gradient(90deg, #b91c1c, #dc2626); }
        .result-body {
            background: #fff; border: 1px solid #e5e7eb;
            border-top: none; border-radius: 0 0 12px 12px; padding: 22px;
        }
        .result-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        @media (max-width: 600px) { .result-grid { grid-template-columns: 1fr 1fr; } }
        .ri label {
            font-size: .71rem; font-weight: 700; color: #6b7280;
            text-transform: uppercase; letter-spacing: .5px; display: block;
        }
        .ri .val { font-size: .95rem; font-weight: 600; color: #111827; margin-top: 3px; word-break: break-all; }
        .ri .val.hl { color: #059669; font-size: 1.08rem; }
        .ri .val.code { font-family: monospace; font-size: .82rem; }
        .ri .val.tag {
            display: inline-block;
            background: #d1fae5; color: #065f46;
            border-radius: 6px; padding: 2px 8px; font-size: .82rem;
        }
        .section-sep { grid-column: 1 / -1; border: none; border-top: 1px dashed #e5e7eb; }

        /* ── Debug ── */
        .debug-wrap { margin-top: 14px; }
        .debug-btn {
            width: 100%; background: #f1f5f9; border: 1px solid #cbd5e1;
            border-radius: 8px; padding: 9px 14px;
            font-size: .83rem; font-weight: 600; color: #475569;
            cursor: pointer; text-align: left;
            display: flex; justify-content: space-between;
        }
        .debug-content {
            display: none; background: #0f172a; color: #94a3b8;
            padding: 16px; border-radius: 0 0 8px 8px;
            font-family: monospace; font-size: .76rem;
            white-space: pre-wrap; word-break: break-all;
            max-height: 560px; overflow-y: auto;
        }
        .debug-content.show { display: block; }
        .dc { color: #34d399; }

        /* ── Spinner ── */
        .spinner {
            display: none; width: 18px; height: 18px;
            border: 3px solid rgba(255,255,255,.3);
            border-top-color: #fff; border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Footer ── */
        .footer { text-align: center; color: rgba(255,255,255,.5); font-size: .8rem; padding: 18px 0 8px; }
    </style>
</head>
<body>
<div class="container">

    <!-- ── Header ── -->
    <div class="page-header">
        <div class="header-badge">
            <span class="badge-bifast">BIFAST</span>
            <span class="badge-sep">via</span>
            <span class="badge-permata">PERMATA</span>
            <span class="badge-sep">×</span>
            <span class="badge-assist">ASSIST</span>
            <span style="color:rgba(255,255,255,.6);font-size:.82rem;">Switching v3 Pro</span>
        </div>
        <h1>⚡ Inquiry Transfer BIFAST</h1>
        <p>Cek rekening tujuan BIFAST (Bank Indonesia Fast Payment) via SIS/Assist Digital Switching Middleware (MTI=010, INQBIFAST)</p>
    </div>

    <!-- ── Feature Cards ── -->
    <div class="feature-row">
        <div class="fc">
            <div class="fc-i">⚡</div>
            <div class="fc-t">Real-Time 24/7</div>
            <div class="fc-d">Proses dalam hitungan detik</div>
        </div>
        <div class="fc">
            <div class="fc-i">🏦</div>
            <div class="fc-t">Antar Bank BI</div>
            <div class="fc-d">Semua peserta BIFAST BI</div>
        </div>
        <div class="fc">
            <div class="fc-i">📱</div>
            <div class="fc-t">Multi-Proxy</div>
            <div class="fc-d">Rekening, HP, Email, VA</div>
        </div>
    </div>

    <!-- ── Config Status ── -->
    <div class="card">
        <div class="card-header blue">
            <div class="ch-icon">⚙️</div>
            Status Konfigurasi Middleware (BIFAST)
            <span class="ch-badge"><?= $isConfigured ? '✅ Siap' : '⚠️ Belum Lengkap' ?></span>
        </div>
        <div class="card-body" style="padding:18px 24px;">

            <!-- Detection Log -->
            <div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.8rem;">
                <div style="font-weight:800;color:#065f46;margin-bottom:6px;">🔍 Auto-Detection Report (BIFAST)</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:3px 16px;">
                <?php foreach ($_DETECTION_LOG as $k => $v): ?>
                    <div><span style="color:#64748b;font-family:monospace;"><?= htmlspecialchars($k) ?></span>: <span><?= htmlspecialchars($v) ?></span></div>
                <?php endforeach; ?>
                </div>
                <?php if (!empty($_ASSIST_ROOT)): ?>
                <div style="margin-top:6px;color:#065f46;">📂 Assist Root: <code><?= htmlspecialchars($_ASSIST_ROOT) ?></code></div>
                <?php endif; ?>
            </div>

            <div class="config-grid">
                <div class="cfg">
                    <div class="cg-l">Token URL</div>
                    <div class="cg-v ok" title="<?= htmlspecialchars(URL_GET_TOKEN) ?>">myassist.sis1.net ✓</div>
                </div>
                <div class="cfg">
                    <div class="cg-l">Digital Server</div>
                    <div class="cg-v ok" title="<?= htmlspecialchars(URL_DIGITAL) ?>">digital.sis1.net/dgl ✓</div>
                </div>
                <div class="cfg">
                    <div class="cg-l">mftfi (BIFAST)</div>
                    <div class="cg-v ok"><?= htmlspecialchars(MFTFI) ?></div>
                </div>
                <div class="cfg">
                    <div class="cg-l">Kode Agen</div>
                    <div class="cg-v <?= KODE_AGEN !== '' ? 'ok' : 'err' ?>">
                        <?= KODE_AGEN !== '' ? htmlspecialchars(KODE_AGEN) : '⚠ Tidak terdeteksi' ?>
                    </div>
                </div>
                <div class="cfg">
                    <div class="cg-l">OAuth Client ID</div>
                    <div class="cg-v <?= OAUTH_CLIENT_ID !== '' ? 'ok' : 'warn' ?>">
                        <?= OAUTH_CLIENT_ID !== '' ? '✓ Terisi (.assist.env)' : '— Isi di .assist.env' ?>
                    </div>
                </div>
                <div class="cfg">
                    <div class="cg-l">cicd Identity</div>
                    <div class="cg-v ok" style="font-size:.68rem;" title="<?= htmlspecialchars(CICD) ?>"><?= substr(CICD, 0, 16) ?>…</div>
                </div>
                <div class="cfg">
                    <div class="cg-l">OAuth Username</div>
                    <div class="cg-v <?= OAUTH_USERNAME !== '' ? 'ok' : 'warn' ?>">
                        <?= OAUTH_USERNAME !== '' ? '✓ ' . htmlspecialchars(substr(OAUTH_USERNAME, 0, 8)) . '…' : '— Isi di .assist.env' ?>
                    </div>
                </div>
                <div class="cfg">
                    <div class="cg-l">Cache File BIFAST</div>
                    <div class="cg-v <?= file_exists(CACHE_FILE_BIFAST) ? 'ok' : 'warn' ?>" style="font-size:.7rem;">
                        snap_ft_bdi.cache<br>
                        <?= file_exists(CACHE_FILE_BIFAST) ? '✓ Ada' : '— Belum ada' ?>
                    </div>
                </div>
                <div class="cfg">
                    <div class="cg-l">DE061 / SIMSerial</div>
                    <div class="cg-v <?= DE061_SIM_SERIAL !== '' ? 'ok' : 'warn' ?>">
                        <?= DE061_SIM_SERIAL !== '' ? '✓ Terisi' : '— Opsional' ?>
                    </div>
                </div>
            </div>
            <div class="alert alert-info" style="margin-bottom:0;font-size:.84rem;">
                <span class="ai">ℹ️</span>
                <div>
                    Cache BIFAST menggunakan suffix <code style="background:#bbf7d0;padding:1px 5px;border-radius:3px;">snap_ft_bdi.cache</code>
                    (berbeda dari TF Permata: <code>snap_tf_bank_permata.cache</code>).
                    DE048: <code style="background:#bbf7d0;padding:1px 5px;border-radius:3px;">0601*1001*INQBIFAST~~BLTRFAG</code>.
                    <?php if (!$isConfigured): ?>
                    <br><strong style="color:#b45309;">Buat <code>.assist.env</code> dengan isi: OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, OAUTH_USERNAME, OAUTH_PASSWORD.</strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Form Card ── -->
    <div class="card">
        <div class="card-header">
            <div class="ch-icon">⚡</div>
            Form Inquiry Transfer BIFAST
            <span class="ch-badge">ISO 8583 MTI=010 · INQBIFAST</span>
        </div>
        <div class="card-body">

            <?php if ($errorMessage): ?>
            <div class="alert alert-error">
                <span class="ai">❌</span>
                <div><?= htmlspecialchars($errorMessage) ?></div>
            </div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-bottom:16px;">
                <span class="ai">⚡</span>
                <div>
                    Request dikirim ke <strong>digital.sis1.net</strong> via ISO 8583 (MTI=010).
                    DE048 = <code>INQBIFAST</code> — routing oleh server ke PermataSNAP atau DanamonSNAP
                    sesuai konfigurasi <code>PermataSNAPTF</code> / <code>DanamonSNAPTF</code> agen.
                    Batas nominal: <strong>Rp 200.000.000</strong> per transaksi.
                </div>
            </div>

            <form method="POST" id="bifastForm" onsubmit="handleSubmit(event)">
                <input type="hidden" name="action" value="inquiry_bifast">

                <!-- Proxy Type -->
                <div class="section-title">Tipe Identifikasi Tujuan</div>
                <div class="form-group full" style="margin-bottom:16px;">
                    <div class="proxy-tabs">
                        <label class="proxy-tab <?= ($formData['proxy_type'] ?? 'ACCOUNT_NUMBER') === 'ACCOUNT_NUMBER' ? 'active' : '' ?>">
                            <input type="radio" name="proxy_type" value="ACCOUNT_NUMBER"
                                onchange="updateProxy(this)"
                                <?= ($formData['proxy_type'] ?? 'ACCOUNT_NUMBER') === 'ACCOUNT_NUMBER' ? 'checked' : '' ?>>
                            <span class="pt-i">🏦</span>
                            <span class="pt-l">No. Rekening</span>
                            <span class="pt-d">Account Number</span>
                        </label>
                        <label class="proxy-tab <?= ($formData['proxy_type'] ?? '') === 'PHONE_NUMBER' ? 'active' : '' ?>">
                            <input type="radio" name="proxy_type" value="PHONE_NUMBER"
                                onchange="updateProxy(this)"
                                <?= ($formData['proxy_type'] ?? '') === 'PHONE_NUMBER' ? 'checked' : '' ?>>
                            <span class="pt-i">📱</span>
                            <span class="pt-l">No. HP</span>
                            <span class="pt-d">Phone Number</span>
                        </label>
                        <label class="proxy-tab <?= ($formData['proxy_type'] ?? '') === 'EMAIL' ? 'active' : '' ?>">
                            <input type="radio" name="proxy_type" value="EMAIL"
                                onchange="updateProxy(this)"
                                <?= ($formData['proxy_type'] ?? '') === 'EMAIL' ? 'checked' : '' ?>>
                            <span class="pt-i">📧</span>
                            <span class="pt-l">Email</span>
                            <span class="pt-d">Alamat Email</span>
                        </label>
                        <label class="proxy-tab <?= ($formData['proxy_type'] ?? '') === 'VIRTUAL_ACCOUNT' ? 'active' : '' ?>">
                            <input type="radio" name="proxy_type" value="VIRTUAL_ACCOUNT"
                                onchange="updateProxy(this)"
                                <?= ($formData['proxy_type'] ?? '') === 'VIRTUAL_ACCOUNT' ? 'checked' : '' ?>>
                            <span class="pt-i">🔢</span>
                            <span class="pt-l">Virtual Account</span>
                            <span class="pt-d">VA Number</span>
                        </label>
                    </div>
                </div>

                <!-- Rekening & Proxy -->
                <div class="section-title">Data Tujuan</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nomor Rekening Tujuan <span class="req">*</span></label>
                        <input type="text" name="nomor_rekening" id="nomorRek"
                            placeholder="Masukkan nomor rekening"
                            value="<?= htmlspecialchars($formData['nomor_rekening'] ?? '') ?>"
                            maxlength="30">
                        <div class="field-hint" id="rekHint">Diisi ke DE102 dalam request ISO</div>
                    </div>
                    <div class="form-group">
                        <label>Proxy Value <span class="opt">(HP / Email / VA jika bukan rekening)</span></label>
                        <input type="text" name="proxy_value" id="proxyValue"
                            placeholder="Kosongkan jika sama dengan nomor rekening"
                            value="<?= htmlspecialchars($formData['proxy_value'] ?? '') ?>">
                        <div class="field-hint">Proxy sesuai tipe yang dipilih</div>
                    </div>
                </div>

                <!-- Bank Tujuan -->
                <div class="section-title">Informasi Bank Tujuan</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Bank Tujuan (Kode Numerik) <span class="req">*</span></label>
                        <select name="kode_bank" id="kodeBankNum" onchange="setBankName(this)">
                            <?php foreach ($daftarBankNumerik as $kode => $nama): ?>
                            <option value="<?= htmlspecialchars($kode) ?>"
                                <?= ($formData['kode_bank'] ?? '') === $kode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nama) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Diisi ke DE103 dalam request ISO</div>
                    </div>
                    <div class="form-group">
                        <label>Kode Bank Manual <span class="opt">(opsional)</span></label>
                        <input type="text" name="kode_bank_manual" id="kodeBankManual"
                            placeholder="Kode numerik / BIC, ex: 014"
                            value="<?= htmlspecialchars($formData['kode_bank_manual'] ?? '') ?>">
                        <div class="field-hint">Override kode bank dari dropdown</div>
                    </div>
                    <input type="hidden" name="nama_bank" id="namaBank"
                        value="<?= htmlspecialchars($formData['nama_bank'] ?? '') ?>">
                    <div class="form-group">
                        <label>Nominal Transfer (Rp) <span class="opt">(opsional)</span></label>
                        <input type="text" name="nominal" id="nominalInput"
                            placeholder="Maks. Rp 200.000.000"
                            value="<?= htmlspecialchars($formData['nominal'] ?? '') ?>"
                            oninput="checkNominal(this)">
                        <div class="field-hint" id="nominalHint">BIFAST: maks Rp 200 juta/transaksi</div>
                    </div>
                </div>

                <hr style="margin:20px 0;border:none;border-top:1px dashed #e5e7eb;">

                <button type="submit" class="btn-submit" id="submitBtn">
                    <span id="btnText">⚡ Kirim Inquiry BIFAST</span>
                    <div class="spinner" id="spinner"></div>
                </button>
            </form>
        </div>
    </div>

    <!-- ── RESULT ── -->
    <?php if ($result !== null): ?>
    <div class="result-wrap">
        <div class="result-header <?= $result['success'] ? 'ok' : 'fail' ?>">
            <?= $result['success'] ? '✅ Inquiry BIFAST Berhasil' : '❌ Inquiry BIFAST Gagal' ?>
            <span style="margin-left:auto;background:rgba(255,255,255,.2);border-radius:6px;padding:2px 10px;font-size:.78rem;">
                RC: <?= htmlspecialchars($result['rc'] ?? '-') ?>
            </span>
        </div>
        <div class="result-body">

            <?php if ($result['success']): ?>
            <div class="result-grid">
                <div class="ri">
                    <label>Nama Pemilik Rekening</label>
                    <div class="val hl"><?= htmlspecialchars($result['beneficiary']['account_name'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Nomor Rekening</label>
                    <div class="val"><?= htmlspecialchars($result['beneficiary']['account_no'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Bank Tujuan</label>
                    <div class="val">
                        [<?= htmlspecialchars($result['beneficiary']['bank_code'] ?? '-') ?>]
                        <?= htmlspecialchars($result['beneficiary']['bank_name'] ?? '-') ?>
                    </div>
                </div>
                <hr class="section-sep">
                <div class="ri">
                    <label>Proxy Type</label>
                    <div class="val tag"><?= htmlspecialchars($result['proxy_info']['type'] ?? 'ACCOUNT_NUMBER') ?></div>
                </div>
                <div class="ri">
                    <label>Proxy Value</label>
                    <div class="val"><?= htmlspecialchars($result['proxy_info']['value'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Biaya Admin</label>
                    <div class="val">Rp <?= number_format($result['biaya_admin'] ?? 0, 0, ',', '.') ?></div>
                </div>
                <hr class="section-sep">
                <div class="ri">
                    <label>DE048 (TrxCode)</label>
                    <div class="val code"><?= htmlspecialchars($result['de048'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Cache File</label>
                    <div class="val code" style="font-size:.7rem;"><?= htmlspecialchars($result['cache_file'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Response Code</label>
                    <div class="val code"><?= htmlspecialchars($result['rc'] ?? '-') ?></div>
                </div>
                <div class="ri" style="grid-column:1/-1">
                    <label>Pesan / Tagihan</label>
                    <div class="val"><?= htmlspecialchars($result['message'] ?? '-') ?></div>
                </div>
            </div>
            <div style="margin-top:14px;padding:12px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;font-size:.84rem;color:#065f46;">
                ✅ Rekening valid! Transfer BIFAST dapat dilanjutkan ke
                <strong><?= htmlspecialchars($result['beneficiary']['account_name'] ?? '-') ?></strong>
                (<?= htmlspecialchars($result['beneficiary']['account_no'] ?? '-') ?>)
                di <?= htmlspecialchars($result['beneficiary']['bank_name'] ?? '-') ?>.
                <?php if ($result['token_from_cache'] ?? false): ?>
                <em style="font-size:.78rem;">(Token dari cache)</em>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="alert alert-error">
                <span class="ai">🚫</span>
                <div>
                    <strong>Step:</strong> <?= htmlspecialchars($result['step'] ?? '-') ?><br>
                    <strong>RC:</strong> <?= htmlspecialchars($result['rc'] ?? '-') ?><br>
                    <strong>Pesan:</strong> <?= htmlspecialchars($result['message'] ?? '-') ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Debug Panel ── -->
            <?php if (DEBUG_MODE): ?>
            <div class="debug-wrap">
                <button class="debug-btn" onclick="toggleDebug('dbg1')">
                    🔧 Debug: Request / Response ISO 8583 BIFAST (SHA256 Signing)
                    <span id="dbg1-icon">▼</span>
                </button>
                <div class="debug-content" id="dbg1">
<span class="dc">━━ STEP 1: GET TOKEN (myassist.sis1.net) ━━</span>
Token URL  : <?= htmlspecialchars(URL_GET_TOKEN) ?>

Cache File : <?= htmlspecialchars(CACHE_FILE_BIFAST) ?>

Dari Cache : <?= ($result['debug']['step1_get_token']['from_cache'] ?? false) ? 'YA' : 'TIDAK' ?>

Sukses     : <?= ($result['debug']['step1_get_token']['success'] ?? false) ? 'YA' : 'TIDAK' ?>


<span class="dc">━━ STEP 2: ISO 8583 REQUEST (MTI=010, INQBIFAST) ━━</span>
<?= htmlspecialchars(json_encode($result['debug']['step2_iso_request'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>


<span class="dc">━━ STEP 3: HTTP POST ke Digital Server ━━</span>
URL        : <?= htmlspecialchars($result['debug']['step3_http_result']['url'] ?? '') ?>

Body Sent  : <?= htmlspecialchars($result['debug']['step3_http_result']['body_signed'] ?? '') ?>

DateTime   : <?= htmlspecialchars($result['debug']['step3_http_result']['datetime'] ?? '') ?>

SHA256 Auth: <?= htmlspecialchars($result['debug']['step3_http_result']['sha256_auth'] ?? '') ?>

HTTP Code  : <?= htmlspecialchars((string)($result['debug']['step3_http_result']['http_code'] ?? '')) ?>  (<?= $result['debug']['step3_http_result']['elapsed_ms'] ?? 0 ?>ms)

RAW Response:
<?= htmlspecialchars($result['debug']['step3_http_result']['raw'] ?? '(kosong)') ?>


<span class="dc">━━ STEP 4: ISO 8583 RESPONSE PARSED ━━</span>
<?= htmlspecialchars(json_encode($result['debug']['step4_iso_response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>

                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="footer">
        Inquiry BIFAST — SIS/Assist Switching Middleware v1.6.39 &mdash;
        PHP <?= PHP_VERSION ?> &mdash; <?= SNow() ?> WIB
    </div>

</div>

<script>
const bankNamesNum = <?= json_encode(array_filter($daftarBankNumerik, fn($v) => $v !== '-- Pilih Bank --')) ?>;

function setBankName(sel) {
    document.getElementById('namaBank').value = bankNamesNum[sel.value] || '';
    if (sel.value) document.getElementById('kodeBankManual').value = '';
}

document.getElementById('kodeBankManual').addEventListener('input', function() {
    if (this.value) {
        document.getElementById('kodeBankNum').value = '';
        document.getElementById('namaBank').value    = '';
    }
});

const proxyPH = {
    'ACCOUNT_NUMBER': { rek: 'Masukkan nomor rekening tujuan', hint: 'Diisi ke DE102 dalam request ISO', proxy: 'Kosongkan jika sama dengan nomor rekening' },
    'PHONE_NUMBER':   { rek: 'Nomor HP terdaftar (ex: 0812345...)', hint: 'Nomor HP sebagai proxy BIFAST', proxy: 'Nomor HP tujuan (ex: 08123456789)' },
    'EMAIL':          { rek: 'Masukkan alamat email terdaftar', hint: 'Email sebagai proxy BIFAST', proxy: 'Alamat email tujuan (ex: user@mail.com)' },
    'VIRTUAL_ACCOUNT':{ rek: 'Masukkan nomor Virtual Account', hint: 'Nomor VA tujuan', proxy: 'Nomor VA tujuan' },
};

function updateProxy(radio) {
    const ph = proxyPH[radio.value] || proxyPH['ACCOUNT_NUMBER'];
    document.getElementById('nomorRek').placeholder   = ph.rek;
    document.getElementById('rekHint').textContent    = ph.hint;
    document.getElementById('proxyValue').placeholder = ph.proxy;
    document.querySelectorAll('.proxy-tab').forEach(t => t.classList.remove('active'));
    radio.closest('.proxy-tab').classList.add('active');
}

function checkNominal(el) {
    let val = el.value.replace(/\D/g, '');
    const hint = document.getElementById('nominalHint');
    if (parseInt(val) > 200000000) {
        el.style.borderColor = '#f59e0b';
        hint.textContent = '⚠️ Melebihi batas BIFAST (Rp 200 juta), akan disesuaikan otomatis';
        hint.style.color = '#d97706';
    } else {
        el.style.borderColor = '';
        hint.textContent = 'BIFAST: maks Rp 200 juta/transaksi';
        hint.style.color = '#6b7280';
    }
    el.value = val;
}

function handleSubmit(e) {
    const manual = document.getElementById('kodeBankManual').value.trim();
    if (manual) document.getElementById('kodeBankNum').value = manual;
    const proxyVal = document.getElementById('proxyValue').value.trim();
    const nomorRek = document.getElementById('nomorRek').value.trim();
    if (!proxyVal) document.getElementById('proxyValue').value = nomorRek;
    document.getElementById('btnText').textContent = 'Memproses...';
    document.getElementById('spinner').style.display = 'block';
    document.getElementById('submitBtn').disabled = true;
    return true;
}

function toggleDebug(id) {
    const el   = document.getElementById(id);
    const icon = document.getElementById(id + '-icon');
    el.classList.toggle('show') ? icon.textContent = '▲' : icon.textContent = '▼';
}

document.querySelectorAll('.proxy-tab input[type=radio]').forEach(function(r) {
    r.addEventListener('change', function() { updateProxy(r); });
});
</script>
</body>
</html>
