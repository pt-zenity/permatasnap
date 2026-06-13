<?php
/**
 * router.php — PHP Built-in Server Router
 * =========================================
 * Digunakan dengan: php -S 0.0.0.0:3000 router.php
 *
 * Routes:
 *   GET  /                          → Landing page (daftar tool)
 *   GET  /inquiry_transfer_permata.php  → Inquiry Transfer Bank (TF)
 *   POST /inquiry_transfer_permata.php  → Submit inquiry TF
 *   GET  /inquiry_bifast_permata.php    → Inquiry BIFAST
 *   POST /inquiry_bifast_permata.php    → Submit inquiry BIFAST
 *   GET  /favicon.ico               → Favicon inline (SVG→ICO)
 *   GET  /health                    → Health check JSON
 *   *    *                          → 404 halaman HTML
 */

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── 1. File statis sungguhan (CSS, JS, images, dll.) ─────────────
// Kembalikan false agar built-in server serve langsung dari disk.
if ($uri !== '/' && $uri !== '/favicon.ico') {
    $physical = __DIR__ . $uri;
    if (file_exists($physical) && is_file($physical) && !preg_match('/\.php$/i', $uri)) {
        return false;
    }
}

// ── 2. Favicon — inline SVG data URI (menghilangkan 404 di browser) ─
if ($uri === '/favicon.ico' || $uri === '/favicon.svg') {
    // SVG mini: ikon bank/currency biru
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
         . '<rect width="32" height="32" rx="6" fill="#1d4ed8"/>'
         . '<text x="16" y="23" font-size="20" text-anchor="middle" fill="#fff">🏦</text>'
         . '</svg>';
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    echo $svg;
    exit;
}

// ── 3. Health check endpoint ─────────────────────────────────────
if ($uri === '/health' || $uri === '/ping') {
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'ok',
        'php'     => phpversion(),
        'time'    => date('Y-m-d H:i:s'),
        'routes'  => [
            '/'                            => 'Landing page',
            '/inquiry_transfer_permata.php' => 'Inquiry Transfer Bank (TF)',
            '/inquiry_bifast_permata.php'   => 'Inquiry BIFAST',
        ],
    ]);
    exit;
}

