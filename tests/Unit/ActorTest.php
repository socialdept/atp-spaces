<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSpaces\Support\Actor;
use SocialDept\AtpSpaces\Tests\TestCase;
use SocialDept\AtpSupport\Contracts\HandleResolver;

class ActorTest extends TestCase
{
    /**
     * A DID is already what the protocol wants, so it must not cost a lookup.
     */
    public function test_a_did_passes_through_without_resolving(): void
    {
        $this->app->bind(HandleResolver::class, function () {
            $this->fail('a DID should never be resolved');
        });

        $this->assertSame('did:plc:abc', Actor::did('did:plc:abc'));
        $this->assertSame('did:web:example.com', Actor::did('did:web:example.com'));
    }

    public function test_a_handle_is_resolved(): void
    {
        $this->fakeHandleResolver(['alice.example.com' => 'did:plc:alice']);

        $this->assertSame('did:plc:alice', Actor::did('alice.example.com'));
    }

    public function test_it_resolves_several_actors(): void
    {
        $this->fakeHandleResolver(['alice.example.com' => 'did:plc:alice']);

        $this->assertSame(
            ['did:plc:bob', 'did:plc:alice'],
            Actor::dids(['did:plc:bob', 'alice.example.com']),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty' => [''],
            'not an identifier' => ['not a handle'],
            'an at-uri' => ['at://did:plc:abc/app.bsky.feed.post/3k'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProvider')]
    public function test_it_rejects_things_that_are_neither(string $value): void
    {
        $this->expectException(InvalidSpaceRefException::class);
        $this->expectExceptionMessage('Expected a DID or handle');

        Actor::did($value);
    }

    public function test_an_unresolvable_handle_fails_clearly(): void
    {
        $this->fakeHandleResolver([]);

        $this->expectException(InvalidSpaceRefException::class);
        $this->expectExceptionMessage('Could not resolve handle "ghost.example.com"');

        Actor::did('ghost.example.com');
    }

    /**
     * The rule applied at the edges: anywhere an actor is named, a handle works.
     */
    public function test_handles_work_wherever_an_actor_is_named(): void
    {
        $this->fakeHandleResolver([
            'alice.example.com' => 'did:plc:alice',
            'bob.example.com' => 'did:plc:bob',
            'app.example.com' => 'did:web:app.example.com',
        ]);

        $space = \SocialDept\AtpSpaces\SpaceRef::make('alice.example.com', 'com.example.forum', 'main');
        $this->assertSame('did:plc:alice', $space->authority);

        $record = $space->record('bob.example.com', 'com.example.thread', '3abc');
        $this->assertSame('did:plc:bob', $record->author);

        $type = \SocialDept\AtpSpaces\SpaceType::make('com.example.forum');
        $this->assertSame('did:plc:alice', $type->space('alice.example.com', 'main')->authority);

        $policy = \SocialDept\AtpSpaces\Management\SpacePolicy::managingApp('app.example.com#forum');
        $this->assertSame('did:web:app.example.com#forum', $policy->managingAppId());

        $scope = \SocialDept\AtpSpaces\Scopes\SpaceScope::for('com.example.forum')->authority('alice.example.com');
        $this->assertStringContainsString('authority=did:plc:alice', (string) $scope);
    }

    /**
     * The wildcards a scope understands are not actors and must survive.
     */
    public function test_scope_wildcards_are_not_resolved(): void
    {
        $scope = \SocialDept\AtpSpaces\Scopes\SpaceScope::for('com.example.forum');

        $this->assertSame('space:com.example.forum', (string) $scope->authority('self'));
        $this->assertStringContainsString('authority=*', (string) $scope->authority('*'));
    }

    /**
     * @param  array<string, string>  $handles
     */
    protected function fakeHandleResolver(array $handles): void
    {
        $this->app->bind(HandleResolver::class, fn () => new class ($handles) implements HandleResolver {
            /** @param array<string, string> $handles */
            public function __construct(protected array $handles)
            {
            }

            public function resolve(string $handle): string
            {
                return $this->handles[$handle]
                    ?? throw new \RuntimeException("no such handle: {$handle}");
            }
        });
    }
}
