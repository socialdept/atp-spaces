<?php

namespace SocialDept\AtpSpaces\Support;

use InvalidArgumentException;
use SocialDept\AtpSupport\Identity;
use SocialDept\AtpSupport\Nsid;

/**
 * Non-throwing syntax predicates for the identifiers spaces are addressed by.
 *
 * `atp-support` owns DID and NSID syntax; this only adds the record-key rules a
 * space key follows, and wraps the throwing NSID parser in a boolean.
 */
class Syntax
{
    /**
     * A record key is 1-512 chars from a restricted set, and never "." or "..".
     *
     * @see https://atproto.com/specs/record-key
     */
    protected const RECORD_KEY_REGEX = '/^[a-zA-Z0-9._~:-]{1,512}$/';

    /**
     * Whether a value is a syntactically valid DID.
     */
    public static function isDid(?string $value): bool
    {
        return Identity::isDid($value);
    }

    /**
     * Whether a value is a syntactically valid NSID.
     */
    public static function isNsid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            Nsid::parse($value);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * Whether a value is a syntactically valid record key.
     *
     * A space key ("skey") shares these rules.
     */
    public static function isRecordKey(?string $value): bool
    {
        if ($value === null || $value === '.' || $value === '..') {
            return false;
        }

        return (bool) preg_match(self::RECORD_KEY_REGEX, $value);
    }
}
