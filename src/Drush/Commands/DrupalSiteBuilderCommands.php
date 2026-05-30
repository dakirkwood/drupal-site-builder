<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\drupal_site_builder\BundleRecord;
use Drupal\drupal_site_builder\FieldRecord;
use Drupal\drupal_site_builder\Operation;
use Drupal\drupal_site_builder\RecordFactory;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use League\Csv\Reader;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Drush command that drives the Build Spec → Drupal config translation.
 *
 * The command delegates all per-row work to a RecordFactory-built record;
 * this class is intentionally a thin orchestrator (read the CSV, decide which
 * action to take, render the summary table). No \Drupal::service() calls are
 * made here — every service is constructor-injected.
 */
final class DrupalSiteBuilderCommands extends DrushCommands {

  /**
   * Contains all the $report arrays.
   *
   * @var array
   *
   * Provides confirmation to the user about what the command did.
   * Output as a Drush table.
   *
   * @see printSummaryTable()
   */
  protected array $summary = [];

  /**
   * The current grouping key used to separate summary table sections.
   *
   * @var string
   */
  protected string $summaryGroup = '';

  /**
   * Tracks the weight of the current field within its bundle group.
   */
  protected int $fieldPosition;

  /**
   * Constructs a DrupalSiteBuilderCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Used by importFinish() to walk content entity types.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   Used by importFinish() to enumerate bundles per entity type.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entityDisplayRepository
   *   Used by importFinish() to look up form displays per bundle.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Used by importFinish() to look up base field definitions per bundle.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Used by importFinish() to read drupal_site_builder.settings.
   * @param \Drupal\drupal_site_builder\RecordFactory $recordFactory
   *   Builds the BuildSpecRecord subclass for each row.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RecordFactory $recordFactory,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_display.repository'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('drupal_site_builder.record_factory'),
    );
  }

  /**
   * Imports the site-configuration information exported from the Build Spec.
   */
  #[CLI\Command(name: 'drupal_site_builder:import', aliases: ['dsbi'])]
  #[CLI\Argument(name: 'file', description: 'The path to the CSV file (relative to the Drupal root) exported from the Build Spec.')]
  #[CLI\Option(name: 'type', description: 'The config type. \'bundles\' | \'fields\' | \'img-styles\' | \'reimg-styles\' | \'view-modes\' | \'views\' | \'views-displays\' | \'migrations\' | \'wf\' | \'wf-states\' | \'wf-trans\' | \'user-roles\'.')]
  #[CLI\Usage(name: 'drupal_site_builder:import dsbi', description: '/path/to/bundles.csv --type=bundles')]
  public function buildSpecImport(
    string $file,
    array $options = [
      'type' => 'bundles',
      'id' => self::REQ,
      'fieldId' => self::REQ,
    ],
  ): void {
    // Validate --type early so failures are loud and listed.
    $allowed_values = RecordFactory::allowedTypes();
    if (!in_array(strtolower($options['type']), $allowed_values, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Invalid type option "%s". Allowed values are %s.',
        $options['type'],
        implode(', ', $allowed_values),
      ));
    }

    // Backup the database before creating nodes.
    try {
      $this->createDatabaseDump();
    }
    catch (\Exception $exception) {
      if (!$this->io()->confirm('Database backup failed. Do you want to continue?', FALSE)) {
        return;
      }
    }

    if (isset($options['id']) || isset($options['fieldId'])) {
      $id = $options['id'] ?? $options['fieldId'];
      $records = $this->processSingleRecord($file, $id);
      $records_count = 1;
    }
    else {
      $records = $this->getBuildSpecRecords($file);
      // Loud failure: the CSV could not be read. getBuildSpecRecords() has
      // already logged the underlying exception with class+method context.
      if ($records === FALSE) {
        throw new \RuntimeException(sprintf('Unable to read Build Spec CSV: %s', $file));
      }
      // Create a new progress bar.
      $records_count = iterator_count($records);
      $records->rewind();
    }

    $progressBar = $this->io()->createProgressBar($records_count);
    // Start and display the progress bar.
    $progressBar->start();
    $report_headers = [];

