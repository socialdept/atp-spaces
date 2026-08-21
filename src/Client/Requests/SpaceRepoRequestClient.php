<?php

namespace SocialDept\AtpSpaces\Client\Requests;

use SocialDept\AtpClient\Client\Requests\Request;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Enums\SpaceNsid;
use SocialDept\AtpSpaces\SpaceRecordUri;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Actor;

/**
 * Writes into the authenticated user's own permissioned repos.
 *
 * Every method here is served by the user's **own PDS** under their OAuth
 * session — you can only ever write to your own repo, in a space you are a
 * member of. Reading anyone else's repo is a different transport entirely; see
 * {@see \SocialDept\AtpSpaces\Client\SpaceReader}.
 */
class SpaceRepoRequestClient extends Request
{
    /**
     * Mint a delegation token, to be exchanged with the authority.
     *
     * Short-lived and single-use — do not cache it. The credential it buys is
     * the thing worth caching.
     */
    public function getDelegationToken(SpaceRef|string $space): SpaceToken
    {
        $response = $this->atp->client->get(SpaceNsid::GetDelegationToken->value, [
            'space' => (string) $space,
        ]);

        return SpaceToken::parse(SpaceTokenType::Delegation, (string) $response->json()['token']);
    }

    /**
     * The spaces this user holds a repo in.
     *
     * Note this is "spaces I have written to", not "spaces I am a member of" —
     * a PDS only tracks the spaces its user has actually put records in.
     *
     * @param  string|null  $actor  A DID or handle to filter by authority
     * @return array{spaces: array<int, SpaceRef>, cursor: string|null}
     */
    public function listSpaces(
        ?string $type = null,
        ?string $actor = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        $response = $this->atp->client->get(SpaceNsid::ListSpaces->value, array_filter([
            'type' => $type,
            'did' => $actor === null ? null : Actor::did($actor),
            'limit' => $limit,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));

        $json = $response->json();
        $spaces = [];

        foreach ($json['spaces'] ?? [] as $view) {
            $ref = SpaceRef::tryParse((string) ($view['uri'] ?? ''));

            if ($ref !== null) {
                $spaces[] = $ref;
            }
        }

        return ['spaces' => $spaces, 'cursor' => $json['cursor'] ?? null];
    }

    /**
     * Create a record in one of the user's permissioned repos.
     *
     * @param  array<string, mixed>  $record  Must carry a `$type`
     * @return array{uri: SpaceRecordUri|null, cid: string, validationStatus: string|null}
     */
    public function createRecord(
        SpaceRef|string $space,
        string $collection,
        array $record,
        ?string $rkey = null,
        ?bool $validate = null,
    ): array {
        $response = $this->atp->client->post(SpaceNsid::CreateRecord->value, array_filter([
            'space' => (string) $space,
            'repo' => $this->atp->client->session()->did(),
            'collection' => $collection,
            'rkey' => $rkey,
            'validate' => $validate,
            'record' => $record,
        ], fn ($value) => $value !== null));

        return $this->writeResult($response->json());
    }

    /**
     * Create or replace a record at a known key.
     *
     * @param  array<string, mixed>  $record
     * @return array{uri: SpaceRecordUri|null, cid: string, validationStatus: string|null}
     */
    public function putRecord(
        SpaceRef|string $space,
        string $collection,
        string $rkey,
        array $record,
        ?bool $validate = null,
        ?string $swapRecord = null,
    ): array {
        $response = $this->atp->client->post(SpaceNsid::PutRecord->value, array_filter([
            'space' => (string) $space,
            'repo' => $this->atp->client->session()->did(),
            'collection' => $collection,
            'rkey' => $rkey,
            'validate' => $validate,
            'swapRecord' => $swapRecord,
            'record' => $record,
        ], fn ($value) => $value !== null));

        return $this->writeResult($response->json());
    }

    /**
     * Delete a record.
     */
    public function deleteRecord(
        SpaceRef|string $space,
        string $collection,
        string $rkey,
        ?string $swapRecord = null,
    ): bool {
        $this->atp->client->post(SpaceNsid::DeleteRecord->value, array_filter([
            'space' => (string) $space,
            'repo' => $this->atp->client->session()->did(),
            'collection' => $collection,
            'rkey' => $rkey,
            'swapRecord' => $swapRecord,
        ], fn ($value) => $value !== null));

        return true;
    }

    /**
     * Apply a batch of writes atomically.
     *
     * @param  array<int, array<string, mixed>>  $writes  Tagged with `$type`
     * @return array<string, mixed>
     */
    public function applyWrites(SpaceRef|string $space, array $writes, ?bool $validate = null): array
    {
        $response = $this->atp->client->post(SpaceNsid::ApplyWrites->value, array_filter([
            'space' => (string) $space,
            'repo' => $this->atp->client->session()->did(),
            'validate' => $validate,
            'writes' => $writes,
        ], fn ($value) => $value !== null));

        return (array) $response->json();
    }

    /**
     * Normalize a write response.
     *
     * @param  array<string, mixed>  $json
     * @return array{uri: SpaceRecordUri|null, cid: string, validationStatus: string|null}
     */
    protected function writeResult(array $json): array
    {
        return [
            'uri' => SpaceRecordUri::tryParse((string) ($json['uri'] ?? '')),
            'cid' => (string) ($json['cid'] ?? ''),
            'validationStatus' => $json['validationStatus'] ?? null,
        ];
    }
}
