<?php

namespace SocialDept\AtpSpaces\Auth;

use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * A P-256 keypair for OAuth DPoP with a user's PDS, as the dev OAuth script
 * and the live tests use.
 *
 * Space credentials no longer use DPoP. They are bound to a
 * {@see CredentialKey} and presented with an HTTP Message Signature.
 */
class DpopKeypair
{
    public const CURVE = 'secp256r1';
    public const ALGORITHM = 'ES256';

    protected function __construct(
        protected readonly PrivateKey $privateKey,
    ) {
    }

    /**
     * Generate a fresh keypair.
     */
    public static function generate(): static
    {
        $key = EC::createKey(self::CURVE);

        if (! $key instanceof PrivateKey) {
            throw new InvalidArgumentException('Failed to generate a P-256 keypair.');
        }

        return new static($key);
    }

    /**
     * Restore from a PKCS#8 PEM.
     */
    public static function fromPem(string $pem): static
    {
        $key = PublicKeyLoader::load($pem);

        if (! $key instanceof PrivateKey) {
            throw new InvalidArgumentException('DPoP keypair PEM must contain a private key.');
        }

        return new static($key);
    }

    /**
     * The private key as a PKCS#8 PEM, for caching alongside a credential.
     */
    public function toPem(): string
    {
        return $this->privateKey->toString('PKCS8');
    }

    public function privateKey(): PrivateKey
    {
        return $this->privateKey;
    }

    /**
     * The bare public JWK a DPoP proof header carries.
     *
     * Only the RFC 7638 required members: anything else would change the
     * thumbprint the authority computes.
     *
     * @return array<string, mixed>
     */
    public function publicJwk(): array
    {
        $decoded = json_decode($this->privateKey->getPublicKey()->toString('JWK'), true);

        // phpseclib emits a JWK Set.
        $jwk = $decoded['keys'][0] ?? $decoded;

        if (! is_array($jwk)) {
            throw new InvalidArgumentException('Could not read the public JWK for this keypair.');
        }

        return Jwk::bare($jwk);
    }

    /**
     * The RFC 7638 thumbprint, which the credential carries as `cnf.jkt`.
     */
    public function thumbprint(): string
    {
        return Jwk::thumbprint($this->publicJwk());
    }
}
