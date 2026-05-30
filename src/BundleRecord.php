<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\taxonomy\Entity\Vocabulary;
use Psr\Log\LoggerInterface;

/**
 * Represents a single bundle row from the Build Spec.
 */
class BundleRecord extends BuildSpecRecord {
  use EcConsoleDebug;

  /**
   * The pathauto URL alias pattern for this bundle.
   *
   * @var string
   */
  public string $aliasPattern;

  /**
   * The bundle description.
   *
   * @var string
   */
  public string $description;

  /**
   * The entity type ID this is a bundle of.
   *
   * @var string
   */
  public string $entity;

  /**
   * The human-readable bundle label.
   *
   * @var string
   */
  public string $label;

  /**
   * Whether Layout Builder should be enabled for this bundle.
   *
   * @var bool
   */
  public bool $layoutBuilder;

  /**
   * The bundle machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * Whether metatags should be enabled for this bundle.
   *
   * @var bool
   */
  public bool $metatags;

  /**
   * Whether content of this bundle should be migrated.
   *
   * @var bool
   */
  public bool $migrate;

  /**
   * Whether content moderation should be enabled for this bundle.
   *
   * @var bool
   */
  public bool $moderated;

  /**
   * Whether scheduling should be enabled for this bundle.
   *
   * @var bool
   */
  public bool $schedule;

  /**
   * The search configuration value for this bundle.
   *
   * @var string
   */
  public string $search;

  /**
   * Whether translation should be enabled for this bundle.
   *
   * @var bool
   */
  public bool $translate;

  /**
   * The base values used to create the bundle config entity.
   *
   * Computed lazily by getBaseValues() so the constructor stays a pure
   * data-mapping step.
   *
   * @var array
   */
  public array $baseValues;

  /**
   * The accumulated summary report for this row.
   *
   * @var array
   */
  public array $report;

  /**
   * Converts the array of values in a build spec record into an object.
   *
   * Pure data mapping only — no Drupal API calls. Existence checks and base
   * value assembly happen in their own methods, invoked by the Drush command
   * (or tests) after construction.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Shared transliteration service for machineName().
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Used by getPathAliasPattern() to query existing pathauto patterns.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   Used by configExists() to query existing bundles.
   * @param \Psr\Log\LoggerInterface $logger
   *   Module-channel logger for loud failures inside create/delete paths.
   * @param array $record
   *   The CSV row keyed by column header.
   */
  public function __construct(
    TransliterationInterface $transliteration,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly LoggerInterface $logger,
    array $record,
  ) {
    parent::__construct($transliteration);
    $this->label = $record['Name'];
    $this->description = $record['Description'];
    $this->machineName = $record['Machine name'];
    $this->moderated = $record['Mod.'] == 'y';
    $this->layoutBuilder = $record['Layout'] == 'y';
    $this->translate = $record['Trns.'] == 'y';
    $this->migrate = $record['Migr.'] == 'y';
    $this->metatags = $record['Meta'] == 'y';
    $this->schedule = $record['Sched.'] == 'y';
    $this->search = $record['Srch.'];
    $this->aliasPattern = $record['URL alias pattern'];

    // Determine what action applies to this bundle.
    $this->operation = Operation::fromColumn($record['X']);

    // Determine which entity type this will be a bundle of.
    $this->entity = EntityTypeResolver::fromLabel($record['Type']);
  }

  /**
   * {@inheritdoc}
   */
  public function configExists(): bool {
    // Get the list of bundles for this object's entity type (node, media, etc).
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($this->entity);
    return isset($bundles[$this->machineName]);
  }

