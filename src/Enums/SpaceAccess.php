<?php

namespace SocialDept\AtpSpaces\Enums;

/**
 * What a space authority is asking a managing app about: whether a user may
 * read the space, or write to it.
 *
 * Read gates credentials, so it decides who can see the space. Write decides
 * whether the authority tracks a member's repo and forwards its writes. It
 * cannot stop anyone writing into their own repo.
 */
enum SpaceAccess: string
{
    case Read = 'read';
    case Write = 'write';
}
