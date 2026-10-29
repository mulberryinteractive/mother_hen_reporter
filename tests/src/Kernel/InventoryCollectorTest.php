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
  protected static $modules = ['mother_hen_reporter', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('mother_hen_reporter', [
      'site_id' => '6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f',
      'environment' => 'live',
    ]);
  }

  /**
   * Collects a payload.
   */
  private function collect(): array {
    return $this->container->get(InventoryCollector::class)->collect();
  }

  /**
   * Tests the payload envelope.
   */
  public function testEnvelope(): void {
    $payload = $this->collect();

    $this->assertSame(1, $payload['schema']);
    $this->assertSame('6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f', $payload['site']);
    $this->assertSame('live', $payload['environment']);
    $this->assertSame(\Drupal::VERSION, $payload['drupal']['core_version']);
    $this->assertSame(PHP_VERSION, $payload['php_version']);
    $this->assertIsInt($payload['captured_at']);
  }

  /**
   * Tests that Composer packages are listed, without the root project.
   */
  public function testPackages(): void {
    $packages = array_column($this->collect()['packages'], NULL, 'name');

    $this->assertArrayHasKey('drupal/core', $packages);
    $this->assertSame(InstalledVersions::getPrettyVersion('drupal/core'), $packages['drupal/core']['version']);
    $this->assertSame('drupal-core', $packages['drupal/core']['type']);
    $this->assertArrayNotHasKey(InstalledVersions::getRootPackage()['name'], $packages);
    $this->assertSame(array_keys($packages), array_column($this->collect()['packages'], 'name'), 'Unique names.');
  }

  /**
   * Tests extensions with installed state and project.
   */
  public function testExtensions(): void {
    $extensions = array_column($this->collect()['extensions'], NULL, 'name');

    $this->assertTrue($extensions['system']['installed']);
    $this->assertSame('drupal', $extensions['system']['project']);
    $this->assertSame('module', $extensions['system']['type']);
    $this->assertFalse($extensions['node']['installed'], 'Available but not installed.');
    $this->assertSame('theme', $extensions['claro']['type']);
  }

  /**
   * Tests that nothing beyond versions is sent.
   */
  public function testPayloadKeys(): void {
    $this->assertSame(
      [
        'schema',
        'site',
        'environment',
        'captured_at',
        'reporter_version',
        'drupal',
        'php_version',
        'packages',
        'extensions',
        'patches',
      ],
      array_keys($this->collect()),
    );
  }

}
