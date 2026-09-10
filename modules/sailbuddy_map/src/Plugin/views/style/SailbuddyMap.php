<?php

namespace Drupal\sailbuddy_map\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Sailbuddy Map style plugin — renders a MapLibre GL map (OpenWaters).
 *
 * @ViewsStyle(
 *   id = "sailbuddy_map",
 *   title = @Translation("Sailbuddy Map (MapLibre / OpenWaters)"),
 *   help = @Translation("Displays results on an OpenWaters nautical map (MapLibre GL) with markers, live AIS traffic and tide predictions."),
 *   theme = "views_view_list",
 *   display_types = {"normal"},
 * )
 */
class SailbuddyMap extends StylePluginBase {

  protected $usesOptions = TRUE;

  protected $usesGrouping = FALSE;

  public function defineOptions() {
    $options = parent::defineOptions();
    $options['data_source'] = ['default' => ''];
    $options['base_style_url'] = ['default' => ''];
    $options['height'] = ['default' => '80'];
    $options['height_unit'] = ['default' => 'vh'];
    $options['fitbounds'] = ['default' => TRUE];
    $options['geolocate'] = ['default' => FALSE];
    $options['enable_seascape'] = ['default' => TRUE];
    $options['enable_tides'] = ['default' => TRUE];
    $options['tides_units'] = ['default' => 'm'];
    $options['enable_ais'] = ['default' => TRUE];
    $options['ais_refresh'] = ['default' => 30];
    return $options;
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $options = $this->displayHandler->getFieldLabels();
    $geofields = [];
    foreach ($options as $field_id => $label) {
      $field = $this->view->getHandler($this->options['grouping']['field'] ?? '', $field_id) ?? NULL;
      if (!empty($this->displayHandler->getHandlers('field')[$field_id])) {
        $field_handler = $this->displayHandler->getHandlers('field')[$field_id];
        if ($field_handler instanceof \Drupal\views\Plugin\views\field\EntityField
            && in_array($field_handler->getFieldDefinition()?->getType(), ['geofield', 'geolocation'])) {
          $geofields[$field_id] = $label;
        }
      }
    }
    if (empty($geofields)) {
      foreach ($options as $field_id => $label) {
        $field_handler = $this->displayHandler->getHandlers('field')[$field_id];
        if ($field_handler && strpos($field_id, 'field_geolocation') !== FALSE) {
          $geofields[$field_id] = $label;
          break;
        }
      }
    }

    $form['data_source'] = [
      '#type' => 'select',
      '#title' => $this->t('Data source (geolocation field)'),
      '#description' => $this->t('The field containing the coordinates to place on the map.'),
      '#options' => $geofields,
      '#default_value' => $this->options['data_source'],
      '#required' => TRUE,
    ];

    $cconf = \Drupal::config('sailbuddy_map.settings');

    $form['base_style_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base MapLibre style URL (override)'),
      '#description' => $this->t('Leave empty to use site default. Default: https://tiles.openwaters.io/seamap/style.json'),
      '#default_value' => $this->options['base_style_url'],
    ];

