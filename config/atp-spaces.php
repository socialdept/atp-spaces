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
    | HTTP
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => env('SPACES_HTTP_TIMEOUT', 30),
        'user_agent' => 'socialdept/atp-spaces',
    ],

];
