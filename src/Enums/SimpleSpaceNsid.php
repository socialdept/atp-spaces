<?php

namespace SocialDept\AtpSpaces\Enums;

/**
 * The `com.atproto.simplespace.*` management methods.
 *
 * "Simplespace" is the baseline space-management implementation every host is
 * expected to provide. An authority is free to implement a richer scheme; this
 * is the one that can be assumed.
 */
enum SimpleSpaceNsid: string
{
    case CreateSpace = 'com.atproto.simplespace.createSpace';
    case UpdateSpace = 'com.atproto.simplespace.updateSpace';
    case DeleteSpace = 'com.atproto.simplespace.deleteSpace';
    case GetSpace = 'com.atproto.simplespace.getSpace';
    case AddMember = 'com.atproto.simplespace.addMember';
    case RemoveMember = 'com.atproto.simplespace.removeMember';
    case ListMembers = 'com.atproto.simplespace.listMembers';

    /**
     * Served by a managing app, not the PDS — the authority calls it.
     */
    case CheckUserAccess = 'com.atproto.simplespace.checkUserAccess';
}
