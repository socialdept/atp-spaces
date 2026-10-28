<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSpaces\Management\AppAccess;
use SocialDept\AtpSpaces\Management\SpacePolicy;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;
use SocialDept\AtpSpaces\Tests\Support\OAuthActor;
use SocialDept\AtpSpaces\Tests\TestCase;

/**
 * The whole feature, against a real PDS.
 *
 * Creates a space, writes to it, reads **another member's** repo through a
 * credential, confirms a non-member is refused, and deletes everything. This is
 * the only test that proves spaces actually work rather than that the code is
 * self-consistent.
 *
 * It needs three OAuth-authorized accounts on a spaces-enabled PDS. App
 * passwords cannot be used — the endpoints require `ACCESS_FULL`, which
 * excludes both app-password scopes. Obtain tokens with:
 *
 *     php dev/oauth.php authorize <handle>     # prints a URL to approve
 *     php dev/oauth.php exchange <handle> <callback-url>
 *
 * Then point the suite at them and run:
 *
 *     ATP_SPACES_TOKENS=/path/to/tokens vendor/bin/phpunit --group integration
 *
 * Skips cleanly when no tokens are present, so it never blocks CI.
 *
 * **Each run leaves one empty repo shell on the member's account.** Deleting a
 * space does not remove the per-space repos its members opened by writing to
 * it, and no lexicon method removes one. They hold no records and cannot be
 * read (the authority answers `SpaceDeleted`), but they do accumulate in that
 * member's `listSpaces` — so run this against throwaway accounts.
 */
#[Group('integration')]
class SpaceLifecycleTest extends TestCase
{
    protected const SPACE_TYPE = 'com.socialdept.scratch.probe';
    protected const COLLECTION = 'com.socialdept.scratch.note';

    /** @var array<string, OAuthActor> */
    protected array $actors = [];

    protected ?SpaceRef $space = null;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = env('ATP_SPACES_TOKENS');

        if (! is_string($directory) || ! is_dir($directory)) {
            $this->markTestSkipped('Set ATP_SPACES_TOKENS to a directory of authorized accounts.');
        }

        $this->actors = OAuthActor::discover($directory);

        foreach (['dad', 'mom', 'son'] as $required) {
            if (! isset($this->actors[$required])) {
                $this->markTestSkipped("No authorized account found for \"{$required}\".");
            }
        }

