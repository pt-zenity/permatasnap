<?php
/**
 * Inquiry Transfer Bank via Permata (SIS/Assist Switching Middleware)
 * =====================================================================
 * Script single-file PHP untuk melakukan inquiry transfer bank melalui
 * jalur SIS/Assist switching middleware (assist-switching_v3_pro).
 *
 * Mendukung jenis transfer:
 *   - TFDANA  (Transfer Dana Online / SKN Real-Time)
 *   - LLG     (Lalu Lintas Giro / SKN Batch)
 *   - RTGS    (Real-Time Gross Settlement)
 *
 * AUTO-DETECTION dari source code assist-switching_v3_pro & assist-bpr.net:
 *   ✅ URL_GET_TOKEN, URL_DIGITAL, CICD, MFTFI → dari config/local_config.php
 *   ✅ KODE_AGEN, MFTFI TF  → dari nama file storage/cds/cache/*snap_tf_bank_permata.cache
 *   ✅ DE061_SIM_SERIAL     → dari storage/cds/cache/ (nama file cache terakhir)
 *   ✅ OAuth credentials (auto-detect, urutan prioritas):
 *      1. File .assist.env (format KEY=VALUE):
 *           OAUTH_CLIENT_ID=xxxx
 *           OAUTH_CLIENT_SECRET=xxxx
 *           OAUTH_USERNAME=xxxx       ← UserH2H di tabel agen
 *           OAUTH_PASSWORD=xxxx
 *           DE061_SIM_SERIAL=xxxx     ← opsional, override auto-detect
 *      2. assist-bpr.net/env/{kode}_.env (format key = value, spasi sekitar =):
 *           auth_client_id = xxxx     → OAUTH_CLIENT_ID
 *           auth_client_secret = xxxx → OAUTH_CLIENT_SECRET
 *           username = Assist         → OAUTH_USERNAME (default H2H)
 *           password = Irac           → OAUTH_PASSWORD (default H2H)
 *           auth_server_uri = https://one.myassist.id
 *
 * Alur transaksi (sesuai source code mbanking.controller.php):
 *   1. Ambil OAuth access token dari myassist.sis1.net
 *   2. Bangun pesan ISO 8583-like JSON (MTI="010", MSG dengan DE fields)
 *   3. Kirim POST ke digital.sis1.net/assist-digital.net/public/dgl
 *      dengan header: authorization (SHA256), identity (cicd), datetime
 *   4. Response di-parse dari ISO 8583 array
 *
 * Referensi: assist-switching_v3_pro v1.6.39 "Assist Pro Net"
 *   - config/local_config.php
 *   - mvc/mbanking/mbanking.controller.php → ProsesInquiryPayment()
 *   - storage/cds/cache/*snap_tf_bank_permata.cache → KODE_AGEN + MFTFI
 *   - include/func.oauth.mod.php
 *
 * PHP 8.1+
 */

date_default_timezone_set('Asia/Jakarta');

// ============================================================
// AUTO-DETECTION ENGINE
// ============================================================

/**
 * Cari direktori root assist-switching_v3_pro secara otomatis.
 *
 * Urutan pencarian:
 *   1. Known absolute paths (produksi, dicek berurutan):
 *        /var/www/prg/app/assist/aa-pro/app/assist-switching_v3_pro
 *   2. Traversal dari __DIR__ ke atas (maks 6 level):
 *      Cocok jika >= 2 dari 3 marker ditemukan:
 *        config/local_config.php | storage/cds/cache | sisproject/project.json
 */
function detectAssistRoot(): string {
    $markers = ['config/local_config.php', 'storage/cds/cache', 'sisproject/project.json'];

    // ── Helper validasi root ──────────────────────────────────
    $isValidRoot = function (string $candidate) use ($markers): bool {
        $found = 0;
        foreach ($markers as $m) {
            if (file_exists($candidate . '/' . $m)) $found++;
        }
        return $found >= 2;
    };

    // ── Langkah 1: known absolute paths (produksi) ────────────
    $knownPaths = [
        '/var/www/prg/app/assist/aa-pro/app/assist-switching_v3_pro',
    ];
    foreach ($knownPaths as $known) {
        if ($isValidRoot($known)) return $known;
    }

    // ── Langkah 2: traversal dari __DIR__ ke atas ─────────────
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if ($isValidRoot($dir)) return $dir;
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return '';
}

/**
 * Parse local_config.php dari assist-switching_v3_pro.
 * Membaca nilai $config['key'] menggunakan regex (tanpa require/eval).
 * Return array key => value dari $config[].
 */
function parseLocalConfig(string $assistRoot): array {
    $cfgFile = $assistRoot . '/config/local_config.php';
    if (!file_exists($cfgFile)) return [];

    $src  = file_get_contents($cfgFile);
    $vals = [];

    // Pattern: $config['KEY'] = "VALUE"; atau $config["KEY"] = 'VALUE';
    // Tangani juga concatenation sederhana seperti $config['X'] . "/path"
    preg_match_all(
        '/\$config\[[\'"]([\w_]+)[\'"]\]\s*=\s*["\']([^"\']*)["\']/',
        $src,
        $matches,
        PREG_SET_ORDER
    );
    foreach ($matches as $m) {
        $vals[$m[1]] = $m[2];
    }

    // Resolve concatenation: $config['X'] . "/suffix"
    // misal: $config['_URL_GET_TOKEN_'] = $config['_URL_MY_ASSIST_'] . "/oauth/getaccesstoken";
    preg_match_all(
        '/\$config\[[\'"]([\w_]+)[\'"]\]\s*=\s*\$config\[[\'"]([\w_]+)[\'"]\]\s*\.\s*["\']([^"\']*)["\']/',
        $src,
        $concatMatches,
        PREG_SET_ORDER
    );
    foreach ($concatMatches as $m) {
        $base = $vals[$m[2]] ?? '';
        $vals[$m[1]] = $base . $m[3];
    }

    return $vals;
}

/**
 * Deteksi KODE_AGEN dan MFTFI dari nama file cache snap_tf_bank_permata.
 * Pattern nama: cds-auth-a-{KodeAgen}_{mftfi}-snap_tf_bank_permata.cache
 * Contoh: cds-auth-a-000268_0017-snap_tf_bank_permata.cache
 *   → KodeAgen = "A-000268", mftfi = "0017"
 */
function detectFromCacheTF(string $assistRoot): array {
    $cacheDir = $assistRoot . '/storage/cds/cache';
    if (!is_dir($cacheDir)) return ['kode_agen' => '', 'mftfi' => ''];

    $files = glob($cacheDir . '/cds-auth-a-*-snap_tf_bank_permata.cache');
    if (empty($files)) return ['kode_agen' => '', 'mftfi' => ''];

    // Ambil file terbaru (paling baru dimodifikasi)
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $fname = basename($files[0]);

    // Parse: cds-auth-a-{numerik}_{mftfi}-snap_tf_bank_permata.cache
    if (preg_match('/^cds-auth-a-(\d+)_(\w+)-snap_tf_bank_permata\.cache$/', $fname, $m)) {
        return [
            'kode_agen' => 'A-' . $m[1],   // ex: "A-000268"
            'mftfi'     => $m[2],            // ex: "0017"
            'raw_kode'  => $m[1],            // ex: "000268"
        ];
    }

    return ['kode_agen' => '', 'mftfi' => ''];
}

/**
 * Baca OAuth credentials dari file .assist.env.
 * Cari di: __DIR__, satu level atas, dua level atas.
 * Format: KEY=VALUE (satu per baris, # untuk komentar)
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
 *
 * Urutan pencarian:
 *   1. Known absolute paths (produksi, dicek berurutan):
 *        /var/www/prg/app/assist/bpr-myassist/assist-bpr.net
 *        /var/www/prg/app/mvc/assist-bpr.net
 *   2. Traversal dari __DIR__ ke atas (maks 8 level):
 *      a. Direktori itu sendiri memiliki env/ + bin/connect.php
 *      b. Subdirektori bernama assist-bpr.net / assist-bpr / assistbpr
 *      c. Subdirektori pola app/mvc/assist-bpr.net
 *      d. Subdirektori pola app/assist/bpr-myassist/assist-bpr.net
 */
