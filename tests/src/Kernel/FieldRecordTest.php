<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_site_builder\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_site_builder\FieldRecord;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that FieldRecord creates field storage and field config from a row.
 */
#[Group('drupal_site_builder')]
#[CoversClass(FieldRecord::class)]
#[RunTestsInSeparateProcesses]
class FieldRecordTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'drupal_site_builder',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'test_article', 'name' => 'Test Article'])->save();
  }

  /**
   * Builds a record through the factory the production code uses.
   *
   * @param array $row
   *   The CSV row keyed by Build Spec column header.
   *
   * @return \Drupal\drupal_site_builder\FieldRecord
   *   The factory-built field record.
   */
  protected function buildRecord(array $row): FieldRecord {
    /** @var \Drupal\drupal_site_builder\RecordFactory $factory */
    $factory = $this->container->get('drupal_site_builder.record_factory');
    $record = $factory->create('fields', $row);
    $this->assertInstanceOf(FieldRecord::class, $record);
    return $record;
  }

  /**
   * Builds a Build Spec row for a plain-text field on the test bundle.
   *
   * @param array $overrides
   *   Column values to override the defaults.
   *
   * @return array
   *   A CSV-style row keyed by Build Spec column header.
   */
  protected function fieldRecord(array $overrides = []): array {
    return $overrides + [
      'Bundle machine name' => 'test_article',
      'Bundle' => 'Content type',
      'Field label' => 'Subtitle',
      'Machine name' => 'field_subtitle',
      'Field type' => 'Text (plain)',
      'Form widget' => 'Textfield',
      'Vals.' => '1',
      "Req'd" => '',
      'Reused Field' => '',
      'Trns.' => '',
      'Field group' => '',
      'Help text' => 'A subtitle.',
      'Ref. bundle' => '',
      'X' => '',
    ];
  }

  /**
   * CreateConfig() creates both the field storage and the field instance.
   */
  public function testCreatesFieldStorageAndConfig(): void {
    $record = $this->buildRecord($this->fieldRecord());
    $this->assertSame('node', $record->entity);
    $this->assertSame('string', $record->typeId);
    $this->assertSame('string_textfield', $record->formWidget);
    $this->assertFalse($record->configExists());

    $record->createConfig();

    $storage = FieldStorageConfig::loadByName('node', 'field_subtitle');
    $this->assertNotNull($storage);
    $this->assertSame('string', $storage->getType());
    $this->assertSame(1, $storage->getCardinality());

    $field = FieldConfig::loadByName('node', 'test_article', 'field_subtitle');
    $this->assertNotNull($field);
    $this->assertSame('Subtitle', $field->getLabel());
    $this->assertFalse($field->isRequired());
    $this->assertFalse($field->isTranslatable());
    $this->assertTrue($record->configExists());
  }

  /**
   * An unlimited cardinality column ("*") maps to -1.
   */
  public function testUnlimitedCardinality(): void {
    $record = $this->buildRecord($this->fieldRecord(['Vals.' => '*']));
    $record->createConfig();

    $storage = FieldStorageConfig::loadByName('node', 'field_subtitle');
    $this->assertSame(-1, $storage->getCardinality());
  }

  /**
   * RelatedConfigs() enables the field on the bundle's form display.
   */
  public function testRelatedConfigsAddsToFormDisplay(): void {
    $record = $this->buildRecord($this->fieldRecord());
    $record->createConfig();
    $record->setFieldWeight(0);
    $record->relatedConfigs();

    $form_display = \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'test_article', 'default');
    $this->assertNotNull($form_display->getComponent('field_subtitle'));
  }

  /**
   * A row marked "d" deletes an existing field instance.
   */
  public function testDeletesField(): void {
    $this->buildRecord($this->fieldRecord())->createConfig();
    $this->assertNotNull(FieldConfig::loadByName('node', 'test_article', 'field_subtitle'));

    $delete = $this->buildRecord($this->fieldRecord(['X' => 'd']));
    $this->assertSame(-1, $delete->operation);
    $delete->deleteConfig();

    $this->assertNull(FieldConfig::loadByName('node', 'test_article', 'field_subtitle'));
  }

}
