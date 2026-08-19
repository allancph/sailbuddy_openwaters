# Sailbuddy Map module

This Drupal 11 module integrates OpenWaters datasets into MapLibre (vector) with a Leaflet fallback for raster XYZ tiles. It auto-discovers datasets from https://openwaters.io/api/ and exposes a configurable block to add layers to your site.

Installation
1. Copy `modules/sailbuddy_map` into your Drupal site's `modules/` directory, or install via Composer if packaged.
2. Enable the module: `drush en sailbuddy_map` or via Admin → Extend.
3. Configure defaults: Admin → Configuration → System → Sailbuddy Map. Optionally set a TileJSON URL or default XYZ tile URL.
4. Place the "Sailbuddy Map" block in Block Layout and configure per-block overrides.

Notes
- MapLibre is primary for vector tiles. If TileJSON contains vector_layers metadata, the module will create simple line layers for each vector layer.
- No API key is required by default; TileJSON/tiles are fetched anonymously from openwaters.io.
- Views GeoJSON and advanced Views style integration are planned in future updates.

