<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\field\FieldStorageConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Represents a single field row from the Build Spec.
 */
class FieldRecord extends BuildSpecRecord {
  use EcConsoleDebug;

  /**
   * The bundle machine name this field belongs to.
   *
   * @var string
   */
  public string $bundle;

  /**
   * The field cardinality, or -1 for unlimited.
   *
   * @var int
   */
  public int $cardinality;

  /**
   * The entity type ID this field is attached to.
   *
   * @var string
   */
  public string $entity;

  /**
   * The view-display formatter machine name.
   *
   * @var string
   */
  public string $formatter;

  /**
   * The form-widget machine name.
   *
   * @var string
   */
  public string $formWidget;

  /**
   * The field group label, if the field belongs to one.
   *
   * @var string
   */
  public string $group;

  /**
   * The field help text.
   *
   * @var string
   */
  public string $helpText;

  /**
   * Whether this field is an entity reference field.
   *
   * @var bool
   */
  public bool $isEntRef;

  /**
   * The human-readable field label.
   *
   * @var string
   */
  public string $label;

  /**
   * The field machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The raw "Ref. bundle" cell, deferred so resolution stays out of the ctor.
   *
   * @var string
   */
  private string $refBundleRaw;

  /**
   * Information about the referenced entity bundle, if a reference field.
   *
   * Computed lazily on first access via resolveRefBundle(); the constructor
   * stores only the raw column value so it remains pure data mapping.
   *
   * @var array|null
   */
  private ?array $refBundleResolved = NULL;

  /**
   * Whether the field is required.
   *
   * @var bool
   */
  public bool $required;

  /**
   * Whether the field reuses an existing field storage.
   *
   * @var bool
   */
  public bool $reused;

  /**
   * Whether the field is translatable.
   *
   * @var bool
   */
  public bool $translate;

  /**
   * The human-readable field type from the Build Spec.
   *
   * @var string
   */
  public string $type;

  /**
   * The field type plugin machine name.
   *
   * @var string
   */
  public string $typeId;

  /**
   * The field weight, derived from the row position.
   *
   * @var int
   */
  public int $weight;

  /**
   * The accumulated summary report for this row.
   *
   * @var array
   */
  public array $report;

  /**
   * Constructs a FieldRecord from a Build Spec row.
   *
   * Pure data mapping plus plugin-name resolution: the constructor needs the
   * field-type / widget / formatter plugin managers because the Build Spec
   * carries human-readable labels and the record is expected to expose the
   * machine names. configExists() and referenced-bundle resolution happen
   * later, when the Drush command invokes them.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Shared transliteration service for machineName().
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   Used by reference-bundle resolution to look up sibling bundles.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $displayRepository
   *   Used by relatedConfigs() to update form / view displays.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $fieldTypeManager
   *   Resolves "Field type" labels to plugin machine names.
   * @param \Drupal\Core\Field\WidgetPluginManager $widgetManager
   *   Resolves "Form widget" labels to plugin machine names.
   * @param \Drupal\Core\Field\FormatterPluginManager $formatterManager
   *   Resolves view-display formatter labels to plugin machine names.
   * @param \Psr\Log\LoggerInterface $logger
   *   Module-channel logger for loud failures.
   * @param array $record
   *   The CSV row keyed by column header.
   */
  public function __construct(
    TransliterationInterface $transliteration,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
    private readonly FieldTypePluginManagerInterface $fieldTypeManager,
    private readonly WidgetPluginManager $widgetManager,
    private readonly FormatterPluginManager $formatterManager,
    private readonly LoggerInterface $logger,
    array $record,
  ) {
    parent::__construct($transliteration);
    $this->bundle = $record['Bundle machine name'];
    $this->cardinality = $record['Vals.'] == '*' ? -1 : (int) $record['Vals.'];
    $this->group = $record['Field group'];
    $this->helpText = $record['Help text'];
    $this->label = $record['Field label'];
    $this->machineName = $record['Machine name'];
    $this->required = $record['Req\'d'] == 'y';
    $this->reused = $record['Reused Field'] == 'y';
    $this->translate = $record['Trns.'] == 'y';
    $this->type = $record['Field type'];
    $this->typeId = $this->getFieldTypeMachineName($record['Field type']);
    $this->formWidget = $this->getFieldFormWidget($record['Form widget']);

    // Determine the operation that applies to this field.
    $this->operation = Operation::fromColumn($record['X']);

    // Determine which entity type this field will be added to.
    $this->entity = EntityTypeResolver::fromHaystack($record['Bundle']);

    // Determine if this is an entity reference field.
    $this->isEntRef = $this->isReferenceField($record['Field type']);

    // Stash the raw reference-bundle cell — resolution happens on demand to
    // keep the constructor free of API calls.
    $this->refBundleRaw = $record['Ref. bundle'] ?? '';

    // Create the keys of the report array.
    $headers = ['Bundle', 'Label', 'Id', 'Oper', 'Status', 'Type', 'RfBn', 'Req', 'Vals', 'Trns', 'Help', 'Err'];
    $this->report = array_fill_keys($headers, '');
  }

