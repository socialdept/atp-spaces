<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Auth\DpopKeypair;
use SocialDept\AtpSpaces\Auth\Jwk;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Exceptions\SpaceTokenException;
use SocialDept\AtpSpaces\Tests\MintsTokens;

class SpaceTokenTest extends TestCase
{
    use MintsTokens;

    protected const AUTHORITY = 'did:plc:authority';
    protected const USER = 'did:plc:user';
    protected const SPACE = 'at://did:plc:authority/space/com.example.forum/main';

    public function test_the_host_audience_is_a_fragment_not_a_url(): void
    {
        $this->assertSame(
            'did:plc:authority#atproto_space_host',
            SpaceToken::hostAudience(self::AUTHORITY),
        );
    }

    public function test_it_parses_a_delegation_token(): void
    {
        $jwt = $this->mintDelegationToken(self::USER, self::SPACE, self::AUTHORITY);
        $token = SpaceToken::parse(SpaceTokenType::Delegation, $jwt);

        $this->assertSame(self::USER, $token->issuer());
        $this->assertSame(self::SPACE, $token->subject());
        $this->assertSame('did:plc:authority#atproto_space_host', $token->audience());
        $this->assertSame('#atproto', $token->header['kid']);
        $this->assertSame(self::SPACE, (string) $token->space());
    }

    public function test_it_parses_a_credential_bound_to_a_key(): void
    {
        $keypair = DpopKeypair::generate();
        $jwt = $this->mintCredential(self::AUTHORITY, self::SPACE, $keypair);
        $token = SpaceToken::parse(SpaceTokenType::Credential, $jwt);

        $this->assertSame($keypair->thumbprint(), $token->confirmationThumbprint());
        $this->assertTrue($token->isBoundTo($keypair->thumbprint()));
        $this->assertFalse($token->isBoundTo(DpopKeypair::generate()->thumbprint()));
    }

    /**
     * A credential carries no audience: it is multi-use across every repo host
     * in the space, and bound to a DPoP key instead.
     */
    public function test_a_credential_needs_no_audience(): void
    {
        $token = SpaceToken::parse(
            SpaceTokenType::Credential,
            $this->mintCredential(self::AUTHORITY, self::SPACE, DpopKeypair::generate()),
        );

        $this->assertNull($token->audience());
    }

    public function test_a_delegation_token_requires_an_audience(): void
    {
        $jwt = $this->mintToken(SpaceTokenType::Delegation, self::USER, self::SPACE);

        $this->expectException(SpaceTokenException::class);
        $this->expectExceptionMessage('missing "aud"');

        SpaceToken::parse(SpaceTokenType::Delegation, $jwt);
    }

    public function test_a_credential_requires_a_key_binding(): void
    {
        $jwt = $this->mintToken(SpaceTokenType::Credential, self::AUTHORITY, self::SPACE);

        $this->expectException(SpaceTokenException::class);
        $this->expectExceptionMessage('missing "cnf.jkt"');

        SpaceToken::parse(SpaceTokenType::Credential, $jwt);
    }

    /**
     * Parsing as the wrong class must fail, or a delegation token could be
     * presented where a credential is expected.
     */
    public function test_it_rejects_a_token_of_the_wrong_class(): void
    {
        $jwt = $this->mintDelegationToken(self::USER, self::SPACE, self::AUTHORITY);

        $this->expectException(SpaceTokenException::class);
        $this->expectExceptionMessage('Wrong token type');

        SpaceToken::parse(SpaceTokenType::Credential, $jwt);
    }

    public function test_it_rejects_a_malformed_token(): void
    {
        $this->expectException(SpaceTokenException::class);
        $this->expectExceptionMessage('expected 3 parts');

        SpaceToken::parse(SpaceTokenType::Delegation, 'not.a-jwt');
    }

    public function test_it_rejects_an_unparseable_payload(): void
    {
        $jwt = Jwk::base64UrlEncode('{"typ":"atproto-space-delegation+jwt","alg":"ES256"}')
            .'.'.Jwk::base64UrlEncode('not json').'.sig';

        $this->expectException(SpaceTokenException::class);
        $this->expectExceptionMessage('Could not parse token payload');

        SpaceToken::parse(SpaceTokenType::Delegation, $jwt);
    }

    public function test_a_client_attestation_must_be_self_issued(): void
    {
        $jwt = $this->mintToken(
            SpaceTokenType::ClientAttestation,
            'https://app.example.com/client-metadata.json',
            'https://other.example.com/client-metadata.json',
            audience: SpaceToken::hostAudience(self::AUTHORITY),
        );

        $this->expectException(SpaceTokenException::class);
        $this->expectExceptionMessage('must both be the client_id');

        SpaceToken::parse(SpaceTokenType::ClientAttestation, $jwt);
    }

    public function test_expiry_allows_for_clock_skew(): void
    {
        $token = SpaceToken::parse(
            SpaceTokenType::Delegation,
            $this->mintDelegationToken(self::USER, self::SPACE, self::AUTHORITY),
        );

        $expiry = $token->expiresAt();

        $this->assertFalse($token->isExpired($expiry - 10));
        $this->assertTrue($token->isExpired($expiry + SpaceToken::CLOCK_SKEW));
        $this->assertSame(0, $token->secondsRemaining($expiry + 100));
    }

    /**
     * @return array<string, array{SpaceTokenType, string, int, bool, bool, bool}>
     */
    public static function tokenSpecProvider(): array
    {
        return [
            'delegation' => [SpaceTokenType::Delegation, 'atproto-space-delegation+jwt', 60, true, false, true],
            'credential' => [SpaceTokenType::Credential, 'atproto-space-credential+jwt', 7200, false, true, false],
            'attestation' => [SpaceTokenType::ClientAttestation, 'atproto-client-attestation+jwt', 60, true, false, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenSpecProvider')]
    public function test_the_token_specs_match_upstream(
        SpaceTokenType $type,
        string $typ,
        int $lifetime,
        bool $requiresAudience,
        bool $requiresConfirmation,
        bool $singleUse,
    ): void {
        $this->assertSame($typ, $type->typ());
        $this->assertSame($lifetime, $type->lifetime());
        $this->assertSame($requiresAudience, $type->requiresAudience());
        $this->assertSame($requiresConfirmation, $type->requiresConfirmation());
        $this->assertSame($singleUse, $type->isSingleUse());
    }
}
