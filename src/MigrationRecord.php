<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;

/**
 * Represents a single migration row from the Build Spec.
 *
 * Generates a migration YAML definition for the companion citizen_migrate
 * module from the Build Spec's migration and field-mapping data.
 */
class MigrationRecord extends BuildSpecRecord {

  /**
   * Rows describing the migrations to delete, indexed numerically.
   *
   * @var array
   */
  protected array $migrationData = [];

  /**
   * Field-mapping rows loaded from the migration mappings CSV.
   *
   * @var array
   */
  protected array $mappingData;

  /**
   * The migration machine name.
   *
   * @var string
   */
  public string $id;

  /**
   * The human-readable migration label.
   *
   * @var string
   */
  public string $label;

  /**
   * The migration source plugin ID.
   *
   * @var string
   */
  public string $sourcePlugin;

  /**
   * The migration destination plugin ID.
   *
   * @var string
   */
  public string $destinationPlugin;

  /**
   * The default destination bundle for migrated entities.
   *
   * @var string
   */
  public string $defaultBundle;

  /**
   * The migration tags.
   *
   * @var array
   */
  public array $tags;

  /**
   * The required and optional migration dependencies.
   *
   * @var array
   */
  public array $dependencies;

  /**
   * Constructs a MigrationRecord from a Build Spec row.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Shared transliteration service for machineName().
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Used by createConfig() to write the generated YAML through Drupal's
   *   file API instead of a raw file_put_contents().
   * @param \Psr\Log\LoggerInterface $logger
   *   Module-channel logger for loud failures.
   * @param array $record
   *   The CSV row keyed by column header.
   */
  public function __construct(
    TransliterationInterface $transliteration,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
    array $record,
  ) {
    parent::__construct($transliteration);
    $mapping_file = DRUPAL_ROOT . '/../config/source_data/migration_mappings.csv';
    $this->mappingData = $this->loadCsv($mapping_file);

    $this->id = $record['Machine name'];
    $this->label = $record['Label'];
    $this->operation = Operation::fromColumn($record['X']);
    $this->sourcePlugin = $this->getPlugin($record['Source plugin']);
    $this->destinationPlugin = $this->getPlugin($record['Destination plugin']);
    $this->defaultBundle = $record['Default bundle'];
    $this->tags = explode(', ', $record['Tags']);
    $this->dependencies = [
      'required' => !empty($record['Depend req']) ? explode(', ', $record['Depend req']) : [],
      'optional' => !empty($record['Depend opt']) ? explode(', ', $record['Depend opt']) : [],
    ];
  }

  /**
   * Loads a CSV file into a numerically-indexed array of rows.
   *
   * @param string $file_path
   *   Absolute path to the CSV file.
   *
   * @return array
   *   The parsed rows, or an empty array if the file is missing or unreadable.
   */
  protected function loadCsv(string $file_path): array {
    try {
      if (!file_exists($file_path)) {
        throw new \Exception("The file at path {$file_path} does not exist.");
      }

      $lines = file($file_path);
      if ($lines === FALSE) {
        throw new \Exception("Unable to read the file at path {$file_path}.");
      }
      $csv_data = array_map('str_getcsv', $lines);

      if (empty($csv_data)) {
        throw new \Exception("The file at path {$file_path} is empty or unreadable.");
      }

      return $csv_data;
    }
    catch (\Exception $e) {
      $this->logger->error('MigrationRecord::loadCsv(): @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function configExists(): bool {
    $file_path = DRUPAL_ROOT . "/modules/custom/citizen_migrate/migrations/{$this->id}.yml";
    return file_exists($file_path);
  }

  /**
   * Isolates the plugin ID from the value provided by the Build Spec.
   *
   * @param string $value
   *   The Build Spec value, e.g. "CSV (csv)".
   *
   * @return string
   *   The plugin ID, or an empty string if none is found.
   */
  public function getPlugin(string $value): string {
    preg_match('/[\w+\s]\(([\w:\s_]+)\)/', $value, $plugin);
    return $plugin[1] ?? '';
  }

  /**
   * Writes the migration YAML definition for this row.
   */
  public function createConfig(): void {
    $yaml_content = [
      'id' => $this->id,
      'label' => $this->label,
      'migration_group' => 'ecm',
      'migration_tags' => $this->tags,
      'migration_dependencies' => $this->dependencies,
      'includes' => [],
      'source' => [
        'plugin' => $this->sourcePlugin,
      ],
      'destination' => [
        'plugin' => $this->destinationPlugin,
        'default_bundle' => $this->defaultBundle,
      ],
      'process' => $this->getFieldMappings($this->id),
    ];

    $file_path = DRUPAL_ROOT . "/modules/custom/citizen_migrate/migrations/{$this->id}.yml";
    $this->writeYaml($file_path, $yaml_content);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteConfig(): void {
    foreach ($this->migrationData as $migration) {
      $machine_name = $migration[2];
      $file_path = "web/modules/custom/citizen_migrate/migrations/{$machine_name}.yml";
      if (file_exists($file_path)) {
        // Use Drupal's file API so the delete is observable to file_managed
        // listeners and consistent with the createConfig() write path.
        $this->fileSystem->delete($file_path);
      }
    }
  }

  /**
   * Builds the process (field mapping) section for the migration.
   *
   * @param string $migration_id
   *   The migration machine name to collect mappings for.
   * @param bool $return_string
   *   When TRUE, return a printable string instead of the mapping array.
   *
   * @return array|string
   *   The mappings keyed by destination property, or a printable string.
   */
  protected function getFieldMappings(string $migration_id, bool $return_string = FALSE): array|string {
    $mappings = [];
    foreach ($this->mappingData as $mapping) {
      if ($mapping[8] === $migration_id) {
        $destination_property = $mapping[2];
        $source_property = $mapping[6];
        $mappings[$destination_property] = $source_property;
      }
    }

    if ($return_string) {
      $mapping_strings = array_map(function ($key, $value) {
        return "$key -> $value";
      }, array_keys($mappings), $mappings);

      return implode("\n", $mapping_strings);
    }

    return $mappings;
  }

  /**
   * Encodes an array as YAML and writes it through Drupal's file API.
   *
   * @param string $file_path
   *   Absolute path to the destination file.
   * @param array $yaml_content
   *   The data to encode.
   */
  protected function writeYaml(string $file_path, array $yaml_content): void {
    $yaml = Yaml::encode($yaml_content);
    // FileSystemInterface::saveData replaces existing files in place and
    // routes the write through Drupal's file API so listeners and the file
    // status registry stay consistent.
    $this->fileSystem->saveData($yaml, $file_path, FileExists::Replace);
  }

  /**
   * {@inheritdoc}
   */
  public function relatedConfigs(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [
      'migration_id' => $this->id,
      'label' => $this->label,
      'source_plugin' => $this->sourcePlugin,
      'dest_plugin' => $this->destinationPlugin,
      'process' => $this->getFieldMappings($this->id, TRUE),
    ];

    return $array_keys ? array_keys($report) : $report;
  }

}
