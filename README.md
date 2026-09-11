# Sailbuddy OpenWaters

Drupal 11-integration af [OpenWaters](https://openwaters.io) søkort (seamap), AIS-skibstrafik og tidevandsprognoser.

## Indhold

- **`modules/sailbuddy_map/`** — Drupal-modulet "Sailbuddy Map".
  - OpenWaters Seamap som kortunderlag via Leaflet-modulets native vector-support (`hook_leaflet_map_info` → preset `openwaters_seamap`).
  - AIS-trafik-overlay (levende skibe, farvekodet efter fart, popup med MMSI/navn/fart/kurs).
  - Tidevandsprognose ved klik på kortet (nærmeste reference-station, næste 6 ekstremer).
  - Alternativ egen MapLibre-renderer (block, Views-style, fieldformatter).
  - Style-proxy der serverer OpenWaters-stilen sanitized for den bundledede MapLibre GL 5.18.

## Dokumentation

Se **[`modules/sailbuddy_map/README.md`](modules/sailbuddy_map/README.md)** for installation, konfiguration og brug.

## Licens

Se [LICENSE](LICENSE) (GPLv2+/BSD, se modul-README).