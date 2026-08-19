<?php
// Shared helpers for the radar overlay endpoints (radar-map.php / radar-gif.php).
//
// Composites a live RainViewer radar frame onto the OSM road basemap this
// proxy already serves via tiles.php, for any lat/lon. RainViewer's own
// tiles have no basemap under them (unlabeled color blobs on transparent
// background), so this fetches a basemap tile + a radar tile per grid cell
// and alpha-blends them with GD before stitching/cropping.
//
// Coordinate math and the overall approach (3x3 tile grid with one tile of
// margin, exact pixel crop rather than snapping to a tile boundary) were
// worked out and bug-fixed against a Node/sharp prototype before this port —
// see the PR description for the two real bugs that shaped it.

define('RADAR_TILE_SIZE', 256);
define('RADAR_GRID', 3);
define('RADAR_OUTPUT_SIZE', 512);

function radarFail($code, $message = '') {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $message !== '' ? $message : 'Radar request failed';
    exit;
}

// Generic disk-cached fetch, same shape as tiles.php's cache (separate
// directory so this doesn't share cache-key format with it). Returns the
// body string, or false on failure.
function radarFetchCached($url, $cacheFile, $ttlSeconds, $userAgent) {
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttlSeconds)) {
        $body = file_get_contents($cacheFile);
        if ($body !== false) return $body;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '' || $code < 200 || $code >= 300) {
        return false;
    }

    if (@mkdir(dirname($cacheFile), 0775, true) || is_dir(dirname($cacheFile))) {
        @file_put_contents($cacheFile, $body);
    }

    return $body;
}

