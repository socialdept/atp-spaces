<?php

namespace SocialDept\AtpSpaces;

use SocialDept\AtpSpaces\Exceptions\InvalidSpaceRefException;
use SocialDept\AtpSpaces\Support\Syntax;
use Stringable;

/**
 * A space type declaration: the lexicon entry (`"type": "space"`) that names a
 * kind of space and describes it to a user on an OAuth consent screen.
 *
 * The collection list is a recommendation to clients, not a constraint — a
 * space will accept records outside it. It exists so consent screens can say
 * what kind of data is at stake, and so clients know what to sync.
 */
class SpaceType implements Stringable
{
    /**
     * @param  string  $nsid  The NSID identifying this space type
     * @param  string  $key  Recommended space key type ("any", "tid", "literal:…")
     * @param  string  $name  Human-readable name, shown on consent screens
     * @param  array<int, string>  $collections  Recommended record collections
     * @param  string|null  $description  Developer-facing description
     * @param  array<string, string>  $localizedNames  Names by language code
     */
    public function __construct(
        public readonly string $nsid,
        public readonly string $key = 'any',
        public readonly string $name = '',
        public readonly array $collections = [],
        public readonly ?string $description = null,
        public readonly array $localizedNames = [],
    ) {
    }

    /**
     * Declare a space type, validating its NSID and collections.
     *
     * @param  array<int, string>  $collections
     * @param  array<string, string>  $localizedNames
     *
     * @throws InvalidSpaceRefException
     */
    public static function make(
        string $nsid,
        string $key = 'any',
        string $name = '',
        array $collections = [],
        ?string $description = null,
        array $localizedNames = [],
    ): static {
        if (! Syntax::isNsid($nsid)) {
            throw new InvalidSpaceRefException("Space type must be an NSID, got \"{$nsid}\".");
        }

        foreach ($collections as $collection) {
            if (! Syntax::isNsid($collection)) {
                throw new InvalidSpaceRefException("Space type collection must be an NSID, got \"{$collection}\".");
            }
        }

        return new static($nsid, $key, $name, array_values($collections), $description, $localizedNames);
    }

    /**
     * Build from a lexicon `"type": "space"` definition.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function fromLexicon(string $nsid, array $definition): static
    {
        $localized = [];

        foreach ($definition as $field => $value) {
            if (str_starts_with($field, 'name:') && is_string($value)) {
                $localized[substr($field, strlen('name:'))] = $value;
            }
        }

        return static::make(
            $nsid,
            is_string($definition['key'] ?? null) ? $definition['key'] : 'any',
            is_string($definition['name'] ?? null) ? $definition['name'] : '',
            array_filter((array) ($definition['collections'] ?? []), 'is_string'),
            is_string($definition['description'] ?? null) ? $definition['description'] : null,
            $localized,
        );
    }

    /**
     * The display name for a language, falling back to the default name.
     */
    public function nameFor(?string $lang = null): string
    {
        if ($lang !== null && isset($this->localizedNames[$lang])) {
            return $this->localizedNames[$lang];
        }

        return $this->name;
    }

    /**
     * Whether a collection is one this space type recommends.
     */
    public function declares(string $collection): bool
    {
        return in_array($collection, $this->collections, true);
    }

    /**
     * Address a space of this type.
     *
     * @param  string  $authority  A DID or a handle
     *
     * @throws InvalidSpaceRefException
     */
    public function space(string $authority, string $skey): SpaceRef
    {
        return SpaceRef::make($authority, $this->nsid, $skey);
    }

    /**
     * Serialize back to a lexicon definition body.
     *
     * @return array<string, mixed>
     */
    public function toLexicon(): array
    {
        $definition = ['type' => 'space', 'key' => $this->key];

        if ($this->name !== '') {
            $definition['name'] = $this->name;
        }

        foreach ($this->localizedNames as $lang => $name) {
            $definition['name:'.$lang] = $name;
        }

        if ($this->description !== null) {
            $definition['description'] = $this->description;
        }

        if ($this->collections !== []) {
            $definition['collections'] = $this->collections;
        }

        return $definition;
    }

    public function __toString(): string
    {
        return $this->nsid;
    }
}
