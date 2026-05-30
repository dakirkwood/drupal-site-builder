<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drush\Drush;
use League\Csv\Reader;

/**
 * Console debugging helpers for Build Spec record classes.
 *
 * Strictly diagnostic. The trait is opt-in (used only by a handful of record
 * classes) and never participates in the production code path — it exists so
 * the operator can inspect a single CSV row while iterating on a build.
 */
trait SbConsoleDebug {

  /**
   * Prints a variable to the Drush console.
   *
   * @param mixed $var
   *   The value to print.
   */
  public function debug(mixed $var): void {
    $output = Drush::output();
    if (in_array(gettype($var), ['array', 'object'])) {
      $output->writeln(print_r($var, TRUE));
    }
    else {
      $output->writeln($var);
    }
  }

  /**
   * Returns a single Build Spec row matching the given machine name.
   *
   * Failures are intentionally not caught — this helper exists so the operator
   * can see what is going wrong while debugging a build, not to swallow it.
   *
   * @param string $filepath
   *   Path to the CSV file.
   * @param string $search_value
   *   The machine name to look for.
   *
   * @return array
   *   The matching row, or an empty array if none is found.
   */
  public function debugRecord(string $filepath, string $search_value): array {
    $csv = Reader::createFromPath($filepath);
    $csv->setHeaderOffset(0);
    $records = $csv->getRecords();

    foreach ($records as $record) {
      if ($record['Machine name'] == $search_value) {
        return $record;
      }
    }

    return [];
  }

}
