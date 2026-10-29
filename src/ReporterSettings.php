<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * The reporter's settings: config, with the private key held by Key.
 *
 * Configure at Configuration > System > Mother Hen reporter. Override in
 * settings.php with $config['mother_hen_reporter.settings'][...] if needed.
 *
 * The private key is a Key entity, so it can come from an environment
 * variable (recommended) rather than configuration or the database. Where
 * the key has no value, e.g. on staging or local copies, nothing is sent.
 */
final class ReporterSettings {

  /**
   * Key providers that keep the value in configuration or the database.
   */
  private const UNSAFE_PROVIDERS = ['config', 'state'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * Returns why reporting is disabled, or NULL when it is enabled.
   */
  public function disabledReason(): ?string {
    if ($this->keyRepository === NULL) {
      return 'The Key module is not installed. Run database updates (drush updb).';
    }
    if ($this->endpoint() === '' || $this->siteId() === '' || $this->keyId() === '') {
      return 'Not configured: set the dashboard endpoint, site ID and private key at Configuration > System > Mother Hen reporter.';
    }
    if (!str_starts_with($this->endpoint(), 'https://') && !$this->config()->get('allow_insecure_endpoint')) {
      return 'The endpoint must use HTTPS.';
    }
    $key = $this->key();
    if ($key === NULL) {
      return sprintf('The private key "%s" does not exist.', $this->keyId());
    }
    if ($this->privateKey() === '') {
      return sprintf('The private key "%s" has no value in this environment, so this environment does not report.', $key->label());
    }
    return NULL;
  }

  /**
   * The dashboard endpoint URL.
   */
  public function endpoint(): string {
    return trim((string) $this->config()->get('endpoint'));
  }

  /**
   * The site UUID assigned by the dashboard.
   */
  public function siteId(): string {
    return trim((string) $this->config()->get('site_id'));
  }

  /**
   * The base64 Ed25519 secret key, or '' when it has no value here.
   */
  public function privateKey(): string {
    return trim((string) $this->key()?->getKeyValue());
  }

  /**
   * The public key matching the private key, for pasting into the dashboard.
   */
  public function publicKey(): ?string {
    $secret = base64_decode($this->privateKey(), TRUE);
    if ($secret === FALSE || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
      return NULL;
    }
    return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
  }

  /**
   * Warns when the private key is stored in configuration or the database.
   */
  public function keyStorageWarning(): ?string {
    $key = $this->key();
    if ($key === NULL || !in_array($key->getKeyProvider()->getPluginId(), self::UNSAFE_PROVIDERS, TRUE)) {
      return NULL;
    }
    return sprintf('The key "%s" is stored in configuration or the database, which usually ends up in Git or backups. Use the Environment or File provider instead.', $key->label());
  }

  /**
   * The environment name reported to the dashboard.
   *
   * On Upsun and Platform.sh each environment is named after its branch.
   */
  public function environment(): string {
    $configured = trim((string) $this->config()->get('environment'));
    if ($configured !== '') {
      return $configured;
    }
    $branch = getenv('PLATFORM_BRANCH');
    return is_string($branch) && $branch !== '' ? $branch : 'production';
  }

  /**
   * Whether settings.php still has the 1.x $settings['mother_hen_reporter'].
   */
  public function hasLegacySettings(): bool {
    return Settings::get('mother_hen_reporter') !== NULL;
  }

  /**
   * The configured Key entity ID.
   */
  private function keyId(): string {
    return trim((string) $this->config()->get('private_key'));
  }

  /**
   * The configured Key entity.
   */
  private function key(): ?KeyInterface {
    return $this->keyId() === '' || $this->keyRepository === NULL ? NULL : $this->keyRepository->getKey($this->keyId());
  }

  /**
   * The settings config.
   */
  private function config(): ImmutableConfig {
    return $this->configFactory->get('mother_hen_reporter.settings');
  }

}
