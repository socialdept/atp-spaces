<h1 align="center">ATP Spaces</h1>

<h3 align="center">
    Read and write permissioned AT Protocol data in Laravel.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/atp-spaces" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/atp-spaces.svg?style=flat-square"></a>
    <a href="https://packagist.org/packages/socialdept/atp-spaces" title="Total Downloads"><img src="https://img.shields.io/packagist/dt/socialdept/atp-spaces.svg?style=flat-square"></a>
    <a href="https://github.com/socialdept/atp-spaces/actions/workflows/tests.yml" title="GitHub Tests Action Status"><img src="https://img.shields.io/github/actions/workflow/status/socialdept/atp-spaces/tests.yml?branch=main&label=tests&style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/atp-spaces?style=flat-square"></a>
</p>

---

> [!WARNING]
> **Don't use this in production.** Spaces are an alpha AT Protocol extension. The lexicons this
> package is built against are still moving, and so is this package. Only alpha PDS software
> serves these endpoints today. Pin a version, expect breaking changes, and treat anything you
> store as throwaway.

## What is Spaces?

**Spaces** is a Laravel package for AT Protocol **permissioned data**, meaning records only
certain people and applications can read. Private drafts, subscriber-only publications, group
discussions, anything you don't want on the public firehose.

A space is an authorization and sync boundary. It is **not** encryption. Records stay plaintext
to any authorized reader. It is **not** a separate database either. Each member's records live
in their own repo on their own PDS, right next to their public ones.

Think of it as a private corner of the Atmosphere that its owner decides who may enter.

## Why use Spaces?

- **Familiar addressing** - `SpaceRef` and `SpaceRecordUri` parse and build space URIs as immutable value objects
- **Credentials handled for you** - The two-hop delegation-token exchange, DPoP binding, caching and renewal
- **Reads across the space** - One credential reads every member's repo, wherever each one is hosted
- **Space management** - Create spaces, admit members, and choose how the perimeter is enforced
- **App-gated access** - Answer `checkUserAccess` yourself, so entitlements stay in your own database
- **Verifiable state** - Repo commits check out against a set hash computed locally, signature and all
- **Pinned lexicons** - Upstream definitions vendored verbatim, with a command that detects drift
- **No app-specific opinions** - Ships no space types of its own, so you register yours
- **DIDs or handles** - Anywhere an actor is named, either works, matching the rest of the `atp-*` packages

## Quick Example

```php
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSpaces\Management\SpacePolicy;

$client = Atp::as('alice.example.com');

// Create a space you own, and admit someone
$space = $client->space->manage->createSpace('com.example.forum', SpacePolicy::memberList());
$client->space->manage->putMember($space, 'bob.example.com');

// Write to your own repo inside it
$client->space->repo->createRecord($space, 'com.example.thread', [
    '$type' => 'com.example.thread',
    'title' => 'Hello, members',
]);

// Read someone else's repo inside it
$reader = $client->space->read($space);

$reader->listRepos();                                   // who is in the space
$reader->listRecords('bob.example.com', 'com.example.thread');
```

## Installation

```bash
composer require socialdept/atp-spaces
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=atp-spaces-config
```

## Getting Started

### Addressing a space

A space is named by a triple. The DID that governs it, an NSID for its kind, and a key
distinguishing it from others of that kind.

```php
use SocialDept\AtpSpaces\SpaceRef;

$space = SpaceRef::make('did:plc:alice', 'com.example.forum', 'main');
(string) $space;   // at://did:plc:alice/space/com.example.forum/main

$space = SpaceRef::parse('at://did:plc:alice/space/com.example.forum/main');
$space->authority; // did:plc:alice
$space->type;      // com.example.forum
$space->skey;      // main

$record = $space->record('did:plc:bob', 'com.example.thread', '3abc');
$record->path();   // com.example.thread/3abc
```

`SpaceRef::tryParse()` and `SpaceRecordUri::tryParse()` return `null` rather than throwing.

### Declaring space types

A space type names a kind of space and lists the collections clients should expect in it. This
package ships none, because a space type belongs to whoever defines it. Register yours in
`config/atp-spaces.php`:

```php
'types' => [
    'com.example.forum' => [
        'key' => 'any',
        'name' => 'Example Forum',
        'collections' => ['com.example.thread', 'com.example.reply'],
    ],
],
```

```php
use SocialDept\AtpSpaces\SpaceTypeRegistry;

$type = app(SpaceTypeRegistry::class)->get('com.example.forum');

$type->nameFor('es');                  // name for an OAuth consent screen
$type->collections;                    // what clients should expect
$type->space('did:plc:alice', 'main'); // a SpaceRef
```

