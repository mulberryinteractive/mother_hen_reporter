<?php

declare(strict_types=1);

namespace Drupal\Tests\mother_hen_reporter\Kernel;

use Composer\InstalledVersions;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mother_hen_reporter\InventoryCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the inventory payload.
 */
#[CoversClass(InventoryCollector::class)]
#[Group('mother_hen_reporter')]
final class InventoryCollectorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['mother_hen_reporter', 'key', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['mother_hen_reporter']);
    $this->config('mother_hen_reporter.settings')
      ->set('site_id', '6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f')
      ->set('environment', 'live')
      ->save();
  }

  /**
   * Returns the collector.
   */
  private function collector(): InventoryCollector {
    return $this->container->get(InventoryCollector::class);
  }

  /**
   * Tests that the payload holds versions, install state and nothing else.
   */
  public function testPayload(): void {
    $payload = $this->collector()->collect();

    $this->assertSame(
      [
        'schema', 'site', 'environment', 'captured_at', 'reporter_version',
        'drupal', 'php_version', 'packages', 'extensions', 'patches',
      ],
      array_keys($payload),
    );
    $this->assertSame('6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f', $payload['site']);
    $this->assertSame('live', $payload['environment']);
    $this->assertSame(\Drupal::VERSION, $payload['drupal']['core_version']);

    $packages = array_column($payload['packages'], NULL, 'name');
    $this->assertSame(InstalledVersions::getPrettyVersion('drupal/core'), $packages['drupal/core']['version']);
    $this->assertArrayNotHasKey(InstalledVersions::getRootPackage()['name'], $packages);

    $extensions = array_column($payload['extensions'], NULL, 'name');
    $this->assertTrue($extensions['system']['installed']);
    $this->assertSame('drupal', $extensions['system']['project']);
    $this->assertFalse($extensions['node']['installed'], 'Available but not installed.');
  }

}
