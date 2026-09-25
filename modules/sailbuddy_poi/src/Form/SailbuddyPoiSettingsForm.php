<?php

namespace Drupal\sailbuddy_poi\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\sailbuddy_poi\PoiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Sailbuddy POI.
 */
class SailbuddyPoiSettingsForm extends ConfigFormBase {

  /**
   * Key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * POI provider plugin manager.
   *
   * @var \Drupal\sailbuddy_poi\PoiProviderPluginManager
   */
  protected $providerManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('key.repository'),
      $container->get('cache_tags.invalidator'),
      $container->get('plugin.manager.sailbuddy_poi.provider')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository, CacheTagsInvalidatorInterface $cache_tags_invalidator, PoiProviderPluginManager $provider_manager) {
    parent::__construct($config_factory);
    $this->keyRepository = $key_repository;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
    $this->providerManager = $provider_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['sailbuddy_poi.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sailbuddy_poi_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sailbuddy_poi.settings');

    $key_ids = [];
    foreach ($this->keyRepository->getKeys() as $key) {
      $key_ids[$key->id()] = $key->label() . ' (' . $key->id() . ')';
    }
    if (empty($key_ids)) {
      $key_ids[''] = $this->t('No keys found. Create one in the Key module first.');
    }

    $form['api_key_key'] = [
      '#type' => 'select',
      '#title' => $this->t('ActiveCaptain API key'),
      '#description' => $this->t('Key module reference holding the Garmin ActiveCaptain API key.'),
      '#options' => $key_ids,
      '#default_value' => $config->get('api_key_key'),
      '#required' => TRUE,
    ];

    $form['environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Garmin environment'),
      '#options' => [
        'stage' => $this->t('Staging (development + testing)'),
        'production' => $this->t('Production (requires approved production key)'),
      ],
      '#default_value' => $config->get('environment') === 'production' ? 'production' : 'stage',
    ];

    $form['zoom_min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum zoom for POI icons'),
      '#description' => $this->t('POI icons are only fetched and shown when the map is at (or above) this zoom level.'),
      '#min' => 0,
      '#max' => 18,
      '#default_value' => (int) $config->get('zoom_min') ?: 10,
      '#required' => TRUE,
    ];

    $provider_options = [];
    foreach ($this->providerManager->getDefinitions() as $id => $definition) {
      $provider_options[$id] = (string) $definition['label'];
    }
    $form['providers_enabled'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Aktive POI-providers'),
      '#description' => $this->t('Hvilke provider-lag der vises i kortets lag-menu (alle starter slukket).'),
      '#options' => $provider_options,
      '#default_value' => array_values(array_filter((array) $config->get('providers_enabled'))) ?: array_keys($provider_options),
    ];

    $form['overpass'] = [
      '#type' => 'details',
      '#title' => $this->t('Overpass (OpenStreetMap)'),
      '#open' => FALSE,
    ];
    $form['overpass']['overpass_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Overpass API endpoint'),
      '#description' => $this->t('Eksempel: https://overpass-api.de/api/interpreter'),
      '#default_value' => $config->get('overpass_endpoint') ?: 'https://overpass-api.de/api/interpreter',
      '#required' => TRUE,
    ];
    $form['overpass']['overpass_tags'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Overpass tag-filtre'),
      '#description' => $this->t('Én "key=value" pr. linje, f.eks. amenity=fuel eller shop=chandlery.'),
      '#default_value' => implode("\n", (array) $config->get('overpass_tags')),
    ];

    $form['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache lifetime (seconds)'),
      '#description' => $this->t('How long a bbox response is cached before the upstream provider is called again. Stale data is served while an outage is detected.'),
      '#min' => 30,
      '#max' => 31536000,
      '#default_value' => (int) $config->get('cache_ttl') ?: 604800,
      '#required' => TRUE,
    ];

    $form['overpass_budget'] = [
      '#type' => 'number',
      '#title' => $this->t('Overpass meget-tid-budget (seconds)'),
      '#description' => $this->t('Total time budget in seconds for trying all Overpass endpoints before the request fails over to stale cache.'),
      '#min' => 5,
      '#max' => 120,
      '#step' => 1,
      '#default_value' => (float) ($config->get('overpass_budget') ?: 30.0),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $enabled = array_values(array_filter($form_state->getValue('providers_enabled')));
    $tags = array_values(array_filter(array_map('trim', explode("\n", (string) $form_state->getValue('overpass_tags')))));

    $this->config('sailbuddy_poi.settings')
      ->set('api_key_key', $form_state->getValue('api_key_key'))
      ->set('environment', $form_state->getValue('environment'))
      ->set('zoom_min', (int) $form_state->getValue('zoom_min'))
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->set('overpass_budget', (float) $form_state->getValue('overpass_budget') ?: 30.0)
      ->set('providers_enabled', $enabled)
      ->set('overpass_endpoint', $form_state->getValue('overpass_endpoint'))
      ->set('overpass_tags', $tags)
      ->save();

    $tags_invalidate = ['sailbuddy_poi:activecaptain', 'sailbuddy_poi:overpass'];
    foreach ($tags_invalidate as $tag) {
      $this->cacheTagsInvalidator->invalidateTags([$tag]);
    }
    parent::submitForm($form, $form_state);
  }

}