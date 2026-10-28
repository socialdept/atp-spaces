<?php

namespace SocialDept\AtpSpaces\Auth;

use SocialDept\AtpSpaces\SpaceRef;

/**
 * A space credential together with the key it is bound to.
 *
 * The two travel as one: a credential without its key is unusable, and a key
 * without its credential proves nothing. Anything that persists one must
 * persist both.
 */
class SpaceCredential
{
    public function __construct(
        public readonly SpaceRef $space,
        public readonly string $userDid,
        public readonly SpaceToken $token,
        public readonly CredentialKey $key,
    ) {
    }

    /**
     * The raw credential JWT, as presented in an Authorization header.
     */
    public function jwt(): string
    {
        return $this->token->jwt;
    }

    public function expiresAt(): int
    {
        return $this->token->expiresAt();
    }

    /**
     * Whether the credential is spent, treating anything inside `$leeway` of
     * expiry as already gone.
     */
    public function isExpired(int $leeway = 0, ?int $now = null): bool
    {
        return ($now ?? time()) + $leeway >= $this->expiresAt();
    }

    /**
     * Whether the credential really is bound to the key we hold.
     *
     * Worth checking once on issue: a credential bound to someone else's key is
     * useless, and finding out at request time is a confusing failure.
     */
    public function isCorrectlyBound(): bool
    {
        return $this->token->isBoundTo($this->key->didKey());
    }

    /**
     * The headers that present this credential to one audience: the repo
     * owner's DID for a repo read, the space authority's DID for its host.
     *
     * @return array<string, string>
     */
    public function headers(string $audience): array
    {
        return RequestSignature::headers($this->key, $this->jwt(), $audience);
    }

    /**
     * Serialize for a cache store.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'space' => (string) $this->space,
            'userDid' => $this->userDid,
            'credential' => $this->jwt(),
            'key' => $this->key->toPem(),
        ];
    }

    /**
     * Restore from a cache store, or null when the payload is unusable.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?static
    {
        $space = SpaceRef::tryParse((string) ($data['space'] ?? ''));

        // A credential cached before keys were did:keys has no "key", and is
        // dropped so a fresh one is minted.
        if ($space === null || ! is_string($data['credential'] ?? null) || ! is_string($data['key'] ?? null)) {
            return null;
        }

        try {
            return new static(
                $space,
                (string) ($data['userDid'] ?? ''),
                SpaceToken::parse(SpaceTokenType::Credential, $data['credential']),
                CredentialKey::fromPem($data['key']),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
