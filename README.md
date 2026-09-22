# Sailbuddy POI

Point-of-Interest overlay layers for Sailbuddy maps. Ships the **Garmin
ActiveCaptain** provider behind a common provider-facade so more sources
(e.g. OpenStreetMap Overpass) can be added later as plugins.

## Features

- Backend route `/sailbuddy-poi/{provider}` returns normalized GeoJSON for a
  bounding box. Currently the default provider is `activecaptain`.
- POI icons are only fetched/shown when the map is zoomed in (default `zoom_min
  = 10`) — small icons appear as you get close to the coast/harbours.
- Aggregated Garmin "active cell" markers (several POIs in one spot) render as
  a small count badge; clicking suggests zooming in.
- Single POIs render with Garmin's icon and a popup with name + type.
- "Vis / rediger i ActiveCaptain" opens the Garmin WebView in an iframe modal.
  The API key is appended **server-side** via a redirect route, so it never
  ends up in the page DOM.

## Architecture

```
sailbuddy_poi
├── src/Plugin/PoiProvider/     # provider plugins
│   └── ActiveCaptainProvider   # Garmin bbox API v2 (+ webview)
├── src/Controller/PoiController.php  # GeoJSON + cache + webview redirect
├── src/Form/SailbuddyPoiSettingsForm.php
├── js/sailbuddy-poi.js         # Leaflet overlay + layer control + modal
└── css/sailbuddy_poi.css
```

- **Provider interface:** `Drupal::service('plugin.manager.sailbuddy_poi.provider')`
- **Config:** `sailbuddy_poi.settings` (admin: Configuration > System > Sailbuddy POI)
- **API key:** stored in the Key module (reference id `activecaptain` by default,
  selectable in the settings form). Garmin allows both a **staging** and a
  **production** key; toggle the environment in settings.

## Garmin ActiveCaptain API (v2)

- Bounding-box: `POST {stage|prod}/api/v2/points-of-interest/bbox`
  with header `apikey` and body `{north, south, west, east, zoomLevel, ...}`.
- WebView (view/edit): `https://activecaptain-stage.garmin.com/embed/webview/{PoiId}?apikey=...`
- Swagger: `https://marine.garmin.com/thirdparty-stage/swagger/index.html`

## Switching to production

1. In the Garmin developer portal, complete certification and request a
   production key.
2. Update the key value in the Key module.
3. Set environment to *Production* in the settings form.
4. Verify with the Antarctica test POI (544051) per Garmin's Go Live guide.

## Roadmap

- Overpass provider (OSM marinas, fuel, shops, ramps, ...) as a second plugin.
- Filter WebView (`/embed/webview/mapfilter`) for category filtering.