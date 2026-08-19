<?php
// GET /us-radar-region.php?lat=<lat>&lon=<lon>
//
// Returns the region code to plug into NWS's own pre-rendered station-loop
// GIF (https://radar.weather.gov/ridge/standard/{region}_loop.gif) for a US
// lat/lon. Ported from com.usatoday.webos's Cloudflare Worker
// (nearestRadarStation() + usRadarRegion() in worker/src/index.js), which
// resolves this the same way server-side -- consistent with this whole
// project's pattern of proxying external HTTPS APIs the device's old TLS
// stack shouldn't be trusted to reach directly, rather than have the device
// call api.weather.gov itself.
//
// Plain text response: a WSR-88D station ID (e.g. "OKX") when the nearest-
// station lookup succeeds, or one of NWS's coarse regional loop codes
// (CONUS-LARGE/HAWAII/ALASKA/GUAM/CARIB) as a fallback if it can't be
// reached.

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

$config = include __DIR__ . '/config.php';

// Fallback only -- used if the station lookup below can't reach
// api.weather.gov. CONUS-LARGE only covers the contiguous 48 states --
// Hawaii, Alaska, Guam, and Puerto Rico/the Caribbean are all still under
// the US but fall outside it. Rough bounding boxes (good enough to route to
// the right region, not survey-grade) -- see usRadarRegion() in
// worker/src/index.js for the original.
function usRadarRegionFallback($lat, $lon) {
    if ($lat >= 15 && $lat <= 23 && $lon >= -68 && $lon <= -64) return 'CARIB';
    if ($lat >= 18 && $lat <= 23 && $lon >= -161 && $lon <= -154) return 'HAWAII';
    if ($lat >= 51 && $lon <= -129) return 'ALASKA';
    if ($lat >= 12 && $lat <= 21 && $lon >= 144 && $lon <= 146) return 'GUAM';
    return 'CONUS-LARGE';
}

// api.weather.gov (NWS's official developer API, free, no key, just a
// User-Agent per their usage policy) publishes all ~208 NEXRAD station
// locations directly. Station locations never change, so this is cached for
// a full day, same as the original Worker's cache TTL for it.
function nearestRadarStation($config) {
    $cacheFile = $config['radarCacheDir'] . '/nws-stations.json';
    $ttl = 60 * 60 * 24;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        $body = file_get_contents($cacheFile);
        $stations = $body !== false ? json_decode($body, true) : null;
        if ($stations !== null) return $stations;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.weather.gov/radar/stations',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['User-Agent: webOS-Maps-TileProxy/1.0 (maps.webosarchive.org)'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok) return null;

    $data = json_decode($body, true);
    $stations = [];
    foreach (($data['features'] ?? []) as $f) {
        // Only WSR-88D (real NEXRAD) stations have a ridge/standard loop GIF
        // -- TDWR/Profiler stations 404 on it (confirmed against the
        // original Worker's own comment/testing).
        if (($f['properties']['stationType'] ?? '') !== 'WSR-88D') continue;
        $stations[] = [
            'id' => $f['properties']['id'],
            'lon' => $f['geometry']['coordinates'][0],
            'lat' => $f['geometry']['coordinates'][1],
        ];
    }

    if (@mkdir(dirname($cacheFile), 0775, true) || is_dir(dirname($cacheFile))) {
        @file_put_contents($cacheFile, json_encode($stations));
    }
    return $stations;
}

function findNearestStationId($stations, $lat, $lon) {
    if (empty($stations)) return null;

    $nearestId = null;
    $nearestDistSq = INF;
    foreach ($stations as $s) {
        $dLat = $s['lat'] - $lat;
        $dLon = $s['lon'] - $lon;
        $distSq = $dLat * $dLat + $dLon * $dLon;
        if ($distSq < $nearestDistSq) {
            $nearestDistSq = $distSq;
            $nearestId = $s['id'];
        }
    }
    return $nearestId;
}

$stations = nearestRadarStation($config);
$region = $stations !== null ? findNearestStationId($stations, $lat, $lon) : null;
if ($region === null) $region = usRadarRegionFallback($lat, $lon);

echo $region;
