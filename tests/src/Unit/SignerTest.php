<?php

declare(strict_types=1);

namespace Drupal\Tests\mother_hen_reporter\Unit;

use Drupal\mother_hen_reporter\Signer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests request signing against the vector shared with the dashboard.
 *
 * The dashboard's RequestSignatureTest verifies exactly this signature;
 * Ed25519 is deterministic, so any change to the scheme breaks one side.
 */
#[CoversClass(Signer::class)]
#[Group('mother_hen_reporter')]
final class SignerTest extends TestCase {

  private const SECRET_KEY = 'BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwfqSmxj4pxSCr71UHsTLsX5lUd2rr6+e5JCHuppFEbSLA==';
  private const PUBLIC_KEY = '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=';
  private const SITE = '6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f';
  private const TIMESTAMP = 1793200000;
  private const NONCE = '0123456789abcdef0123456789abcdef';
  private const BODY = '{"schema":1}';
  private const SIGNATURE = 'GbrpEB7Prs+gPCDRvD3VyphgKNnPd8gNH4MQlm12Fg4XKvCfdIvG0TZWGSqNwnPul8Pe0ShT13cyq/ZMNEluBg==';

  /**
   * Tests that signing reproduces the shared vector.
   */
  public function testProducesSharedVector(): void {
    $this->assertSame(self::SIGNATURE, Signer::sign(self::SECRET_KEY, self::SITE, self::TIMESTAMP, self::NONCE, self::BODY));
  }

  /**
   * Tests that generated key pairs work together.
   */
  public function testGeneratedKeyPairVerifies(): void {
    $keys = Signer::generateKeyPair();
    $signature = Signer::sign($keys['secret'], self::SITE, self::TIMESTAMP, self::NONCE, self::BODY);

    $this->assertTrue(sodium_crypto_sign_verify_detached(
      base64_decode($signature),
      Signer::canonicalMessage(self::SITE, self::TIMESTAMP, self::NONCE, self::BODY),
      base64_decode($keys['public']),
    ));
  }

}
