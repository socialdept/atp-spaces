<?php

namespace SocialDept\AtpSpaces\Enums;

/**
 * The `com.atproto.space.*` XRPC methods.
 *
 * Grouped here by who serves them, because that determines how a request is
 * authenticated — the PDS methods take the user's OAuth session, the repo and
 * host methods take a space credential with a DPoP proof.
 */
enum SpaceNsid: string
{
    // Served by the user's own PDS, with OAuth.
    case GetDelegationToken = 'com.atproto.space.getDelegationToken';
    case ListSpaces = 'com.atproto.space.listSpaces';
    case CreateRecord = 'com.atproto.space.createRecord';
    case PutRecord = 'com.atproto.space.putRecord';
    case DeleteRecord = 'com.atproto.space.deleteRecord';
    case ApplyWrites = 'com.atproto.space.applyWrites';

    // Served by the space authority.
    case GetSpaceCredential = 'com.atproto.space.getSpaceCredential';
    case ListRepos = 'com.atproto.space.listRepos';

    // Served by each member's repo host, with a space credential.
    case GetRecord = 'com.atproto.space.getRecord';
    case ListRecords = 'com.atproto.space.listRecords';
    case ListRepoOps = 'com.atproto.space.listRepoOps';
    case GetLatestCommit = 'com.atproto.space.getLatestCommit';
    case GetRepo = 'com.atproto.space.getRepo';
    case GetBlob = 'com.atproto.space.getBlob';
    case ListBlobs = 'com.atproto.space.listBlobs';

    // Notification routing.
    case RegisterNotify = 'com.atproto.space.registerNotify';
    case UnregisterNotify = 'com.atproto.space.unregisterNotify';
    case NotifyWrite = 'com.atproto.space.notifyWrite';
    case NotifySpaceDeleted = 'com.atproto.space.notifySpaceDeleted';

    /**
     * Whether the user's own PDS serves this method under OAuth.
     */
    public function servedByPds(): bool
    {
        return in_array($this, [
            self::GetDelegationToken,
            self::ListSpaces,
            self::CreateRecord,
            self::PutRecord,
            self::DeleteRecord,
            self::ApplyWrites,
        ], true);
    }
}
