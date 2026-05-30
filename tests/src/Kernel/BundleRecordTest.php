<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_site_builder\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_site_builder\BundleRecord;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that BundleRecord creates and deletes node bundles from a spec row.
 */
#[Group('drupal_site_builder')]
#[CoversClass(BundleRecord::class)]
#[RunTestsInSeparateProcesses]
class BundleRecordTest extends KernelTestBase {

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
   * Builds a record through the factory the production code uses.
   *
   * @param array $row
   *   The CSV row keyed by Build Spec column header.
   *
   * @return \Drupal\drupal_site_builder\BundleRecord
   *   The factory-built bundle record.
   */
  protected function buildRecord(array $row): BundleRecord {
    /** @var \Drupal\drupal_site_builder\RecordFactory $factory */
    $factory = $this->container->get('drupal_site_builder.record_factory');
    $record = $factory->create('bundles', $row);
    $this->assertInstanceOf(BundleRecord::class, $record);
    return $record;
  }

  /**
   * Builds a Build Spec row for a node content type.
   *
   * @param array $overrides
   *   Column values to override the defaults.
   *
   * @return array
   *   A CSV-style row keyed by Build Spec column header.
   */
  protected function nodeRecord(array $overrides = []): array {
    return $overrides + [
      'Name' => 'Test Article',
      'Description' => 'A test content type.',
      'Machine name' => 'test_article',
      'Mod.' => '',
      'Layout' => '',
      'Trns.' => '',
      'Migr.' => '',
      'Meta' => '',
      'Sched.' => '',
      'Srch.' => '',
      'URL alias pattern' => '',
      'X' => '',
      'Type' => 'Content type',
    ];
  }

  /**
   * The create operation produces the node type.
   */
  public function testCreatesNodeBundle(): void {
    $record = $this->buildRecord($this->nodeRecord());
    $this->assertSame('node', $record->entity);
    $this->assertSame(1, $record->operation);
    $this->assertFalse($record->configExists());

    $this->assertTrue($record->createConfig());

    $node_type = NodeType::load('test_article');
    $this->assertNotNull($node_type);
    $this->assertSame('Test Article', $node_type->label());
    $this->assertTrue($record->configExists());
  }

  /**
   * RelatedConfigs() creates the default form and view displays.
   */
  public function testRelatedConfigsCreatesDisplays(): void {
    $record = $this->buildRecord($this->nodeRecord());
    $record->createConfig();
    $record->relatedConfigs();

    $this->assertNotNull(EntityFormDisplay::load('node.test_article.default'));
    $this->assertNotNull(EntityViewDisplay::load('node.test_article.default'));
  }

  /**
   * A row marked "d" deletes an existing node type.
   */
  public function testDeletesNodeBundle(): void {
    $this->buildRecord($this->nodeRecord())->createConfig();
    $this->assertNotNull(NodeType::load('test_article'));

    $delete = $this->buildRecord($this->nodeRecord(['X' => 'd']));
    $this->assertSame(-1, $delete->operation);
    $this->assertTrue($delete->deleteConfig());
    $this->assertNull(NodeType::load('test_article'));
  }

  /**
   * The X column maps to the create/delete/ignore operation codes.
   */
  public function testOperationMapping(): void {
    $this->assertSame(1, $this->buildRecord($this->nodeRecord())->operation);
    $this->assertSame(-1, $this->buildRecord($this->nodeRecord(['X' => 'd']))->operation);
    $this->assertSame(0, $this->buildRecord($this->nodeRecord(['X' => 'x']))->operation);
  }

}
