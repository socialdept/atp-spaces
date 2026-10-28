<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Http\Client\Factory as HttpFactory;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use SocialDept\AtpSpaces\Auth\CredentialBroker;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Authority\SpaceAuthority;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSpaces\Crypto\Kem;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\Repo\Commit;
use SocialDept\AtpSpaces\Repo\Mac;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Cid;
use SocialDept\AtpSpaces\Support\Drisl\Drisl;
use SocialDept\AtpSpaces\Support\StructuredFields;
use SocialDept\AtpSpaces\Tests\MintsTokens;
use SocialDept\AtpSpaces\Tests\TestCase;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Resolver;

class SpaceReaderTest extends TestCase
{
    use MintsTokens;

    protected const AUTHORITY = 'did:plc:authority';
    protected const MEMBER = 'did:plc:member';
    protected const MEMBER_PDS = 'https://member-pds.example';
    protected const AUTHORITY_HOST = 'https://authority.example';

    protected ?PrivateKey $kemKey = null;

    /** @var array<int, string> The member's #atproto_kem keys, newest last, as DID document reads return them */
    protected array $publishedKemKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kemKey = EC::createKey('secp256r1');
        $this->publishedKemKeys = [$this->didKeyOf($this->kemKey)];
    }

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
        $resolver->method('resolveDid')->willReturnCallback(fn (string $did, bool $useCache = true) => DidDocument::fromArray([
            'id' => $did,
            'verificationMethod' => [[
                'id' => $did.'#atproto_kem',
                'publicKeyMultibase' => substr($useCache ? $this->publishedKemKeys[0] : end($this->publishedKemKeys), strlen('did:key:')),
            ]],
        ]));

        return new SpaceReader($this->credential(), $authorities, $resolver, $http, broker: $broker);
    }

    protected function didKeyOf(PrivateKey $key): string
    {
        $point = $key->getPublicKey()->getEncodedCoordinates();

        return (string) new \SocialDept\AtpSupport\Crypto\DidKey('p256', (ord($point[64]) % 2 === 0 ? "\x02" : "\x03").substr($point, 1, 32));
    }

    /**
     * The MAC a real repo host would send: the shared secret from the
     * request's key share and the author's KEM key.
     */
    protected function macFor($request, string $type, callable $mac): string
    {
        $want = StructuredFields::parseDictionary($request->header(Mac::WANT_HEADER)[0] ?? '');
        $this->assertSame($type, $want['type'] ?? null);
        $this->assertSame(1, $want['ver'] ?? null);

        $secret = Kem::decapsulate($this->kemKey, $want['key_share']);

        return 'type="'.$type.'", ver=1, mac=:'.base64_encode($mac($secret)).':';
    }

    /**
     * @return array<string, mixed>
     */
    protected function commitJson(string $data, string $repo = self::MEMBER, ?string $space = null): array
    {
        return ['ver' => 1, 'repo' => $repo, 'space' => $space ?? (string) $this->space(), 'rev' => '3lkq2xhbc2s2j', 'data' => ['$bytes' => base64_encode($data)]];
    }

    protected function commitOf(array $json): Commit
    {
        return SpaceReader::decodeCommit($json);
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

            return $http->response(['records' => []]);
        });

        $this->reader($http)->listRecords(self::MEMBER);

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
        $http->fake(function ($request) use ($http) {
            $this->assertNotEmpty($request->header(Mac::WANT_HEADER), 'Every oplog page asks for a MAC.');

            return $http->response([
                'ops' => [
                    ['collection' => 'com.example.thread', 'rkey' => '1', 'cid' => 'bafyA'],
                    ['collection' => 'com.example.thread', 'rkey' => '2', 'cid' => 'bafyC', 'prev' => 'bafyB'],
                    ['collection' => 'com.example.reply', 'rkey' => '3', 'prev' => 'bafyD'],
                ],
                'cursor' => 'next',
            ]);
        });

        $result = $this->reader($http)->listRepoOps(self::MEMBER);

        $this->assertCount(3, $result['ops']);
        $this->assertTrue($result['ops'][0]->isCreate());
        $this->assertTrue($result['ops'][1]->isUpdate());
        $this->assertTrue($result['ops'][2]->isDelete());
        $this->assertSame('next', $result['cursor']);

        // Absent until the response reaches the head of the log, and then no MAC is needed.
        $this->assertNull($result['commit']);
    }

    /**
     * The commit at the head of the log comes back only once its MAC checks
     * out, and its digest is what local state compares against.
     */
    public function test_the_commit_at_the_head_of_the_oplog_is_verified(): void
    {
        $repo = (new RepoCommit())->add('com.example.thread', '1', 'bafyA');
        $json = $this->commitJson($repo->digest());

        $http = new HttpFactory();
        $http->fake(fn ($request) => $http->response(['ops' => [], 'commit' => $json], 200, [
            Mac::HEADER => $this->macFor($request, Mac::COMMIT, fn ($secret) => Mac::forCommit($secret, $this->commitOf($json))),
        ]));

        $commit = $this->reader($http)->listRepoOps(self::MEMBER)['commit'];

        $this->assertSame(self::MEMBER, $commit->repo);
        $this->assertSame($repo->digest(), $commit->data);
        $this->assertTrue($repo->matches($commit));
    }

    public function test_the_latest_commit_is_verified(): void
    {
        $json = $this->commitJson(str_repeat("\x05", 32));

        $http = new HttpFactory();
        $http->fake(fn ($request) => $http->response(['commit' => $json], 200, [
            Mac::HEADER => $this->macFor($request, Mac::COMMIT, fn ($secret) => Mac::forCommit($secret, $this->commitOf($json))),
        ]));

        $this->assertSame(str_repeat("\x05", 32), $this->reader($http)->getLatestCommit(self::MEMBER)->data);
    }

    /**
     * A commit without a MAC, or with one keyed by another secret, is never
     * returned.
     */
    public function test_a_commit_without_a_valid_mac_is_refused(): void
    {
        $json = $this->commitJson(str_repeat("\x05", 32));

        $http = new HttpFactory();
        $http->fake(fn () => $http->response(['commit' => $json], 200, [
            Mac::HEADER => 'type="atproto-space-repo", ver=1, mac=:'.base64_encode(str_repeat("\0", 32)).':',
        ]));

        $this->expectException(RepoVerificationException::class);
        $this->expectExceptionMessage('commit mac does not match');

        $this->reader($http)->getLatestCommit(self::MEMBER);
    }

    public function test_a_commit_for_another_repo_is_refused(): void
    {
        $json = $this->commitJson(str_repeat("\x05", 32), repo: 'did:plc:someone-else');

        $http = new HttpFactory();
        $http->fake(fn ($request) => $http->response(['commit' => $json], 200, [
            Mac::HEADER => $this->macFor($request, Mac::COMMIT, fn ($secret) => Mac::forCommit($secret, $this->commitOf($json))),
        ]));

        $this->expectException(RepoVerificationException::class);
        $this->expectExceptionMessage('commit is for another repo');

        $this->reader($http)->getLatestCommit(self::MEMBER);
    }

    /**
     * When the author's KEM key rotated, the cached DID document is stale and
     * the first MAC fails. The reader rereads it and tries once more, with a
     * fresh key share.
     */
    public function test_a_rotated_kem_key_is_picked_up_on_retry(): void
    {
        $this->kemKey = EC::createKey('secp256r1');
        $this->publishedKemKeys[] = $this->didKeyOf($this->kemKey);
        $json = $this->commitJson(str_repeat("\x05", 32));
        $shares = [];

        $http = new HttpFactory();
        $http->fake(function ($request) use ($http, $json, &$shares) {
            $shares[] = StructuredFields::parseDictionary($request->header(Mac::WANT_HEADER)[0])['key_share'];

            return $http->response(['commit' => $json], 200, [
                Mac::HEADER => $this->macFor($request, Mac::COMMIT, fn ($secret) => Mac::forCommit($secret, $this->commitOf($json))),
            ]);
        });

        $this->reader($http)->getLatestCommit(self::MEMBER);

        $this->assertCount(2, $shares);
        $this->assertNotSame($shares[0], $shares[1], 'Each request has its own key share.');
    }

    /**
     * A record comes back only once its MAC checks out and its value
     * encodes to its CID.
     */
    public function test_a_record_is_checked_against_its_mac_and_cid(): void
    {
        $value = ['$type' => 'com.example.thread', 'text' => 'hello'];
        $uri = (string) $this->space()->record(self::MEMBER, 'com.example.thread', '3abc');
        $cid = Cid::forBytes(Drisl::encode($value));

        $http = new HttpFactory();
        $http->fake(fn ($request) => $http->response(['uri' => $uri, 'cid' => (string) $cid, 'value' => $value], 200, [
            Mac::HEADER => $this->macFor($request, Mac::RECORD, fn ($secret) => Mac::forRecord($secret, $uri, $cid)),
        ]));

        $this->assertSame('hello', $this->reader($http)->getRecord(self::MEMBER, 'com.example.thread', '3abc')['value']['text']);
    }

    /**
     * A host that sends a valid MAC over the CID, but a value that is not the
     * one the CID names, is caught by the CID.
     */
    public function test_a_record_whose_value_does_not_match_its_cid_is_refused(): void
    {
        $uri = (string) $this->space()->record(self::MEMBER, 'com.example.thread', '3abc');
        $cid = Cid::forBytes(Drisl::encode(['$type' => 'com.example.thread', 'text' => 'hello']));

        $http = new HttpFactory();
        $http->fake(fn ($request) => $http->response(['uri' => $uri, 'cid' => (string) $cid, 'value' => ['$type' => 'com.example.thread', 'text' => 'tampered']], 200, [
            Mac::HEADER => $this->macFor($request, Mac::RECORD, fn ($secret) => Mac::forRecord($secret, $uri, $cid)),
        ]));

        $this->expectException(RepoVerificationException::class);
        $this->expectExceptionMessage('does not match its CID');

        $this->reader($http)->getRecord(self::MEMBER, 'com.example.thread', '3abc');
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

        $this->reader($http)->listRecords(self::MEMBER);
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
            ->listRecords(self::MEMBER);
    }
}
