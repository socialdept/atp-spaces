<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use SocialDept\AtpSpaces\Auth\DpopKeypair;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Cache\CacheCredentialStore;
use SocialDept\AtpSpaces\Cache\NullCredentialStore;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Tests\MintsTokens;
use SocialDept\AtpSpaces\Tests\TestCase;

class CredentialStoreTest extends TestCase
{
    use MintsTokens;

    protected const AUTHORITY = 'did:plc:authority';
    protected const USER = 'did:plc:user';

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    protected function credential(?int $expiresIn = null, ?DpopKeypair $keypair = null): SpaceCredential
    {
        $keypair ??= DpopKeypair::generate();

        return new SpaceCredential(
            $this->space(),
            self::USER,
            SpaceToken::parse(SpaceTokenType::Credential, $this->mintToken(
                SpaceTokenType::Credential,
                self::AUTHORITY,
                (string) $this->space(),
                dpopJkt: $keypair->thumbprint(),
                expiresIn: $expiresIn,
            )),
            $keypair,
        );
    }

    protected function store(): CacheCredentialStore
    {
        return new CacheCredentialStore($this->app->make('cache')->store());
    }

    /**
     * A credential without its key is unusable, so both must survive the trip.
     */
    public function test_a_credential_survives_the_cache_with_its_key(): void
    {
        $original = $this->credential();
        $store = $this->store();

        $store->put($original);
        $restored = $store->get($this->space(), self::USER);

        $this->assertNotNull($restored);
        $this->assertSame($original->jwt(), $restored->jwt());
        $this->assertSame($original->keypair->thumbprint(), $restored->keypair->thumbprint());
        $this->assertTrue($restored->isCorrectlyBound());
    }

    public function test_an_absent_credential_reads_as_null(): void
    {
        $this->assertNull($this->store()->get($this->space(), self::USER));
    }

    public function test_a_credential_can_be_forgotten(): void
    {
        $store = $this->store();
        $store->put($this->credential());
        $store->forget($this->space(), self::USER);

        $this->assertNull($store->get($this->space(), self::USER));
    }

    /**
     * A store must never hand back something it cannot outlive.
     */
    public function test_an_expiring_credential_is_not_stored(): void
    {
        $store = $this->store();
        $store->put($this->credential(expiresIn: 5));

        $this->assertNull($store->get($this->space(), self::USER));
    }

    public function test_credentials_are_scoped_to_space_and_user(): void
    {
        $store = $this->store();
        $store->put($this->credential());

        $this->assertNull($store->get($this->space(), 'did:plc:someone-else'));
        $this->assertNull($store->get(
            SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'other'),
            self::USER,
        ));
    }

    /**
     * A DID is an identifier worth not leaving in plaintext cache keys.
     */
    public function test_cache_keys_do_not_contain_the_did(): void
    {
        $store = new CacheCredentialStore($this->app->make('cache')->store(), 'test-prefix:');
        $store->put($this->credential());

        $method = new \ReflectionMethod($store, 'key');
        $key = $method->invoke($store, $this->space(), self::USER);

        $this->assertStringStartsWith('test-prefix:', $key);
        $this->assertStringNotContainsString(self::USER, $key);
        $this->assertStringNotContainsString('com.example.forum', $key);
    }

    public function test_the_null_store_keeps_nothing(): void
    {
        $store = new NullCredentialStore();
        $store->put($this->credential());

        $this->assertNull($store->get($this->space(), self::USER));
    }

    public function test_a_corrupted_entry_reads_as_null(): void
    {
        $this->app->make('cache')->store()->put(
            'atp-spaces:credential:'.hash('sha256', (string) $this->space().'|'.self::USER),
            ['space' => 'garbage', 'credential' => 'nope', 'keypair' => 'nope'],
            600,
        );

        $this->assertNull($this->store()->get($this->space(), self::USER));
    }

    public function test_the_configured_store_is_bound(): void
    {
        $this->assertInstanceOf(CacheCredentialStore::class, $this->app->make(CredentialStore::class));
    }

    public function test_caching_can_be_disabled(): void
    {
        $this->app['config']->set('atp-spaces.credentials.cache', false);
        $this->app->forgetInstance(CredentialStore::class);

        $this->assertInstanceOf(NullCredentialStore::class, $this->app->make(CredentialStore::class));
    }

    /**
     * The headers a repo host expects: the credential under the DPoP scheme,
     * plus a proof for that exact request.
     */
    public function test_it_builds_request_headers(): void
    {
        $credential = $this->credential();
        $headers = $credential->headers('GET', 'https://pds.example/xrpc/com.atproto.space.getRecord');

        $this->assertSame('DPoP '.$credential->jwt(), $headers['Authorization']);
        $this->assertNotEmpty($headers['DPoP']);
    }
}