  /**
   * Returns the resolved reference-bundle info, computing it on first access.
   *
   * @return array
   *   The 'entity', 'bundle_label', 'bundle_machine_name' triplet, or an
   *   empty array when this is not a reference field.
   */
  public function refBundle(): array {
    if (!$this->isEntRef) {
      return [];
    }
    if ($this->refBundleResolved === NULL) {
      $this->refBundleResolved = $this->getReferencedEntityInfo($this->refBundleRaw);
    }
    return $this->refBundleResolved;
  }

  /**
   * Add this field to a field group if one is designated.
   */
  public function addToFieldGroup(): void {

    // Return early if no field group is designated for this row.
    if (empty($this->group)) {
      return;
    }

    // Get the field_group from the form_display.
    $group_id = "group_{$this->machineName($this->group)}";
    $form_display = $this->displayRepository->getFormDisplay($this->entity, $this->bundle, 'default');
    $field_groups = $form_display->getThirdPartySettings('field_group');

    // If the group doesn't already exist, create it.
    if (!$group = $field_groups[$group_id] ?? '') {
      $group = $this->createFieldGroup();
    }

    // Add this field as a child element of the group.
    $group['children'][] = $this->machineName;
    // Save the updated 'children' array back to the form_display object.
    $form_display->setThirdPartySetting('field_group', $group['group_name'], $group);

    // Save the form display.
    try {
      $form_display->save();
    }
    catch (\Exception $exception) {
      $this->logger->error('FieldRecord::addToFieldGroup(): @message', ['@message' => $exception->getMessage()]);
    }
  }

  /**
   * Enable this field on the node's form display.
   */
  public function addToFormDisplay(): void {
    // Get the form display object.
    $display = $this->displayRepository->getFormDisplay($this->entity, $this->bundle, 'default');

    // Enable this field in the form display.
    $display->setComponent($this->machineName, [
      'type' => $this->formWidget,
      'weight' => $this->weight,
    ]);

    // Save the form display settings.
    try {
      $display->save();
    }
    catch (\Exception $exception) {
      $this->logger->error('FieldRecord::addToFormDisplay(): @message', ['@message' => $exception->getMessage()]);
    }
  }

  /**
   * Add this field the node's view display.
   */
  public function addToViewDisplay(): void {
    // Get the form display object.
    $display = $this->displayRepository->getViewDisplay($this->entity, $this->bundle, 'default');

    // Enable this field in the form display.
    $display->setComponent($this->machineName, [
      'label' => 'above',
      'type' => $this->formatter ?? NULL,
      'weight' => $this->weight,
    ]);

    // Save the form display.
    try {
      $display->save();
    }
    catch (\Exception $exception) {
      $this->logger->error('FieldRecord::addToViewDisplay(): @message', ['@message' => $exception->getMessage()]);
    }
  }

