# Managing spaces

Part of the [atp-spaces docs](../introduction.md#documentation). Creating spaces, admitting members, and the read, write and app policies.

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

`createSpace()` returns the new `SpaceRef`. Pass `skey:` to choose the space key, or leave it out and the host generates a TID. Every policy left out defaults to the member list, and app access to open.

`putMember()` sets both permissions at once, replacing what the member had. `addMember()` still works, as a deprecated shortcut for a member who may read and write. `updateSpace()` changes only the policies it is given. `getSpace()` reads a space's configuration back, with its policies as `SpacePolicy` and `AppAccess` objects.

## Access policies

A space has two policies, one for reading and one for writing. The read policy decides who is issued a credential, and so who can see the space. The write policy decides whose repo the authority tracks and whose writes it forwards to readers. Nobody can be stopped from writing into their own repo, only from being counted in the space. Each is one of:

| Policy | Behavior |
|--------|----------|
| `SpacePolicy::memberList()` | Only DIDs on the space's member list. The default. |
| `SpacePolicy::public()` | Anyone who asks. |
| `SpacePolicy::managingApp($did)` | Your application is asked, per request. |

`managingApp` is the interesting one. The authority calls your app's `checkUserAccess`, for reading each time a credential is minted and for writing when a member writes, so authorization stays in your own system instead of being mirrored into a member list. Entitlements, subscriptions, bans, whatever you already have. It takes a DID or a handle, with an optional service fragment, such as `did:web:app.example.com#forum`. See [Answering for a space](managing-app.md) to serve that endpoint.

A read policy is checked when a credential is minted, not when a record is read, so a change takes effect within one credential lifetime rather than at once.

Separately, the app policy decides whether to admit an **app**:

| Policy | Behavior |
|--------|----------|
| `AppAccess::open()` | Any app. No client attestation required. The default. |
| `AppAccess::allowList([...])` | Only the named OAuth client IDs, checked against an attestation. |

Under an allow list, pass the client attestation when you ask for a reader or a credential, as `$client->space->read($space, $clientAttestation)`.
