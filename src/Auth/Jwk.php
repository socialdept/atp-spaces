<?php

namespace SocialDept\AtpSpaces\Auth;

use InvalidArgumentException;

/**
 * JWK helpers for the DPoP binding on a space credential.
 *
 * The authority copies the thumbprint of the key that signed a DPoP proof into
 * the credential's `cnf.jkt`, and every repo host checks the two agree. That
 * makes the thumbprint a wire value, not an internal id, so it must be RFC 7638
 * exactly: required members only, lexicographic keys, no whitespace.
 */
class Jwk
{
    /**
     * The members RFC 7638 includes for each key type, in lexicographic order.
     *
     * @var array<string, array<int, string>>
     */
    protected const REQUIRED_MEMBERS = [
        'EC' => ['crv', 'kty', 'x', 'y'],
        'RSA' => ['e', 'kty', 'n'],
        'OKP' => ['crv', 'kty', 'x'],
        'oct' => ['k', 'kty'],
    ];

    /**
     * The RFC 7638 thumbprint of a JWK, base64url-encoded.
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function thumbprint(array $jwk): string
    {
        return self::base64UrlEncode(hash('sha256', self::canonicalize($jwk), true));
    }

    /**
     * The canonical JSON RFC 7638 hashes.
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function canonicalize(array $jwk): string
    {
        $kty = $jwk['kty'] ?? null;

        if (! is_string($kty) || ! isset(self::REQUIRED_MEMBERS[$kty])) {
            throw new InvalidArgumentException('Cannot thumbprint a JWK with an unsupported "kty".');
        }

        $canonical = [];

        foreach (self::REQUIRED_MEMBERS[$kty] as $member) {
            if (! isset($jwk[$member]) || ! is_string($jwk[$member])) {
                throw new InvalidArgumentException("JWK is missing the required member \"{$member}\".");
            }

            $canonical[$member] = $jwk[$member];
        }

        return (string) json_encode($canonical, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Strip a JWK down to the members a DPoP proof header should carry.
     *
     * @param  array<string, mixed>  $jwk
     * @return array<string, mixed>
     */
    public static function bare(array $jwk): array
    {
        $kty = $jwk['kty'] ?? null;

        if (! is_string($kty) || ! isset(self::REQUIRED_MEMBERS[$kty])) {
            throw new InvalidArgumentException('Cannot reduce a JWK with an unsupported "kty".');
        }

        $bare = [];

        foreach (self::REQUIRED_MEMBERS[$kty] as $member) {
            if (isset($jwk[$member])) {
                $bare[$member] = $jwk[$member];
            }
        }

        return $bare;
    }

    /**
     * Base64url without padding, as every JOSE value uses.
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode base64url, tolerating missing padding.
     */
    public static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode($padded, true);
    }
}
