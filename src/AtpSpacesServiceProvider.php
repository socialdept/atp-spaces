<?php

namespace SocialDept\AtpSpaces;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpSpaces\Access\DenyAllAccessResolver;
use SocialDept\AtpSpaces\Auth\CredentialBroker;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Cache\CacheCredentialStore;
use SocialDept\AtpSpaces\Cache\NullCredentialStore;
use SocialDept\AtpSpaces\Client\SpaceDomainClient;
use SocialDept\AtpSpaces\Console\LexiconDriftCommand;
use SocialDept\AtpSpaces\Contracts\Blake3Hasher;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\Contracts\SignatureVerifier;
use SocialDept\AtpSpaces\Contracts\SpaceAccessResolver;
use SocialDept\AtpSpaces\Crypto\Blake3;
use SocialDept\AtpSpaces\Crypto\SupportSignatureVerifier;
use SocialDept\AtpSpaces\Enums\SimpleSpaceNsid;
use SocialDept\AtpSpaces\Http\Controllers\CheckUserAccessController;
use SocialDept\AtpSpaces\Lexicons\LexiconManifest;
use SocialDept\AtpSupport\Resolver;

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

        // Commit authenticity is checkable out of the box now; swap this out
        // to verify against something other than a published did:key.
        $this->app->singleton(SignatureVerifier::class, fn () => new SupportSignatureVerifier());

        // Refuses everyone unless the application binds its own.
        $this->app->singleton(SpaceAccessResolver::class, function ($app) {
            $resolver = $app['config']['atp-spaces.managing_app.resolver'] ?? DenyAllAccessResolver::class;

            return $app->make($resolver);
        });

        $this->app->singleton(LexiconManifest::class, fn () => LexiconManifest::load());

        $this->app->singleton(SpaceTypeRegistry::class, function ($app) {
            $registry = new SpaceTypeRegistry();

            foreach ((array) $app['config']['atp-spaces.types'] ?? [] as $nsid => $definition) {
                $registry->register(SpaceType::fromLexicon((string) $nsid, (array) $definition));
            }

            return $registry;
        });

        $this->app->alias(SpaceTypeRegistry::class, 'atp-spaces.types');

        $this->app->singleton(AuthorityResolver::class, function ($app) {
            return new AuthorityResolver($app->make(Resolver::class));
        });

        $this->app->singleton(CredentialStore::class, function ($app) {
            $config = (array) ($app['config']['atp-spaces.credentials'] ?? []);

            if (($config['cache'] ?? true) === false) {
                return new NullCredentialStore();
            }

            return new CacheCredentialStore(
                $app->make('cache')->store($config['store'] ?? null),
                (string) ($config['prefix'] ?? 'atp-spaces:credential:'),
                (int) ($config['leeway'] ?? 30),
            );
        });

        $this->app->singleton(CredentialBroker::class, function ($app) {
            $config = (array) ($app['config']['atp-spaces.credentials'] ?? []);

            return new CredentialBroker(
                $app->make(AuthorityResolver::class),
                $app->make(CredentialStore::class),
                $app->make(HttpFactory::class),
                (int) ($config['leeway'] ?? 30),
                $this->timeout($app),
            );
        });

        $this->app->singleton(Spaces::class, function ($app) {
            return new Spaces(
                $app->make(CredentialBroker::class),
                $app->make(AuthorityResolver::class),
                $app->make(SpaceTypeRegistry::class),
                $app->make(Resolver::class),
                $app->make(HttpFactory::class),
                $this->timeout($app),
            );
        });

        $this->app->alias(Spaces::class, 'atp-spaces');
    }

    /**
     * The configured HTTP timeout.
     */
    protected function timeout($app): int
    {
        return (int) ($app['config']['atp-spaces.http.timeout'] ?? 30);
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        // Reads like the rest of the client: `$client->space->…`.
        AtpClient::extend('space', fn (AtpClient $atp) => new SpaceDomainClient($atp));

        $this->registerManagingAppRoute();

        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Expose `checkUserAccess`, so an authority can ask this application
     * whether to admit a user.
     *
     * Opt-in: the route must be reachable from the public internet, and it
     * answers questions about who can read what.
     */
    protected function registerManagingAppRoute(): void
    {
        if (! ($this->app['config']['atp-spaces.managing_app.enabled'] ?? false)) {
            return;
        }

        $path = $this->app['config']['atp-spaces.managing_app.path']
            ?? '/xrpc/com.atproto.simplespace.checkUserAccess';

        $middleware = array_merge(
            ['atp.service-auth:'.SimpleSpaceNsid::CheckUserAccess->value],
            (array) ($this->app['config']['atp-spaces.managing_app.middleware'] ?? []),
        );

        Route::get($path, CheckUserAccessController::class)
            ->middleware($middleware)
            ->name('atp-spaces.check-user-access');
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
            SignatureVerifier::class,
            SpaceAccessResolver::class,
            LexiconManifest::class,
            SpaceTypeRegistry::class,
            AuthorityResolver::class,
            CredentialStore::class,
            CredentialBroker::class,
            Spaces::class,
            'atp-spaces',
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
