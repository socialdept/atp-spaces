<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialDept\AtpSpaces\Auth\CredentialBroker;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Authority\SpaceAuthority;
use SocialDept\AtpSpaces\Cache\CacheCredentialStore;
use SocialDept\AtpSpaces\Cache\NullCredentialStore;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
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
    protected function broker(HttpFactory $http, ?string $delegation = null, ?CredentialStore $store = null): TestableBroker
    {
        $authorities = $this->createStub(AuthorityResolver::class);
        $authorities->method('forSpace')->willReturn(new SpaceAuthority(
            did: self::AUTHORITY,
            endpoint: self::HOST,
        ));

        return new TestableBroker(
            $authorities,
            $store ?? new NullCredentialStore(),
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
     * Everything goes in the body, and the request itself carries no
     * authorization: the delegation token, and the did:key the authority is
     * to bind the credential to.
     */
    public function test_it_asks_for_a_credential_in_the_body(): void
    {
        $seen = [];
        $http = $this->fakeAuthority(seen: $seen);

        $credential = $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER, 'the-attestation');

        $this->assertSame('', $seen['authorization']);
        $this->assertSame((string) $this->space(), $seen['body']['space']);
        $this->assertSame($credential->key->didKey(), $seen['body']['credentialKid']);
        $this->assertStringStartsWith('did:key:zDn', $seen['body']['credentialKid'], 'A P-256 did:key.');
        $this->assertSame(SpaceTokenType::Delegation->typ(), SpaceToken::parse(SpaceTokenType::Delegation, $seen['body']['delegationToken'])->header['typ']);
        $this->assertSame('the-attestation', $seen['body']['clientAttestation']);
    }

    public function test_each_credential_gets_a_fresh_key(): void
    {
        $broker = $this->broker($this->fakeAuthority());

        $this->assertNotSame(
            $broker->mint($this->space(), $this->stubClient(), self::USER)->key->didKey(),
            $broker->mint($this->space(), $this->stubClient(), self::USER)->key->didKey(),
        );
    }

    public function test_it_rejects_a_credential_bound_to_another_key(): void
    {
        $http = $this->fakeAuthority(bindTo: CredentialKey::generate());

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
            'bad delegation token' => ['InvalidDelegationToken', 'isRetryable'],
        ];
    }

    #[DataProvider('refusalProvider')]
    public function test_it_surfaces_the_authority_refusal(string $error, string $predicate): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*' => $http->response(['error' => $error, 'message' => 'nope'], 400),
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

    public function test_it_fails_when_the_authority_returns_no_credential(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response(['ok' => true])]);

        $this->expectException(CredentialException::class);
        $this->expectExceptionMessage('returned no credential');

        $this->broker($http)->mint($this->space(), $this->stubClient(), self::USER);
    }

    /**
     * A browser that holds the session hands over the token, and the
     * credential is bound to this application's key all the same.
     */
    public function test_it_mints_from_a_delegation_token_it_is_handed(): void
    {
        $seen = [];
        $store = new CacheCredentialStore(new Repository(new ArrayStore()));
        $token = $this->mintDelegationToken(self::USER, (string) $this->space(), self::AUTHORITY);

        $credential = $this->broker($this->fakeAuthority(seen: $seen), store: $store)
            ->fromDelegation($this->space(), $token, 'the-attestation');

        $this->assertTrue($credential->isCorrectlyBound());
        $this->assertSame(self::USER, $credential->userDid);
        $this->assertSame($token, $seen['body']['delegationToken']);
        $this->assertSame('the-attestation', $seen['body']['clientAttestation']);
        $this->assertSame($credential->token->jwt, $store->get($this->space(), self::USER)?->token->jwt);
    }

    public function test_a_handed_token_for_another_space_is_refused(): void
    {
        $other = SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'other');
        $token = $this->mintDelegationToken(self::USER, (string) $other, self::AUTHORITY);
        $seen = [];

        try {
            $this->broker($this->fakeAuthority(seen: $seen))->fromDelegation($this->space(), $token);
            $this->fail('Expected a CredentialException.');
        } catch (CredentialException $e) {
            $this->assertSame('InvalidDelegationToken', $e->error);
            $this->assertStringContainsString('not '.$this->space(), $e->getMessage());
        }

        $this->assertSame([], $seen, 'The token is never sent.');
    }

    public function test_a_handed_token_for_another_authority_is_refused(): void
    {
        $token = $this->mintDelegationToken(self::USER, (string) $this->space(), 'did:plc:elsewhere');

        $this->expectException(CredentialException::class);
        $this->expectExceptionMessage('audience does not match');

        $this->broker($this->fakeAuthority())->fromDelegation($this->space(), $token);
    }

    /**
     * A credential is not a delegation token, even though both are JWTs.
     */
    public function test_a_handed_token_of_the_wrong_kind_is_refused(): void
    {
        $credential = $this->mintCredential(self::AUTHORITY, (string) $this->space(), CredentialKey::generate());

        foreach ([$credential, 'not-a-jwt'] as $token) {
            try {
                $this->broker($this->fakeAuthority())->fromDelegation($this->space(), $token);
                $this->fail('Expected a CredentialException.');
            } catch (CredentialException $e) {
                $this->assertSame('InvalidDelegationToken', $e->error);
            }
        }
    }

    /**
     * Fake the authority, echoing back a credential bound to whichever key
     * the request named.
     *
     * @param  array<string, mixed>  $seen
     */
    protected function fakeAuthority(
        array &$seen = [],
        ?CredentialKey $bindTo = null,
        ?string $issuer = null,
    ): HttpFactory {
        $http = new HttpFactory();

        $http->fake(function ($request) use ($http, &$seen, $bindTo, $issuer) {
            $seen['authorization'] = $request->header('Authorization')[0] ?? '';
            $seen['body'] = $request->data();

            return $http->response([
                'credential' => $this->mintToken(
                    SpaceTokenType::Credential,
                    $issuer ?? self::AUTHORITY,
                    (string) $this->space(),
                    boundKey: $bindTo?->didKey() ?? $seen['body']['credentialKid'],
                ),
            ]);
        });

        return $http;
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
 * A broker with the PDS hop replaced.
 */
class TestableBroker extends CredentialBroker
{
    public function __construct(
        AuthorityResolver $authorities,
        CredentialStore $store,
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