  /**
   * Verifies if the configuration for this field exists already.
   *
   * @return bool
   *   TRUE if the field configuration already exists, FALSE otherwise.
   */
  public function configExists(): bool {
    $field_config = FieldConfig::loadByName($this->entity, $this->bundle, $this->machineName);
    return $field_config !== NULL;
  }

  /**
   * Contains function calls to save this field to the database.
   */
  public function createConfig(): void {
    $this->createField();
  }

  /**
   * Create this field's configuration.
   *
   * @return \Drupal\field\FieldConfigInterface|false
   *   The FieldConfig object for this row/record.
   */
  public function createField(): FieldConfigInterface|false {
    // Get/Create the field storage object.
    if ($this->createFieldStorage()) {

      // Verify this field's config does not already exist.
      $field_config = FieldConfig::loadByName($this->entity, $this->bundle, $this->machineName);
      if (!$field_config) {

        // Create the configuration object for this field.
        $field_config = FieldConfig::create([
          'field_name' => $this->machineName,
          'entity_type' => $this->entity,
          'bundle' => $this->bundle,
          'label' => $this->label,
          'description' => $this->helpText,
          'required' => $this->required,
          'translatable' => $this->translate,
        ]);

        // Add the information about entity references if necessary.
        if ($this->isEntRef) {
          $ref_bundle = $this->refBundle();

          // Handle Paragraph entity references.
          if ($ref_bundle['entity'] == 'paragraph') {
            $handler_settings = [
              'target_bundles' => $this->getParagraphTargetBundles(),
              'negate' => 0,
              'target_bundles_drag_drop' => $this->getParagraphTargetBundles(TRUE),
            ];
          }
          // Handle regular entity references.
          else {
            $handler_settings = [
              'target_bundles' => [
                $ref_bundle['bundle_machine_name'] => $ref_bundle['bundle_machine_name'],
              ],
            ];
          }
          $field_config->setSetting('handler_settings', $handler_settings);
        }

        // Save the field configuration.
        try {
          $field_config->save();
          // This data will be printed in the summary.
          $this->setReport('Status', 'new');
        }
        catch (\Exception $exception) {
          $this->logger->error('FieldRecord::createField(): @message', ['@message' => $exception->getMessage()]);
          // Alert the user by saving this message to the report.
          $this->setReport('Status', 'err-F');
        }
      }

      if (empty($this->report['Status'])) {
        $this->setReport('Status', 'exists');
      }
      return $field_config;
    }
    return FALSE;
  }

  /**
   * Create a new field_group if one has been designated.
   *
   * @return array
   *   An associative array that can be saved to the form display.
   */
  public function createFieldGroup(): array {
    // The value coming from the build spec may need breaking up.
    preg_match('/([\w\s\-]+)\s\(/', $this->group, $groupName);

    // Determine if the value is an array or a simple string.
    $group_name = !empty($groupName) ? $groupName[1] : $this->group;

    // Create a machine name from the human-readable name.
    $machine_name = $this->machineName($group_name);

    // Construct and return just the field group array.
    return [
      'group_name' => "group_$machine_name",
      'entity_type' => $this->entity,
      'bundle' => $this->bundle,
      'mode' => 'default',
      'context' => 'form',
      'children' => [],
      'parent_name' => '',
      'weight' => $this->weight,
      'format_type' => 'details',
      'format_settings' => [
        'show_empty_fields' => FALSE,
        'id' => '',
        'classes' => '',
        'description' => '',
        'open' => FALSE,
        'required_fields' => TRUE,
      ],
      'label' => $group_name,
      'region' => 'content',
    ];
  }