  /**
   * Create the entity form display for the bundle.
   *
   * @return \Drupal\Core\Entity\Entity\EntityFormDisplay|false
   *   The saved form display, or FALSE if none was created.
   */
  public function configureFormDisplay(): EntityFormDisplay|false {
    if ($this->configExists()) {
      if (!EntityFormDisplay::load("$this->entity.$this->machineName.default")) {
        // Create the form display.
        $form_display = EntityFormDisplay::create([
          'targetEntityType' => $this->entity,
          'bundle' => $this->machineName,
          'mode' => 'default',
          'status' => TRUE,
        ]);
        // Save and return the new form object.
        try {
          $form_display->save();
          return $form_display;
        }
        catch (\Exception $exception) {
          $this->logger->error('BundleRecord::configureFormDisplay(): @message', ['@message' => $exception->getMessage()]);
          return FALSE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Adds the bundle to the designated workflow.
   *
   * @todo Add the bundle to the configured moderation workflow.
   */
  public function configureModeration(): void {
    if ($this->configExists()) {

    }
  }

  /**
   * Saves the pathauto alias pattern for this bundle.
   *
   * @return \Drupal\pathauto\Entity\PathautoPattern|false
   *   The saved alias pattern, or FALSE on failure.
   */
  public function configurePathAlias(): PathautoPattern|false {
    if ($this->configExists()) {
      if (!$alias_pattern = PathautoPattern::load($this->machineName)) {
        // Create the alias pattern object.
        $alias_pattern = PathautoPattern::create([
          'id' => $this->machineName,
          'label' => $this->label,
          'type' => "canonical_entities:$this->entity",
          'weight' => 0,
          'selection_criteria' => [
            [
              'id' => "entity_bundle:$this->entity",
              'context_mapping' => [
                $this->entity => $this->entity,
              ],
              'bundles' => [
                $this->machineName => $this->machineName,
              ],
            ],
          ],
        ]);
      }
      // Set the value of the pattern.
      $alias_pattern->setPattern($this->aliasPattern);
      // Designate the applicable bundles.
      $alias_pattern->set('bundles', [$this->machineName => $this->machineName]);
      // Save and return the alias pattern object.
      try {
        $alias_pattern->save();
        return $alias_pattern;
      }
      catch (\Exception $exception) {
        $this->logger->error('BundleRecord::configurePathAlias(): @message', ['@message' => $exception->getMessage()]);
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   * Configure the translation settings for the bundle.
   *
   * @return \Drupal\language\Entity\ContentLanguageSettings|false|null
   *   The saved language settings, or FALSE on failure.
   */
  public function configureTranslations(): ContentLanguageSettings|false|null {
    if ($this->configExists()) {
      if (!$language_settings = ContentLanguageSettings::load("$this->entity.$this->machineName")) {
        // Create the translation settings object.
        $language_settings = ContentLanguageSettings::create([
          'language' => 'en',
          'target_entity_type_id' => $this->entity,
          'target_bundle' => $this->machineName,
          'default_langcode' => 'site_default',
          'language_alterable' => FALSE,
        ]);
        // Save the settings.
        try {
          $language_settings->save();
        }
        catch (\Exception $exception) {
          $this->logger->error('BundleRecord::configureTranslations() create: @message', ['@message' => $exception->getMessage()]);
        }
      }
      // Create the settings to hide untranslatable fields.
      $thirdPartySettings = [
        'content_translation' => [
          'bundle_settings' => [
            'untranslatable_fields_hide' => 1,
          ],
        ],
      ];
      // Enable translations and hide untranslatable fields for this bundle.
      $language_settings->setThirdPartySetting('content_translation', 'enabled', TRUE)
        ->setThirdPartySetting('content_translation', 'bundle_settings', $thirdPartySettings['content_translation']['bundle_settings']);
      // Save and return the translation settings object.
      try {
        $language_settings->save();
        return $language_settings;
      }
      catch (\Exception $exception) {
        $this->logger->error('BundleRecord::configureTranslations() update: @message', ['@message' => $exception->getMessage()]);
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   * Create the entity view display for the bundle.
   *
   * @return \Drupal\Core\Entity\Entity\EntityViewDisplay|false
   *   The saved view display, or FALSE if none was created.
   */
  public function configureViewDisplay(): EntityViewDisplay|false {
    if ($this->configExists()) {
      if (!EntityViewDisplay::load("$this->entity.$this->machineName.default")) {
        // Create the view display object.
        $view_display = EntityViewDisplay::create([
          'targetEntityType' => $this->entity,
          'bundle' => $this->machineName,
          'mode' => 'default',
          'status' => TRUE,
        ]);
        // Save and return the view display.
        try {
          $view_display->save();
          return $view_display;
        }
        catch (\Exception $exception) {
          $this->logger->error('BundleRecord::configureViewDisplay(): @message', ['@message' => $exception->getMessage()]);
          return FALSE;
        }
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @return bool
   *   TRUE if the bundle was saved, FALSE on failure.
   *
   * @see getBaseValues()
   *   Constructs the baseValues array used here to create this bundle.
   */
  public function createConfig(): bool {
    // Build the base values array on demand — keeping the constructor pure.
    $this->baseValues = $this->getBaseValues();

    // Determine which entity type this bundle is being built for using the
    // baseValues array assembled above.
    $bundle = match ($this->entity) {
      'block_content' => BlockContentType::create($this->baseValues),
      'media' => MediaType::create($this->baseValues),
      'node' => NodeType::create($this->baseValues),
      'paragraph' => ParagraphsType::create($this->baseValues),
      'taxonomy_term' => Vocabulary::create($this->baseValues),
      // User is included here for completeness; the user entity has no bundles.
      'user' => NULL,
      default => NULL,
    };
    if ($bundle === NULL) {
      return FALSE;
    }
    // Save the configuration.
    try {
      $bundle->save();
      return TRUE;
    }
    catch (\Exception $exception) {
      $this->logger->error('BundleRecord::createConfig(): @message', ['@message' => $exception->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Create the source field for the media bundle.
   *
   * @param \Drupal\media\Entity\MediaType $media_bundle
   *   The media type to create the source field for.
   *
   * @return string|false
   *   The source field name, or FALSE if it could not be determined.
   */
  public function createSourceField(MediaType $media_bundle): string|false {
    $source_field = $media_bundle->getSource()->createSourceField($media_bundle);
    try {
      $source_field->save();
    }
    catch (\Exception $exception) {
      $this->logger->error('BundleRecord::createSourceField(): @message', ['@message' => $exception->getMessage()]);
    }
    $name = $source_field->getName();
    return $name !== '' ? $name : FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @return bool
   *   TRUE if the bundle was deleted, FALSE on failure.
   */
  public function deleteConfig(): bool {
    $bundle = match ($this->entity) {
      'block_content' => BlockContentType::load($this->machineName),
      'media' => MediaType::load($this->machineName),
      'node' => NodeType::load($this->machineName),
      'paragraph' => ParagraphsType::load($this->machineName),
      'taxonomy_term' => Vocabulary::load($this->machineName),
      // User is included for completeness; it has no bundles to delete.
      'user' => NULL,
      default => NULL,
    };
    if ($bundle === NULL) {
      return FALSE;
    }
    // Delete the config.
    try {
      $bundle->delete();
      return TRUE;
    }
    catch (\Exception $exception) {
      $this->logger->error('BundleRecord::deleteConfig(): @message', ['@message' => $exception->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Construct the array of base values for creating the bundle.
   *
   * @return array
   *   The base values keyed for the target entity type's create() call.
   */
  public function getBaseValues(): array {
    $baseValues = [];
    switch ($this->entity) {
      case 'node':
        $baseValues['type'] = $this->machineName;
        $baseValues['name'] = $this->label;
        $baseValues['description'] = $this->description;
        break;

      case 'taxonomy_term':
        $baseValues['vid'] = $this->machineName;
        $baseValues['name'] = $this->label;
        $baseValues['description'] = $this->description;
        break;

      case 'media':
        $baseValues['id'] = $this->machineName;
        $baseValues['label'] = $this->label;
        $baseValues['source'] = 'image';
        break;

      case 'block_content':
      case 'paragraph':
        $baseValues['id'] = $this->machineName;
        $baseValues['label'] = $this->label;
        $baseValues['description'] = $this->description;
        break;

      case 'user':
        // The user entity is included here for completeness
        // since the user entity does not have bundles.
        break;
    }
    return $baseValues;
  }

  /**
   * Returns the configured alias pattern for this bundle.
   *
   * @param string $entity
   *   The entity type ID the pattern applies to.
   * @param string $bundle
   *   The bundle machine name to look up.
   *
   * @return string|false
   *   The matching alias pattern machine name, or FALSE if none is found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getPathAliasPattern(string $entity, string $bundle): string|false {
    // Get all the configured alias patterns for bundles of this entity type.
    $pathauto_patterns = $this->entityTypeManager->getStorage('pathauto_pattern')
      ->loadByProperties(['type' => "canonical_entities:$entity"]);
    // Create an array of patterns keyed by their machine names.
    $patterns = array_map(function ($pattern) {
      /** @var \Drupal\pathauto\PathautoPatternInterface $pattern */
      return $pattern->getPattern();
    }, $pathauto_patterns);
    // Return the pattern or false if none exists.
    return array_search($bundle, $patterns);
  }

  /**
   * Returns this row's values as an array to be added to the summary table.
   *
   * @param bool $array_keys
   *   If true, only the array keys are returned to facilitate a header row.
   *
   * @return array
   *   The report row, keyed by column label.
   */
  public function getReport(bool $array_keys = FALSE): array {
    $operation = match ($this->operation) {
      Operation::DELETE => 'delete',
      Operation::CREATE_OR_UPDATE => 'new',
      default => 'exists',
    };

    $status = $this->configExists();

    $report['Entity'] = $this->entity;
    $report['Label'] = $this->label;
    $report['Machine name'] = $this->machineName;
    // Preserve the legacy "exists" override: when the bundle already exists
    // the summary reports the action as "update" regardless of operation code.
    $report['Oper'] = $status ? 'update' : $operation;
    $report['Status'] = $status ? 'exists' : 'new';
    $report['Mod'] = $this->moderated ? 'M' : '-';
    $report['Layout'] = $this->layoutBuilder ? 'LB' : '-';
    $report['Trns'] = $this->translate ? 'T' : '-';
    $report['Alias'] = $this->aliasPattern;

    if ($array_keys) {
      return array_keys($report);
    }
    return $report;
  }

  /**
   * {@inheritdoc}
   */
  public function relatedConfigs(): void {
    $this->configureFormDisplay();
    $this->configureViewDisplay();

    if ($this->translate) {
      $this->configureTranslations();
    }

    if ($this->moderated) {
      $this->configureModeration();
    }

    if (!empty($this->aliasPattern) && $this->aliasPattern != '-') {
      $this->configurePathAlias();
    }
  }

  /**
   * Stores a single value in this row's summary report.
   *
   * @param string $key
   *   The report column label.
   * @param string $value
   *   The value to record.
   */
  public function setRecord(string $key, string $value): void {
    $this->report[$key] = $value;
  }

}
