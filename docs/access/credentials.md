# Credentials

Part of the [atp-spaces docs](../introduction.md#documentation). The space credential that reads a space, how it is minted, cached and refused, and minting one from a browser session.

Getting into a space takes two hops, because the two halves answer different questions. Your PDS attests *that this app acts for this user*, in a 60-second single-use delegation token. The space authority decides *whether that user may enter*, and issues a credential bound to a key only your app holds.

`$client->space->read($space)` does all of this for you. To work with the credential itself:

```php
$credential = $client->space->credential($space);

$credential->expiresAt();
$credential->isExpired(leeway: 30);
$credential->isCorrectlyBound();   // checked automatically on issue

$client->space->forget($space);    // discard, forcing a fresh mint
```

Credentials are cached for their lifetime, keyed by space and user. Configure under `atp-spaces.credentials`, or set `SPACES_CREDENTIAL_CACHE=false` to mint one per use. A cached credential reads the whole space, so treat its store like a session store, not like a cache of public data.

## Key binding

A credential lasts about ten minutes. It reads the **whole space** and is presented to every repo host in it. As a bearer token that would be a shared secret, since a host given one could replay it against its peers. So each credential is bound to a fresh P-256 key only your app holds, and every request carries an HTTP Message Signature over the credential and the DID it is for: the repo owner for a repo read, the authority for its host. A host cannot replay a request at another member's host.

## Refusals and revocation

An authority can revoke a credential early. A reader then throws a `CredentialException` whose `isRevoked()` is true and drops the stored credential, so the next `read()` mints a replacement if the user may still enter.

Refusals say which perimeter failed:

```php
use SocialDept\AtpSpaces\Exceptions\CredentialException;

try {
    $reader = $client->space->read($space);
} catch (CredentialException $e) {
    $e->isUserRefusal();   // UserNotAuthorized
    $e->isAppRefusal();    // AppNotAuthorized
    $e->isSpaceDeleted();  // SpaceDeleted
    $e->isRetryable();     // InvalidDelegationToken: a fresh mint may succeed
}
```

An authority that would rather not disclose which perimeter failed returns `NotAuthorized`, and both refusal predicates are false. The lexicon error name is on `$e->error`.

## When the session lives in the browser

An app whose users' OAuth sessions live in the browser has no session on the server to ask the PDS with. The browser asks its own PDS for the delegation token instead, with `com.atproto.space.getDelegationToken`, and sends it to your server, which spends it:

```php
use SocialDept\AtpSpaces\Facades\Spaces;

$credential = Spaces::credentialFromDelegation($space, $request->input('delegationToken'));

$credential->userDid;   // the user the token names
$reader = Spaces::readerFor($credential);
```

The credential is bound to your server's key as usual, and stored under the user the token names. The token itself is not checked against the user's signing key here. The authority does that before it issues anything, so the user it names is trusted only once a credential comes back. A token for a different space, or one aimed at a different authority, is refused before it is sent, with a `CredentialException` whose `error` is `InvalidDelegationToken` or `BadJwtAudience`.
