<?php

namespace SocialDept\AtpSpaces\Tests\Support;

use SocialDept\AtpSpaces\Auth\DpopProof;

/**
 * A minimal DPoP-aware HTTP client for integration tests.
 *
 * Curl rather than Laravel's client, so the write path under test is not also
 * the transport the harness depends on.
 */
class DpopHttp
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public static function request(
        string $method,
        string $url,
        array $payload,
        string $scheme,
        string $token,
        DpopProof $proof,
        ?string &$nonce,
    ): array {
        $status = 0;
        $body = null;

        // One retry, for the nonce a server only reveals by rejecting once.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $isGet = $method === 'GET';
            $target = $isGet && $payload !== [] ? $url.'?'.self::query($payload) : $url;

            $headers = [
                'Authorization: '.$scheme.' '.$token,
                'DPoP: '.$proof->create($method, $url, $nonce, $scheme === 'DPoP' ? $token : null),
                'Accept: application/json',
            ];

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CUSTOMREQUEST => $method,
            ];

            if (! $isGet) {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload);
                $headers[] = 'Content-Type: application/json';
            }

            $options[CURLOPT_HTTPHEADER] = $headers;

            $handle = curl_init($target);
            curl_setopt_array($handle, $options);
            $raw = (string) curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $headerLength = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            curl_close($handle);

            if (preg_match('/dpop-nonce:\s*(\S+)/i', substr($raw, 0, $headerLength), $matches)) {
                $nonce = $matches[1];
            }

            $body = json_decode(trim(substr($raw, $headerLength)), true);
            $error = is_array($body) ? ($body['error'] ?? '') : '';

            if ($status < 400 || ! in_array($error, ['use_dpop_nonce', 'UseDpopNonce'], true)) {
                break;
            }
        }

        return [$status, is_array($body) ? $body : null];
    }

    /**
     * Booleans have to go over the wire as true/false, not 1/0.
     *
     * @param  array<string, mixed>  $params
     */
    public static function query(array $params): string
    {
        $parts = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $encoded = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $parts[] = rawurlencode($key).'='.rawurlencode($encoded);
        }

        return implode('&', $parts);
    }
}
