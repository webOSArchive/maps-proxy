<?php
// GET /radar/map.png?lat=<lat>&lon=<lon>
// A single flat PNG: OSM basemap with the latest RainViewer radar frame
// composited on top, 512x512, centered on the given point.

require __DIR__ . '/radar-common.php';

$config = include __DIR__ . '/config.php';

$latLon = radarParseLatLon();
if ($latLon === null) radarFail(400, 'lat/lon required');
[$lat, $lon] = $latLon;

$prep = radarPrepare($config, $lat, $lon);
if ($prep === false) radarFail(502, 'Failed to fetch radar/basemap data');

$latestFramePath = $prep['frames'][count($prep['frames']) - 1]['path'];
$overlayTiles = radarFetchRadarTiles($config, $prep['host'], $latestFramePath, $prep['zoom'], $prep['offsets']);
$png = radarBuildFrame($prep['offsets'], $prep['basemapTiles'], $overlayTiles, $prep['cropLeft'], $prep['cropTop']);
if ($png === false) radarFail(502, 'Failed to composite radar frame');

header('Content-Type: image/png');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');
echo $png;
