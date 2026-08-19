<?php
// GET /us-radar-gif.php?lat=<lat>&lon=<lon>
//
// NWS's real animated radar loop for a US lat/lon, relayed over plain HTTP
// -- see us-radar-static.php for why the device must never hit
// radar.weather.gov directly.

require __DIR__ . '/radar-common.php';
require __DIR__ . '/us-radar-common.php';

$config = include __DIR__ . '/config.php';

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : false;
$lon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : false;
if ($lat === false || $lon === false) radarFail(400, 'lat/lon required');

$region = usRadarResolveRegion($config, $lat, $lon);
$loopUrl = 'https://radar.weather.gov/ridge/standard/' . $region . '_loop.gif';

$safeRegion = preg_replace('/[^a-zA-Z0-9_-]/', '', $region);
$cacheFile = $config['radarCacheDir'] . "/us-gif/$safeRegion.gif";
$body = radarFetchCached($loopUrl, $cacheFile, 5 * 60, $config['tileUserAgent']);
if ($body === false) radarFail(502, 'Failed to fetch NWS radar image');

header('Content-Type: image/gif');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');
echo $body;
