<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\Entity\Role;

/**
 * Represents a single user-role row from the Build Spec.
 */
class UserRoleRecord extends BuildSpecRecord {

  use StringTranslationTrait;

  /**
   * The human-readable role label.
   *
   * @var string
   */
  public string $label;

  /**
   * The role machine name.
   *
   * @var string
   */
  public string $machineName;

  /**
   * Constructs a UserRoleRecord from a Build Spec row.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Shared transliteration service for machineName().
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Used by create/delete paths to inform the operator of the outcome.
   * @param array $record
   *   The CSV row keyed by column header.
   */
  public function __construct(
    TransliterationInterface $transliteration,
    private readonly MessengerInterface $messenger,
    array $record,
  ) {
    parent::__construct($transliteration);
    $this->label = $record['Name'];
    $this->machineName = $record['Machine name'];
    $this->operation = Operation::fromColumn($record['X']);
  }

  /**
   * {@inheritDoc}
   */
  public function relatedConfigs(): ?bool {
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function configExists(): bool {
    return (bool) Role::load($this->machineName);
  }

  /**
   * {@inheritDoc}
   */
  public function createConfig(): void {
    // Check if the role already exists.
    if (Role::load($this->machineName)) {
      $this->messenger->addMessage($this->t('The role %role already exists.', ['%role' => $this->label]));
      return;
    }

    // Create the new role.
    $role = Role::create([
      'id' => $this->machineName,
      'label' => $this->label,
    ]);

    // Save the role.
    $role->save();
    $this->messenger->addMessage($this->t('The role %role has been created.', ['%role' => $this->label]));
  }

  /**
   * {@inheritDoc}
   */
  public function deleteConfig(): void {
    // Load the role by ID.
    $role = Role::load($this->machineName);
    if ($role) {
      // Delete the role.
      $role->delete();
      $this->messenger->addMessage($this->t('The role %role has been deleted.', ['%role' => $this->machineName]));
    }
    else {
      $this->messenger->addMessage($this->t('The role %role does not exist.', ['%role' => $this->machineName]), 'error');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getReport(bool $array_keys = FALSE): array {
    $report = [
      'id' => $this->machineName,
      'label' => $this->label,
      'operation' => $this->operation,
    ];

    return $array_keys ? array_keys($report) : $report;
  }

}
