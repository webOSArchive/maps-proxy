// Microsoft.Maps v7.0 compatibility shim for com.palm.app.maps
// Implements the exact API surface the app uses, backed by Leaflet.
// Config is injected by mapcontrol.php as window._bingProxyConfig.

(function (global) {
    'use strict';

    if (typeof L === 'undefined') {
        console.error('Microsoft.Maps shim: Leaflet not loaded');
        return;
    }

    var cfg = global._bingProxyConfig || {};

    if (!global.Microsoft) global.Microsoft = {};
    if (!global.Microsoft.Maps) global.Microsoft.Maps = {};

    var MM = global.Microsoft.Maps;

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    MM.MapTypeId = {
        road:           'road',
        aerial:         'aerial',
        birdseye:       'aerial',   // no true birdseye; use aerial
        auto:           'road',
        collinsBart:    'road',
        mercator:       'road',
        ordnanceSurvey: 'road'
    };

    MM.PixelReference = {
        control:  'control',
        page:     'page',
        viewport: 'viewport'
    };

    // -------------------------------------------------------------------------
    // Location — simple lat/lng value object
    // -------------------------------------------------------------------------

    MM.Location = function (latitude, longitude) {
        this.latitude  = latitude;
        this.longitude = longitude;
    };

    // -------------------------------------------------------------------------
    // Point — simple x/y value object (used for pushpin anchor offsets)
    // -------------------------------------------------------------------------

    MM.Point = function (x, y) {
        this.x = x;
        this.y = y;
    };

    // -------------------------------------------------------------------------
    // Color — ARGB (0–255 each)
    // -------------------------------------------------------------------------

    MM.Color = function (alpha, red, green, blue) {
        this.a = alpha;
        this.r = red;
        this.g = green;
        this.b = blue;
    };

    MM.Color.prototype.toString = function () {
        return 'rgba(' + this.r + ',' + this.g + ',' + this.b + ',' +
               (this.a / 255).toFixed(2) + ')';
    };

    // -------------------------------------------------------------------------
    // LocationRect — bounding box backed by L.LatLngBounds
    // -------------------------------------------------------------------------

    MM.LocationRect = function () {
        this._bounds = null;
    };

    MM.LocationRect.fromLocations = function (loc1, loc2) {
        var rect = new MM.LocationRect();
        rect._bounds = L.latLngBounds(
            L.latLng(loc1.latitude, loc1.longitude),
            L.latLng(loc2.latitude, loc2.longitude)
        );
        return rect;
    };

    MM.LocationRect.fromEdges = function (north, west, south, east) {
        var rect = new MM.LocationRect();
        rect._bounds = L.latLngBounds(L.latLng(south, west), L.latLng(north, east));
        return rect;
    };

    MM.LocationRect.prototype.getNorthwest = function () {
        var nw = this._bounds.getNorthWest();
        return new MM.Location(nw.lat, nw.lng);
    };

    MM.LocationRect.prototype.getSoutheast = function () {
        var se = this._bounds.getSouthEast();
        return new MM.Location(se.lat, se.lng);
    };

    MM.LocationRect.prototype.contains = function (location) {
        return this._bounds.contains(L.latLng(location.latitude, location.longitude));
    };

    // -------------------------------------------------------------------------
    // TileSource / TileLayer — traffic overlay; no free source, so no-op
    // -------------------------------------------------------------------------

    MM.TileSource = function (options) {
        this.uriConstructor = (options && options.uriConstructor) || '';
    };

    MM.TileLayer = function (options) {
        this._options = options || {};
    };

    MM.TileLayer.prototype._addToMap    = function () {};
    MM.TileLayer.prototype._removeFromMap = function () {};

    // -------------------------------------------------------------------------
    // EntityCollection — manages Leaflet layers/markers on the map
    // -------------------------------------------------------------------------

    function EntityCollection(shimMap) {
        this._shimMap  = shimMap;
        this._entities = [];
    }

    EntityCollection.prototype.push = function (entity) {
        this._entities.push(entity);
        if (entity && typeof entity._addToMap === 'function') {
            entity._addToMap(this._shimMap._leaflet);
        }
    };

    EntityCollection.prototype.remove = function (entity) {
        var idx = this._entities.indexOf(entity);
        if (idx !== -1) {
            this._entities.splice(idx, 1);
            if (typeof entity._removeFromMap === 'function') {
                entity._removeFromMap(this._shimMap._leaflet);
            }
        }
    };

    EntityCollection.prototype.clear = function () {
        var snap = this._entities.slice();
        for (var i = 0; i < snap.length; i++) this.remove(snap[i]);
        this._entities = [];
    };

    // -------------------------------------------------------------------------
    // Tile layer definitions
    // -------------------------------------------------------------------------

    var TILE_DEFS = {
        road: {
            url:     cfg.osmTileUrl    || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            options: {
                attribution: cfg.osmAttribution || '&copy; OpenStreetMap contributors',
                subdomains:  cfg.osmSubdomains  || 'abc',
                maxZoom: 19
            }
        },
        aerial: {
            url:     cfg.aerialTileUrl || 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            options: {
                attribution: cfg.aerialAttribution || 'Tiles &copy; Esri',
                maxZoom: 19
            }
        }
    };

    // -------------------------------------------------------------------------
    // Map — wraps L.Map
    // -------------------------------------------------------------------------

    var _allMaps = [];   // for global resize handling

    MM.Map = function (element, options) {
        options = options || {};

        this._leaflet = L.map(element, {
            zoomControl:     false,
            attributionControl: false,
            keyboard:        !(options.disableKeyboardInput),
            dragging:        true,          // always allow; touch needs this
            scrollWheelZoom: !(options.disableMouseInput),
            touchZoom:       true,
            tap:             true,
            doubleClickZoom: true
        });

        // Enyo's popup layer has a low fixed z-index; Leaflet's mapPane gets
        // z-index:400 from .leaflet-pane and creates an isolated stacking context
        // that beats it. Anchoring the map container at z-index:0 confines all
        // Leaflet content (tiles, markers, canvas) inside one stacking context
        // that sits below Enyo's popup/toaster layer.
        element.style.zIndex = '0';

        this._currentTypeKey = 'road';
        this._tileLayer = L.tileLayer(TILE_DEFS.road.url, TILE_DEFS.road.options)
                           .addTo(this._leaflet);

        this.entities = new EntityCollection(this);

        // Leaflet needs an initial view before it renders anything
        this._leaflet.setView([0, 0], 2);

        _allMaps.push(this);
    };

    MM.Map.prototype.setView = function (opts) {
        if (!opts) return;

        if (opts.mapTypeId !== undefined) {
            this._setMapType(opts.mapTypeId);
        }

        if (opts.bounds && opts.bounds._bounds) {
            this._leaflet.fitBounds(opts.bounds._bounds, { animate: false });
        } else if (opts.center) {
            var zoom = (opts.zoom !== undefined) ? opts.zoom : this._leaflet.getZoom();
            this._leaflet.setView(
                L.latLng(opts.center.latitude, opts.center.longitude),
                zoom,
                { animate: false }
            );
        } else if (opts.zoom !== undefined) {
            this._leaflet.setZoom(opts.zoom, { animate: false });
        }
    };

    MM.Map.prototype._setMapType = function (typeId) {
        var key = (typeof typeId === 'string') ? typeId : 'road';
        var def = TILE_DEFS[key] || TILE_DEFS.road;
        if (key === this._currentTypeKey) return;

        this._leaflet.removeLayer(this._tileLayer);
        this._tileLayer = L.tileLayer(def.url, def.options).addTo(this._leaflet);
        this._currentTypeKey = key;
    };

    MM.Map.prototype.getZoom = function () {
        return this._leaflet.getZoom();
    };

    MM.Map.prototype.getCenter = function () {
        var c = this._leaflet.getCenter();
        return new MM.Location(c.lat, c.lng);
    };

    MM.Map.prototype.getBounds = function () {
        var rect = new MM.LocationRect();
        rect._bounds = this._leaflet.getBounds();
        return rect;
    };

    // Returns pixel coordinates relative to the map container element.
    // PixelReference.control is what the app always passes.
    MM.Map.prototype.tryLocationToPixel = function (location /*, pixelRef */) {
        var pt = this._leaflet.latLngToContainerPoint(
            L.latLng(location.latitude, location.longitude)
        );
        return { x: Math.round(pt.x), y: Math.round(pt.y) };
    };

    // Keep all Leaflet instances sized correctly when the window changes
    global.addEventListener('resize', function () {
        for (var i = 0; i < _allMaps.length; i++) {
            _allMaps[i]._leaflet.invalidateSize();
        }
    });

    // -------------------------------------------------------------------------
    // Pushpin — wraps L.Marker
    // -------------------------------------------------------------------------

    MM.Pushpin = function (location, options) {
        options = options || {};
        this._location = location;
        this._opts     = options;
        this._marker   = this._buildMarker();
    };

    MM.Pushpin.prototype._buildIcon = function () {
        if (this._opts.icon) {
            var w = this._opts.width  || 28;
            var h = this._opts.height || 28;
            var ax = this._opts.anchor ? this._opts.anchor.x : Math.round(w / 2);
            var ay = this._opts.anchor ? this._opts.anchor.y : Math.round(h / 2);
            return L.icon({
                iconUrl:    this._opts.icon,
                iconSize:   [w, h],
                iconAnchor: [ax, ay],
                className:  ''          // suppress Leaflet's default shadow
            });
        }
        return new L.Icon.Default();
    };

    MM.Pushpin.prototype._buildMarker = function () {
        var marker = L.marker(
            L.latLng(this._location.latitude, this._location.longitude),
            {
                draggable:    !!this._opts.draggable,
                icon:         this._buildIcon(),
                opacity:      (this._opts.visible === false) ? 0 : 1,
                zIndexOffset: this._opts.zIndex || 0
            }
        );
        marker._shimPushpin = this;
        return marker;
    };

    MM.Pushpin.prototype._addToMap = function (leafletMap) {
        this._leafletMap = leafletMap;
        // Always add to the map so removeLayer is always safe.
        // Visibility is controlled via opacity rather than presence.
        this._marker.addTo(leafletMap);
        if (this._opts.visible === false) {
            this._marker.setOpacity(0);
        }
    };

    MM.Pushpin.prototype._removeFromMap = function (leafletMap) {
        if (this._leafletMap) {
            leafletMap.removeLayer(this._marker);
            this._leafletMap = null;
        }
    };

    MM.Pushpin.prototype.getLocation = function () {
        return this._location;
    };

    MM.Pushpin.prototype.setLocation = function (location) {
        this._location = location;
        this._marker.setLatLng(L.latLng(location.latitude, location.longitude));
    };

    MM.Pushpin.prototype.setOptions = function (options) {
        if (!options) return;

        var iconChanged = false;
        var keys = ['icon', 'width', 'height', 'anchor'];
        for (var i = 0; i < keys.length; i++) {
            if (options[keys[i]] !== undefined) {
                this._opts[keys[i]] = options[keys[i]];
                iconChanged = true;
            }
        }
        if (iconChanged) {
            this._marker.setIcon(this._buildIcon());
        }

        if (options.visible !== undefined) {
            this._marker.setOpacity(options.visible ? 1 : 0);
        }

        if (options.draggable !== undefined) {
            options.draggable
                ? this._marker.dragging.enable()
                : this._marker.dragging.disable();
        }

        if (options.zIndex !== undefined) {
            this._marker.setZIndexOffset(options.zIndex || 0);
        }

        // Absorb any remaining keys (title, address, etc.)
        for (var k in options) {
            if (options.hasOwnProperty(k)) this._opts[k] = options[k];
        }
    };

    // -------------------------------------------------------------------------
    // Polyline — route path drawn on a <canvas> element injected directly into
    // Leaflet's overlay pane. This bypasses Leaflet's renderer pipeline (SVG
    // and Canvas renderers both fail on webOS 2.x WebKit due to broken browser
    // detection), and also avoids createElementNS (SVG namespaces are broken
    // on the same WebKit build). Plain <canvas> has worked since webOS 1.x.
    //
    // The canvas lives in the overlay pane so it pans with the map via CSS
    // transform during a drag — we only redraw on moveend/zoomend/viewreset.
    // -------------------------------------------------------------------------

    MM.Polyline = function (locations, options) {
        options = options || {};
        this._latlngs = [];
        for (var i = 0; i < locations.length; i++) {
            this._latlngs.push(L.latLng(locations[i].latitude, locations[i].longitude));
        }
        this._color  = options.strokeColor ? options.strokeColor.toString() : 'rgba(0,0,200,0.8)';
        this._map    = null;
        this._canvas = null;
        this._ctx    = null;
    };

    MM.Polyline.prototype._addToMap = function (lm) {
        try {
            var size   = lm.getSize();
            var canvas = document.createElement('canvas');
            canvas.width  = size.x;
            canvas.height = size.y;
            canvas.style.cssText = 'position:absolute;top:0;left:0;pointer-events:none;';

            var ctx = canvas.getContext && canvas.getContext('2d');
            if (!ctx) throw new Error('no 2d context');

            lm.getPanes().overlayPane.appendChild(canvas);

            this._map    = lm;
            this._canvas = canvas;
            this._ctx    = ctx;

            this._onMove = L.bind(this._redraw, this);
            lm.on('moveend zoomend viewreset', this._onMove);
            this._redraw();
        } catch (e) {
            console.warn('Polyline canvas failed:', e.message);
        }
    };

    MM.Polyline.prototype._redraw = function () {
        if (!this._map || !this._ctx || !this._latlngs.length) return;
        try {
            var size = this._map.getSize();
            // Resizing clears the canvas
            this._canvas.width  = size.x;
            this._canvas.height = size.y;

            var ctx = this._ctx;
            ctx.strokeStyle = this._color;
            ctx.lineWidth   = 4;
            ctx.globalAlpha = 0.85;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            ctx.beginPath();

            for (var i = 0; i < this._latlngs.length; i++) {
                // latLngToLayerPoint gives coords in the overlay-pane coordinate
                // system, which is exactly what we need for a canvas in that pane.
                var pt = this._map.latLngToLayerPoint(this._latlngs[i]);
                if (i === 0) ctx.moveTo(pt.x, pt.y);
                else         ctx.lineTo(pt.x, pt.y);
            }
            ctx.stroke();
        } catch (e) {}
    };

    MM.Polyline.prototype._removeFromMap = function (lm) {
        if (this._onMove) {
            lm.off('moveend zoomend viewreset', this._onMove);
            this._onMove = null;
        }
        if (this._canvas && this._canvas.parentNode) {
            this._canvas.parentNode.removeChild(this._canvas);
        }
        this._canvas = null;
        this._ctx    = null;
        this._map    = null;
    };

    // -------------------------------------------------------------------------
    // Events — bridges Bing event names to Leaflet events
    // -------------------------------------------------------------------------

    var BINGEV_TO_LEAFLET = {
        click:         ['click'],
        mousedown:     ['mousedown'],
        mousemove:     ['mousemove'],
        mouseup:       ['mouseup'],
        viewchangeend: ['moveend', 'zoomend'],
        dblclick:      ['dblclick']
    };

    function leafletTargetOf(shimObj) {
        if (shimObj instanceof MM.Map)     return shimObj._leaflet;
        if (shimObj instanceof MM.Pushpin) return shimObj._marker;
        return null;
    }

    MM.Events = {
        _refs: [],

        addHandler: function (shimTarget, eventName, handler) {
            var lt = leafletTargetOf(shimTarget);
            if (!lt) return null;

            var leafletEvents = BINGEV_TO_LEAFLET[eventName];
            if (!leafletEvents) return null;

            var wrapped = function (e) {
                var ev = {
                    target:        shimTarget,
                    originalEvent: e.originalEvent || e,
                    location:      e.latlng ? new MM.Location(e.latlng.lat, e.latlng.lng) : null,
                    point:         e.containerPoint ? { x: e.containerPoint.x, y: e.containerPoint.y } : null
                };
                handler(ev);
            };

            for (var i = 0; i < leafletEvents.length; i++) {
                lt.on(leafletEvents[i], wrapped);
            }

            var ref = { lt: lt, leafletEvents: leafletEvents, wrapped: wrapped, handler: handler };
            MM.Events._refs.push(ref);
            return ref;
        },

        addThrottledHandler: function (shimTarget, eventName, handler, delayMs) {
            var lastFired = 0;
            var throttled = function (ev) {
                var now = Date.now();
                if (now - lastFired >= delayMs) {
                    lastFired = now;
                    handler(ev);
                }
            };
            return MM.Events.addHandler(shimTarget, eventName, throttled);
        },

        removeHandler: function (ref) {
            if (!ref || !ref.lt) return;
            var idx = MM.Events._refs.indexOf(ref);
            if (idx !== -1) MM.Events._refs.splice(idx, 1);
            for (var i = 0; i < ref.leafletEvents.length; i++) {
                ref.lt.off(ref.leafletEvents[i], ref.wrapped);
            }
        }
    };

    // -------------------------------------------------------------------------
    // loadModule — traffic and other modules; no-op, fire callback immediately
    // -------------------------------------------------------------------------

    MM.loadModule = function (moduleName, options) {
        if (options && typeof options.callback === 'function') {
            setTimeout(options.callback, 0);
        }
    };

    // Stub out the Traffic module object so code that references it doesn't throw
    MM.Traffic = {
        TrafficLayer: function () {
            this.show = function () {};
            this.hide = function () {};
        }
    };

}(window));
