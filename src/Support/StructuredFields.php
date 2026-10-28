<?php

namespace SocialDept\AtpSpaces\Support;

use SocialDept\AtpSpaces\Support\Drisl\Bytes;

/**
 * Just enough of RFC 9651 Structured Field Values to read the dictionaries
 * spaces put in headers: `type="atproto-space-repo", ver=1, mac=:…:`.
 *
 * Members are bare items: strings, integers, byte sequences (as
 * {@see Bytes}), booleans and tokens. Parameters are read past and dropped,
 * and inner lists refuse the whole field.
 */
final class StructuredFields
{
    /**
     * @return array<string, mixed>|null Null when the field does not parse
     */
    public static function parseDictionary(string $field): ?array
    {
        $input = trim($field, " \t");
        $offset = 0;
        $members = [];

        while ($offset < strlen($input)) {
            if (! preg_match('/\G[a-z*][a-z0-9_\-.*]*/A', $input, $match, 0, $offset)) {
                return null;
            }

            $key = $match[0];
            $offset += strlen($key);

            if (($input[$offset] ?? '') === '=') {
                $offset++;
                $value = self::item($input, $offset);

                if ($value === null) {
                    return null;
                }
            } else {
                $value = true;
            }

            // Parameters: ;name or ;name=value, dropped.
            while (($input[$offset] ?? '') === ';') {
                $offset++;

                if (! preg_match('/\G[a-z*][a-z0-9_\-.*]*/A', $input, $param, 0, $offset)) {
                    return null;
                }

                $offset += strlen($param[0]);

                if (($input[$offset] ?? '') === '=') {
                    $offset++;

                    if (self::item($input, $offset) === null) {
                        return null;
                    }
                }
            }

            $members[$key] = $value;

            $offset += strspn($input, " \t", $offset);

            if ($offset >= strlen($input)) {
                break;
            }

            if ($input[$offset] !== ',') {
                return null;
            }

            $offset++;
            $offset += strspn($input, " \t", $offset);

            if ($offset >= strlen($input)) {
                return null;
            }
        }

        return $members;
    }

    protected static function item(string $input, int &$offset): mixed
    {
        $char = $input[$offset] ?? '';

        if ($char === '"') {
            $value = '';
            $offset++;

            while ($offset < strlen($input)) {
                $c = $input[$offset++];

                if ($c === '\\') {
                    $next = $input[$offset++] ?? '';

                    if ($next !== '"' && $next !== '\\') {
                        return null;
                    }

                    $value .= $next;
                } elseif ($c === '"') {
                    return $value;
                } else {
                    $value .= $c;
                }
            }

            return null;
        }

        if ($char === ':') {
            $end = strpos($input, ':', $offset + 1);

            if ($end === false) {
                return null;
            }

            // Padding is required on the wire, but tolerated when missing.
            $encoded = substr($input, $offset + 1, $end - $offset - 1);
            $decoded = base64_decode(str_pad($encoded, intdiv(strlen($encoded) + 3, 4) * 4, '='), true);
            $offset = $end + 1;

            return $decoded === false ? null : new Bytes($decoded);
        }

        if (preg_match('/\G-?[0-9]{1,15}(?![.0-9])/A', $input, $match, 0, $offset)) {
            $offset += strlen($match[0]);

            return (int) $match[0];
        }

        if ($char === '?' && in_array($input[$offset + 1] ?? '', ['0', '1'], true)) {
            $offset += 2;

            return $input[$offset - 1] === '1';
        }

        if (preg_match('/\G[A-Za-z*][!#$%&\'*+\-.^_`|~:\/0-9A-Za-z]*/A', $input, $match, 0, $offset)) {
            $offset += strlen($match[0]);

            return $match[0];
        }

        return null;
    }
}
