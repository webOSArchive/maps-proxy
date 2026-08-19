<?php
// Tile proxy: fetches upstream map tiles (which are only served over HTTPS) and
// re-serves them over plain HTTP, so the oldest webOS devices — which can't
// negotiate modern TLS / lack current CA bundles — can load the map without a
// per-device ssl-bump proxy.
//
// Request:  /tiles/{layer}/{z}/{x}/{y}.png      layer = road | aerial
// (.htaccess / nginx rewrites that path to tiles.php?layer=..&z=..&x=..&y=..)
//
// Tiles are cached on disk (OSM's usage policy requires caching). Coordinates
// are strictly validated so this can't be used as an open proxy.

$config = include __DIR__ . '/config.php';

// --- resolve params: rewritten query first, else parse the path ---
$layer = isset($_GET['layer']) ? $_GET['layer'] : '';
$z = isset($_GET['z']) ? $_GET['z'] : '';
$x = isset($_GET['x']) ? $_GET['x'] : '';
$y = isset($_GET['y']) ? $_GET['y'] : '';

if ($layer === '' || $z === '' || $x === '' || $y === '') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#/tiles/(road|aerial)/(\d+)/(\d+)/(\d+)#', $path, $m)) {
        $layer = $m[1]; $z = $m[2]; $x = $m[3]; $y = $m[4];
    }
}

function tileFail($code) { http_response_code($code); exit; }

// --- validate (prevents SSRF / open-proxy abuse) ---
if ($layer !== 'road' && $layer !== 'aerial') { tileFail(404); }
if (!ctype_digit((string)$z) || !ctype_digit((string)$x) || !ctype_digit((string)$y)) { tileFail(400); }
$z = (int)$z; $x = (int)$x; $y = (int)$y;
if ($z < 0 || $z > 19) { tileFail(400); }
$max = (1 << $z) - 1;
if ($x < 0 || $x > $max || $y < 0 || $y > $max) { tileFail(400); }

// --- build the upstream URL from the configured template ---
$tmplKey = ($layer === 'aerial') ? 'aerialUpstreamUrl' : 'osmUpstreamUrl';
$tmpl = isset($config[$tmplKey]) ? $config[$tmplKey] : '';
if ($tmpl === '') { tileFail(500); }

// rotate OSM subdomains (the template may use {s}); the device only ever talks
// to this proxy, so the spread happens here.
$sub = 'a';
if (strpos($tmpl, '{s}') !== false) {
    $subs = isset($config['osmTileSubdomains']) ? $config['osmTileSubdomains'] : 'abc';
    $sub = $subs[($x + $y) % strlen($subs)];
}
// NOTE: the aerial template orders coords {z}/{y}/{x}; str_replace maps each
// named placeholder regardless of order, so this stays correct.
$upstream = str_replace(['{s}', '{z}', '{x}', '{y}'], [$sub, $z, $x, $y], $tmpl);

// --- disk cache ---
$cacheRoot = isset($config['tileCacheDir']) ? $config['tileCacheDir'] : (__DIR__ . '/tiles_cache');
$cacheTtl  = isset($config['tileCacheTtl']) ? (int)$config['tileCacheTtl'] : (60 * 60 * 24 * 30); // 30d
$cacheFile = $cacheRoot . "/$layer/$z/$x/$y";

$body = false;
$ctype = null;
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $body = file_get_contents($cacheFile);
    if (is_file($cacheFile . '.type')) { $ctype = trim(file_get_contents($cacheFile . '.type')); }
}

// --- fetch upstream on cache miss ---
if ($body === false) {
    $ua = isset($config['tileUserAgent'])
        ? $config['tileUserAgent']
        : 'webOS-Maps-TileProxy/1.0 (maps.webosarchive.org)';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $upstream,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '' || $code < 200 || $code >= 300) {
        tileFail(502);
    }

    // best-effort cache write (gracefully no-op if the dir isn't writable)
    if (@mkdir(dirname($cacheFile), 0775, true) || is_dir(dirname($cacheFile))) {
        @file_put_contents($cacheFile, $body);
        if ($ctype) { @file_put_contents($cacheFile . '.type', $ctype); }
    }
}

if (!$ctype) { $ctype = ($layer === 'aerial') ? 'image/jpeg' : 'image/png'; }

header('Content-Type: ' . $ctype);
header('Cache-Control: public, max-age=2592000'); // tell the device to cache 30d
header('Access-Control-Allow-Origin: *');
echo $body;
