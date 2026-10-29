<?php

declare(strict_types=1);

namespace Drupal\Tests\mother_hen_reporter\Functional;

use Drupal\key\Entity\Key;
use Drupal\mother_hen_reporter\Form\SettingsForm;
use Drupal\mother_hen_reporter\Signer;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests configuring the reporter in the UI.
 */
#[CoversClass(SettingsForm::class)]
#[Group('mother_hen_reporter')]
final class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['mother_hen_reporter'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests saving settings, the derived public key, and the storage warning.
   */
  public function testSettingsForm(): void {
    $keys = Signer::generateKeyPair();
    Key::create([
      'id' => 'mother_hen',
      'label' => 'Mother Hen private key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => $keys['secret']],
    ])->save();

    $this->drupalGet('/admin/config/system/mother-hen-reporter');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer mother hen reporter']));
    $this->drupalGet('/admin/config/system/mother-hen-reporter');
    $this->submitForm([
      'endpoint' => 'https://dashboard.example/api/mother-hen/inventory',
      'site_id' => 'not-a-uuid',
      'private_key' => 'mother_hen',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The site ID is the UUID shown on the dashboard');

    $this->submitForm(['site_id' => '6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f'], 'Save configuration');
    $config = $this->config('mother_hen_reporter.settings');
    $this->assertSame('6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f', $config->get('site_id'));
    $this->assertSame('mother_hen', $config->get('private_key'));

    // The public key to paste into the dashboard is shown.
    $this->assertSession()->pageTextContains($keys['public']);
    // A key stored in configuration would end up in Git: warn.
    $this->assertSession()->pageTextContains('stored in configuration');
  }

}
