<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Exceptions\AuthorityResolutionException;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Resolver;

class AuthorityResolverTest extends TestCase
{
    /**
     * The DID document of the account created on Bluesky's alpha PDS, verbatim.
     *
     * It publishes neither `#atproto_space` nor `#atproto_space_host`, which is
     * the whole point: for any authority that is an ordinary account on a PDS —
     * that is, nearly all of them — the spec's fallback is the only path.
     */
    protected function alphaPdsDocument(): DidDocument
    {
        return DidDocument::fromArray([
            'id' => 'did:plc:3znwyhr2wogfy4nyhy4daii4',
            'alsoKnownAs' => ['at://dad.spaces-alpha.bsky.network'],
            'verificationMethod' => [[
                'id' => 'did:plc:3znwyhr2wogfy4nyhy4daii4#atproto',
                'type' => 'Multikey',
                'controller' => 'did:plc:3znwyhr2wogfy4nyhy4daii4',
                'publicKeyMultibase' => 'zQ3shRsCQBcwtEg6XySQtTqztts9qpT28JsQNQQrqSHspMT7r',
            ]],
            'service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => 'https://spaces-alpha.host.bsky.network',
            ]],
        ]);
    }

    protected function resolver(): AuthorityResolver
    {
        // fromDocument() never touches the injected resolver.
        return new AuthorityResolver($this->createStub(Resolver::class));
    }

    public function test_it_falls_back_to_the_account_pds_and_key(): void
    {
        $authority = $this->resolver()->fromDocument($this->alphaPdsDocument());

        $this->assertSame('https://spaces-alpha.host.bsky.network', $authority->endpoint);
        $this->assertSame('#atproto', $authority->signingKeyId);
        $this->assertSame(
            'did:key:zQ3shRsCQBcwtEg6XySQtTqztts9qpT28JsQNQQrqSHspMT7r',
            $authority->signingKey,
        );
        $this->assertFalse($authority->dedicatedEndpoint);
        $this->assertFalse($authority->dedicatedKey);
        $this->assertTrue($authority->isPdsHosted());
    }

    /**
     * The audience is an identifier, not an address: it is always the
     * `#atproto_space_host` fragment even when no such entry is published.
     */
    public function test_the_audience_is_a_fragment_even_without_a_dedicated_host(): void
    {
        $authority = $this->resolver()->fromDocument($this->alphaPdsDocument());

        $this->assertSame('did:plc:3znwyhr2wogfy4nyhy4daii4#atproto_space_host', $authority->audience());
    }

    public function test_it_prefers_dedicated_space_entries(): void
    {
        $authority = $this->resolver()->fromDocument(DidDocument::fromArray([
            'id' => 'did:web:spaces.example.com',
            'verificationMethod' => [
                [
                    'id' => 'did:web:spaces.example.com#atproto',
                    'publicKeyMultibase' => 'zAccountKey',
                ],
                [
                    'id' => 'did:web:spaces.example.com#atproto_space',
                    'publicKeyMultibase' => 'zSpaceKey',
                ],
            ],
            'service' => [
                [
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://pds.example.com',
                ],
                [
                    'id' => '#atproto_space_host',
                    'type' => 'AtprotoSpaceHost',
                    'serviceEndpoint' => 'https://spaces.example.com/',
                ],
            ],
        ]));

        $this->assertSame('https://spaces.example.com', $authority->endpoint);
        $this->assertSame('#atproto_space', $authority->signingKeyId);
        $this->assertSame('did:key:zSpaceKey', $authority->signingKey);
        $this->assertTrue($authority->dedicatedEndpoint);
        $this->assertTrue($authority->dedicatedKey);
        $this->assertFalse($authority->isPdsHosted());
    }

    /**
     * `#atproto_space` must not be mistaken for `#atproto`, or the wrong key
     * verifies credentials.
     */
    public function test_fragment_matching_does_not_confuse_similar_names(): void
    {
        $authority = $this->resolver()->fromDocument(DidDocument::fromArray([
            'id' => 'did:plc:abc',
            'verificationMethod' => [[
                'id' => 'did:plc:abc#atproto_space',
                'publicKeyMultibase' => 'zSpaceKey',
            ]],
            'service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => 'https://pds.example.com',
            ]],
        ]));

        $this->assertSame('#atproto_space', $authority->signingKeyId);
        $this->assertSame('did:key:zSpaceKey', $authority->signingKey);
    }

    public function test_it_builds_xrpc_urls(): void
    {
        $authority = $this->resolver()->fromDocument($this->alphaPdsDocument());

        $this->assertSame(
            'https://spaces-alpha.host.bsky.network/xrpc/com.atproto.space.getSpaceCredential',
            $authority->xrpc('com.atproto.space.getSpaceCredential'),
        );
    }

    public function test_it_fails_without_any_endpoint(): void
    {
        $this->expectException(AuthorityResolutionException::class);
        $this->expectExceptionMessage('neither a space host nor a PDS endpoint');

        $this->resolver()->fromDocument(DidDocument::fromArray([
            'id' => 'did:plc:abc',
            'verificationMethod' => [['id' => '#atproto', 'publicKeyMultibase' => 'zKey']],
        ]));
    }

    public function test_it_fails_without_a_signing_key(): void
    {
        $this->expectException(AuthorityResolutionException::class);
        $this->expectExceptionMessage('publishes no signing key');

        $this->resolver()->fromDocument(DidDocument::fromArray([
            'id' => 'did:plc:abc',
            'service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => 'https://pds.example.com',
            ]],
        ]));
    }
}
