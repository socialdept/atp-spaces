<?php

namespace SocialDept\AtpSpaces\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpClient\Data\ServiceAuthToken;
use SocialDept\AtpClient\Http\Middleware\VerifyServiceAuthMiddleware;
use SocialDept\AtpSpaces\Events\SpaceDeletedNotified;
use SocialDept\AtpSpaces\Events\SpaceWriteNotified;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Syntax;

/**
 * Serves `com.atproto.space.notifyWrite` and `com.atproto.space.notifySpaceDeleted`,
 * the two calls a space's authority makes to services registered through
 * `registerNotify`. Each becomes an event, and the application decides what
 * to sync.
 *
 * The service auth middleware has already proven some service signed the
 * request for this method and this application. What it cannot know is which
 * space the request is about, so the one check left is that the signer is that
 * space's own authority. Without it any service holding a valid token could
 * tell this application a space was deleted.
 */
class SyncerController
{
    public function __construct(
        protected Dispatcher $events,
    ) {
    }

    public function write(Request $request): Response|JsonResponse
    {
        $space = $this->authorizedSpace($request);

        if ($space instanceof JsonResponse) {
            return $space;
        }

        $repo = $request->input('repo');
        $repoRev = $request->input('repoRev');
        $data = $this->bytes($request->input('data'));

        if (! is_string($repo) || ! Syntax::isDid($repo) || ! is_string($repoRev) || $repoRev === '' || $data === null) {
            return $this->invalid('"repo" (a DID), "repoRev" and "data" (bytes) are required.');
        }

        $this->events->dispatch(new SpaceWriteNotified(
            $space,
            $repo,
            $repoRev,
            $data,
            $this->optional($request->input('spaceRev')),
            $this->optional($request->input('prevSpaceRev')),
        ));

        return response('');
    }

    public function spaceDeleted(Request $request): Response|JsonResponse
    {
        $space = $this->authorizedSpace($request);

        if ($space instanceof JsonResponse) {
            return $space;
        }

        $this->events->dispatch(new SpaceDeletedNotified($space));

        return response('');
    }

    /**
     * The space the request is about, once its signer is shown to govern it.
     */
    protected function authorizedSpace(Request $request): SpaceRef|JsonResponse
    {
        $space = SpaceRef::tryParse((string) $request->input('space', ''));

        if ($space === null) {
            return $this->invalid('"space" must be a space reference.');
        }

        $token = $request->attributes->get(VerifyServiceAuthMiddleware::ATTRIBUTE);

        if (! $token instanceof ServiceAuthToken) {
            return $this->refuse('request was not service-authenticated', $space);
        }

        if (! hash_equals($space->authority, $this->didOf($token->issuer))) {
            return $this->refuse('caller is not the space authority', $space, $token->issuer);
        }

        return $space;
    }

    /**
     * Unwrap a lexicon bytes value, `{"$bytes": "<base64>"}` over JSON.
     */
    protected function bytes(mixed $value): ?string
    {
        if (! is_array($value) || ! is_string($value['$bytes'] ?? null)) {
            return null;
        }

        $decoded = base64_decode($value['$bytes'], true);

        return $decoded === false ? null : $decoded;
    }

    protected function optional(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function invalid(string $message): JsonResponse
    {
        return response()->json(['error' => 'InvalidRequest', 'message' => $message], 400);
    }

    /**
     * Refuse, and say why in the log rather than in the response.
     */
    protected function refuse(string $reason, SpaceRef $space, ?string $caller = null): JsonResponse
    {
        Log::warning('space notification refused: '.$reason, [
            'space' => (string) $space,
            'caller' => $caller,
        ]);

        return response()->json([
            'error' => 'AuthRequired',
            'message' => 'Only the space authority may notify about this space.',
        ], 401);
    }

    /**
     * A service identifier may carry a fragment, and the DID does not.
     */
    protected function didOf(string $identifier): string
    {
        return explode('#', $identifier, 2)[0];
    }
}
