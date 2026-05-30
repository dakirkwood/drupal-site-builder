<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_site_builder\Unit;

use Drupal\drupal_site_builder\MachineName;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the MachineName label-to-machine-name converter.
 */
#[Group('drupal_site_builder')]
#[CoversClass(MachineName::class)]
class MachineNameTest extends UnitTestCase {

  /**
   * Labels and their expected machine names.
   *
   * @return array<string, array{string, string}>
   *   Each case: [label, expected machine name].
   */
  public static function labelProvider(): array {
    return [
      'simple' => ['Teaser', 'teaser'],
      'spaced' => ['Content Thumbnail', 'content_thumbnail'],
      'punctuation' => ['42x42 1.5x', '42x42_1_5x'],
      'already lower' => ['teaser', 'teaser'],
    ];
  }

  /**
   * Generate() lowercases, replaces non-word characters and caps at 32 chars.
   */
  #[DataProvider('labelProvider')]
  public function testGenerate(string $label, string $expected): void {
    $this->assertSame($expected, MachineName::generate($label));
  }

  /**
   * The result never exceeds 32 characters.
   */
  public function testTruncatesToThirtyTwoCharacters(): void {
    $generated = MachineName::generate(str_repeat('a', 40));
    $this->assertSame(32, strlen($generated));
    $this->assertSame(str_repeat('a', 32), $generated);
  }

}
