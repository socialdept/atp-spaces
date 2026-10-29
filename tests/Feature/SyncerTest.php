<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Math\BigInteger;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpSpaces\Events\SpaceDeletedNotified;
use SocialDept\AtpSpaces\Events\SpaceWriteNotified;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Tests\TestCase;
use SocialDept\AtpSupport\Crypto\Base58;
use SocialDept\AtpSupport\Crypto\DidKey;
use SocialDept\AtpSupport\Crypto\SignatureVerifier;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Resolver;

/**
 * The endpoints a space's authority calls on a service registered through
 * `registerNotify`.
 */
class SyncerTest extends TestCase
{
    protected const AUTHORITY = 'did:plc:authority';
    protected const MEMBER = 'did:plc:member';
    protected const AUDIENCE = 'did:web:app.example.com#atproto_space_syncer';
    protected const WRITE = 'com.atproto.space.notifyWrite';
    protected const DELETED = 'com.atproto.space.notifySpaceDeleted';
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
        $app['config']->set('atp-spaces.syncer.enabled', true);
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

        Event::fake([SpaceWriteNotified::class, SpaceDeletedNotified::class]);
        Log::spy();
    }

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    public function test_a_write_becomes_an_event(): void
    {
        $this->notify(self::WRITE, $this->write())->assertOk();

        Event::assertDispatched(SpaceWriteNotified::class, function (SpaceWriteNotified $event) {
            return (string) $event->space === (string) $this->space()
                && $event->repo === self::MEMBER
                && $event->repoRev === '3lzjrdf2kvc2a'
                && $event->data === str_repeat("\x01", 32)
                && $event->spaceRev === '3lzjrdf2kvc2b'
                && $event->prevSpaceRev === '3lzjrdf2kvc22';
        });
    }

    /**
     * The first write in a space has no previous revision, which is not a gap.
     */
    public function test_a_first_write_follows_nothing(): void
    {
        $this->notify(self::WRITE, $this->write(['prevSpaceRev' => null]))->assertOk();

        Event::assertDispatched(SpaceWriteNotified::class, fn (SpaceWriteNotified $event) => $event->follows(null)
            && ! $event->follows('3lzjrdf2kvc22'));
    }

    public function test_a_deletion_becomes_an_event(): void
    {
        $this->notify(self::DELETED, ['space' => (string) $this->space()])->assertOk();

        Event::assertDispatched(SpaceDeletedNotified::class, fn (SpaceDeletedNotified $event) => (string) $event->space === (string) $this->space());
    }

    /**
     * The middleware proves *a* service signed for this method, but not that
     * it governs the space in question. Without this check anyone could tell
     * the application to drop a space.
     */
    public function test_a_caller_that_is_not_the_spaces_authority_is_refused(): void
    {
        $this->notify(self::DELETED, ['space' => (string) $this->space()], issuer: 'did:plc:someone-else')
            ->assertStatus(401)
            ->assertJson(['error' => 'AuthRequired']);

        Event::assertNotDispatched(SpaceDeletedNotified::class);
    }

    public function test_the_authority_may_identify_itself_with_a_fragment(): void
    {
        $this->notify(self::WRITE, $this->write(), issuer: self::AUTHORITY.'#atproto_pds')->assertOk();

        Event::assertDispatched(SpaceWriteNotified::class);
    }

    /**
     * A token minted for one method is not spendable at the other.
     */
    public function test_a_token_for_the_other_method_is_refused(): void
    {
        $this->notify(self::DELETED, ['space' => (string) $this->space()], method: self::WRITE)
            ->assertStatus(401);

        Event::assertNotDispatched(SpaceDeletedNotified::class);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->postJson('/xrpc/'.self::WRITE, $this->write())
            ->assertStatus(401)
            ->assertJson(['error' => 'AuthMissing']);

        Event::assertNotDispatched(SpaceWriteNotified::class);
    }

    public function test_a_malformed_write_is_rejected(): void
    {
        $this->notify(self::WRITE, $this->write(['data' => 'not bytes']))
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest']);

        $this->notify(self::WRITE, $this->write(['repo' => 'alice']))
            ->assertStatus(400);

        $this->notify(self::WRITE, $this->write(['space' => 'at://nope']))
            ->assertStatus(400);

        Event::assertNotDispatched(SpaceWriteNotified::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function write(array $overrides = []): array
    {
        return array_filter(array_merge([
            'space' => (string) $this->space(),
            'repo' => self::MEMBER,
            'repoRev' => '3lzjrdf2kvc2a',
            // Unpadded, as atproto writes bytes.
            'data' => ['$bytes' => rtrim(base64_encode(str_repeat("\x01", 32)), '=')],
            'spaceRev' => '3lzjrdf2kvc2b',
            'prevSpaceRev' => '3lzjrdf2kvc22',
        ], $overrides), fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function notify(string $path, array $body, ?string $issuer = null, ?string $method = null)
    {
        $jwt = $this->app->make(ServiceAuth::class)->mint(
            $issuer ?? self::AUTHORITY,
            self::AUDIENCE,
            $method ?? $path,
            $this->signer(),
        );

        return $this->postJson('/xrpc/'.$path, $body, ['Authorization' => 'Bearer '.$jwt]);
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
