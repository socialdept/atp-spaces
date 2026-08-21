# atp-spaces

AT Protocol **spaces** (permissioned data) for Laravel.

> **Alpha.** Spaces are an in-progress AT Protocol extension. The lexicons this
> package is built against move, and so does this package. See
> [Lexicon drift](#lexicon-drift).

A space is an authorization and sync boundary for private or gated records. It
is **not** encryption — records are plaintext to any authorized reader — and it
is **not** a separate database: each member's records live in a per-space repo
on their own PDS, beside their public one.

```
at://{authority}/space/{type}/{skey}                          a space
at://{authority}/space/{type}/{skey}/{author}/{coll}/{rkey}   a record in it
```

## Installation

```bash
composer require socialdept/atp-spaces
```

```bash
php artisan vendor:publish --tag=atp-spaces-config
```

## Addressing spaces

```php
use SocialDept\AtpSpaces\SpaceRef;

$space = SpaceRef::make('did:plc:abc', 'com.example.forum', 'main');
(string) $space;   // at://did:plc:abc/space/com.example.forum/main

$space = SpaceRef::parse('at://did:plc:abc/space/com.example.forum/main');
$space->authority; // did:plc:abc
$space->type;      // com.example.forum
$space->skey;      // main

$record = $space->record('did:plc:author', 'com.example.thread', '3abc');
$record->path();   // com.example.thread/3abc
```

`SpaceRef::tryParse()` and `SpaceRecordUri::tryParse()` return `null` instead of
throwing.

## Space types

A space type is an NSID naming a kind of space. This package ships none — a
space type belongs to whoever defines it. Register your own in
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
$type->nameFor('es');                    // consent-screen name
$type->collections;                      // what clients should expect
$type->space('did:plc:abc', 'main');     // a SpaceRef
```

The collection list is a recommendation, not a constraint — a space accepts
records outside it. It exists so OAuth consent screens can describe what access
is being requested.

## OAuth scopes

```php
use SocialDept\AtpSpaces\Scopes\SpaceScope;
use SocialDept\AtpSpaces\Scopes\SpaceAction;

(string) SpaceScope::for('com.example.forum');
// space:com.example.forum

(string) SpaceScope::forSpace($space)->readOnly();
// space:com.example.forum?authority=did:plc:abc&skey=main&action=read

(string) SpaceScope::for('com.example.forum')
    ->collections(['com.example.thread'])
    ->actions([SpaceAction::Create, SpaceAction::Update]);
// space:com.example.forum?collection=com.example.thread&action=create&action=update
```

Parameters equal to their default are omitted, so the shortest form of a scope
is the canonical one. Defaults: `authority=self`, `skey=*`, no collections, the
four non-`read_self` actions, no management operations.

## Reading and writing

Spaces attach to an authenticated [`atp-client`](https://github.com/socialdept/atp-client)
session as the `space` domain, because the two halves of the protocol authenticate
differently and it is worth seeing which is which.

**Writes go to your own PDS, under your OAuth session.** You can only ever write to your own
repo, in a space you belong to:

```php
use SocialDept\AtpClient\Facades\Atp;

$client = Atp::as('alice.example.com');

$client->space->repo->createRecord($space, 'com.example.thread', [
    '$type' => 'com.example.thread',
    'title' => 'Hello',
]);

$client->space->repo->putRecord($space, 'com.example.thread', '3abc', $record);
$client->space->repo->deleteRecord($space, 'com.example.thread', '3abc');

$client->space->repo->listSpaces();   // spaces you have written to
```

**Reads go to each member's own host, under a space credential.** There is no relay for
permissioned data, so reading a space means visiting its members in turn:

```php
$reader = $client->space->read($space);

$reader->listRepos();                                  // who is in the space
$reader->getRecord($memberDid, 'com.example.thread', '3abc');
$reader->listRecords($memberDid, 'com.example.thread');
$reader->getBlob($memberDid, $cid);
```

The credential is minted on first use and cached until it expires.

### Incremental sync

```php
$result = $reader->listRepoOps($memberDid, since: $cursor);

$repo = RepoCommit::fromState($savedState)->applyOps($result['ops']);

// The commit arrives only once you reach the head of the log — which is
// exactly when comparing hashes means something.
if ($result['commit'] && $repo->matches($result['commit'])) {
    // in sync
}
```

The oplog is a transport optimization with no history guarantee: a host may compact or drop
it. When `since` is too old, fall back to `listRecords()` or `getRepo()`.

## Managing spaces

```php
use SocialDept\AtpSpaces\Management\AppAccess;
use SocialDept\AtpSpaces\Management\SpacePolicy;

$space = $client->space->manage->createSpace(
    type: 'com.example.forum',
    policy: SpacePolicy::memberList(),
    appAccess: AppAccess::open(),
);

$client->space->manage->addMember($space, 'did:plc:someone');
$client->space->manage->listMembers($space);
```

A space is anchored on the authenticated user's DID, who becomes its owner.

Three user policies are available. `memberList()` is the default; `public()` admits anyone;
and `managingApp()` defers the decision to an application:

```php
SpacePolicy::managingApp('did:web:example.com#forum');
```

Under that policy the authority calls the named app's `checkUserAccess` each time a
credential is minted. It is how an application keeps authorization in its own system —
entitlements, subscriptions, bans, whatever — instead of mirroring state into a member list.
Serving that endpoint is not implemented yet.

Orthogonally, `AppAccess::allowList([...])` restricts a space to named OAuth clients, checked
against a client attestation.

## Credentials

Getting into a space is two hops, because the two halves answer different questions. Your PDS
attests *that this app acts for this user*, in a 60-second single-use delegation token. The
space authority decides *whether that user may enter*, and issues a credential bound to a key
only your app holds.

```php
$credential = $client->space->credential($space);

$credential->expiresAt();
$credential->isExpired(leeway: 30);
$credential->isCorrectlyBound();   // checked automatically on issue

$client->space->forget($space);    // discard, forcing a fresh mint
```

Credentials are cached for their lifetime, keyed by space and user. Configure with
`atp-spaces.credentials`, or set `SPACES_CREDENTIAL_CACHE=false` to mint one per use.

A credential reads the **whole space** and is presented to every repo host in it. As a bearer
token that would be a shared secret — a host given one could replay it against its peers — so
it is bound to a DPoP key instead and presented under the `DPoP` scheme, never `Bearer`.

Refusals arrive as a `CredentialException` that says which perimeter failed:

```php
try {
    $reader = $client->space->read($space);
} catch (CredentialException $e) {
    $e->isUserRefusal();   // UserNotAuthorized
    $e->isAppRefusal();    // AppNotAuthorized
    $e->isSpaceDeleted();  // SpaceDeleted
}
```

An authority that does not wish to disclose which perimeter failed returns `NotAuthorized`
instead, and both predicates are false.

## Repo state

Permissioned repos have no Merkle search tree. State is the *set* of records a
repo contains, folded into a homomorphic set hash, so operations can be replayed
in any order and still produce a comparable digest.

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

### Verifying a commit

A space commit's signature covers only its **context** — space, author, revision
and per-commit keying material — and never the repo hash. A leaked commit
therefore proves nothing about what its author wrote. The hash is bound to the
context by a symmetric MAC instead, so readers get integrity while third parties
get nothing.

```php
$result = RepoCommit::verify($commit, $context);

$result->isIntact();          // the hash belongs to this context
$result->isFullyVerified();   // ...and the author signed it
$result->failureReason();     // null, or a short explanation
```

Authenticity needs the author's signing key, so bind a
`Contracts\SignatureVerifier` to check signatures. Without one,
`signatureChecked` is `false` and `isFullyVerified()` returns `false` — an
unchecked signature is never reported as a valid one.

## BLAKE3

The set hash expands each element with BLAKE3 in XOF mode. PHP has no BLAKE3, so
the package ships a pure-PHP implementation verified against the upstream test
vectors — hash, keyed hash and key derivation, at extended output lengths.

It costs roughly **1.25 ms per record**. That is fine for incremental sync and
acceptable for background verification; if it is not, bind your own
`Contracts\Blake3Hasher`:

```php
$this->app->bind(Blake3Hasher::class, fn () => new MyFasterHasher());
```

`Crypto\Blake3` is also usable standalone:

```php
Blake3::hash($input, 32);
Blake3::keyedHash($key, $input);
Blake3::deriveKey($context, $keyMaterial);
```

## Lexicon drift

The `com.atproto.space.*` and `com.atproto.simplespace.*` lexicons are vendored
verbatim under `lexicons/`, pinned to an upstream commit in `MANIFEST.json`.
Nothing is transcribed by hand, so a rename upstream surfaces as a failing
command rather than a 400 in production.

```bash
php artisan spaces:lexicon-drift             # compare against upstream
php artisan spaces:lexicon-drift --offline   # only verify the vendored files
php artisan spaces:lexicon-drift --token=…   # raise the GitHub rate limit
```

The command exits non-zero on drift. It is also an integration test:

```bash
vendor/bin/phpunit --group integration
```

Vendored files are byte-for-byte upstream's — re-vendor them rather than editing.

## Development target

Bluesky runs a public alpha PDS with spaces enabled at
`https://spaces-alpha.host.bsky.network` (handles under
`.spaces-alpha.bsky.network`, invite required). Both the repo endpoints and the
authority's `getSpaceCredential` are live there.

