<?php

namespace SocialDept\AtpSpaces;

use Illuminate\Http\Client\Factory as HttpFactory;
use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpSpaces\Auth\CredentialBroker;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Authority\AuthorityResolver;
use SocialDept\AtpSpaces\Authority\SpaceAuthority;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSupport\Resolver;

/**
 * The package's entry point.
 *
 * Holds the pieces that have to agree with each other — how authorities are
 * resolved, where credentials are kept, which HTTP client is used — so callers
 * do not have to assemble them.
 */
class Spaces
{
    public function __construct(
        protected CredentialBroker $broker,
        protected AuthorityResolver $authorities,
        protected SpaceTypeRegistry $types,
        protected Resolver $resolver,
        protected HttpFactory $http,
        protected int $timeout = 30,
        protected ?string $service = null,
    ) {
    }

    /**
     * A credential for a space, reusing a stored one when it is still good.
     */
    public function credential(
        SpaceRef $space,
        AtpClient $client,
        ?string $clientAttestation = null,
    ): SpaceCredential {
        return $this->broker->for($space, $client, $clientAttestation);
    }

    /**
     * A credential from a delegation token the user's browser obtained, for
     * when their session lives there rather than on this server.
     */
    public function credentialFromDelegation(
        SpaceRef $space,
        string $delegationToken,
        ?string $clientAttestation = null,
    ): SpaceCredential {
        return $this->broker->fromDelegation($space, $delegationToken, $clientAttestation);
    }

    /**
     * A reader for a space, credentialed on this user's behalf.
     */
    public function reader(
        SpaceRef $space,
        AtpClient $client,
        ?string $clientAttestation = null,
    ): SpaceReader {
        return $this->readerFor($this->credential($space, $client, $clientAttestation));
    }

    /**
     * A reader for a credential you already hold.
     */
    public function readerFor(SpaceCredential $credential): SpaceReader
    {
        return new SpaceReader(
            $credential,
            $this->authorities,
            $this->resolver,
            $this->http,
            $this->timeout,
            $this->broker,
            $this->service,
        );
    }

    /**
     * Where a space's authority lives and which key signs for it.
     */
    public function authority(SpaceRef $space, bool $useCache = true): SpaceAuthority
    {
        return $this->authorities->forSpace($space, $useCache);
    }

    /**
     * The space types this application has registered.
     */
    public function types(): SpaceTypeRegistry
    {
        return $this->types;
    }

    /**
     * The credential broker, for minting outside the normal flow.
     */
    public function broker(): CredentialBroker
    {
        return $this->broker;
    }

    /**
     * Discard a stored credential.
     *
     * @param  string  $user  A DID or a handle
     */
    public function forget(SpaceRef $space, string $user): void
    {
        $this->broker->forget($space, $user);
    }
}
