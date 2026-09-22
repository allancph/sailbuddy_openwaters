(function (Drupal, drupalSettings, once) {
  "use strict";

  var DEFAULTS = {
    zoom_min: 10,
    providers: {}
  };
  var MAX_DIAGONAL_KM = 700;

  var TYPE_LABELS = {
    cluster: 'Samlet gruppe',
    marina: 'Havn / marina',
    anchorage: 'Ankerplads',
    hazard: 'Fare',
    fuel: 'Brændstof',
    lock: 'Sluse',
    bridge: 'Bro',
    boat_ramp: 'Bådrampe',
    business: 'Forretning',
    knowledge: 'Lokal viden',
    drinking_water: 'Drikkevand',
    charger: 'El-opladning',
    poi: 'POI'
  };

  var FALLBACK_COLORS = {
    marina: '#0d9488',
    anchorage: '#2563eb',
    hazard: '#dc2626',
    fuel: '#7c3aed',
    knowledge: '#0891b2',
    boat_ramp: '#65a30d',
    lock: '#334155',
    bridge: '#92400e',
    business: '#ca8a04',
    drinking_water: '#06b6d4',
    charger: '#eab308',
    poi: '#475569'
  };

  function esc(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
      return {
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c];
    });
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
    var dLat = b.getNorth() - b.getSouth();
    var midLat = b.getCenter().lat * Math.PI / 180;
    var kmLat = dLat * 111.32;
    var kmLng = (b.getEast() - b.getWest()) * 111.32 * Math.cos(midLat);
    return Math.sqrt(kmLat * kmLat + kmLng * kmLng);
  }

  function typeLabel(type) {
    return TYPE_LABELS[type] || TYPE_LABELS.poi;
  }

  function poiMarker(feature, latlng) {
    var p = feature.properties || {};
    var isCluster = !!(p.count && p.count > 1 && !p.webview);
    if (isCluster) {
      var node = document.createElement('div');
      node.className = 'sailbuddy-poi-cluster';
      node.title = p.count + " sejler-POI'er";
      node.appendChild(document.createTextNode(String(p.count)));
      return L.marker(latlng, {
        icon: L.divIcon({
          className: '',
          html: node,
          iconSize: [22, 22],
          iconAnchor: [11, 11]
        })
      });
    }
    var size = 24;
    if (p.icon) {
      return L.marker(latlng, {
        icon: L.icon({
          className: 'sailbuddy-poi-marker',
          iconUrl: p.icon,
          iconSize: [size, size],
          iconAnchor: [size / 2, size / 2],
          popupAnchor: [0, -size / 2]
        }),
        title: p.name || typeLabel(p.type)
      });
    }
    return L.circleMarker(latlng, {
      radius: 6,
      fillColor: FALLBACK_COLORS[p.type] || FALLBACK_COLORS.poi,
      color: '#ffffff',
      weight: 1.5,
      fillOpacity: 0.9
    });
  }

  function poiPopup(feature, layer, cfg, providerId) {
    var p = feature.properties || {};
    var html = '<div class="sailbuddy-poi-popup">';
    html += '<h3>' + esc(p.name || typeLabel(p.type)) + '</h3>';
    html += '<div class="sailbuddy-poi-type">' + esc(typeLabel(p.type)) + '</div>';
    if (p.count && p.count > 1) {
      html += '<div class="sailbuddy-poi-count">' + esc(String(p.count)) + ' ' + esc(Drupal.t('POI i området')) + '</div>';
    }

    else if (p.type === 'cluster') {
      html += '<div class="sailbuddy-poi-nozoom">' + esc(Drupal.t('Zoom ind for at se de enkelte POI\'er')) + '</div>';
    }
    html += '</div>';

    var content = document.createElement('div');
    content.className = 'sailbuddy-poi-popup-wrap';
    content.innerHTML = html;
    layer.bindPopup(content);
  }

  var ActiveCaptainLayer = L.Layer.extend({
    initialize: function (cfg, providerId) {
      this.cfg = cfg;
      this.providerId = providerId;
      this._group = null;
      this._abort = null;
      this._map = null;
      this._refresh = throttle(this.refresh.bind(this), 400);
    },

    onAdd: function (map) {
      this._map = map;
      this._group = L.geoJSON(null, {
        pointToLayer: poiMarker,
        onEachFeature: function (feature, layer) {
          poiPopup(feature, layer, this.cfg, this.providerId);
        }.bind(this)
      });
      this._group.addTo(map);
      map.on('moveend zoomend', this._refresh, this);
      this.refresh();
    },

    onRemove: function (map) {
      map.off('moveend zoomend', this._refresh, this);
      this._abortNow();
      if (this._group) {
        this._group.clearLayers();
      }
      this._map = null;
    },

    _abortNow: function () {
      if (this._abort) {
        this._abort.abort();
        this._abort = null;
      }
    },

    refresh: function () {
      if (!this._map || !this._group) {
        return;
      }
      var map = this._map;
      var zoom = map.getZoom();
      if (zoom < this.cfg.zoom_min) {
        this._group.clearLayers();
        return;
      }
      var first = this.cfg.providers[this.providerId];
      if (!first || !first.api) {
        return;
      }
      var b = map.getBounds();
      if (bboxDiagonalKm(b) > MAX_DIAGONAL_KM) {
        this._group.clearLayers();
        return;
      }

      var q = '?north=' + b.getNorth().toFixed(3) +
        '&south=' + b.getSouth().toFixed(3) +
        '&east=' + b.getEast().toFixed(3) +
        '&west=' + b.getWest().toFixed(3) +
        '&zoom=' + zoom;
      var url = first.api + q;

      this._abortNow();
      var ac = new AbortController();
      this._abort = ac;

      fetch(url, { signal: ac.signal, headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (!r.ok) throw new Error('POI HTTP ' + r.status);
          return r.json();
        })
        .then(function (data) {
          if (ac.signal.aborted) return;
          if (!this._group) return;
          this._group.clearLayers();
          if (data && data.features && data.features.length) {
            this._group.addData(data.features);
          }
        }.bind(this))
        .catch(function () {});
    }
  });

  function findLayerControl(map) {
    if (map._sailbuddyLayerControl && map._sailbuddyLayerControl instanceof L.Control.Layers) {
      return map._sailbuddyLayerControl;
    }
    var found = null;
    var lists = map._controls || {};
    for (var key in lists) {
      var arr = lists[key];
      if (!arr || !arr.length) continue;
      for (var i = 0; i < arr.length; i++) {
        if (arr[i] instanceof L.Control.Layers) {
          found = arr[i];
          break;
        }
      }
      if (found) break;
    }
    return found;
  }

  function addToLayerControl(map, layer, label) {
    var existing = findLayerControl(map);
    if (existing) {
      existing.addOverlay(layer, label);
      return;
    }
    var control = L.control.layers(null, {}, { position: 'topright', collapsed: true });
    control.addOverlay(layer, label);
    control.addTo(map);
    // The site's own layer menu (sailbuddy_map) may appear a moment later;
    // retry a few times to merge "ActiveCaptain" into it so only one menu exists.
    var attempts = 0;
    var merge = function () {
      var later = findLayerControl(map);
      if (later && later !== control) {
        map.removeControl(control);
        later.addOverlay(layer, label);
        return;
      }
      if (++attempts < 4) {
        window.setTimeout(merge, 2000);
      }
    };
    window.setTimeout(merge, 2000);
  }

  function attachProviders(map, cfg) {
    Object.keys(cfg.providers).forEach(function (providerId) {
      var def = cfg.providers[providerId];
      if (!def || !def.api) return;
      var layer = new ActiveCaptainLayer(cfg, providerId);
      addToLayerControl(map, layer, def.label || providerId);
      // POI layers start OFF — the visitor turns them on in the layer menu.
      // Nothing renders until zoomed in close regardless.
    });
  }

  Drupal.behaviors.sailbuddyPoi = {
    attach: function (context, settings) {
      var cfg = Object.assign({}, DEFAULTS, (settings && settings.sailbuddy_poi) || {});
      if (!settings || !settings.leaflet) {
        return;
      }
      Object.keys(settings.leaflet).forEach(function (mapid) {
        var container = document.getElementById(mapid);
        if (!container) return;
        once('sailbuddy-poi-' + mapid, container).forEach(function () {
          var tries = 0;
          var poll = window.setInterval(function () {
            var inst = Drupal.Leaflet && Drupal.Leaflet[mapid];
            if (inst && inst.lMap) {
              window.clearInterval(poll);
              try {
                attachProviders(inst.lMap, cfg);
              }
              catch (err) {
                if (window.console && console.warn) {
                  console.warn('sailbuddy_poi: attach fejlede for ' + mapid, err);
                }
              }
            }
            else if (++tries > 120) {
              window.clearInterval(poll);
            }
          }, 250);
        });
      });
    }
  };

})(Drupal, drupalSettings, once);