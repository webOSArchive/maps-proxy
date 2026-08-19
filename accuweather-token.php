<?php
// GET /nmigs/acx.aspx?cb=<cachebuster>
//
// Stand-in for AccuWeather's dead NMIGS "crypto feed". The real app's
// client-side decryptToken() (app/services/radar-image.js) just reverses a
// fixed 54-char interleaving and never throws on a short/empty input (uses
// .substr, which is safe out-of-range in JS) — and accuweather-radar.php
// ignores the resulting token entirely, since we own both endpoints. So this
// only needs to satisfy the client's XML parse, nothing more.

header('Content-Type: text/xml');
header('Cache-Control: no-store');
echo '<?xml version="1.0"?><acx>0</acx>';
