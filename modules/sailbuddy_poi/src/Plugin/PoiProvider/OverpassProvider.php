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
   * Maps a configured Overpass tag ("key=value") to a normalized POI type
   * (see JS TYPE_LABELS / FALLBACK_COLORS in sailbuddy-poi.js).
   *
   * @var array
   */
  protected const TYPE_BY_TAG = [
    'amenity=fuel' => 'fuel',
    'amenity=drinking_water' => 'drinking_water',
    'amenity=toilets' => 'toilets',
    'amenity=recycling' => 'recycling',
    'amenity=atm' => 'atm',
    'amenity=pharmacy' => 'pharmacy',
    'amenity=post_office' => 'post',
    'amenity=doctors' => 'doctor',
    'amenity=restaurant' => 'food',
    'amenity=cafe' => 'food',
    'amenity=bar' => 'food',
    'amenity=pub' => 'food',
    'amenity=fast_food' => 'food',
    'amenity=ice_cream' => 'food',
    'shop=supermarket' => 'shop',
    'shop=convenience' => 'shop',
    'shop=chandlery' => 'chandlery',
    'shop=laundry' => 'laundry',
    'craft=boatbuilder' => 'boatbuilder',
    'tourism=museum' => 'tourist',
    'tourism=attraction' => 'tourist',
    'tourism=artwork' => 'tourist',
    'tourism=viewpoint' => 'viewpoint',
    'tourism=hotel' => 'accommodation',
    'tourism=hostel' => 'accommodation',
    'tourism=camp_site' => 'accommodation',
    'tourism=caravan_site' => 'accommodation',
    'amenity=bicycle_rental' => 'rental',
    'amenity=escooter_rental' => 'rental',
    'amenity=bicycle_repair_station' => 'bike_repair',
    'leisure=marina' => 'marina',
  ];

  /**
   * Backup Overpass mirrors tried in order if the configured endpoint fails.
   *
   * overpass-api.de is regularly throttled or unavailable; fallbacks keep the
   * POI layer alive during outages. Mirrors must cover the whole planet —
   * regional extract mirrors (e.g. overpass.osm.ch) silently return empty for
   * areas outside their extract and would look like "no data".
   *
   * @var string[]
   */
  protected const FALLBACK_ENDPOINTS = [
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://overpass.private.coffee/api/interpreter',
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

    $endpoints = array_values(array_unique(array_filter(array_merge(
      [$endpoint],
      self::FALLBACK_ENDPOINTS
    ))));

    $last_error = 'alle Overpass-endpoints var utilgængelige.';
    foreach ($endpoints as $candidate) {
      try {
        $response = $this->httpClient->post($candidate, [
          'body' => 'data=' . urlencode($ql),
          'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'User-Agent' => 'SailbuddyPOI/1.0 (+https://dev.sailbuddy.com)',
          ],
          'timeout' => 30,
        ]);
      }
      catch (\Exception $e) {
        $last_error = $candidate . ': ' . $e->getMessage();
        continue;
      }
      if ($response->getStatusCode() !== 200) {
        $last_error = $candidate . ': HTTP ' . $response->getStatusCode();
        continue;
      }
      $data = json_decode($response->getBody()->getContents(), TRUE);
      // A usable response carries an "elements" array; errors come back with
      // a "remark" field instead and should not count as success.
      if (!is_array($data) || !array_key_exists('elements', $data)) {
        $last_error = $candidate . ': empty/invalid JSON response.';
        continue;
      }
      return $this->buildRows($data);
    }
    throw new \RuntimeException('Overpass: ' . $last_error);
  }

  /**
   * Converts an Overpass response to normalized POI rows.
   *
   * @param array $data
   *   Decoded Overpass JSON with "elements".
   *
   * @return array
   *   Normalized rows (see PoiProviderInterface::fetch()).
   */
  protected function buildRows(array $data) {
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

      $type = $this->detectType($tags_el);
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
   * Picks which configured tag an element matches, in config order, and maps
   * it to a normalized POI type.
   *
   * @param array $tags
   *   OSM tags of the element.
   *
   * @return string
   *   Normalized type (see TYPE_BY_TAG), or 'poi'.
   */
  protected function detectType(array $tags) {
    $settings = $this->configFactory->get('sailbuddy_poi.settings');
    $configured = (array) ($settings->get('overpass_tags') ?: []);
    foreach ($configured as $tag) {
      $pair = array_map('trim', explode('=', (string) $tag, 2));
      if (count($pair) === 2 && isset($tags[$pair[0]]) && (string) $tags[$pair[0]] === $pair[1]) {
        return self::TYPE_BY_TAG[$tag] ?? 'poi';
      }
    }
    return 'poi';
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

      case 'boat_ramp':
        return $this->t('Bådrampe');

      case 'marina':
        return $this->t('Havn / marina');

      case 'anchorage':
        return $this->t('Ankerplads');

      case 'toilets':
        return $this->t('Toilet');

      case 'recycling':
        return $this->t('Genbrug / affald');

      case 'atm':
        return $this->t('Hæveautomat');

      case 'pharmacy':
        return $this->t('Apotek');

      case 'food':
        return $this->t('Mad & drikke');

      case 'shop':
        return $this->t('Butik / købmand');

      case 'chandlery':
        return $this->t('Sejlerforretning');

      case 'boatbuilder':
        return $this->t('Bådværft');

      case 'laundry':
        return $this->t('Vaskeri');

      case 'post':
        return $this->t('Post');

      case 'doctor':
        return $this->t('Læge');

      case 'tourist':
        return $this->t('Turistattraktion');

      case 'viewpoint':
        return $this->t('Udsigtspunkt');

      case 'rental':
        return $this->t('Cykel / éløbehjul-udlejning');

      case 'bike_repair':
        return $this->t('Cykelservice');

      case 'accommodation':
        return $this->t('Overnatning');

      default:
        return $this->t('POI');
    }
  }

}