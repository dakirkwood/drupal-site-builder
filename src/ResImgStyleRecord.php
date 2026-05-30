<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;

/**
 * Represents a single responsive-image-style row from the Build Spec.
 */
class ResImgStyleRecord extends BuildSpecRecord {
  use SbConsoleDebug;

  /**
   * The human-readable responsive image style label.
   *
   * @var string
   */
  public string $styleName;

  /**
   * The responsive image style machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The breakpoint group machine name this style maps against.
   *
   * @var string
   */
  public string $breakpointGroup;

  /**
   * The list of breakpoint descriptions used by this style.
   *
   * @var array
   */
  public array $breakpoints;

  /**
   * The human-readable label of the fallback image style.
   *
   * @var string
   */
  public string $fallbackLabel;

  /**
   * The machine name of the fallback image style.
   *
   * @var string
   */
  public string $fallbackMachineName;

  /**
   * The list of image style group machine names, one per breakpoint.
   *
   * @var array
   */
  public array $imgStyleGroup;

  /**
   * Free-text notes carried from the Build Spec row.
   *
   * @var array
   */
  public array $notes;

  /**
   * Constructs a ResImgStyleRecord from a Build Spec row.
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
    $this->styleName = $record['Responsive style'];
    $this->machineName = $record['Machine name'];
    $this->breakpointGroup = $record['Breakpoint Group'];
    $this->breakpoints = $this->splitBreakpointsUsed($record['Breakpoints Used']);
    $this->fallbackLabel = $record['Fallback Style'];
    $this->fallbackMachineName = $record['Fallback machine name'];
    $this->imgStyleGroup = array_map('trim', explode(',', $record['Image Style Group(s)']));
    $this->operation = Operation::fromColumn($record['X']);
    $this->notes = [];
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
    $style = ResponsiveImageStyle::load($this->machineName);
    return $style !== NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    $style = ResponsiveImageStyle::create([
      'id' => $this->machineName,
      'label' => $this->styleName,
      'breakpoint_group' => $this->breakpointGroup,
      'fallback_image_style' => $this->fallbackMachineName,
    ]);
    $i = 0;
    foreach ($this->breakpoints as $breakpoint) {
      if (preg_match('/(\w+) \(([\w\s\.,]+)\)/', $breakpoint, $matches)) {
        $bp_subgroup = strtolower(str_replace(' ', '', trim($matches[1])));
        $bp_id = "$this->breakpointGroup.$bp_subgroup";
        $multipliers = explode(',', $matches[2]);
        foreach ($multipliers as $multiplier) {
          $mp_machine = '';
          $multiplier = $multiplier == '1' ? '1x' : $multiplier;
          if ($multiplier != '1' && $multiplier != '1x') {
            $mp_machine = '_' . trim(str_replace('.', '_', $multiplier));
          }
          $img_group = strtolower(str_replace(' ', '_', $this->imgStyleGroup[$i]));
          $style->addImageStyleMapping($bp_id, trim($multiplier), [
            'image_mapping_type' => 'image_style',
            'image_mapping' => $img_group . $mp_machine,
          ]);
        }
      }
      else {
        $bp_subgroup = strtolower(str_replace(' ', '', trim($breakpoint)));
        $bp_id = "$this->breakpointGroup.$bp_subgroup";
        $style->addImageStyleMapping($bp_id, '1x', [
          'image_mapping_type' => 'image_style',
          'image_mapping' => $this->imgStyleGroup[$i],
          'breakpoint_id' => $bp_id,
          'multiplier' => '1x',
        ]);
      }
      $i++;
    }
    $style->save();
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    $style = ResponsiveImageStyle::load($this->machineName);
    $style?->delete();
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [];
    $report['Style'] = $this->styleName;
    $report['Mach. name'] = $this->machineName;
    $report['Bkpt Grp'] = $this->breakpointGroup;
    $report['Bkpts'] = implode("\n", $this->breakpoints);
    $report['Img Grps'] = implode("\n", $this->imgStyleGroup);
    $report['Oper.'] = $this->operation;
    $report['Fallback'] = $this->fallbackMachineName;
    if ($array_keys) {
      return array_keys($report);
    }
    return $report;
  }

  /**
   * Splits the "Breakpoints Used" cell into individual breakpoint entries.
   *
   * @param string $breakpoints_used
   *   The raw cell value, where parenthesised commas are not separators.
   *
   * @return array
   *   The trimmed list of breakpoint descriptions.
   */
  public function splitBreakpointsUsed(string $breakpoints_used): array {
    $pattern = '/,(?![^(]*\))/';
    // Split the input based on the pattern.
    $parts = preg_split($pattern, $breakpoints_used);
    return $parts === FALSE ? [] : array_map('trim', $parts);
  }

}
