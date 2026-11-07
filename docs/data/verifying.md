# Verifying repo state

Part of the [atp-spaces docs](../introduction.md#documentation). The set hash, MACs from each member's host, and BLAKE3.

Space repos have no Merkle search tree. State is the *set* of records a repo contains, folded into a homomorphic set hash, so operations replay in any order and still produce a comparable digest.

```php
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;

$repo = RepoCommit::fromIndex([
    'com.example.thread/1' => 'bafyA',
    'com.example.thread/2' => 'bafyB',
]);

$repo->applyOp(new RepoOp('com.example.thread', '1', cid: 'bafyC', prev: 'bafyA'));

$repo->matches($reader->getLatestCommit('did:plc:bob'));   // does our state match the host's?
```

`RepoCommit::fromRecords()` folds in records given as `collection`, `rkey` and `cid`, and `fromState()` restores one saved from `$repo->setHash->state()`. `add()` and `remove()` change one record.

## MACs

A commit carries no signature. Each member publishes a `#atproto_kem` key in their DID document, and for every read the reader generates a fresh key, derives a secret only it and the member's host share, and asks for a MAC. The host returns the commit or record with a MAC keyed by that secret, so a leaked response proves nothing to anyone else.

The reader does all of this itself. `getLatestCommit()`, the commit at the head of `listRepoOps()`, `getRepo()` and `getRecord()` return only what checked out, and throw a `RepoVerificationException` otherwise. A record is also checked against its CID. A failed check is retried once after rereading the member's DID document, in case their KEM key rotated. A member whose host has not yet published a KEM key cannot be read with a credential until it does.

To check a commit by hand:

```php
$result = RepoCommit::verify($commit, (string) $space, $repo, $sharedSecret, $mac);

$result->isVerified();      // the right repo and space, and the MAC matches
$result->failureReason();   // null, or a short explanation
$result->assertVerified();
```

`isVerified()` also requires a commit version this package supports.

## BLAKE3

The set hash expands each element with BLAKE3 in XOF mode. PHP has no BLAKE3 and no maintained package provides one, so this ships a pure-PHP implementation verified against all 105 upstream test vectors.

It costs roughly **1.25 ms per record**, which is fine incrementally and acceptable for background verification. If it isn't, bind your own `Contracts\Blake3Hasher`. `Crypto\Blake3` is also usable standalone for hashing, keyed hashing, and key derivation.
