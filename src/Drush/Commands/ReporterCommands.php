<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter\Drush\Commands;

use Drupal\mother_hen_reporter\InventoryCollector;
use Drupal\mother_hen_reporter\Reporter;
use Drupal\mother_hen_reporter\Signer;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Mother Hen Reporter.
 */
final class ReporterCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Reporter $reporter,
    private readonly InventoryCollector $collector,
  ) {
    parent::__construct();
  }

  /**
   * Generates a key pair for this site.
   */
  #[CLI\Command(name: 'mother-hen:keygen')]
  #[CLI\Usage(name: 'drush mother-hen:keygen', description: 'Print a new key pair and the settings to add.')]
  public function keygen(): void {
    $keys = Signer::generateKeyPair();
    $this->output()->writeln([
      'Public key (paste into the site on the Mother Hen dashboard):',
      '',
      '  ' . $keys['public'],
      '',
      'Private key (store as a secret environment variable, production only; never commit it):',
      '',
      '  MOTHER_HEN_PRIVATE_KEY=' . $keys['secret'],
      '',
      'settings.php:',
      '',
      "  \$settings['mother_hen_reporter'] = [",
      "    'endpoint' => 'https://YOUR-DASHBOARD/api/mother-hen/inventory',",
      "    'site_id' => getenv('MOTHER_HEN_SITE_ID'),",
      "    'private_key' => getenv('MOTHER_HEN_PRIVATE_KEY'),",
      "  ];",
    ]);
  }

  /**
   * Sends a report now, regardless of schedule.
   */
  #[CLI\Command(name: 'mother-hen:report')]
  public function report(): int {
    $result = $this->reporter->report();
    if ($result->success) {
      $this->logger()->success('Report accepted by the dashboard.');
      return self::EXIT_SUCCESS;
    }
    $this->logger()->error('Report not accepted: ' . $result->message);
    return self::EXIT_FAILURE;
  }

  /**
   * Prints the payload that would be sent.
   */
  #[CLI\Command(name: 'mother-hen:payload')]
  public function payload(): void {
    $this->output()->writeln(json_encode($this->collector->collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

}
