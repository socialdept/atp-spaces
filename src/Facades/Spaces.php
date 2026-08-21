<?php

namespace SocialDept\AtpSpaces\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SocialDept\AtpSpaces\Auth\SpaceCredential credential(\SocialDept\AtpSpaces\SpaceRef $space, \SocialDept\AtpClient\AtpClient $client, ?string $clientAttestation = null)
 * @method static \SocialDept\AtpSpaces\Client\SpaceReader reader(\SocialDept\AtpSpaces\SpaceRef $space, \SocialDept\AtpClient\AtpClient $client, ?string $clientAttestation = null)
 * @method static \SocialDept\AtpSpaces\Client\SpaceReader readerFor(\SocialDept\AtpSpaces\Auth\SpaceCredential $credential)
 * @method static \SocialDept\AtpSpaces\Authority\SpaceAuthority authority(\SocialDept\AtpSpaces\SpaceRef $space, bool $useCache = true)
 * @method static \SocialDept\AtpSpaces\SpaceTypeRegistry types()
 * @method static \SocialDept\AtpSpaces\Auth\CredentialBroker broker()
 * @method static void forget(\SocialDept\AtpSpaces\SpaceRef $space, string $userDid)
 *
 * @see \SocialDept\AtpSpaces\Spaces
 */
class Spaces extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SocialDept\AtpSpaces\Spaces::class;
    }
}
