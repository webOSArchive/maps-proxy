<?php
// Translates Bing Routes REST API calls to OSRM routing.
//
// Bing API the app calls:
//   GET /REST/v1/Routes/{Driving|Walking|Transit}
//       ?wp.0={start}&wp.1={end}&distanceUnit=km&routePathOutput=Points
//       &dateTime=HH:mm:ss&timeType=Departure&output=json&c={locale}&key={key}
//
// App parses: response.resourceSets[0].resources[0]
//   result.travelDistance      — total distance (km or mi)
//   result.travelDuration      — total seconds
//   result.routeLegs[0].itineraryItems[].instruction.{text, maneuverType}
//   result.routeLegs[0].itineraryItems[].travelDistance
//   result.routeLegs[0].itineraryItems[].travelDuration
//   result.routePath.line.coordinates  — [[lat,lon], ...]
//
// Strategy:
//   1. Geocode wp.0 and wp.1 via Nominatim
//   2. Call OSRM for routing
//   3. Transform OSRM steps → Bing itinerary schema

require_once __DIR__ . '/common.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$config       = include __DIR__ . '/../config.php';
$nominatimUrl = $config['nominatimUrl'];
$userAgent    = $config['nominatimUserAgent'];
$osrmUrl      = $config['osrmUrl'];

// --- Extract parameters ---

$uri        = $_SERVER['REQUEST_URI'];
$pathAfter  = preg_replace('#^.*/REST/v1/Routes/?#i', '', parse_url($uri, PHP_URL_PATH));
$travelMode = strtolower(trim($pathAfter, '/')) ?: 'driving';

// PHP converts dots in query-string parameter names to underscores in $_GET,
// so wp.0 → wp_0 and wp.1 → wp_1. Parse the raw query string to get the
// original dot-keyed values before that mangling occurs.
parse_str($_SERVER['QUERY_STRING'] ?? '', $rawParams);
$wp0          = trim($rawParams['wp.0']        ?? $_GET['wp_0'] ?? '');
$wp1          = trim($rawParams['wp.1']        ?? $_GET['wp_1'] ?? '');
$distanceUnit = strtolower($_GET['distanceUnit'] ?? 'km');

if ($wp0 === '' || $wp1 === '') {
    errorOut('Both wp.0 and wp.1 waypoints are required');
}

// --- Geocode waypoints ---

$start = nominatimGeocode($wp0, $nominatimUrl, $userAgent);
if (!$start) errorOut("Could not geocode start: $wp0");

$end = nominatimGeocode($wp1, $nominatimUrl, $userAgent);
if (!$end) errorOut("Could not geocode destination: $wp1");

// --- Call OSRM ---

// OSRM coordinate format: {lon},{lat} (GeoJSON order)
$osrmProfile = ($travelMode === 'walking') ? 'foot' : 'driving';
$coordStr    = $start[1] . ',' . $start[0] . ';' . $end[1] . ',' . $end[0];
$osrmQuery   = $osrmUrl . '/route/v1/' . $osrmProfile . '/' . $coordStr . '?' .
               http_build_query([
                   'overview'   => 'full',
                   'steps'      => 'true',
                   'geometries' => 'geojson',
               ]);

$body = curlGet($osrmQuery, $userAgent);
if (!$body) errorOut('Routing service unavailable', 503);

$osrm = json_decode($body, true);
if (empty($osrm['routes'])) {
    errorOut($osrm['message'] ?? 'No route found');
}

$route = $osrm['routes'][0];
$leg   = $route['legs'][0];

// --- Distance conversion ---

$totalDistM = (float)$route['distance'];
$totalDist  = ($distanceUnit === 'mi')
    ? round($totalDistM / 1609.344, 2)
    : round($totalDistM / 1000,     2);

$totalDuration = (int)round($route['duration']);

// --- Build itinerary items from OSRM steps ---

$itineraryItems = [];
foreach ($leg['steps'] as $step) {
    $stepDistM = (float)$step['distance'];
    $stepDist  = ($distanceUnit === 'mi')
        ? round($stepDistM / 1609.344, 2)
        : round($stepDistM / 1000,     2);

    // maneuver.location is GeoJSON [lon,lat] — swap to Bing's [lat,lon]
    $maneuverLoc = $step['maneuver']['location'] ?? null;
    $itineraryItems[] = [
        'travelDistance' => $stepDist,
        'travelDuration' => (int)round($step['duration']),
        'instruction'    => [
            'text'         => osrmStepToText($step),
            'maneuverType' => osrmStepToBingType($step),
        ],
        'maneuverPoint'  => $maneuverLoc ? [
            'type'        => 'Point',
            'coordinates' => [$maneuverLoc[1], $maneuverLoc[0]],
        ] : null,
    ];
}

// --- Route path: swap GeoJSON [lon,lat] → Bing [lat,lon] ---

$routeCoords = [];
foreach ($route['geometry']['coordinates'] as $coord) {
    $routeCoords[] = [$coord[1], $coord[0]];
}

// --- Bounding box from the full route path ---