    foreach ($records as $record) {
      // Ignore spacer rows.
      if (isset($record['Machine name']) && empty($record['Machine name'])) {
        $progressBar->advance();
        continue;
      }
      // Ignore rows that don't require any action.
      if ($record['X'] == 'x') {
        $progressBar->advance();
        continue;
      }
      // Ignore rows that don't have a proper machine name.
      if ($options['type'] != 'view-modes' && $record['Machine name'] == '-') {
        $progressBar->advance();
        continue;
      }

      // Build the record via the factory — collapses the per-type switch.
      $row = $this->recordFactory->create($options['type'], $record);

      // Maintain the per-summary grouping (visual separators in the table).
      $this->applyGrouping($options['type'], $row);

      // Create/Delete the bundle as indicated in the spec.
      switch ($row->operation) {
        // Delete the config if it exists.
        case Operation::DELETE:
          if ($row->configExists()) {
            $row->deleteConfig();
          }
          break;

        default:
          if (!$row->configExists()) {
            $row->createConfig();
          }
          $row->relatedConfigs();
      }

      // Set the report headers.
      if (empty($report_headers)) {
        $report_headers = $row->getReport(TRUE);
      }

      // Add the report to the summary and advance the progress bar.
      $this->summary[] = $row->getReport();
      if (isset($this->fieldPosition)) {
        $this->fieldPosition++;
      }
      $progressBar->advance();
    }

