<?php

namespace SocialDept\AtpSpaces\Contracts;

use SocialDept\AtpSpaces\Access\SpaceAccessRequest;

/**
 * Decides whether a user may enter a space your application manages.
 *
 * This is the hook behind `SpacePolicy::managingApp()`. It lets authorization
 * live in your own system, whatever that is, instead of being mirrored into a
 * member list on someone's PDS. The package deliberately knows nothing about
 * how you decide.
 *
 * Two things to keep in mind while implementing it:
 *
 * - **Be quick.** The authority calls this while a user waits for a
 *   credential, and it will treat a slow or failed call as a refusal.
 * - **Fail closed.** Throwing is treated as "no". That is the safe default,
 *   but it means an outage in whatever you consult here locks people out.
 */
interface SpaceAccessResolver
{
    public function authorize(SpaceAccessRequest $request): bool;
}
