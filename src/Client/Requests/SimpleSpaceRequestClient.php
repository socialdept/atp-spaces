<?php

namespace SocialDept\AtpSpaces\Client\Requests;

use SocialDept\AtpClient\Client\Requests\Request;
use SocialDept\AtpSpaces\Enums\SimpleSpaceNsid;
use SocialDept\AtpSpaces\Management\AppAccess;
use SocialDept\AtpSpaces\Management\SpacePolicy;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Space lifecycle and membership, through the baseline `simplespace` scheme.
 *
 * Spaces created here are anchored on the **authenticated user's DID**, who
 * becomes the owner. That is the ordinary arrangement: a space belongs to a
 * person, and an application is at most delegated the decision of who gets in.
 */
class SimpleSpaceRequestClient extends Request
{
    /**
     * Create a space owned by the authenticated user.
     *
     * @param  string|null  $skey  Omit to have the host generate a TID
     */
    public function createSpace(
        string $type,
        ?SpacePolicy $policy = null,
        ?AppAccess $appAccess = null,
        ?string $skey = null,
    ): SpaceRef {
        $response = $this->atp->client->post(SimpleSpaceNsid::CreateSpace->value, array_filter([
            'type' => $type,
            'skey' => $skey,
            'policy' => ($policy ?? SpacePolicy::memberList())->toArray(),
            'appAccess' => ($appAccess ?? AppAccess::open())->toArray(),
        ], fn ($value) => $value !== null));

        return SpaceRef::parse((string) $response->json()['uri']);
    }

    /**
     * Change a space's policies.
     */
    public function updateSpace(
        SpaceRef|string $space,
        ?SpacePolicy $policy = null,
        ?AppAccess $appAccess = null,
    ): bool {
        $this->atp->client->post(SimpleSpaceNsid::UpdateSpace->value, array_filter([
            'space' => (string) $space,
            'policy' => $policy?->toArray(),
            'appAccess' => $appAccess?->toArray(),
        ], fn ($value) => $value !== null));

        return true;
    }

    /**
     * Delete a space.
     */
    public function deleteSpace(SpaceRef|string $space): bool
    {
        $this->atp->client->post(SimpleSpaceNsid::DeleteSpace->value, [
            'space' => (string) $space,
        ]);

        return true;
    }

    /**
     * Read a space's configuration.
     *
     * @return array{space: SpaceRef|null, policy: SpacePolicy|null, appAccess: AppAccess|null, raw: array<string, mixed>}
     */
    public function getSpace(SpaceRef|string $space): array
    {
        $json = (array) $this->atp->client->get(SimpleSpaceNsid::GetSpace->value, [
            'space' => (string) $space,
        ])->json();

        return [
            'space' => SpaceRef::tryParse((string) ($json['uri'] ?? (string) $space)),
            'policy' => is_array($json['policy'] ?? null) ? SpacePolicy::fromArray($json['policy']) : null,
            'appAccess' => is_array($json['appAccess'] ?? null) ? AppAccess::fromArray($json['appAccess']) : null,
            'raw' => $json,
        ];
    }

    /**
     * Add a member. Requires auth as the space owner.
     */
    public function addMember(SpaceRef|string $space, string $did): bool
    {
        $this->atp->client->post(SimpleSpaceNsid::AddMember->value, [
            'space' => (string) $space,
            'did' => $did,
        ]);

        return true;
    }

    /**
     * Remove a member. Requires auth as the space owner.
     */
    public function removeMember(SpaceRef|string $space, string $did): bool
    {
        $this->atp->client->post(SimpleSpaceNsid::RemoveMember->value, [
            'space' => (string) $space,
            'did' => $did,
        ]);

        return true;
    }

    /**
     * List a space's members.
     *
     * @return array{members: array<int, string>, cursor: string|null}
     */
    public function listMembers(SpaceRef|string $space, ?int $limit = null, ?string $cursor = null): array
    {
        $json = (array) $this->atp->client->get(SimpleSpaceNsid::ListMembers->value, array_filter([
            'space' => (string) $space,
            'limit' => $limit,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null))->json();

        $members = [];

        foreach ($json['members'] ?? [] as $member) {
            $did = is_array($member) ? ($member['did'] ?? null) : $member;

            if (is_string($did)) {
                $members[] = $did;
            }
        }

        return ['members' => $members, 'cursor' => $json['cursor'] ?? null];
    }
}