$minLat = $maxLat = $routeCoords[0][0];
$minLon = $maxLon = $routeCoords[0][1];
foreach ($routeCoords as $coord) {
    if ($coord[0] < $minLat) $minLat = $coord[0];
    if ($coord[0] > $maxLat) $maxLat = $coord[0];
    if ($coord[1] < $minLon) $minLon = $coord[1];
    if ($coord[1] > $maxLon) $maxLon = $coord[1];
}
$bbox = [$minLat, $minLon, $maxLat, $maxLon];

// --- Emit Bing-schema response ---

jsonOut([
    'resourceSets' => [[
        'estimatedTotal' => 1,
        'resources'      => [[
            '__type'         => 'Route:http://schemas.microsoft.com/search/local/ws/rest/v1',
            'bbox'           => $bbox,
            'travelDistance' => $totalDist,
            'travelDuration' => $totalDuration,
            'routeLegs'      => [[
                'itineraryItems' => $itineraryItems,
                'startLocation'  => ['point' => ['coordinates' => $start]],
                'endLocation'    => ['point' => ['coordinates' => $end]],
            ]],
            'routePath' => [
                'line' => ['coordinates' => $routeCoords],
            ],
        ]],
    ]],
]);

// =============================================================================
// Helper functions
// =============================================================================

function osrmStepToText($step) {
    $type     = $step['maneuver']['type']     ?? 'continue';
    $modifier = $step['maneuver']['modifier'] ?? '';
    $name     = $step['name'] ?? '';
    $ref      = $step['ref']  ?? '';
    $road     = $name ?: $ref ?: '';
    $onto     = $road ? ' onto ' . $road : '';
    $on       = $road ? ' on '   . $road : '';

    switch ($type) {
        case 'depart':
            $bearing = cardinalBearing($step['maneuver']['bearing_after'] ?? 0);
            return 'Head ' . $bearing . ($road ? ' on ' . $road : '');

        case 'arrive':
            return 'You have arrived at your destination';

        case 'turn':
            return 'Turn ' . modifierDir($modifier) . $onto;

        case 'new name':
            return 'Continue' . $onto;

        case 'continue':
            return 'Continue' . $on;

        case 'slight turn':
            return 'Slight ' . modifierDir($modifier) . $onto;

        case 'sharp turn':
            return 'Sharp turn ' . modifierDir($modifier) . $onto;

        case 'merge':
            return 'Merge' . $onto;

        case 'on ramp':
            return 'Take the ' . modifierDir($modifier) . ' ramp' . $onto;

        case 'off ramp':
            return 'Take the exit' . $onto;

        case 'fork':
            return 'Keep ' . modifierDir($modifier) . ' at the fork' . $onto;

        case 'end of road':
            return 'Turn ' . modifierDir($modifier) . ' at the end of the road' . $onto;

        case 'roundabout':
        case 'rotary':
            $exit = $step['maneuver']['exit'] ?? '';
            return $exit ? 'Take exit ' . $exit . ' at the roundabout' . $onto
                         : 'Enter the roundabout' . $onto;

        case 'roundabout turn':
            return 'At the roundabout, turn ' . modifierDir($modifier) . $onto;

        default:
            return 'Continue' . $on;
    }
}

function osrmStepToBingType($step) {
    $type     = $step['maneuver']['type']     ?? 'continue';
    $modifier = $step['maneuver']['modifier'] ?? '';

    switch ($type) {
        case 'depart':  return 'DepartStart';
        case 'arrive':  return 'ArriveFinish';
        case 'merge':   return 'Merge';
        case 'off ramp': return 'TakeExit';

        case 'on ramp':
            return (strpos($modifier, 'left') !== false) ? 'TakeRampLeft' : 'TakeRampRight';

        case 'fork':
            return (strpos($modifier, 'left') !== false) ? 'KeepLeft' : 'KeepRight';

        case 'roundabout':
        case 'rotary':
            return 'EnterRoundabout';

        case 'turn':
        case 'slight turn':
        case 'sharp turn':
        case 'end of road':
        case 'roundabout turn':
            switch ($modifier) {
                case 'uturn':       return 'UTurn';
                case 'sharp right':
                case 'right':       return 'TurnRight';
                case 'slight right': return 'BearRight';
                case 'straight':    return 'Straight';
                case 'slight left': return 'BearLeft';
                case 'sharp left':
                case 'left':        return 'TurnLeft';
                default:            return 'Straight';
            }

        default:
            return 'Straight';
    }
}

function modifierDir($modifier) {
    switch ($modifier) {
        case 'uturn':       return 'around';
        case 'sharp right': return 'sharp right';
        case 'right':       return 'right';
        case 'slight right': return 'slight right';
        case 'straight':    return 'straight';
        case 'slight left': return 'slight left';
        case 'left':        return 'left';
        case 'sharp left':  return 'sharp left';
        default:            return $modifier ?: 'straight';
    }
}

function cardinalBearing($deg) {
    $dirs = ['north','northeast','east','southeast','south','southwest','west','northwest'];
    return $dirs[(int)round($deg / 45) % 8];
}
