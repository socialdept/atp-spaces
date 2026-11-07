# Reading a space

Part of the [atp-spaces docs](../introduction.md#documentation). Reading every member's repo with one space credential.

Reads go to each member's own host, under a space [credential](../access/credentials.md). There is no relay for space data, so reading a space means visiting its members in turn. Anywhere a member is named, a DID or a handle both work. A DID costs nothing, a handle is resolved for you.

```php
$reader = $client->space->read($space);

$reader->listRepos();                                   // who writes, by spaceRev
$reader->getRecord($did, 'com.example.thread', '3abc'); // checked against its MAC and CID
$reader->listRecords($did, 'com.example.thread');
$reader->getBlob($did, $cid);
$reader->getRepo($did);    // ['commit' => …, 'records' => [...]], checked whole
```

`read()` mints a credential, or reuses a cached one, and returns a `SpaceReader`. `listRepos()` goes to the authority, since no single member knows the whole roster. Everything else goes to the member's own host.

`listRepos()` returns each writer's latest state, `{ did, repoRev, data, spaceRev }`, in `spaceRev` order. Its cursor is the last `spaceRev` read, so keep it as a checkpoint: pass it back to read on, and an empty page, with no cursor, means you are caught up. A repo updated during the listing can appear twice.

`getRepo()` reads a member's whole repo from a STAR archive. It returns only once the commit's MAC checks out and the records, folded into a set hash, reproduce the commit's digest. Records come in path order with their collection, record key, CID and value. When replacing a local copy, drop what is not there.

`listRecords()` also takes `limit:`, `cursor:`, `reverse:` and `excludeValues:`. `listBlobs($did)` lists the CIDs of the blobs a repo references, and `getBlob()` returns a blob's bytes.

A reader throws a `SpacesException` when a host fails, a `RepoVerificationException` when a commit or record does not check out, and a `CredentialException` when the credential was revoked. See [Verifying repo state](verifying.md) for what is checked.
