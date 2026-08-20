<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use SocialDept\AtpSpaces\Contracts\Blake3Hasher;
use SocialDept\AtpSpaces\Crypto\Blake3;
use SocialDept\AtpSpaces\Lexicons\LexiconManifest;
use SocialDept\AtpSpaces\SpaceTypeRegistry;
use SocialDept\AtpSpaces\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_it_binds_the_blake3_hasher(): void
    {
        $this->assertInstanceOf(Blake3::class, $this->app->make(Blake3Hasher::class));
    }

    public function test_the_hasher_can_be_swapped(): void
    {
        $this->app->bind(Blake3Hasher::class, fn () => new class () implements Blake3Hasher {
            public function xof(string $input, int $length): string
            {
                return str_repeat('x', $length);
            }
        });

        $this->assertSame('xxxx', $this->app->make(Blake3Hasher::class)->xof('a', 4));
    }

    public function test_it_binds_the_lexicon_manifest(): void
    {
        $this->assertInstanceOf(LexiconManifest::class, $this->app->make(LexiconManifest::class));
    }

    /**
     * The package ships no space types of its own — they belong to the app.
     */
    public function test_the_registry_starts_empty(): void
    {
        $this->assertSame([], $this->app->make(SpaceTypeRegistry::class)->all());
    }

    public function test_it_registers_configured_space_types(): void
    {
        $this->app['config']->set('atp-spaces.types', [
            'com.example.forum' => [
                'name' => 'Example Forum',
                'collections' => ['com.example.thread'],
            ],
        ]);

        $registry = $this->app->make(SpaceTypeRegistry::class);

        $this->assertTrue($registry->has('com.example.forum'));
        $this->assertSame('Example Forum', $registry->get('com.example.forum')->name);
        $this->assertSame(['com.example.thread'], $registry->collectionsFor('com.example.forum'));
    }

    public function test_the_drift_command_verifies_vendored_files_offline(): void
    {
        $this->artisan('spaces:lexicon-drift', ['--offline' => true])
            ->assertSuccessful();
    }
}
