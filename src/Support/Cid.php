<?php

namespace SocialDept\AtpSpaces\Support;

use InvalidArgumentException;

/**
 * A CIDv1 with a SHA-256 multihash: dag-cbor for records, raw for the blobs
 * records link to.
 *
 * Space repos fold record CIDs into their set hash and MAC them, so a reader
 * computes them from record bytes and compares them as strings and as binary.
 */
final class Cid
{
    /**
     * CIDv1, the dag-cbor codec, a sha2-256 multihash of 32 bytes.
     */
    public const PREFIX = "\x01\x71\x12\x20";

    /**
     * The same, with the raw codec a blob's CID uses.
     */
    public const RAW_PREFIX = "\x01\x55\x12\x20";

    protected const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    private function __construct(
        public readonly string $bytes,
    ) {
    }

    /**
     * The CID of a record's DRISL encoding.
     */
    public static function forBytes(string $drisl): self
    {
        return new self(self::PREFIX.hash('sha256', $drisl, true));
    }

    /**
     * Parse the base32 string form, as `bafyrei…`.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $cid): self
    {
        if (! str_starts_with($cid, 'b')) {
            throw new InvalidArgumentException("Not a base32 CIDv1: \"{$cid}\".");
        }

        $bits = '';

        foreach (str_split(substr($cid, 1)) as $char) {
            $index = strpos(self::BASE32, $char);

            if ($index === false) {
                throw new InvalidArgumentException("Not a base32 CIDv1: \"{$cid}\".");
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $bytes .= chr(bindec($byte));
        }

        return self::fromBinary($bytes);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromBinary(string $bytes): self
    {
        if (strlen($bytes) !== strlen(self::PREFIX) + 32 || (! str_starts_with($bytes, self::PREFIX) && ! str_starts_with($bytes, self::RAW_PREFIX))) {
            throw new InvalidArgumentException('Only CIDv1 sha2-256 CIDs, dag-cbor or raw, are supported.');
        }

        return new self($bytes);
    }

    /**
     * The base32 string form.
     */
    public function toString(): string
    {
        $bits = '';

        foreach (str_split($this->bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = 'b';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
