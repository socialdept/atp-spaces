<?php

namespace SocialDept\AtpSpaces\Tests;

use Firebase\JWT\JWT;
use phpseclib3\Crypt\EC;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;

/**
 * Mints the tokens a PDS and an authority would issue, so the exchange can be
 * exercised without either.
 */
trait MintsTokens
{
    protected function mintToken(
        SpaceTokenType $type,
        string $issuer,
        string $subject,
        ?string $audience = null,
        ?string $boundKey = null,
        ?int $expiresIn = null,
        ?string $kid = null,
    ): string {
        $now = time();

        $payload = [
            'iss' => $issuer,
            'sub' => $subject,
            'iat' => $now,
            'exp' => $now + ($expiresIn ?? $type->lifetime()),
            'jti' => bin2hex(random_bytes(8)),
        ];

        if ($audience !== null) {
            $payload['aud'] = $audience;
        }

        if ($boundKey !== null) {
            $payload['cnf'] = ['kid' => $boundKey];
        }

        $header = ['typ' => $type->typ(), 'alg' => 'ES256'];
        $kid ??= $type->defaultKid();

        if ($kid !== null) {
            $header['kid'] = $kid;
        }

        return JWT::encode(
            payload: $payload,
            key: EC::createKey('secp256r1')->toString('PKCS8'),
            alg: 'ES256',
            head: $header,
        );
    }

    /**
     * A delegation token as a user's PDS would mint it.
     */
    protected function mintDelegationToken(string $userDid, string $space, string $authorityDid): string
    {
        return $this->mintToken(
            SpaceTokenType::Delegation,
            $userDid,
            $space,
            SpaceToken::hostAudience($authorityDid),
        );
    }

    /**
     * A credential as an authority would issue it, bound to a key.
     */
    protected function mintCredential(string $authorityDid, string $space, CredentialKey $key, ?int $expiresIn = null): string
    {
        return $this->mintToken(
            SpaceTokenType::Credential,
            $authorityDid,
            $space,
            boundKey: $key->didKey(),
            expiresIn: $expiresIn,
        );
    }
}
