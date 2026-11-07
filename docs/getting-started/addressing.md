# Addressing a space

Part of the [atp-spaces docs](../introduction.md#documentation). Space references and space record URIs.

A space is named by a triple: the DID that governs it, an NSID for its kind, and a key distinguishing it from others of that kind.

```php
use SocialDept\AtpSpaces\SpaceRef;

$space = SpaceRef::make('did:plc:alice', 'com.example.forum', 'main');
(string) $space;   // at://did:plc:alice/space/com.example.forum/main

$space = SpaceRef::parse('at://did:plc:alice/space/com.example.forum/main');
$space->authority; // did:plc:alice
$space->type;      // com.example.forum
$space->skey;      // main
```

`SpaceRef::make()` takes a DID or a handle for the authority, and resolves a handle to its DID. The key follows record-key syntax. `parse()` accepts only a DID authority, since a space URI always carries one.

A record inside a space adds the repo it lives in, then its collection and record key:

```php
$record = $space->record('did:plc:bob', 'com.example.thread', '3abc');

(string) $record;  // at://did:plc:alice/space/com.example.forum/main/did:plc:bob/com.example.thread/3abc
$record->path();   // com.example.thread/3abc
```

The author segment matters, because records are not kept on the authority's host. Each member holds their own repo for the space on their own PDS, and the URI names which of those repos the record is in.

`SpaceRef::tryParse()` and `SpaceRecordUri::tryParse()` return `null` rather than throwing. `parse()` and `make()` throw an `InvalidSpaceRefException`.

The methods on `$client->space`, including `repo` and `manage`, accept a `SpaceRef` or its string form. The `Spaces` facade takes a `SpaceRef`.
