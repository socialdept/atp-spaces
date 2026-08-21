<?php

namespace SocialDept\AtpSpaces\Support;

use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * Turns whatever a caller passed into the DID the protocol wants.
 *
 * Space lexicons take DIDs, never handles. Making callers resolve first would
 * be tedious and inconsistent with the rest of these packages, where
 * `Atp::as()` and `Post::for()` both accept either. So a DID passes straight
 * through at no cost, and a handle is resolved.
 */
class Actor
{
    /**
     * Resolve an actor to a DID.
     *
     * @param  string  $actor  A DID or a handle
     *
     * @throws InvalidSpaceRefException
     */
    public static function did(string $actor): string
    {
        if (Syntax::isDid($actor)) {
            return $actor;
        }

        if (! Syntax::isHandle($actor)) {
            throw new InvalidSpaceRefException("Expected a DID or handle, got \"{$actor}\".");
        }

        try {
            $did = Resolver::handleToDid($actor);
        } catch (Throwable $e) {
            throw new InvalidSpaceRefException("Could not resolve handle \"{$actor}\": ".$e->getMessage(), 0, $e);
        }

        if (! is_string($did) || ! Syntax::isDid($did)) {
            throw new InvalidSpaceRefException("Handle \"{$actor}\" did not resolve to a DID.");
        }

        return $did;
    }

    /**
     * Resolve several actors at once.
     *
     * @param  array<int, string>  $actors
     * @return array<int, string>
     */
    public static function dids(array $actors): array
    {
        return array_map(self::did(...), array_values($actors));
    }
}