    // Finish the progress bar and print the summary.
    $progressBar->finish();
    $this->printSummaryTable($report_headers);
  }

  /**
   * Maintains the table-separator grouping that the legacy switch handled.
   *
   * Bundle records group by entity type, field records group by bundle and
   * also reset the field weight counter. Other types do not group.
   *
   * @param string $type
   *   The Drush --type argument.
   * @param object $row
   *   The freshly-built record.
   */
  private function applyGrouping(string $type, object $row): void {
    if ($type === 'bundles' && $row instanceof BundleRecord) {
      if ($this->summaryGroup !== $row->entity) {
        if ($this->summaryGroup !== '') {
          $this->summary[] = new TableSeparator();
        }
        $this->summaryGroup = $row->entity;
      }
      return;
    }
    if ($type === 'fields' && $row instanceof FieldRecord) {
      if ($this->summaryGroup !== $row->bundle) {
        if ($this->summaryGroup !== '') {
          $this->summary[] = new TableSeparator();
        }
        $this->summaryGroup = $row->bundle;
        $this->fieldPosition = 0;
      }
      $row->setFieldWeight($this->fieldPosition);
    }
  }

  /**
   * Gives user the option of backing up the database before proceeding.
   *
   * @throws \Exception
   */
  protected function createDatabaseDump(): void {
    $this->io()->writeln("Preparing to back up the database...");

    // Confirm with the user if they want to proceed with the database backup.
    if (!$this->io()->confirm('Do you want to preserve the current state of your database?', TRUE)) {
      $this->io()->writeln("Skipping database backup.");
      return;
    }

    $dump_file = DRUPAL_ROOT . '/../ecsb_' . date('ymd_Hi') . '.sql';
    $this->io()->writeln("Creating database dump at {$dump_file}. (This may take a while depending on size)");

    // Execute the sql:dump command.
    $process = new Process([
      'drush', 'sql:dump', '--result-file=' . $dump_file,
    ]);

    // Wait for the process to finish and handle errors.
    try {
      $process->mustRun();
    }
    catch (ProcessFailedException $e) {
      $this->logger()->error("Failed to create database dump: " . $e->getMessage());
      throw new \Exception('Database backup failed, terminating the command.');
    }
  }

  /**
   * Reads the Build Spec CSV file and returns an iterator over its rows.
   *
   * @param string $filepath
   *   Path to the CSV file, relative to the Drupal root.
   *
   * @return false|\Iterator
   *   An iterator over the CSV rows, or FALSE if the file cannot be read.
   */
  protected function getBuildSpecRecords(string $filepath) {
    // Get the file with the data.
    try {
      $csv = Reader::createFromPath($filepath, 'r');
      // Set the CSV header offset.
      $csv->setHeaderOffset(0);
      // Get the header row.
      $header = $csv->getHeader();
      // Return all the records from the CSV source data file.
      return $csv->getRecords($header);
    }
    catch (\Exception $exception) {
      $this->logger()->error('DrupalSiteBuilderCommands::getBuildSpecRecords(): @message', ['@message' => $exception->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Outputs a table summarizing what the command did.
   *
   * @param array $headers
   *   The column headers for the summary table.
   */
  protected function printSummaryTable(array $headers): void {
    $this->io()->text("\n");
    $this->io()->table($headers, $this->summary);
  }

  /**
   * Finds a single Build Spec row matching a machine name or bundle/field.
   *
   * @param string $filepath
   *   Path to the CSV file, relative to the Drupal root.
   * @param string $search_value
   *   The machine name, or a "bundle/field_name" pair, to search for.
   *
   * @return array
   *   A single-element array containing the matching row, or an empty array.
   */
  protected function processSingleRecord(string $filepath, string $search_value): array {
    if (preg_match('/(\w+)\/(\w+)/', $search_value, $search_id)) {
      $search_values = [
        'bundle' => $search_id[1],
        'field_name' => $search_id[2],
      ];
    }

    try {
      $records = $this->getBuildSpecRecords($filepath);
      if ($records === FALSE) {
        return [];
      }
      foreach ($records as $record) {
        if (isset($search_values)) {
          if (
            $record['Bundle machine name'] == $search_values['bundle'] &&
            $record['Machine name'] == $search_values['field_name']
          ) {
            return [$record];
          }
        }
        elseif ($record['Machine name'] == $search_value) {
          return [$record];
        }
      }
    }
    catch (\Exception $exception) {
      $this->logger()->error('DrupalSiteBuilderCommands::processSingleRecord(): @message', ['@message' => $exception->getMessage()]);
      return [];
    }

    $this->logger()->warning('No record matching "@id" was found in @file.', [
      '@id' => $search_value,
      '@file' => $filepath,
    ]);
    return [];
  }

  /**
   * Creates the base field overrides typically set in an EC build.
   */
  #[CLI\Command(name: 'drupal_site_builder:finish', aliases: ['dsbf'])]
  public function importFinish(): void {
    // Get the module settings about which fields to act on.
    $config = $this->configFactory->get('drupal_site_builder.settings');
    // Fields to disable in the form_display.
    $disableFields = $config->get('disableFields');
    // Base fields that don't require translation.
    $noTranslateFields = $config->get('nonTranslatableFields');

    // Get the list of entity types in the system.
    $entityTypes = $this->entityTypeManager->getDefinitions();
    // Start the progress bar.
    $progressBar = $this->io()->createProgressBar(count($entityTypes));
    $progressBar->start();

    foreach ($entityTypes as $entityTypeId => $entityType) {
      // Only act on content entities.
      if ($entityType->getGroup() == 'content') {
        // Disable base fields in the form display.
        if (isset($disableFields[$entityTypeId]) && !empty($disableFields[$entityTypeId])) {
          $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityType->id());
          // Iterate through the bundles.
          foreach (array_keys($bundles) as $bundle_id) {
            $formDisplay = $this->entityDisplayRepository->getFormDisplay($entityTypeId, $bundle_id);
            // Hide each field from the form display of the current bundle.
            foreach ($disableFields[$entityTypeId] as $field) {
              if ($formDisplay->getComponent($field)) {
                $formDisplay->removeComponent($field)->save();
              }
            }
            // Disable translations on the base fields of the current bundle.
            foreach ($noTranslateFields[$entityTypeId] as $nt_field) {
              $field_definition = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle_id)[$nt_field];
              if ($field_definition instanceof BaseFieldDefinition) {
                BaseFieldOverride::createFromBaseFieldDefinition($field_definition, $bundle_id)
                  ->setTranslatable(FALSE)
                  ->save();
              }
            }
            // Enable Layout Builder on the current bundle.
            if ($entityTypeId == 'node') {
              $entity_view_display = EntityViewDisplay::load("node.$bundle_id.default");
              $entity_view_display->setComponent('layout_builder__layout')
                ->setThirdPartySetting('layout_builder', 'enabled', TRUE)
                ->setThirdPartySetting('layout_builder', 'allow_custom', FALSE);
              $entity_view_display->save();
            }
          }
        }
      }
      $progressBar->advance();
    }
    $progressBar->finish();
  }

}
