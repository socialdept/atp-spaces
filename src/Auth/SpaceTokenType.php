<?php

namespace SocialDept\AtpSpaces\Auth;

/**
 * The three token classes in the space credential exchange.
 *
 * They share a wire shape and differ only in who signs them, who they are
 * addressed to, and how long they live — hence one enum with data attached
 * rather than three implementations.
 */
enum SpaceTokenType: string
{
    /**
     * Minted by the user's PDS, proving an app acts on their behalf.
     */
    case Delegation = 'delegation';

    /**
     * Issued by the space authority, read across every repo host in the space.
     */
    case Credential = 'credential';

    /**
     * Optional, proving the app's identity when a space gates on it.
     */
    case ClientAttestation = 'clientAttestation';

    /**
     * The `typ` header this token class carries.
     */
    public function typ(): string
    {
        return match ($this) {
            self::Delegation => 'atproto-space-delegation+jwt',
            self::Credential => 'atproto-space-credential+jwt',
            self::ClientAttestation => 'atproto-client-attestation+jwt',
        };
    }

    /**
     * The default `kid`, or null when the signer supplies its own.
     *
     * An authority publishing a dedicated `#atproto_space` key signs with it
     * and passes that kid. Absent one — the case for any authority hosted on a
     * PDS — the space signing key is the account's `#atproto` key.
     */
    public function defaultKid(): ?string
    {
        return match ($this) {
            self::Delegation, self::Credential => '#atproto',
            self::ClientAttestation => null,
        };
    }

    /**
     * How long this token class lives, in seconds.
     */
    public function lifetime(): int
    {
        return match ($this) {
            self::Delegation, self::ClientAttestation => 60,
            self::Credential => 7200,
        };
    }

    /**
     * Whether an `aud` is mandatory.
     *
     * A credential has none: it is multi-use across repo hosts and bound to a
     * DPoP key instead.
     */
    public function requiresAudience(): bool
    {
        return $this !== self::Credential;
    }

    /**
     * Whether a `cnf.jkt` DPoP binding is mandatory.
     */
    public function requiresConfirmation(): bool
    {
        return $this === self::Credential;
    }

    /**
     * Whether the token is consumed on first use, and so needs a `jti`.
     */
    public function isSingleUse(): bool
    {
        return $this !== self::Credential;
    }
}
