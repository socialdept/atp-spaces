<?php

namespace SocialDept\AtpSpaces\Exceptions;

/**
 * A space authority refused to issue a credential, issued an unusable one,
 * or revoked one.
 */
class CredentialException extends SpacesException
{
    /**
     * @param  string|null  $error  The lexicon error name, when the authority named one
     */
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the refusal was about the user rather than the app.
     */
    public function isUserRefusal(): bool
    {
        return $this->error === 'UserNotAuthorized';
    }

    /**
     * Whether the refusal was about the app rather than the user.
     */
    public function isAppRefusal(): bool
    {
        return $this->error === 'AppNotAuthorized';
    }

    /**
     * Whether the space is gone.
     *
     * A syncer that missed `notifySpaceDeleted` learns it here, on renewal.
     */
    public function isSpaceDeleted(): bool
    {
        return $this->error === 'SpaceDeleted';
    }

    /**
     * Whether the authority revoked the credential. It is never retried: a
     * replacement has to be minted through a session that may still enter.
     */
    public function isRevoked(): bool
    {
        return $this->error === 'CredentialRevoked';
    }

    /**
     * Whether re-minting could plausibly succeed.
     *
     * An authorization refusal will not change on its own. A stale or spent
     * delegation token might, since minting again fetches a fresh one.
     */
    public function isRetryable(): bool
    {
        return $this->error === 'InvalidDelegationToken';
    }
}