  /**
   * Creates the field storage configuration if it doesn't exist already.
   *
   * @return \Drupal\field\FieldStorageConfigInterface|false
   *   The field storage configuration for this row/record.
   */
  public function createFieldStorage(): FieldStorageConfigInterface|false {

    $field_storage = FieldStorageConfig::loadByName($this->entity, $this->machineName);
    if (!$field_storage) {
      $field_storage = FieldStorageConfig::create([
        'field_name' => $this->machineName,
        'entity_type' => $this->entity,
        'type' => $this->typeId,
        'cardinality' => $this->cardinality,
      ]);
      if ($this->isEntRef) {
        $field_storage->setSetting('target_type', $this->refBundle()['entity']);
      }
      try {
        $field_storage->save();
      }
      catch (\Exception $exception) {
        $this->logger->error('FieldRecord::createFieldStorage(): @message', ['@message' => $exception->getMessage()]);
        $this->setReport('Status', 'err-FS');
      }
    }
    return $field_storage;
  }

  /**
   * Removes the configuration for this row/record.
   */
  public function deleteConfig(): void {
    // Get the field configuration object.
    $field = FieldConfig::loadByName($this->entity, $this->bundle, $this->machineName);

    // Delete the configuration if it exists.
    if ($field) {
      try {
        $field->delete();
        $this->setReport('Status', 'deleted');
      }
      catch (\Exception $exception) {
        $this->logger->error('FieldRecord::deleteConfig(): @message', ['@message' => $exception->getMessage()]);
        // Configuration could not be deleted.
        $this->setReport('Status', 'err-D');
      }
    }
  }

  /**
   * Returns all the bundles for a given entity.
   *
   * @param string $entity
   *   The entity ID of the entity (node, taxonomy_term, media).
   *
   * @return array
   *   An associative array of bundle labels keyed by their machine name.
   */
  public function getAllBundles(string $entity): array {
    // Get the bundle info array.
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity);

