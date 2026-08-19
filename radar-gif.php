<?php
// GET /radar/map.gif?lat=<lat>&lon=<lon>
// Animated GIF looping RainViewer's last N past frames over the OSM
// basemap, centered on the given point.
//
// Requires the PHP Imagick extension (ext-imagick) to assemble the frames
// into a GIF — GD alone has no animated-GIF writer. Returns 501 with a
// clear message if it isn't installed, rather than a broken image, so this
// is safe to deploy even if Imagick isn't available yet; radar-map.php
// (the static PNG) works either way.

require __DIR__ . '/radar-common.php';

$config = include __DIR__ . '/config.php';

if (!class_exists('Imagick')) {
    radarFail(501, 'Animated radar requires the PHP Imagick extension, which is not installed on this server.');
}

$latLon = radarParseLatLon();
if ($latLon === null) radarFail(400, 'lat/lon required');
[$lat, $lon] = $latLon;

$prep = radarPrepare($config, $lat, $lon);
if ($prep === false) radarFail(502, 'Failed to fetch radar/basemap data');

$frames = $prep['frames'];
$frameCount = min(count($frames), $config['radarFrameCount']);
$frames = array_slice($frames, count($frames) - $frameCount);

$gif = new Imagick();
foreach ($frames as $frame) {
    $overlayTiles = radarFetchRadarTiles($config, $prep['host'], $frame['path'], $prep['zoom'], $prep['offsets']);
    $pngBytes = radarBuildFrame($prep['offsets'], $prep['basemapTiles'], $overlayTiles, $prep['cropLeft'], $prep['cropTop']);
    if ($pngBytes === false) continue;

    $frameImg = new Imagick();
    $frameImg->readImageBlob($pngBytes);
    $frameImg->setImageFormat('gif');
    // radarFrameDelayMs is in milliseconds; GIF delay units are centiseconds.
    $frameImg->setImageDelay((int)round($config['radarFrameDelayMs'] / 10));
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
