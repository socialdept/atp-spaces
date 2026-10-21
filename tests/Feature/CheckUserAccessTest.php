<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Math\BigInteger;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpSpaces\Access\SpaceAccessRequest;
use SocialDept\AtpSpaces\Contracts\SpaceAccessResolver;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Tests\TestCase;
use SocialDept\AtpSupport\Crypto\Base58;
use SocialDept\AtpSupport\Crypto\DidKey;
use SocialDept\AtpSupport\Crypto\SignatureVerifier;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Resolver;

/**
 * The managing-app endpoint an authority calls to ask whether a user may enter
 * a space it governs.
 */
class CheckUserAccessTest extends TestCase
{
    protected const AUTHORITY = 'did:plc:authority';
    protected const USER = 'did:plc:reader';
    protected const AUDIENCE = 'did:web:app.example.com#forum';
    protected const METHOD = 'com.atproto.simplespace.checkUserAccess';
    protected const PATH = '/xrpc/com.atproto.simplespace.checkUserAccess';
    protected const ORDER = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

    protected PrivateKey $key;

    /**
     * Routes are registered while providers boot, so this has to be set before
     * that happens rather than in setUp().
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-spaces.managing_app.enabled', true);
        $app['config']->set('atp-client.service_auth.audience', self::AUDIENCE);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = EC::createKey('secp256k1');

        // Whoever we say signed, publishes this test's key.
        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolveDid')->willReturnCallback(
            fn (string $did) => DidDocument::fromArray([
                'id' => $did,
                'verificationMethod' => [[
                    'id' => $did.'#atproto',
                    'publicKeyMultibase' => substr($this->didKey(), strlen('did:key:')),
                ]],
            ]),
        );

        $this->app->instance(ServiceAuth::class, new ServiceAuth($resolver, new SignatureVerifier()));

        Log::spy();
    }

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    public function test_it_returns_the_resolvers_answer(): void
    {
        $this->resolve(fn () => true);

        $this->call('GET', self::PATH, $this->params(), [], [], $this->authHeader())
            ->assertOk()
            ->assertExactJson(['authorized' => true]);
    }

    public function test_a_refusal_is_reported_as_such(): void
    {
        $this->resolve(fn () => false);

        $this->call('GET', self::PATH, $this->params(), [], [], $this->authHeader())
            ->assertOk()
            ->assertExactJson(['authorized' => false]);
    }

    /**
     * The resolver receives the question in a form it can act on.
     */
    public function test_the_resolver_sees_the_parsed_request(): void
    {
        $seen = null;
        $this->resolve(function (SpaceAccessRequest $request) use (&$seen) {
            $seen = $request;

            return true;
        });

        $this->call('GET', self::PATH, $this->params(['clientId' => 'https://app.example.com/client-metadata.json']), [], [], $this->authHeader())
            ->assertOk();

        $this->assertSame((string) $this->space(), (string) $seen->space);
        $this->assertSame(self::USER, $seen->user);
        $this->assertSame('https://app.example.com/client-metadata.json', $seen->clientId);
        $this->assertTrue($seen->hasClient());
        $this->assertFalse($seen->isOwner());
        $this->assertSame(self::AUTHORITY, $seen->authority);
    }

    public function test_it_recognises_the_space_owner(): void
    {
        $seen = null;
        $this->resolve(function (SpaceAccessRequest $request) use (&$seen) {
            $seen = $request;

            return true;
        });

        $this->call('GET', self::PATH, $this->params(['user' => self::AUTHORITY]), [], [], $this->authHeader())
            ->assertOk();

        $this->assertTrue($seen->isOwner());
        $this->assertFalse($seen->hasClient());
    }

    /**
     * The middleware proves *a* service signed for this method, but not that
     * it governs the space in question. Without this check any token holder
     * could probe who has access to anyone's space.
     */
    public function test_a_caller_that_is_not_the_spaces_authority_is_refused(): void
    {
        $this->resolve(fn () => $this->fail('the resolver must not be consulted'));

        $headers = $this->authHeader(issuer: 'did:plc:someone-else');

        $this->call('GET', self::PATH, $this->params(), [], [], $headers)
            ->assertOk()
            ->assertExactJson(['authorized' => false]);
    }

    /**
     * A fragment on the caller's identifier is part of the service id, not the DID.
     */
    public function test_the_authority_may_identify_itself_with_a_fragment(): void
    {
        $this->resolve(fn () => true);

        $headers = $this->authHeader(issuer: self::AUTHORITY.'#atproto_space_host');

        $this->call('GET', self::PATH, $this->params(), [], [], $headers)
            ->assertOk()
            ->assertExactJson(['authorized' => true]);
    }

    /**
     * A throwing resolver must read as "no", not as a 500 with a stack trace.
     */
    public function test_a_throwing_resolver_denies(): void
    {
        $this->resolve(fn () => throw new \RuntimeException('billing system down'));

        $this->call('GET', self::PATH, $this->params(), [], [], $this->authHeader())
            ->assertOk()
            ->assertExactJson(['authorized' => false]);
    }

    public function test_an_unauthenticated_request_never_reaches_the_resolver(): void
    {
        $this->resolve(fn () => $this->fail('the resolver must not be consulted'));

        $this->getJson(self::PATH.'?'.http_build_query($this->params()))
            ->assertStatus(401)
            ->assertJson(['error' => 'AuthMissing']);
    }

    public function test_missing_parameters_are_rejected(): void
    {
        $this->resolve(fn () => true);

        $this->call('GET', self::PATH, ['user' => self::USER], [], [], $this->authHeader())
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest']);
    }

    /**
     * Exposing this endpoint without binding a resolver must grant nothing.
     */
    public function test_the_default_resolver_refuses_everyone(): void
    {
        $this->call('GET', self::PATH, $this->params(), [], [], $this->authHeader())
            ->assertOk()
            ->assertExactJson(['authorized' => false]);
    }

    protected function resolve(callable $decide): void
    {
        $this->app->instance(SpaceAccessResolver::class, new class ($decide) implements SpaceAccessResolver {
            public function __construct(protected $decide)
            {
            }

            public function authorize(SpaceAccessRequest $request): bool
            {
                return ($this->decide)($request);
            }
        });
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function params(array $overrides = []): array
    {
        return array_merge([
            'space' => (string) $this->space(),
            'user' => self::USER,
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    protected function authHeader(?string $issuer = null): array
    {
        $jwt = $this->app->make(ServiceAuth::class)->mint(
            $issuer ?? self::AUTHORITY,
            self::AUDIENCE,
            self::METHOD,
            $this->signer(),
        );

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt];
    }

    /**
     * @return callable(string): string
     */
    protected function signer(): callable
    {
        $order = new BigInteger(self::ORDER, 16);

        return function (string $data) use ($order): string {
            $signature = $this->key->withSignatureFormat('Raw')->withHash('sha256')->sign($data);
            [$half] = $order->divide(new BigInteger(2));
            $s = $signature['s']->compare($half) > 0 ? $order->subtract($signature['s']) : $signature['s'];

            return str_pad($signature['r']->toBytes(), 32, "\0", STR_PAD_LEFT)
                .str_pad($s->toBytes(), 32, "\0", STR_PAD_LEFT);
        };
    }

    protected function didKey(): string
    {
        $point = $this->key->getPublicKey()->getEncodedCoordinates();
        $x = substr($point, 1, 32);
        $y = substr($point, 33, 32);

        return 'did:key:z'.Base58::encode(DidKey::SECP256K1_PREFIX.chr(0x02 | (ord($y[31]) & 1)).$x);
    }
}