function detectAssistBprRoot(): string {
    // ── Helper validasi root ──────────────────────────────────
    $isValidRoot = function (string $candidate): bool {
        if (!is_dir($candidate . '/env')) return false;
        if (!file_exists($candidate . '/bin/connect.php')) return false;
        $envFiles = glob($candidate . '/env/*.env') ?: [];
        return !empty($envFiles);
    };

    // ── Langkah 1: known absolute paths (produksi) ────────────
    // Urutan: path paling spesifik/baru dulu
    $knownPaths = [
        '/var/www/prg/app/assist/bpr-myassist/assist-bpr.net',
        '/var/www/prg/app/mvc/assist-bpr.net',
    ];
    foreach ($knownPaths as $known) {
        if ($isValidRoot($known)) return $known;
    }

    // ── Langkah 2: traversal dari __DIR__ ke atas ─────────────
    $subNames = ['assist-bpr.net', 'assist-bpr', 'assistbpr'];
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        // 2a. Direktori saat ini sendiri adalah root assist-bpr.net
        if ($isValidRoot($dir)) return $dir;

        // 2b. Subdirektori langsung bernama assist-bpr.net / assist-bpr / assistbpr
        foreach ($subNames as $sub) {
            $candidate = $dir . '/' . $sub;
            if ($isValidRoot($candidate)) return $candidate;
        }

        // 2c. Subdirektori app/mvc/assist-bpr.net (struktur /var/www/prg/app/mvc/)
        foreach ($subNames as $sub) {
            $candidate = $dir . '/app/mvc/' . $sub;
            if ($isValidRoot($candidate)) return $candidate;
        }

        // 2d. Subdirektori app/assist/bpr-myassist/assist-bpr.net
        foreach ($subNames as $sub) {
            $candidate = $dir . '/app/assist/bpr-myassist/' . $sub;
            if ($isValidRoot($candidate)) return $candidate;
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
 *   auth_server_uri / base_url_auth → OAUTH_SERVER_URI (opsional)
 *
 * @param string $assistBprRoot  Path root assist-bpr.net (dari detectAssistBprRoot())
 * @param string $rawKode        Kode numerik agen, mis. "000268" (dari cache filename)
 *
 * Urutan pencarian kandidat:
 *   1. {envDir}/{rawKode}_.env          — kode spesifik dari cache filename
 *   2. Semua file env numerik [0-9]*.env  diurutkan mtime desc, dicocokkan log_name dulu
 *   3. File env hostname-pattern (bpr.*.env, *.net.env, dll) — mencakup bpr.myassist.id.env
 *
 * Matching log_name:
 *   Setiap file env numerik dapat berisi "log_name = {hostname}".
 *   Jika server hostname cocok dengan log_name, file itu diprioritaskan.
 *
 * Field yang dikembalikan:
 *   OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, OAUTH_USERNAME, OAUTH_PASSWORD,
 *   OAUTH_SERVER_URI, OAUTH_CORPORATE_ID, OAUTH_TOKEN_PRIVATE (RSA key jika ada),
 *   _env_file, _source
 */
function detectFromAssistBprEnv(string $assistBprRoot, string $rawKode = ''): array {
    $envDir = $assistBprRoot . '/env';
    if (!is_dir($envDir)) return [];

    // ── Helper: parse satu env file ke array raw ──────────────
    $parseEnvFile = function (string $path): array {
        if (!file_exists($path)) return [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];
        $raw = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            // Format assist-bpr: "key = value" (dengan spasi di sekitar =)
            [$k, $v] = explode('=', $line, 2);
            $raw[trim($k)] = trim($v);
        }
        return $raw;
    };

    // ── Helper: buat result array dari $raw ────────────────────
    $buildResult = function (array $raw, string $path) use ($parseEnvFile, $envDir): array {
        // Resolusi URL OAuth: auth_server_uri > base_url_auth
        $oauthServerUri = rtrim(
            $raw['auth_server_uri'] ?? $raw['base_url_auth'] ?? '',
            '/'
        );
        // RSA private key — hanya ada di bpr.myassist.id.env (OAUTH_TOKEN_PRIVATE / oauth_token_private)
        // Format dalam file: backslash-n literal → ganti ke newline sesungguhnya
        $tokenPrivate = $raw['oauth_token_private'] ?? $raw['OAUTH_TOKEN_PRIVATE'] ?? '';
        if (!empty($tokenPrivate)) {
            $tokenPrivate = str_replace('\n', "\n", $tokenPrivate);
        }
        return [
            'OAUTH_CLIENT_ID'      => $raw['auth_client_id']    ?? $raw['OAUTH_CLIENT_ID']     ?? '',
            'OAUTH_CLIENT_SECRET'  => $raw['auth_client_secret'] ?? $raw['OAUTH_CLIENT_SECRET'] ?? '',
            'OAUTH_USERNAME'       => $raw['username']           ?? '',
            'OAUTH_PASSWORD'       => $raw['password']           ?? '',
            'OAUTH_SERVER_URI'     => $oauthServerUri,
            'OAUTH_CORPORATE_ID'   => $raw['auth_corporate_id']  ?? $raw['OAUTH_CORPORATE_ID']  ?? '',
            'OAUTH_TOKEN_PRIVATE'  => $tokenPrivate,
            '_env_file'            => $path,
            '_source'              => 'assist-bpr.net/env',
        ];
    };

    // ── Deteksi hostname server saat ini ──────────────────────
    // Digunakan untuk mencocokkan log_name di env files
    $serverHost = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        // Hilangkan port jika ada (mis. "bpr.ams.sis1.net:80" → "bpr.ams.sis1.net")
        $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'])[0]);
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $serverHost = strtolower($_SERVER['SERVER_NAME']);
    } else {
        // CLI: gethostname() → hostname OS
        $hn = @gethostname();
        if (!empty($hn)) $serverHost = strtolower($hn);
    }

    // ── Langkah 1: kode spesifik dari cache filename ──────────
    if (!empty($rawKode)) {
        $specificPath = $envDir . '/' . $rawKode . '_.env';
        $raw = $parseEnvFile($specificPath);
        if (!empty($raw['auth_client_id']) || !empty($raw['OAUTH_CLIENT_ID'])) {
            return $buildResult($raw, $specificPath);
        }
        // Coba tanpa leading zeros (mis. "000087" → "87")
        $noLeading = ltrim($rawKode, '0');
        if ($noLeading !== $rawKode && $noLeading !== '') {
            $altPath = $envDir . '/' . $noLeading . '_.env';
            $raw = $parseEnvFile($altPath);
            if (!empty($raw['auth_client_id']) || !empty($raw['OAUTH_CLIENT_ID'])) {
                return $buildResult($raw, $altPath);
            }
        }
    }

    // ── Langkah 2: hostname matching via log_name ─────────────
    // Scan semua file numerik; jika log_name cocok dengan server hostname → prioritas tinggi
    if (!empty($serverHost)) {
        $allNumericEnv = glob($envDir . '/[0-9]*.env') ?: [];
        foreach ($allNumericEnv as $path) {
            $raw = $parseEnvFile($path);
            if (empty($raw)) continue;
            $logName = strtolower(trim($raw['log_name'] ?? ''));
            if ($logName === '' || $logName !== $serverHost) continue;
            // Cocok! File ini milik server yang sedang berjalan
            if (!empty($raw['auth_client_id']) || !empty($raw['OAUTH_CLIENT_ID'])) {
                return $buildResult($raw, $path);
            }
        }
    }

    // ── Langkah 3: fallback semua file numerik, mtime desc ────
    $allNumericEnv = glob($envDir . '/[0-9]*.env') ?: [];
    usort($allNumericEnv, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach ($allNumericEnv as $path) {
        $raw = $parseEnvFile($path);
        if (empty($raw['auth_client_id']) && empty($raw['OAUTH_CLIENT_ID'])) continue;
        return $buildResult($raw, $path);
    }

    // ── Langkah 4: file env hostname-pattern (non-numerik) ────
    // Contoh: bpr.myassist.id.env, sis1.cloud.env, dll.
    // Glob semua *.env, exclude file numerik yang sudah di-scan
    $allEnvFiles  = glob($envDir . '/*.env') ?: [];
    $numericPaths = array_fill_keys($allNumericEnv, true);
    foreach ($allEnvFiles as $path) {
        if (isset($numericPaths[$path])) continue;   // sudah di-scan di langkah 3
        $basename = basename($path);
        // Skip file yang diketahui kosong (berisi IP dengan port, atau nama sangat pendek)
        if (strpos($basename, ':') !== false) continue;
        $raw = $parseEnvFile($path);
        if (empty($raw['auth_client_id']) && empty($raw['OAUTH_CLIENT_ID'])) continue;
        return $buildResult($raw, $path);
    }

    return [];
}

// ────────────────────────────────────────────────────────────
// Jalankan auto-detection
// ────────────────────────────────────────────────────────────
$_ASSIST_ROOT   = detectAssistRoot();
$_LOCAL_CONFIG  = !empty($_ASSIST_ROOT) ? parseLocalConfig($_ASSIST_ROOT) : [];
$_CACHE_TF      = !empty($_ASSIST_ROOT) ? detectFromCacheTF($_ASSIST_ROOT) : [];
$_ASSIST_ENV    = loadAssistEnv();

// Auto-detect assist-bpr.net (sumber OAuth tambahan)
$_ASSIST_BPR_ROOT = detectAssistBprRoot();
$_RAW_KODE_TF     = $_CACHE_TF['raw_kode'] ?? '';
$_BPR_ENV         = !empty($_ASSIST_BPR_ROOT)
    ? detectFromAssistBprEnv($_ASSIST_BPR_ROOT, $_RAW_KODE_TF)
    : [];

// Deteksi sumber tiap nilai
$_DETECTION_LOG = [];

// ── URLs (dari local_config.php) ─────────────────────────────
$_myAssistBase  = $_LOCAL_CONFIG['_URL_MY_ASSIST_'] ?? 'http://myassist.sis1.net/assist-auth_api/public';
$_urlGetToken   = $_LOCAL_CONFIG['_URL_GET_TOKEN_'] ?? ($_myAssistBase . '/oauth/getaccesstoken');
$_urlCekToken   = $_LOCAL_CONFIG['_URL_CEK_TOKEN_'] ?? ($_myAssistBase . '/oauth/cekaccesstoken');
$_urlRfzToken   = $_LOCAL_CONFIG['_URL_RFZ_TOKEN_'] ?? ($_myAssistBase . '/oauth/getaccesstokenfromrefresh');
$_urlDigital    = $_LOCAL_CONFIG['s']               ?? 'http://digital.sis1.net/assist-digital.net/public/dgl';
$_urlDigitalSS  = $_LOCAL_CONFIG['ss']              ?? 'http://digital.sis1.net/assist-digital.net/public/rgol';
$_cicd          = $_LOCAL_CONFIG['cicd']            ?? 'db96e3cba196f76a6c31e4c9625614b3dc57619fba7e29ee534dd20c5c44855d';
$_mftfi         = $_CACHE_TF['mftfi']               ?? ($_LOCAL_CONFIG['mftfi'] ?? '002');

$_DETECTION_LOG['assist_root']    = $_ASSIST_ROOT ?: '— tidak ditemukan (fallback ke default)';
$_DETECTION_LOG['local_config']   = !empty($_LOCAL_CONFIG) ? '✅ Terbaca (' . count($_LOCAL_CONFIG) . ' keys)' : '⚠️ Tidak ditemukan';
$_DETECTION_LOG['url_get_token']  = empty($_LOCAL_CONFIG['_URL_GET_TOKEN_']) ? '⚠️ fallback default' : '✅ dari local_config.php';
$_DETECTION_LOG['url_digital']    = empty($_LOCAL_CONFIG['s'])               ? '⚠️ fallback default' : '✅ dari local_config.php';
$_DETECTION_LOG['cicd']           = empty($_LOCAL_CONFIG['cicd'])            ? '⚠️ fallback default' : '✅ dari local_config.php';

// ── KODE_AGEN + MFTFI (dari cache file) ──────────────────────
$_kodeAgen     = $_CACHE_TF['kode_agen'] ?? '';
$_mftfiFromCache = !empty($_CACHE_TF['mftfi']);

$_DETECTION_LOG['kode_agen']      = !empty($_kodeAgen)   ? '✅ auto dari cache: ' . $_kodeAgen  : '⚠️ tidak ditemukan di cache';
$_DETECTION_LOG['mftfi']          = $_mftfiFromCache      ? '✅ auto dari cache: ' . $_mftfi     : '⚠️ fallback dari local_config/default';

// ── OAuth Credentials (prioritas: .assist.env > assist-bpr.net/env/) ──────
// Sumber 1: .assist.env (format KEY=VALUE)
$_oauthClientId     = $_ASSIST_ENV['OAUTH_CLIENT_ID']     ?? '';
$_oauthClientSecret = $_ASSIST_ENV['OAUTH_CLIENT_SECRET'] ?? '';
$_oauthUsername     = $_ASSIST_ENV['OAUTH_USERNAME']      ?? '';
$_oauthPassword     = $_ASSIST_ENV['OAUTH_PASSWORD']      ?? '';
$_de061             = $_ASSIST_ENV['DE061_SIM_SERIAL']     ?? '';
$_oauthCorporateId  = '';           // auth_corporate_id dari env BPR
$_oauthTokenPrivate = '';           // RSA private key dari bpr.myassist.id.env
$_oauthSource       = !empty($_oauthClientId) ? '.assist.env' : '';

// Sumber 2: assist-bpr.net/env/{kode}_.env — fallback jika .assist.env kosong
if (empty($_oauthClientId) && !empty($_BPR_ENV['OAUTH_CLIENT_ID'])) {
    // Full override dari BPR env
    $_oauthClientId     = $_BPR_ENV['OAUTH_CLIENT_ID'];
    $_oauthClientSecret = $_BPR_ENV['OAUTH_CLIENT_SECRET'] ?? '';
    $_oauthUsername     = $_BPR_ENV['OAUTH_USERNAME']      ?? '';
    $_oauthPassword     = $_BPR_ENV['OAUTH_PASSWORD']      ?? '';
    $_oauthCorporateId  = $_BPR_ENV['OAUTH_CORPORATE_ID']  ?? '';
    $_oauthTokenPrivate = $_BPR_ENV['OAUTH_TOKEN_PRIVATE'] ?? '';
    // DE061 tetap dari .assist.env jika ada; assist-bpr tidak menyimpan DE061
    $_oauthSource       = 'assist-bpr.net/env';
} elseif (empty($_oauthClientSecret) && !empty($_BPR_ENV['OAUTH_CLIENT_SECRET'])) {
    // Partial fill: client_id ada di .assist.env tapi secret kosong → ambil dari bpr
    $_oauthClientSecret = $_BPR_ENV['OAUTH_CLIENT_SECRET'];
    if (empty($_oauthUsername)) $_oauthUsername = $_BPR_ENV['OAUTH_USERNAME'] ?? '';
    if (empty($_oauthPassword)) $_oauthPassword = $_BPR_ENV['OAUTH_PASSWORD'] ?? '';
    $_oauthSource .= '+assist-bpr.net/env';
}
// Corporate ID + RSA key selalu diisi dari BPR env jika belum ada (field tambahan)
if (empty($_oauthCorporateId)  && !empty($_BPR_ENV['OAUTH_CORPORATE_ID']))  $_oauthCorporateId  = $_BPR_ENV['OAUTH_CORPORATE_ID'];
if (empty($_oauthTokenPrivate) && !empty($_BPR_ENV['OAUTH_TOKEN_PRIVATE'])) $_oauthTokenPrivate = $_BPR_ENV['OAUTH_TOKEN_PRIVATE'];

$_envFile = !empty($_oauthSource) && str_contains($_oauthSource, 'bpr')
    ? ($_BPR_ENV['_env_file'] ?? ($_ASSIST_ENV['_env_file'] ?? ''))
    : ($_ASSIST_ENV['_env_file'] ?? '');

$_DETECTION_LOG['assist_bpr_root']      = !empty($_ASSIST_BPR_ROOT)     ? '✅ ' . $_ASSIST_BPR_ROOT      : '— tidak ditemukan';
$_DETECTION_LOG['oauth_env_file']       = !empty($_envFile)             ? '✅ ' . $_envFile               : '⚠️ env tidak ditemukan';
$_DETECTION_LOG['oauth_source']         = !empty($_oauthSource)         ? '✅ ' . $_oauthSource           : '❌ tidak ada sumber OAuth';
$_DETECTION_LOG['oauth_client_id']      = !empty($_oauthClientId)       ? '✅ terisi (' . $_oauthSource . ')' : '❌ belum diisi';
$_DETECTION_LOG['oauth_username']       = !empty($_oauthUsername)       ? '✅ terisi (' . $_oauthSource . ')' : '❌ belum diisi';
$_DETECTION_LOG['oauth_corporate_id']   = !empty($_oauthCorporateId)    ? '✅ ' . $_oauthCorporateId      : '⚠️ kosong (opsional)';
$_DETECTION_LOG['oauth_token_private']  = !empty($_oauthTokenPrivate)   ? '✅ RSA key terdeteksi'         : '⚠️ tidak ada (opsional)';
$_DETECTION_LOG['de061_sim_serial']     = !empty($_de061)               ? '✅ terisi dari .assist.env'    : '⚠️ kosong';

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
define('OAUTH_CLIENT_ID',      $_oauthClientId);
define('OAUTH_CLIENT_SECRET',  $_oauthClientSecret);
define('OAUTH_USERNAME',       $_oauthUsername);
define('OAUTH_PASSWORD',       $_oauthPassword);
define('OAUTH_CORPORATE_ID',   $_oauthCorporateId);
define('OAUTH_TOKEN_PRIVATE',  $_oauthTokenPrivate);
define('DE061_SIM_SERIAL',     $_de061);

// ── Token Cache (CDS pattern) ────────────────────────────────
// Cache dir: ikuti struktur asli jika assist root ditemukan, fallback ke __DIR__
$_cacheDir = !empty($_ASSIST_ROOT)
    ? $_ASSIST_ROOT . '/storage/cds/cache'
    : __DIR__ . '/storage/cds/cache';

$_rawKode = $_CACHE_TF['raw_kode'] ?? str_replace('A-', '', KODE_AGEN);

define('CACHE_DIR',  $_cacheDir);
define('CACHE_FILE_TF', CACHE_DIR . '/cds-auth-a-' . $_rawKode . '_' . MFTFI . '-snap_tf_bank_permata.cache');

// ── Opsi ────────────────────────────────────────────────────
define('CURL_TIMEOUT', 30);
define('DEBUG_MODE',   true);

// Bersihkan variabel sementara
// Bersihkan variabel sementara yang sudah tidak dipakai
// CATATAN: $_ASSIST_ROOT, $_LOCAL_CONFIG, $_CACHE_TF, $_ASSIST_ENV,
//          $_ASSIST_BPR_ROOT, dan $_BPR_ENV TIDAK di-unset —
//          masih dipakai oleh panel Status Auto-Detection di HTML bawah.
unset($_myAssistBase, $_urlGetToken, $_urlCekToken, $_urlRfzToken,
      $_urlDigital, $_urlDigitalSS, $_cicd, $_mftfi, $_mftfiFromCache,
      $_kodeAgen, $_rawKode, $_cacheDir, $_envFile, $_oauthSource,
      $_oauthClientId, $_oauthClientSecret, $_oauthUsername, $_oauthPassword,
      $_oauthCorporateId, $_oauthTokenPrivate, $_de061, $_RAW_KODE_TF);

// ============================================================
// FUNGSI HELPER
// ============================================================

/**
 * SNow() — timestamp fungsi sesuai source code Assist
 * Format: Y-m-d H:i:s (WIB)
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
 * Simpan token ke cache file (CDS pattern)
 * Format cache: JSON {access_token, expires_in, cached_at, refresh_token}
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
    @file_put_contents(CACHE_FILE_TF, json_encode($cache));
}

/**
 * Baca token dari cache file
 * Return null jika cache tidak ada / expired
 */
function readTokenCache(): ?string {
    if (!file_exists(CACHE_FILE_TF)) return null;
    $raw   = @file_get_contents(CACHE_FILE_TF);
    if (!$raw) return null;
    $cache = json_decode($raw, true);
    if (empty($cache['access_token'])) return null;
    // Cek expiry: expires_in dikurangi 60 detik buffer
    $expiresAt = ($cache['cached_at'] ?? 0) + ($cache['expires_in'] ?? 3600) - 60;
    if (time() > $expiresAt) return null;
    return $cache['access_token'];
}

/**
 * HTTP POST menggunakan cURL
 * Mendukung form-urlencoded (default) dan JSON
 */
function sendHttpPost(string $url, string $body, array $headers, bool $isJson = false): array {
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
 * Sesuai func.oauth.mod.php → menggunakan grant_type=password / client_credentials
 *
 * Request format (form-urlencoded):
 *   grant_type=password&username=&password=&client_id=&client_secret=
 */
function getAccessToken(): array {
    // 1. Coba ambil dari cache
    $cached = readTokenCache();
    if ($cached) {
        return ['success' => true, 'token' => $cached, 'from_cache' => true];
    }

    // 2. Request token baru
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

    // Response sukses: {"access_token":"...", "token_type":"Bearer", "expires_in":3600, ...}
    if (!empty($data['access_token'])) {
        saveTokenCache($data);
        return ['success' => true, 'token' => $data['access_token'], 'from_cache' => false, 'raw' => $result];
    }

    // Coba juga sebagai client_credentials
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
 * Bangun DE048 sesuai format ISO 8583 Assist
 *
 * Dari source (mbanking.controller.php):
 *   $vaDE048 = split("*", $cRequest['DE048']);  → 3 bagian dipisah *
 *   $vaTrx   = split("~~", $vaDE048[2]);         → bagian ke-2 dipisah ~~
 *   Format: {part1}*{part2}*{TrxCode}~~{SubCode}
 *
 * Contoh DE048 yang terlihat di source:
 *   INQTFDANA: "0601*1001*INQTFDANA~~BLTRFAG"
 *   INQLLG:    "0201*1001*INQLLG~~BLTRFAG"
 *   INQRTGS:   "0801*1001*INQRTGS~~BLTRFAG"
 *
 * Part1 = kode kategori, Part2 = kode produk internal, TrxCode = kode transaksi Assist
 */
function buildDE048(string $jenisTF): string {
    $map = [
        'TFDANA' => '0601*1001*INQTFDANA~~BLTRFAG',
        'LLG'    => '0201*1001*INQLLG~~BLTRFAG',
        'RTGS'   => '0801*1001*INQRTGS~~BLTRFAG',
    ];
    return $map[strtoupper($jenisTF)] ?? $map['TFDANA'];
}

/**
 * JSON2ISO — bangun string ISO 8583 serialized sesuai MBankingFunc::JSON2ISO
 *
 * Signature dari source:
 *   MBankingFunc::JSON2ISO(false, DE003, DE004, DE012, DE013, DE037, RC, DE044, DE048, DE052, DE061, DE102, DE103)
 *
 * Format output: JSON array dengan key MTI, RC, MSG (DE fields)
 * Digunakan untuk membangun request ke digital server.
 *
 * Untuk INQUIRY (MTI=010), field yang dikirim:
 *   DE003  = processing code (ex: "231041" untuk payment inquiry)
 *   DE004  = amount (12 digit, ex: "000000000000")
 *   DE012  = local time (HHmm, ex: "1430")
 *   DE013  = local date (ddMM, ex: "1206")
 *   DE037  = retrieval reference number (12 digit)
 *   DE044  = additional response data (biasanya "0")
 *   DE048  = additional data (kode transaksi, ex: "0601*1001*INQTFDANA~~BLTRFAG")
 *   DE052  = PIN data (64 zero untuk inquiry)
 *   DE061  = SIMSerial / device identifier
 *   DE102  = account identification 1 (nomor rekening tujuan)
 *   DE103  = account identification 2 (kode bank tujuan)
 */
function buildISO8583Request(array $params, string $accessToken): array {
    $jenisTF       = strtoupper($params['jenis_transfer'] ?? 'TFDANA');
    $nomorRekening = preg_replace('/\D/', '', $params['nomor_rekening'] ?? '');
    $kodeBank      = $params['kode_bank'] ?? '';
    $nominal       = (int)preg_replace('/\D/', '', $params['nominal'] ?? '0');

    // DE004: nominal dalam format 12 digit + "00" (2 digit desimal)
    $de004 = str_pad($nominal, 10, '0', STR_PAD_LEFT) . '00';

    // DE012: HHmm (jam:menit sekarang)
    $de012 = date('Hi');

    // DE013: ddMM (tanggal:bulan sekarang)
    $de013 = date('dm');

    // DE037: retrieval reference number 12 digit (nomor unik transaksi)
    $de037 = date('His') . str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

    // DE048: kode transaksi sesuai jenis transfer
    $de048 = buildDE048($jenisTF);

    // DE052: PIN block (64 zero untuk inquiry)
    $de052 = str_repeat('0', 64);

    // DE061: SIM Serial / device identifier agen
    $de061 = DE061_SIM_SERIAL;

    // DE102: nomor rekening tujuan
    $de102 = $nomorRekening;

    // DE103: kode bank tujuan
    $de103 = $kodeBank;

    // Bangun struktur ISO 8583-like JSON sesuai MTI=010 (DIGITAL_BANK_INQUIRY)
    $msg = [
        'DE003' => '231041',    // processing code inquiry payment
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

    $isoRequest = [
        'MTI' => '010',         // DIGITAL_BANK_INQUIRY
        'MSG' => $msg,
    ];

    return $isoRequest;
}

/**
 * Kirim request inquiry ke digital.sis1.net
 *
 * Sesuai source code (mbanking.controller.php):
 *   $cM  = "cCode=" . $cB;  ← body form-urlencoded
 *   $cU  = GetConfig('s')   ← URL digital server
 *   $vaH = array(
 *     'authorization: ' . hash('sha256', $cM . SNow()),
 *     'identity: '      . GetConfig('cicd'),
 *     'datetime: '      . SNow(),
 *     'Content-Type: application/x-www-form-urlencoded'
 *   );
 *   SendHTTPPostMB($cU, $cM, '', false, $vaH);
 *
 * PENTING: authorization = SHA256(fullBodyString . timestamp)
 *          bukan SHA256(json . timestamp) — melainkan SHA256("cCode={json}" . timestamp)
 */
function sendInquiryRequest(array $isoRequest, string $accessToken): array {
    $cB  = json_encode($isoRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $cM  = 'cCode=' . $cB;
    $cU  = URL_DIGITAL;
    $dNs = SNow();

    // Authorization = SHA256(body_string . timestamp)
    $authorization = hash('sha256', $cM . $dNs);

    $headers = [
        'authorization: ' . $authorization,
        'identity: '      . CICD,
        'datetime: '      . $dNs,
        'Content-Type: application/x-www-form-urlencoded',
        // Tambah Authorization Bearer sesuai OAuth token
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
 * ISO2Array — parse response ISO 8583 dari digital server
 *
 * Response dari digital server bisa berupa:
 *   a) JSON wrapper: {"data": "...ISO_JSON_STRING...", "RC": "00", ...}
 *   b) JSON langsung array ISO: {"MTI":"010","RC":"00","MSG":{...}}
 *   c) String ISO terenkapsulasi
 *
 * Sesuai source:
 *   $vaResponse = json_decode($cResponse, 1);
 *   $vaResponse = json_decode($vaResponse["data"], 1);  // unwrap
 *   $vaResponse = MBankingFunc::ISO2Array($cResponse);  // parse ISO
 */
function parseISO8583Response(array $httpResult): array {
    $raw  = $httpResult['raw'] ?? '';
    $data = $httpResult['data'] ?? [];

    // Coba unwrap {"data": "..."} jika ada
    if (!empty($data['data']) && is_string($data['data'])) {
        $inner = json_decode($data['data'], true);
        if (is_array($inner)) {
            $data = $inner;
        }
    }

    // Jika response sudah berupa array ISO langsung
    if (isset($data['MTI'])) {
        return $data;
    }

    // Fallback: kembalikan data apa adanya
    return $data;
}

/**
 * Fungsi utama: Inquiry Transfer Bank
 */
function inquiryTransferBank(array $params): array {
    $debug = [];

    // ── Step 1: Ambil Token ────────────────────────────────────
    $tokenResult = getAccessToken();
    $debug['step1_get_token'] = $tokenResult;

    if (!$tokenResult['success']) {
        return [
            'success' => false,
            'step'    => 'get_token',
            'rc'      => 'XT',
            'message' => 'Gagal mendapatkan token: ' . ($tokenResult['error'] ?? 'Unknown error'),
            'debug'   => $debug,
        ];
    }

    $accessToken = $tokenResult['token'];

    // ── Step 2: Bangun ISO Request ─────────────────────────────
    $isoRequest = buildISO8583Request($params, $accessToken);
    $debug['step2_iso_request'] = $isoRequest;

    // ── Step 3: Kirim ke Digital Server ───────────────────────
    $httpResult = sendInquiryRequest($isoRequest, $accessToken);
    $debug['step3_http_result'] = $httpResult;

    // ── Step 4: Parse Response ─────────────────────────────────
    $isoResponse = parseISO8583Response($httpResult);
    $debug['step4_iso_response'] = $isoResponse;

    // Ambil RC dari response
    $rc      = $isoResponse['RC']  ?? $isoResponse['rc'] ?? ($httpResult['http_code'] == 200 ? '00' : 'XT');
    $msgData = $isoResponse['MSG'] ?? $isoResponse;

    // Parsing field-field dari MSG (hasil ISO2Array)
    // Sesuai source: tagihan (nama rekening), DE004 (biaya admin), DE102 (rekening), dll.
    $tagihan      = '';
    $namaRekening = '';
    $biayaAdmin   = 0;

    if (is_string($msgData)) {
        // MSG bisa berupa string tagihan langsung
        $tagihan = $msgData;
    } elseif (is_array($msgData)) {
        // Atau array dengan field-field ISO
        $tagihan      = $msgData['DE048'] ?? $msgData['tagihan'] ?? $msgData['message'] ?? '';
        $namaRekening = $msgData['DE048'] ?? $msgData['NamaRekening'] ?? '';
        // DE004 = biaya admin (dalam format "0000000000{nominal}00")
        $de004Raw     = $msgData['DE004'] ?? '000000000000';
        $biayaAdmin   = (int)substr($de004Raw, 0, -2); // hapus 2 digit desimal
        // DE102 = nomor rekening konfirmasi
        $nomorKonfirm = $msgData['DE102'] ?? $params['nomor_rekening'] ?? '';
    }

    $success = in_array($rc, ['00', '19'], true);

    // Parse nama pemilik rekening dari tagihan (format Assist: "NamaBank|NamaRekening|..." atau string langsung)
    $beneficiaryName = '-';
    $beneficiaryNo   = $params['nomor_rekening'] ?? '';
    if (!empty($tagihan)) {
        $parts = explode('|', $tagihan);
        if (count($parts) >= 2) {
            $beneficiaryName = $parts[1] ?? '-';
            $beneficiaryNo   = $parts[0] ?? $beneficiaryNo;
        } elseif (count($parts) === 1 && strlen($tagihan) > 5) {
            $beneficiaryName = $tagihan;
        }
    }

    return [
        'success'         => $success,
        'step'            => 'inquiry',
        'rc'              => $rc,
        'message'         => $tagihan ?: ($success ? 'Inquiry berhasil' : 'Inquiry gagal'),
        'beneficiary'     => [
            'account_no'   => $beneficiaryNo,
            'account_name' => $beneficiaryName,
            'bank_code'    => $params['kode_bank']  ?? '',
            'bank_name'    => $params['nama_bank']  ?? '',
        ],
        'biaya_admin'     => $biayaAdmin,
        'jenis_transfer'  => strtoupper($params['jenis_transfer'] ?? 'TFDANA'),
        'de048'           => buildDE048($params['jenis_transfer'] ?? 'TFDANA'),
        'token_from_cache'=> $tokenResult['from_cache'] ?? false,
        'debug'           => $debug,
    ];
}

// ============================================================
// HANDLE POST REQUEST
// ============================================================
$result       = null;
$errorMessage = '';
$formData     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inquiry') {

    $formData = [
        'nomor_rekening' => trim($_POST['nomor_rekening']  ?? ''),
        'kode_bank'      => trim($_POST['kode_bank']       ?? ''),
        'kode_bank_manual'=> trim($_POST['kode_bank_manual'] ?? ''),
        'nama_bank'      => trim($_POST['nama_bank']       ?? ''),
        'nominal'        => trim($_POST['nominal']         ?? '0'),
        'jenis_transfer' => strtoupper(trim($_POST['jenis_transfer'] ?? 'TFDANA')),
    ];

    // Jika input kode bank manual diisi, gunakan itu
    if (!empty($formData['kode_bank_manual'])) {
        $formData['kode_bank'] = $formData['kode_bank_manual'];
    }

    // Validasi input
    if (empty($formData['nomor_rekening'])) {
        $errorMessage = 'Nomor rekening tujuan wajib diisi!';
    } elseif (empty($formData['kode_bank'])) {
        $errorMessage = 'Kode bank tujuan wajib diisi!';
    } elseif (KODE_AGEN === '') {
        $errorMessage = '⚠️ KODE_AGEN tidak terdeteksi! Pastikan ada file cache snap_tf_bank_permata.cache di storage/cds/cache/ dalam direktori assist-switching_v3_pro.';
    } elseif (OAUTH_CLIENT_ID === '' && OAUTH_USERNAME === '') {
        $errorMessage = '⚠️ Kredensial OAuth belum dikonfigurasi! Buat file .assist.env dengan isi: OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, OAUTH_USERNAME, OAUTH_PASSWORD.';
    } else {
        $result = inquiryTransferBank($formData);
    }
}

// ============================================================
// DATA REFERENSI
// ============================================================
$daftarBank = [
    ''       => '-- Pilih Bank --',
    '008'    => 'Bank Mandiri',
    '009'    => 'BNI',
    '002'    => 'BRI',
    '014'    => 'BCA',
    '013'    => 'Bank Permata',
    '022'    => 'CIMB Niaga',
    '016'    => 'Maybank',
    '011'    => 'Danamon',
    '028'    => 'OCBC NISP',
    '200'    => 'BTN',
    '019'    => 'Panin Bank',
    '023'    => 'UOB',
    '076'    => 'BPD Bali',
    '110'    => 'BJB',
    '111'    => 'Bank DKI',
    '112'    => 'BPD Jateng',
    '113'    => 'BPD DIY',
    '114'    => 'BPD Jatim',
    '116'    => 'BPD Sumut',
    '118'    => 'BPD Sulsel',
    '119'    => 'BPD NTB',
    '120'    => 'BPD Kalbar',
    '121'    => 'BPD Kalteng',
    '122'    => 'BPD Kalsel',
    '123'    => 'BPD Kaltim',
    '131'    => 'BPD Sulut',
    '132'    => 'BPD Sulteng',
    '133'    => 'BPD Sultra',
    '335'    => 'BNC (Neo Commerce)',
    '422'    => 'BSI',
    '503'    => 'Bank Jago',
    '506'    => 'SeaBank',
    '513'    => 'Bank Ina',
    '553'    => 'Bank DBS',
    '688'    => 'HSBC Indonesia',
];

// Cek apakah konfigurasi lengkap
$isConfigured = (KODE_AGEN !== '' && OAUTH_CLIENT_ID !== '' && OAUTH_USERNAME !== '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inquiry Transfer Bank — SIS/Assist Middleware</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #003d82 0%, #006eff 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 960px; margin: 0 auto; }

        /* ── Header ── */
        .page-header { text-align: center; color: #fff; margin-bottom: 24px; }
        .header-badge {
            display: inline-flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,.13);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 50px;
            padding: 9px 22px;
            margin-bottom: 12px;
        }
        .badge-permata {
            background: #003d82; color: #fff;
            border-radius: 8px; padding: 3px 9px;
            font-size: .72rem; font-weight: 800; letter-spacing: 1px;
        }
        .badge-assist {
            background: #f59e0b; color: #1c1917;
            border-radius: 8px; padding: 3px 9px;
            font-size: .72rem; font-weight: 800; letter-spacing: 1px;
        }
        .badge-sep { color: rgba(255,255,255,.5); }
        .page-header h1 { font-size: 1.85rem; font-weight: 800; }
        .page-header p  { font-size: .93rem; opacity: .82; margin-top: 5px; }

        /* ── Cards ── */
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 36px rgba(0,0,0,.18);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(90deg, #003d82, #0057c2);
            color: #fff;
            padding: 15px 22px;
            display: flex; align-items: center; gap: 10px;
            font-size: .97rem; font-weight: 700;
        }
        .card-header .ch-icon {
            width: 30px; height: 30px;
            background: rgba(255,255,255,.2);
            border-radius: 7px;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px;
        }
        .card-header .ch-badge {
            margin-left: auto;
            background: rgba(255,255,255,.18);
            border-radius: 6px;
            padding: 2px 9px;
            font-size: .72rem;
            letter-spacing: .4px;
        }
        .card-header .ch-badge.status-ready { background: #16a34a; color: #fff; font-weight: 800; }
        .card-header .ch-badge.status-warn  { background: #d97706; color: #fff; font-weight: 800; }
        .card-header .ch-badge.status-error { background: #dc2626; color: #fff; font-weight: 800; }
        .card-body { padding: 24px; }

        /* ── Config Info ── */
        .config-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }
        @media (max-width: 640px) { .config-grid { grid-template-columns: 1fr 1fr; } }
        .cfg-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 12px;
        }
        .cfg-item .cfg-label { font-size: .72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .cfg-item .cfg-value { font-size: .88rem; font-weight: 600; color: #1e293b; margin-top: 3px; font-family: monospace; }
        .cfg-item .cfg-value.ok   { color: #059669; }
        .cfg-item .cfg-value.warn { color: #d97706; }
        .cfg-item .cfg-value.err  { color: #dc2626; }

        /* ── Auto-Detection Panel ── */
        .det-panel {
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 18px;
            border: 1.5px solid #e2e8f0;
        }
        .det-panel-header {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 16px;
            font-weight: 800; font-size: .82rem;
            letter-spacing: .3px;
        }
        .det-panel-header.ready  { background: #dcfce7; color: #14532d; border-bottom: 1.5px solid #bbf7d0; }
        .det-panel-header.warn   { background: #fef9c3; color: #713f12; border-bottom: 1.5px solid #fde68a; }
        .det-panel-header.error  { background: #fee2e2; color: #7f1d1d; border-bottom: 1.5px solid #fca5a5; }
        .det-badge {
            margin-left: auto;
            border-radius: 20px; padding: 2px 11px;
            font-size: .7rem; font-weight: 800; letter-spacing: .5px;
        }
        .det-badge.ready { background: #16a34a; color: #fff; }
        .det-badge.warn  { background: #d97706; color: #fff; }
        .det-badge.error { background: #dc2626; color: #fff; }
        .det-rows { background: #fff; }
        .det-row {
            display: grid;
            grid-template-columns: 22px 160px 1fr auto;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: .8rem;
        }
        .det-row:last-child { border-bottom: none; }
        .det-row:hover { background: #f8fafc; }
        .det-icon { font-size: 14px; text-align: center; }
        .det-key  { color: #64748b; font-family: monospace; font-size: .76rem; }
        .det-val  { color: #1e293b; font-weight: 600; word-break: break-all; }
        .det-val.ok   { color: #059669; }
        .det-val.warn { color: #d97706; }
        .det-val.err  { color: #dc2626; }
        .det-pill {
            font-size: .65rem; font-weight: 700; border-radius: 10px;
            padding: 1px 8px; white-space: nowrap;
        }
        .det-pill.ok   { background: #dcfce7; color: #14532d; }
        .det-pill.warn { background: #fef9c3; color: #713f12; }
        .det-pill.err  { background: #fee2e2; color: #991b1b; }
        .det-pill.info { background: #dbeafe; color: #1e3a8a; }
        .det-section-label {
            padding: 5px 16px 3px;
            font-size: .67rem; font-weight: 800; color: #94a3b8;
            text-transform: uppercase; letter-spacing: .8px;
            background: #f8fafc; border-bottom: 1px solid #f1f5f9;
        }

        /* ── Alert ── */
        .alert {
            border-radius: 10px; padding: 13px 17px;
            margin-bottom: 16px; font-size: .9rem;
            display: flex; align-items: flex-start; gap: 10px;
        }
        .alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }
        .alert-warning { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }
        .alert-info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1e3a8a; }
        .alert .al-ic  { font-size: 17px; flex-shrink: 0; }

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
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            padding: 9px 13px;
            font-size: .92rem;
            color: #111827;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.13);
        }
        .field-hint { font-size: .75rem; color: #6b7280; }

        /* ── Transfer Type Tabs ── */
        .type-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        .type-tab {
            flex: 1; min-width: 90px;
            padding: 10px 8px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            background: #f9fafb;
            transition: all .2s;
        }
        .type-tab input[type=radio] { display: none; }
        .type-tab .ti  { font-size: 20px; display: block; }
        .type-tab .tl  { font-size: .78rem; font-weight: 700; color: #374151; display: block; }
        .type-tab .td  { font-size: .69rem; color: #6b7280; display: block; }
        .type-tab .tde048 { font-size: .65rem; color: #9ca3af; display: block; font-family: monospace; }
        .type-tab:has(input:checked), .type-tab.active {
            border-color: #2563eb; background: #eff6ff;
        }
        .type-tab:has(input:checked) .tl, .type-tab.active .tl { color: #1d4ed8; }

        /* ── Submit Button ── */
        .btn-submit {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
            color: #fff; border: none; border-radius: 10px;
            font-size: .97rem; font-weight: 700; cursor: pointer;
            transition: box-shadow .2s; margin-top: 6px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { box-shadow: 0 4px 20px rgba(29,78,216,.4); }
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
            max-height: 520px; overflow-y: auto;
        }
        .debug-content.show { display: block; }
        .dc { color: #38bdf8; }

        /* ── Spinner ── */
        .spinner {
            display: none; width: 18px; height: 18px;
            border: 3px solid rgba(255,255,255,.3);
            border-top-color: #fff; border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Footer ── */
        .footer { text-align: center; color: rgba(255,255,255,.55); font-size: .8rem; padding: 18px 0 8px; }
    </style>
</head>
<body>
<div class="container">

    <!-- ── Header ── -->
    <div class="page-header">
        <div class="header-badge">
            <span class="badge-permata">PERMATA</span>
            <span class="badge-sep">via</span>
            <span class="badge-assist">ASSIST</span>
            <span style="color:rgba(255,255,255,.65); font-size:.82rem;">Switching v3 Pro</span>
        </div>
        <h1>🏦 Inquiry Transfer Bank</h1>
        <p>Cek rekening tujuan via SIS/Assist Digital Switching Middleware (MTI=010, INQTFDANA / INQLLG / INQRTGS)</p>
    </div>

    <!-- ── Config Status ── -->
    <div class="card">
        <div class="card-header">
            <div class="ch-icon">⚙️</div>
            Status Auto-Detection &amp; Konfigurasi
            <?php
                $totalOk  = 0; $totalErr = 0; $totalWarn = 0;
                // hitung status kritis
                if (KODE_AGEN !== '')       $totalOk++; else $totalErr++;
                if (OAUTH_CLIENT_ID !== '') $totalOk++; else $totalErr++;
                if (OAUTH_USERNAME !== '')  $totalOk++; else $totalErr++;
                if ($_ASSIST_ROOT !== '')   $totalOk++; else $totalWarn++;
                if ($_ASSIST_BPR_ROOT !== '') $totalOk++; else $totalWarn++;
                $overallClass = $totalErr > 0 ? 'error' : ($totalWarn > 0 ? 'warn' : 'ready');
                $overallLabel = $totalErr > 0 ? '❌ Belum Siap' : ($totalWarn > 0 ? '⚠️ Sebagian' : '✅ Siap');
            ?>
            <span class="ch-badge status-<?= $overallClass ?>"><?= $overallLabel ?></span>
        </div>
        <div class="card-body" style="padding:18px 24px;">

        <?php
        // ── Tentukan status tiap komponen ────────────────────────────
        $assistRootOk  = $_ASSIST_ROOT !== '';
        $bprRootOk     = $_ASSIST_BPR_ROOT !== '';
        $localCfgOk    = !empty($_LOCAL_CONFIG);
        $urlTokenOk    = !empty($_LOCAL_CONFIG['_URL_GET_TOKEN_']);
        $urlDigitalOk  = !empty($_LOCAL_CONFIG['s']);
        $cicdOk        = !empty($_LOCAL_CONFIG['cicd']);
        $kodeAgenOk    = KODE_AGEN !== '';
        $mftfiOk       = !empty($_CACHE_TF['mftfi'] ?? null);
        $cacheFileOk   = file_exists(CACHE_FILE_TF);
        $oauthIdOk     = OAUTH_CLIENT_ID !== '';
        $oauthSecOk    = OAUTH_CLIENT_SECRET !== '';
        $oauthUserOk   = OAUTH_USERNAME !== '';
        $oauthPassOk   = OAUTH_PASSWORD !== '';
        $corpIdOk      = OAUTH_CORPORATE_ID !== '';
        $rsaKeyOk      = OAUTH_TOKEN_PRIVATE !== '';
        $de061Ok       = DE061_SIM_SERIAL !== '';
        $envFileOk     = !empty($_BPR_ENV['_env_file'] ?? ($_ASSIST_ENV['_env_file'] ?? ''));
        $oauthSrc      = $_DETECTION_LOG['oauth_source'] ?? '';

        // ── helper render satu baris ─────────────────────────────────
        $row = function(string $icon, string $key, string $val, string $cls, string $pill, string $pillCls) {
            echo '<div class="det-row">';
            echo '<span class="det-icon">' . $icon . '</span>';
            echo '<span class="det-key">' . htmlspecialchars($key) . '</span>';
            echo '<span class="det-val ' . $cls . '">' . htmlspecialchars($val) . '</span>';
            echo '<span class="det-pill ' . $pillCls . '">' . $pill . '</span>';
            echo '</div>';
        };

        // ── Blok 1: Path Root ─────────────────────────────────────────
        $panelCls1 = ($assistRootOk && $bprRootOk) ? 'ready' : ($assistRootOk || $bprRootOk ? 'warn' : 'error');
        ?>

        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls1 ?>">
                📂 Path Root
                <span class="det-badge <?= $panelCls1 ?>"><?= $panelCls1 === 'ready' ? 'READY' : ($panelCls1 === 'warn' ? 'SEBAGIAN' : 'TIDAK DITEMUKAN') ?></span>
            </div>
            <div class="det-rows">
                <div class="det-section-label">assist-switching_v3_pro</div>
                <?php $row(
                    $assistRootOk ? '✅' : '❌',
                    'assist_root',
                    $assistRootOk ? $_ASSIST_ROOT : 'Tidak ditemukan',
                    $assistRootOk ? 'ok' : 'err',
                    $assistRootOk ? 'FOUND' : 'MISSING',
                    $assistRootOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $localCfgOk ? '✅' : '⚠️',
                    'local_config.php',
                    $localCfgOk ? 'Terbaca (' . count($_LOCAL_CONFIG) . ' keys)' : 'Tidak ditemukan — URL pakai default',
                    $localCfgOk ? 'ok' : 'warn',
                    $localCfgOk ? 'OK' : 'DEFAULT',
                    $localCfgOk ? 'ok' : 'warn'
                ); ?>
                <?php $row(
                    $cacheFileOk ? '✅' : '❌',
                    'cache snap_tf_bank_permata',
                    $cacheFileOk ? basename(CACHE_FILE_TF) : 'Belum ada — KODE_AGEN tidak dapat dibaca',
                    $cacheFileOk ? 'ok' : 'err',
                    $cacheFileOk ? 'ADA' : 'MISSING',
                    $cacheFileOk ? 'ok' : 'err'
                ); ?>

                <div class="det-section-label">assist-bpr.net</div>
                <?php $row(
                    $bprRootOk ? '✅' : '⚠️',
                    'assist_bpr_root',
                    $bprRootOk ? $_ASSIST_BPR_ROOT : 'Tidak ditemukan — fallback ke .assist.env',
                    $bprRootOk ? 'ok' : 'warn',
                    $bprRootOk ? 'FOUND' : 'MISSING',
                    $bprRootOk ? 'ok' : 'warn'
                ); ?>
                <?php $row(
                    $envFileOk ? '✅' : '⚠️',
                    'env file aktif',
                    $envFileOk
                        ? basename($_BPR_ENV['_env_file'] ?? ($_ASSIST_ENV['_env_file'] ?? '-'))
                        : 'Tidak ada — isi manual via .assist.env',
                    $envFileOk ? 'ok' : 'warn',
                    $envFileOk ? 'LOADED' : 'NONE',
                    $envFileOk ? 'ok' : 'warn'
                ); ?>
            </div>
        </div>

        <?php
        // ── Blok 2: Nilai Agen ────────────────────────────────────────
        $panelCls2 = ($kodeAgenOk && $mftfiOk) ? 'ready' : ($kodeAgenOk || $mftfiOk ? 'warn' : 'error');
        ?>
        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls2 ?>">
                🏦 Data Agen
                <span class="det-badge <?= $panelCls2 ?>"><?= $kodeAgenOk ? 'TERDETEKSI' : 'BELUM ADA' ?></span>
            </div>
            <div class="det-rows">
                <?php $row(
                    $kodeAgenOk ? '✅' : '❌',
                    'KODE_AGEN',
                    $kodeAgenOk ? KODE_AGEN : 'Tidak terdeteksi — butuh cache snap_tf_bank_permata.cache',
                    $kodeAgenOk ? 'ok' : 'err',
                    $kodeAgenOk ? 'AUTO' : 'MISSING',
                    $kodeAgenOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $mftfiOk ? '✅' : '⚠️',
                    'MFTFI',
                    MFTFI !== '' ? MFTFI . ($mftfiOk ? ' (dari cache)' : ' (default)') : '—',
                    $mftfiOk ? 'ok' : 'warn',
                    $mftfiOk ? 'AUTO' : 'DEFAULT',
                    $mftfiOk ? 'ok' : 'warn'
                ); ?>
                <?php $row(
                    $corpIdOk ? '✅' : '⚠️',
                    'OAUTH_CORPORATE_ID',
                    $corpIdOk ? OAUTH_CORPORATE_ID : 'Kosong (opsional)',
                    $corpIdOk ? 'ok' : 'warn',
                    $corpIdOk ? 'AUTO' : 'OPSIONAL',
                    $corpIdOk ? 'ok' : 'info'
                ); ?>
            </div>
        </div>

        <?php
        // ── Blok 3: OAuth Credentials ────────────────────────────────
        $panelCls3 = ($oauthIdOk && $oauthSecOk && $oauthUserOk) ? 'ready' : ($oauthIdOk || $oauthUserOk ? 'warn' : 'error');
        $srcLabel  = '';
        if (!empty($_BPR_ENV['_env_file']))        $srcLabel = 'bpr-env: ' . basename($_BPR_ENV['_env_file']);
        elseif (!empty($_ASSIST_ENV['_env_file'])) $srcLabel = '.assist.env';
        ?>
        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls3 ?>">
                🔑 OAuth Credentials
                <?php if ($srcLabel): ?>
                <span style="font-size:.72rem;opacity:.75;margin-left:4px;">← <?= htmlspecialchars($srcLabel) ?></span>
                <?php endif; ?>
                <span class="det-badge <?= $panelCls3 ?>"><?= $panelCls3 === 'ready' ? 'LENGKAP' : ($panelCls3 === 'warn' ? 'SEBAGIAN' : 'KOSONG') ?></span>
            </div>
            <div class="det-rows">
                <?php $row(
                    $oauthIdOk ? '✅' : '❌',
                    'OAUTH_CLIENT_ID',
                    $oauthIdOk ? OAUTH_CLIENT_ID : 'Belum diisi',
                    $oauthIdOk ? 'ok' : 'err',
                    $oauthIdOk ? 'OK' : 'MISSING',
                    $oauthIdOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $oauthSecOk ? '✅' : '❌',
                    'OAUTH_CLIENT_SECRET',
                    $oauthSecOk ? substr(OAUTH_CLIENT_SECRET, 0, 8) . str_repeat('•', 12) : 'Belum diisi',
                    $oauthSecOk ? 'ok' : 'err',
                    $oauthSecOk ? 'OK' : 'MISSING',
                    $oauthSecOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $oauthUserOk ? '✅' : '❌',
                    'OAUTH_USERNAME',
                    $oauthUserOk ? OAUTH_USERNAME : 'Belum diisi',
                    $oauthUserOk ? 'ok' : 'err',
                    $oauthUserOk ? 'OK' : 'MISSING',
                    $oauthUserOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $oauthPassOk ? '✅' : '❌',
                    'OAUTH_PASSWORD',
                    $oauthPassOk ? str_repeat('•', 8) : 'Belum diisi',
                    $oauthPassOk ? 'ok' : 'err',
                    $oauthPassOk ? 'OK' : 'MISSING',
                    $oauthPassOk ? 'ok' : 'err'
                ); ?>
                <?php $row(
                    $de061Ok ? '✅' : '⚠️',
                    'DE061_SIM_SERIAL',
                    $de061Ok ? DE061_SIM_SERIAL : 'Kosong (opsional)',
                    $de061Ok ? 'ok' : 'warn',
                    $de061Ok ? 'OK' : 'OPSIONAL',
                    $de061Ok ? 'ok' : 'info'
                ); ?>
                <?php $row(
                    $rsaKeyOk ? '✅' : '⚠️',
                    'OAUTH_TOKEN_PRIVATE',
                    $rsaKeyOk ? 'RSA key tersedia (' . strlen(OAUTH_TOKEN_PRIVATE) . ' chars)' : 'Tidak ada (opsional)',
                    $rsaKeyOk ? 'ok' : 'warn',
                    $rsaKeyOk ? 'RSA' : 'OPSIONAL',
                    $rsaKeyOk ? 'ok' : 'info'
                ); ?>
            </div>
        </div>

        <?php
        // ── Blok 4: Endpoint URL ──────────────────────────────────────
        $panelCls4 = 'ready';
        ?>
        <div class="det-panel">
            <div class="det-panel-header <?= $panelCls4 ?>">
                🌐 Endpoint &amp; Identity
                <span class="det-badge <?= $panelCls4 ?>">READY</span>
            </div>
            <div class="det-rows">
                <?php $row(
                    '✅', 'URL_GET_TOKEN',
                    URL_GET_TOKEN,
                    'ok', $urlTokenOk ? 'CONFIG' : 'DEFAULT', $urlTokenOk ? 'ok' : 'info'
                ); ?>
                <?php $row(
                    '✅', 'URL_DIGITAL (TF)',
                    URL_DIGITAL,
                    'ok', $urlDigitalOk ? 'CONFIG' : 'DEFAULT', $urlDigitalOk ? 'ok' : 'info'
                ); ?>
                <?php $row(
                    $cicdOk ? '✅' : '⚠️',
                    'CICD Identity',
                    substr(CICD, 0, 20) . '…',
                    'ok', $cicdOk ? 'CONFIG' : 'DEFAULT', $cicdOk ? 'ok' : 'info'
                ); ?>
            </div>
        </div>

        <?php if (!$isConfigured): ?>
        <div class="alert alert-warning" style="margin-bottom:0;">
            <span class="al-ic">⚠️</span>
            <div>
                <strong>Konfigurasi belum lengkap.</strong>
                Buat file <code style="background:#fef3c7;padding:1px 5px;border-radius:3px;">.assist.env</code>
                di direktori yang sama dengan script ini:
                <pre style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px;margin:8px 0;font-size:.8rem;overflow-x:auto;">OAUTH_CLIENT_ID=isi_client_id
OAUTH_CLIENT_SECRET=isi_client_secret
OAUTH_USERNAME=Assist
OAUTH_PASSWORD=Irac
DE061_SIM_SERIAL=</pre>
                <strong>KODE_AGEN</strong> &amp; <strong>MFTFI</strong> dibaca otomatis dari
                <code>storage/cds/cache/*snap_tf_bank_permata.cache</code>.
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ── Form Card ── -->
    <div class="card">
        <div class="card-header">
            <div class="ch-icon">🔍</div>
            Form Inquiry Transfer Bank
            <span class="ch-badge">ISO 8583 MTI=010</span>
        </div>
        <div class="card-body">

            <?php if ($errorMessage): ?>
            <div class="alert alert-error">
                <span class="al-ic">❌</span>
                <div><?= htmlspecialchars($errorMessage) ?></div>
            </div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-bottom:16px;">
                <span class="al-ic">ℹ️</span>
                <div>
                    Request dikirim ke <strong>digital.sis1.net</strong> menggunakan format ISO 8583 (MTI=010).
                    Header: <code>authorization=SHA256(body+timestamp)</code>, <code>identity=cicd</code>.
                    DE048 berisi kode transaksi Assist (<code>INQTFDANA</code> / <code>INQLLG</code> / <code>INQRTGS</code>).
                </div>
            </div>

            <form method="POST" id="inquiryForm" onsubmit="handleSubmit(event)">
                <input type="hidden" name="action" value="inquiry">

                <!-- Jenis Transfer -->
                <div class="section-title">Jenis Transfer</div>
                <div class="form-group full" style="margin-bottom:16px;">
                    <div class="type-tabs">
                        <label class="type-tab <?= ($formData['jenis_transfer'] ?? 'TFDANA') === 'TFDANA' ? 'active' : '' ?>">
                            <input type="radio" name="jenis_transfer" value="TFDANA"
                                <?= ($formData['jenis_transfer'] ?? 'TFDANA') === 'TFDANA' ? 'checked' : '' ?>>
                            <span class="ti">💸</span>
                            <span class="tl">Transfer Dana</span>
                            <span class="td">Online / SKN Real-Time</span>
                            <span class="tde048">DE048: INQTFDANA</span>
                        </label>
                        <label class="type-tab <?= ($formData['jenis_transfer'] ?? '') === 'LLG' ? 'active' : '' ?>">
                            <input type="radio" name="jenis_transfer" value="LLG"
                                <?= ($formData['jenis_transfer'] ?? '') === 'LLG' ? 'checked' : '' ?>>
                            <span class="ti">📋</span>
                            <span class="tl">LLG / SKN Batch</span>
                            <span class="td">Maks. Rp 500 juta</span>
                            <span class="tde048">DE048: INQLLG</span>
                        </label>
                        <label class="type-tab <?= ($formData['jenis_transfer'] ?? '') === 'RTGS' ? 'active' : '' ?>">
                            <input type="radio" name="jenis_transfer" value="RTGS"
                                <?= ($formData['jenis_transfer'] ?? '') === 'RTGS' ? 'checked' : '' ?>>
                            <span class="ti">🏛️</span>
                            <span class="tl">RTGS</span>
                            <span class="td">Di atas Rp 100 juta</span>
                            <span class="tde048">DE048: INQRTGS</span>
                        </label>
                    </div>
                </div>

                <!-- Bank & Rekening Tujuan -->
                <div class="section-title">Rekening Tujuan</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Bank Tujuan <span class="req">*</span></label>
                        <select name="kode_bank" id="kodeBank" onchange="setNamaBank(this)">
                            <?php foreach ($daftarBank as $kode => $nama): ?>
                            <option value="<?= htmlspecialchars($kode) ?>"
                                <?= ($formData['kode_bank'] ?? '') === $kode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nama) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Diisi ke DE103 dalam request ISO</div>
                    </div>

                    <div class="form-group">
                        <label>Kode Bank Manual <span class="opt">(jika tidak ada di list)</span></label>
                        <input type="text" name="kode_bank_manual" id="kodeBankManual"
                            placeholder="Contoh: 014"
                            value="<?= htmlspecialchars($formData['kode_bank_manual'] ?? '') ?>">
                    </div>

                    <input type="hidden" name="nama_bank" id="namaBank"
                        value="<?= htmlspecialchars($formData['nama_bank'] ?? '') ?>">

                    <div class="form-group">
                        <label>Nomor Rekening Tujuan <span class="req">*</span></label>
                        <input type="text" name="nomor_rekening" id="nomorRek"
                            placeholder="Contoh: 1234567890"
                            maxlength="28"
                            value="<?= htmlspecialchars($formData['nomor_rekening'] ?? '') ?>"
                            oninput="this.value=this.value.replace(/\D/g,'')">
                        <div class="field-hint">Diisi ke DE102 dalam request ISO</div>
                    </div>

                    <div class="form-group">
                        <label>Nominal Transfer (Rp) <span class="opt">(opsional)</span></label>
                        <input type="text" name="nominal" id="nominalInput"
                            placeholder="Contoh: 100000"
                            value="<?= htmlspecialchars($formData['nominal'] ?? '') ?>"
                            oninput="this.value=this.value.replace(/\D/g,'')">
                        <div class="field-hint">Diisi ke DE004 dalam request ISO</div>
                    </div>
                </div>

                <hr style="margin:20px 0;border:none;border-top:1px dashed #e5e7eb;">

                <button type="submit" class="btn-submit" id="submitBtn">
                    <span id="btnText">🔍 Kirim Inquiry Transfer</span>
                    <div class="spinner" id="spinner"></div>
                </button>
            </form>
        </div>
    </div>

    <!-- ── RESULT ── -->
    <?php if ($result !== null): ?>
    <div class="result-wrap">

        <div class="result-header <?= $result['success'] ? 'ok' : 'fail' ?>">
            <?= $result['success'] ? '✅ Inquiry Berhasil' : '❌ Inquiry Gagal' ?>
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
                    <label>Jenis Transfer</label>
                    <div class="val"><?= htmlspecialchars($result['jenis_transfer'] ?? '-') ?></div>
                </div>
                <div class="ri">
                    <label>Biaya Admin</label>
                    <div class="val">Rp <?= number_format($result['biaya_admin'] ?? 0, 0, ',', '.') ?></div>
                </div>
                <div class="ri">
                    <label>DE048</label>
                    <div class="val code"><?= htmlspecialchars($result['de048'] ?? '-') ?></div>
                </div>
                <hr class="section-sep">
                <div class="ri">
                    <label>Response Code</label>
                    <div class="val code"><?= htmlspecialchars($result['rc'] ?? '-') ?></div>
                </div>
                <div class="ri" style="grid-column:1/-1">
                    <label>Pesan / Tagihan</label>
                    <div class="val"><?= htmlspecialchars($result['message'] ?? '-') ?></div>
                </div>
            </div>
            <div style="margin-top:14px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.84rem;color:#065f46;">
                ✅ Rekening valid! Transfer dapat dilanjutkan ke
                <strong><?= htmlspecialchars($result['beneficiary']['account_name'] ?? '-') ?></strong>
                (<?= htmlspecialchars($result['beneficiary']['account_no'] ?? '-') ?>)
                di <?= htmlspecialchars($result['beneficiary']['bank_name'] ?? '-') ?>.
                <?php if ($result['token_from_cache'] ?? false): ?>
                <em style="font-size:.78rem;">(Token dari cache)</em>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="alert alert-error">
                <span class="al-ic">🚫</span>
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
                    🔧 Debug: Request / Response ISO 8583 (SHA256 Signing)
                    <span id="dbg1-icon">▼</span>
                </button>
                <div class="debug-content" id="dbg1">
<span class="dc">━━ STEP 1: GET TOKEN ━━</span>
Token URL : <?= htmlspecialchars(URL_GET_TOKEN) ?>

Dari Cache: <?= ($result['debug']['step1_get_token']['from_cache'] ?? false) ? 'YA' : 'TIDAK' ?>

Sukses    : <?= ($result['debug']['step1_get_token']['success'] ?? false) ? 'YA' : 'TIDAK' ?>


<span class="dc">━━ STEP 2: ISO 8583 REQUEST (MTI=010) ━━</span>
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
        Inquiry Transfer Bank — SIS/Assist Switching Middleware v1.6.39 &mdash;
        PHP <?= PHP_VERSION ?> &mdash; <?= SNow() ?> WIB
    </div>

</div>

<script>
const bankNames = <?= json_encode(array_filter($daftarBank, fn($v) => $v !== '-- Pilih Bank --')) ?>;

function setNamaBank(sel) {
    document.getElementById('namaBank').value = bankNames[sel.value] || '';
    if (sel.value) document.getElementById('kodeBankManual').value = '';
}

document.getElementById('kodeBankManual').addEventListener('input', function() {
    if (this.value) {
        document.getElementById('kodeBank').value = '';
        document.getElementById('namaBank').value  = '';
    }
});

function handleSubmit(e) {
    const manual = document.getElementById('kodeBankManual').value.trim();
    if (manual) document.getElementById('kodeBank').value = manual;
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

document.querySelectorAll('.type-tab input[type=radio]').forEach(function(r) {
    r.addEventListener('change', function() {
        document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
        r.closest('.type-tab').classList.add('active');
    });
});
</script>
</body>
</html>
