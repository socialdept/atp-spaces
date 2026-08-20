<?php

namespace SocialDept\AtpSpaces\Lexicons;

use SocialDept\AtpSpaces\Exceptions\LexiconDriftException;

/**
 * The vendored space lexicons, pinned to an upstream commit.
 *
 * Spaces are alpha and the lexicons move. Rather than transcribe endpoint
 * shapes by hand and discover a rename through a runtime 400, the JSON is
 * vendored verbatim and pinned to a commit SHA. `spaces:lexicon-drift`
 * compares the pin against upstream.
 */
class LexiconManifest
{
    /**
     * @param  array<string, string>  $files  Relative path to content sha256
     */
    public function __construct(
        public readonly string $repository,
        public readonly string $branch,
        public readonly string $commit,
        public readonly string $path,
        public readonly string $retrieved,
        public readonly array $files,
        public readonly string $directory,
    ) {
    }

    /**
     * Load the manifest shipped with this package.
     */
    public static function load(?string $directory = null): static
    {
        $directory = $directory ?? self::defaultDirectory();
        $file = $directory.'/MANIFEST.json';

        if (! is_file($file)) {
            throw new LexiconDriftException("Lexicon manifest not found at {$file}.");
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            throw new LexiconDriftException("Lexicon manifest at {$file} is not valid JSON.");
        }

        return new static(
            (string) ($data['repository'] ?? ''),
            (string) ($data['branch'] ?? ''),
            (string) ($data['commit'] ?? ''),
            (string) ($data['path'] ?? ''),
            (string) ($data['retrieved'] ?? ''),
            array_map('strval', (array) ($data['files'] ?? [])),
            $directory,
        );
    }

    /**
     * Where the vendored lexicons live.
     */
    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 2).'/lexicons';
    }

    /**
     * The NSIDs the vendored lexicons define.
     *
     * @return array<int, string>
     */
    public function nsids(): array
    {
        $nsids = array_map(
            fn (string $file) => str_replace('/', '.', substr($file, 0, -strlen('.json'))),
            array_keys($this->files),
        );

        sort($nsids);

        return $nsids;
    }

    /**
     * The absolute path of a vendored lexicon by NSID.
     */
    public function pathFor(string $nsid): ?string
    {
        $relative = str_replace('.', '/', $nsid).'.json';

        return isset($this->files[$relative]) ? $this->directory.'/'.$relative : null;
    }

    /**
     * Decode a vendored lexicon document by NSID.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $nsid): ?array
    {
        $path = $this->pathFor($nsid);

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Check the vendored files still match the manifest.
     *
     * This catches local tampering, not upstream change — for that, run the
     * drift command, which compares against the pinned commit.
     *
     * @return array<int, string> Human-readable problems, empty when intact
     */
    public function verifyLocal(): array
    {
        $problems = [];

        foreach ($this->files as $relative => $expected) {
            $path = $this->directory.'/'.$relative;

            if (! is_file($path)) {
                $problems[] = "missing: {$relative}";

                continue;
            }

            $actual = hash_file('sha256', $path);

            if (! hash_equals($expected, (string) $actual)) {
                $problems[] = "modified: {$relative}";
            }
        }

        foreach ($this->scanDirectory() as $relative) {
            if (! isset($this->files[$relative])) {
                $problems[] = "untracked: {$relative}";
            }
        }

        return $problems;
    }

    /**
     * Every lexicon JSON file actually present on disk.
     *
     * @return array<int, string>
     */
    public function scanDirectory(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $found = [];
        $length = strlen($this->directory) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }

            $relative = substr($file->getPathname(), $length);

            if ($relative === 'MANIFEST.json') {
                continue;
            }

            $found[] = $relative;
        }

        sort($found);

        return $found;
    }

    /**
     * The git blob SHA-1 of a vendored file, for comparison against a tree.
     */
    public function blobSha(string $relative): ?string
    {
        $path = $this->directory.'/'.$relative;

        if (! is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);

        return sha1('blob '.strlen($contents)."\0".$contents);
    }

    /**
     * The upstream tree URL this manifest is pinned to.
     */
    public function treeUrl(): string
    {
        return "https://api.github.com/repos/{$this->repository}/git/trees/{$this->commit}?recursive=1";
    }

    /**
     * The upstream branch head URL, for detecting a moved branch.
     */
    public function branchUrl(): string
    {
        return "https://api.github.com/repos/{$this->repository}/commits/{$this->branch}";
    }
}
