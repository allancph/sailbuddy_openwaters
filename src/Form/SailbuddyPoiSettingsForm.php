<?php

namespace Drupal\sailbuddy_poi\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('key.repository'),
      $container->get('cache_tags.invalidator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository, CacheTagsInvalidatorInterface $cache_tags_invalidator) {
    parent::__construct($config_factory);
    $this->keyRepository = $key_repository;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
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

    $form['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache lifetime (seconds)'),
      '#description' => $this->t('How long a bbox response is cached before the Garmin API is called again.'),
      '#min' => 30,
      '#max' => 86400,
      '#default_value' => (int) $config->get('cache_ttl') ?: 900,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('sailbuddy_poi.settings')
      ->set('api_key_key', $form_state->getValue('api_key_key'))
      ->set('environment', $form_state->getValue('environment'))
      ->set('zoom_min', (int) $form_state->getValue('zoom_min'))
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->save();
    $this->cacheTagsInvalidator->invalidateTags(['sailbuddy_poi:activecaptain']);
    parent::submitForm($form, $form_state);
  }

}