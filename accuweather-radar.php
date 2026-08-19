<?php
// GET /nmigs/wapv4.aspx?imagewidth=&imageheight=&mx=&my=&imagesource=&geowidth=
//                       [&framecount=&interval=&imageformat=]
//
// Real radar image for the AccuWeather webOS app's old NMIGS radar view
// (com.accuweather.palm.purchased's app/services/radar-image.js) — see that
// project's radar.md for the full request shape this mirrors. token, ux, uy,
// layers, and ulabel are all accepted (so the client's URL-building code
// doesn't need to change) but ignored: we own both this endpoint and
// accuweather-token.php, so none of them do anything meaningful server-side.
//
// US locations (imagesource=US_SIR) use IEM's NEXRAD mosaic; everything else
// (including HI_RE/AL_RE — not covered by the CONUS-only mosaic) uses
// RainViewer. Both are composited onto the OSM basemap via the same grid
// machinery radar-map.php/radar-gif.php use for World Today, generalized in
// radar-common.php for arbitrary width/height/zoom instead of their fixed
// 512x512 @ zoom 6.

require __DIR__ . '/radar-common.php';

$config = include __DIR__ . '/config.php';

function nmigsIntParam($name, $default, $min, $max) {
    if (!isset($_GET[$name])) return $default;
    $v = filter_var($_GET[$name], FILTER_VALIDATE_INT);
    if ($v === false) return $default;
    return max($min, min($max, $v));
}

// Builds one composited PNG frame, from either NEXRAD (US) or RainViewer
// (everywhere else). $overlaySelector is a NEXRAD age suffix ('', '-m15m',
// ...) when $useNexrad, or a RainViewer frame path otherwise.
function nmigsBuildFrame($config, $useNexrad, $overlaySelector, $rainviewerHost, $zoom, $offsets, $basemapTiles, $grid, $tileSize, $imageWidth, $imageHeight) {
    $overlayTiles = $useNexrad
        ? radarFetchNexradTiles($config, $overlaySelector, $zoom, $offsets)
        : radarFetchRadarTiles($config, $rainviewerHost, $overlaySelector, $zoom, $offsets);

    return radarBuildFrameGeneric(
        $offsets, $grid['gridCols'], $grid['gridRows'], $tileSize,
        $basemapTiles, $overlayTiles, $grid['cropLeft'], $grid['cropTop'],
        $imageWidth, $imageHeight
    );
}

$imageWidth  = nmigsIntParam('imagewidth', 480, 32, 2048);
$imageHeight = nmigsIntParam('imageheight', 480, 32, 2048);

$lat = isset($_GET['my']) ? filter_var($_GET['my'], FILTER_VALIDATE_FLOAT) : false;
$lon = isset($_GET['mx']) ? filter_var($_GET['mx'], FILTER_VALIDATE_FLOAT) : false;
if ($lat === false || $lon === false) radarFail(400, 'mx/my required');

$validGeowidths = [50, 100, 200, 400, 800, 1600, 3200, 6400];
$geoWidth = nmigsIntParam('geowidth', 400, 0, 999999);
if (!in_array($geoWidth, $validGeowidths, true)) $geoWidth = 400;

$imageSource = isset($_GET['imagesource']) ? (string) $_GET['imagesource'] : 'WORLD_IR';
$useNexrad = $imageSource === 'US_SIR';

$isAnimated = isset($_GET['imageformat']) && strtolower($_GET['imageformat']) === 'gif' && isset($_GET['framecount']);
if ($isAnimated && !class_exists('Imagick')) {
    radarFail(501, 'Animated radar requires the PHP Imagick extension, which is not installed on this server.');
}

$zoom = radarZoomForGeowidth($geoWidth, $imageWidth, $lat, $config['radarMinZoom'], $config['radarMaxZoom']);
$tileSize = RADAR_TILE_SIZE;

$grid = radarCenterGridGeneric($lat, $lon, $zoom, $tileSize, $imageWidth, $imageHeight);
$offsets = radarGridOffsetsGeneric($grid['x0'], $grid['y0'], $grid['gridCols'], $grid['gridRows'], $tileSize);

$basemapTiles = radarFetchOsmTiles($config, $zoom, $offsets);
foreach ($basemapTiles as $tile) {
    if ($tile === false) radarFail(502, 'Failed to fetch basemap tiles');
}

// RainViewer needs its frame-list metadata up front (for both the static
// and animated cases); NEXRAD doesn't (its age suffixes are static strings).
$rainviewerMeta = null;
if (!$useNexrad) {
    $rainviewerMeta = radarFetchMeta($config);
    if ($rainviewerMeta === false) radarFail(502, 'Failed to fetch radar frame list');
}

if (!$isAnimated) {
    $overlaySelector = $useNexrad
        ? ''
        : $rainviewerMeta['frames'][count($rainviewerMeta['frames']) - 1]['path'];
    $rainviewerHost = $useNexrad ? null : $rainviewerMeta['host'];

    $png = nmigsBuildFrame($config, $useNexrad, $overlaySelector, $rainviewerHost, $zoom, $offsets, $basemapTiles, $grid, $tileSize, $imageWidth, $imageHeight);
    if ($png === false) radarFail(502, 'Failed to composite radar frame');

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=60');
    header('Access-Control-Allow-Origin: *');
    echo $png;
    exit;
}

// Animated case: build one frame per overlay selector, assemble into a GIF.
$frameCount = nmigsIntParam('framecount', 5, 1, 20);
$intervalMinutes = nmigsIntParam('interval', 15, 1, 120);

if ($useNexrad) {
    $overlaySelectors = radarNexradAgeSuffixes($frameCount, $intervalMinutes);
    $rainviewerHost = null;
} else {
    $frames = $rainviewerMeta['frames'];
    $n = min(count($frames), $frameCount);
    $frames = array_slice($frames, count($frames) - $n);
    $overlaySelectors = array_map(function ($f) { return $f['path']; }, $frames);
    $rainviewerHost = $rainviewerMeta['host'];
}

$gif = new Imagick();
foreach ($overlaySelectors as $selector) {
    $pngBytes = nmigsBuildFrame($config, $useNexrad, $selector, $rainviewerHost, $zoom, $offsets, $basemapTiles, $grid, $tileSize, $imageWidth, $imageHeight);
    if ($pngBytes === false) continue;

    $frameImg = new Imagick();
    $frameImg->readImageBlob($pngBytes);
    $frameImg->setImageFormat('gif');
    // radarFrameDelayMs is in milliseconds; GIF delay units are centiseconds.
    $frameImg->setImageDelay((int) round($config['radarFrameDelayMs'] / 10));
    $frameImg->setImageDispose(Imagick::DISPOSE_BACKGROUND);
    $gif->addImage($frameImg);
}

if ($gif->getNumberImages() === 0) {
    radarFail(502, 'Failed to composite any radar frames');
}

$gif->setImageIterations(0); // loop forever
$gif->setFormat('gif');

header('Content-Type: image/gif');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');
echo $gif->getImagesBlob();
