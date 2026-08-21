<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Space Types
    |--------------------------------------------------------------------------
    |
    | The space types this application knows about. A space type is an NSID
    | naming a kind of space, plus the collections clients should expect in
    | it. This package ships none of its own — a space type belongs to
    | whoever defines it.
    |
    | Each entry is keyed by NSID:
    |
    |   'com.example.forum' => [
    |       'key' => 'any',
    |       'name' => 'Example Forum',
    |       'collections' => ['com.example.thread', 'com.example.reply'],
    |   ],
    |
    */

    'types' => [],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | A space credential is minted by the authority and lives for two hours by
    | default. Caching them avoids a delegation-token round trip on every read.
    | The leeway is subtracted from a credential's expiry so one is never used
    | in the moment it lapses.
    |
    */

    'credentials' => [
        'cache' => env('SPACES_CREDENTIAL_CACHE', true),
        'store' => env('SPACES_CREDENTIAL_STORE'),
        'prefix' => 'atp-spaces:credential:',
        'leeway' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Managing App
    |--------------------------------------------------------------------------
    |
    | Serve `com.atproto.simplespace.checkUserAccess`, so spaces configured with
    | `SpacePolicy::managingApp()` can ask this application whether to admit a
    | user. Off by default, because exposing it without a resolver is pointless
    | and the route must be publicly reachable by the authority's PDS.
    |
    | `resolver` must implement Contracts\SpaceAccessResolver. The default
    | refuses everyone, so a half-finished setup grants nothing.
    |
    | Requests are authenticated with atp-client's `atp.service-auth`
    | middleware, which checks the token against ATP_SERVICE_AUTH_AUDIENCE.
    | That audience is the identifier you publish in your DID document and
    | name in `SpacePolicy::managingApp()`.
    |
    */

    'managing_app' => [
        'enabled' => env('SPACES_MANAGING_APP', false),
        'path' => env('SPACES_MANAGING_APP_PATH', '/xrpc/com.atproto.simplespace.checkUserAccess'),
        'resolver' => SocialDept\AtpSpaces\Access\DenyAllAccessResolver::class,
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lexicons
    |--------------------------------------------------------------------------
    |
    | The space lexicons are vendored and pinned to an upstream commit.
    | `spaces:lexicon-drift` compares them against that commit through the
    | GitHub API, which is rate limited to 60 requests an hour unauthenticated.
    |
    */

    'lexicons' => [
        'github_token' => env('GITHUB_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => env('SPACES_HTTP_TIMEOUT', 30),
        'user_agent' => 'socialdept/atp-spaces',
    ],

];
