<?php

namespace Drupal\sailbuddy_poi;

use Drupal\Core\Database\Connection;

/**
 * Durable GeoJSON cache for POI buckets.
 *
 * Stored in a dedicated database table instead of the Drupal cache so data
 * survives cache rebuilds (drush cr / cache:clear default). Together with the
 * serve-stale fallback this keeps the POI layer alive even when a provider
 * outage coincides with a fresh cache container.
 */
class PoiCache {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new PoiCache.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Returns fresh cached data for a bucket, NULL when absent or expired.
   *
   * @param string $bucket
   *   Bucket key.
   *
   * @return array|null
   *   Decoded collection, or NULL.
   */
  public function get($bucket) {
    $row = $this->load($bucket);
    if ($row === FALSE || (int) $row['expire'] <= \Drupal::time()->getCurrentTime()) {
      return NULL;
    }
    return $this->decode($row['data']);
  }

  /**
   * Returns cached data for a bucket even when expired, NULL when absent.
   *
   * @param string $bucket
   *   Bucket key.
   *
   * @return array|null
   *   Decoded collection, or NULL.
   */
  public function getStale($bucket) {
    $row = $this->load($bucket);
    if ($row === FALSE) {
      return NULL;
    }
    return $this->decode($row['data']);
  }

  /**
   * Stores a collection for a bucket and garbage-collects expired rows.
   *
   * @param string $bucket
   *   Bucket key.
   * @param array $data
   *   GeoJSON FeatureCollection.
   * @param int $ttl_seconds
   *   Time to live in seconds.
   */
  public function set($bucket, array $data, $ttl_seconds) {
    $now = \Drupal::time()->getCurrentTime();
    $this->database->upsert('sailbuddy_poi_cache')
      ->key('bucket')
      ->fields([
        'bucket' => $bucket,
        'data' => serialize($data),
        'created' => $now,
        'expire' => $now + $ttl_seconds,
      ])
      ->execute();
    // Opportunistic GC keeps the table bounded; row count is tiny in practice.
    $this->database->delete('sailbuddy_poi_cache')
      ->condition('expire', $now, '<')
      ->execute();
  }

  /**
   * Loads a raw row for a bucket.
   *
   * @param string $bucket
   *   Bucket key.
   *
   * @return array|false
   *   Associative row, or FALSE.
   */
  protected function load($bucket) {
    return $this->database->select('sailbuddy_poi_cache', 'c')
      ->fields('c', ['data', 'expire'])
      ->condition('bucket', $bucket)
      ->execute()
      ->fetchAssoc();
  }

  /**
   * Unserializes stored data.
   *
   * @param string $blob
   *   Serialized data.
   *
   * @return array|null
   *   Decoded collection, or NULL when not a usable array.
   */
  protected function decode($blob) {
    if (!is_string($blob)) {
      return NULL;
    }
    $data = @unserialize($blob);
    return is_array($data) ? $data : NULL;
  }

}