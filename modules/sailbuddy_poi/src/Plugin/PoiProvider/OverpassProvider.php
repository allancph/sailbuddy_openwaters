<?php

namespace Drupal\sailbuddy_poi\Plugin\PoiProvider;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\sailbuddy_poi\Annotation\PoiProvider;
use Drupal\sailbuddy_poi\PoiProviderInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides OpenStreetMap Points of Interest via the Overpass API.
 *
 * @PoiProvider(
 *   id = "overpass",
 *   label = @Translation("OSM POI")
 * )
 */
class OverpassProvider extends PluginBase implements PoiProviderInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Maps a configured tag value to a normalized POI type (see JS TYPE_LABELS).
   *
   * @var array
   */
  protected const TYPE_MAP = [
    'fuel' => 'fuel',
    'drinking_water' => 'drinking_water',
    'chandlery' => 'business',
    'boatbuilder' => 'business',
    'charging_station' => 'charger',
  ];

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('string_translation')
    );
  }

  /**
   * Constructs a new OverpassProvider.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, ClientInterface $http_client, TranslationInterface $string_translation) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return $this->pluginId;
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(array $bbox, int $zoom) {
    $settings = $this->configFactory->get('sailbuddy_poi.settings');
    $endpoint = $settings->get('overpass_endpoint') ?: 'https://overpass-api.de/api/interpreter';
    $tags = (array) ($settings->get('overpass_tags') ?: []);

    $queries = [];
    foreach ($tags as $tag) {
      $pair = array_map('trim', explode('=', (string) $tag, 2));
      if (count($pair) !== 2 || empty($pair[0]) || empty($pair[1])) {
        continue;
      }
      $queries[] = 'nwr["' . addcslashes($pair[0], '"\\') . '"="' . addcslashes($pair[1], '"\\') . '"]'
        . '(' . $bbox['south'] . ',' . $bbox['west'] . ',' . $bbox['north'] . ',' . $bbox['east'] . ');';
    }
    if (empty($queries)) {
      return [];
    }

    $ql = "[out:json][timeout:25];(\n" . implode("\n", $queries) . "\n);\nout center 400;";

    $response = $this->httpClient->post($endpoint, [
      'body' => 'data=' . urlencode($ql),
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
        'User-Agent' => 'SailbuddyPOI/1.0 (+https://dev.sailbuddy.com)',
      ],
      'timeout' => 30,
    ]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Overpass: HTTP ' . $response->getStatusCode());
    }
    $data = json_decode($response->getBody()->getContents(), TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Overpass: invalid JSON response.');
    }

    $rows = [];
    foreach ((array) ($data['elements'] ?? []) as $element) {
      $tags_el = (array) ($element['tags'] ?? []);
      if (isset($element['lat'], $element['lon'])) {
        $lat = (float) $element['lat'];
        $lng = (float) $element['lon'];
      }
      elseif (isset($element['center']['lat'], $element['center']['lon'])) {
        $lat = (float) $element['center']['lat'];
        $lng = (float) $element['center']['lon'];
      }
      else {
        continue;
      }

      $type_key = $this->detectTypeKey($tags_el);
      $type = self::TYPE_MAP[$type_key] ?? 'poi';
      $name = (string) ($tags_el['name'] ?? '');
      if ($name === '' && isset($tags_el['brand'])) {
        $name = (string) $tags_el['brand'];
      }
      if ($name === '') {
        $name = (string) $this->typeLabel($type);
      }

      $rows[] = [
        'id' => substr($element['type'] ?? 'n', 0, 1) . (string) ($element['id'] ?? '0'),
        'lat' => $lat,
        'lng' => $lng,
        'name' => $name,
        'type' => $type,
        'icon' => '',
        'webview' => '',
        'count' => 1,
      ];
    }

    return $rows;
  }

  /**
   * Picks which configured tag value an element matches, in config order.
   *
   * @param array $tags
   *   OSM tags of the element.
   *
   * @return string|null
   *   The matched tag value, or NULL.
   */
  protected function detectTypeKey(array $tags) {
    $settings = $this->configFactory->get('sailbuddy_poi.settings');
    $configured = (array) ($settings->get('overpass_tags') ?: []);
    foreach ($configured as $tag) {
      $pair = array_map('trim', explode('=', (string) $tag, 2));
      if (count($pair) === 2 && isset($tags[$pair[0]]) && (string) $tags[$pair[0]] === $pair[1]) {
        return $pair[1];
      }
    }
    return NULL;
  }

  /**
   * Human-readable label for a normalized POI type.
   *
   * @param string $type
   *   Normalized type.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   Translated label.
   */
  protected function typeLabel($type) {
    switch ($type) {
      case 'fuel':
        return $this->t('Brændstof');

      case 'drinking_water':
        return $this->t('Drikkevand');

      case 'charger':
        return $this->t('El-opladning');

      case 'boat_ramp':
        return $this->t('Bådrampe');

      case 'marina':
        return $this->t('Havn / marina');

      case 'anchorage':
        return $this->t('Ankerplads');

      case 'business':
        return $this->t('Forretning');

      default:
        return $this->t('POI');
    }
  }

}