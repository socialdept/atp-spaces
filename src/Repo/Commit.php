<?php

namespace SocialDept\AtpSpaces\Repo;

use InvalidArgumentException;

/**
 * A space repo's commit: which repo, in which space, at which revision, and
 * the digest of its set hash.
 *
 * Mirrors `com.atproto.space.defs#commit`. It carries no signature. A reader
 * trusts `data` only after the MAC its repo host sent alongside it checks
 * out, with a secret only that reader and that host share, so a leaked
 * commit proves nothing to anyone else.
 */
class Commit
{
    public const VERSION = 1;

    public const DATA_BYTES = 32;

    /**
     * @param  int  $ver  Commit format version
     * @param  string  $repo  The author's DID
     * @param  string  $space  The space URI
     * @param  string  $rev  The repo revision (TID)
     * @param  string  $data  sha256 of the set hash state, 32 bytes
     */
    public function __construct(
        public readonly int $ver,
        public readonly string $repo,
        public readonly string $space,
        public readonly string $rev,
        public readonly string $data,
    ) {
    }

    /**
     * Build from a decoded lexicon object, `data` as raw bytes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): static
    {
        foreach (['ver', 'repo', 'space', 'rev', 'data'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Commit is missing \"{$field}\".");
            }
        }

        $commit = new static((int) $data['ver'], (string) $data['repo'], (string) $data['space'], (string) $data['rev'], (string) $data['data']);
        $commit->assertWellFormed();

        return $commit;
    }

    /**
     * @return array{ver: int, repo: string, space: string, rev: string, data: string}
     */
    public function toArray(): array
    {
        return ['ver' => $this->ver, 'repo' => $this->repo, 'space' => $this->space, 'rev' => $this->rev, 'data' => $this->data];
    }

    public function isSupportedVersion(): bool
    {
        return $this->ver === self::VERSION;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertWellFormed(): void
    {
        if (strlen($this->data) !== self::DATA_BYTES) {
            throw new InvalidArgumentException('Commit data must be '.self::DATA_BYTES.' bytes.');
        }

        if ($this->repo === '' || $this->space === '' || $this->rev === '') {
            throw new InvalidArgumentException('Commit repo, space and rev must be present.');
        }
    }
}
