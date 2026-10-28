<?php

namespace SocialDept\AtpSpaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpClient\Data\ServiceAuthToken;
use SocialDept\AtpClient\Http\Middleware\VerifyServiceAuthMiddleware;
use SocialDept\AtpSpaces\Access\SpaceAccessRequest;
use SocialDept\AtpSpaces\Contracts\SpaceAccessResolver;
use SocialDept\AtpSpaces\Enums\SpaceAccess;
use SocialDept\AtpSpaces\SpaceRef;
use Throwable;

/**
 * Serves `com.atproto.simplespace.checkUserAccess`.
 *
 * A space authority calls this when a read or write policy is `managingApp`:
 * for read when someone asks for a credential, for write when a member
 * writes. The reply is a single boolean, and every path that is not a
 * confident yes answers no.
 *
 * The service auth middleware has already proven *some* service signed the
 * request for this method. It cannot check the one thing that matters most
 * here, because it does not know which space is being asked about: that the
 * caller is that space's own authority. Without that check, any service
 * holding a valid token could ask this endpoint about any space and learn who
 * has access to it.
 */
class CheckUserAccessController
{
    public function __construct(
        protected SpaceAccessResolver $resolver,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $space = SpaceRef::tryParse((string) $request->query('space', ''));
        $user = (string) $request->query('user', '');
        $access = SpaceAccess::tryFrom((string) $request->query('access', ''));

        if ($space === null || $user === '' || $access === null) {
            return response()->json([
                'error' => 'InvalidRequest',
                'message' => '"space", "user" and "access" (read or write) are required.',
            ], 400);
        }

        $token = $request->attributes->get(VerifyServiceAuthMiddleware::ATTRIBUTE);

        if (! $token instanceof ServiceAuthToken) {
            return $this->deny('request was not service-authenticated', $space, $user);
        }

        // The caller must be the authority for the space it is asking about.
        if (! hash_equals($space->authority, $this->didOf($token->issuer))) {
            return $this->deny('caller is not the space authority', $space, $user, $token->issuer);
        }

        $clientId = $request->query('clientId');

        try {
            $authorized = $this->resolver->authorize(new SpaceAccessRequest(
                $space,
                $user,
                $access,
                is_string($clientId) && $clientId !== '' ? $clientId : null,
                $token->issuer,
            ));
        } catch (Throwable $e) {
            // The authority treats an error as a refusal either way. Answering
            // it plainly keeps the outcome predictable and the trace private.
            return $this->deny('resolver threw: '.$e->getMessage(), $space, $user, $token->issuer);
        }

        return response()->json(['authorized' => $authorized]);
    }

    /**
     * Refuse, and say why in the log rather than in the response.
     */
    protected function deny(string $reason, SpaceRef $space, string $user, ?string $caller = null): JsonResponse
    {
        Log::warning('checkUserAccess denied: '.$reason, [
            'space' => (string) $space,
            'user' => $user,
            'caller' => $caller,
        ]);

        return response()->json(['authorized' => false]);
    }

    /**
     * A service identifier may carry a fragment; the DID does not.
     */
    protected function didOf(string $identifier): string
    {
        return explode('#', $identifier, 2)[0];
    }
}
