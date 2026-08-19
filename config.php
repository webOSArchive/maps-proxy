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

    // -------------------------------------------------------------------------
    // Radar overlay (radar-map.php / radar-gif.php)
    //
    // Composites a live RainViewer radar frame onto the OSM basemap above.
    // Free, no API key, but personal/non-commercial use + attribution
    // required per RainViewer's terms: https://www.rainviewer.com/api.html
    // -------------------------------------------------------------------------

    'rainviewerMetaUrl' => 'https://api.rainviewer.com/public/weather-maps.json',

    // Slippy-map zoom the basemap+radar tiles are fetched at. 6 keeps
    // individual tile fetches small while still reading as a real map.
    'radarZoom' => 6,

    // On-disk cache, separate from tileCacheDir above (radar tiles are
    // keyed per-frame-timestamp, basemap tiles are keyed the same as
    // tiles.php's own cache but not shared with it). Must be writable by
    // the web-server user.
    'radarCacheDir' => __DIR__ . '/radar_cache',
    // Basemap tiles essentially never change on this timescale.
    'radarBasemapCacheTtl' => 60 * 60 * 24,
    // Radar tiles are cached under their frame path, so a hit is always the
    // exact right frame — safe to cache well past RainViewer's own ~10 min
    // refresh cadence.
    'radarTileCacheTtl' => 60 * 30,
    // weather-maps.json (the frame list) changes roughly every 10 min.
    'radarMetaCacheTtl' => 60 * 2,

    // Animated GIF: how many of RainViewer's past frames to include, and
    // the per-frame delay. 13 frames * 10-min spacing = ~2 hours of radar
    // history; 300ms/frame reads as motion without feeling sluggish.
    'radarFrameCount' => 13,
    'radarFrameDelayMs' => 300,

    // -------------------------------------------------------------------------
    // NEXRAD mosaic (accuweather-radar.php's US branch — see radar-common.php's
    // radarFetchNexradTiles()/radarNexradAgeSuffixes())
    //
    // Iowa Environmental Mesonet's public national composite-reflectivity tile
    // cache — NOAA/NWS NEXRAD data mirrored as standard z/x/y slippy tiles,
    // unlike radar.weather.gov's own fixed-extent pre-rendered station loop
    // GIFs. Free, community-run (mesonet.agron.iastate.edu); confirmed live
    // and returning real 256x256 PNG tiles. CONUS-only — HI_RE/AL_RE
    // (Hawaii/Alaska) requests fall back to RainViewer regardless of this.
    // -------------------------------------------------------------------------

    // {suffix} is '' for the current frame or '-mNNm' (NN = 05..55, 5-min
    // steps) for a past frame.
    'nexradTileUrl' => 'https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q{suffix}/{z}/{x}/{y}.png',
    'nexradUserAgent' => 'webOS-Maps-TileProxy/1.0 (maps.webosarchive.org)',
    // IEM's mosaic tiles refresh roughly every 5 minutes.
    'nexradTileCacheTtl' => 60 * 2,

    // Zoom bounds for accuweather-radar.php's geowidth-derived zoom
    // (radarZoomForGeowidth() in radar-common.php) — clamped to what OSM,
    // RainViewer, and IEM all realistically have usable tiles for.
    'radarMinZoom' => 3,
    'radarMaxZoom' => 10,
];