    // Return just the array of labels keyed by machine name.
    return array_map(function ($bundle) {
      return $bundle['label'];
    }, $bundles);
  }

  /**
   * Returns the machine name of bundle for a given label.
   *
   * @param string $bundle_label
   *   The human-readable name of the bundle.
   * @param string $entity_id
   *   The machine name of the entity.
   *
   * @return string
   *   The machine name of the bundle.
   */
  public function getBundleMachineNameFromLabel(string $bundle_label, string $entity_id): string {
    // Ensure the label's capitalization matches Drupal's.
    $bundle_label = ucfirst(strtolower($bundle_label));

    // Get the array of bundles keyed by machine name.
    $bundles = $this->getAllBundles($entity_id);
    $result = array_search(strtolower($bundle_label), array_map('strtolower', $bundles));
    return $result !== FALSE ? (string) $result : '';
  }

  /**
   * Returns the machine name of the field formatter for a given label.
   *
   * @param string $record_field_formatter
   *   The human-readable name of the field formatter for view displays.
   *
   * @return string
   *   The machine name of the field formatter.
   */
  public function getFieldFormatter(string $record_field_formatter): string {
    // Get an array of all the available formatters for this field type.
    $options = $this->formatterManager->getOptions($this->typeId);

    // Create an array of the formatter labels keyed by machine name.
    $formatters = array_map(function ($formatter) {
      return $formatter->render();
    }, $options);

    // Return the machine name.
    $result = array_search($record_field_formatter, $formatters);
    return $result !== FALSE ? (string) $result : '';
  }

  /**
   * Returns the machine name of the form widget for a given label.
   *
   * @param string $record_form_widget
   *   The human-readable value coming from the Build Spec.
   *
   * @return string
   *   The machine name of the widget.
   */
  public function getFieldFormWidget(string $record_form_widget): string {
    // Get the array of form widget options for this field type.
    $widget_options = $this->widgetManager->getOptions($this->typeId);

    // Construct an array of widget labels keyed by machine name.
    $widgets = array_map(function ($field_widget) {
      return $field_widget->render();
    }, $widget_options);

    // Return the machine name.
    $result = array_search($record_form_widget, $widgets);
    return $result !== FALSE ? (string) $result : '';
  }

  /**
   * Returns the machine name of the field type.
   *
   * @param string $record_field_type
   *   The human-readable field type value provided by the Build Spec.
   *
   * @return string
   *   The machine name of the field type.
   */
  public function getFieldTypeMachineName(string $record_field_type): string {
    // Exit early if the field type contains 'paragraphs'.
    if (str_contains($record_field_type, '(paragraphs)')) {
      return 'entity_reference_revisions';
    }

    // Get an array of the field type definitions.
    $ui_definitions = $this->fieldTypeManager->getUiDefinitions();

    // Construct an array of field type labels keyed by machine name.
    $types = array_map(function ($type) {
      return $type['label']->render();
    }, $ui_definitions);

    // Return the machine name.
    $result = array_search($record_field_type, $types);
    return $result !== FALSE ? (string) $result : '';
  }

  /**
   * Returns paragraph bundles for entity reference revisions fields.
   *
   * @param bool $dragDrop
   *   Determines the format of the return value:
   *   TRUE: Drag-n-Drop form widget.
   *   FALSE: (default) Simple select list.
   *
   * @return array
   *   Array formatted for use in the form display configuration.
   */
  public function getParagraphTargetBundles(bool $dragDrop = FALSE): array {
    $target_bundles = [];
    $ref_bundle = $this->refBundle();

    // The target_bundles array needs to be formatted two different ways:
    // 1) to list the bundles available for referencing;
    // 2) for the drag and drop interface.
    switch ($dragDrop) {

      // Format for Drag-n-Drop widget.
      case TRUE:

        // If the 'bundle_machine_name' key is an array, format the array
        // for multiple selections.
        if (is_array($ref_bundle['bundle_machine_name'])) {

          // Construct a compound array keyed by bundle machine name.
          $target_bundles = array_map(function ($bundle) {
            return ['enabled' => TRUE];
          }, $ref_bundle['bundle_machine_name']);
        }

        // The 'bundle_machine_name' key is a string indicating a single bundle.
        else {
          $target_bundles[$ref_bundle['bundle_machine_name']] = [
            'enabled' => TRUE,
          ];
        }
        break;

      // Format for simple select list.
      default:
        // The 'bundle_machine_name' is an array indicating multiple bundles.
        if (is_array($ref_bundle['bundle_machine_name'])) {
          foreach ($ref_bundle['bundle_machine_name'] as $key => $value) {
            $target_bundles[$key] = $key;
          }
        }
        // The 'bundle_machine_name' key is a string indicating a single bundle.
        else {
          $target_bundles[$ref_bundle['bundle_machine_name']] = $ref_bundle['bundle_machine_name'];
        }
        break;
    }
    return $target_bundles;
  }

  /**
   * Converts the Build Spec reference-entity values into useful terms.
   *
   * @param string $record_refBun
   *   The value provided by the Build Spec for referenced entity bundles.
   *
   * @return array
   *   An associative array of bundle's label, machine name and parent entity.
   */
  public function getReferencedEntityInfo(string $record_refBun): array {

    // The Build Spec doesn't provide a clean way to select multiple bundles
    // for entity reference fields. 'All' or '' (empty) are the typical values.
    // Return early.
    if ($record_refBun == 'All' || empty($record_refBun)) {
      return [
        'entity' => 'paragraph',
        'bundle_label' => '',
        'bundle_machine_name' => $this->getAllBundles('paragraph'),
      ];
    }
    // The Build Spec value combines the bundle name and its entity name in
    // parentheses -- Image (Media type). Separate the values into parts.
    preg_match('/([\w\s\-\&\/]+)\s\(([\w\s\-]+)\)/', $record_refBun, $ref_values);

    // Identify each part of the referenced entities.
    $ref_entity = [
      'bundle_label' => $ref_values[1] ?? '',
      'entity' => EntityTypeResolver::fromLabel($ref_values[2] ?? ''),
    ];
    $ref_entity['bundle_machine_name'] = !empty($ref_values)
      ? $this->getBundleMachineNameFromLabel($ref_values[1], $ref_entity['entity'])
      : '';
    return $ref_entity;
  }

  /**
   * Returns information about this record for the console summary table.
   *
   * @param bool $array_keys
   *   Determines if the full array or just the keys are returned.
   *
   * @return array
   *   Keys and values about this row/record in the Build Spec.
   *   - False parameter (default): the full array is returned.
   *   - True parameter: only the keys are returned.
   */
  public function getReport(bool $array_keys = FALSE): array {
    // Get the field and field storage config objects.
    $field = FieldConfig::loadByName($this->entity, $this->bundle, $this->machineName);
    $field_storage = FieldStorageConfig::loadByName($this->entity, $this->machineName);

    // Convert the operation integer into a human-readable term.
    $operation = match ($this->operation) {
      Operation::DELETE => 'delete',
      Operation::CREATE_OR_UPDATE => 'new',
      default => 'exists',
    };

    // Use system values for fields created/updated by this process.
    if ($field && $field_storage) {
      $ref_bundle = '-';
      if (str_contains($field->getType(), 'reference')) {
        $handler_settings = $field->getSetting('handler_settings');
        if (isset($handler_settings['target_bundles'])) {
          $ref_bundle = array_values($handler_settings['target_bundles']);
        }
      }

      $field_label = (string) $field->getLabel();
      $field_description = (string) $field->getDescription();
      $this->report['Bundle'] = $field->getTargetBundle();
      $this->report['Label'] = strlen($field_label) > 25 ?
        mb_substr($field_label, 0, 25) . '...' : $field_label;
      $this->report['Id'] = $field->getName();
      $this->report['Oper'] = $operation;
      $this->report['Type'] = $field->getType();
      $this->report['RfBn'] = is_array($ref_bundle) ? $ref_bundle[0] : $ref_bundle;
      $this->report['Req'] = $field->isRequired() ? 'R' : '-';
      $this->report['Vals'] = $field_storage->getCardinality() == -1 ? 'U' : $field_storage->getCardinality();
      $this->report['Trns'] = $field->isTranslatable() ? 'T' : '-';
      $this->report['Help'] = $field_description !== '' ?
        mb_substr($field_description, 0, 25) . '...' : '';

    }
    // Use Build Spec values from the record for fields deleted by this process.
    else {
      if ($operation == 'delete') {
        $label = strlen($this->label) > 25 ? mb_substr($this->label, 0, 25) . '...' : $this->label;

        $this->report['Bundle'] = $this->bundle;
        $this->report['Label'] = $label;
        $this->report['Id'] = $this->machineName;
        $this->report['Oper'] = $operation;
        $this->report['Type'] = 'x';
        $this->report['RfBn'] = 'x';
        $this->report['Req'] = 'x';
        $this->report['Vals'] = 'x';
        $this->report['Trns'] = 'x';
        $this->report['Help'] = 'x';
      }
    }

    // Calling this function with a TRUE value returns only the keys
    // which provides the headers for the summary table in the console.
    if ($array_keys) {
      return array_keys($this->report);
    }

    return $this->report;
  }

  /**
   * Provides boolean value to determine processing for entity reference fields.
   *
   * @param string $field_type
   *   The value provided by the Build Spec which varies in capitalization.
   *
   * @return bool
   *   TRUE if the field type is an entity reference field, FALSE otherwise.
   */
  public function isReferenceField(string $field_type): bool {
    return str_contains(strtolower($field_type), 'reference');
  }

  /**
   * Calls functions to create/update additional field configurations.
   */
  public function relatedConfigs(): void {
    // Add/Enable this field for displays and field group assignment.
    $this->addToFormDisplay();
    $this->addToViewDisplay();
    $this->addToFieldGroup();
  }

  /**
   * Sets the field weight based on the row number being processed.
   *
   * @param int $field_position
   *   The number of this row in the Build Spec being processed.
   */
  public function setFieldWeight(int $field_position): void {
    $this->weight = $field_position;
  }

  /**
   * Provides a controlled way to update report settings.
   *
   * @param string $key
   *   The key in this->report.
   * @param mixed $value
   *   The value to be set.
   */
  public function setReport(string $key, mixed $value): void {
    $this->report[$key] = $value;
  }

}
