<?php
// Translates Bing Locations REST API calls to Nominatim geocoding.
//
// Bing API the app calls:
//   GET /REST/v1/Locations/{query}?output=json&c={locale}
//   GET /REST/v1/Locations/{lat},{lon}?output=json        (reverse geocode)
//
// App parses: response.resourceSets[0].resources[0]
//   with fields: bbox[], name, point.coordinates[], entityType

require_once __DIR__ . '/common.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$config       = include __DIR__ . '/../config.php';
$nominatimUrl = $config['nominatimUrl'];
$userAgent    = $config['nominatimUserAgent'];

// Extract the location query from the request path.
// After Apache rewriting, the original path segment after /REST/v1/Locations is
// available; we reconstruct it from REQUEST_URI.
$uri   = $_SERVER['REQUEST_URI'];
$after = preg_replace('#^.*/REST/v1/Locations/?#i', '', parse_url($uri, PHP_URL_PATH));
$query = urldecode(trim($after, '/'));

// Fall back to query string params if path segment is empty.
// The app passes text searches as ?query=... (Bing also accepted this form).
if ($query === '' && isset($_GET['query'])) {
    $query = trim($_GET['query']);
}
if ($query === '' && isset($_GET['q'])) {
    $query = trim($_GET['q']);
}

if ($query === '') {
    errorOut('No location query provided');
}

// Detect reverse geocode: "{lat},{lon}" — digits, optional minus, comma
if (preg_match('#^-?\d+\.?\d*,-?\d+\.?\d*$#', $query)) {
    list($lat, $lon) = explode(',', $query, 2);
    $url = $nominatimUrl . '/reverse?' . http_build_query([
        'lat'            => $lat,
        'lon'            => $lon,
        'format'         => 'json',
        'addressdetails' => 1,
    ]);
    $body    = curlGet($url, $userAgent);
    $results = $body ? [json_decode($body, true)] : [];
} else {
    // Forward geocode
    $url = $nominatimUrl . '/search?' . http_build_query([
        'q'              => $query,
        'format'         => 'json',
        'addressdetails' => 1,
        'limit'          => 1,
    ]);
    $body    = curlGet($url, $userAgent);
    $results = $body ? json_decode($body, true) : [];
}

if (empty($results) || empty($results[0])) {
    // Return empty resourceSets so the app shows "no results" rather than crashing
    jsonOut(['resourceSets' => [['estimatedTotal' => 0, 'resources' => []]]]);
}

$r    = $results[0];
$lat  = (float)($r['lat'] ?? 0);
$lon  = (float)($r['lon'] ?? 0);
$name = $r['display_name'] ?? $query;

// Nominatim boundingbox: [south_lat, north_lat, west_lon, east_lon]
// Bing bbox:             [south_lat, west_lon, north_lat, east_lon]
$bb = $r['boundingbox'] ?? [$lat - 0.05, $lat + 0.05, $lon - 0.05, $lon + 0.05];
$bbox = [(float)$bb[0], (float)$bb[2], (float)$bb[1], (float)$bb[3]];

// Map Nominatim type to a rough Bing entityType
$typeMap = [
    'administrative' => 'AdminDivision1',
    'city'           => 'PopulatedPlace',
    'town'           => 'PopulatedPlace',
    'village'        => 'PopulatedPlace',
    'suburb'         => 'Neighborhood',
    'postcode'       => 'Postcode1',
    'road'           => 'RoadBlock',
    'house'          => 'Address',
    'building'       => 'Address',
];
$entityType = $typeMap[$r['type'] ?? ''] ?? 'Address';

$addr = $r['address'] ?? [];

jsonOut([
    'resourceSets' => [[
        'estimatedTotal' => 1,
        'resources'      => [[
            '__type'     => 'Location:http://schemas.microsoft.com/search/local/ws/rest/v1',
            'bbox'       => $bbox,
            'name'       => $name,
            'point'      => ['type' => 'Point', 'coordinates' => [$lat, $lon]],
            'entityType' => $entityType,
            'address'    => [
                'adminDistrict'    => $addr['state']         ?? '',
                'adminDistrict2'   => $addr['county']        ?? '',
                'countryRegion'    => $addr['country']       ?? '',
                'formattedAddress' => $name,
                'locality'         => $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? '',
                'postalCode'       => $addr['postcode']      ?? '',
            ],
        ]],
    ]],
]);
