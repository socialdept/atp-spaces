<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSpaces\Management\AppAccess;
use SocialDept\AtpSpaces\Management\SpacePolicy;

class ManagementTest extends TestCase
{
    public function test_the_default_policies_are_tagged_unions(): void
    {
        $this->assertSame(
            ['$type' => 'com.atproto.simplespace.defs#memberListPolicy'],
            SpacePolicy::memberList()->toArray(),
        );

        $this->assertSame(
            ['$type' => 'com.atproto.simplespace.defs#publicPolicy'],
            SpacePolicy::public()->toArray(),
        );

        $this->assertSame(
            ['$type' => 'com.atproto.simplespace.defs#open'],
            AppAccess::open()->toArray(),
        );
    }

    /**
     * The hook that lets an app keep authorization in its own system instead
     * of mirroring it into a member list.
     */
    public function test_a_managing_app_policy_carries_the_service_id(): void
    {
        $policy = SpacePolicy::managingApp('did:web:example.com#forum');

        $this->assertTrue($policy->isManagingApp());
        $this->assertSame('did:web:example.com#forum', $policy->managingAppId());
        $this->assertSame([
            '$type' => 'com.atproto.simplespace.defs#managingAppPolicy',
            'managingApp' => 'did:web:example.com#forum',
        ], $policy->toArray());
    }

    public function test_a_managing_app_must_be_a_did(): void
    {
        $this->expectException(InvalidSpaceRefException::class);

        SpacePolicy::managingApp('example.com');
    }

    public function test_policies_round_trip(): void
    {
        foreach ([SpacePolicy::public(), SpacePolicy::memberList(), SpacePolicy::managingApp('did:web:x.test')] as $policy) {
            $this->assertSame($policy->toArray(), SpacePolicy::fromArray($policy->toArray())->toArray());
        }
    }

    public function test_only_the_default_policies_report_their_own_kind(): void
    {
        $this->assertTrue(SpacePolicy::public()->isPublic());
        $this->assertTrue(SpacePolicy::memberList()->isMemberList());
        $this->assertFalse(SpacePolicy::memberList()->isPublic());
        $this->assertNull(SpacePolicy::memberList()->managingAppId());
    }

    /**
     * An allow list is evaluated against an attested client_id, so choosing one
     * makes client attestations mandatory.
     */
    public function test_an_allow_list_requires_attestation(): void
    {
        $access = AppAccess::allowList([
            'https://app.example.com/client-metadata.json',
            'https://other.example.com/client-metadata.json',
        ]);

        $this->assertFalse($access->isOpen());
        $this->assertTrue($access->requiresAttestation());
        $this->assertCount(2, $access->allowed());

        $this->assertFalse(AppAccess::open()->requiresAttestation());
        $this->assertSame([], AppAccess::open()->allowed());
    }

    public function test_app_access_round_trips(): void
    {
        $access = AppAccess::allowList(['https://app.example.com/client-metadata.json']);

        $this->assertSame($access->toArray(), AppAccess::fromArray($access->toArray())->toArray());
    }
}
