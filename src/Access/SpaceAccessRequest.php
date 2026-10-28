<?php

namespace SocialDept\AtpSpaces\Access;

use SocialDept\AtpSpaces\SpaceRef;

/**
 * A question the space authority is asking your application: may this user
 * read this space?
 *
 * Asked once per credential mint, not per record read, so the answer takes
 * effect within one credential lifetime rather than immediately.
 */
class SpaceAccessRequest
{
    /**
     * @param  SpaceRef  $space  The space being entered
     * @param  string  $user  DID of the user asking to enter
     * @param  string|null  $clientId  The attested OAuth client, when the space gates on app identity
     * @param  string  $authority  DID of the authority that asked, already verified as the space's own
     */
    public function __construct(
        public readonly SpaceRef $space,
        public readonly string $user,
        public readonly ?string $clientId,
        public readonly string $authority,
    ) {
    }

    /**
     * Whether the space is owned by the user being asked about.
     *
     * An owner reading their own space is the common case, and usually wants
     * to be allowed without consulting anything.
     */
    public function isOwner(): bool
    {
        return $this->user === $this->space->authority;
    }

    /**
     * Whether an app identity was proven for this request.
     */
    public function hasClient(): bool
    {
        return $this->clientId !== null && $this->clientId !== '';
    }
}
