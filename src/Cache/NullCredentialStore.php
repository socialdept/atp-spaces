<?php

namespace SocialDept\AtpSpaces\Cache;

use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Keeps nothing, so every request mints a fresh credential.
 *
 * Bound when caching is disabled. Correct but expensive — two extra round
 * trips per read — so it exists for environments that would rather not hold a
 * space-wide capability at rest.
 */
class NullCredentialStore implements CredentialStore
{
    public function get(SpaceRef $space, string $userDid): ?SpaceCredential
    {
        return null;
    }

    public function put(SpaceCredential $credential): void
    {
    }

    public function forget(SpaceRef $space, string $userDid): void
    {
    }
}
