# Installation

Part of the [atp-spaces docs](../introduction.md#documentation). Installing the package and its config.

## Installing

```bash
composer require socialdept/atp-spaces
php artisan vendor:publish --tag=atp-spaces-config
```

The service provider is discovered automatically. Spaces use the OAuth sessions of [atp-client](https://github.com/socialdept/atp-client), so set that package up first. App passwords cannot reach the space endpoints, see [Gotchas](../gotchas.md).

## Configuration

Everything lives in `config/atp-spaces.php`:

| Key | Env | Default | What it does |
|-----|-----|---------|--------------|
| `types` | | `[]` | The [space types](space-types.md) your application knows about. |
| `credentials.cache` | `SPACES_CREDENTIAL_CACHE` | `true` | Keep [credentials](../access/credentials.md) for their lifetime. `false` mints one per use. |
| `credentials.store` | `SPACES_CREDENTIAL_STORE` | `null` | The cache store to keep them in. `null` is the default store. |
| `credentials.prefix` | | `atp-spaces:credential:` | The cache key prefix. |
| `credentials.leeway` | | `30` | Seconds before expiry when a credential is treated as spent. |
| `managing_app.enabled` | `SPACES_MANAGING_APP` | `false` | Serve [`checkUserAccess`](../access/managing-app.md). |
| `managing_app.path` | `SPACES_MANAGING_APP_PATH` | `/xrpc/com.atproto.simplespace.checkUserAccess` | Where that route is served. |
| `managing_app.resolver` | | `DenyAllAccessResolver::class` | The class that answers it. The default refuses everyone. |
| `managing_app.middleware` | | `[]` | Middleware added after `atp.service-auth`. |
| `syncer.enabled` | `SPACES_SYNCER` | `false` | Serve [`notifyWrite` and `notifySpaceDeleted`](../data/syncing.md). |
| `syncer.prefix` | `SPACES_SYNCER_PREFIX` | `/xrpc` | The prefix those routes are served under. |
| `syncer.middleware` | | `[]` | Middleware added after `atp.service-auth`. |
| `lexicons.github_token` | `GITHUB_TOKEN` | `null` | A token for [`spaces:lexicon-drift`](../lexicon-drift.md), to raise the GitHub rate limit. |
| `http.timeout` | `SPACES_HTTP_TIMEOUT` | `30` | Seconds before a request to an authority or a repo host gives up. |

The managing app and the syncer endpoints authenticate their callers with atp-client's `atp.service-auth` middleware, which checks each token against `ATP_SERVICE_AUTH_AUDIENCE` from atp-client's config. The same value is the default service for `registerNotify()`.

The package has no migrations.
