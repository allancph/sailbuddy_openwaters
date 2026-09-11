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
    wind_tiles: null
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
    if (p.mmsi) rows.push('MMSI: ' + p.mmsi);
    if (p.type) rows.push('Type: ' + p.type);
    if (typeof p.sog === 'number') rows.push('Fart: ' + p.sog.toFixed(1) + ' kn');
    if (typeof p.cog === 'number') rows.push('Kurs: ' + p.cog.toFixed(0) + '\u00B0');
    if (p.seen) rows.push('Sidst set: ' + p.seen);
    layer.bindPopup(rows.join('<br>'));
  }

  function buildExtremesHtml(station, extremes, unit) {
    var head = '<div><b>Tidevand \u2014 ' + (station && station.name ? station.name : '') + '</b><br>';
    var rows = extremes.map(function (e) {
      var t = new Date(e.time);
      var hh = ('0' + t.getUTCHours()).slice(-2);
      var mm = ('0' + t.getUTCMinutes()).slice(-2);
      var dd = t.getUTCDate();
      var mo = t.getUTCMonth() + 1;
      var label = e.high ? '\u25B3 h\u00F8jvande' : (e.low ? '\u25BD lavvande' : '');
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
          (p.type ? 'Type: ' + p.type + '<br>' : '') +
          (p.country ? 'Land: ' + p.country : '') + '</div>';
        layer.bindPopup(html);
        layer.on('click', function (e) {
          L.DomEvent.stopPropagation(e.originalEvent);
        });
      }
    });
  }

  function attachOverlays(map, mapid, cfg) {
    var overlays = {};

    // --- AIS layer ---
    var aisLayer = null;
    var aisTimer = null;

    if (cfg.enable_ais) {
      aisLayer = makeAisLayer();
      overlays['AIS-skibe'] = aisLayer;

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
      overlays['Tidevand'] = tidesLayer;

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

      // Keep the click-popup predictions, but only for stations we know about.
      map.on('click', function (e) {
        var best = null;
        var bestDist = Infinity;
        if (tidesLayer && map.hasLayer(tidesLayer)) {
          tidesLayer.eachLayer(function (l) {
            var d = l.getLatLng().distanceTo(e.latlng);
            if (d < bestDist) { bestDist = d; best = l; }
          });
        }
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

    // --- Wind overlay (reuses existing openweathermap wind tiles) ---
    var windLayer = null;
    if (cfg.wind_tiles) {
      windLayer = L.tileLayer(cfg.wind_tiles, {
        attribution: 'Vind &copy; <a href="https://openweathermap.org">OpenWeatherMap</a>',
        opacity: 0.85,
        maxZoom: 18
      });
      overlays['Vind'] = windLayer;
    }

    // --- Build the layer menu (collapsed) and default state ---
    var layerControl = null;
    if (Object.keys(overlays).length) {
      layerControl = L.control.layers(null, overlays, { collapsed: true, position: 'topright' });
      layerControl.addTo(map);
    }

    // Only start refresh loops for layers that are actually visible.
    var startLoops = function () { loadAis(); loadTides(); };
    if (aisLayer) aisLayer.addTo(map);
    if (tidesLayer) tidesLayer.addTo(map);

    if (aisTimer) window.clearInterval(aisTimer);
    if (cfg.enable_ais && cfg.ais_refresh) {
      aisTimer = window.setInterval(loadAis, (cfg.ais_refresh || 30) * 1000);
    }

    map.on('overlayadd overlayremove', function () {
      if (map.hasLayer(aisLayer)) loadAis();
      if (map.hasLayer(tidesLayer)) loadTides();
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

})(Drupal, drupalSettings, once);