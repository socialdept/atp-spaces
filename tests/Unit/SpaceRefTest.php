<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSpaces\SpaceRecordUri;
use SocialDept\AtpSpaces\SpaceRef;

class SpaceRefTest extends TestCase
{
    protected const URI = 'at://did:plc:3znwyhr2wogfy4nyhy4daii4/space/com.example.forum/main';

    public function test_it_parses_a_space_reference(): void
    {
        $ref = SpaceRef::parse(self::URI);

        $this->assertSame('did:plc:3znwyhr2wogfy4nyhy4daii4', $ref->authority);
        $this->assertSame('com.example.forum', $ref->type);
        $this->assertSame('main', $ref->skey);
    }

    public function test_it_round_trips(): void
    {
        $this->assertSame(self::URI, (string) SpaceRef::parse(self::URI));
    }

    public function test_it_builds_from_components(): void
    {
        $ref = SpaceRef::make('did:plc:abc', 'com.example.forum', 'main');

        $this->assertSame('at://did:plc:abc/space/com.example.forum/main', (string) $ref);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'a record at-uri' => ['at://did:plc:abc/app.bsky.feed.post/3lkq'],
            'missing the space marker' => ['at://did:plc:abc/notspace/com.example.forum/main'],
            'a handle authority' => ['at://alice.bsky.social/space/com.example.forum/main'],
            'a non-nsid type' => ['at://did:plc:abc/space/forum/main'],
            'no skey' => ['at://did:plc:abc/space/com.example.forum'],
            'a record uri' => ['at://did:plc:abc/space/com.example.forum/main/did:plc:x/com.example.thread/1'],
            'not an at-uri' => ['https://example.com/space/com.example.forum/main'],
            'empty segments' => ['at://did:plc:abc//com.example.forum/main'],
            'a dot skey' => ['at://did:plc:abc/space/com.example.forum/.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProvider')]
    public function test_it_rejects_invalid_references(string $uri): void
    {
        $this->assertNull(SpaceRef::tryParse($uri));
    }

    public function test_parse_throws_on_an_invalid_reference(): void
    {
        $this->expectException(InvalidSpaceRefException::class);

        SpaceRef::parse('at://did:plc:abc/app.bsky.feed.post/3lkq');
    }

    public function test_make_rejects_a_handle_authority(): void
    {
        $this->expectException(InvalidSpaceRefException::class);

        SpaceRef::make('alice.bsky.social', 'com.example.forum', 'main');
    }

    public function test_equality_compares_all_three_components(): void
    {
        $a = SpaceRef::make('did:plc:abc', 'com.example.forum', 'main');

        $this->assertTrue($a->equals(SpaceRef::make('did:plc:abc', 'com.example.forum', 'main')));
        $this->assertFalse($a->equals(SpaceRef::make('did:plc:abc', 'com.example.forum', 'other')));
        $this->assertFalse($a->equals(SpaceRef::make('did:plc:xyz', 'com.example.forum', 'main')));
    }

    public function test_a_cache_key_is_stable_and_distinct(): void
    {
        $a = SpaceRef::make('did:plc:abc', 'com.example.forum', 'main');
        $b = SpaceRef::make('did:plc:abc', 'com.example.forum', 'other');

        $this->assertSame($a->cacheKey(), SpaceRef::parse((string) $a)->cacheKey());
        $this->assertNotSame($a->cacheKey(), $b->cacheKey());
    }

    public function test_it_addresses_records_inside_the_space(): void
    {
        $record = SpaceRef::parse(self::URI)->record('did:plc:author', 'com.example.thread', '3abc');

        $this->assertSame(self::URI.'/did:plc:author/com.example.thread/3abc', (string) $record);
        $this->assertSame('com.example.thread/3abc', $record->path());
    }

    public function test_it_parses_a_record_uri(): void
    {
        $uri = self::URI.'/did:plc:author/com.example.thread/3abc';
        $record = SpaceRecordUri::parse($uri);

        $this->assertSame('did:plc:author', $record->author);
        $this->assertSame('com.example.thread', $record->collection);
        $this->assertSame('3abc', $record->rkey);
        $this->assertSame(self::URI, (string) $record->space);
        $this->assertSame($uri, (string) $record);
    }

    public function test_a_space_reference_is_not_a_record_uri(): void
    {
        $this->assertNull(SpaceRecordUri::tryParse(self::URI));
    }

    public function test_a_record_uri_rejects_a_handle_author(): void
    {
        $this->assertNull(SpaceRecordUri::tryParse(
            self::URI.'/alice.bsky.social/com.example.thread/3abc'
        ));
    }
}
