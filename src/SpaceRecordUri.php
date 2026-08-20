<?php

namespace SocialDept\AtpSpaces;

use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSpaces\Support\Syntax;
use Stringable;

/**
 * A record living inside a space.
 *
 *     at://{authority}/space/{type}/{skey}/{author}/{collection}/{rkey}
 *
 * The author segment matters: records are not colocated on the authority's
 * host. Each member holds their own per-space repo on their own PDS, and this
 * URI names which of those repos the record is in.
 */
class SpaceRecordUri implements Stringable
{
    public function __construct(
        public readonly SpaceRef $space,
        public readonly string $author,
        public readonly string $collection,
        public readonly string $rkey,
    ) {
    }

    /**
     * Build a record URI, validating the components the space does not cover.
     *
     * @throws InvalidSpaceRefException
     */
    public static function make(SpaceRef $space, string $author, string $collection, string $rkey): static
    {
        if (! Syntax::isDid($author)) {
            throw new InvalidSpaceRefException("Record author must be a DID, got \"{$author}\".");
        }

        if (! Syntax::isNsid($collection)) {
            throw new InvalidSpaceRefException("Record collection must be an NSID, got \"{$collection}\".");
        }

        if (! Syntax::isRecordKey($rkey)) {
            throw new InvalidSpaceRefException("Record key must follow record-key syntax, got \"{$rkey}\".");
        }

        return new static($space, $author, $collection, $rkey);
    }

    /**
     * Parse a space record URI, returning null when the string is not one.
     */
    public static function tryParse(string $uri): ?static
    {
        $segments = SpaceRef::segments($uri);

        if ($segments === null || count($segments) !== 7) {
            return null;
        }

        [$authority, $marker, $type, $skey, $author, $collection, $rkey] = $segments;

        if ($marker !== SpaceRef::SEGMENT) {
            return null;
        }

        $space = SpaceRef::tryParse('at://'.$authority.'/'.$marker.'/'.$type.'/'.$skey);

        if ($space === null) {
            return null;
        }

        if (! Syntax::isDid($author) || ! Syntax::isNsid($collection) || ! Syntax::isRecordKey($rkey)) {
            return null;
        }

        return new static($space, $author, $collection, $rkey);
    }

    /**
     * Parse a space record URI or throw.
     *
     * @throws InvalidSpaceRefException
     */
    public static function parse(string $uri): static
    {
        return static::tryParse($uri)
            ?? throw new InvalidSpaceRefException("Not a valid space record URI: \"{$uri}\".");
    }

    /**
     * The `{collection}/{rkey}` path used as a repo index key.
     */
    public function path(): string
    {
        return $this->collection.'/'.$this->rkey;
    }

    public function __toString(): string
    {
        return (string) $this->space.'/'.$this->author.'/'.$this->collection.'/'.$this->rkey;
    }
}
