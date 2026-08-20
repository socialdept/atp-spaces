<?php

namespace SocialDept\AtpSpaces\Scopes;

use SocialDept\AtpSpaces\SpaceRef;
use Stringable;

/**
 * Builder for the OAuth `space:` scope string.
 *
 *     space:<type>[?authority=<did>][&skey=<skey>][&collection=<nsid>…][&action=<action>…][&manage=<op>…]
 *
 * The type is positional; every other parameter is named, repeated once per
 * value when it takes several. Parameters equal to their default are omitted,
 * so the shortest form of a scope is the canonical one.
 *
 * Defaults mirror upstream: `authority=self`, `skey=*`, no collections, the
 * four non-`read_self` actions, and no management operations.
 */
class SpaceScope implements Stringable
{
    /**
     * Characters upstream leaves literal in a scope string.
     *
     * Everything else is percent-encoded. "?" and "&" are deliberately absent —
     * they would break query-string parsing.
     */
    protected const LITERAL_CHARS = [
        '%3A' => ':',
        '%2F' => '/',
        '%2B' => '+',
        '%2C' => ',',
        '%40' => '@',
        '%25' => '%',
    ];

    /**
     * The form-urlencoded "unreserved" set, which passes through untouched.
     */
    protected const UNRESERVED = '/[^A-Za-z0-9*\-._]/';

    /**
     * @param  string  $type  A space type NSID, or "*" for any
     * @param  string  $authority  A DID, "self" for the granting user, or "*" for any
     * @param  string  $skey  A space key, or "*" for any
     * @param  array<int, string>  $collections  Collection NSIDs, or ["*"]
     * @param  array<int, SpaceAction>  $actions
     * @param  array<int, SpaceManageOp>  $manage
     */
    public function __construct(
        public readonly string $type,
        public readonly string $authority = 'self',
        public readonly string $skey = '*',
        public readonly array $collections = [],
        public readonly array $actions = [],
        public readonly array $manage = [],
    ) {
    }

    /**
     * Start building a scope for a space type.
     */
    public static function for(string $type): static
    {
        return new static($type);
    }

    /**
     * Start building a scope narrowed to one concrete space.
     */
    public static function forSpace(SpaceRef $space): static
    {
        return new static($space->type, $space->authority, $space->skey);
    }

    /**
     * Narrow to a single authority DID.
     */
    public function authority(string $authority): static
    {
        return $this->with(authority: $authority === '' ? 'self' : $authority);
    }

    /**
     * Narrow to a single space key.
     */
    public function skey(string $skey): static
    {
        return $this->with(skey: $skey);
    }

    /**
     * Restrict write actions to the given collections.
     *
     * @param  array<int, string>|string  $collections
     */
    public function collections(array|string $collections): static
    {
        return $this->with(collections: (array) $collections);
    }

    /**
     * Set the record actions granted.
     *
     * @param  array<int, SpaceAction>|SpaceAction  $actions
     */
    public function actions(array|SpaceAction $actions): static
    {
        return $this->with(actions: is_array($actions) ? $actions : [$actions]);
    }

    /**
     * Set the space-lifecycle operations granted.
     *
     * @param  array<int, SpaceManageOp>|SpaceManageOp  $manage
     */
    public function manage(array|SpaceManageOp $manage): static
    {
        return $this->with(manage: is_array($manage) ? $manage : [$manage]);
    }

    /**
     * Grant read access only, over the whole space.
     */
    public function readOnly(): static
    {
        return $this->actions(SpaceAction::Read);
    }

    /**
     * Grant read access to the user's own repo only.
     */
    public function readSelfOnly(): static
    {
        return $this->actions(SpaceAction::ReadSelf);
    }

    /**
     * The normalized collection list: "*" absorbs everything, the rest sort.
     *
     * @return array<int, string>
     */
    public function normalizedCollections(): array
    {
        if ($this->collections === []) {
            return [];
        }

        if (in_array('*', $this->collections, true)) {
            return ['*'];
        }

        $unique = array_values(array_unique($this->collections));
        sort($unique);

        return $unique;
    }

    /**
     * The action list in canonical order.
     *
     * @return array<int, SpaceAction>
     */
    public function normalizedActions(): array
    {
        $actions = $this->actions === [] ? SpaceAction::defaults() : $this->actions;

        return array_values(array_filter(
            SpaceAction::cases(),
            fn (SpaceAction $case) => in_array($case, $actions, true),
        ));
    }

    /**
     * The management operation list in canonical order.
     *
     * @return array<int, SpaceManageOp>
     */
    public function normalizedManage(): array
    {
        return array_values(array_filter(
            SpaceManageOp::cases(),
            fn (SpaceManageOp $case) => in_array($case, $this->manage, true),
        ));
    }

    public function __toString(): string
    {
        $scope = 'space:'.$this->encode($this->type);
        $params = [];

        if ($this->authority !== 'self') {
            $params[] = 'authority='.$this->encode($this->authority);
        }

        if ($this->skey !== '*') {
            $params[] = 'skey='.$this->encode($this->skey);
        }

        foreach ($this->normalizedCollections() as $collection) {
            $params[] = 'collection='.$this->encode($collection);
        }

        $actions = $this->normalizedActions();

        if ($actions !== SpaceAction::defaults()) {
            foreach ($actions as $action) {
                $params[] = 'action='.$this->encode($action->value);
            }
        }

        foreach ($this->normalizedManage() as $op) {
            $params[] = 'manage='.$this->encode($op->value);
        }

        return $params === [] ? $scope : $scope.'?'.implode('&', $params);
    }

    /**
     * Clone with selected properties replaced.
     *
     * @param  array<int, string>|null  $collections
     * @param  array<int, SpaceAction>|null  $actions
     * @param  array<int, SpaceManageOp>|null  $manage
     */
    protected function with(
        ?string $authority = null,
        ?string $skey = null,
        ?array $collections = null,
        ?array $actions = null,
        ?array $manage = null,
    ): static {
        return new static(
            $this->type,
            $authority ?? $this->authority,
            $skey ?? $this->skey,
            $collections ?? $this->collections,
            $actions ?? $this->actions,
            $manage ?? $this->manage,
        );
    }

    /**
     * Percent-encode a value, then restore the characters scopes leave literal.
     */
    protected function encode(string $value): string
    {
        $encoded = preg_replace_callback(
            self::UNRESERVED,
            fn (array $m) => $m[0] === ' ' ? '+' : sprintf('%%%02X', ord($m[0])),
            $value,
        ) ?? $value;

        return strtr($encoded, self::LITERAL_CHARS);
    }
}
