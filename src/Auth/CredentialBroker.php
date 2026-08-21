<?php

namespace SocialDept\AtpSpaces\Auth;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Authority\SpaceAuthority;
use SocialDept\AtpSpaces\Client\Requests\SpaceRepoRequestClient;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\Enums\SpaceNsid;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Actor;
use Throwable;

/**
 * Turns an authenticated user into a credential that reads a space.
 *
 * Two hops, because the two halves answer different questions. The user's PDS
 * attests *that this app acts for this user* (a 60-second, single-use
 * delegation token). The space authority decides *whether that user may enter*
 * and issues a credential bound to a key only this app holds. Neither party
 * can do the other's job, which is what keeps a space's membership out of the
 * user's PDS and the user's identity out of the authority's trust store.
 */
class CredentialBroker
{
    public function __construct(
        protected AuthorityResolver $authorities,
        protected CredentialStore $store,
        protected HttpFactory $http,
        protected int $leeway = 30,
        protected int $timeout = 30,
    ) {
    }

    /**
     * Get a usable credential for a space, minting one only when needed.
     *
     * @param  string|null  $clientAttestation  Required when the space gates on app identity
     *
     * @throws CredentialException
     */
    public function for(
        SpaceRef $space,
        AtpClient $client,
        ?string $clientAttestation = null,
    ): SpaceCredential {
        $userDid = $client->client->session()->did();
        $cached = $this->store->get($space, $userDid);

        if ($cached !== null && ! $cached->isExpired($this->leeway)) {
            return $cached;
        }

        $credential = $this->mint($space, $client, $userDid, $clientAttestation);

        $this->store->put($credential);

        return $credential;
    }

    /**
     * Mint a fresh credential, ignoring anything cached.
     *
     * @throws CredentialException
     */
    public function mint(
        SpaceRef $space,
        AtpClient $client,
        ?string $userDid = null,
        ?string $clientAttestation = null,
    ): SpaceCredential {
        $userDid ??= $client->client->session()->did();

        $delegation = $this->delegationToken($space, $client);
        $authority = $this->authorities->forSpace($space);

        // A delegation token names the authority as its audience, so one minted
        // for this space cannot be spent at another.
        if ($delegation->audience() !== $authority->audience()) {
            throw new CredentialException(
                'Delegation token audience does not match the space authority.',
                'BadJwtAudience',
            );
        }

        $keypair = DpopKeypair::generate();
        $jwt = $this->exchange($space, $authority, $delegation, $keypair, $clientAttestation);

        $credential = new SpaceCredential(
            $space,
            $userDid,
            SpaceToken::parse(SpaceTokenType::Credential, $jwt),
            $keypair,
        );

        if (! $credential->isCorrectlyBound()) {
            throw new CredentialException(
                'The authority issued a credential bound to a different key.',
                'BadJwtCnf',
            );
        }

        if ($credential->token->issuer() !== $space->authority) {
            throw new CredentialException(
                'Space credential issuer is not the space authority.',
                'BadJwtIss',
            );
        }

        return $credential;
    }

    /**
     * Discard a stored credential, so the next call mints a fresh one.
     *
     * @param  string  $user  A DID or a handle
     */
    public function forget(SpaceRef $space, string $user): void
    {
        $this->store->forget($space, Actor::did($user));
    }

    /**
     * Ask the user's PDS for a delegation token.
     */
    protected function delegationToken(SpaceRef $space, AtpClient $client): SpaceToken
    {
        try {
            return (new SpaceRepoRequestClient($client))->getDelegationToken($space);
        } catch (Throwable $e) {
            throw new CredentialException(
                "Could not mint a delegation token for {$space}: ".$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Exchange a delegation token for a credential at the authority.
     *
     * The delegation token is a bearer credential here; the DPoP proof is what
     * tells the authority which key to bind the result to.
     */
    protected function exchange(
        SpaceRef $space,
        SpaceAuthority $authority,
        SpaceToken $delegation,
        DpopKeypair $keypair,
        ?string $clientAttestation,
    ): string {
        $url = $authority->xrpc(SpaceNsid::GetSpaceCredential->value);
        $proof = new DpopProof($keypair);

        $body = array_filter([
            'space' => (string) $space,
            'clientAttestation' => $clientAttestation,
        ], fn ($value) => $value !== null);

        $response = $this->post($url, $body, $delegation->jwt, $proof->create('POST', $url));

        // The authority may demand a nonce it only reveals by rejecting once.
        $nonce = $this->nonceFrom($response);

        if ($nonce !== null && $this->errorOf($response) === 'UseDpopNonce') {
            $response = $this->post($url, $body, $delegation->jwt, $proof->create('POST', $url, $nonce));
        }

        if ($response->failed() || $this->errorOf($response) !== null) {
            throw new CredentialException(
                $this->messageOf($response, $space),
                $this->errorOf($response),
            );
        }

        $credential = $response->json()['credential'] ?? null;

        if (! is_string($credential) || $credential === '') {
            throw new CredentialException("The authority for {$space} returned no credential.");
        }

        return $credential;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function post(string $url, array $body, string $delegationToken, string $proof): Response
    {
        return $this->http
            ->timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$delegationToken,
                'DPoP' => $proof,
            ])
            ->acceptJson()
            ->post($url, $body);
    }

    protected function nonceFrom(Response $response): ?string
    {
        $nonce = $response->header('DPoP-Nonce');

        return $nonce !== '' ? $nonce : null;
    }

    protected function errorOf(Response $response): ?string
    {
        $error = $response->json()['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : null;
    }

    protected function messageOf(Response $response, SpaceRef $space): string
    {
        $error = $this->errorOf($response) ?? 'HTTP '.$response->status();
        $message = $response->json()['message'] ?? null;

        return is_string($message) && $message !== ''
            ? "Space authority refused a credential for {$space}: {$error} — {$message}"
            : "Space authority refused a credential for {$space}: {$error}";
    }
}
