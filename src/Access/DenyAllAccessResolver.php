<?php

namespace SocialDept\AtpSpaces\Access;

use SocialDept\AtpSpaces\Contracts\SpaceAccessResolver;

/**
 * Refuses everyone.
 *
 * The default binding, so an application that exposes the endpoint without
 * implementing a resolver hands out nothing. Answering "yes" by default would
 * mean a misconfiguration silently opens every space that trusted this app.
 */
class DenyAllAccessResolver implements SpaceAccessResolver
{
    public function authorize(SpaceAccessRequest $request): bool
    {
        return false;
    }
}
