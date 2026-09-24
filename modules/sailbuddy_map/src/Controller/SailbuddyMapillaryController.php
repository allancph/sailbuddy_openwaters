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
 *
 * The Mapillary /images endpoint rejects bbox queries that cover "too much
 * data" (HTTP 500) regardless of the limit parameter. Dense harbours fail,
 * sparse ones succeed. To keep the layer working everywhere we split an
 * oversized bbox into quadrants and recurse until each sub-query is accepted.
 */
final class SailbuddyMapillaryController extends ControllerBase {

  private const MAPILLARY_API = 'https://graph.mapillary.com';

  private const MAX_SPLIT_DEPTH = 4;

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
   *  - LineString features: joined track points grouped by sequence.
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

    $token = (string) $this->configFactory->get('sailbuddy_map.settings')->get('mapillary_access_token');
    if ($token === '') {
      return new JsonResponse(['type' => 'FeatureCollection', 'features' => [], 'error' => 'no token'], 500);
    }

    // Fetch images, splitting oversized bboxes into quadrants that the
    // Mapillary API will accept (dense harbours otherwise return HTTP 500).
    $images = [];
    $this->fetchQuadrant($token, $left, $bottom, $right, $top, $images, 0);

    $payload = $this->buildFeatureCollection($images);
    $this->cache->set($cid, $payload, time() + 600);
    return new JsonResponse($payload, 200, ['X-Sailbuddy-Cache' => 'MISS']);
  }

  /**
   * Fetches images for a bbox, recursing into quadrants when the API refuses.
   *
   * @param string $token
   *   Mapillary access token.
   * @param float $left
   *   Western longitude.
   * @param float $bottom
   *   Southern latitude.
   * @param float $right
   *   Eastern longitude.
   * @param float $top
   *   Northern latitude.
   * @param array<int, array<string, mixed>> $images
   *   Accumulator filled with raw image rows.
   * @param int $depth
   *   Current recursion depth.
   */
  private function fetchQuadrant(string $token, float $left, float $bottom, float $right, float $top, array &$images, int $depth): void {
    $bbox = sprintf('%.6f,%.6f,%.6f,%.6f', $left, $bottom, $right, $top);
    $url = self::MAPILLARY_API . '/images?' . http_build_query([
      'bbox' => $bbox,
      'fields' => 'id,geometry,sequence,captured_at,is_pano,thumb_1024_url',
      'limit' => 500,
      'access_token' => $token,
    ]);

    try {
      $response = $this->httpClient->get($url, [
        'timeout' => 20,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $raw = Json::decode((string) $response->getBody());
      foreach (($raw['data'] ?? []) as $image) {
        if (is_array($image) && !empty($image['id'])) {
          $images[] = $image;
        }
      }
      return;
    }
    catch (\Throwable $e) {
      // Only recurse when the API refuses because of data volume. Other
      // failures (auth, network, rate limit) bubble up as-is.
      $body = '';
      if ($e instanceof \GuzzleHttp\Exception\BadResponseException) {
        $body = (string) $e->getResponse()->getBody();
      }
      if ($depth >= self::MAX_SPLIT_DEPTH || stripos($body, 'reduce the amount of data') === FALSE) {
        \Drupal::logger('sailbuddy_map')->error('Mapillary photos request failed: @msg', ['@msg' => $e->getMessage()]);
        return;
      }
    }

    // Split into four quadrants and retry each.
    $midLon = ($left + $right) / 2;
    $midLat = ($bottom + $top) / 2;
    $this->fetchQuadrant($token, $left, $midLat, $midLon, $top, $images, $depth + 1);
    $this->fetchQuadrant($token, $midLon, $midLat, $right, $top, $images, $depth + 1);
    $this->fetchQuadrant($token, $left, $bottom, $midLon, $midLat, $images, $depth + 1);
    $this->fetchQuadrant($token, $midLon, $bottom, $right, $midLat, $images, $depth + 1);
  }

  /**
   * Builds the GeoJSON FeatureCollection from raw Mapillary image rows.
   *
   * @param array<int, array<string, mixed>> $images
   *   Raw image rows (possibly duplicated across quadrant splits).
   *
   * @return array<string, mixed>
   *   The payload to cache/return.
   */
  private function buildFeatureCollection(array $images): array {
    $byId = [];
    foreach ($images as $image) {
      $id = (string) ($image['id'] ?? '');
      if ($id !== '' && !isset($byId[$id])) {
        $byId[$id] = $image;
      }
    }

    $bySequence = [];
    foreach ($byId as $image) {
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

    return ['type' => 'FeatureCollection', 'features' => $features];
  }

}