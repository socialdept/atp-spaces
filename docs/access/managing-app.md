# Answering for a space

Part of the [atp-spaces docs](../introduction.md#documentation). Serving `checkUserAccess`, so your application decides who may enter.

When a space uses `SpacePolicy::managingApp()`, the authority calls your application to ask whether a user may read the space, or write to it. Implement a resolver:

```php
use SocialDept\AtpSpaces\Access\SpaceAccessRequest;
use SocialDept\AtpSpaces\Contracts\SpaceAccessResolver;

class SubscriberAccess implements SpaceAccessResolver
{
    public function authorize(SpaceAccessRequest $request): bool
    {
        if ($request->isOwner()) {
            return true;
        }

        // Subscribers read, and only staff write.
        if ($request->isWrite()) {
            return Staff::query()->where('did', $request->user)->exists();
        }

        return Subscription::query()
            ->where('publication_did', $request->space->authority)
            ->where('subscriber_did', $request->user)
            ->active()
            ->exists();
    }
}
```

`Staff` and `Subscription` stand for your own models. The request carries the `space`, the `user` DID, the `access` asked for, the attested `clientId` when the space gates on app identity, and the `authority` that asked. `hasClient()` says whether a client was attested.

Bind it and turn the endpoint on:

```php
// config/atp-spaces.php
'managing_app' => [
    'enabled' => true,
    'resolver' => App\Spaces\SubscriberAccess::class,
],
```

```env
ATP_SERVICE_AUTH_AUDIENCE=did:web:app.example.com#forum
```

That audience is the identifier you publish in your DID document and name in `SpacePolicy::managingApp()`. Requests are authenticated by atp-client's `atp.service-auth` middleware, which verifies the authority's signature against its published key. The route is served at `managing_app.path`, `/xrpc/com.atproto.simplespace.checkUserAccess` by default, and must be reachable from the public internet.

Three things this endpoint does on your behalf:

- **Confirms the caller governs the space it is asking about.** The middleware proves some service signed for this method, but not which space it owns. Without that check any token holder could ask who has access to anyone's space.
- **Fails closed.** A throwing resolver, an unauthenticated request, or no resolver at all all answer `false`. The reason goes to the log, not the response.
- **Stays off until you turn it on.** The default resolver refuses everyone, so a half-finished setup grants nothing.

> [!IMPORTANT]
> An unreachable managing app **denies**. Under this policy your endpoint's availability becomes your content's availability, and the authority calls it while a user waits for a credential. Keep the resolver fast, and be careful what it depends on.
