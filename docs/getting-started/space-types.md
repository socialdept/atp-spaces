# Space types

Part of the [atp-spaces docs](../introduction.md#documentation). Registering the kinds of space your application knows about.

A space type names a kind of space and lists the collections clients should expect in it. This package ships none, because a space type belongs to whoever defines it. Register yours in `config/atp-spaces.php`:

```php
'types' => [
    'com.example.forum' => [
        'key' => 'any',
        'name' => 'Example Forum',
        'collections' => ['com.example.thread', 'com.example.reply'],
    ],
],
```

Each entry is read like a lexicon `"type": "space"` definition, so it may also carry a `description` and localized names as `name:es` and so on. Look a type up through the registry:

```php
use SocialDept\AtpSpaces\SpaceTypeRegistry;

$type = app(SpaceTypeRegistry::class)->get('com.example.forum');

$type->nameFor('es');                  // name for an OAuth consent screen
$type->collections;                    // what clients should expect
$type->space('did:plc:alice', 'main'); // a SpaceRef
```

`get()` throws an `UnknownSpaceTypeException` for a type that is not registered, and `find()` returns `null`. `nameFor()` falls back to `name` when there is no name for the language. The registry is also reachable as `Spaces::types()`, and a type can be registered from code with `register(SpaceType::make(...))`.

The collection list is a recommendation, not a constraint. A space accepts records outside it. It exists so consent screens can describe the access being requested.
