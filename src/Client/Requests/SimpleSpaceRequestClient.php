<?php

namespace SocialDept\AtpSpaces\Client\Requests;

use SocialDept\AtpClient\Client\Requests\Request;
use SocialDept\AtpSpaces\Enums\SimpleSpaceNsid;
use SocialDept\AtpSpaces\Management\AppAccess;
use SocialDept\AtpSpaces\Management\SpacePolicy;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Actor;

/**
 * Space lifecycle and membership, through the baseline `simplespace` scheme.
 *
 * Spaces created here are anchored on the **authenticated user's DID**, who
 * becomes the owner. That is the ordinary arrangement: a space belongs to a
 * person, and an application is at most delegated the decision of who gets in.
 *
 * Reading and writing are governed separately. The read policy decides who
 * is issued a credential, and so who can see the space. The write policy
 * decides whose repo the authority tracks and whose writes it forwards. Nobody
 * can be stopped from writing into their own repo, only from being counted.
 */
class SimpleSpaceRequestClient extends Request
{
    /**
     * Create a space owned by the authenticated user.
     *
     * Both policies default to the member list, and apps to open access.
     *
     * @param  string  $spaceType  The space type NSID
     * @param  string|null  $skey  Omit to have the host generate a TID
     */
    public function createSpace(
        string $spaceType,
        ?SpacePolicy $readPolicy = null,
        ?SpacePolicy $writePolicy = null,
        ?AppAccess $appAccess = null,
        ?string $skey = null,
    ): SpaceRef {
        $response = $this->atp->client->post(SimpleSpaceNsid::CreateSpace->value, array_filter([
            'spaceType' => $spaceType,
            'skey' => $skey,
            'readPolicy' => ($readPolicy ?? SpacePolicy::memberList())->toArray(),
            'writePolicy' => ($writePolicy ?? SpacePolicy::memberList())->toArray(),
            'appAccess' => ($appAccess ?? AppAccess::open())->toArray(),
        ], fn ($value) => $value !== null));

        return SpaceRef::parse((string) $response->json()['uri']);
    }

    /**
     * Change a space's policies. Anything left null stays as it is.
     */
    public function updateSpace(
        SpaceRef|string $space,
        ?SpacePolicy $readPolicy = null,
        ?SpacePolicy $writePolicy = null,
        ?AppAccess $appAccess = null,
    ): bool {
        $this->atp->client->post(SimpleSpaceNsid::UpdateSpace->value, array_filter([
            'space' => (string) $space,
            'readPolicy' => $readPolicy?->toArray(),
            'writePolicy' => $writePolicy?->toArray(),
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
     * @return array{space: SpaceRef|null, readPolicy: SpacePolicy|null, writePolicy: SpacePolicy|null, appAccess: AppAccess|null, raw: array<string, mixed>}
     */
    public function getSpace(SpaceRef|string $space): array
    {
        $json = (array) $this->atp->client->get(SimpleSpaceNsid::GetSpace->value, [
            'space' => (string) $space,
        ])->json();

        return [
            'space' => SpaceRef::tryParse((string) ($json['uri'] ?? (string) $space)),
            'readPolicy' => is_array($json['readPolicy'] ?? null) ? SpacePolicy::fromArray($json['readPolicy']) : null,
            'writePolicy' => is_array($json['writePolicy'] ?? null) ? SpacePolicy::fromArray($json['writePolicy']) : null,
            'appAccess' => is_array($json['appAccess'] ?? null) ? AppAccess::fromArray($json['appAccess']) : null,
            'raw' => $json,
        ];
    }

    /**
     * Add a member or change what they may do. Both permissions are set
     * together, replacing what the member had. Requires auth as the space
     * owner.
     *
     * @param  string  $actor  A DID or a handle
     */
    public function putMember(SpaceRef|string $space, string $actor, bool $read = true, bool $write = true): bool
    {
        $this->atp->client->post(SimpleSpaceNsid::PutMember->value, [
            'space' => (string) $space,
            'did' => Actor::did($actor),
            'read' => $read,
            'write' => $write,
        ]);

        return true;
    }

    /**
     * Add a member who may read and write.
     *
     * @deprecated Use putMember(), which sets read and write separately.
     *
     * @param  string  $actor  A DID or a handle
     */
    public function addMember(SpaceRef|string $space, string $actor): bool
    {
        return $this->putMember($space, $actor);
    }

    /**
     * Remove a member. Requires auth as the space owner.
     *
     * @param  string  $actor  A DID or a handle
     */
    public function removeMember(SpaceRef|string $space, string $actor): bool
    {
        $this->atp->client->post(SimpleSpaceNsid::RemoveMember->value, [
            'space' => (string) $space,
            'did' => Actor::did($actor),
        ]);

        return true;
    }

    /**
     * List a space's members.
     *
     * @return array{members: array<int, array{did: string, read: bool, write: bool}>, cursor: string|null}
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
            if (is_array($member) && is_string($member['did'] ?? null)) {
                $members[] = ['did' => $member['did'], 'read' => (bool) ($member['read'] ?? false), 'write' => (bool) ($member['write'] ?? false)];
            }
        }

        return ['members' => $members, 'cursor' => $json['cursor'] ?? null];
    }
}
