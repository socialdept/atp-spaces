<?php

namespace SocialDept\AtpSpaces\Client;

use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Client\Requests\SimpleSpaceRequestClient;
use SocialDept\AtpSpaces\Client\Requests\SpaceRepoRequestClient;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;

/**
 * The `space` domain on an authenticated {@see AtpClient}.
 *
 * Registered as an extension so spaces read like the rest of the client:
 *
 *     $client->space->repo->createRecord(...);   // your own repo, via OAuth
 *     $client->space->manage->addMember(...);    // spaces you own
 *     $client->space->read($space)->listRepos(); // anyone's, via a credential
 */
class SpaceDomainClient
{
    /**
     * Writes to the authenticated user's own space repos.
     */
    public SpaceRepoRequestClient $repo;

    /**
     * Space lifecycle and membership.
     */
    public SimpleSpaceRequestClient $manage;

    protected AtpClient $atp;

    public function __construct(AtpClient $parent)
    {
        $this->atp = $parent;
        $this->repo = new SpaceRepoRequestClient($this);
        $this->manage = new SimpleSpaceRequestClient($this);
    }

    /**
     * The AtpClient this domain hangs off, as request clients expect.
     */
    public function root(): AtpClient
    {
        return $this->atp;
    }

    /**
     * A reader for a space, minting or reusing a credential as needed.
     */
    public function read(SpaceRef|string $space, ?string $clientAttestation = null): SpaceReader
    {
        return $this->manager()->reader($this->ref($space), $this->atp, $clientAttestation);
    }

    /**
     * The credential this app holds for a space, minting one if needed.
     */
    public function credential(SpaceRef|string $space, ?string $clientAttestation = null): SpaceCredential
    {
        return $this->manager()->credential($this->ref($space), $this->atp, $clientAttestation);
    }

    /**
     * Discard the stored credential for a space.
     */
    public function forget(SpaceRef|string $space): void
    {
        $this->manager()->forget($this->ref($space), $this->atp->client->session()->did());
    }

    protected function ref(SpaceRef|string $space): SpaceRef
    {
        return $space instanceof SpaceRef ? $space : SpaceRef::parse($space);
    }

    protected function manager(): Spaces
    {
        return app(Spaces::class);
    }

    protected function getDomainName(): string
    {
        return 'space';
    }
}
