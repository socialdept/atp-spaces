<?php

namespace SocialDept\AtpSpaces\Management;

use SocialDept\AtpSpaces\Support\Actor;

/**
 * How an authority decides whether to authorize a requesting **user**, for
 * reading a space or for writing to it. A space has one of each.
 *
 * A read policy is evaluated when a credential is minted, not when a record is
 * read, so a change takes effect within one credential lifetime rather than
 * immediately. A write policy decides whose writes the authority tracks.
 */
class SpacePolicy
{
    public const NAMESPACE = 'com.atproto.simplespace.defs';

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function __construct(
        public readonly string $type,
        public readonly array $extra = [],
    ) {
    }

    /**
     * Any user may access the space.
     */
    public static function public(): static
    {
        return new static(self::NAMESPACE.'#publicPolicy');
    }

    /**
     * Only users on the space's member list may access it.
     *
     * The member list is host-internal state, not a synced protocol structure,
     * and is never enumerated to the network.
     */
    public static function memberList(): static
    {
        return new static(self::NAMESPACE.'#memberListPolicy');
    }

    /**
     * The named app is asked, per request, whether to authorize each user to
     * read or write.
     *
     * This is how an application keeps authorization logic — entitlements,
     * subscriptions, bans, anything — in its own system instead of mirroring
     * it into a member list. The authority calls the app's `checkUserAccess`.
     *
     * @param  string  $managingApp  A DID or handle, with an optional service
     *                               fragment, e.g. `did:web:example.com#forum`
     */
    public static function managingApp(string $managingApp): static
    {
        [$actor, $fragment] = array_pad(explode('#', $managingApp, 2), 2, null);

        $identifier = Actor::did($actor).($fragment === null ? '' : '#'.$fragment);

        return new static(self::NAMESPACE.'#managingAppPolicy', ['managingApp' => $identifier]);
    }

    public function isPublic(): bool
    {
        return $this->type === self::NAMESPACE.'#publicPolicy';
    }

    public function isMemberList(): bool
    {
        return $this->type === self::NAMESPACE.'#memberListPolicy';
    }

    public function isManagingApp(): bool
    {
        return $this->type === self::NAMESPACE.'#managingAppPolicy';
    }

    /**
     * The managing app's service identifier, when this is a managing-app policy.
     */
    public function managingAppId(): ?string
    {
        $id = $this->extra['managingApp'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Rebuild from a decoded union value.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $type = (string) ($data['$type'] ?? '');
        $extra = $data;
        unset($extra['$type']);

        return new static($type, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['$type' => $this->type] + $this->extra;
    }
}
