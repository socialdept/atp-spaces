<?php

namespace SocialDept\AtpSpaces\Repo;

use InvalidArgumentException;

/**
 * A signed commit over the current state of a permissioned repo.
 *
 * Mirrors `com.atproto.space.defs#signedCommit`. Note what is and is not
 * covered: `sig` signs the context alone, while `mac` is what ties `hash` to
 * that context. Checking one without the other proves nothing useful.
 */
class SignedCommit
{
    public const VERSION = 1;

    public const HASH_BYTES = 32;
    public const IKM_BYTES = 32;

    /**
     * @param  int  $ver  Commit format version
     * @param  string  $hash  sha256 of the LtHash state (32 bytes)
     * @param  string  $ikm  Per-signature input keying material (32 bytes)
     * @param  string  $sig  Signature over the context by the author's signing key
     * @param  string  $mac  HMAC binding the hash to the context
     * @param  string  $rev  Commit revision (TID)
     */
    public function __construct(
        public readonly int $ver,
        public readonly string $hash,
        public readonly string $ikm,
        public readonly string $sig,
        public readonly string $mac,
        public readonly string $rev,
    ) {
    }

    /**
     * Build from a decoded lexicon object, with raw-byte fields.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        foreach (['ver', 'hash', 'ikm', 'sig', 'mac', 'rev'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Signed commit is missing \"{$field}\".");
            }
        }

        $commit = new static(
            (int) $data['ver'],
            (string) $data['hash'],
            (string) $data['ikm'],
            (string) $data['sig'],
            (string) $data['mac'],
            (string) $data['rev'],
        );

        $commit->assertWellFormed();

        return $commit;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ver' => $this->ver,
            'hash' => $this->hash,
            'ikm' => $this->ikm,
            'sig' => $this->sig,
            'mac' => $this->mac,
            'rev' => $this->rev,
        ];
    }

    /**
     * Whether this commit uses a version this package understands.
     */
    public function isSupportedVersion(): bool
    {
        return $this->ver === self::VERSION;
    }

    /**
     * Check field widths, which are fixed by the lexicon.
     *
     * @throws InvalidArgumentException
     */
    public function assertWellFormed(): void
    {
        if (strlen($this->hash) !== self::HASH_BYTES) {
            throw new InvalidArgumentException('Signed commit hash must be '.self::HASH_BYTES.' bytes.');
        }

        if (strlen($this->ikm) !== self::IKM_BYTES) {
            throw new InvalidArgumentException('Signed commit ikm must be '.self::IKM_BYTES.' bytes.');
        }

        if ($this->sig === '' || $this->mac === '' || $this->rev === '') {
            throw new InvalidArgumentException('Signed commit sig, mac and rev must be present.');
        }
    }
}
