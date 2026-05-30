<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;

/**
 * Base class for a single Build Spec row mapped to Drupal configuration.
 *
 * Concrete record subclasses parse a single CSV row into typed properties in
 * their constructor (no side effects), and expose the four contract methods
 * — configExists(), createConfig(), deleteConfig(), relatedConfigs() — that
 * the Drush command invokes once the row has been built.
 *
 * Services that touch the Drupal container (entity type manager, plugin
 * managers, transliteration, etc.) are constructor-injected via
 * \Drupal\drupal_site_builder\RecordFactory, never resolved with
 * \Drupal::service() inside the records themselves.
 */
abstract class BuildSpecRecord {

  /**
   * Holds the operation code (delete | skip | create-or-update) for this row.
   *
   * Subclasses set this from their constructor via Operation::fromColumn().
   */
  public int $operation;

  /**
   * Constructs a record with the services every subclass needs.
   *
   * Concrete subclasses extend this constructor to inject the additional
   * services they require (for example field plugin managers for FieldRecord,
   * file system for MigrationRecord). The transliteration service is shared
   * by every record because machineName() lives on the base class.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Transliteration service used by machineName().
   */
  public function __construct(
    protected readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * Calls methods that create/update configs related to primary settings.
   *
   * For example, this method instantiates the creation of entity_form and
   * entity_view displays, translations and path alias for node bundles.
   *
   * @return mixed
   *   The result of the related configuration operations.
   */
  abstract public function relatedConfigs();

  /**
   * Checks for the existence of a configuration.
   *
   * @return bool
   *   TRUE if the configuration already exists, FALSE otherwise.
   */
  abstract public function configExists(): bool;

  /**
   * Creates a new configuration defined by the row in the build spec.
   *
   * @return mixed
   *   The created configuration entity or operation result.
   */
  abstract public function createConfig();

  /**
   * Deletes an existing configuration defined by the row in the build spec.
   *
   * @return mixed
   *   The result of the delete operation.
   */
  abstract public function deleteConfig();

  /**
   * Returns an associative array of properties printed in the summary.
   *
   * @param bool $array_keys
   *   If true, only the array keys are returned to facilitate a header row.
   *
   * @return array
   *   The report row, keyed by column label.
   */
  abstract public function getReport(bool $array_keys = FALSE): array;

  /**
   * Generates a Drupal machine name from an arbitrary label.
   *
   * @param string $value
   *   The human-readable label to transliterate.
   *
   * @return string
   *   The sanitised machine name, truncated to 32 characters.
   */
  public function machineName(string $value): string {
    $machine_name = strtolower($this->transliteration->transliterate($value, 'en', '_'));
    $machine_name = str_replace(' ', '_', $machine_name);
    $machine_name = preg_replace('/[^\w]+/', '', $machine_name);
    $machine_name = preg_replace('/_+/', '_', $machine_name);

    return mb_substr($machine_name, 0, 32);
  }

  /**
   * Converts the value provided by the Build Spec into an integer.
   *
   * Thin shim around \Drupal\drupal_site_builder\Operation::fromColumn() so
   * subclass call sites can keep their existing $this->getOperation() form.
   *
   * @param string $record_x
   *   The one-letter value provided by the Build Spec.
   *
   * @return int
   *   -1 (delete) | 0 (ignore) | 1 (create/update)
   */
  public function getOperation(string $record_x): int {
    return Operation::fromColumn($record_x);
  }

}
