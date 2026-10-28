<?php

namespace SocialDept\AtpSpaces\Contracts;

use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Where minted space credentials are kept between requests.
 *
 * A credential lives about ten minutes, an hour at most, and costs a
 * delegation-token round trip plus an authority call to obtain, so reusing
 * one matters. It is also a capability:
 * anything holding it can read the whole space, so a store should be treated
 * like a session store, not like a cache of public data.
 */
interface CredentialStore
{
    // The user is a DID only. These are storage keys, and the broker has
    // already resolved by the time it calls them.

    public function get(SpaceRef $space, string $userDid): ?SpaceCredential;

    public function put(SpaceCredential $credential): void;

    public function forget(SpaceRef $space, string $userDid): void;
}
