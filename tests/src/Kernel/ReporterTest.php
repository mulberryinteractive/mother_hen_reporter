<?php

declare(strict_types=1);

namespace Drupal\Tests\mother_hen_reporter\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\mother_hen_reporter\Reporter;
use Drupal\mother_hen_reporter\ReporterSettings;
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
  protected static $modules = ['mother_hen_reporter', 'key', 'system'];

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
    $this->installConfig(['mother_hen_reporter']);
    $this->keys = Signer::generateKeyPair();
    Key::create([
      'id' => 'mother_hen',
      'label' => 'Mother Hen private key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => $this->keys['secret']],
    ])->save();
    putenv('PLATFORM_BRANCH');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('PLATFORM_BRANCH');
    parent::tearDown();
  }

  /**
   * Configures the reporter, with the private key held in a Key entity.
   */
  private function configure(array $overrides = []): void {
    $this->config('mother_hen_reporter.settings')->setData($overrides + [
      'endpoint' => 'https://dashboard.example/api/mother-hen/inventory',
      'site_id' => '6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f',
      'private_key' => 'mother_hen',
      'environment' => '',
      'allow_insecure_endpoint' => FALSE,
    ])->save();
  }

  /**
   * Returns the reporter backed by queued responses.
   */
  private function reporter(array $responses = []): Reporter {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    // Rebuild services so changed settings and the new client take effect.
    $this->container->set(Reporter::class, NULL);
    $this->container->set(ReporterSettings::class, NULL);
    return $this->container->get(Reporter::class);
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
   * Tests that reports are re-sent only when changed, due or failed.
   */
  public function testResendsOnlyWhenNeeded(): void {
    $this->configure();
    $reporter = $this->reporter([
      new Response(401, [], '{"error":"Request signature is not valid."}'),
      new Response(202),
      new Response(202),
    ]);

    $failed = $reporter->maybeReport();
    $this->assertSame('Request signature is not valid.', $failed->message);
    $this->assertTrue($reporter->maybeReport()->success, 'A failed report is retried.');
    $this->assertNull($reporter->maybeReport(), 'Unchanged within the heartbeat: not re-sent.');
    $this->assertTrue($reporter->report()->success, 'A forced report always sends.');
    $this->assertCount(3, $this->history);
  }

  /**
   * Tests that nothing is sent unless configured and over HTTPS.
   */
  public function testSilentUnlessConfigured(): void {
    $this->assertNull($this->reporter()->maybeReport(), 'Not configured.');

    $this->configure(['endpoint' => 'http://dashboard.example/api/mother-hen/inventory']);
    $this->assertNull($this->reporter()->maybeReport(), 'Plain HTTP.');
    $this->assertSame([], $this->history);

    // A key without a value in this environment (e.g. staging, where the
    // variable is not set) is silent, and says why.
    Key::create([
      'id' => 'env_key',
      'label' => 'From the environment',
      'key_type' => 'authentication',
      'key_provider' => 'env',
      'key_provider_settings' => ['env_variable' => 'MOTHER_HEN_TEST_UNSET'],
    ])->save();
    $this->configure(['private_key' => 'env_key']);
    $reporter = $this->reporter();
    $this->assertNull($reporter->maybeReport());
    $this->assertStringContainsString('has no value in this environment', $this->container->get(ReporterSettings::class)->disabledReason());
  }

  /**
   * Tests that any environment reports, named after its Upsun branch.
   */
  public function testReportsEnvironmentName(): void {
    $this->configure();
    putenv('PLATFORM_BRANCH=staging');
    $this->assertTrue($this->reporter([new Response(202)])->maybeReport()->success);
    $this->assertSame('staging', json_decode((string) $this->history[0]['request']->getBody(), TRUE)['environment']);

    // An explicit setting wins.
    $this->configure(['environment' => 'live']);
    $this->assertTrue($this->reporter([new Response(202)])->report()->success);
    $this->assertSame('live', json_decode((string) end($this->history)['request']->getBody(), TRUE)['environment']);
  }

}
