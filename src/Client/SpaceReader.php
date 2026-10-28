<?php

namespace SocialDept\AtpSpaces\Client;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SocialDept\AtpSpaces\Auth\CredentialBroker;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Crypto\Kem;
use SocialDept\AtpSpaces\Enums\SpaceNsid;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\Repo\Commit;
use SocialDept\AtpSpaces\Repo\Mac;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Actor;
use SocialDept\AtpSpaces\Support\Cid;
use SocialDept\AtpSpaces\Support\Drisl\Drisl;
use SocialDept\AtpSupport\Resolver;
use Throwable;

/**
 * Reads a space, repo by repo.
 *
 * Every `$repo` argument takes a DID or a handle.
 *
 * There is no relay for space data, so reading a space means visiting each
 * member's own PDS in turn. `listRepos` on the authority says who is in it,
 * and everything else is addressed to one member's host. A single credential
 * works at all of them, which is exactly why it is bound to a key rather than
 * passed as a bearer token: each request signs it together with the DID it is
 * for, the repo owner or the authority, so no host can replay it elsewhere.
 *
 * Commits and records come with a MAC from the member's host, keyed by a
 * secret only this request shares with it (see {@see Kem}). Each is checked
 * before it is returned, and one that fails is never returned. A failure is
 * retried once after rereading the author's DID document, in case their KEM
 * key rotated.
 */
class SpaceReader
{
    public function __construct(
        protected SpaceCredential $credential,
        protected AuthorityResolver $authorities,
        protected Resolver $resolver,
        protected HttpFactory $http,
        protected int $timeout = 30,
        protected ?CredentialBroker $broker = null,
    ) {
    }

    /**
     * The space this reader is credentialed for.
     */
    public function space(): SpaceRef
    {
        return $this->credential->space;
    }

