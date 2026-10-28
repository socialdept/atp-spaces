<?php

namespace SocialDept\AtpSpaces\Repo;

use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;

/**
 * The outcome of checking a commit against the MAC its repo host sent.
 *
 * A commit is trusted only when it is for the repo and space that were
 * asked about and its MAC checks out with the secret of that request. Only
 * then does its `data` mean anything.
 */
class CommitVerification
{
    public function __construct(
        public readonly bool $versionSupported,
        public readonly bool $repoMatches,
        public readonly bool $spaceMatches,
        public readonly bool $macValid,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->versionSupported && $this->repoMatches && $this->spaceMatches && $this->macValid;
    }

    /**
     * @throws RepoVerificationException
     */
    public function assertVerified(): void
    {
        if (! $this->isVerified()) {
            throw new RepoVerificationException('Commit verification failed: '.$this->failureReason().'.');
        }
    }

    /**
     * A short reason for the first failed check, or null when all passed.
     */
    public function failureReason(): ?string
    {
        return match (true) {
            ! $this->versionSupported => 'unsupported commit version',
            ! $this->repoMatches => 'commit is for another repo',
            ! $this->spaceMatches => 'commit is for another space',
            ! $this->macValid => 'commit mac does not match',
            default => null,
        };
    }
}
