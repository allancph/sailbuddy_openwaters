(function (Drupal, drupalSettings, once) {
  "use strict";

  var DEFAULTS = {
    zoom_min: 10,
    groupLabel: 'POI',
    auto_on_min_zoom: 13,
    providers: {}
  };
  var MAX_DIAGONAL_KM = 700;

  var TYPE_LABELS = {
    cluster: 'Samlet gruppe',
    marina: 'Havn / marina',
    anchorage: 'Ankerplads',
    hazard: 'Fare',
    fuel: 'Brændstof',
    toilets: 'Toilet',
    drinking_water: 'Drikkevand',
    recycling: 'Genbrug / affald',
    atm: 'Hæveautomat',
    pharmacy: 'Apotek',
    food: 'Mad & drikke',
    shop: 'Butik / købmand',
    chandlery: 'Sejlerforretning',
    boat_dealer: 'Bådforhandler',
    boatbuilder: 'Bådværft',
    shipbuilder: 'Skibsværft',
    sailmaker: 'Sejlmager',
    laundry: 'Vaskeri',
    post: 'Post',
    doctor: 'Læge',
    lock: 'Sluse',
    bridge: 'Bro',
    boat_ramp: 'Bådrampe',
    business: 'Forretning',
    knowledge: 'Lokal viden',
    tourist: 'Turistattraktion',
    viewpoint: 'Udsigtspunkt',
    rental: 'Cykel / éløbehjul',
    bike_repair: 'Cykelservice',
    accommodation: 'Overnatning',
    poi: 'POI'
  };

  var FALLBACK_COLORS = {
    marina: '#0d9488',
    anchorage: '#2563eb',
    hazard: '#dc2626',
    fuel: '#7c3aed',
    toilets: '#64748b',
    drinking_water: '#06b6d4',
    recycling: '#22c55e',
    atm: '#14b8a6',
    pharmacy: '#e11d48',
    food: '#ef4444',
    shop: '#84cc16',
    chandlery: '#ca8a04',
    boat_dealer: '#a16207',
    boatbuilder: '#92400e',
    shipbuilder: '#7f1d1d',
    sailmaker: '#a855f7',
    laundry: '#38bdf8',
    post: '#0f766e',
    doctor: '#b91c1c',
    knowledge: '#0891b2',
    boat_ramp: '#65a30d',
    lock: '#334155',
    bridge: '#92400e',
    business: '#ca8a04',
    tourist: '#a855f7',
    viewpoint: '#f59e0b',
    rental: '#8b5cf6',
    bike_repair: '#16a34a',
    accommodation: '#db2777',
    poi: '#475569'
  };

  function esc(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
      return {
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c];
    });
  }

  function escUrl(str) {
    var s = String(str == null ? '' : str).trim();
    return /^https?:\/\//i.test(s) ? esc(s) : '#';
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

  // Maps a normalized POI type to the legacy "Faciliteter" taxonomy term names
  // it corresponds to. Used to cross-reference live OSM amenities against the
  // harbour's hand-curated facility tags (SEO-valuable, kept untouched).
  var FACILITY_TERMS_BY_TYPE = {
    fuel: ['Benzin', 'Diesel'],
    toilets: ['Toilet'],
    drinking_water: ['Vand'],
    recycling: ['Miljøstation'],
    food: ['Restaurant'],
    shop: ['Supermarked'],
    chandlery: ['Sejlerforretning', 'Udstyr butik'],
    boat_dealer: ['Bådforhandler'],
    boatbuilder: ['Værksted', 'Bådværft'],
    shipbuilder: ['Skibsværft'],
    sailmaker: ['Sejlmager'],
    boat_ramp: ['Båd rampe', 'Bootsrampe', 'Boat ramp'],
    anchorage: ['Ankerplads'],
    laundry: ['Vaskeri'],
    rental: ['Cykel udlejning'],
    accommodation: ['Overnatning'],
    tourist: ['Turistattraktion'],
    viewpoint: ['Udsigtspunkt'],
    marina: ['Havn', 'Marina']
  };

  // Human label + term-name lookup per type for the "I nærheden" summary.
  function nearbyFacilityLabel(type) {
    var terms = FACILITY_TERMS_BY_TYPE[type];
    return terms && terms.length ? terms[0] : typeLabel(type);
  }

  // Merges the harbour's hand-curated "Faciliteter" tags (crawlable taxonomy
  // <a> links, kept intact for SEO) with live OSM POI data into ONE
  // self-updating list. Curated entries get a live count badge when POIs are
  // confirmed nearby; auto-only facility types are appended as chips that zoom
  // the map to their POIs. Re-runs on every map move (self-updating).
  var NEARBY_ID = 'sailbuddy-facility-live';

  function renderNearbyFacilities(typeCounts, featuresByType, map, poiGroup) {
    var host = document.querySelector('.field--name-field-faciliteter');
    if (!host) {
      return;
    }
    var container = host.querySelector('.field__items');
    if (!container) {
      return;
    }

    // Reset the previous live-render (badges + auto chips) — curated anchors stay.
    container.querySelectorAll('.' + NEARBY_ID + ', a.sailbuddy-facility-confirmed')
      .forEach(function (el) {
        if (el.getAttribute('data-poi-confirmed')) {
          el.removeAttribute('data-poi-confirmed');
          el.classList.remove('sailbuddy-facility-confirmed');
        }
        el.remove();
      });

    var keys = Object.keys(typeCounts).filter(function (t) {
      return FACILITY_TERMS_BY_TYPE[t];
    });
    if (!keys.length) {
      return;
    }
    // Auto-only facilities (present in nearby OSM, absent from manual tags)
    // are appended into the same list so it reads as one merged set.
    var extras = document.createElement('span');
    extras.className = NEARBY_ID + ' sailbuddy-facility-extras';
    container.appendChild(extras);

    keys.forEach(function (type) {
      var terms = FACILITY_TERMS_BY_TYPE[type];
      var count = typeCounts[type];
      var matchedAnchor = null;
      var manualTerms = {};
      container.querySelectorAll('a').forEach(function (a) {
        var txt = (a.textContent || '').trim();
        if (manualTerms[txt]) return;
        manualTerms[txt] = a;
      });
      terms.forEach(function (term) {
        var a = manualTerms[term];
        if (a && !matchedAnchor) matchedAnchor = a;
      });

      if (matchedAnchor) {
        // Curated entry confirmed by live data: add a live count badge.
        if (!matchedAnchor.getAttribute('data-poi-confirmed')) {
          matchedAnchor.setAttribute('data-poi-confirmed', '1');
          matchedAnchor.classList.add('sailbuddy-facility-confirmed');
        }
        var badge = document.createElement('span');
        badge.className = NEARBY_ID + ' sailbuddy-facility-badge';
        badge.textContent = count;
        badge.title = typeLabel(type) + ' i n\u00e6rheden (live OSM)';
        matchedAnchor.appendChild(badge);
      }
      else {
        // Auto-only facility: a chip that zooms the map to its POIs.
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = NEARBY_ID + ' sailbuddy-facility-chip-auto';
        chip.innerHTML = nearbyFacilityLabel(type) + ' <b>' + count + '</b>';
        chip.title = 'Findes i n\u00e6rheden (live OSM) \u2014 klik for at zoome til p\u00e5 kortet';
        chip.addEventListener('click', function () {
          zoomMapToType(map, poiGroup, featuresByType[type]);
        });
        extras.appendChild(chip);
      }
    });
  }

  // Centers/fits the map to the POIs of a clicked facility and ensures the POI
  // layer is visible.
  function zoomMapToType(map, poiGroup, features) {
    if (!map || !features || !features.length) {
      return;
    }
    if (poiGroup && !map.hasLayer(poiGroup)) {
      map.addLayer(poiGroup);
    }
    var bounds = L.latLngBounds([]);
    features.forEach(function (f) {
      var g = f.geometry;
      if (!g) return;
      if (g.type === 'Point') {
        bounds.extend(L.latLng(g.coordinates[1], g.coordinates[0]));
      }
      else if (g.type === 'MultiPoint') {
        g.coordinates.forEach(function (c) {
          bounds.extend(L.latLng(c[1], c[0]));
        });
      }
    });
    if (bounds.isValid()) {
      map.flyToBounds(bounds, { maxZoom: 16, padding: [40, 40] });
    }
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
    if (p.phone) {
      html += '<div class="sailbuddy-poi-row"><a href="tel:' + esc(String(p.phone).replace(/[^\d+]/g, '')) + '">' + esc(p.phone) + '</a></div>';
    }
    if (p.website) {
      html += '<div class="sailbuddy-poi-row"><a href="' + escUrl(p.website) + '" target="_blank" rel="noopener">' + esc(String(p.website).replace(/^https?:\/\//, '')) + '</a></div>';
    }
    if (p.opening_hours) {
      html += '<div class="sailbuddy-poi-row sailbuddy-poi-hours">' + esc(p.opening_hours) + '</div>';
    }
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
      // Harbour/detail maps are often zoomed far in, so the visible extent is
      // a few hundred metres and almost no amenities are tagged that close to
      // the marina. Fetch a wider area (min ~8 km diagonal) so POIs appear as
      // soon as the visitor pans a little.
      var minDiag = 8;
      if (bboxDiagonalKm(b) < minDiag) {
        var c = b.getCenter();
        var halfLat = (minDiag / 2) / 111.32;
        var halfLng = (minDiag / 2) / (111.32 * Math.cos(c.lat * Math.PI / 180));
        b = L.latLngBounds(
          [c.lat - halfLat, c.lng - halfLng],
          [c.lat + halfLat, c.lng + halfLng]
        );
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
            if (this.providerId === 'overpass') {
              var typeCounts = {};
              var featuresByType = {};
              data.features.forEach(function (feat) {
                var t = feat.properties && feat.properties.type;
                if (t) {
                  typeCounts[t] = (typeCounts[t] || 0) + 1;
                  (featuresByType[t] = featuresByType[t] || []).push(feat);
                }
              });
              renderNearbyFacilities(typeCounts, featuresByType, map, this._group);
            }
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
    // One combined "POI" menu entry holding every provider's layer — the
    // visitor does not care whether a point came from ActiveCaptain or OSM.
    var poiGroup = L.layerGroup([]);
    Object.keys(cfg.providers).forEach(function (providerId) {
      var def = cfg.providers[providerId];
      if (!def || !def.api) return;
      poiGroup.addLayer(new ActiveCaptainLayer(cfg, providerId));
    });
    addToLayerControl(map, poiGroup, cfg.groupLabel || 'POI');
    // Detail maps (harbours, anchorages — zoomed far in) turn the layer on
    // automatically: local POIs are exactly what a visitor zooms in for.
    // Overview maps stay clean (everything off) until the visitor toggles it.
    var autoOn = cfg.auto_on_min_zoom || 13;
    if (map.getZoom() >= autoOn && typeof map.addLayer === 'function') {
      window.setTimeout(function () {
        if (!map.hasLayer(poiGroup)) {
          map.addLayer(poiGroup);
        }
      }, 1200);
    }
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