<?php

namespace Drupal\sailbuddy_poi\Plugin\PoiProvider;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\sailbuddy_poi\Annotation\PoiProvider;
use Drupal\sailbuddy_poi\PoiProviderInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Garmin ActiveCaptain community POI provider.
 *
 * @PoiProvider(
 *   id = "activecaptain",
 *   label = @Translation("ActiveCaptain (Garmin)")
 * )
 */
class ActiveCaptainProvider extends PluginBase implements PoiProviderInterface, ContainerFactoryPluginInterface {

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

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
      $container->get('key.repository'),
      $container->get('http_client')
    );
  }

  /**
   * Constructs a new ActiveCaptainProvider.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
    $this->httpClient = $http_client;
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
    $environment = $settings->get('environment') === 'production' ? 'production' : 'stage';
    $endpoint = $settings->get($environment === 'production' ? 'endpoint_production' : 'endpoint_stage');

    $key_id = $settings->get('api_key_key');
    $api_key = $key_id ? $this->keyRepository->getKey($key_id) : NULL;
    $api_key = $api_key ? $api_key->getKeyValue() : NULL;
    if (empty($api_key)) {
      throw new \RuntimeException('ActiveCaptain: missing API key (Key module id "' . ($key_id ?? '') . '").');
    }

    $body = [
      'north' => (float) $bbox['north'],
      'south' => (float) $bbox['south'],
      'east' => (float) $bbox['east'],
      'west' => (float) $bbox['west'],
      'zoomLevel' => (int) $zoom,
    ];

    $response = $this->httpClient->post($endpoint, [
      'headers' => [
        'apikey' => $api_key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'json' => $body,
      'timeout' => 20,
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);

    $rows = [];
    foreach (($data['pointsOfInterest'] ?? []) as $poi) {
      $location = $poi['mapLocation'] ?? [];
      if (!isset($location['latitude'], $location['longitude'])) {
        continue;
      }
      $icon = (string) ($poi['iconUrl'] ?? '');
      $webview = (string) ($poi['webviewUrl'] ?? '');
      $count = (int) ($poi['poiCount'] ?? 1);
      $id = (string) ($poi['id'] ?? '');
      // The API drops the trailing poi id into the webview URL for single POIs.
      if (empty($id) && $webview) {
        $id = (string) basename(parse_url($webview, PHP_URL_PATH));
      }
      $rows[] = [
        'id' => $id,
        'lat' => (float) $location['latitude'],
        'lng' => (float) $location['longitude'],
        'name' => $this->cleanName((string) ($poi['name'] ?? '')),
        'type' => $this->typeFromIcon($icon, $count, $webview),
        'icon' => $icon,
        'webview' => $webview,
        'count' => $count,
      ];
    }

    return $rows;
  }

  /**
   * Collapse the double-encoded UTF-8 the Garmin API returns.
   *
   * Garmin returns e.g. "LystbÃ¥dehavn" (bytes C3 83 C2 A5) for what should
   * be "Lystbådehavn". Names that are already correct are left untouched.
   *
   * @param string $name
   *   Raw name from the Garmin API.
   *
   * @return string
   *   Cleaned name.
   */
  protected function cleanName($name) {
    // Mojibake markers: the literal characters "Ã" (U+00C3) and "Â" (U+00C2)
    // never legitimately appear in the (Scandinavian) titles handled here.
    if (strpos($name, "\u{00C3}") !== FALSE || strpos($name, "\u{00C2}") !== FALSE) {
      $fixed = mb_convert_encoding($name, 'ISO-8859-1', 'UTF-8');
      if ($fixed !== FALSE) {
        $name = $fixed;
      }
    }
    return $name;
  }

  /**
   * Derives a POI type from the Garmin icon URL, or marks a cluster.
   *
   * @param string $icon
   *   Garmin icon URL.
   * @param int $count
   *   Aggregated POI count for the marker.
   * @param string $webview
   *   Webview URL; empty for cluster markers.
   *
   * @return string
   *   Machine type: marina, anchorage, hazard, knowledge, boat_ramp, lock,
   *   bridge, fuel, business, poi or cluster.
   */
  protected function typeFromIcon($icon, $count, $webview) {
    if ($count > 1 && empty($webview)) {
      return 'cluster';
    }
    $base = strtolower((string) pathinfo($icon, PATHINFO_FILENAME));
    if (strpos($base, 'anchorage') !== FALSE) {
      return 'anchorage';
    }
    if (strpos($base, 'marina') !== FALSE) {
      return 'marina';
    }
    if (strpos($base, 'hazard') !== FALSE) {
      return 'hazard';
    }
    if (strpos($base, 'fuel') !== FALSE) {
      return 'fuel';
    }
    if (strpos($base, 'lock') !== FALSE) {
      return 'lock';
    }
    if (strpos($base, 'bridge') !== FALSE) {
      return 'bridge';
    }
    if (strpos($base, 'ramp') !== FALSE) {
      return 'boat_ramp';
    }
    if (strpos($base, 'shop') !== FALSE || strpos($base, 'business') !== FALSE) {
      return 'business';
    }
    if (strpos($base, 'know') !== FALSE || strpos($base, 'knowledge') !== FALSE) {
      return 'knowledge';
    }
    return 'poi';
  }

}