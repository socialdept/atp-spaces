<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Http\Client\Factory as HttpFactory;
use SocialDept\AtpSpaces\Auth\CredentialBroker;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Authority\SpaceAuthority;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
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

    protected function credential(): SpaceCredential
    {
        $key = CredentialKey::generate();

        return new SpaceCredential(
            $this->space(),
            'did:plc:user',
            SpaceToken::parse(
                SpaceTokenType::Credential,
                $this->mintCredential(self::AUTHORITY, (string) $this->space(), $key),
            ),
            $key,
        );
    }

    protected function reader(HttpFactory $http, ?CredentialBroker $broker = null): SpaceReader
    {
        $authorities = $this->createStub(AuthorityResolver::class);
        $authorities->method('forSpace')->willReturn(new SpaceAuthority(
            did: self::AUTHORITY,
            endpoint: self::AUTHORITY_HOST,
        ));

        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolvePds')->willReturn(self::MEMBER_PDS);

        return new SpaceReader($this->credential(), $authorities, $resolver, $http, broker: $broker);
    }

    /**
     * A repo read is signed for the repo's owner, so the host serving it
     * cannot replay the request at another member's host.
     */
    public function test_a_repo_read_is_signed_for_the_repo_owner(): void
    {
        $seen = [];
        $http = new HttpFactory();
        $http->fake(function ($request) use ($http, &$seen) {
            $seen = array_map(fn ($values) => $values[0], $request->headers());

            return $http->response(['uri' => 'at://x', 'value' => []]);
        });

        $this->reader($http)->getRecord(self::MEMBER, 'com.example.thread', '3abc');

        $this->assertStringStartsWith('Atproto-Space-Credential ', $seen['Authorization']);
        $this->assertSame(self::MEMBER, $seen['Atproto-Space-Audience']);
        $this->assertStringStartsWith('atproto-space-credential=', $seen['Signature']);
        $this->assertArrayNotHasKey('DPoP', $seen);
    }

    /**
     * A call to the space host is signed for the authority's bare DID.
     */
    public function test_a_space_host_call_is_signed_for_the_authority(): void
    {
        $seen = [];
        $http = new HttpFactory();
        $http->fake(function ($request) use ($http, &$seen) {
            $seen = array_map(fn ($values) => $values[0], $request->headers());

            return $http->response(['repos' => []]);
        });

        $this->reader($http)->listRepos();

        $this->assertSame(self::AUTHORITY, $seen['Atproto-Space-Audience']);
    }

    /**
     * A revoked credential is never retried. It is dropped, so the next
     * reader mints a replacement if the user may still enter.
     */
    public function test_a_revoked_credential_is_forgotten_and_not_retried(): void
    {
        $calls = 0;
        $http = new HttpFactory();
        $http->fake(function () use ($http, &$calls) {
            $calls++;

            return $http->response(['error' => 'CredentialRevoked', 'message' => 'revoked'], 401);
        });

        $broker = $this->createMock(CredentialBroker::class);
        $broker->expects($this->once())->method('forget')->with($this->callback(fn ($space) => (string) $space === (string) $this->space()), 'did:plc:user');

        try {
            $this->reader($http, $broker)->listRecords(self::MEMBER);
            $this->fail('Expected a CredentialException.');
        } catch (CredentialException $e) {
            $this->assertTrue($e->isRevoked());
        }

        $this->assertSame(1, $calls);
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

    public function test_it_fails_when_a_member_publishes_no_pds(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response([])]);

        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolvePds')->willReturn(null);

        $authorities = $this->createStub(AuthorityResolver::class);

        $this->expectException(SpacesException::class);
        $this->expectExceptionMessage('No PDS endpoint published');

        (new SpaceReader($this->credential(), $authorities, $resolver, $http))
            ->getRecord(self::MEMBER, 'com.example.thread', '3abc');
    }
}
