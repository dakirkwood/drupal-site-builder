<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\views\Entity\View;

/**
 * Represents a single view-display row from the Build Spec.
 */
class ViewsDisplaysRecord extends BuildSpecRecord {

  /**
   * The human-readable name of the parent view.
   *
   * @var string
   */
  public string $view;

  /**
   * The machine name of the parent view.
   *
   * @var string
   */
  public string $viewId;

  /**
   * The display title.
   *
   * @var string
   */
  public string $title;

  /**
   * The display machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * The display plugin ID (for example "page" or "block").
   *
   * @var string
   */
  public string $plugin;

  /**
   * Errors collected while creating or deleting the display.
   *
   * @var array
   */
  public array $errors = [];

  /**
   * Constructs a ViewsDisplaysRecord from a Build Spec row.
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
    $this->view = $record['View'];
    $this->viewId = $record['View id'];
    $this->title = $record['Title'];
    $this->machineName = $record['Machine name'];
    $this->plugin = strtolower(str_replace(' ', '_', $record['Display plugin']));
    $this->operation = Operation::fromColumn($record['X']);
  }

  /**
   * {@inheritDoc}
   */
  public function relatedConfigs(): void {
    // No related configs for a display row.
  }

  /**
   * {@inheritDoc}
   */
  public function configExists(): bool {
    $view = View::load($this->viewId);
    if (!$view) {
      return FALSE;
    }
    $displays = $view->get('display');
    return isset($displays[$this->machineName]);
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    // Load the view.
    $view = View::load($this->viewId);
    // Log the error and return early if the view is not found.
    if (!$view) {
      $this->errors[] = "View $this->view not found.";
      return;
    }
    // Add the display and save.
    $view->addDisplay($this->plugin, $this->title, $this->machineName);
    $displays = $view->get('display');
    $displays[$this->machineName]['display_options']['title'] = $this->title;
    $view->set('display', $displays);
    $view->save();
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    // Load the view.
    $view = View::load($this->viewId);
    if (!$view) {
      $this->errors[] = "View $this->view doesn't exist.";
      return;
    }
    // Get the list of displays.
    $displays = $view->get('display');
    // Remove this display if it exists.
    if (isset($displays[$this->machineName])) {
      unset($displays[$this->machineName]);
      $view->set('display', $displays);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [];
    $report['View'] = $this->view;
    $report['Disp Title'] = $this->title;
    $report['Machine name'] = $this->machineName;
    $report['Plugin'] = $this->plugin;
    $report['Operation'] = $this->operation;
    $report['Errors'] = implode("\n", $this->errors);
    if ($array_keys) {
      return array_keys($report);
    }
    return $report;
  }

}
