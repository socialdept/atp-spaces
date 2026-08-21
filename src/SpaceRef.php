<?php

namespace SocialDept\AtpSpaces;

use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSpaces\Support\Actor;
use SocialDept\AtpSpaces\Support\Syntax;
use Stringable;

/**
 * A reference to a space: the (authority, type, skey) triple that names an
 * authorization and sync boundary.
 *
 *     at://{authority}/space/{type}/{skey}
 *
 * The authority is the DID that decides who may enter. The type is an NSID
 * naming the space's modality. The skey distinguishes several spaces of the
 * same type under one authority, and follows record-key syntax.
 */
class SpaceRef implements Stringable
{
    /**
     * The literal segment that distinguishes a space URI from a record AT-URI.
     */
    public const SEGMENT = 'space';

    public function __construct(
        public readonly string $authority,
        public readonly string $type,
        public readonly string $skey,
    ) {
    }

    /**
     * Build a reference, validating each component.
     *
     * @param  string  $authority  A DID or a handle
     *
     * @throws InvalidSpaceRefException
     */
    public static function make(string $authority, string $type, string $skey): static
    {
        $authority = Actor::did($authority);

        if (! Syntax::isNsid($type)) {
            throw new InvalidSpaceRefException("Space type must be an NSID, got \"{$type}\".");
        }

        if (! Syntax::isRecordKey($skey)) {
            throw new InvalidSpaceRefException("Space key must follow record-key syntax, got \"{$skey}\".");
        }

        return new static($authority, $type, $skey);
    }

    /**
     * Parse a space reference, returning null when the string is not one.
     *
     * A space record URI is not a space reference; use {@see SpaceRecordUri} for
     * those, or {@see SpaceRecordUri::parse()} then read its `space` property.
     */
    public static function tryParse(string $uri): ?static
    {
        $segments = self::segments($uri);

        if ($segments === null || count($segments) !== 4) {
            return null;
        }

        [$authority, $marker, $type, $skey] = $segments;

        if ($marker !== self::SEGMENT) {
            return null;
        }

        if (! Syntax::isDid($authority) || ! Syntax::isNsid($type) || ! Syntax::isRecordKey($skey)) {
            return null;
        }

        return new static($authority, $type, $skey);
    }

    /**
     * Parse a space reference or throw.
     *
     * @throws InvalidSpaceRefException
     */
    public static function parse(string $uri): static
    {
        return static::tryParse($uri)
            ?? throw new InvalidSpaceRefException("Not a valid space reference: \"{$uri}\".");
    }

    /**
     * Split an at:// URI into its path segments, or null if it is not one.
     *
     * @return array<int, string>|null
     */
    public static function segments(string $uri): ?array
    {
        if (! str_starts_with($uri, 'at://')) {
            return null;
        }

        $path = substr($uri, strlen('at://'));

        if ($path === '' || str_contains($path, '//')) {
            return null;
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '') {
                return null;
            }
        }

        return $segments;
    }

    /**
     * Whether this reference points at the same space as another.
     */
    public function equals(self $other): bool
    {
        return $this->authority === $other->authority
            && $this->type === $other->type
            && $this->skey === $other->skey;
    }

    /**
     * Address a record inside this space.
     *
     * @param  string  $author  A DID or a handle
     */
    public function record(string $author, string $collection, string $rkey): SpaceRecordUri
    {
        return SpaceRecordUri::make($this, $author, $collection, $rkey);
    }

    /**
     * A stable cache-key fragment for this space.
     */
    public function cacheKey(): string
    {
        return hash('sha256', (string) $this);
    }

    public function __toString(): string
    {
        return 'at://'.$this->authority.'/'.self::SEGMENT.'/'.$this->type.'/'.$this->skey;
    }
}
