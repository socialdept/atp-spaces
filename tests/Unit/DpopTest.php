<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Auth\DpopKeypair;
use SocialDept\AtpSpaces\Auth\DpopProof;
use SocialDept\AtpSpaces\Auth\Jwk;

class DpopTest extends TestCase
{
    /**
     * The worked example from RFC 7638 section 3.1. The thumbprint is a wire
     * value the authority recomputes, so it has to be exactly right.
     */
    public function test_the_thumbprint_matches_the_rfc_vector(): void
    {
        $jwk = [
            'kty' => 'RSA',
            'n' => '0vx7agoebGcQSuuPiLJXZptN9nndrQmbXEps2aiAFbWhM78LhWx4cbbfAAtVT86zwu1RK7aPFFxuhDR1L'
                .'6tSoc_BJECPebWKRXjBZCiFV4n3oknjhMstn64tZ_2W-5JsGY4Hc5n9yBXArwl93lqt7_RN5w6Cf0h4QyQ'
                .'5v-65YGjQR0_FDW2QvzqY368QQMicAtaSqzs8KJZgnYb9c7d0zgdAZHzu6qMQvRL5hajrn1n91CbOpbISD'
                .'08qNLyrdkt-bFTWhAI4vMQFh6WeZu0fM4lFd2NcRwr3XPksINHaQ-G_xBniIqbw0Ls1jF44-csFCur-kEg'
                .'U8awapJzKnqDKgw',
            'e' => 'AQAB',
            'alg' => 'RS256',
            'kid' => '2011-04-29',
        ];

        $this->assertSame('NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs', Jwk::thumbprint($jwk));
    }

    /**
     * Extra members must not reach the canonical form, or every party would
     * compute a different thumbprint for the same key.
     */
    public function test_canonicalization_drops_everything_optional(): void
    {
        $this->assertSame(
            '{"crv":"P-256","kty":"EC","x":"xxx","y":"yyy"}',
            Jwk::canonicalize([
                'use' => 'sig',
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => 'xxx',
                'y' => 'yyy',
                'alg' => 'ES256',
                'kid' => 'ignored',
            ]),
        );
    }

    public function test_it_refuses_an_unknown_key_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Jwk::thumbprint(['kty' => 'MAGIC']);
    }

    public function test_it_refuses_a_jwk_missing_a_required_member(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Jwk::thumbprint(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'xxx']);
    }

    public function test_base64url_round_trips_without_padding(): void
    {
        $bytes = random_bytes(33);
        $encoded = Jwk::base64UrlEncode($bytes);

        $this->assertStringNotContainsString('=', $encoded);
        $this->assertSame($bytes, Jwk::base64UrlDecode($encoded));
    }

    public function test_a_keypair_exposes_only_bare_jwk_members(): void
    {
        $jwk = DpopKeypair::generate()->publicJwk();

        $this->assertSame(['crv', 'kty', 'x', 'y'], array_keys($jwk));
        $this->assertSame('P-256', $jwk['crv']);
        $this->assertSame('EC', $jwk['kty']);
    }

    public function test_a_keypair_survives_a_pem_round_trip(): void
    {
        $original = DpopKeypair::generate();
        $restored = DpopKeypair::fromPem($original->toPem());

        $this->assertSame($original->thumbprint(), $restored->thumbprint());
    }

    public function test_two_keypairs_differ(): void
    {
        $this->assertNotSame(
            DpopKeypair::generate()->thumbprint(),
            DpopKeypair::generate()->thumbprint(),
        );
    }

    public function test_a_proof_names_the_method_and_url(): void
    {
        $keypair = DpopKeypair::generate();
        $proof = new DpopProof($keypair);

        $payload = $this->payloadOf($proof->create('post', 'https://pds.example/xrpc/com.atproto.space.getRecord'));

        $this->assertSame('POST', $payload['htm']);
        $this->assertSame('https://pds.example/xrpc/com.atproto.space.getRecord', $payload['htu']);
        $this->assertArrayHasKey('jti', $payload);
        $this->assertSame($payload['iat'] + DpopProof::MAX_AGE, $payload['exp']);
    }

    /**
     * `htu` is the request URI with query and fragment removed.
     */
    public function test_the_proof_url_drops_the_query_string(): void
    {
        $this->assertSame(
            'https://pds.example:8443/xrpc/com.atproto.space.listRecords',
            DpopProof::canonicalUrl('https://pds.example:8443/xrpc/com.atproto.space.listRecords?repo=did:plc:x#frag'),
        );
    }

    public function test_the_embedded_jwk_matches_the_signing_key(): void
    {
        $keypair = DpopKeypair::generate();
        $header = $this->headerOf((new DpopProof($keypair))->create('GET', 'https://pds.example/x'));

        $this->assertSame('dpop+jwt', $header['typ']);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame($keypair->thumbprint(), Jwk::thumbprint($header['jwk']));
    }

    public function test_presenting_a_credential_hashes_it_into_ath(): void
    {
        $proof = new DpopProof(DpopKeypair::generate());

        $withToken = $this->payloadOf($proof->create('GET', 'https://pds.example/x', token: 'the-credential'));
        $without = $this->payloadOf($proof->create('GET', 'https://pds.example/x'));

        $this->assertSame(
            Jwk::base64UrlEncode(hash('sha256', 'the-credential', true)),
            $withToken['ath'],
        );
        $this->assertArrayNotHasKey('ath', $without);
    }

    public function test_a_nonce_is_included_only_when_given(): void
    {
        $proof = new DpopProof(DpopKeypair::generate());

        $this->assertSame('n-1', $this->payloadOf($proof->create('GET', 'https://x.test/y', 'n-1'))['nonce']);
        $this->assertArrayNotHasKey('nonce', $this->payloadOf($proof->create('GET', 'https://x.test/y')));
    }

    public function test_every_proof_has_a_unique_id(): void
    {
        $proof = new DpopProof(DpopKeypair::generate());

        $this->assertNotSame(
            $this->payloadOf($proof->create('GET', 'https://x.test/y'))['jti'],
            $this->payloadOf($proof->create('GET', 'https://x.test/y'))['jti'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadOf(string $jwt): array
    {
        return json_decode(Jwk::base64UrlDecode(explode('.', $jwt)[1]), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function headerOf(string $jwt): array
    {
        return json_decode(Jwk::base64UrlDecode(explode('.', $jwt)[0]), true);
    }
}
