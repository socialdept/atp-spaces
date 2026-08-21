<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use SocialDept\AtpSpaces\Tests\TestCase;

/**
 * The managing-app endpoint is opt-in, so it must not exist by default.
 *
 * A separate class because whether the route is registered is decided while
 * providers boot, and cannot be toggled inside a test.
 */
class CheckUserAccessDisabledTest extends TestCase
{
    public function test_the_route_is_absent_by_default(): void
    {
        $this->getJson('/xrpc/com.atproto.simplespace.checkUserAccess')->assertNotFound();
    }

    public function test_the_default_binding_refuses_everyone(): void
    {
        $resolver = $this->app->make(\SocialDept\AtpSpaces\Contracts\SpaceAccessResolver::class);

        $this->assertInstanceOf(\SocialDept\AtpSpaces\Access\DenyAllAccessResolver::class, $resolver);

        $this->assertFalse($resolver->authorize(new \SocialDept\AtpSpaces\Access\SpaceAccessRequest(
            \SocialDept\AtpSpaces\SpaceRef::make('did:plc:authority', 'com.example.forum', 'main'),
            'did:plc:reader',
            null,
            'did:plc:authority',
        )));
    }
}