The collection list is a recommendation, not a constraint. A space accepts records outside it.
It exists so consent screens can describe the access being requested.

### Requesting access

Spaces use their own OAuth scope. Parameters equal to their default are omitted, so the
shortest form is the canonical one.

```php
use SocialDept\AtpSpaces\Scopes\SpaceAction;
use SocialDept\AtpSpaces\Scopes\SpaceScope;

(string) SpaceScope::for('com.example.forum');
// space:com.example.forum

(string) SpaceScope::forSpace($space)->readOnly();
// space:com.example.forum?authority=did:plc:alice&skey=main&action=read

(string) SpaceScope::for('com.example.forum')
    ->collections(['com.example.thread'])
    ->actions([SpaceAction::Create, SpaceAction::Update]);
// space:com.example.forum?collection=com.example.thread&action=create&action=update
```

Defaults: `authority=self`, `skey=*`, no collections, the four non-`read_self` actions, and no
management operations.

## Reading and Writing

The two halves of the protocol authenticate differently, and this package keeps that visible.

**Writes go to your own PDS, under your OAuth session.** You can only write to your own repo, in
a space you belong to.

```php
$client->space->repo->createRecord($space, 'com.example.thread', $record, rkey: '3abc');
$client->space->repo->putRecord($space, 'com.example.thread', '3abc', $record);
$client->space->repo->deleteRecord($space, 'com.example.thread', '3abc');
$client->space->repo->applyWrites($space, $writes);

$client->space->repo->listSpaces();   // spaces you have written to
```

**Reads go to each member's own host, under a space credential.** There is no relay for
permissioned data, so reading a space means visiting its members in turn. Anywhere a member is
named, a DID or a handle both work. A DID costs nothing, a handle is resolved for you.

```php
$reader = $client->space->read($space);

$reader->listRepos();
$reader->getRecord($did, 'com.example.thread', '3abc');
$reader->listRecords($did, 'com.example.thread');
$reader->getBlob($did, $cid);
$reader->getRepo($did);        // raw CAR bytes
```

### Incremental sync

```php
use SocialDept\AtpSpaces\Repo\RepoCommit;

$result = $reader->listRepoOps($memberDid, since: $cursor);

$repo = RepoCommit::fromState($savedState)->applyOps($result['ops']);

// The commit arrives only once you reach the head of the log — which is
// exactly when comparing hashes means something.
if ($result['commit'] && $repo->matches($result['commit'])) {
    // in sync
}
```

The oplog is a transport optimization with no history guarantee: a host may compact or drop it.
When `since` is too old, fall back to `listRecords()` or `getRepo()`.

## Managing Spaces

A space is anchored on the authenticated user's DID, who becomes its owner.

```php
use SocialDept\AtpSpaces\Management\AppAccess;
use SocialDept\AtpSpaces\Management\SpacePolicy;

$space = $client->space->manage->createSpace(
    spaceType: 'com.example.forum',
    readPolicy: SpacePolicy::memberList(),
    writePolicy: SpacePolicy::memberList(),
    appAccess: AppAccess::open(),
);

$client->space->manage->putMember($space, 'bob.example.com');                 // read and write
$client->space->manage->putMember($space, 'carol.example.com', write: false); // read only
$client->space->manage->removeMember($space, 'did:plc:bob');
$client->space->manage->listMembers($space);   // each with did, read and write
$client->space->manage->updateSpace($space, readPolicy: SpacePolicy::public());
$client->space->manage->deleteSpace($space);
```

`putMember()` sets both permissions at once, replacing what the member had. `addMember()` still
works, as a deprecated shortcut for a member who may read and write.

### Access policies

A space has two policies, one for reading and one for writing. The read policy decides who is
issued a credential, and so who can see the space. The write policy decides whose repo the
authority tracks and whose writes it forwards to readers. Nobody can be stopped from writing
into their own repo, only from being counted in the space. Each is one of:

| Policy | Behaviour |
|--------|-----------|
| `SpacePolicy::memberList()` | Only DIDs on the space's member list. The default. |
| `SpacePolicy::public()` | Anyone who asks. |
| `SpacePolicy::managingApp($did)` | Your application is asked, per request. |

`managingApp` is the interesting one. The authority calls your app's `checkUserAccess`, for
reading each time a credential is minted and for writing when a member writes, so authorization
stays in your own system instead of being mirrored into a member list. Entitlements,
subscriptions, bans, whatever you already have.

