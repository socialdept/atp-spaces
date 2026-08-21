<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialDept\AtpSpaces\Auth\CredentialBroker;
use SocialDept\AtpSpaces\Auth\DpopKeypair;
use SocialDept\AtpSpaces\Auth\Jwk;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Authority\SpaceAuthority;
use SocialDept\AtpSpaces\Cache\NullCredentialStore;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Tests\MintsTokens;
use SocialDept\AtpSpaces\Tests\TestCase;

class CredentialBrokerTest extends TestCase
{
    use MintsTokens;

    protected const AUTHORITY = 'did:plc:authority';
    protected const USER = 'did:plc:user';
    protected const HOST = 'https://authority.example';

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    /**
     * A broker whose PDS hop and authority hop are both stubbed, so only the
     * exchange itself is under test.
     */
    protected function broker(HttpFactory $http, ?string $delegation = null): TestableBroker
    {
        $authorities = $this->createStub(AuthorityResolver::class);
        $authorities->method('forSpace')->willReturn(new SpaceAuthority(
            did: self::AUTHORITY,
            endpoint: self::HOST,
            signingKeyId: '#atproto',
        ));

        return new TestableBroker(
            $authorities,
            new NullCredentialStore(),
            $http,
            $delegation ?? $this->mintDelegationToken(self::USER, (string) $this->space(), self::AUTHORITY),
        );
    }

    public function test_it_exchanges_a_delegation_token_for_a_bound_credential(): void
    {
        $credential = $this->broker($this->fakeAuthority())->mint(
            $this->space(),
            $this->stubClient(),
            self::USER,
        );

        $this->assertTrue($credential->isCorrectlyBound());
        $this->assertSame(self::AUTHORITY, $credential->token->issuer());
        $this->assertSame((string) $this->space(), $credential->token->subject());
    }

    /**
     * The delegation token goes as a bearer credential; the DPoP proof is what
     * tells the authority which key to bind the result to.
     */
    public function test_it_sends_the_delegation_token_and_a_proof(): void
    {
        $seen = [];
        $http = $this->fakeAuthority(headers: $seen);

        $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER);

        $this->assertStringStartsWith('Bearer ', $seen['authorization']);
        $this->assertNotEmpty($seen['dpop']);

