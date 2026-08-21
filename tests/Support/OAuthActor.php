<?php

namespace SocialDept\AtpSpaces\Tests\Support;

use RuntimeException;
use SocialDept\AtpSpaces\Auth\DpopKeypair;
use SocialDept\AtpSpaces\Auth\DpopProof;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * One OAuth-authorized account, for integration tests against a live PDS.
 *
 * Deliberately not built on `atp-client`: these tests need to drive the write
 * path from tokens obtained out of band (see `dev/oauth.php`), and going
 * through a full session manager would test the harness more than the package.
 * Reads go through the real {@see \SocialDept\AtpSpaces\Client\SpaceReader}.
 */
class OAuthActor
{
    public readonly string $did;
    public readonly string $handle;
    public readonly string $host;

    protected string $accessToken;
    protected DpopProof $proof;
    protected ?string $nonce;

    public function __construct(string $file)
    {
        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException("Unusable token file: {$file}");
        }

        $this->did = (string) $data['did'];
        $this->handle = (string) $data['handle'];
        $this->host = (string) $data['host'];
        $this->accessToken = (string) $data['access_token'];
        $this->proof = new DpopProof(DpopKeypair::fromPem($data['dpop_pem']));
        $this->nonce = $data['dpop_nonce'] ?? null;
    }

    /**
     * Load every authorized account in a directory, keyed by short name.
     *
     * @return array<string, self>
     */
    public static function discover(string $directory): array
    {
        $actors = [];

        foreach (glob(rtrim($directory, '/').'/tokens-*.json') ?: [] as $file) {
            try {
                $actor = new self($file);
            } catch (RuntimeException) {
                continue;
            }

            $actors[$actor->short()] = $actor;
        }

        return $actors;
    }

    /**
     * The leading label of the handle, e.g. "dad".
     */
    public function short(): string
    {
        return explode('.', $this->handle)[0];
    }

    /**
     * An authenticated XRPC call against this actor's own PDS.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function call(string $method, string $nsid, array $payload = []): array
    {
        return DpopHttp::request(
            $method,
            $this->host.'/xrpc/'.$nsid,
            $payload,
            'DPoP',
            $this->accessToken,
            $this->proof,
            $this->nonce,
        );
    }

    /**
     * Mint a delegation token for a space from this actor's own PDS.
     */
    public function delegationToken(SpaceRef $space): SpaceToken
    {
        [$status, $body] = $this->call('GET', 'com.atproto.space.getDelegationToken', [
            'space' => (string) $space,
        ]);

        if ($status !== 200) {
            throw new RuntimeException("getDelegationToken failed ({$status}): ".json_encode($body));
        }

        return SpaceToken::parse(SpaceTokenType::Delegation, (string) $body['token']);
    }

    /**
     * Whether the access token is still usable.
     */
    public static function isFresh(string $file): bool
    {
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) && (int) ($data['expires_at'] ?? 0) > time() + 60;
    }
}
