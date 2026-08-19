# webOS Maps Revival

![Maps Icon](icon.png)

Maps on legacy webOS have been broken since Bing Maps APIs were retired. This repo contains the back-end code base:

- **maps-proxy** — this server-side shim that uses Leaflet + OSM + Nominatim + OSRM

Its used by

- [webos-maps](https://www.github.com/webOSArchive/webos-maps) — original HP/Palm maps app (Enyo 0.10 framework), patched to point at `map-proxy`

---

## bing-proxy setup

### 1. Download Leaflet

Download **Leaflet 1.9.x** from [leafletjs.com/download.html](https://leafletjs.com/download.html) and place the two files here:

```
bing-proxy/shim/leaflet.js
bing-proxy/shim/leaflet.css
```

### 2. Configure the proxy

Copy and edit the config:

```bash
cp bing-proxy/config.php bing-proxy/config.local.php   # optional; edit config.php directly
```

Open `bing-proxy/config.php` and set:

- **`proxyBaseUrl`** — the public URL where you're hosting `bing-proxy/`, with trailing slash.  
  Example: `http://maps.webosarchive.org/`
- **`nominatimUserAgent`** — identify your deployment per Nominatim's [usage policy](https://operations.osmfoundation.org/policies/tiles/).  
  Example: `webOS-Maps/1.0 (yourdomain.org)`
- **`osrmUrl`** — defaults to the public OSRM demo (`http://router.project-osrm.org`). Override if you're running a self-hosted instance.
- **`osmTileUrl` / `aerialTileUrl`** — what the **device** loads. By default these point at the built-in **HTTP tile proxy** (`tiles.php`), so the oldest webOS devices can load the map over plain HTTP without an on-device ssl-bump proxy. To instead have devices fetch tiles directly over HTTPS, set each equal to its matching `*UpstreamUrl`.
- **`osmUpstreamUrl` / `aerialUpstreamUrl`** — the real (HTTPS) tile sources that `tiles.php` fetches and caches.
- **`tileCacheDir`** — on-disk tile cache (default `bing-proxy/tiles_cache/`). Must be **writable by the web-server user**. OSM's tile policy requires caching; the proxy also sends `tileUserAgent` upstream.

> The HTTP tile proxy is the recommended default — it removes the per-device Squid ssl-bump requirement entirely. Tiles are validated (`layer`/`z`/`x`/`y` only) and cached, so it isn't an open proxy.

**Tile cache maintenance.** The cache never deletes on its own — it only refreshes a tile (in place) when it's requested again after the 30-day TTL, so tiles for one-off locations linger forever and the directory grows unbounded. Add a daily cron to evict cold tiles. Anything actively viewed is re-fetched within the TTL (its mtime stays fresh), so this only removes tiles not seen in ~35 days; if one's needed again it's simply re-fetched:

```cron
# /etc/cron.d/webos-maps-tilecache  — prune tiles not refreshed in ~35 days
17 4 * * *  www-data  find /var/www/bing-proxy/tiles_cache -type f -mtime +35 -delete; find /var/www/bing-proxy/tiles_cache -type d -empty -delete
```

(Adjust the path and user to your deployment. Lower `-mtime` to cap disk use more aggressively, at the cost of more re-fetching.)

### 3. Configure your web server

#### nginx

`bing-proxy` is intended as the root of its own virtual server. This assumes the repo's `bing-proxy/` directory is deployed to `/var/www/bing-proxy/` and PHP is handled via php-fpm.

```nginx
server {
    listen 80;
    server_name maps.webosarchive.org;

    root /var/www/bing-proxy;
    index index.php;

    # Serve static shim assets (leaflet.js, leaflet.css) directly
    location /shim/ {
        try_files $uri =404;
        expires 1d;
        add_header Cache-Control "public";
    }

    # Bing Maps SDK endpoint → shim server
    location = /mapcontrol.ashx {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/mapcontrol.php;
        include fastcgi_params;
    }

    # Geocoding
    location ~ ^/REST/v1/Locations {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api/locations.php;
        include fastcgi_params;
    }

    # Routing
    location ~ ^/REST/v1/Routes {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api/routes.php;
        include fastcgi_params;
    }

    # POI / Phonebook search
    location = /json.aspx {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api/places.php;
        include fastcgi_params;
    }

    # HTTP tile proxy
    location ~ ^/tiles/(road|aerial)/(\d+)/(\d+)/(\d+)\.png$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/tiles.php;
        fastcgi_param QUERY_STRING layer=$1&z=$2&x=$3&y=$4;
        include fastcgi_params;
    }

    # Radar overlay (see "Radar overlay" section below)
    location = /radar/map.png {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/radar-map.php;
        include fastcgi_params;
    }
    location = /radar/map.gif {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/radar-gif.php;
        include fastcgi_params;
    }

}
```

Adjust `fastcgi_pass` to match your php-fpm socket or TCP address (e.g. `127.0.0.1:9000`).

Create the tile and radar cache directories and make them writable by the web-server user:

```bash
mkdir -p /var/www/bing-proxy/tiles_cache /var/www/bing-proxy/radar_cache
chown www-data:www-data /var/www/bing-proxy/tiles_cache /var/www/bing-proxy/radar_cache
```

#### Apache

`.htaccess` is included in `bing-proxy/`. Enable `mod_rewrite` and ensure `AllowOverride All` is set for the directory:

```apache
<Directory /var/www/maps/bing-proxy>
    AllowOverride All
</Directory>
```

### 4. Deploy

Copy the `bing-proxy/` directory to your server at the path matching `proxyBaseUrl`. Ensure the web server user can read all files. No writable directories are needed.

### 5. Verify

Test each endpoint with curl before putting the app on a device:

```bash
BASE=http://maps.webosarchive.org

# Shim JS loads (should return JavaScript defining Microsoft.Maps)
curl -s "$BASE/mapcontrol.ashx?v=7.0&mkt=en-us" | head -5

# HTTP tile proxy (should return a PNG road tile and a JPEG aerial tile)
curl -s -o /dev/null -w "road  %{http_code} %{content_type}\n" "$BASE/tiles/road/3/4/2.png"
curl -s -o /dev/null -w "aerial %{http_code} %{content_type}\n" "$BASE/tiles/aerial/3/4/2.png"

# Geocoding
curl -s "$BASE/REST/v1/Locations/Seattle,WA?output=json" | python3 -m json.tool | head -20

# Reverse geocode
curl -s "$BASE/REST/v1/Locations/47.6062,-122.3321?output=json" | python3 -m json.tool | head -20

# POI search
curl -s "$BASE/json.aspx?Query=coffee&Latitude=47.6062&Longitude=-122.3321&Phonebook.Count=5" | python3 -m json.tool | head -20

# Routing (geocodes waypoints then calls OSRM — takes a few seconds)
curl -s "$BASE/REST/v1/Routes/Driving?wp.0=Seattle,WA&wp.1=Portland,OR&distanceUnit=km&output=json" | python3 -m json.tool | head -30

# Radar overlay (should return a PNG, and a GIF if Imagick is installed)
curl -s -o /dev/null -w "radar png %{http_code} %{content_type}\n" "$BASE/radar/map.png?lat=40.7128&lon=-74.0060"
curl -s -o /dev/null -w "radar gif %{http_code} %{content_type}\n" "$BASE/radar/map.gif?lat=40.7128&lon=-74.0060"
```

## Radar overlay

`radar-map.php` / `radar-gif.php` composite a live [RainViewer](https://www.rainviewer.com/api.html)
radar frame onto the OSM basemap above — RainViewer's own tiles have no
basemap of their own (unlabeled color blobs on a transparent background),
so this fetches a basemap tile and a radar tile per grid cell and
alpha-blends them server-side with GD.

- `GET /radar/map.png?lat=<lat>&lon=<lon>` — single flat PNG, 512x512,
  centered on the point, using the latest radar frame.
- `GET /radar/map.gif?lat=<lat>&lon=<lon>` — the same, animated: loops
  RainViewer's last `radarFrameCount` past frames (13 by default, ~2 hours
  at their 10-min cadence).

**The GIF endpoint requires the PHP `imagick` extension** (`apt install
php-imagick` on Debian/Ubuntu) — GD alone has no animated-GIF writer. If
Imagick isn't installed, `/radar/map.gif` returns `501` with a clear
message rather than a broken image; `/radar/map.png` works either way.

Radar/basemap tiles are cached on disk under `radarCacheDir` (`radar_cache/`
by default, separate from `tiles_cache/` above), TTLs configured in
`config.php`. **RainViewer's terms are personal/non-commercial use with
attribution required** — worth a re-check if this ends up serving more
than a small hobbyist/community audience.
