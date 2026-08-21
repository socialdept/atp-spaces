<?php

namespace SocialDept\AtpSpaces\Management;

/**
 * How an authority decides whether to authorize a requesting **app**.
 *
 * Orthogonal to the user policy: a space can admit any app but only some
 * users, or the reverse. An allow list is checked against the `client_id`
 * proven by a client attestation, so choosing it makes attestations mandatory.
 */
class AppAccess
{
    public const NAMESPACE = 'com.atproto.simplespace.defs';

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function __construct(
        public readonly string $type,
        public readonly array $extra = [],
    ) {
    }

    /**
     * Any app may access the space. No client attestation required.
     */
    public static function open(): static
    {
        return new static(self::NAMESPACE.'#open');
    }

    /**
     * Only the named OAuth client IDs may access the space.
     *
     * @param  array<int, string>  $clientIds
     */
    public static function allowList(array $clientIds): static
    {
        return new static(self::NAMESPACE.'#allowList', ['allowed' => array_values($clientIds)]);
    }

    public function isOpen(): bool
    {
        return $this->type === self::NAMESPACE.'#open';
    }

    /**
     * Whether a client attestation must accompany a credential request.
     */
    public function requiresAttestation(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * @return array<int, string>
     */
    public function allowed(): array
    {
        $allowed = $this->extra['allowed'] ?? [];

        return is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $type = (string) ($data['$type'] ?? '');
        $extra = $data;
        unset($extra['$type']);

        return new static($type, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['$type' => $this->type] + $this->extra;
    }
}
