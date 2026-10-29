<?php

declare(strict_types=1);

namespace Drupal\Tests\mother_hen_reporter\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mother_hen_reporter\Reporter;
use Drupal\mother_hen_reporter\Signer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests sending reports.
 */
#[CoversClass(Reporter::class)]
#[Group('mother_hen_reporter')]
final class ReporterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['mother_hen_reporter', 'system'];

  /**
   * Requests made through the mocked client.
   */
  private array $history = [];

  /**
   * The generated key pair.
   */
  private array $keys;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->keys = Signer::generateKeyPair();
    putenv('PLATFORM_ENVIRONMENT_TYPE');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('PLATFORM_ENVIRONMENT_TYPE');
    parent::tearDown();
  }

  /**
   * Configures the reporter.
   */
  private function configure(array $overrides = []): void {
    $this->setSetting('mother_hen_reporter', $overrides + [
      'endpoint' => 'https://dashboard.example/api/mother-hen/inventory',
      'site_id' => '6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f',
      'private_key' => $this->keys['secret'],
    ]);
  }

  /**
   * Returns the reporter backed by queued responses.
   */
  private function reporter(array $responses = []): Reporter {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    return $this->container->get(Reporter::class);
  }

  /**
   * Tests that an unconfigured site sends nothing.
   */
  public function testUnconfiguredSendsNothing(): void {
    $this->assertNull($this->reporter()->maybeReport());
    $this->assertSame([], $this->history);
  }

  /**
   * Tests that the request is signed over the exact body sent.
   */
  public function testSendsSignedReport(): void {
    $this->configure();
    $result = $this->reporter([new Response(202, [], '{"status":"accepted"}')])->maybeReport();

    $this->assertTrue($result->success);
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://dashboard.example/api/mother-hen/inventory', (string) $request->getUri());
    $body = (string) $request->getBody();
    $this->assertSame('6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f', json_decode($body, TRUE)['site']);
    $this->assertTrue(sodium_crypto_sign_verify_detached(
      base64_decode($request->getHeaderLine('X-Mother-Hen-Signature')),
      Signer::canonicalMessage(
        $request->getHeaderLine('X-Mother-Hen-Site'),
        (int) $request->getHeaderLine('X-Mother-Hen-Timestamp'),
        $request->getHeaderLine('X-Mother-Hen-Nonce'),
        $body,
      ),
      base64_decode($this->keys['public']),
    ));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $request->getHeaderLine('X-Mother-Hen-Nonce'));
  }

  /**
   * Tests that an unchanged inventory is not re-sent within the heartbeat.
   */
  public function testSkipsUnchangedWithinHeartbeat(): void {
    $this->configure();
    $reporter = $this->reporter([new Response(202), new Response(202)]);

    $this->assertNotNull($reporter->maybeReport());
    $this->assertNull($reporter->maybeReport());
    $this->assertCount(1, $this->history);
    // A forced report always sends.
    $this->assertTrue($reporter->report()->success);
    $this->assertCount(2, $this->history);
  }

  /**
   * Tests that a failed report is retried on the next run.
   */
  public function testRetriesAfterFailure(): void {
    $this->configure();
    $reporter = $this->reporter([
      new Response(401, [], '{"error":"Request signature is not valid."}'),
      new Response(202),
    ]);

    $failed = $reporter->maybeReport();
    $this->assertFalse($failed->success);
    $this->assertSame('Request signature is not valid.', $failed->message);
    $this->assertSame(401, $reporter->status()['code']);
    $this->assertTrue($reporter->maybeReport()->success);
  }

  /**
   * Tests that non-production Upsun environments stay silent.
   */
  public function testNonProductionUpsunEnvironmentIsSilent(): void {
    $this->configure();
    putenv('PLATFORM_ENVIRONMENT_TYPE=development');

    $this->assertNull($this->reporter()->maybeReport());
    $this->assertSame([], $this->history);
  }

  /**
   * Tests that production Upsun environments report.
   */
  public function testProductionUpsunEnvironmentReports(): void {
    $this->configure();
    putenv('PLATFORM_ENVIRONMENT_TYPE=production');

    $this->assertTrue($this->reporter([new Response(202)])->maybeReport()->success);
  }

  /**
   * Tests that a plain HTTP endpoint is refused unless explicitly allowed.
   */
  public function testRequiresHttps(): void {
    $this->configure(['endpoint' => 'http://dashboard.example/api/mother-hen/inventory']);
    $this->assertNull($this->reporter()->maybeReport());
    $this->assertSame([], $this->history);
  }

}