// ── 4. Landing page ───────────────────────────────────────────────
if ($uri === '/' || $uri === '/index.php') {
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permata Snap — Assist Middleware Tools</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #e2e8f0; min-height: 100vh; margin: 0;
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .wrap { text-align: center; max-width: 580px; width: 100%; }
        .logo { font-size: 3rem; margin-bottom: 12px; }
        h1 { font-size: 1.8rem; margin: 0 0 8px; font-weight: 800; }
        .subtitle { color: #94a3b8; margin: 0 0 12px; font-size: .95rem; }
        .php-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #1e293b; border: 1px solid #334155;
            border-radius: 20px; padding: 4px 14px; font-size: .75rem;
            color: #60a5fa; margin-bottom: 36px;
        }
        .php-badge span { color: #94a3b8; }
        .cards { display: grid; gap: 16px; }
        a.tool-card {
            display: block; background: #1e293b; border: 1.5px solid #334155;
            border-radius: 16px; padding: 24px 28px; text-decoration: none;
            color: inherit; transition: border-color .2s, transform .15s, box-shadow .2s;
            text-align: left;
        }
        a.tool-card:hover {
            border-color: #3b82f6; transform: translateY(-3px);
            box-shadow: 0 8px 32px rgba(59,130,246,.15);
        }
        .tc-top { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
        .tc-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 1.6rem;
            flex-shrink: 0;
        }
        .tc-icon.blue  { background: #1d3a6b; }
        .tc-icon.green { background: #064e3b; }
        .tc-title { font-size: 1.05rem; font-weight: 700; margin-bottom: 3px; }
        .tc-desc  { font-size: .82rem; color: #64748b; }
        .tc-meta  { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .tc-pill {
            background: #0f172a; border: 1px solid #1e3a5f;
            color: #60a5fa; border-radius: 6px;
            padding: 2px 9px; font-size: .7rem; font-family: monospace;
        }
        .tc-pill.green { border-color: #065f46; color: #34d399; }
        .divider {
            margin: 28px 0 20px;
            border: none; border-top: 1px solid #1e293b;
        }
        .footer { font-size: .75rem; color: #475569; }
        .footer a { color: #60a5fa; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
            h1 { font-size: 1.4rem; }
            a.tool-card { padding: 18px 20px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo">🏦</div>
    <h1>Permata Snap</h1>
    <p class="subtitle">Assist Switching Middleware — Inquiry Tools</p>
    <div class="php-badge">
        <span>PHP</span> <?= htmlspecialchars(phpversion()) ?>
        &nbsp;·&nbsp;
        <span>Built-in Server</span>
    </div>

    <div class="cards">
        <!-- Tool 1: Transfer Bank -->
        <a class="tool-card" href="/inquiry_transfer_permata.php">
            <div class="tc-top">
                <div class="tc-icon blue">💸</div>
                <div>
                    <div class="tc-title">Inquiry Transfer Bank</div>
                    <div class="tc-desc">Cek rekening tujuan untuk transfer antarbank</div>
                </div>
            </div>
            <div class="tc-meta">
                <span class="tc-pill">INQTFDANA</span>
                <span class="tc-pill">INQLLG</span>
                <span class="tc-pill">INQRTGS</span>
                <span class="tc-pill">MTI=010</span>
            </div>
        </a>

        <!-- Tool 2: BIFAST -->
        <a class="tool-card" href="/inquiry_bifast_permata.php">
            <div class="tc-top">
                <div class="tc-icon green">⚡</div>
                <div>
                    <div class="tc-title">Inquiry BIFAST</div>
                    <div class="tc-desc">Bank Indonesia Fast Payment — real-time settlement</div>
                </div>
            </div>
            <div class="tc-meta">
                <span class="tc-pill green">INQBIFAST</span>
                <span class="tc-pill green">MTI=010</span>
                <span class="tc-pill green">snap_ft_bdi</span>
            </div>
        </a>
    </div>

    <hr class="divider">
    <div class="footer">
        assist-switching_v3_pro &nbsp;·&nbsp;
        <a href="/health">health check</a> &nbsp;·&nbsp;
        <?= date('Y-m-d H:i:s T') ?>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ── 5. Route: Inquiry Transfer Bank (GET & POST) ─────────────────
if ($uri === '/inquiry_transfer_permata.php') {
    $target = __DIR__ . '/inquiry_transfer_permata.php';
    if (!file_exists($target)) {
        _send404('inquiry_transfer_permata.php tidak ditemukan di server.');
    }
    require $target;
    exit;
}

// ── 6. Route: Inquiry BIFAST (GET & POST) ────────────────────────
if ($uri === '/inquiry_bifast_permata.php') {
    $target = __DIR__ . '/inquiry_bifast_permata.php';
    if (!file_exists($target)) {
        _send404('inquiry_bifast_permata.php tidak ditemukan di server.');
    }
    require $target;
    exit;
}

// ── 7. 404 untuk semua route lain ────────────────────────────────
_send404('Halaman <code>' . htmlspecialchars($uri) . '</code> tidak ditemukan.');

// ─────────────────────────────────────────────────────────────────
// Helper: kirim halaman 404 yang rapi
// ─────────────────────────────────────────────────────────────────
function _send404(string $msg = ''): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 — Tidak Ditemukan</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
  body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0;
         display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .box { text-align: center; max-width: 420px; padding: 40px 24px; }
  .code { font-size: 5rem; font-weight: 900; color: #1e293b; margin-bottom: 8px; }
  h2   { margin: 0 0 12px; font-size: 1.2rem; }
  p    { color: #64748b; font-size: .9rem; margin-bottom: 28px; }
  a    { display: inline-block; background: #1d4ed8; color: #fff; padding: 10px 24px;
         border-radius: 8px; text-decoration: none; font-weight: 600; font-size: .9rem; }
  a:hover { background: #2563eb; }
  code { background: #1e293b; padding: 2px 6px; border-radius: 4px; font-size: .85em; }
</style>
</head><body>
<div class="box">
  <div class="code">404</div>
  <h2>Halaman Tidak Ditemukan</h2>
  <p>' . $msg . '</p>
  <a href="/">← Kembali ke Beranda</a>
</div>
</body></html>';
    exit;
}
