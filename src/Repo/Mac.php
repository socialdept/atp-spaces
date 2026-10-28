<?php

namespace SocialDept\AtpSpaces\Repo;

use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;
use SocialDept\AtpSpaces\Support\Cid;
use SocialDept\AtpSpaces\Support\Drisl\Bytes;
use SocialDept\AtpSpaces\Support\StructuredFields;

/**
 * The MACs a repo host authenticates commits and records with.
 *
 * A reader asks for one with `Atproto-Space-Want-Mac`, sending its key share,
 * and the host answers in `Atproto-Space-Mac`, keyed by the secret they now
 * share. Commits (getLatestCommit, getRepo, listRepoOps) and records
 * (getRecord) each have their own MAC type.
 *
 *     commit: HMAC(secret, "atproto-space-repo-v1" || len space || len repo || len rev || data)
 *     record: HMAC(secret, "atproto-space-record-v1" || len uri || record CID bytes)
 *
 * Each string behind a big-endian uint16 of its byte length.
 */
final class Mac
{
    public const WANT_HEADER = 'Atproto-Space-Want-Mac';

    public const HEADER = 'Atproto-Space-Mac';

    public const COMMIT = 'atproto-space-repo';

    public const RECORD = 'atproto-space-record';

    public const RECORD_VERSION = 1;

    /**
     * The request header asking for a MAC of a type.
     */
    public static function wantHeader(string $type, string $keyShare): string
    {
        return 'type="'.$type.'", ver='.self::version($type).', key_share="'.$keyShare.'"';
    }

    /**
     * The MAC in a response header, after checking its type and version.
     *
     * @throws RepoVerificationException when the header is missing or malformed
     */
    public static function fromHeader(?string $header, string $type): string
    {
        $fields = $header === null || $header === '' ? null : StructuredFields::parseDictionary($header);

        if ($fields === null) {
            throw new RepoVerificationException('The repo host sent no '.self::HEADER.' header.');
        }

        if (($fields['type'] ?? null) !== $type || ($fields['ver'] ?? null) !== self::version($type)) {
            throw new RepoVerificationException("The repo host sent a MAC of another type or version than {$type}.");
        }

        $mac = $fields['mac'] ?? null;

        if (! $mac instanceof Bytes || strlen($mac->value) !== 32) {
            throw new RepoVerificationException('The repo host sent a malformed MAC.');
        }

        return $mac->value;
    }

    public static function forCommit(string $sharedSecret, Commit $commit): string
    {
        $context = self::COMMIT.'-v'.Commit::VERSION
            .self::lengthPrefixed($commit->space)
            .self::lengthPrefixed($commit->repo)
            .self::lengthPrefixed($commit->rev);

        return hash_hmac('sha256', $context.$commit->data, $sharedSecret, true);
    }

    public static function forRecord(string $sharedSecret, string $uri, Cid $cid): string
    {
        $context = self::RECORD.'-v'.self::RECORD_VERSION.self::lengthPrefixed($uri);

        return hash_hmac('sha256', $context.$cid->bytes, $sharedSecret, true);
    }

    public static function version(string $type): int
    {
        return $type === self::COMMIT ? Commit::VERSION : self::RECORD_VERSION;
    }

    protected static function lengthPrefixed(string $value): string
    {
        return pack('n', strlen($value)).$value;
    }
}
