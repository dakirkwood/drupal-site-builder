<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the right BuildSpecRecord subclass for a Drush --type argument.
 *
 * The factory exists so that record classes never reach into the service
 * container themselves — every service a record needs is constructor-injected
 * by this factory. The Drush command takes the factory as a single dependency
 * and asks for a record per CSV row, which collapses what used to be a giant
 * switch statement in the command into a single call.
 *
 * Unknown --type values throw \InvalidArgumentException loudly (per the
 * Manifesto's "loud failures" guarantee) rather than returning NULL silently.
 */
final class RecordFactory {

  /**
   * Maps each supported --type value to the record class that handles it.
   *
   * Centralising the mapping makes the supported set explicit and easy to
   * inventory in error messages.
   */
  private const array TYPE_MAP = [
    'bundles' => BundleRecord::class,
    'fields' => FieldRecord::class,
    'img-styles' => ImgStyleRecord::class,
    'reimg-styles' => ResImgStyleRecord::class,
    'view-modes' => ViewModeRecord::class,
    'views' => ViewsRecord::class,
    'views-displays' => ViewsDisplaysRecord::class,
    'migrations' => MigrationRecord::class,
    'wf' => WorkflowRecord::class,
    'wf-states' => WorkflowStateRecord::class,
    'wf-trans' => WorkflowTransitionRecord::class,
    'user-roles' => UserRoleRecord::class,
  ];

  /**
   * Constructs a RecordFactory.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Used by every record's machineName() helper.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Used by BundleRecord for pathauto pattern lookups.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   Used by BundleRecord / FieldRecord / ViewModeRecord for bundle existence
   *   and reference-bundle resolution.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entityDisplayRepository
   *   Used by FieldRecord to load form and view displays.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $fieldTypeManager
   *   Used by FieldRecord to resolve field-type machine names.
   * @param \Drupal\Core\Field\WidgetPluginManager $widgetManager
   *   Used by FieldRecord to resolve form-widget machine names.
   * @param \Drupal\Core\Field\FormatterPluginManager $formatterManager
   *   Used by FieldRecord to resolve view-formatter machine names.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Used by MigrationRecord to write generated migration YAML.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Used by UserRoleRecord for user-visible status messages.
   * @param \Psr\Log\LoggerInterface $logger
   *   Module-channel logger; every record uses it for loud failures.
   */
  public function __construct(
    private readonly TransliterationInterface $transliteration,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly FieldTypePluginManagerInterface $fieldTypeManager,
    private readonly WidgetPluginManager $widgetManager,
    private readonly FormatterPluginManager $formatterManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds a record instance for a single Build Spec row.
   *
   * @param string $type
   *   The Drush --type argument, for example "bundles" or "fields".
   * @param array $row
   *   The CSV row, keyed by Build Spec column header.
   *
   * @return \Drupal\drupal_site_builder\BuildSpecRecord
   *   The fully-constructed record. The constructor only maps CSV columns to
   *   typed properties; any Drupal API call happens later, when the Drush
   *   command invokes configExists(), createConfig(), deleteConfig(), or
   *   relatedConfigs() on the returned record.
   *
   * @throws \InvalidArgumentException
   *   When $type is not one of the supported types.
   */
  public function create(string $type, array $row): BuildSpecRecord {
    return match ($type) {
      'bundles' => new BundleRecord(
        $this->transliteration,
        $this->entityTypeManager,
        $this->entityTypeBundleInfo,
        $this->logger,
        $row,
      ),
      'fields' => new FieldRecord(
        $this->transliteration,
        $this->entityTypeBundleInfo,
        $this->entityDisplayRepository,
        $this->fieldTypeManager,
        $this->widgetManager,
        $this->formatterManager,
        $this->logger,
        $row,
      ),
      'img-styles' => new ImgStyleRecord($this->transliteration, $row),
      'reimg-styles' => new ResImgStyleRecord($this->transliteration, $row),
      'view-modes' => new ViewModeRecord(
        $this->transliteration,
        $this->entityTypeBundleInfo,
        $row,
      ),
      'views' => new ViewsRecord($this->transliteration, $row),
      'views-displays' => new ViewsDisplaysRecord($this->transliteration, $row),
      'migrations' => new MigrationRecord(
        $this->transliteration,
        $this->fileSystem,
        $this->logger,
        $row,
      ),
      'wf' => new WorkflowRecord($this->transliteration, $row),
      'wf-states' => new WorkflowStateRecord($this->transliteration, $row),
      'wf-trans' => new WorkflowTransitionRecord($this->transliteration, $row),
      'user-roles' => new UserRoleRecord(
        $this->transliteration,
        $this->messenger,
        $row,
      ),
      default => throw new \InvalidArgumentException(sprintf(
        'Unknown record type "%s". Allowed values: %s.',
        $type,
        implode(', ', array_keys(self::TYPE_MAP)),
      )),
    };
  }

  /**
   * Returns the list of supported --type values.
   *
   * Used by the Drush command to validate --type before any work is done.
   *
   * @return list<string>
   *   The sorted list of allowed --type values.
   */
  public static function allowedTypes(): array {
    $types = array_keys(self::TYPE_MAP);
    sort($types);
    return $types;
  }

}
