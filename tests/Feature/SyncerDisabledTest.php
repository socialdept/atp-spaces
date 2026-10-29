<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use SocialDept\AtpSpaces\Tests\TestCase;

/**
 * The syncer endpoints are opt-in, so they must not exist by default.
 *
 * A separate class because whether the routes are registered is decided while
 * providers boot, and cannot be toggled inside a test.
 */
class SyncerDisabledTest extends TestCase
{
    public function test_the_routes_are_absent_by_default(): void
    {
        $this->postJson('/xrpc/com.atproto.space.notifyWrite')->assertNotFound();
        $this->postJson('/xrpc/com.atproto.space.notifySpaceDeleted')->assertNotFound();
    }
}
