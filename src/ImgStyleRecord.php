<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\image\Entity\ImageStyle;

/**
 * Represents a single image-style row from the Build Spec.
 */
class ImgStyleRecord extends BuildSpecRecord {
  use EcConsoleDebug;

  /**
   * The human-readable image style label.
   *
   * @var string
   */
  public string $styleName;

  /**
   * The image style machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The leading token of the style name used to group related styles.
   *
   * @var string
   */
  public string $configGroup;

  /**
   * The list of image effect configurations to apply, in order.
   *
   * @var array
   */
  public array $filters;

  /**
   * Converts the array of values in a build spec record into an object.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Shared transliteration service for machineName().
   * @param array $record
   *   The CSV row keyed by column header.
   */
  public function __construct(
    TransliterationInterface $transliteration,
    array $record,
  ) {
    parent::__construct($transliteration);
    $this->styleName = $record['Style name'];
    $this->machineName = $record['Machine name'];
    $this->operation = Operation::fromColumn($record['X']);

    if (preg_match('/^([^\s]*)/', $this->styleName, $matches)) {
      $this->configGroup = $matches[1];
    }
    else {
      $this->configGroup = $record['Style name'];
    }

    $this->filters = [];
    foreach (explode(', ', $record['Notes']) as $index => $item) {
      $this->filters[] = $this->getFilterConfig($index, $item);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function relatedConfigs(): void {
    // @todo Implement relatedConfigs() method.
  }

  /**
   * {@inheritDoc}
   */
  public function configExists(): bool {
    $image_style = ImageStyle::load($this->machineName);
    return $image_style !== NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    $style = ImageStyle::create([
      'label' => $this->styleName,
      'name' => $this->machineName,
    ]);
    foreach ($this->filters as $filter) {
      if (empty($filter)) {
        continue;
      }
      $style->addImageEffect($filter);
    }
    $style->save();
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    $style = ImageStyle::load($this->machineName);
    $style?->delete();
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [];
    $report['Style'] = $this->styleName;
    $report['Machine name'] = $this->machineName;
    $report['Operation'] = $this->operation;
    $filter_report = '';
    foreach ($this->filters as $filter) {
      $filter_report .= "{$filter['id']}, ";
    }
    $report['Filters'] = $filter_report;
    if ($array_keys) {
      return array_keys($report);
    }
    return $report;
  }

  /**
   * Builds an image effect configuration from a filter description string.
   *
   * @param int $weight
   *   The position of the effect within the image style pipeline.
   * @param string $filter_string
   *   The free-text filter description parsed from the Build Spec notes.
   *
   * @return array
   *   The image effect configuration, or an empty array if unrecognised.
   */
  public function getFilterConfig(int $weight, string $filter_string): array {
    $config = [];
    switch (TRUE) {
      case preg_match('/^Focal[\w\s]+ (\d+)x(\d+)/', $filter_string, $matches):
        $config = [
          'id' => 'focal_point_scale_and_crop',
          'weight' => $weight,
          'data' => [
            'width' => $matches[1],
            'height' => $matches[2],
          ],
        ];
        break;

      case preg_match('/^Scale[\w\s\/]+ (\d+)x?(\d+)?/', $filter_string, $matches):
        $upscaling = str_contains($filter_string, 'upscaling');
        $config = [
          'id' => 'image_scale',
          'weight' => $weight,
          'data' => [
            'width' => $matches[1],
            'height' => $matches[2] ?? '',
            'upscaling' => $upscaling,
          ],
        ];
        break;

      case preg_match('/^Image Style Quality (\d+%)/i', $filter_string, $matches):
        $config = [
          'id' => 'image_style_quality',
          'weight' => $weight,
          'data' => [
            'quality' => $matches[1],
          ],
        ];
        break;

      case preg_match('/^convert/', $filter_string, $matches):
        $config = [
          'id' => 'image_convert',
          'weight' => $weight,
          'data' => [
            'extension' => 'webp',
          ],
        ];
        break;
    }
    return $config;
  }

}
