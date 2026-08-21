<?php

namespace SocialDept\AtpSpaces\Auth;

use SocialDept\AtpSpaces\Exceptions\SpaceTokenException;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * A parsed space token.
 *
 * Parsing is structural only — it never checks a signature. That is deliberate
 * and matches upstream: a client attestation's key comes from the client's
 * JWKS rather than a DID document, so there is no single verification path.
 * Applications hold credentials they were handed by an authority they chose to
 * trust; hosts are the ones that must verify.
 */
class SpaceToken
{
    /**
     * Tolerated clock difference when judging expiry, in seconds.
     */
    public const CLOCK_SKEW = 5;

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly SpaceTokenType $type,
        public readonly array $header,
        public readonly array $payload,
        public readonly string $jwt,
    ) {
    }

    /**
     * Parse and structurally validate a token of a given class.
     *
     * @throws SpaceTokenException
     */
    public static function parse(SpaceTokenType $type, string $jwt): static
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new SpaceTokenException('Malformed token: expected 3 parts.');
        }

        $header = self::decodePart($parts[0], 'header');
        $payload = self::decodePart($parts[1], 'payload');

        if (($header['typ'] ?? null) !== $type->typ()) {
            $got = is_string($header['typ'] ?? null) ? $header['typ'] : 'none';

            throw new SpaceTokenException("Wrong token type: expected \"{$type->typ()}\", got \"{$got}\".");
        }

        if (! is_string($header['alg'] ?? null) || $header['alg'] === '') {
            throw new SpaceTokenException('Token is missing "alg".');
        }

        foreach (['iss', 'sub'] as $claim) {
            if (! is_string($payload[$claim] ?? null) || $payload[$claim] === '') {
                throw new SpaceTokenException("Token is missing \"{$claim}\".");
            }
        }

        if (! is_int($payload['exp'] ?? null)) {
            throw new SpaceTokenException('Token is missing "exp".');
        }

        if ($type->requiresAudience() && ! is_string($payload['aud'] ?? null)) {
            throw new SpaceTokenException('Token is missing "aud".');
        }

        if ($type->requiresConfirmation() && ! is_string($payload['cnf']['jkt'] ?? null)) {
            throw new SpaceTokenException('Token is missing "cnf.jkt".');
        }

        if ($type->isSingleUse() && (! is_string($payload['jti'] ?? null) || $payload['jti'] === '')) {
            throw new SpaceTokenException("A {$type->value} token requires a \"jti\".");
        }

        if ($type === SpaceTokenType::ClientAttestation && $payload['iss'] !== $payload['sub']) {
            throw new SpaceTokenException('Client attestation "iss" and "sub" must both be the client_id.');
        }

        return new static($type, $header, $payload, $jwt);
    }

    /**
     * The service identifier a space's authority answers to as its host.
     *
     * This is the audience a delegation token names — not necessarily where
     * requests are sent. An authority publishing no such entry is still
     * reached at its `#atproto_pds` endpoint.
     */
    public static function hostAudience(string $spaceDid): string
    {
        return $spaceDid.'#atproto_space_host';
    }

    public function issuer(): string
    {
        return (string) $this->payload['iss'];
    }

    /**
     * The `sub`: a space URI, or a client_id for a client attestation.
     */
    public function subject(): string
    {
        return (string) $this->payload['sub'];
    }

    public function audience(): ?string
    {
        return is_string($this->payload['aud'] ?? null) ? $this->payload['aud'] : null;
    }

    /**
     * The DPoP key thumbprint this token is bound to, if any.
     */
    public function confirmationThumbprint(): ?string
    {
        return is_string($this->payload['cnf']['jkt'] ?? null) ? $this->payload['cnf']['jkt'] : null;
    }

    public function expiresAt(): int
    {
        return (int) $this->payload['exp'];
    }

    public function issuedAt(): ?int
    {
        return is_int($this->payload['iat'] ?? null) ? $this->payload['iat'] : null;
    }

    /**
     * Whether the token has expired, allowing for clock skew.
     */
    public function isExpired(?int $now = null): bool
    {
        return ($now ?? time()) - self::CLOCK_SKEW >= $this->expiresAt();
    }

    /**
     * Seconds until expiry, never negative.
     */
    public function secondsRemaining(?int $now = null): int
    {
        return max(0, $this->expiresAt() - ($now ?? time()));
    }

    /**
     * The space this token concerns, when its subject names one.
     */
    public function space(): ?SpaceRef
    {
        return SpaceRef::tryParse($this->subject());
    }

    /**
     * Whether this token is bound to the given DPoP key thumbprint.
     */
    public function isBoundTo(string $thumbprint): bool
    {
        $bound = $this->confirmationThumbprint();

        return $bound !== null && hash_equals($bound, $thumbprint);
    }

    /**
     * Decode one base64url JSON segment.
     *
     * @return array<string, mixed>
     */
    protected static function decodePart(string $segment, string $part): array
    {
        $decoded = json_decode(Jwk::base64UrlDecode($segment), true);

        if (! is_array($decoded)) {
            throw new SpaceTokenException("Could not parse token {$part}.");
        }

        return $decoded;
    }
}
