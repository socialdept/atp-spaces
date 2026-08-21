<?php

namespace SocialDept\AtpSpaces\Crypto;

use SocialDept\AtpSpaces\Contracts\SignatureVerifier;
use SocialDept\AtpSupport\Crypto\SignatureVerifier as SupportVerifier;

/**
 * Verifies space commits with `atp-support`'s did:key implementation.
 *
 * A thin adapter rather than a direct binding: `atp-support` has no reason to
 * know this package's contract exists, and this package should not care which
 * curve library sits underneath.
 */
class SupportSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        protected SupportVerifier $verifier = new SupportVerifier(),
    ) {
    }

    public function verify(string $didKey, string $data, string $signature): bool
    {
        return $this->verifier->verify($didKey, $data, $signature);
    }
}
