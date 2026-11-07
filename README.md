<h1 align="center">ATP Spaces</h1>

<h3 align="center">
    Read and write permissioned AT Protocol data in Laravel.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/atp-spaces" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/atp-spaces.svg?style=flat-square"></a>
    <a href="https://packagist.org/packages/socialdept/atp-spaces" title="Total Downloads"><img src="https://img.shields.io/packagist/dt/socialdept/atp-spaces.svg?style=flat-square"></a>
    <a href="https://github.com/socialdept/atp-spaces/actions/workflows/tests.yml" title="GitHub Tests Action Status"><img src="https://img.shields.io/github/actions/workflow/status/socialdept/atp-spaces/tests.yml?branch=main&label=tests&style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/atp-spaces?style=flat-square"></a>
</p>

---

> [!WARNING]
> **Don't use this in production.** Spaces are an alpha AT Protocol extension. The lexicons this
> package is built against are still moving, and so is this package. It tracks the October 22,
> 2026 alpha release. Only alpha PDS software serves these endpoints today. Pin a version, expect
> breaking changes, and treat anything you store as throwaway.

This package works with atproto spaces, AT Protocol's permissioned data: records only certain people and applications can read. It creates and manages spaces, writes to your own repo inside them, reads every member's repo with one space credential, and checks what it reads against each member's host. To learn all about it, head over to [the documentation](https://socialde.pt/docs/atp-spaces).

Here are a few short examples of what you can do. Create a space you own and admit someone:

```php
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSpaces\Management\SpacePolicy;

$client = Atp::as('alice.example.com');

$space = $client->space->manage->createSpace('com.example.forum', SpacePolicy::memberList());
$client->space->manage->putMember($space, 'bob.example.com');
```

Write to your own repo inside it:

```php
$client->space->repo->createRecord($space, 'com.example.thread', [
    '$type' => 'com.example.thread',
    'title' => 'Hello, members',
]);
```

Read someone else's repo inside it:

```php
$reader = $client->space->read($space);

$reader->listRepos();
$reader->listRecords('bob.example.com', 'com.example.thread');
```

Ask for access to a space with its own OAuth scope:

```php
use SocialDept\AtpSpaces\Scopes\SpaceScope;
use SocialDept\AtpSpaces\SpaceRef;

$space = SpaceRef::make('did:plc:alice', 'com.example.forum', 'main');

(string) SpaceScope::forSpace($space)->readOnly();
// space:com.example.forum?authority=did:plc:alice&skey=main&action=read
```

It builds on [atp-client](https://github.com/socialdept/atp-client) for OAuth sessions and service auth, and on [atp-support](https://github.com/socialdept/atp-support) for identity resolution.

## Documentation

You'll find the documentation on [https://socialde.pt/docs/atp-spaces](https://socialde.pt/docs/atp-spaces).

Find yourself stuck using the package? Found a bug? Do you have general questions or suggestions for improving it? Feel free to [create an issue on GitHub](https://github.com/socialdept/atp-spaces/issues).

## Testing

```bash
vendor/bin/phpunit --exclude-group integration
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
