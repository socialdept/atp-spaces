<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;
use SocialDept\AtpSpaces\Repo\Star;
use SocialDept\AtpSpaces\Support\Drisl\Bytes;
use SocialDept\AtpSpaces\Support\Drisl\Drisl;

class StarTest extends TestCase
{
    public static function varint(int $value): string
    {
        $out = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);

        return $out;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    public static function archive(mixed $meta, array $pairs = []): string
    {
        $metaBytes = is_string($meta) ? $meta : Drisl::encode($meta);
        $out = Star::MAGIC.self::varint(strlen($metaBytes)).$metaBytes;

        foreach ($pairs as [$key, $value]) {
            $out .= self::varint(strlen($key)).$key.self::varint(strlen($value)).$value;
        }

        return $out;
    }

    protected function meta(): array
    {
        return ['$type' => Star::REPO_TYPE, 'ver' => 1, 'repo' => 'did:plc:member', 'space' => 'at://did:plc:a/space/com.example.forum/main', 'rev' => '3lkq2xhbc2s2j', 'data' => new Bytes(str_repeat("\x01", 32))];
    }

    public function test_it_reads_metadata_and_pairs(): void
    {
        $record = Drisl::encode(['text' => 'hi']);
        $read = Star::read(self::archive($this->meta(), [['com.example.thread/1', $record], ['com.example.thread/2', $record]]));

        $this->assertSame(Star::REPO_TYPE, $read['meta']->{'$type'});
        $this->assertSame(str_repeat("\x01", 32), $read['meta']->data->value);
        $this->assertSame([['com.example.thread/1', $record], ['com.example.thread/2', $record]], $read['pairs']);
    }

    public function test_an_empty_repo_has_no_pairs(): void
    {
        $this->assertSame([], Star::read(self::archive($this->meta()))['pairs']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenProvider(): array
    {
        $meta = Drisl::encode(['$type' => Star::REPO_TYPE]);

        return [
            'wrong magic' => ["\x2A\x6C\x02\x00".self::varint(strlen($meta)).$meta, 'wrong magic'],
            'no metadata' => [Star::MAGIC, 'no metadata'],
            'truncated' => [substr(self::archive($meta, [['a/b', 'value']]), 0, -2), 'truncated'],
            'a key with no value' => [self::archive($meta).self::varint(3).'a/b', 'ends after a key'],
            'non-canonical varint' => [Star::MAGIC."\x8b\x00".$meta, 'non-canonical varint'],
            'unsorted metadata keys' => [self::archive("\xa2\x65\$type\x62hi\x61a\x01"), 'not canonical'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenProvider')]
    public function test_a_broken_archive_is_refused(string $archive, string $message): void
    {
        $this->expectException(RepoVerificationException::class);
        $this->expectExceptionMessage($message);

        Star::read($archive);
    }

    public function test_a_record_over_the_limit_is_refused_before_it_is_read(): void
    {
        $this->expectException(RepoVerificationException::class);
        $this->expectExceptionMessage('over the limit');

        Star::read(Star::MAGIC.self::varint(strlen($m = Drisl::encode(['$type' => 'x']))).$m.self::varint(3).'a/b'.self::varint(Star::MAX_RECORD_BYTES + 1));
    }
}
