<?php

namespace Drupal\sailbuddy_poi\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a POI provider plugin annotation object.
 *
 * @Annotation
 */
class PoiProvider extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name of the provider.
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $label;

}