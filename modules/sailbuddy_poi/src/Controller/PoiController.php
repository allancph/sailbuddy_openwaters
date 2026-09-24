<?php

namespace Drupal\sailbuddy_poi\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\key\KeyRepositoryInterface;
use Drupal\sailbuddy_poi\PoiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves normalized POI GeoJSON and webview redirects.
 */
class PoiController extends ControllerBase {

  /**
   * The POI provider plugin manager.
   *
   * @var \Drupal\sailbuddy_poi\PoiProviderPluginManager
   */
  protected $providerManager;

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.sailbuddy_poi.provider'),
      $container->get('cache.default'),
      $container->get('config.factory'),
      $container->get('key.repository')
    );
  }

  /**
   * Constructs a new PoiController.
   */
  public function __construct(PoiProviderPluginManager $provider_manager, CacheBackendInterface $cache, ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository) {
    $this->providerManager = $provider_manager;
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
  }

  /**
   * Returns normalized POI data as GeoJSON for a bounding box.
   *
   * @param string $provider
   *   Provider plugin id, e.g. "activecaptain".
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   GeoJSON FeatureCollection response.
   */
  public function build($provider, Request $request) {
    $settings = $this->configFactory->get('sailbuddy_poi.settings');
    $ttl = (int) $settings->get('cache_ttl') ?: 900;

    $error = $this->validateBbox($request, $bbox, $zoom);
    if ($error !== NULL) {
      return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
    }
    if (!$this->providerManager->hasDefinition($provider)) {
      return new JsonResponse(['error' => 'Unknown POI provider.'], Response::HTTP_NOT_FOUND);
    }

    // Cache per provider + coarse bbox bucket so adjacent views reuse data.
    $bucket = 'sbpoi:' . $provider . ':'
      . round($bbox['north'], 2) . ',' . round($bbox['south'], 2) . ','
      . round($bbox['west'], 2) . ',' . round($bbox['east'], 2) . ':z' . $zoom;

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheTags(['sailbuddy_poi:' . $provider]);

    $from_cache = $this->cache->get($bucket);
    if ($from_cache !== FALSE && !empty($from_cache->data['features'])) {
      $collection = $from_cache->data;
      $hit = 'HIT';
    }
    else {
      try {
        $plugin = $this->providerManager->createInstance($provider);
        $rows = $plugin->fetch($bbox, $zoom);
      }
      catch (\Exception $e) {
        $this->getLogger('sailbuddy_poi')->error('Provider @provider failed: @err', [
          '@provider' => $provider,
          '@err' => $e->getMessage(),
        ]);
        return new JsonResponse(['error' => 'POI provider unavailable.'], Response::HTTP_BAD_GATEWAY);
      }

      $collection = $this->toGeoJson($rows);
      $this->cache->set($bucket, $collection, $this->time() + $ttl, $cacheable->getCacheTags());
      $hit = 'MISS';
    }

    $response = new JsonResponse($collection);
    // Deliberately NOT a CacheableResponseInterface response: Drupal's
    // internal page cache would conflate distinct bbox query strings under
    // this route. Caching is handled by our own bbox-bucket cache above; the
    // Cache-Control header only gives browsers/CDNs a TTL. Add cache tags and
    // contexts manually so invalidation still works if a reverse proxy honors
    // X-Drupal-Cache-Tags.
    $response->headers->set('Cache-Control', 'public, max-age=' . $ttl);
    $response->headers->set('X-Sailbuddy-Poi-Provider', $provider);
    $response->headers->set('X-Sailbuddy-Poi-Cache', $hit);
    $response->headers->set('X-Drupal-Cache-Contexts', 'url.query_args');
    $response->headers->set('X-Drupal-Cache-Tags', 'sailbuddy_poi:' . $provider);
    return $response;
  }

  /**
   * Redirects to the Garmin POI webview, appending the API key server-side.
   *
   * @param string $provider
   *   Provider plugin id.
   * @param int $poiId
   *   Numeric Garmin POI identifier.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the Garmin webview, or a 404 JSON response.
   */
  public function webview($provider, $poiId) {
    if (!$this->providerManager->hasDefinition($provider)) {
      return new JsonResponse(['error' => 'Unknown POI provider.'], Response::HTTP_NOT_FOUND);
    }
    $settings = $this->configFactory->get('sailbuddy_poi.settings');
    $environment = $settings->get('environment') === 'production' ? 'production' : 'stage';
    $base = $settings->get($environment === 'production' ? 'webview_production' : 'webview_stage');
    if (empty($base) || !ctype_digit((string) $poiId)) {
      return new JsonResponse(['error' => 'Invalid POI.'], Response::HTTP_NOT_FOUND);
    }

    $key_id = $settings->get('api_key_key');
    $api_key = $key_id ? $this->keyRepository->getKey($key_id) : NULL;
    $api_key = $api_key ? $api_key->getKeyValue() : '';
    $separator = strpos($base, '?') === FALSE ? '?' : '&';
    $url = $base . '/' . $poiId . $separator . 'apikey=' . rawurlencode($api_key);

    $response = new TrustedRedirectResponse($url, Response::HTTP_FOUND);
    $response->headers->set('Referrer-Policy', 'no-referrer');
    return $response;
  }

  /**
   * Validates and normalizes bbox query parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param array|null $bbox
   *   Output bbox (by reference).
   * @param int|null $zoom
   *   Output clamped zoom (by reference).
   *
   * @return string|null
   *   Error message or NULL when valid.
   */
  protected function validateBbox(Request $request, ?array &$bbox, ?int &$zoom) {
    $keys = ['north', 'south', 'west', 'east'];
    $values = [];
    foreach ($keys as $key) {
      $raw = $request->query->get($key);
      if ($raw === NULL || !is_numeric($raw)) {
        return 'Missing or non-numeric bbox parameter "' . $key . '".';
      }
      $value = (float) $raw;
      if ($value < -180 || $value > 180) {
        return 'Bbox parameter "' . $key . '" out of range.';
      }
      $values[$key] = $value;
    }
    if ($values['north'] <= $values['south']) {
      return 'Invalid bbox: north must be greater than south.';
    }
    if ($values['east'] <= $values['west']) {
      return 'Invalid bbox: east must be greater than west.';
    }
    $lat_span = abs($values['north'] - $values['south']);
    if ($lat_span > 90) {
      return 'Bbox too large.';
    }

    $zoom_raw = $request->query->get('zoom', 10);
    $zoom = $zoom_raw !== NULL && is_numeric($zoom_raw) ? max(0, min(18, (int) $zoom_raw)) : 10;
    $zoom = max(0, min(18, $zoom));

    // Clamp latitudes/longitudes to valid ranges.
    $values['north'] = max(-90, min(90, $values['north']));
    $values['south'] = max(-90, min(90, $values['south']));
    $values['east'] = max(-180, min(180, $values['east']));
    $values['west'] = max(-180, min(180, $values['west']));

    $bbox = $values;
    return NULL;
  }

  /**
   * Builds a GeoJSON FeatureCollection from normalized provider rows.
   *
   * @param array $rows
   *   Normalized POI rows from a provider.
   *
   * @return array
   *   GeoJSON FeatureCollection.
   */
  protected function toGeoJson(array $rows) {
    $features = [];
    foreach ($rows as $row) {
      $features[] = [
        'type' => 'Feature',
        'geometry' => [
          'type' => 'Point',
          'coordinates' => [$row['lng'], $row['lat']],
        ],
        'properties' => [
          'id' => $row['id'],
          'name' => $row['name'],
          'type' => $row['type'],
          'icon' => $row['icon'],
          'webview' => $row['webview'],
          'count' => $row['count'],
          // Optional contact details; only included when the provider sets them.
          'phone' => $row['phone'] ?? NULL,
          'website' => $row['website'] ?? NULL,
          'opening_hours' => $row['opening_hours'] ?? NULL,
        ],
      ];
    }
    return [
      'type' => 'FeatureCollection',
      'features' => $features,
    ];
  }

  /**
   * Returns the current time for cache expiry.
   *
   * @return int
   */
  protected function time() {
    return \Drupal::time()->getCurrentTime();
  }

}