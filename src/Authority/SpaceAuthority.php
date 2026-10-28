<?php

namespace SocialDept\AtpSpaces\Authority;

/**
 * Where a space authority can be reached, and the key that signs for it.
 *
 * An authority is an ordinary account: its PDS is its space host, and its
 * `#atproto` key signs its credentials.
 */
class SpaceAuthority
{
    public function __construct(
        public readonly string $did,
        public readonly string $endpoint,
        public readonly ?string $signingKey = null,
        public readonly string $signingKeyId = AuthorityResolver::ACCOUNT_KEY_FRAGMENT,
    ) {
    }

    /**
     * The service identifier a delegation token or client attestation names
     * as its audience.
     */
    public function audience(): string
    {
        return $this->did.AuthorityResolver::PDS_FRAGMENT;
    }

    /**
     * The URL of an XRPC method on this authority.
     */
    public function xrpc(string $nsid): string
    {
        return rtrim($this->endpoint, '/').'/xrpc/'.$nsid;
    }
}
