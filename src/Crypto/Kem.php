<?php

namespace SocialDept\AtpSpaces\Crypto;

use InvalidArgumentException;
use phpseclib3\Crypt\DH;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\EC\PublicKey;
use phpseclib3\Crypt\PublicKeyLoader;
use SocialDept\AtpSupport\Crypto\DidKey;

/**
 * The shared secret a reader and a repo host MAC a response with.
 *
 * The reader generates a fresh P-256 keypair for every request, does ECDH
 * with the author's `#atproto_kem` key, and sends its public key as the
 * `key_share`. The host does the same with the author's private key, and both
 * derive the secret as DHKEM(P-256, HKDF-SHA256) does in RFC 9180 §4.1, with
 * the reader's key as `enc`. A new keypair every time, retries and pages
 * included, and never the credential's key.
 */
final class Kem
{
    /**
     * "HPKE-v1" || "KEM" || the DHKEM(P-256, HKDF-SHA256) suite id.
     */
    protected const PREFIX = 'HPKE-v1KEM'."\x00\x10";

    /**
     * Derive a fresh secret against an author's KEM key.
     *
     * @param  string  $authorKemKey  The author's `#atproto_kem` key, a P-256 did:key
     * @return array{keyShare: string, sharedSecret: string} The did:key to send, and the 32-byte secret
     *
     * @throws InvalidArgumentException when the key is not a P-256 did:key
     */
    public static function encapsulate(string $authorKemKey): array
    {
        $author = self::publicKey($authorKemKey);
        $ephemeral = EC::createKey('secp256r1');

        if (! $ephemeral instanceof PrivateKey) {
            throw new InvalidArgumentException('Failed to generate a P-256 keypair.');
        }

        return [
            'keyShare' => self::didKey($ephemeral->getPublicKey()),
            'sharedSecret' => self::derive($ephemeral, $author, $ephemeral->getPublicKey()),
        ];
    }

    /**
     * The host's side, for tests: the same secret from the author's private
     * key and the reader's key share.
     */
    public static function decapsulate(PrivateKey $authorKemKey, string $keyShare): string
    {
        $reader = self::publicKey($keyShare);

        return self::derive($authorKemKey, $reader, $reader, $authorKemKey->getPublicKey());
    }

    /**
     * @param  PublicKey  $readerKey  The reader's public key, always first in the context
     * @param  PublicKey|null  $authorKey  The author's public key, when $peer is not it
     */
    protected static function derive(PrivateKey $private, PublicKey $peer, PublicKey $readerKey, ?PublicKey $authorKey = null): string
    {
        // The x-coordinate of the shared point, kept at its full 32 bytes.
        $dh = str_pad(DH::computeSecret($private, $peer), 32, "\0", STR_PAD_LEFT);

        $context = $readerKey->getEncodedCoordinates().($authorKey ?? $peer)->getEncodedCoordinates();

        return hash_hkdf(
            'sha256',
            self::PREFIX.'eae_prk'.$dh,
            32,
            pack('n', 32).self::PREFIX.'shared_secret'.$context,
            '',
        );
    }

    /**
     * Load a P-256 did:key, whose compressed point phpseclib reads from a
     * SubjectPublicKeyInfo.
     */
    protected static function publicKey(string $didKey): PublicKey
    {
        $key = DidKey::tryParse($didKey);

        if ($key === null || $key->curve !== DidKey::P256) {
            throw new InvalidArgumentException('A KEM key must be a P-256 did:key.');
        }

        // SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING { point } }
        $algorithm = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $point = "\x03".chr(strlen($key->publicKey) + 1)."\x00".$key->publicKey;
        $der = "\x30".chr(strlen($algorithm.$point)).$algorithm.$point;

        $loaded = PublicKeyLoader::load("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n");

        if (! $loaded instanceof PublicKey) {
            throw new InvalidArgumentException('A KEM key must be a P-256 did:key.');
        }

        return $loaded;
    }

    protected static function didKey(PublicKey $key): string
    {
        $point = $key->getEncodedCoordinates();

        return (string) new DidKey(DidKey::P256, (ord($point[64]) % 2 === 0 ? "\x02" : "\x03").substr($point, 1, 32));
    }
}
