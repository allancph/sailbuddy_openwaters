<?php

namespace Drupal\sailbuddy_poi;

/**
 * Defines the interface for POI provider plugins.
 *
 * A provider turns a geographic bounding box into a list of normalized POIs,
 * ready to be serialized as GeoJSON by the PoiController.
 */
interface PoiProviderInterface {

  /**
   * Returns the provider plugin ID.
   *
   * @return string
   */
  public function getId();

  /**
   * Returns the human-readable provider label.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  public function getLabel();

  /**
   * Fetches POIs for the given bounding box.
   *
   * @param array $bbox
   *   Associative array with numeric keys: north, south, west, east.
   * @param int $zoom
   *   Map zoom level at the time of the request.
   *
   * @return array
   *   List of normalized POI rows. Each row has: id, lat, lng, name, type,
   *   icon, webview and count keys.
   */
  public function fetch(array $bbox, int $zoom);

}