<?php

namespace SocialDept\AtpSpaces\Auth;

/**
 * The HTTP Message Signature (RFC 9421) a request carries to present a space
 * credential.
 *
 * It covers exactly two fields, in this order: the Authorization header with
 * the credential, and the DID the request is for. Signing the audience is
 * what keeps one repo host from replaying a request it was sent at another.
 * The signature names no method or URL, so the same headers can be reused for
 * every request to one audience while the credential lasts.
 *
 *     "authorization": Atproto-Space-Credential <credential>
 *     "atproto-space-audience": <audience DID>
 *     "@signature-params": ("authorization" "atproto-space-audience");tag="atproto-space-credential"
 *
 * The audience is the repo owner's DID for a repo read, and the space
 * authority's bare DID for a call to the space host.
 */
final class RequestSignature
{
    public const SCHEME = 'Atproto-Space-Credential';

    public const LABEL = 'atproto-space-credential';

    public const TAG = 'atproto-space-credential';

    public const AUDIENCE_HEADER = 'Atproto-Space-Audience';

    /**
     * The headers that present a credential to one audience.
     *
     * @return array<string, string>
     */
    public static function headers(CredentialKey $key, string $credential, string $audience): array
    {
        $authorization = self::SCHEME.' '.$credential;
        $params = '("authorization" "atproto-space-audience");tag="'.self::TAG.'"';

        $signature = $key->sign(self::base($authorization, $audience, $params));

        return [
            'Authorization' => $authorization,
            self::AUDIENCE_HEADER => $audience,
            'Signature-Input' => self::LABEL.'='.$params,
            'Signature' => self::LABEL.'=:'.base64_encode($signature).':',
        ];
    }

    /**
     * The bytes that are signed: each covered field, then the signature
     * parameters, joined by newlines with none at the end.
     */
    public static function base(string $authorization, string $audience, string $params): string
    {
        return implode("\n", [
            '"authorization": '.trim($authorization),
            '"atproto-space-audience": '.trim($audience),
            '"@signature-params": '.$params,
        ]);
    }
}
