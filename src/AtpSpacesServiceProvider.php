<?php

namespace SocialDept\AtpSpaces;

use Illuminate\Support\ServiceProvider;
use SocialDept\AtpSpaces\Console\LexiconDriftCommand;
use SocialDept\AtpSpaces\Contracts\Blake3Hasher;
use SocialDept\AtpSpaces\Crypto\Blake3;
use SocialDept\AtpSpaces\Lexicons\LexiconManifest;

class AtpSpacesServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atp-spaces.php', 'atp-spaces');

        // Swappable so an application with a compiled BLAKE3 can bind it.
        $this->app->singleton(Blake3Hasher::class, fn () => new Blake3());

        $this->app->singleton(LexiconManifest::class, fn () => LexiconManifest::load());

        $this->app->singleton(SpaceTypeRegistry::class, function ($app) {
            $registry = new SpaceTypeRegistry();

            foreach ((array) $app['config']['atp-spaces.types'] ?? [] as $nsid => $definition) {
                $registry->register(SpaceType::fromLexicon((string) $nsid, (array) $definition));
            }

            return $registry;
        });

        $this->app->alias(SpaceTypeRegistry::class, 'atp-spaces.types');
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            Blake3Hasher::class,
            LexiconManifest::class,
            SpaceTypeRegistry::class,
            'atp-spaces.types',
        ];
    }

    /**
     * Console-specific booting.
     */
    protected function bootForConsole(): void
    {
        $this->publishes([
            __DIR__.'/../config/atp-spaces.php' => config_path('atp-spaces.php'),
        ], 'atp-spaces-config');

        $this->commands([
            LexiconDriftCommand::class,
        ]);
    }
}
