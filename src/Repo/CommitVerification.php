<?php

namespace SocialDept\AtpSpaces\Repo;

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
