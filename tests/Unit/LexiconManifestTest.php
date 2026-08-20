<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Lexicons\LexiconManifest;

class LexiconManifestTest extends TestCase
{
    public function test_it_loads_the_vendored_manifest(): void
    {
        $manifest = LexiconManifest::load();

        $this->assertSame('bluesky-social/atproto', $manifest->repository);
        $this->assertSame('permissioned-data', $manifest->branch);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest->commit);
        $this->assertNotEmpty($manifest->files);
    }

    /**
     * The vendored files are byte-for-byte upstream's. If this fails, someone
     * hand-edited a lexicon instead of re-vendoring it.
     */
    public function test_the_vendored_files_match_the_manifest(): void
    {
        $this->assertSame([], LexiconManifest::load()->verifyLocal());
    }

    public function test_it_lists_the_space_nsids(): void
    {
        $nsids = LexiconManifest::load()->nsids();

        $this->assertContains('com.atproto.space.getDelegationToken', $nsids);
        $this->assertContains('com.atproto.space.getSpaceCredential', $nsids);
        $this->assertContains('com.atproto.space.listRepoOps', $nsids);
        $this->assertContains('com.atproto.simplespace.createSpace', $nsids);
        $this->assertContains('com.atproto.simplespace.checkUserAccess', $nsids);
    }

    /**
     * An abandoned upstream branch renamed these. Pinning to the branch that
     * won means the old names must stay absent.
     */
    public function test_it_does_not_carry_the_abandoned_names(): void
    {
        $nsids = LexiconManifest::load()->nsids();

        $this->assertNotContains('com.atproto.space.getAccessGrant', $nsids);
        $this->assertNotContains('com.atproto.space.getRepoOplog', $nsids);
        $this->assertNotContains('com.atproto.space.getRepoState', $nsids);
    }

    public function test_it_reads_a_lexicon_document(): void
    {
        $document = LexiconManifest::load()->document('com.atproto.space.getDelegationToken');

        $this->assertIsArray($document);
        $this->assertSame('com.atproto.space.getDelegationToken', $document['id']);
        $this->assertSame('query', $document['defs']['main']['type']);
    }

    public function test_the_signed_commit_definition_matches_our_dto(): void
    {
        $defs = LexiconManifest::load()->document('com.atproto.space.defs');
        $properties = $defs['defs']['signedCommit']['properties'];

        $this->assertSame(
            ['ver', 'hash', 'ikm', 'sig', 'mac', 'rev'],
            array_keys($properties),
        );
    }

    public function test_an_unknown_nsid_has_no_document(): void
    {
        $manifest = LexiconManifest::load();

        $this->assertNull($manifest->document('com.example.nope'));
        $this->assertNull($manifest->pathFor('com.example.nope'));
    }

    public function test_it_detects_a_modified_file(): void
    {
        $directory = $this->scratchManifest(['com/atproto/space/defs.json' => '{"lexicon":1}']);

        file_put_contents($directory.'/com/atproto/space/defs.json', '{"lexicon":2}');

        $this->assertSame(
            ['modified: com/atproto/space/defs.json'],
            LexiconManifest::load($directory)->verifyLocal(),
        );
    }

    public function test_it_detects_a_missing_file(): void
    {
        $directory = $this->scratchManifest(['com/atproto/space/defs.json' => '{"lexicon":1}']);

        unlink($directory.'/com/atproto/space/defs.json');

        $this->assertSame(
            ['missing: com/atproto/space/defs.json'],
            LexiconManifest::load($directory)->verifyLocal(),
        );
    }

    public function test_it_detects_an_untracked_file(): void
    {
        $directory = $this->scratchManifest(['com/atproto/space/defs.json' => '{"lexicon":1}']);

        file_put_contents($directory.'/com/atproto/space/extra.json', '{}');

        $this->assertSame(
            ['untracked: com/atproto/space/extra.json'],
            LexiconManifest::load($directory)->verifyLocal(),
        );
    }

    /**
     * Build a throwaway lexicon directory with a matching manifest.
     *
     * @param  array<string, string>  $files
     */
    protected function scratchManifest(array $files): string
    {
        $directory = sys_get_temp_dir().'/atp-spaces-'.bin2hex(random_bytes(6));
        $hashes = [];

        foreach ($files as $relative => $contents) {
            $path = $directory.'/'.$relative;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $contents);
            $hashes[$relative] = hash('sha256', $contents);
        }

        file_put_contents($directory.'/MANIFEST.json', json_encode([
            'repository' => 'bluesky-social/atproto',
            'branch' => 'permissioned-data',
            'commit' => str_repeat('a', 40),
            'path' => 'lexicons/com/atproto',
            'retrieved' => '2026-08-20',
            'files' => $hashes,
        ]));

        $this->scratchDirectories[] = $directory;

        return $directory;
    }

    /**
     * @var array<int, string>
     */
    protected array $scratchDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirectories as $directory) {
            foreach (glob($directory.'/*/*/*/*.json') ?: [] as $file) {
                @unlink($file);
            }

            @unlink($directory.'/MANIFEST.json');
        }

        parent::tearDown();
    }

    public function test_it_computes_git_blob_shas(): void
    {
        $manifest = LexiconManifest::load();
        $sha = $manifest->blobSha('com/atproto/space/defs.json');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $sha);
        $this->assertNull($manifest->blobSha('com/atproto/space/nope.json'));
    }
}