    /**
     * Who holds a repo in this space.
     *
     * Served by the authority, since no single member knows the whole roster.
     * Each entry is a writer's latest state, `{ did, repoRev, data, spaceRev }`,
     * in ascending `spaceRev`. The cursor is the last entry's `spaceRev`, an
     * exclusive checkpoint: pass it back to read on, and an empty page, which
     * has no cursor, means caught up. A repo updated mid-listing can appear
     * twice.
     *
     * @return array{repos: array<int, array<string, mixed>>, cursor: string|null}
     */
    public function listRepos(?int $limit = null, ?string $cursor = null): array
    {
        $url = $this->authorities->forSpace($this->space())->xrpc(SpaceNsid::ListRepos->value);

        $json = $this->get($url, $this->space()->authority, array_filter([
            'space' => (string) $this->space(),
            'limit' => $limit,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));

        return [
            'repos' => is_array($json['repos'] ?? null) ? $json['repos'] : [],
            'cursor' => $json['cursor'] ?? null,
        ];
    }

    /**
     * One record from a member's repo, checked against its MAC and its CID.
     *
     * @return array<string, mixed>
     *
     * @throws RepoVerificationException when the record does not check out
     */
    public function getRecord(string $repo, string $collection, string $rkey): array
    {
        $repo = Actor::did($repo);
        $uri = (string) $this->space()->record($repo, $collection, $rkey);

        return $this->withMac($repo, SpaceNsid::GetRecord, Mac::RECORD, [
            'space' => (string) $this->space(),
            'repo' => $repo,
            'collection' => $collection,
            'rkey' => $rkey,
        ], function (Response $response, string $secret) use ($uri) {
            $json = (array) $response->json();

            if (($json['uri'] ?? null) !== $uri || ! is_string($json['cid'] ?? null)) {
                throw new RepoVerificationException("The repo host answered for another record than {$uri}.");
            }

            try {
                $cid = Cid::parse($json['cid']);
                $encoded = Drisl::encode(Drisl::fromJson($response->body())->value ?? null);
            } catch (\Throwable $e) {
                throw new RepoVerificationException("The record at {$uri} is not valid DRISL: ".$e->getMessage(), 0, $e);
            }

            if (! hash_equals(Mac::forRecord($secret, $uri, $cid), Mac::fromHeader($response->header(Mac::HEADER), Mac::RECORD))) {
                throw new RepoVerificationException("The MAC for {$uri} does not match.");
            }

            if (! Cid::forBytes($encoded)->equals($cid)) {
                throw new RepoVerificationException("The record at {$uri} does not match its CID.");
            }

            return $json;
        });
    }

    /**
     * List a member's records, optionally filtered to one collection.
     *
     * @return array{records: array<int, array<string, mixed>>, cursor: string|null}
     */
    public function listRecords(
        string $repo,
        ?string $collection = null,
        ?int $limit = null,
        ?string $cursor = null,
        ?bool $reverse = null,
        bool $excludeValues = false,
    ): array {
        $repo = Actor::did($repo);

        $json = $this->get($this->repoUrl($repo, SpaceNsid::ListRecords), $repo, array_filter([
            'space' => (string) $this->space(),
            'repo' => $repo,
            'collection' => $collection,
            'limit' => $limit,
            'cursor' => $cursor,
            'reverse' => $reverse,
            'excludeValues' => $excludeValues ?: null,
        ], fn ($value) => $value !== null));

        return [
            'records' => is_array($json['records'] ?? null) ? $json['records'] : [],
            'cursor' => $json['cursor'] ?? null,
        ];
    }

    /**
     * A member's operation log since a revision — the incremental sync path.
     *
     * The commit is present only once the response reaches the head of the
     * log, which is the signal that you are caught up and may compare hashes.
     * The log itself carries no history guarantee: a host may compact or drop
     * it, so a missing revision means falling back to a full listing.
     *
     * The commit, when present, has been checked against its MAC.
     *
     * @return array{ops: array<int, RepoOp>, commit: Commit|null, cursor: string|null}
     *
     * @throws RepoVerificationException when the commit does not check out
     */
    public function listRepoOps(
        string $repo,
        ?string $since = null,
        ?int $limit = null,
        ?string $cursor = null,
        bool $excludeValues = false,
    ): array {
        $repo = Actor::did($repo);

        $params = array_filter([
            'space' => (string) $this->space(),
            'repo' => $repo,
            'since' => $since,
            'limit' => $limit,
            'cursor' => $cursor,
            'excludeValues' => $excludeValues ?: null,
        ], fn ($value) => $value !== null);

        // Every page asks for a MAC, and only a page with a commit has one.
        return $this->withMac($repo, SpaceNsid::ListRepoOps, Mac::COMMIT, $params, function (Response $response, string $secret) use ($repo) {
            $json = (array) $response->json();
            $ops = [];

            foreach ($json['ops'] ?? [] as $entry) {
                if (is_array($entry)) {
                    $ops[] = RepoOp::fromArray($entry);
                }
            }

            $commit = isset($json['commit']) && is_array($json['commit'])
                ? $this->verified(self::decodeCommit($json['commit']), $repo, $secret, $response)
                : null;

            return ['ops' => $ops, 'commit' => $commit, 'cursor' => $json['cursor'] ?? null];
        });
    }

    /**
     * A member's current commit, checked against its MAC.
     *
     * @throws RepoVerificationException when the commit does not check out
     */
    public function getLatestCommit(string $repo): Commit
    {
        $repo = Actor::did($repo);

        return $this->withMac($repo, SpaceNsid::GetLatestCommit, Mac::COMMIT, [
            'space' => (string) $this->space(),
            'repo' => $repo,
        ], function (Response $response, string $secret) use ($repo) {
            $commit = $response->json()['commit'] ?? null;

            if (! is_array($commit)) {
                throw new RepoVerificationException("The repo host sent no commit for {$repo}.");
            }

            return $this->verified(self::decodeCommit($commit), $repo, $secret, $response);
        });
    }

    /**
     * A member's whole repo as a CAR, for full-state recovery.
     */
    public function getRepo(string $repo): string
    {
        $repo = Actor::did($repo);

        return $this->send('GET', $this->repoUrl($repo, SpaceNsid::GetRepo), $repo, [
            'space' => (string) $this->space(),
            'repo' => $repo,
        ])->body();
    }

    /**
     * A blob's bytes.
     */
    public function getBlob(string $repo, string $cid): string
    {
        $repo = Actor::did($repo);

        return $this->send('GET', $this->repoUrl($repo, SpaceNsid::GetBlob), $repo, [
            'space' => (string) $this->space(),
            'repo' => $repo,
            'cid' => $cid,
        ])->body();
    }

    /**
     * The blobs a member's repo references.
     *
     * @return array{cids: array<int, string>, cursor: string|null}
     */
    public function listBlobs(string $repo, ?int $limit = null, ?string $cursor = null): array
    {
        $repo = Actor::did($repo);

        $json = $this->get($this->repoUrl($repo, SpaceNsid::ListBlobs), $repo, array_filter([
            'space' => (string) $this->space(),
            'repo' => $repo,
            'limit' => $limit,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));

        return [
            'cids' => array_values(array_filter((array) ($json['cids'] ?? []), 'is_string')),
            'cursor' => $json['cursor'] ?? null,
        ];
    }

    /**
     * Decode a commit from its JSON form.
     *
     * Lexicon `bytes` arrive as `{"$bytes": "<base64>"}` over JSON, so `data`
     * needs unwrapping before it means anything.
     *
     * @param  array<string, mixed>  $commit
     *
     * @throws RepoVerificationException when the commit is malformed
     */
    public static function decodeCommit(array $commit): Commit
    {
        try {
            return Commit::fromArray([
                'ver' => $commit['ver'] ?? null,
                'repo' => $commit['repo'] ?? '',
                'space' => $commit['space'] ?? '',
                'rev' => $commit['rev'] ?? '',
                'data' => self::decodeBytes($commit['data'] ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new RepoVerificationException('Malformed commit: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Unwrap a lexicon bytes value.
     */
    protected static function decodeBytes(mixed $value): string
    {
        if (is_array($value) && isset($value['$bytes']) && is_string($value['$bytes'])) {
            return (string) base64_decode($value['$bytes'], true);
        }

        return is_string($value) ? $value : '';
    }

    /**
     * The XRPC URL for a method on a member's own host.
     */
    protected function repoUrl(string $repo, SpaceNsid $method): string
    {
        try {
            $endpoint = $this->resolver->resolvePds($repo);
        } catch (Throwable $e) {
            throw new SpacesException("Could not resolve the PDS for \"{$repo}\": ".$e->getMessage(), 0, $e);
        }

        if ($endpoint === null || $endpoint === '') {
            throw new SpacesException("No PDS endpoint published for \"{$repo}\".");
        }

        return rtrim($endpoint, '/').'/xrpc/'.$method->value;
    }

    /**
     * Send a request that asks for a MAC, and hand the response and the
     * secret to $verify. A failed check is retried once, after rereading the
     * author's DID document, in case their KEM key rotated, with a fresh key
     * share as every request needs.
     *
     * @template T
     *
     * @param  array<string, mixed>  $params
     * @param  Closure(Response, string): T  $verify
     * @return T
     *
     * @throws RepoVerificationException when it fails twice
     */
    protected function withMac(string $repo, SpaceNsid $method, string $type, array $params, Closure $verify): mixed
    {
        $failure = null;

        foreach ([true, false] as $useCache) {
            ['keyShare' => $keyShare, 'sharedSecret' => $secret] = Kem::encapsulate($this->kemKey($repo, $useCache));

            $response = $this->send('GET', $this->repoUrl($repo, $method), $repo, $params, [
                Mac::WANT_HEADER => Mac::wantHeader($type, $keyShare),
            ]);

            try {
                return $verify($response, $secret);
            } catch (RepoVerificationException $e) {
                $failure = $e;
            }
        }

        throw $failure;
    }

    /**
     * Check a commit came from the repo and space asked about, with this
     * request's MAC.
     *
     * @throws RepoVerificationException
     */
    protected function verified(Commit $commit, string $repo, string $secret, Response $response): Commit
    {
        RepoCommit::verify($commit, (string) $this->space(), $repo, $secret, Mac::fromHeader($response->header(Mac::HEADER), Mac::COMMIT))
            ->assertVerified();

        return $commit;
    }

    /**
     * An author's `#atproto_kem` key, which every MAC is keyed against.
     *
     * @throws RepoVerificationException when the author publishes none
     */
    protected function kemKey(string $repo, bool $useCache = true): string
    {
        try {
            $key = $this->resolver->resolveDid($repo, $useCache)->getSigningKey('#atproto_kem');
        } catch (Throwable $e) {
            throw new SpacesException("Could not resolve the DID document for \"{$repo}\": ".$e->getMessage(), 0, $e);
        }

        if ($key === null) {
            throw new RepoVerificationException("\"{$repo}\" publishes no #atproto_kem key, so its space repo cannot be read with a credential yet.");
        }

        return $key;
    }

    /**
     * A GET returning decoded JSON.
     *
     * @param  string  $audience  The DID the request is for
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function get(string $url, string $audience, array $params): array
    {
        $json = $this->send('GET', $url, $audience, $params)->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Send a credentialed request.
     *
     * A revoked credential is never retried. The stored one is dropped, so
     * the next reader mints a replacement through the user's session, if the
     * user may still enter.
     *
     * @param  string  $audience  The DID the request is for
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     *
     * @throws CredentialException when the credential was revoked
     * @throws SpacesException
     */
    protected function send(string $method, string $url, string $audience, array $params, array $headers = []): Response
    {
        $response = $this->dispatch($method, $url, $audience, $params, $headers);

        if ($this->errorOf($response) === 'CredentialRevoked') {
            $this->broker?->forget($this->space(), $this->credential->userDid);

            throw new CredentialException("The credential for {$this->space()} was revoked.", 'CredentialRevoked');
        }

        if ($response->failed() || $this->errorOf($response) !== null) {
            $error = $this->errorOf($response) ?? 'HTTP '.$response->status();
            $message = $response->json()['message'] ?? null;

            throw new SpacesException(is_string($message) && $message !== ''
                ? "{$method} {$url} failed: {$error} — {$message}"
                : "{$method} {$url} failed: {$error}");
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function dispatch(string $method, string $url, string $audience, array $params, array $headers = []): Response
    {
        $request = $this->http
            ->timeout($this->timeout)
            ->withHeaders($this->credential->headers($audience) + $headers);

        return $method === 'GET'
            ? $request->get($url, $params)
            : $request->send($method, $url, ['json' => $params]);
    }

    protected function errorOf(Response $response): ?string
    {
        if (! str_contains((string) $response->header('Content-Type'), 'json')) {
            return null;
        }

        $error = $response->json()['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : null;
    }
}
