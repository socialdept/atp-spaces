<?php

namespace SocialDept\AtpSpaces\Auth;

use Firebase\JWT\JWT;

/**
 * Builds the DPoP proofs that accompany a space credential (RFC 9449).
 *
 * A proof is signed per request and names the exact method and URL it is for,
 * so intercepting one buys nothing beyond replaying that one call within its
 * lifetime.
 */
class DpopProof
{
    public const TYP = 'dpop+jwt';

    /**
     * How long a proof stays acceptable, in seconds.
     */
    public const MAX_AGE = 60;

    public function __construct(
        protected readonly DpopKeypair $keypair,
    ) {
    }

    /**
     * Create a proof for one request.
     *
     * @param  string  $method  The HTTP method, uppercase
     * @param  string  $url  The request URL, without query or fragment
     * @param  string|null  $nonce  A server-supplied nonce, when one has been issued
     * @param  string|null  $token  The credential being presented, hashed into `ath`
     */
    public function create(
        string $method,
        string $url,
        ?string $nonce = null,
        ?string $token = null,
    ): string {
        $now = time();

        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => strtoupper($method),
            'htu' => self::canonicalUrl($url),
            'iat' => $now,
            'exp' => $now + self::MAX_AGE,
        ];

        if ($nonce !== null && $nonce !== '') {
            $payload['nonce'] = $nonce;
        }

        if ($token !== null && $token !== '') {
            $payload['ath'] = Jwk::base64UrlEncode(hash('sha256', $token, true));
        }

        return JWT::encode(
            payload: $payload,
            key: $this->keypair->toPem(),
            alg: DpopKeypair::ALGORITHM,
            head: [
                'typ' => self::TYP,
                'alg' => DpopKeypair::ALGORITHM,
                'jwk' => $this->keypair->publicJwk(),
            ],
        );
    }

    /**
     * The thumbprint of the key these proofs are signed with.
     */
    public function thumbprint(): string
    {
        return $this->keypair->thumbprint();
    }

    /**
     * `htu` is the request URI with query and fragment removed.
     */
    public static function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $canonical = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $canonical .= ':'.$parts['port'];
        }

        return $canonical.($parts['path'] ?? '');
    }
}