The full lifecycle has been exercised against it with three real accounts — create a space,
add a member, write, read another member's repo through a credential, get refused as a
non-member, and tear it all down. The set hash computed here matched the commit that PDS
signed.

Two notes for anyone doing the same:

- **App passwords do not work.** The space endpoints require `ACCESS_FULL`
  (`com.atproto.access`), which excludes both `AppPass` and `AppPassPrivileged`. Use OAuth.
- **Only loopback redirects are accepted** for `http://` clients — `localhost`, `127.0.0.1`,
  or `[::1]`. A LAN or tailnet address is rejected outright.

`space:` scopes are accepted by the authorization server and come back granted verbatim, even
though its `scopes_supported` metadata does not list them.

## Gotchas

**`listSpaces` is a hint, not a roster.** It reports spaces the user has *written to*, and a
member's per-space repo survives the space being deleted — as an empty shell no method
removes. `getDelegationToken` also succeeds for spaces that never existed, since the PDS
mints tokens without checking. The durable signal is one hop later:

```php
try {
    $reader = $client->space->read($space);
} catch (CredentialException $e) {
    if ($e->isSpaceDeleted()) { /* prune it */ }
}
```

Let credential renewal be what prunes dead spaces.

## Status

| | |
|---|---|
| Addressing, space types, scopes | done |
| Repo set hash, commits, verification | done |
| Lexicon vendoring + drift detection | done |
| Credential broker (delegation → credential, DPoP-bound) | done |
| Read/write client | done |
| Space management (`simplespace`) | done |
| Managing-app `checkUserAccess` endpoint | not yet |
| Sync consumer + notification endpoints | not yet |
| CAR full-state verification (`getRepo`) | returns bytes; not yet parsed |

## Testing

```bash
composer install
vendor/bin/phpunit --exclude-group integration
vendor/bin/php-cs-fixer fix --dry-run --diff
```

The `integration` group reaches the network. Most of it is read-only — it checks the vendored
lexicons against upstream and probes the alpha PDS — and it is excluded from CI, since a
failure there means the world changed rather than the code:

```bash
vendor/bin/phpunit --group integration
```

`SpaceLifecycleTest` goes further and exercises the whole feature against a live PDS: create a
space, admit a member, write, read another member's repo through a credential, confirm a
non-member is refused, and verify a signed commit against a locally computed set hash. It
needs three OAuth-authorized accounts and **skips** without them.

```bash
php dev/oauth.php authorize alice.spaces-alpha.bsky.network
# approve in a browser, then copy the failed callback URL
php dev/oauth.php exchange alice.spaces-alpha.bsky.network '<callback-url>'

ATP_SPACES_TOKENS=./.oauth vendor/bin/phpunit --group integration
```

It cleans up after itself, but each run leaves one empty repo shell on the member's account —
see the gotcha above — so point it at throwaway accounts.

## License

MIT
