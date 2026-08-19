<?php
// Translates Bing Phonebook (POI search) API calls to Nominatim search.
//
// Bing API the app calls:
//   GET /json.aspx?Sources=PhoneBook&Phonebook.Count=20
//                 &AppId=...&Latitude=...&Longitude=...&Query=...
//
// App parses: response.SearchResponse.Phonebook.Results[]
//   with fields: Title, Address, City, StateOrProvince,
//                PhoneNumber, DisplayUrl, UserRating, Latitude, Longitude
//
// Nominatim doesn't provide phone, URL, or ratings; those fields are left empty.
// The app renders gracefully when they're absent.

require_once __DIR__ . '/common.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$config       = include __DIR__ . '/../config.php';
$nominatimUrl = $config['nominatimUrl'];
$userAgent    = $config['nominatimUserAgent'];

$searchQuery = trim($_GET['Query']     ?? $_GET['query'] ?? '');
$lat         = (float)($_GET['Latitude']  ?? $_GET['lat']     ?? 0);
$lon         = (float)($_GET['Longitude'] ?? $_GET['lon']     ?? 0);
$count       = min((int)($_GET['Phonebook.Count'] ?? 20), 50);

if ($searchQuery === '') {
    errorOut('No search query provided');
}

// Build Nominatim search with lat/lon viewbox bias if coordinates were supplied
$params = [
    'q'              => $searchQuery,
    'format'         => 'json',
    'addressdetails' => 1,
    'limit'          => $count,
    'extratags'      => 1,  // gets phone, website when available
];

if ($lat !== 0.0 || $lon !== 0.0) {
    // Use a viewbox centred on the device location to bias results locally.
    // Nominatim viewbox: left,top,right,bottom (lon/lat order)
    $delta = 0.5; // ~50 km box
    $params['viewbox']  = ($lon - $delta) . ',' . ($lat + $delta) . ',' .
                          ($lon + $delta) . ',' . ($lat - $delta);
    $params['bounded']  = 0;  // return global results if nothing in viewbox
}

$url  = $nominatimUrl . '/search?' . http_build_query($params);
$body = curlGet($url, $userAgent);

$places = [];
if ($body) {
    $raw = json_decode($body, true) ?: [];
    foreach ($raw as $r) {
        $addr  = $r['address']   ?? [];
        $extra = $r['extratags'] ?? [];

        $places[] = [
            'Title'           => $r['name'] ?: ($r['display_name'] ?? $searchQuery),
            'Address'         => trim(($addr['house_number'] ?? '') . ' ' . ($addr['road'] ?? '')),
            'City'            => $addr['city']     ?? $addr['town']    ?? $addr['village'] ?? '',
            'StateOrProvince' => $addr['state']    ?? '',
            'PhoneNumber'     => $extra['phone']   ?? '',
            'DisplayUrl'      => $extra['website'] ?? '',
            'UserRating'      => 0,
            'Latitude'        => (float)$r['lat'],
            'Longitude'       => (float)$r['lon'],
        ];
    }
}

jsonOut([
    'SearchResponse' => [
        'Version'  => '2.0',
        'Phonebook' => [
            'Total'   => (string)count($places),
            'Results' => $places,
        ],
    ],
]);
