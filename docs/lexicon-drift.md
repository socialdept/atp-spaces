# Lexicon drift

Part of the [atp-spaces docs](introduction.md#documentation). The vendored lexicons and the command that checks them against upstream.

The `com.atproto.space.*` and `com.atproto.simplespace.*` lexicons are vendored verbatim under `lexicons/`, pinned to an upstream commit in `MANIFEST.json`. Nothing is transcribed by hand, so a rename upstream surfaces as a failing command rather than a 400 in production.

```bash
php artisan spaces:lexicon-drift             # compare against upstream
php artisan spaces:lexicon-drift --offline   # verify the vendored files only
php artisan spaces:lexicon-drift --token=…   # raise the GitHub rate limit
```

The command exits non-zero on drift. It compares through the GitHub API, which allows 60 requests an hour without a token. Without `--token` it uses `GITHUB_TOKEN`, through `atp-spaces.lexicons.github_token`.

Vendored files are byte-for-byte upstream's, so re-vendor them rather than editing them.
