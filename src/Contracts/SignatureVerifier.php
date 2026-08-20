<?php

namespace SocialDept\AtpSpaces\Contracts;

/**
 * Verifies an atproto signature against a did:key-encoded public key.
 *
 * A space commit is signed by the author's atproto signing key, so checking
 * one means resolving that key from their DID document and verifying an
 * ES256K or ES256 signature. This package does not implement that itself —
 * bind an implementation to check commit authenticity.
 */
interface SignatureVerifier
{
    /**
     * @param  string  $didKey  A did:key-encoded public key
     * @param  string  $data  The exact bytes that were signed
     * @param  string  $signature  The raw signature bytes
     */
    public function verify(string $didKey, string $data, string $signature): bool;
}
