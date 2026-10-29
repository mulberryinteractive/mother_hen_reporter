<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter;

use Drupal\Core\Site\Settings;

/**
 * Reads reporter settings from settings.php.
 *
 * @code
 * $settings['mother_hen_reporter'] = [
 *   'endpoint' => 'https://dashboard.example.com/api/mother-hen/inventory',
 *   'site_id' => getenv('MOTHER_HEN_SITE_ID'),
 *   'private_key' => getenv('MOTHER_HEN_PRIVATE_KEY'),
 *   // Optional. Defaults to the Upsun/Platform.sh branch name, otherwise
 *   // "production". The dashboard keeps reports only from environments it
 *   // monitors for the site.
 *   'environment' => 'production',
 * ];
 * @endcode
 *
 * Secrets are deliberately not stored in configuration or the database.
 */
final class ReporterSettings {

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * Returns why reporting is disabled, or NULL when it is enabled.
   */
  public function disabledReason(): ?string {
    $settings = $this->all();
    if (empty($settings['endpoint']) || empty($settings['site_id']) || empty($settings['private_key'])) {
      return 'Not configured: endpoint, site_id and private_key are required in $settings[\'mother_hen_reporter\'].';
    }
    if (!str_starts_with((string) $settings['endpoint'], 'https://') && empty($settings['allow_insecure_endpoint'])) {
      return 'The endpoint must use HTTPS.';
    }
    return NULL;
  }

  /**
   * The dashboard endpoint URL.
   */
  public function endpoint(): string {
    return (string) ($this->all()['endpoint'] ?? '');
  }

  /**
   * The site UUID assigned by the dashboard.
   */
  public function siteId(): string {
    return (string) ($this->all()['site_id'] ?? '');
  }

  /**
   * The base64 Ed25519 secret key.
   */
  public function privateKey(): string {
    return (string) ($this->all()['private_key'] ?? '');
  }

  /**
   * The environment name reported to the dashboard.
   *
   * On Upsun and Platform.sh each environment is named after its branch.
   */
  public function environment(): string {
    $configured = $this->all()['environment'] ?? NULL;
    if (is_string($configured) && $configured !== '') {
      return $configured;
    }
    $branch = getenv('PLATFORM_BRANCH');
    return is_string($branch) && $branch !== '' ? $branch : 'production';
  }

  /**
   * Returns the raw settings array.
   */
  private function all(): array {
    $settings = $this->settings->get('mother_hen_reporter', []);
    return is_array($settings) ? $settings : [];
  }

}
