<?php

namespace SocialDept\AtpSpaces\Auth;

use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use SocialDept\AtpSupport\Crypto\DidKey;

/**
 * The ephemeral P-256 keypair a space credential is bound to.
 *
 * A credential reads a whole space and is presented to every repo host in it.
 * As a bearer token it would be a shared secret: a host given one to serve its
 * own repo could replay it against every other host in the space. Binding it
 * to a key the application holds is what prevents that. The authority binds
 * the credential to this key's did:key (`cnf.kid`), and every request signs
 * the credential and its audience with it.
 *
 * Generate a fresh one for every credential, and let it die with it.
 */
class CredentialKey
{
    public const CURVE = 'secp256r1';

    protected function __construct(
        protected readonly PrivateKey $privateKey,
    ) {
    }

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

        if (! $key instanceof PrivateKey || $key->getCurve() !== self::CURVE) {
            throw new InvalidArgumentException('A credential key PEM must hold a P-256 private key.');
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
     * The public key as a P-256 did:key, which the authority binds the
     * credential to as `cnf.kid`.
     */
    public function didKey(): string
    {
        // 0x04 || x || y, compressed to its parity byte and x.
        $point = $this->privateKey->getPublicKey()->getEncodedCoordinates();
        $x = substr($point, 1, 32);
        $prefix = ord($point[64]) % 2 === 0 ? "\x02" : "\x03";

        return (string) new DidKey(DidKey::P256, $prefix.$x);
    }

    /**
     * Sign with ECDSA P-256 and SHA-256, as 64 raw bytes of r || s.
     */
    public function sign(string $message): string
    {
        return $this->privateKey->withHash('sha256')->withSignatureFormat('IEEE')->sign($message);
    }
}
