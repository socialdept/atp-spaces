<?php

namespace SocialDept\AtpSpaces\Crypto;

use InvalidArgumentException;
use SocialDept\AtpSpaces\Contracts\Blake3Hasher;

/**
 * A pure-PHP BLAKE3, supporting hashing, keyed hashing, key derivation, and
 * extendable output.
 *
 * PHP has no BLAKE3 in core and no widely-adopted extension, and spaces need
 * it for the set hash that backs every space repo. Rather than take a
 * dependency on a thinly-used package for one function, it lives here.
 *
 * This is a direct port of the reference implementation, chosen for
 * auditability over speed. It is verified against the upstream test vectors in
 * the package's test suite. If throughput matters, bind a faster
 * {@see Blake3Hasher} in its place.
 *
 * @see https://github.com/BLAKE3-team/BLAKE3
 */
class Blake3 implements Blake3Hasher
{
    public const OUT_LEN = 32;
    public const KEY_LEN = 32;
    public const BLOCK_LEN = 64;
    public const CHUNK_LEN = 1024;

    protected const CHUNK_START = 1 << 0;
    protected const CHUNK_END = 1 << 1;
    protected const PARENT = 1 << 2;
    protected const ROOT = 1 << 3;
    protected const KEYED_HASH = 1 << 4;
    protected const DERIVE_KEY_CONTEXT = 1 << 5;
    protected const DERIVE_KEY_MATERIAL = 1 << 6;

    protected const MASK = 0xFFFFFFFF;

    /**
     * The SHA-256 initialization vector.
     */
    protected const IV = [
        0x6A09E667, 0xBB67AE85, 0x3C6EF372, 0xA54FF53A,
        0x510E527F, 0x9B05688C, 0x1F83D9AB, 0x5BE0CD19,
    ];

    protected const MSG_PERMUTATION = [
        2, 6, 3, 10, 7, 0, 4, 13, 1, 11, 12, 5, 9, 14, 15, 8,
    ];

    /**
     * Hash an input to an arbitrary output length.
     */
    public function xof(string $input, int $length): string
    {
        return self::hash($input, $length);
    }

    /**
     * Standard BLAKE3 hash.
     */
    public static function hash(string $input, int $length = self::OUT_LEN): string
    {
        return self::run($input, self::IV, 0, $length);
    }

    /**
     * Keyed BLAKE3, for a 32-byte key.
     */
    public static function keyedHash(string $key, string $input, int $length = self::OUT_LEN): string
    {
        if (strlen($key) !== self::KEY_LEN) {
            throw new InvalidArgumentException('BLAKE3 key must be '.self::KEY_LEN.' bytes.');
        }

        return self::run($input, self::wordsFromBytes($key), self::KEYED_HASH, $length);
    }

    /**
     * BLAKE3 key derivation from a context string.
     */
    public static function deriveKey(string $context, string $keyMaterial, int $length = self::OUT_LEN): string
    {
        $contextKey = self::run($context, self::IV, self::DERIVE_KEY_CONTEXT, self::KEY_LEN);

        return self::run($keyMaterial, self::wordsFromBytes($contextKey), self::DERIVE_KEY_MATERIAL, $length);
    }

    /**
     * Hash an input under a given key and flag set.
     *
     * Input is consumed a block at a time into the current chunk. A finished
     * chunk's chaining value is merged with every completed left sibling on the
     * stack, which keeps the tree in exactly `popcount(chunks)` pending nodes.
     *
     * @param  array<int, int>  $keyWords
     */
    protected static function run(string $input, array $keyWords, int $flags, int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('BLAKE3 output length cannot be negative.');
        }

        if ($length === 0) {
            return '';
        }

        $cv = $keyWords;
        $chunkCounter = 0;
        $block = '';
        $blocksCompressed = 0;

        /** @var array<int, array<int, int>> $stack */
        $stack = [];

        $offset = 0;
        $total = strlen($input);

        while ($offset < $total) {
            // The chunk is full and input remains, so it is not the last one:
            // finalize it and fold its chaining value into the tree.
            if (self::BLOCK_LEN * $blocksCompressed + strlen($block) === self::CHUNK_LEN) {
                $chunkCv = self::first8(self::compress(
                    $cv,
                    self::wordsFromBytes($block),
                    $chunkCounter,
                    strlen($block),
                    $flags | self::startFlag($blocksCompressed) | self::CHUNK_END,
                ));

                $totalChunks = $chunkCounter + 1;

                // Every trailing zero bit is a completed left sibling to merge.
                // This consumes a copy: the next chunk's counter is the
                // unshifted total, not what the merge loop leaves behind.
                $merging = $totalChunks;

                while (($merging & 1) === 0) {
                    $chunkCv = self::first8(self::compress(
                        $keyWords,
                        array_merge(array_pop($stack), $chunkCv),
                        0,
                        self::BLOCK_LEN,
                        $flags | self::PARENT,
                    ));
                    $merging >>= 1;
                }

                $stack[] = $chunkCv;

                $cv = $keyWords;
                $chunkCounter = $totalChunks;
                $block = '';
                $blocksCompressed = 0;
            }

            // A full block with input still to come is never a chunk's last.
            if (strlen($block) === self::BLOCK_LEN) {
                $cv = self::first8(self::compress(
                    $cv,
                    self::wordsFromBytes($block),
                    $chunkCounter,
                    self::BLOCK_LEN,
                    $flags | self::startFlag($blocksCompressed),
                ));
                $blocksCompressed++;
                $block = '';
            }

            $take = min(self::BLOCK_LEN - strlen($block), $total - $offset);
            $block .= substr($input, $offset, $take);
            $offset += $take;
        }

