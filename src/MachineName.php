<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

/**
 * Generates Drupal machine names from human-readable labels.
 */
class MachineName {

  /**
   * Converts a label into a Drupal machine name.
   *
   * @param string $label
   *   The human-readable label.
   *
   * @return string
   *   A lowercased, underscore-delimited machine name capped at 32 characters.
   */
  public static function generate(string $label): string {
    return substr(
      strtolower(
        preg_replace('/\W/', '_', $label)
      ),
      0,
      32
    );
  }

}
