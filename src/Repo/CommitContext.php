<?php

namespace SocialDept\AtpSpaces\Repo;

use InvalidArgumentException;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * The context a space commit is signed over.
 *
 * A space commit's signature deliberately does **not** cover the repo hash. It
 * covers only this context, so a leaked commit proves the author wrote *some*
 * revision of the repo at a moment in time, but never what was in it. The hash
 * is bound to the context by a symmetric MAC instead, which only a reader who
 * already received the commit can check.
 *
 * Serialization is TLS-style: a domain prefix, then each field behind a
 * big-endian uint16 length. The byte order is deliberately the opposite of the
 * set hash's little-endian lanes.
 */
class CommitContext
{
    /**
     * The protocol tag, which carries the commit format version.
     */
    public const DOMAIN_PREFIX = 'atproto-space-v1';

    protected const MAX_FIELD_LENGTH = 0xFFFF;

    public function __construct(
        public readonly string $space,
        public readonly string $author,
        public readonly string $rev,
    ) {
    }

    /**
     * Build a context for a space reference.
     */
    public static function for(SpaceRef $space, string $author, string $rev): static
    {
        return new static((string) $space, $author, $rev);
    }

    /**
     * Encode the context with a commit's input keying material.
     *
     *     ctx = "atproto-space-v1"
     *        || uint16be(len(space))  || space
     *        || uint16be(len(author)) || author
     *        || uint16be(len(rev))    || rev
     *        || uint16be(len(ikm))    || ikm
     */
    public function encode(string $ikm): string
    {
        $out = self::DOMAIN_PREFIX;

        foreach ([$this->space, $this->author, $this->rev, $ikm] as $field) {
            $length = strlen($field);

            if ($length > self::MAX_FIELD_LENGTH) {
                throw new InvalidArgumentException('Commit context field exceeds its uint16 length prefix.');
            }

            $out .= pack('n', $length).$field;
        }

        return $out;
    }

    /**
     * Whether this context describes the same commit target as another.
     */
    public function equals(self $other): bool
    {
        return $this->space === $other->space
            && $this->author === $other->author
            && $this->rev === $other->rev;
    }
}
