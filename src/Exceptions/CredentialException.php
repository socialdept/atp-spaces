<?php

namespace SocialDept\AtpSpaces\Exceptions;

/**
 * A space authority refused to issue a credential, or issued an unusable one.
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
     * Whether re-minting could plausibly succeed.
     *
     * An authorization refusal will not change on its own; a stale delegation
     * token might.
     */
    public function isRetryable(): bool
    {
        return in_array($this->error, ['InvalidDelegationToken', 'UseDpopNonce'], true);
    }
}
