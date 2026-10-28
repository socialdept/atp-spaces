<?php

namespace SocialDept\AtpSpaces\Support\Drisl;

use InvalidArgumentException;
use SocialDept\AtpSpaces\Support\Cid;
use stdClass;

/**
 * DRISL, the deterministic subset of CBOR atproto encodes records with: maps
 * with text keys in length-then-bytewise order, integers in their shortest
 * form, byte and text strings, arrays, booleans, null, and CIDs as tag 42. No
 * floats, no indefinite lengths, no other tags.
 *
 * Just enough for spaces: checking that a record's bytes match its CID, and
 * that a STAR archive's metadata is canonical. Types decode without loss, so
 * a value encodes back to the bytes it came from: maps become stdClass, byte
 * strings {@see Bytes}, links {@see Cid}.
 */
final class Drisl
{
    protected const TAG_CID = 42;

    /**
     * @throws InvalidArgumentException
     */
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => "\xf6",
            $value === false => "\xf4",
            $value === true => "\xf5",
            is_int($value) => $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value),
            is_string($value) => self::head(3, strlen($value)).$value,
            $value instanceof Bytes => self::head(2, strlen($value->value)).$value->value,
            $value instanceof Cid => self::head(6, self::TAG_CID).self::encode(new Bytes("\x00".$value->bytes)),
            is_array($value) && array_is_list($value) => self::head(4, count($value)).implode('', array_map(self::encode(...), $value)),
            is_array($value), $value instanceof stdClass => self::encodeMap((array) $value),
            is_float($value) => throw new InvalidArgumentException('DRISL has no floats.'),
            default => throw new InvalidArgumentException('Cannot encode a '.get_debug_type($value).' as DRISL.'),
        };
    }

    /**
     * Decode one complete value.
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $bytes): mixed
    {
        $offset = 0;
        $value = self::read($bytes, $offset);

        if ($offset !== strlen($bytes)) {
            throw new InvalidArgumentException('Trailing bytes after a DRISL value.');
        }

        return $value;
    }

    /**
     * Turn a record from its JSON form into DRISL values: objects stay maps,
     * `{"$link": …}` becomes a CID and `{"$bytes": …}` a byte string.
     *
     * @throws InvalidArgumentException
     */
    public static function fromJson(string $json): mixed
    {
        return self::fromJsonValue(json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
    }

    protected static function fromJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::fromJsonValue(...), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $fields = get_object_vars($value);

        if (count($fields) === 1 && is_string($fields['$link'] ?? null)) {
            return Cid::parse($fields['$link']);
        }

        if (count($fields) === 1 && is_string($fields['$bytes'] ?? null)) {
            $decoded = base64_decode(strtr($fields['$bytes'], '-_', '+/'), true);

            if ($decoded === false) {
                throw new InvalidArgumentException('A $bytes value is not base64.');
            }

            return new Bytes($decoded);
        }

        $map = new stdClass();

        foreach ($fields as $key => $field) {
            $map->{$key} = self::fromJsonValue($field);
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $map
     */
    protected static function encodeMap(array $map): string
    {
        $keys = array_map('strval', array_keys($map));
        usort($keys, fn (string $a, string $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        $out = self::head(5, count($keys));

        foreach ($keys as $key) {
            $out .= self::encode($key).self::encode($map[$key]);
        }

        return $out;
    }

    /**
     * A major type and its argument in the shortest form.
     */
    protected static function head(int $major, int $argument): string
    {
        $type = $major << 5;

        return match (true) {
            $argument < 24 => chr($type | $argument),
            $argument <= 0xFF => chr($type | 24).chr($argument),
            $argument <= 0xFFFF => chr($type | 25).pack('n', $argument),
            $argument <= 0xFFFFFFFF => chr($type | 26).pack('N', $argument),
            default => chr($type | 27).pack('J', $argument),
        };
    }

    protected static function read(string $bytes, int &$offset): mixed
    {
        [$major, $argument] = self::readHead($bytes, $offset);

        switch ($major) {
            case 0:
                return $argument;
            case 1:
                return -1 - $argument;
            case 2:
                return new Bytes(self::take($bytes, $offset, $argument));
            case 3:
                return self::take($bytes, $offset, $argument);
            case 4:
                $list = [];

                for ($i = 0; $i < $argument; $i++) {
                    $list[] = self::read($bytes, $offset);
                }

                return $list;
            case 5:
                $map = new stdClass();

                for ($i = 0; $i < $argument; $i++) {
                    $key = self::read($bytes, $offset);

                    if (! is_string($key)) {
                        throw new InvalidArgumentException('DRISL map keys must be text.');
                    }

                    $map->{$key} = self::read($bytes, $offset);
                }

                return $map;
            case 6:
                $content = self::read($bytes, $offset);

                if ($argument !== self::TAG_CID || ! $content instanceof Bytes || ! str_starts_with($content->value, "\x00")) {
                    throw new InvalidArgumentException('DRISL allows only CID links as tags.');
                }

                return Cid::fromBinary(substr($content->value, 1));
            default:
                return match ($argument) {
                    20 => false,
                    21 => true,
                    22 => null,
                    default => throw new InvalidArgumentException('DRISL has no floats or other simple values.'),
                };
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected static function readHead(string $bytes, int &$offset): array
    {
        $first = ord(self::take($bytes, $offset, 1));
        $major = $first >> 5;
        $info = $first & 0x1F;

        if ($major === 7) {
            return [$major, $info];
        }

        $argument = match (true) {
            $info < 24 => $info,
            $info === 24 => ord(self::take($bytes, $offset, 1)),
            $info === 25 => unpack('n', self::take($bytes, $offset, 2))[1],
            $info === 26 => unpack('N', self::take($bytes, $offset, 4))[1],
            $info === 27 => unpack('J', self::take($bytes, $offset, 8))[1],
            default => throw new InvalidArgumentException('DRISL has no indefinite lengths.'),
        };

        if ($argument < 0) {
            throw new InvalidArgumentException('A DRISL integer is out of range.');
        }

        return [$major, $argument];
    }

    protected static function take(string $bytes, int &$offset, int $length): string
    {
        if ($length > strlen($bytes) - $offset) {
            throw new InvalidArgumentException('Truncated DRISL value.');
        }

        $taken = substr($bytes, $offset, $length);
        $offset += $length;

        return $taken;
    }
}