        // The trailing chunk, then each unmerged left sibling from the top down.
        $outCv = $cv;
        $outBlock = self::wordsFromBytes($block);
        $outCounter = $chunkCounter;
        $outBlockLen = strlen($block);
        $outFlags = $flags | self::startFlag($blocksCompressed) | self::CHUNK_END;

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $right = self::first8(self::compress($outCv, $outBlock, $outCounter, $outBlockLen, $outFlags));
            $outBlock = array_merge($stack[$i], $right);
            $outCv = $keyWords;
            $outCounter = 0;
            $outBlockLen = self::BLOCK_LEN;
            $outFlags = $flags | self::PARENT;
        }

        return self::rootBytes($outCv, $outBlock, $outBlockLen, $outFlags, $length);
    }

    /**
     * CHUNK_START applies only to a chunk's first block.
     */
    protected static function startFlag(int $blocksCompressed): int
    {
        return $blocksCompressed === 0 ? self::CHUNK_START : 0;
    }

    /**
     * Squeeze the root output to the requested length.
     *
     * The counter here numbers output blocks, and always restarts at zero — it
     * is unrelated to the chunk counter that produced this node.
     *
     * @param  array<int, int>  $cv
     * @param  array<int, int>  $block
     */
    protected static function rootBytes(array $cv, array $block, int $blockLen, int $flags, int $length): string
    {
        $out = '';
        $counter = 0;

        while (strlen($out) < $length) {
            $out .= pack('V16', ...self::compress($cv, $block, $counter, $blockLen, $flags | self::ROOT));
            $counter++;
        }

        return substr($out, 0, $length);
    }

    /**
     * The BLAKE3 compression function, returning all 16 state words.
     *
     * @param  array<int, int>  $cv
     * @param  array<int, int>  $block
     * @return array<int, int>
     */
    protected static function compress(array $cv, array $block, int $counter, int $blockLen, int $flags): array
    {
        $s = [
            $cv[0], $cv[1], $cv[2], $cv[3], $cv[4], $cv[5], $cv[6], $cv[7],
            self::IV[0], self::IV[1], self::IV[2], self::IV[3],
            $counter & self::MASK,
            ($counter >> 32) & self::MASK,
            $blockLen & self::MASK,
            $flags & self::MASK,
        ];

        $m = $block;

        for ($round = 0; $round < 7; $round++) {
            // Columns.
            self::g($s, 0, 4, 8, 12, $m[0], $m[1]);
            self::g($s, 1, 5, 9, 13, $m[2], $m[3]);
            self::g($s, 2, 6, 10, 14, $m[4], $m[5]);
            self::g($s, 3, 7, 11, 15, $m[6], $m[7]);
            // Diagonals.
            self::g($s, 0, 5, 10, 15, $m[8], $m[9]);
            self::g($s, 1, 6, 11, 12, $m[10], $m[11]);
            self::g($s, 2, 7, 8, 13, $m[12], $m[13]);
            self::g($s, 3, 4, 9, 14, $m[14], $m[15]);

            if ($round === 6) {
                break;
            }

            $permuted = [];

            foreach (self::MSG_PERMUTATION as $index) {
                $permuted[] = $m[$index];
            }

            $m = $permuted;
        }

        for ($i = 0; $i < 8; $i++) {
            $s[$i] ^= $s[$i + 8];
            $s[$i + 8] ^= $cv[$i];
        }

        return $s;
    }

    /**
     * The BLAKE3 quarter-round.
     *
     * @param  array<int, int>  $s
     */
    protected static function g(array &$s, int $a, int $b, int $c, int $d, int $mx, int $my): void
    {
        $s[$a] = ($s[$a] + $s[$b] + $mx) & self::MASK;
        $s[$d] = self::rotr($s[$d] ^ $s[$a], 16);
        $s[$c] = ($s[$c] + $s[$d]) & self::MASK;
        $s[$b] = self::rotr($s[$b] ^ $s[$c], 12);
        $s[$a] = ($s[$a] + $s[$b] + $my) & self::MASK;
        $s[$d] = self::rotr($s[$d] ^ $s[$a], 8);
        $s[$c] = ($s[$c] + $s[$d]) & self::MASK;
        $s[$b] = self::rotr($s[$b] ^ $s[$c], 7);
    }

    /**
     * Rotate a 32-bit word right.
     */
    protected static function rotr(int $x, int $n): int
    {
        $x &= self::MASK;

        return (($x >> $n) | ($x << (32 - $n))) & self::MASK;
    }

    /**
     * The first eight words of a compression result: a chaining value.
     *
     * @param  array<int, int>  $words
     * @return array<int, int>
     */
    protected static function first8(array $words): array
    {
        return array_slice($words, 0, 8);
    }

    /**
     * Read little-endian 32-bit words, zero-padding to a full block.
     *
     * @return array<int, int>
     */
    protected static function wordsFromBytes(string $bytes): array
    {
        $padded = str_pad($bytes, self::BLOCK_LEN, "\0");

        return array_values(unpack('V16', substr($padded, 0, self::BLOCK_LEN)));
    }
}
