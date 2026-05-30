<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\workflows\Entity\Workflow;

/**
 * Represents a single workflow-transition row from the Build Spec.
 */
class WorkflowTransitionRecord extends BuildSpecRecord {

  /**
   * The machine name of the workflow this transition belongs to.
   *
   * @var string
   */
  public string $workflowId;

  /**
   * The human-readable transition label.
   *
   * @var string
   */
  public string $label;

  /**
   * The transition machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The list of source state machine names for this transition.
   *
   * @var array
   */
  public array $fromState;

  /**
   * The destination state machine name for this transition.
   *
   * @var string
   */
  public string $toState;

  /**
   * Constructs a WorkflowTransitionRecord from a Build Spec row.
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
    $this->machineName = $record['Machine name'];
    $this->operation = Operation::fromColumn($record['X']);
    $this->fromState = array_map('trim', explode(',', $record['From']));
    $this->toState = $record['To'];
  }

  /**
   * {@inheritDoc}
   */
  public function relatedConfigs(): void {
    // No related configs for a transition row.
  }

  /**
   * {@inheritDoc}
   */
  public function configExists(): bool {
    $workflow = Workflow::load($this->workflowId);
    if ($workflow) {
      foreach ($workflow->get('type_settings')['transitions'] as $transition) {
        if ($transition['label'] === $this->label) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    if ($this->configExists()) {
      return;
    }

    $workflow = Workflow::load($this->workflowId);
    if ($workflow) {
      // Retrieve the current 'type_settings' array.
      $type_settings = $workflow->get('type_settings');

      // Ensure 'transitions' is initialized if it does not exist.
      if (!isset($type_settings['transitions'])) {
        $type_settings['transitions'] = [];
      }

      // Add or modify the transition.
      $type_settings['transitions'][$this->machineName] = [
        'label' => $this->label,
        // This needs to be an array.
        'from' => $this->fromState,
        'to' => $this->toState,
        'weight' => 0,
      ];

      // Save the modified 'type_settings' back to the workflow entity.
      $workflow->set('type_settings', $type_settings);
      $workflow->save();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    if ($this->configExists()) {
      $workflow = Workflow::load($this->workflowId);
      if ($workflow) {
        $transitions = $workflow->get('type_settings')['transitions'];
        unset($transitions[$this->machineName]);
        $workflow->set('type_settings', ['transitions' => $transitions]);
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
      'operation' => $this->operation,
      'from_state' => implode(', ', $this->fromState),
      'to_state' => $this->toState,
    ];

    return $array_keys ? array_keys($report) : $report;
  }

}
