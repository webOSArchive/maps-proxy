<?php
// Shared helpers for the US radar endpoints (us-radar-region.php /
// us-radar-static.php / us-radar-gif.php) -- resolving a US lat/lon to the
// NWS station/region code for https://radar.weather.gov/ridge/standard/
// {region}_loop.gif. Ported from com.usatoday.webos's Cloudflare Worker
// (nearestRadarStation()/usRadarRegion() in worker/src/index.js).

// api.weather.gov (NWS's official developer API, free, no key, just a
// User-Agent per their usage policy) publishes all ~208 NEXRAD station
// locations directly. Station locations never change, so this is cached for
// a full day, same as the original Worker's cache TTL for it.
function usRadarStations($config) {
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
        // -- TDWR/Profiler stations 404 on it.
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

// Fallback only -- used if the station lookup above can't reach
// api.weather.gov. CONUS-LARGE only covers the contiguous 48 states --
// Hawaii, Alaska, Guam, and Puerto Rico/the Caribbean are all still under
// the US but fall outside it. Rough bounding boxes (good enough to route to
// the right region, not survey-grade).
function usRadarRegionFallback($lat, $lon) {
    if ($lat >= 15 && $lat <= 23 && $lon >= -68 && $lon <= -64) return 'CARIB';
    if ($lat >= 18 && $lat <= 23 && $lon >= -161 && $lon <= -154) return 'HAWAII';
    if ($lat >= 51 && $lon <= -129) return 'ALASKA';
    if ($lat >= 12 && $lat <= 21 && $lon >= 144 && $lon <= 146) return 'GUAM';
    return 'CONUS-LARGE';
}

function usRadarNearestStationId($stations, $lat, $lon) {
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

// Single entry point used by all three us-radar-*.php endpoints.
function usRadarResolveRegion($config, $lat, $lon) {
    $stations = usRadarStations($config);
    $region = $stations !== null ? usRadarNearestStationId($stations, $lat, $lon) : null;
    return $region !== null ? $region : usRadarRegionFallback($lat, $lon);
}
