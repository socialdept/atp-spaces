<?php

namespace SocialDept\AtpSpaces\Contracts;

/**
 * BLAKE3 in extendable-output (XOF) mode.
 *
 * Spaces need BLAKE3 for exactly one thing: expanding a set-hash element to
 * 2048 bytes. PHP ships no BLAKE3, so the package provides a pure-PHP
 * implementation and this seam, in case an application has something faster
 * available and wants to bind it instead.
 */
interface Blake3Hasher
{
    /**
     * Hash an input to an arbitrary output length.
     *
     * @param  string  $input  Raw bytes to hash
     * @param  int  $length  Desired output length in bytes
     * @return string Raw output bytes, exactly $length long
     */
    public function xof(string $input, int $length): string;
}
