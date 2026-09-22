<?php

namespace Drupal\sailbuddy_map\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Sailbuddy Map' block.
 *
 * @Block(
 *   id = "sailbuddy_map_block",
 *   admin_label = @Translation("Sailbuddy Map"),
 * )
 */
class SailbuddyMapBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return [
      'use_site_default' => TRUE,
      'tile_url' => '',
      'tilejson_url' => '',
      'center_lat' => 55.6761,
      'center_lng' => 12.5683,
      'zoom' => 5,
      'height' => '500px',
      'enabled_layers' => [],
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state) {
    $config = \Drupal::config('sailbuddy_map.settings');

    $form['use_site_default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use site default tile source / TileJSON'),
      '#default_value' => $this->configuration['use_site_default'] ?? TRUE,
    ];

    $form['tilejson_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Per-block TileJSON URL'),
      '#description' => $this->t('Optional TileJSON endpoint (TileJSON format). Example: https://openwaters.io/api/tiles/bathymetry/tilejson.json'),
      '#default_value' => $this->configuration['tilejson_url'],
    ];

    $form['tile_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Per-block tile URL (XYZ)'),
      '#description' => $this->t('Fallback XYZ template: https://.../{z}/{x}/{y}.png'),
      '#default_value' => $this->configuration['tile_url'],
    ];

    $form['center_lat'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Center latitude'),
      '#default_value' => $this->configuration['center_lat'],
    ];
    $form['center_lng'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Center longitude'),
      '#default_value' => $this->configuration['center_lng'],
    ];
    $form['zoom'] = [
      '#type' => 'number',
      '#title' => $this->t('Zoom'),
      '#default_value' => $this->configuration['zoom'],
      '#min' => 0,
      '#max' => 22,
    ];

    $form['height'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map height'),
      '#default_value' => $this->configuration['height'],
      '#description' => $this->t('CSS height value, e.g., 500px or 60vh'),
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['use_site_default'] = $form_state->getValue('use_site_default');
    $this->configuration['tilejson_url'] = $form_state->getValue('tilejson_url');
    $this->configuration['tile_url'] = $form_state->getValue('tile_url');
    $this->configuration['center_lat'] = $form_state->getValue('center_lat');
    $this->configuration['center_lng'] = $form_state->getValue('center_lng');
    $this->configuration['zoom'] = $form_state->getValue('zoom');
    $this->configuration['height'] = $form_state->getValue('height');
  }

  public function build(): array {
    $config = \Drupal::config('sailbuddy_map.settings');

    $tilejson = '';
    $tileUrl = '';

    if (!empty($this->configuration['use_site_default'])) {
      $tilejson = $config->get('openwaters_tilejson_url') ?: '';
      $tileUrl = $config->get('default_tile_url') ?: '';
    }

    // Per-block overrides
    if (!empty($this->configuration['tilejson_url'])) {
      $tilejson = $this->configuration['tilejson_url'];
    }
    if (!empty($this->configuration['tile_url'])) {
      $tileUrl = $this->configuration['tile_url'];
    }

    $id = 'sailbuddy-map-' . md5($this->getPluginId() . serialize($this->configuration));

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sailbuddy-map-wrapper']],
      'map' => [
        '#markup' => '<div id="' . $id . '" class="sailbuddy-map" style="height: ' . $this->configuration['height'] . ';"></div>',
      ],
      '#attached' => [
        'library' => [
          'sailbuddy_map/sailbuddy_map.init',
        ],
        'drupalSettings' => [
          'sailbuddy_map' => [
            $id => [
              'tileJsonUrl' => $tilejson,
              'tileUrl' => $tileUrl,
              'center' => [
                'lat' => (float) $this->configuration['center_lat'],
                'lng' => (float) $this->configuration['center_lng'],
              ],
              'zoom' => (int) $this->configuration['zoom'],
              'mapId' => $id,
              'apiBase' => 'https://openwaters.io/api',
            ],
          ],
        ],
      ],
    ];

    return $build;
  }

}
