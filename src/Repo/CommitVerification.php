<?php

namespace SocialDept\AtpSpaces\Repo;

use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;

/**
 * The outcome of checking a signed commit.
 *
 * Authenticity and integrity are separate results because they can be
 * established separately: without a {@see \SocialDept\AtpSpaces\Contracts\SignatureVerifier}
 * the MAC still proves the hash belongs to this context, it just does not
 * prove who produced it. `signatureChecked` says which situation you are in,
 * so a caller never mistakes "not checked" for "valid".
 */
class CommitVerification
{
    public function __construct(
        public readonly bool $versionSupported,
        public readonly bool $revisionMatches,
        public readonly bool $macValid,
        public readonly bool $signatureChecked,
        public readonly bool $signatureValid,
    ) {
    }

    /**
     * Whether every check that ran passed, including the signature.
     *
     * An unchecked signature is not a pass.
     */
    public function isFullyVerified(): bool
    {
        return $this->isIntact() && $this->signatureChecked && $this->signatureValid;
    }

    /**
     * Whether the hash is bound to this context, regardless of authorship.
     */
    public function isIntact(): bool
    {
        return $this->versionSupported && $this->revisionMatches && $this->macValid;
    }

    /**
     * Throw unless every check passed, including the signature.
     *
     * @throws RepoVerificationException
     */
    public function assertFullyVerified(): void
    {
        if (! $this->isFullyVerified()) {
            throw new RepoVerificationException('Commit verification failed: '.$this->failureReason().'.');
        }
    }

    /**
     * Throw unless the hash is bound to its context, ignoring authorship.
     *
     * @throws RepoVerificationException
     */
    public function assertIntact(): void
    {
        if (! $this->isIntact()) {
            throw new RepoVerificationException('Commit integrity check failed: '.$this->failureReason().'.');
        }
    }

    /**
     * A short reason for the first failed check, or null when all passed.
     */
    public function failureReason(): ?string
    {
        return match (true) {
            ! $this->versionSupported => 'unsupported commit version',
            ! $this->revisionMatches => 'commit revision does not match the context',
            ! $this->macValid => 'commit mac does not bind the hash to the context',
            ! $this->signatureChecked => 'commit signature was not checked',
            ! $this->signatureValid => 'commit signature is invalid',
            default => null,
        };
    }
}
