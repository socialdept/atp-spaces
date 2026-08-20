<?php

namespace SocialDept\AtpSpaces\Scopes;

/**
 * The record-level actions a `space:` scope can grant.
 *
 * Case order is significant: upstream normalizes an action list into this
 * order before formatting a scope string.
 */
enum SpaceAction: string
{
    case ReadSelf = 'read_self';
    case Read = 'read';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * The actions granted when a scope names none.
     *
     * `read_self` is absent because `read` already implies it.
     *
     * @return array<int, self>
     */
    public static function defaults(): array
    {
        return [self::Read, self::Create, self::Update, self::Delete];
    }

    /**
     * Whether this action reads rather than writes.
     */
    public function isRead(): bool
    {
        return $this === self::Read || $this === self::ReadSelf;
    }
}
