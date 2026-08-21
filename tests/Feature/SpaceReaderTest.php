<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Http\Client\Factory as HttpFactory;
use SocialDept\AtpSpaces\Auth\DpopKeypair;
use SocialDept\AtpSpaces\Auth\Jwk;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Authority\SpaceAuthority;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Tests\MintsTokens;
use SocialDept\AtpSpaces\Tests\TestCase;
use SocialDept\AtpSupport\Resolver;

class SpaceReaderTest extends TestCase
{
    use MintsTokens;

    protected const AUTHORITY = 'did:plc:authority';
    protected const MEMBER = 'did:plc:member';
    protected const MEMBER_PDS = 'https://member-pds.example';
    protected const AUTHORITY_HOST = 'https://authority.example';

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    protected function reader(HttpFactory $http): SpaceReader
    {
        $keypair = DpopKeypair::generate();

        $credential = new SpaceCredential(
            $this->space(),
            'did:plc:user',
            SpaceToken::parse(
                SpaceTokenType::Credential,
                $this->mintCredential(self::AUTHORITY, (string) $this->space(), $keypair),
            ),
            $keypair,
        );

        $authorities = $this->createStub(AuthorityResolver::class);
        $authorities->method('forSpace')->willReturn(new SpaceAuthority(
            did: self::AUTHORITY,
            endpoint: self::AUTHORITY_HOST,
            signingKeyId: '#atproto',
        ));

        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolvePds')->willReturn(self::MEMBER_PDS);

        return new SpaceReader($credential, $authorities, $resolver, $http);
    }

    /**
     * A credential is presented under the DPoP scheme, not as a bearer token,
     * and every request carries a fresh proof naming that exact URL.
     */
    public function test_requests_present_the_credential_under_the_dpop_scheme(): void
    {
        $seen = [];
        $http = new HttpFactory();
        $http->fake(function ($request) use ($http, &$seen) {
            $seen['authorization'] = $request->header('Authorization')[0] ?? '';
            $seen['dpop'] = $request->header('DPoP')[0] ?? '';
            $seen['url'] = $request->url();

            return $http->response(['uri' => 'at://x', 'value' => []]);
        });

        $this->reader($http)->getRecord(self::MEMBER, 'com.example.thread', '3abc');

        $this->assertStringStartsWith('DPoP ', $seen['authorization']);

        $payload = json_decode(Jwk::base64UrlDecode(explode('.', $seen['dpop'])[1]), true);
        $this->assertSame('GET', $payload['htm']);
        $this->assertSame(self::MEMBER_PDS.'/xrpc/com.atproto.space.getRecord', $payload['htu']);

        // The proof binds the exact credential being presented.
        $credential = substr($seen['authorization'], strlen('DPoP '));
        $this->assertSame(Jwk::base64UrlEncode(hash('sha256', $credential, true)), $payload['ath']);
    }

