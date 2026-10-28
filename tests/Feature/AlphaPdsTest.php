<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Enums\SimpleSpaceNsid;
use SocialDept\AtpSpaces\Enums\SpaceNsid;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Tests\TestCase;

/**
 * Checks this package's assumptions against Bluesky's public alpha PDS.
 *
 * Read-only and unauthenticated: it confirms the endpoints exist and reject us
 * the way the lexicons say they should. Nothing here creates records, so it is
 * safe to run against the live service — but it does need the network, hence
 * the integration group.
 *
 *     vendor/bin/phpunit --group integration
 *
 * A failure means the alpha service changed, not that this code broke.
 */
#[Group('integration')]
class AlphaPdsTest extends TestCase
{
    protected const HOST = 'https://spaces-alpha.host.bsky.network';
    protected const ACCOUNT = 'did:plc:3znwyhr2wogfy4nyhy4daii4';

    public function test_the_alpha_pds_serves_the_space_endpoints(): void
    {
        // Params are validated before auth, so a bare call names the missing
        // key — which proves the deployed lexicon matches the one we vendored.
        foreach ([
            SpaceNsid::GetDelegationToken,
            SpaceNsid::ListRepos,
            SimpleSpaceNsid::GetSpace,
            SimpleSpaceNsid::ListMembers,
        ] as $method) {
            $response = Http::timeout(30)->acceptJson()->get(self::HOST.'/xrpc/'.$method->value);

            $this->assertSame('InvalidRequest', $response->json()['error'] ?? null, $method->value);
            $this->assertStringContainsString(
                'Missing required key "space"',
                (string) ($response->json()['message'] ?? ''),
                $method->value,
            );
        }
    }

    /**
     * The authority takes the credential request in the body, not in an
     * Authorization header.
     */
    public function test_the_authority_takes_the_request_in_the_body(): void
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withBody('{}', 'application/json')
            ->post(self::HOST.'/xrpc/'.SpaceNsid::GetSpaceCredential->value);

        $this->assertSame(400, $response->status());
        $this->assertSame('InvalidRequest', $response->json()['error'] ?? null);
        $this->assertStringContainsString('Missing required key "space"', (string) ($response->json()['message'] ?? ''));
    }

    /**
     * Without a delegation token, a space that is not public reads as not
     * found, so a stranger cannot learn it exists.
     */
    public function test_a_space_that_is_not_public_is_not_found_without_a_token(): void
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->post(self::HOST.'/xrpc/'.SpaceNsid::GetSpaceCredential->value, [
                'space' => (string) SpaceRef::make(self::ACCOUNT, 'com.example.forum', 'nope'),
                'credentialKid' => CredentialKey::generate()->didKey(),
            ]);

        $this->assertSame('SpaceNotFound', $response->json()['error'] ?? null);
    }

    /**
     * The endpoints exist and require auth, rather than being unimplemented.
     */
    public function test_the_write_endpoints_require_authentication(): void
    {
        foreach ([SpaceNsid::CreateRecord, SimpleSpaceNsid::CreateSpace] as $method) {
            $response = Http::timeout(30)
                ->acceptJson()
                ->post(self::HOST.'/xrpc/'.$method->value, []);

            $this->assertSame(401, $response->status(), $method->value);
            $this->assertSame('AuthMissing', $response->json()['error'] ?? null, $method->value);
        }
    }

    /**
     * The live version of the fallback the resolver treats as normal.
     */
    public function test_it_resolves_a_real_account_as_a_space_authority(): void
    {
        $space = SpaceRef::make(self::ACCOUNT, 'com.example.forum', 'main');
        $authority = $this->app->make(AuthorityResolver::class)->forSpace($space);

        $this->assertSame(self::HOST, $authority->endpoint);
        $this->assertSame('#atproto', $authority->signingKeyId);
        $this->assertStringStartsWith('did:key:', (string) $authority->signingKey);
        $this->assertSame(self::ACCOUNT.'#atproto_pds', $authority->audience());
    }
}
