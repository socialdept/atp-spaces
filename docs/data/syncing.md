# Syncing a space

Part of the [atp-spaces docs](../introduction.md#documentation). Keeping a copy current with the operation log and the authority's notifications.

## Incremental sync

Each member's host keeps an operation log. Read it from the last revision you saw, and fold the operations into the set hash you saved:

```php
use SocialDept\AtpSpaces\Repo\RepoCommit;

$result = $reader->listRepoOps($memberDid, since: $cursor);

$repo = RepoCommit::fromState($savedState)->applyOps($result['ops']);

// The commit arrives only once you reach the head of the log, which is
// exactly when comparing digests means something. It is already verified.
if ($result['commit'] && $repo->matches($result['commit'])) {
    // in sync
}
```

`listRepoOps()` returns `ops` as `RepoOp` objects, the `commit` when the page reaches the head of the log, and a `cursor`. A create carries a `cid`, a delete a `prev`, and an update both. See [Verifying repo state](verifying.md) for the set hash.

The oplog is a transport optimization with no history guarantee: a host may compact or drop it. When `since` is too old, fall back to `listRecords()` or `getRepo()`.

## Notifications

Polling every member with `listRepoOps()` works, but the authority can tell you when something changes instead. Register your service for a space, and the authority calls you each time a member's repo advances, and once more if the space is deleted.

```php
$reader = $client->space->read($space);

$expiresAt = $reader->registerNotify();
```

`registerNotify()` returns the expiry as a `CarbonImmutable`. The registration lapses on its own, after a day on the alpha PDS. Register again before `$expiresAt` to stay subscribed, and call `unregisterNotify()` to stop early.

Your service is named by the same kind of identifier the managing app uses, a DID plus the fragment of the DID document entry the authority delivers to. It defaults to `ATP_SERVICE_AUTH_AUDIENCE`, or pass one to `registerNotify()`.

```env
ATP_SERVICE_AUTH_AUDIENCE=did:web:app.example.com#atproto_space_syncer
SPACES_SYNCER=true
```

With the syncer on, the package serves `com.atproto.space.notifyWrite` and `com.atproto.space.notifySpaceDeleted` under `syncer.prefix`, `/xrpc` by default, and turns each call into an event:

```php
use SocialDept\AtpSpaces\Events\SpaceDeletedNotified;
use SocialDept\AtpSpaces\Events\SpaceWriteNotified;

Event::listen(function (SpaceWriteNotified $event) {
    $last = SyncState::lastSpaceRev($event->space);

    if (! $event->follows($last)) {
        // Notifications were missed. Catch up with listRepos() from $last.
    }

    // Then read what changed with listRepoOps($event->repo, since: ...).
});

Event::listen(function (SpaceDeletedNotified $event) {
    // Drop your copy of $event->space.
});
```

`SyncState` stands for wherever you keep the last `spaceRev` per space. `SpaceWriteNotified` carries the `space`, the `repo` DID, its `repoRev`, the commit `data`, the `spaceRev` and the `prevSpaceRev` before it.

A notification says only that a repo moved, never what changed, so a listener reads the new state itself. Queue your listeners: the authority does not wait long for an answer.

Two things these endpoints do on your behalf:

- **Confirm the caller governs the space.** As with the managing app, the middleware proves some service signed for the method, and the package checks that the signer is that space's own authority. Without it anyone with a token could tell you a space was deleted.
- **Stay off until you turn them on.** The routes must be reachable from the public internet.

> [!NOTE]
> Delivery is best effort. The authority does not retry a write notification, so treat the events as a prompt to sync, not as the record of what happened. `prevSpaceRev` is how you notice a gap, and a deletion you miss surfaces later as `SpaceDeleted` when a credential is renewed.
