<?php

namespace SocialDept\AtpSpaces\Events;

use SocialDept\AtpSpaces\SpaceRef;

/**
 * A member's repo in a space this application syncs has advanced.
 *
 * Sent by the space's authority to every service registered through
 * `registerNotify`. It says only that the repo moved, never what changed:
 * read the new state with {@see \SocialDept\AtpSpaces\Client\SpaceReader::listRepoOps()}.
 *
 * Delivery is best effort. When `prevSpaceRev` is not the last `spaceRev`
 * you saw for this space, notifications were missed in between, and
 * `listRepos()` from that cursor catches up.
 */
class SpaceWriteNotified
{
    /**
     * @param  string  $repo  The DID whose repo advanced
     * @param  string  $repoRev  The repo revision after the write
     * @param  string  $data  The repo's commit data after the write, as raw bytes
     * @param  string|null  $spaceRev  The space revision the authority assigned
     * @param  string|null  $prevSpaceRev  The one before it, absent on the first write in a space
     */
    public function __construct(
        public readonly SpaceRef $space,
        public readonly string $repo,
        public readonly string $repoRev,
        public readonly string $data,
        public readonly ?string $spaceRev = null,
        public readonly ?string $prevSpaceRev = null,
    ) {
    }

    /**
     * Whether this follows directly on the given space revision.
     *
     * Pass the last `spaceRev` stored for this space, or null if there is
     * none. A false answer means notifications were missed.
     */
    public function follows(?string $lastSpaceRev): bool
    {
        return $this->prevSpaceRev === $lastSpaceRev;
    }
}