        // Access tokens last an hour. Say so plainly, rather than letting every
        // assertion fail on a 401 that looks like a protocol change.
        foreach (glob(rtrim($directory, '/').'/tokens-*.json') ?: [] as $file) {
            if (! OAuthActor::isFresh($file)) {
                $this->markTestSkipped('Access tokens have expired — run `php dev/oauth.php refresh`.');
            }
        }
    }

    /**
     * Delete whatever the test created, however it ended.
     */
    protected function tearDown(): void
    {
        if ($this->space !== null) {
            foreach ($this->actors as $actor) {
                $actor->call('POST', 'com.atproto.space.deleteRecord', [
                    'space' => (string) $this->space,
                    'repo' => $actor->did,
                    'collection' => self::COLLECTION,
                    'rkey' => 'note-'.$actor->short(),
                ]);
            }

            $this->actors['dad']->call('POST', 'com.atproto.simplespace.deleteSpace', [
                'space' => (string) $this->space,
            ]);

            $this->space = null;
        }

        parent::tearDown();
    }

    public function test_a_space_gates_reads_to_its_members(): void
    {
        [$dad, $mom, $son] = [$this->actors['dad'], $this->actors['mom'], $this->actors['son']];

        // ── The owner creates a space and admits one member.
        [$status, $body] = $dad->call('POST', 'com.atproto.simplespace.createSpace', [
            'spaceType' => self::SPACE_TYPE,
            'readPolicy' => SpacePolicy::memberList()->toArray(),
            'writePolicy' => SpacePolicy::memberList()->toArray(),
            'appAccess' => AppAccess::open()->toArray(),
        ]);

        $this->assertSame(200, $status, 'createSpace: '.json_encode($body));

        $this->space = SpaceRef::parse((string) $body['uri']);
        $this->assertSame($dad->did, $this->space->authority);
        $this->assertSame(self::SPACE_TYPE, $this->space->type);

        [$status] = $dad->call('POST', 'com.atproto.simplespace.putMember', [
            'space' => (string) $this->space,
            'did' => $mom->did,
            'read' => true,
            'write' => true,
        ]);
        $this->assertSame(200, $status, 'putMember');

        // ── Both members write into their own repos.
        foreach ([$dad, $mom] as $author) {
            [$status, $body] = $author->call('POST', 'com.atproto.space.createRecord', [
                'space' => (string) $this->space,
                'repo' => $author->did,
                'collection' => self::COLLECTION,
                'rkey' => 'note-'.$author->short(),
                'record' => ['$type' => self::COLLECTION, 'text' => 'hello from '.$author->short()],
            ]);

            $this->assertSame(200, $status, "createRecord for {$author->short()}: ".json_encode($body));
        }

        // ── A member reads the *other* member's repo. This is the feature.
        $reader = $this->readerFor($mom);

        $records = $reader->listRecords($dad->did, self::COLLECTION);
        $this->assertCount(1, $records['records']);
        $this->assertSame('hello from dad', $records['records'][0]['value']['text']);

        $record = $reader->getRecord($mom->did, self::COLLECTION, 'note-mom');
        $this->assertSame('hello from mom', $record['value']['text']);

        // Reads are addressed per member, but the roster is space-level state.
        $repos = $reader->listRepos();
        $dids = array_map(fn ($entry) => $entry['did'] ?? $entry, $repos['repos']);
        $this->assertEqualsCanonicalizing([$dad->did, $mom->did], $dids);

        // ── A non-member is refused, and the refusal names the user perimeter.
        [$status, $body] = $this->exchange($son);
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertSame('UserNotAuthorized', $body['error'] ?? null);
    }

    /**
     * The commit a PDS sends, checked against its MAC, must match the set hash computed here.
     *
     * This is what proves the BLAKE3 port and the LtHash accumulator against
     * real data rather than against our own fixtures.
     */
    public function test_a_verified_commit_matches_a_locally_computed_set_hash(): void
    {
        [$dad, $mom] = [$this->actors['dad'], $this->actors['mom']];

        [$status, $body] = $dad->call('POST', 'com.atproto.simplespace.createSpace', [
            'spaceType' => self::SPACE_TYPE,
            'readPolicy' => SpacePolicy::memberList()->toArray(),
            'writePolicy' => SpacePolicy::memberList()->toArray(),
            'appAccess' => AppAccess::open()->toArray(),
        ]);
        $this->assertSame(200, $status, 'createSpace: '.json_encode($body));

        $this->space = SpaceRef::parse((string) $body['uri']);
        $dad->call('POST', 'com.atproto.simplespace.putMember', [
            'space' => (string) $this->space,
            'did' => $mom->did,
            'read' => true,
            'write' => true,
        ]);

        [$status] = $dad->call('POST', 'com.atproto.space.createRecord', [
            'space' => (string) $this->space,
            'repo' => $dad->did,
            'collection' => self::COLLECTION,
            'rkey' => 'note-dad',
            'record' => ['$type' => self::COLLECTION, 'text' => 'hello from dad'],
        ]);
        $this->assertSame(200, $status);

        $reader = $this->readerFor($mom);
        $result = $reader->listRepoOps($dad->did);

        $this->assertNotEmpty($result['ops']);
        $this->assertInstanceOf(RepoOp::class, $result['ops'][0]);
        $this->assertNotNull($result['commit'], 'commit is present at the head of the oplog');
        $this->assertSame(32, strlen($result['commit']->data));

        // Replaying the oplog reproduces what the PDS signed...
        $replayed = (new RepoCommit())->applyOps($result['ops']);
        $this->assertTrue(
            $replayed->matches($result['commit']),
            'replayed oplog: '.bin2hex($replayed->digest()).' vs commit '.bin2hex($result['commit']->data),
        );

        // ...and so does folding the record listing, which arrives in the
        // {collection, rkey, cid} shape RepoCommit::fromRecords() expects.
        $listed = RepoCommit::fromRecords($reader->listRecords($dad->did)['records']);

        $this->assertTrue(
            $listed->matches($result['commit']),
            'record listing: '.bin2hex($listed->digest()),
        );

        // Finally, authenticity: the reader returned this commit only after
        // the MAC dad's host sent checked out, keyed by a secret only this
        // request shared with it, against dad's #atproto_kem key.
        $this->assertSame($dad->did, $result['commit']->repo);
        $this->assertSame((string) $this->space, $result['commit']->space);
        $this->assertTrue($replayed->matches($reader->getLatestCommit($dad->did)), 'getLatestCommit carries the same verified digest');
    }

    /**
     * A member's per-space repo outlives the space, so `listSpaces` reports
     * spaces that no longer exist. Credential renewal is what prunes them.
     */
    public function test_a_deleted_space_is_signalled_at_credential_renewal(): void
    {
        [$dad, $mom] = [$this->actors['dad'], $this->actors['mom']];

        [$status, $body] = $dad->call('POST', 'com.atproto.simplespace.createSpace', [
            'spaceType' => self::SPACE_TYPE,
            'readPolicy' => SpacePolicy::memberList()->toArray(),
            'writePolicy' => SpacePolicy::memberList()->toArray(),
            'appAccess' => AppAccess::open()->toArray(),
        ]);
        $this->assertSame(200, $status);

        $space = SpaceRef::parse((string) $body['uri']);
        $dad->call('POST', 'com.atproto.simplespace.putMember', [
            'space' => (string) $space,
            'did' => $mom->did,
            'read' => true,
            'write' => true,
        ]);

        [$status] = $dad->call('POST', 'com.atproto.simplespace.deleteSpace', ['space' => (string) $space]);
        $this->assertSame(200, $status);

        // The PDS mints delegation tokens without checking the space exists...
        $this->assertNotNull($mom->delegationToken($space));

        // ...so the durable signal is one hop later, at the authority.
        [$status, $body] = $this->exchange($mom, $space);
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertSame('SpaceDeleted', $body['error'] ?? null);
    }

    /**
     * Exchange an actor's delegation token for a credential at the authority.
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: CredentialKey}
     */
    protected function exchange(OAuthActor $actor, ?SpaceRef $space = null): array
    {
        $space ??= $this->space;
        $delegation = $actor->delegationToken($space);
        $key = CredentialKey::generate();

        $response = Http::timeout(30)->acceptJson()->post($actor->host.'/xrpc/com.atproto.space.getSpaceCredential', [
            'space' => (string) $space,
            'credentialKid' => $key->didKey(),
            'delegationToken' => $delegation->jwt,
        ]);

        return [$response->status(), $response->json(), $key];
    }

    /**
     * A real {@see SpaceReader}, credentialed as the given actor.
     */
    protected function readerFor(OAuthActor $actor): SpaceReader
    {
        [$status, $body, $key] = $this->exchange($actor);

        $this->assertSame(200, $status, 'getSpaceCredential: '.json_encode($body));

        $credential = new SpaceCredential(
            $this->space,
            $actor->did,
            SpaceToken::parse(SpaceTokenType::Credential, (string) $body['credential']),
            $key,
        );

        $this->assertTrue($credential->isCorrectlyBound());
        $this->assertSame($this->space->authority, $credential->token->issuer());

        return $this->app->make(Spaces::class)->readerFor($credential);
    }
}
