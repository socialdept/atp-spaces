<?php

/**
 * Obtain OAuth tokens for the integration suite.
 *
 * Spaces require full OAuth: the endpoints ask for `ACCESS_FULL`
 * (`com.atproto.access`), which excludes both app-password scopes, so an app
 * password cannot be used. The authorization server also only accepts loopback
 * redirects for `http://` clients, which is why the callback is never meant to
 * load — you copy the failed URL out of the address bar.
 *
 *     php dev/oauth.php authorize dad.spaces-alpha.bsky.network
 *     php dev/oauth.php exchange  dad.spaces-alpha.bsky.network '<callback-url>'
 *
 * Tokens land in --dir (default ./.oauth), which the integration suite reads
 * via ATP_SPACES_TOKENS. Keep that directory out of version control.
 */

require __DIR__.'/../vendor/autoload.php';

use SocialDept\AtpSpaces\Auth\DpopKeypair;
use SocialDept\AtpSpaces\Auth\DpopProof;

const REDIRECT = 'http://127.0.0.1:8910/callback';
const SCOPE = 'atproto transition:generic '
    .'space:*?authority=*&collection=*&manage=create&manage=update&manage=delete';

$command = $argv[1] ?? '';
$handle = $argv[2] ?? '';
$dir = getenv('ATP_SPACES_TOKENS') ?: __DIR__.'/../.oauth';
@mkdir($dir, 0700, true);

$host = getenv('ATP_SPACES_HOST') ?: 'https://spaces-alpha.host.bsky.network';
$clientId = 'http://localhost?redirect_uri='.rawurlencode(REDIRECT).'&scope='.rawurlencode(SCOPE);

/**
 * POST form-encoded with a DPoP proof, retrying once for a demanded nonce.
 */
function post(string $url, array $fields, DpopProof $proof, ?string &$nonce): array
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'DPoP: '.$proof->create('POST', $url, $nonce),
            ],
        ]);
        $raw = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerLength = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        if (preg_match('/dpop-nonce:\s*(\S+)/i', substr($raw, 0, $headerLength), $m)) {
            $nonce = $m[1];
        }

        $body = json_decode(trim(substr($raw, $headerLength)), true);

        if ($status < 400 || ($body['error'] ?? '') !== 'use_dpop_nonce') {
            return [$status, $body];
        }
    }

    return [$status, $body];
}

if ($command === 'authorize' && $handle !== '') {
    $keypair = DpopKeypair::generate();
    $proof = new DpopProof($keypair);
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $state = bin2hex(random_bytes(16));
    $nonce = null;

    [$status, $body] = post($host.'/oauth/par', [
        'client_id' => $clientId,
        'redirect_uri' => REDIRECT,
        'response_type' => 'code',
        'scope' => SCOPE,
        'state' => $state,
        'login_hint' => $handle,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ], $proof, $nonce);

    if ($status !== 201 || empty($body['request_uri'])) {
        fwrite(STDERR, "PAR failed ({$status}): ".json_encode($body).PHP_EOL);
        exit(1);
    }

    file_put_contents("{$dir}/session-{$handle}.json", json_encode([
        'handle' => $handle, 'host' => $host, 'client_id' => $clientId,
        'redirect_uri' => REDIRECT, 'scope' => SCOPE, 'state' => $state,
        'code_verifier' => $verifier, 'dpop_pem' => $keypair->toPem(), 'dpop_nonce' => $nonce,
    ], JSON_PRETTY_PRINT));

    echo $host.'/oauth/authorize?client_id='.rawurlencode($clientId)
        .'&request_uri='.rawurlencode($body['request_uri']).PHP_EOL;

    fwrite(STDERR, 'Approve within '.($body['expires_in'] ?? '?')."s, then copy the failed callback URL.".PHP_EOL);
    exit(0);
}

if ($command === 'exchange' && $handle !== '' && ! empty($argv[3])) {
    $session = json_decode((string) file_get_contents("{$dir}/session-{$handle}.json"), true);
    parse_str((string) parse_url($argv[3], PHP_URL_QUERY), $query);

    if (($query['state'] ?? null) !== $session['state']) {
        fwrite(STDERR, 'State mismatch — refusing to exchange.'.PHP_EOL);
        exit(1);
    }

    $proof = new DpopProof(DpopKeypair::fromPem($session['dpop_pem']));
    $nonce = $session['dpop_nonce'] ?? null;

    [$status, $body] = post($session['host'].'/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'] ?? '',
        'redirect_uri' => $session['redirect_uri'],
        'client_id' => $session['client_id'],
        'code_verifier' => $session['code_verifier'],
    ], $proof, $nonce);

    if ($status !== 200 || empty($body['access_token'])) {
        fwrite(STDERR, "Token exchange failed ({$status}): ".json_encode($body).PHP_EOL);
        exit(1);
    }

    file_put_contents("{$dir}/tokens-{$handle}.json", json_encode([
        'handle' => $handle, 'did' => $body['sub'] ?? null, 'host' => $session['host'],
        'access_token' => $body['access_token'], 'refresh_token' => $body['refresh_token'] ?? null,
        'scope' => $body['scope'] ?? null, 'expires_at' => time() + (int) ($body['expires_in'] ?? 0),
        'dpop_pem' => $session['dpop_pem'], 'dpop_nonce' => $nonce, 'client_id' => $session['client_id'],
    ], JSON_PRETTY_PRINT));

    @unlink("{$dir}/session-{$handle}.json");

    echo "authorized {$handle} as ".($body['sub'] ?? '?').PHP_EOL;
    echo 'scope: '.($body['scope'] ?? '?').PHP_EOL;
    exit(0);
}

fwrite(STDERR, <<<USAGE
usage:
  php dev/oauth.php authorize <handle>
  php dev/oauth.php exchange  <handle> <callback-url>

tokens are written to ATP_SPACES_TOKENS (default ./.oauth)

USAGE);
exit(1);
