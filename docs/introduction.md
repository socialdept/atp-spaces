# Introduction

**ATP Spaces** is a Laravel package for atproto spaces, AT Protocol's permissioned data: records only certain people and applications can read. Private drafts, subscriber-only publications, group discussions, anything you don't want on the public firehose.

> [!WARNING]
> Spaces are an alpha AT Protocol extension, and only alpha PDS software serves these endpoints today. Pin a version, expect breaking changes, and treat anything you store as throwaway.

A space is an authorization and sync boundary. It is **not** encryption. Records stay plaintext to any authorized reader. It is **not** a separate database either. Each member's records live in their own repo on their own PDS, right next to their public ones. Think of it as a private corner of the Atmosphere whose owner decides who may enter.

- **Familiar addressing:** `SpaceRef` and `SpaceRecordUri` parse and build space URIs as immutable value objects.
- **Credentials handled for you:** the two-hop delegation token exchange, key binding, request signing, caching and renewal.
- **Reads across the space:** one credential reads every member's repo, wherever each one is hosted.
- **Space management:** create spaces, admit members, and choose how the perimeter is enforced.
- **App-gated access:** answer `checkUserAccess` yourself, so entitlements stay in your own database.
- **Verifiable state:** commits and records checked against a MAC from each member's host, and commits against a set hash computed locally.
- **Pinned lexicons:** upstream definitions vendored verbatim, with a command that detects drift.
- **No app-specific opinions:** ships no space types of its own, so you register yours.
- **DIDs or handles:** anywhere an actor is named, either works, matching the rest of the `atp-*` packages.

It builds on [atp-client](https://github.com/socialdept/atp-client) for OAuth sessions and service auth, and on [atp-support](https://github.com/socialdept/atp-support) for identity resolution and did:key signature verification.

## A first look

Spaces hang off an authenticated client as `$client->space`. Create a space you own and admit someone:

```php
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSpaces\Management\SpacePolicy;

$client = Atp::as('alice.example.com');

$space = $client->space->manage->createSpace('com.example.forum', SpacePolicy::memberList());
$client->space->manage->putMember($space, 'bob.example.com');
```

Writes go to your own repo inside the space, under your OAuth session:

```php
$client->space->repo->createRecord($space, 'com.example.thread', [
    '$type' => 'com.example.thread',
    'title' => 'Hello, members',
]);
```

Reads go to each member's own host, under a space credential the package mints for you:

```php
$reader = $client->space->read($space);

$reader->listRepos();                                   // who is in the space
$reader->listRecords('bob.example.com', 'com.example.thread');
```

## Documentation

Start with [Installation](getting-started/installation.md), then [Addressing a space](getting-started/addressing.md) for the URIs every other page uses. The docs are grouped by area, and each group has an index of its own:

- **[Getting started](getting-started/README.md)**: [Installation](getting-started/installation.md), [Addressing a space](getting-started/addressing.md), [Space types](getting-started/space-types.md)
- **[Access](access/README.md)**: [Managing spaces](access/managing.md), [Requesting access](access/scopes.md), [Credentials](access/credentials.md), [Answering for a space](access/managing-app.md)
- **[Reading and writing](data/README.md)**: [Writing records](data/writing.md), [Reading a space](data/reading.md), [Syncing a space](data/syncing.md), [Verifying repo state](data/verifying.md)
- **[Lexicon drift](lexicon-drift.md)**: The vendored lexicons and the command that checks them against upstream.
- **[Gotchas](gotchas.md)**: Behavior of the alpha PDS that surprises people.

## Further reading

- [Atproto Spaces (alpha)](https://atproto.com/blog/atproto-spaces-alpha)
- [Atproto Spaces Proposal](https://github.com/bluesky-social/proposals/tree/main/0016-permissioned-data)
- [OAuth Scopes Proposal](https://github.com/bluesky-social/proposals/tree/main/0011-auth-scopes)
- [AT Protocol Documentation](https://atproto.com/)
