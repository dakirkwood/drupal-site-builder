<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

/**
 * Centralises the Build Spec "X" column to operation-code mapping.
 *
 * The operation contract is defined in the project manifesto: the X column
 * decides whether a row is created/updated, deleted, or skipped. The integer
 * codes returned here are stable contract values consumed by the Drush command
 * and by every record class:
 *
 *   1  → CREATE_OR_UPDATE (default for any value not matched below)
 *   0  → SKIP             (X is one of x, w, h)
 *  -1  → DELETE           (X is d)
 *
 * Keeping the mapping in one place means a change to the contract is a single
 * edit, and every consumer (records, command, tests) sees the same answer.
 */
final class Operation {

  /**
   * Operation code for "create the config, or update an existing config".
   */
  public const int CREATE_OR_UPDATE = 1;

  /**
   * Operation code for "skip this row, no action".
   */
  public const int SKIP = 0;

  /**
   * Operation code for "delete an existing config".
   */
  public const int DELETE = -1;

  /**
   * Maps a Build Spec X column value to its operation code.
   *
   * @param string $x
   *   The raw value of the X column for a single Build Spec row.
   *
   * @return int
   *   One of self::CREATE_OR_UPDATE, self::SKIP, self::DELETE.
   */
  public static function fromColumn(string $x): int {
    return match ($x) {
      'd' => self::DELETE,
      'x', 'w', 'h' => self::SKIP,
      default => self::CREATE_OR_UPDATE,
    };
  }

}
