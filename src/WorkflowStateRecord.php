<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\workflows\Entity\Workflow;

/**
 * Represents a single workflow-state row from the Build Spec.
 */
class WorkflowStateRecord extends BuildSpecRecord {

  /**
   * The machine name of the workflow this state belongs to.
   *
   * @var string
   */
  public string $workflowId;

  /**
   * The human-readable state label.
   *
   * @var string
   */
  public string $label;

  /**
   * The state machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The state weight used for ordering.
   *
   * @var int
   */
  public int $weight;

  /**
   * Constructs a WorkflowStateRecord from a Build Spec row.
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
    $this->workflowId = $record['Workflow id'];
    $this->label = $record['Label'];
    $this->machineName = $this->machineName($record['Machine name']);
    $this->weight = (int) ($record['weight'] ?? 0);
    $this->operation = Operation::fromColumn($record['X']);
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
    return (bool) ContentModerationState::load($this->machineName);
  }

  /**
   * {@inheritDoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createConfig(): void {
    if ($this->configExists()) {
      return;
    }

    // Attach the state to the workflow.
    $workflow = Workflow::load($this->workflowId);
    if ($workflow) {
      $states = $workflow->get('type_settings')['states'];
      $states[$this->machineName] = [
        'label' => $this->label,
        'weight' => 0,
        'published' => TRUE,
      ];
      $workflow->set('type_settings', ['states' => $states]);
      $workflow->save();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    if ($this->configExists()) {
      $moderation_state = ContentModerationState::load($this->machineName);
      $moderation_state->delete();

      // Remove the state from the workflow.
      $workflow = Workflow::load($this->workflowId);
      if ($workflow) {
        $states = $workflow->get('type_settings')['states'];
        unset($states[$this->machineName]);
        $workflow->set('type_settings', ['states' => $states]);
        $workflow->save();
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [
      'workflow_id' => $this->workflowId,
      'label' => $this->label,
      'machine_name' => $this->machineName,
      'weight' => $this->weight,
      'operation' => $this->operation,
    ];

    return $array_keys ? array_keys($report) : $report;
  }

}
