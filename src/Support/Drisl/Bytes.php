<?php

namespace SocialDept\AtpSpaces\Support\Drisl;

/**
 * A DRISL byte string, kept apart from text so a value encodes back to
 * exactly the bytes it was decoded from.
 */
final class Bytes
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}
