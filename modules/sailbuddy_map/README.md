# Sailbuddy Map

Drupal 11-modul der integrerer [OpenWaters](https://openwaters.io) søkort, AIS-skibstrafik og tidevandsprognoser i dine kort. Modulet understøtter to rendringsveje:

1. **Leaflet-modulet (anbefalet)** — bruger det klassiske `leaflet` contrib-moduls native vector-støtte (`L.maplibreGL`) til at tegne OpenWaters-seakortet, med AIS- og tidevands-overlays bundet ovenpå hvert Leaflet-kort. Ingen egen kort-bootstrap.
2. **Egen MapLibre-renderer** — block/views-style/fieldformatter + `sailbuddy_map`-settings bygger kortet direkte med MapLibre GL, med AIS/tides indbygget i `js/sailbuddy_map.js`.

## Features

- **OpenWaters Seamap** som kortunderlag (nautisk søkort + batymetri), serveret via egen style-proxy der sanitizerer for den bundledede MapLibre-version.
- **AIS-trafik**: levende skibe som farvede prikker (rød = under vejs, grøn = stævnet/stoppet), popup med navn, MMSI, type, fart (kn), kurs og sidst-set-tid. Opdateres ved panning og med jævne mellemrum (`ais_refresh`).
- **Tidevandsprognose**: klik på kortet → popup med nærmeste reference-station og de næste 6 ekstremer (høj-/lavvande med tidspunkt og niveau), i meter eller feet.
- **Marker clustering** når der er mange steder (via `leaflet_markercluster`).

## Installation

1. Installer den klassiske `leaflet`-modul + `leaflet_markercluster`, og eventuelt `leaflet_views` (for Views-display-stile).
2. Kopiér `sailbuddy_map` ind i `web/modules/custom/`, eller installér via Composer hvis pakket.
3. Aktivér: `drush en leaflet leaflet_markercluster sailbuddy_map` (eller Admin → Extend).
4. Konfigurér: Admin → Configuration → System → Sailbuddy Map (`/admin/config/system/sailbuddy-map`).

## Konfiguration

| Indstilling | Standard | Betydning |
|---|---|---|
| `enable_ais` | `true` | Slå AIS-skibsoverlay til/fra |
| `ais_refresh` | `30` | Hvor mange sekunder mellem AIS-opdateringer |
| `enable_tides` | `true` | Slå tidevands-popup til/fra |
| `tides_units` | `m` | Enhed for tidevand (`m`, `ft`, `fm`) |
| `base_style_url` | `https://tiles.openwaters.io/seamap/style.json` | Kildestyle til style-proxyen |
| `center_lat`, `center_lng`, `zoom` | `55.6761, 12.5683, 10` | Standardkortcenter/-zoom |
| `marker_icon_url` | `/sites/default/files/Ankerplads.png` | Ikon for anker-/havnmarkører |
| `cache_ttl` | `60` | Cachetid for hentede styles |

## Sådan bruges det med Leaflet-modulet (anbefalet)

Efter aktivering registreres et kort-preset `openwaters_seamap` via `hook_leaflet_map_info()`.
Kortet er tilgængeligt for alt, der bruger Leaflet-modulets map-lookup — Views-style
`leaflet_map` (fra `leaflet_views`), fieldformatter `Leaflet` (standard), eller
`leaflet_map_get_info('openwaters_seamap')` i egen kode.

Eksempel – fieldformatter på et geolocation-felt:

1. Feltindstillinger → Formatter: **Leaflet** → Map definition: **OpenWaters Seamap (nautical chart + bathymetry)**.
2. Vælg et ikon (fx "Havn.png") under Marker settings.

Eksempel – Views-display (`leaflet_map`):

1. Display → Format → **Leaflet map**.
2. Sæt View style → Map preset til **OpenWaters Seamap**, og slå Markercluster til.
3. Stien `/map` på et sejler-site med 5 ankerpladser giver fx 5 cluster-noder og fuldt kort.

### AIS + tidevand ovenpå Leaflet-kort

Overlays aktiveres automatisk på **alle** Leaflet-kort når `enable_ais`/`enable_tides` er slået til.
`hook_preprocess_leaflet_map()` i `sailbuddy_map.module` injecter `sailbuddy_map.overlays`-biblioteket
og drupalSettings (`sailbuddy_map_overlays`) på hvert kort; det nye behavior
`sailbuddyOverlays` i `js/sailbuddy-map-overlays.js` bindes til `Drupal.Leaflet[mapid].lMap`
(instansen som `leaflet.drupal.js` eksponerer) og tilføjer AIS-geojson-lag + tides-popup.

## Sådan bruges blokken (egen renderer)

Desuden leverer modulet en block, en views-style (`Sailbuddy Map`) og en fieldformatter
(`sailbuddy_map`) der bygger kortet direkte med MapLibre GL:

- **Block**: Admin → Structure → Block layout → Place "Sailbuddy Map".
- **Views-style**: Format → **Sailbuddy Map (MapLibre)** (udyldet i ældre `sailbuddy_map.js`).
- **Fieldformatter**: Vælg **Sailbuddy Map** som formatter for et geolocation-felt (rendrer via MapLibre).

## Style-proxy og sanitization

Søkortets style serveres lokalt via `GET /sailbuddy-map/style/{chart}` (std. `seamap`)
af `SailbuddyMapStyleController`. Proxyen henter den originale OpenWaters-style og:

- fjerner `paint.resampling` (findes ikke i den medfølgende MapLibre 5.18.0),
- erstatter tomme `format`-options-arrays med tomme objekter (krævet af MapLibre),
- gemmer resultatet i `cache.data` i `cache_ttl` sekunder.

Resultatet kan bruges som basiskort i ethvert MapLibre-/Leaflet-`maplibreGL`-setup uden at
falde over versions-brud m.m.

## API'er der anvendes (gratis, ingen nøgle)

| Tjeneste | Endpoint | Noter |
|---|---|---|
| Kort | `https://tiles.openwaters.io/{chart}/style.json` | Seamap / seascape |
| AIS | `https://ais.openwaters.io/v1/vessels?bbox=…` | GeoJSON; **bbox-max-diagonal ~1400 km** — større views returnerer 400 ("bbox not allowed for this key") |
| Tidevand | `https://api.openwaters.io/tides/extremes?latitude&longitude&units=meters` | `units` skal være `meters`/`feet` (modulet oversætter `m/ft/fm`) |

## Bemærkninger / kendte ting

- **Caching**: Styles caches i `cache.data`. Efter ændring af `base_style_url` skal cache tømmes: `drush cr`.
- **CORS**: OpenWaters har `Access-Control-Allow-Origin: *`, så vectorkort kan tegnes frit fra enhver origin.
- **Datas risiko**: AIS-data er fra AISHub og kan have hul i dækningen; `seen`-feltet i popup viser alderen.
- **Lokaltid**: Tidevands-tidspunkter vises pt. som UTC i popup'en.

## Kompatibilitet

- Drupal 11 (`core_version_requirement: ^11`), PHP 8.1+.
- Kræver `leaflet` (≥4.x) for den anbefalede rendringsvej; `leaflet_markercluster` og `leaflet_views` valgfrit men anbefalet.
- Bundlet MapLibre GL 5.18.0 + `leaflet-maplibre-gl` (fra `leaflet`-modulet).

## Licens

Se `LICENSE` (Standard Drupal (GPLv2+)).