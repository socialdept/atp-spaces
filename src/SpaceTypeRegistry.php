<?php

namespace SocialDept\AtpSpaces;

use SocialDept\AtpSpaces\Exceptions\UnknownSpaceTypeException;

/**
 * The space types an application knows about.
 *
 * A consuming app registers its own declarations here, usually from a service
 * provider. Nothing is registered by default: this package ships no space
 * types of its own, because a space type belongs to whoever defines it.
 */
class SpaceTypeRegistry
{
    /**
     * @var array<string, SpaceType>
     */
    protected array $types = [];

    /**
     * @param  array<int, SpaceType>  $types
     */
    public function __construct(array $types = [])
    {
        foreach ($types as $type) {
            $this->register($type);
        }
    }

    /**
     * Register a space type, replacing any declaration with the same NSID.
     */
    public function register(SpaceType $type): static
    {
        $this->types[$type->nsid] = $type;

        return $this;
    }

    /**
     * Whether a space type has been registered.
     */
    public function has(string $nsid): bool
    {
        return isset($this->types[$nsid]);
    }

    /**
     * Look up a space type, or null when it is not registered.
     */
    public function find(string $nsid): ?SpaceType
    {
        return $this->types[$nsid] ?? null;
    }

    /**
     * Look up a space type or throw.
     *
     * @throws UnknownSpaceTypeException
     */
    public function get(string $nsid): SpaceType
    {
        return $this->find($nsid)
            ?? throw new UnknownSpaceTypeException("Space type \"{$nsid}\" is not registered.");
    }

    /**
     * The space type a reference names, or null when it is not registered.
     */
    public function for(SpaceRef $space): ?SpaceType
    {
        return $this->find($space->type);
    }

    /**
     * Every registered space type, keyed by NSID.
     *
     * @return array<string, SpaceType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The collections a space type declares, for a scope's default grant.
     *
     * @return array<int, string>
     */
    public function collectionsFor(string $nsid): array
    {
        return $this->find($nsid)?->collections ?? [];
    }

    /**
     * Forget every registration.
     */
    public function flush(): static
    {
        $this->types = [];

        return $this;
    }
}
