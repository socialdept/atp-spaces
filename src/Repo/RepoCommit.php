<?php

namespace SocialDept\AtpSpaces\Repo;

use SocialDept\AtpSpaces\Contracts\Blake3Hasher;
use SocialDept\AtpSpaces\Contracts\SignatureVerifier;

/**
 * The contents of one member's permissioned repo, as a set hash.
 *
 * Permissioned repos have no Merkle search tree. State is the set of records
 * they contain, folded into an {@see LtHash}, and a commit is a signature over
 * that set's digest. Syncing is therefore order-independent: apply operations
 * however they arrive and compare the digest at the end.
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
     * Whether these contents match a commit's claimed hash.
     *
     * Verify the commit first — on its own this says nothing about
     * authenticity, only that two digests agree.
     */
    public function matches(SignedCommit $commit): bool
    {
        return hash_equals($this->setHash->digest(), $commit->hash);
    }

    /**
     * The current digest, as a commit would carry it.
     */
    public function digest(): string
    {
        return $this->setHash->digest();
    }

    /**
     * Check a commit's integrity, and its authenticity when able.
     *
     * The MAC binds `hash` to the context; the signature covers the context
     * alone. Without a verifier and a did:key the signature is left unchecked
     * and the result says so.
     */
    public static function verify(
        SignedCommit $commit,
        CommitContext $context,
        ?SignatureVerifier $verifier = null,
        ?string $didKey = null,
    ): CommitVerification {
        $versionSupported = $commit->isSupportedVersion();
        $revisionMatches = hash_equals($context->rev, $commit->rev);

        $contextBytes = $context->encode($commit->ikm);
        $macValid = hash_equals(
            self::computeMac($commit->ikm, $contextBytes, $commit->hash),
            $commit->mac,
        );

        $signatureChecked = $verifier !== null && $didKey !== null;
        $signatureValid = $signatureChecked
            && $verifier->verify($didKey, $contextBytes, $commit->sig);

        return new CommitVerification(
            $versionSupported,
            $revisionMatches,
            $macValid,
            $signatureChecked,
            $signatureValid,
        );
    }

    /**
     * The MAC binding a repo hash to a commit context.
     *
     * HKDF here is expand-only, with the commit's `ikm` used directly as the
     * pseudorandom key — not PHP's `hash_hkdf()`, which extracts first.
     */
    public static function computeMac(string $ikm, string $contextBytes, string $hash): string
    {
        $key = hash_hmac('sha256', $contextBytes."\x01", $ikm, true);

        return hash_hmac('sha256', $hash, $key, true);
    }
}
