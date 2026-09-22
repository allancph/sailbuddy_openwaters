<?php

namespace Drupal\sailbuddy_map\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Sailbuddy Map formatter — OpenWaters MapLibre map for a geofield.
 *
 * @FieldFormatter(
 *   id = "sailbuddy_map",
 *   label = @Translation("Sailbuddy Map (OpenWaters)"),
 *   field_types = {"geofield", "geolocation"}
 * )
 */
class SailbuddyMapFormatter extends FormatterBase {

  public static function defaultSettings() {
    return [
      'height' => '400',
      'height_unit' => 'px',
      'zoom' => 13,
      'enable_seascape' => TRUE,
      'enable_tides' => TRUE,
      'tides_units' => 'm',
      'enable_ais' => TRUE,
      'ais_refresh' => 30,
    ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Map height'),
      '#default_value' => $this->getSetting('height'),
      '#min' => 100,
    ];
    $elements['height_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Map height unit'),
      '#options' => ['px' => 'px', 'vh' => 'vh'],
      '#default_value' => $this->getSetting('height_unit'),
    ];
    $elements['zoom'] = [
      '#type' => 'number',
      '#title' => $this->t('Initial zoom level'),
      '#default_value' => $this->getSetting('zoom'),
      '#min' => 1,
      '#max' => 22,
    ];
    $elements['openwaters'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenWaters data layers'),
      '#open' => TRUE,
    ];
    $elements['openwaters']['enable_seascape'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Seascape bathymetry overlay'),
      '#default_value' => $this->getSetting('enable_seascape'),
    ];
    $elements['openwaters']['enable_tides'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable tide predictions on map click'),
      '#default_value' => $this->getSetting('enable_tides'),
    ];
    $elements['openwaters']['tides_units'] = [
      '#type' => 'select',
      '#title' => $this->t('Tide units'),
      '#options' => ['m' => 'Metres', 'ft' => 'Feet', 'fm' => 'Fathoms'],
      '#default_value' => $this->getSetting('tides_units'),
    ];
    $elements['openwaters']['enable_ais'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable live vessel traffic (AIS)'),
      '#default_value' => $this->getSetting('enable_ais'),
    ];
    $elements['openwaters']['ais_refresh'] = [
      '#type' => 'number',
      '#title' => $this->t('AIS refresh interval (seconds)'),
      '#default_value' => $this->getSetting('ais_refresh'),
      '#min' => 5,
      '#max' => 600,
    ];

    return $elements;
  }

  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Map height: @height', ['@height' => $this->getSetting('height') . $this->getSetting('height_unit')]);
    $summary[] = $this->t('Zoom: @zoom', ['@zoom' => $this->getSetting('zoom')]);
    if ($this->getSetting('enable_tides')) {
      $summary[] = $this->t('Tides on click');
    }
    if ($this->getSetting('enable_ais')) {
      $summary[] = $this->t('Live AIS traffic');
    }
    return $summary;
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $cconf = \Drupal::config('sailbuddy_map.settings');
    $entity = $items->getEntity();

    $features = [];
    $points = $this->extractPoints($items->getValue());

    foreach ($points as $point) {
      if (empty($point['lat']) || empty($point['lon'])) {
        continue;
      }
      $features[] = [
        'lon' => (float) $point['lon'],
        'lat' => (float) $point['lat'],
        'title' => $entity->label(),
        'url' => $entity->toUrl()->toString(),
        'type' => $entity->bundle(),
      ];
    }

    $settings = [
      'height' => $this->getSetting('height') . $this->getSetting('height_unit'),
      'zoom' => $this->getSetting('zoom'),
      'enable_seascape' => $this->getSetting('enable_seascape'),
      'enable_tides' => $this->getSetting('enable_tides'),
      'tides_units' => $this->getSetting('tides_units'),
      'enable_ais' => $this->getSetting('enable_ais'),
      'ais_refresh' => $this->getSetting('ais_refresh'),
    ];

    $map_id = 'sailbuddy-map-' . md5($items->getEntity()->getEntityTypeId() . $items->getEntity()->id() . $this->fieldDefinition->getName());

    $build = [
      '#theme' => 'sailbuddy_map',
      '#map_id' => $map_id,
      '#height' => $settings['height'],
      '#attached' => [
        'library' => ['sailbuddy_map/sailbuddy_map.init'],
        'drupalSettings' => [
          'sailbuddy_map' => [
            $map_id => [
              'baseStyleUrl' => (string) $cconf->get('base_style_url'),
              'tileJsonUrl' => '',
              'tileUrl' => '',
              'features' => $features,
              'center' => [
                'lat' => $features[0]['lat'] ?? (float) $cconf->get('center_lat') ?? 55.6761,
                'lng' => $features[0]['lon'] ?? (float) $cconf->get('center_lng') ?? 12.5683,
              ],
              'zoom' => (int) $settings['zoom'],
              'mapId' => $map_id,
              'apiBase' => 'https://openwaters.io/api',
              'tideApi' => 'https://api.openwaters.io/tides',
              'aisApi' => 'https://ais.openwaters.io/v1/vessels',
              'seascapeBase' => 'https://tiles.openwaters.io/seascape',
              'enableSeascape' => (bool) $settings['enable_seascape'],
              'enableTides' => (bool) $settings['enable_tides'],
              'tidesUnits' => $settings['tides_units'],
              'enableAis' => (bool) $settings['enable_ais'],
              'aisRefresh' => (int) $settings['ais_refresh'],
              'fitbounds' => FALSE,
              'markerIconUrl' => (string) $cconf->get('marker_icon_url'),
              'attribution' => (string) $cconf->get('attribution'),
            ],
          ],
        ],
      ],
      '#cache' => [
        'tags' => $entity->getCacheTags(),
      ],
    ];

    return [$build];
  }

  /**
   * Extract lat/lon points from geofield item values.
   *
   * Robust across storage formats: uses pre-computed lat/lon when present,
   * otherwise parses the WKT 'value'.
   */
  protected function extractPoints(array $items): array {
    $points = [];
    foreach ($items as $item) {
      if (!empty($item['lat']) && !empty($item['lon'])) {
        $points[] = ['lat' => (float) $item['lat'], 'lon' => (float) $item['lon']];
        continue;
      }
      $wkt = $item['value'] ?? $item['wkt'] ?? NULL;
      if (is_string($wkt) && preg_match('/POINT\s*\(([-0-9.eE]+)\s+([-0-9.eE]+)\)/', $wkt, $m)) {
        $points[] = ['lon' => (float) $m[1], 'lat' => (float) $m[2]];
      }
    }
    return $points;
  }

}