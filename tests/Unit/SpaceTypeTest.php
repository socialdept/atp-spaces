<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSpaces\Exceptions\UnknownSpaceTypeException;
use SocialDept\AtpSpaces\SpaceType;
use SocialDept\AtpSpaces\SpaceTypeRegistry;

class SpaceTypeTest extends TestCase
{
    public function test_it_declares_a_type(): void
    {
        $type = SpaceType::make(
            'com.example.forum',
            'any',
            'Example Forum',
            ['com.example.thread', 'com.example.reply'],
        );

        $this->assertSame('com.example.forum', $type->nsid);
        $this->assertSame('Example Forum', $type->name);
        $this->assertTrue($type->declares('com.example.thread'));
        $this->assertFalse($type->declares('com.example.other'));
        $this->assertSame('com.example.forum', (string) $type);
    }

    public function test_it_rejects_a_non_nsid(): void
    {
        $this->expectException(InvalidSpaceRefException::class);

        SpaceType::make('forum');
    }

    public function test_it_rejects_a_non_nsid_collection(): void
    {
        $this->expectException(InvalidSpaceRefException::class);

        SpaceType::make('com.example.forum', collections: ['thread']);
    }

    public function test_it_reads_a_lexicon_definition(): void
    {
        $type = SpaceType::fromLexicon('com.atmoboards.forum', [
            'type' => 'space',
            'key' => 'any',
            'name' => 'AtmoBoards Forum',
            'name:es' => 'Foro AtmoBoards',
            'description' => 'A forum.',
            'collections' => ['com.atmoboards.thread'],
        ]);

        $this->assertSame('AtmoBoards Forum', $type->name);
        $this->assertSame('Foro AtmoBoards', $type->nameFor('es'));
        $this->assertSame('AtmoBoards Forum', $type->nameFor('fr'));
        $this->assertSame('A forum.', $type->description);
        $this->assertSame(['com.atmoboards.thread'], $type->collections);
    }

    public function test_a_lexicon_round_trips(): void
    {
        $definition = [
            'type' => 'space',
            'key' => 'tid',
            'name' => 'Example Forum',
            'name:es' => 'Foro',
            'description' => 'Desc.',
            'collections' => ['com.example.thread'],
        ];

        $this->assertSame(
            $definition,
            SpaceType::fromLexicon('com.example.forum', $definition)->toLexicon(),
        );
    }

    public function test_it_addresses_a_space(): void
    {
        $space = SpaceType::make('com.example.forum')->space('did:plc:abc', 'main');

        $this->assertSame('at://did:plc:abc/space/com.example.forum/main', (string) $space);
    }

    public function test_the_registry_is_empty_by_default(): void
    {
        $this->assertSame([], (new SpaceTypeRegistry())->all());
    }

    public function test_the_registry_stores_and_finds_types(): void
    {
        $type = SpaceType::make('com.example.forum', collections: ['com.example.thread']);
        $registry = new SpaceTypeRegistry([$type]);

        $this->assertTrue($registry->has('com.example.forum'));
        $this->assertSame($type, $registry->get('com.example.forum'));
        $this->assertSame($type, $registry->find('com.example.forum'));
        $this->assertNull($registry->find('com.example.missing'));
        $this->assertSame(['com.example.thread'], $registry->collectionsFor('com.example.forum'));
        $this->assertSame([], $registry->collectionsFor('com.example.missing'));
    }

    public function test_the_registry_throws_for_an_unknown_type(): void
    {
        $this->expectException(UnknownSpaceTypeException::class);

        (new SpaceTypeRegistry())->get('com.example.missing');
    }

    public function test_registering_the_same_nsid_replaces_it(): void
    {
        $registry = new SpaceTypeRegistry([SpaceType::make('com.example.forum', name: 'First')]);
        $registry->register(SpaceType::make('com.example.forum', name: 'Second'));

        $this->assertCount(1, $registry->all());
        $this->assertSame('Second', $registry->get('com.example.forum')->name);
    }

    public function test_it_resolves_the_type_for_a_space(): void
    {
        $type = SpaceType::make('com.example.forum');
        $registry = new SpaceTypeRegistry([$type]);

        $this->assertSame($type, $registry->for($type->space('did:plc:abc', 'main')));
    }

    public function test_it_can_be_flushed(): void
    {
        $registry = new SpaceTypeRegistry([SpaceType::make('com.example.forum')]);

        $this->assertSame([], $registry->flush()->all());
    }
}
