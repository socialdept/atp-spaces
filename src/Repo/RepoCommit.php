<?php

namespace SocialDept\AtpSpaces\Repo;

use SocialDept\AtpSpaces\Contracts\Blake3Hasher;

/**
 * The contents of one member's space repo, as a set hash.
 *
 * Space repos have no Merkle search tree. State is the set of records they
 * contain, folded into an {@see LtHash}, and a commit carries that set's
 * digest. Syncing is therefore order-independent: apply operations however
 * they arrive and compare the digest at the end.
 */
class RepoCommit
{
    public function __construct(
        public readonly LtHash $setHash = new LtHash(),
    ) {
    }

    /**
     * Restore from a persisted set-hash state; null yields an empty repo.
     */
    public static function fromState(?string $state, ?Blake3Hasher $hasher = null): static
    {
        return new static(LtHash::fromState($state, $hasher));
    }

    /**
     * Fold in a set of records.
     *
     * @param  iterable<array{collection: string, rkey: string, cid: string}>  $records
     */
    public static function fromRecords(iterable $records, ?Blake3Hasher $hasher = null): static
    {
        $commit = new static(new LtHash(null, $hasher));

        foreach ($records as $record) {
            $commit->add($record['collection'], $record['rkey'], $record['cid']);
        }

        return $commit;
    }

    /**
     * Fold in every record a repo index describes.
     *
     * The index maps `{collection}/{rkey}` to a record CID, which is already
     * the set-hash element split in two — so this needs no path parsing.
     *
     * @param  array<string, string>  $index
     */
    public static function fromIndex(array $index, ?Blake3Hasher $hasher = null): static
    {
        $commit = new static(new LtHash(null, $hasher));

        foreach ($index as $path => $cid) {
            $commit->setHash->add($path.'/'.$cid);
        }

        return $commit;
    }

    /**
     * The element a record contributes to the set hash.
     */
    public static function element(string $collection, string $rkey, string $cid): string
    {
        return $collection.'/'.$rkey.'/'.$cid;
    }

    /**
     * Add a record.
     */
    public function add(string $collection, string $rkey, string $cid): static
    {
        $this->setHash->add(self::element($collection, $rkey, $cid));

        return $this;
    }

    /**
     * Remove a record.
     */
    public function remove(string $collection, string $rkey, string $cid): static
    {
        $this->setHash->remove(self::element($collection, $rkey, $cid));

        return $this;
    }

    /**
     * Apply one operation from a repo oplog.
     *
     * A create has no `prev`, a delete no `cid`, an update both.
     */
    public function applyOp(RepoOp $op): static
    {
        if ($op->prev !== null) {
            $this->remove($op->collection, $op->rkey, $op->prev);
        }

        if ($op->cid !== null) {
            $this->add($op->collection, $op->rkey, $op->cid);
        }

        return $this;
    }

    /**
     * Apply a sequence of operations.
     *
     * @param  iterable<RepoOp>  $ops
     */
    public function applyOps(iterable $ops): static
    {
        foreach ($ops as $op) {
            $this->applyOp($op);
        }

        return $this;
    }

    /**
     * Whether these contents match a commit's digest.
     *
     * Verify the commit first. On its own this says nothing about where the
     * commit came from, only that two digests agree.
     */
    public function matches(Commit $commit): bool
    {
        return hash_equals($this->setHash->digest(), $commit->data);
    }

    /**
     * The current digest, as a commit would carry it.
     */
    public function digest(): string
    {
        return $this->setHash->digest();
    }

    /**
     * Check a commit against the MAC its repo host sent, with the secret of
     * the request it answered.
     *
     * @param  string  $space  The space that was asked about
     * @param  string  $repo  The repo that was asked about, a DID
     */
    public static function verify(Commit $commit, string $space, string $repo, string $sharedSecret, string $mac): CommitVerification
    {
        return new CommitVerification(
            versionSupported: $commit->isSupportedVersion(),
            repoMatches: hash_equals($repo, $commit->repo),
            spaceMatches: hash_equals($space, $commit->space),
            macValid: hash_equals(Mac::forCommit($sharedSecret, $commit), $mac),
        );
    }
}
