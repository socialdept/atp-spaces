<?php

namespace SocialDept\AtpSpaces\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpSpaces\Lexicons\LexiconManifest;

/**
 * Compare the vendored space lexicons against the upstream branch.
 *
 * Spaces are alpha: endpoints have already been renamed once on an abandoned
 * branch. This turns "upstream changed" into a command that fails loudly,
 * rather than a 400 in production months later.
 */
class LexiconDriftCommand extends Command
{
    protected $signature = 'spaces:lexicon-drift
                            {--offline : Only verify the vendored files against the manifest}
                            {--token= : A GitHub token, to raise the API rate limit}';

    protected $description = 'Check the vendored AT Protocol space lexicons for drift against upstream';

    public function handle(): int
    {
        $manifest = LexiconManifest::load();

        $this->line('');
        $this->line("  <options=bold>Vendored lexicons</> {$manifest->repository}@{$manifest->branch}");
        $this->line("  commit    {$manifest->commit}");
        $this->line('  retrieved '.$manifest->retrieved);
        $this->line('  files     '.count($manifest->files));
        $this->line('');

        $problems = $manifest->verifyLocal();

        if ($problems !== []) {
            $this->error('  Vendored files do not match the manifest:');

            foreach ($problems as $problem) {
                $this->line("    - {$problem}");
            }

            $this->line('');
            $this->line('  These files are vendored verbatim. Re-vendor rather than editing them.');

            return self::FAILURE;
        }

        $this->info('  Local files match the manifest.');

        if ($this->option('offline')) {
            $this->line('');

            return self::SUCCESS;
        }

        return $this->checkUpstream($manifest);
    }

    /**
     * Compare the pinned tree and the branch head against upstream.
     */
    protected function checkUpstream(LexiconManifest $manifest): int
    {
        $this->line('');
        $this->line('  <options=bold>Upstream</>');

        $tree = $this->fetch($manifest->treeUrl());

        if ($tree === null) {
            return self::FAILURE;
        }

        $upstream = [];
        $prefix = $manifest->path.'/';

        foreach ($tree['tree'] ?? [] as $entry) {
            $path = (string) ($entry['path'] ?? '');

            if (($entry['type'] ?? '') !== 'blob' || ! str_starts_with($path, $prefix)) {
                continue;
            }

            if (! str_ends_with($path, '.json')) {
                continue;
            }

            // Manifest paths are relative to `lexicons/`, the tree's to the repo root.
            $relative = substr($path, strlen('lexicons/'));

            if (str_starts_with($relative, 'com/atproto/space/') || str_starts_with($relative, 'com/atproto/simplespace/')) {
                $upstream[$relative] = (string) ($entry['sha'] ?? '');
            }
        }

        $drift = $this->compare($manifest, $upstream);

        // A moved branch head is a warning, not a failure: the pinned tree can
        // still be identical if the new commits touched nothing we vendor.
        $head = $this->fetch($manifest->branchUrl(), quiet: true);

        if ($head !== null && ($head['sha'] ?? '') !== $manifest->commit) {
            $date = $head['commit']['committer']['date'] ?? '?';
            $this->line('');
            $this->line("  <fg=yellow>Branch has moved</> to ".substr((string) $head['sha'], 0, 12)." ({$date})");
        }

        $this->line('');

        if ($drift === []) {
            $this->info('  No drift: the vendored lexicons match the pinned commit.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('  Drift detected:');

        foreach ($drift as $line) {
            $this->line("    - {$line}");
        }

        $this->line('');
        $this->line('  Re-vendor the lexicons and update MANIFEST.json, then reconcile any');
        $this->line('  code that depends on the changed shapes.');
        $this->line('');

        return self::FAILURE;
    }

    /**
     * Diff the vendored files against an upstream path => blob SHA map.
     *
     * @param  array<string, string>  $upstream
     * @return array<int, string>
     */
    protected function compare(LexiconManifest $manifest, array $upstream): array
    {
        $drift = [];

        foreach ($manifest->files as $relative => $_) {
            if (! isset($upstream[$relative])) {
                $drift[] = "removed upstream: {$relative}";

                continue;
            }

            if ($manifest->blobSha($relative) !== $upstream[$relative]) {
                $drift[] = "changed upstream: {$relative}";
            }
        }

        foreach (array_keys($upstream) as $relative) {
            if (! isset($manifest->files[$relative])) {
                $drift[] = "added upstream: {$relative}";
            }
        }

        sort($drift);

        return $drift;
    }

    /**
     * Fetch and decode a GitHub API response.
     *
     * @return array<string, mixed>|null
     */
    protected function fetch(string $url, bool $quiet = false): ?array
    {
        $request = Http::timeout(30)->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'socialdept/atp-spaces',
        ]);

        $token = $this->option('token') ?: env('GITHUB_TOKEN');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->get($url);
        } catch (\Throwable $e) {
            if (! $quiet) {
                $this->error('  Could not reach GitHub: '.$e->getMessage());
            }

            return null;
        }

        if ($response->status() === 403 && ! $quiet) {
            $this->error('  GitHub API rate limit reached. Pass --token or set GITHUB_TOKEN.');

            return null;
        }

        if (! $response->successful()) {
            if (! $quiet) {
                $this->error('  GitHub responded '.$response->status().' for '.$url);
            }

            return null;
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }
}