    /**
     * Reads go to each member's own host, because permissioned records are
     * never colocated on the authority.
     */
    public function test_record_reads_go_to_the_members_own_pds(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response(['records' => [], 'cursor' => null])]);

        $this->reader($http)->listRecords(self::MEMBER, 'com.example.thread');

        $http->assertSent(fn ($request) => str_starts_with($request->url(), self::MEMBER_PDS.'/xrpc/'));
    }

    /**
     * The roster is space-level state, so it comes from the authority.
     */
    public function test_listing_repos_goes_to_the_authority(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response(['repos' => [['did' => self::MEMBER]]])]);

        $result = $this->reader($http)->listRepos();

        $this->assertSame([['did' => self::MEMBER]], $result['repos']);
        $http->assertSent(fn ($request) => str_starts_with($request->url(), self::AUTHORITY_HOST.'/xrpc/'));
    }

    public function test_it_parses_an_oplog_into_operations(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response([
            'ops' => [
                ['collection' => 'com.example.thread', 'rkey' => '1', 'cid' => 'bafyA'],
                ['collection' => 'com.example.thread', 'rkey' => '2', 'cid' => 'bafyC', 'prev' => 'bafyB'],
                ['collection' => 'com.example.reply', 'rkey' => '3', 'prev' => 'bafyD'],
            ],
            'cursor' => 'next',
        ])]);

        $result = $this->reader($http)->listRepoOps(self::MEMBER);

        $this->assertCount(3, $result['ops']);
        $this->assertTrue($result['ops'][0]->isCreate());
        $this->assertTrue($result['ops'][1]->isUpdate());
        $this->assertTrue($result['ops'][2]->isDelete());
        $this->assertSame('next', $result['cursor']);

        // Absent until the response reaches the head of the log.
        $this->assertNull($result['commit']);
    }

    /**
     * Lexicon `bytes` arrive as `{"$bytes": "<base64>"}` over JSON, so a commit
     * is meaningless until every binary field is unwrapped.
     */
    public function test_it_decodes_the_binary_fields_of_a_commit(): void
    {
        $repo = RepoCommit::fromIndex(['com.example.thread/1' => 'bafyA']);
        $hash = $repo->digest();
        $ikm = random_bytes(32);

        $http = new HttpFactory();
        $http->fake(['*' => $http->response([
            'ops' => [],
            'commit' => [
                'ver' => 1,
                'hash' => ['$bytes' => base64_encode($hash)],
                'ikm' => ['$bytes' => base64_encode($ikm)],
                'sig' => ['$bytes' => base64_encode('sig-bytes')],
                'mac' => ['$bytes' => base64_encode('mac-bytes')],
                'rev' => '3lkq2xhbc2s2j',
            ],
        ])]);

        $commit = $this->reader($http)->listRepoOps(self::MEMBER)['commit'];

        $this->assertNotNull($commit);
        $this->assertSame($hash, $commit->hash);
        $this->assertSame($ikm, $commit->ikm);
        $this->assertSame('sig-bytes', $commit->sig);
        $this->assertSame('3lkq2xhbc2s2j', $commit->rev);

        // And the decoded hash is what our local state compares against.
        $this->assertTrue($repo->matches($commit));
    }

    public function test_it_returns_raw_bytes_for_a_blob(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response('binary-bytes', 200, ['Content-Type' => 'image/png'])]);

        $this->assertSame('binary-bytes', $this->reader($http)->getBlob(self::MEMBER, 'bafyblob'));
    }

    public function test_it_surfaces_a_host_error(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response(['error' => 'RepoNotFound', 'message' => 'no repo'], 400)]);

        $this->expectException(SpacesException::class);
        $this->expectExceptionMessage('RepoNotFound — no repo');

        $this->reader($http)->getRecord(self::MEMBER, 'com.example.thread', '3abc');
    }

    public function test_it_retries_once_when_the_host_demands_a_nonce(): void
    {
        $calls = 0;
        $http = new HttpFactory();
        $http->fake(function ($request) use (&$calls, $http) {
            $calls++;

            if ($calls === 1) {
                return $http->response(
                    ['error' => 'UseDpopNonce'],
                    401,
                    ['DPoP-Nonce' => 'host-nonce', 'Content-Type' => 'application/json'],
                );
            }

            $proof = $request->header('DPoP')[0];
            $payload = json_decode(Jwk::base64UrlDecode(explode('.', $proof)[1]), true);
            $this->assertSame('host-nonce', $payload['nonce']);

            return $http->response(['records' => []]);
        });

        $this->reader($http)->listRecords(self::MEMBER);

        $this->assertSame(2, $calls);
    }

    public function test_it_fails_when_a_member_publishes_no_pds(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response([])]);

        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolvePds')->willReturn(null);

        $authorities = $this->createStub(AuthorityResolver::class);

        $keypair = DpopKeypair::generate();
        $credential = new SpaceCredential(
            $this->space(),
            'did:plc:user',
            SpaceToken::parse(
                SpaceTokenType::Credential,
                $this->mintCredential(self::AUTHORITY, (string) $this->space(), $keypair),
            ),
            $keypair,
        );

        $this->expectException(SpacesException::class);
        $this->expectExceptionMessage('No PDS endpoint published');

        (new SpaceReader($credential, $authorities, $resolver, $http))
            ->getRecord(self::MEMBER, 'com.example.thread', '3abc');
    }
}
