<?php
// Serves the Microsoft.Maps compatibility shim to com.palm.app.maps.
// The app loads this as if it were the real Bing Maps v7.0 SDK.
// This file inlines Leaflet (CSS + JS) followed by the shim, so the
// app gets everything it needs in a single synchronous script load.

header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');

$shimDir = __DIR__ . '/shim/';
$leafletCss = $shimDir . 'leaflet.css';
$leafletJs  = $shimDir . 'leaflet.js';
$shimJs     = $shimDir . 'microsoft-maps-shim.js';

$missing = [];
if (!file_exists($leafletCss)) $missing[] = 'shim/leaflet.css';
if (!file_exists($leafletJs))  $missing[] = 'shim/leaflet.js';
if (!file_exists($shimJs))     $missing[] = 'shim/microsoft-maps-shim.js';

if (!empty($missing)) {
    echo "console.error('bing-proxy/mapcontrol: missing files: " . implode(', ', $missing) . "');";
    echo "console.error('Download Leaflet 1.9.x and place leaflet.js + leaflet.css in bing-proxy/shim/');";
    exit;
}

$config = include __DIR__ . '/config.php';

// Inject Leaflet CSS as an inline <style> block so it's immediately available
// without a second HTTP request, avoiding FOUC and timing issues.
echo "(function(){\n";
echo "  var s=document.createElement('style');\n";
echo "  s.type='text/css';\n";
echo "  s.innerHTML=" . json_encode(file_get_contents($leafletCss)) . ";\n";
echo "  (document.head||document.documentElement).appendChild(s);\n";
echo "})();\n\n";

// Inline Leaflet JS — must come before the shim
readfile($leafletJs);
echo "\n\n";

// Emit proxy config for the shim to consume
echo "var _bingProxyConfig = " . json_encode([
    'proxyBase'         => $config['proxyBaseUrl'],
    'osmTileUrl'        => $config['osmTileUrl'],
    'osmSubdomains'     => $config['osmTileSubdomains'],
    'osmAttribution'    => $config['osmAttribution'],
    'aerialTileUrl'     => $config['aerialTileUrl'],
    'aerialAttribution' => $config['aerialAttribution'],
]) . ";\n\n";

// Inline the Microsoft.Maps shim
readfile($shimJs);
