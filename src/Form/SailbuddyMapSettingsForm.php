<?php

namespace Drupal\sailbuddy_map\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SailbuddyMapSettingsForm extends ConfigFormBase {

  public function getFormId() {
    return 'sailbuddy_map_settings';
  }

  protected function getEditableConfigNames() {
    return ['sailbuddy_map.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sailbuddy_map.settings');

    $form['default_tile_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Tile URL (XYZ)'),
      '#description' => $this->t('Site-wide default tile URL template (use {z}, {x}, {y}) for XYZ raster tiles.'),
      '#default_value' => $config->get('default_tile_url'),
    ];

    $form['openwaters_tilejson_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenWaters TileJSON URL'),
      '#description' => $this->t('TileJSON endpoint to discover available OpenWaters vector or raster tiles. Example: https://openwaters.io/api/tiles/bathymetry/tilejson.json'),
      '#default_value' => $config->get('openwaters_tilejson_url'),
    ];

    $form['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('TileJSON / GeoJSON cache TTL (seconds)'),
      '#default_value' => $config->get('cache_ttl') ?? 3600,
      '#min' => 0,
    ];

    $form['default_center'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    $form['default_center']['center_lat'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default center latitude'),
      '#default_value' => $config->get('center_lat') ?? 55.6761,
    ];
    $form['default_center']['center_lng'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default center longitude'),
      '#default_value' => $config->get('center_lng') ?? 12.5683,
    ];

    $form['default_zoom'] = [
      '#type' => 'number',
      '#title' => $this->t('Default zoom'),
      '#default_value' => $config->get('zoom') ?? 5,
      '#min' => 0,
      '#max' => 22,
    ];

    $form['attribution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default attribution'),
      '#default_value' => $config->get('attribution') ?? 'Map data © OpenWaters',
    ];

    return parent::buildForm($form, $form_state) + $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $tile = $form_state->getValue('default_tile_url');
    if (!empty($tile) && strpos($tile, '{z}') === FALSE) {
      $form_state->setErrorByName('default_tile_url', $this->t('Tile URL should include {z}, {x}, and {y} placeholders for XYZ tiles.'));
    }
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('sailbuddy_map.settings')
      ->set('default_tile_url', $form_state->getValue('default_tile_url'))
      ->set('openwaters_tilejson_url', $form_state->getValue('openwaters_tilejson_url'))
      ->set('cache_ttl', $form_state->getValue('cache_ttl'))
      ->set('center_lat', $form_state->getValue(['default_center', 'center_lat']))
      ->set('center_lng', $form_state->getValue(['default_center', 'center_lng']))
      ->set('zoom', $form_state->getValue('default_zoom'))
      ->set('attribution', $form_state->getValue('attribution'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
