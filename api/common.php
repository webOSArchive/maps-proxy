<?php
// Shared utilities for the Bing Maps API translation proxies.

function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonOut($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function errorOut($msg, $code = 400) {
    http_response_code($code);
    jsonOut(['errorDetails' => [$msg]]);
}

// Fetch a URL with curl, returning the response body or false on failure.
function curlGet($url, $userAgent = 'webOS-Maps-Proxy/1.0') {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return ($body !== false && empty($err)) ? $body : false;
}

// Geocode an address string via Nominatim.
// Returns [lat, lon] on success, or false on failure.
function nominatimGeocode($query, $nominatimUrl, $userAgent) {
    $url = $nominatimUrl . '/search?' . http_build_query([
        'q'              => $query,
        'format'         => 'json',
        'addressdetails' => 1,
        'limit'          => 1,
    ]);
    $body = curlGet($url, $userAgent);
    if (!$body) return false;

    $results = json_decode($body, true);
    if (empty($results)) return false;

    return [(float)$results[0]['lat'], (float)$results[0]['lon']];
}
