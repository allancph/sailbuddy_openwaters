<?php

namespace Drupal\sailbuddy_map\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves OpenWaters style JSON sanitized for the bundled MapLibre version.
 */
final class SailbuddyMapStyleController extends ControllerBase {

  /**
   * Constructs a SailbuddyMapStyleController.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly \GuzzleHttp\ClientInterface $httpClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('cache.data'),
      $container->get('http_client'),
    );
  }

  /**
   * Returns a sanitized OpenWaters style for a chart name.
   *
   * Strip paint properties not supported by the bundled MapLibre version
   * (resampling was added in MapLibre 5.20.0; leaflet ships 5.18.0).
   *
   * @param string $chart
   *   OpenWaters chart name, e.g. "seamap".
   */
  public function build(string $chart = 'seamap'): JsonResponse {
    $cid = 'sailbuddy_map:style:' . $chart;

    if ($cached = $this->cache->get($cid)) {
      if (!empty($cached->data)) {
        return new JsonResponse($cached->data);
      }
    }

    $style = NULL;
    try {
      $response = $this->httpClient->get('https://tiles.openwaters.io/' . $chart . '/style.json', [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $style = Json::decode((string) $response->getBody());
    }
    catch (\Throwable $e) {
      watchdog_exception('sailbuddy_map', $e);
      $style = NULL;
    }

    if (!is_array($style) || empty($style['layers'])) {
      return new JsonResponse([
        'version' => 8,
        'sources' => [],
        'layers' => [
          [
            'id' => 'background',
            'type' => 'background',
            'paint' => ['background-color' => '#1a2b52'],
          ],
        ],
      ]);
    }

    // OpenWaters fonts and sprites are CORS enabled and work with the correct
    // font stack names, so glyphs/sprite are kept as-is. Only adjust the style
    // where the bundled MapLibre version (< 5.20) would fail to parse:
    //  - "resampling" paint property (added in MapLibre 5.20.0).
    //  - ["format", <text>, []] expression where the options array is empty
    //    (MapLibre 5.18 requires at least one element in the options array).
    $sanitize = function (array &$value) use (&$sanitize): void {
      if (isset($value['paint']) && is_array($value['paint'])) {
        unset($value['paint']['resampling']);
      }
      // ["format", text1, opts1, text2, opts2, ...]: opts positions (even
      // indices >= 2) must not be empty arrays in MapLibre < 5.20.
      if (($value[0] ?? NULL) === 'format') {
        foreach ($value as $idx => &$seg) {
          if ($idx >= 2 && $idx % 2 === 0 && $seg === []) {
            $seg = new \stdClass();
          }
        }
        unset($seg);
      }
      foreach ($value as &$item) {
        if (is_array($item)) {
          $sanitize($item);
        }
      }
      unset($item);
    };
    $sanitize($style);

    $this->cache->set($cid, $style, 3600);
    return new JsonResponse($style);
  }

}