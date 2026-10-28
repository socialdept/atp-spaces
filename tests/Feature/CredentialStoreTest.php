<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\RequestSignature;
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

    protected function credential(?int $expiresIn = null, ?CredentialKey $key = null): SpaceCredential
    {
        $key ??= CredentialKey::generate();

        return new SpaceCredential(
            $this->space(),
            self::USER,
            SpaceToken::parse(SpaceTokenType::Credential, $this->mintCredential(self::AUTHORITY, (string) $this->space(), $key, $expiresIn)),
            $key,
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
        $this->assertSame($original->key->didKey(), $restored->key->didKey());
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
     * A credential cached in the DPoP era has no did:key binding, and must be
     * dropped so a fresh one is minted rather than presented and refused.
     */
    public function test_a_credential_cached_in_the_old_format_is_dropped(): void
    {
        $this->assertNull(SpaceCredential::fromArray([
            'space' => (string) $this->space(),
            'userDid' => self::USER,
            'credential' => $this->credential()->jwt(),
            'keypair' => CredentialKey::generate()->toPem(),
        ]));
    }

    /**
     * The headers a repo host expects: the credential under its own scheme,
     * the audience, and a signature over both.
     */
    public function test_it_builds_request_headers(): void
    {
        $credential = $this->credential();
        $headers = $credential->headers('did:plc:member');

        $this->assertSame('Atproto-Space-Credential '.$credential->jwt(), $headers['Authorization']);
        $this->assertSame('did:plc:member', $headers['Atproto-Space-Audience']);
        $this->assertSame('atproto-space-credential=("authorization" "atproto-space-audience");tag="atproto-space-credential"', $headers['Signature-Input']);
        $this->assertMatchesRegularExpression('/^atproto-space-credential=:[A-Za-z0-9+\/]+={0,2}:$/', $headers['Signature']);
    }

    /**
     * The exact bytes a repo host rebuilds and checks against cnf.kid.
     */
    public function test_the_signature_covers_the_credential_and_audience(): void
    {
        $credential = $this->credential();
        $headers = $credential->headers('did:plc:member');

        $base = "\"authorization\": Atproto-Space-Credential {$credential->jwt()}\n"
            ."\"atproto-space-audience\": did:plc:member\n"
            .'"@signature-params": ("authorization" "atproto-space-audience");tag="atproto-space-credential"';
        $signature = base64_decode(substr($headers['Signature'], strlen('atproto-space-credential=:'), -1), true);

        $this->assertSame(64, strlen($signature), 'Raw r || s, not DER.');
        $this->assertTrue((new \SocialDept\AtpSupport\Crypto\SignatureVerifier())->verify($credential->token->confirmationKey(), $base, $signature, allowMalleable: true));
        $this->assertFalse((new \SocialDept\AtpSupport\Crypto\SignatureVerifier())->verify($credential->token->confirmationKey(), str_replace('did:plc:member', 'did:plc:other', $base), $signature, allowMalleable: true), 'A request for another audience does not verify.');
        $this->assertSame($base, RequestSignature::base('Atproto-Space-Credential '.$credential->jwt(), 'did:plc:member', '("authorization" "atproto-space-audience");tag="atproto-space-credential"'));
    }
}
