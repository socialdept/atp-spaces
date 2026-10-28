<?php

namespace SocialDept\AtpSpaces\Client;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Enums\SpaceNsid;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\Repo\RepoOp;
use SocialDept\AtpSpaces\Repo\SignedCommit;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Actor;
use SocialDept\AtpSupport\Resolver;
use Throwable;

/**
 * Reads a space, repo by repo.
 *
 * Every `$repo` argument takes a DID or a handle.
 *
 * There is no relay for permissioned data, so reading a space means visiting
 * each member's own PDS in turn — `listRepos` on the authority says who is in
 * it, and everything else is addressed to one member's host. A single
 * credential works at all of them, which is exactly why it is bound to a key
 * rather than passed as a bearer token.
 */
class SpaceReader
{
    public function __construct(
        protected SpaceCredential $credential,
        protected AuthorityResolver $authorities,
        protected Resolver $resolver,
        protected HttpFactory $http,
        protected int $timeout = 30,
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

        $json = $this->get($url, array_filter([
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
     * One record from a member's repo.
     *
     * @return array<string, mixed>
     */
    public function getRecord(string $repo, string $collection, string $rkey): array
    {
        $repo = Actor::did($repo);

        return $this->get($this->repoUrl($repo, SpaceNsid::GetRecord), [
            'space' => (string) $this->space(),
            'repo' => $repo,
            'collection' => $collection,
            'rkey' => $rkey,
        ]);
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

        $json = $this->get($this->repoUrl($repo, SpaceNsid::ListRecords), array_filter([
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
     * @return array{ops: array<int, RepoOp>, commit: SignedCommit|null, cursor: string|null}
     */
    public function listRepoOps(
        string $repo,
        ?string $since = null,
        ?int $limit = null,
        ?string $cursor = null,
        bool $excludeValues = false,
    ): array {
        $repo = Actor::did($repo);

        $json = $this->get($this->repoUrl($repo, SpaceNsid::ListRepoOps), array_filter([
            'space' => (string) $this->space(),
            'repo' => $repo,
            'since' => $since,
            'limit' => $limit,
            'cursor' => $cursor,
            'excludeValues' => $excludeValues ?: null,
        ], fn ($value) => $value !== null));

        $ops = [];

        foreach ($json['ops'] ?? [] as $entry) {
            if (is_array($entry)) {
                $ops[] = RepoOp::fromArray($entry);
            }
        }

        return [
            'ops' => $ops,
            'commit' => isset($json['commit']) && is_array($json['commit'])
                ? self::decodeCommit($json['commit'])
                : null,
            'cursor' => $json['cursor'] ?? null,
        ];
    }

    /**
     * A member's current signed commit.
     */
    public function getLatestCommit(string $repo): SignedCommit
    {
        $repo = Actor::did($repo);

        $json = $this->get($this->repoUrl($repo, SpaceNsid::GetLatestCommit), [
            'space' => (string) $this->space(),
            'repo' => $repo,
        ]);

        $commit = is_array($json['commit'] ?? null) ? $json['commit'] : $json;

        return self::decodeCommit($commit);
    }

    /**
     * A member's whole repo as a CAR, for full-state recovery.
     */
    public function getRepo(string $repo): string
    {
        $repo = Actor::did($repo);

        return $this->send('GET', $this->repoUrl($repo, SpaceNsid::GetRepo), [
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

        return $this->send('GET', $this->repoUrl($repo, SpaceNsid::GetBlob), [
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

        $json = $this->get($this->repoUrl($repo, SpaceNsid::ListBlobs), array_filter([
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
     * Decode a signed commit from its JSON form.
     *
     * Lexicon `bytes` arrive as `{"$bytes": "<base64>"}` over JSON, so every
     * binary field needs unwrapping before it means anything.
     *
     * @param  array<string, mixed>  $commit
     */
    public static function decodeCommit(array $commit): SignedCommit
    {
        return SignedCommit::fromArray([
            'ver' => $commit['ver'] ?? SignedCommit::VERSION,
            'hash' => self::decodeBytes($commit['hash'] ?? ''),
            'ikm' => self::decodeBytes($commit['ikm'] ?? ''),
            'sig' => self::decodeBytes($commit['sig'] ?? ''),
            'mac' => self::decodeBytes($commit['mac'] ?? ''),
            'rev' => $commit['rev'] ?? '',
        ]);
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
     * A GET returning decoded JSON.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function get(string $url, array $params): array
    {
        $json = $this->send('GET', $url, $params)->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Send a credentialed request, retrying once if the host demands a nonce.
     *
     * @param  array<string, mixed>  $params
     */
    protected function send(string $method, string $url, array $params): Response
    {
        $response = $this->dispatch($method, $url, $params);

        if ($this->errorOf($response) === 'UseDpopNonce') {
            $nonce = $response->header('DPoP-Nonce');

            if ($nonce !== '') {
                $response = $this->dispatch($method, $url, $params, $nonce);
            }
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
    protected function dispatch(string $method, string $url, array $params, ?string $nonce = null): Response
    {
        $request = $this->http
            ->timeout($this->timeout)
            ->withHeaders($this->credential->headers($method, $url, $nonce));

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