        $proofHeader = json_decode(Jwk::base64UrlDecode(explode('.', $seen['dpop'])[0]), true);
        $this->assertSame('dpop+jwt', $proofHeader['typ']);
    }

    public function test_it_rejects_a_credential_bound_to_another_key(): void
    {
        $http = $this->fakeAuthority(bindTo: DpopKeypair::generate());

        $this->expectException(CredentialException::class);
        $this->expectExceptionMessage('bound to a different key');

        $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER);
    }

    /**
     * Without this, any DID could sign a credential naming someone else's
     * space and have it accepted.
     */
    public function test_it_rejects_a_credential_from_the_wrong_issuer(): void
    {
        $http = $this->fakeAuthority(issuer: 'did:plc:impostor');

        $this->expectException(CredentialException::class);
        $this->expectExceptionMessage('issuer is not the space authority');

        $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER);
    }

    /**
     * A token minted for one authority must not be spendable at another.
     */
    public function test_it_rejects_a_delegation_token_for_another_authority(): void
    {
        $wrong = $this->mintDelegationToken(self::USER, (string) $this->space(), 'did:plc:elsewhere');
        $http = $this->fakeAuthority();

        $this->expectException(CredentialException::class);
        $this->expectExceptionMessage('audience does not match');

        $this->broker($http, $wrong)->mint($this->space(), $this->stubClient(), self::USER);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'user refused' => ['UserNotAuthorized', 'isUserRefusal'],
            'app refused' => ['AppNotAuthorized', 'isAppRefusal'],
            'space gone' => ['SpaceDeleted', 'isSpaceDeleted'],
        ];
    }

    #[DataProvider('refusalProvider')]
    public function test_it_surfaces_the_authority_refusal(string $error, string $predicate): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*' => $http->response(['error' => $error, 'message' => 'nope'], 401),
        ]);

        try {
            $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER);
            $this->fail('Expected a CredentialException.');
        } catch (CredentialException $e) {
            $this->assertSame($error, $e->error);
            $this->assertTrue($e->{$predicate}());
            $this->assertStringContainsString('nope', $e->getMessage());
        }
    }

    /**
     * An authority may only reveal the nonce it wants by rejecting once.
     */
    public function test_it_retries_once_with_a_nonce(): void
    {
        $calls = 0;

        $http = new HttpFactory();
        $http->fake(function ($request) use (&$calls, $http) {
            $calls++;

            if ($calls === 1) {
                return $http->response(
                    ['error' => 'UseDpopNonce', 'message' => 'nonce required'],
                    400,
                    ['DPoP-Nonce' => 'server-nonce'],
                );
            }

            // The retry must carry the nonce the authority just handed back,
            // signed by the same key as the first attempt.
            $proof = $request->header('DPoP')[0];
            $payload = json_decode(Jwk::base64UrlDecode(explode('.', $proof)[1]), true);
            $this->assertSame('server-nonce', $payload['nonce']);

            return $http->response([
                'credential' => $this->mintToken(
                    SpaceTokenType::Credential,
                    self::AUTHORITY,
                    (string) $this->space(),
                    dpopJkt: $this->thumbprintOfProof($proof),
                ),
            ]);
        });

        $credential = $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER);

        $this->assertSame(2, $calls);
        $this->assertTrue($credential->isCorrectlyBound());
    }

    public function test_it_fails_when_the_authority_returns_no_credential(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response(['ok' => true])]);

        $this->expectException(CredentialException::class);
        $this->expectExceptionMessage('returned no credential');

        $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER);
    }

    /**
     * Fake the authority, echoing back a credential bound to whichever key the
     * request's DPoP proof carried.
     *
     * @param  array<string, string>  $headers
     */
    protected function fakeAuthority(
        array &$headers = [],
        ?DpopKeypair $bindTo = null,
        ?string $issuer = null,
    ): HttpFactory {
        $http = new HttpFactory();

        $http->fake(function ($request) use ($http, &$headers, $bindTo, $issuer) {
            $headers['authorization'] = $request->header('Authorization')[0] ?? '';
            $headers['dpop'] = $request->header('DPoP')[0] ?? '';

            $jkt = $bindTo?->thumbprint() ?? $this->thumbprintOfProof($headers['dpop']);

            return $http->response([
                'credential' => $this->mintToken(
                    SpaceTokenType::Credential,
                    $issuer ?? self::AUTHORITY,
                    (string) $this->space(),
                    dpopJkt: $jkt,
                ),
            ]);
        });

        return $http;
    }

    protected function thumbprintOfProof(string $proof): string
    {
        $header = json_decode(Jwk::base64UrlDecode(explode('.', $proof)[0]), true);

        return Jwk::thumbprint($header['jwk']);
    }

    /**
     * The broker only ever asks the client for a DID, which it takes from the
     * stubbed delegation token instead.
     */
    protected function stubClient(): \SocialDept\AtpClient\AtpClient
    {
        return $this->createStub(\SocialDept\AtpClient\AtpClient::class);
    }
}

/**
 * A broker with the PDS hop replaced, and an optional fixed DPoP key so a test
 * can predict the binding.
 */
class TestableBroker extends CredentialBroker
{
    public function __construct(
        AuthorityResolver $authorities,
        NullCredentialStore $store,
        HttpFactory $http,
        protected string $delegationJwt,
    ) {
        parent::__construct($authorities, $store, $http);
    }

    protected function delegationToken(SpaceRef $space, \SocialDept\AtpClient\AtpClient $client): SpaceToken
    {
        return SpaceToken::parse(SpaceTokenType::Delegation, $this->delegationJwt);
    }
}
