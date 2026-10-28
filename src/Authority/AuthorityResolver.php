<?php

namespace SocialDept\AtpSpaces\Authority;

use SocialDept\AtpSpaces\Exceptions\AuthorityResolutionException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Resolver;
use Throwable;

/**
 * Resolves a space authority's host endpoint and signing key from its DID
 * document, the way any account is resolved: its `#atproto_pds` service is
 * its space host, and its `#atproto` key signs its credentials and the
 * service auth it sends.
 *
 * Earlier drafts had dedicated `#atproto_space` and `#atproto_space_host`
 * entries. They are gone, and ignored where an old DID document still
 * publishes them.
 */
class AuthorityResolver
{
    public const ACCOUNT_KEY_FRAGMENT = '#atproto';
    public const PDS_FRAGMENT = '#atproto_pds';

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
     * A DID only, as the name says. Resolve a handle first, or use
     * {@see self::forSpace()}, whose reference already carries one.
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
        $endpoint = $document->getPdsEndpoint();

        if ($endpoint === null || $endpoint === '') {
            throw new AuthorityResolutionException(
                "Space authority \"{$did}\" publishes no PDS endpoint."
            );
        }

        $key = $document->getSigningKey(self::ACCOUNT_KEY_FRAGMENT);

        if ($key === null) {
            throw new AuthorityResolutionException(
                "Space authority \"{$did}\" publishes no signing key."
            );
        }

        return new SpaceAuthority(
            did: $did,
            endpoint: rtrim($endpoint, '/'),
            signingKey: $key,
        );
    }
}
