<?php
// GET /us-radar-region.php?lat=<lat>&lon=<lon>
//
// Returns the region code to plug into NWS's own pre-rendered station-loop
// GIF (https://radar.weather.gov/ridge/standard/{region}_loop.gif) for a US
// lat/lon. Kept as a standalone plain-text endpoint for debugging/reuse, but
// the AccuWeather webOS client itself no longer calls this directly -- see
// us-radar-static.php/us-radar-gif.php, which resolve the region AND relay
// the actual image bytes server-side, so the device never has to make an
// HTTPS request of its own to either api.weather.gov or radar.weather.gov.

header('Content-Type: text/plain');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : false;
$lon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : false;
if ($lat === false || $lon === false) {
    http_response_code(400);
    echo 'lat/lon required';
    exit;
}

require __DIR__ . '/us-radar-common.php';
$config = include __DIR__ . '/config.php';

echo usRadarResolveRegion($config, $lat, $lon);