See [Answering for a space](#answering-for-a-space) to serve that endpoint.

Orthogonally, how it decides whether to admit an **app**:

| Policy | Behaviour |
|--------|-----------|
| `AppAccess::open()` | Any app. No client attestation required. The default. |
| `AppAccess::allowList([...])` | Only the named OAuth client IDs, checked against an attestation. |

## Answering for a Space

When a space uses `SpacePolicy::managingApp()`, the authority calls your application to ask
whether a user may read the space, or write to it. Implement a resolver:

```php
use SocialDept\AtpSpaces\Access\SpaceAccessRequest;
use SocialDept\AtpSpaces\Contracts\SpaceAccessResolver;

class SubscriberAccess implements SpaceAccessResolver
{
    public function authorize(SpaceAccessRequest $request): bool
    {
        if ($request->isOwner()) {
            return true;
        }

        // Subscribers read, and only staff write.
        if ($request->isWrite()) {
            return Staff::query()->where('did', $request->user)->exists();
        }

        return Subscription::query()
            ->where('publication_did', $request->space->authority)
            ->where('subscriber_did', $request->user)
            ->active()
            ->exists();
    }
}
```

Bind it and turn the endpoint on:

```php
// config/atp-spaces.php
'managing_app' => [
    'enabled' => true,
    'resolver' => App\Spaces\SubscriberAccess::class,
],
```

```env
ATP_SERVICE_AUTH_AUDIENCE=did:web:app.example.com#forum
```

That audience is the identifier you publish in your DID document and name in
`SpacePolicy::managingApp()`. Requests are authenticated by `atp-client`'s `atp.service-auth`
middleware, which verifies the authority's signature against its published key.

Three things this endpoint does on your behalf:

- **Confirms the caller governs the space it is asking about.** The middleware proves some
  service signed for this method, but not which space it owns. Without that check any token
  holder could ask who has access to anyone's space.
- **Fails closed.** A throwing resolver, an unauthenticated request, or no resolver at all all
  answer `false`. The reason goes to the log, not the response.
- **Stays off until you turn it on.** The default resolver refuses everyone, so a half-finished
  setup grants nothing.

> [!IMPORTANT]
> An unreachable managing app **denies**. Under this policy your endpoint's availability becomes
> your content's availability, and the authority calls it while a user waits for a credential.
> Keep the resolver fast, and be careful what it depends on.

## Credentials

Getting into a space takes two hops, because the two halves answer different questions. Your PDS
attests *that this app acts for this user*, in a 60-second single-use delegation token. The space
authority decides *whether that user may enter*, and issues a credential bound to a key only your
app holds.

```php
$credential = $client->space->credential($space);

$credential->expiresAt();
$credential->isExpired(leeway: 30);
$credential->isCorrectlyBound();   // checked automatically on issue

$client->space->forget($space);    // discard, forcing a fresh mint
```

Credentials are cached for their lifetime, keyed by space and user. Configure under
`atp-spaces.credentials`, or set `SPACES_CREDENTIAL_CACHE=false` to mint one per use.

A credential reads the **whole space** and is presented to every repo host in it. As a bearer
token that would be a shared secret, since a host given one could replay it against its peers.
So it is bound to a DPoP key and presented under the `DPoP` scheme, never `Bearer`.

Refusals say which perimeter failed:

```php
use SocialDept\AtpSpaces\Exceptions\CredentialException;

try {
    $reader = $client->space->read($space);
} catch (CredentialException $e) {
    $e->isUserRefusal();   // UserNotAuthorized
    $e->isAppRefusal();    // AppNotAuthorized
    $e->isSpaceDeleted();  // SpaceDeleted
}
```

An authority that would rather not disclose which perimeter failed returns `NotAuthorized`, and
both predicates are false.

## Verifying Repo State

Permissioned repos have no Merkle search tree. State is the *set* of records a repo contains,
folded into a homomorphic set hash, so operations replay in any order and still produce a
comparable digest.

```php
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;

$repo = RepoCommit::fromIndex([
    'com.example.thread/1' => 'bafyA',
    'com.example.thread/2' => 'bafyB',
]);

$repo->applyOp(new RepoOp('com.example.thread', '1', cid: 'bafyC', prev: 'bafyA'));

$repo->matches($signedCommit);   // does our state match the host's claim?
```

A space commit's signature covers only its **context**, meaning the space, author, revision, and
per-commit keying material. It never covers the repo hash. A leaked commit therefore proves
nothing about what its author wrote. The hash is bound to that context by a symmetric MAC
instead, so readers get integrity while third parties get nothing.

```php
$result = RepoCommit::verify($commit, $context, $verifier, $didKey);

$result->isIntact();          // the hash belongs to this context
$result->isFullyVerified();   // ...and the author signed it
$result->failureReason();     // null, or a short explanation
$result->assertFullyVerified();
```

A verifier backed by `atp-support` is bound by default, so `isFullyVerified()` means it. Without
one, `signatureChecked` is `false` and an unchecked signature is never reported as valid.

### BLAKE3

The set hash expands each element with BLAKE3 in XOF mode. PHP has no BLAKE3 and no maintained
package provides one, so this ships a pure-PHP implementation verified against all 105 upstream
test vectors.

It costs roughly **1.25 ms per record**, which is fine incrementally and acceptable for
background verification. If it isn't, bind your own `Contracts\Blake3Hasher`. `Crypto\Blake3` is
also usable standalone for hashing, keyed hashing, and key derivation.

## Lexicon Drift

The `com.atproto.space.*` and `com.atproto.simplespace.*` lexicons are vendored verbatim under
`lexicons/`, pinned to an upstream commit in `MANIFEST.json`. Nothing is transcribed by hand, so
a rename upstream surfaces as a failing command rather than a 400 in production.

```bash
php artisan spaces:lexicon-drift             # compare against upstream
php artisan spaces:lexicon-drift --offline   # verify the vendored files only
php artisan spaces:lexicon-drift --token=…   # raise the GitHub rate limit
```

The command exits non-zero on drift. Vendored files are byte-for-byte upstream's, so re-vendor
them rather than editing them.

## Gotchas

**`listSpaces` is a hint, not a roster.** It reports spaces the user has *written to*, and a
member's per-space repo survives the space being deleted, as an empty shell no method removes.
`getDelegationToken` also succeeds for spaces that never existed, since the PDS mints tokens
without checking. The durable signal is one hop later, at credential renewal:

```php
catch (CredentialException $e) {
    if ($e->isSpaceDeleted()) { /* prune it */ }
}
```

**App passwords do not work.** The space endpoints require `ACCESS_FULL`
(`com.atproto.access`), which excludes both `AppPass` and `AppPassPrivileged`. Use OAuth.

**Only loopback redirects are accepted** for `http://` OAuth clients. That means `localhost`,
`127.0.0.1`, or `[::1]`. A LAN or tailnet address is rejected outright.

**`space:` scopes work** and are granted verbatim, even though an authorization server's
`scopes_supported` metadata may not list them.

## Available Commands

```bash
php artisan spaces:lexicon-drift    # check the vendored lexicons against upstream
```

## Requirements

- PHP 8.3+
- Laravel 11+
- [socialdept/atp-support](https://github.com/socialdept/atp-support) - Identity resolution, did:key signature verification
- [socialdept/atp-client](https://github.com/socialdept/atp-client) - OAuth sessions and the authenticated HTTP client

### Development target

Bluesky runs a public alpha PDS with spaces enabled at `https://spaces-alpha.host.bsky.network`
(handles under `.spaces-alpha.bsky.network`, invite required). The full lifecycle has been
exercised against it with three real accounts, and the set hash computed here matched the commit
that PDS signed.

## Running Tests

```bash
composer install
vendor/bin/phpunit --exclude-group integration
vendor/bin/php-cs-fixer fix --dry-run --diff
```

The `integration` group reaches the network and is excluded from CI, since a failure there means
the world changed rather than the code:

```bash
vendor/bin/phpunit --group integration
```

`SpaceLifecycleTest` goes further and exercises the whole feature against a live PDS. It needs
three OAuth-authorized accounts and **skips** without them:

```bash
php dev/oauth.php authorize alice.spaces-alpha.bsky.network
# approve in a browser, then copy the failed callback URL
php dev/oauth.php exchange alice.spaces-alpha.bsky.network '<callback-url>'

ATP_SPACES_TOKENS=./.oauth vendor/bin/phpunit --group integration
```

It cleans up after itself, but each run leaves one empty repo shell on the member's account.
See the gotcha above. Point it at throwaway accounts.

## Resources

- [Atproto Spaces (alpha)](https://atproto.com/blog/atproto-spaces-alpha)
- [Permissioned Data Proposal](https://github.com/bluesky-social/proposals/tree/main/0016-permissioned-data)
- [AT Protocol Documentation](https://atproto.com/)
- [OAuth Scopes Proposal](https://github.com/bluesky-social/proposals/tree/main/0011-auth-scopes)

## Support & Contributing

Found a bug or have a feature request? [Open an issue](https://github.com/socialdept/atp-spaces/issues).

Want to contribute? We'd love your help! Check out the [contribution guidelines](CONTRIBUTING.md).

## Credits

- [Miguel Batres](https://batres.co) - founder & lead maintainer
- [All contributors](https://github.com/socialdept/atp-spaces/graphs/contributors)

## License

ATP Spaces is open-source software licensed under the [MIT license](LICENSE).

---

**Built for the Atmosphere** • By Social Dept.
