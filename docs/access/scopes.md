# Requesting access

Part of the [atp-spaces docs](../introduction.md#documentation). Building `space:` OAuth scopes.

Spaces use their own OAuth scope. Parameters equal to their default are omitted, so the shortest form is the canonical one.

```php
use SocialDept\AtpSpaces\Scopes\SpaceAction;
use SocialDept\AtpSpaces\Scopes\SpaceScope;
use SocialDept\AtpSpaces\SpaceRef;

(string) SpaceScope::for('com.example.forum');
// space:com.example.forum

$space = SpaceRef::make('did:plc:alice', 'com.example.forum', 'main');

(string) SpaceScope::forSpace($space)->readOnly();
// space:com.example.forum?authority=did:plc:alice&skey=main&action=read

(string) SpaceScope::for('com.example.forum')
    ->collections(['com.example.thread'])
    ->actions([SpaceAction::Create, SpaceAction::Update]);
// space:com.example.forum?collection=com.example.thread&action=create&action=update
```

Defaults: `authority=self`, `skey=*`, no collections, the four actions other than `read_self`, and no management operations.

The builder also has `authority()`, which takes a DID, a handle, `self` or `*`, `skey()`, `readSelfOnly()` for read access to the user's own repo only, and `manage()`, which takes `SpaceManageOp::Create`, `Update` and `Delete` for the space lifecycle. Each returns a new scope.

`space:` scopes are granted verbatim, even when the authorization server's metadata does not list them. See [Gotchas](../gotchas.md).
