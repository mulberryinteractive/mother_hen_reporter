<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter;

use Composer\InstalledVersions;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;

/**
 * Builds the inventory payload sent to the dashboard.
 *
 * Contains versions only: no content, users, configuration or credentials.
 * Use "drush mother-hen:payload" to inspect exactly what is sent.
 */
final class InventoryCollector {

  /**
   * Payload schema version understood by the dashboard.
   */
  public const SCHEMA = 1;

  /**
   * Reporter version included in each payload.
   */
  public const REPORTER_VERSION = '2.0.0';

  public function __construct(
    private readonly ReporterSettings $settings,
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeExtensionList $themeList,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Collects the current inventory.
   */
  public function collect(): array {
    return [
      'schema' => self::SCHEMA,
      'site' => $this->settings->siteId(),
      'environment' => $this->settings->environment(),
      'captured_at' => $this->time->getCurrentTime(),
      'reporter_version' => self::REPORTER_VERSION,
      'drupal' => ['core_version' => \Drupal::VERSION],
      'php_version' => PHP_VERSION,
      'packages' => $this->packages(),
      'extensions' => $this->extensions(),
      'patches' => $this->patches(),
    ];
  }

  /**
   * Lists installed Composer packages, excluding the root project.
   *
   * @return list<array{name: string, version: string, reference: ?string, type: ?string, dev: bool}>
   *   Packages sorted by name.
   */
  private function packages(): array {
    $root = InstalledVersions::getRootPackage()['name'] ?? NULL;
    $packages = [];
    foreach (InstalledVersions::getAllRawData() as $dataset) {
      foreach ($dataset['versions'] ?? [] as $name => $package) {
        // Replaced or provided packages have no installed version of their own.
        if ($name === $root || !isset($package['pretty_version'])) {
          continue;
        }
        $packages[$name] = [
          'name' => $name,
          'version' => $package['pretty_version'],
          'reference' => $package['reference'] ?? NULL,
          'type' => $package['type'] ?? NULL,
          'dev' => !empty($package['dev_requirement']),
        ];
      }
    }
    ksort($packages);
    return array_values($packages);
  }

  /**
   * Lists available modules and themes with their installed state.
   *
   * @return list<array{name: string, type: string, project: ?string, version: ?string, installed: bool}>
   *   Extensions sorted by type and name.
   */
  private function extensions(): array {
    $extensions = [];
    foreach (['module' => $this->moduleList, 'theme' => $this->themeList] as $type => $list) {
      foreach ($list->getList() as $name => $extension) {
        $extensions[] = [
          'name' => $name,
          'type' => $type,
          'project' => $this->project($extension),
          'version' => $extension->info['version'] ?? NULL,
          'installed' => !empty($extension->status),
        ];
      }
    }
    return $extensions;
  }

  /**
   * Determines the drupal.org project an extension belongs to.
   */
  private function project(Extension $extension): ?string {
    if (str_starts_with($extension->getPath(), 'core/')) {
      return 'drupal';
    }
    return $extension->info['project'] ?? NULL;
  }

  /**
   * Reads composer-patches 2.x patches.lock.json, if present.
   */
  private function patches(): ?array {
    $root = InstalledVersions::getRootPackage()['install_path'] ?? NULL;
    $file = $root ? rtrim($root, '/') . '/patches.lock.json' : NULL;
    if (!$file || !is_readable($file)) {
      return NULL;
    }
    $patches = json_decode((string) file_get_contents($file), TRUE);
    return is_array($patches) ? $patches : NULL;
  }

}
