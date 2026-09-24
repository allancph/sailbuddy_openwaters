<?php

namespace Drupal\sailbuddy_map\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proxies Mapillary API v4 data for the harbour photo overlay, keeping the
 * access token server-side (never exposed in page HTML).
 */
final class SailbuddyMapillaryController extends ControllerBase {

  /**
   * Mapillary Graph API v4 base URL.
   */
  private const MAPILLARY_API = 'https://graph.mapillary.com';

  /**
   * Constructs a SailbuddyMapillaryController.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly \GuzzleHttp\ClientInterface $httpClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('cache.data'),
      $container->get('http_client'),
    );
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * Maps a Drupal cache ID to a normalized bbox key.
   */
  private function bboxCid(string $bbox): string {
    return 'sailbuddy_map:mapillary:photos:' . md5($bbox);
  }

  /**
   * Returns Photo GeoJSON (points + tracks) for a bbox.
   *
   * Query params:
   *  - bbox: "left,bottom,right,top" (lon, lat).
   *
   * Response is a GeoJSON FeatureCollection:
   *  - Point features: photo positions (properties: id, sequence,
   *    captured_at, is_pano, date, thumb).
   *  - MultiPoint features: joined track points grouped by sequence.
   *
   * Photos without a live thumbnail (deleted/private content) are skipped so
   * the popup never points at dead URLs.
   *
   * Cached in cache.data for 10 minutes.
   */
  public function photos(Request $request): JsonResponse {
    $bbox = (string) $request->query->get('bbox', '');
    if (!preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $bbox)) {
      return new JsonResponse(['error' => 'invalid bbox'], 400);
    }

    [$left, $bottom, $right, $top] = array_map('floatval', explode(',', $bbox));

    // Guard against absurdly large queries.
    $maxSpan = 0.5;
    if ($right - $left > $maxSpan || $top - $bottom > $maxSpan) {
      return new JsonResponse(['error' => 'bbox too large'], 400);
    }

    $cid = $this->bboxCid($bbox);
    if ($cached = $this->cache->get($cid)) {
      if (!empty($cached->data)) {
        return new JsonResponse($cached->data, 200, ['X-Sailbuddy-Cache' => 'HIT']);
      }
    }

    $token = (string) $this->configFactory->get('mapillary.settings')->get('access_token');
    if ($token === '') {
      return new JsonResponse(['type' => 'FeatureCollection', 'features' => [], 'error' => 'no token'], 500);
    }

    $url = self::MAPILLARY_API . '/images?' . http_build_query([
      'bbox' => $bbox,
      'fields' => 'id,geometry,sequence,captured_at,is_pano,thumb_1024_url',
      'limit' => 500,
      'access_token' => $token,
    ]);

    $raw = NULL;
    try {
      $response = $this->httpClient->get($url, [
        'timeout' => 20,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $raw = Json::decode((string) $response->getBody());
    }
    catch (\Throwable $e) {
      watchdog_exception('sailbuddy_map', $e);
      return new JsonResponse(['type' => 'FeatureCollection', 'features' => [], 'error' => 'mapillary request failed'], 502);
    }

    $images = $raw['data'] ?? [];
    if (!is_array($images) || !$images) {
      return new JsonResponse(['type' => 'FeatureCollection', 'features' => []], 200);
    }

    // Group by sequence, preserving captured_at order so tracks are drawn
    // in chronological order.
    $bySequence = [];
    foreach ($images as $image) {
      $seq = (string) ($image['sequence'] ?? 'n/a');
      $bySequence[$seq][] = $image;
    }

    $features = [];
    foreach ($bySequence as $seq => $list) {
      usort($list, static fn ($a, $b) => (int) ($a['captured_at'] ?? 0) <=> (int) ($b['captured_at'] ?? 0));

      // Cap points per sequence to keep the map readable on dense roads.
      $maxPerSeq = 80;
      if (count($list) > $maxPerSeq) {
        $step = (int) ceil(count($list) / $maxPerSeq);
        $list = array_values(array_filter($list, static fn ($_, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
      }

      $coords = [];
      foreach ($list as $image) {
        $geometry = $image['geometry'] ?? NULL;
        if (!is_array($geometry) || ($geometry['type'] ?? '') !== 'Point') {
          continue;
        }
        $thumb = $image['thumb_1024_url'] ?? NULL;
        // Skip images without a live thumbnail (deleted/private content) so
        // the popup never points at dead URLs.
        if (!is_string($thumb) || $thumb === '') {
          continue;
        }
        $coords[] = $geometry['coordinates'];

        $captured = (int) ($image['captured_at'] ?? 0);
        $date = $captured ? date('d-m-Y', (int) floor($captured / 1000)) : '';
        $features[] = [
          'type' => 'Feature',
          'geometry' => $geometry,
          'properties' => [
            'kind' => 'photo',
            'id' => (string) ($image['id'] ?? ''),
            'sequence' => $seq,
            'captured_at' => $captured,
            'date' => $date,
            'is_pano' => (bool) ($image['is_pano'] ?? FALSE),
            'thumb' => $thumb,
          ],
        ];
      }

      if (count($coords) >= 2) {
        $features[] = [
          'type' => 'Feature',
          'geometry' => [
            'type' => 'LineString',
            'coordinates' => $coords,
          ],
          'properties' => [
            'kind' => 'track',
            'sequence' => $seq,
          ],
        ];
      }
    }

    $payload = ['type' => 'FeatureCollection', 'features' => $features];
    $this->cache->set($cid, $payload, time() + 600);
    return new JsonResponse($payload, 200, ['X-Sailbuddy-Cache' => 'MISS']);
  }

}
