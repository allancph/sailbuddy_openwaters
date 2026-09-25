(function (Drupal, drupalSettings, once) {
  "use strict";

  var DEG = 2;
  var UNIT_MAP = { m: 'meters', meters: 'meters', mtr: 'meters', ft: 'feet', feet: 'feet', fm: 'fathoms' };
  var DEFAULTS = {
    enable_ais: true,
    ais_refresh: 30,
    enable_tides: true,
    tides_units: 'm',
    ais_api: 'https://ais.openwaters.io/v1/vessels',
    tide_api: 'https://api.openwaters.io/tides/extremes',
    tide_stations: 'https://api.openwaters.io/tides/stations',
    wind_tiles: null,
    wind_particles: true,
    wind_particles_url: '/weather/wind-grid',
    wind_particles_maxspeed: 20,
    initial_zoom: 10
  };
  var MAX_DIAGONAL_KM = 1400;

  function round(v, p) {
    var f = Math.pow(10, p);
    return Math.round(v * f) / f;
  }

  function throttle(fn, delay) {
    var pending = null;
    return function () {
      if (pending) return;
      var now = function () {
        pending = null;
        fn();
      };
      pending = window.setTimeout(now, delay);
    };
  }

  function bboxDiagonalKm(b) {
    var sw = b.getSouthWest();
    var ne = b.getNorthEast();
    var dLat = ne.lat - sw.lat;
    var dLng = ne.lng - sw.lng;
    var midLat = (ne.lat + sw.lat) / 2 * Math.PI / 180;
    var kmLat = dLat * 111.32;
    var kmLng = dLng * 111.32 * Math.cos(midLat);
    return Math.sqrt(kmLat * kmLat + kmLng * kmLng);
  }

  function vesselStyle(feature) {
    var sog = feature.properties && feature.properties.sog ? feature.properties.sog : 0;
    return {
      radius: 4 + Math.min(6, sog),
      fillColor: sog > 0.5 ? '#dc2626' : '#16a34a',
      color: '#ffffff',
      weight: 1,
      fillOpacity: 0.9
    };
  }

  function vesselPopup(feature, layer) {
    var p = feature.properties || {};
    var rows = [];
    if (p.name) rows.push('<b>' + p.name + '</b>');
    if (p.mmsi) rows.push(Drupal.t('MMSI: @mmsi', { '@mmsi': p.mmsi }));
    if (p.type) rows.push(Drupal.t('Type: @type', { '@type': p.type }));
    if (typeof p.sog === 'number') rows.push(Drupal.t('Speed: @sog kn', { '@sog': p.sog.toFixed(1) }));
    if (typeof p.cog === 'number') rows.push(Drupal.t('Course: @cog°', { '@cog': p.cog.toFixed(0) }));
    if (p.seen) rows.push(Drupal.t('Last seen: @seen', { '@seen': p.seen }));
    layer.bindPopup(rows.join('<br>'));
  }

  function buildExtremesHtml(station, extremes, unit) {
    var name = (station && station.name) ? station.name : '';
    var head = '<div><b>' + Drupal.t('Tides — @name', { '@name': name }) + '</b><br>';
    var rows = extremes.map(function (e) {
      var t = new Date(e.time);
      var hh = ('0' + t.getUTCHours()).slice(-2);
      var mm = ('0' + t.getUTCMinutes()).slice(-2);
      var dd = t.getUTCDate();
      var mo = t.getUTCMonth() + 1;
      var label = e.high ? Drupal.t('△ high water') : (e.low ? Drupal.t('▽ low water') : '');
      return dd + '/' + mo + ' ' + hh + ':' + mm + ' \u2014 ' + e.level.toFixed(2) + ' ' + unit + ' ' + label;
    }).join('<br>');
    return head + rows + '</div>';
  }

  function fitFeatureBounds(map, geoJsonLayer) {
    var bounds = new L.LatLngBounds([]);
    geoJsonLayer.eachLayer(function (l) {
      if (l.getBounds) bounds.extend(l.getBounds());
      else if (l.getLatLng) bounds.extend(l.getLatLng());
    });
    if (bounds.isValid() && map.getZoom() < 9) {
      map.fitBounds(bounds, { padding: [40, 40], maxZoom: 9 });
    }
  }

  function makeAisLayer() {
    return L.geoJSON(null, {
      pointToLayer: function (feature, latlng) {
        return L.circleMarker(latlng, vesselStyle(feature));
      },
      onEachFeature: vesselPopup
    });
  }

  function makeTidesStationLayer() {
    return L.geoJSON(null, {
      pointToLayer: function (feature, latlng) {
        var type = feature.properties && feature.properties.type;
        var opts = {
          radius: type === 'reference' ? 6 : 4,
          fillColor: '#0ea5e9',
          color: '#075985',
          weight: 1,
          fillOpacity: 0.85
        };
        return L.circleMarker(latlng, opts);
      },
      onEachFeature: function (feature, layer) {
        var p = feature.properties || {};
        var html = '<div><b>' + (p.name || '') + '</b><br>' +
          (p.type ? Drupal.t('Type: @type', { '@type': p.type }) + '<br>' : '') +
          (p.country ? Drupal.t('Country: @country', { '@country': p.country }) : '') + '</div>';
        layer.bindPopup(html);
        layer.on('click', function (e) {
          L.DomEvent.stopPropagation(e.originalEvent);
        });
      }
    });
  }

  var WIND_STOPS = [
    [0, [255, 255, 255]], [1, [189, 226, 255]], [2, [65, 176, 255]],
    [3, [32, 151, 255]], [4, [13, 138, 255]], [5, [0, 163, 255]],
    [6, [0, 200, 239]], [7, [11, 226, 165]], [8, [74, 250, 70]],
    [9, [155, 255, 0]], [10, [216, 255, 0]], [11, [255, 230, 0]],
    [12, [255, 180, 0]], [13, [255, 131, 0]], [14, [255, 77, 26]],
    [15, [255, 46, 77]], [16, [226, 0, 121]], [20, [160, 0, 80]]
  ];

  function windColor(speed) {
    var stops = WIND_STOPS;
    if (speed <= stops[0][0]) return 'rgb(' + stops[0][1][0] + ',' + stops[0][1][1] + ',' + stops[0][1][2] + ')';
    for (var i = 1; i < stops.length; i++) {
      if (speed <= stops[i][0]) {
        var a = stops[i - 1], b = stops[i];
        var t = (speed - a[0]) / (b[0] - a[0]);
        var c = [0, 1, 2].map(function (k) {
          return Math.round(a[1][k] + (b[1][k] - a[1][k]) * t);
        });
        return 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')';
      }
    }
    var last = stops[stops.length - 1][1];
    return 'rgb(' + last[0] + ',' + last[1] + ',' + last[2] + ')';
  }

  var WindParticleLayer = L.Layer.extend({
    options: {
      sim: 9,
      cell: 3,
      spacing: 24,
      lineWidth: 1.4,
      fadeAlpha: 0.055,
      particlesPerCell: 1
    },

    initialize: function (cfg, data) {
      this.cfg = cfg || {};
      this.data = data;
      this._particles = [];
      this._running = false;
      this._raf = 0;
      this._fieldU = null;
      this._fieldV = null;
      this._fieldW = 0;
      this._fieldH = 0;
      this._lastTs = 0;
    },

    onAdd: function (map) {
      this._map = map;
      var dpr = window.devicePixelRatio || 1;
      var size = map.getSize();
      var pane = map.getPane('overlayPane');
      this._canvas = L.DomUtil.create('canvas', 'sailbuddy-wind-canvas', pane);
      this._ctx = this._canvas.getContext('2d');
      this._dpr = dpr;
      this._canvas.width = size.x * dpr;
      this._canvas.height = size.y * dpr;
      this._canvas.style.width = size.x + 'px';
      this._canvas.style.height = size.y + 'px';
      this._canvas.style.pointerEvents = 'none';

      map.on('moveend zoomend resize', this._rebuild, this);
      this._rebuild();
      this._running = true;
      this._loop();
    },

    onRemove: function (map) {
      map.off('moveend zoomend resize', this._rebuild, this);
      this._running = false;
      if (this._raf) {
        cancelAnimationFrame(this._raf);
        this._raf = 0;
      }
      if (this._canvas && this._canvas.parentNode) {
        this._canvas.parentNode.removeChild(this._canvas);
      }
      this._canvas = null;
    },

    _gridBounds: function () {
      var d = this.data;
      if (!d) return null;
      return {
        sw: L.latLng(d.lat0, d.lon0),
        ne: L.latLng(d.lat0 + d.rows * d.dlat, d.lon0 + d.cols * d.dlon)
      };
    },

    _gridScreenRect: function () {
      // Screen pixel bounds of the wind data grid (may be partially offscreen).
      var b = this._gridBounds();
      if (!b) return null;
      var sw = this._map.latLngToContainerPoint(b.sw);
      var ne = this._map.latLngToContainerPoint(b.ne);
      return {
        x: Math.min(sw.x, ne.x),
        y: Math.min(sw.y, ne.y),
        x2: Math.max(sw.x, ne.x),
        y2: Math.max(sw.y, ne.y)
      };
    },

    _gridCoverage: function (rect) {
      // Fraction of the viewport that actually contains wind data (0..1).
      var size = this._map.getSize();
      var v = Math.max(0, size.x) * Math.max(0, size.y);
      if (!v) return 0;
      var w = Math.max(0, Math.min(rect.x2, size.x) - Math.max(rect.x, 0));
      var h = Math.max(0, Math.min(rect.y2, size.y) - Math.max(rect.y, 0));
      return (w * h) / v;
    },

    _respawn: function (p, size) {
      var rect = this._gridScreenRect();
      var iw = rect.x2 - rect.x;
      var ih = rect.y2 - rect.y;
      if (iw > 1 && ih > 1 && this._lastCoverage >= 0.06) {
        p.x = rect.x + Math.random() * iw;
        p.y = rect.y + Math.random() * ih;
      }
      else {
        p.x = Math.random() * size.x;
        p.y = Math.random() * size.y;
      }
      p.px = p.x;
      p.py = p.y;
      p.age = Math.random() * 80;
    },

    _rebuild: function () {
      var map = this._map;
      if (!map || !this.data) return;
      var size = map.getSize();
      var cell = this.options.cell;
      var dpr = this._dpr;

      // Only animate when a meaningful part of the viewport has wind data.
      var rect = this._gridScreenRect();
      if (!rect) {
        this._visible = false;
        this._canvas.style.display = 'none';
        return;
      }
      this._lastCoverage = this._gridCoverage(rect);
      this._visible = this._lastCoverage >= 0.06;
      this._canvas.style.display = this._visible ? 'block' : 'none';
      if (!this._visible) return;

      this._canvas.width = size.x * dpr;
      this._canvas.height = size.y * dpr;
      this._canvas.style.width = size.x + 'px';
      this._canvas.style.height = size.y + 'px';

      var w = Math.max(1, Math.ceil(size.x / cell));
      var h = Math.max(1, Math.ceil(size.y / cell));
      this._fieldW = w;
      this._fieldH = h;
      var u = new Float32Array(w * h);
      var v = new Float32Array(w * h);
      var redraw = !this._fieldU;
      var out = [0, 0];
      for (var py = 0; py < h; py++) {
        for (var px = 0; px < w; px++) {
          var ll = map.containerPointToLatLng(L.point(px * cell, py * cell));
          this._sample(ll.lat, ll.lng, out);
          u[py * w + px] = out[0];
          v[py * w + px] = out[1];
        }
      }
      this._fieldU = u;
      this._fieldV = v;

      var spacing = this.options.spacing;
      var count = Math.max(200, Math.min(7000, (size.x / spacing) * (size.y / spacing) * this.options.particlesPerCell));
      var list = [];
      for (var i = 0; i < count; i++) {
        var p = { x: 0, y: 0, px: 0, py: 0, age: 0 };
        this._respawn(p, size);
        list.push(p);
      }
      this._particles = list;
      if (redraw) this._render(performance.now(), true);
    },

    _sample: function (lat, lng, out) {
      var d = this.data;
      if (!d) { out[0] = 0; out[1] = 0; return; }
      var fx = (lng - d.lon0) / d.dlon;
      var fy = (lat - d.lat0) / d.dlat;
      var x0 = Math.floor(fx), y0 = Math.floor(fy);
      var tx = fx - x0, ty = fy - y0;
      var cols = d.cols, rows = d.rows;
      if (x0 < 0 || y0 < 0 || x0 >= cols - 1 || y0 >= rows - 1) {
        out[0] = 0; out[1] = 0; return;
      }
      var U = d.u, V = d.v;
      var u00 = U[y0][x0], u10 = U[y0][x0 + 1], u01 = U[y0 + 1][x0], u11 = U[y0 + 1][x0 + 1];
      var v00 = V[y0][x0], v10 = V[y0][x0 + 1], v01 = V[y0 + 1][x0], v11 = V[y0 + 1][x0 + 1];
      var top = u00 * (1 - tx) + u10 * tx;
      var bot = u01 * (1 - tx) + u11 * tx;
      out[0] = (top * (1 - ty) + bot * ty);
      var vtop = v00 * (1 - tx) + v10 * tx;
      var vbot = v01 * (1 - tx) + v11 * tx;
      out[1] = (vtop * (1 - ty) + vbot * ty);
    },

    _fieldAt: function (x, y, out) {
      var cell = this.options.cell;
      var fx = x / cell;
      var fy = y / cell;
      var x0 = Math.floor(fx), y0 = Math.floor(fy);
      var tx = fx - x0, ty = fy - y0;
      var w = this._fieldW, h = this._fieldH;
      var U = this._fieldU, V = this._fieldV;
      if (x0 < 0 || y0 < 0 || x0 >= w - 1 || y0 >= h - 1) {
        out[0] = 0; out[1] = 0; return;
      }
      var u00 = U[y0 * w + x0], u10 = U[y0 * w + x0 + 1], u01 = U[(y0 + 1) * w + x0], u11 = U[(y0 + 1) * w + x0 + 1];
      var v00 = V[y0 * w + x0], v10 = V[y0 * w + x0 + 1], v01 = V[(y0 + 1) * w + x0], v11 = V[(y0 + 1) * w + x0 + 1];
      out[0] = (u00 * (1 - tx) + u10 * tx) * (1 - ty) + (u01 * (1 - tx) + u11 * tx) * ty;
      out[1] = (v00 * (1 - tx) + v10 * tx) * (1 - ty) + (v01 * (1 - tx) + v11 * tx) * ty;
    },

    _loop: function (ts) {
      if (!this._running) return;
      this._raf = requestAnimationFrame(this._loop.bind(this));
      if (!this._lastTs) this._lastTs = ts;
      var dt = Math.min(0.05, Math.max(0.008, (ts - this._lastTs) / 1000));
      this._lastTs = ts;
      this._render(ts, false, dt);
    },

    _render: function (ts, force, dt) {
      if (!this._visible) return;
      var size = this._map.getSize();
      var ctx = this._ctx;
      var dpr = this._dpr;
      if (!force) {
        ctx.globalCompositeOperation = 'destination-out';
        ctx.fillStyle = 'rgba(0,0,0,' + this.options.fadeAlpha + ')';
        ctx.fillRect(0, 0, this._canvas.width, this._canvas.height);
        ctx.globalCompositeOperation = 'lighter';
      }
      var sim = this.options.sim;
      var cell = this.options.cell;
      var w = this._fieldW;
      var vel = [0, 0];
      var maxS = this.cfg.wind_particles_maxspeed || 20;
      ctx.lineWidth = this.options.lineWidth;
      ctx.lineCap = 'round';
      var list = this._particles;
      var margin = 40;
      for (var i = 0; i < list.length; i++) {
        var p = list[i];
        this._fieldAt(p.x, p.y, vel);
        var u = vel[0], v = vel[1];
        var speed = Math.sqrt(u * u + v * v);
        var nx = p.x + u * sim * (dt || 0.016) * 6;
        var ny = p.y - v * sim * (dt || 0.016) * 6;
        p.age++;
        if (nx < -margin || ny < -margin || nx > size.x + margin || ny > size.y + margin || p.age > 300 || speed < 0.1) {
          this._respawn(p, size);
          continue;
        }
        p.px = p.x;
        p.py = p.y;
        p.x = nx;
        p.y = ny;
        // Draw a short "feather" in the wind direction; length grows with speed.
        var streak = Math.max(3, Math.min(18, speed * 1.6));
        var su = 0, sv = 0;
        if (speed > 0.1) { su = u / speed; sv = v / speed; }
        var ex = nx + su * streak;
        var ey = ny - sv * streak;
        var alpha = 0.35 + Math.min(0.6, speed / maxS * 0.65);
        ctx.strokeStyle = windColor(speed);
        ctx.globalAlpha = alpha;
        ctx.beginPath();
        ctx.moveTo(p.px * dpr, p.py * dpr);
        ctx.lineTo(ex * dpr, ey * dpr);
        ctx.stroke();
      }
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';
    }
  });

  function addWindLegend(map, layer, data) {
    var ctrl = L.control({ position: 'bottomleft' });
    ctrl.onAdd = function () {
      var div = L.DomUtil.create('div', 'sailbuddy-wind-legend');
      div.innerHTML =
        '<span class="sailbuddy-wind-legend-title">m/s</span>' +
        '<div class="sailbuddy-wind-legend-bar"></div>' +
        '<div class="sailbuddy-wind-legend-scale"><span>0</span><span>' + (Math.max(20, Math.round(layer.options.sim * 4))) + '</span></div>';
      return div;
    };
    ctrl.addTo(map);
    layer.on('remove', function () {
      if (ctrl._container) L.DomUtil.remove(ctrl._container);
    });
  }

  // Consolidate the map credits into a single concise attribution line.
  // Without this the layer menu shows a long chain gathered from the
  // maplibre style sources (OSM + Mapterhorn), the OpenWaters tileJSON
  // url refs (4x "Open Waters") and the Esri satellite layer.
  // The maplibre-gL wrapper binds its gather-function at layer-init, so a
  // late async addAttribution() can re-add the long chain regardless of how
  // often we clear the control. Installing a filter on addAttribution() is
  // the only robust way to keep exactly one credit line.
  function tidyMapAttribution(map) {
    var ac = map && map.attributionControl;
    if (!ac) { return; }
    var combined =
      '&copy; <a href="https://openwaters.io" rel="nofollow noopener">OpenWaters</a> Seamap &middot; ' +
      '&copy; <a href="https://www.openstreetmap.org/copyright" rel="nofollow noopener">OpenStreetMap</a> contributors &middot; ' +
      '<a href="https://mapterhorn.com/attribution" rel="nofollow noopener">&copy; Mapterhorn</a> &middot; ' +
      '&copy; Esri, Maxar, Earthstar Geographics &middot; ' +
      'Wind &copy; <a href="https://openweathermap.org" rel="nofollow noopener">OpenWeatherMap</a>';
    if (!ac._sailbuddyTidied) {
      ac.setPrefix(false);
      ac._attributions = {};
      var blockOthers = ac.addAttribution;
      ac.addAttribution = function (attribution) {
        if (typeof attribution === 'string' && attribution.indexOf('openwaters.io') === -1) {
          return undefined;
        }
        return blockOthers.call(this, attribution);
      };
      ac._sailbuddyTidied = true;
    }
    ac.addAttribution(combined);
    if (typeof ac._update === 'function') {
      ac._update();
    }
  }

  function attachOverlays(map, mapid, cfg) {
    var overlays = {};

    tidyMapAttribution(map);
    map.on('baselayerchange', function () { tidyMapAttribution(map); });
    window.setTimeout(function () { tidyMapAttribution(map); }, 2500);

    // --- AIS layer ---
    var aisLayer = null;
    var aisTimer = null;

    if (cfg.enable_ais) {
      aisLayer = makeAisLayer();
      overlays[Drupal.t('AIS vessels')] = aisLayer;

      var loadAis = function () {
        if (!aisLayer || !map.hasLayer(aisLayer)) return;
        var b = map.getBounds().pad(0.1);
        if (bboxDiagonalKm(b) > MAX_DIAGONAL_KM) {
          aisLayer.clearLayers();
          return;
        }
        var bbox = round(b.getSouth(), DEG) + ',' + round(b.getWest(), DEG) + ',' + round(b.getNorth(), DEG) + ',' + round(b.getEast(), DEG);
        fetch(cfg.ais_api + '?bbox=' + bbox, { cache: 'force-cache' })
          .then(function (r) {
            if (!r.ok) throw new Error('AIS HTTP ' + r.status);
            return r.json();
          })
          .then(function (data) {
            aisLayer.clearLayers();
            if (data && data.features && data.features.length) {
              aisLayer.addData(data.features);
            }
          })
          .catch(function () {});
      };

      map.on('moveend', throttle(loadAis, 800));
      loadAis();
    }

    // --- Tides station layer (overlay) ---
    var tidesLayer = null;
    var tidesTimer = null;

    if (cfg.enable_tides) {
      tidesLayer = makeTidesStationLayer();
      overlays[Drupal.t('Tides')] = tidesLayer;

      var loadTides = function () {
        if (!tidesLayer || !map.hasLayer(tidesLayer)) return;
        var b = map.getBounds().pad(0.1);
        var center = b.getCenter();
        var radius = Math.round(bboxDiagonalKm(b) / 2 / 50) * 50;
        fetch(cfg.tide_stations + '?latitude=' + center.lat + '&longitude=' + center.lng + '&radius=' + radius, { cache: 'force-cache' })
          .then(function (r) {
            if (!r.ok) throw new Error('Tides stations HTTP ' + r.status);
            return r.json();
          })
          .then(function (data) {
            tidesLayer.clearLayers();
            if (data && data.length) {
              tidesLayer.addData(data.map(function (s) {
                return {
                  type: 'Feature',
                  properties: {
                    name: s.name,
                    type: s.type,
                    country: s.country,
                    timezone: s.timezone,
                    id: s.id,
                    latitude: s.latitude,
                    longitude: s.longitude
                  },
                  geometry: {
                    type: 'Point',
                    coordinates: [s.longitude, s.latitude]
                  }
                };
              }));
            }
          })
          .catch(function () {});
      };

      map.on('moveend', throttle(loadTides, 1000));

      map.on('click', function (e) {
        if (!tidesLayer || !map.hasLayer(tidesLayer)) return;
        var best = null;
        var bestDist = Infinity;
        tidesLayer.eachLayer(function (l) {
          var d = l.getLatLng().distanceTo(e.latlng);
          if (d < bestDist) { bestDist = d; best = l; }
        });
        var target = best && bestDist <= 20000 ? best.getLatLng() : e.latlng;
        var lat = round(target.lat, 4);
        var lng = round(target.lng, 4);
        var units = UNIT_MAP[cfg.tides_units] || 'meters';
        fetch(cfg.tide_api + '?latitude=' + lat + '&longitude=' + lng + '&units=' + units)
          .then(function (r) {
            if (!r.ok) throw new Error('Tides HTTP ' + r.status);
            return r.json();
          })
          .then(function (data) {
            var extremes = (data && data.extremes) ? data.extremes.slice(0, 6) : [];
            if (!extremes.length) return;
            var unit = data.units || cfg.tides_units;
            L.popup()
              .setLatLng(target)
              .setContent(buildExtremesHtml(data.station, extremes, unit))
              .openOn(map);
          })
          .catch(function () {});
      });
    }

    // --- Wind overlay: particles (async data load) or legacy tiles ---
    var windLayer = null;
    if (cfg.wind_particles && cfg.wind_particles_url && typeof fetch === 'function') {
      var windGroup = L.layerGroup();
      var windLayerDk = new L.velocityLayer({
        displayValues: true,
        displayOptions: { velocityType: 'Global Wind (DK)', displayPosition: 'bottomleft',
          displayEmptyString: 'Vind: ', angleConvention: 'bearing', speedUnit: 'm/s' },
        data: null, minVelocity: 0.25, maxVelocity: 35,
        direction: 'uNf', particleAge: 95, lineWidth: 2,
        velocityScale: 0.005, particleMultiplier: 0.0035, frameRate: 15, maxParticles: 3500,
        particleTrailing: true, fade: true });
      var windLayerEu = new L.velocityLayer({
        displayValues: true,
        displayOptions: { velocityType: 'Global Wind (EU)', displayPosition: 'bottomright',
          displayEmptyString: 'Vind: ', angleConvention: 'bearing', speedUnit: 'm/s' },
        data: null, minVelocity: 0.25, maxVelocity: 35,
        direction: 'uNf', particleAge: 110, lineWidth: 1.5,
        velocityScale: 0.005, particleMultiplier: 0.0025, frameRate: 14, maxParticles: 2200,
        particleTrailing: true, fade: true });
      windGroup.addLayer(windLayerDk);
      windGroup.addLayer(windLayerEu);
      overlays[Drupal.t('Wind')] = windGroup;
      [[cfg.wind_particles_url, windLayerDk], [cfg.wind_particles_url_eu, windLayerEu]]
        .forEach(function (pair) { var url = pair[0], layer = pair[1];
          if (!url) return;
          fetch(url, { cache: 'no-cache' })
            .then(function (r) { return r.json(); })
            .then(function (data) { layer.setData(data); })
            .catch(function (e) { console.error('Wind fetch fejl', url, e); });
        });
      [windLayerDk, windLayerEu].forEach(function(layer, idx) {
        var url = idx === 0 ? cfg.wind_particles_url : cfg.wind_particles_url_eu;
        if (!url) return;
        fetch(url, { cache: 'no-cache' })
          .then(function (r) { return r.json(); })
          .then(function (data) { layer.setData(data); })
          .catch(function (e) { console.error('wind fetch fejl', url, e); });
      });
      fetch(cfg.wind_particles_url, { cache: 'no-cache' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.u || !data.v || !data.rows || !data.cols) return;
          windLayer.data = data;
          if (cfg.wind_particles_legend !== false) addWindLegend(map, windLayer, data);
          windLayer._rebuild();
        })
        .catch(function () {});
    }
    else if (cfg.wind_tiles) {
      windLayer = L.tileLayer(cfg.wind_tiles, {
        attribution: 'Wind &copy; <a href="https://openweathermap.org">OpenWeatherMap</a>',
        opacity: 0.85,
        maxZoom: 18
      });
      overlays[Drupal.t('Wind')] = windLayer;
    }

    // --- Mapillary photo overlay (street-level images near the harbour) ---
    var mlyLayer = null;
    var mlyTimers = [];
    var MLY_MAX_DIAGONAL_KM = 30;

    function mlyPopUp(feature) {
      var p = feature.properties || {};
      var date = p.date ? '<span class="sailbuddy-mly-date">' + p.date + '</span>' : '';
      var open = '<a class="sailbuddy-mly-open" href="https://www.mapillary.com/app/?pKey=' + p.id + '&focus=photo" target="_blank" rel="noopener">Mapillary ↗</a>';
      var img = p.thumb ? '<img src="' + p.thumb + '" alt="Mapillary foto" loading="lazy">' : '<div class="sailbuddy-mly-thumb">' + Drupal.t('Billede ikke tilgængeligt') + '</div>';
      return '<div class="sailbuddy-mly-popup" data-mlyid="' + p.id + '">' +
        '<div class="sailbuddy-mly-thumb">' + img + '</div>' +
        '<div class="sailbuddy-mly-meta">' + date + (date ? ' · ' : '') + open + '</div>' +
        '</div>';
    }

    function mlyOnEach(feature, layer) {
      if ((feature.properties && feature.properties.kind) !== 'photo') {
        return;
      }
      layer.bindPopup(mlyPopUp(feature));
    }

    function mlyStyle(feature) {
      if (feature.properties && feature.properties.kind === 'track') {
        return { color: '#d97706', weight: 2, opacity: 0.55, dashArray: '5 5', interactive: false };
      }
      return {};
    }

    function mlyPointToLayer(feature, latlng) {
      var pano = feature.properties && feature.properties.is_pano;
      return L.circleMarker(latlng, {
        radius: pano ? 6 : 4,
        fillColor: pano ? '#7c3aed' : '#f59e0b',
        color: '#ffffff',
        weight: 1.2,
        fillOpacity: 0.9
      });
    }

    if (cfg.mapillary && cfg.mapillary.enable !== false) {
      mlyLayer = L.geoJSON(null, {
        pointToLayer: mlyPointToLayer,
        style: mlyStyle,
        onEachFeature: mlyOnEach
      });
      overlays[Drupal.t('Mapillary fotos')] = mlyLayer;

      var loadMly = function () {
        if (!mlyLayer || !map.hasLayer(mlyLayer)) return;
        var b = map.getBounds().pad(0.1);
        if (bboxDiagonalKm(b) > MLY_MAX_DIAGONAL_KM) {
          mlyLayer.clearLayers();
          return;
        }
        var bbox = round(b.getWest(), 5) + ',' + round(b.getSouth(), 5) + ',' + round(b.getEast(), 5) + ',' + round(b.getNorth(), 5);
        fetch(cfg.mapillary.photos + '?bbox=' + bbox, { cache: 'no-cache' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) {
            mlyLayer.clearLayers();
            if (data && data.features && data.features.length) {
              mlyLayer.addData(data.features);
            }
          })
          .catch(function () {});
      };

      map.on('moveend', throttle(loadMly, 1200));
    }

    // --- Build the layer menu (collapsed) and default state ---
    var layerControl = null;
    if (Object.keys(overlays).length) {
      layerControl = L.control.layers(null, overlays, { collapsed: true, position: 'topright' });
      layerControl.addTo(map);
      // Expose the control object so other modules (cf. sailbuddy_poi) can add overlays to the same menu.
      map._sailbuddyLayerControl = layerControl;
    }

    /* All overlays start OFF — the user picks from the layer menu. */

    if (aisTimer) window.clearInterval(aisTimer);

    if (aisTimer) window.clearInterval(aisTimer);
    if (cfg.enable_ais && cfg.ais_refresh) {
      aisTimer = window.setInterval(loadAis, (cfg.ais_refresh || 30) * 1000);
    }

    map.on('overlayadd overlayremove', function () {
      if (map.hasLayer(aisLayer)) loadAis();
      if (map.hasLayer(tidesLayer)) loadTides();
      if (map.hasLayer(mlyLayer)) loadMly();
    });

    if (cfg.enable_fit) {
      L.DomEvent.on(map.getContainer(), 'load', function () {
        if (aisLayer && aisLayer.getLayers().length) {
          fitFeatureBounds(map, aisLayer);
        }
      });
    }
  }

  Drupal.behaviors.sailbuddyOverlays = {
    attach: function (context, settings) {
      var cfg = Object.assign({}, DEFAULTS, (settings && settings.sailbuddy_map_overlays) || {});
      if (!settings || !settings.leaflet) {
        return;
      }
      Object.keys(settings.leaflet).forEach(function (mapid) {
        var container = document.getElementById(mapid);
        if (!container) {
          return;
        }
        once('sailbuddy-overlays-' + mapid, container).forEach(function () {
          var tries = 0;
          var poll = window.setInterval(function () {
            var inst = Drupal.Leaflet && Drupal.Leaflet[mapid];
            if (inst && inst.lMap) {
              window.clearInterval(poll);
              attachOverlays(inst.lMap, mapid, cfg);
            }
            else if (++tries > 100) {
              window.clearInterval(poll);
            }
          }, 250);
        });
      });
    }
  };

Drupal.behaviors.sailbuddyInitialView = {
    attach: function (context, settings) {
      var cfg = Object.assign({}, DEFAULTS, (settings && settings.sailbuddy_map_overlays) || {});
      if (!settings || !settings.leaflet) {
        return;
      }
      Object.keys(settings.leaflet).forEach(function (mapid) {
        var container = document.getElementById(mapid);
        if (!container || container.dataset.sailbuddyInitialView) {
          return;
        }
        var tries = 0;
        var poll = window.setInterval(function () {
          var inst = Drupal.Leaflet && Drupal.Leaflet[mapid];
          if (inst && inst.lMap) {
            window.clearInterval(poll);
            container.dataset.sailbuddyInitialView = '1';
            var map = inst.lMap;
            // Only overview maps (coarse default zoom) are recentered on the
            // visitor's position; detail maps (harbours, anchorages) keep their
            // configured view.
            if (map.getZoom() >= 9) {
              return;
            }
            var zoomTo = Math.max(9, (cfg.initial_zoom || 11));
            var fallback = function () {
              if (map.getZoom() >= 9) {
                return;
              }
              map.setView(map.getCenter(), zoomTo);
            };
            if (typeof map.locate === 'function') {
              map.locate({ setView: false, enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 });
              map.once('locationfound', function (e) {
                map.setView(e.latlng, zoomTo);
              });
              map.once('locationerror', fallback);
            }
            else {
              fallback();
            }
          }
          else if (++tries > 100) {
            window.clearInterval(poll);
          }
        }, 250);
      });
    }
  };

})(Drupal, drupalSettings, once);
