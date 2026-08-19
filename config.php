<?php

// Public URL where this proxy is hosted, with trailing slash.
// When hosted as the root of its own virtual server this is just the origin.
$base = 'http://maps.webosarchive.org/';

return [
    // Used by the shim JS to load Leaflet assets, and to build tile-proxy URLs.
    'proxyBaseUrl' => $base,

    // Nominatim geocoding service. The public instance is free for low-volume use;
    // must include a valid User-Agent and stay under 1 req/sec.
    // Self-host: https://nominatim.org/release-docs/latest/admin/Installation/
    'nominatimUrl' => 'https://nominatim.openstreetmap.org',

    // Sent to Nominatim as the HTTP User-Agent. Policy requires identifying your app.
    'nominatimUserAgent' => 'webOS-Maps-Proxy/1.0 (maps.webosarchive.org)',

    // OSRM routing service. The public demo instance has no uptime guarantee.
    // Self-host: https://github.com/Project-OSRM/osrm-backend
    'osrmUrl' => 'http://router.project-osrm.org',

    // -------------------------------------------------------------------------
    // Map tiles
    //
    // Two layers of URL here:
    //   *TileUrl     -> what the DEVICE loads (emitted to the shim).
    //   *UpstreamUrl -> what tiles.php fetches server-side.
    //
    // HTTP tile proxy (default): the device loads tiles over plain HTTP from
    // tiles.php, which fetches the real (HTTPS-only) tiles server-side. This is
    // what lets the oldest webOS devices show the map without an ssl-bump proxy.
    //
    // To load tiles DIRECTLY from the source over HTTPS instead (e.g. to keep
    // tile bandwidth off this server, when your devices can do modern TLS), set
    // each *TileUrl equal to the matching *UpstreamUrl below.
    // -------------------------------------------------------------------------

    // Device-facing: served over HTTP by tiles.php. {z}/{x}/{y} for coords.
    'osmTileUrl'    => $base . 'tiles/road/{z}/{x}/{y}.png',
    'aerialTileUrl' => $base . 'tiles/aerial/{z}/{x}/{y}.png',

    // Upstream sources the proxy fetches from (HTTPS).
    'osmUpstreamUrl'    => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    // Esri World Imagery is free for non-commercial use. {y}/{x} order is
    // reversed vs OSM — tiles.php maps named placeholders, so it stays correct.
    'aerialUpstreamUrl' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',

    'osmTileSubdomains' => 'abc',
    'osmAttribution'    => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    'aerialAttribution' => 'Tiles &copy; Esri',

    // Sent upstream when the proxy fetches a tile (OSM policy requires this).
    'tileUserAgent' => 'webOS-Maps-TileProxy/1.0 (maps.webosarchive.org)',
    // On-disk tile cache. Must be writable by the web-server user. 30-day TTL.
    'tileCacheDir' => __DIR__ . '/tiles_cache',
    'tileCacheTtl' => 60 * 60 * 24 * 30,
];
