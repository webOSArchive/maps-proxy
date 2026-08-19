<?php
// GET /us-radar-static.php?lat=<lat>&lon=<lon>
//
// A single still frame of NWS's real radar for a US lat/lon, relayed over
// plain HTTP -- the device never talks to radar.weather.gov or wsrv.nl
// directly. That matters here specifically: every other external-HTTPS
// dependency in this project (OSM tiles, weather-data.asp, etc.) is proxied
// the same way because the target devices' old WebKit TLS stack can't be
// trusted to reach many modern HTTPS hosts directly (see this repo's own
// README on tiles.php's HTTP tile proxy). An earlier version of this had
// the AccuWeather webOS client build https://radar.weather.gov and
// https://wsrv.nl URLs itself and load them directly -- almost certainly
// the cause of that app's radar area showing solid black on-device.
//
// wsrv.nl's frame-extraction (page=9) is what turns NWS's animated loop
// into a genuinely static frame -- same technique/params as World Today's
// wsrvResize() (worker/src/index.js), just run here instead of in a Worker.

require __DIR__ . '/radar-common.php';
require __DIR__ . '/us-radar-common.php';

$config = include __DIR__ . '/config.php';

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : false;
$lon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : false;
if ($lat === false || $lon === false) radarFail(400, 'lat/lon required');

$region = usRadarResolveRegion($config, $lat, $lon);
$loopUrl = 'https://radar.weather.gov/ridge/standard/' . $region . '_loop.gif';
$wsrvUrl = 'https://wsrv.nl/?url=' . urlencode($loopUrl) . '&w=600&h=400&fit=inside&output=jpg&page=9';

$safeRegion = preg_replace('/[^a-zA-Z0-9_-]/', '', $region);
$cacheFile = $config['radarCacheDir'] . "/us-static/$safeRegion.jpg";
$body = radarFetchCached($wsrvUrl, $cacheFile, 5 * 60, $config['tileUserAgent']);
if ($body === false) radarFail(502, 'Failed to fetch NWS radar image');

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');
echo $body;
