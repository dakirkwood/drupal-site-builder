<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\views\Entity\View;

/**
 * Represents a single view row from the Build Spec.
 */
class ViewsRecord extends BuildSpecRecord {

  /**
   * The human-readable view label.
   *
   * @var string
   */
  public string $viewName;

  /**
   * The view machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The base table the view queries against.
   *
   * @var string
   */
  public string $baseTable;

  /**
   * Whether the view is enabled.
   *
   * @var bool
   */
  public bool $status;

  /**
   * The view description.
   *
   * @var string
   */
  public string $description;

  /**
   * Constructs a ViewsRecord from a Build Spec row.
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
    $this->viewName = $record['View name'];
    $this->machineName = $record['Machine name'];
    $this->baseTable = $this->getBaseTable($record['Base table']);
    $this->status = $record['Status'] == 'Enabled';
    $this->description = $record['Description'];
    $this->operation = Operation::fromColumn($record['X']);
  }

  /**
   * {@inheritDoc}
   */
  public function relatedConfigs(): void {
    // No related configs for a view; displays are imported separately.
  }

  /**
   * {@inheritDoc}
   */
  public function configExists(): bool {
    $view = View::load($this->machineName);
    return $view !== NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    $view = View::load($this->machineName) ?: View::create([
      'id' => $this->machineName,
      'label' => $this->viewName,
      'base_table' => $this->baseTable,
      'status' => $this->status,
      'description' => $this->description,
    ]);
    $view->addDisplay('default', 'Default');
    // Add the title field to the default display.
    $displays = $view->get('display');
    $displays['default']['display_options']['fields']['title'] = [
      'id' => 'title',
      'table' => 'node_field_data',
      'field' => 'title',
      'entity_type' => 'node',
    ];
    $displays['default']['display_options']['title'] = 'Default';
    $view->set('display', $displays);
    $view->save();
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    $view = View::load($this->machineName);
    $view?->delete();
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [];
    $report['View name'] = $this->viewName;
    $report['Machine name'] = $this->machineName;
    $report['Base table'] = $this->baseTable;
    $report['Status'] = $this->status == 1 ? 'Enabled' : 'Disabled';
    $report['Description'] = $this->description;
    $report['Operation'] = $this->operation;
    if ($array_keys) {
      return array_keys($report);
    }
    return $report;
  }

  /**
   * Returns the default field configuration for a view's title column.
   *
   * @return array
   *   The Views field handler configuration for the node title.
   */
  public function getDefaultFieldConfig(): array {
    return [
      'title' => [
        'id' => 'title',
        'table' => 'node_field_data',
        'field' => 'title',
        'relationship' => 'none',
        'group_type' => 'group',
        'admin_label' => '',
        'entity_type' => 'node',
        'entity_field' => 'title',
        'plugin_id' => 'field',
        'label' => '',
        'exclude' => FALSE,
        'alter' => [
          'alter_text' => FALSE,
          'make_link' => FALSE,
          'absolute' => FALSE,
          'word_boundary' => FALSE,
          'ellipsis' => FALSE,
          'strip_tags' => FALSE,
          'trim' => FALSE,
          'html' => FALSE,
        ],
        'element_type' => '',
        'element_class' => '',
        'element_label_type' => '',
        'element_label_class' => '',
        'element_label_colon' => TRUE,
        'element_wrapper_type' => '',
        'element_wrapper_class' => '',
        'element_default_classes' => TRUE,
        'empty' => '',
        'hide_empty' => FALSE,
        'empty_zero' => FALSE,
        'hide_alter_empty' => TRUE,
        'click_sort_column' => 'value',
        'type' => 'string',
        'settings' => [
          'link_to_entity' => TRUE,
        ],
        'group_column' => 'value',
        'group_columns' => NULL,
        'group_rows' => TRUE,
        'delta_limit' => 0,
        'delta_offset' => 0,
        'delta_reversed' => FALSE,
        'delta_first_last' => FALSE,
        'multi_type' => 'separator',
        'separator' => ', ',
        'field_api_classes' => FALSE,
      ],
    ];
  }

  /**
   * Maps a Build Spec base-table label to its Views base table machine name.
   *
   * @param string $table_option
   *   The base-table label from the Build Spec.
   *
   * @return string
   *   The Views base table machine name, or an empty string if unmapped.
   */
  public function getBaseTable(string $table_option): string {
    return match ($table_option) {
      'Content', 'Index Content' => 'node_field_data',
      'Files' => 'file_managed',
      'Media' => 'media_field_data',
      'Paragraph' => 'standard:paragraphs_item_field_data',
      'Taxonomy terms' => 'taxonomy_term_field_data',
      'Users' => 'users_field_data',
      default => '',
    };
  }

}
