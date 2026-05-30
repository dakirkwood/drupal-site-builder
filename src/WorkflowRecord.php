<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\workflows\Entity\Workflow;

/**
 * Represents a single workflow row from the Build Spec.
 */
class WorkflowRecord extends BuildSpecRecord {

  /**
   * The human-readable workflow label.
   *
   * @var string
   */
  public string $label;

  /**
   * The workflow machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The workflow type plugin ID.
   *
   * @var string
   */
  public string $type;

  /**
   * Constructs a WorkflowRecord from a Build Spec row.
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
    $this->label = $record['Label'];
    $this->machineName = $this->machineName($record['Machine name']);
    $this->type = strtolower(str_replace(' ', '_', $record['Type']));
    $this->operation = Operation::fromColumn($record['X']);
  }

  /**
   * {@inheritDoc}
   */
  public function relatedConfigs(): void {
    // No related configs for a workflow row.
  }

  /**
   * {@inheritDoc}
   */
  public function configExists(): bool {
    return (bool) Workflow::load($this->machineName);
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    if ($this->configExists()) {
      return;
    }

    $workflow = Workflow::create([
      'id' => $this->machineName,
      'label' => $this->label,
      'type' => $this->type,
    ]);

    $workflow->save();
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    if ($this->configExists()) {
      $workflow = Workflow::load($this->machineName);
      $workflow->delete();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [
      'label' => $this->label,
      'machine_name' => $this->machineName,
      'type' => $this->type,
      'operation' => $this->operation,
    ];

    return $array_keys ? array_keys($report) : $report;
  }

}
