(function (Drupal, drupalSettings) {
  Drupal.behaviors.sailbuddyMap = {
    attach: function (context) {
      if (!drupalSettings.sailbuddy_map) {
        return;
      }

      Object.keys(drupalSettings.sailbuddy_map).forEach(function (mapKey) {
        var cfg = drupalSettings.sailbuddy_map[mapKey];
        var el = document.getElementById(cfg.mapId);
        if (!el || el.dataset.sailbuddyInitialized) {
          return;
        }
        el.dataset.sailbuddyInitialized = '1';

        var center = [cfg.center.lat || 0, cfg.center.lng || 0];
        var zoom = cfg.zoom || 2;
        var tileJsonUrl = cfg.tileJsonUrl || '';
        var tileUrl = cfg.tileUrl || '';
        var attribution = cfg.attribution || '';

        // Helper: init Leaflet with XYZ tile
        function initLeaflet(mapId, tile) {
          try {
            var mapL = L.map(mapId).setView(center, zoom);
            L.tileLayer(tile, { attribution: attribution, maxZoom: 22 }).addTo(mapL);
          } catch (e) {
            console.error('Leaflet init error', e);
          }
        }

        // Helper: build a minimal MapLibre style from TileJSON (vector) or raster tiles
        function buildMaplibreFromTileJSON(tilejson) {
          var style = { version: 8, sources: {}, layers: [] };
          if (tilejson.tiles && tilejson.tiles.length) {
            var tiles = tilejson.tiles;
            var isVector = tiles[0].indexOf('.mvt') !== -1 || (tilejson.format && tilejson.format === 'pbf');
            if (isVector) {
              style.sources['ow'] = { type: 'vector', tiles: tiles }; 
              // if vector_layers present, create simple line/fill layers for each
              if (tilejson.vector_layers && Array.isArray(tilejson.vector_layers)) {
                tilejson.vector_layers.forEach(function (vl, idx) {
                  // create a simple line layer
                  style.layers.push({
                    id: 'ow-' + (vl.id || vl.name || idx),
                    type: 'line',
                    source: 'ow',
                    'source-layer': vl.id || vl.name,
                    paint: { 'line-color': '#0066cc', 'line-width': 1 }
                  });
                });
              } else {
                // unknown source-layer: client will need to enable layers manually
              }
            } else {
              // raster tiles
              style.sources['ow'] = { type: 'raster', tiles: tiles, tileSize: 256 };
              style.layers.push({ id: 'ow-raster', type: 'raster', source: 'ow' });
            }
          }
          return style;
        }

        // If TileJSON provided, fetch it and try MapLibre first
        if (tileJsonUrl) {
          fetch(tileJsonUrl).then(function (r) {
            if (!r.ok) { throw new Error('TileJSON fetch failed'); }
            return r.json();
          }).then(function (tj) {
            // Build style
            var style = buildMaplibreFromTileJSON(tj);
            var isVector = false;
            if (tj.tiles && tj.tiles.length) {
              isVector = tj.tiles[0].indexOf('.mvt') !== -1 || (tj.format && tj.format === 'pbf');
            }
            try {
              // if vector or raster, initialize MapLibre with generated style
              var map = new maplibregl.Map({
                container: cfg.mapId,
                style: style,
                center: [center[1], center[0]], // maplibre uses [lng, lat]
                zoom: zoom
              });

              // add default controls
              map.addControl(new maplibregl.NavigationControl());

              // if vector layers exist but no source-layer mapping, show a console hint
              if (isVector && (!tj.vector_layers || !tj.vector_layers.length)) {
                console.warn('TileJSON appears to be vector tiles but vector_layers metadata is missing. Layers may not render until you provide "source-layer" names.');
              }

            } catch (e) {
              console.error('MapLibre init error, falling back to Leaflet if possible', e);
              // if fallback: if tilejson has raster tiles, use Leaflet
              if (tj.tiles && tj.tiles.length && tj.tiles[0].match(/\.png|\.jpg|\.jpeg/)) {
                initLeaflet(cfg.mapId, tj.tiles[0]);
              }
            }
          }).catch(function (err) {
            console.warn('TileJSON fetch failed, falling back to XYZ tileUrl if present', err);
            if (tileUrl) {
              initLeaflet(cfg.mapId, tileUrl);
            }
          });
        } else if (tileUrl) {
          // no TileJSON: assume XYZ raster
          initLeaflet(cfg.mapId, tileUrl);
        } else {
          // attempt auto-discovery from OpenWaters API: /api/datasets (not guaranteed)
          var apiBase = cfg.apiBase || 'https://openwaters.io/api';
          fetch(apiBase + '/datasets').then(function (r) { if (!r.ok) { throw new Error('datasets fetch failed'); } return r.json(); }).then(function (datasets) {
            // Try to pick a sensible tiles endpoint for bathymetry or first dataset with tiles
            if (Array.isArray(datasets) && datasets.length) {
              var chosen = datasets.find(function (d) { return d.name && d.tiles; }) || datasets[0];
              if (chosen && chosen.tiles) {
                // chosen.tiles may be a TileJSON url or tiles array
                if (typeof chosen.tiles === 'string') {
                  // if TileJSON url
                  fetch(chosen.tiles).then(function (r) { return r.json(); }).then(function (tj) {
                    var style = buildMaplibreFromTileJSON(tj);
                    try {
                      var map2 = new maplibregl.Map({ container: cfg.mapId, style: style, center: [center[1], center[0]], zoom: zoom });
                      map2.addControl(new maplibregl.NavigationControl());
                    } catch (e) {
                      console.error(e);
                    }
                  });
                }
              }
            }
          }).catch(function (e) {
            console.warn('No tiles configured and dataset discovery failed', e);
          });
        }

      });
    }
  };
})(Drupal, drupalSettings);
