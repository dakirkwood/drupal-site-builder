<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;

/**
 * Represents a single view-mode row from the Build Spec.
 */
class ViewModeRecord extends BuildSpecRecord {

  /**
   * The human-readable view mode label.
   *
   * @var string
   */
  public string $label;

  /**
   * The fully-qualified view mode machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The target entity type ID for this view mode.
   *
   * @var string
   */
  public string $entityType = '';

  /**
   * The bundles this view mode is enabled on.
   *
   * @var array
   */
  public array $entityBundles;

  /**
   * Errors collected while processing this row.
   *
   * @var array
   */
  public array $errors = [];

  /**
   * Whether processing of this row should be aborted.
   *
   * @var bool
   */
  public bool $abort = FALSE;

  /**
   * Constructs a ViewModeRecord from a Build Spec row.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Shared transliteration service for machineName().
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   Used by bundleExists() to verify bundles before display creation.
   * @param array $record
   *   The CSV row keyed by column header.
   */
  public function __construct(
    TransliterationInterface $transliteration,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    array $record,
  ) {
    parent::__construct($transliteration);
    $this->label = $record['View Modes'];
    $this->entityBundles = array_map('trim', explode(',', $record['Entities Used On']));
    $this->operation = Operation::fromColumn($record['X']);

    if (preg_match('/^(\w+)\s/', $record['Entity Type'], $entType)) {
      $this->entityType = $entType[1] == 'Content' ? 'node' : strtolower($entType[1]);
    }
    else {
      $this->errors[] = "The $this->entityType entity type could not be found.";
      $this->abort = TRUE;
    }

    $this->machineName = $this->entityType . '.' . MachineName::generate($record['View Modes']);
  }

  /**
   * {@inheritDoc}
   */
  public function relatedConfigs(): void {
    $this->addViewModeToBundles();
    $this->addViewDisplayToBundles();
  }

  /**
   * {@inheritDoc}
   */
  public function configExists(): bool {
    $view_mode = EntityViewMode::load($this->machineName);
    return $view_mode !== NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    // Check if the view mode already exists to avoid duplication.
    $view_mode = EntityViewMode::load($this->entityType . '.' . $this->machineName);
    if (!$view_mode) {
      // View mode doesn't exist, create it.
      $view_mode = EntityViewMode::create([
        'id' => $this->entityType . '.' . $this->machineName,
        'label' => $this->label,
        'targetEntityType' => $this->entityType,
      ]);
      $view_mode->save();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    $view_mode = EntityViewMode::load($this->machineName);
    $view_mode?->delete();
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [];
    $report['View Mode'] = $this->label;
    $report['Machine Name'] = $this->machineName;
    $report['Entity Type'] = $this->entityType;
    $report['Bundles'] = implode("\n", $this->entityBundles);
    $report['Operation'] = $this->operation;
    $report['Errors'] = implode("\n", $this->errors);
    if ($array_keys) {
      return array_keys($report);
    }
    return $report;
  }

  /**
   * Adds this mode to each bundle's Manage Display settings.
   *
   * Creates the entity_view_mode config for this row.
   */
  public function addViewModeToBundles(): void {
    foreach ($this->entityBundles as $bundle) {
      $bundle = strtolower($bundle);

      // Verify the bundle exists in the system.
      if (!$this->bundleExists($bundle)) {
        $this->errors[] = "$bundle bundle does not exist.";
        continue;
      }

      // Assemble the id.
      $view_mode_id = "$this->entityType.$this->machineName";

      // Load or create the Entity View Mode.
      $view_mode = EntityViewMode::load($view_mode_id) ?: EntityViewMode::create([
        'id' => $view_mode_id,
        'label' => ucfirst($this->machineName),
        'targetEntityType' => $this->entityType,
      ]);
      // Save the view mode to the database.
      $view_mode->save();
    }
  }

  /**
   * Sets the view display for each of the target bundles.
   *
   * Creates the entity_view_display config for this row.
   */
  public function addViewDisplayToBundles(): void {
    foreach ($this->entityBundles as $bundle) {
      $bundle = strtolower($bundle);

      // Verify the bundle exists in the system.
      if (!$this->bundleExists($bundle)) {
        $this->errors[] = "$bundle bundle does not exist.";
        continue;
      }

      // Assemble the id.
      $view_display_id = "{$this->entityType}.{$bundle}.{$this->machineName}";

      // Load or create the view display.
      $view_display = EntityViewDisplay::load($view_display_id) ?: EntityViewDisplay::create([
        'targetEntityType' => $this->entityType,
        'bundle' => $bundle,
        'mode' => $this->machineName,
        'id' => $view_display_id,
        'status' => TRUE,
      ]);

      // For image view displays, set the responsive image style field to the
      // matching responsive image style.
      if ($bundle == 'image') {
        $img_styles = ImageStyle::loadMultiple();
        $resp_img_styles = ResponsiveImageStyle::loadMultiple();
        switch (TRUE) {
          case isset($resp_img_styles[$this->machineName]):
            $view_display->setComponent('field_media_image', [
              'type' => 'responsive_image',
              'settings' => [
                'responsive_image_style' => $this->machineName,
              ],
            ]);
            break;

          case isset($img_styles[$this->machineName]):
            $view_display->setComponent('field_media_image', [
              'type' => 'image',
              'settings' => [
                'image_style' => $this->machineName,
              ],
            ]);
            break;

          default:
            $this->errors[] = "The $this->machineName resp. image style not found.";
        }
        $this->adjustFieldSettings($view_display);
      }
      $view_display->save();
    }
  }

  /**
   * Removes default media fields that should not appear in the view display.
   *
   * @param \Drupal\Core\Entity\Entity\EntityViewDisplay $view_display
   *   The view display to adjust, modified by reference.
   */
  public function adjustFieldSettings(EntityViewDisplay &$view_display): void {
    $disable_fields = ['created', 'uid', 'thumbnail'];
    foreach ($disable_fields as $field) {
      $view_display->removeComponent($field);
    }
  }

  /**
   * Verifies if the bundle for this row's entity type exists.
   *
   * @param string $bundle
   *   The bundle machine name to check.
   *
   * @return bool
   *   TRUE if the bundle exists for this row's entity type, FALSE otherwise.
   */
  public function bundleExists(string $bundle): bool {
    $allBundles = $this->entityTypeBundleInfo->getBundleInfo($this->entityType);
    return isset($allBundles[$bundle]);
  }

}
