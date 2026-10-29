<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter;

/**
 * The outcome of a report attempt.
 */
final class ReportResult {

  public function __construct(
    public readonly bool $success,
    public readonly int $statusCode,
    public readonly string $message,
  ) {}

}
