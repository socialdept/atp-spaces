<?php

namespace SocialDept\AtpSpaces\Repo;

/**
 * One entry from a permissioned repo's operation log.
 *
 * The shape encodes the operation: a create carries `cid` only, a delete
 * `prev` only, an update both. That is what lets a set hash apply an oplog
 * without knowing anything else about history.
 */
class RepoOp
{
    /**
     * @param  string|null  $cid  The record's new CID, or null when deleted
     * @param  string|null  $prev  The record's previous CID, or null when created
     * @param  array<string, mixed>|null  $value  The inlined record, when the host sent one
     */
    public function __construct(
        public readonly string $collection,
        public readonly string $rkey,
        public readonly ?string $cid = null,
        public readonly ?string $prev = null,
        public readonly ?string $rev = null,
        public readonly ?array $value = null,
    ) {
    }

    /**
     * Build from a `com.atproto.space.listRepoOps#opEntry` object.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): static
    {
        return new static(
            (string) ($entry['collection'] ?? ''),
            (string) ($entry['rkey'] ?? ''),
            isset($entry['cid']) ? (string) $entry['cid'] : null,
            isset($entry['prev']) ? (string) $entry['prev'] : null,
            isset($entry['rev']) ? (string) $entry['rev'] : null,
            isset($entry['value']) && is_array($entry['value']) ? $entry['value'] : null,
        );
    }

    public function isCreate(): bool
    {
        return $this->cid !== null && $this->prev === null;
    }

    public function isUpdate(): bool
    {
        return $this->cid !== null && $this->prev !== null;
    }

    public function isDelete(): bool
    {
        return $this->cid === null;
    }

    /**
     * The `{collection}/{rkey}` index path this operation touches.
     */
    public function path(): string
    {
        return $this->collection.'/'.$this->rkey;
    }
}
