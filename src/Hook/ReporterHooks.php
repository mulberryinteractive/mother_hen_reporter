<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter\Hook;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mother_hen_reporter\Reporter;
use Drupal\mother_hen_reporter\ReporterSettings;

/**
 * Hook implementations for Mother Hen Reporter.
 */
final class ReporterHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly Reporter $reporter,
    private readonly ReporterSettings $settings,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->reporter->maybeReport();
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.mother_hen_reporter') {
      return NULL;
    }
    return '<p>' . $this->t("Sends this site's installed Drupal core, module and Composer package versions to a Mother Hen security dashboard during cron, signed with this site's private key. Run <code>drush mother-hen:keygen</code> to create a key pair, store the private key with the Key module (preferably from an environment variable), and configure the reporter at Configuration > System > Mother Hen reporter. Run <code>drush mother-hen:payload</code> to see exactly what is sent.") . '</p>';
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    if ($this->settings->hasLegacySettings()) {
      return [
        'mother_hen_reporter' => [
          'title' => $this->t('Mother Hen reporter'),
          'value' => $this->t('Old settings.php configuration found'),
          'description' => $this->t('Since version 2.0, the old mother_hen_reporter block in settings.php is ignored. Configure the reporter at Configuration > System > Mother Hen reporter, then remove the block.'),
          // REQUIREMENT_WARNING / RequirementSeverity::Warning both equal 1.
          'severity' => 1,
        ],
      ];
    }
    $reason = $this->settings->disabledReason();
    $status = $this->reporter->status();

    if ($reason !== NULL) {
      // REQUIREMENT_INFO / RequirementSeverity::Info both equal -1.
      $severity = -1;
      $value = $this->t('Not reporting');
      $description = $reason;
    }
    elseif (empty($status['attempted'])) {
      $severity = 1;
      $value = $this->t('No report sent yet');
      $description = $this->t('A report is sent on the next cron run.');
    }
    elseif (($status['code'] ?? 0) === 202) {
      $severity = 0;
      $value = $this->t('Last report accepted @ago ago', ['@ago' => $this->dateFormatter->formatTimeDiffSince($status['attempted'])]);
      $description = NULL;
    }
    else {
      $severity = 2;
      $value = $this->t('Last report failed @ago ago', ['@ago' => $this->dateFormatter->formatTimeDiffSince($status['attempted'])]);
      $description = $status['message'] ?? '';
    }

    return [
      'mother_hen_reporter' => [
        'title' => $this->t('Mother Hen reporter'),
        'value' => $value,
        'description' => $description,
        'severity' => $severity,
      ],
    ];
  }

}
