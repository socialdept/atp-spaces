# Gotchas

Part of the [atp-spaces docs](introduction.md#documentation). Behavior of the alpha PDS that surprises people.

## listSpaces is a hint, not a roster

`listSpaces()` reports spaces the user has *written to*, and a member's per-space repo survives the space being deleted, as an empty shell no method removes. `getDelegationToken` also succeeds for spaces that never existed, since the PDS mints tokens without checking. The durable signal is one hop later, at credential renewal:

```php
catch (CredentialException $e) {
    if ($e->isSpaceDeleted()) { /* prune it */ }
}
```

## App passwords do not work

The space endpoints require `ACCESS_FULL` (`com.atproto.access`), which excludes both `AppPass` and `AppPassPrivileged`. Use OAuth.

## Only loopback redirects are accepted

For `http://` OAuth clients, the redirect must be `localhost`, `127.0.0.1`, or `[::1]`. A LAN or tailnet address is rejected outright.

## space: scopes work

They are granted verbatim, even though an authorization server's `scopes_supported` metadata may not list them.
