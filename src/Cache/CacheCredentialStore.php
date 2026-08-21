<?php

namespace SocialDept\AtpSpaces\Cache;

use Illuminate\Contracts\Cache\Repository;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Keeps credentials in a Laravel cache store, expiring them with the token.
 *
 * The entry's TTL is the credential's own remaining life, so a stale entry can
 * never outlive the thing it holds.
 */
class CacheCredentialStore implements CredentialStore
{
    public function __construct(
        protected Repository $cache,
        protected string $prefix = 'atp-spaces:credential:',
        protected int $leeway = 30,
    ) {
    }

    public function get(SpaceRef $space, string $userDid): ?SpaceCredential
    {
        $data = $this->cache->get($this->key($space, $userDid));

        if (! is_array($data)) {
            return null;
        }

        $credential = SpaceCredential::fromArray($data);

        if ($credential === null || $credential->isExpired($this->leeway)) {
            return null;
        }

        return $credential;
    }

    public function put(SpaceCredential $credential): void
    {
        $ttl = $credential->expiresAt() - time() - $this->leeway;

        if ($ttl <= 0) {
            return;
        }

        $this->cache->put(
            $this->key($credential->space, $credential->userDid),
            $credential->toArray(),
            $ttl,
        );
    }

    public function forget(SpaceRef $space, string $userDid): void
    {
        $this->cache->forget($this->key($space, $userDid));
    }

    /**
     * Hashed so a DID never lands in a cache key verbatim.
     */
    protected function key(SpaceRef $space, string $userDid): string
    {
        return $this->prefix.hash('sha256', (string) $space.'|'.$userDid);
    }
}
