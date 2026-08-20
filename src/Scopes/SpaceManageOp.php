<?php

namespace SocialDept\AtpSpaces\Scopes;

/**
 * The space-lifecycle operations a `space:` scope can grant.
 *
 * Management is orthogonal to record access: a scope granting `manage=create`
 * says nothing about reading or writing records.
 */
enum SpaceManageOp: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
