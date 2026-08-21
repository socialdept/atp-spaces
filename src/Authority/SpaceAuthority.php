<?php

namespace SocialDept\AtpSpaces\Authority;

/**
 * Where a space authority can be reached, and which key signs for it.
 *
 * `dedicated` records whether the DID document published space-specific
 * entries or whether the account-level ones were used. That distinction is
 * worth keeping: it is the difference between an authority that runs its own
 * space service and one that is just an ordinary user on a PDS.
 */
class SpaceAuthority
{
    public function __construct(
        public readonly string $did,
        public readonly string $endpoint,
        public readonly string $signingKeyId,
        public readonly ?string $signingKey = null,
        public readonly bool $dedicatedEndpoint = false,
        public readonly bool $dedicatedKey = false,
    ) {
    }

    /**
     * The service identifier a delegation token names as its audience.
     *
     * Always the `#atproto_space_host` fragment, whether or not the DID
     * document publishes one — the audience is an identifier, not an address.
     */
    public function audience(): string
    {
        return $this->did.'#atproto_space_host';
    }

    /**
     * The URL of an XRPC method on this authority.
     */
    public function xrpc(string $nsid): string
    {
        return rtrim($this->endpoint, '/').'/xrpc/'.$nsid;
    }

    /**
     * Whether this authority is an ordinary account on a PDS rather than a
     * dedicated space service.
     */
    public function isPdsHosted(): bool
    {
        return ! $this->dedicatedEndpoint;
    }
}
