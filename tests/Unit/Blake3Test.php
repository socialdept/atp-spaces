<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Crypto\Blake3;

/**
 * BLAKE3 against the upstream test vectors.
 *
 * The vectors are the official ones from the BLAKE3 reference repository,
 * vendored in tests/fixtures. Every case covers hash, keyed hash and key
 * derivation at an extended output length, so XOF is exercised throughout —
 * which is the mode spaces actually use.
 */
class Blake3Test extends TestCase
{
    /**
     * @return array<string, array{int, string, string, string}>
     */
    public static function vectorProvider(): array
    {
        $vectors = json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/blake3_test_vectors.json'),
            true,
        );

        $cases = [];

        foreach ($vectors['cases'] as $case) {
            $cases['input_len='.$case['input_len']] = [
                $case['input_len'],
                $case['hash'],
                $case['keyed_hash'],
                $case['derive_key'],
            ];
        }

        return $cases;
    }

    #[DataProvider('vectorProvider')]
    public function test_it_matches_the_upstream_vectors(
        int $inputLength,
        string $hash,
        string $keyedHash,
        string $deriveKey,
    ): void {
        $input = self::input($inputLength);
        $outLength = strlen($hash) / 2;

        $this->assertSame($hash, bin2hex(Blake3::hash($input, $outLength)));
        $this->assertSame($keyedHash, bin2hex(Blake3::keyedHash(self::KEY, $input, $outLength)));
        $this->assertSame($deriveKey, bin2hex(Blake3::deriveKey(self::CONTEXT, $input, $outLength)));
    }

    public function test_it_defaults_to_a_32_byte_digest(): void
    {
        $this->assertSame(32, strlen(Blake3::hash('')));
    }

    public function test_it_returns_nothing_for_a_zero_length_output(): void
    {
        $this->assertSame('', Blake3::hash('hello', 0));
    }

    public function test_it_rejects_a_negative_output_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Blake3::hash('hello', -1);
    }

    public function test_it_rejects_a_key_of_the_wrong_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Blake3::keyedHash('too short', 'hello');
    }

    public function test_extended_output_extends_the_short_one(): void
    {
        // XOF output is a prefix-stable stream, so a longer squeeze must begin
        // with the shorter one.
        $short = Blake3::hash('atproto', 32);
        $long = Blake3::hash('atproto', 2048);

        $this->assertSame(2048, strlen($long));
        $this->assertSame(bin2hex($short), bin2hex(substr($long, 0, 32)));
    }

    public function test_it_implements_the_hasher_contract(): void
    {
        $hasher = new Blake3();

        $this->assertSame(
            bin2hex(Blake3::hash('atproto', 2048)),
            bin2hex($hasher->xof('atproto', 2048)),
        );
    }

    /**
     * Upstream builds inputs by repeating the bytes 0..250.
     */
    protected static function input(int $length): string
    {
        $input = '';

        for ($i = 0; $i < $length; $i++) {
            $input .= chr($i % 251);
        }

        return $input;
    }

    protected const KEY = 'whats the Elvish word for friend';
    protected const CONTEXT = 'BLAKE3 2019-12-27 16:29:52 test vectors context';
}
