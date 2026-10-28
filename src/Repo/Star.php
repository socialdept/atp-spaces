<?php

namespace SocialDept\AtpSpaces\Repo;

use InvalidArgumentException;
use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;
use SocialDept\AtpSpaces\Support\Drisl\Drisl;
use stdClass;

/**
 * Reads STAR-lite v1 archives, the form `getRepo` returns a space repo in:
 * magic bytes, then length-prefixed metadata, then length-prefixed key and
 * value pairs until the end.
 *
 *     [ 2A 6C 01 00 | len | metadata ] [ len | key | len | value ] ...
 *
 * Every length is an unsigned varint in its shortest form. For a space repo
 * the metadata is the commit plus `$type: "atproto-space-repo"`, each key a
 * record path `{collection}/{rkey}` in strict byte order, and each value that
 * record's DRISL encoding, at most 1 MiB.
 *
 * @see https://tangled.org/microcosm.blue/star/tree/main/v1-star
 */
final class Star
{
    public const MAGIC = "\x2A\x6C\x01\x00";

    public const REPO_TYPE = 'atproto-space-repo';

    public const MAX_RECORD_BYTES = 1048576;

    protected const MAX_VARINT_BYTES = 6;

    /**
     * Split an archive into its metadata and pairs.
     *
     * @return array{meta: stdClass, pairs: list<array{0: string, 1: string}>}
     *
     * @throws RepoVerificationException when the archive does not parse
     */
    public static function read(string $archive, int $maxValueBytes = self::MAX_RECORD_BYTES): array
    {
        if (! str_starts_with($archive, self::MAGIC)) {
            throw new RepoVerificationException('Not a STAR archive: wrong magic bytes.');
        }

        $offset = strlen(self::MAGIC);
        $metaBytes = self::frame($archive, $offset, PHP_INT_MAX) ?? throw new RepoVerificationException('The STAR archive has no metadata.');

        try {
            $meta = Drisl::decode($metaBytes);
        } catch (InvalidArgumentException $e) {
            throw new RepoVerificationException('The STAR metadata is not DRISL: '.$e->getMessage(), 0, $e);
        }

        if (! $meta instanceof stdClass || ! is_string($meta->{'$type'} ?? null)) {
            throw new RepoVerificationException('The STAR metadata must be a map with a $type.');
        }

        if (Drisl::encode($meta) !== $metaBytes) {
            throw new RepoVerificationException('The STAR metadata is not canonical DRISL.');
        }

        $pairs = [];

        // The end of the archive is allowed only between pairs.
        while (($key = self::frame($archive, $offset, PHP_INT_MAX)) !== null) {
            $value = self::frame($archive, $offset, $maxValueBytes) ?? throw new RepoVerificationException('The STAR archive ends after a key.');
            $pairs[] = [$key, $value];
        }

        return ['meta' => $meta, 'pairs' => $pairs];
    }

    /**
     * One length-prefixed item, or null at the end of the archive.
     */
    protected static function frame(string $archive, int &$offset, int $maxLength): ?string
    {
        if ($offset === strlen($archive)) {
            return null;
        }

        $length = self::varint($archive, $offset);

        if ($length > $maxLength) {
            throw new RepoVerificationException("A STAR item of {$length} bytes is over the limit of {$maxLength}.");
        }

        if ($length > strlen($archive) - $offset) {
            throw new RepoVerificationException('The STAR archive is truncated.');
        }

        $item = substr($archive, $offset, $length);
        $offset += $length;

        return $item;
    }

    protected static function varint(string $archive, int &$offset): int
    {
        $value = 0;

        for ($i = 0; $i < self::MAX_VARINT_BYTES; $i++) {
            if ($offset >= strlen($archive)) {
                throw new RepoVerificationException('The STAR archive is truncated.');
            }

            $byte = ord($archive[$offset++]);
            $value += ($byte & 0x7F) * (2 ** (7 * $i));

            if ($byte < 0x80) {
                // A final zero byte only pads the encoding, which STAR forbids.
                if ($byte === 0 && $i > 0) {
                    throw new RepoVerificationException('The STAR archive has a non-canonical varint.');
                }

                return $value;
            }
        }

        throw new RepoVerificationException('The STAR archive has a varint that is too long.');
    }
}
