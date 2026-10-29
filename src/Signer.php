<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter;

/**
 * Ed25519 request signing, matching the Mother Hen dashboard.
 *
 * The signed message binds the site, timestamp and nonce to the SHA-256 of
 * the exact request body:
 *
 * @code
 * mother-hen-v1\n{site}\n{timestamp}\n{nonce}\n{sha256(body)}
 * @endcode
 */
final class Signer {

  /**
   * Scheme identifier; must match the dashboard.
   */
  public const VERSION = 'mother-hen-v1';

  /**
   * Builds the message that is signed.
   */
  public static function canonicalMessage(string $site, int $timestamp, string $nonce, string $body): string {
    return implode("\n", [self::VERSION, $site, (string) $timestamp, $nonce, hash('sha256', $body)]);
  }

  /**
   * Signs a request.
   *
   * @param string $secret_key
   *   Base64-encoded Ed25519 secret key (64 bytes decoded).
   * @param string $site
   *   The site UUID from the dashboard.
   * @param int $timestamp
   *   Unix timestamp.
   * @param string $nonce
   *   A nonce of 32 lowercase hex characters, unique per request.
   * @param string $body
   *   The exact request body.
   *
   * @return string
   *   The base64-encoded signature.
   *
   * @throws \InvalidArgumentException
   *   When the secret key is malformed.
   */
  public static function sign(string $secret_key, string $site, int $timestamp, string $nonce, string $body): string {
    $key = base64_decode($secret_key, TRUE);
    if ($key === FALSE || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
      throw new \InvalidArgumentException('The Mother Hen private key is not a valid Ed25519 secret key.');
    }
    return base64_encode(sodium_crypto_sign_detached(self::canonicalMessage($site, $timestamp, $nonce, $body), $key));
  }

  /**
   * Generates a new key pair.
   *
   * @return array{public: string, secret: string}
   *   Base64-encoded public and secret keys.
   */
  public static function generateKeyPair(): array {
    $pair = sodium_crypto_sign_keypair();
    return [
      'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
      'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
    ];
  }

}
