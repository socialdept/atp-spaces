<?php

namespace SocialDept\AtpSpaces\Events;

use SocialDept\AtpSpaces\SpaceRef;

/**
 * A space this application syncs was deleted, so its copy should be dropped.
 *
 * Sent by the space's authority to every service registered through
 * `registerNotify`, and retried until delivered or the registration lapses.
 * A service that misses it learns the same thing when renewing a credential
 * for the space fails with `SpaceDeleted`.
 */
class SpaceDeletedNotified
{
    public function __construct(
        public readonly SpaceRef $space,
    ) {
    }
}