    $form['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Map height'),
      '#default_value' => $this->options['height'],
      '#min' => 100,
    ];
    $form['height_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Map height unit'),
      '#options' => [
        'px' => $this->t('px'),
        'vh' => $this->t('vh'),
      ],
      '#default_value' => $this->options['height_unit'],
    ];

    $form['fitbounds'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fit map to markers'),
      '#default_value' => $this->options['fitbounds'],
    ];

    $form['geolocate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Start at visitor position (browser geolocation)'),
      '#description' => $this->t('Uses the visitor’s browser position as the map start. Falls back to the configured center if denied.'),
      '#default_value' => $this->options['geolocate'],
    ];

    $form['openwaters'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenWaters data layers'),
      '#open' => TRUE,
    ];

    $form['openwaters']['enable_seascape'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Seascape bathymetry overlay'),
      '#default_value' => $this->options['enable_seascape'],
    ];
    $form['openwaters']['enable_tides'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable tide predictions on map click'),
      '#default_value' => $this->options['enable_tides'],
    ];
    $form['openwaters']['tides_units'] = [
      '#type' => 'select',
      '#title' => $this->t('Tide units'),
      '#options' => ['m' => 'Metres', 'ft' => 'Feet', 'fm' => 'Fathoms'],
      '#default_value' => $this->options['tides_units'],
    ];
    $form['openwaters']['enable_ais'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable live vessel traffic (AIS)'),
      '#default_value' => $this->options['enable_ais'],
    ];
    $form['openwaters']['ais_refresh'] = [
      '#type' => 'number',
      '#title' => $this->t('AIS refresh interval (seconds)'),
      '#default_value' => $this->options['ais_refresh'],
      '#min' => 5,
      '#max' => 600,
    ];
  }

  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    if (!$form_state->getValue(['style_options', 'data_source'])) {
      $form_state->setError($form['data_source'], $this->t('A geolocation data source is required.'));
    }
  }

  public function render() {
    $cconf = \Drupal::config('sailbuddy_map.settings');

    $data_source = $this->options['data_source'];
    if (!$data_source) {
      return ['#markup' => $this->t('No geolocation data source configured for this map.')];
    }

    $features = [];
    foreach ($this->view->result as $row_index => $row) {
      if (empty($row->_entity)) {
        continue;
      }
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = $row->_entity;
      if (!$entity->hasField($data_source)) {
        continue;
      }

      $items = $entity->get($data_source)->getValue();
      $points = $this->extractPoints($items);

      foreach ($points as $point) {
        if (empty($point['lat']) || empty($point['lon'])) {
          continue;
        }
        $features[] = [
          'lon' => (float) $point['lon'],
          'lat' => (float) $point['lat'],
          'title' => $entity->label(),
          'url' => $entity->hasLinkTemplate('canonical') ? $entity->toUrl()->toString() : '',
          'type' => $entity->bundle(),
        ];
      }
    }

    $height_unit = $this->options['height_unit'] ?? 'vh';
    $map_id = 'sailbuddy-map-' . md5($this->view->id() . $this->view->current_display . serialize($this->options));

    return [
      '#theme' => 'sailbuddy_map',
      '#map_id' => $map_id,
      '#height' => $this->options['height'] . $height_unit,
      '#attached' => [
        'library' => ['sailbuddy_map/sailbuddy_map.init'],
        'drupalSettings' => [
          'sailbuddy_map' => [
            $map_id => [
              'baseStyleUrl' => !empty($this->options['base_style_url']) ? $this->options['base_style_url'] : $cconf->get('base_style_url'),
              'tileJsonUrl' => '',
              'tileUrl' => '',
              'features' => $features,
              'center' => [
                'lat' => (float) $cconf->get('center_lat') ?? 55.6761,
                'lng' => (float) $cconf->get('center_lng') ?? 12.5683,
              ],
              'zoom' => (int) $cconf->get('zoom') ?? 5,
              'mapId' => $map_id,
              'apiBase' => 'https://openwaters.io/api',
              'tideApi' => 'https://api.openwaters.io/tides',
              'aisApi' => 'https://ais.openwaters.io/v1/vessels',
              'seascapeBase' => 'https://tiles.openwaters.io/seascape',
              'enableSeascape' => (bool) $this->options['enable_seascape'],
              'enableTides' => (bool) $this->options['enable_tides'],
              'tidesUnits' => $this->options['tides_units'],
              'enableAis' => (bool) $this->options['enable_ais'],
              'aisRefresh' => (int) $this->options['ais_refresh'],
              'fitbounds' => (bool) $this->options['fitbounds'],
              'geolocate' => (bool) ($this->options['geolocate'] ?? FALSE),
              'markerIconUrl' => (string) $cconf->get('marker_icon_url'),
              'attribution' => (string) $cconf->get('attribution'),
            ],
          ],
        ],
      ],
    ];
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