// Same as radarFetchCached, but for a batch of [url, cacheFile, ttl]
// requests fetched concurrently via curl_multi for whichever ones miss
// cache. Fetching a frame's 9 grid tiles one at a time (~150-200ms each)
// made the animated GIF endpoint take 20+ seconds; the Node prototype this
// was ported from avoided that with Promise.all per frame, which this
// mirrors. Returns an array of bodies (or false per-entry), same order and
// count as $requests — each is ['url' => .., 'cacheFile' => .., 'ttl' => ..].
function radarFetchManyCached(array $requests, $userAgent) {
    $results = array_fill(0, count($requests), false);
    $toFetch = [];

    foreach ($requests as $i => $req) {
        if (is_file($req['cacheFile']) && (time() - filemtime($req['cacheFile']) < $req['ttl'])) {
            $body = file_get_contents($req['cacheFile']);
            if ($body !== false) {
                $results[$i] = $body;
                continue;
            }
        }
        $toFetch[$i] = $req;
    }

    if (empty($toFetch)) return $results;

    $mh = curl_multi_init();
    $handles = [];
    foreach ($toFetch as $i => $req) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $req['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh);
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($handles as $i => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $body = curl_multi_getcontent($ch);

        if ($body !== false && $body !== '' && $err === '' && $code >= 200 && $code < 300) {
            $results[$i] = $body;
            $cacheFile = $toFetch[$i]['cacheFile'];
            if (@mkdir(dirname($cacheFile), 0775, true) || is_dir(dirname($cacheFile))) {
                @file_put_contents($cacheFile, $body);
            }
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

function lonToTileX($lon, $z) {
    return (($lon + 180) / 360) * (1 << $z);
}

function latToTileY($lat, $z) {
    $rad = deg2rad($lat);
    return ((1 - log(tan($rad) + 1 / cos($rad)) / M_PI) / 2) * (1 << $z);
}

// Fetching one tile snapped to whichever grid boundary is nearest the point
// only guarantees the point lands within the middle 50% of the output image
// — up to a quarter-image off center depending on where it falls inside its
// own tile (confirmed visually against the Node prototype: fine for some
// cities, badly off-center for e.g. Los Angeles). A 3x3 fetch with an exact
// pixel-position crop avoids snapping to tile boundaries at all.
function radarCenterGrid($lat, $lon, $zoom) {
    $xf = lonToTileX($lon, $zoom);
    $yf = latToTileY($lat, $zoom);
    $margin = intdiv(RADAR_GRID, 2);
    $x0 = (int)floor($xf) - $margin;
    $y0 = (int)floor($yf) - $margin;
    $pointPxX = ($xf - $x0) * RADAR_TILE_SIZE;
    $pointPxY = ($yf - $y0) * RADAR_TILE_SIZE;
    $canvasSize = RADAR_GRID * RADAR_TILE_SIZE;
    $clamp = function ($v) use ($canvasSize) {
        return max(0, min($canvasSize - RADAR_OUTPUT_SIZE, $v));
    };
    return [
        'x0' => $x0,
        'y0' => $y0,
        'cropLeft' => $clamp((int)round($pointPxX - RADAR_OUTPUT_SIZE / 2)),
        'cropTop'  => $clamp((int)round($pointPxY - RADAR_OUTPUT_SIZE / 2)),
    ];
}

function radarGridOffsets($x0, $y0) {
    $offsets = [];
    for ($row = 0; $row < RADAR_GRID; $row++) {
        for ($col = 0; $col < RADAR_GRID; $col++) {
            $offsets[] = [
                'x' => $x0 + $col,
                'y' => $y0 + $row,
                'left' => $col * RADAR_TILE_SIZE,
                'top' => $row * RADAR_TILE_SIZE,
            ];
        }
    }
    return $offsets;
}

// Fetches all of the grid's basemap tiles concurrently. Reused across every
// frame of an animated GIF (the basemap doesn't change frame to frame), so
// this is only called once per request regardless of frame count.
function radarFetchOsmTiles($config, $zoom, $offsets) {
    $tmpl = $config['osmUpstreamUrl'];
    $subs = isset($config['osmTileSubdomains']) ? $config['osmTileSubdomains'] : 'abc';

    $requests = [];
    foreach ($offsets as $o) {
        $sub = $subs[($o['x'] + $o['y']) % strlen($subs)];
        $requests[] = [
            'url' => str_replace(['{s}', '{z}', '{x}', '{y}'], [$sub, $zoom, $o['x'], $o['y']], $tmpl),
            'cacheFile' => $config['radarCacheDir'] . "/osm/$zoom/{$o['x']}/{$o['y']}.png",
            'ttl' => $config['radarBasemapCacheTtl'],
        ];
    }

    return radarFetchManyCached($requests, $config['tileUserAgent']);
}

// weather-maps.json changes roughly every 10 min; cached briefly with its
// own file so repeated requests within that window don't re-fetch it.
function radarFetchMeta($config) {
    $cacheFile = $config['radarCacheDir'] . '/meta.json';
    $body = radarFetchCached($config['rainviewerMetaUrl'], $cacheFile, $config['radarMetaCacheTtl'], $config['tileUserAgent']);
    if ($body === false) return false;

    $meta = json_decode($body, true);
    if (!$meta || empty($meta['radar']['past'])) return false;

    return ['host' => $meta['host'], 'frames' => $meta['radar']['past']];
}

// Fetches one frame's 9 grid tiles concurrently. Radar tiles are cached
// under their frame path, so a cache hit is always for the exact right
// frame — safe to keep well past RainViewer's own ~10 min refresh cadence
// since a given frame's tiles never change once issued.
function radarFetchRadarTiles($config, $host, $path, $zoom, $offsets) {
    $safePath = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $path);

    $requests = [];
    foreach ($offsets as $o) {
        // color=2 (Universal Blue), smooth=1, snow=1 — matches
        // rainviewer.com's own default site rendering.
        $requests[] = [
            'url' => "$host$path/" . RADAR_TILE_SIZE . "/$zoom/{$o['x']}/{$o['y']}/2/1_1.png",
            'cacheFile' => $config['radarCacheDir'] . "/radar/$safePath/$zoom/{$o['x']}/{$o['y']}.png",
            'ttl' => $config['radarTileCacheTtl'],
        ];
    }

    return radarFetchManyCached($requests, $config['tileUserAgent']);
}

// Alpha-blends one radar tile onto its matching basemap tile. GD honors the
// source's per-pixel alpha when imagealphablending() is enabled on the
// destination, so this is a plain imagecopy() rather than imagecopymerge().
function radarCompositeTile($baseBytes, $overlayBytes) {
    $base = @imagecreatefromstring($baseBytes);
    if (!$base) return false;
    // OSM's tiles are palette (indexed-color) PNGs. GD's alpha blending is
    // unreliable when the destination is a palette image (limited to a
    // 256-color table, no true per-pixel alpha) — confirmed directly: it
    // produced a blank-white tile in one grid cell and muddy/darkened
    // colors in the rest. Converting to truecolor first fixes both.
    imagepalettetotruecolor($base);
    $overlay = @imagecreatefromstring($overlayBytes);
    if (!$overlay) return $base;

    imagealphablending($base, true);
    imagesavealpha($base, true);
    imagecopy($base, $overlay, 0, 0, 0, 0, RADAR_TILE_SIZE, RADAR_TILE_SIZE);
    imagedestroy($overlay);
    return $base;
}

// Builds one full composited+cropped frame (as raw PNG bytes) given
// already-fetched basemap + radar-overlay tile bytes for the grid (one
// entry per $offsets position; an overlay entry can be false if that tile
// failed to fetch, in which case the bare basemap tile is used instead).
function radarBuildFrame($offsets, $basemapTiles, $overlayTiles, $cropLeft, $cropTop) {
    $canvasSize = RADAR_GRID * RADAR_TILE_SIZE;
    $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);

    foreach ($offsets as $i => $o) {
        $overlayBytes = $overlayTiles[$i];
        $tileImg = $overlayBytes !== false
            ? radarCompositeTile($basemapTiles[$i], $overlayBytes)
            : @imagecreatefromstring($basemapTiles[$i]);
        if (!$tileImg) continue;

        imagealphablending($canvas, false);
        imagecopy($canvas, $tileImg, $o['left'], $o['top'], 0, 0, RADAR_TILE_SIZE, RADAR_TILE_SIZE);
        imagedestroy($tileImg);
    }

    $cropped = imagecrop($canvas, [
        'x' => $cropLeft, 'y' => $cropTop,
        'width' => RADAR_OUTPUT_SIZE, 'height' => RADAR_OUTPUT_SIZE,
    ]);
    imagedestroy($canvas);
    if (!$cropped) return false;

    ob_start();
    imagepng($cropped);
    $pngBytes = ob_get_clean();
    imagedestroy($cropped);
    return $pngBytes;
}

// Fetches everything needed to build frames for one lat/lon: the frame
// list, and the 9 basemap tiles (fetched once, concurrently, reused across
// every frame since the basemap doesn't change frame to frame). Returns
// false on any hard failure (no radar data available at all).
function radarPrepare($config, $lat, $lon) {
    $zoom = $config['radarZoom'];
    $meta = radarFetchMeta($config);
    if ($meta === false) return false;

    $grid = radarCenterGrid($lat, $lon, $zoom);
    $offsets = radarGridOffsets($grid['x0'], $grid['y0']);

    $basemapTiles = radarFetchOsmTiles($config, $zoom, $offsets);
    foreach ($basemapTiles as $tile) {
        if ($tile === false) return false;
    }

    return [
        'zoom' => $zoom,
        'host' => $meta['host'],
        'frames' => $meta['frames'],
        'offsets' => $offsets,
        'basemapTiles' => $basemapTiles,
        'cropLeft' => $grid['cropLeft'],
        'cropTop' => $grid['cropTop'],
    ];
}

function radarParseLatLon() {
    if (!isset($_GET['lat']) || !isset($_GET['lon'])) return null;
    $lat = filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT);
    $lon = filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lon === false) return null;
    return [$lat, $lon];
}
