<?php

namespace SocialDept\AtpSpaces\Authority;

use SocialDept\AtpSpaces\Exceptions\AuthorityResolutionException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Resolver;
use Throwable;

/**
 * Resolves a space authority's host endpoint and signing key from its DID
 * document.
 *
 * The spec defines dedicated entries — `#atproto_space` for the credential
 * signing key and `#atproto_space_host` for the endpoint — and falls back to
 * `#atproto` and `#atproto_pds`. In practice **the fallback is the common
 * case**: any authority that is an ordinary account on a PDS publishes neither
 * dedicated entry, which is what Bluesky's alpha PDS does today. Missing
 * dedicated entries are therefore normal, never an error.
 */
class AuthorityResolver
{
    public const SPACE_KEY_FRAGMENT = '#atproto_space';
    public const SPACE_HOST_FRAGMENT = '#atproto_space_host';
    public const ACCOUNT_KEY_FRAGMENT = '#atproto';
    public const PDS_FRAGMENT = '#atproto_pds';

    /**
     * The service type a dedicated space host publishes.
     */
    public const SPACE_HOST_TYPE = 'AtprotoSpaceHost';

    public function __construct(
        protected Resolver $resolver,
    ) {
    }

    /**
     * Resolve the authority of a space.
     *
     * @throws AuthorityResolutionException
     */
    public function forSpace(SpaceRef $space, bool $useCache = true): SpaceAuthority
    {
        return $this->forDid($space->authority, $useCache);
    }

    /**
     * Resolve an authority by DID.
     *
     * @throws AuthorityResolutionException
     */
    public function forDid(string $did, bool $useCache = true): SpaceAuthority
    {
        try {
            $document = $this->resolver->resolveDid($did, $useCache);
        } catch (Throwable $e) {
            throw new AuthorityResolutionException(
                "Could not resolve space authority \"{$did}\": ".$e->getMessage(),
                previous: $e,
            );
        }

        return $this->fromDocument($document);
    }

    /**
     * Read an authority out of an already-resolved DID document.
     *
     * @throws AuthorityResolutionException
     */
    public function fromDocument(DidDocument $document): SpaceAuthority
    {
        $did = $document->id;

        $dedicatedEndpoint = $this->serviceEndpoint($document, self::SPACE_HOST_FRAGMENT);
        $endpoint = $dedicatedEndpoint ?? $document->getPdsEndpoint();

        if ($endpoint === null || $endpoint === '') {
            throw new AuthorityResolutionException(
                "Space authority \"{$did}\" publishes neither a space host nor a PDS endpoint."
            );
        }

        $dedicatedKey = $this->verificationMethod($document, self::SPACE_KEY_FRAGMENT);
        $accountKey = $this->verificationMethod($document, self::ACCOUNT_KEY_FRAGMENT);
        $key = $dedicatedKey ?? $accountKey;

        if ($key === null) {
            throw new AuthorityResolutionException(
                "Space authority \"{$did}\" publishes no signing key."
            );
        }

        return new SpaceAuthority(
            did: $did,
            endpoint: rtrim($endpoint, '/'),
            signingKeyId: $dedicatedKey !== null ? self::SPACE_KEY_FRAGMENT : self::ACCOUNT_KEY_FRAGMENT,
            signingKey: $key,
            dedicatedEndpoint: $dedicatedEndpoint !== null,
            dedicatedKey: $dedicatedKey !== null,
        );
    }

    /**
     * A service endpoint by fragment.
     *
     * DID documents in the wild write service ids both ways — bare (`#frag`)
     * and fully qualified (`did:plc:x#frag`) — so match on either.
     */
    protected function serviceEndpoint(DidDocument $document, string $fragment): ?string
    {
        foreach ($document->service as $service) {
            if (! $this->matchesFragment($service['id'] ?? null, $fragment)) {
                continue;
            }

            $endpoint = $service['serviceEndpoint'] ?? null;

            return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
        }

        return null;
    }

    /**
     * A verification method's public key by fragment, as a did:key or multibase.
     */
    protected function verificationMethod(DidDocument $document, string $fragment): ?string
    {
        foreach ($document->verificationMethod as $method) {
            if (! $this->matchesFragment($method['id'] ?? null, $fragment)) {
                continue;
            }

            $multibase = $method['publicKeyMultibase'] ?? null;

            if (is_string($multibase) && $multibase !== '') {
                return 'did:key:'.$multibase;
            }

            $multikey = $method['publicKeyJwk'] ?? null;

            if (is_array($multikey)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether an id ends in the fragment we are looking for.
     */
    protected function matchesFragment(mixed $id, string $fragment): bool
    {
        return is_string($id) && ($id === $fragment || str_ends_with($id, $fragment));
    }
}
