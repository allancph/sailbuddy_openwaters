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
    tide_api: 'https://api.openwaters.io/tides/extremes'
  };

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

  var MAX_DIAGONAL_KM = 1400;

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

  function attachOverlays(map, mapid, cfg) {
    var aisLayer = null;

    if (cfg.enable_ais) {
      aisLayer = L.geoJSON(null, {
        pointToLayer: function (feature, latlng) {
          return L.circleMarker(latlng, vesselStyle(feature));
        },
        onEachFeature: vesselPopup
      });
      aisLayer.addTo(map);

      var loadAis = function () {
        if (!aisLayer) return;
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
      window.setInterval(loadAis, (cfg.ais_refresh || 30) * 1000);
    }

    if (cfg.enable_tides) {
      map.on('click', function (e) {
        var lat = round(e.latlng.lat, 4);
        var lng = round(e.latlng.lng, 4);
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
              .setLatLng(e.latlng)
              .setContent(buildExtremesHtml(data.station, extremes, unit))
              .openOn(map);
          })
          .catch(function () {});
      });
    }

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