# Writing records

Part of the [atp-spaces docs](../introduction.md#documentation). Writing to your own repo in a space, under your OAuth session.

The two halves of the protocol authenticate differently, and this package keeps that visible. Writes go to your own PDS, under your OAuth session. You can only write to your own repo, in a space you belong to. Reads go to each member's host under a space credential, see [Reading a space](reading.md).

```php
$client->space->repo->createRecord($space, 'com.example.thread', $record, rkey: '3abc');
$client->space->repo->putRecord($space, 'com.example.thread', '3abc', $record);
$client->space->repo->deleteRecord($space, 'com.example.thread', '3abc');
$client->space->repo->applyWrites($space, $writes);

$client->space->repo->listSpaces();   // spaces you have written to
```

A record must carry a `$type`. `createRecord()` and `putRecord()` return the new record's `uri` as a `SpaceRecordUri`, its `cid` and its `validationStatus`. Leave out `rkey` and the host picks one. `putRecord()` and `deleteRecord()` take `swapRecord:` for a compare and swap, and `createRecord()`, `putRecord()` and `applyWrites()` take `validate:`. Each write in `applyWrites()` is tagged with its `$type`, and the batch is applied atomically.

`listSpaces()` returns `spaces`, as `SpaceRef` objects, and a `cursor`. It filters by space type with `spaceType:` and by authority with `actor:`. It lists the spaces you have written to, not the ones you belong to, see [Gotchas](../gotchas.md).
