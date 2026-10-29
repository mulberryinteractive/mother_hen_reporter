<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends signed inventory reports to the dashboard.
 */
final class Reporter {

  /**
   * State key holding the last attempt's outcome.
   */
  public const STATE = 'mother_hen_reporter.status';

  /**
   * Seconds between reports when nothing has changed.
   */
  public const HEARTBEAT = 3600;

  public function __construct(
    private readonly ReporterSettings $settings,
    private readonly InventoryCollector $collector,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reports if the inventory changed or the heartbeat interval has passed.
   *
   * @return \Drupal\mother_hen_reporter\ReportResult|null
   *   The result, or NULL when reporting is disabled or not due.
   */
  public function maybeReport(): ?ReportResult {
    if ($this->settings->disabledReason() !== NULL) {
      return NULL;
    }
    $payload = $this->collector->collect();
    $status = $this->status();
    $unchanged = ($status['hash'] ?? NULL) === $this->fingerprint($payload);
    $recent = $this->time->getCurrentTime() - (int) ($status['succeeded'] ?? 0) < self::HEARTBEAT;
    return $unchanged && $recent ? NULL : $this->send($payload);
  }

  /**
   * Reports now.
   */
  public function report(): ReportResult {
    $reason = $this->settings->disabledReason();
    if ($reason !== NULL) {
      return new ReportResult(FALSE, 0, $reason);
    }
    return $this->send($this->collector->collect());
  }

  /**
   * Returns the last attempt's recorded status.
   *
   * @return array{attempted?: int, succeeded?: int, code?: int, message?: string, hash?: string}
   *   The stored status.
   */
  public function status(): array {
    return $this->state->get(self::STATE, []);
  }

  /**
   * Signs and posts a payload, recording the outcome.
   */
  private function send(array $payload): ReportResult {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = $this->time->getCurrentTime();
    $nonce = bin2hex(random_bytes(16));
    $site = $this->settings->siteId();

    try {
      $response = $this->httpClient->request('POST', $this->settings->endpoint(), [
        'headers' => [
          'Content-Type' => 'application/json',
          'User-Agent' => 'MotherHenReporter/' . InventoryCollector::REPORTER_VERSION,
          'X-Mother-Hen-Site' => $site,
          'X-Mother-Hen-Timestamp' => (string) $timestamp,
          'X-Mother-Hen-Nonce' => $nonce,
          'X-Mother-Hen-Signature' => Signer::sign($this->settings->privateKey(), $site, $timestamp, $nonce, $body),
        ],
        'body' => $body,
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
      $code = $response->getStatusCode();
      $error = json_decode((string) $response->getBody(), TRUE)['error'] ?? NULL;
      $result = new ReportResult($code === 202, $code, $code === 202 ? 'Accepted' : (string) ($error ?? "HTTP $code"));
    }
    catch (GuzzleException | \InvalidArgumentException $e) {
      $result = new ReportResult(FALSE, 0, $e->getMessage());
    }

    $status = $this->status();
    $status['attempted'] = $timestamp;
    $status['code'] = $result->statusCode;
    $status['message'] = $result->message;
    if ($result->success) {
      $status['succeeded'] = $timestamp;
      $status['hash'] = $this->fingerprint($payload);
    }
    else {
      $this->logger->warning('Mother Hen report failed: @message', ['@message' => $result->message]);
    }
    $this->state->set(self::STATE, $status);
    return $result;
  }

  /**
   * Hashes a payload, ignoring the capture time.
   */
  private function fingerprint(array $payload): string {
    unset($payload['captured_at']);
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

}
