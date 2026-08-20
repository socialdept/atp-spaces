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

## Status

| | |
|---|---|
| Addressing, space types, scopes | done |
| Repo set hash, commits, verification | done |
| Lexicon vendoring + drift detection | done |
| Credential broker, read/write client | not yet |
| Space management (`simplespace`) | not yet |
| Managing-app `checkUserAccess` endpoint | not yet |
| Incremental sync + notifications | not yet |

## Testing

```bash
composer install
vendor/bin/phpunit --exclude-group integration
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## License

MIT
