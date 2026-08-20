<?php

namespace SocialDept\AtpSpaces\Repo;

use InvalidArgumentException;
use SocialDept\AtpSpaces\Contracts\Blake3Hasher;
use SocialDept\AtpSpaces\Crypto\Blake3;

/**
 * A homomorphic set hash.
 *
 * Each element expands to 1024 little-endian uint16 lanes, summed into the
 * state mod 2^16. Addition and subtraction commute, so the state depends only
 * on which elements are present, never on the order they arrived — which is
 * what lets a permissioned repo be synced by replaying operations in any order
 * and still arrive at a comparable hash.
 */
class LtHash
{
    public const LANES = 1024;
    public const LANE_BYTES = 2;
    public const STATE_BYTES = self::LANES * self::LANE_BYTES;

    protected const LANE_MASK = 0xFFFF;

    /**
     * @var array<int, int> 1024 uint16 lanes
     */
    protected array $lanes;

    protected Blake3Hasher $hasher;

    /**
     * @param  string|null  $state  A previously persisted 2048-byte state
     */
    public function __construct(?string $state = null, ?Blake3Hasher $hasher = null)
    {
        $this->hasher = $hasher ?? new Blake3();

        if ($state === null) {
            $this->lanes = array_fill(0, self::LANES, 0);

            return;
        }

        if (strlen($state) !== self::STATE_BYTES) {
            throw new InvalidArgumentException(
                'LtHash state must be '.self::STATE_BYTES.' bytes, got '.strlen($state).'.'
            );
        }

        $this->lanes = array_values(unpack('v'.self::LANES, $state));
    }

    /**
     * Restore from a persisted state, treating null as an empty set.
     */
    public static function fromState(?string $state, ?Blake3Hasher $hasher = null): static
    {
        return new static($state, $hasher);
    }

    /**
     * Add an element to the set.
     */
    public function add(string $element): static
    {
        $expanded = $this->expand($element);

        for ($i = 0; $i < self::LANES; $i++) {
            $this->lanes[$i] = ($this->lanes[$i] + $expanded[$i]) & self::LANE_MASK;
        }

        return $this;
    }

    /**
     * Remove an element from the set.
     */
    public function remove(string $element): static
    {
        $expanded = $this->expand($element);

        for ($i = 0; $i < self::LANES; $i++) {
            $this->lanes[$i] = ($this->lanes[$i] - $expanded[$i]) & self::LANE_MASK;
        }

        return $this;
    }

    /**
     * The full 2048-byte state, for persistence.
     */
    public function state(): string
    {
        return pack('v'.self::LANES, ...$this->lanes);
    }

    /**
     * The 32-byte commit hash: sha256 over the state.
     */
    public function digest(): string
    {
        return hash('sha256', $this->state(), true);
    }

    /**
     * Whether the set is empty.
     */
    public function isEmpty(): bool
    {
        foreach ($this->lanes as $lane) {
            if ($lane !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether two set hashes describe the same set.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->state(), $other->state());
    }

    /**
     * A copy, so callers can fold speculative changes without mutating.
     */
    public function copy(): static
    {
        return new static($this->state(), $this->hasher);
    }

    /**
     * Expand an element to 1024 uint16 lanes.
     *
     * @return array<int, int>
     */
    protected function expand(string $element): array
    {
        return array_values(unpack('v'.self::LANES, $this->hasher->xof($element, self::STATE_BYTES)));
    }
}
