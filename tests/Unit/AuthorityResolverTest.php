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
     * The DID document of the account created on Bluesky's alpha PDS, as it
     * reads since the October 22 release: its `#atproto` key, the
     * `#atproto_kem` key readers use for MACs, and its PDS.
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
            ], [
                'id' => 'did:plc:3znwyhr2wogfy4nyhy4daii4#atproto_kem',
                'type' => 'Multikey',
                'controller' => 'did:plc:3znwyhr2wogfy4nyhy4daii4',
                'publicKeyMultibase' => 'zDnaerDaTF5BXEavCrfRZEk316dpbLsfPDZ3WJ5hRTPFU2169',
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

    public function test_an_authority_is_its_pds_and_its_atproto_key(): void
    {
        $authority = $this->resolver()->fromDocument($this->alphaPdsDocument());

        $this->assertSame('https://spaces-alpha.host.bsky.network', $authority->endpoint);
        $this->assertSame('#atproto', $authority->signingKeyId);
        $this->assertSame(
            'did:key:zQ3shRsCQBcwtEg6XySQtTqztts9qpT28JsQNQQrqSHspMT7r',
            $authority->signingKey,
            'The #atproto key, never #atproto_kem beside it.',
        );
    }

    public function test_the_audience_is_the_pds_service(): void
    {
        $authority = $this->resolver()->fromDocument($this->alphaPdsDocument());

        $this->assertSame('did:plc:3znwyhr2wogfy4nyhy4daii4#atproto_pds', $authority->audience());
    }

    /**
     * The dedicated entries of earlier drafts are ignored, so an old DID
     * document cannot send credential requests or trust somewhere else.
     */
    public function test_the_old_dedicated_space_entries_are_ignored(): void
    {
        $authority = $this->resolver()->fromDocument(DidDocument::fromArray([
            'id' => 'did:web:spaces.example.com',
            'verificationMethod' => [
                ['id' => 'did:web:spaces.example.com#atproto_space', 'publicKeyMultibase' => 'zSpaceKey'],
                ['id' => 'did:web:spaces.example.com#atproto', 'publicKeyMultibase' => 'zAccountKey'],
            ],
            'service' => [
                ['id' => '#atproto_space_host', 'type' => 'AtprotoSpaceHost', 'serviceEndpoint' => 'https://spaces.example.com/'],
                ['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.com'],
            ],
        ]));

        $this->assertSame('https://pds.example.com', $authority->endpoint);
        $this->assertSame('did:key:zAccountKey', $authority->signingKey);
    }

    /**
     * `#atproto_space` must not be mistaken for `#atproto`, or the wrong key
     * verifies credentials.
     */
    public function test_fragment_matching_does_not_confuse_similar_names(): void
    {
        $this->expectException(AuthorityResolutionException::class);
        $this->expectExceptionMessage('publishes no signing key');

        $this->resolver()->fromDocument(DidDocument::fromArray([
            'id' => 'did:plc:abc',
            'verificationMethod' => [['id' => 'did:plc:abc#atproto_space', 'publicKeyMultibase' => 'zSpaceKey']],
            'service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => 'https://pds.example.com',
            ]],
        ]));
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
        $this->expectExceptionMessage('publishes no PDS endpoint');

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
